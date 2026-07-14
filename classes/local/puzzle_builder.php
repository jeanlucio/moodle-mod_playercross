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
 * Puzzle assembly for one PlayerCross round.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use moodle_exception;

/**
 * Builds a round's puzzle: picks the mystery phrase, ciphers its letters into slots,
 * greedily selects clue words that cover as many of those slots as possible, then
 * extends the slot numbering to every other distinct letter the selected clues bring
 * in — so a solved clue can cross-reveal a shared letter in another clue directly,
 * not only through the mystery phrase.
 *
 * See SCOPE.md §4 and §17 for the full rationale behind the linear (non-spatial) design:
 * a slot identifies a distinct letter, not a physical grid position, so any word can
 * contribute letters to any slot without a 2D placement algorithm.
 */
class puzzle_builder {
    /**
     * Assembles a full puzzle for a new round.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $completedround Number of rounds the student has already completed,
     *     used for deterministic theme selection in PLAYERCROSS_WORDMODE_SHARED.
     * @param int $excludethemeid Theme word id to avoid repeating immediately, 0 for none.
     * @return \stdClass Puzzle state: themewordid, themeword, themeslots, slotcount,
     *     clues (wordid, word, hint, slots — one slot number per character position,
     *     round-wide, not just theme letters) and alwaysrevealedslots.
     * @throws moodle_exception If the approved pool cannot support num_clues clues, or has
     *     no word eligible as the mystery phrase.
     */
    public static function build_round(
        \stdClass $instance,
        int $completedround = 0,
        int $excludethemeid = 0
    ): \stdClass {
        global $DB;

        $numclues = (int)$instance->num_clues;

        $totalapproved = $DB->count_records('playercross_words', [
            'playercrossid' => $instance->id,
            'approved' => 1,
        ]);
        if ($totalapproved < $numclues + 1) {
            throw new moodle_exception('error_insufficientpool', 'mod_playercross', '', $numclues + 1);
        }

        $themeword = words_repository::pick_theme_word($instance, $completedround, $excludethemeid);
        if ($themeword === null) {
            throw new moodle_exception('error_insufficientpool', 'mod_playercross', '', $numclues + 1);
        }

        $normalizedtheme = word_normalizer::normalize($themeword->word);
        [$themeslots, $themeslotsbyletter] = self::cipher_slots($normalizedtheme);

        $cluecandidates = array_values(array_filter(
            words_repository::get_candidate_words($instance),
            fn($candidate) => (int)$candidate->id !== (int)$themeword->id
        ));

        // Clue selection still greedily maximizes coverage of the theme's own letters
        // only (themeslotsbyletter) — the goal of the selection step is still to pick
        // clues that help crack the mystery phrase, not merely to pick clues that share
        // letters with each other.
        [$selectedclues] = self::select_clues(
            $cluecandidates,
            $themeslotsbyletter,
            $numclues,
            (int)$instance->id
        );

        // Once clues are selected, every distinct letter across the whole round (theme
        // plus every selected clue, not just the theme) gets its own slot number, so a
        // solved clue can cross-reveal a shared letter directly in another clue too —
        // not only via the mystery phrase (SCOPE.md §20.2 v1.7).
        $slotsbyletter = self::expand_slots_by_letter($themeslotsbyletter, $selectedclues);

        $coveredslots = [];
        foreach ($selectedclues as $clue) {
            $clue->slots = self::word_slot_positions($clue->word, $slotsbyletter);
            $coveredslots = array_merge($coveredslots, $clue->slots);
        }
        $coveredslots = array_values(array_unique($coveredslots));

        $alwaysrevealedslots = array_values(array_diff(array_values($slotsbyletter), $coveredslots));
        sort($alwaysrevealedslots);

        return (object)[
            'themewordid' => (int)$themeword->id,
            'themeword' => $normalizedtheme,
            'themeslots' => $themeslots,
            'slotcount' => count($slotsbyletter),
            'clues' => $selectedclues,
            'alwaysrevealedslots' => $alwaysrevealedslots,
        ];
    }

    /**
     * Assigns a sequential slot number to each distinct letter of the mystery phrase,
     * in order of first appearance.
     *
     * The slot identifies a letter, not a position: every occurrence of that letter,
     * anywhere in the phrase, shares the same slot number (see SCOPE.md §17 trade-off).
     *
     * @param string $normalizedtheme Already-normalized theme word (see word_normalizer::normalize()).
     * @return array{0: int[], 1: array<string, int>} Per-position slot numbers, and the
     *     letter => slot number map.
     */
    private static function cipher_slots(string $normalizedtheme): array {
        $slotsbyletter = [];
        $themeslots = [];
        $nextslot = 1;

        foreach (word_normalizer::chars($normalizedtheme) as $char) {
            if (!isset($slotsbyletter[$char])) {
                $slotsbyletter[$char] = $nextslot++;
            }
            $themeslots[] = $slotsbyletter[$char];
        }

        return [$themeslots, $slotsbyletter];
    }

