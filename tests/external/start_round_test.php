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
 * External function tests for start_round.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use core_external\external_api;
use mod_playercross\local\round_service;

/**
 * Tests for the mod_playercross_start_round web service.
 */
final class start_round_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Creates a playercross instance with a deterministic two-word pool (one theme
     * candidate, one clue), timer enabled.
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(array $overrides = []): \stdClass {
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $record = array_merge([
            'course'           => $this->course->id,
            'num_clues'        => 1,
            'theme_min_length' => 6,
            'min_length'       => 3,
            'max_length'       => 15,
            'timer_minutes'    => 2,
        ], $overrides);

        $instance = $modgenerator->create_instance($record);
        $modgenerator->create_word($instance->id, 'escola');
        $modgenerator->create_word($instance->id, 'livro');

        return $instance;
    }

    /**
     * Skips the current test when block_playerhud is not installed.
     *
     * @return void
     */
    private function skip_if_no_playerhud(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
    }

    /**
     * Inserts a genuine block_playerhud_items record (with its own block instance), so
     * cost-checking tests exercise a real item rather than a bare numeric ID.
     *
     * @return int Item ID.
     */
    private function make_hud_item(): int {
        global $DB;
        $ctx = \context_course::instance($this->course->id);
        $biid = $DB->insert_record('block_instances', (object)[
            'blockname'         => 'playerhud',
            'parentcontextid'   => $ctx->id,
            'showinsubcontexts' => 0,
            'pagetypepattern'   => 'course-view-*',
            'subpagepattern'    => null,
            'defaultregion'     => 'side-pre',
            'defaultweight'     => 0,
            'configdata'        => base64_encode(serialize(new \stdClass())),
            'timecreated'       => time(),
            'timemodified'      => time(),
        ]);
        return $DB->insert_record('block_playerhud_items', (object)[
            'blockinstanceid' => $biid,
            'name'            => 'Gold Key',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    /**
     * Calls the mod_playercross_start_round web service through the real dispatch path.
     *
     * @param int $cmid Course module id.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_start_round(int $cmid): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playercross_start_round', ['cmid' => $cmid]);
    }

    /**
     * Tests that starting the round begins the timer and marks it as started.
     *
     * @covers \mod_playercross\external\start_round::execute
     * @return void
     */
    public function test_starts_round(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $result = $this->call_start_round($instance->cmid);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        // The timer starts counting down between start_round() persisting starttime and this
        // response being built, so a slow run can observe 119 instead of 120. Accept both.
        $this->assertGreaterThanOrEqual(119, $result['data']['panel']['timeleft']);
        $this->assertLessThanOrEqual(120, $result['data']['panel']['timeleft']);
        $this->assertTrue($result['data']['panel']['timerenabled']);
        $this->assertFalse($result['data']['panel']['roundfinished']);
        // Regression guard: a context field not also declared in panel_structure() is
        // silently stripped by the external API's return-value cleaning, so this must stay
        // in sync with round_presenter::build_round_panel_context().
        $this->assertNotEmpty($result['data']['panel']['cluesprogresslabel']);

        $state = round_service::load_state($instance->cmid, $this->student->id);
        $this->assertTrue($state['roundstarted']);
    }

    /**
     * Tests that starting an already-started round is rejected without restarting the timer.
     *
     * @covers \mod_playercross\external\start_round::execute
     * @return void
     */
    public function test_rejects_when_already_started(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $this->call_start_round($instance->cmid);
        $second = $this->call_start_round($instance->cmid);

        $this->assertFalse($second['error']);
        $this->assertFalse($second['data']['success']);
    }

    /**
     * Tests that a user without the view capability in the module context is rejected.
     *
     * @covers \mod_playercross\external\start_round::execute
     * @return void
     */
    public function test_requires_view_capability(): void {
        $instance = $this->make_instance();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $result = $this->call_start_round($instance->cmid);

        $this->assertTrue($result['error']);
    }

    /**
     * Tests that an insufficient PlayerHUD item balance blocks starting the round.
     *
     * @covers \mod_playercross\external\start_round::execute
     * @return void
     */
    public function test_hud_insufficient_item_blocks_start(): void {
        $this->skip_if_no_playerhud();
        $itemid = $this->make_hud_item();
        $instance = $this->make_instance(['hud_round_cost_item' => $itemid, 'hud_round_cost_qty' => 1]);
        $this->setUser($this->student);

        $result = $this->call_start_round($instance->cmid);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['success']);
        $this->assertNotEmpty($result['data']['notification']);

        $state = round_service::load_state($instance->cmid, $this->student->id);
        $this->assertFalse($state['roundstarted']);
    }

    /**
     * Tests that a round cost pointing at a PlayerHUD item that does not exist (e.g. it was
     * deleted after the activity was configured) is waived rather than blocking the round
     * forever with a broken notification.
     *
     * @covers \mod_playercross\external\start_round::execute
     * @return void
     */
    public function test_hud_deleted_item_waives_start_cost(): void {
        $instance = $this->make_instance(['hud_round_cost_item' => 999999, 'hud_round_cost_qty' => 1]);
        $this->setUser($this->student);

        $result = $this->call_start_round($instance->cmid);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);

        $state = round_service::load_state($instance->cmid, $this->student->id);
        $this->assertTrue($state['roundstarted']);
    }
}
