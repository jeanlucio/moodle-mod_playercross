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
 * @param {number} timertotal Total seconds configured for the round.
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
 * Applies the side effects that must run after every stage re-render: the round or
 * cooldown countdown, the forfeit button's visibility, and moving focus somewhere
 * sensible.
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
    document.getElementById('playercross-final-guess')?.focus({preventScroll: true});
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
 * Submits a clue guess via mod_playercross_submit_clue_guess.
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
};

/**
 * Submits a direct guess of the mystery phrase via mod_playercross_submit_final_guess.
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
};

/**
 * Reveals a clue's hint via mod_playercross_reveal_hint.
 *
 * @param {number} cmid Course-module id.
 * @param {number} clueid Clue word id.
 */
const revealHint = async(cmid, clueid) => {
    let payload;
    try {
        payload = await Ajax.call([{methodname: 'mod_playercross_reveal_hint', args: {cmid, clueid}}])[0];
    } catch (error) {
        Notification.exception(error);
        return;
    }
    notify(payload.notification, payload.notificationtype);
    if (!payload.success) {
        return;
    }
    const clueItem = document.querySelector(`#playercross-clues-list li[data-clue-id="${clueid}"]`);
    announce(payload.hintvalue);
    if (!clueItem) {
        return;
    }
    let hintEl = clueItem.querySelector('.mod-playercross-clue-hint');
    if (!hintEl) {
        hintEl = document.createElement('p');
        hintEl.className = 'mod-playercross-clue-hint text-muted small mb-0';
        clueItem.appendChild(hintEl);
    }
    hintEl.textContent = payload.hintvalue;
    clueItem.querySelector('.playercross-hint-button')?.remove();
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
 * Wires a round-result's new-round button via mod_playercross_new_round, and the
 * clue/final-guess forms and hint buttons via event delegation. All delegated on
 * #playercross-stage, which is never itself replaced across re-renders.
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

        const hintButton = e.target.closest('.playercross-hint-button');
        if (hintButton) {
            const clueid = Number(hintButton.dataset.clueId);
            if (!hintButton.dataset.hudConfirmBody) {
                await revealHint(cmid, clueid);
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
                modal.getRoot().on(ModalEvents.save, () => revealHint(cmid, clueid));
                return;
            }).catch(Notification.exception);
        }
    });

    stage.addEventListener('submit', async(e) => {
        const clueForm = e.target.closest('.mod-playercross-clue-form');
        if (clueForm) {
            e.preventDefault();
            const clueid = Number(clueForm.dataset.clueId);
            const input = clueForm.querySelector('input');
            const guess = input.value;
            input.value = '';
            await submitClueGuess(cmid, clueid, guess, timertotal);
            return;
        }

        if (e.target.id === 'playercross-final-guess-form') {
            e.preventDefault();
            const input = document.getElementById('playercross-final-guess');
            const guess = input.value;
            input.value = '';
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