    /**
     * Returns the distinct theme slot numbers a word's letters would reveal.
     *
     * @param string $normalizedword Already-normalized clue word.
     * @param int[] $slotsbyletter Letter => slot number map from the theme word (string keys).
     * @return int[] Distinct slot numbers, unsorted.
     */
    private static function word_slot_coverage(string $normalizedword, array $slotsbyletter): array {
        $slots = [];
        foreach (array_unique(word_normalizer::chars($normalizedword)) as $char) {
            if (isset($slotsbyletter[$char])) {
                $slots[] = $slotsbyletter[$char];
            }
        }
        return array_values(array_unique($slots));
    }

    /**
     * Extends the theme's own letter => slot map with any additional letters
     * introduced by the selected clue words, so every letter in the round — not just
     * the mystery phrase's own — ends up with a slot number. New letters are numbered
     * in order of first appearance across the selected clues, continuing straight
     * after the theme's own highest slot number.
     *
     * @param array $themeslotsbyletter Letter => slot number map for the theme word alone.
     * @param \stdClass[] $selectedclues Selected clues, each with a normalized ->word.
     * @return array Letter => slot number map covering the theme plus every selected clue.
     */
    private static function expand_slots_by_letter(array $themeslotsbyletter, array $selectedclues): array {
        $slotsbyletter = $themeslotsbyletter;
        $nextslot = count($slotsbyletter) + 1;

        foreach ($selectedclues as $clue) {
            foreach (word_normalizer::chars($clue->word) as $char) {
                if (!isset($slotsbyletter[$char])) {
                    $slotsbyletter[$char] = $nextslot++;
                }
            }
        }

        return $slotsbyletter;
    }

    /**
     * Returns a word's per-position slot numbers, one per character, using the
     * round-wide letter => slot map built by expand_slots_by_letter().
     *
     * @param string $normalizedword Already-normalized word.
     * @param array $slotsbyletter Letter => slot number map covering every letter in the word.
     * @return int[] One slot number per character position.
     */
    private static function word_slot_positions(string $normalizedword, array $slotsbyletter): array {
        return array_map(
            fn($char) => $slotsbyletter[$char],
            word_normalizer::chars($normalizedword)
        );
    }

    /**
     * Greedily selects up to $numclues words, each step picking the candidate that
     * reveals the most theme slots not yet covered by an already-selected clue.
     *
     * Ties are broken deterministically via crc32, the same mechanism used elsewhere
     * in the ecosystem for reproducible pseudo-random ordering (see
     * words_repository::pick_theme_word()). If the clue pool is smaller than
     * $numclues, fewer clues are simply returned — only the total approved pool size
     * is a hard failure (checked earlier in build_round()), not the length-filtered
     * clue pool. Selection is scored purely against the theme's own letters (not the
     * round-wide slot map, which does not exist yet at this point) — the goal here is
     * still to pick clues that help crack the mystery phrase.
     *
     * @param array $cluecandidates Candidate word records (id, word, hint, concept).
     * @param int[] $slotsbyletter Letter => slot number map from the theme word (string keys).
     * @param int $numclues Maximum number of clues to select.
     * @param int $instanceid Activity instance id, used to seed the tie-break order.
     * @return array{0: \stdClass[]} Selected clues (wordid, word, hint), their own
     *     ->slots not yet assigned — see build_round(), which fills it in once the
     *     round-wide slot map exists.
     */
    private static function select_clues(
        array $cluecandidates,
        array $slotsbyletter,
        int $numclues,
        int $instanceid
    ): array {
        $pool = [];
        foreach ($cluecandidates as $candidate) {
            $normalizedword = word_normalizer::normalize($candidate->word);
            $pool[] = (object)[
                'wordid' => (int)$candidate->id,
                'word' => $normalizedword,
                'hint' => (string)($candidate->hint ?? ''),
                'coverage' => self::word_slot_coverage($normalizedword, $slotsbyletter),
            ];
        }

        $selected = [];
        $covered = [];

        while (count($selected) < $numclues && $pool !== []) {
            $bestkey = null;
            $bestnewcount = -1;
            $besttiebreak = null;

            foreach ($pool as $key => $candidate) {
                $newcount = count(array_diff($candidate->coverage, $covered));
                $tiebreak = crc32($instanceid . '_' . $candidate->wordid);
                if ($newcount > $bestnewcount || ($newcount === $bestnewcount && $tiebreak < $besttiebreak)) {
                    $bestnewcount = $newcount;
                    $besttiebreak = $tiebreak;
                    $bestkey = $key;
                }
            }

            $chosen = $pool[$bestkey];
            unset($pool[$bestkey]);
            $selected[] = (object)[
                'wordid' => $chosen->wordid,
                'word' => $chosen->word,
                'hint' => $chosen->hint,
            ];
            $covered = array_values(array_unique(array_merge($covered, $chosen->coverage)));
        }

        return [$selected];
    }
}
