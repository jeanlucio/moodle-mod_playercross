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

use mod_playercross\event\attempt_deleted;

/**
 * Tests for attempts_history_service — requires database.
 *
 * @covers \mod_playercross\local\attempts_history_service
 * @covers \mod_playercross\event\attempt_deleted
 */
final class attempts_history_service_test extends \advanced_testcase {
    /** @var \stdClass Course used to host test instances. */
    private \stdClass $course;

    /** @var \stdClass Generic viewer user for get_all_history()/get_players_for_filter()
     * calls that are not themselves testing SEPARATEGROUPS scoping — NOGROUPS is the
     * default groupmode, so which user this is never affects those tests. */
    private \stdClass $user;

    /** @var \mod_playercross_generator Activity module generator. */
    private $modgenerator;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
    }

    /**
     * get_history() only returns the given user's own attempts, most recent first —
     * the sole security boundary the "my attempts" page relies on.
     *
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
     * get_history() includes a formatted rankingpoints column, gated by the
     * activity's own show_ranking setting — hidden (blank) when ranking is disabled,
     * shown when enabled, and independent from score when they diverge.
     *
     * @return void
     */
    public function test_get_history_rankingpoints_gated_by_show_ranking(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();

        $cmoff = $this->modgenerator->create_instance(['course' => $this->course->id, 'show_ranking' => 0]);
        $instanceoff = $DB->get_record('playercross', ['id' => $cmoff->id], '*', MUST_EXIST);
        $themeoff = $this->modgenerator->create_word($instanceoff->id, 'escola');
        $this->modgenerator->create_attempt($instanceoff->id, $user->id, $themeoff->id, [
            'score' => 50.0,
            'rankingpoints' => 60.0,
        ]);

        $historyoff = attempts_history_service::get_history($instanceoff, $user->id);
        $this->assertFalse($historyoff['showranking']);
        $this->assertSame('', $historyoff['rows'][0]['rankingpoints']);

        $cmon = $this->modgenerator->create_instance(['course' => $this->course->id, 'show_ranking' => 1]);
        $instanceon = $DB->get_record('playercross', ['id' => $cmon->id], '*', MUST_EXIST);
        $themeon = $this->modgenerator->create_word($instanceon->id, 'escola');
        $this->modgenerator->create_attempt($instanceon->id, $user->id, $themeon->id, [
            'score' => 50.0,
            'rankingpoints' => 60.0,
        ]);

        $historyon = attempts_history_service::get_history($instanceon, $user->id);
        $this->assertTrue($historyon['showranking']);
        $this->assertSame('60.00', $historyon['rows'][0]['rankingpoints']);
        $this->assertSame('50.00', $historyon['rows'][0]['score']);
    }

    /**
     * An activity with no attempts at all yields an empty history and no grade.
     *
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
     * @return void
     */
    public function test_get_all_history_paginates_and_falls_back_on_unknown_sort(): void {
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('playercross', $instance->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
        for ($i = 0; $i < 5; $i++) {
            $this->modgenerator->create_attempt($instance->id, $user->id, $theme->id, [
                'timecreated' => time() + $i,
            ]);
        }

        $page1 = attempts_history_service::get_all_history($cm, $instance, $context, $this->user->id, 0, 2, 'date', 'DESC', 0);
        $this->assertSame(5, $page1['total']);
        $this->assertCount(2, $page1['rows']);

        // A malicious sort key isn't realistic here since PARAM_ALPHA already strips
        // it upstream, but any key absent from SORTABLE_COLUMNS must still
        // resolve to the safe default rather than erroring out.
        $fallback = attempts_history_service::get_all_history(
            $cm,
            $instance,
            $context,
            $this->user->id,
            0,
            2,
            'nosuchcolumn',
            'DESC',
            0
        );
        $this->assertSame(5, $fallback['total']);
    }

    /**
     * get_all_history() filters to a single student when a userid is given.
     *
     * @return void
     */
    public function test_get_all_history_filters_by_student(): void {
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('playercross', $instance->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $this->modgenerator->create_attempt($instance->id, $usera->id, $theme->id);
        $this->modgenerator->create_attempt($instance->id, $userb->id, $theme->id);

        $filtered = attempts_history_service::get_all_history(
            $cm,
            $instance,
            $context,
            $this->user->id,
            0,
            30,
            'date',
            'DESC',
            $usera->id
        );

        $this->assertSame(1, $filtered['total']);
    }

    /**
     * Sorting by score ascending puts the lowest-scoring row first — SORTABLE_COLUMNS
     * actually reorders the query, not just accepts the key without effect.
     *
     * @return void
     */
    public function test_get_all_history_sorts_by_score_ascending(): void {
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('playercross', $instance->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
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

        $ascending = attempts_history_service::get_all_history(
            $cm,
            $instance,
            $context,
            $this->user->id,
            0,
            30,
            'score',
            'ASC',
            0
        );

        $this->assertSame('30.00', $ascending['rows'][0]['score']);
    }

    /**
     * get_all_history() returns every student's finished attempts, most recent
     * first by default, with each student's own full name attached to their row.
     *
     * @return void
     */
    public function test_get_all_history_includes_every_student_most_recent_first(): void {
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('playercross', $instance->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $this->modgenerator->create_attempt($instance->id, $usera->id, $theme->id, ['timecreated' => time() - 20]);
        $this->modgenerator->create_attempt($instance->id, $userb->id, $theme->id, ['timecreated' => time() - 10]);

        $history = attempts_history_service::get_all_history(
            $cm,
            $instance,
            $context,
            $this->user->id,
            0,
            30,
            'date',
            'DESC',
            0
        );

        $this->assertSame(2, $history['total']);
        $this->assertSame(fullname($userb), $history['rows'][0]['student']);
        $this->assertSame(fullname($usera), $history['rows'][1]['student']);
    }

    /**
     * Users who can manage the activity (editingteacher, manager) are excluded from
     * the all-students report and from the filter dropdown — a teacher previewing the
     * activity should not be tracked as a player in a student-facing report.
     *
     * @return void
     */
    public function test_manager_attempts_are_excluded_from_report(): void {
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('playercross', $instance->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');

        $this->modgenerator->create_attempt($instance->id, $student->id, $theme->id);
        $this->modgenerator->create_attempt($instance->id, $teacher->id, $theme->id);

        $history = attempts_history_service::get_all_history(
            $cm,
            $instance,
            $context,
            $this->user->id,
            0,
            30,
            'date',
            'DESC',
            0
        );
        $this->assertSame(1, $history['total']);

        $players = attempts_history_service::get_players_for_filter($cm, $instance, $context, $this->user->id);
        $this->assertCount(1, $players);
        $this->assertSame((int)$student->id, (int)reset($players)->id);
    }

    /**
     * Puts the instance's course module into SEPARATEGROUPS mode and returns the
     * reloaded $cm record, whose ->groupmode a stale in-memory copy would still
     * report as 0 (NOGROUPS).
     *
     * @param \stdClass $instance Activity instance.
     * @return \stdClass Reloaded course module record.
     */
    private function enable_separategroups(\stdClass $instance): \stdClass {
        global $DB;
        $cm = get_coursemodule_from_instance('playercross', $instance->id, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cm->id]);
        return get_coursemodule_from_instance('playercross', $instance->id, 0, false, MUST_EXIST);
    }

    /**
     * Regression test: with SEPARATEGROUPS active, a viewer restricted to one group
     * (a non-editing teacher, the exact role mod/playercross:viewreports is granted to
     * by default) must not see another group's students in the all-students report —
     * mirroring the restriction ranking_service::get_ranking() already applies.
     *
     * @return void
     */
    public function test_get_all_history_separategroups_restricts_to_viewers_own_group(): void {
        global $DB;
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = $this->enable_separategroups($instance);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'teacher');
        $studenta = $this->getDataGenerator()->create_user();
        $studentb = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studenta->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($studentb->id, $this->course->id, 'student');

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $this->modgenerator->create_attempt($instance->id, $studenta->id, $theme->id, ['score' => 50.0]);
        $this->modgenerator->create_attempt($instance->id, $studentb->id, $theme->id, ['score' => 90.0]);

        $history = attempts_history_service::get_all_history(
            $cm,
            $instance,
            $context,
            $teacher->id,
            0,
            30,
            'date',
            'DESC',
            0
        );

        $this->assertSame(1, $history['total']);
        $this->assertSame(fullname($studenta), $history['rows'][0]['student']);
    }

    /**
     * Regression test for the studentid bypass in the report's PoC: even if the
     * filter dropdown is correctly scoped, requesting another group's student id
     * directly via the studentid parameter must still return nothing — the group
     * scope is enforced as an additional SQL condition, not just a UI-level filter.
     *
     * @return void
     */
    public function test_get_all_history_separategroups_studentid_filter_cannot_bypass_group_scope(): void {
        global $DB;
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = $this->enable_separategroups($instance);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'teacher');
        $studentb = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studentb->id, $this->course->id, 'student');

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $this->modgenerator->create_attempt($instance->id, $studentb->id, $theme->id, ['score' => 90.0]);

        $history = attempts_history_service::get_all_history(
            $cm,
            $instance,
            $context,
            $teacher->id,
            0,
            30,
            'date',
            'DESC',
            $studentb->id
        );

        $this->assertSame(0, $history['total']);
        $this->assertTrue($history['isempty']);
    }

    /**
     * The filter dropdown itself must not offer a student from another group either —
     * otherwise the UI would advertise an id the report then (correctly) refuses.
     *
     * @return void
     */
    public function test_get_players_for_filter_separategroups_excludes_other_group(): void {
        global $DB;
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = $this->enable_separategroups($instance);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'teacher');
        $studenta = $this->getDataGenerator()->create_user();
        $studentb = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studenta->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($studentb->id, $this->course->id, 'student');

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $this->modgenerator->create_attempt($instance->id, $studenta->id, $theme->id, ['score' => 50.0]);
        $this->modgenerator->create_attempt($instance->id, $studentb->id, $theme->id, ['score' => 90.0]);

        $players = attempts_history_service::get_players_for_filter($cm, $instance, $context, $teacher->id);

        $this->assertCount(1, $players);
        $this->assertSame(fullname($studenta), $players[0]->fullname);
    }

    /**
     * A viewer holding moodle/site:accessallgroups sees every group's students
     * despite SEPARATEGROUPS — the standard Moodle override for a report-viewing
     * role, mirrored from the fix's own recommendation rather than left unhandled.
     *
     * @return void
     */
    public function test_get_all_history_accessallgroups_overrides_separategroups(): void {
        global $DB;
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = $this->enable_separategroups($instance);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $viewer = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($viewer->id, $this->course->id, 'teacher');
        $roleid = create_role(
            'Accessallgroups viewer',
            'accessallgroupsviewer',
            'Holds moodle/site:accessallgroups'
        );
        // Core declares moodle/site:accessallgroups at CONTEXT_MODULE — assigning it at
        // the module context mirrors exactly where a real site would override it (the
        // activity's own Permissions screen), not the course context above it.
        assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $viewer->id, $context->id);

        $studenta = $this->getDataGenerator()->create_user();
        $studentb = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studenta->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($studentb->id, $this->course->id, 'student');
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);
        // Viewer deliberately joins neither group — accessallgroups alone must be enough.

        $this->modgenerator->create_attempt($instance->id, $studenta->id, $theme->id, ['score' => 50.0]);
        $this->modgenerator->create_attempt($instance->id, $studentb->id, $theme->id, ['score' => 90.0]);

        $history = attempts_history_service::get_all_history(
            $cm,
            $instance,
            $context,
            $viewer->id,
            0,
            30,
            'date',
            'DESC',
            0
        );

        $this->assertSame(2, $history['total']);
    }

    /**
     * Regression test for the security audit finding (ported from mod_playerwords,
     * which the same bug was found and fixed in first): both get_all_history()'s
     * 'student' column and get_players_for_filter()'s ->fullname used to build the
     * displayed name with $DB->sql_fullname() directly, ignoring both
     * $CFG->fullnamedisplay and the moodle/site:viewfullnames capability. The default
     * teacher archetype already holds that capability (so it would not have shown this
     * bug), so this uses a custom report-viewer role without it — a realistic
     * configuration for a site that restricts full-name visibility more tightly than
     * core's own teacher default.
     *
     * @return void
     */
    public function test_get_all_history_and_players_hide_surname_without_viewfullnames_capability(): void {
        global $CFG, $DB;

        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('playercross', $instance->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $roleid = create_role('Report viewer without full names', 'reportviewernofullnames', '');
        assign_capability('mod/playercross:viewreports', CAP_ALLOW, $roleid, $context->id, true);
        assign_capability('moodle/site:viewfullnames', CAP_PREVENT, $roleid, $context->id, true);
        $viewer = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($viewer->id, $this->course->id, 'student');
        role_assign($roleid, $viewer->id, $context->id);

        $student = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Secret']);
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->modgenerator->create_attempt($instance->id, $student->id, $theme->id, ['score' => 50.0]);

        $CFG->fullnamedisplay = 'firstname';
        $this->setUser($viewer);

        $history = attempts_history_service::get_all_history(
            $cm,
            $instance,
            $context,
            $viewer->id,
            0,
            30,
            'date',
            'DESC',
            0
        );
        $players = attempts_history_service::get_players_for_filter($cm, $instance, $context, $viewer->id);

        $this->assertSame('Ana', $history['rows'][0]['student']);
        $this->assertCount(1, $players);
        $this->assertSame('Ana', $players[0]->fullname);
    }

    /**
     * The ordinary case: a valid attemptid belonging to this instance is deleted, the
     * owning userid is returned so the caller knows whose grade to recompute, and an
     * attempt_deleted event fires with the right data.
     *
     * @return void
     */
    public function test_delete_attempts_removes_the_row_and_fires_event(): void {
        global $DB;
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('playercross', $instance->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');
        $student = $this->getDataGenerator()->create_user();
        $attempt = $this->modgenerator->create_attempt($instance->id, $student->id, $theme->id);

        $sink = $this->redirectEvents();
        $affected = attempts_history_service::delete_attempts(
            [$attempt->id],
            $cm,
            $instance,
            $context,
            $this->user->id
        );

        $this->assertSame([(int)$student->id], $affected);
        $this->assertFalse($DB->record_exists('playercross_attempts', ['id' => $attempt->id]));

        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof attempt_deleted));
        $this->assertCount(1, $events);
        $this->assertSame((int)$attempt->id, $events[0]->objectid);
        $this->assertSame((int)$student->id, $events[0]->relateduserid);
        $this->assertSame((int)$instance->id, $events[0]->other['playercrossid']);
    }

    /**
     * An attemptid belonging to a different instance is never deleted, no matter how
     * it is passed in — the instance-isolation invariant every other query in this
     * class already upholds.
     *
     * @return void
     */
    public function test_delete_attempts_ignores_attempt_from_another_instance(): void {
        global $DB;
        $modinstancea = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $modinstanceb = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instancea = $DB->get_record('playercross', ['id' => $modinstancea->id], '*', MUST_EXIST);
        $instanceb = $DB->get_record('playercross', ['id' => $modinstanceb->id], '*', MUST_EXIST);
        $cma = get_coursemodule_from_instance('playercross', $instancea->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cma->id);
        $themeb = $this->modgenerator->create_word($instanceb->id, 'escola');
        $student = $this->getDataGenerator()->create_user();
        $attemptb = $this->modgenerator->create_attempt($instanceb->id, $student->id, $themeb->id);

        // Attacking instance A's report with instance B's attemptid.
        $affected = attempts_history_service::delete_attempts(
            [$attemptb->id],
            $cma,
            $instancea,
            $context,
            $this->user->id
        );

        $this->assertSame([], $affected);
        $this->assertTrue($DB->record_exists('playercross_attempts', ['id' => $attemptb->id]));
    }

    /**
     * With SEPARATEGROUPS active, a viewer restricted to one group cannot delete an
     * attempt belonging to a student in a different group — the same restriction
     * get_all_history() already enforces, now also enforced on the write path.
     *
     * @return void
     */
    public function test_delete_attempts_separategroups_cannot_delete_outside_group(): void {
        global $DB;
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = $this->enable_separategroups($instance);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'teacher');
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($outsider->id, $this->course->id, 'student');

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        // The outsider deliberately joins no group the teacher shares.

        $attempt = $this->modgenerator->create_attempt($instance->id, $outsider->id, $theme->id);

        $affected = attempts_history_service::delete_attempts(
            [$attempt->id],
            $cm,
            $instance,
            $context,
            $teacher->id
        );

        $this->assertSame([], $affected);
        $this->assertTrue($DB->record_exists('playercross_attempts', ['id' => $attempt->id]));
    }

    /**
     * Deleting several attempts across two students in one call returns both their
     * userids, deduplicated even when a student has more than one attempt deleted at
     * once.
     *
     * @return void
     */
    public function test_delete_attempts_bulk_returns_distinct_affected_userids(): void {
        global $DB;
        $modinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instance = $DB->get_record('playercross', ['id' => $modinstance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('playercross', $instance->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $theme = $this->modgenerator->create_word($instance->id, 'escola');
        $studenta = $this->getDataGenerator()->create_user();
        $studentb = $this->getDataGenerator()->create_user();

        $attempta1 = $this->modgenerator->create_attempt($instance->id, $studenta->id, $theme->id);
        $attempta2 = $this->modgenerator->create_attempt($instance->id, $studenta->id, $theme->id);
        $attemptb = $this->modgenerator->create_attempt($instance->id, $studentb->id, $theme->id);

        $affected = attempts_history_service::delete_attempts(
            [$attempta1->id, $attempta2->id, $attemptb->id],
            $cm,
            $instance,
            $context,
            $this->user->id
        );

        sort($affected);
        $expected = [(int)$studenta->id, (int)$studentb->id];
        sort($expected);
        $this->assertSame($expected, $affected);
        $this->assertSame(0, $DB->count_records('playercross_attempts', ['playercrossid' => $instance->id]));
    }
}
