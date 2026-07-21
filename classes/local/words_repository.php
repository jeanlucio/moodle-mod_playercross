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
 * Data access layer for playercross words.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use core_text;

/**
 * Repository for words used by the activity.
 *
 * Ported from mod_playerwords\local\words_repository, same method signatures and
 * behaviour except where the puzzle mechanic genuinely differs: theme-word candidates
 * are filtered by theme_min_length/theme_max_length (its own range, the latter 0 for
 * unlimited) instead of the min_length/max_length range used for clue words, and the
 * attempts table tracks one theme word per round (themewordid) instead of one word per
 * round.
 */
class words_repository {
    /**
     * Splits a glossary concept into individual candidate words, ignoring given stopwords.
     *
     * Single-word concepts are returned as-is. For multi-word concepts each
     * non-stopword token becomes a separate candidate. If all tokens are stopwords,
     * or if no stopwords are given, every token is returned.
     *
     * @param string $concept Raw concept string from a glossary entry.
     * @param string $stopwordsraw Comma-separated words to ignore, as configured on the activity.
     * @return string[]
     */
    public static function extract_candidate_words(string $concept, string $stopwordsraw = ''): array {
        $tokens = preg_split('/\s+/u', $concept, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_values(array_filter($tokens, fn($t) => word_normalizer::is_valid_charset($t)));
        if ($tokens === []) {
            return [];
        }
        if (count($tokens) === 1) {
            return $tokens;
        }
        $stopwords = [];
        if ($stopwordsraw !== '') {
            foreach (explode(',', $stopwordsraw) as $w) {
                $w = core_text::strtolower(trim($w));
                if ($w !== '') {
                    $stopwords[] = $w;
                }
            }
        }
        if (empty($stopwords)) {
            return $tokens;
        }
        $filtered = array_values(array_filter(
            $tokens,
            fn($t) => !in_array(core_text::strtolower($t), $stopwords, true)
        ));
        return $filtered !== [] ? $filtered : $tokens;
    }

    /**
     * Returns approved words eligible as clues: matching the instance's configured length range.
     *
     * @param \stdClass $instance Activity instance.
     * @return array
     */
    public static function get_candidate_words(\stdClass $instance): array {
        global $DB;

        $records = $DB->get_records_select(
            'playercross_words',
            'playercrossid = :playercrossid AND approved = :approved',
            [
                'playercrossid' => $instance->id,
                'approved' => 1,
            ],
            '',
            'id, word, hint, concept'
        );

        $candidates = [];
        foreach ($records as $record) {
            $word = trim($record->word);
            $wordlength = core_text::strlen($word);
            if ($wordlength < (int)$instance->min_length || $wordlength > (int)$instance->max_length) {
                continue;
            }
            if (!word_normalizer::is_valid_charset($word)) {
                continue;
            }
            $candidates[] = $record;
        }

        return $candidates;
    }

    /**
     * Returns approved words eligible as the theme concept: their own hint normalizes
     * to at least one letter-only word, and the phrase's total letter count (summed
     * across every word, spaces excluded) is at least theme_min_length long — and, if
     * theme_max_length is set (non-zero), no more than that. The concept's own word is
     * shown openly as a caption, never tiled (SCOPE.md §20.2 v1.9) — the mystery phrase
     * to guess is the hint — so the word's own length no longer matters for
     * eligibility.
     *
     * theme_max_length exists purely as a teacher-facing pacing/screen-size control —
     * a longer hint is never unplayable, it simply produces more distinct letter slots
     * for puzzle_builder to cover with more clues, and mod_playercross/round_panel
     * already wraps arbitrarily long words onto multiple lines without overflowing.
     *
     * @param \stdClass $instance Activity instance.
     * @return array
     */
    public static function get_theme_candidate_words(\stdClass $instance): array {
        global $DB;

        $records = $DB->get_records_select(
            'playercross_words',
            'playercrossid = :playercrossid AND approved = :approved',
            [
                'playercrossid' => $instance->id,
                'approved' => 1,
            ],
            '',
            'id, word, hint, concept'
        );

        $thememaxlength = (int)($instance->theme_max_length ?? 0);

        $candidates = [];
        foreach ($records as $record) {
            $phrasewords = word_normalizer::normalize_phrase((string)$record->hint);
            if ($phrasewords === []) {
                continue;
            }

            $letters = array_sum(array_map(
                fn($word) => count(word_normalizer::chars($word)),
                $phrasewords
            ));
            if ($letters < (int)$instance->theme_min_length) {
                continue;
            }
            if ($thememaxlength > 0 && $letters > $thememaxlength) {
                continue;
            }

            $candidates[] = $record;
        }

        return $candidates;
    }

    /**
     * Picks one theme word for a new round according to the configured word mode.
     *
     * In WORDMODE_SHARED mode all students receive the same theme word for each round
     * number: round N uses index (completedround + instanceid) % total, cycling
     * silently when the candidate list is exhausted.
     *
     * In WORDMODE_RANDOM mode, $excludethemeid (typically the student's most recently
     * played theme word) is left out of the draw so the same phrase cannot appear twice
     * in a row — unless it is the only candidate left, in which case it is allowed back
     * in rather than blocking play.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $completedround Number of rounds the student has already completed.
     * @param int $excludethemeid Theme word id to avoid repeating immediately, 0 for none.
     * @return \stdClass|null
     */
    public static function pick_theme_word(
        \stdClass $instance,
        int $completedround = 0,
        int $excludethemeid = 0
    ): ?\stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/playercross/lib.php');

        $candidates = self::get_theme_candidate_words($instance);
        if ($candidates === []) {
            return null;
        }

        $wordmode = (int)($instance->wordmode ?? PLAYERCROSS_WORDMODE_RANDOM);

        if ($wordmode === PLAYERCROSS_WORDMODE_SHARED) {
            $instanceseed = (int)$instance->id;
            usort($candidates, function ($a, $b) use ($instanceseed) {
                return crc32($instanceseed . '_' . $a->id) <=> crc32($instanceseed . '_' . $b->id);
            });
            $index = $completedround % count($candidates);
            return $candidates[$index];
        }

        if ($excludethemeid > 0 && count($candidates) > 1) {
            $fresh = array_values(array_filter($candidates, fn($c) => (int)$c->id !== $excludethemeid));
            if ($fresh !== []) {
                $candidates = $fresh;
            }
        }

        $index = random_int(0, count($candidates) - 1);
        return $candidates[$index];
    }

