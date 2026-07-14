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
 * Backup and restore tests for mod_playercross.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross;

/**
 * Tests that duplicating a playercross activity, and backing up/restoring a full
 * course, complete without error and preserve every column.
 */
final class backup_restore_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that duplicating an activity copies its words, renames the copy, and is
     * immediately visible — a regression test for a missing prepare_activity_structure()
     * call in the restore step, which would leave the restore's old-to-new context
     * mapping unset. That mapping is what the generic post-restore duplicate flow
     * (renaming to "(copy)", moving the module, rebuilding the course cache) and the
     * generic calendar-events restore step both depend on; without it, duplicating
     * throws unknown_context_mapping and leaves the copy invisible until caches are
     * purged.
     *
     * @covers \restore_playercross_activity_structure_step::define_structure
     * @return void
     */
    public function test_duplicate_activity(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/playercross/lib.php');

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instancecm = $modgenerator->create_instance(['course' => $course->id]);
        $modgenerator->create_word($instancecm->id, 'teste');

        $cm = get_coursemodule_from_instance('playercross', $instancecm->id, $course->id, false, MUST_EXIST);

        $newcm = duplicate_module($course, $cm);

        $this->assertNotNull($newcm);
        $this->assertNotSame($cm->id, $newcm->id);
        $this->assertStringContainsString('(copy)', $newcm->name);

        $newinstance = $DB->get_record('playercross', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('playercross_words', ['playercrossid' => $newinstance->id]));

        // No explicit cache purge here: this proves the context mapping (and therefore
        // the whole post-restore cleanup) actually ran, since a stale course cache is
        // exactly the symptom the missing mapping would cause.
        $modinfo = get_fast_modinfo($course->id);
        $this->assertNotNull($modinfo->get_cm($newcm->id));

        // Regression guard: the restore step must never also call
        // playercross_grade_item_update() in after_execute(), which would race against
        // the generic grades-restore step and leave two grade_items for the same instance.
        $this->assertSame(1, $DB->count_records('grade_items', [
            'courseid'     => $course->id,
            'itemtype'     => 'mod',
            'itemmodule'   => 'playercross',
            'iteminstance' => $newinstance->id,
        ]));

        unset($user);
    }

    /**
     * Backs up the given course and restores it into a brand new course, returning
     * that course. Mirrors block_playerhud's own full-course backup/restore test pattern.
     *
     * @param \stdClass $course Source course.
     * @return \stdClass The new course the backup was restored into.
     */
    private function backup_and_restore_into_new_course(\stdClass $course): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $admin = get_admin();

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id
        );
        $bc->execute_plan();
        $backupfile = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $newcourse = $this->getDataGenerator()->create_course();
        $tempdir = \restore_controller::get_tempdir_name($newcourse->id, $admin->id);
        $fp = get_file_packer('application/vnd.moodle.backup');
        $backupfile->extract_to_pathname($fp, make_backup_temp_directory($tempdir));

        $rc = new \restore_controller(
            $tempdir,
            $newcourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        return $newcourse;
    }

    /**
     * A full course backup/restore must carry every playercross_attempts column,
     * including the puzzle-specific ones (cluestotal, cluesresolved, finalguessed) — a
     * regression test for the backup/restore checklist rule that every install.xml
     * column must be mirrored into the matching backup_nested_element() attribute
     * list; a column added after the initial backup implementation silently reverts to
     * its DB default on restore otherwise, with nothing in PHPCS/moodlecheck/PHPStan
     * catching the omission.
     *
     * @covers \backup_playercross_activity_structure_step::define_structure
     * @return void
     */
    public function test_backup_restore_preserves_attempt_columns(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instancecm = $modgenerator->create_instance(['course' => $course->id]);
        $theme = $modgenerator->create_word($instancecm->id, 'escola');
        $modgenerator->create_attempt($instancecm->id, $user->id, $theme->id, [
            'cluestotal'    => 5,
            'cluesresolved' => 4,
            'finalguessed'  => 1,
            'attempts_used' => 7,
            'time_used'     => 42,
            'completed'     => 1,
            'score'         => 91.5,
        ]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playercross', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newattempt = $DB->get_record('playercross_attempts', ['playercrossid' => $newinstance->id], '*', MUST_EXIST);

        $this->assertSame(5, (int)$newattempt->cluestotal);
        $this->assertSame(4, (int)$newattempt->cluesresolved);
        $this->assertSame(1, (int)$newattempt->finalguessed);
        $this->assertSame(7, (int)$newattempt->attempts_used);
        $this->assertSame(42, (int)$newattempt->time_used);
        $this->assertEqualsWithDelta(91.5, (float)$newattempt->score, 0.001);
    }

    /**
     * A full course backup/restore must preserve playercross_words.timemodified — a
     * column-drift regression guard mirroring the exact bug class that hit
     * PlayerWords in production before this checklist rule existed.
     *
     * @covers \backup_playercross_activity_structure_step::define_structure
     * @covers \restore_playercross_activity_structure_step::process_playercross_word
     * @return void
     */
    public function test_backup_restore_preserves_word_timemodified(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instancecm = $modgenerator->create_instance(['course' => $course->id]);
        $word = $modgenerator->create_word($instancecm->id, 'escola');
        $originaltimemodified = time() - 12345;
        $DB->set_field('playercross_words', 'timemodified', $originaltimemodified, ['id' => $word->id]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playercross', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newword = $DB->get_record('playercross_words', ['playercrossid' => $newinstance->id], '*', MUST_EXIST);
        $this->assertSame($originaltimemodified, (int)$newword->timemodified);
    }

    /**
     * Skips the current test when block_playerhud is not installed.
     *
     * @return void
     */
    private function skip_if_no_playerhud(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
    }

    /**
     * Inserts a block_instances record for block_playerhud in the given course context.
     *
     * @param \stdClass $course Course object.
     * @return int Block instance ID.
     */
    private function make_block_instance(\stdClass $course): int {
        global $DB;
        $ctx = \context_course::instance($course->id);
        return $DB->insert_record('block_instances', (object)[
            'blockname'         => 'playerhud',
            'parentcontextid'   => $ctx->id,
            'showinsubcontexts' => 0,
            'pagetypepattern'   => 'course-view-*',
            'subpagepattern'    => null,
            'defaultregion'     => 'side-pre',
            'defaultweight'     => 0,
            'configdata'        => base64_encode(serialize(new \stdClass())),
            'timecreated'       => time(),
            'timemodified'      => time(),
        ]);
    }

    /**
     * Inserts a block_playerhud_items record for the given block instance.
     *
     * @param int $blockinstanceid Block instance ID.
     * @return int Item ID.
     */
    private function make_item(int $blockinstanceid): int {
        global $DB;
        return $DB->insert_record('block_playerhud_items', (object)[
            'blockinstanceid' => $blockinstanceid,
            'name'            => 'Troféu',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    /**
     * Duplicating an activity within the same course never touches the block, so its
     * hud_win_reward_item — still a genuinely valid item in that same course — must
     * survive unchanged, even though no playerhud_item restore mapping was ever
     * registered.
     *
     * @covers \restore_playercross_activity_structure_step::resolve_hud_item
     * @return void
     */
    public function test_duplicate_activity_preserves_hud_item_from_same_course(): void {
        global $CFG, $DB;
        $this->skip_if_no_playerhud();
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid);
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instancecm = $modgenerator->create_instance([
            'course' => $course->id, 'hud_win_reward_item' => $itemid,
        ]);

        $cm = get_coursemodule_from_instance('playercross', $instancecm->id, $course->id, false, MUST_EXIST);
        $newcm = duplicate_module($course, $cm);

        $newinstance = $DB->get_record('playercross', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertSame($itemid, (int)$newinstance->hud_win_reward_item);
    }

    /**
     * A full course backup/restore into a new course carries the PlayerHUD block
     * along, so the restored activity's hud_win_reward_item must point at the item's
     * NEW id, via the playerhud_item mapping block_playerhud's own restore step
     * registers.
     *
     * @covers \restore_playercross_activity_structure_step::resolve_hud_item
     * @return void
     */
    public function test_backup_restore_full_course_remaps_hud_item(): void {
        global $CFG, $DB;
        $this->skip_if_no_playerhud();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid);
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $modgenerator->create_instance(['course' => $course->id, 'hud_win_reward_item' => $itemid]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playercross', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newblock = $DB->get_record('block_instances', [
            'blockname' => 'playerhud',
            'parentcontextid' => \context_course::instance($newcourse->id)->id,
        ], '*', MUST_EXIST);
        $newitemid = $DB->get_field('block_playerhud_items', 'id', [
            'blockinstanceid' => $newblock->id, 'name' => 'Troféu',
        ], MUST_EXIST);

        $this->assertNotSame($itemid, (int)$newitemid);
        $this->assertSame((int)$newitemid, (int)$newinstance->hud_win_reward_item);
    }

    /**
     * An activity whose hud_win_reward_item points at another course's PlayerHUD item
     * — a stale or misconfigured reference that predates this activity's own backup —
     * must have that field dropped on restore, never silently kept pointing at a
     * foreign course's item. The foreign course's own PlayerHUD block is deliberately
     * not part of this backup, so no playerhud_item mapping exists for it either.
     *
     * @covers \restore_playercross_activity_structure_step::resolve_hud_item
     * @return void
     */
    public function test_backup_restore_drops_hud_item_from_foreign_course(): void {
        global $CFG, $DB;
        $this->skip_if_no_playerhud();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->setAdminUser();

        $foreigncourse = $this->getDataGenerator()->create_course();
        $foreignbiid = $this->make_block_instance($foreigncourse);
        $foreignitemid = $this->make_item($foreignbiid);

        $course = $this->getDataGenerator()->create_course();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $modgenerator->create_instance(['course' => $course->id, 'hud_win_reward_item' => $foreignitemid]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playercross', ['course' => $newcourse->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$newinstance->hud_win_reward_item);
    }
}
