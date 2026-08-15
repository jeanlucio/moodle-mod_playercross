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
 * Unit tests for ai_word_generator's untrusted-input handling.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Tests for ai_word_generator — no database or network access needed.
 *
 * The AI provider always returns raw, untrusted text. These tests exercise
 * parse_words() (response-shape parsing) and is_valid_term()/is_valid_hint()
 * (the safety filters applied before anything is saved to the word pool)
 * directly via reflection, since all three are intentionally kept protected:
 * they are internal parsing helpers, not part of the class's public contract.
 *
 * @covers \mod_playercross\local\ai_word_generator
 */
final class ai_word_generator_test extends \basic_testcase {
    /**
     * Invokes the protected static parse_words() method.
     *
     * @param string $responsetext Raw AI response text.
     * @return array
     */
    private function parse_words(string $responsetext): array {
        $method = new \ReflectionMethod(ai_word_generator::class, 'parse_words');
        $method->setAccessible(true);
        return $method->invoke(null, $responsetext);
    }

    /**
     * Invokes the protected static is_valid_term() method.
     *
     * @param string $term Candidate term.
     * @return bool
     */
    private function is_valid_term(string $term): bool {
        $method = new \ReflectionMethod(ai_word_generator::class, 'is_valid_term');
        $method->setAccessible(true);
        return $method->invoke(null, $term);
    }

    /**
     * Invokes the protected static is_valid_hint() method.
     *
     * @param string $hint Candidate hint.
     * @return bool
     */
    private function is_valid_hint(string $hint): bool {
        $method = new \ReflectionMethod(ai_word_generator::class, 'is_valid_hint');
        $method->setAccessible(true);
        return $method->invoke(null, $hint);
    }

    /**
     * Invokes the protected static build_prompt() method.
     *
     * @param string $topic Subject area or theme.
     * @param string $language Target language name.
     * @param int $count Number of words to request.
     * @param string[] $avoidwords Existing pool words the AI should not repeat.
     * @return string
     */
    private function build_prompt(string $topic, string $language, int $count, array $avoidwords = []): string {
        $method = new \ReflectionMethod(ai_word_generator::class, 'build_prompt');
        $method->setAccessible(true);
        return $method->invoke(null, $topic, $language, $count, $avoidwords);
    }

    /**
     * The documented "words" wrapper is parsed into term/hint pairs.
     *
     * @return void
     */
    public function test_parse_words_words_wrapper(): void {
        $response = '{"words":[{"term":"planeta","hint":"orbita uma estrela"}]}';

        $items = $this->parse_words($response);

        $this->assertSame([['term' => 'planeta', 'hint' => 'orbita uma estrela']], $items);
    }

    /**
     * The legacy "concepts" wrapper is still accepted.
     *
     * @return void
     */
    public function test_parse_words_legacy_concepts_wrapper(): void {
        $response = '{"concepts":[{"term":"atomo","hint":"unidade da materia"}]}';

        $items = $this->parse_words($response);

        $this->assertSame([['term' => 'atomo', 'hint' => 'unidade da materia']], $items);
    }

    /**
     * A bare JSON list (no wrapper key) is accepted.
     *
     * @return void
     */
    public function test_parse_words_bare_list(): void {
        $response = '[{"term":"rio","hint":"curso de agua"}]';

        $items = $this->parse_words($response);

        $this->assertSame([['term' => 'rio', 'hint' => 'curso de agua']], $items);
    }

    /**
     * Markdown code fences around the JSON payload are stripped before decoding.
     *
     * @return void
     */
    public function test_parse_words_strips_markdown_code_fence(): void {
        $fence = str_repeat(chr(96), 3);
        $response = $fence . "json\n" . '{"words":[{"term":"lua","hint":"satelite natural"}]}' . "\n" . $fence;

        $items = $this->parse_words($response);

        $this->assertSame([['term' => 'lua', 'hint' => 'satelite natural']], $items);
    }

    /**
     * Malformed JSON never throws; it degrades to an empty result.
     *
     * @return void
     */
    public function test_parse_words_malformed_json_returns_empty(): void {
        $items = $this->parse_words('not valid json at all {{{');

        $this->assertSame([], $items);
    }

    /**
     * A syntactically valid JSON value that is not an object/list wrapper
     * (e.g. a bare string or number) is rejected instead of crashing.
     *
     * @return void
     */
    public function test_parse_words_non_array_json_returns_empty(): void {
        $this->assertSame([], $this->parse_words('"just a string"'));
        $this->assertSame([], $this->parse_words('42'));
        $this->assertSame([], $this->parse_words('{"unexpectedkey":"value"}'));
    }

    /**
     * An entry using "definition" instead of "hint" still yields a hint value.
     *
     * @return void
     */
    public function test_parse_words_hint_falls_back_to_definition(): void {
        $response = '{"words":[{"term":"estrela","definition":"corpo celeste luminoso"}]}';

        $items = $this->parse_words($response);

        $this->assertSame([['term' => 'estrela', 'hint' => 'corpo celeste luminoso']], $items);
    }

