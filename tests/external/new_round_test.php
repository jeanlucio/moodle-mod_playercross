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
 * External function tests for new_round.
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
 * Tests for the mod_playercross_new_round web service.
 *
 * @covers \mod_playercross\external\new_round
 */
final class new_round_test extends \advanced_testcase {
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
     * Creates a playercross instance with a deterministic two-word pool.
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
     * Finishes (forfeits) a round for the given instance.
     *
     * Must run after $this->setUser() — setUser() empties the session, so any session
     * state written before it is silently lost.
     *
     * @param \stdClass $instance Activity instance.
     * @return void
     */
    private function finish_round_for_student(\stdClass $instance): void {
        $state = round_service::load_state($instance->cmid, $this->student->id);
        $state = round_service::ensure_round_state($state, $instance, $instance->cmid, $this->student->id);
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        [$state] = round_service::forfeit($state, $instance, $instance->cmid, $this->student->id);
        round_service::save_state($instance->cmid, $this->student->id, $state);
    }

    /**
     * Calls the mod_playercross_new_round web service through the real dispatch path.
     *
     * @param int $cmid Course module id.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_new_round(int $cmid): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playercross_new_round', ['cmid' => $cmid]);
    }

    /**
     * Tests that a fresh puzzle is available after starting a new round.
     *
     * @return void
     */
    public function test_new_round_picks_fresh_puzzle(): void {
        $instance = $this->make_instance(['max_rounds' => 0, 'cooldown_amount' => 0]);
        $this->setUser($this->student);
        $this->finish_round_for_student($instance);

        $result = $this->call_new_round($instance->cmid);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['hastheme']);
        // Regression guard: build_lobby_context() must keep returning exactly the keys
        // declared in execute_returns()'s 'lobby' structure, or the external API silently
        // strips/rejects them — this caught a real drift once already (cluestotal ->
        // cluesthisround rename that execute_returns() had not been updated for).
        $this->assertNotEmpty($result['data']['lobby']['cluesthisround']);

        $state = round_service::load_state($instance->cmid, $this->student->id);
        $this->assertFalse($state['finished']);
    }

    /**
     * Tests that reaching max_rounds blocks starting a new round.
     *
     * @return void
     */
    public function test_blocked_when_round_limit_reached(): void {
        $instance = $this->make_instance(['max_rounds' => 1, 'cooldown_amount' => 0]);
        $this->setUser($this->student);
        $this->finish_round_for_student($instance);

        $result = $this->call_new_round($instance->cmid);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['hastheme']);
        $this->assertNotEmpty($result['data']['notification']);
    }

    /**
     * Tests that a round in progress cannot be discarded through the web service without
     * being recorded, closing the gap where a student could call
     * mod_playercross_new_round directly to abandon a losing round unrecorded.
     *
     * @return void
     */
    public function test_blocked_while_round_in_progress(): void {
        global $DB;

        $instance = $this->make_instance(['max_rounds' => 0, 'cooldown_amount' => 0]);
        $this->setUser($this->student);

        $state = round_service::load_state($instance->cmid, $this->student->id);
        $state = round_service::ensure_round_state($state, $instance, $instance->cmid, $this->student->id);
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        round_service::save_state($instance->cmid, $this->student->id, $state);

        $result = $this->call_new_round($instance->cmid);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['hastheme']);
        $this->assertNotEmpty($result['data']['notification']);

        // The in-progress round must still be there, untouched: not reset, and no
        // attempt row inserted (PlayerCross only inserts one when the round actually
        // finishes — see round_service's class docblock).
        $state = round_service::load_state($instance->cmid, $this->student->id);
        $this->assertFalse($state['finished']);
        $this->assertTrue($state['roundstarted']);
        $this->assertSame(0, $DB->count_records('playercross_attempts', ['userid' => $this->student->id]));
    }

    /**
     * Tests that a theme word already armed in the lobby (ensure_round_state() has
     * picked one, but start_round() was never called — roundstarted is still false)
     * cannot be re-rolled for free through the web service. Before this guard covered
     * themewordid instead of only roundstarted, a client could call this endpoint
     * repeatedly before ever starting the round to re-roll until an easier puzzle came
     * up, without spending max_rounds, cooldown or a PlayerHUD item — none of which
     * are charged until start_round() actually runs.
     *
     * @return void
     */
    public function test_blocked_while_theme_armed_in_lobby(): void {
        $instance = $this->make_instance(['max_rounds' => 0, 'cooldown_amount' => 0]);
        $this->setUser($this->student);

        $state = round_service::load_state($instance->cmid, $this->student->id);
        $state = round_service::ensure_round_state($state, $instance, $instance->cmid, $this->student->id);
        round_service::save_state($instance->cmid, $this->student->id, $state);
        $originalthemewordid = (int)$state['themewordid'];
        $this->assertGreaterThan(0, $originalthemewordid);
        $this->assertFalse($state['roundstarted']);

        $result = $this->call_new_round($instance->cmid);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['hastheme']);
        $this->assertNotEmpty($result['data']['notification']);

        // The armed theme must be untouched: same themewordid, still not started, not
        // finished — no free re-roll happened.
        $state = round_service::load_state($instance->cmid, $this->student->id);
        $this->assertSame($originalthemewordid, (int)$state['themewordid']);
        $this->assertFalse($state['roundstarted']);
        $this->assertFalse($state['finished']);
    }

    /**
     * Tests that a user without the view capability in the module context is rejected.
     *
     * @return void
     */
    public function test_requires_view_capability(): void {
        $instance = $this->make_instance();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $result = $this->call_new_round($instance->cmid);

        $this->assertTrue($result['error']);
    }
}
