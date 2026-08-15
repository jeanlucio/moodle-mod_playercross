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
 * Privacy provider tests for mod_playercross.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_playercross\local\intro_service;

/**
 * Tests for the Privacy API provider.
 *
 * @covers \mod_playercross\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Creates a playercross course module and returns its cm record.
     *
     * @param \stdClass $course Course object.
     * @return \stdClass Course module record (->id is the instance id, ->cmid the module id).
     */
    private function make_cm(\stdClass $course): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_playercross')
            ->create_instance(['course' => $course->id]);
    }

    /**
     * Tests that get_metadata declares both playercross tables and the site-wide
     * "seen intro" user preference.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = new collection('mod_playercross');
        $collection = provider::get_metadata($collection);
        $items = $collection->get_collection();
        $keys = array_map(fn($item) => $item->get_name(), $items);
        $this->assertContains('playercross_attempts', $keys);
        $this->assertContains('playercross_words', $keys);
        $this->assertContains(intro_service::get_preference_name(), $keys);
    }

    /**
     * Tests that the declared playercross_attempts field keys match every real column
     * of the table (minus id). Asserted as a set-equality against $DB->get_columns()
     * rather than checking individual keys one by one, so a future column silently
     * added to install.xml without a matching metadata entry fails this test — unlike
     * playercross_words, every column of this table genuinely is personal data (no
     * catalogue/config columns to carve out), so a strict schema diff is the right
     * tool here rather than a declared-vs-exported comparison.
     *
     * @return void
     */
    public function test_get_metadata_playercross_attempts_fields_match_schema(): void {
        global $DB;

        $tableitem = null;
        foreach (provider::get_metadata(new collection('mod_playercross'))->get_collection() as $item) {
            if ($item->get_name() === 'playercross_attempts') {
                $tableitem = $item;
                break;
            }
        }
        $this->assertNotNull($tableitem);

        $declaredfields = array_keys($tableitem->get_privacy_fields());
        $realcolumns = array_keys($DB->get_columns('playercross_attempts'));
        $realcolumns = array_values(array_diff($realcolumns, ['id']));

        sort($declaredfields);
        sort($realcolumns);
        $this->assertSame($realcolumns, $declaredfields);
    }

    /**
     * Regression test for metadata drift: every field export_user_data() actually
     * puts in a playercross_words row must be declared in get_metadata() — asserted
     * against what export_user_data() really returns, not by hardcoding the expected
     * key list, so a field added to one without the other fails this test rather than
     * silently drifting (the addedby column is deliberately not exported itself, since
     * the export is already scoped by it — that column is checked separately).
     *
     * @return void
     */
    public function test_playercross_words_export_keys_are_all_declared_in_metadata(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $word = $this->getDataGenerator()->get_plugin_generator('mod_playercross')->create_word($cm->id, 'escola');
        $DB->set_field('playercross_words', 'addedby', $user->id, ['id' => $word->id]);

        $context = \context_module::instance($cm->cmid);
        $contextlist = new approved_contextlist($user, 'mod_playercross', [$context->id]);
        provider::export_user_data($contextlist);

        $wordsdata = writer::with_context($context)->get_data([
            get_string('pluginname', 'mod_playercross'),
            get_string('privacy:words', 'mod_playercross'),
        ]);
        $this->assertNotEmpty($wordsdata->words);

        $declaredfields = [];
        foreach (provider::get_metadata(new collection('mod_playercross'))->get_collection() as $item) {
            if ($item->get_name() === 'playercross_words') {
                $declaredfields = array_keys($item->get_privacy_fields());
            }
        }
        $this->assertContains('addedby', $declaredfields);

        foreach (array_keys($wordsdata->words[0]) as $exportedkey) {
            $this->assertContains(
                $exportedkey,
                $declaredfields,
                "Field '$exportedkey' is exported but not declared in get_metadata()."
            );
        }
    }

    /**
     * Regression test: every real column of playercross_words must have an explicit
     * privacy decision — either declared in get_metadata() (asserted above to also
     * match what export_user_data() actually returns) or listed here as a documented
     * exclusion, with the same reasoning as the comment directly above the
     * add_database_table('playercross_words', ...) call in get_metadata(). A new
     * column added to install.xml without updating either list fails this test,
     * instead of silently falling through undeclared and unexplained.
     *
     * @return void
     */
    public function test_playercross_words_every_column_is_declared_or_documented(): void {
        global $DB;

        // Kept in sync by hand with the comment in provider.php::get_metadata():
        // concept mirrors word verbatim for manual/AI rows and otherwise carries
        // glossary content on addedby=0 rows (never attributable to a real user);
        // glossaryid only means anything on those same addedby=0 rows; approved is a
        // moderation flag a manager sets, not necessarily addedby; timemodified can
        // likewise be touched by a manager acting on someone else's word.
        $documentedexclusions = ['concept', 'glossaryid', 'approved', 'timemodified'];

        $tableitem = null;
        foreach (provider::get_metadata(new collection('mod_playercross'))->get_collection() as $item) {
            if ($item->get_name() === 'playercross_words') {
                $tableitem = $item;
                break;
            }
        }
        $this->assertNotNull($tableitem);
        $declaredfields = array_keys($tableitem->get_privacy_fields());

        $realcolumns = array_keys($DB->get_columns('playercross_words'));
        $realcolumns = array_values(array_diff($realcolumns, ['id']));

        $accountedfor = array_merge($declaredfields, $documentedexclusions);
        foreach ($realcolumns as $column) {
            $this->assertContains(
                $column,
                $accountedfor,
                "Column '$column' is neither declared in get_metadata() nor listed as a documented exclusion."
            );
        }

        // Also guards the other direction: an exclusion left in the list after the
        // column itself was renamed or dropped would otherwise go unnoticed.
        foreach ($documentedexclusions as $excluded) {
            $this->assertContains($excluded, $realcolumns);
            $this->assertNotContains($excluded, $declaredfields);
        }
    }

    /**
     * A user who never had the intro preference set exports no preference data.
     *
     * @return void
     */
    public function test_export_user_preferences_no_pref(): void {
        $user = $this->getDataGenerator()->create_user();

        provider::export_user_preferences($user->id);

        $writer = writer::with_context(\context_system::instance());
        $this->assertFalse($writer->has_any_data());
    }

    /**
     * A user who has seen the intro exports exactly that one preference, under the
     * mod_playercross component.
     *
     * @return void
     */
    public function test_export_user_preferences_seen(): void {
        $user = $this->getDataGenerator()->create_user();
        intro_service::mark_intro_seen((int)$user->id);

        provider::export_user_preferences($user->id);

        $writer = writer::with_context(\context_system::instance());
        $this->assertTrue($writer->has_any_data());

        $prefs = (array)$writer->get_user_preferences('mod_playercross');
        $this->assertCount(1, $prefs);
        $this->assertArrayHasKey(intro_service::get_preference_name(), $prefs);
    }

    /**
     * Tests that get_contexts_for_userid finds the context via attempts.
     *
     * @return void
     */
    public function test_get_contexts_for_userid_by_attempts(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $theme = $this->getDataGenerator()->get_plugin_generator('mod_playercross')->create_word($cm->id, 'escola');
        $this->getDataGenerator()->get_plugin_generator('mod_playercross')
            ->create_attempt($cm->id, $user->id, $theme->id);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $contextids = $contextlist->get_contextids();

        $expected = \context_module::instance($cm->cmid)->id;
        $this->assertContains((string)$expected, $contextids);
    }

    /**
     * Tests that get_contexts_for_userid finds the context via added words.
     *
     * @return void
     */
    public function test_get_contexts_for_userid_by_words_added(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $word = $this->getDataGenerator()->get_plugin_generator('mod_playercross')->create_word($cm->id, 'escola');
        $DB->set_field('playercross_words', 'addedby', $user->id, ['id' => $word->id]);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $contextids = $contextlist->get_contextids();

        $expected = \context_module::instance($cm->cmid)->id;
        $this->assertContains((string)$expected, $contextids);
    }

    /**
     * Tests that get_users_in_context returns both attempt users and word authors.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($cm->id, 'escola');
        $DB->set_field('playercross_words', 'addedby', $teacher->id, ['id' => $theme->id]);
        $modgenerator->create_attempt($cm->id, $student->id, $theme->id);

        $context = \context_module::instance($cm->cmid);
        $userlist = new userlist($context, 'mod_playercross');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();

        $this->assertContains((int)$student->id, $userids);
        $this->assertContains((int)$teacher->id, $userids);
    }

    /**
     * Tests that get_users_in_context is a silent no-op for a non-module context.
     *
     * @return void
     */
    public function test_get_users_in_context_ignores_non_module_context(): void {
        $userlist = new userlist(\context_system::instance(), 'mod_playercross');

        provider::get_users_in_context($userlist);

        $this->assertSame([], $userlist->get_userids());
    }

    /**
     * Regression test for a course_modules.instance collision with another module
     * type: get_users_in_context() must resolve the instance id via
     * get_coursemodule_from_id('playercross', ...), not a bare instance lookup, so a
     * page module whose row happens to carry the same numeric instance id as a real
     * playercross activity is never mistaken for it.
     *
     * @return void
     */
    public function test_get_users_in_context_ignores_colliding_instance_id_from_other_module_type(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $student = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($cm->id, 'escola');
        $modgenerator->create_attempt($cm->id, $student->id, $theme->id);

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field('course_modules', 'instance', $cm->id, ['id' => $page->cmid]);

        $context = \context_module::instance($page->cmid);
        $userlist = new userlist($context, 'mod_playercross');
        provider::get_users_in_context($userlist);

        $this->assertSame([], $userlist->get_userids());
    }

    /**
     * Regression test mirroring the one above, for get_contexts_for_userid(): a page
     * module whose course_modules row was made to carry the same numeric instance id
     * as a real playercross activity must not appear in the contextlist of a user who
     * only ever interacted with the playercross activity itself.
     *
     * @return void
     */
    public function test_get_contexts_for_userid_ignores_colliding_instance_id_from_other_module_type(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $student = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($cm->id, 'escola');
        $modgenerator->create_attempt($cm->id, $student->id, $theme->id);

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field('course_modules', 'instance', $cm->id, ['id' => $page->cmid]);

        $contextlist = provider::get_contexts_for_userid($student->id);
        $contextids = $contextlist->get_contextids();

        $realcontext = \context_module::instance($cm->cmid)->id;
        $collidingcontext = \context_module::instance($page->cmid)->id;
        $this->assertContains((string)$realcontext, $contextids);
        $this->assertNotContains((string)$collidingcontext, $contextids);
    }

    /**
     * Tests that export_user_data writes both attempts and added-word data for the user.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($cm->id, 'escola');
        $DB->set_field('playercross_words', 'addedby', $user->id, ['id' => $theme->id]);
        $modgenerator->create_attempt($cm->id, $user->id, $theme->id);

        $context = \context_module::instance($cm->cmid);
        $contextlist = new approved_contextlist($user, 'mod_playercross', [$context->id]);
        provider::export_user_data($contextlist);

        $attemptsdata = writer::with_context($context)->get_data([
            get_string('pluginname', 'mod_playercross'),
            get_string('privacy:attempts', 'mod_playercross'),
        ]);
        $this->assertNotEmpty($attemptsdata->attempts);
        $this->assertSame($theme->id, (int)$attemptsdata->attempts[0]['themewordid']);

        $wordsdata = writer::with_context($context)->get_data([
            get_string('pluginname', 'mod_playercross'),
            get_string('privacy:words', 'mod_playercross'),
        ]);
        $this->assertNotEmpty($wordsdata->words);
        $this->assertSame('escola', $wordsdata->words[0]['word']);
    }

    /**
     * Tests that export_user_data writes the correct, non-overlapping attempts/words for
     * each context when the approved list spans several activities — the correctness side
     * of the N+1 fix: batching by playercrossid must not blend one activity's rows into
     * another's export.
     *
     * @return void
     */
    public function test_export_user_data_across_multiple_contexts(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm1 = $this->make_cm($course);
        $cm2 = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');

        $theme1 = $modgenerator->create_word($cm1->id, 'escola');
        $DB->set_field('playercross_words', 'addedby', $user->id, ['id' => $theme1->id]);
        $modgenerator->create_attempt($cm1->id, $user->id, $theme1->id, ['score' => 10.0]);

        $theme2 = $modgenerator->create_word($cm2->id, 'livro');
        $DB->set_field('playercross_words', 'addedby', $user->id, ['id' => $theme2->id]);
        $modgenerator->create_attempt($cm2->id, $user->id, $theme2->id, ['score' => 20.0]);

        $context1 = \context_module::instance($cm1->cmid);
        $context2 = \context_module::instance($cm2->cmid);
        $contextlist = new approved_contextlist($user, 'mod_playercross', [$context1->id, $context2->id]);
        provider::export_user_data($contextlist);

        $data1 = writer::with_context($context1)->get_data([
            get_string('pluginname', 'mod_playercross'),
            get_string('privacy:attempts', 'mod_playercross'),
        ]);
        $data2 = writer::with_context($context2)->get_data([
            get_string('pluginname', 'mod_playercross'),
            get_string('privacy:attempts', 'mod_playercross'),
        ]);

        $this->assertCount(1, $data1->attempts);
        $this->assertSame($theme1->id, (int)$data1->attempts[0]['themewordid']);
        $this->assertSame(10.0, (float)$data1->attempts[0]['score']);

        $this->assertCount(1, $data2->attempts);
        $this->assertSame($theme2->id, (int)$data2->attempts[0]['themewordid']);
        $this->assertSame(20.0, (float)$data2->attempts[0]['score']);
    }

    /**
     * Regression test for the N+1 pattern flagged by the same security audit that found
     * it in mod_playerwords: reading attempts and words used to run two
     * get_records_select() calls per context in the approved list. Asserts the DB read
     * count stays bounded as the number of contexts grows, instead of scaling linearly
     * with it — mirrors the read-count assertion style already used in
     * blocks/playerhud/tests/quest_test.php and mod_playerwords's own provider_test.php.
     *
     * @return void
     */
    public function test_export_user_data_read_count_does_not_scale_with_contexts(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $user = $this->getDataGenerator()->create_user();
        $contextids = [];
        for ($i = 0; $i < 6; $i++) {
            $cm = $this->make_cm($course);
            $theme = $modgenerator->create_word($cm->id, 'escola' . $i);
            $DB->set_field('playercross_words', 'addedby', $user->id, ['id' => $theme->id]);
            $modgenerator->create_attempt($cm->id, $user->id, $theme->id);
            $contextids[] = \context_module::instance($cm->cmid)->id;
        }

        $contextlist = new approved_contextlist($user, 'mod_playercross', $contextids);

        $readsbefore = $DB->perf_get_reads();
        provider::export_user_data($contextlist);
        $reads = $DB->perf_get_reads() - $readsbefore;

        // Before the fix: 2 reads per context (12 for 6 contexts), plus the shared
        // cmid=>instanceid lookup. After: the cmid=>instanceid lookup plus exactly one
        // bulk read each for attempts and words, regardless of context count.
        $this->assertLessThanOrEqual(4, $reads);
    }

    /**
     * Tests that export_user_data is a no-op for an empty approved contextlist.
     *
     * @return void
     */
    public function test_export_user_data_empty_contextlist_is_noop(): void {
        $user = $this->getDataGenerator()->create_user();
        $contextlist = new approved_contextlist($user, 'mod_playercross', []);

        provider::export_user_data($contextlist);

        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that delete_data_for_user removes only that user's attempts and
     * anonymises their words, for a single context.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');

        $theme = $modgenerator->create_word($cm->id, 'escola');
        $DB->set_field('playercross_words', 'addedby', $user->id, ['id' => $theme->id]);
        $modgenerator->create_attempt($cm->id, $user->id, $theme->id);

        $context = \context_module::instance($cm->cmid);
        $contextlist = new approved_contextlist($user, 'mod_playercross', [$context->id]);

        provider::delete_data_for_user($contextlist);

        $attempts = $DB->count_records('playercross_attempts', [
            'userid'        => $user->id,
            'playercrossid' => (int)$cm->id,
        ]);
        $this->assertSame(0, $attempts);
        $this->assertSame('0', (string)$DB->get_field('playercross_words', 'addedby', ['id' => $theme->id]));
    }

    /**
     * Tests that delete_data_for_user removes only that user's attempts and
     * anonymises their words, across every context in the approved list.
     *
     * @return void
     */
    public function test_delete_data_for_user_across_multiple_contexts(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm1 = $this->make_cm($course);
        $cm2 = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');

        $theme1 = $modgenerator->create_word($cm1->id, 'escola');
        $DB->set_field('playercross_words', 'addedby', $user->id, ['id' => $theme1->id]);
        $modgenerator->create_attempt($cm1->id, $user->id, $theme1->id);

        $theme2 = $modgenerator->create_word($cm2->id, 'caderno');
        $DB->set_field('playercross_words', 'addedby', $user->id, ['id' => $theme2->id]);
        $modgenerator->create_attempt($cm2->id, $user->id, $theme2->id);

        $context1 = \context_module::instance($cm1->cmid);
        $context2 = \context_module::instance($cm2->cmid);
        $contextlist = new approved_contextlist($user, 'mod_playercross', [$context1->id, $context2->id]);

        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, $DB->count_records('playercross_attempts', ['userid' => $user->id]));
        $this->assertSame('0', (string)$DB->get_field('playercross_words', 'addedby', ['id' => $theme1->id]));
        $this->assertSame('0', (string)$DB->get_field('playercross_words', 'addedby', ['id' => $theme2->id]));
    }

    /**
     * Tests that delete_data_for_users removes data for the listed users only.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($cm->id, 'escola');
        $modgenerator->create_attempt($cm->id, $user1->id, $theme->id);
        $modgenerator->create_attempt($cm->id, $user2->id, $theme->id);

        $context = \context_module::instance($cm->cmid);
        $approvedlist = new approved_userlist($context, 'mod_playercross', [$user1->id]);
        provider::delete_data_for_users($approvedlist);

        $this->assertSame(0, $DB->count_records('playercross_attempts', ['userid' => $user1->id]));
        $this->assertSame(1, $DB->count_records('playercross_attempts', ['userid' => $user2->id]));
    }

    /**
     * Tests that delete_data_for_all_users_in_context clears every user's attempts and
     * anonymises every word author within that context only, leaving another
     * activity's data untouched.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cmtarget = $this->make_cm($course);
        $cmother = $this->make_cm($course);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');

        $theme = $modgenerator->create_word($cmtarget->id, 'escola');
        $DB->set_field('playercross_words', 'addedby', $user1->id, ['id' => $theme->id]);
        $modgenerator->create_attempt($cmtarget->id, $user1->id, $theme->id);
        $modgenerator->create_attempt($cmtarget->id, $user2->id, $theme->id);

        $othertheme = $modgenerator->create_word($cmother->id, 'caderno');
        $DB->set_field('playercross_words', 'addedby', $user1->id, ['id' => $othertheme->id]);
        $modgenerator->create_attempt($cmother->id, $user1->id, $othertheme->id);

        provider::delete_data_for_all_users_in_context(\context_module::instance($cmtarget->cmid));

        $this->assertSame(0, $DB->count_records('playercross_attempts', ['playercrossid' => $cmtarget->id]));
        $this->assertSame('0', (string)$DB->get_field('playercross_words', 'addedby', ['id' => $theme->id]));

        $this->assertSame(1, $DB->count_records('playercross_attempts', ['playercrossid' => $cmother->id]));
        $this->assertEquals($user1->id, $DB->get_field('playercross_words', 'addedby', ['id' => $othertheme->id]));
    }

    /**
     * Tests that delete_data_for_all_users_in_context is a silent no-op for a
     * non-module context.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context_ignores_non_module_context(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($cm->id, 'escola');
        $modgenerator->create_attempt($cm->id, $user->id, $theme->id);

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame(1, $DB->count_records('playercross_attempts', ['playercrossid' => $cm->id]));
    }
}
