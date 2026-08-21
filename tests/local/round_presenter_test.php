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
 * Unit tests for round_presenter.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Tests for round_presenter.
 *
 * Requires database access: build_round_result_context() and
 * build_ranking_summary_context() compute cooldown/ranking fields via round_service/
 * ranking_service, not session state alone (so a cooldown_seconds or ranking change
 * always applies immediately).
 *
 * @covers \mod_playercross\local\round_presenter
 * @covers \mod_playercross\local\ranking_service
 */
final class round_presenter_test extends \advanced_testcase {
    /** @var \stdClass Course used by the DB-dependent tests. */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Creates a playercross instance for the DB-dependent tests.
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass
     */
    private function make_instance(array $overrides = []): \stdClass {
        $record = array_merge([
            'course'       => $this->course->id,
            'show_ranking' => 0,
        ], $overrides);

        return $this->getDataGenerator()->get_plugin_generator('mod_playercross')->create_instance($record);
    }

    /**
     * Returns a minimal instance stub for the build_term_rows()/build_round_panel_context()
     * tests that do not need a real DB-backed activity — unlimited attempts by default,
     * so the attempts-remaining badge assertions elsewhere are unaffected unless overridden.
     *
     * @param array $overrides Field overrides.
     * @return \stdClass
     */
    private function make_instance_stub(array $overrides = []): \stdClass {
        return (object)array_merge([
            'max_attempts_per_term' => 0,
            'max_attempts_final_guess' => 0,
        ], $overrides);
    }

    /**
     * Returns a minimal default state array for a theme concept whose own mystery
     * phrase is the single word "escola" (6 distinct letters, cipher slots 1..6 in
     * order — a phrase of just one word ciphers identically to the pre-v1.9 single
     * theme word, see puzzle_builder::cipher_phrase_slots()) and one term "livro",
     * overridable per test.
     *
     * "livro" shares l (slot 5) and o (slot 4) with the phrase; its own i, v, r do not
     * appear in "escola" at all, so under the round-wide slot map (SCOPE.md §20.2
     * v1.7) they still get their own slot numbers (7, 8, 9 — continuing right after
     * the phrase's own 1..6), rather than staying number-less as they did before.
     *
     * @param array $overrides State field overrides.
     * @return array
     */
    private function make_state(array $overrides = []): array {
        return array_merge([
            'themewordid'      => 1,
            'themeconcept'     => 'Escola',
            'themewords'       => ['escola'],
            'themeclue'        => 'escola',
            'themeslots'       => [1, 2, 3, 4, 5, 6],
            'slotcount'        => 9,
            'revealedslots'    => [],
            'terms'            => [
                [
                    'wordid'       => 2,
                    'word'         => 'livro',
                    'originalword' => 'livro',
                    'clue'         => 'dica',
                    'slots'        => [5, 7, 8, 9, 4],
                    'resolved'     => false,
                    'attemptsused' => 0,
                    'exhausted'    => false,
                ],
            ],
            'termstotal'       => 1,
            'termsresolved'    => 0,
            'errorsused'       => 0,
            'score'            => 0.0,
            'rankingpoints'    => 0.0,
            'attemptsused'     => 0,
            'finalguessattemptsused' => 0,
            'finalguessexhausted' => false,
            'starttime'        => 0,
            'roundstarted'     => false,
            'finished'         => false,
            'won'              => false,
            'forfeited'        => false,
            'timedout'         => false,
            'finalguessed'     => false,
            'termsexhausted'   => false,
        ], $overrides);
    }

    /**
     * Tests that unrevealed slots stay hidden and revealed ones show the uppercase
     * letter, and that a single-word phrase produces exactly one word group.
     *
     * @return void
     */
    public function test_build_phrase_tiles_respects_revealed_slots(): void {
        $state = $this->make_state(['revealedslots' => [1]]);

        $groups = round_presenter::build_phrase_tiles($state, false);

        $this->assertCount(1, $groups);
        $tiles = $groups[0]['tiles'];
        $this->assertCount(6, $tiles);
        $this->assertTrue($tiles[0]['revealed']);
        $this->assertSame('E', $tiles[0]['letter']);
        $this->assertFalse($tiles[1]['revealed']);
        $this->assertSame('', $tiles[1]['letter']);
    }

    /**
     * Tests that a hidden phrase tile carries its own slot number, and a revealed one
     * carries none — the number is what lets a student tell which term would reveal
     * that position before it happens.
     *
     * @return void
     */
    public function test_build_phrase_tiles_hidden_tile_carries_slot_number(): void {
        $state = $this->make_state(['revealedslots' => [1]]);

        $tiles = round_presenter::build_phrase_tiles($state, false)[0]['tiles'];

        $this->assertSame('', $tiles[0]['slotnum']);
        $this->assertSame('2', $tiles[1]['slotnum']);
    }

    /**
     * Tests that every tile is revealed once the round has finished, regardless of
     * which slots were actually uncovered during play.
     *
     * @return void
     */
    public function test_build_phrase_tiles_all_revealed_when_finished(): void {
        $state = $this->make_state(['revealedslots' => []]);

        $tiles = round_presenter::build_phrase_tiles($state, true)[0]['tiles'];

        foreach ($tiles as $tile) {
            $this->assertTrue($tile['revealed']);
        }
        $this->assertSame('E', $tiles[0]['letter']);
        $this->assertSame('A', $tiles[5]['letter']);
    }

    /**
     * Tests that a multi-word mystery phrase produces one word group per word, each
     * holding only that word's own tiles — so the template can render a visual gap
     * between words instead of one continuous, spaceless run of letters.
     *
     * @return void
     */
    public function test_build_phrase_tiles_groups_by_word(): void {
        // Word "de" (d,e) then word "sala" (s,a,l,a) — slots continue in order of
        // first appearance across both words: d=1, e=2, s=3, a=4, l=5.
        $state = $this->make_state([
            'themewords' => ['de', 'sala'],
            'themeslots' => [1, 2, 3, 4, 5, 4],
            'revealedslots' => [1, 2, 3, 4, 5],
        ]);

        $groups = round_presenter::build_phrase_tiles($state, false);

        $this->assertCount(2, $groups);
        $this->assertCount(2, $groups[0]['tiles']);
        $this->assertCount(4, $groups[1]['tiles']);
        $this->assertSame('D', $groups[0]['tiles'][0]['letter']);
        $this->assertSame('E', $groups[0]['tiles'][1]['letter']);
        $this->assertSame('S', $groups[1]['tiles'][0]['letter']);
        $this->assertSame('A', $groups[1]['tiles'][1]['letter']);
        $this->assertSame('L', $groups[1]['tiles'][2]['letter']);
        $this->assertSame('A', $groups[1]['tiles'][3]['letter']);
    }

