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
 * External function tests for reveal_hint.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use core_external\external_api;

/**
 * Tests for the mod_playercross_reveal_hint web service.
 */
final class reveal_hint_test extends \advanced_testcase {
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
     * Creates a playercross instance with a deterministic two-word pool: a theme
     * candidate ("escola") and the sole clue ("livro"), the clue carrying a hint.
     *
     * @param array $overrides Instance field overrides.
     * @return array{0: \stdClass, 1: int} [instance (with ->cmid), clue word id]
     */
    private function make_instance_with_clue(array $overrides = []): array {
        global $DB;

        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $record = array_merge([
            'course'           => $this->course->id,
            'num_clues'        => 1,
            'theme_min_length' => 6,
            'min_length'       => 3,
            'max_length'       => 15,
        ], $overrides);

        $instance = $modgenerator->create_instance($record);
        $modgenerator->create_word($instance->id, 'escola');
        $modgenerator->create_word($instance->id, 'livro', 'dica secreta');

        $clueid = (int)$DB->get_field('playercross_words', 'id', [
            'playercrossid' => $instance->id, 'word' => 'livro',
        ], MUST_EXIST);

        return [$instance, $clueid];
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
     * Calls the mod_playercross_reveal_hint web service through the real dispatch path.
     *
     * @param int $cmid Course module id.
     * @param int $clueid Clue word id.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_reveal_hint(int $cmid, int $clueid): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function(
            'mod_playercross_reveal_hint',
            ['cmid' => $cmid, 'clueid' => $clueid]
        );
    }

    /**
     * Tests that revealing the hint returns the hint text.
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_reveals_hint(): void {
        [$instance, $clueid] = $this->make_instance_with_clue();
        $this->setUser($this->student);

        $result = $this->call_reveal_hint($instance->cmid, $clueid);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertSame($clueid, $result['data']['clueid']);
        $this->assertSame('dica secreta', $result['data']['hintvalue']);
    }

    /**
     * Tests that revealing an already-revealed clue's hint is rejected as a fresh action
     * (so no PlayerHUD item would be double-charged), while the hint text already known
     * from the first reveal remains visible in the response.
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_reveal_hint_twice_rejects_without_hiding_known_hint(): void {
        [$instance, $clueid] = $this->make_instance_with_clue();
        $this->setUser($this->student);

        $this->call_reveal_hint($instance->cmid, $clueid);
        $second = $this->call_reveal_hint($instance->cmid, $clueid);

        $this->assertFalse($second['error']);
        $this->assertFalse($second['data']['success']);
        $this->assertSame('dica secreta', $second['data']['hintvalue']);
    }

    /**
     * Tests that an unknown clue id is rejected with an empty hint value.
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_rejects_unknown_clue_id(): void {
        [$instance] = $this->make_instance_with_clue();
        $this->setUser($this->student);

        $result = $this->call_reveal_hint($instance->cmid, 999999);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['success']);
        $this->assertSame('', $result['data']['hintvalue']);
    }

    /**
     * Tests that a user without the view capability in the module context is rejected.
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_requires_view_capability(): void {
        [$instance, $clueid] = $this->make_instance_with_clue();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $result = $this->call_reveal_hint($instance->cmid, $clueid);

        $this->assertTrue($result['error']);
    }

    /**
     * Tests that an insufficient PlayerHUD item balance blocks revealing the hint.
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_hud_insufficient_item_blocks_reveal(): void {
        $this->skip_if_no_playerhud();
        $itemid = $this->make_hud_item();
        [$instance, $clueid] = $this->make_instance_with_clue([
            'hud_hint_cost_item' => $itemid, 'hud_hint_cost_qty' => 1,
        ]);
        $this->setUser($this->student);

        $result = $this->call_reveal_hint($instance->cmid, $clueid);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['success']);
        $this->assertSame('', $result['data']['hintvalue']);
        $this->assertNotEmpty($result['data']['notification']);
    }

    /**
     * Tests that a hint cost pointing at a PlayerHUD item that does not exist (e.g. it was
     * deleted after the activity was configured) is waived rather than blocking the hint
     * forever with a broken notification.
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_hud_deleted_item_waives_reveal_cost(): void {
        [$instance, $clueid] = $this->make_instance_with_clue([
            'hud_hint_cost_item' => 999999, 'hud_hint_cost_qty' => 1,
        ]);
        $this->setUser($this->student);

        $result = $this->call_reveal_hint($instance->cmid, $clueid);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertSame('dica secreta', $result['data']['hintvalue']);
    }
}
