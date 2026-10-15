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

    if ($oldversion < 2026101501) {
        // Each of these columns had a second index identical to the one Moodle creates for its
        // foreign key. drop_index() removes every index on the field, the key's own included, so
        // the key is added back afterwards; add_key() only creates its index when the column has
        // none, which also leaves a column with a single index as it was.
        $redundant = [
            'playercross' => ['fk_course', 'course', 'course'],
            'playercross_words' => ['fk_playercross', 'playercrossid', 'playercross'],
        ];
        foreach ($redundant as $tablename => [$keyname, $field, $reftable]) {
            $table = new xmldb_table($tablename);
            $sameindexes = array_filter(
                $DB->get_indexes($tablename),
                fn(array $index): bool => !$index['unique'] && $index['columns'] === [$field]
            );
            if (count($sameindexes) > 1) {
                $dbman->drop_index($table, new xmldb_index($keyname, XMLDB_INDEX_NOTUNIQUE, [$field]));
            }
            $dbman->add_key($table, new xmldb_key($keyname, XMLDB_KEY_FOREIGN, [$field], $reftable, ['id']));
        }

        upgrade_mod_savepoint(true, 2026101501, 'playercross');
    }

    return true;
}
