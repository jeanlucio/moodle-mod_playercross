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
 * Tests for the mod_playercross upgrade steps.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross;

use advanced_testcase;
use xmldb_index;
use xmldb_table;

/**
 * Unit tests for the mod_playercross upgrade steps.
 *
 * Nothing else runs upgrade.php: the test database and moodle-plugin-ci both build the schema
 * from install.xml, so a step that goes wrong stays green everywhere and only shows on a site
 * that already has data. These tests rebuild the state each step expects and run the real
 * function against it, on every database the CI matrix covers.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::xmldb_playercross_upgrade
 */
final class db_upgrade_test extends advanced_testcase {
    /** @var int Version recorded before the step that removes the duplicated indexes. */
    private const PREVIOUS_VERSION = 2026101500;

    /** @var int Version the step saves when it finishes. */
    private const STEP_VERSION = 2026101501;

    /** @var array Columns the step covers: table name => field that carries a foreign key. */
    private const COLUMNS = [
        'playercross' => 'course',
        'playercross_words' => 'playercrossid',
    ];

    /**
     * Pulls in the upgrade function and the savepoint helper it calls.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        // The savepoint helper lives in upgradelib.php, which only the real upgrade runner loads.
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/playercross/db/upgrade.php');
    }

    /**
     * Names of the non-unique indexes whose only field is the given one.
     *
     * @param string $table Table name without prefix.
     * @param string $field Field name.
     * @return string[]
     */
    private function indexes_on(string $table, string $field): array {
        global $DB;

        $indexes = array_filter(
            $DB->get_indexes($table),
            fn(array $index): bool => !$index['unique'] && array_values($index['columns']) === [$field]
        );
        return array_keys($indexes);
    }

    /**
     * Adds a second index identical to the key's own, as install.xml did before the fix.
     *
     * add_index() refuses an index whose fields another index already covers, so the statement
     * comes straight from the DDL generator, which builds it the way installing the old
     * install.xml did.
     *
     * @param string $table Table name without prefix.
     * @param string $field Field name.
     * @return void
     */
    private function add_duplicate_index(string $table, string $field): void {
        global $DB;

        $sql = $DB->get_manager()->generator->getCreateIndexSQL(
            new xmldb_table($table),
            new xmldb_index('duplicate', XMLDB_INDEX_NOTUNIQUE, [$field])
        );
        $DB->change_database_structure($sql, [$table]);
    }

    /**
     * Runs the upgrade from the version that precedes the step.
     *
     * The savepoint refuses anything that is not a step forward, so the older version is
     * recorded first.
     *
     * @return void
     */
    private function run_step(): void {
        set_config('version', self::PREVIOUS_VERSION, 'mod_playercross');
        $this->assertTrue(xmldb_playercross_upgrade(self::PREVIOUS_VERSION));
        $this->assertEquals(self::STEP_VERSION, get_config('mod_playercross', 'version'));
    }

    /**
     * A column holding two identical indexes ends with exactly one.
     *
     * @return void
     */
    public function test_two_identical_indexes_become_one(): void {
        foreach (self::COLUMNS as $table => $field) {
            $this->add_duplicate_index($table, $field);
            $this->assertCount(2, $this->indexes_on($table, $field), "{$table}.{$field} before the step");
        }

        $this->run_step();

        foreach (self::COLUMNS as $table => $field) {
            $this->assertCount(1, $this->indexes_on($table, $field), "{$table}.{$field} after the step");
        }
    }

    /**
     * A column left with no index gets the one its foreign key needs.
     *
     * @return void
     */
    public function test_column_without_index_gets_the_key_index(): void {
        global $DB;

        $dbman = $DB->get_manager();
        foreach (self::COLUMNS as $table => $field) {
            $dbman->drop_index(new xmldb_table($table), new xmldb_index('any', XMLDB_INDEX_NOTUNIQUE, [$field]));
            $this->assertCount(0, $this->indexes_on($table, $field), "{$table}.{$field} before the step");
        }

        $this->run_step();

        foreach (self::COLUMNS as $table => $field) {
            $this->assertCount(1, $this->indexes_on($table, $field), "{$table}.{$field} after the step");
        }
    }

    /**
     * A column that already has a single index keeps that same index.
     *
     * Comparing the names shows the step neither dropped nor rebuilt it.
     *
     * @return void
     */
    public function test_single_index_is_left_untouched(): void {
        $before = [];
        foreach (self::COLUMNS as $table => $field) {
            $before[$table] = $this->indexes_on($table, $field);
            $this->assertCount(1, $before[$table], "{$table}.{$field} before the step");
        }

        $this->run_step();

        foreach (self::COLUMNS as $table => $field) {
            $this->assertSame($before[$table], $this->indexes_on($table, $field), "{$table}.{$field} after the step");
        }
    }
}
