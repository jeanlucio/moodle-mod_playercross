// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AMD module for mod_playercross game interactions.
 *
 * Submits guesses via AJAX so the page never reloads — the mystery phrase and every
 * clue answer stay server-side the whole time, only the updated round panel (theme
 * tiles, clue rows, reveal-once-finished fields) comes back on each response. Every
 * delegated listener is attached once, on #playercross-stage, at init() time: the
 * stage element itself is never replaced across re-renders (only its contents, via
 * Templates.replaceNodeContents()), so delegation survives every AJAX round-trip
 * without needing to be rewired.
 *
 * Every still-hidden letter of a guess row (a clue's own word, or the mystery phrase)
 * is its own real single-character <input> — a locked, already-revealed letter is a
 * plain, non-focusable <span> instead (inputmode="none" on every box, so the device's
 * own keyboard never appears). A single virtual keyboard writes into whichever box
 * last received focus, tracked in activeInput below, then advances focus to the next
 * hidden box in that row automatically — locked letters are skipped, since they were
 * never boxes to begin with. Clicking a specific box focuses exactly that one (native
 * browser behaviour), so a single wrong letter can be fixed without retyping the rest.
 * A guess is assembled at submit time by reading every tile in a row, in order: a
 * locked span's own letter, or a box's typed value (see buildClueGuess/
 * buildFinalGuess). No row carries a visible submit button — every guess is confirmed
 * via the keyboard's own Enviar key or a physical Enter key.
 *
 * @module     mod_playercross/game
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Config from 'core/config';
import Modal from 'core/modal';
import ModalEvents from 'core/modal_events';
import ModalSaveCancel from 'core/modal_save_cancel';
import Notification from 'core/notification';
import {getString} from 'core/str';
import Templates from 'core/templates';

/** @type {?number} Handle of the pending round-timer tick, if any. */
let timerHandle = null;

/** @type {?number} Handle of the pending cooldown-countdown tick, if any. */
let cooldownHandle = null;

/** @type {?HTMLElement} Letter box (.mod-playercross-tile-input) the virtual keyboard currently writes into. */
let activeInput = null;

/**
 * Writes a message into the live region so screen readers announce it.
 *
 * @param {string} message Message to announce.
 */
const announce = (message) => {
    const region = document.getElementById('playercross-live-region');
    if (region) {
        region.textContent = message;
    }
};

/**
 * Shows a visible Moodle notification.
 *
 * @param {string} message Notification text.
 * @param {string} type Notification type: success, info, warning or error.
 */
const notify = (message, type) => {
    if (!message) {
        return;
    }
    Notification.addNotification({message, type: type || 'info'});
};

/**
 * Formats a seconds count as "Xmin YYs".
 *
 * @param {number} seconds Total seconds remaining.
 * @returns {string}
 */
const formatGameTime = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}min ${String(s).padStart(2, '0')}s`;
};

/**
 * Cancels any pending round-timer tick.
 */
const stopTimer = () => {
    if (timerHandle) {
        window.clearTimeout(timerHandle);
        timerHandle = null;
    }
};

/**
 * Ticks the round timer down one second and re-schedules itself, ending the round via
 * mod_playercross_end_round (reason: timeout) once time runs out — the server
 * independently re-validates that the deadline actually passed.
 *
 * @param {HTMLElement} el Span showing the countdown.
 * @param {number} deadline Unix timestamp (seconds) when the round times out.
 * @param {number} threshold Seconds at which to add the urgency class.
 * @param {number} cmid Course-module id.
 */
const tickTimer = (el, deadline, threshold, cmid) => {
    const remaining = deadline - Math.floor(Date.now() / 1000);
    el.textContent = formatGameTime(Math.max(0, remaining));
    if (remaining <= threshold) {
        el.classList.add('pc-timer-urgent');
    }
    if (remaining <= 0) {
        stopTimer();
        endRound(cmid, 'timeout');
        return;
    }
    timerHandle = window.setTimeout(() => tickTimer(el, deadline, threshold, cmid), 1000);
};

/**
 * (Re)starts the round-timer countdown if the timer element is present.
 *
 * @param {number} timeleft Seconds remaining.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 * @param {number} cmid Course-module id.
 */
const startTimer = (timeleft, timertotal, cmid) => {
    stopTimer();
    const el = document.getElementById('playercross-timer-countdown');
    if (!el || timeleft <= 0) {
        return;
    }
    el.textContent = formatGameTime(timeleft);
    const threshold = timertotal > 0 ? Math.max(10, Math.floor(timertotal * 0.2)) : 30;
    const deadline = Math.floor(Date.now() / 1000) + timeleft;
    tickTimer(el, deadline, threshold, cmid);
};

/**
 * Cancels any pending cooldown-countdown tick.
 */
const stopCountdown = () => {
    if (cooldownHandle) {
        window.clearTimeout(cooldownHandle);
        cooldownHandle = null;
    }
};

/**
 * Updates the cooldown countdown span every second until the timestamp is reached.
 *
 * @param {HTMLElement} el The span element to update.
 * @param {number} until Unix timestamp (seconds) when the cooldown ends.
 * @param {number} cmid Course-module id used to build the reload URL.
 */
const tickCountdown = (el, until, cmid) => {
    const remaining = until - Math.floor(Date.now() / 1000);
    if (remaining <= 0) {
        stopCountdown();
        window.location.href = `${Config.wwwroot}/mod/playercross/view.php?id=${cmid}`;
        return;
    }
    const h = Math.floor(remaining / 3600);
    const m = Math.floor((remaining % 3600) / 60);
    const s = remaining % 60;
    const parts = [];
    if (h > 0) {
        parts.push(`${h}h`);
    }
    parts.push(`${String(m).padStart(2, '0')}m`);
    parts.push(`${String(s).padStart(2, '0')}s`);
    el.textContent = parts.join(' ');
    cooldownHandle = window.setTimeout(() => tickCountdown(el, until, cmid), 1000);
};

/**
 * (Re)starts the cooldown countdown if the element is present.
 *
 * @param {number} until Unix timestamp when the cooldown ends.
 * @param {number} cmid Course-module id used to build the reload URL.
 */
const startCountdown = (until, cmid) => {
    stopCountdown();
    const el = document.getElementById('playercross-cooldown-countdown');
    if (!el || until <= 0) {
        return;
    }
    tickCountdown(el, until, cmid);
};

/**
 * Opens the how-to-play content (already server-rendered into
 * #playercross-help-content) in a modal.
 *
 * @param {HTMLElement} button Help toolbar button, source of the modal title.
 * @param {HTMLElement} content Hidden container holding the pre-rendered help body.
 */
const openHelpModal = (button, content) => {
    Modal.create({
        title: button.dataset.title,
        body: content.innerHTML,
        show: true,
        removeOnClose: true,
    }).catch(Notification.exception);
};

/**
 * Wires the toolbar's help button, and — when requested by the server for this page
 * load — opens it once automatically too (see intro_service::has_seen_intro()).
 *
 * @param {boolean} autoshow Whether to open the modal immediately, once, on this load.
 */
const initHelpModal = (autoshow) => {
    const button = document.getElementById('playercross-help-button');
    const content = document.getElementById('playercross-help-content');
    if (!button || !content) {
        return;
    }
    button.addEventListener('click', () => {
        openHelpModal(button, content);
    });
    if (autoshow) {
        openHelpModal(button, content);
    }
};

/**
 * Wires a Moodle confirmation modal to the forfeit button, ending the round via
 * mod_playercross_end_round on confirm.
 *
 * @param {number} cmid Course-module id.
 */
const initForfeit = (cmid) => {
    const button = document.getElementById('playercross-forfeit-button');
    if (!button) {
        return;
    }
    button.addEventListener('click', () => {
        Promise.all([
            ModalSaveCancel.create({
                title: button.dataset.title,
                body: button.dataset.confirm,
                show: true,
                removeOnClose: true,
            }),
            getString('yes', 'core'),
        ]).then(([modal, yesStr]) => {
            modal.setSaveButtonText(yesStr);
            modal.getRoot().on(ModalEvents.save, () => {
                endRound(cmid, 'forfeit');
            });
            return;
        }).catch(Notification.exception);
    });
};

/**
 * Returns every tile-wrap element within a scope, in position order — one per letter,
 * whether that position is a locked (already-revealed) tile or an editable box.
 *
 * @param {HTMLElement} scope A clue's tiles container, or one mystery-phrase word group.
 * @returns {HTMLElement[]}
 */
const getTileWraps = (scope) => Array.from(scope.querySelectorAll('.mod-playercross-tile-wrap'));

/**
 * Reads one tile-wrap's current letter: a locked tile's own text, or an editable box's
 * typed value.
 *
 * @param {HTMLElement} wrap One .mod-playercross-tile-wrap element.
 * @returns {string}
 */
const readTileWrap = (wrap) => {
    const locked = wrap.querySelector('.mod-playercross-tile.is-revealed');
    return locked ? locked.textContent : (wrap.querySelector('.mod-playercross-tile-input')?.value ?? '');
};

/**
 * Assembles a clue's full guess from its tile row: each locked tile's own letter, or
 * each editable box's typed letter, in position order.
 *
 * @param {HTMLElement} tilesContainer A clue's .mod-playercross-clue-tiles element.
 * @returns {string}
 */
const buildClueGuess = (tilesContainer) => getTileWraps(tilesContainer).map(readTileWrap).join('');

/**
 * Assembles the mystery phrase's full guess from its tile rows: each word group's own
 * letters joined together, with a single space inserted between word groups. The
 * player never types that space — the boundary between words is structural, one
 * .mod-playercross-word-group per word (see mod_playercross/round_panel).
 *
 * @param {HTMLElement} themeContainer The .mod-playercross-theme element.
 * @returns {string}
 */
const buildFinalGuess = (themeContainer) => Array.from(themeContainer.querySelectorAll('.mod-playercross-word-group'))
    .map((group) => getTileWraps(group).map(readTileWrap).join(''))
    .join(' ');

/**
 * Writes a guess's characters back into a tile row's editable boxes, skipping locked
 * positions — the row's structure (which letters are locked) never changes between a
 * wrong guess and the re-render that follows it, so the characters line up with the
 * fresh tile-wraps one for one.
 *
 * @param {HTMLElement} scope A clue's tiles container, or one word group.
 * @param {string[]} chars Characters to distribute, one per tile-wrap in scope.
 */
const distributeIntoWraps = (scope, chars) => {
    getTileWraps(scope).forEach((wrap, i) => {
        const box = wrap.querySelector('.mod-playercross-tile-input');
        if (box && chars[i] !== undefined) {
            box.value = chars[i].toUpperCase();
        }
    });
};

/**
 * Restores a clue's guess into its freshly re-rendered boxes after a wrong (or
 * exhausted) submission, and focuses its first editable box. A no-op once the clue is
 * actually resolved or the round finished — canguess is then false server-side, so no
 * matching form exists to restore into.
 *
 * @param {number} clueid Clue word id.
 * @param {string} guess The guess text the player had submitted.
 */
const restoreClueGuess = (clueid, guess) => {
    const tilesContainer = document.querySelector(`.mod-playercross-clue-tiles[data-clue-tiles="${clueid}"]`);
    if (!tilesContainer) {
        return;
    }
    distributeIntoWraps(tilesContainer, guess.split(''));
    tilesContainer.querySelector('.mod-playercross-tile-input')?.focus();
};

/**
 * Restores the mystery-phrase guess into its freshly re-rendered boxes after a wrong
 * submission, word group by word group, and focuses the first editable box.
 *
 * @param {string} guess The guess text the player had submitted.
 */
const restoreFinalGuess = (guess) => {
    const themeContainer = document.querySelector('.mod-playercross-theme');
    if (!themeContainer) {
        return;
    }
    const words = guess.split(' ');
    Array.from(themeContainer.querySelectorAll('.mod-playercross-word-group')).forEach((group, i) => {
        distributeIntoWraps(group, (words[i] ?? '').split(''));
    });
    themeContainer.querySelector('.mod-playercross-tile-input')?.focus();
};

/**
 * Returns every editable box within the same guess form as the given box, in position
 * order — spans every word group for the mystery phrase, so typing carries straight
 * from the last letter of one word into the first letter of the next.
 *
 * @param {HTMLElement} box A .mod-playercross-tile-input element.
 * @returns {HTMLElement[]}
 */
const getFormBoxes = (box) => {
    const form = box.closest('.mod-playercross-guess-form');
    return form ? Array.from(form.querySelectorAll('.mod-playercross-tile-input')) : [];
};

/**
 * Moves focus to the editable box immediately before or after the given one, in the
 * same guess form, if any — locked tiles are never part of getFormBoxes(), so this
 * skips over them automatically.
 *
 * @param {HTMLElement} box A .mod-playercross-tile-input element.
 * @param {number} offset -1 for the previous box, 1 for the next.
 */
const focusAdjacentBox = (box, offset) => {
    const boxes = getFormBoxes(box);
    boxes[boxes.indexOf(box) + offset]?.focus();
};

/**
 * Filters a letter box's value down to a single letter and, once filled, advances
 * focus to the next editable box in the same guess form. Delegated on the stage (see
 * wireStageDelegation) so it applies uniformly whether the letter came from a physical
 * keyboard or the on-screen one, without needing to be rewired per render.
 *
 * @param {HTMLElement} box Letter box that just changed.
 */
const handleBoxInput = (box) => {
    const filtered = box.value.replace(/[^\p{L}]/gu, '').slice(0, 1).toUpperCase();
    box.value = filtered;
    if (filtered !== '') {
        focusAdjacentBox(box, 1);
    }
};

/**
 * Marks the guess row containing the focused box as active (amber highlight) — a
 * clue's own <li> card, or the mystery phrase's <form> when that is the target —
 * remembers the box as the virtual keyboard's write target, and selects its existing
 * content so a physical keystroke replaces it instead of being silently rejected by
 * the box's own maxlength="1". Delegated on focusin (see wireStageDelegation), so it
 * fires whether focus arrived via a click on a specific box, physical Tab navigation,
 * or a script-driven .focus() call.
 *
 * @param {HTMLElement} input Letter box that just gained focus.
 */
const setActiveInput = (input) => {
    activeInput = input;
    document.querySelectorAll('.mod-playercross-clue.is-active, .mod-playercross-theme-form.is-active').forEach((el) => {
        el.classList.remove('is-active');
    });
    const row = input.closest('.mod-playercross-clue') ?? input.closest('.mod-playercross-theme-form');
    row?.classList.add('is-active');
    input.select();
};

/**
 * Applies the side effects that must run after every stage re-render: the round or
 * cooldown countdown, the forfeit button's visibility, and moving focus to the first
 * pending clue (or the mystery-phrase input, if every clue is resolved) so continuous
 * typing can carry straight on from one guess to the next.
 *
 * @param {Object} panelcontext Context matching mod_playercross/round_panel.
 * @param {number} cmid Course-module id.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const applyPanelSideEffects = (panelcontext, cmid, timertotal) => {
    stopTimer();
    stopCountdown();

    const forfeitButton = document.getElementById('playercross-forfeit-button');
    if (forfeitButton) {
        forfeitButton.hidden = Boolean(panelcontext.roundfinished);
    }

    if (panelcontext.roundfinished) {
        if (panelcontext.cooldownuntil > 0) {
            startCountdown(panelcontext.cooldownuntil, cmid);
        }
        const focusTarget = document.querySelector('#playercross-round-result button, #playercross-round-result a')
            ?? document.getElementById('playercross-round-result');
        focusTarget?.focus();
        return;
    }

    if (panelcontext.timerenabled && panelcontext.timeleft > 0) {
        startTimer(panelcontext.timeleft, timertotal, cmid);
    }

    const firstClueBox = document.querySelector('#playercross-clues-list .mod-playercross-tile-input');
    const finalBox = document.querySelector('.mod-playercross-theme .mod-playercross-tile-input');
    (firstClueBox ?? finalBox)?.focus({preventScroll: true});
};

/**
 * Renders the active-round panel into the stage.
 *
 * @param {Object} panelcontext Context matching mod_playercross/round_panel.
 * @param {number} cmid Course-module id.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const showRoundPanel = async(panelcontext, cmid, timertotal) => {
    const stage = document.getElementById('playercross-stage');
    if (!stage) {
        return;
    }
    const {html, js} = await Templates.renderForPromise('mod_playercross/round_panel', panelcontext);
    await Templates.replaceNodeContents(stage, html, js);
    applyPanelSideEffects(panelcontext, cmid, timertotal);
};

/**
 * Renders the pre-round lobby into the stage.
 *
 * @param {Object} lobbycontext Context matching mod_playercross/lobby.
 */
const showLobby = async(lobbycontext) => {
    const stage = document.getElementById('playercross-stage');
    if (!stage) {
        return;
    }
    stopTimer();
    stopCountdown();
    const {html, js} = await Templates.renderForPromise('mod_playercross/lobby', lobbycontext);
    await Templates.replaceNodeContents(stage, html, js);
    const forfeitButton = document.getElementById('playercross-forfeit-button');
    if (forfeitButton) {
        forfeitButton.hidden = true;
    }
    document.getElementById('playercross-start-round-button')?.focus();
};

/**
 * Ends the round (forfeit or timeout) via mod_playercross_end_round and applies the
 * response, without ever reloading the page.
 *
 * @param {number} cmid Course-module id.
 * @param {string} reason Either "forfeit" or "timeout".
 */
const endRound = async(cmid, reason) => {
    let payload;
    try {
        payload = await Ajax.call([{
            methodname: 'mod_playercross_end_round',
            args: {cmid, reason},
        }])[0];
    } catch (error) {
        Notification.exception(error);
        return;
    }
    notify(payload.notification, payload.notificationtype);
    if (payload.finished) {
        await showRoundPanel(payload.panel, cmid, 0);
    }
};

/**
 * Submits a clue guess via mod_playercross_submit_clue_guess. On a wrong (or
 * exhausted) guess, restores the typed text into the freshly re-rendered clue instead
 * of leaving it blank — an explicit re-send corrects a mistake without punishing the
 * player for a typo they have not yet had the chance to review.
 *
 * @param {number} cmid Course-module id.
 * @param {number} clueid Clue word id.
 * @param {string} guess Player guess text.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const submitClueGuess = async(cmid, clueid, guess, timertotal) => {
    let payload;
    try {
        payload = await Ajax.call([{
            methodname: 'mod_playercross_submit_clue_guess',
            args: {cmid, clueid, guess},
        }])[0];
    } catch (error) {
        Notification.exception(error);
        return;
    }
    notify(payload.notification, payload.notificationtype);
    if (payload.resolved) {
        announce(payload.notification);
    }
    await showRoundPanel(payload.panel, cmid, timertotal);
    if (!payload.resolved) {
        restoreClueGuess(clueid, guess);
    }
};

/**
 * Submits a direct guess of the mystery phrase via mod_playercross_submit_final_guess.
 * Same non-punitive rule as submitClueGuess: a wrong guess keeps its text in place.
 *
 * @param {number} cmid Course-module id.
 * @param {string} guess Player guess text.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const submitFinalGuess = async(cmid, guess, timertotal) => {
    let payload;
    try {
        payload = await Ajax.call([{
            methodname: 'mod_playercross_submit_final_guess',
            args: {cmid, guess},
        }])[0];
    } catch (error) {
        Notification.exception(error);
        return;
    }
    notify(payload.notification, payload.notificationtype);
    await showRoundPanel(payload.panel, cmid, timertotal);
    if (!payload.correct) {
        restoreFinalGuess(guess);
    }
};

/**
 * Reveals one mystery-phrase letter via mod_playercross_reveal_hint. A single
 * round-wide action (see round_service::reveal_hint()), not scoped to any clue, so it
 * always re-renders the whole panel exactly like a guess would.
 *
 * @param {number} cmid Course-module id.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const revealHint = async(cmid, timertotal) => {
    let payload;
    try {
        payload = await Ajax.call([{methodname: 'mod_playercross_reveal_hint', args: {cmid}}])[0];
    } catch (error) {
        Notification.exception(error);
        return;
    }
    notify(payload.notification, payload.notificationtype);
    await showRoundPanel(payload.panel, cmid, timertotal);
};

/**
 * Wires the lobby's start-round button via mod_playercross_start_round.
 *
 * @param {number} cmid Course-module id.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const wireStartRound = (cmid, timertotal) => {
    document.getElementById('playercross-stage')?.addEventListener('click', async(e) => {
        if (!e.target.closest('#playercross-start-round-button')) {
            return;
        }
        let payload;
        try {
            payload = await Ajax.call([{methodname: 'mod_playercross_start_round', args: {cmid}}])[0];
        } catch (error) {
            Notification.exception(error);
            return;
        }
        notify(payload.notification, payload.notificationtype);
        if (!payload.success) {
            return;
        }
        await showRoundPanel(payload.panel, cmid, timertotal);
    });
};

/**
 * Wires the virtual keyboard's clicks to whichever letter box last had focus.
 * Delegated once on the stage (see wireStageDelegation), since the keyboard element
 * itself is destroyed and recreated on every round-panel re-render. A no-op if no
 * box has been activated yet (see setActiveInput).
 *
 * @param {string} key The data-key value of the button that was clicked.
 */
const handleKeyboardKey = (key) => {
    if (!activeInput) {
        return;
    }
    if (key === 'BACKSPACE') {
        if (activeInput.value !== '') {
            activeInput.value = '';
            return;
        }
        const boxes = getFormBoxes(activeInput);
        const prev = boxes[boxes.indexOf(activeInput) - 1];
        if (prev) {
            prev.value = '';
            prev.focus();
        }
        return;
    }
    if (key === 'ENTER') {
        const form = activeInput.closest('form');
        if (form?.requestSubmit) {
            form.requestSubmit();
        } else {
            form?.submit();
        }
        return;
    }
    if (key === 'SPACE') {
        // Word boundaries in the mystery phrase are structural, one word group per
        // word (see buildFinalGuess) — there is nothing for this key to write.
        return;
    }
    activeInput.value = key;
    focusAdjacentBox(activeInput, 1);
};

/**
 * Wires a round-result's new-round button via mod_playercross_new_round, the global
 * hint button, click-to-activate on any guess row, the virtual keyboard, and every
 * guess form (clues and the mystery phrase alike, both share .mod-playercross-guess-
 * form) — all via event delegation on #playercross-stage, which is never itself
 * replaced across re-renders.
 *
 * @param {number} cmid Course-module id.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const wireStageDelegation = (cmid, timertotal) => {
    const stage = document.getElementById('playercross-stage');
    if (!stage) {
        return;
    }

    stage.addEventListener('click', async(e) => {
        const newRoundButton = e.target.closest('#playercross-new-round-button');
        if (newRoundButton) {
            let payload;
            try {
                payload = await Ajax.call([{methodname: 'mod_playercross_new_round', args: {cmid}}])[0];
            } catch (error) {
                Notification.exception(error);
                return;
            }
            notify(payload.notification, payload.notificationtype);
            if (!payload.hastheme) {
                stage.textContent = '';
                if (payload.notification) {
                    const alertEl = document.createElement('div');
                    alertEl.className = 'alert alert-warning';
                    alertEl.textContent = payload.notification;
                    stage.appendChild(alertEl);
                }
                return;
            }
            await showLobby(payload.lobby);
            return;
        }

        const hintButton = e.target.closest('#playercross-global-hint-button');
        if (hintButton) {
            if (!hintButton.dataset.hudConfirmBody) {
                await revealHint(cmid, timertotal);
                return;
            }
            Promise.all([
                ModalSaveCancel.create({
                    title: hintButton.dataset.hudConfirmTitle,
                    body: hintButton.dataset.hudConfirmBody,
                    show: true,
                    removeOnClose: true,
                }),
                getString('yes', 'core'),
            ]).then(([modal, yesStr]) => {
                modal.setSaveButtonText(yesStr);
                if (hintButton.dataset.hudConfirmInsufficient) {
                    modal.setButtonDisabled('save', true);
                }
                modal.getRoot().on(ModalEvents.save, () => revealHint(cmid, timertotal));
                return;
            }).catch(Notification.exception);
            return;
        }

        const keyButton = e.target.closest('#playercross-keyboard [data-key]');
        if (keyButton) {
            handleKeyboardKey(keyButton.dataset.key);
            return;
        }

        // Clicking a specific box already focuses it natively — only fall back to the
        // row's first editable box when the click landed elsewhere in the row (its
        // phrase text, a locked tile, the row's own padding), never overriding a click
        // that already targeted one particular box.
        if (e.target.closest('.mod-playercross-tile-input')) {
            return;
        }
        const activatable = e.target.closest('.mod-playercross-guess-form');
        activatable?.querySelector('.mod-playercross-tile-input')?.focus();
    });

    stage.addEventListener('focusin', (e) => {
        const input = e.target.closest('.mod-playercross-tile-input');
        if (input) {
            setActiveInput(input);
        }
    });

    stage.addEventListener('input', (e) => {
        const input = e.target.closest('.mod-playercross-tile-input');
        if (input) {
            handleBoxInput(input);
        }
    });

    // Backspace on an already-empty box moves back to the previous editable box and
    // clears it too — the 'input' listener above only fires when a box's value
    // actually changes, which an empty box's own backspace never does.
    stage.addEventListener('keydown', (e) => {
        if (e.key !== 'Backspace') {
            return;
        }
        const box = e.target.closest('.mod-playercross-tile-input');
        if (!box || box.value !== '') {
            return;
        }
        const boxes = getFormBoxes(box);
        const prev = boxes[boxes.indexOf(box) - 1];
        if (!prev) {
            return;
        }
        e.preventDefault();
        prev.value = '';
        prev.focus();
    });

    stage.addEventListener('submit', async(e) => {
        const form = e.target.closest('.mod-playercross-guess-form');
        if (!form) {
            return;
        }
        e.preventDefault();
        if (form.dataset.clueId) {
            const guess = buildClueGuess(form.querySelector('.mod-playercross-clue-tiles'));
            await submitClueGuess(cmid, Number(form.dataset.clueId), guess, timertotal);
        } else {
            const guess = buildFinalGuess(document.querySelector('.mod-playercross-theme'));
            await submitFinalGuess(cmid, guess, timertotal);
        }
    });
};

/**
 * Entry point called by view.php via $PAGE->requires->js_call_amd().
 *
 * @param {number} cooldownUntil Unix timestamp when the cooldown ends (0 = disabled).
 * @param {number} timeleft Seconds remaining in the current round (0 = no timer).
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 * @param {number} cmid Course-module id.
 * @param {boolean} shouldAutoShowIntro Whether to open the how-to-play modal once, automatically.
 */
const init = (cooldownUntil, timeleft, timertotal, cmid, shouldAutoShowIntro) => {
    initHelpModal(Boolean(shouldAutoShowIntro));
    initForfeit(cmid);
    wireStartRound(cmid, timertotal || 0);
    wireStageDelegation(cmid, timertotal || 0);
    if (timeleft > 0) {
        startTimer(timeleft, timertotal || 0, cmid);
    }
    if (cooldownUntil > 0) {
        startCountdown(cooldownUntil, cmid);
    }
};

export {init};
