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
     * load_state() discards a round left over from an older, structurally
     * incompatible puzzle_builder version — a clue whose slots array is too short for
     * its own word (the old distinct-set-of-theme-slots shape, before slots became a
     * round-wide, per-position map, SCOPE.md §20.2 v1.7) — instead of returning it
     * as-is and letting round_presenter fatal on it the next time it is rendered.
     *
     * @covers \mod_playercross\local\round_service::load_state
     * @return void
     */
    public function test_load_state_discards_structurally_stale_state(): void {
        global $SESSION;

        $cmid = 42;
        $sessionkey = gameplay_service::build_session_key($cmid, $this->user->id);
        $SESSION->mod_playercross = [
            $sessionkey => [
                'themewordid'      => 1,
                'themeword'        => 'escola',
                'themeslots'       => [1, 2, 3, 4, 5, 6],
                'slotcount'        => 6,
                'revealedslots'    => [],
                'clues'            => [
                    [
                        'wordid'       => 2,
                        'word'         => 'livro',
                        'hint'         => 'dica',
                        // Old shape: a distinct set of theme slots, too short for
                        // "livro"'s 5 characters.
                        'slots'        => [4, 5],
                        'resolved'     => false,
                        'attemptsused' => 0,
                        'exhausted'    => false,
                    ],
                ],
                'cluestotal'       => 1,
                'cluesresolved'    => 0,
                'scoreaccumulated' => 0.0,
                'attemptsused'     => 0,
                'starttime'        => 0,
                'endtime'          => 0,
                'roundstarted'     => false,
                'finished'         => false,
                'won'              => false,
                'forfeited'        => false,
                'timedout'         => false,
                'finalguessed'     => false,
            ],
        ];

        $state = round_service::load_state($cmid, $this->user->id);

        $this->assertSame(0, $state['themewordid']);
        $this->assertSame([], $state['clues']);
    }

    /**
     * load_state() also discards a round left over from just before themehint/
     * originalword existed (added for the post-round reveal to keep its accented
     * spelling — see puzzle_builder::build_round()): themewords and every clue's slots
     * are already the current, correctly-sized shape, but themehint and originalword
     * are simply absent, the way a session saved by the previous code version would be.
     *
     * @covers \mod_playercross\local\round_service::load_state
     * @return void
     */
    public function test_load_state_discards_state_missing_reveal_spelling_fields(): void {
        global $SESSION;

        $cmid = 43;
        $sessionkey = gameplay_service::build_session_key($cmid, $this->user->id);
        $SESSION->mod_playercross = [
            $sessionkey => [
                'themewordid'      => 1,
                'themeconcept'     => 'Escola',
                'themewords'       => ['escola'],
                // Themehint intentionally absent — the pre-upgrade shape.
                'themeslots'       => [1, 2, 3, 4, 5, 6],
                'slotcount'        => 6,
                'revealedslots'    => [],
                'clues'            => [
                    [
                        'wordid'       => 2,
                        'word'         => 'livro',
                        // Originalword intentionally absent — the pre-upgrade shape.
                        'hint'         => 'dica',
                        'slots'        => [1, 2, 3, 4, 5],
                        'resolved'     => false,
                        'attemptsused' => 0,
                        'exhausted'    => false,
                    ],
                ],
                'cluestotal'       => 1,
                'cluesresolved'    => 0,
                'scoreaccumulated' => 0.0,
                'attemptsused'     => 0,
                'starttime'        => 0,
                'endtime'          => 0,
                'roundstarted'     => false,
                'finished'         => false,
                'won'              => false,
                'forfeited'        => false,
                'timedout'         => false,
                'finalguessed'     => false,
            ],
        ];

        $state = round_service::load_state($cmid, $this->user->id);

        $this->assertSame(0, $state['themewordid']);
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
     * reveal_hint() counts each successful reveal in hintsused, and once
     * max_hints_per_round is reached, further calls are rejected with a warning
     * instead of revealing another letter or incrementing the counter further.
     *
     * @covers \mod_playercross\local\round_service::reveal_hint
     * @return void
     */
    public function test_reveal_hint_stops_at_configured_limit(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_clues' => 3,
            'theme_min_length' => 6,
            'max_hints_per_round' => 2,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        [$state] = round_service::reveal_hint($state, $instance, $cm->cmid, $this->user->id);
        $this->assertSame(1, $state['hintsused']);

        [$state] = round_service::reveal_hint($state, $instance, $cm->cmid, $this->user->id);
        $this->assertSame(2, $state['hintsused']);
        $revealedslotsatlimit = $state['revealedslots'];

        [$state, $notification, $notificationtype, $toast] = round_service::reveal_hint(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id
        );

        $this->assertSame(2, $state['hintsused']);
        $this->assertSame($revealedslotsatlimit, $state['revealedslots']);
        $this->assertSame(get_string('hintlimitreached', 'mod_playercross'), $notification);
        $this->assertSame('warning', $notificationtype);
        $this->assertTrue($toast);
    }

    /**
     * Revealing every slot in the round via hints alone — never a single typed guess
     * through either the phrase's own form or the sole clue's — still finishes and
     * wins the round. A deterministic two-word pool (theme "escola", sole clue
     * "livro", sharing "l" and "o" — see tests/external/reveal_hint_test.php's class
     * docblock for the exact slot numbering this relies on) makes exactly 5 reveal_hint
     * calls exhaust every hidden slot: the theme's own two shared slots first, so the
     * phrase itself (confirm_fully_revealed_theme()) is already fully known by the 2nd
     * call, then livro's three exclusive ones, resolving it
     * (resolve_fully_revealed_clues()) and — since both PLAYERCROSS_WINCONDITION_BOTH
     * conditions are then met — finishing the round on the 5th.
     *
     * @covers \mod_playercross\local\round_service::reveal_hint
     * @return void
     */
    public function test_reveal_hint_alone_can_finish_and_win_the_round(): void {
        $cm = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_clues' => 1,
            'theme_min_length' => 6,
            'min_length' => 3,
            'max_length' => 15,
        ]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);
        $this->modgenerator->create_word($instance->id, 'escola');
        $this->modgenerator->create_word($instance->id, 'livro', 'dica');

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        $notification = null;
        for ($i = 0; $i < 5; $i++) {
            [$state, $notification] = round_service::reveal_hint($state, $instance, $cm->cmid, $this->user->id);
        }

        $this->assertTrue($state['finished']);
        $this->assertTrue($state['won']);
        $this->assertTrue($state['finalguesscorrect']);
        $this->assertTrue($state['clues'][0]['resolved']);
        $this->assertSame(get_string('roundwon', 'mod_playercross'), $notification);
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

        [$state, $resolved, $notification, $notificationtype] = round_service::submit_clue_guess(
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
        $this->assertSame(get_string('clueguesswrong', 'mod_playercross'), $notification);
        $this->assertSame('warning', $notificationtype);
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

        [$state, $resolved, , $notificationtype, $toast] = round_service::submit_clue_guess(
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
        $this->assertSame('success', $notificationtype);
        // Every round-flow message is toast-worthy, this mid-round clue included — see
        // round_service::submit_clue_guess().
        $this->assertTrue($toast);
        foreach ($clue['slots'] as $slot) {
            $this->assertContains($slot, $state['revealedslots']);
        }
    }

    /**
     * Resolving every clue alone does not finish the round while the mystery phrase has
     * not been guessed yet — winning always requires both conditions.
     *
     * num_clues is 2 here, not the usual 3 (see make_ready_instance()): with all three
     * of casa/lobo/mel selected, their combined coverage always happens to reach every
     * theme letter (SCOPE.md-level coincidence of this fixed word pool), which would
     * make reconcile_after_reveal() confirm the phrase — and finish the round — as a
     * side effect of the last clue, defeating the very thing this test means to check.
     * reveal_uncovered_slots is also disabled so the one theme letter neither of the
     * two selected clues covers is not simply given away for free at round start.
     *
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @return void
     */
    public function test_resolving_all_clues_alone_does_not_finish_round(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_clues' => 2,
            'theme_min_length' => 6,
            'reveal_uncovered_slots' => 0,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        $lasttoast = null;
        foreach ($state['clues'] as $clue) {
            [$state, , , , $lasttoast] = round_service::submit_clue_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$clue['wordid'],
                $clue['word']
            );
        }

        $this->assertSame(2, $state['cluesresolved']);
        $this->assertFalse($state['finished']);
        // The last clue resolves them all, triggering cluescompleteneedsfinal instead of the
        // ordinary per-clue message — still toast-worthy like every other round-flow message
        // (see test_submit_clue_guess_correct_resolves_and_reveals_slots).
        $this->assertTrue($lasttoast);
    }

    /**
     * A correct direct guess of the mystery phrase alone does not finish the round while
     * clues are still pending — winning always requires both conditions. The correct
     * guess is still recorded (finalguesscorrect), and every mystery-phrase tile is
     * revealed immediately even though the round stays open — the player just
     * demonstrated they know the whole phrase, so the grid must reflect that right away
     * rather than only once every clue is also solved.
     *
     * @covers \mod_playercross\local\round_service::submit_final_guess
     * @return void
     */
    public function test_submit_final_guess_correct_alone_does_not_finish_round(): void {
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
            implode(' ', $state['themewords'])
        );

        $this->assertTrue($correct);
        $this->assertFalse($state['finished']);
        $this->assertTrue($state['finalguesscorrect']);
        foreach ($state['themeslots'] as $slot) {
            $this->assertContains($slot, $state['revealedslots']);
        }
    }

    /**
     * A clue made entirely of letters shared with the mystery phrase (here "casa",
     * every letter of which also appears in the theme "escola") ends up with every
     * tile revealed the instant a correct final guess reveals the whole phrase — see
     * test_submit_final_guess_correct_alone_does_not_finish_round(). Without
     * round_service::resolve_fully_revealed_clues(), that clue's own resolved flag
     * would stay false with no editable box left to ever set it: every tile locked,
     * nothing left to type. This asserts it is auto-resolved instead, so the round can
     * still finish once every other clue is solved too.
     *
     * @covers \mod_playercross\local\round_service::submit_final_guess
     * @return void
     */
    public function test_final_guess_auto_resolves_a_clue_made_entirely_of_shared_letters(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_clues' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        $casaindex = null;
        foreach ($state['clues'] as $index => $clue) {
            if ($clue['word'] === 'casa') {
                $casaindex = $index;
            }
        }
        $this->assertNotNull($casaindex, 'Fixture assumption: casa must be selected as a clue.');

        [$state] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            implode(' ', $state['themewords'])
        );

        $this->assertTrue($state['clues'][$casaindex]['resolved']);
        $this->assertSame(1, $state['cluesresolved']);
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
     * Resolving every clue first, then guessing the mystery phrase, finishes and wins
     * the round and writes the attempts row.
     *
     * num_clues/reveal_uncovered_slots overridden the same way and for the same reason
     * as test_resolving_all_clues_alone_does_not_finish_round(): otherwise the clue
     * loop below would already finish the round by itself (every theme letter
     * incidentally covered), leaving nothing left for the submit_final_guess() call
     * this test actually means to exercise.
     *
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @covers \mod_playercross\local\round_service::submit_final_guess
     * @return void
     */
    public function test_clues_then_final_guess_finishes_and_wins_round(): void {
        global $DB;
        [$instance, $cm] = $this->make_ready_instance([
            'num_clues' => 2,
            'theme_min_length' => 6,
            'reveal_uncovered_slots' => 0,
        ]);
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
        $this->assertFalse($state['finished']);

        [$state, $correct] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            implode(' ', $state['themewords'])
        );

        $this->assertTrue($correct);
        $this->assertTrue($state['finished']);
        $this->assertTrue($state['won']);
        $this->assertTrue($state['finalguessed']);

        $attempt = $DB->get_record('playercross_attempts', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame(2, (int)$attempt->cluesresolved);
        $this->assertSame(1, (int)$attempt->completed);
    }

    /**
     * Guessing the mystery phrase first, then resolving every remaining clue, finishes
     * and wins the round, recording the earlier correct guess as finalguessed.
     *
     * @covers \mod_playercross\local\round_service::submit_final_guess
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @return void
     */
    public function test_final_guess_then_clues_finishes_and_wins_round(): void {
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
            implode(' ', $state['themewords'])
        );
        $this->assertTrue($correct);
        $this->assertFalse($state['finished']);

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
        $this->assertTrue($state['finalguessed']);
    }

    /**
     * PLAYERCROSS_WINCONDITION_BOTH (the default): a clue running out of attempts
     * makes winning mathematically impossible from then on, so the round ends
     * immediately as a loss instead of being left open with no way forward.
     *
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @return void
     */
    public function test_clue_exhaustion_ends_round_as_loss_under_both(): void {
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
        $this->assertFalse($state['finished']);

        [$state, $resolved, $notification] = round_service::submit_clue_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            $clueid,
            'erradodois'
        );

        $this->assertFalse($resolved);
        $this->assertTrue($state['clues'][0]['exhausted']);
        $this->assertFalse($state['clues'][0]['resolved']);
        $this->assertTrue($state['finished']);
        $this->assertFalse($state['won']);
        $this->assertTrue($state['cluesexhausted']);
        $this->assertSame(get_string('feedback_cluesexhausted', 'mod_playercross'), $notification);
    }

    /**
     * PLAYERCROSS_WINCONDITION_FINALONLY: a clue running out of attempts never ends
     * the round by itself — the mystery phrase alone can still win it.
     *
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @return void
     */
    public function test_clue_exhaustion_does_not_end_round_under_finalonly(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_clues' => 3,
            'theme_min_length' => 6,
            'max_attempts_per_clue' => 2,
            'win_condition' => PLAYERCROSS_WINCONDITION_FINALONLY,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        $clueid = (int)$state['clues'][0]['wordid'];

        [$state] = round_service::submit_clue_guess($state, $instance, $cm->cmid, $this->user->id, $clueid, 'erradoum');
        [$state] = round_service::submit_clue_guess($state, $instance, $cm->cmid, $this->user->id, $clueid, 'erradodois');

        $this->assertTrue($state['clues'][0]['exhausted']);
        $this->assertFalse($state['clues'][0]['resolved']);
        $this->assertFalse($state['finished']);
    }

    /**
     * PLAYERCROSS_WINCONDITION_FINALONLY: resolving every clue never finishes the
     * round on its own — only a direct guess of the mystery phrase does.
     *
     * num_clues/reveal_uncovered_slots overridden the same way and for the same reason
     * as test_resolving_all_clues_alone_does_not_finish_round(): otherwise resolving
     * every clue would incidentally reveal the whole phrase too, and under
     * PLAYERCROSS_WINCONDITION_FINALONLY that alone is enough to finish the round —
     * exactly the outcome this test means to rule out.
     *
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @return void
     */
    public function test_finalonly_resolving_all_clues_does_not_finish_round(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_clues' => 2,
            'theme_min_length' => 6,
            'reveal_uncovered_slots' => 0,
            'win_condition' => PLAYERCROSS_WINCONDITION_FINALONLY,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        $lasttoast = null;
        foreach ($state['clues'] as $clue) {
            [$state, , , , $lasttoast] = round_service::submit_clue_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$clue['wordid'],
                $clue['word']
            );
        }

        $this->assertSame(2, $state['cluesresolved']);
        $this->assertFalse($state['finished']);
        // The last clue resolves them all, triggering cluescompleteneedsfinal instead of the
        // ordinary per-clue message — still toast-worthy like every other round-flow message
        // (see test_submit_clue_guess_correct_resolves_and_reveals_slots).
        $this->assertTrue($lasttoast);
    }

    /**
     * PLAYERCROSS_WINCONDITION_FINALONLY: a correct direct guess wins the round
     * immediately, even with every clue still pending.
     *
     * @covers \mod_playercross\local\round_service::submit_final_guess
     * @return void
     */
    public function test_finalonly_final_guess_wins_round_immediately(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_clues' => 3,
            'theme_min_length' => 6,
            'win_condition' => PLAYERCROSS_WINCONDITION_FINALONLY,
        ]);
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
            implode(' ', $state['themewords'])
        );

        $this->assertTrue($correct);
        $this->assertTrue($state['finished']);
        $this->assertTrue($state['won']);
        $this->assertTrue($state['finalguessed']);
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
     * Winning a round — resolving every clue, then guessing the mystery phrase — fires
     * round_completed exactly once, with the outcome recorded in its "other" payload.
     *
     * @covers \mod_playercross\local\round_service::submit_clue_guess
     * @covers \mod_playercross\local\round_service::submit_final_guess
     * @return void
     */
    public function test_winning_the_round_fires_round_completed_event(): void {
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
        [$state] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            implode(' ', $state['themewords'])
        );

        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof round_completed));
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->other['completed']);
        $this->assertTrue($events[0]->other['finalguessed']);
        $this->assertSame(3, $events[0]->other['cluesresolved']);
        $this->assertSame(3, $events[0]->other['cluestotal']);
    }

    /**
     * Tests that the round-count restriction is enforced once max_rounds is reached.
     *
     * @covers \mod_playercross\local\round_service::get_round_restriction_notice
     * @return void
     */
    public function test_restriction_notice_max_rounds_reached(): void {
        [$instance] = $this->make_ready_instance(['max_rounds' => 1, 'cooldown_amount' => 0]);
        $this->modgenerator->create_attempt($instance->id, $this->user->id, 0);

        $this->assertNotNull(round_service::get_round_restriction_notice($instance, $this->user->id));
    }

    /**
     * Tests that a still-active cooldown is also reported via the same restriction
     * notice, not just the max_rounds branch.
     *
     * @covers \mod_playercross\local\round_service::get_round_restriction_notice
     * @return void
     */
    public function test_restriction_notice_cooldown_active(): void {
        [$instance] = $this->make_ready_instance(['max_rounds' => 0, 'cooldown_amount' => 1, 'cooldown_unit' => 'days']);
        $this->modgenerator->create_attempt($instance->id, $this->user->id, 0);

        $this->assertNotNull(round_service::get_round_restriction_notice($instance, $this->user->id));
    }

    /**
     * Tests that no restriction applies when limits are disabled and no attempts exist.
     *
     * @covers \mod_playercross\local\round_service::get_round_restriction_notice
     * @return void
     */
    public function test_restriction_notice_none_when_unrestricted(): void {
        [$instance] = $this->make_ready_instance(['max_rounds' => 0, 'cooldown_amount' => 0]);

        $this->assertNull(round_service::get_round_restriction_notice($instance, $this->user->id));
    }

    /**
     * Tests that no cooldown applies when the setting is disabled, even with a recent
     * attempt.
     *
     * @covers \mod_playercross\local\round_service::compute_cooldown_until
     * @return void
     */
    public function test_compute_cooldown_until_disabled(): void {
        [$instance] = $this->make_ready_instance(['cooldown_amount' => 0]);
        $this->modgenerator->create_attempt($instance->id, $this->user->id, 0);

        $this->assertSame(0, round_service::compute_cooldown_until($instance, $this->user->id));
    }

    /**
     * Tests that a cooldown already expired by elapsed time returns 0.
     *
     * @covers \mod_playercross\local\round_service::compute_cooldown_until
     * @return void
     */
    public function test_compute_cooldown_until_expired_by_time(): void {
        [$instance] = $this->make_ready_instance(['cooldown_amount' => 1, 'cooldown_unit' => 'minutes']);
        $this->modgenerator->create_attempt($instance->id, $this->user->id, 0, [
            'timecreated' => time() - 120,
        ]);

        $this->assertSame(0, round_service::compute_cooldown_until($instance, $this->user->id));
    }

    /**
     * Tests that changing cooldown_seconds after an attempt already happened takes
     * effect immediately on the next call — never cached from the moment the round
     * finished, the same way mod_quiz's inter-attempt delay always uses its current
     * setting.
     *
     * @covers \mod_playercross\local\round_service::compute_cooldown_until
     * @return void
     */
    public function test_compute_cooldown_until_reflects_a_later_settings_change(): void {
        global $DB;
        [$instance] = $this->make_ready_instance(['cooldown_amount' => 1, 'cooldown_unit' => 'days']);
        $this->modgenerator->create_attempt($instance->id, $this->user->id, 0);

        $this->assertGreaterThan(time() + 3600, round_service::compute_cooldown_until($instance, $this->user->id));

        // The teacher disables the cooldown entirely.
        $DB->set_field('playercross', 'cooldown_seconds', 0, ['id' => $instance->id]);
        $instance = $DB->get_record('playercross', ['id' => $instance->id], '*', MUST_EXIST);

        $this->assertSame(0, round_service::compute_cooldown_until($instance, $this->user->id));
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
     * @return int Block instance id.
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
     * @param int $blockinstanceid Block instance id.
     * @param int $xp XP awarded per unit collected, 0 for none.
     * @return int Item id.
     */
    private function make_item(int $blockinstanceid, int $xp = 0): int {
        global $DB;
        return $DB->insert_record('block_playerhud_items', (object)[
            'blockinstanceid' => $blockinstanceid,
            'name'            => 'Gold Key',
            'xp'              => $xp,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    /**
     * Winning a round with a bounded max_rounds grants the configured PlayerHUD item
     * together with its XP — a finite round limit is the same "bounded source" case
     * block_playerhud itself allows XP for on its own drops.
     *
     * @covers \mod_playercross\local\round_service::finish_round
     * @return void
     */
    public function test_win_grants_item_with_xp_when_bounded(): void {
        global $DB;
        $this->skip_if_no_playerhud();

        $biid = $this->make_block_instance($this->course);
        $itemid = $this->make_item($biid, 30);
        [$instance, $cm] = $this->make_ready_instance([
            'num_clues' => 3,
            'theme_min_length' => 6,
            'max_rounds' => 5,
            'win_condition' => PLAYERCROSS_WINCONDITION_FINALONLY,
            'hud_win_reward_item' => $itemid,
            'hud_win_reward_qty' => 2,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        round_service::submit_final_guess($state, $instance, $cm->cmid, $this->user->id, implode(' ', $state['themewords']));

        $this->assertSame(2, $DB->count_records('block_playerhud_inventory', [
            'userid' => $this->user->id,
            'itemid' => $itemid,
        ]));
        $currentxp = $DB->get_field('block_playerhud_user', 'currentxp', [
            'blockinstanceid' => $biid,
            'userid'          => $this->user->id,
        ]);
        $this->assertSame(60, (int)$currentxp);
    }

    /**
     * Winning a round on an activity with Unlimited rounds still grants the item, but
     * withholds its XP — the anti-farming safeguard needed to match PlayerHUD's own
     * "infinite drop gives no XP" rule.
     *
     * @covers \mod_playercross\local\round_service::finish_round
     * @return void
     */
    public function test_win_grants_item_without_xp_when_unlimited(): void {
        global $DB;
        $this->skip_if_no_playerhud();

        $biid = $this->make_block_instance($this->course);
        $itemid = $this->make_item($biid, 30);
        // The max_rounds override is omitted — make_ready_instance() defaults it to 0 (unlimited).
        [$instance, $cm] = $this->make_ready_instance([
            'num_clues' => 3,
            'theme_min_length' => 6,
            'win_condition' => PLAYERCROSS_WINCONDITION_FINALONLY,
            'hud_win_reward_item' => $itemid,
            'hud_win_reward_qty' => 2,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        round_service::submit_final_guess($state, $instance, $cm->cmid, $this->user->id, implode(' ', $state['themewords']));

        $this->assertSame(2, $DB->count_records('block_playerhud_inventory', [
            'userid' => $this->user->id,
            'itemid' => $itemid,
        ]));
        $currentxp = $DB->get_field('block_playerhud_user', 'currentxp', [
            'blockinstanceid' => $biid,
            'userid'          => $this->user->id,
        ]);
        $this->assertSame(0, (int)$currentxp);
    }

    /**
     * Tests that forfeiting a round never grants the win item, regardless of
     * configuration — the reward is exclusive to a genuine win.
     *
     * @covers \mod_playercross\local\round_service::finish_round
     * @return void
     */
    public function test_forfeit_does_not_grant_item(): void {
        global $DB;
        $this->skip_if_no_playerhud();

        $biid = $this->make_block_instance($this->course);
        $itemid = $this->make_item($biid, 30);
        [$instance, $cm] = $this->make_ready_instance([
            'hud_win_reward_item' => $itemid,
            'hud_win_reward_qty' => 2,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        round_service::forfeit($state, $instance, $cm->cmid, $this->user->id);

        $this->assertSame(0, $DB->count_records('block_playerhud_inventory', ['userid' => $this->user->id]));
    }

    /**
     * A round cost pointing at a PlayerHUD item that no longer exists is waived
     * instead of blocking the student forever — a deleted item can never be
     * restocked, so charging for it would be a permanent lockout. Mirrors
     * round_presenter::build_hud_cost_info(), which already hides the cost badge in
     * this same case.
     *
     * @covers \mod_playercross\local\round_service::start_round
     * @return void
     */
    public function test_start_round_waives_cost_when_item_deleted(): void {
        $this->skip_if_no_playerhud();

        [$instance, $cm] = $this->make_ready_instance([
            'hud_round_cost_item' => 999999,
            'hud_round_cost_qty' => 1,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        [$state, $notification] = round_service::start_round($state, $instance, $this->user->id);

        $this->assertNull($notification);
        $this->assertTrue($state['roundstarted']);
    }

    /**
     * A round cost pointing at a PlayerHUD item belonging to a different course's
     * block instance is waived, the same as a deleted item — the cross-course leak
     * this scoping rule exists to prevent (block_playerhud_items.id is a single
     * site-wide sequence, so a stale or misconfigured id could otherwise silently
     * charge against another course's economy). This course has its own PlayerHUD
     * block instance too, proving the rejection is about this specific item's
     * ownership, not merely "no PlayerHUD available in this course".
     *
     * @covers \mod_playercross\local\round_service::start_round
     * @return void
     */
    public function test_start_round_waives_cost_when_item_belongs_to_other_course(): void {
        $this->skip_if_no_playerhud();

        $this->make_block_instance($this->course);
        $othercourse = $this->getDataGenerator()->create_course();
        $otherbiid = $this->make_block_instance($othercourse);
        $itemid = $this->make_item($otherbiid);

        [$instance, $cm] = $this->make_ready_instance([
            'hud_round_cost_item' => $itemid,
            'hud_round_cost_qty' => 1,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        [$state, $notification] = round_service::start_round($state, $instance, $this->user->id);

        $this->assertNull($notification);
        $this->assertTrue($state['roundstarted']);
    }

    /**
     * A hint cost pointing at a PlayerHUD item that no longer exists is waived, same
     * rationale as test_start_round_waives_cost_when_item_deleted().
     *
     * @covers \mod_playercross\local\round_service::reveal_hint
     * @return void
     */
    public function test_reveal_hint_waives_cost_when_item_deleted(): void {
        $this->skip_if_no_playerhud();

        [$instance, $cm] = $this->make_ready_instance([
            'num_clues' => 3,
            'theme_min_length' => 6,
            'hud_hint_cost_item' => 999999,
            'hud_hint_cost_qty' => 1,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        $revealedbefore = count($state['revealedslots']);

        [$state, , $notificationtype, $toast] = round_service::reveal_hint($state, $instance, $cm->cmid, $this->user->id);

        $this->assertSame('success', $notificationtype);
        $this->assertTrue($toast);
        $this->assertGreaterThan($revealedbefore, count($state['revealedslots']));
    }

    /**
     * A round cost pointing at a disabled (not deleted) item still blocks the
     * student when their balance is short. Disabling is reversible, so the cost is
     * deliberately not waived here — only a deleted item (permanently unobtainable)
     * gets that treatment.
     *
     * @covers \mod_playercross\local\round_service::start_round
     * @return void
     */
    public function test_start_round_still_blocks_when_item_disabled_and_insufficient(): void {
        global $DB;
        $this->skip_if_no_playerhud();

        $biid = $this->make_block_instance($this->course);
        $itemid = $this->make_item($biid);
        $DB->set_field('block_playerhud_items', 'enabled', 0, ['id' => $itemid]);

        [$instance, $cm] = $this->make_ready_instance([
            'hud_round_cost_item' => $itemid,
            'hud_round_cost_qty' => 1,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        [$state, $notification] = round_service::start_round($state, $instance, $this->user->id);

        $this->assertNotNull($notification);
        $this->assertFalse($state['roundstarted']);
    }
}
