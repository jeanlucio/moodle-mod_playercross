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
 * Word normalization helper.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use core_text;

/**
 * Utility to normalize words consistently.
 */
class word_normalizer {
    /**
     * Normalizes one word: lowercased and accent-stripped.
     *
     * @param string $value Raw word.
     * @return string
     */
    public static function normalize(string $value): string {
        return core_text::strtolower(core_text::specialtoascii(core_text::strtolower(trim($value))));
    }

    /**
     * Whether a word is made up exclusively of letters — the same rule the game
     * itself enforces at play time (see words_repository::get_candidate_words()
     * and ::extract_candidate_words()). A word that fails this can be saved and
     * marked approved, but will never actually be drawn into a round.
     *
     * @param string $word Word text, already trimmed.
     * @return bool
     */
    public static function is_valid_charset(string $word): bool {
        return (bool)preg_match('/^[\p{L}]+$/u', $word);
    }

    /**
     * Splits a normalized word into its individual Unicode characters.
     *
     * Uses a regex-based split instead of str_split() because clue/theme words may
     * contain multi-byte UTF-8 characters that str_split() would tear across bytes.
     *
     * @param string $normalizedword Already-normalized word (see normalize()).
     * @return string[]
     */
    public static function chars(string $normalizedword): array {
        return preg_split('//u', $normalizedword, -1, PREG_SPLIT_NO_EMPTY);
    }
}