    /**
     * A revealed phrase tile shows the letter in its true original spelling (with
     * accent), not the accent-stripped form used for slot matching and guess
     * comparison — themewords/themeslots carry the normalized "cafe" (4 letters,
     * matching the accent-insensitive c/a/f/e slot cipher), while themeclue keeps the
     * original "café" the word was actually authored with.
     *
     * @return void
     */
    public function test_build_phrase_tiles_shows_original_accent_when_revealed(): void {
        $state = $this->make_state([
            'themewords' => ['cafe'],
            'themeclue' => 'café',
            'themeslots' => [1, 2, 3, 4],
            'revealedslots' => [1, 2, 3, 4],
        ]);

        $tiles = round_presenter::build_phrase_tiles($state, false)[0]['tiles'];

        $this->assertSame(['C', 'A', 'F', 'É'], array_column($tiles, 'letter'));
    }

    /**
     * Tests that an unresolved term never reveals its word, and can still be guessed.
     * Every position in its tile row carries a slot number while hidden — both the
     * letters shared with the mystery phrase (l, slot 5; o, slot 4) and the letters
     * exclusive to this term (i, v, r; slots 7, 8, 9), since the round-wide slot map
     * covers every letter in the round, not just the theme's own (SCOPE.md §20.2
     * v1.7) — none of them revealed while revealedslots is empty.
     *
     * @return void
     */
    public function test_build_term_rows_hides_unresolved_word(): void {
        $state = $this->make_state();

        $rows = round_presenter::build_term_rows($this->make_instance_stub(), $state, false);

        $this->assertCount(1, $rows);
        $this->assertSame('', $rows[0]['revealword']);
        $this->assertTrue($rows[0]['canguess']);
        $this->assertCount(5, $rows[0]['tiles']);
        foreach ($rows[0]['tiles'] as $tile) {
            $this->assertFalse($tile['revealed']);
        }
        $this->assertSame('5', $rows[0]['tiles'][0]['slotnum']);
        $this->assertSame('7', $rows[0]['tiles'][1]['slotnum']);
    }

    /**
     * Tests that a term the student never resolved still reveals its own answer word
     * once the round has finished — the round-result recap (templates/round_result.
     * mustache) relies on this to show every term's answer, not only the ones actually
     * solved during play.
     *
     * @return void
     */
    public function test_build_term_rows_reveals_unresolved_word_when_round_finished(): void {
        $state = $this->make_state();

        $rows = round_presenter::build_term_rows($this->make_instance_stub(), $state, true);

        $this->assertFalse($rows[0]['resolved']);
        $this->assertSame('LIVRO', $rows[0]['revealword']);
        $this->assertFalse($rows[0]['canguess']);
    }

    /**
     * Tests that a resolved term reveals its word in uppercase and can no longer be
     * guessed — including its own tiles, even the letters not shared with the mystery
     * phrase, since the full word is already known once resolved.
     *
     * @return void
     */
    public function test_build_term_rows_reveals_resolved_word(): void {
        $state = $this->make_state();
        $state['terms'][0]['resolved'] = true;

        $rows = round_presenter::build_term_rows($this->make_instance_stub(), $state, false);

        $this->assertSame('LIVRO', $rows[0]['revealword']);
        $this->assertFalse($rows[0]['canguess']);
        foreach ($rows[0]['tiles'] as $tile) {
            $this->assertTrue($tile['revealed']);
        }
        $this->assertSame('L', $rows[0]['tiles'][0]['letter']);
    }

    /**
     * A resolved term's own tiles show the letter in its true original spelling
     * (accent or cedilla kept), the same as revealword already does — not the
     * accent-stripped form guess comparison and slot matching use internally.
     *
     * @return void
     */
    public function test_build_term_rows_tiles_show_original_accent_when_resolved(): void {
        $state = $this->make_state();
        $state['terms'][0]['word'] = 'pacoca';
        $state['terms'][0]['originalword'] = 'paçoca';
        $state['terms'][0]['slots'] = [1, 2, 3, 4, 5, 6];
        $state['terms'][0]['resolved'] = true;

        $rows = round_presenter::build_term_rows($this->make_instance_stub(), $state, false);

        $this->assertSame('PAÇOCA', $rows[0]['revealword']);
        $this->assertSame(['P', 'A', 'Ç', 'O', 'C', 'A'], array_column($rows[0]['tiles'], 'letter'));
    }

    /**
     * Tests that an exhausted term carries a human-readable exhaustedlabel — not just
     * the bare attemptsused count the template used to print directly.
     *
     * @return void
     */
    public function test_build_term_rows_exhausted_label(): void {
        $state = $this->make_state();
        $state['terms'][0]['exhausted'] = true;
        $state['terms'][0]['attemptsused'] = 3;

        $rows = round_presenter::build_term_rows($this->make_instance_stub(), $state, false);

        $this->assertSame(get_string('termexhaustedlabel', 'mod_playercross', 3), $rows[0]['exhaustedlabel']);
    }

    /**
     * Tests that a term not (yet) exhausted carries a blank exhaustedlabel.
     *
     * @return void
     */
    public function test_build_term_rows_exhausted_label_blank_when_not_exhausted(): void {
        $rows = round_presenter::build_term_rows($this->make_instance_stub(), $this->make_state(), false);

        $this->assertSame('', $rows[0]['exhaustedlabel']);
    }

    /**
     * Tests that the term's phrase is always present in the row — it is the question
     * itself, never gated behind any reveal state.
     *
     * @return void
     */
    public function test_build_term_rows_phrase_always_shown(): void {
        $rows = round_presenter::build_term_rows($this->make_instance_stub(), $this->make_state(), false);

        $this->assertSame('dica', $rows[0]['phrase']);
    }

