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
 * Tests for playercross_update_grades() and the recompute triggered by playercross_update_instance().
 *
 * @covers ::playercross_update_grades
 * @covers ::playercross_update_instance
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

    /**
     * Rounds used by the datesubmitted tests: score and the hour (after the base time) each
     * one finished. Each round starts ten minutes before it finishes. The two 90s tie for
     * the highest score on purpose.
     *
     * @return array
     */
    private static function datesubmitted_rounds(): array {
        return [
            ['score' => 70.0, 'hour' => 1],
            ['score' => 90.0, 'hour' => 2],
            ['score' => 90.0, 'hour' => 3],
            ['score' => 50.0, 'hour' => 4],
        ];
    }

    /**
     * Seeds the datesubmitted rounds for a student.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $userid Student user id.
     * @param int $base Base timestamp.
     * @return array Attempt records keyed by their hour offset.
     */
    private function create_datesubmitted_rounds(\stdClass $instance, int $userid, int $base): array {
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($instance->id, 'escola');
        $attempts = [];
        foreach (self::datesubmitted_rounds() as $round) {
            $finished = $base + $round['hour'] * HOURSECS;
            $attempts[$round['hour']] = $modgenerator->create_attempt($instance->id, $userid, $theme->id, [
                'score' => $round['score'],
                'timecreated' => $finished - 10 * MINSECS,
                'timefinished' => $finished,
            ]);
        }
        return $attempts;
    }

    /**
     * Data provider for test_update_grades_reports_datesubmitted().
     *
     * @return array
     */
    public static function datesubmitted_provider(): array {
        global $CFG;
        // Providers run before setUp(), so the PLAYERCROSS_GRADE_* constants are not loaded yet.
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        return [
            'highest picks the earliest of the tied best rounds' => [PLAYERCROSS_GRADE_HIGHEST, 2],
            'first picks the first round' => [PLAYERCROSS_GRADE_FIRST, 1],
            'last picks the last round' => [PLAYERCROSS_GRADE_LAST, 4],
            'average depends on every round, so the last one' => [PLAYERCROSS_GRADE_AVERAGE, 4],
            'average over required rounds also uses the last one' => [PLAYERCROSS_GRADE_AVERAGE_ALL, 4],
        ];
    }

    /**
     * The grade sent to the gradebook must carry the time the student finished the round
     * that produced it, not just the time the grade changed: consumers of the gradebook
     * (e.g. late-penalty plugins) read it as the submission time.
     *
     * @dataProvider datesubmitted_provider
     * @param int $grademethod PLAYERCROSS_GRADE_* constant.
     * @param int $expectedhour Hour offset of the round expected as the submission.
     * @return void
     */
    public function test_update_grades_reports_datesubmitted(int $grademethod, int $expectedhour): void {
        $course = $this->getDataGenerator()->create_course();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $modgenerator->create_instance([
            'course' => $course->id,
            'grade' => 100,
            'grademethod' => $grademethod,
            'max_rounds' => 5,
        ]);
        $user = $this->getDataGenerator()->create_user();

        $base = 1700000000;
        $this->create_datesubmitted_rounds($instance, $user->id, $base);
        playercross_update_grades($instance, $user->id);

        $grade = $this->fetch_grade_item($instance)->get_grade($user->id, false);
        $this->assertEquals($base + $expectedhour * HOURSECS, $grade->get_datesubmitted());
    }

    /**
     * Deleting a round that does not produce the grade must not move datesubmitted: the
     * round that still produces it keeps its own finish time.
     *
     * @return void
     */
    public function test_update_grades_datesubmitted_survives_unrelated_deletion(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $modgenerator->create_instance([
            'course' => $course->id,
            'grade' => 100,
            'grademethod' => PLAYERCROSS_GRADE_HIGHEST,
        ]);
        $user = $this->getDataGenerator()->create_user();

        $base = 1700000000;
        $attempts = $this->create_datesubmitted_rounds($instance, $user->id, $base);
        playercross_update_grades($instance, $user->id);

        $DB->delete_records('playercross_attempts', ['id' => $attempts[4]->id]);
        playercross_update_grades($instance, $user->id);

        $grade = $this->fetch_grade_item($instance)->get_grade($user->id, false);
        $this->assertSame(90.0, (float)$grade->finalgrade);
        $this->assertEquals($base + 2 * HOURSECS, $grade->get_datesubmitted());
    }

    /**
     * The "average over all required rounds" method divides by max_rounds, and mod_form
     * leaves max_rounds editable after grades exist (unlike grademethod, which it freezes).
     * Saving a new max_rounds must recompute the grades already in the gradebook, as
     * quiz_update_instance() does for its own grading settings.
     *
     * @return void
     */
    public function test_update_instance_recomputes_grades_when_max_rounds_changes(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $modgenerator->create_instance([
            'course' => $course->id,
            'grade' => 100,
            'grademethod' => PLAYERCROSS_GRADE_AVERAGE_ALL,
            'max_rounds' => 4,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $theme = $modgenerator->create_word($instance->id, 'escola');

        $modgenerator->create_attempt($instance->id, $user->id, $theme->id, ['score' => 40.0]);
        $modgenerator->create_attempt($instance->id, $user->id, $theme->id, ['score' => 90.0]);
        playercross_update_grades($instance, $user->id);

        $grade = $this->fetch_grade_item($instance)->get_grade($user->id, false);
        $this->assertSame(32.5, (float)$grade->finalgrade, 'Precondition: 130 / 4 required rounds.');

        // Same shape mod_form posts and update_moduleinfo() hands to update_instance().
        $data = $DB->get_record('playercross', ['id' => $instance->id]);
        $data->instance = $instance->id;
        $data->coursemodule = $instance->cmid;
        $data->source_manual = 1;
        $data->cooldown_amount = 1;
        $data->cooldown_unit = 'days';
        $data->timer_minutes = 0;
        $data->max_rounds = 2;
        playercross_update_instance($data);

        $grade = $this->fetch_grade_item($instance)->get_grade($user->id, false);
        $this->assertSame(65.0, (float)$grade->finalgrade);
    }
}
