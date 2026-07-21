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
 * Plugin upgrade steps.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Executes mod_playercross upgrade steps from the given old version.
 *
 * @param int $oldversion Version number we are upgrading from.
 * @return bool True if upgrade succeeded.
 */
function xmldb_playercross_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026072100) {
        $table = new xmldb_table('playercross');

        // Add reveal_uncovered_slots.
        $field = new xmldb_field('reveal_uncovered_slots', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072100, 'playercross');
    }

    if ($oldversion < 2026072101) {
        // The win_condition column briefly existed in an earlier, uncommitted version
        // of this upgrade step (a per-activity win-condition setting) before the
        // decision was made to keep a single fixed rule instead — see SCOPE.md §4.
        // Drop it if a site happened to run that step already.
        $table = new xmldb_table('playercross');
        $field = new xmldb_field('win_condition');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072101, 'playercross');
    }

    if ($oldversion < 2026072200) {
        // Reintroduces win_condition as a genuine per-activity teacher setting (two
        // modes this time: both required, or the mystery phrase alone) — every
        // activity, new or already existing, was running the "both required" rule
        // unconditionally since 2026072101, so defaulting every row to 1 here changes
        // nothing for anyone already using the plugin.
        $table = new xmldb_table('playercross');
        $field = new xmldb_field('win_condition', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072200, 'playercross');
    }

    return true;
}
