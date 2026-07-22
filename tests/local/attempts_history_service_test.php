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
 * Unit tests for attempts_history_service.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Tests for attempts_history_service — requires database.
 */
final class attempts_history_service_test extends \advanced_testcase {
    /** @var \stdClass Course used to host test instances. */
    private \stdClass $course;

    /** @var \mod_playercross_generator Activity module generator. */
    private $modgenerator;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
    }

    /**
     * get_history() only returns the given user's own attempts, most recent first —
     * the sole security boundary the "my attempts" page relies on.
     *
     * @covers \mod_playercross\local\attempts_history_service::get_history
     * @return void
     */
    public function test_get_history_is_scoped_to_the_given_user(): void {
        $cm = $this->modgenerator->create_instance(['course' => $this->course->id, 'grade' => 100]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $this->modgenerator->create_attempt($instance->id, $usera->id, $theme->id, [
            'timecreated' => time() - 100,
            'score' => 40.0,
        ]);
        $this->modgenerator->create_attempt($instance->id, $usera->id, $theme->id, [
            'timecreated' => time(),
            'score' => 90.0,
        ]);
        $this->modgenerator->create_attempt($instance->id, $userb->id, $theme->id, ['score' => 100.0]);

        $history = attempts_history_service::get_history($instance, $usera->id);

        $this->assertFalse($history['isempty']);
        $this->assertCount(2, $history['rows']);
        // Most recent first.
        $this->assertSame('90.00', $history['rows'][0]['score']);
        $this->assertSame('40.00', $history['rows'][1]['score']);
        $this->assertTrue($history['showgrade']);
    }

    /**
     * An activity with no attempts at all yields an empty history and no grade.
     *
     * @covers \mod_playercross\local\attempts_history_service::get_history
     * @return void
     */
    public function test_get_history_is_empty_without_any_attempts(): void {
        $cm = $this->modgenerator->create_instance(['course' => $this->course->id, 'grade' => 100]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $user = $this->getDataGenerator()->create_user();

        $history = attempts_history_service::get_history($instance, $user->id);

        $this->assertTrue($history['isempty']);
        $this->assertSame([], $history['rows']);
        $this->assertFalse($history['showgrade']);
    }

    /**
     * The current grade matches playercross_calculate_user_grade() for the
     * instance's configured grading method — the whole point of this service is to
     * never duplicate that aggregation logic.
     *
     * @covers \mod_playercross\local\attempts_history_service::get_history
     * @return void
     */
    public function test_get_history_grade_matches_calculate_user_grade(): void {
        $cm = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'grade' => 100,
            'grademethod' => PLAYERCROSS_GRADE_HIGHEST,
        ]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');
        $user = $this->getDataGenerator()->create_user();
        $this->modgenerator->create_attempt($instance->id, $user->id, $theme->id, [
            'score' => 40.0,
            'timecreated' => time() - 20,
        ]);
        $this->modgenerator->create_attempt($instance->id, $user->id, $theme->id, [
            'score' => 90.0,
            'timecreated' => time() - 10,
        ]);

        $history = attempts_history_service::get_history($instance, $user->id);

        $this->assertTrue($history['showgrade']);
        $this->assertSame('90.00', $history['grade']);
        $this->assertSame('100.00', $history['maxgrade']);
    }

    /**
     * The theme-word column falls back to the joined word's raw text when no
     * concept was recorded for it (e.g. a manually added word inserted outside the
     * generator's own concept-always-set convention).
     *
     * @covers \mod_playercross\local\attempts_history_service::get_history
     * @return void
     */
    public function test_get_history_row_falls_back_to_word_when_no_concept(): void {
        global $DB;
        $cm = $this->modgenerator->create_instance(['course' => $this->course->id, 'grade' => 100]);
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $wordid = $DB->insert_record('playercross_words', (object)[
            'playercrossid' => $instance->id,
            'word'          => 'floresta',
            'concept'       => null,
            'hint'          => 'floresta',
            'source'        => 'manual',
            'glossaryid'    => 0,
            'approved'      => 1,
            'timecreated'   => time(),
            'timemodified'  => time(),
            'addedby'       => 0,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->modgenerator->create_attempt($instance->id, $user->id, $wordid);

        $history = attempts_history_service::get_history($instance, $user->id);

        $this->assertSame('floresta', $history['rows'][0]['themeword']);
    }

    /**
     * Time used is formatted as m:ss for display.
     *
     * @covers \mod_playercross\local\attempts_history_service::get_history
     * @return void
     */
    public function test_get_history_row_formats_time_used(): void {
        $cm = $this->modgenerator->create_instance(['course' => $this->course->id, 'grade' => 100]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');
        $user = $this->getDataGenerator()->create_user();
        $this->modgenerator->create_attempt($instance->id, $user->id, $theme->id, ['time_used' => 65]);

        $history = attempts_history_service::get_history($instance, $user->id);

        $this->assertSame('1:05', $history['rows'][0]['timeused']);
    }

    /**
     * get_history() reports no grade summary when the activity has no numeric grade.
     *
     * @covers \mod_playercross\local\attempts_history_service::get_history
     * @return void
     */
    public function test_get_history_hides_grade_when_ungraded(): void {
        $cm = $this->modgenerator->create_instance(['course' => $this->course->id, 'grade' => 0]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');
        $user = $this->getDataGenerator()->create_user();

        $this->modgenerator->create_attempt($instance->id, $user->id, $theme->id);

        $history = attempts_history_service::get_history($instance, $user->id);

        $this->assertFalse($history['showgrade']);
    }

    /**
     * get_all_history() paginates and sorts, and an unknown sort key falls back to date —
     * SORTABLE_COLUMNS is an allow-list, not a pass-through of client input into SQL.
     *
     * @covers \mod_playercross\local\attempts_history_service::get_all_history
     * @return void
     */
    public function test_get_all_history_paginates_and_falls_back_on_unknown_sort(): void {
        $cm = $this->modgenerator->create_instance(['course' => $this->course->id]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $context = \context_module::instance($cm->cmid);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
        for ($i = 0; $i < 5; $i++) {
            $this->modgenerator->create_attempt($instance->id, $user->id, $theme->id, [
                'timecreated' => time() + $i,
            ]);
        }

        $page1 = attempts_history_service::get_all_history($instance, $context, 0, 2, 'date', 'DESC', 0);
        $this->assertSame(5, $page1['total']);
        $this->assertCount(2, $page1['rows']);

        // A malicious sort key isn't realistic here since PARAM_ALPHA already strips
        // it upstream, but any key absent from SORTABLE_COLUMNS must still
        // resolve to the safe default rather than erroring out.
        $fallback = attempts_history_service::get_all_history($instance, $context, 0, 2, 'nosuchcolumn', 'DESC', 0);
        $this->assertSame(5, $fallback['total']);
    }

    /**
     * get_all_history() filters to a single student when a userid is given.
     *
     * @covers \mod_playercross\local\attempts_history_service::get_all_history
     * @return void
     */
    public function test_get_all_history_filters_by_student(): void {
        $cm = $this->modgenerator->create_instance(['course' => $this->course->id]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $context = \context_module::instance($cm->cmid);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $this->modgenerator->create_attempt($instance->id, $usera->id, $theme->id);
        $this->modgenerator->create_attempt($instance->id, $userb->id, $theme->id);

        $filtered = attempts_history_service::get_all_history($instance, $context, 0, 30, 'date', 'DESC', $usera->id);

        $this->assertSame(1, $filtered['total']);
    }

    /**
     * Sorting by score ascending puts the lowest-scoring row first — SORTABLE_COLUMNS
     * actually reorders the query, not just accepts the key without effect.
     *
     * @covers \mod_playercross\local\attempts_history_service::get_all_history
     * @return void
     */
    public function test_get_all_history_sorts_by_score_ascending(): void {
        $cm = $this->modgenerator->create_instance(['course' => $this->course->id]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $context = \context_module::instance($cm->cmid);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');
        $user = $this->getDataGenerator()->create_user();
        $this->modgenerator->create_attempt($instance->id, $user->id, $theme->id, [
            'score' => 70.0,
            'timecreated' => time() - 20,
        ]);
        $this->modgenerator->create_attempt($instance->id, $user->id, $theme->id, [
            'score' => 30.0,
            'timecreated' => time() - 10,
        ]);

        $ascending = attempts_history_service::get_all_history($instance, $context, 0, 30, 'score', 'ASC', 0);

        $this->assertSame('30.00', $ascending['rows'][0]['score']);
    }

    /**
     * get_all_history() returns every student's finished attempts, most recent
     * first by default, with each student's own full name attached to their row.
     *
     * @covers \mod_playercross\local\attempts_history_service::get_all_history
     * @return void
     */
    public function test_get_all_history_includes_every_student_most_recent_first(): void {
        $cm = $this->modgenerator->create_instance(['course' => $this->course->id]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $context = \context_module::instance($cm->cmid);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $this->modgenerator->create_attempt($instance->id, $usera->id, $theme->id, ['timecreated' => time() - 20]);
        $this->modgenerator->create_attempt($instance->id, $userb->id, $theme->id, ['timecreated' => time() - 10]);

        $history = attempts_history_service::get_all_history($instance, $context, 0, 30, 'date', 'DESC', 0);

        $this->assertSame(2, $history['total']);
        $this->assertSame(fullname($userb), $history['rows'][0]['student']);
        $this->assertSame(fullname($usera), $history['rows'][1]['student']);
    }

    /**
     * Users who can manage the activity (editingteacher, manager) are excluded from
     * the all-students report and from the filter dropdown — a teacher previewing the
     * activity should not be tracked as a player in a student-facing report.
     *
     * @covers \mod_playercross\local\attempts_history_service::get_all_history
     * @covers \mod_playercross\local\attempts_history_service::get_players_for_filter
     * @return void
     */
    public function test_manager_attempts_are_excluded_from_report(): void {
        $cm = $this->modgenerator->create_instance(['course' => $this->course->id]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $context = \context_module::instance($cm->cmid);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');

        $this->modgenerator->create_attempt($instance->id, $student->id, $theme->id);
        $this->modgenerator->create_attempt($instance->id, $teacher->id, $theme->id);

        $history = attempts_history_service::get_all_history($instance, $context, 0, 30, 'date', 'DESC', 0);
        $this->assertSame(1, $history['total']);

        $players = attempts_history_service::get_players_for_filter($instance, $context);
        $this->assertCount(1, $players);
        $this->assertSame((int)$student->id, (int)reset($players)->id);
    }
}
