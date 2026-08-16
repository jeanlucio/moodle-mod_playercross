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
 * Unit tests for words_repository.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Tests for words_repository — focused on the methods that diverge from the
 * mod_playerwords port (theme-word candidates/selection, themewordid-based
 * attempts lookups), since the rest is a direct, unmodified port. Requires database.
 *
 * @covers \mod_playercross\local\words_repository
 */
final class words_repository_test extends \advanced_testcase {
    /** @var \stdClass Course used to host test instances. */
    private \stdClass $course;

    /** @var \stdClass User used as the addedby FK on manually/AI-added words. */
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
     * Inserts a full playercross instance record directly (bypassing
     * playercross_add_instance()'s own auto-sync-on-create call to
     * sync_glossary_words()), with every column populated, for tests that need fields
     * beyond what mod_playercross_generator::create_instance() exposes (e.g. ->sources,
     * ->glossaryid, ->stopwords, used by sync_glossary_words()).
     *
     * @param array $overrides Field overrides.
     * @return \stdClass Full instance record.
     */
    private function make_full_instance(array $overrides = []): \stdClass {
        global $DB;
        $now = time();
        $defaults = [
            'course'                => $this->course->id,
            'name'                  => 'Test',
            'intro'                 => '',
            'introformat'           => 0,
            'sources'               => PLAYERCROSS_SOURCE_MANUAL | PLAYERCROSS_SOURCE_GLOSSARY,
            'glossaryid'            => 0,
            'stopwords'             => '',
            'min_length'            => 1,
            'max_length'            => 30,
            'theme_min_length'      => 1,
            'theme_max_length'      => 0,
            'num_terms'             => 5,
            'reveal_uncovered_slots' => 1,
            'win_condition'         => 1,
            'max_attempts_per_term' => 0,
            'timer_seconds'         => 0,
            'show_ranking'          => 1,
            'wordmode'              => PLAYERCROSS_WORDMODE_RANDOM,
            'max_rounds'            => 0,
            'max_hints_per_round'   => 0,
            'cooldown_seconds'      => 0,
            'completionrounds'      => 0,
            'grade'                 => 100,
            'gradepass'             => 0,
            'grademethod'           => 1,
            'hud_round_cost_item'   => 0,
            'hud_round_cost_qty'    => 1,
            'hud_hint_cost_item'    => 0,
            'hud_hint_cost_qty'     => 1,
            'hud_win_reward_item'   => 0,
            'hud_win_reward_qty'    => 1,
            'timecreated'           => $now,
            'timemodified'          => $now,
        ];
        $id = $DB->insert_record('playercross', (object)array_merge($defaults, $overrides));
        return $DB->get_record('playercross', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Creates a course glossary with one approved entry.
     *
     * @param string $concept Entry concept text.
     * @param string $definition Entry definition text.
     * @return array{0: \stdClass, 1: \stdClass} [glossary, entry]
     */
    private function make_glossary_entry(string $concept, string $definition): array {
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $this->course->id]);
        $entry = $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($glossary, [
            'concept'    => $concept,
            'definition' => $definition,
            'approved'   => 1,
        ]);
        return [$glossary, $entry];
    }