    /**
     * Tests that a shared letter already revealed via another term (or the global
     * hint) shows through in a still-unresolved term's own tile row.
     *
     * @return void
     */
    public function test_build_term_rows_shows_cross_revealed_shared_letter(): void {
        $state = $this->make_state(['revealedslots' => [5]]);

        $rows = round_presenter::build_term_rows($this->make_instance_stub(), $state, false);

        $this->assertTrue($rows[0]['tiles'][0]['revealed']);
        $this->assertSame('L', $rows[0]['tiles'][0]['letter']);
        $this->assertFalse($rows[0]['tiles'][1]['revealed']);
    }

    /**
     * Tests that an inactive cooldown produces an empty string.
     *
     * @return void
     */
    public function test_build_cooldown_text_inactive(): void {
        $this->assertSame('', round_presenter::build_cooldown_text(0));
    }

    /**
     * Tests that an active cooldown produces a non-empty formatted string.
     *
     * @return void
     */
    public function test_build_cooldown_text_active(): void {
        $this->assertNotSame('', round_presenter::build_cooldown_text(time() + 3600));
    }

    /**
     * Tests that a not-yet-finished round has no feedback message.
     *
     * @return void
     */
    public function test_build_feedback_message_not_finished(): void {
        $this->assertSame('', round_presenter::build_feedback_message($this->make_state()));
    }

    /**
     * Tests that forfeited, timed-out, terms-exhausted, final-guessed and plain-
     * won/lost rounds each produce their own distinct message.
     *
     * @return void
     */
    public function test_build_feedback_message_varies_by_outcome(): void {
        $forfeited = round_presenter::build_feedback_message($this->make_state(['finished' => true, 'forfeited' => true]));
        $timedout = round_presenter::build_feedback_message($this->make_state(['finished' => true, 'timedout' => true]));
        $termsexhausted = round_presenter::build_feedback_message(
            $this->make_state(['finished' => true, 'termsexhausted' => true])
        );
        $finalguessed = round_presenter::build_feedback_message(
            $this->make_state(['finished' => true, 'won' => true, 'finalguessed' => true])
        );
        $won = round_presenter::build_feedback_message($this->make_state(['finished' => true, 'won' => true]));
        $lost = round_presenter::build_feedback_message($this->make_state(['finished' => true]));

        $messages = [$forfeited, $timedout, $termsexhausted, $finalguessed, $won, $lost];
        $this->assertSame($messages, array_unique($messages));
    }

    /**
     * feedback_finalguessed only fires when it tells the player something feedback_won
     * would not — a direct guess that won the round while terms were still pending
     * (PLAYERCROSS_WINCONDITION_FINALONLY, or any win reached with termsresolved short
     * of termstotal). Once every term was also resolved, "you solved every term" is
     * already true regardless of the direct guess, and the player already saw a
     * dedicated toast the moment they guessed correctly — repeating it in the
     * end-of-round summary would just be the same fact stated twice.
     *
     * @return void
     */
    public function test_build_feedback_message_prefers_won_once_every_term_is_also_resolved(): void {
        $finalguessedwithtermspending = round_presenter::build_feedback_message($this->make_state([
            'finished' => true,
            'won' => true,
            'finalguessed' => true,
            'termstotal' => 3,
            'termsresolved' => 1,
        ]));
        $finalguessedwitheverytermresolved = round_presenter::build_feedback_message($this->make_state([
            'finished' => true,
            'won' => true,
            'finalguessed' => true,
            'termstotal' => 3,
            'termsresolved' => 3,
        ]));

        $this->assertSame(get_string('feedback_finalguessed', 'mod_playercross'), $finalguessedwithtermspending);
        $this->assertSame(get_string('feedback_won', 'mod_playercross'), $finalguessedwitheverytermresolved);
    }

    /**
     * Tests that the grading method info line is shown only when grading is enabled
     * and more than one round is possible.
     *
     * @return void
     */
    public function test_build_grading_method_info_relevance(): void {
        $graded = $this->make_instance(['grade' => 100, 'max_rounds' => 0]);
        $ungraded = $this->make_instance(['grade' => 0]);
        $singleround = $this->make_instance(['grade' => 100, 'max_rounds' => 1]);

        $this->assertTrue(round_presenter::build_grading_method_info($graded)['showgradingmethodinfo']);
        $this->assertFalse(round_presenter::build_grading_method_info($ungraded)['showgradingmethodinfo']);
        $this->assertFalse(round_presenter::build_grading_method_info($singleround)['showgradingmethodinfo']);
    }

    /**
     * Unlike the grading-method line, the points/scoring-mode summary is meaningful
     * even when only a single round is possible, so it must stay visible there — the
     * only thing that hides it is the activity being ungraded.
     *
     * @return void
     */
    public function test_build_grade_summary_info_relevance(): void {
        $graded = $this->make_instance(['grade' => 100, 'max_rounds' => 0]);
        $ungraded = $this->make_instance(['grade' => 0]);
        $singleround = $this->make_instance(['grade' => 100, 'max_rounds' => 1]);

        $this->assertTrue(round_presenter::build_grade_summary_info($graded)['showgradesummary']);
        $this->assertFalse(round_presenter::build_grade_summary_info($ungraded)['showgradesummary']);
        $this->assertSame('', round_presenter::build_grade_summary_info($ungraded)['gradesummary']);
        $this->assertTrue(round_presenter::build_grade_summary_info($singleround)['showgradesummary']);
    }

    /**
     * The summary text names both the point value and the configured scoring mode.
     *
     * @return void
     */
    public function test_build_grade_summary_info_text(): void {
        $binary = $this->make_instance(['grade' => 100, 'gradescoringmode' => PLAYERCROSS_SCORING_BINARY]);
        $linear = $this->make_instance(['grade' => 50, 'gradescoringmode' => PLAYERCROSS_SCORING_LINEAR]);

        $this->assertSame(
            get_string('lobby_gradesummary', 'mod_playercross', (object)[
                'points' => format_float(100.0, 2),
                'scoringmode' => get_string('scoringmode_binary', 'mod_playercross'),
            ]),
            round_presenter::build_grade_summary_info($binary)['gradesummary']
        );
        $this->assertSame(
            get_string('lobby_gradesummary', 'mod_playercross', (object)[
                'points' => format_float(50.0, 2),
                'scoringmode' => get_string('scoringmode_linear', 'mod_playercross'),
            ]),
            round_presenter::build_grade_summary_info($linear)['gradesummary']
        );
    }

