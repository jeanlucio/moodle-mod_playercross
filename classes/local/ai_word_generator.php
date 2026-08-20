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
 * AI word generation wrapper for mod_playercross.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use core_text;

/**
 * Generates single-word terms through the shared AI ladder.
 *
 * Routes generation to the AI Hub (local_aihub) for BYOK keys, falling back to
 * the Moodle core_ai subsystem when the hub has no key or is not installed. This
 * class owns the word-generation prompt and the response parsing; the hub returns
 * raw, untrusted text.
 */
class ai_word_generator {
    /**
     * Maximum number of existing pool words listed in the prompt as terms to avoid.
     *
     * Keeps the prompt bounded for an activity with a very large pool — the defensive
     * duplicate check in generate_and_save() still compares against the full set
     * regardless of this cap, so a truncated avoid-list never lets a duplicate
     * through, it only makes the AI's own avoidance slightly less complete.
     */
    private const MAX_AVOID_WORDS = 200;

    /**
     * Extra generation calls attempted when the first response leaves the request
     * short, on top of the initial call (so 3 means up to 4 calls total).
     *
     * A model that only returns part of what was asked is common with weaker
     * providers; local_aihub's own ladder already fails over to the next
     * registered provider on an outright error, but it has no notion of a
     * "successful but incomplete" response. This is the layer that fills that gap.
     */
    private const MAX_TOPUP_ATTEMPTS = 3;

    /**
     * Ceiling on the raw word count requested from the AI in a single call,
     * regardless of how many are still missing.
     *
     * Verified against live provider behaviour: a single call asking for 90 raw
     * words made every registered provider fail — Groq rejected its own output as
     * invalid JSON, DeepSeek and the OpenAI-compatible endpoint both timed out at
     * 30s. 60 is the largest raw count observed to complete successfully, so a
     * request for more than this is spread across extra top-up attempts (see
     * MAX_TOPUP_ATTEMPTS) instead of one larger call.
     */
    private const MAX_RAW_WORDS_PER_ATTEMPT = 60;

    /**
     * PHP execution time, in seconds, guaranteed for a full generate_and_save() run.
     *
     * Bounds the true worst case: (MAX_TOPUP_ATTEMPTS + 1) calls, each potentially
     * cascading through every provider local_aihub's client has registered
     * (4 today) at its 30-second HTTP timeout apiece — observed directly on this
     * host during a run where 3 of 4 site providers were failing (see the class
     * comment history). Without raising the limit, that worst case comfortably
     * exceeds this site's default max_execution_time and PHP kills the request
     * mid-run, discarding the response even though every word already saved to
     * that point remains in the database.
     */
    private const TIME_LIMIT_SECONDS = 600;

    /**
     * Returns true when an AI source (hub key or core_ai) is available.
     *
     * local_aihub is a site-wide BYOK service with no per-course scoping of its own, so
     * only the core_ai path needs the activity's context to honour a course/module-level
     * "Enable AI tools" override.
     *
     * @param \context $context Context of the activity checking availability.
     * @return bool
     */
    public static function has_key(\context $context): bool {
        if (class_exists(\local_aihub\ai::class) && \local_aihub\ai::is_available()) {
            return true;
        }
        return self::has_core_ai($context);
    }

