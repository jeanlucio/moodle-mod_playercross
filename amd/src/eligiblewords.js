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
 * AMD module for the live "eligible words" count on the settings form.
 *
 * Only wired up while editing an already-created activity (see mod_form.php) — on
 * creation there is no pool yet to count. Re-queries mod_playercross_count_eligible_words
 * whenever the minimum/maximum length fields change, so a teacher narrowing the range
 * sees immediately how many approved pool words would still qualify, before saving.
 *
 * @module     mod_playercross/eligiblewords
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {getString} from 'core/str';

/** @type {?number} Handle of the pending debounce timeout, if any. */
let debounceHandle = null;

/**
 * Initialises the live eligible-words count.
 *
 * @param {number} cmid Course-module id.
 * @param {string} minFieldId Element id of the minimum-length field.
 * @param {string} maxFieldId Element id of the maximum-length field.
 * @param {string} outputId Element id of the output span the count is written into.
 */
const init = (cmid, minFieldId, maxFieldId, outputId) => {
    const minField = document.getElementById(minFieldId);
    const maxField = document.getElementById(maxFieldId);
    const output = document.getElementById(outputId);
    if (!minField || !maxField || !output) {
        return;
    }

    const refresh = async() => {
        const minlength = parseInt(minField.value, 10) || 0;
        const maxlength = parseInt(maxField.value, 10) || 0;

        try {
            const result = await Ajax.call([{
                methodname: 'mod_playercross_count_eligible_words',
                args: {cmid, minlength, maxlength},
            }])[0];
            output.textContent = await getString('eligiblewordscount', 'mod_playercross', result.count);
        } catch (error) {
            Notification.exception(error);
        }
    };

    const scheduleRefresh = () => {
        if (debounceHandle) {
            clearTimeout(debounceHandle);
        }
        debounceHandle = setTimeout(refresh, 300);
    };

    minField.addEventListener('input', scheduleRefresh);
    maxField.addEventListener('input', scheduleRefresh);
    refresh();
};

export {init};
