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
 * Database upgrade steps for mod_playercross.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin from one version to the next.
 *
 * @param int $oldversion The old plugin version.
 * @return bool True on success.
 */
function xmldb_playercross_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026081800) {
        $table = new xmldb_table('playercross_attempts');
        $field = new xmldb_field(
            'timefinished',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'timecreated'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Every row that already exists at this point was written by the pre-reservation
        // finish_round(), so it already represents a genuinely finished round. Backfill with
        // timecreated (the closest available proxy for the original finish time) instead of
        // leaving the new column at its 0 default, which would otherwise make every existing
        // finished round look like a still-pending reservation to every timefinished > 0 filter
        // added alongside this column.
        $DB->execute('UPDATE {playercross_attempts} SET timefinished = timecreated WHERE timefinished = 0');

        upgrade_mod_savepoint(true, 2026081800, 'playercross');
    }

    return true;
}
