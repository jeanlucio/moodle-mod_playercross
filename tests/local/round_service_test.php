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
 * Unit tests for round_service.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use mod_playercross\event\round_completed;
use mod_playercross\event\round_started;

/**
 * Tests for round_service — the single source of truth for every round transition.
 * Requires database.
 */
final class round_service_test extends \advanced_testcase {
    /** @var \stdClass Course used to host test instances. */
    private \stdClass $course;

    /** @var \stdClass Student user. */
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
     * Creates a ready-to-play instance and its course module.
     *
     * @param array $overrides Field overrides for create_instance().
     * @return array{0: \stdClass, 1: \stdClass} [instance record, course module]
     */
    private function make_ready_instance(array $overrides = []): array {
        $cm = $this->modgenerator->create_instance($overrides + ['course' => $this->course->id]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);

        $this->modgenerator->create_word($instance->id, 'escola');
        $this->modgenerator->create_word($instance->id, 'casa');
        $this->modgenerator->create_word($instance->id, 'lobo');
        $this->modgenerator->create_word($instance->id, 'mel');

        return [$instance, $cm];
    }

    /**
     * load_state() returns the default (empty) shape when nothing was ever saved.
     *
     * @covers \mod_playercross\local\round_service::load_state
     * @return void
     */
    public function test_load_state_returns_defaults(): void {
        $state = round_service::load_state(1, $this->user->id);
        $this->assertSame(0, $state['themewordid']);
        $this->assertFalse($state['finished']);
        $this->assertSame([], $state['clues']);
    }

    /**
     * ensure_round_state() builds a real puzzle from the approved pool.
     *
     * @covers \mod_playercross\local\round_service::ensure_round_state
     * @return void
     */
    public function test_ensure_round_state_builds_puzzle(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_clues' => 3, 'theme_min_length' => 6]);

        $state = round_service::load_state($cm->cmid, $this->user->id);
        $state = round_service::ensure_round_state($state, $instance, $cm->cmid, $this->user->id);

        $this->assertGreaterThan(0, $state['themewordid']);
        $this->assertSame(3, $state['cluestotal']);
        $this->assertCount(3, $state['clues']);
    }

    /**
     * A wrong clue guess increments its attempt counter without resolving it or
     * revealing any theme letters.
     *
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @return void
     */
    public function test_submit_clue_guess_wrong_increments_attempts(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_clues' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        $clueid = (int)$state['clues'][0]['wordid'];
        $revealedbefore = $state['revealedslots'];

        [$state, $resolved] = round_service::submit_clue_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            $clueid,
            'zzzzzzz'
        );

