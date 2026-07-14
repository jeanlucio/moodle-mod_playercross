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
     * Only single-word, purely alphabetic terms within the activity's configured
     * length bounds are saved. Multi-word phrases and numeric tokens are skipped.
     *
     * @param \stdClass $instance Activity instance record.
     * @param int $userid ID of the user triggering the generation.
     * @param string $topic Subject area or theme for the AI prompt.
     * @param int $count Number of words to request (1–20).
     * @param \context $context Context of the activity requesting generation.
     * @return int Number of words saved as pending.
     * @throws \moodle_exception If no AI source is available or the request fails.
     */
    public static function generate_and_save(
        \stdClass $instance,
        int $userid,
        string $topic,
        int $count,
        \context $context
    ): int {
        $language = get_string('thislanguage', 'langconfig');
        $requestcount = min(60, $count * 3);
        $prompt = self::build_prompt($topic, $language, $requestcount);
        $description = get_string('aiusage', 'mod_playercross', $topic);

        $result = self::call_ai($prompt, $description, $context);
        if (empty($result['success'])) {
            throw new \moodle_exception('aigenerateerror', 'mod_playercross');
        }

        $items = self::parse_words((string)($result['data'] ?? ''));

        $saved = 0;
        foreach ($items as $item) {
            if ($saved >= $count) {
                break;
            }

            $term = trim($item['term'] ?? '');
            $hint = trim(strip_tags($item['hint'] ?? ''));

            if (!self::is_valid_term($term)) {
                continue;
            }

            words_repository::add_ai_word((int)$instance->id, $userid, $term, $hint);
            $saved++;
        }

        return $saved;
    }

    /**
     * Checks whether a candidate term from the AI response is safe to save.
     *
     * The AI response is untrusted input: only a single-token, purely alphabetic
     * term is accepted. Multi-word phrases, numbers and punctuation are rejected.
     *
     * @param string $term Trimmed candidate term.
     * @return bool
     */
    protected static function is_valid_term(string $term): bool {
        if ($term === '') {
            return false;
        }

        $tokens = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY);
        if (count($tokens) !== 1) {
            return false;
        }

        return (bool)preg_match('/^[\p{L}]+$/u', $term);
    }

    /**
     * Builds the prompt asking the AI for single-word terms with hints.
     *
     * @param string $topic Subject area or theme.
     * @param string $language Target language name.
     * @param int $count Number of words to request.
     * @return string The constructed prompt.
     */
    protected static function build_prompt(string $topic, string $language, int $count): string {
        $langname = $language !== '' ? $language : 'English';
        $example = '{"words":[{"term":"...","hint":"..."}]}';

        $parts = [
            "You are generating vocabulary words for a deduction crossword game about the topic: \"{$topic}\".",
            "Generate {$count} words. Write all text in language: {$langname}.",
            'For each word provide:',
            '- term: EXACTLY ONE word (a single token) — letters only, no spaces, hyphens, digits'
                . ' or punctuation.',
            '- hint: one short clue sentence that helps guess the word without containing the word itself.',
            'Prefer common, guessable words. Avoid proper nouns and abbreviations.',
            'IMPORTANT: Reply ONLY with a valid JSON object in this exact format, no code fences:',
            $example,
        ];

        return implode("\n", $parts);
    }

    /**
     * Parses the AI response into an array of term/hint pairs.
     *
     * Strips optional markdown code fences, then decodes the JSON. Accepts a
     * "words" or legacy "concepts" wrapper, or a bare list.
     *
     * @param string $responsetext Raw text returned by the AI provider.
     * @return array Array of arrays with keys: term, hint.
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
                'hint' => (string)($entry['hint'] ?? ($entry['definition'] ?? '')),
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
