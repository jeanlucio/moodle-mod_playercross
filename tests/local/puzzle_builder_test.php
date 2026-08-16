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
 * Unit tests for puzzle_builder.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Tests for puzzle_builder — the highest-risk class in the plugin (SCOPE.md §14),
 * since it has no equivalent logic in mod_playerwords to copy from. Requires database.
 *
 * @covers \mod_playercross\local\puzzle_builder
 */
final class puzzle_builder_test extends \advanced_testcase {
    /** @var \stdClass Course used to host test instances. */
    private \stdClass $course;

    /** @var \mod_playercross_generator Activity module generator. */
    private $modgenerator;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
    }

    /**
     * A term pool that jointly contains every distinct letter of the mystery phrase,
     * and no letter outside it, leaves no slot always-revealed and introduces no extra
     * slots beyond the theme's own — regardless of the order ties are broken in.
     *
     * @return void
     */
    public function test_build_round_full_slot_coverage(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_terms' => 3,
            'theme_min_length' => 6,
        ]);

        $this->modgenerator->create_word($instance->id, 'escola'); // Letters e,s,c,o,l,a — the theme candidate.
        $this->modgenerator->create_word($instance->id, 'casa');   // Covers c, a, s — all in escola.
        $this->modgenerator->create_word($instance->id, 'cole');   // Covers c, o, l, e — all in escola.
        $this->modgenerator->create_word($instance->id, 'sala');   // Covers s, a, l — all in escola.

        $puzzle = puzzle_builder::build_round($instance);

        $this->assertSame(6, $puzzle->slotcount);
        $this->assertCount(6, $puzzle->themeslots);
        $this->assertCount(3, $puzzle->terms);
        $this->assertSame([], $puzzle->alwaysrevealedslots);
    }

    /**
     * Two term words that share a letter which does not appear in the mystery phrase
     * at all must still be assigned the very same slot number for it — the round-wide
     * slot map (SCOPE.md §20.2 v1.7) covers every letter in the round, not just the
     * theme's own, so resolving one such term cross-reveals that letter directly in
     * the other, without going through the mystery phrase.
     *
     * @return void
     */
    public function test_build_round_shares_slot_for_letter_exclusive_to_terms(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_terms' => 2,
            'theme_min_length' => 4,
        ]);

        // Theme word "casa" (c,a,s) never contains the letter e; both term candidates
        // below do, and only those two candidates exist in the pool, so the pool
        // exactly fills num_terms and term selection order cannot affect this test.
        $this->modgenerator->create_word($instance->id, 'casa');
        $this->modgenerator->create_word($instance->id, 'mel');
        $this->modgenerator->create_word($instance->id, 'fez');

        $puzzle = puzzle_builder::build_round($instance);

        $this->assertCount(2, $puzzle->terms);
        $byword = [];
        foreach ($puzzle->terms as $term) {
            $byword[$term->word] = $term;
        }
        $this->assertArrayHasKey('mel', $byword);
        $this->assertArrayHasKey('fez', $byword);

        // The letter e is the second character of both "mel" and "fez".
        $eslotmel = $byword['mel']->slots[1];
        $eslotfez = $byword['fez']->slots[1];
        $this->assertSame($eslotmel, $eslotfez);
        $this->assertGreaterThan(3, $eslotmel); // Numbered after the theme's own c,a,s.

        $expecteddistinctletters = count(array_unique(array_merge(
            word_normalizer::chars('casa'),
            word_normalizer::chars('mel'),
            word_normalizer::chars('fez')
        )));
        $this->assertSame($expecteddistinctletters, $puzzle->slotcount);
    }

    /**
     * When no term in the pool contains a given theme letter, that letter's slot must
     * be marked always-revealed instead of blocking generation — the graceful
     * degradation rule from SCOPE.md §4.
     *
     * @return void
     */
    public function test_build_round_graceful_degradation(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_terms' => 3,
            'theme_min_length' => 6,
        ]);

        // Quixote contains q and x, letters none of the term words below ever use.
        $this->modgenerator->create_word($instance->id, 'quixote');
        $this->modgenerator->create_word($instance->id, 'bola');
        $this->modgenerator->create_word($instance->id, 'fita');
        $this->modgenerator->create_word($instance->id, 'dedo');

        $puzzle = puzzle_builder::build_round($instance);

        $this->assertCount(3, $puzzle->terms);
        $this->assertNotEmpty($puzzle->alwaysrevealedslots);

        // The uncoverable letters (q, x) must both resolve to always-revealed slots.
        // The theme word's default clue (see the generator's create_word()) is the
        // word itself, so the phrase here is that single word.
        $chars = word_normalizer::chars($puzzle->themewords[0]);
        $slotsbyletter = [];
        foreach ($chars as $position => $char) {
            $slotsbyletter[$char] = $puzzle->themeslots[$position];
        }
        $this->assertContains($slotsbyletter['q'], $puzzle->alwaysrevealedslots);
        $this->assertContains($slotsbyletter['x'], $puzzle->alwaysrevealedslots);
    }

    /**
     * With reveal_uncovered_slots disabled, an uncoverable theme letter stays hidden
     * instead of being marked always-revealed — the teacher-configurable opt-out from
     * the graceful degradation rule (SCOPE.md §4.4, v1.10).
     *
     * @return void
     */
    public function test_build_round_graceful_degradation_can_be_disabled(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_terms' => 3,
            'theme_min_length' => 6,
            'reveal_uncovered_slots' => 0,
        ]);

        // Same fixture as test_build_round_graceful_degradation(): q and x are never
        // covered by any of the three term candidates.
        $this->modgenerator->create_word($instance->id, 'quixote');
        $this->modgenerator->create_word($instance->id, 'bola');
        $this->modgenerator->create_word($instance->id, 'fita');
        $this->modgenerator->create_word($instance->id, 'dedo');

        $puzzle = puzzle_builder::build_round($instance);

        $this->assertCount(3, $puzzle->terms);
        $this->assertSame([], $puzzle->alwaysrevealedslots);
    }

    /**
     * A word picked as the theme concept exposes its own clue as the multi-word
     * mystery phrase (SCOPE.md §20.2 v1.9), flattened into one per-position slot
     * array across every word (no entry for the gaps between them) — not the word
     * itself, which becomes the concept caption instead, shown openly and never tiled.
     *
     * @return void
     */
    public function test_build_round_theme_phrase_comes_from_clue(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_terms' => 2,
            'theme_min_length' => 5,
            'min_length' => 3,
            'max_length' => 15,
        ]);

        // Words "casa" and "gato" default to a clue equal to themselves (4 letters,
        // see the generator's create_word()) — too short at theme_min_length 5, so
        // only "administracao" is eligible as the theme concept, and its pick is
        // deterministic.
        $this->modgenerator->create_word($instance->id, 'administracao', 'area de gestao');
        $this->modgenerator->create_word($instance->id, 'casa');
        $this->modgenerator->create_word($instance->id, 'gato');

        $puzzle = puzzle_builder::build_round($instance);

        $this->assertSame('administracao', $puzzle->themeconcept);
        $this->assertSame(['area', 'de', 'gestao'], $puzzle->themewords);
        // Words area (4 letters), de (2 letters) and gestao (6 letters) sum to 12
        // letters total, flattened.
        $this->assertCount(12, $puzzle->themeslots);
    }

    /**
     * A term word and the theme's own clue keep their original accented spelling
     * available (originalword, themeclue) alongside the normalized ones used for slot
     * matching (word, themewords) — matching stays accent-insensitive throughout (see
     * word_normalizer::normalize()), but the post-round reveal (round_presenter::
     * build_term_rows()/build_round_result_context()) needs the real spelling, which
     * would otherwise never survive past this method — see select_terms() and
     * build_round()'s own themeclue field.
     *
     * @return void
     */
    public function test_build_round_preserves_original_accented_spelling(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_terms' => 2,
            'theme_min_length' => 5,
            'min_length' => 3,
            'max_length' => 15,
        ]);

        // Theme: clue carries a real accent ("área"), too short to be picked as a term
        // itself since it is the only theme candidate (theme_min_length 5).
        $this->modgenerator->create_word($instance->id, 'administracao', 'área de gestão');
        // Term: the word itself carries a real accent.
        $this->modgenerator->create_word($instance->id, 'café');
        $this->modgenerator->create_word($instance->id, 'casa');

        $puzzle = puzzle_builder::build_round($instance);

        $this->assertSame('area de gestao', implode(' ', $puzzle->themewords));
        $this->assertSame('área de gestão', $puzzle->themeclue);

        $byword = [];
        foreach ($puzzle->terms as $term) {
            $byword[$term->word] = $term;
        }
        $this->assertArrayHasKey('cafe', $byword);
        $this->assertSame('café', $byword['cafe']->originalword);
    }

    /**
     * A pool smaller than num_terms + 1 approved words must never start a round.
     *
     * @return void
     */
    public function test_build_round_hard_failure_on_insufficient_pool(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_terms' => 5,
            'theme_min_length' => 6,
        ]);

        // Only 3 approved words total, but num_terms + 1 = 6 are required.
        $this->modgenerator->create_word($instance->id, 'escola');
        $this->modgenerator->create_word($instance->id, 'casa');
        $this->modgenerator->create_word($instance->id, 'lobo');

        $this->expectException(\moodle_exception::class);
        puzzle_builder::build_round($instance);
    }

    /**
     * PLAYERCROSS_WORDMODE_SHARED must derive the same theme word for the same round
     * number across independent calls — the determinism every student in the course
     * relies on to see the same puzzle.
     *
     * @return void
     */
    public function test_build_round_shared_wordmode_is_deterministic(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_terms' => 2,
            'theme_min_length' => 6,
            'wordmode' => PLAYERCROSS_WORDMODE_SHARED,
        ]);

        $this->modgenerator->create_word($instance->id, 'escola');
        $this->modgenerator->create_word($instance->id, 'floresta');
        $this->modgenerator->create_word($instance->id, 'casa');
        $this->modgenerator->create_word($instance->id, 'lobo');
        $this->modgenerator->create_word($instance->id, 'mel');

        $first = puzzle_builder::build_round($instance, 0);
        $second = puzzle_builder::build_round($instance, 0);

        $this->assertSame($first->themewordid, $second->themewordid);
    }

    /**
     * The crc32-based tie-break in the greedy term selection must be deterministic:
     * repeated builds over an identical pool always select the same terms in the same
     * order, never an arbitrary one.
     *
     * @return void
     */
    public function test_build_round_greedy_tie_break_is_deterministic(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'num_terms' => 2,
            'theme_min_length' => 6,
            'wordmode' => PLAYERCROSS_WORDMODE_SHARED,
        ]);

        // A single theme candidate keeps the theme pick itself deterministic, isolating
        // the term tie-break as the only remaining source of variation.
        $this->modgenerator->create_word($instance->id, 'escola');
        // Casa and cola both cover exactly 2 never-before-covered theme letters on
        // the first greedy pass (c,a and c,o,l,a respectively overlap identically in size).
        $this->modgenerator->create_word($instance->id, 'casa');
        $this->modgenerator->create_word($instance->id, 'cola');

        $first = puzzle_builder::build_round($instance, 0);
        $second = puzzle_builder::build_round($instance, 0);

        $this->assertSame(
            array_map(fn($term) => $term->wordid, $first->terms),
            array_map(fn($term) => $term->wordid, $second->terms)
        );
    }
}