    /**
     * Tests that the inline ranking summary is hidden when the activity has ranking
     * turned off, without ever touching ranking_service (no real cm needed).
     *
     * @return void
     */
    public function test_build_ranking_summary_context_hidden_when_ranking_off(): void {
        $instance = $this->make_instance(['show_ranking' => 0]);
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();

        $context = round_presenter::build_ranking_summary_context($instance, $cm, $user->id);

        $this->assertFalse($context['showranking']);
        $this->assertSame([], $context['rankingrows']);
    }

    /**
     * Tests that the inline ranking summary surfaces the same rows ranking.php's own
     * page would show, once a round has actually been completed.
     *
     * @return void
     */
    public function test_build_ranking_summary_context_shows_rows_when_ranking_on(): void {
        $instance = $this->make_instance(['show_ranking' => 1]);
        $cm = get_coursemodule_from_instance('playercross', $instance->id, $this->course->id, false, MUST_EXIST);
        $user = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($instance->id, 'escola');
        $modgenerator->create_attempt($instance->id, $user->id, $theme->id, ['rankingpoints' => 80]);

        $context = round_presenter::build_ranking_summary_context($instance, $cm, $user->id);

        $this->assertTrue($context['showranking']);
        $this->assertFalse($context['rankingempty']);
        $this->assertCount(1, $context['rankingrows']);
        $this->assertSame(format_float(80.0, 2), $context['rankingrows'][0]['totalscore']);
    }

    /**
     * Tests that the ranking scoring mode name resolves both configured options.
     *
     * @return void
     */
    public function test_ranking_scoring_mode_name(): void {
        $binary = $this->make_instance(['rankingscoringmode' => PLAYERCROSS_SCORING_BINARY]);
        $linear = $this->make_instance(['rankingscoringmode' => PLAYERCROSS_SCORING_LINEAR]);

        $this->assertSame(
            get_string('scoringmode_binary', 'mod_playercross'),
            round_presenter::ranking_scoring_mode_name($binary)
        );
        $this->assertSame(
            get_string('scoringmode_linear', 'mod_playercross'),
            round_presenter::ranking_scoring_mode_name($linear)
        );
    }

    /** @var int|null Memoized PlayerHUD block instance ID for $this->course. */
    private ?int $hudblockinstanceid = null;

