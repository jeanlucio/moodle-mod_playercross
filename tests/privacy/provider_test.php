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
     * @covers \mod_playercross\privacy\provider::get_metadata
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
     * A user who never had the intro preference set exports no preference data.
     *
     * @covers \mod_playercross\privacy\provider::export_user_preferences
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
     * @covers \mod_playercross\privacy\provider::export_user_preferences
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
     * @covers \mod_playercross\privacy\provider::get_contexts_for_userid
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
     * @covers \mod_playercross\privacy\provider::get_contexts_for_userid
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
     * @covers \mod_playercross\privacy\provider::get_users_in_context
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
     * @covers \mod_playercross\privacy\provider::get_users_in_context
     * @return void
     */
    public function test_get_users_in_context_ignores_non_module_context(): void {
        $userlist = new userlist(\context_system::instance(), 'mod_playercross');

        provider::get_users_in_context($userlist);

        $this->assertSame([], $userlist->get_userids());
    }

    /**
     * Tests that export_user_data writes both attempts and added-word data for the user.
     *
     * @covers \mod_playercross\privacy\provider::export_user_data
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
     * Tests that export_user_data is a no-op for an empty approved contextlist.
     *
     * @covers \mod_playercross\privacy\provider::export_user_data
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
     * @covers \mod_playercross\privacy\provider::delete_data_for_user
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
     * @covers \mod_playercross\privacy\provider::delete_data_for_user
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
     * @covers \mod_playercross\privacy\provider::delete_data_for_users
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
     * @covers \mod_playercross\privacy\provider::delete_data_for_all_users_in_context
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
     * @covers \mod_playercross\privacy\provider::delete_data_for_all_users_in_context
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