    /**
     * Generates words using the AI provider and saves them as pending approval.
     *
     * Only single-word, purely alphabetic terms that fit the word column are saved.
     * Multi-word phrases, numeric tokens and terms longer than the database column
     * are skipped.
     * A term matching a word the activity already owns (any source, any approval
     * status) is skipped too — the prompt asks the AI to avoid the activity's
     * existing words, and this is the actual enforcement of that, independent of
     * whether the AI follows the instruction.
     *
     * When one call comes back short (a weaker model answering fewer than asked, or
     * losses to the validity/duplicate filters below), up to MAX_TOPUP_ATTEMPTS more
     * calls are made asking only for the remaining amount, until $count is reached
     * or the attempts run out. Each call goes back through the full local_aihub
     * provider ladder, so a top-up can land on a different registered API than the
     * first call did.
     *
     * @param \stdClass $instance Activity instance record.
     * @param int $userid ID of the user triggering the generation.
     * @param string $topic Subject area or theme for the AI prompt.
     * @param int $count Number of words to request.
     * @param \context $context Context of the activity requesting generation.
     * @return int Number of words saved as pending.
     * @throws \moodle_exception If no AI source is available or every attempt fails.
     */
    public static function generate_and_save(
        \stdClass $instance,
        int $userid,
        string $topic,
        int $count,
        \context $context
    ): int {
        \core_php_time_limit::raise(self::TIME_LIMIT_SECONDS);

        $existingwords = words_repository::get_all_word_texts((int)$instance->id);
        $seen = [];
        foreach ($existingwords as $existingword) {
            $seen[core_text::strtolower(trim($existingword))] = true;
        }
        $avoidpool = $existingwords;

        $language = get_string('thislanguage', 'langconfig');
        $description = get_string('aiusage', 'mod_playercross', $topic);

        $saved = 0;
        $anysuccess = false;

        for ($attempt = 0; $attempt <= self::MAX_TOPUP_ATTEMPTS && $saved < $count; $attempt++) {
            $remaining = $count - $saved;
            $requestcount = min(self::MAX_RAW_WORDS_PER_ATTEMPT, $remaining * 3);
            $avoidwords = array_slice($avoidpool, 0, self::MAX_AVOID_WORDS);
            $prompt = self::build_prompt($topic, $language, $requestcount, $avoidwords);

            $result = self::call_ai($prompt, $description, $context);
            if (empty($result['success'])) {
                continue;
            }
            $anysuccess = true;

            $items = self::parse_words((string)($result['data'] ?? ''));

            foreach ($items as $item) {
                if ($saved >= $count) {
                    break;
                }

                $term = trim($item['term'] ?? '');
                $clue = trim(strip_tags($item['clue'] ?? ''));

                if (!self::is_valid_term($term) || !self::is_valid_clue($clue)) {
                    continue;
                }

                // The prompt already asks the AI to avoid the activity's existing words,
                // but that is an instruction, not a guarantee — this is the actual gate
                // that keeps a duplicate from ever being written, and also catches the
                // AI repeating a term within its own response or across top-up attempts.
                $key = core_text::strtolower($term);
                if (isset($seen[$key])) {
                    continue;
                }

                words_repository::add_ai_word((int)$instance->id, $userid, $term, $clue);
                $seen[$key] = true;
                $avoidpool[] = $term;
                $saved++;
            }
        }

        if (!$anysuccess) {
            diagnostics::increment('ai_generation_failed');
            throw new \moodle_exception('aigenerateerror', 'mod_playercross');
        }

        if ($saved < $count) {
            // The provider answered, but delivered fewer usable terms than asked — a weaker
            // model underdelivering, or every returned item failing the validity/duplicate
            // filters above (including this plugin's extra is_valid_clue() check). This method
            // already returns whatever it got without raising an error, so without this
            // counter the shortfall is invisible: the caller sees fewer terms with no
            // explanation, and there is no other signal that anything went wrong.
            diagnostics::increment('ai_generation_partial');
        }

        return $saved;
    }

    /**
     * Checks whether a candidate term from the AI response is safe to save.
     *
     * The AI response is untrusted input: only a single-token, purely alphabetic
     * term that fits the word column is accepted. Multi-word phrases, numbers,
     * punctuation and terms longer than the database column are rejected — the
     * length check exists because nothing about the prompt guarantees a short
     * reply, and a term past the column length would otherwise abort
     * add_ai_word() with a raw dml_write_exception (see the equivalent
     * glossary-sync check in words_repository::sync_glossary_words()).
     *
     * @param string $term Trimmed candidate term.
     * @return bool
     */
    protected static function is_valid_term(string $term): bool {
        if ($term === '') {
            return false;
        }

        if (core_text::strlen($term) > words_repository::WORD_COLUMN_MAX_LENGTH) {
            return false;
        }

        $tokens = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY);
        if (count($tokens) !== 1) {
            return false;
        }