    /**
     * Returns the PlayerHUD block instance ID for $this->course, creating it on first use.
     *
     * @return int
     */
    private function get_hud_block_instance(): int {
        global $DB;

        if ($this->hudblockinstanceid === null) {
            $ctx = \context_course::instance($this->course->id);
            $this->hudblockinstanceid = (int) $DB->insert_record('block_instances', (object) [
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

        return $this->hudblockinstanceid;
    }

    /**
     * Inserts a block_playerhud_items record, skipping the test if the block is absent.
     *
     * @param string $name Item display name.
     * @return int Item id.
     */
    private function make_hud_item(string $name): int {
        global $DB;
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
        return $DB->insert_record('block_playerhud_items', (object)[
            'blockinstanceid' => $this->get_hud_block_instance(),
            'name'            => $name,
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
     * The lobby shows a PlayerHUD cost hint when a valid item is configured, and
     * disables starting when the user's balance is short of the required quantity.
     *
     * @return void
     */
    public function test_build_lobby_context_shows_hud_cost_when_item_configured(): void {
        $itemid = $this->make_hud_item('Chave de Ouro');
        $instance = $this->make_instance(['hud_round_cost_item' => $itemid, 'hud_round_cost_qty' => 2]);
        $state = $this->make_state();
        $user = $this->getDataGenerator()->create_user();

        $context = round_presenter::build_lobby_context($instance, $state, $user->id);

        $this->assertTrue($context['hudstartcost']);
        $this->assertStringContainsString('Chave de Ouro', $context['hudstartcostlabel']);
        $this->assertFalse($context['canstart']);
    }

    /**
     * The guest account plays a free demo: round_service::start_round() never actually
     * charges it, so the lobby must not show a cost it won't apply, nor block starting
     * on a PlayerHUD balance the guest doesn't have (it has none at all).
     *
     * @return void
     */
    public function test_build_lobby_context_no_hud_cost_for_guest(): void {
        $itemid = $this->make_hud_item('Chave de Ouro');
        $instance = $this->make_instance(['hud_round_cost_item' => $itemid, 'hud_round_cost_qty' => 2]);
        $state = $this->make_state();
        $this->setGuestUser();

        $context = round_presenter::build_lobby_context($instance, $state, (int)guest_user()->id);

        $this->assertFalse($context['hudstartcost']);
        $this->assertSame('', $context['hudstartcostlabel']);
        $this->assertTrue($context['canstart']);
    }

    /**
     * The lobby allows starting once the user's balance meets the required quantity.
     *
     * @return void
     */
    public function test_build_lobby_context_canstart_true_with_enough_balance(): void {
        global $DB;

        $itemid = $this->make_hud_item('Chave de Ouro');
        $instance = $this->make_instance(['hud_round_cost_item' => $itemid, 'hud_round_cost_qty' => 1]);
        $state = $this->make_state();
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('block_playerhud_inventory', (object)[
            'userid'      => $user->id,
            'itemid'      => $itemid,
            'dropid'      => 0,
            'source'      => 'manual',
            'timecreated' => time(),
        ]);

        $context = round_presenter::build_lobby_context($instance, $state, $user->id);

        $this->assertTrue($context['canstart']);
    }

    /**
     * The lobby's timer info text is populated only when the activity timer is enabled.
     *
     * @return void
     */
    public function test_build_lobby_context_timer_info_only_when_enabled(): void {
        $withtimer = $this->make_instance(['timer_minutes' => 3]);
        $withouttimer = $this->make_instance();
        $state = $this->make_state();
        $user = $this->getDataGenerator()->create_user();

        $enabledctx = round_presenter::build_lobby_context($withtimer, $state, $user->id);
        $disabledctx = round_presenter::build_lobby_context($withouttimer, $state, $user->id);

        $this->assertTrue($enabledctx['timerenabled']);
        $this->assertNotSame('', $enabledctx['lobbytimerinfo']);
        $this->assertFalse($disabledctx['timerenabled']);
        $this->assertSame('', $disabledctx['lobbytimerinfo']);
    }

    /**
     * The lobby always shows the terms-this-round summary, driven by the puzzle state.
     *
     * @return void
     */
    public function test_build_lobby_context_shows_terms_this_round(): void {
        $instance = $this->make_instance();
        $state = $this->make_state(['termstotal' => 5]);
        $user = $this->getDataGenerator()->create_user();

        $context = round_presenter::build_lobby_context($instance, $state, $user->id);

        $this->assertStringContainsString('5', $context['termsthisround']);
    }

    /**
     * timeleft stays 0 while the round has not started yet, even with a timer configured.
     *
     * @return void
     */
    public function test_build_round_panel_context_timeleft_zero_before_round_started(): void {
        $instance = $this->make_instance(['timer_minutes' => 2]);
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();
        $state = $this->make_state(['roundstarted' => false]);

        $context = round_presenter::build_round_panel_context($instance, $cm, $state, $user->id);

        $this->assertSame(0, $context['timeleft']);
    }

    /**
     * Tests that the round-panel context merges in a structurally blank result when
     * the round is still active, never exposing the mystery phrase from session state.
     *
     * @return void
     */
    public function test_build_round_panel_context_hides_reveal_when_active(): void {
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();
        $state = $this->make_state();

        $context = round_presenter::build_round_panel_context($instance, $cm, $state, $user->id);

        $this->assertFalse($context['roundfinished']);
        $this->assertSame('', $context['revealthemeword']);
    }

    /**
     * The theme concept caption is uppercased for display, the same as every other
     * revealed word in the round (revealword, themedisplayword, revealthemeword) —
     * puzzle_builder stores it exactly as the admin typed it in the word bank
     * (puzzle_builder_test.php::test_build_round_theme_phrase_comes_from_clue()
     * asserts the raw lowercase value survives that layer unchanged), so normalising
     * the casing is this presentation layer's job.
     *
     * @return void
     */
    public function test_build_round_panel_context_uppercases_theme_concept(): void {
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();
        $state = $this->make_state(['themeconcept' => 'pasta']);

        $context = round_presenter::build_round_panel_context($instance, $cm, $state, $user->id);

        $this->assertSame('PASTA', $context['themeconcept']);
    }

    /**
     * A correct direct guess collapses the mystery-phrase tiles into resolved text
     * immediately — mirroring an individual solved term — even with the round still
     * active (terms pending under PLAYERCROSS_WINCONDITION_BOTH), instead of only once
     * the whole round finishes.
     *
     * @return void
     */
    public function test_build_round_panel_context_theme_solved_before_round_finishes(): void {
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();
        $state = $this->make_state(['finalguesscorrect' => true]);

        $context = round_presenter::build_round_panel_context($instance, $cm, $state, $user->id);

        $this->assertFalse($context['roundfinished']);
        $this->assertTrue($context['themesolved']);
        $this->assertTrue($context['finalguesscorrect']);
        $this->assertFalse($context['canfinalguess']);
        $this->assertSame('ESCOLA', $context['themedisplayword']);
    }

    /**
     * A round lost via forfeit/timeout/termsexhausted still collapses the mystery-phrase
     * tiles into text once finished, even though the phrase itself was never correctly
     * guessed — but without the checkmark a genuine win gets, since the player did not
     * actually solve it.
     *
     * @return void
     */
    public function test_build_round_panel_context_theme_solved_on_finished_loss_without_checkmark(): void {
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();
        $state = $this->make_state(['finished' => true, 'forfeited' => true, 'finalguesscorrect' => false]);

        $context = round_presenter::build_round_panel_context($instance, $cm, $state, $user->id);

        $this->assertTrue($context['roundfinished']);
        $this->assertTrue($context['themesolved']);
        $this->assertFalse($context['finalguesscorrect']);
        $this->assertSame('ESCOLA', $context['themedisplayword']);
    }

    /**
     * With nothing revealed yet, the keyboard's revealed-letters set is empty — no key
     * should be marked before the student has resolved anything.
     *
     * @return void
     */
    public function test_build_round_panel_context_revealed_letters_json_empty_when_nothing_revealed(): void {
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();
        $state = $this->make_state();

        $context = round_presenter::build_round_panel_context($instance, $cm, $state, $user->id);

        $this->assertSame([], json_decode($context['revealedlettersjson'], true));
    }

    /**
     * Revealing a mystery-phrase slot surfaces that letter in the keyboard's
     * revealed-letters set, the same round-wide fact the phrase tiles themselves
     * already reflect (see build_phrase_tiles()).
     *
     * @return void
     */
    public function test_build_round_panel_context_revealed_letters_json_includes_phrase_letter(): void {
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();
        // Slot 1 is 'e', the phrase's own first letter (see make_state()'s docblock).
        $state = $this->make_state(['revealedslots' => [1]]);

        $context = round_presenter::build_round_panel_context($instance, $cm, $state, $user->id);

        $this->assertSame(['E'], json_decode($context['revealedlettersjson'], true));
    }

    /**
     * A revealed letter exclusive to a term — never appearing in the mystery phrase
     * itself — still surfaces in the keyboard's revealed-letters set, since the
     * round-wide slot map covers every letter in the round, not just the phrase's own
     * (SCOPE.md §20.2 v1.7).
     *
     * @return void
     */
    public function test_build_round_panel_context_revealed_letters_json_includes_term_only_letter(): void {
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();
        // Slot 7 is 'i', exclusive to the term "livro" — absent from the phrase "escola".
        $state = $this->make_state(['revealedslots' => [7]]);

        $context = round_presenter::build_round_panel_context($instance, $cm, $state, $user->id);

        $this->assertSame(['I'], json_decode($context['revealedlettersjson'], true));
    }

    /**
     * A letter shared between the phrase and a term (slot 5, "l") appears only once in
     * the revealed-letters set — the keyboard has one key per letter, not one per
     * target it happens to belong to.
     *
     * @return void
     */
    public function test_build_round_panel_context_revealed_letters_json_dedupes_shared_letter(): void {
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();
        $state = $this->make_state(['revealedslots' => [5]]);

        $context = round_presenter::build_round_panel_context($instance, $cm, $state, $user->id);

        $this->assertSame(['L'], json_decode($context['revealedlettersjson'], true));
    }

    /**
     * Tests that the round-result context is structurally blank while the round is
     * active, never exposing the mystery phrase sitting in session state.
     *
     * @return void
     */
    public function test_build_round_result_context_blank_when_not_finished(): void {
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $state = $this->make_state();

        $context = round_presenter::build_round_result_context($instance, $cm, $state, 1, false);

        $this->assertSame('', $context['revealthemeword']);
        $this->assertSame(0, $context['cooldownuntil']);
        $this->assertSame('', $context['roundsplayedlabel']);
        $this->assertFalse($context['showscoreachieved']);
        $this->assertFalse($context['showranking']);
    }

    /**
     * Tests that the round-result context reveals the mystery phrase once finished,
     * and computes the cooldown from the current instance settings rather than
     * session state.
     *
     * @return void
     */
    public function test_build_round_result_context_reveals_when_finished(): void {
        $instance = $this->make_instance(['cooldown_amount' => 2, 'cooldown_unit' => 'minutes']);
        $user = $this->getDataGenerator()->create_user();
        $cm = (object)['id' => 5];
        $state = $this->make_state(['finished' => true, 'won' => true]);
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($instance->id, 'escola');
        $modgenerator->create_attempt($instance->id, $user->id, $theme->id);

        $context = round_presenter::build_round_result_context($instance, $cm, $state, $user->id, true);

        $this->assertSame('ESCOLA', $context['revealthemeword']);
        $this->assertGreaterThan(time(), $context['cooldownuntil']);
        $this->assertTrue($context['cooldownactive']);
        $this->assertSame("Rounds played: 1 / \u{221E}.", $context['roundsplayedlabel']);
        $this->assertNotSame('', $context['resulttermslabel']);
        $this->assertTrue($context['showscoreachieved']);
    }

    /**
     * Tests that the achieved-score line is hidden for an ungraded activity — the
     * score is always computed against instance->grade as its ceiling, so an
     * ungraded activity (grade == 0) would otherwise always show a misleading 0.00
     * regardless of whether the round was won.
     *
     * @return void
     */
    public function test_build_round_result_context_hides_score_when_ungraded(): void {
        $instance = $this->make_instance(['grade' => 0]);
        $user = $this->getDataGenerator()->create_user();
        $cm = (object)['id' => 5];
        $state = $this->make_state(['finished' => true, 'won' => true]);
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($instance->id, 'escola');
        $modgenerator->create_attempt($instance->id, $user->id, $theme->id);

        $context = round_presenter::build_round_result_context($instance, $cm, $state, $user->id, true);

        $this->assertFalse($context['showscoreachieved']);
    }

    /**
     * Tests that changing cooldown_seconds after a round finished takes effect
     * immediately — the specific behaviour that motivates computing cooldown from the
     * DB instead of caching it in session state at the moment the round ended.
     *
     * @return void
     */
    public function test_cooldown_reflects_a_later_settings_change(): void {
        global $DB;

        $instance = $this->make_instance(['cooldown_amount' => 1, 'cooldown_unit' => 'days']);
        $user = $this->getDataGenerator()->create_user();
        $cm = (object)['id' => 5];
        $state = $this->make_state(['finished' => true, 'won' => true]);
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($instance->id, 'escola');
        $modgenerator->create_attempt($instance->id, $user->id, $theme->id);

        $before = round_presenter::build_round_result_context($instance, $cm, $state, $user->id, true);
        $this->assertTrue($before['cooldownactive']);
        $this->assertGreaterThan(time() + 3600, $before['cooldownuntil']);

        $DB->set_field('playercross', 'cooldown_seconds', 0, ['id' => $instance->id]);
        $instance = $DB->get_record('playercross', ['id' => $instance->id], '*', MUST_EXIST);

        $after = round_presenter::build_round_result_context($instance, $cm, $state, $user->id, true);
        $this->assertFalse($after['cooldownactive']);
        $this->assertSame(0, $after['cooldownuntil']);
    }

    /**
     * The round-wide hint action is offered while at least one slot anywhere in the
     * round is still hidden — including a term-exclusive slot that never appears in
     * the mystery phrase at all (SCOPE.md §20.2 v1.8) — and withdrawn only once every
     * slot in the round is already revealed.
     *
     * @return void
     */
    public function test_build_round_panel_context_global_hint_availability(): void {
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();

        $partial = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            // Every theme slot (1..6) revealed, but slots 7..9 (livro's own i, v, r)
            // stay hidden — the hint must still be offered, since it can still reveal
            // one of those term-exclusive letters.
            $this->make_state(['revealedslots' => [1, 2, 3, 4, 5, 6]]),
            $user->id
        );
        $this->assertTrue($partial['showglobalhint']);
        // No max_hints_per_round configured (make_instance()'s default): unlimited, so
        // the badge shows the infinity glyph rather than a counting-down number — same
        // convention as build_rounds_played_label()'s own "3 / ∞".
        $this->assertTrue($partial['showhintsremaining']);
        $this->assertSame("\u{221E}", $partial['hintsremainingvalue']);

        $complete = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state(['revealedslots' => range(1, 9)]),
            $user->id
        );
        $this->assertFalse($complete['showglobalhint']);
    }

    /**
     * When the teacher configures max_hints_per_round, the hint button carries a
     * visible remaining-hints count that counts down as hintsused grows, disappearing
     * only once the button itself is withdrawn at the limit (see
     * test_build_round_panel_context_hint_limit_hides_button()).
     *
     * @return void
     */
    public function test_build_round_panel_context_shows_hints_remaining_count(): void {
        $instance = $this->make_instance(['max_hints_per_round' => 3]);
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();

        $fresh = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state(['revealedslots' => [1, 2, 3, 4, 5, 6], 'hintsused' => 0]),
            $user->id
        );
        $this->assertTrue($fresh['showhintsremaining']);
        $this->assertSame('3', $fresh['hintsremainingvalue']);
        $this->assertSame(get_string('hintsremaining', 'mod_playercross', '3'), $fresh['hintsremaininglabel']);

        $afterone = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state(['revealedslots' => [1, 2, 3, 4, 5, 6], 'hintsused' => 1]),
            $user->id
        );
        $this->assertSame('2', $afterone['hintsremainingvalue']);

        $afterall = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state(['revealedslots' => [1, 2, 3, 4, 5, 6], 'hintsused' => 3]),
            $user->id
        );
        // The button itself is withdrawn at the limit, so there is nothing left to
        // count down — showhintsremaining reverts to the same false/blank shape as
        // roundfinished/no-hidden-slots does, not a "0 left" count.
        $this->assertFalse($afterall['showglobalhint']);
        $this->assertFalse($afterall['showhintsremaining']);
        $this->assertSame('', $afterall['hintsremainingvalue']);
    }

    /**
     * Tests that a term row shows an attempts-remaining badge that counts down as
     * attemptsused grows, matching the hints-remaining pattern.
     *
     * @return void
     */
    public function test_build_term_rows_shows_attempts_remaining_count(): void {
        $instance = $this->make_instance_stub(['max_attempts_per_term' => 3]);

        $fresh = round_presenter::build_term_rows($instance, $this->make_state(), false);
        $this->assertTrue($fresh[0]['showtermattemptsremaining']);
        $this->assertSame('3', $fresh[0]['termattemptsremainingvalue']);
        $this->assertSame(
            get_string('attemptsremaining', 'mod_playercross', '3'),
            $fresh[0]['termattemptsremaininglabel']
        );

        $state = $this->make_state();
        $state['terms'][0]['attemptsused'] = 2;
        $afterone = round_presenter::build_term_rows($instance, $state, false);
        $this->assertSame('1', $afterone[0]['termattemptsremainingvalue']);
    }

    /**
     * Tests that the term attempts-remaining badge shows the infinity glyph when
     * max_attempts_per_term is unlimited (0).
     *
     * @return void
     */
    public function test_build_term_rows_attempts_remaining_infinity_when_unlimited(): void {
        $instance = $this->make_instance_stub(['max_attempts_per_term' => 0]);

        $rows = round_presenter::build_term_rows($instance, $this->make_state(), false);

        $this->assertSame("\u{221E}", $rows[0]['termattemptsremainingvalue']);
    }

    /**
     * Tests that the attempts-remaining badge is hidden once a term is resolved,
     * exhausted, or the round has finished — nothing left to count down.
     *
     * @return void
     */
    public function test_build_term_rows_hides_attempts_remaining_once_unavailable(): void {
        $instance = $this->make_instance_stub(['max_attempts_per_term' => 3]);

        $resolvedstate = $this->make_state();
        $resolvedstate['terms'][0]['resolved'] = true;
        $resolved = round_presenter::build_term_rows($instance, $resolvedstate, false);
        $this->assertFalse($resolved[0]['showtermattemptsremaining']);
        $this->assertSame('', $resolved[0]['termattemptsremainingvalue']);

        $exhaustedstate = $this->make_state();
        $exhaustedstate['terms'][0]['exhausted'] = true;
        $exhausted = round_presenter::build_term_rows($instance, $exhaustedstate, false);
        $this->assertFalse($exhausted[0]['showtermattemptsremaining']);

        $finished = round_presenter::build_term_rows($instance, $this->make_state(), true);
        $this->assertFalse($finished[0]['showtermattemptsremaining']);
    }

    /**
     * Tests that the panel shows a final-guess attempts-remaining badge that counts
     * down as finalguessattemptsused grows, and is hidden once the phrase is solved.
     *
     * @return void
     */
    public function test_build_round_panel_context_shows_final_guess_attempts_remaining(): void {
        $instance = $this->make_instance(['max_attempts_final_guess' => 3]);
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();

        $fresh = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state(['finalguessattemptsused' => 0]),
            $user->id
        );
        $this->assertTrue($fresh['showfinalguessattemptsremaining']);
        $this->assertSame('3', $fresh['finalguessattemptsremainingvalue']);
        $this->assertSame(
            get_string('attemptsremaining', 'mod_playercross', '3'),
            $fresh['finalguessattemptsremaininglabel']
        );

        $afterone = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state(['finalguessattemptsused' => 1]),
            $user->id
        );
        $this->assertSame('2', $afterone['finalguessattemptsremainingvalue']);

