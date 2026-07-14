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
 * AMD module for the live glossary-candidates preview on the settings form.
 *
 * Only wired up while creating a brand-new activity (see mod_form.php) — there is
 * no pool yet to count the normal way, but a glossary source already has real
 * entries to preview against. Re-queries
 * mod_playercross_count_glossary_candidates whenever the glossary source
 * checkbox, the glossary picker, or the minimum/maximum length fields change.
 *
 * @module     mod_playercross/glossarypreview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {getString} from 'core/str';

/** @type {?number} Handle of the pending debounce timeout, if any. */
let debounceHandle = null;

/**
 * Initialises the live glossary-candidates preview.
 *
 * @param {number} courseid Course id.
 * @param {string} sourceCheckboxId Element id of the "Glossary" source checkbox.
 * @param {string} glossarySelectId Element id of the glossary picker select.
 * @param {string} minFieldId Element id of the minimum-length field.
 * @param {string} maxFieldId Element id of the maximum-length field.
 * @param {string} stopwordsFieldId Element id of the stopwords textarea.
 * @param {string} outputId Element id of the output element the count is written into.
 */
const init = (courseid, sourceCheckboxId, glossarySelectId, minFieldId, maxFieldId, stopwordsFieldId, outputId) => {
    const sourceCheckbox = document.getElementById(sourceCheckboxId);
    const glossarySelect = document.getElementById(glossarySelectId);
    const minField = document.getElementById(minFieldId);
    const maxField = document.getElementById(maxFieldId);
    const stopwordsField = document.getElementById(stopwordsFieldId);
    const output = document.getElementById(outputId);
    if (!sourceCheckbox || !glossarySelect || !minField || !maxField || !stopwordsField || !output) {
        return;
    }

    const refresh = async() => {
        if (!sourceCheckbox.checked) {
            return;
        }

        const glossaryid = parseInt(glossarySelect.value, 10) || 0;
        const minlength = parseInt(minField.value, 10) || 0;
        const maxlength = parseInt(maxField.value, 10) || 0;
        const stopwords = stopwordsField.value;

        try {
            const result = await Ajax.call([{
                methodname: 'mod_playercross_count_glossary_candidates',
                args: {courseid, glossaryid, minlength, maxlength, stopwords},
            }])[0];
            output.textContent = await getString('glossarywordscount', 'mod_playercross', result.count);
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

    sourceCheckbox.addEventListener('change', scheduleRefresh);
    glossarySelect.addEventListener('change', scheduleRefresh);
    minField.addEventListener('input', scheduleRefresh);
    maxField.addEventListener('input', scheduleRefresh);
    stopwordsField.addEventListener('input', scheduleRefresh);
    refresh();
};

export {init};
