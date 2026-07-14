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
 * External function tests for end_round.
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
 * Tests for the mod_playercross_end_round web service.
 */
final class end_round_test extends \advanced_testcase {
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
     * candidate ("escola") and the sole clue ("livro").
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
        ], $overrides);

        $instance = $modgenerator->create_instance($record);
        $modgenerator->create_word($instance->id, 'escola');
        $modgenerator->create_word($instance->id, 'livro');

        return $instance;
    }

    /**
     * Puts a round in progress (puzzle built, timer started) for the given instance.
     *
     * Must run after $this->setUser() — setUser() empties the session, so any session
     * state written before it is silently lost.
     *
     * @param \stdClass $instance Activity instance.
     * @return void
     */
    private function start_round_for_student(\stdClass $instance): void {
        $state = round_service::load_state($instance->cmid, $this->student->id);
        $state = round_service::ensure_round_state($state, $instance, $instance->cmid, $this->student->id);
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        round_service::save_state($instance->cmid, $this->student->id, $state);
    }

    /**
     * Calls the mod_playercross_end_round web service through the real dispatch path.
     *
     * @param int $cmid Course module id.
     * @param string $reason Either "forfeit" or "timeout".
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_end_round(int $cmid, string $reason): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function(
            'mod_playercross_end_round',
            ['cmid' => $cmid, 'reason' => $reason]
        );
    }

    /**
     * Tests that forfeiting finishes the round and reveals the mystery phrase.
     *
     * @covers \mod_playercross\external\end_round::execute
     * @return void
     */
    public function test_forfeit_finishes_round(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $this->start_round_for_student($instance);

        $result = $this->call_end_round($instance->cmid, 'forfeit');

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['finished']);
        $this->assertSame('ESCOLA', $result['data']['panel']['revealthemeword']);
    }

    /**
     * Tests that timing out finishes the round and reveals the mystery phrase, once the
     * configured deadline has genuinely passed.
     *
     * @covers \mod_playercross\external\end_round::execute
     * @return void
     */
    public function test_timeout_finishes_round(): void {
        $instance = $this->make_instance(['timer_minutes' => 1]);
        $this->setUser($this->student);
        $this->start_round_for_student($instance);

        $state = round_service::load_state($instance->cmid, $this->student->id);
        $state['starttime'] = time() - 120;
        round_service::save_state($instance->cmid, $this->student->id, $state);

        $result = $this->call_end_round($instance->cmid, 'timeout');

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['finished']);
        $this->assertSame('ESCOLA', $result['data']['panel']['revealthemeword']);
    }

    /**
     * Tests that an invalid reason value is rejected.
     *
     * @covers \mod_playercross\external\end_round::execute
     * @return void
     */
    public function test_rejects_invalid_reason(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $this->start_round_for_student($instance);

        $result = $this->call_end_round($instance->cmid, 'cheat');

        $this->assertTrue($result['error']);
    }

    /**
     * Tests that a user without the view capability in the module context is rejected.
     *
     * @covers \mod_playercross\external\end_round::execute
     * @return void
     */
    public function test_requires_view_capability(): void {
        $instance = $this->make_instance();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $result = $this->call_end_round($instance->cmid, 'forfeit');

        $this->assertTrue($result['error']);
    }
}
