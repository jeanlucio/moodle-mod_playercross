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
 * Tests for playercross_update_grades() in lib.php.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross;

/**
 * Tests for playercross_update_grades().
 *
 * @covers ::playercross_update_grades
 */
final class lib_update_grades_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        require_once($CFG->libdir . '/gradelib.php');
    }

    /**
     * Fetches the grade_item for the given instance, requiring it to exist.
     *
     * @param \stdClass $instance Activity instance.
     * @return \grade_item
     */
    private function fetch_grade_item(\stdClass $instance): \grade_item {
        return \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'playercross',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
            'courseid'     => $instance->course,
        ]);
    }

    /**
     * When a specific student's last attempt is removed (so the filtered query for
     * that userid finds nothing), their stale grade must actually be cleared from the
     * gradebook — not just leave grade_item::has_grades() permanently true because the
     * old finalgrade value was never touched. This is the exact condition that keeps
     * mod_form's attempt-gated field locks (num_terms, grademethod, max attempts,
     * gradescoringmode — all gated on has_grades()) frozen forever once a delete
     * feature lets a student's attempt count legitimately drop back to zero.
     *
     * @return void
     */
    public function test_update_grades_clears_grade_for_user_with_no_attempts_left(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $modgenerator->create_instance(['course' => $course->id, 'grade' => 100]);
        $user = $this->getDataGenerator()->create_user();
        $theme = $modgenerator->create_word($instance->id, 'escola');

        $modgenerator->create_attempt($instance->id, $user->id, $theme->id, ['score' => 80.0]);
        playercross_update_grades($instance, $user->id);

        $gradeitem = $this->fetch_grade_item($instance);
        $this->assertTrue($gradeitem->has_grades(), 'Precondition: the student must have a real grade first.');

        // Simulates the effect of deleting that student's only attempt.
        $DB->delete_records('playercross_attempts', ['playercrossid' => $instance->id, 'userid' => $user->id]);
        playercross_update_grades($instance, $user->id);

        $gradeitem = $this->fetch_grade_item($instance);
        $this->assertFalse(
            $gradeitem->has_grades(),
            'The student\'s stale grade must be cleared once they have no attempts left.'
        );
    }

    /**
     * A student who still has other attempts after one is deleted gets their grade
     * recomputed from what remains — the ordinary, already-working recompute path,
     * kept here as a contrast to the zero-attempts-left case above.
     *
     * @return void
     */
    public function test_update_grades_recomputes_when_attempts_remain(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $modgenerator->create_instance([
            'course' => $course->id,
            'grade' => 100,
            'grademethod' => PLAYERCROSS_GRADE_HIGHEST,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $theme = $modgenerator->create_word($instance->id, 'escola');

        $low = $modgenerator->create_attempt($instance->id, $user->id, $theme->id, ['score' => 40.0]);
        $modgenerator->create_attempt($instance->id, $user->id, $theme->id, ['score' => 90.0]);
        playercross_update_grades($instance, $user->id);

        // Delete only the lower-scoring attempt — the higher one still stands.
        $DB->delete_records('playercross_attempts', ['id' => $low->id]);
        playercross_update_grades($instance, $user->id);

        $gradeitem = $this->fetch_grade_item($instance);
        $grade = $gradeitem->get_grade($user->id, false);
        $this->assertSame(90.0, (float)$grade->finalgrade);
    }
}
