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
 * External function: preview how many glossary-sourced words fit a length range.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playercross\local\words_repository;

/**
 * Backs the live glossary-candidates preview shown on the settings form while
 * creating a brand-new activity — see mod_form.php. Unlike
 * count_eligible_words, there is no course-module yet at this point, so this
 * validates against the course context instead and reads directly from the
 * course's own glossaries rather than from a (non-existent) pool.
 */
class count_glossary_candidates extends external_api {
    /**
     * Returns parameter definitions for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT, 'Course id'),
            'glossaryid' => new external_value(PARAM_INT, 'Glossary id, or 0 for every course glossary'),
            'minlength'  => new external_value(PARAM_INT, 'Candidate minimum word length'),
            'maxlength'  => new external_value(PARAM_INT, 'Candidate maximum word length'),
            'stopwords'  => new external_value(
                PARAM_TEXT,
                'Comma-separated words to ignore when splitting multi-word concepts',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Counts distinct candidate words the given glossary source would produce.
     *
     * @param int $courseid Course id.
     * @param int $glossaryid Glossary id, or 0 for every course glossary.
     * @param int $minlength Candidate minimum word length.
     * @param int $maxlength Candidate maximum word length.
     * @param string $stopwords Comma-separated words to ignore when splitting multi-word concepts.
     * @return array
     */
    public static function execute(
        int $courseid,
        int $glossaryid,
        int $minlength,
        int $maxlength,
        string $stopwords = ''
    ): array {
        [
            'courseid'   => $courseid,
            'glossaryid' => $glossaryid,
            'minlength'  => $minlength,
            'maxlength'  => $maxlength,
            'stopwords'  => $stopwords,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid'   => $courseid,
            'glossaryid' => $glossaryid,
            'minlength'  => $minlength,
            'maxlength'  => $maxlength,
            'stopwords'  => $stopwords,
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('mod/playercross:addinstance', $context);

        $count = words_repository::count_glossary_candidates($courseid, $glossaryid, $minlength, $maxlength, $stopwords);

        return ['count' => $count];
    }

    /**
     * Returns the structure of the execute() return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Number of distinct candidate words within the range'),
        ]);
    }
}
