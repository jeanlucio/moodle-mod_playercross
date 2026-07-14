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
 * Unit tests for ranking_service.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Tests for ranking_service — requires database.
 */
final class ranking_service_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Activity instance used by the tests, with ->cmid and ->id. */
    private \stdClass $instance;

    /** @var \stdClass Real course_modules record for the instance. */
    private \stdClass $cm;

    /** @var \stdClass Theme word used to seed attempts. */
    private \stdClass $theme;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $this->instance = $modgenerator->create_instance(['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance(
            'playercross',
            $this->instance->id,
            $this->course->id,
            false,
            MUST_EXIST
        );
        $this->theme = $modgenerator->create_word($this->instance->id, 'escola');
    }

    /**
     * Inserts one finished attempt record for a user.
     *
     * @param \stdClass $user User.
     * @param float $score Score for the attempt.
     * @param int $attemptsused Attempts used.
     * @param int $timeused Time used, in seconds.
     * @return void
     */
    private function add_attempt(\stdClass $user, float $score, int $attemptsused = 1, int $timeused = 10): void {
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $modgenerator->create_attempt($this->instance->id, $user->id, $this->theme->id, [
            'attempts_used' => $attemptsused,
            'time_used'     => $timeused,
            'score'         => $score,
        ]);
    }

    /**
     * An activity with no attempts yields an empty ranking.
     *
     * @covers \mod_playercross\local\ranking_service::get_ranking
     * @return void
     */
    public function test_get_ranking_is_empty_without_attempts(): void {
        $user = $this->getDataGenerator()->create_user();

        $ranking = ranking_service::get_ranking($this->instance, $this->cm, $user->id);

        $this->assertTrue($ranking['isempty']);
        $this->assertSame([], $ranking['rows']);
        $this->assertFalse($ranking['hasoutsider']);
    }

    /**
     * Rows are ordered by total score descending.
     *
     * @covers \mod_playercross\local\ranking_service::get_ranking
     * @return void
     */
    public function test_get_ranking_orders_by_score_desc(): void {
        $lowuser = $this->getDataGenerator()->create_user();
        $highuser = $this->getDataGenerator()->create_user();

        $this->add_attempt($lowuser, 20);
        $this->add_attempt($highuser, 80);

        $ranking = ranking_service::get_ranking($this->instance, $this->cm, $highuser->id);

        $this->assertFalse($ranking['isempty']);
        $this->assertCount(2, $ranking['rows']);
        $this->assertSame('80.00', $ranking['rows'][0]['totalscore']);
        $this->assertSame(1, $ranking['rows'][0]['position']);
        $this->assertTrue($ranking['rows'][0]['iscurrentuser']);
        $this->assertSame('20.00', $ranking['rows'][1]['totalscore']);
    }

    /**
     * Only the top 5 users appear in rows; a lower-ranked current user gets an
     * outsider row instead of being silently dropped.
     *
     * @covers \mod_playercross\local\ranking_service::get_ranking
     * @return void
     */
    public function test_get_ranking_top5_and_outsider_row(): void {
        $scores = [60, 50, 40, 30, 20, 10];
        $users = [];
        foreach ($scores as $score) {
            $user = $this->getDataGenerator()->create_user();
            $this->add_attempt($user, $score);
            $users[] = $user;
        }
        $lastuser = end($users);

        $ranking = ranking_service::get_ranking($this->instance, $this->cm, $lastuser->id);

        $this->assertCount(5, $ranking['rows']);
        $this->assertTrue($ranking['hasoutsider']);
        $this->assertNotNull($ranking['outsiderrow']);
        $this->assertSame(6, $ranking['outsiderrow']['position']);
        $this->assertSame('10.00', $ranking['outsiderrow']['totalscore']);
        $this->assertTrue($ranking['outsiderrow']['iscurrentuser']);

        foreach ($ranking['rows'] as $row) {
            $this->assertFalse($row['iscurrentuser']);
        }
    }

    /**
     * A user who can manage the activity (editingteacher) never appears in the ranking,
     * even with attempts of their own — the ranking is student-facing, not a raw attempts
     * dump, so a teacher previewing the activity must not pollute it.
     *
     * @covers \mod_playercross\local\ranking_service::get_ranking
     * @return void
     */
    public function test_get_ranking_excludes_users_who_can_manage_the_activity(): void {
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');

        $this->add_attempt($student, 50);
        $this->add_attempt($teacher, 999);

        $ranking = ranking_service::get_ranking($this->instance, $this->cm, $student->id);

        $this->assertCount(1, $ranking['rows']);
        $this->assertSame(fullname($student), $ranking['rows'][0]['fullname']);
        $this->assertFalse($ranking['hasoutsider']);
    }

    /**
     * With SEPARATEGROUPS, the ranking only includes members of the current
     * user's own group, never students from a different group.
     *
     * @covers \mod_playercross\local\ranking_service::get_ranking
     * @return void
     */
    public function test_get_ranking_separategroups_filters_by_group_membership(): void {
        global $DB;

        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->cm->id]);
        $this->cm = get_coursemodule_from_instance(
            'playercross',
            $this->instance->id,
            $this->course->id,
            false,
            MUST_EXIST
        );

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);

        // Explicit, guaranteed-distinct names — the generator's default random pool can
        // otherwise draw the same fullname for two of these three users, making the
        // fullname-based assertions below flaky (MDL random name generator collision).
        $usera = $this->getDataGenerator()->create_user(['firstname' => 'Alpha', 'lastname' => 'Groupmembera']);
        $userb = $this->getDataGenerator()->create_user(['firstname' => 'Beta', 'lastname' => 'Groupmemberb']);
        $userc = $this->getDataGenerator()->create_user(['firstname' => 'Gamma', 'lastname' => 'Outsider']);

        // Adding a group member silently no-ops for a user who isn't enrolled in the
        // group's course (is_enrolled() guard inside groups_add_member()) — enrol first
        // or the membership never lands.
        $this->getDataGenerator()->enrol_user($usera->id, $this->course->id);
        $this->getDataGenerator()->enrol_user($userb->id, $this->course->id);
        $this->getDataGenerator()->enrol_user($userc->id, $this->course->id);

        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $usera->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $userb->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $userc->id]);

        $this->add_attempt($usera, 50);
        $this->add_attempt($userb, 40);
        $this->add_attempt($userc, 90);

        $ranking = ranking_service::get_ranking($this->instance, $this->cm, $usera->id);

        $seenuserids = array_map(
            fn(array $row): string => $row['fullname'],
            $ranking['rows']
        );
        $this->assertCount(2, $ranking['rows']);
        $this->assertContains(fullname($usera), $seenuserids);
        $this->assertContains(fullname($userb), $seenuserids);
        $this->assertNotContains(fullname($userc), $seenuserids);
    }
}
