<?php
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
 * External function: count approved pool words within a length range.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playercross\local\words_repository;

/**
 * Backs the live "eligible words" count shown next to the length fields on the
 * settings form while editing an already-created activity — see mod_form.php.
 */
class count_eligible_words extends external_api {
    /**
     * Returns parameter definitions for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'      => new external_value(PARAM_INT, 'Course module id'),
            'minlength' => new external_value(PARAM_INT, 'Candidate minimum word length'),
            'maxlength' => new external_value(PARAM_INT, 'Candidate maximum word length'),
        ]);
    }

    /**
     * Counts approved pool words whose length falls within the given range.
     *
     * Reuses words_repository::get_candidate_words() rather than a separate SQL count,
     * so this always reports the exact same set the real round-picking logic would draw
     * from — never a figure that could drift out of sync with it.
     *
     * @param int $cmid Course module id.
     * @param int $minlength Candidate minimum word length.
     * @param int $maxlength Candidate maximum word length.
     * @return array
     */
    public static function execute(int $cmid, int $minlength, int $maxlength): array {
        [
            'cmid'      => $cmid,
            'minlength' => $minlength,
            'maxlength' => $maxlength,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid'      => $cmid,
            'minlength' => $minlength,
            'maxlength' => $maxlength,
        ]);

        $cm = get_coursemodule_from_id('playercross', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playercross:addinstance', $context);

        $candidates = words_repository::get_candidate_words((object)[
            'id'         => $cm->instance,
            'min_length' => $minlength,
            'max_length' => $maxlength,
        ]);

        return ['count' => count($candidates)];
    }

    /**
     * Returns the structure of the execute() return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Number of approved pool words within the range'),
        ]);
    }
}
