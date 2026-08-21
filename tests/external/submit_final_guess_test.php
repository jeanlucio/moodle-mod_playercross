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
 * External function tests for submit_final_guess.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use core_text;
use mod_playercross\local\round_service;

/**
 * Tests for the mod_playercross_submit_final_guess web service.
 *
 * The invariant under test (SCOPE.md §7): a wrong direct guess never reveals the
 * mystery phrase, and no unresolved term's word leaks either.
 *
 * @covers \mod_playercross\external\submit_final_guess
 * @covers \mod_playercross\external\submit_term_guess
 */
final class submit_final_guess_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    /** @var \mod_playercross_generator Activity module generator. */
    private $modgenerator;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
    }

    /**
     * Enables the course's guest-access enrolment method — precondition for the
     * guest-demo-mode regression test: mod/playercross:view (the sole gate on this
     * write service) is granted to the guest archetype, but only reachable at all once
     * a course opts into guest access.
     *
     * @return void
     */
    private function enable_guest_access(): void {
        global $DB;
        $guestplugin = enrol_get_plugin('guest');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'guest'], '*', MUST_EXIST);
        $guestplugin->update_status($instance, ENROL_INSTANCE_ENABLED);
    }

    /**
     * Creates a ready-to-play instance with a small, deterministic word pool.
     *
     * @param array $overrides Instance field overrides.
     * @return array{0: \stdClass, 1: \stdClass} [instance record, course module]
     */
    private function make_ready_instance(array $overrides = []): array {
        $cm = $this->modgenerator->create_instance($overrides + [
            'course' => $this->course->id,
            'num_terms' => 3,
            'theme_min_length' => 6,
        ]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);

        $this->modgenerator->create_word($instance->id, 'escola');
        $this->modgenerator->create_word($instance->id, 'casa');
        $this->modgenerator->create_word($instance->id, 'lobo');
        $this->modgenerator->create_word($instance->id, 'mel');

        return [$instance, $cm];
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
     * Inserts a genuine block_playerhud_items record (with its own block instance).
     *
     * @return int Item id.
     */
    private function make_hud_item(): int {
        global $DB;
        $ctx = \context_course::instance($this->course->id);
        $biid = $DB->insert_record('block_instances', (object)[
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
        return $DB->insert_record('block_playerhud_items', (object)[
            'blockinstanceid' => $biid,
            'name'            => 'Gold Key',
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
     * A wrong direct guess never reveals the mystery phrase or any term word.
     *
     * @return void
     */
    public function test_wrong_final_guess_never_leaks_theme_word(): void {
        [$instance, $cm] = $this->make_ready_instance();
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        round_service::save_state($cm->cmid, $this->student->id, $state);

        $result = submit_final_guess::execute($cm->cmid, 'totalmenteerrado');

        $this->assertFalse($result['correct']);
        $this->assertFalse($result['finished']);
        $this->assertSame('', $result['panel']['revealthemeword']);
        foreach ($result['panel']['terms'] as $termrow) {
            $this->assertSame('', $termrow['revealword']);
        }
    }

    /**
     * Running out of attempts for the final guess ends the round as a loss and flags
     * finalguessexhausted in the panel response, through the real web service dispatch
     * path — regression coverage for the exhaustion mechanic added alongside Binary/
     * Linear scoring (see round_service::submit_final_guess()).
     *
     * @return void
     */
    public function test_exhausting_final_guess_attempts_ends_round_and_flags_panel(): void {
        [$instance, $cm] = $this->make_ready_instance(['max_attempts_final_guess' => 1]);
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        round_service::save_state($cm->cmid, $this->student->id, $state);

        $result = submit_final_guess::execute($cm->cmid, 'totalmenteerrado');

        $this->assertFalse($result['correct']);
        $this->assertTrue($result['finished']);
        $this->assertTrue($result['panel']['finalguessexhausted']);
        $this->assertNotSame('', $result['panel']['finalguessexhaustedlabel']);
    }

    /**
     * A correct direct guess with terms still pending does not win the round on its
     * own — winning always requires every term resolved too — and does not reveal the
     * mystery phrase's readable text (revealthemeword) until the round actually
     * finishes. Its tile-by-tile grid (themetiles), however, lights up immediately: the
     * player just demonstrated they know the whole phrase, so nothing is left blank
     * pending the still-unsolved terms.
     *
     * @return void
     */
    public function test_correct_final_guess_alone_does_not_win_or_reveal_theme_word(): void {
        [$instance, $cm] = $this->make_ready_instance();
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        round_service::save_state($cm->cmid, $this->student->id, $state);

        $themephrase = implode(' ', $state['themewords']);
        $result = submit_final_guess::execute($cm->cmid, $themephrase);

        $this->assertTrue($result['correct']);
        $this->assertFalse($result['finished']);
        $this->assertSame('', $result['panel']['revealthemeword']);
        foreach ($result['panel']['themetiles'] as $wordgroup) {
            foreach ($wordgroup['tiles'] as $tile) {
                $this->assertTrue($tile['revealed']);
                $this->assertNotSame('', $tile['letter']);
            }
        }
    }

    /**
     * A correct direct guess, followed by resolving every remaining term, wins the
     * round and reveals the mystery phrase only in that final response.
     *
     * @return void
     */
    public function test_final_guess_then_all_terms_wins_and_reveals_theme_word(): void {
        [$instance, $cm] = $this->make_ready_instance();
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        round_service::save_state($cm->cmid, $this->student->id, $state);

        $themephrase = implode(' ', $state['themewords']);
        submit_final_guess::execute($cm->cmid, $themephrase);

        $result = null;
        foreach ($state['terms'] as $term) {
            $result = submit_term_guess::execute($cm->cmid, (int)$term['wordid'], $term['word']);
        }

        $this->assertTrue($result['finished']);
        $this->assertSame(core_text::strtoupper($themephrase), $result['panel']['revealthemeword']);
    }

    /**
     * The guest account is allowed to play a free demo through the real web service
     * dispatch path, all the way to winning a round: the call succeeds and the response
     * reveals the mystery phrase as usual, but round_service::finish_round() must leave
     * no {playercross_attempts} row behind and grant no PlayerHUD item — every guest
     * visitor to a course shares the same account, so nothing here could be safely
     * attributed to one specific person.
     *
     * @return void
     */
    public function test_guest_can_win_demo_without_persisting(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }

        $ctx = \context_course::instance($this->course->id);
        $biid = $DB->insert_record('block_instances', (object)[
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
        $itemid = $DB->insert_record('block_playerhud_items', (object)[
            'blockinstanceid' => $biid,
            'name'            => 'Gold Key',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);

        $cm = $this->modgenerator->create_instance([
            'course'              => $this->course->id,
            'num_terms'           => 1,
            'theme_min_length'    => 6,
            'win_condition'       => PLAYERCROSS_WINCONDITION_FINALONLY,
            'hud_win_reward_item' => $itemid,
            'hud_win_reward_qty'  => 2,
        ]);
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $this->modgenerator->create_word($instance->id, 'escola');
        $this->modgenerator->create_word($instance->id, 'livro');

        $this->enable_guest_access();
        $this->setGuestUser();
        $guestid = (int)guest_user()->id;

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $guestid),
            $instance,
            $cm->cmid,
            $guestid
        );
        [$state] = round_service::start_round($state, $instance, $guestid);
        round_service::save_state($cm->cmid, $guestid, $state);

        $themephrase = implode(' ', $state['themewords']);
        $result = submit_final_guess::execute($cm->cmid, $themephrase);

        $this->assertTrue($result['correct']);
        $this->assertTrue($result['finished']);
        $this->assertSame(0, $DB->count_records('playercross_attempts', ['playercrossid' => $instance->id]));
        $this->assertSame(0, $DB->count_records('block_playerhud_inventory', ['userid' => $guestid]));
    }

    /**
     * Regression test for the round-cost bypass: a student who skips the "Iniciar
     * rodada" button — the only place a configured PlayerHUD round cost is actually
     * charged — and calls mod_playercross_submit_final_guess directly through the real
     * web service dispatch path must be rejected, even with the correct phrase already
     * sitting in session (view.php's GET-time ensure_round_state() call puts it there
     * before the student ever clicks anything). The round must not finish.
     *
     * @return void
     */
    public function test_rejects_final_guess_when_round_not_started_bypassing_hud_cost(): void {
        $this->skip_if_no_playerhud();
        $itemid = $this->make_hud_item();
        [$instance, $cm] = $this->make_ready_instance([
            'hud_round_cost_item' => $itemid,
            'hud_round_cost_qty'  => 1,
        ]);
        $this->setUser($this->student);

        // Load the puzzle into session (what view.php's GET does), but never call
        // start_round — the exact shape of the exploit in the security report's PoC.
        $state = round_service::load_state($cm->cmid, $this->student->id);
        $state = round_service::ensure_round_state($state, $instance, $cm->cmid, $this->student->id);
        round_service::save_state($cm->cmid, $this->student->id, $state);

        $result = submit_final_guess::execute($cm->cmid, implode(' ', $state['themewords']));

        $this->assertFalse($result['correct']);
        $this->assertFalse($result['finished']);
        $this->assertNotEmpty($result['notification']);
    }

    /**
     * Every other test in this file calls execute() directly, which never exercises
     * execute_returns() — Moodle's external API only validates/cleans a response
     * against that schema when dispatched through the real web service layer. This is
     * the one test that goes through it, so a field genuinely missing from
     * execute_returns() (as opposed to one merely absent from a hand-built context
     * array) would surface here as a thrown exception, not a silently stripped key.
     *
     * @return void
     */
    public function test_real_dispatch_validates_execute_returns_schema(): void {
        [$instance, $cm] = $this->make_ready_instance(['win_condition' => PLAYERCROSS_WINCONDITION_FINALONLY]);
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        round_service::save_state($cm->cmid, $this->student->id, $state);

        $_POST['sesskey'] = sesskey();
        $result = \core_external\external_api::call_external_function(
            'mod_playercross_submit_final_guess',
            ['cmid' => $cm->cmid, 'guess' => implode(' ', $state['themewords'])]
        );

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['correct']);
        $this->assertTrue($result['data']['finished']);
        $this->assertArrayHasKey('showscoreachieved', $result['data']['panel']);
        $this->assertArrayHasKey('showranking', $result['data']['panel']);
    }
}
