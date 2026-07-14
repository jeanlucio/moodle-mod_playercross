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
 * Library functions for mod_playercross.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Source type bit flag for manual clue words. */
define('PLAYERCROSS_SOURCE_MANUAL', 1);

/** Source type bit flag for glossary clue words. */
define('PLAYERCROSS_SOURCE_GLOSSARY', 2);

/** Theme word selection mode: a random theme word is picked each round. */
define('PLAYERCROSS_WORDMODE_RANDOM', 1);

/** Theme word selection mode: all students receive the same theme word per round number. */
define('PLAYERCROSS_WORDMODE_SHARED', 2);

/**
 * Creates a new playercross activity instance.
 *
 * @param stdClass $data Form data.
 * @return int New instance id.
 */
function playercross_add_instance(stdClass $data): int {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = time();

    return $DB->insert_record('playercross', $data);
}

/**
 * Updates an existing playercross activity instance.
 *
 * @param stdClass $data Form data, including the instance id in $data->instance.
 * @return bool
 */
function playercross_update_instance(stdClass $data): bool {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();

    return $DB->update_record('playercross', $data);
}

/**
 * Deletes a playercross activity instance and its owned data.
 *
 * @param int $id Instance id.
 * @return bool True if the instance existed and was deleted.
 */
function playercross_delete_instance(int $id): bool {
    global $DB;

    if (!$DB->record_exists('playercross', ['id' => $id])) {
        return false;
    }

    $DB->delete_records('playercross_attempts', ['playercrossid' => $id]);
    $DB->delete_records('playercross_words', ['playercrossid' => $id]);
    $DB->delete_records('playercross', ['id' => $id]);

    return true;
}
