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
 * Unit tests for the custom completion rules.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\completion;

use advanced_testcase;

/**
 * Tests for \mod_playercross\completion\custom_completion.
 *
 * @covers \mod_playercross\completion\custom_completion
 */
final class custom_completion_test extends advanced_testcase {
    /**
     * Creates a course and a playercross activity requiring the given number of rounds.
     *
     * @param int $required Number of completed rounds required for completion.
     * @return array{0: \stdClass, 1: \stdClass} [course, cm]
     */
    private function create_fixture(int $required): array {
        global $CFG;
        $CFG->enablecompletion = true;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $cm = $this->getDataGenerator()->get_plugin_generator('mod_playercross')->create_instance([
            'course'                 => $course->id,
            'completion'             => COMPLETION_TRACKING_AUTOMATIC,
            'completionroundsenabled' => $required > 0,
            'completionrounds'       => $required,
        ]);

        return [$course, $cm];
    }

    /**
     * Inserts a finished attempt row for the given user.
     *
     * @param int $playercrossid Instance id.
     * @param int $userid User id.
     * @return void
     */
    private function add_attempt(int $playercrossid, int $userid): void {
        global $DB;

        $DB->insert_record('playercross_attempts', (object)[
            'playercrossid' => $playercrossid,
            'userid'        => $userid,
            'themewordid'   => 1,
            'cluestotal'    => 5,
            'cluesresolved' => 5,
            'finalguessed'  => 0,
            'attempts_used' => 1,
            'time_used'     => 5,
            'completed'     => 1,
            'score'         => 100,
            'timecreated'   => time(),
        ]);
    }

    /**
     * The rule is incomplete while the student has fewer completed rounds than required.
     *
     * @return void
     */
    public function test_get_state_incomplete_below_threshold(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(2);
        $user = $this->getDataGenerator()->create_user();

        $this->add_attempt($cm->id, $user->id);

        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, (int)$user->id);

        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_state('completionrounds'));
    }

    /**
     * The rule is complete once the student reaches the required round count.
     *
     * @return void
     */
    public function test_get_state_complete_at_threshold(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(2);
        $user = $this->getDataGenerator()->create_user();

        $this->add_attempt($cm->id, $user->id);
        $this->add_attempt($cm->id, $user->id);

        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, (int)$user->id);

        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_state('completionrounds'));
    }

    /**
     * The rule is only reported as available when the teacher has actually enabled it.
     *
     * @return void
     */
    public function test_rule_not_available_when_disabled(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(0);

        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, 0);

        $this->assertSame([], $completion->get_available_custom_rules());
    }

    /**
     * The module declares exactly one custom completion rule: completionrounds.
     *
     * @covers \mod_playercross\completion\custom_completion::get_defined_custom_rules
     * @return void
     */
    public function test_get_defined_custom_rules_returns_completionrounds(): void {
        $this->assertSame(['completionrounds'], custom_completion::get_defined_custom_rules());
    }

    /**
     * The custom rule's human-readable description includes the configured round count.
     *
     * @return void
     */
    public function test_get_custom_rule_descriptions_includes_required_count(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(3);
        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, 0);

        $descriptions = $completion->get_custom_rule_descriptions();

        $this->assertArrayHasKey('completionrounds', $descriptions);
        $this->assertStringContainsString('3', $descriptions['completionrounds']);
    }

    /**
     * The display order places the custom rule after the two core rules.
     *
     * @return void
     */
    public function test_get_sort_order_places_custom_rule_last(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(2);
        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, 0);

        $this->assertSame(
            ['completionview', 'completionusegrade', 'completionrounds'],
            $completion->get_sort_order()
        );
    }
}