    /**
     * Returns the theme word id from the student's most recently completed round, 0 if none.
     *
     * Unlike PlayerWords, playercross_attempts is append-only and only ever gains a row
     * once a round is fully finished (see SCOPE.md §5) — there is no "reserved but not
     * finished" state to filter out here.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $userid User id.
     * @return int
     */
    public static function get_last_played_theme_word_id(\stdClass $instance, int $userid): int {
        global $DB;

        $themewordid = $DB->get_field_sql(
            "SELECT themewordid FROM {playercross_attempts}"
            . " WHERE playercrossid = :pid AND userid = :uid"
            . " ORDER BY timecreated DESC",
            ['pid' => $instance->id, 'uid' => $userid],
            IGNORE_MULTIPLE
        );

        return $themewordid ? (int)$themewordid : 0;
    }

    /**
     * Gets one approved word by id and activity.
     *
     * @param int $wordid Word id.
     * @param int $instanceid Activity instance id.
     * @return \stdClass|null
     */
    public static function get_approved_word_by_id(int $wordid, int $instanceid): ?\stdClass {
        global $DB;

        $word = $DB->get_record(
            'playercross_words',
            [
                'id' => $wordid,
                'playercrossid' => $instanceid,
                'approved' => 1,
            ],
            'id, word, hint, concept',
            IGNORE_MISSING
        );

        return $word ?: null;
    }

    /**
     * Whether any approved word in this activity contains a cedilla (ç).
     *
     * Used to decide whether the on-screen keyboard needs its extra Ç key at all —
     * word matching already ignores accents (word_normalizer::normalize()), so hiding
     * the key never blocks a correct guess; it is purely a decluttering decision.
     *
     * @param int $instanceid Activity instance id.
     * @return bool
     */
    public static function has_cedilla_word(int $instanceid): bool {
        global $DB;

        $select = 'playercrossid = :instanceid AND approved = 1 AND ' . $DB->sql_like('word', ':pattern', false);

        return $DB->record_exists_select('playercross_words', $select, [
            'instanceid' => $instanceid,
            'pattern' => '%ç%',
        ]);
    }

