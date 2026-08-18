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
 *
 * @covers \mod_playercross\local\round_service
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
     * @return void
     */
    public function test_load_state_returns_defaults(): void {
        $state = round_service::load_state(1, $this->user->id);
        $this->assertSame(0, $state['themewordid']);
        $this->assertFalse($state['finished']);
        $this->assertSame([], $state['terms']);
    }

    /**
     * load_state() discards a round left over from an older, structurally
     * incompatible puzzle_builder version — a term whose slots array is too short for
     * its own word (the old distinct-set-of-theme-slots shape, before slots became a
     * round-wide, per-position map, SCOPE.md §20.2 v1.7) — instead of returning it
     * as-is and letting round_presenter fatal on it the next time it is rendered.
     *
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
                'terms'            => [
                    [
                        'wordid'       => 2,
                        'word'         => 'livro',
                        'clue'         => 'dica',
                        // Old shape: a distinct set of theme slots, too short for
                        // "livro"'s 5 characters.
                        'slots'        => [4, 5],
                        'resolved'     => false,
                        'attemptsused' => 0,
                        'exhausted'    => false,
                    ],
                ],
                'termstotal'       => 1,
                'termsresolved'    => 0,
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
        $this->assertSame([], $state['terms']);
    }

    /**
     * load_state() also discards a round left over from just before themehint/
     * originalword existed (added for the post-round reveal to keep its accented
     * spelling — see puzzle_builder::build_round()): themewords and every term's slots
     * are already the current, correctly-sized shape, but themehint and originalword
     * are simply absent, the way a session saved by the previous code version would be.
     *
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
                'terms'            => [
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
                'termstotal'       => 1,
                'termsresolved'    => 0,
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
        $this->assertSame([], $state['terms']);
    }

    /**
     * ensure_round_state() builds a real puzzle from the approved pool.
     *
     * @return void
     */
    public function test_ensure_round_state_builds_puzzle(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);

        $state = round_service::load_state($cm->cmid, $this->user->id);
        $state = round_service::ensure_round_state($state, $instance, $cm->cmid, $this->user->id);

        $this->assertGreaterThan(0, $state['themewordid']);
        $this->assertSame(3, $state['termstotal']);
        $this->assertCount(3, $state['terms']);
    }

    /**
     * Regression test for the round-cost bypass: a client that calls
     * submit_term_guess() before start_round() — skipping the "Iniciar rodada" button,
     * the only place a configured PlayerHUD round cost is actually charged — must be
     * rejected, even with a correct guess for a term already sitting in session. The
     * round stays unfinished and no attempt is counted, so a repeat with start_round()
     * first still works normally.
     *
     * @return void
     */
    public function test_submit_term_guess_rejected_when_round_not_started(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        $this->assertFalse($state['roundstarted']);
        $term = $state['terms'][0];

        [$state, $resolved, $notification, $notificationtype] = round_service::submit_term_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            (int)$term['wordid'],
            $term['word']
        );

        $this->assertFalse($resolved);
        $this->assertNotEmpty($notification);
        $this->assertSame('warning', $notificationtype);
        $this->assertFalse($state['finished']);
        $this->assertFalse($state['terms'][0]['resolved']);
        $this->assertSame(0, $state['attemptsused']);
    }

    /**
     * Regression test for the round-cost bypass: same as
     * test_submit_term_guess_rejected_when_round_not_started(), for a direct guess of
     * the mystery phrase.
     *
     * @return void
     */
    public function test_submit_final_guess_rejected_when_round_not_started(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        $this->assertFalse($state['roundstarted']);

        [$state, $correct, $notification, $notificationtype] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            implode(' ', $state['themewords'])
        );

        $this->assertFalse($correct);
        $this->assertNotEmpty($notification);
        $this->assertSame('warning', $notificationtype);
        $this->assertFalse($state['finished']);
        $this->assertFalse($state['finalguesscorrect']);
    }

    /**
     * Regression test ported from mod_playerwords: reveal_hint() is rejected once the
     * round is already finished, instead of silently revealing further tiles for a
     * round that no longer counts.
     *
     * @return void
     */
    public function test_reveal_hint_rejected_when_already_finished(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        [$state] = round_service::forfeit($state, $instance, $cm->cmid, $this->user->id);
        $this->assertTrue($state['finished']);
        $revealedbefore = $state['revealedslots'];

        [$state, $notification] = round_service::reveal_hint($state, $instance, $cm->cmid, $this->user->id);

        $this->assertNotEmpty($notification);
        $this->assertSame($revealedbefore, $state['revealedslots']);
    }

    /**
     * Regression test for the round-cost bypass: same as
     * test_submit_term_guess_rejected_when_round_not_started(), for revealing a hint —
     * which has its own configurable PlayerHUD cost, equally skippable before
     * start_round() without this guard.
     *
     * @return void
     */
    public function test_reveal_hint_rejected_when_round_not_started(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        $this->assertFalse($state['roundstarted']);
        $revealedbefore = $state['revealedslots'];

        [$state, $notification, $notificationtype] = round_service::reveal_hint(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id
        );

        $this->assertNotEmpty($notification);
        $this->assertSame('warning', $notificationtype);
        $this->assertSame($revealedbefore, $state['revealedslots']);
        $this->assertSame(0, $state['hintsused']);
    }

    /**
     * reveal_hint() counts each successful reveal in hintsused, and once
     * max_hints_per_round is reached, further calls are rejected with a warning
     * instead of revealing another letter or incrementing the counter further.
     *
     * @return void
     */
    public function test_reveal_hint_stops_at_configured_limit(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'max_hints_per_round' => 2,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

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
     * through either the phrase's own form or the sole term's — still finishes and
     * wins the round. A deterministic two-word pool (theme "escola", sole term
     * "livro", sharing "l" and "o" — see tests/external/reveal_hint_test.php's class
     * docblock for the exact slot numbering this relies on) makes exactly 5 reveal_hint
     * calls exhaust every hidden slot: the theme's own two shared slots first, so the
     * phrase itself (confirm_fully_revealed_theme()) is already fully known by the 2nd
     * call, then livro's three exclusive ones, resolving it
     * (resolve_fully_revealed_terms()) and — since both PLAYERCROSS_WINCONDITION_BOTH
     * conditions are then met — finishing the round on the 5th.
     *
     * @return void
     */
    public function test_reveal_hint_alone_can_finish_and_win_the_round(): void {
        $cm = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_terms' => 1,
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
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        $notification = null;
        for ($i = 0; $i < 5; $i++) {
            [$state, $notification] = round_service::reveal_hint($state, $instance, $cm->cmid, $this->user->id);
        }

        $this->assertTrue($state['finished']);
        $this->assertTrue($state['won']);
        $this->assertTrue($state['finalguesscorrect']);
        $this->assertTrue($state['terms'][0]['resolved']);
        $this->assertSame(get_string('roundwon', 'mod_playercross'), $notification);
    }

    /**
     * A wrong term guess increments its attempt counter without resolving it or
     * revealing any theme letters.
     *
     * @return void
     */
    public function test_submit_term_guess_wrong_increments_attempts(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $termid = (int)$state['terms'][0]['wordid'];
        $revealedbefore = $state['revealedslots'];

        [$state, $resolved, $notification, $notificationtype] = round_service::submit_term_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            $termid,
            'zzzzzzz'
        );

        $this->assertFalse($resolved);
        $this->assertSame(1, $state['terms'][0]['attemptsused']);
        $this->assertFalse($state['terms'][0]['resolved']);
        $this->assertSame($revealedbefore, $state['revealedslots']);
        $this->assertSame(get_string('termguesswrong', 'mod_playercross'), $notification);
        $this->assertSame('warning', $notificationtype);
    }

    /**
     * A correct term guess resolves it and reveals every theme slot it covers.
     *
     * @return void
     */
    public function test_submit_term_guess_correct_resolves_and_reveals_slots(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $term = $state['terms'][0];

        [$state, $resolved, , $notificationtype, $toast] = round_service::submit_term_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            (int)$term['wordid'],
            $term['word']
        );

        $this->assertTrue($resolved);
        $this->assertTrue($state['terms'][0]['resolved']);
        $this->assertSame(1, $state['termsresolved']);
        $this->assertSame('success', $notificationtype);
        // Every round-flow message is toast-worthy, this mid-round term included — see
        // round_service::submit_term_guess().
        $this->assertTrue($toast);
        foreach ($term['slots'] as $slot) {
            $this->assertContains($slot, $state['revealedslots']);
        }
    }

    /**
     * Resolving every term alone does not finish the round while the mystery phrase has
     * not been guessed yet — winning always requires both conditions.
     *
     * num_terms is 2 here, not the usual 3 (see make_ready_instance()): with all three
     * of casa/lobo/mel selected, their combined coverage always happens to reach every
     * theme letter (SCOPE.md-level coincidence of this fixed word pool), which would
     * make reconcile_after_reveal() confirm the phrase — and finish the round — as a
     * side effect of the last term, defeating the very thing this test means to check.
     * reveal_uncovered_slots is also disabled so the one theme letter neither of the
     * two selected terms covers is not simply given away for free at round start.
     *
     * @return void
     */
    public function test_resolving_all_terms_alone_does_not_finish_round(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 2,
            'theme_min_length' => 6,
            'reveal_uncovered_slots' => 0,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        $lasttoast = null;
        foreach ($state['terms'] as $term) {
            [$state, , , , $lasttoast] = round_service::submit_term_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$term['wordid'],
                $term['word']
            );
        }

        $this->assertSame(2, $state['termsresolved']);
        $this->assertFalse($state['finished']);
        // The last term resolves them all, triggering termscompleteneedsfinal instead of the
        // ordinary per-term message — still toast-worthy like every other round-flow message
        // (see test_submit_term_guess_correct_resolves_and_reveals_slots).
        $this->assertTrue($lasttoast);
    }

    /**
     * A correct direct guess of the mystery phrase alone does not finish the round while
     * terms are still pending — winning always requires both conditions. The correct
     * guess is still recorded (finalguesscorrect), and every mystery-phrase tile is
     * revealed immediately even though the round stays open — the player just
     * demonstrated they know the whole phrase, so the grid must reflect that right away
     * rather than only once every term is also solved.
     *
     * @return void
     */
    public function test_submit_final_guess_correct_alone_does_not_finish_round(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

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
     * A term made entirely of letters shared with the mystery phrase (here "casa",
     * every letter of which also appears in the theme "escola") ends up with every
     * tile revealed the instant a correct final guess reveals the whole phrase — see
     * test_submit_final_guess_correct_alone_does_not_finish_round(). Without
     * round_service::resolve_fully_revealed_terms(), that term's own resolved flag
     * would stay false with no editable box left to ever set it: every tile locked,
     * nothing left to type. This asserts it is auto-resolved instead, so the round can
     * still finish once every other term is solved too.
     *
     * @return void
     */
    public function test_final_guess_auto_resolves_a_term_made_entirely_of_shared_letters(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        $casaindex = null;
        foreach ($state['terms'] as $index => $term) {
            if ($term['word'] === 'casa') {
                $casaindex = $index;
            }
        }
        $this->assertNotNull($casaindex, 'Fixture assumption: casa must be selected as a term.');

        [$state] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            implode(' ', $state['themewords'])
        );

        $this->assertTrue($state['terms'][$casaindex]['resolved']);
        $this->assertSame(1, $state['termsresolved']);
    }

    /**
     * A wrong direct guess of the mystery phrase leaves the round open.
     *
     * @return void
     */
    public function test_submit_final_guess_wrong_keeps_round_open(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

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
     * Resolving every term first, then guessing the mystery phrase, finishes and wins
     * the round and writes the attempts row.
     *
     * num_terms/reveal_uncovered_slots overridden the same way and for the same reason
     * as test_resolving_all_terms_alone_does_not_finish_round(): otherwise the term
     * loop below would already finish the round by itself (every theme letter
     * incidentally covered), leaving nothing left for the submit_final_guess() call
     * this test actually means to exercise.
     *
     * @return void
     */
    public function test_terms_then_final_guess_finishes_and_wins_round(): void {
        global $DB;
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 2,
            'theme_min_length' => 6,
            'reveal_uncovered_slots' => 0,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        foreach ($state['terms'] as $term) {
            [$state] = round_service::submit_term_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$term['wordid'],
                $term['word']
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
        $this->assertSame(2, (int)$attempt->termsresolved);
        $this->assertSame(1, (int)$attempt->completed);
    }

    /**
     * Guessing the mystery phrase first, then resolving every remaining term, finishes
     * and wins the round, recording the earlier correct guess as finalguessed.
     *
     * @return void
     */
    public function test_final_guess_then_terms_finishes_and_wins_round(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        [$state, $correct] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            implode(' ', $state['themewords'])
        );
        $this->assertTrue($correct);
        $this->assertFalse($state['finished']);

        foreach ($state['terms'] as $term) {
            [$state] = round_service::submit_term_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$term['wordid'],
                $term['word']
            );
        }

        $this->assertTrue($state['finished']);
        $this->assertTrue($state['won']);
        $this->assertTrue($state['finalguessed']);
    }

    /**
     * PLAYERCROSS_WINCONDITION_BOTH (the default): a term running out of attempts
     * makes winning mathematically impossible from then on, so the round ends
     * immediately as a loss instead of being left open with no way forward.
     *
     * @return void
     */
    public function test_term_exhaustion_ends_round_as_loss_under_both(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'max_attempts_per_term' => 2,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $termid = (int)$state['terms'][0]['wordid'];

        [$state] = round_service::submit_term_guess($state, $instance, $cm->cmid, $this->user->id, $termid, 'erradoum');
        $this->assertFalse($state['terms'][0]['exhausted']);
        $this->assertFalse($state['finished']);

        [$state, $resolved, $notification] = round_service::submit_term_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            $termid,
            'erradodois'
        );

        $this->assertFalse($resolved);
        $this->assertTrue($state['terms'][0]['exhausted']);
        $this->assertFalse($state['terms'][0]['resolved']);
        $this->assertTrue($state['finished']);
        $this->assertFalse($state['won']);
        $this->assertTrue($state['termsexhausted']);
        $this->assertSame(get_string('feedback_termsexhausted', 'mod_playercross'), $notification);
    }

    /**
     * PLAYERCROSS_WINCONDITION_FINALONLY: a term running out of attempts never ends
     * the round by itself — the mystery phrase alone can still win it.
     *
     * @return void
     */
    public function test_term_exhaustion_does_not_end_round_under_finalonly(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'max_attempts_per_term' => 2,
            'win_condition' => PLAYERCROSS_WINCONDITION_FINALONLY,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $termid = (int)$state['terms'][0]['wordid'];

        [$state] = round_service::submit_term_guess($state, $instance, $cm->cmid, $this->user->id, $termid, 'erradoum');
        [$state] = round_service::submit_term_guess($state, $instance, $cm->cmid, $this->user->id, $termid, 'erradodois');

        $this->assertTrue($state['terms'][0]['exhausted']);
        $this->assertFalse($state['terms'][0]['resolved']);
        $this->assertFalse($state['finished']);
    }

    /**
     * A wrong final guess counts against max_attempts_final_guess, but not until it
     * is actually exhausted the round stays open.
     *
     * @return void
     */
    public function test_final_guess_wrong_below_limit_keeps_round_open(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'max_attempts_final_guess' => 2,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        [$state, $correct] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            'totalmenteerrado'
        );

        $this->assertFalse($correct);
        $this->assertFalse($state['finished']);
        $this->assertFalse($state['finalguessexhausted']);
        $this->assertSame(1, $state['finalguessattemptsused']);
    }

    /**
     * Final-guess exhaustion always ends the round as a loss under
     * PLAYERCROSS_WINCONDITION_BOTH — the phrase is unconditionally required to win.
     *
     * @return void
     */
    public function test_final_guess_exhaustion_ends_round_as_loss_under_both(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'max_attempts_final_guess' => 2,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        [$state] = round_service::submit_final_guess($state, $instance, $cm->cmid, $this->user->id, 'errado um');
        [$state, $correct, $notification] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            'errado dois'
        );

        $this->assertFalse($correct);
        $this->assertTrue($state['finalguessexhausted']);
        $this->assertTrue($state['finished']);
        $this->assertFalse($state['won']);
        $this->assertSame(get_string('feedback_finalguessexhausted', 'mod_playercross'), $notification);
    }

    /**
     * Final-guess exhaustion always ends the round as a loss even under
     * PLAYERCROSS_WINCONDITION_FINALONLY — unlike term exhaustion (which never ends
     * the round in this mode, see test_term_exhaustion_does_not_end_round_under_finalonly()),
     * the phrase is exclusively required to win here, so running out of attempts for
     * it makes winning impossible regardless of win_condition.
     *
     * @return void
     */
    public function test_final_guess_exhaustion_ends_round_as_loss_under_finalonly(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'max_attempts_final_guess' => 2,
            'win_condition' => PLAYERCROSS_WINCONDITION_FINALONLY,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        [$state] = round_service::submit_final_guess($state, $instance, $cm->cmid, $this->user->id, 'errado um');
        [$state, $correct] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            'errado dois'
        );

        $this->assertFalse($correct);
        $this->assertTrue($state['finalguessexhausted']);
        $this->assertTrue($state['finished']);
        $this->assertFalse($state['won']);
    }

    /**
     * errorsused (the shared pool feeding the Linear formula) counts only wrong
     * guesses, never the successful resolving one — distinct from attemptsused, which
     * counts every submission regardless of correctness.
     *
     * @return void
     */
    public function test_errorsused_counts_only_wrong_guesses(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $term = $state['terms'][0];

        [$state] = round_service::submit_term_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            (int)$term['wordid'],
            'zzzzzzz'
        );
        $this->assertSame(1, $state['errorsused']);
        $this->assertSame(1, $state['attemptsused']);

        [$state] = round_service::submit_term_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            (int)$term['wordid'],
            $term['word']
        );
        // The correct guess advances attemptsused but not errorsused.
        $this->assertSame(1, $state['errorsused']);
        $this->assertSame(2, $state['attemptsused']);
    }

    /**
     * A round won via a direct final guess before any term is resolved is eligible for
     * the early-guess bonus: the ranking total (uncapped) ends up higher than the
     * grade score (capped at the nominal grade), even under Binary scoring.
     *
     * @return void
     */
    public function test_early_bonus_eligible_when_final_guess_precedes_every_term(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'grade' => 100,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        [$state] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            implode(' ', $state['themewords'])
        );
        $this->assertFalse($state['finished']);

        foreach ($state['terms'] as $term) {
            [$state] = round_service::submit_term_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$term['wordid'],
                $term['word']
            );
        }

        $this->assertTrue($state['finished']);
        $this->assertTrue($state['won']);
        $this->assertEqualsWithDelta(100.0, $state['score'], 0.00001);
        $this->assertEqualsWithDelta(110.0, $state['rankingpoints'], 0.00001);
    }

    /**
     * A round where at least one term is resolved before the final guess is never
     * eligible for the early-guess bonus, even when the final guess is later confirmed
     * correct — score and rankingpoints stay equal.
     *
     * @return void
     */
    public function test_early_bonus_not_eligible_once_a_term_is_resolved_first(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'grade' => 100,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        foreach ($state['terms'] as $term) {
            [$state] = round_service::submit_term_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$term['wordid'],
                $term['word']
            );
        }
        [$state] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            implode(' ', $state['themewords'])
        );

        $this->assertTrue($state['finished']);
        $this->assertTrue($state['won']);
        $this->assertEqualsWithDelta(100.0, $state['score'], 0.00001);
        $this->assertEqualsWithDelta(100.0, $state['rankingpoints'], 0.00001);
    }

    /**
     * finish_round() persists rankingpoints alongside score on the attempts row —
     * they can genuinely diverge (independent scoring modes, uncapped bonus).
     *
     * @return void
     */
    public function test_finish_round_persists_rankingpoints_alongside_score(): void {
        global $DB;
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        [$state] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            implode(' ', $state['themewords'])
        );
        foreach ($state['terms'] as $term) {
            [$state] = round_service::submit_term_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$term['wordid'],
                $term['word']
            );
        }

        $attempt = $DB->get_record('playercross_attempts', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertEqualsWithDelta((float)$state['score'], (float)$attempt->score, 0.00001);
        $this->assertEqualsWithDelta((float)$state['rankingpoints'], (float)$attempt->rankingpoints, 0.00001);
        $this->assertEqualsWithDelta(110.0, (float)$attempt->rankingpoints, 0.00001);
    }

    /**
     * The core regression test for the ranking/grade decoupling fix: an ungraded
     * activity (grade=0, the mod_form default) still persists a real, nonzero
     * rankingpoints value — ranking is scored against its own fixed
     * PLAYERCROSS_RANKING_BASE_POINTS, never against the (here, zero) grade.
     *
     * @return void
     */
    public function test_finish_round_persists_rankingpoints_when_ungraded(): void {
        global $DB;
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'grade' => 0,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        [$state] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            implode(' ', $state['themewords'])
        );
        foreach ($state['terms'] as $term) {
            [$state] = round_service::submit_term_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$term['wordid'],
                $term['word']
            );
        }

        $attempt = $DB->get_record('playercross_attempts', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(0.0, (float)$attempt->score, 0.00001);
        $this->assertEqualsWithDelta(110.0, (float)$attempt->rankingpoints, 0.00001);
    }

    /**
     * PLAYERCROSS_WINCONDITION_FINALONLY: resolving every term never finishes the
     * round on its own — only a direct guess of the mystery phrase does.
     *
     * num_terms/reveal_uncovered_slots overridden the same way and for the same reason
     * as test_resolving_all_terms_alone_does_not_finish_round(): otherwise resolving
     * every term would incidentally reveal the whole phrase too, and under
     * PLAYERCROSS_WINCONDITION_FINALONLY that alone is enough to finish the round —
     * exactly the outcome this test means to rule out.
     *
     * @return void
     */
    public function test_finalonly_resolving_all_terms_does_not_finish_round(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 2,
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
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        $lasttoast = null;
        foreach ($state['terms'] as $term) {
            [$state, , , , $lasttoast] = round_service::submit_term_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$term['wordid'],
                $term['word']
            );
        }

        $this->assertSame(2, $state['termsresolved']);
        $this->assertFalse($state['finished']);
        // The last term resolves them all, triggering termscompleteneedsfinal instead of the
        // ordinary per-term message — still toast-worthy like every other round-flow message
        // (see test_submit_term_guess_correct_resolves_and_reveals_slots).
        $this->assertTrue($lasttoast);
    }

    /**
     * PLAYERCROSS_WINCONDITION_FINALONLY: a correct direct guess wins the round
     * immediately, even with every term still pending.
     *
     * @return void
     */
    public function test_finalonly_final_guess_wins_round_immediately(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'win_condition' => PLAYERCROSS_WINCONDITION_FINALONLY,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

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
     * Forfeiting ends the round as a loss without resolving remaining terms.
     *
     * @return void
     */
    public function test_forfeit_ends_round_as_loss(): void {
        global $DB;
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        [$state] = round_service::forfeit($state, $instance, $cm->cmid, $this->user->id);

        $this->assertTrue($state['finished']);
        $this->assertTrue($state['forfeited']);
        $this->assertFalse($state['won']);

        $attempt = $DB->get_record('playercross_attempts', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$attempt->completed);
    }

    /**
     * Regression test: forfeiting a puzzle that was only ever armed at page-load time
     * (ensure_round_state(), before "Iniciar rodada" is ever clicked) must be rejected
     * — otherwise a student could burn one of their max_rounds, and trigger the
     * cooldown, on a round they never actually played.
     *
     * @return void
     */
    public function test_forfeit_rejected_when_round_not_started(): void {
        global $DB;
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        $this->assertFalse($state['roundstarted']);

        [$state, $notification] = round_service::forfeit($state, $instance, $cm->cmid, $this->user->id);

        $this->assertNotEmpty($notification);
        $this->assertFalse($state['finished']);
        $this->assertSame(0, $DB->count_records('playercross_attempts', ['playercrossid' => $instance->id]));
    }

    /**
     * A timeout call before the configured deadline (minus tolerance) is rejected —
     * the client's own countdown reaching zero is never trusted on its own.
     *
     * @return void
     */
    public function test_timeout_rejected_before_deadline(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
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
     * Regression test: submit_term_guess() must close an expired round itself instead
     * of processing the guess — a client that never calls end_round(timeout) (or
     * reloads after the deadline, since the timer is only ever armed client-side once
     * it first sees timeleft > 0) would otherwise keep scoring indefinitely past the
     * activity's configured time limit.
     *
     * @return void
     */
    public function test_submit_term_guess_closes_round_once_deadline_has_passed(): void {
        global $DB;
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'timer_minutes' => 1,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $state['starttime'] = time() - 120;
        $term = $state['terms'][0];

        [$state, $resolved, $notification] = round_service::submit_term_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            (int)$term['wordid'],
            $term['word']
        );

        $this->assertFalse($resolved);
        $this->assertTrue($state['finished']);
        $this->assertTrue($state['timedout']);
        $this->assertNotEmpty($notification);
        $this->assertFalse($state['terms'][0]['resolved']);
        $attempt = $DB->get_record('playercross_attempts', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$attempt->completed);
    }

    /**
     * Same regression as test_submit_term_guess_closes_round_once_deadline_has_passed(),
     * for a direct guess of the mystery phrase.
     *
     * @return void
     */
    public function test_submit_final_guess_closes_round_once_deadline_has_passed(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'timer_minutes' => 1,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $state['starttime'] = time() - 120;

        [$state, $correct, $notification] = round_service::submit_final_guess(
            $state,
            $instance,
            $cm->cmid,
            $this->user->id,
            implode(' ', $state['themewords'])
        );

        $this->assertFalse($correct);
        $this->assertTrue($state['finished']);
        $this->assertTrue($state['timedout']);
        $this->assertNotEmpty($notification);
        $this->assertFalse($state['finalguesscorrect']);
    }

    /**
     * Same regression as test_submit_term_guess_closes_round_once_deadline_has_passed(),
     * for revealing a hint.
     *
     * @return void
     */
    public function test_reveal_hint_closes_round_once_deadline_has_passed(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'timer_minutes' => 1,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $state['starttime'] = time() - 120;
        $revealedbefore = $state['revealedslots'];

        [$state, $notification] = round_service::reveal_hint($state, $instance, $cm->cmid, $this->user->id);

        $this->assertTrue($state['finished']);
        $this->assertTrue($state['timedout']);
        $this->assertNotEmpty($notification);
        $this->assertSame($revealedbefore, $state['revealedslots']);
    }

    /**
     * Regression test: close_if_expired() — used by view_page_service so a page
     * reload after the deadline renders the round as finished — must leave an
     * unstarted or still-within-deadline round untouched, and close a genuinely
     * expired one exactly the way timeout() would.
     *
     * @return void
     */
    public function test_close_if_expired_only_closes_a_genuinely_expired_round(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'timer_minutes' => 1,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        $untouched = round_service::close_if_expired($state, $instance, $cm->cmid, $this->user->id);
        $this->assertFalse($untouched['finished']);

        $state['starttime'] = time() - 120;
        $closed = round_service::close_if_expired($state, $instance, $cm->cmid, $this->user->id);
        $this->assertTrue($closed['finished']);
        $this->assertTrue($closed['timedout']);
    }

    /**
     * Regression test: a puzzle armed at page-load time but never started
     * (starttime stays 0) must reject timeout() outright, not fall through to the
     * deadline check — with starttime=0, that check's own deadline sits in the remote
     * past and would otherwise pass unconditionally, defeating the anti-forgery
     * tolerance window it documents.
     *
     * @return void
     */
    public function test_timeout_rejected_when_round_not_started(): void {
        global $DB;
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'timer_minutes' => 5,
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        $this->assertFalse($state['roundstarted']);
        $this->assertSame(0, $state['starttime']);

        [$state, $notification] = round_service::timeout($state, $instance, $cm->cmid, $this->user->id);

        $this->assertNotEmpty($notification);
        $this->assertFalse($state['finished']);
        $this->assertSame(0, $DB->count_records('playercross_attempts', ['playercrossid' => $instance->id]));
    }

    /**
     * new_round() resets state to defaults, so the next ensure_round_state() builds a
     * completely fresh puzzle.
     *
     * @return void
     */
    public function test_new_round_resets_state(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
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
     * @return void
     */
    public function test_ensure_round_state_fires_round_started_event(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
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
        $this->assertSame(3, $events[0]->other['termstotal']);
    }

    /**
     * Winning a round — resolving every term — fires round_completed exactly once,
     * with the outcome recorded in its "other" payload.
     *
     * num_terms defaults to 3 here, and with theme_min_length 6 the fixed word pool
     * (casa/lobo/mel) always happens to cover every theme letter between the three of
     * them (see test_resolving_all_terms_alone_does_not_finish_round()) — so the round
     * already finishes as a side effect of the last term, before the trailing
     * submit_final_guess() call below is ever reached. That call is kept anyway to
     * confirm it is a harmless no-op once the round is already finished (it returns
     * early without touching state or firing a second event) — and, since the win came
     * from term resolution and not a typed-and-submitted guess, "other.finalguessed"
     * must read false, not true.
     *
     * @return void
     */
    public function test_winning_the_round_fires_round_completed_event(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);

        $sink = $this->redirectEvents();
        foreach ($state['terms'] as $term) {
            [$state] = round_service::submit_term_guess(
                $state,
                $instance,
                $cm->cmid,
                $this->user->id,
                (int)$term['wordid'],
                $term['word']
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
        $this->assertFalse($events[0]->other['finalguessed']);
        $this->assertSame(3, $events[0]->other['termsresolved']);
        $this->assertSame(3, $events[0]->other['termstotal']);
    }

    /**
     * Tests that the round-count restriction is enforced once max_rounds is reached.
     *
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
     * @return void
     */
    public function test_restriction_notice_none_when_unrestricted(): void {
        [$instance] = $this->make_ready_instance(['max_rounds' => 0, 'cooldown_amount' => 0]);

        $this->assertNull(round_service::get_round_restriction_notice($instance, $this->user->id));
    }

    /**
     * Regression test for the max_rounds/cooldown bypass: ensure_round_state() must
     * refuse to build a fresh puzzle once get_round_restriction_notice() reports a
     * restriction, even when the session state already looks like a fresh lobby
     * (themewordid=0, finished=false) — the exact shape a brand-new session, or a
     * blocked new_round() call, leaves behind. Before this guard, a direct call to
     * start_round, submit_term_guess, submit_final_guess or reveal_hint from that state
     * would build a puzzle and (once finished) insert an attempt row past max_rounds,
     * ignoring the cooldown entirely.
     *
     * @return void
     */
    public function test_ensure_round_state_refuses_new_puzzle_when_restricted(): void {
        [$instance, $cm] = $this->make_ready_instance(['max_rounds' => 1, 'cooldown_amount' => 0]);
        $this->modgenerator->create_attempt($instance->id, $this->user->id, 0);

        // Simulates the state a fresh session, or a blocked new_round(), leaves behind.
        $state = round_service::load_state($cm->cmid, $this->user->id);

        $state = round_service::ensure_round_state($state, $instance, $cm->cmid, $this->user->id);

        $this->assertSame(0, $state['themewordid']);
        $this->assertSame([], $state['terms']);
        $this->assertSame(1, round_service::count_rounds_played($instance, $this->user->id));
    }

    /**
     * Regression test for the parallel-session bypass: a puzzle can sit armed in a
     * session's state (ensure_round_state() already picked it) for a while before the
     * student ever clicks "Iniciar rodada". If, in the meantime, a second session for
     * the same user reaches max_rounds — e.g. two open tabs, one finishing rounds
     * while the other's lobby still holds a stale armed puzzle — start_round() must
     * refuse to commit for the first session too, instead of trusting that
     * ensure_round_state() already checked the restriction (it only checks when a NEW
     * puzzle is picked, not when an already-armed one is reused).
     *
     * @return void
     */
    public function test_start_round_revalidates_restriction_for_a_puzzle_armed_before_the_limit_hit(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6, 'max_rounds' => 1]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        $this->assertGreaterThan(0, $state['themewordid'], 'puzzle must be armed before the limit is reached');

        // Simulates a second, concurrent session for the same user finishing a round
        // in the meantime, reaching max_rounds — without going through the first
        // session's own (stale) $state at all.
        $this->modgenerator->create_attempt($instance->id, $this->user->id, 0);

        [$state, $notification] = round_service::start_round($state, $instance, $this->user->id);

        $this->assertNotNull($notification);
        $this->assertFalse($state['roundstarted']);
    }

    /**
     * start_round() reserves a playercross_attempts row immediately, before any guess
     * is submitted: attemptid is set, exactly one row exists with timefinished still 0,
     * and that reservation already counts against the round limit.
     *
     * @return void
     */
    public function test_start_round_reserves_attempt_row(): void {
        global $DB;

        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        [$state, $notification] = round_service::start_round($state, $instance, $this->user->id);

        $this->assertNull($notification);
        $this->assertGreaterThan(0, $state['attemptid']);

        $record = $DB->get_record('playercross_attempts', ['id' => $state['attemptid']], '*', MUST_EXIST);
        $this->assertSame(0, (int)$record->timefinished);
        $this->assertSame(1, $DB->count_records('playercross_attempts', ['playercrossid' => $instance->id]));
        $this->assertSame(1, round_service::count_rounds_played($instance, $this->user->id));
    }

    /**
     * The core regression test for round reservation: a round that is started and then
     * never finished (tab closed, session abandoned) still counts against max_rounds —
     * a student cannot abandon a losing round for free and start over indefinitely.
     *
     * @return void
     */
    public function test_abandoned_round_counts_towards_max_rounds(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6, 'max_rounds' => 1]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $this->assertTrue($state['roundstarted']);

        $notice = round_service::get_round_restriction_notice($instance, $this->user->id);

        $this->assertNotNull($notice, 'an abandoned, never-finished round must still count against max_rounds');
    }

    /**
     * The cooldown counterpart of test_abandoned_round_counts_towards_max_rounds(): a
     * round that is started and then never finished (tab closed, session abandoned)
     * must still start the cooldown clock. Before this fix, compute_cooldown_until()
     * ignored still-open reservations, so a student could discard the session holding
     * the reservation (logout, private tab, cookie wipe) and start a fresh round
     * immediately, bypassing cooldown_seconds entirely.
     *
     * @return void
     */
    public function test_abandoned_round_counts_towards_cooldown(): void {
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
            'theme_min_length' => 6,
            'max_rounds' => 0,
            'cooldown_amount' => 1,
            'cooldown_unit' => 'days',
        ]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );

        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $this->assertTrue($state['roundstarted']);

        $this->assertGreaterThan(
            time(),
            round_service::compute_cooldown_until($instance, $this->user->id),
            'an abandoned, never-finished round must still start the cooldown clock'
        );
        $this->assertNotNull(
            round_service::get_round_restriction_notice($instance, $this->user->id),
            'the restriction notice must report the cooldown as active, blocking a fresh round'
        );
    }

    /**
     * finish_round() updates the same row start_round() reserved, matched by attemptid,
     * instead of inserting a second one.
     *
     * @return void
     */
    public function test_finish_round_completes_reservation_instead_of_duplicating(): void {
        global $DB;

        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $reservedid = $state['attemptid'];

        [$state] = round_service::forfeit($state, $instance, $cm->cmid, $this->user->id);

        $this->assertSame(0, $state['attemptid']);
        $this->assertSame(1, $DB->count_records('playercross_attempts', ['playercrossid' => $instance->id]));
        $record = $DB->get_record('playercross_attempts', ['id' => $reservedid], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int)$record->timefinished);
    }

    /**
     * finish_round() falls back to a fresh insert when session state carries no
     * reservation id — the only real-world way to reach this is a round already
     * mid-play, in a session predating the reservation mechanism, at the moment the
     * plugin is upgraded.
     *
     * @return void
     */
    public function test_finish_round_inserts_fresh_record_when_no_reservation_exists(): void {
        global $DB;

        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 3, 'theme_min_length' => 6]);
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->user->id),
            $instance,
            $cm->cmid,
            $this->user->id
        );
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        // Simulates a pre-upgrade session: it started this round before attemptid
        // existed at all.
        $state['attemptid'] = 0;

        [$state] = round_service::forfeit($state, $instance, $cm->cmid, $this->user->id);

        $this->assertSame(0, $state['attemptid']);
        $this->assertSame(2, $DB->count_records('playercross_attempts', ['playercrossid' => $instance->id]));
    }

    /**
     * Tests that no cooldown applies when the setting is disabled, even with a recent
     * attempt.
     *
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
     * @return void
     */
    public function test_win_grants_item_with_xp_when_bounded(): void {
        global $DB;
        $this->skip_if_no_playerhud();

        $biid = $this->make_block_instance($this->course);
        $itemid = $this->make_item($biid, 30);
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
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
        [$state] = round_service::start_round($state, $instance, $this->user->id);

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
     * @return void
     */
    public function test_win_grants_item_without_xp_when_unlimited(): void {
        global $DB;
        $this->skip_if_no_playerhud();

        $biid = $this->make_block_instance($this->course);
        $itemid = $this->make_item($biid, 30);
        // The max_rounds override is omitted — make_ready_instance() defaults it to 0 (unlimited).
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
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
        [$state] = round_service::start_round($state, $instance, $this->user->id);

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
        [$state] = round_service::start_round($state, $instance, $this->user->id);

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
     * @return void
     */
    public function test_reveal_hint_waives_cost_when_item_deleted(): void {
        $this->skip_if_no_playerhud();

        [$instance, $cm] = $this->make_ready_instance([
            'num_terms' => 3,
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
        [$state] = round_service::start_round($state, $instance, $this->user->id);
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

    /**
     * The guest account plays a free demo: start_round() must skip the PlayerHUD cost
     * even when a real cost item is configured and the guest's balance (it has none)
     * would otherwise block it — test_start_round_still_blocks_when_item_disabled_and_
     * insufficient() above proves a regular student is still blocked by the same kind of
     * configuration, so this is a guest-specific waiver, not a general bypass.
     *
     * @return void
     */
    public function test_start_round_guest_never_charges_or_reserves(): void {
        global $DB;
        $this->skip_if_no_playerhud();

        $biid = $this->make_block_instance($this->course);
        $itemid = $this->make_item($biid);
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms'           => 3,
            'theme_min_length'    => 6,
            'hud_round_cost_item' => $itemid,
            'hud_round_cost_qty'  => 1,
        ]);
        $this->setGuestUser();
        $guestid = (int)guest_user()->id;

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $guestid),
            $instance,
            $cm->cmid,
            $guestid
        );
        [$state, $notification] = round_service::start_round($state, $instance, $guestid);

        $this->assertNull($notification);
        $this->assertTrue($state['roundstarted']);
        $this->assertSame(0, $state['attemptid']);
        $this->assertSame(0, $DB->count_records('playercross_attempts', ['playercrossid' => $instance->id]));
    }

    /**
     * The guest account plays a free demo: reveal_hint() must skip the PlayerHUD cost
     * even when a real cost item is configured and the guest's balance would otherwise
     * block it.
     *
     * @return void
     */
    public function test_reveal_hint_guest_never_charges(): void {
        $this->skip_if_no_playerhud();

        $biid = $this->make_block_instance($this->course);
        $itemid = $this->make_item($biid);
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms'          => 3,
            'theme_min_length'   => 6,
            'hud_hint_cost_item' => $itemid,
            'hud_hint_cost_qty'  => 1,
        ]);
        $this->setGuestUser();
        $guestid = (int)guest_user()->id;

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $guestid),
            $instance,
            $cm->cmid,
            $guestid
        );
        [$state] = round_service::start_round($state, $instance, $guestid);
        $revealedbefore = count($state['revealedslots']);

        [$state, , $notificationtype] = round_service::reveal_hint($state, $instance, $cm->cmid, $guestid);

        $this->assertSame('success', $notificationtype);
        $this->assertGreaterThan($revealedbefore, count($state['revealedslots']));
    }

    /**
     * The guest account plays a free demo: winning a round must leave no
     * {playercross_attempts} row, grant no PlayerHUD item and never touch the
     * gradebook — every guest visitor to a course shares the same account, so none of
     * this could be safely attributed to one specific person.
     *
     * @return void
     */
    public function test_finish_round_guest_never_persists(): void {
        global $DB;
        $this->skip_if_no_playerhud();

        $biid = $this->make_block_instance($this->course);
        $itemid = $this->make_item($biid, 30);
        [$instance, $cm] = $this->make_ready_instance([
            'num_terms'           => 3,
            'theme_min_length'    => 6,
            'win_condition'       => PLAYERCROSS_WINCONDITION_FINALONLY,
            'hud_win_reward_item' => $itemid,
            'hud_win_reward_qty'  => 2,
        ]);
        $this->setGuestUser();
        $guestid = (int)guest_user()->id;

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $guestid),
            $instance,
            $cm->cmid,
            $guestid
        );
        [$state] = round_service::start_round($state, $instance, $guestid);

        [$state] = round_service::submit_final_guess($state, $instance, $cm->cmid, $guestid, implode(' ', $state['themewords']));

        $this->assertTrue($state['finished']);
        $this->assertTrue($state['won']);
        $this->assertSame(0, $DB->count_records('playercross_attempts', ['playercrossid' => $instance->id]));
        $this->assertSame(0, $DB->count_records('block_playerhud_inventory', ['userid' => $guestid]));
    }
}
