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
 * Unit tests for intro_service.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Tests for the site-wide "seen intro" user preference tracked by intro_service.
 */
final class intro_service_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A user who never had the preference set has not seen the intro.
     *
     * @covers \mod_playercross\local\intro_service::has_seen_intro
     * @return void
     */
    public function test_has_seen_intro_false_by_default(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse(intro_service::has_seen_intro((int)$user->id));
    }

    /**
     * Marking the intro seen flips has_seen_intro to true for that user.
     *
     * @covers \mod_playercross\local\intro_service::mark_intro_seen
     * @covers \mod_playercross\local\intro_service::has_seen_intro
     * @return void
     */
    public function test_mark_intro_seen_persists(): void {
        $user = $this->getDataGenerator()->create_user();

        intro_service::mark_intro_seen((int)$user->id);

        $this->assertTrue(intro_service::has_seen_intro((int)$user->id));
    }

    /**
     * The preference is site-wide per user, not shared across users: marking one
     * user as having seen the intro must never affect another user's state.
     *
     * @covers \mod_playercross\local\intro_service::mark_intro_seen
     * @covers \mod_playercross\local\intro_service::has_seen_intro
     * @return void
     */
    public function test_has_seen_intro_is_isolated_per_user(): void {
        $seenuser = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();

        intro_service::mark_intro_seen((int)$seenuser->id);

        $this->assertTrue(intro_service::has_seen_intro((int)$seenuser->id));
        $this->assertFalse(intro_service::has_seen_intro((int)$otheruser->id));
    }

    /**
     * Calling mark_intro_seen a second time is a harmless no-op: the preference
     * stays true, it is never toggled back off.
     *
     * @covers \mod_playercross\local\intro_service::mark_intro_seen
     * @covers \mod_playercross\local\intro_service::has_seen_intro
     * @return void
     */
    public function test_mark_intro_seen_is_idempotent(): void {
        $user = $this->getDataGenerator()->create_user();

        intro_service::mark_intro_seen((int)$user->id);
        intro_service::mark_intro_seen((int)$user->id);

        $this->assertTrue(intro_service::has_seen_intro((int)$user->id));
    }

    /**
     * The preference name is exposed as a stable contract for the privacy provider
     * and db/uninstall.php's prefix-based cleanup — a regression guard against
     * silently renaming it in only one of the three places that must agree on it.
     *
     * @covers \mod_playercross\local\intro_service::get_preference_name
     * @return void
     */
    public function test_get_preference_name_is_prefixed_for_the_plugin(): void {
        $this->assertStringStartsWith('mod_playercross_', intro_service::get_preference_name());
    }
}