    /**
     * A non-array entry inside the list is skipped rather than crashing.
     *
     * @return void
     */
    public function test_parse_words_skips_non_array_entries(): void {
        $response = '{"words":["not an object",{"term":"campo","hint":"area aberta"}]}';

        $items = $this->parse_words($response);

        $this->assertSame([['term' => 'campo', 'hint' => 'area aberta']], $items);
    }

    /**
     * A single alphabetic word is accepted.
     *
     * @return void
     */
    public function test_is_valid_term_accepts_single_alphabetic_word(): void {
        $this->assertTrue($this->is_valid_term('planeta'));
    }

    /**
     * An empty term is rejected.
     *
     * @return void
     */
    public function test_is_valid_term_rejects_empty_string(): void {
        $this->assertFalse($this->is_valid_term(''));
    }

    /**
     * A multi-word phrase is rejected — only single tokens are allowed.
     *
     * @return void
     */
    public function test_is_valid_term_rejects_multi_word_phrase(): void {
        $this->assertFalse($this->is_valid_term('sistema solar'));
    }

    /**
     * Terms containing digits or punctuation are rejected.
     *
     * @return void
     */
    public function test_is_valid_term_rejects_non_alphabetic_terms(): void {
        $this->assertFalse($this->is_valid_term('c3po'));
        $this->assertFalse($this->is_valid_term('planeta!'));
        $this->assertFalse($this->is_valid_term("plan'eta"));
    }

    /**
     * Regression test for the security audit finding (ported from mod_playerwords,
     * which the same bug was found and fixed in first): the AI response is
     * untrusted input, and nothing about the prompt guarantees a short reply — a
     * term longer than the word column (char(100)) used to reach add_ai_word()
     * unchecked and abort with a raw dml_write_exception. is_valid_term() must
     * reject it before generate_and_save() ever tries to save it.
     *
     * @return void
     */
    public function test_is_valid_term_rejects_term_longer_than_word_column(): void {
        $toolong = str_repeat('a', words_repository::WORD_COLUMN_MAX_LENGTH + 1);
        $this->assertFalse($this->is_valid_term($toolong));
    }

    /**
     * A term exactly at the word column length is still accepted — only strictly
     * longer terms are rejected.
     *
     * @return void
     */
    public function test_is_valid_term_accepts_term_at_word_column_limit(): void {
        $atlimit = str_repeat('a', words_repository::WORD_COLUMN_MAX_LENGTH);
        $this->assertTrue($this->is_valid_term($atlimit));
    }

    /**
     * A non-blank hint is accepted.
     *
     * @return void
     */
    public function test_is_valid_hint_accepts_non_blank_text(): void {
        $this->assertTrue($this->is_valid_hint('Corpo celeste que orbita uma estrela.'));
    }

    /**
     * An empty hint is rejected — the AI response is untrusted text, and the prompt
     * asking for a hint on every item is an instruction, not a guarantee. Unlike
     * mod_playerwords (where the hint is an optional assist), a PlayerCross clue's
     * hint is always shown to the student as the clue's own question, so a blank one
     * must never reach add_ai_word().
     *
     * @return void
     */
    public function test_is_valid_hint_rejects_empty_string(): void {
        $this->assertFalse($this->is_valid_hint(''));
    }

    /**
     * The prompt lists existing pool words as terms to avoid when any are given.
     *
     * @return void
     */
    public function test_build_prompt_includes_avoid_words(): void {
        $prompt = $this->build_prompt('astronomia', 'Portuguese', 5, ['planeta', 'estrela']);

        $this->assertStringContainsString('Do not repeat any of these words', $prompt);
        $this->assertStringContainsString('planeta, estrela', $prompt);
    }

    /**
     * No avoid-list section is added when there are no existing words to avoid.
     *
     * @return void
     */
    public function test_build_prompt_omits_avoid_section_when_empty(): void {
        $prompt = $this->build_prompt('astronomia', 'Portuguese', 5);

        $this->assertStringNotContainsString('Do not repeat', $prompt);
    }

    /**
     * Regression test: the prompt must explicitly tell the AI to keep accents,
     * diacritics and cedillas on the term field — is_valid_term() already accepts
     * them (it matches any Unicode letter, not just ASCII), but an unaccented
     * response like "pao" instead of "pão" still passes that check and slips into
     * the pool spelled wrong, since nothing downstream can correct a term the AI
     * itself already generated without its accent.
     *
     * @return void
     */
    public function test_build_prompt_asks_to_keep_accents(): void {
        $prompt = $this->build_prompt('comida brasileira', 'Portuguese', 5);

        $this->assertStringContainsString('accent', $prompt);
        $this->assertStringContainsString('do not strip them', $prompt);
    }
}
