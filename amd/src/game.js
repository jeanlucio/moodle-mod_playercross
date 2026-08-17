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
 * term answer stay server-side the whole time, only the updated round panel (theme
 * tiles, term rows, reveal-once-finished fields) comes back on each response. Every
 * delegated listener is attached once, on #playercross-stage, at init() time: the
 * stage element itself is never replaced across re-renders (only its contents, via
 * Templates.replaceNodeContents()), so delegation survives every AJAX round-trip
 * without needing to be rewired.
 *
 * Every still-hidden letter of a guess row (a term's own word, or the mystery phrase)
 * is its own real single-character <input> — a locked, already-revealed letter is a
 * plain, non-focusable <span> instead (inputmode="none" on every box, so the device's
 * own keyboard never appears). A single virtual keyboard writes into whichever box
 * last received focus, tracked in activeInput below, then advances focus to the next
 * hidden box in that row automatically — locked letters are skipped, since they were
 * never boxes to begin with. Clicking a specific box focuses exactly that one (native
 * browser behaviour), so a single wrong letter can be fixed without retyping the rest.
 * The physical Left/Right arrow keys move focus one editable box over, the same
 * verification-code-input convention as a bank 2FA field; Up/Down move to the same
 * column in the row above/below, clamped to a shorter target row's own length — the
 * mystery phrase counts as the topmost row, so Up from the first term reaches it —
 * mirroring how real crossword apps let arrow keys move both along and across an
 * answer (see the stage's own keydown listener, and focusAdjacentBox/
 * focusAdjacentRow). All four take this over from the browser's own default (moving
 * the text caret inside a single-character box, effectively a no-op there).
 * A guess is assembled at submit time by reading every tile in a row, in order: a
 * locked span's own letter, or a box's typed value (see buildTermGuess/
 * buildFinalGuess). A guess is confirmed via a physical Enter key or the row's own
 * submit button (each row's <form> carries one, see round_play.mustache/
 * round_panel.mustache) — that button stays hidden (.pc-row-submit, still tab-reachable)
 * until every one of the row's own boxes is filled, at which point refreshRowReadiness
 * reveals it right where the player is already looking. Usability testing showed
 * players otherwise assumed every row had to be completed before anything could be
 * submitted, since nothing on screen suggested a single row could be checked on its
 * own; earlier fixes tried a submit key on the shared virtual keyboard (plain, then
 * pulsing) before settling on this per-row button — the on-screen keyboard itself has
 * no Enter/submit key of its own any more, matching the original game this plugin is
 * based on (see .plans/PlayerCross.jpeg): only letters and Backspace.
 *
 * Submitting any one row (or revealing a hint) re-renders the *whole* panel from the
 * server's response — the only state the server knows about. Whatever the player had
 * typed into every other still-open row, correct or not, is client-only and would
 * otherwise vanish the instant that fresh HTML replaces the old. snapshotInProgressGuesses/
 * restoreInProgressGuesses close that gap by capturing every row before the request and
 * writing it back into the matching boxes afterwards — real usability testing surfaced
 * this as guesses "disappearing" when several rows were typed ahead before submitting any
 * one of them.
 *
 * Long-pressing a keyboard key with accent variants (A, E, I, O, U — see
 * initAccentLongPress/ACCENT_VARIANTS) opens a popup to type the accented form
 * instead, mirroring a phone's own native long-press-for-diacritics keyboard.
 * Matching stays accent-insensitive throughout (word_normalizer::normalize()) — this
 * is purely a typing convenience, touch-only, never a requirement to guess correctly.
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
import {add as addToast} from 'core/toast';

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
 * Shows visible player feedback, either as an auto-dismissing toast or as a persistent
 * Moodle notification the player must close themselves. round_service flags every
 * round-flow message (wrong guess, hint revealed, round won, forfeited, timed out...)
 * as toast-worthy: a persistent notification never clears on its own, so a wrong-guess
 * warning could still be on screen next to a later "round won" message, reading as
 * contradictory feedback. The persistent path stays available here for anything that
 * is not part of the fast-paced round flow — e.g. Ajax.call() failures routed through
 * Notification.exception() elsewhere in this module.
 *
 * @param {string} message Notification text.
 * @param {string} type Notification type: success, info, warning or error.
 * @param {boolean} [toast] Whether the server flagged this message as toast-worthy.
 */
