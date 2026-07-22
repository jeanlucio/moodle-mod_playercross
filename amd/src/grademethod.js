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
 * AMD module for mod_playercross activity settings form.
 *
 * Removes the "Average over all required rounds" grading method from the
 * grademethod select whenever "Maximum rounds per student" is set to Unlimited,
 * since that grading method has no valid denominator without a configured
 * round limit. Restores the option when a limit is chosen again.
 *
 * @module     mod_playercross/grademethod
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    /**
     * Initialises the max_rounds / grademethod interaction.
     *
     * @param {string} maxRoundsId Element id of the max_rounds select.
     * @param {string} gradeMethodId Element id of the grademethod select.
     * @param {string} averageAllValue Option value of the average-over-all-rounds method.
     * @param {string} fallbackValue Option value to select if the current one becomes unavailable.
     */
    const init = (maxRoundsId, gradeMethodId, averageAllValue, fallbackValue) => {
        const maxRounds = document.getElementById(maxRoundsId);
        const gradeMethod = document.getElementById(gradeMethodId);
        // Once the activity has a real grade, mod_form.php freezes grademethod: Moodle
        // then renders it as a hidden <input> carrying the same id instead of a
        // <select> (MoodleQuickForm_select::getFrozenHtml()), so this guard must reject
        // that case too — reading .options off it would throw and leave
        // M.util.js_pending('mod_playercross/grademethod') stuck forever, since
        // js_call_amd() calls M.util.js_complete() only after init() returns normally.
        if (!maxRounds || !gradeMethod || gradeMethod.tagName !== 'SELECT') {
            return;
        }

        const averageAllOption = Array.from(gradeMethod.options)
            .find(option => option.value === averageAllValue);
        if (!averageAllOption) {
            return;
        }

        const originalIndex = Array.from(gradeMethod.options).indexOf(averageAllOption);

        const sync = () => {
            const unlimited = maxRounds.value === '0';
            const attached = averageAllOption.parentNode === gradeMethod;
            if (unlimited && attached) {
                if (gradeMethod.value === averageAllValue) {
                    gradeMethod.value = fallbackValue;
                }
                averageAllOption.remove();
            } else if (!unlimited && !attached) {
                const referenceOption = gradeMethod.options[originalIndex] ?? null;
                gradeMethod.add(averageAllOption, referenceOption);
            }
        };

        maxRounds.addEventListener('change', sync);
        sync();
    };

    return {init};
});
