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
 * External function tests for submit_final_guess.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use core_text;
use mod_playercross\local\round_service;

/**
 * Tests for the mod_playercross_submit_final_guess web service.
 *
 * The invariant under test (SCOPE.md §7): a wrong direct guess never reveals the
 * mystery phrase, and no unresolved clue's word leaks either.
 */
final class submit_final_guess_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    /** @var \mod_playercross_generator Activity module generator. */
    private $modgenerator;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
    }

    /**
     * Creates a ready-to-play instance with a small, deterministic word pool.
     *
     * @return array{0: \stdClass, 1: \stdClass} [instance record, course module]
     */
    private function make_ready_instance(): array {
        $cm = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_clues' => 3,
            'theme_min_length' => 6,
        ]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);

        $this->modgenerator->create_word($instance->id, 'escola');
        $this->modgenerator->create_word($instance->id, 'casa');
        $this->modgenerator->create_word($instance->id, 'lobo');
        $this->modgenerator->create_word($instance->id, 'mel');

        return [$instance, $cm];
    }

    /**
     * A wrong direct guess never reveals the mystery phrase or any clue word.
     *
     * @covers \mod_playercross\external\submit_final_guess::execute
     * @return void
     */
    public function test_wrong_final_guess_never_leaks_theme_word(): void {
        [$instance, $cm] = $this->make_ready_instance();
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        round_service::save_state($cm->cmid, $this->student->id, $state);

        $result = submit_final_guess::execute($cm->cmid, 'totalmenteerrado');

        $this->assertFalse($result['correct']);
        $this->assertFalse($result['finished']);
        $this->assertSame('', $result['panel']['revealthemeword']);
        foreach ($result['panel']['clues'] as $cluerow) {
            $this->assertSame('', $cluerow['revealword']);
        }
    }

    /**
     * A correct direct guess wins the round immediately, even with clues pending, and
     * reveals the mystery phrase only in that final response.
     *
     * @covers \mod_playercross\external\submit_final_guess::execute
     * @return void
     */
    public function test_correct_final_guess_wins_and_reveals_theme_word(): void {
        [$instance, $cm] = $this->make_ready_instance();
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        round_service::save_state($cm->cmid, $this->student->id, $state);

        $themephrase = implode(' ', $state['themewords']);
        $result = submit_final_guess::execute($cm->cmid, $themephrase);

        $this->assertTrue($result['correct']);
        $this->assertTrue($result['finished']);
        $this->assertSame(core_text::strtoupper($themephrase), $result['panel']['revealthemeword']);
    }
}
