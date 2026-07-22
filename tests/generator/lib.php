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
 * Data generator for mod_playercross.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Data generator class for the playercross activity module.
 */
class mod_playercross_generator extends testing_module_generator {
    /**
     * Creates a new instance of the playercross activity.
     *
     * @param array|\stdClass|null $record Field values for the instance.
     * @param array|null $options Module options (e.g. idnumber, section).
     * @return \stdClass Created course-module record.
     */
    public function create_instance($record = null, ?array $options = null): \stdClass {
        $record = (object)(array)$record;

        // These are the same form-shaped fields playercross_normalise_instance_data() (see
        // lib.php) expects from mod_form.php — not the raw stored columns — since
        // add_instance()/update_instance() always recompute sources/cooldown_seconds/
        // timer_seconds from them.
        $defaults = [
            'source_manual'         => 1,
            'source_glossary'       => 0,
            'glossaryid'            => 0,
            'min_length'            => 3,
            'max_length'            => 15,
            'theme_min_length'      => 6,
            'num_clues'             => 5,
            'max_attempts_per_clue' => 0,
            'timer_minutes'         => 0,
            'show_ranking'          => 1,
            'wordmode'              => 1,
            'max_rounds'            => 0,
            'max_hints_per_round'   => 0,
            'cooldown_amount'       => 1,
            'cooldown_unit'         => 'days',
            'completionrounds'      => 0,
            'grade'                 => 100,
            'gradepass'             => 0.0,
            'grademethod'           => 1,
            'hud_round_cost_item'   => 0,
            'hud_round_cost_qty'    => 1,
            'hud_hint_cost_item'    => 0,
            'hud_hint_cost_qty'     => 1,
            'hud_win_reward_item'   => 0,
            'hud_win_reward_qty'    => 1,
        ];

        foreach ($defaults as $field => $value) {
            if (!isset($record->$field)) {
                $record->$field = $value;
            }
        }

        return parent::create_instance($record, $options);
    }

    /**
     * Inserts an already-approved manual word directly into an instance's pool.
     *
     * Bypasses managewords.php and the approval flow entirely, so tests can seed a
     * deterministic pool instead of depending on the pseudo-random round selection.
     *
     * The hint defaults to the word itself when omitted — since SCOPE.md §20.2 v1.9,
     * a word's own hint is what becomes the mystery phrase if it is picked as the
     * theme concept (see puzzle_builder::build_round()), and a blank hint would make
     * it ineligible. Defaulting it to the word keeps every existing call site that
     * never cared about hint content working exactly as before (a single-word
     * "phrase", same letters as the word itself); pass an explicit multi-word $hint
     * only in tests that exercise the phrase mechanic itself.
     *
     * @param int $playercrossid Instance id (playercross.id, not the course module id).
     * @param string $word Game word.
     * @param string $hint Hint text, defaults to $word itself when omitted.
     * @return \stdClass Created playercross_words record.
     */
    public function create_word(int $playercrossid, string $word, string $hint = ''): \stdClass {
        global $DB;

        $record = (object) [
            'playercrossid' => $playercrossid,
            'word'          => $word,
            'concept'       => $word,
            'hint'          => $hint !== '' ? $hint : $word,
            'source'        => 'manual',
            'glossaryid'    => 0,
            'approved'      => 1,
            'timecreated'   => time(),
            'timemodified'  => time(),
            'addedby'       => 0,
        ];
        $record->id = $DB->insert_record('playercross_words', $record);

        return $record;
    }

    /**
     * Inserts a finished attempt row directly, without playing a real round.
     *
     * Used to seed volume for report/ranking test scenarios (pagination, sorting) where
     * driving dozens of real rounds through the UI would be slow and flaky.
     *
     * @param int $playercrossid Instance id.
     * @param int $userid Student user id.
     * @param int $themewordid Word id used as the mystery phrase for this round.
     * @param array $data Optional field overrides: cluestotal, cluesresolved, finalguessed,
     *     attempts_used, time_used, completed, score, timecreated.
     * @return \stdClass Created playercross_attempts record.
     */
    public function create_attempt(int $playercrossid, int $userid, int $themewordid, array $data = []): \stdClass {
        global $DB;

        $record = (object) array_merge([
            'playercrossid' => $playercrossid,
            'userid'        => $userid,
            'themewordid'   => $themewordid,
            'cluestotal'    => 5,
            'cluesresolved' => 5,
            'finalguessed'  => 0,
            'attempts_used' => 1,
            'time_used'     => 30,
            'completed'     => 1,
            'score'         => 100.0,
            'timecreated'   => time(),
        ], $data);
        $record->id = $DB->insert_record('playercross_attempts', $record);

        return $record;
    }
}