    /**
     * Whether a word with the given text already exists in this activity's pool.
     *
     * Case-insensitive, scoped to the activity and checked across every source (manual,
     * glossary, AI) so the same text is never approved twice under a different origin.
     *
     * @param int $instanceid Activity instance id.
     * @param string $word Word text to check.
     * @param int $excludewordid Word id to ignore, used when renaming an existing word.
     * @return bool
     */
    public static function word_exists(int $instanceid, string $word, int $excludewordid = 0): bool {
        global $DB;

        $target = core_text::strtolower(trim($word));
        $records = $DB->get_records_select(
            'playercross_words',
            'playercrossid = :instanceid',
            ['instanceid' => $instanceid],
            '',
            'id, word'
        );
        foreach ($records as $record) {
            if ((int)$record->id !== $excludewordid && core_text::strtolower($record->word) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inserts one manual word as approved.
     *
     * @param int $instanceid Activity instance id.
     * @param int $userid User id.
     * @param string $word Word text.
     * @param string $hint Optional hint.
     * @return void
     */
    public static function add_manual_word(int $instanceid, int $userid, string $word, string $hint): void {
        global $DB;

        $record = (object)[
            'playercrossid' => $instanceid,
            'word' => trim($word),
            'concept' => trim($word),
            'hint' => trim($hint),
            'source' => 'manual',
            'approved' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'addedby' => $userid,
        ];
        $DB->insert_record('playercross_words', $record);
    }

    /**
     * Imports approved glossary entries into the word pool for a given activity instance.
     *
     * Existing glossary-sourced words (matched case-insensitively by concept) have their hint
     * updated. New words are inserted as approved. A word whose text already belongs to a
     * manual or AI-sourced entry is skipped instead, leaving that entry untouched — glossary
     * sync never overwrites a word the teacher (or the AI flow) already owns.
     *
     * @param \stdClass $instance Activity instance.
     * @return int Number of new words imported.
     */
    public static function sync_glossary_words(\stdClass $instance): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playercross/lib.php');

        if (!((int)$instance->sources & PLAYERCROSS_SOURCE_GLOSSARY)) {
            return 0;
        }

        $glossaryid = (int)($instance->glossaryid ?? 0);
        if ($glossaryid > 0) {
            $glossaryids = [$glossaryid];
        } else {
            $glossaryids = $DB->get_fieldset_select(
                'glossary',
                'id',
                'course = :course',
                ['course' => $instance->course]
            );
            if (empty($glossaryids)) {
                return 0;
            }
        }

        [$insql, $inparams] = $DB->get_in_or_equal($glossaryids, SQL_PARAMS_NAMED, 'gid');
        $entries = $DB->get_records_sql(
            "SELECT ge.id, ge.concept, ge.definition, ge.glossaryid"
            . " FROM {glossary_entries} ge"
            . " WHERE ge.glossaryid $insql AND ge.approved = 1",
            $inparams
        );

        $existing = $DB->get_records_select(
            'playercross_words',
            'playercrossid = :pid AND source = :source',
            ['pid' => $instance->id, 'source' => 'glossary'],
            '',
            'id, word'
        );
        $existingmap = [];
        foreach ($existing as $rec) {
            $existingmap[core_text::strtolower($rec->word)] = $rec->id;
        }

        $othersource = $DB->get_records_select(
            'playercross_words',
            'playercrossid = :pid AND source <> :source',
            ['pid' => $instance->id, 'source' => 'glossary'],
            '',
            'id, word'
        );
        $othersourcemap = [];
        foreach ($othersource as $rec) {
            $othersourcemap[core_text::strtolower($rec->word)] = true;
        }

        $imported = 0;
        foreach ($entries as $entry) {
            $concept = trim($entry->concept);
            if ($concept === '') {
                continue;
            }
            $hint = trim(html_entity_decode(strip_tags($entry->definition), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $words = self::extract_candidate_words($concept, (string)($instance->stopwords ?? ''));

            foreach ($words as $word) {
                $key = core_text::strtolower($word);
                if (isset($existingmap[$key])) {
                    if ($existingmap[$key] !== true) {
                        $DB->update_record('playercross_words', (object)[
                            'id'           => $existingmap[$key],
                            'hint'         => $hint,
                            'concept'      => $concept,
                            'glossaryid'   => (int)$entry->glossaryid,
                            'timemodified' => time(),
                        ]);
                        $existingmap[$key] = true;
                    }
                } else if (isset($othersourcemap[$key])) {
                    // A manual/AI word already owns this text — leave it untouched, do not
                    // duplicate it under a second, glossary-owned row.
                    continue;
                } else {
                    $DB->insert_record('playercross_words', (object)[
                        'playercrossid' => $instance->id,
                        'word'          => $word,
                        'concept'       => $concept,
                        'hint'          => $hint,
                        'source'        => 'glossary',
                        'glossaryid'    => (int)$entry->glossaryid,
                        'approved'      => 1,
                        'timecreated'   => time(),
                        'timemodified'  => time(),
                        'addedby'       => 0,
                    ]);
                    $existingmap[$key] = true;
                    $imported++;
                }
            }
        }

        $orphanids = [];
        foreach ($existingmap as $val) {
            if ($val !== true) {
                $orphanids[] = $val;
            }
        }
        if (!empty($orphanids)) {
            [$delsql, $delparams] = $DB->get_in_or_equal($orphanids, SQL_PARAMS_NAMED, 'del');
            $DB->delete_records_select('playercross_words', "id $delsql", $delparams);
        }

        return $imported;
    }

    /**
     * Gets one word by id and activity, regardless of approval status.
     *
     * @param int $wordid Word id.
     * @param int $instanceid Activity instance id.
     * @return \stdClass|null
     */
    public static function get_word_by_id(int $wordid, int $instanceid): ?\stdClass {
        global $DB;
        $word = $DB->get_record_sql(
            "SELECT id, word, hint, source, approved
               FROM {playercross_words}
              WHERE id = :id AND playercrossid = :iid",
            ['id' => $wordid, 'iid' => $instanceid],
            IGNORE_MISSING
        );
        return $word ?: null;
    }

    /**
     * Updates word text and hint for an entry that belongs to the given activity.
     *
     * @param int $wordid Word id.
     * @param int $instanceid Activity instance id.
     * @param string $word New word text.
     * @param string $hint New hint text.
     * @return bool True if the record was found and updated.
     */
    public static function update_word(int $wordid, int $instanceid, string $word, string $hint): bool {
        global $DB;
        $existing = $DB->get_record_sql(
            "SELECT id FROM {playercross_words} WHERE id = :id AND playercrossid = :iid",
            ['id' => $wordid, 'iid' => $instanceid],
            IGNORE_MISSING
        );
        if (!$existing) {
            return false;
        }
        return $DB->update_record('playercross_words', (object)[
            'id'           => $wordid,
            'word'         => trim($word),
            'concept'      => trim($word),
            'hint'         => trim($hint),
            'timemodified' => time(),
        ]);
    }

    /**
     * Bulk-deletes words that belong to the given activity instance.
     *
     * @param int[] $wordids Word ids to delete.
     * @param int $instanceid Activity instance id.
     * @return void
     */
    public static function delete_words_bulk(array $wordids, int $instanceid): void {
        global $DB;
        if (empty($wordids)) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($wordids, SQL_PARAMS_NAMED, 'wid');
        $inparams['instanceid'] = $instanceid;
        $DB->delete_records_select(
            'playercross_words',
            "id $insql AND playercrossid = :instanceid",
            $inparams
        );
    }

    /**
     * Deletes one word from a given activity instance.
     *
     * @param int $wordid Word id.
     * @param int $instanceid Activity instance id.
     * @return bool True if a matching record was found and deleted, false otherwise.
     */
    public static function delete_word(int $wordid, int $instanceid): bool {
        global $DB;

        // Moodle's delete_records() always returns true, even when zero rows matched, so an
        // existence check is required first to give the caller a real found/not-found signal.
        if (!$DB->record_exists('playercross_words', ['id' => $wordid, 'playercrossid' => $instanceid])) {
            return false;
        }
        return $DB->delete_records('playercross_words', ['id' => $wordid, 'playercrossid' => $instanceid]);
    }

    /**
     * Inserts one AI-generated word as pending approval.
     *
     * @param int $instanceid Activity instance id.
     * @param int $userid User id.
     * @param string $word Word text.
     * @param string $hint Optional hint or definition.
     * @return void
     */
    public static function add_ai_word(int $instanceid, int $userid, string $word, string $hint): void {
        global $DB;

        $record = (object)[
            'playercrossid' => $instanceid,
            'word' => trim($word),
            'concept' => trim($word),
            'hint' => trim($hint),
            'source' => 'ai',
            'approved' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'addedby' => $userid,
        ];
        $DB->insert_record('playercross_words', $record);
    }

    /**
     * Bulk-approves words that belong to the given activity instance.
     *
     * @param int[] $wordids Word ids to approve.
     * @param int $instanceid Activity instance id.
     * @return void
     */
    public static function approve_words_bulk(array $wordids, int $instanceid): void {
        global $DB;
        if (empty($wordids)) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($wordids, SQL_PARAMS_NAMED, 'wid');
        $inparams['instanceid'] = $instanceid;
        $condition = "id $insql AND playercrossid = :instanceid";
        $DB->set_field_select('playercross_words', 'approved', 1, $condition, $inparams);
        $DB->set_field_select('playercross_words', 'timemodified', time(), $condition, $inparams);
    }

    /**
     * Returns words for the teacher word pool, ordered by the given column.
     *
     * Both $sort and $dir must be validated by the caller against an allow-list
     * before being passed here.
     *
     * @param int $instanceid Activity instance id.
     * @param int $limit Maximum number of records (0 = unlimited).
     * @param string $sort Column name to sort by.
     * @param string $dir Sort direction: 'ASC' or 'DESC'.
     * @return array
     */
    public static function get_recent_words(
        int $instanceid,
        int $limit = 0,
        string $sort = 'id',
        string $dir = 'DESC'
    ): array {
        global $DB;
        $sql = "SELECT w.id, w.word, w.source, w.approved, w.concept, g.name AS glossaryname"
            . " FROM {playercross_words} w"
            . " LEFT JOIN {glossary} g ON g.id = w.glossaryid"
            . " WHERE w.playercrossid = :playercrossid"
            . " ORDER BY w.$sort $dir";
        return $DB->get_records_sql($sql, ['playercrossid' => $instanceid], 0, $limit);
    }

    /**
     * Returns the glossary concepts that produced more than one word row for this
     * instance — i.e. multi-word concepts extract_candidate_words() split into
     * several sibling tokens (see sync_glossary_words()).
     *
     * Each sibling word still carries the full original concept's definition as its
     * hint, so guessing one in isolation only tests a fragment of what the hint
     * actually describes — the teacher is expected to review these before publishing.
     * Scoped to source = 'glossary' on purpose: manual and AI-added words always store
     * concept = word (a single token, enforced at insert time), so they can never
     * collide here.
     *
     * @param int $instanceid Activity instance id.
     * @return string[] Concepts (as stored) shared by more than one word row.
     */
    public static function get_fragmented_concepts(int $instanceid): array {
        global $DB;

        $sql = "SELECT concept
                  FROM {playercross_words}
                 WHERE playercrossid = :playercrossid
                       AND source = :source
                       AND concept IS NOT NULL
                       AND concept <> ''
              GROUP BY concept
                HAVING COUNT(*) > 1";
        return array_values($DB->get_fieldset_sql($sql, ['playercrossid' => $instanceid, 'source' => 'glossary']));
    }

    /**
     * Returns every approved word that get_candidate_words()/get_theme_candidate_words()
     * would silently exclude from play — either outside the instance's current length
     * bounds, or containing a character the game cannot use. A word can end up here
     * after being approved: the instance's length settings were edited afterwards, or
     * it was saved with punctuation, digits or spaces. Used to warn the teacher directly
     * on view.php.
     *
     * @param \stdClass $instance Activity instance.
     * @return array<int, array{word: string, reason: string}> Reason is 'length' or 'invalidchars'.
     */
    public static function get_inactive_words(\stdClass $instance): array {
        global $DB;

        $records = $DB->get_records_select(
            'playercross_words',
            'playercrossid = :playercrossid AND approved = :approved',
            ['playercrossid' => $instance->id, 'approved' => 1],
            '',
            'id, word'
        );

        $inactive = [];
        foreach ($records as $record) {
            $word = trim($record->word);
            if (!word_normalizer::is_valid_charset($word)) {
                $inactive[] = ['word' => $word, 'reason' => 'invalidchars'];
                continue;
            }
            $wordlength = core_text::strlen($word);
            $fitsclue = $wordlength >= (int)$instance->min_length && $wordlength <= (int)$instance->max_length;
            $fitstheme = $wordlength >= (int)$instance->theme_min_length;
            if (!$fitsclue && !$fitstheme) {
                $inactive[] = ['word' => $word, 'reason' => 'length'];
            }
        }

        return $inactive;
    }

    /**
     * Counts how many times each word has been drawn as the mystery phrase for this
     * instance, regardless of the round's outcome — every row in {playercross_attempts}
     * represents one such draw.
     *
     * @param int $instanceid Activity instance id.
     * @return array<int, int> Word id => number of times drawn as theme. A word with no
     *     attempts at all is simply absent from the map, not present with 0.
     */
    public static function get_theme_draw_counts(int $instanceid): array {
        global $DB;

        $sql = "SELECT themewordid, COUNT(*) AS timesdrawn
                  FROM {playercross_attempts}
                 WHERE playercrossid = :playercrossid
              GROUP BY themewordid";
        $records = $DB->get_records_sql($sql, ['playercrossid' => $instanceid]);

        $counts = [];
        foreach ($records as $record) {
            $counts[(int)$record->themewordid] = (int)$record->timesdrawn;
        }
        return $counts;
    }

    /**
     * Previews how many distinct words a glossary source would contribute to the
     * pool within a given length range — the read-only counterpart to
     * sync_glossary_words(), for the settings form while creating a brand-new
     * activity, before any instance (and therefore any real pool) exists yet.
     * Nothing is written; the real sync only happens once the activity is saved.
     *
     * @param int $courseid Course id.
     * @param int $glossaryid Specific glossary id, or 0 for every glossary in the course.
     * @param int $minlength Candidate minimum word length.
     * @param int $maxlength Candidate maximum word length.
     * @param string $stopwordsraw Comma-separated words to ignore, as typed on the settings form.
     * @return int
     */
    public static function count_glossary_candidates(
        int $courseid,
        int $glossaryid,
        int $minlength,
        int $maxlength,
        string $stopwordsraw = ''
    ): int {
        global $DB;

        if ($glossaryid > 0) {
            // The glossary id comes straight from client input — never trust it
            // without confirming it actually belongs to this course, the same
            // instance-isolation rule every other externally-supplied id follows.
            if (!$DB->record_exists('glossary', ['id' => $glossaryid, 'course' => $courseid])) {
                return 0;
            }
            $glossaryids = [$glossaryid];
        } else {
            $glossaryids = $DB->get_fieldset_select(
                'glossary',
                'id',
                'course = :course',
                ['course' => $courseid]
            );
            if (empty($glossaryids)) {
                return 0;
            }
        }

        [$insql, $inparams] = $DB->get_in_or_equal($glossaryids, SQL_PARAMS_NAMED, 'gid');
        $concepts = $DB->get_fieldset_select(
            'glossary_entries',
            'concept',
            "glossaryid $insql AND approved = 1",
            $inparams
        );

        $candidates = [];
        foreach ($concepts as $concept) {
            foreach (self::extract_candidate_words(trim($concept), $stopwordsraw) as $word) {
                $wordlength = core_text::strlen($word);
                if ($wordlength < $minlength || $wordlength > $maxlength) {
                    continue;
                }
                // Two different concepts can tokenise into the same word (e.g. a
                // stopword-adjacent term repeated across entries) — sync_glossary_words()
                // only ever creates one row for it, so dedupe here too for an accurate
                // preview of what the real sync would actually produce.
                $candidates[core_text::strtolower($word)] = true;
            }
        }

        return count($candidates);
    }
}