    /**
     * With theme_max_length left at its default (0 = unlimited), theme candidates
     * respect theme_min_length with no upper bound.
     *
     * @return void
     */
    public function test_get_theme_candidate_words_respects_min_length_only(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'theme_min_length' => 6,
            'min_length' => 3,
            'max_length' => 5,
        ]);
        $this->modgenerator->create_word($instance->id, 'gato');       // 4 letters, too short for theme.
        $this->modgenerator->create_word($instance->id, 'floresta');   // 8 letters, eligible for theme.

        $themecandidates = words_repository::get_theme_candidate_words($instance);
        $this->assertCount(1, $themecandidates);
        $this->assertSame('floresta', reset($themecandidates)->word);
    }

    /**
     * With theme_max_length set to a real ceiling, a clue whose total letter count
     * exceeds it is excluded from theme eligibility, even though it clears
     * theme_min_length comfortably.
     *
     * @return void
     */
    public function test_get_theme_candidate_words_respects_max_length_when_set(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'theme_min_length' => 6,
            'theme_max_length' => 10,
        ]);
        $this->modgenerator->create_word($instance->id, 'sol', 'floresta');        // 8 letters, eligible.
        $this->modgenerator->create_word($instance->id, 'mar', 'incrivelmente');   // 13 letters, too long.

        $themecandidates = words_repository::get_theme_candidate_words($instance);

        $this->assertCount(1, $themecandidates);
        $this->assertSame('sol', reset($themecandidates)->word);
    }

    /**
     * Term candidates are bounded by min_length/max_length, same rule as PlayerWords.
     *
     * @return void
     */
    public function test_get_candidate_words_respects_length_range(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'min_length' => 3,
            'max_length' => 5,
        ]);
        $this->modgenerator->create_word($instance->id, 'floresta'); // 8 letters, too long for a term.
        $this->modgenerator->create_word($instance->id, 'gato');     // 4 letters, within range.

        $termcandidates = words_repository::get_candidate_words($instance);
        $this->assertCount(1, $termcandidates);
        $this->assertSame('gato', reset($termcandidates)->word);
    }

    /**
     * Regression test ported from mod_playerwords: an unapproved word is never a term
     * candidate, regardless of otherwise fitting the length range.
     *
     * @return void
     */
    public function test_get_candidate_words_excludes_unapproved(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $pending = $this->modgenerator->create_word($instance->id, 'gato');
        $DB->set_field('playercross_words', 'approved', 0, ['id' => $pending->id]);

        $this->assertSame([], words_repository::get_candidate_words($instance));
    }

    /**
     * Regression test ported from mod_playerwords: a word containing non-letter
     * characters is never a term candidate.
     *
     * @return void
     */
    public function test_get_candidate_words_excludes_non_letter_chars(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instance->id, 'ga to');

        $this->assertSame([], words_repository::get_candidate_words($instance));
    }

    /**
     * A word saved with a blank clue is never a term candidate — a term's own phrase
     * (its clue) is always shown to the player as the question itself, so a blank one
     * would render as a term with nothing to ask. manualclue is optional on the
     * manage-words form (add_manual_word()/update_word() both accept an empty string),
     * so this is reachable through ordinary admin use, not just direct DB tampering —
     * the test generator itself defaults clue to the word when none is given, which is
     * exactly why this gap went untested until now.
     *
     * @return void
     */
    public function test_get_candidate_words_excludes_blank_clue(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $blank = $this->modgenerator->create_word($instance->id, 'teste');
        $DB->set_field('playercross_words', 'clue', '', ['id' => $blank->id]);
        $this->modgenerator->create_word($instance->id, 'gato');

        $termcandidates = words_repository::get_candidate_words($instance);

        $this->assertCount(1, $termcandidates);
        $this->assertSame('gato', reset($termcandidates)->word);
    }

    /**
     * pick_theme_word() returns null instead of throwing when the theme-candidate pool
     * is empty — the caller (ensure_round_state()) relies on this to degrade instead of
     * fataling for a misconfigured or under-stocked activity.
     *
     * @return void
     */
    public function test_pick_theme_word_returns_null_when_empty(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->assertNull(words_repository::pick_theme_word($instance));
    }

    /**
     * PLAYERCROSS_WORDMODE_SHARED must be deterministic across independent calls for
     * the same round number.
     *
     * @return void
     */
    public function test_pick_theme_word_shared_is_deterministic(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'theme_min_length' => 6,
            'wordmode' => PLAYERCROSS_WORDMODE_SHARED,
        ]);
        $this->modgenerator->create_word($instance->id, 'floresta');
        $this->modgenerator->create_word($instance->id, 'escritor');
        $this->modgenerator->create_word($instance->id, 'lanterna');

        $round0a = words_repository::pick_theme_word($instance, 0);
        $round0b = words_repository::pick_theme_word($instance, 0);
        $this->assertNotNull($round0a);
        $this->assertSame($round0a->word, $round0b->word);
    }

    /**
     * PLAYERCROSS_WORDMODE_SHARED cycles through the candidate pool as completedround
     * advances, wrapping back to the same theme word once the round count exceeds the
     * pool size — not just deterministic for a single fixed round number.
     *
     * @return void
     */
    public function test_pick_theme_word_shared_cycles(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'theme_min_length' => 6,
            'wordmode' => PLAYERCROSS_WORDMODE_SHARED,
        ]);
        $this->modgenerator->create_word($instance->id, 'floresta');
        $this->modgenerator->create_word($instance->id, 'escritor');
        $this->modgenerator->create_word($instance->id, 'lanterna');

        $round0 = words_repository::pick_theme_word($instance, 0);
        $round3 = words_repository::pick_theme_word($instance, 3);
        $this->assertNotNull($round0);
        $this->assertSame($round0->word, $round3->word);
    }

    /**
     * In random mode, an excluded theme id is avoided while an alternative exists.
     *
     * @return void
     */
    public function test_pick_theme_word_random_avoids_excluded_id_when_alternative_exists(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'theme_min_length' => 6,
        ]);
        $this->modgenerator->create_word($instance->id, 'floresta');
        $this->modgenerator->create_word($instance->id, 'escritor');
        $excludeid = (int)$DB->get_field('playercross_words', 'id', ['word' => 'floresta'], MUST_EXIST);

        for ($i = 0; $i < 10; $i++) {
            $word = words_repository::pick_theme_word($instance, 0, $excludeid);
            $this->assertNotNull($word);
            $this->assertSame('escritor', $word->word);
        }
    }

    /**
     * In random mode, the excluded theme id is allowed back in when it is the only
     * candidate left — refusing it would leave the round with no theme word at all,
     * blocking play entirely instead of just repeating the last one.
     *
     * @return void
     */
    public function test_pick_theme_word_random_allows_excluded_id_when_it_is_the_only_candidate(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'theme_min_length' => 6,
        ]);
        $this->modgenerator->create_word($instance->id, 'floresta');
        $excludeid = (int)$DB->get_field('playercross_words', 'id', ['word' => 'floresta'], MUST_EXIST);

        $word = words_repository::pick_theme_word($instance, 0, $excludeid);

        $this->assertNotNull($word);
        $this->assertSame('floresta', $word->word);
    }

    /**
     * get_last_played_theme_word_id() reads straight off playercross_attempts, with no
     * "reserved but unfinished" state to filter — every row already represents a
     * completed round (SCOPE.md §5).
     *
     * @return void
     */
    public function test_get_last_played_theme_word_id_returns_most_recent(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $user = $this->getDataGenerator()->create_user();
        $wordone = $this->modgenerator->create_word($instance->id, 'floresta');
        $wordtwo = $this->modgenerator->create_word($instance->id, 'escritor');

        $this->modgenerator->create_attempt($instance->id, $user->id, $wordone->id, ['timecreated' => time() - 100]);
        $this->modgenerator->create_attempt($instance->id, $user->id, $wordtwo->id, ['timecreated' => time()]);

        $this->assertSame((int)$wordtwo->id, words_repository::get_last_played_theme_word_id($instance, $user->id));
    }

    /**
     * With no attempts at all, the last played theme word id is 0.
     *
     * @return void
     */
    public function test_get_last_played_theme_word_id_returns_zero_when_none(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame(0, words_repository::get_last_played_theme_word_id($instance, $user->id));
    }

    /**
     * word_exists() is case-insensitive and scoped to the given activity instance.
     *
     * @return void
     */
    public function test_word_exists_is_case_insensitive_and_scoped(): void {
        $instancea = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instanceb = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instancea->id, 'Floresta');

        $this->assertTrue(words_repository::word_exists($instancea->id, 'floresta'));
        $this->assertFalse(words_repository::word_exists($instanceb->id, 'floresta'));
    }

    /**
     * word_exists() ignores a source: a word inserted as AI-pending is matched by a
     * check that carries no source of its own, which is exactly what a
     * duplicate-guard call needs regardless of where the colliding text came from.
     *
     * @return void
     */
    public function test_word_exists_matches_regardless_of_source(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        words_repository::add_ai_word($instance->id, $this->user->id, 'planeta', 'corpo celeste');

        $this->assertTrue(words_repository::word_exists($instance->id, 'planeta'));
    }

    /**
     * word_exists() ignores the excluded word id, so renaming a word to its own
     * current text is not reported as a collision with itself.
     *
     * @return void
     */
    public function test_word_exists_ignores_excluded_word_id(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $word = $this->modgenerator->create_word($instance->id, 'planeta');

        $this->assertFalse(words_repository::word_exists($instance->id, 'planeta', (int)$word->id));
    }

    /**
     * get_all_word_texts() returns every word for the instance regardless of source or
     * approval status, since the AI generator needs to avoid pending words too.
     *
     * @return void
     */
    public function test_get_all_word_texts_returns_every_word_regardless_of_status(): void {
        global $DB;

        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instance->id, 'planeta');
        $pending = $this->modgenerator->create_word($instance->id, 'cometa');
        $DB->set_field('playercross_words', 'approved', 0, ['id' => $pending->id]);
        words_repository::add_ai_word($instance->id, $this->user->id, 'estrela', 'corpo celeste');

        $texts = words_repository::get_all_word_texts($instance->id);

        sort($texts);
        $this->assertSame(['cometa', 'estrela', 'planeta'], $texts);
    }

    /**
     * get_all_word_texts() is scoped to the given instance and empty when it has no words.
     *
     * @return void
     */
    public function test_get_all_word_texts_scoped_to_instance(): void {
        $instancea = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instanceb = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instancea->id, 'planeta');

        $this->assertSame(['planeta'], words_repository::get_all_word_texts($instancea->id));
        $this->assertSame([], words_repository::get_all_word_texts($instanceb->id));
    }

    /**
     * Tests that has_cedilla_word is false when no approved word contains one.
     *
     * @return void
     */
    public function test_has_cedilla_word_false_when_absent(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instance->id, 'gato');

        $this->assertFalse(words_repository::has_cedilla_word($instance->id));
    }

    /**
     * Tests that has_cedilla_word is true once any approved word contains one.
     *
     * @return void
     */
    public function test_has_cedilla_word_true_when_present(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instance->id, 'gato');
        $this->modgenerator->create_word($instance->id, 'começar');

        $this->assertTrue(words_repository::has_cedilla_word($instance->id));
    }

    /**
     * Tests that has_cedilla_word ignores unapproved (pending) words.
     *
     * @return void
     */
    public function test_has_cedilla_word_ignores_unapproved(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        words_repository::add_ai_word($instance->id, $this->user->id, 'começar', 'iniciar algo');

        $this->assertFalse(words_repository::has_cedilla_word($instance->id));
    }

    /**
     * Tests that has_cedilla_word is scoped to its own activity, never a sibling one.
     *
     * @return void
     */
    public function test_has_cedilla_word_is_scoped_to_its_own_activity(): void {
        $instancea = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instanceb = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instanceb->id, 'começar');

        $this->assertFalse(words_repository::has_cedilla_word($instancea->id));
        $this->assertTrue(words_repository::has_cedilla_word($instanceb->id));
    }

    /**
     * A manually added word is trimmed, saved pre-approved, and attributed to its
     * author.
     *
     * @return void
     */
    public function test_add_manual_word_inserts_approved_record(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);

        words_repository::add_manual_word($instance->id, $this->user->id, '  gato  ', '  felino domestico  ');

        $record = $DB->get_record('playercross_words', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('gato', $record->word);
        $this->assertSame('gato', $record->concept);
        $this->assertSame('felino domestico', $record->clue);
        $this->assertSame('manual', $record->source);
        $this->assertEquals(1, $record->approved);
        $this->assertEquals($this->user->id, $record->addedby);
    }

    /**
     * An AI-generated word is saved as pending approval, never pre-approved.
     *
     * @return void
     */
    public function test_add_ai_word_inserts_pending_unapproved(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);

        words_repository::add_ai_word($instance->id, $this->user->id, 'planeta', 'corpo celeste');

        $record = $DB->get_record('playercross_words', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('ai', $record->source);
        $this->assertEquals(0, $record->approved);
    }

    /**
     * get_word_by_id() returns a word regardless of approval status, but only when
     * it belongs to the given activity instance.
     *
     * @return void
     */
    public function test_get_word_by_id_ignores_approval_but_is_scoped_to_instance(): void {
        $instancea = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instanceb = $this->modgenerator->create_instance(['course' => $this->course->id]);
        words_repository::add_ai_word($instancea->id, $this->user->id, 'pendente', 'ainda sem aprovacao');
        global $DB;
        $wordid = (int)$DB->get_field('playercross_words', 'id', ['playercrossid' => $instancea->id], MUST_EXIST);

        $found = words_repository::get_word_by_id($wordid, $instancea->id);
        $this->assertNotNull($found);
        $this->assertSame('pendente', $found->word);

        $this->assertNull(words_repository::get_word_by_id($wordid, $instanceb->id));
    }

    /**
     * Updating a word trims the new text and stamps timemodified.
     *
     * @return void
     */
    public function test_update_word_updates_fields_and_timemodified(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $word = $this->modgenerator->create_word($instance->id, 'antigo');
        $DB->set_field('playercross_words', 'timemodified', 100, ['id' => $word->id]);

        $result = words_repository::update_word((int)$word->id, $instance->id, '  novo  ', '  dica nova  ');

        $this->assertTrue($result);
        $record = $DB->get_record('playercross_words', ['id' => $word->id], '*', MUST_EXIST);
        $this->assertSame('novo', $record->word);
        $this->assertSame('novo', $record->concept);
        $this->assertSame('dica nova', $record->clue);
        $this->assertGreaterThan(100, $record->timemodified);
    }

    /**
     * A word cannot be updated through an instance id it does not belong to.
     *
     * @return void
     */
    public function test_update_word_returns_false_for_wrong_instance(): void {
        global $DB;
        $instancea = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instanceb = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $word = $this->modgenerator->create_word($instancea->id, 'palavra');

        $result = words_repository::update_word((int)$word->id, $instanceb->id, 'outra', 'dica');

        $this->assertFalse($result);
        $record = $DB->get_record('playercross_words', ['id' => $word->id], '*', MUST_EXIST);
        $this->assertSame('palavra', $record->word);
    }

    /**
     * A word is deleted when the given instance id actually owns it.
     *
     * @return void
     */
    public function test_delete_word_removes_when_instance_matches(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $word = $this->modgenerator->create_word($instance->id, 'apagar');

        $result = words_repository::delete_word((int)$word->id, $instance->id);

        $this->assertTrue($result);
        $this->assertFalse($DB->record_exists('playercross_words', ['id' => $word->id]));
    }

    /**
     * A word cannot be deleted through an instance id it does not belong to.
     *
     * @return void
     */
    public function test_delete_word_does_not_remove_when_instance_differs(): void {
        global $DB;
        $instancea = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instanceb = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $word = $this->modgenerator->create_word($instancea->id, 'protegida');

        $result = words_repository::delete_word((int)$word->id, $instanceb->id);

        $this->assertFalse($result);
        $this->assertTrue($DB->record_exists('playercross_words', ['id' => $word->id]));
    }

    /**
     * Bulk delete removes only the specified ids, leaving the rest untouched.
     *
     * @return void
     */
    public function test_delete_words_bulk_removes_only_specified_ids(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instance->id, 'um');
        $this->modgenerator->create_word($instance->id, 'dois');
        $this->modgenerator->create_word($instance->id, 'tres');
        $ids = $DB->get_fieldset_select(
            'playercross_words',
            'id',
            'playercrossid = :pid',
            ['pid' => $instance->id]
        );
        sort($ids);
        $todelete = array_slice($ids, 0, 2);
        $keep = array_slice($ids, 2);

        words_repository::delete_words_bulk($todelete, $instance->id);

        $remaining = $DB->get_fieldset_select(
            'playercross_words',
            'id',
            'playercrossid = :pid',
            ['pid' => $instance->id]
        );
        $this->assertEquals($keep, array_values($remaining));
    }

    /**
     * Bulk delete with an empty id list is a no-op.
     *
     * @return void
     */
    public function test_delete_words_bulk_noop_on_empty_array(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instance->id, 'fica');

        words_repository::delete_words_bulk([], $instance->id);

        $this->assertEquals(1, $DB->count_records('playercross_words', ['playercrossid' => $instance->id]));
    }

    /**
     * Bulk approve marks every given word approved and stamps timemodified.
     *
     * @return void
     */
    public function test_approve_words_bulk_sets_approved_and_timemodified(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        words_repository::add_ai_word($instance->id, $this->user->id, 'pendente1', 'dica1');
        words_repository::add_ai_word($instance->id, $this->user->id, 'pendente2', 'dica2');
        $ids = $DB->get_fieldset_select(
            'playercross_words',
            'id',
            'playercrossid = :pid',
            ['pid' => $instance->id]
        );
        $DB->set_field_select('playercross_words', 'timemodified', 0, 'playercrossid = :pid', ['pid' => $instance->id]);

        words_repository::approve_words_bulk($ids, $instance->id);

        $records = $DB->get_records('playercross_words', ['playercrossid' => $instance->id]);
        foreach ($records as $record) {
            $this->assertEquals(1, $record->approved);
            $this->assertGreaterThan(0, $record->timemodified);
        }
    }

    /**
     * The word pool listing respects the requested sort column and paginates: the
     * unpaginated (page=0, perpage=0) call returns everything, a page size splits
     * the set across pages, and the reported total always reflects the full count
     * regardless of how many rows the current page actually returned.
     *
     * @return void
     */
    public function test_get_recent_words_orders_and_paginates(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instance->id, 'alfa');
        $this->modgenerator->create_word($instance->id, 'beta');
        $this->modgenerator->create_word($instance->id, 'gama');

        $all = words_repository::get_recent_words($instance->id, 0, 0, 'word', 'ASC');
        $words = array_values(array_map(fn($record): string => $record->word, $all['rows']));
        $this->assertSame(['alfa', 'beta', 'gama'], $words);
        $this->assertSame(3, $all['total']);
        $this->assertFalse($all['isempty']);

        $firstpage = words_repository::get_recent_words($instance->id, 0, 2, 'word', 'ASC');
        $this->assertCount(2, $firstpage['rows']);
        $this->assertSame(3, $firstpage['total']);

        $secondpage = words_repository::get_recent_words($instance->id, 1, 2, 'word', 'ASC');
        $secondpagewords = array_values(array_map(fn($record): string => $record->word, $secondpage['rows']));
        $this->assertSame(['gama'], $secondpagewords);
        $this->assertSame(3, $secondpage['total']);
    }

    /**
     * Regression test for the security audit's defence-in-depth finding (ported from
     * mod_playerwords, which the same bug was found and fixed in first): get_recent_words()
     * used to interpolate $sort/$dir into ORDER BY trusting the caller's own allow-list
     * (correctly applied by managewords.php today, but not enforced by the function
     * itself). An out-of-list $sort/$dir — as a future caller forgetting to validate
     * would send — must fall back safely (id/DESC) instead of reaching the database as
     * raw SQL, proving the function is now safe by construction.
     *
     * @return void
     */
    public function test_get_recent_words_falls_back_on_invalid_sort_and_dir(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instance->id, 'alfa');
        $this->modgenerator->create_word($instance->id, 'beta');

        $malicious = words_repository::get_recent_words(
            $instance->id,
            0,
            0,
            'id; DROP TABLE mdl_playercross_words; --',
            'ASC; DROP TABLE mdl_playercross_words; --'
        );

        // Falls back to the default sort (id) and direction (DESC) rather than
        // fataling or executing the injected fragment — the table survives, proven by
        // the pool still returning both words afterwards.
        $this->assertSame(2, $malicious['total']);
        $stillqueryable = words_repository::get_recent_words($instance->id, 0, 0);
        $this->assertSame(2, $stillqueryable['total']);
    }

    /**
     * An empty word pool is reported as such.
     *
     * @return void
     */
    public function test_get_recent_words_reports_isempty_when_pool_is_empty(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);

        $wordpool = words_repository::get_recent_words($instance->id, 0, 0);

        $this->assertTrue($wordpool['isempty']);
        $this->assertSame(0, $wordpool['total']);
        $this->assertSame([], $wordpool['rows']);
    }

    /**
     * A word imported from a glossary reports that glossary's name in the listing.
     *
     * @return void
     */
    public function test_get_recent_words_includes_glossary_name(): void {
        [$glossary] = $this->make_glossary_entry('termo', 'definicao');
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id]);

        words_repository::sync_glossary_words($instance);

        $wordpool = words_repository::get_recent_words($instance->id, 0, 0);
        $row = reset($wordpool['rows']);
        $this->assertSame($glossary->name, $row->glossaryname);
    }

    /**
     * Sync is a no-op when the glossary source bit is not enabled.
     *
     * @return void
     */
    public function test_sync_glossary_words_disabled_when_source_bit_not_set(): void {
        global $DB;
        [$glossary] = $this->make_glossary_entry('planeta', 'corpo celeste');
        $instance = $this->make_full_instance([
            'sources'    => PLAYERCROSS_SOURCE_MANUAL,
            'glossaryid' => $glossary->id,
        ]);

        $imported = words_repository::sync_glossary_words($instance);

        $this->assertSame(0, $imported);
        $this->assertSame(0, $DB->count_records('playercross_words', ['playercrossid' => $instance->id]));
    }

    /**
     * A single-word glossary concept is imported as one approved word.
     *
     * @return void
     */
    public function test_sync_glossary_words_imports_single_word_concept(): void {
        global $DB;
        [$glossary] = $this->make_glossary_entry('planeta', 'corpo celeste que orbita uma estrela');
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id]);

        $imported = words_repository::sync_glossary_words($instance);

        $this->assertSame(1, $imported);
        $record = $DB->get_record('playercross_words', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('planeta', $record->word);
        $this->assertSame('glossary', $record->source);
        $this->assertEquals(1, $record->approved);
        $this->assertSame('corpo celeste que orbita uma estrela', $record->clue);
    }

    /**
     * Regression test for the security audit finding (ported from mod_playerwords,
     * which the same bug was found and fixed in first): an unmoderated glossary
     * concept (any student with mod/glossary:write can create one) longer than the
     * word column (char(100)) used to abort the whole sync with a raw
     * dml_write_exception — and since playercross_add_instance()/update_instance()
     * call sync_glossary_words() on every save, that also blocked the teacher from
     * saving the activity's settings at all while the offending entry existed. The
     * oversized token must now be silently skipped instead, leaving every other
     * candidate word imported normally.
     *
     * @return void
     */
    public function test_sync_glossary_words_skips_token_longer_than_word_column(): void {
        global $DB;
        $toolong = str_repeat('a', words_repository::WORD_COLUMN_MAX_LENGTH + 1);
        [$glossary] = $this->make_glossary_entry($toolong, 'definicao qualquer');
        $this->make_glossary_entry('planeta', 'corpo celeste que orbita uma estrela');
        $instance = $this->make_full_instance(['glossaryid' => 0]);

        $imported = words_repository::sync_glossary_words($instance);

        // Only the well-sized word was imported; the oversized one never reached
        // insert_record() (proven by count, since a fatal DB error would have
        // aborted the whole sync instead of returning a count at all).
        $this->assertSame(1, $imported);
        $this->assertFalse($DB->record_exists('playercross_words', ['playercrossid' => $instance->id, 'word' => $toolong]));
        $this->assertTrue($DB->record_exists('playercross_words', ['playercrossid' => $instance->id, 'word' => 'planeta']));
    }

    /**
     * A glossary entry saved with a blank definition is never imported as a term: its
     * clue is shown verbatim to the student as the term's own phrase
     * (round_play.mustache), so a blank one would be an unplayable term with nothing
     * to ask. Mirrors the same guard get_candidate_words() applies defensively for
     * words already in the pool — this one stops the word from being written at all.
     *
     * @return void
     */
    public function test_sync_glossary_words_skips_blank_definition(): void {
        global $DB;
        [$glossary] = $this->make_glossary_entry('teste', '');
        $this->make_glossary_entry('planeta', 'corpo celeste que orbita uma estrela');
        $instance = $this->make_full_instance(['glossaryid' => 0]);

        $imported = words_repository::sync_glossary_words($instance);

        $this->assertSame(1, $imported);
        $this->assertFalse($DB->record_exists('playercross_words', ['playercrossid' => $instance->id, 'word' => 'teste']));
        $this->assertTrue($DB->record_exists('playercross_words', ['playercrossid' => $instance->id, 'word' => 'planeta']));
    }

    /**
     * A later resync must not blank out a word's already-imported clue just because
     * the glossary entry's own definition was since edited down to empty — the
     * existing row is left untouched rather than overwritten with nothing to ask.
     *
     * @return void
     */
    public function test_sync_glossary_words_does_not_blank_existing_clue_on_resync(): void {
        global $DB;
        [$glossary, $entry] = $this->make_glossary_entry('planeta', 'corpo celeste que orbita uma estrela');
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id]);
        words_repository::sync_glossary_words($instance);

        $DB->set_field('glossary_entries', 'definition', '', ['id' => $entry->id]);
        $imported = words_repository::sync_glossary_words($instance);

        $this->assertSame(0, $imported);
        $record = $DB->get_record('playercross_words', ['playercrossid' => $instance->id, 'word' => 'planeta'], '*', MUST_EXIST);
        $this->assertSame('corpo celeste que orbita uma estrela', $record->clue);
    }

    /**
     * Regression test for the sanitization-order bug: a glossary definition whose
     * markup arrives entity-encoded (exactly how the Moodle editor stores an escaped
     * `<img src=x onerror=...>` typed literally into a FORMAT_HTML field) must not
     * survive as live HTML in the imported clue. The old strip_tags() then
     * html_entity_decode() order let the decode step re-introduce the very markup
     * strip_tags() was meant to remove; content_to_text() converts HTML to plain text
     * instead, so no tag survives regardless of encoding.
     *
     * The payload carries real text alongside the malicious markup (unlike a
     * markup-only payload, which strip_tags() would legitimately reduce to an empty
     * clue, and the blank-clue guard from test_sync_glossary_words_skips_blank_definition()
     * would then correctly skip importing altogether) — this test is specifically
     * about what survives sanitization, not about the blank-clue guard, so the
     * payload needs a real word left over for the import to even happen.
     *
     * @return void
     */
    public function test_sync_glossary_words_strips_entity_encoded_markup_from_clue(): void {
        global $DB;
        $payload = 'Corpo celeste. &lt;img src=x onerror=alert(document.domain)&gt;';
        [$glossary] = $this->make_glossary_entry('planeta', $payload);
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id]);

        words_repository::sync_glossary_words($instance);

        $record = $DB->get_record('playercross_words', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertStringContainsString('Corpo celeste', $record->clue);
        $this->assertStringNotContainsString('<img', $record->clue);
        $this->assertStringNotContainsString('onerror', $record->clue);
    }

    /**
     * A multi-word concept is split into one candidate word per token when no
     * stopwords are configured.
     *
     * @return void
     */
    public function test_sync_glossary_words_splits_multiword_concept_without_stopwords(): void {
        [$glossary] = $this->make_glossary_entry('sistema solar', 'conjunto de planetas');
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id]);

        $imported = words_repository::sync_glossary_words($instance);

        $this->assertSame(2, $imported);
        $wordpool = words_repository::get_recent_words($instance->id, 0, 0, 'word', 'ASC');
        $wordtexts = array_values(array_map(fn($record): string => $record->word, $wordpool['rows']));
        $this->assertSame(['sistema', 'solar'], $wordtexts);
    }

    /**
     * A configured stopword is dropped from a multi-word concept before import.
     *
     * @return void
     */
    public function test_sync_glossary_words_filters_configured_stopwords(): void {
        global $DB;
        [$glossary] = $this->make_glossary_entry('o brasil', 'pais da america do sul');
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id, 'stopwords' => 'o']);

        $imported = words_repository::sync_glossary_words($instance);

        $this->assertSame(1, $imported);
        $record = $DB->get_record('playercross_words', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('brasil', $record->word);
    }

    /**
     * Re-syncing after the glossary definition changed updates the existing word's
     * clue in place, instead of creating a duplicate.
     *
     * @return void
     */
    public function test_sync_glossary_words_updates_clue_on_resync(): void {
        global $DB;
        [$glossary, $entry] = $this->make_glossary_entry('planeta', 'definicao original');
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id]);

        words_repository::sync_glossary_words($instance);
        $DB->set_field('glossary_entries', 'definition', 'definicao atualizada', ['id' => $entry->id]);

        $imported = words_repository::sync_glossary_words($instance);

        $this->assertSame(0, $imported);
        $this->assertSame(1, $DB->count_records('playercross_words', ['playercrossid' => $instance->id]));
        $record = $DB->get_record('playercross_words', ['playercrossid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('definicao atualizada', $record->clue);
    }

    /**
     * When a previously imported glossary entry disappears, the next sync removes
     * the orphaned word, without touching manually added words.
     *
     * @return void
     */
    public function test_sync_glossary_words_removes_orphaned_words(): void {
        global $DB;
        [$glossary, $entry] = $this->make_glossary_entry('planeta', 'corpo celeste');
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id]);
        words_repository::add_manual_word($instance->id, $this->user->id, 'manual', 'palavra manual');

        words_repository::sync_glossary_words($instance);
        $this->assertSame(2, $DB->count_records('playercross_words', ['playercrossid' => $instance->id]));

        $DB->delete_records('glossary_entries', ['id' => $entry->id]);
        words_repository::sync_glossary_words($instance);

        $remaining = $DB->get_records('playercross_words', ['playercrossid' => $instance->id]);
        $this->assertCount(1, $remaining);
        $this->assertSame('manual', reset($remaining)->word);
    }

    /**
     * glossaryid = 0 imports from every glossary in the course, not just one.
     *
     * @return void
     */
    public function test_sync_glossary_words_zero_glossaryid_covers_all_course_glossaries(): void {
        $this->make_glossary_entry('planeta', 'corpo celeste');
        $this->make_glossary_entry('estrela', 'corpo luminoso');
        $instance = $this->make_full_instance(['glossaryid' => 0]);

        $imported = words_repository::sync_glossary_words($instance);

        $this->assertSame(2, $imported);
    }

    /**
     * Regression test for the security audit finding (ported from mod_playerwords,
     * which the same bug was found and fixed in first): sync_glossary_words() must
     * apply the same course-ownership check count_glossary_candidates() already
     * applies to client-supplied input — a glossaryid pointing at another course's
     * glossary is treated as absent (0 imported) instead of importing that course's
     * content into this activity's pool.
     *
     * @return void
     */
    public function test_sync_glossary_words_ignores_glossary_from_another_course(): void {
        global $DB;

        $othercourse = $this->getDataGenerator()->create_course();
        $foreignglossary = $this->getDataGenerator()->create_module('glossary', ['course' => $othercourse->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($foreignglossary, [
            'concept'    => 'segredo',
            'definition' => 'conteudo de outro curso',
            'approved'   => 1,
        ]);
        $instance = $this->make_full_instance(['glossaryid' => $foreignglossary->id]);

        $imported = words_repository::sync_glossary_words($instance);

        $this->assertSame(0, $imported);
        $this->assertSame(0, $DB->count_records('playercross_words', ['playercrossid' => $instance->id]));
    }

    /**
     * A glossary concept whose text already belongs to a manually added word is
     * skipped: no duplicate row is inserted, and the manual word's own clue is left
     * untouched.
     *
     * @return void
     */
    public function test_sync_glossary_words_skips_word_owned_by_another_source(): void {
        global $DB;
        [$glossary] = $this->make_glossary_entry('planeta', 'corpo celeste que orbita uma estrela');
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id]);
        words_repository::add_manual_word($instance->id, $this->user->id, 'planeta', 'dica do professor');

        $imported = words_repository::sync_glossary_words($instance);

        $this->assertSame(0, $imported);
        $records = $DB->get_records('playercross_words', ['playercrossid' => $instance->id]);
        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertSame('manual', $record->source);
        $this->assertSame('dica do professor', $record->clue);
    }

    /**
     * A multi-word glossary concept split into several sibling word rows is
     * reported by get_fragmented_concepts(), keyed by the shared concept text.
     *
     * @return void
     */
    public function test_get_fragmented_concepts_reports_split_multiword_concept(): void {
        [$glossary] = $this->make_glossary_entry('sistema solar', 'conjunto de planetas');
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id]);
        words_repository::sync_glossary_words($instance);

        $fragmented = words_repository::get_fragmented_concepts($instance->id);

        $this->assertSame(['sistema solar'], $fragmented);
    }

    /**
     * A single-word glossary concept never appears in get_fragmented_concepts(),
     * since it produced only one word row — nothing was actually split.
     *
     * @return void
     */
    public function test_get_fragmented_concepts_excludes_single_word_concept(): void {
        [$glossary] = $this->make_glossary_entry('planeta', 'corpo celeste que orbita uma estrela');
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id]);
        words_repository::sync_glossary_words($instance);

        $this->assertSame([], words_repository::get_fragmented_concepts($instance->id));
    }

    /**
     * Manual and AI words always store concept = word (a single token, enforced at
     * insert time), so they can never collide into a false positive here even when
     * two of them happen to share the same text as their concept.
     *
     * @return void
     */
    public function test_get_fragmented_concepts_ignores_non_glossary_sources(): void {
        $instance = $this->make_full_instance();
        words_repository::add_manual_word($instance->id, $this->user->id, 'planeta', 'dica manual');
        words_repository::add_ai_word($instance->id, $this->user->id, 'estrela', 'dica da ia');

        $this->assertSame([], words_repository::get_fragmented_concepts($instance->id));
    }

    /**
     * The fragmented-concept check is scoped to its own activity instance — a split
     * concept in one instance must not leak into another instance's report, even
     * when both import from glossaries in the same course.
     *
     * @return void
     */
    public function test_get_fragmented_concepts_is_scoped_to_its_own_instance(): void {
        [$glossary] = $this->make_glossary_entry('sistema solar', 'conjunto de planetas');
        $instance = $this->make_full_instance(['glossaryid' => $glossary->id]);
        $otherinstance = $this->make_full_instance(['glossaryid' => $glossary->id]);
        words_repository::sync_glossary_words($instance);

        $this->assertSame(['sistema solar'], words_repository::get_fragmented_concepts($instance->id));
        $this->assertSame([], words_repository::get_fragmented_concepts($otherinstance->id));
    }

    /**
     * A word with no issues never appears in get_inactive_words() — here it fits the
     * term length range even though it is shorter than the theme range, which is
     * enough on its own (the two ranges are checked with OR, see
     * words_repository::get_inactive_words()).
     *
     * @return void
     */
    public function test_get_inactive_words_empty_when_no_issues(): void {
        $instance = $this->make_full_instance(['min_length' => 4, 'max_length' => 6, 'theme_min_length' => 8]);
        $this->modgenerator->create_word($instance->id, 'boca');

        $this->assertSame([], words_repository::get_inactive_words($instance));
    }

    /**
     * An approved word outside both the term and the theme length range is reported
     * with reason "length".
     *
     * @return void
     */
    public function test_get_inactive_words_reports_length_mismatch(): void {
        $instance = $this->make_full_instance(['min_length' => 4, 'max_length' => 6, 'theme_min_length' => 8]);
        $this->modgenerator->create_word($instance->id, 'planeta');

        $inactive = words_repository::get_inactive_words($instance);

        $this->assertCount(1, $inactive);
        $this->assertSame('planeta', $inactive[0]['word']);
        $this->assertSame('length', $inactive[0]['reason']);
    }

    /**
     * A word too long for the term range is not reported when it is long enough for
     * the theme range instead — the two length checks in get_inactive_words() are
     * combined with OR, since a word only needs to be eligible for one of the two
     * roles (term or theme concept) to still be usable.
     *
     * @return void
     */
    public function test_get_inactive_words_word_valid_for_theme_only_is_not_reported(): void {
        $instance = $this->make_full_instance(['min_length' => 4, 'max_length' => 6, 'theme_min_length' => 8]);
        $this->modgenerator->create_word($instance->id, 'floresta');

        $this->assertSame([], words_repository::get_inactive_words($instance));
    }

    /**
     * An approved word saved with a character the game cannot use is reported with
     * reason "invalidchars" — this can only happen from data saved before charset
     * validation existed on the manual-word form, or inserted through another path.
     *
     * @return void
     */
    public function test_get_inactive_words_reports_invalid_charset(): void {
        $instance = $this->make_full_instance(['min_length' => 1, 'max_length' => 30]);
        $this->modgenerator->create_word($instance->id, 'café-com-leite');

        $inactive = words_repository::get_inactive_words($instance);

        $this->assertCount(1, $inactive);
        $this->assertSame('café-com-leite', $inactive[0]['word']);
        $this->assertSame('invalidchars', $inactive[0]['reason']);
    }

    /**
     * A pending (unapproved) word is never reported — it was never eligible to play
     * in the first place, so there is nothing "newly" inactive about it.
     *
     * @return void
     */
    public function test_get_inactive_words_ignores_unapproved_words(): void {
        $instance = $this->make_full_instance(['min_length' => 4, 'max_length' => 6, 'theme_min_length' => 8]);
        words_repository::add_ai_word($instance->id, $this->user->id, 'planeta', 'corpo celeste');

        $this->assertSame([], words_repository::get_inactive_words($instance));
    }

    /**
     * No attempts recorded means no entry in the draw-count map at all — a word
     * that was never drawn as the theme is absent, not present with a zero.
     *
     * @return void
     */
    public function test_get_theme_draw_counts_absent_when_never_drawn(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instance->id, 'boca');

        $this->assertSame([], words_repository::get_theme_draw_counts($instance->id));
    }

    /**
     * Every attempt row counts towards the total, regardless of outcome — the
     * attempts table only ever gains a row once a round finishes (SCOPE.md §5), so
     * there is no "pending" state to exclude here.
     *
     * @return void
     */
    public function test_get_theme_draw_counts_sums_all_attempts_regardless_of_outcome(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $word = $this->modgenerator->create_word($instance->id, 'boca');

        foreach ([1, 0, 0] as $won) {
            $this->modgenerator->create_attempt($instance->id, $this->user->id, (int)$word->id, ['completed' => $won]);
        }

        $counts = words_repository::get_theme_draw_counts($instance->id);

        $this->assertSame(3, $counts[(int)$word->id]);
    }

    /**
     * Draw counts are scoped to their own instance — attempts recorded against the
     * same word id in a different instance must never be added in.
     *
     * @return void
     */
    public function test_get_theme_draw_counts_is_scoped_to_its_own_instance(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $otherinstance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $word = $this->modgenerator->create_word($otherinstance->id, 'boca');
        $this->modgenerator->create_attempt($otherinstance->id, $this->user->id, (int)$word->id);

        $this->assertSame([], words_repository::get_theme_draw_counts($instance->id));
        $this->assertSame(1, words_repository::get_theme_draw_counts($otherinstance->id)[(int)$word->id]);
    }

    /**
     * Counts only the candidate words within the requested length range, for a
     * specific glossary — mirrors what sync_glossary_words() would actually import.
     *
     * @return void
     */
    public function test_count_glossary_candidates_counts_within_range(): void {
        [$glossary] = $this->make_glossary_entry('sistema solar', 'conjunto de planetas');

        $count = words_repository::count_glossary_candidates($this->course->id, $glossary->id, 5, 6);

        // Sistema (7 letters) is out of range; solar (5 letters) is in range.
        $this->assertSame(1, $count);
    }

    /**
     * glossaryid = 0 counts candidates across every glossary in the course, not just
     * the first one created.
     *
     * @return void
     */
    public function test_count_glossary_candidates_zero_covers_all_course_glossaries(): void {
        $this->make_glossary_entry('planeta', 'corpo celeste');
        $secondglossary = $this->getDataGenerator()->create_module('glossary', ['course' => $this->course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($secondglossary, [
            'concept'    => 'estrela',
            'definition' => 'corpo celeste luminoso',
            'approved'   => 1,
        ]);

        $count = words_repository::count_glossary_candidates($this->course->id, 0, 1, 30);

        $this->assertSame(2, $count);
    }

    /**
     * Two different concepts tokenising into the same word are only counted once —
     * the same deduplication sync_glossary_words() itself applies.
     *
     * @return void
     */
    public function test_count_glossary_candidates_deduplicates_repeated_tokens(): void {
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $this->course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_glossary');
        $generator->create_content($glossary, ['concept' => 'planeta', 'definition' => 'um', 'approved' => 1]);
        $generator->create_content($glossary, ['concept' => 'Planeta', 'definition' => 'dois', 'approved' => 1]);

        $count = words_repository::count_glossary_candidates($this->course->id, $glossary->id, 1, 30);

        $this->assertSame(1, $count);
    }

    /**
     * A glossary id that belongs to a different course is never counted, even
     * though the id itself is a real, valid glossary — the instance-isolation rule
     * for any externally-supplied id.
     *
     * @return void
     */
    public function test_count_glossary_candidates_ignores_glossary_from_another_course(): void {
        $othercourse = $this->getDataGenerator()->create_course();
        $foreignglossary = $this->getDataGenerator()->create_module('glossary', ['course' => $othercourse->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($foreignglossary, [
            'concept'    => 'planeta',
            'definition' => 'corpo celeste',
            'approved'   => 1,
        ]);

        $count = words_repository::count_glossary_candidates($this->course->id, $foreignglossary->id, 1, 30);

        $this->assertSame(0, $count);
    }

    /**
     * A course with no glossaries at all counts zero, without error.
     *
     * @return void
     */
    public function test_count_glossary_candidates_zero_when_no_glossaries_exist(): void {
        $emptycourse = $this->getDataGenerator()->create_course();

        $this->assertSame(0, words_repository::count_glossary_candidates($emptycourse->id, 0, 1, 30));
    }
}