        $this->assertFalse($resolved);
        $this->assertSame(1, $state['clues'][0]['attemptsused']);
        $this->assertFalse($state['clues'][0]['resolved']);
        $this->assertSame($revealedbefore, $state['revealedslots']);
    }

    /**
     * A correct clue guess resolves it and reveals every theme slot it covers.
     *
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @return void
     */
    public function test_submit_clue_guess_correct_resolves_and_reveals_slots(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_clues' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        $clue = $state['clues'][0];

        [$state, $resolved] = round_service::submit_clue_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            (int)$clue['wordid'],
            $clue['word']
        );

        $this->assertTrue($resolved);
        $this->assertTrue($state['clues'][0]['resolved']);
        $this->assertSame(1, $state['cluesresolved']);
        foreach ($clue['slots'] as $slot) {
            $this->assertContains($slot, $state['revealedslots']);
        }
    }

    /**
     * Resolving every clue in the round wins it and writes the attempts row.
     *
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @return void
     */
    public function test_resolving_all_clues_finishes_and_wins_the_round(): void {
        global $DB;
        [$instance, $cm] = $this->make_ready_instance(['num_clues' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        foreach ($state['clues'] as $clue) {
            [$state] = round_service::submit_clue_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$clue['wordid'],
                $clue['word']
            );
        }

        $this->assertTrue($state['finished']);
        $this->assertTrue($state['won']);
        $this->assertFalse($state['finalguessed']);

        $attempt = $DB->get_record('playercross_attempts', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame(3, (int)$attempt->cluesresolved);
        $this->assertSame(1, (int)$attempt->completed);
    }

    /**
     * A correct direct guess of the mystery phrase wins the round immediately, even
     * with clues still pending.
     *
     * @covers \mod_playercross\local\round_service::submit_final_guess
     * @return void
     */
    public function test_submit_final_guess_correct_wins_round(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_clues' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        [$state, $correct] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            $state['themeword']
        );

        $this->assertTrue($correct);
        $this->assertTrue($state['finished']);
        $this->assertTrue($state['won']);
        $this->assertTrue($state['finalguessed']);
    }

    /**
     * A wrong direct guess of the mystery phrase leaves the round open.
     *
     * @covers \mod_playercross\local\round_service::submit_final_guess
     * @return void
     */
    public function test_submit_final_guess_wrong_keeps_round_open(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_clues' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        [$state, $correct] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            'totalmenteerrado'
        );

        $this->assertFalse($correct);
        $this->assertFalse($state['finished']);
    }

    /**
     * A clue becomes exhausted (never resolvable again this round) once its
     * max_attempts_per_clue is reached, without ending the whole round.
     *
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @return void
     */
    public function test_clue_becomes_exhausted_after_max_attempts(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_clues' => 3,
            'theme_min_length' => 6,
            'max_attempts_per_clue' => 2,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        $clueid = (int)$state['clues'][0]['wordid'];

        [$state] = round_service::submit_clue_guess($state, $instance, $cm->cmid, $this->user->id, $clueid, 'erradoum');
        $this->assertFalse($state['clues'][0]['exhausted']);
        [$state] = round_service::submit_clue_guess($state, $instance, $cm->cmid, $this->user->id, $clueid, 'erradodois');

        $this->assertTrue($state['clues'][0]['exhausted']);
        $this->assertFalse($state['clues'][0]['resolved']);
        $this->assertFalse($state['finished']);
    }

    /**
     * Forfeiting ends the round as a loss without resolving remaining clues.
     *
     * @covers \mod_playercross\local\round_service::forfeit
     * @return void
     */
    public function test_forfeit_ends_round_as_loss(): void {
        global $DB;
        [$instance, $cm] = $this->make_ready_instance(['num_clues' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        [$state] = round_service::forfeit($state, $instance, $cm->cmid, $this->user->id);

        $this->assertTrue($state['finished']);
        $this->assertTrue($state['forfeited']);
        $this->assertFalse($state['won']);

        $attempt = $DB->get_record('playercross_attempts', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$attempt->completed);
    }

    /**
     * A timeout call before the configured deadline (minus tolerance) is rejected —
     * the client's own countdown reaching zero is never trusted on its own.
     *
     * @covers \mod_playercross\local\round_service::timeout
     * @return void
     */
    public function test_timeout_rejected_before_deadline(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_clues' => 3,
            'theme_min_length' => 6,
            'timer_minutes' => 5,
        ]);
        // Timer_minutes is a mod_form-only field, normalised into timer_seconds by
        // playercross_add_instance() — confirm that pipeline actually ran.
        $this->assertSame(300, (int)$instance->timer_seconds);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        [$state] = round_service::timeout($state, $instance, $cm->cmid, $this->user->id);

        $this->assertFalse($state['finished']);
    }

    /**
     * new_round() resets state to defaults, so the next ensure_round_state() builds a
     * completely fresh puzzle.
     *
     * @covers \mod_playercross\local\round_service::new_round
     * @return void
     */
    public function test_new_round_resets_state(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_clues' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        round_service::save_state($cm->cmid, $this->user->id, $state);
        $this->assertGreaterThan(0, round_service::load_state($cm->cmid, $this->user->id)['themewordid']);

        round_service::new_round($cm->cmid, $this->user->id);

        $this->assertSame(0, round_service::load_state($cm->cmid, $this->user->id)['themewordid']);
    }

    /**
     * count_rounds_played() and compute_cooldown_until() reflect real attempt rows.
     *
     * @covers \mod_playercross\local\round_service::count_rounds_played
     * @covers \mod_playercross\local\round_service::compute_cooldown_until
     * @return void
     */
    public function test_count_rounds_and_cooldown(): void {
        [$instance] = $this->make_ready_instance(['cooldown_amount' => 1, 'cooldown_unit' => 'days']);

        $this->assertSame(0, round_service::count_rounds_played($instance, $this->user->id));
        $this->assertSame(0, round_service::compute_cooldown_until($instance, $this->user->id));

        $this->modgenerator->create_attempt($instance->id, $this->user->id, 0);

        $this->assertSame(1, round_service::count_rounds_played($instance, $this->user->id));
        $this->assertGreaterThan(time(), round_service::compute_cooldown_until($instance, $this->user->id));
    }

    /**
     * ensure_round_state() fires round_started exactly once when a fresh puzzle is built.
     *
     * @covers \mod_playercross\local\round_service::ensure_round_state
     * @return void
     */
    public function test_ensure_round_state_fires_round_started_event(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_clues' => 3, 'theme_min_length' => 6]);
        $sink = $this->redirectEvents();

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof round_started));
        $this->assertCount(1, $events);
        $this->assertSame($state['themewordid'], $events[0]->objectid);
        $this->assertSame(3, $events[0]->other['cluestotal']);
    }

    /**
     * Winning a round by resolving every clue fires round_completed exactly once, with
     * the outcome recorded in its "other" payload.
     *
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @return void
     */
    public function test_resolving_all_clues_fires_round_completed_event(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_clues' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        $sink = $this->redirectEvents();
        foreach ($state['clues'] as $clue) {
            [$state] = round_service::submit_clue_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$clue['wordid'],
                $clue['word']
            );
        }

        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof round_completed));
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->other['completed']);
        $this->assertFalse($events[0]->other['finalguessed']);
        $this->assertSame(3, $events[0]->other['cluesresolved']);
        $this->assertSame(3, $events[0]->other['cluestotal']);
    }
}
