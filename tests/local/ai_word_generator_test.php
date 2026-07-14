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
 * parse_words() (response-shape parsing) and is_valid_term() (the safety
 * filter applied before anything is saved to the word pool) directly via
 * reflection, since both are intentionally kept protected: they are internal
 * parsing helpers, not part of the class's public contract.
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
     * The documented "words" wrapper is parsed into term/hint pairs.
     *
     * @covers \mod_playercross\local\ai_word_generator::parse_words
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
     * @covers \mod_playercross\local\ai_word_generator::parse_words
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
     * @covers \mod_playercross\local\ai_word_generator::parse_words
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
     * @covers \mod_playercross\local\ai_word_generator::parse_words
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
     * @covers \mod_playercross\local\ai_word_generator::parse_words
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
     * @covers \mod_playercross\local\ai_word_generator::parse_words
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
     * @covers \mod_playercross\local\ai_word_generator::parse_words
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
     * @covers \mod_playercross\local\ai_word_generator::parse_words
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
     * @covers \mod_playercross\local\ai_word_generator::is_valid_term
     * @return void
     */
    public function test_is_valid_term_accepts_single_alphabetic_word(): void {
        $this->assertTrue($this->is_valid_term('planeta'));
    }

    /**
     * An empty term is rejected.
     *
     * @covers \mod_playercross\local\ai_word_generator::is_valid_term
     * @return void
     */
    public function test_is_valid_term_rejects_empty_string(): void {
        $this->assertFalse($this->is_valid_term(''));
    }

    /**
     * A multi-word phrase is rejected — only single tokens are allowed.
     *
     * @covers \mod_playercross\local\ai_word_generator::is_valid_term
     * @return void
     */
    public function test_is_valid_term_rejects_multi_word_phrase(): void {
        $this->assertFalse($this->is_valid_term('sistema solar'));
    }

    /**
     * Terms containing digits or punctuation are rejected.
     *
     * @covers \mod_playercross\local\ai_word_generator::is_valid_term
     * @return void
     */
    public function test_is_valid_term_rejects_non_alphabetic_terms(): void {
        $this->assertFalse($this->is_valid_term('c3po'));
        $this->assertFalse($this->is_valid_term('planeta!'));
        $this->assertFalse($this->is_valid_term("plan'eta"));
    }
}
