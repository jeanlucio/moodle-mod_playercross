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
 * External function tests for submit_clue_guess.
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
 * Tests for the mod_playercross_submit_clue_guess web service.
 *
 * The invariant under test (SCOPE.md §7): no response ever reveals the mystery
 * phrase, nor an unresolved clue's word, before the round has actually finished
 * server-side.
 */
final class submit_clue_guess_test extends \advanced_testcase {
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
     * An unresolved clue's word is never present in the panel response.
     *
     * @covers \mod_playercross\external\submit_clue_guess::execute
     * @return void
     */
    public function test_wrong_guess_never_leaks_the_clue_word(): void {
        [$instance, $cm] = $this->make_ready_instance();
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        round_service::save_state($cm->cmid, $this->student->id, $state);
        $clueid = (int)$state['clues'][0]['wordid'];

        $result = submit_clue_guess::execute($cm->cmid, $clueid, 'zzzzzzz');

        $this->assertFalse($result['resolved']);
        $this->assertFalse($result['finished']);
        foreach ($result['panel']['clues'] as $cluerow) {
            $this->assertSame('', $cluerow['revealword']);
        }
        $this->assertSame('', $result['panel']['revealthemeword']);
    }

    /**
     * Once every clue is resolved, the mystery phrase and every clue word are
     * revealed in the final response — but only then.
     *
     * @covers \mod_playercross\external\submit_clue_guess::execute
     * @return void
     */
    public function test_resolving_every_clue_reveals_theme_word_only_at_the_end(): void {
        [$instance, $cm] = $this->make_ready_instance();
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        round_service::save_state($cm->cmid, $this->student->id, $state);

        $clues = $state['clues'];
        $lastindex = count($clues) - 1;

        foreach ($clues as $index => $clue) {
            $result = submit_clue_guess::execute($cm->cmid, (int)$clue['wordid'], $clue['word']);
            $this->assertTrue($result['resolved']);

            if ($index < $lastindex) {
                $this->assertFalse($result['finished']);
                $this->assertSame('', $result['panel']['revealthemeword']);
            }
        }

        $this->assertTrue($result['finished']);
        $this->assertSame(core_text::strtoupper($state['themeword']), $result['panel']['revealthemeword']);
    }

    /**
     * A student outside the course cannot submit a guess.
     *
     * @covers \mod_playercross\external\submit_clue_guess::execute
     * @return void
     */
    public function test_outsider_cannot_submit_guess(): void {
        [, $cm] = $this->make_ready_instance();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $this->expectException(\require_login_exception::class);
        submit_clue_guess::execute($cm->cmid, 1, 'palpite');
    }
}