        $solved = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state(['finalguesscorrect' => true]),
            $user->id
        );
        $this->assertFalse($solved['showfinalguessattemptsremaining']);
        $this->assertSame('', $solved['finalguessattemptsremainingvalue']);
    }

    /**
     * Tests that finalguessexhausted carries a human-readable label once the round
     * ended because the mystery phrase ran out of attempts.
     *
     * @return void
     */
    public function test_build_round_panel_context_finalguessexhausted_label(): void {
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();

        $context = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state([
                'finished' => true,
                'finalguessexhausted' => true,
                'finalguessattemptsused' => 3,
            ]),
            $user->id
        );

        $this->assertTrue($context['finalguessexhausted']);
        $this->assertSame(
            get_string('termexhaustedlabel', 'mod_playercross', 3),
            $context['finalguessexhaustedlabel']
        );
    }

    /**
     * Tests that the end-of-round feedback message reports final-guess exhaustion.
     *
     * @return void
     */
    public function test_build_feedback_message_finalguessexhausted(): void {
        $state = $this->make_state(['finished' => true, 'finalguessexhausted' => true]);

        $this->assertSame(
            get_string('feedback_finalguessexhausted', 'mod_playercross'),
            round_presenter::build_feedback_message($state)
        );
    }

    /**
     * Tests that a configured max_hints_per_round hides the hint button once the
     * student's own hint count reaches it, even though hidden slots remain (slots
     * 7..9 are still unrevealed under the default make_state() override below).
     *
     * @return void
     */
    public function test_build_round_panel_context_hint_limit_hides_button(): void {
        $instance = $this->make_instance(['max_hints_per_round' => 1]);
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();

        $underlimit = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state(['revealedslots' => [1, 2, 3, 4, 5, 6], 'hintsused' => 0]),
            $user->id
        );
        $this->assertTrue($underlimit['showglobalhint']);

        $atlimit = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state(['revealedslots' => [1, 2, 3, 4, 5, 6], 'hintsused' => 1]),
            $user->id
        );
        $this->assertFalse($atlimit['showglobalhint']);
    }

    /**
     * Tests that hints_enabled=0 hides the hint button entirely, even with no
     * per-round limit configured and hidden slots still available.
     *
     * @return void
     */
    public function test_build_round_panel_context_hints_disabled_hides_button(): void {
        $instance = $this->make_instance(['hints_enabled' => 0]);
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();

        $context = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state(['revealedslots' => [1, 2, 3, 4, 5, 6], 'hintsused' => 0]),
            $user->id
        );

        $this->assertFalse($context['showglobalhint']);
    }

    /**
     * The hint button shows the PlayerHUD balance/cost line, and canaffordhint is
     * false, while the user's balance is short of the required quantity.
     *
     * @return void
     */
    public function test_build_round_panel_context_hint_button_shows_hud_cost(): void {
        $itemid = $this->make_hud_item('Lupa');
        $instance = $this->make_instance(['hud_hint_cost_item' => $itemid, 'hud_hint_cost_qty' => 1]);
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();

        $context = round_presenter::build_round_panel_context($instance, $cm, $this->make_state(), $user->id);

        $this->assertTrue($context['hudhintcost']);
        $this->assertStringContainsString('Lupa', $context['hudhintcostlabel']);
        $this->assertFalse($context['canaffordhint']);
    }

    /**
     * The guest account plays a free demo: round_service::reveal_hint() never actually
     * charges it, so the round panel must not show a hint cost it won't apply, nor
     * block revealing the hint on a PlayerHUD balance the guest doesn't have.
     *
     * @return void
     */
    public function test_build_round_panel_context_no_hint_cost_for_guest(): void {
        $itemid = $this->make_hud_item('Lupa');
        $instance = $this->make_instance(['hud_hint_cost_item' => $itemid, 'hud_hint_cost_qty' => 1]);
        $cm = (object)['id' => 5];
        $this->setGuestUser();

        $context = round_presenter::build_round_panel_context($instance, $cm, $this->make_state(), (int)guest_user()->id);

        $this->assertFalse($context['hudhintcost']);
        $this->assertSame('', $context['hudhintcostlabel']);
        $this->assertTrue($context['canaffordhint']);
    }

    /**
     * canaffordhint becomes true once the user's balance meets the required quantity.
     *
     * @return void
     */
    public function test_build_round_panel_context_canaffordhint_true_with_enough_balance(): void {
        global $DB;
        $itemid = $this->make_hud_item('Lupa');
        $instance = $this->make_instance(['hud_hint_cost_item' => $itemid, 'hud_hint_cost_qty' => 1]);
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('block_playerhud_inventory', (object)[
            'userid'      => $user->id,
            'itemid'      => $itemid,
            'dropid'      => 0,
            'source'      => 'manual',
            'timecreated' => time(),
        ]);

        $context = round_presenter::build_round_panel_context($instance, $cm, $this->make_state(), $user->id);

        $this->assertTrue($context['canaffordhint']);
    }

    /**
     * The round panel omits the PlayerHUD cost line once every slot in the round is
     * already revealed — the hint button itself disappears at that point (see
     * test_build_round_panel_context_global_hint_availability()), so the cost line
     * has nothing left to attach to.
     *
     * @return void
     */
    public function test_build_round_panel_context_hint_button_omits_cost_once_exhausted(): void {
        $itemid = $this->make_hud_item('Lupa');
        $instance = $this->make_instance(['hud_hint_cost_item' => $itemid]);
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();

        $context = round_presenter::build_round_panel_context(
            $instance,
            $cm,
            $this->make_state(['revealedslots' => range(1, 9)]),
            $user->id
        );

        $this->assertFalse($context['hudhintcost']);
        $this->assertSame('', $context['hudhintcostlabel']);
    }

    /**
     * Tests that the round result announces the PlayerHUD item granted for the win,
     * once configured and the round was actually won.
     *
     * @return void
     */
    public function test_build_round_result_context_shows_hud_grant_label_on_win(): void {
        $itemid = $this->make_hud_item('Gold Key');
        $instance = $this->make_instance(['hud_win_reward_item' => $itemid, 'hud_win_reward_qty' => 2]);
        $cm = (object)['id' => 5];
        $state = $this->make_state(['finished' => true, 'won' => true]);

        $context = round_presenter::build_round_result_context($instance, $cm, $state, 1, true);

        $this->assertStringContainsString('Gold Key', $context['huditemrewardedlabel']);
    }

    /**
     * The guest account plays a free demo: round_service::finish_round() never actually
     * grants a PlayerHUD item to it, so the round result must not announce one even when
     * the state says the round was won and a win-reward item is configured — announcing
     * it would claim something that did not happen.
     *
     * @return void
     */
    public function test_build_round_result_context_no_hud_grant_label_for_guest(): void {
        $itemid = $this->make_hud_item('Gold Key');
        $instance = $this->make_instance(['hud_win_reward_item' => $itemid, 'hud_win_reward_qty' => 2]);
        $cm = (object)['id' => 5];
        $state = $this->make_state(['finished' => true, 'won' => true]);
        $this->setGuestUser();

        $context = round_presenter::build_round_result_context($instance, $cm, $state, (int)guest_user()->id, true);

        $this->assertSame('', $context['huditemrewardedlabel']);
    }

    /**
     * Tests that no grant label is shown when the round was lost, even with a
     * win-reward item configured.
     *
     * @return void
     */
    public function test_build_round_result_context_no_hud_grant_label_on_loss(): void {
        $itemid = $this->make_hud_item('Gold Key');
        $instance = $this->make_instance(['hud_win_reward_item' => $itemid]);
        $cm = (object)['id' => 5];
        $state = $this->make_state(['finished' => true, 'won' => false]);

        $context = round_presenter::build_round_result_context($instance, $cm, $state, 1, true);

        $this->assertSame('', $context['huditemrewardedlabel']);
    }
}