        return (bool)preg_match('/^[\p{L}]+$/u', $term);
    }

    /**
     * Checks whether a candidate clue from the AI response is safe to save.
     *
     * The prompt already asks the AI for a clue on every item, but the response is
     * untrusted text, not a guarantee — a term without one would be saved as a term
     * with nothing to ask the student: its clue is shown verbatim as the term's own
     * phrase (see round_play.mustache), never optional the way it is in the
     * mod_playerwords activity this class was ported from.
     *
     * @param string $clue Trimmed, tag-stripped candidate clue.
     * @return bool
     */
    protected static function is_valid_clue(string $clue): bool {
        return $clue !== '';
    }

    /**
     * Builds the prompt asking the AI for single-word terms with clues.
     *
     * @param string $topic Subject area or theme.
     * @param string $language Target language name.
     * @param int $count Number of words to request.
     * @param string[] $avoidwords Existing pool words the AI should not repeat.
     * @return string The constructed prompt.
     */
    protected static function build_prompt(string $topic, string $language, int $count, array $avoidwords = []): string {
        $langname = $language !== '' ? $language : 'English';
        $example = '{"words":[{"term":"...","clue":"..."}]}';

        $parts = [
            "You are generating vocabulary words for a deduction crossword game about the topic: \"{$topic}\".",
            "Generate {$count} words. Write all text in language: {$langname}.",
            'For each word provide:',
            '- term: EXACTLY ONE word (a single token) — letters only, no spaces, hyphens, digits'
                . ' or punctuation. Spell it correctly for language: ' . $langname
                . ', keeping every accent, diacritic or cedilla the word normally takes —'
                . ' do not strip them.',
            '- clue: one short clue sentence that helps guess the word without containing the word itself.',
            'Prefer common, guessable words. Avoid proper nouns and abbreviations.',
        ];

        if (!empty($avoidwords)) {
            $parts[] = 'Do not repeat any of these words, which are already in the activity\'s word pool: '
                . implode(', ', $avoidwords) . '.';
        }

        $parts[] = 'IMPORTANT: Reply ONLY with a valid JSON object in this exact format, no code fences:';
        $parts[] = $example;

        return implode("\n", $parts);
    }

    /**
     * Parses the AI response into an array of term/clue pairs.
     *
     * Strips optional markdown code fences, then decodes the JSON. Accepts a
     * "words" or legacy "concepts" wrapper, or a bare list.
     *
     * @param string $responsetext Raw text returned by the AI provider.
     * @return array Array of arrays with keys: term, clue.
     */
    protected static function parse_words(string $responsetext): array {
        $cleaned = preg_replace('/^\x60\x60\x60(?:json)?\s*/im', '', $responsetext);
        $cleaned = preg_replace('/\x60\x60\x60\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);
        if (!is_array($decoded)) {
            return [];
        }

        if (isset($decoded['words']) && is_array($decoded['words'])) {
            $list = $decoded['words'];
        } else if (isset($decoded['concepts']) && is_array($decoded['concepts'])) {
            $list = $decoded['concepts'];
        } else if (isset($decoded[0]) && is_array($decoded[0])) {
            $list = $decoded;
        } else {
            return [];
        }

        $items = [];
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $items[] = [
                'term' => (string)($entry['term'] ?? ''),
                'clue' => (string)($entry['clue'] ?? ($entry['definition'] ?? '')),
            ];
        }
        return $items;
    }

    /**
     * Resolves an AI source and generates content.
     *
     * Routes to the AI Hub (local_aihub) first, which resolves personal then site
     * BYOK keys. Falls back to the Moodle core_ai subsystem when the hub returns no
     * result or is not installed.
     *
     * @param string $prompt The full prompt text.
     * @param string $description Short label of what is being generated, for the hub usage log.
     * @param \context $context Context of the activity requesting generation.
     * @return array Result with keys: success (bool), data (string), message (string), provider (string).
     */
    protected static function call_ai(string $prompt, string $description, \context $context): array {
        $lasterror = ['success' => false, 'message' => '', 'data' => '', 'provider' => ''];

        if (class_exists(\local_aihub\ai::class)) {
            $result = \local_aihub\ai::generate_text('', $prompt, true, 'mod_playercross', $description);
            if (!empty($result['success'])) {
                return $result;
            }
            // Preserve a real failure (e.g. an invalid key) so it is not masked as "no source".
            if (!empty($result['message'])) {
                $lasterror = $result;
            }
        }

        if (self::has_core_ai($context)) {
            $result = self::call_core_ai($prompt, $context);
            if ($result['success'] || !empty($result['message'])) {
                return $result;
            }
        }

        return $lasterror;
    }

    /**
     * Returns true when the Moodle core_ai subsystem has a text-generation provider
     * available and, on Moodle versions that support it, not disabled for this context.
     *
     * @param \context $context Context of the activity checking availability.
     * @return bool
     */
    protected static function has_core_ai(\context $context): bool {
        if (
            !class_exists(\core_ai\manager::class)
            || !class_exists(\core_ai\aiactions\generate_text::class)
        ) {
            return false;
        }

        try {
            $actionclass = \core_ai\aiactions\generate_text::class;
            $manager = \core\di::get(\core_ai\manager::class);
            $providers = $manager->get_providers_for_actions([$actionclass], true);
            if (empty($providers[$actionclass])) {
                return false;
            }
            return self::action_enabled_in_context($manager, $context, $actionclass);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Checks the per-course/per-module "Enable AI tools" override, when the running Moodle
     * version supports it.
     *
     * core_ai\manager::is_action_enabled_in_context() was added to Moodle core after 4.5
     * (course.enableaitools / course_modules.enableaitools do not exist on 4.5, so the method
     * itself is undefined there). This plugin supports 4.5+5.x, so the check is skipped —
     * never blocking — on versions where the method does not exist, exactly like every other
     * class_exists()/method_exists() guarded integration in this codebase.
     *
     * @param \core_ai\manager $manager The AI manager instance.
     * @param \context $context Context of the activity requesting generation.
     * @param string $actionclass Fully qualified AI action class name.
     * @return bool
     */
    protected static function action_enabled_in_context(
        \core_ai\manager $manager,
        \context $context,
        string $actionclass
    ): bool {
        if (!method_exists($manager, 'is_action_enabled_in_context')) {
            return true;
        }
        return $manager->is_action_enabled_in_context($context, $actionclass);
    }

    /**
     * Generates text via the Moodle core_ai subsystem (institutional fallback).
     *
     * @param string $prompt The prompt text.
     * @param \context $context Context of the activity requesting generation.
     * @return array Result with keys: success (bool), data (string), message (string), provider (string).
     */
    protected static function call_core_ai(string $prompt, \context $context): array {
        global $USER;

        try {
            $actionclass = \core_ai\aiactions\generate_text::class;
            $manager = \core\di::get(\core_ai\manager::class);
            $providers = $manager->get_providers_for_actions([$actionclass], true);

            if (empty($providers[$actionclass]) || !self::action_enabled_in_context($manager, $context, $actionclass)) {
                return ['success' => false, 'message' => '', 'data' => '', 'provider' => ''];
            }

            $action = new \core_ai\aiactions\generate_text(
                contextid: $context->id,
                userid: (int) $USER->id,
                prompttext: $prompt,
            );

            $response = $manager->process_action($action);

            if (!$response->get_success()) {
                return ['success' => false, 'message' => 'core_ai: provider returned failure', 'data' => '', 'provider' => ''];
            }

            $data = $response->get_response_data();
            $content = (string) ($data['generatedcontent'] ?? '');

            if ($content === '') {
                return ['success' => false, 'message' => 'core_ai: empty response', 'data' => '', 'provider' => ''];
            }

            return ['success' => true, 'data' => $content, 'message' => '', 'provider' => 'Moodle AI'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'core_ai: ' . $e->getMessage(), 'data' => '', 'provider' => ''];
        }
    }
}
