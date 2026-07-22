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
 * Backup structure step for mod_playercross.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the XML tree structure for a PlayerCross backup.
 */
class backup_playercross_activity_structure_step extends backup_activity_structure_step {
    /**
     * Returns the root backup element with all nested children.
     *
     * @return backup_nested_element
     */
    protected function define_structure(): backup_nested_element {
        $userinfo = $this->get_setting_value('userinfo');

        // Root element — mirrors all columns in {playercross}.
        $playercross = new backup_nested_element('playercross', ['id'], [
            'name',
            'intro',
            'introformat',
            'sources',
            'glossaryid',
            'stopwords',
            'min_length',
            'max_length',
            'theme_min_length',
            'theme_max_length',
            'num_clues',
            'reveal_uncovered_slots',
            'win_condition',
            'max_attempts_per_clue',
            'timer_seconds',
            'show_ranking',
            'wordmode',
            'max_rounds',
            'max_hints_per_round',
            'cooldown_seconds',
            'completionrounds',
            'grade',
            'gradepass',
            'grademethod',
            'hud_round_cost_item',
            'hud_round_cost_qty',
            'hud_hint_cost_item',
            'hud_hint_cost_qty',
            'hud_win_reward_item',
            'hud_win_reward_qty',
            'timecreated',
            'timemodified',
        ]);

        // Words belong to the activity and are always backed up.
        $words = new backup_nested_element('words');
        $word = new backup_nested_element('word', ['id'], [
            'word',
            'concept',
            'hint',
            'source',
            'glossaryid',
            'approved',
            'timecreated',
            'timemodified',
            'addedby',
        ]);

        // Attempts are user data — only backed up when userinfo is enabled.
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid',
            'themewordid',
            'cluestotal',
            'cluesresolved',
            'finalguessed',
            'attempts_used',
            'time_used',
            'completed',
            'score',
            'timecreated',
        ]);

        // Build the tree.
        $playercross->add_child($words);
        $words->add_child($word);

        if ($userinfo) {
            $playercross->add_child($attempts);
            $attempts->add_child($attempt);
        }

        // Connect elements to database tables.
        $playercross->set_source_table('playercross', ['id' => backup::VAR_ACTIVITYID]);
        $word->set_source_table('playercross_words', ['playercrossid' => backup::VAR_PARENTID]);

        if ($userinfo) {
            $attempt->set_source_table(
                'playercross_attempts',
                ['playercrossid' => backup::VAR_ACTIVITYID]
            );
        }

        // Annotate files embedded in the intro editor field, if any.
        $playercross->annotate_files('mod_playercross', 'intro', null);

        // Annotate IDs that reference other tables so they are remapped on restore.
        $playercross->annotate_ids('glossary', 'glossaryid');

        $word->annotate_ids('user', 'addedby');
        $word->annotate_ids('glossary', 'glossaryid');

        if ($userinfo) {
            $attempt->annotate_ids('user', 'userid');
            // Themewordid is an intra-plugin reference; resolved via the words mapping table.
            $attempt->annotate_ids('playercross_words', 'themewordid');
        }

        // Wrap the root in the standard activity envelope.
        return $this->prepare_activity_structure($playercross);
    }
}
