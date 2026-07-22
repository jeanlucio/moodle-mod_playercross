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
 * Requires database access: build_round_result_context() and build_grade_so_far()
 * compute cooldown/gradebook fields via round_service/grade_item, not session state
 * alone (so a cooldown_seconds or grade change always applies immediately).
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
     * Returns a minimal default state array for a theme concept whose own mystery
     * phrase is the single word "escola" (6 distinct letters, cipher slots 1..6 in
     * order — a phrase of just one word ciphers identically to the pre-v1.9 single
     * theme word, see puzzle_builder::cipher_phrase_slots()) and one clue "livro",
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
            'themehint'        => 'escola',
            'themeslots'       => [1, 2, 3, 4, 5, 6],
            'slotcount'        => 9,
            'revealedslots'    => [],
            'clues'            => [
                [
                    'wordid'       => 2,
                    'word'         => 'livro',
                    'originalword' => 'livro',
                    'hint'         => 'dica',
                    'slots'        => [5, 7, 8, 9, 4],
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
            'roundstarted'     => false,
            'finished'         => false,
            'won'              => false,
            'forfeited'        => false,
            'timedout'         => false,
            'finalguessed'     => false,
            'cluesexhausted'   => false,
        ], $overrides);
    }

    /**
     * Tests that unrevealed slots stay hidden and revealed ones show the uppercase
     * letter, and that a single-word phrase produces exactly one word group.
     *
     * @covers \mod_playercross\local\round_presenter::build_phrase_tiles
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
     * carries none — the number is what lets a student tell which clue would reveal
     * that position before it happens.
     *
     * @covers \mod_playercross\local\round_presenter::build_phrase_tiles
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
     * @covers \mod_playercross\local\round_presenter::build_phrase_tiles
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
     * @covers \mod_playercross\local\round_presenter::build_phrase_tiles
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
     * Tests that an unresolved clue never reveals its word, and can still be guessed.
     * Every position in its tile row carries a slot number while hidden — both the
     * letters shared with the mystery phrase (l, slot 5; o, slot 4) and the letters
     * exclusive to this clue (i, v, r; slots 7, 8, 9), since the round-wide slot map
     * covers every letter in the round, not just the theme's own (SCOPE.md §20.2
     * v1.7) — none of them revealed while revealedslots is empty.
     *
     * @covers \mod_playercross\local\round_presenter::build_clue_rows
     * @return void
     */
    public function test_build_clue_rows_hides_unresolved_word(): void {
        $state = $this->make_state();

        $rows = round_presenter::build_clue_rows($state, false);

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
     * Tests that a clue the student never resolved still reveals its own answer word
     * once the round has finished — the round-result recap (templates/round_result.
     * mustache) relies on this to show every clue's answer, not only the ones actually
     * solved during play.
     *
     * @covers \mod_playercross\local\round_presenter::build_clue_rows
     * @return void
     */
    public function test_build_clue_rows_reveals_unresolved_word_when_round_finished(): void {
        $state = $this->make_state();

        $rows = round_presenter::build_clue_rows($state, true);

        $this->assertFalse($rows[0]['resolved']);
        $this->assertSame('LIVRO', $rows[0]['revealword']);
        $this->assertFalse($rows[0]['canguess']);
    }

    /**
     * Tests that a resolved clue reveals its word in uppercase and can no longer be
     * guessed — including its own tiles, even the letters not shared with the mystery
     * phrase, since the full word is already known once resolved.
     *
     * @covers \mod_playercross\local\round_presenter::build_clue_rows
     * @return void
     */
    public function test_build_clue_rows_reveals_resolved_word(): void {
        $state = $this->make_state();
        $state['clues'][0]['resolved'] = true;

        $rows = round_presenter::build_clue_rows($state, false);

        $this->assertSame('LIVRO', $rows[0]['revealword']);
        $this->assertFalse($rows[0]['canguess']);
        foreach ($rows[0]['tiles'] as $tile) {
            $this->assertTrue($tile['revealed']);
        }
        $this->assertSame('L', $rows[0]['tiles'][0]['letter']);
    }

    /**
     * Tests that an exhausted clue carries a human-readable exhaustedlabel — not just
     * the bare attemptsused count the template used to print directly.
     *
     * @covers \mod_playercross\local\round_presenter::build_clue_rows
     * @return void
     */
    public function test_build_clue_rows_exhausted_label(): void {
        $state = $this->make_state();
        $state['clues'][0]['exhausted'] = true;
        $state['clues'][0]['attemptsused'] = 3;

        $rows = round_presenter::build_clue_rows($state, false);

        $this->assertSame(get_string('clueexhaustedlabel', 'mod_playercross', 3), $rows[0]['exhaustedlabel']);
    }

    /**
     * Tests that a clue not (yet) exhausted carries a blank exhaustedlabel.
     *
     * @covers \mod_playercross\local\round_presenter::build_clue_rows
     * @return void
     */
    public function test_build_clue_rows_exhausted_label_blank_when_not_exhausted(): void {
        $rows = round_presenter::build_clue_rows($this->make_state(), false);

        $this->assertSame('', $rows[0]['exhaustedlabel']);
    }

    /**
     * Tests that the clue's phrase is always present in the row — it is the question
     * itself, never gated behind any reveal state.
     *
     * @covers \mod_playercross\local\round_presenter::build_clue_rows
     * @return void
     */
    public function test_build_clue_rows_phrase_always_shown(): void {
        $rows = round_presenter::build_clue_rows($this->make_state(), false);

        $this->assertSame('dica', $rows[0]['phrase']);
    }

    /**
     * Tests that a shared letter already revealed via another clue (or the global
     * hint) shows through in a still-unresolved clue's own tile row.
     *
     * @covers \mod_playercross\local\round_presenter::build_clue_rows
     * @return void
     */
    public function test_build_clue_rows_shows_cross_revealed_shared_letter(): void {
        $state = $this->make_state(['revealedslots' => [5]]);

        $rows = round_presenter::build_clue_rows($state, false);

        $this->assertTrue($rows[0]['tiles'][0]['revealed']);
        $this->assertSame('L', $rows[0]['tiles'][0]['letter']);
        $this->assertFalse($rows[0]['tiles'][1]['revealed']);
    }

    /**
     * Tests that an inactive cooldown produces an empty string.
     *
     * @covers \mod_playercross\local\round_presenter::build_cooldown_text
     * @return void
     */
    public function test_build_cooldown_text_inactive(): void {
        $this->assertSame('', round_presenter::build_cooldown_text(0));
    }

    /**
     * Tests that an active cooldown produces a non-empty formatted string.
     *
     * @covers \mod_playercross\local\round_presenter::build_cooldown_text
     * @return void
     */
    public function test_build_cooldown_text_active(): void {
        $this->assertNotSame('', round_presenter::build_cooldown_text(time() + 3600));
    }

    /**
     * Tests that a not-yet-finished round has no feedback message.
     *
     * @covers \mod_playercross\local\round_presenter::build_feedback_message
     * @return void
     */
    public function test_build_feedback_message_not_finished(): void {
        $this->assertSame('', round_presenter::build_feedback_message($this->make_state()));
    }

    /**
     * Tests that forfeited, timed-out, clues-exhausted, final-guessed and plain-
     * won/lost rounds each produce their own distinct message.
     *
     * @covers \mod_playercross\local\round_presenter::build_feedback_message
     * @return void
     */
    public function test_build_feedback_message_varies_by_outcome(): void {
        $forfeited = round_presenter::build_feedback_message($this->make_state(['finished' => true, 'forfeited' => true]));
        $timedout = round_presenter::build_feedback_message($this->make_state(['finished' => true, 'timedout' => true]));
        $cluesexhausted = round_presenter::build_feedback_message(
            $this->make_state(['finished' => true, 'cluesexhausted' => true])
        );
        $finalguessed = round_presenter::build_feedback_message(
            $this->make_state(['finished' => true, 'won' => true, 'finalguessed' => true])
        );
        $won = round_presenter::build_feedback_message($this->make_state(['finished' => true, 'won' => true]));
        $lost = round_presenter::build_feedback_message($this->make_state(['finished' => true]));

        $messages = [$forfeited, $timedout, $cluesexhausted, $finalguessed, $won, $lost];
        $this->assertSame($messages, array_unique($messages));
    }

    /**
     * Tests that the grading method info line is shown only when grading is enabled
     * and more than one round is possible.
     *
     * @covers \mod_playercross\local\round_presenter::build_grading_method_info
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
     * Tests that the grade-so-far summary is absent when there is no gradebook item yet.
     *
     * @covers \mod_playercross\local\round_presenter::build_grade_so_far
     * @return void
     */
    public function test_build_grade_so_far_no_grade_item(): void {
        $instance = $this->make_instance(['grade' => 0]);
        $user = $this->getDataGenerator()->create_user();

        $context = round_presenter::build_grade_so_far($instance, $user->id);

        $this->assertFalse($context['showgradesofar']);
    }

    /**
     * Tests that the grade-so-far summary surfaces the student's current computed
     * grade once a round has finished, matching what playercross_update_grades() writes.
     *
     * @covers \mod_playercross\local\round_presenter::build_grade_so_far
     * @return void
     */
    public function test_build_grade_so_far_shows_current_grade(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/playercross/lib.php');

        $instance = $this->make_instance(['grade' => 100, 'grademethod' => PLAYERCROSS_GRADE_HIGHEST]);
        $user = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($instance->id, 'escola');
        $modgenerator->create_attempt($instance->id, $user->id, $theme->id, ['score' => 80]);
        playercross_update_grades($instance, $user->id);

        $context = round_presenter::build_grade_so_far($instance, $user->id);

        $this->assertTrue($context['showgradesofar']);
        $this->assertStringContainsString('Highest grade', $context['gradesofarmessage']);
        $this->assertStringContainsString('80', $context['gradesofarmessage']);
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
     * @covers \mod_playercross\local\round_presenter::build_lobby_context
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
     * The lobby allows starting once the user's balance meets the required quantity.
     *
     * @covers \mod_playercross\local\round_presenter::build_lobby_context
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
     * @covers \mod_playercross\local\round_presenter::build_lobby_context
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
     * The lobby always shows the clues-this-round summary, driven by the puzzle state.
     *
     * @covers \mod_playercross\local\round_presenter::build_lobby_context
     * @return void
     */
    public function test_build_lobby_context_shows_clues_this_round(): void {
        $instance = $this->make_instance();
        $state = $this->make_state(['cluestotal' => 5]);
        $user = $this->getDataGenerator()->create_user();

        $context = round_presenter::build_lobby_context($instance, $state, $user->id);

        $this->assertStringContainsString('5', $context['cluesthisround']);
    }

    /**
     * timeleft stays 0 while the round has not started yet, even with a timer configured.
     *
     * @covers \mod_playercross\local\round_presenter::build_round_panel_context
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
     * @covers \mod_playercross\local\round_presenter::build_round_panel_context
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
     * Tests that the round-result context is structurally blank while the round is
     * active, never exposing the mystery phrase sitting in session state.
     *
     * @covers \mod_playercross\local\round_presenter::build_round_result_context
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
        $this->assertFalse($context['showgradesofar']);
    }

    /**
     * Tests that the round-result context reveals the mystery phrase once finished,
     * and computes the cooldown from the current instance settings rather than
     * session state.
     *
     * @covers \mod_playercross\local\round_presenter::build_round_result_context
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
        $this->assertNotSame('', $context['resultclueslabel']);
    }

    /**
     * Tests that changing cooldown_seconds after a round finished takes effect
     * immediately — the specific behaviour that motivates computing cooldown from the
     * DB instead of caching it in session state at the moment the round ended.
     *
     * @covers \mod_playercross\local\round_presenter::build_round_result_context
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
     * round is still hidden — including a clue-exclusive slot that never appears in
     * the mystery phrase at all (SCOPE.md §20.2 v1.8) — and withdrawn only once every
     * slot in the round is already revealed.
     *
     * @covers \mod_playercross\local\round_presenter::build_round_panel_context
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
            // one of those clue-exclusive letters.
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
     * @covers \mod_playercross\local\round_presenter::build_round_panel_context
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
     * Tests that a configured max_hints_per_round hides the hint button once the
     * student's own hint count reaches it, even though hidden slots remain (slots
     * 7..9 are still unrevealed under the default make_state() override below).
     *
     * @covers \mod_playercross\local\round_presenter::build_round_panel_context
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
     * The hint button shows the PlayerHUD balance/cost line, and canaffordhint is
     * false, while the user's balance is short of the required quantity.
     *
     * @covers \mod_playercross\local\round_presenter::build_round_panel_context
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
     * canaffordhint becomes true once the user's balance meets the required quantity.
     *
     * @covers \mod_playercross\local\round_presenter::build_round_panel_context
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
     * @covers \mod_playercross\local\round_presenter::build_round_panel_context
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
     * The keyboard's Ç key only shows up when the activity's own word pool actually
     * needs it — many languages never use the letter.
     *
     * @covers \mod_playercross\local\round_presenter::build_round_panel_context
     * @return void
     */
    public function test_build_round_panel_context_showcedilla_reflects_word_pool(): void {
        global $DB;
        $instance = $this->make_instance();
        $cm = (object)['id' => 5];
        $user = $this->getDataGenerator()->create_user();
        $state = $this->make_state();

        $without = round_presenter::build_round_panel_context($instance, $cm, $state, $user->id);
        $this->assertFalse($without['showcedilla']);

        $DB->insert_record('playercross_words', (object)[
            'playercrossid' => $instance->id,
            'word'          => 'cabeça',
            'concept'       => 'cabeça',
            'hint'          => 'cabeça',
            'source'        => 'manual',
            'glossaryid'    => 0,
            'approved'      => 1,
            'timecreated'   => time(),
            'timemodified'  => time(),
            'addedby'       => $user->id,
        ]);

        $with = round_presenter::build_round_panel_context($instance, $cm, $state, $user->id);
        $this->assertTrue($with['showcedilla']);
    }

    /**
     * Tests that the round result announces the PlayerHUD item granted for the win,
     * once configured and the round was actually won.
     *
     * @covers \mod_playercross\local\round_presenter::build_round_result_context
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
     * Tests that no grant label is shown when the round was lost, even with a
     * win-reward item configured.
     *
     * @covers \mod_playercross\local\round_presenter::build_round_result_context
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