const notify = (message, type, toast) => {
    if (!message) {
        return;
    }
    if (toast) {
        addToast(message, {type: type || 'success'});
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
 * Removes the accent long-press tip from the pre-rendered help content on devices
 * without touch support, where initAccentLongPress never wires the gesture at all
 * (its own listeners are touch-only) — showing the tip there would describe a feature
 * that does not exist on that device.
 *
 * @param {HTMLElement} content Hidden container holding the pre-rendered help body.
 */
const pruneAccentTipForNonTouch = (content) => {
    const supportsTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    if (supportsTouch) {
        return;
    }
    content.querySelector('.mod-playercross-help-accents')?.remove();
};

/**
 * Opens the how-to-play content (already server-rendered into
 * #playercross-help-content) in a modal.
 *
 * @param {HTMLElement} button Help toolbar button, source of the modal title.
 * @param {HTMLElement} content Hidden container holding the pre-rendered help body.
 */
const openHelpModal = async(button, content) => {
    try {
        await Modal.create({
            title: button.dataset.title,
            body: content.innerHTML,
            show: true,
            removeOnClose: true,
        });
    } catch (error) {
        Notification.exception(error);
    }
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
    pruneAccentTipForNonTouch(content);
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
    button.addEventListener('click', async() => {
        try {
            const [modal, yesStr] = await Promise.all([
                ModalSaveCancel.create({
                    title: button.dataset.title,
                    body: button.dataset.confirm,
                    removeOnClose: true,
                }),
                getString('yes', 'core'),
            ]);
            modal.setSaveButtonText(yesStr);
            modal.getRoot().on(ModalEvents.save, () => {
                endRound(cmid, 'forfeit');
            });
            modal.show();
        } catch (error) {
            Notification.exception(error);
        }
    });
};

/**
 * Returns every tile-wrap element within a scope, in position order — one per letter,
 * whether that position is a locked (already-revealed) tile or an editable box.
 *
 * @param {HTMLElement} scope A term's tiles container, or one mystery-phrase word group.
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
 * Assembles a term's full guess from its tile row: each locked tile's own letter, or
 * each editable box's typed letter, in position order.
 *
 * @param {HTMLElement} tilesContainer A term's .mod-playercross-term-tiles element.
 * @returns {string}
 */
const buildTermGuess = (tilesContainer) => getTileWraps(tilesContainer).map(readTileWrap).join('');

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
 * fresh tile-wraps one for one. Re-evaluates the row's own submit button afterwards
 * (see refreshRowReadiness) — the only place that needs to happen, since every caller
 * (restoreTermGuess, restoreFinalGuess, restoreInProgressGuesses) only ever changes box
 * values through here.
 *
 * @param {HTMLElement} scope A term's tiles container, or one word group.
 * @param {string[]} chars Characters to distribute, one per tile-wrap in scope.
 */
const distributeIntoWraps = (scope, chars) => {
    getTileWraps(scope).forEach((wrap, i) => {
        const box = wrap.querySelector('.mod-playercross-tile-input');
        if (box && chars[i] !== undefined) {
            box.value = chars[i].toUpperCase();
        }
    });
    refreshRowReadiness(scope.closest('.mod-playercross-guess-form'));
};

/**
 * Restores a term's guess into its freshly re-rendered boxes after a wrong (or
 * exhausted) submission, and focuses its first editable box. A no-op once the term is
 * actually resolved or the round finished — canguess is then false server-side, so no
 * matching form exists to restore into.
 *
 * @param {number} termid Term word id.
 * @param {string} guess The guess text the player had submitted.
 */
const restoreTermGuess = (termid, guess) => {
    const tilesContainer = document.querySelector(`.mod-playercross-term-tiles[data-term-tiles="${termid}"]`);
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
 * Snapshots every guess row's currently typed content right before a submission (term
 * guess, final guess, or hint reveal) triggers a full panel re-render. Every row that is
 * *not* the one being submitted has no way to send its own in-progress typing along with
 * that request — without this snapshot, the fresh panel HTML silently replaces it with
 * blank boxes, discarding whatever the player had already typed there, right or wrong.
 * Read at the very start of the caller, before the AJAX call, since nothing else touches
 * these boxes while that request is in flight.
 *
 * Stored as one raw character array per row/word-group — never joined into a single
 * string. A partially-filled row (unlike the row actually being submitted, its boxes
 * carry no "required" validation) can have blank positions anywhere in the middle;
 * joining would silently drop those blanks and shift every later letter into the wrong
 * box once restored. distributeIntoWraps() already takes chars by array, so this needs
 * no format conversion of its own.
 *
 * @returns {{terms: Map<number, string[]>, theme: ?string[][]}}
 */
const snapshotInProgressGuesses = () => {
    const terms = new Map();
    document.querySelectorAll('.mod-playercross-term-form[data-term-id]').forEach((form) => {
        const tilesContainer = form.querySelector('.mod-playercross-term-tiles');
        if (tilesContainer) {
            terms.set(Number(form.dataset.termId), getTileWraps(tilesContainer).map(readTileWrap));
        }
    });
    const themeContainer = document.querySelector('.mod-playercross-theme');
    const theme = themeContainer
        ? Array.from(themeContainer.querySelectorAll('.mod-playercross-word-group'))
            .map((group) => getTileWraps(group).map(readTileWrap))
        : null;
    return {terms, theme};
};

/**
 * Restores every *other* row's snapshotted in-progress guess (see
 * snapshotInProgressGuesses) into the freshly re-rendered panel, after a different row's
 * submission replaced the whole stage. Skips whichever row the caller already restored
 * itself — submitTermGuess/submitFinalGuess only do that on a wrong guess, and with their
 * own extra focus/shake side effects, so this never duplicates that. A no-op per row if
 * it no longer has an editable form (resolved, exhausted, or the round just finished) or
 * its snapshot was entirely blank.
 *
 * @param {{terms: Map<number, string[]>, theme: ?string[][]}} snapshot From snapshotInProgressGuesses().
 * @param {?number} skipTermId Term id already restored by the caller, if any.
 * @param {boolean} skipTheme Whether the theme row was already restored by the caller.
 */
const restoreInProgressGuesses = (snapshot, skipTermId, skipTheme) => {
    snapshot.terms.forEach((chars, termid) => {
        if (termid === skipTermId || chars.every((char) => char === '')) {
            return;
        }
        const tilesContainer = document.querySelector(`.mod-playercross-term-tiles[data-term-tiles="${termid}"]`);
        if (tilesContainer) {
            distributeIntoWraps(tilesContainer, chars);
        }
    });
    if (skipTheme || !snapshot.theme) {
        return;
    }
    const themeContainer = document.querySelector('.mod-playercross-theme');
    if (!themeContainer) {
        return;
    }
    Array.from(themeContainer.querySelectorAll('.mod-playercross-word-group')).forEach((group, i) => {
        const chars = snapshot.theme[i];
        if (chars && !chars.every((char) => char === '')) {
            distributeIntoWraps(group, chars);
        }
    });
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
 * Returns every guess form currently on screen, in DOM order — the mystery phrase's
 * own form always first (round_panel.mustache renders it, then includes round_play,
 * which lists the terms), then each term in the same top-to-bottom order the player
 * sees. Used by Up/Down row navigation; a row whose form does not exist right now
 * (a resolved term, canguess false) is naturally absent, since no such <form> renders.
 *
 * @returns {HTMLElement[]}
 */
const getAllRows = () => Array.from(document.querySelectorAll('.mod-playercross-guess-form'));

/**
 * Moves focus to the equivalent box — same column index — in the row immediately
 * above or below the given box's own row, clamped to the target row's own length
 * when it has fewer editable boxes than the current one. A no-op past either end (no
 * wrap, matching focusAdjacentBox()) or if the target row currently has no editable
 * box at all (its own guess already fully revealed, but the round has not finished).
 *
 * @param {HTMLElement} box A .mod-playercross-tile-input element.
 * @param {number} offset -1 for the row above, 1 for the row below.
 */
const focusAdjacentRow = (box, offset) => {
    const rows = getAllRows();
    const form = box.closest('.mod-playercross-guess-form');
    const targetForm = rows[rows.indexOf(form) + offset];
    if (!targetForm) {
        return;
    }
    const columnIndex = getFormBoxes(box).indexOf(box);
    const targetBoxes = Array.from(targetForm.querySelectorAll('.mod-playercross-tile-input'));
    targetBoxes[Math.min(columnIndex, targetBoxes.length - 1)]?.focus();
};

/**
 * Toggles a guess row's own submit button into its visible "ready" state exactly when
 * every one of its editable boxes now holds a letter — the visual cue that this one
 * word can be checked right away, without filling any other row first (see
 * help_terms/help_finalguess). Scoped to the row itself rather than to whichever box
 * last had focus, so a row silently completed by restoreTermGuess/restoreFinalGuess/
 * restoreInProgressGuesses (see distributeIntoWraps, which calls this after every
 * write) shows its own button too, not only the row the player is actively typing in.
 *
 * @param {?HTMLElement} form A .mod-playercross-guess-form element, or null.
 */
const refreshRowReadiness = (form) => {
    const submitButton = form?.querySelector('.pc-row-submit');
    if (!submitButton) {
        return;
    }
    const boxes = Array.from(form.querySelectorAll('.mod-playercross-tile-input'));
    submitButton.classList.toggle('is-ready', boxes.length > 0 && boxes.every((box) => box.value !== ''));
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
    refreshRowReadiness(box.closest('.mod-playercross-guess-form'));
};

/**
 * Marks the guess row containing the focused box as active (amber highlight) — a
 * term's own <li> card, or the mystery phrase's <form> when that is the target —
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
    document.querySelectorAll('.mod-playercross-term.is-active, .mod-playercross-theme-form.is-active').forEach((el) => {
        el.classList.remove('is-active');
    });
    const row = input.closest('.mod-playercross-term') ?? input.closest('.mod-playercross-theme-form');
    row?.classList.add('is-active');
    input.select();
};

/**
 * Marks every on-screen keyboard key whose letter is already known round-wide (see
 * round_presenter::build_revealed_letters()), so the player can tell at a glance which
 * letters no longer need to be tried. Reads the revealed-letter set from the
 * keyboard's own data-revealed-letters attribute rather than rescanning rendered
 * tiles: a fully solved term collapses its own tile grid into plain text
 * (.mod-playercross-term-answer, round_play.mustache's resolved branch), leaving no
 * tiles left to scan for it, so the server-computed set is the only reliable source.
 * A no-op once the round is finished, since the keyboard itself is not rendered then.
 */
const markRevealedKeys = () => {
    const keyboard = document.getElementById('playercross-keyboard');
    if (!keyboard) {
        return;
    }

    let letters = [];
    try {
        letters = JSON.parse(keyboard.dataset.revealedLetters ?? '[]');
    } catch {
        letters = [];
    }

    keyboard.querySelectorAll('[data-key]').forEach((btn) => {
        btn.classList.toggle('is-revealed', letters.includes(btn.dataset.key));
    });
};

/**
 * Applies the side effects that must run after every stage re-render: the round or
 * cooldown countdown, the forfeit button's visibility, moving focus to the first
 * pending term (or the mystery-phrase input, if every term is resolved) so continuous
 * typing can carry straight on from one guess to the next, and marking already-known
 * letters on the virtual keyboard.
 *
 * @param {Object} panelcontext Context matching mod_playercross/round_panel.
 * @param {number} cmid Course-module id.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const applyPanelSideEffects = (panelcontext, cmid, timertotal) => {
    stopTimer();
    stopCountdown();
    markRevealedKeys();

    const forfeitButton = document.getElementById('playercross-forfeit-button');
    if (forfeitButton) {
        forfeitButton.hidden = Boolean(panelcontext.roundfinished);
    }

    const timerWrapper = document.getElementById('playercross-timer-wrapper');
    if (timerWrapper) {
        timerWrapper.hidden = !panelcontext.timerenabled || Boolean(panelcontext.roundfinished);
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

    const firstTermBox = document.querySelector('#playercross-terms-list .mod-playercross-tile-input');
    const finalBox = document.querySelector('.mod-playercross-theme .mod-playercross-tile-input');
    (firstTermBox ?? finalBox)?.focus({preventScroll: true});
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
    const timerWrapper = document.getElementById('playercross-timer-wrapper');
    if (timerWrapper) {
        timerWrapper.hidden = true;
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
    notify(payload.notification, payload.notificationtype, payload.toast);
    if (payload.finished) {
        await showRoundPanel(payload.panel, cmid, 0);
    }
};

/**
 * Briefly shakes and reddens a term's card to give an unmistakable visual cue that its
 * last guess was wrong — the toast notification alone (see notify()) is easy to miss
 * in a fast-paced typing flow. A no-op if the card no longer exists, e.g. because the
 * round just ended (a term running out of attempts can finish the round on its own —
 * see round_service::submit_term_guess()).
 *
 * @param {number} termid Term word id.
 */
const flashWrongTerm = (termid) => {
    const card = document.querySelector(`.mod-playercross-term[data-term-id="${termid}"]`);
    if (!card) {
        return;
    }
    card.classList.add('is-wrong');
    card.addEventListener('animationend', () => card.classList.remove('is-wrong'), {once: true});
};

/**
 * Submits a term guess via mod_playercross_submit_term_guess. On a wrong (or
 * exhausted) guess, restores the typed text into the freshly re-rendered term instead
 * of leaving it blank — an explicit re-send corrects a mistake without punishing the
 * player for a typo they have not yet had the chance to review — and flashes the card
 * (see flashWrongTerm) so a wrong guess is never silently invisible.
 *
 * @param {number} cmid Course-module id.
 * @param {number} termid Term word id.
 * @param {string} guess Player guess text.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const submitTermGuess = async(cmid, termid, guess, timertotal) => {
    const snapshot = snapshotInProgressGuesses();
    let payload;
    try {
        payload = await Ajax.call([{
            methodname: 'mod_playercross_submit_term_guess',
            args: {cmid, termid, guess},
        }])[0];
    } catch (error) {
        Notification.exception(error);
        return;
    }
    notify(payload.notification, payload.notificationtype, payload.toast);
    if (payload.resolved) {
        announce(payload.notification);
    }
    await showRoundPanel(payload.panel, cmid, timertotal);
    if (!payload.resolved) {
        restoreTermGuess(termid, guess);
        flashWrongTerm(termid);
    }
    restoreInProgressGuesses(snapshot, termid, false);
};

/**
 * Submits a direct guess of the mystery phrase via mod_playercross_submit_final_guess.
 * Same non-punitive rule as submitTermGuess: a wrong guess keeps its text in place.
 *
 * @param {number} cmid Course-module id.
 * @param {string} guess Player guess text.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const submitFinalGuess = async(cmid, guess, timertotal) => {
    const snapshot = snapshotInProgressGuesses();
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
    notify(payload.notification, payload.notificationtype, payload.toast);
    await showRoundPanel(payload.panel, cmid, timertotal);
    if (!payload.correct) {
        restoreFinalGuess(guess);
    }
    restoreInProgressGuesses(snapshot, null, true);
};

/**
 * Reveals one mystery-phrase letter via mod_playercross_reveal_hint. A single
 * round-wide action (see round_service::reveal_hint()), not scoped to any term, so it
 * always re-renders the whole panel exactly like a guess would.
 *
 * @param {number} cmid Course-module id.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const revealHint = async(cmid, timertotal) => {
    const snapshot = snapshotInProgressGuesses();
    let payload;
    try {
        payload = await Ajax.call([{methodname: 'mod_playercross_reveal_hint', args: {cmid}}])[0];
    } catch (error) {
        Notification.exception(error);
        return;
    }
    notify(payload.notification, payload.notificationtype, payload.toast);
    await showRoundPanel(payload.panel, cmid, timertotal);
    restoreInProgressGuesses(snapshot, null, false);
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
        notify(payload.notification, payload.notificationtype, payload.toast);
        if (!payload.success) {
            return;
        }
        await showRoundPanel(payload.panel, cmid, timertotal);
    });
};

/**
 * Writes one letter into whichever box last had focus and advances to the next one —
 * shared by a normal keyboard tap and an accent-popup selection, so both go through
 * the exact same write-and-advance step. A no-op if no box has been activated yet.
 *
 * @param {string} letter Single letter to write.
 */
const writeLetterIntoActiveBox = (letter) => {
    if (!activeInput) {
        return;
    }
    const box = activeInput;
    box.value = letter;
    focusAdjacentBox(box, 1);
    refreshRowReadiness(box.closest('.mod-playercross-guess-form'));
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
        const form = activeInput.closest('.mod-playercross-guess-form');
        if (activeInput.value !== '') {
            activeInput.value = '';
            refreshRowReadiness(form);
            return;
        }
        const boxes = getFormBoxes(activeInput);
        const prev = boxes[boxes.indexOf(activeInput) - 1];
        if (prev) {
            prev.value = '';
            prev.focus();
            refreshRowReadiness(form);
        }
        return;
    }
    writeLetterIntoActiveBox(key);
};

/**
 * Accent variants offered by the long-press popup, keyed by the base letter's own
 * keyboard key. Matching is accent-insensitive throughout the game (see
 * word_normalizer::normalize()) — this is purely a typing convenience so students can
 * practise proper accented spelling, never a requirement to guess correctly. Covers
 * Portuguese, Spanish, French, Italian and German diacritics; other alphabets (Nordic,
 * Slavic, Turkish, Icelandic...) are out of scope, see mod_playercross's SCOPE.md §3.2.
 *
 * @type {Object<string, string[]>}
 */
const ACCENT_VARIANTS = {
    A: ['Á', 'À', 'Â', 'Ã', 'Ä'],
    E: ['É', 'È', 'Ê', 'Ë'],
    I: ['Í', 'Ì', 'Î', 'Ï'],
    O: ['Ó', 'Ò', 'Ô', 'Õ', 'Ö'],
    U: ['Ú', 'Ù', 'Û', 'Ü'],
    C: ['Ç'],
    N: ['Ñ'],
    S: ['ß'],
};

/** @type {number} Touch hold duration, in ms, before the accent popup appears. */
const ACCENT_LONG_PRESS_MS = 450;

/** @type {?HTMLElement} The accent popup currently on screen, if any. */
let accentPopup = null;

/**
 * Removes the accent popup, if one is currently shown. Safe to call unconditionally.
 */
const removeAccentPopup = () => {
    if (accentPopup) {
        accentPopup.remove();
        accentPopup = null;
    }
};

/**
 * Marks one accent-popup option as the one that will be committed on release, mirroring
 * a phone's own native long-press-for-diacritics keyboard, where sliding a finger
 * across the popup before lifting it picks whichever option is currently underneath.
 *
 * @param {HTMLElement} popup The accent popup element.
 * @param {HTMLElement} target The option to highlight.
 */
const highlightAccentOption = (popup, target) => {
    popup.querySelectorAll('.pc-accent-option').forEach((opt) => {
        opt.classList.toggle('is-active', opt === target);
    });
};

/**
 * Builds and positions the accent popup above the long-pressed key, options being the
 * plain base letter (pre-selected, so a long press released without sliding still
 * types the same letter a normal tap would) followed by each accented variant.
 *
 * @param {HTMLElement} keyboard The keyboard container — the popup's own positioning parent.
 * @param {HTMLElement} btn The long-pressed key button.
 * @param {string} baseLetter The key's own base letter, e.g. "E".
 */
const showAccentPopup = (keyboard, btn, baseLetter) => {
    removeAccentPopup();
    const popup = document.createElement('div');
    popup.className = 'pc-accent-popup';
    [baseLetter, ...ACCENT_VARIANTS[baseLetter]].forEach((letter, i) => {
        const opt = document.createElement('button');
        opt.type = 'button';
        opt.tabIndex = -1;
        opt.className = 'pc-accent-option' + (i === 0 ? ' is-active' : '');
        opt.textContent = letter;
        opt.dataset.letter = letter;
        popup.appendChild(opt);
    });

    const btnRect = btn.getBoundingClientRect();
    const kbRect = keyboard.getBoundingClientRect();
    popup.style.left = `${btnRect.left - kbRect.left + (btnRect.width / 2)}px`;
    popup.style.top = `${btnRect.top - kbRect.top}px`;
    keyboard.appendChild(popup);

    // Keys near either edge of the keyboard (A is the leftmost key with variants)
    // would otherwise centre the popup partly off-screen — nudge it back in.
    const popupRect = popup.getBoundingClientRect();
    if (popupRect.left < kbRect.left) {
        popup.style.left = `${parseFloat(popup.style.left) + (kbRect.left - popupRect.left) + 4}px`;
    } else if (popupRect.right > kbRect.right) {
        popup.style.left = `${parseFloat(popup.style.left) - (popupRect.right - kbRect.right) - 4}px`;
    }

    accentPopup = popup;
};

/**
 * Wires the accent-popup long-press gesture on every keyboard key that has variants
 * (see ACCENT_VARIANTS), delegated once on the stage — same rationale as
 * wireStageDelegation itself, since #playercross-stage is never replaced across
 * re-renders. Touch-only by nature: a long press has no equivalent on a physical
 * keyboard, which can already type accents through the operating system, so desktop
 * typing is entirely unaffected. A normal (short) tap still falls through to the
 * stage's own click handler exactly as before.
 *
 * @param {HTMLElement} stage The #playercross-stage element.
 */
const initAccentLongPress = (stage) => {
    let pressTimer = null;
    let longPressActive = false;

    const clearPressTimer = () => {
        if (pressTimer) {
            window.clearTimeout(pressTimer);
            pressTimer = null;
        }
    };

    const endLongPress = (commit) => {
        if (commit && accentPopup) {
            const active = accentPopup.querySelector('.pc-accent-option.is-active');
            if (active) {
                writeLetterIntoActiveBox(active.dataset.letter);
            }
        }
        removeAccentPopup();
        longPressActive = false;
    };

    stage.addEventListener('touchstart', (e) => {
        const btn = e.target.closest('#playercross-keyboard [data-key]');
        const baseLetter = btn?.dataset.key;
        if (!baseLetter || !ACCENT_VARIANTS[baseLetter]) {
            return;
        }
        const keyboard = document.getElementById('playercross-keyboard');
        clearPressTimer();
        pressTimer = window.setTimeout(() => {
            longPressActive = true;
            showAccentPopup(keyboard, btn, baseLetter);
        }, ACCENT_LONG_PRESS_MS);
    }, {passive: true});

    stage.addEventListener('touchmove', (e) => {
        if (!longPressActive || !accentPopup) {
            return;
        }
        // Backs up the long-press keys' own touch-action: none (see styles.css) —
        // without this the page could still scroll under the player's finger while
        // they slide across the accent options.
        e.preventDefault();
        const touch = e.touches[0];
        const option = document.elementFromPoint(touch.clientX, touch.clientY)?.closest('.pc-accent-option');
        if (option) {
            highlightAccentOption(accentPopup, option);
        }
    });

    stage.addEventListener('touchend', (e) => {
        clearPressTimer();
        if (longPressActive) {
            // Suppresses the synthetic click touchend would otherwise fire next,
            // which would type the plain base letter a second time.
            e.preventDefault();
            endLongPress(true);
        }
    });

    stage.addEventListener('touchcancel', () => {
        clearPressTimer();
        endLongPress(false);
    });
};

/**
 * Wires a round-result's new-round button via mod_playercross_new_round, the global
 * hint button, click-to-activate on any guess row, the virtual keyboard (including its
 * accent long-press popup, see initAccentLongPress), and every guess form (terms and
 * the mystery phrase alike, both share .mod-playercross-guess-form) — all via event
 * delegation on #playercross-stage, which is never itself replaced across re-renders.
 *
 * @param {number} cmid Course-module id.
 * @param {number} timertotal Total seconds configured for the round (0 = no timer).
 */
const wireStageDelegation = (cmid, timertotal) => {
    const stage = document.getElementById('playercross-stage');
    if (!stage) {
        return;
    }

    initAccentLongPress(stage);

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
            try {
                const [modal, yesStr] = await Promise.all([
                    ModalSaveCancel.create({
                        title: hintButton.dataset.hudConfirmTitle,
                        body: hintButton.dataset.hudConfirmBody,
                        removeOnClose: true,
                    }),
                    getString('yes', 'core'),
                ]);
                modal.setSaveButtonText(yesStr);
                if (hintButton.dataset.hudConfirmInsufficient) {
                    modal.setButtonDisabled('save', true);
                }
                modal.getRoot().on(ModalEvents.save, () => revealHint(cmid, timertotal));
                modal.show();
            } catch (error) {
                Notification.exception(error);
            }
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

    // Left/Right move focus one editable box over, without touching either box's own
    // value — the standard verification-code-input convention. Harmless to take over
    // from the browser's own default (moving the text caret before/after the single
    // character a maxlength="1" box can ever hold), and reuses focusAdjacentBox() so
    // locked/revealed tiles are skipped exactly like Tab already skips them. Up/Down
    // move to the equivalent column in the row above/below (see focusAdjacentRow) —
    // the mystery phrase counts as the topmost row — mirroring how real crossword
    // apps let arrow keys move both along and across an answer.
    //
    // Backspace on an already-empty box moves back to the previous editable box and
    // clears it too — the 'input' listener above only fires when a box's value
    // actually changes, which an empty box's own backspace never does.
    stage.addEventListener('keydown', (e) => {
        const box = e.target.closest('.mod-playercross-tile-input');
        if (!box) {
            return;
        }
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
            e.preventDefault();
            focusAdjacentBox(box, e.key === 'ArrowLeft' ? -1 : 1);
            return;
        }
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            focusAdjacentRow(box, e.key === 'ArrowUp' ? -1 : 1);
            return;
        }
        if (e.key !== 'Backspace' || box.value !== '') {
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
        refreshRowReadiness(prev.closest('.mod-playercross-guess-form'));
    });

    stage.addEventListener('submit', async(e) => {
        const form = e.target.closest('.mod-playercross-guess-form');
        if (!form) {
            return;
        }
        e.preventDefault();
        if (form.dataset.termId) {
            const guess = buildTermGuess(form.querySelector('.mod-playercross-term-tiles'));
            await submitTermGuess(cmid, Number(form.dataset.termId), guess, timertotal);
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
    markRevealedKeys();
    if (timeleft > 0) {
        startTimer(timeleft, timertotal || 0, cmid);
    }
    if (cooldownUntil > 0) {
        startCountdown(cooldownUntil, cmid);
    }
};

export {init};
