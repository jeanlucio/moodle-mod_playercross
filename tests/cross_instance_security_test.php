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
 * Cross-instance isolation tests for mod_playercross.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross;

use mod_playercross\local\attempts_history_service;
use mod_playercross\local\round_service;
use mod_playercross\local\words_repository;

/**
 * Proves that round state, word lookups and attempt records never leak between two
 * different playercross activities, even when the same student plays both and the
 * activities share the same course. The architecture relies on this by construction
 * (session state keyed by cmid+userid, word lookups and attempts scoped by
 * playercrossid), but no test asserted it explicitly until this one.
 */
final class cross_instance_security_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
    }

    /**
     * Creates a playercross instance with exactly one clue slot and a two-word pool: one
     * word long enough to be the mystery phrase (the only theme candidate, so it is always
     * picked deterministically) and one shorter word that only ever qualifies as the clue.
     *
     * @param \stdClass $course Course to create the instance in.
     * @param string $themeword Word to seed as the (only) theme candidate, at least 6 chars.
     * @param string $clueword Word to seed as the clue, under 6 chars.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(\stdClass $course, string $themeword, string $clueword): \stdClass {
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $modgenerator->create_instance([
            'course'           => $course->id,
            'num_clues'        => 1,
            'theme_min_length' => 6,
            'min_length'       => 3,
            'max_length'       => 15,
        ]);

        $modgenerator->create_word($instance->id, $themeword);
        $modgenerator->create_word($instance->id, $clueword);

        return $instance;
    }

    /**
     * A freshly loaded state for one activity must never inherit the mystery phrase
     * picked in another activity, even for the same student in the same course.
     *
     * @covers \mod_playercross\local\round_service::load_state
     * @covers \mod_playercross\local\round_service::ensure_round_state
     * @return void
     */
    public function test_session_state_is_isolated_per_activity(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $instancea = $this->make_instance($course, 'escola', 'livro');
        $instanceb = $this->make_instance($course, 'caderno', 'papel');

        $statea = round_service::load_state($instancea->cmid, $user->id);
        $statea = round_service::ensure_round_state($statea, $instancea, $instancea->cmid, $user->id);
        round_service::save_state($instancea->cmid, $user->id, $statea);

        $stateb = round_service::load_state($instanceb->cmid, $user->id);

        $this->assertSame(['escola'], $statea['themewords']);
        $this->assertSame(0, $stateb['themewordid']);
        $this->assertSame([], $stateb['themewords']);
    }

    /**
     * A word id that belongs to one activity must never resolve against another
     * activity's id, even when both activities live in the same course. Without this
     * guard, a client that (maliciously or by a client-side bug) sent another
     * activity's word id alongside this activity's instance id could reveal that
     * activity's clue or mystery phrase.
     *
     * @covers \mod_playercross\local\words_repository::get_approved_word_by_id
     * @return void
     */
    public function test_word_lookup_is_scoped_to_its_own_activity(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $instancea = $this->make_instance($course, 'escola', 'livro');
        $instanceb = $this->make_instance($course, 'caderno', 'papel');

        $wordb = $DB->get_record(
            'playercross_words',
            ['playercrossid' => $instanceb->id, 'word' => 'papel'],
            '*',
            MUST_EXIST
        );

        $crosslookup = words_repository::get_approved_word_by_id((int)$wordb->id, (int)$instancea->id);
        $this->assertNull($crosslookup);

        $ownlookup = words_repository::get_approved_word_by_id((int)$wordb->id, (int)$instanceb->id);
        $this->assertNotNull($ownlookup);
        $this->assertSame('papel', $ownlookup->word);
    }

    /**
     * A round finished in one activity must only ever create an attempt record for
     * that activity, never for another one owned by the same student.
     *
     * @covers \mod_playercross\local\round_service::submit_final_guess
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @return void
     */
    public function test_attempts_are_scoped_to_their_own_activity(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $instancea = $this->make_instance($course, 'escola', 'livro');
        $instanceb = $this->make_instance($course, 'caderno', 'papel');

        $state = round_service::load_state($instancea->cmid, $user->id);
        $state = round_service::ensure_round_state($state, $instancea, $instancea->cmid, $user->id);
        [$state] = round_service::start_round($state, $instancea, $user->id);
        $clue = $state['clues'][0];
        [$state] = round_service::submit_clue_guess(
            $state,
            $instancea,
            $instancea->cmid,
            $user->id,
            (int)$clue['wordid'],
            $clue['word']
        );
        [, $correct] = round_service::submit_final_guess($state, $instancea, $instancea->cmid, $user->id, 'escola');

        $this->assertTrue($correct);
        $this->assertSame(1, $DB->count_records('playercross_attempts', ['playercrossid' => $instancea->id]));
        $this->assertSame(0, $DB->count_records('playercross_attempts', ['playercrossid' => $instanceb->id]));
    }

    /**
     * The "my attempts" history must never surface another activity's rounds, nor
     * another student's rounds within the same activity — both are filtered directly
     * in the SQL, not merely by capability, so this proves the query itself is safe.
     *
     * @covers \mod_playercross\local\attempts_history_service::get_history
     * @return void
     */
    public function test_attempts_history_is_scoped_to_activity_and_user(): void {
        $course = $this->getDataGenerator()->create_course();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $instancea = $this->make_instance($course, 'escola', 'livro');
        $instanceb = $this->make_instance($course, 'caderno', 'papel');

        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $themea = $modgenerator->create_word($instancea->id, 'girassol');

        $modgenerator->create_attempt($instancea->id, $usera->id, $themea->id);
        // Another activity, same student — must not leak into instancea's history.
        $themeb = $modgenerator->create_word($instanceb->id, 'girassol');
        $modgenerator->create_attempt($instanceb->id, $usera->id, $themeb->id);
        // Same activity, another student — must not leak into usera's history.
        $modgenerator->create_attempt($instancea->id, $userb->id, $themea->id);

        $history = attempts_history_service::get_history($instancea, $usera->id);

        $this->assertCount(1, $history['rows']);
    }
}
