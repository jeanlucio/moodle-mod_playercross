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
 * Unit tests for word_normalizer.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Tests for word_normalizer — pure logic, no database required.
 */
final class word_normalizer_test extends \basic_testcase {
    /**
     * Provides raw input strings and their expected normalized form.
     *
     * @return array[]
     */
    public static function normalize_provider(): array {
        return [
            'uppercase lowercased'         => ['GATO', 'gato'],
            'accent stripped acao'         => ['ação', 'acao'],
            'leading and trailing spaces'  => ['  gato  ', 'gato'],
            'combined uppercase accent'    => ['  AÇÃO  ', 'acao'],
            'cedilla and tilde'            => ['maçã', 'maca'],
            'initial accented letter'      => ['Ótimo', 'otimo'],
            'already normalized'           => ['bola', 'bola'],
            'accented e'                   => ['café', 'cafe'],
        ];
    }

    /**
     * Tests that normalize produces the expected lowercase accent-free string.
     *
     * @covers \mod_playercross\local\word_normalizer::normalize
     * @dataProvider normalize_provider
     * @param string $input    Raw input string.
     * @param string $expected Expected normalized string.
     * @return void
     */
    public function test_normalize(string $input, string $expected): void {
        $this->assertSame($expected, word_normalizer::normalize($input));
    }

    /**
     * Provides words and whether they are made up exclusively of letters.
     *
     * @return array[]
     */
    public static function is_valid_charset_provider(): array {
        return [
            'plain lowercase'   => ['gato', true],
            'accented letters'  => ['ação', true],
            'mixed case'        => ['GaTo', true],
            'contains digit'    => ['gato1', false],
            'contains space'    => ['gato preto', false],
            'contains hyphen'   => ['café-com-leite', false],
            'contains apostrophe' => ["d'agua", false],
            'empty string'      => ['', false],
        ];
    }

    /**
     * Tests that is_valid_charset accepts only strings made up of letters.
     *
     * @covers \mod_playercross\local\word_normalizer::is_valid_charset
     * @dataProvider is_valid_charset_provider
     * @param string $word Word to check.
     * @param bool $expected Expected result.
     * @return void
     */
    public function test_is_valid_charset(string $word, bool $expected): void {
        $this->assertSame($expected, word_normalizer::is_valid_charset($word));
    }

    /**
     * Provides already-normalized words and their expected character split.
     *
     * @return array[]
     */
    public static function chars_provider(): array {
        return [
            'plain ascii word'    => ['gato', ['g', 'a', 't', 'o']],
            'single character'    => ['a', ['a']],
            'repeated letters'    => ['escola', ['e', 's', 'c', 'o', 'l', 'a']],
            'already normalized multi-byte-free' => ['casa', ['c', 'a', 's', 'a']],
        ];
    }

    /**
     * Tests that chars() splits a normalized word into individual characters
     * without tearing multi-byte sequences (str_split() would corrupt these,
     * which is exactly why puzzle_builder::cipher_slots() relies on this method
     * instead of a plain byte split).
     *
     * @covers \mod_playercross\local\word_normalizer::chars
     * @dataProvider chars_provider
     * @param string $normalizedword Already-normalized word.
     * @param string[] $expected Expected character list.
     * @return void
     */
    public function test_chars(string $normalizedword, array $expected): void {
        $this->assertSame($expected, word_normalizer::chars($normalizedword));
    }

    /**
     * A word normalized first (stripping accents) then split into chars never
     * yields a multi-byte fragment — the exact invariant cipher_slots() depends on
     * to assign one slot number per distinct letter.
     *
     * @covers \mod_playercross\local\word_normalizer::chars
     * @return void
     */
    public function test_chars_after_normalize_never_splits_multibyte_sequences(): void {
        $normalized = word_normalizer::normalize('AÇÃO');
        $chars = word_normalizer::chars($normalized);

        foreach ($chars as $char) {
            $this->assertSame(1, mb_strlen($char), "Character '$char' should be a single codepoint.");
        }
        $this->assertSame(['a', 'c', 'a', 'o'], $chars);
    }
}
