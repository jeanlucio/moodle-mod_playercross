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
 * English language strings for mod_playercross.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['completionrounds_desc'] = 'Complete at least {$a} round(s)';
$string['completionroundsgroup'] = 'Require completed rounds';
$string['cooldown_label'] = 'Cooldown between rounds';
$string['cooldown_unit_days'] = 'Days';
$string['cooldown_unit_hours'] = 'Hours';
$string['cooldown_unit_minutes'] = 'Minutes';
$string['error_atleastonesource'] = 'Select at least one word source.';
$string['error_completionrounds'] = 'Required rounds must be at least 1.';
$string['error_cooldown'] = 'Cooldown must be 0 or a positive value.';
$string['error_grademethod_average_all'] = 'This grading method requires the maximum rounds per student setting to not be Unlimited.';
$string['error_hud_cost_qty'] = 'Quantity must be at least 1.';
$string['error_insufficientpool'] = 'This activity needs at least {$a} approved words in the pool before a round can start.';
$string['error_maxattemptsperclue'] = 'Maximum attempts per clue must be 0 or a positive value.';
$string['error_maxlength'] = 'Maximum length must be greater than or equal to minimum length.';
$string['error_minlength'] = 'Minimum length must be at least 1.';
$string['error_thememinlength'] = 'Minimum mystery phrase length must be at least 1.';
$string['error_timerseconds'] = 'Timer must be 0 or a positive value.';
$string['gameplayheader'] = 'Gameplay settings';
$string['glossaryid'] = 'Glossary';
$string['glossaryid_all'] = 'All course glossaries';
$string['grademethod'] = 'Grading method';
$string['grademethod_average'] = 'Average grade';
$string['grademethod_average_all'] = 'Average over all required rounds';
$string['grademethod_first'] = 'First attempt';
$string['grademethod_help'] = 'Determines how the final grade is calculated from a student\'s round attempts: <ul><li><strong>Highest grade:</strong> the best score among all rounds.</li><li><strong>Average grade:</strong> the mean of only the rounds actually played.</li><li><strong>First attempt:</strong> the score of the first round only.</li><li><strong>Last attempt:</strong> the score of the most recent round only.</li><li><strong>Average over all required rounds:</strong> the sum of the round scores divided by the configured maximum rounds, so any round not played counts as zero. Requires the maximum rounds per student setting to not be Unlimited.</li></ul>';
$string['grademethod_highest'] = 'Highest grade';
$string['grademethod_last'] = 'Last attempt';
$string['hud_header'] = 'PlayerHUD Integration';
$string['hud_hint_cost_item'] = 'Item to reveal a clue\'s hint';
$string['hud_hint_cost_qty'] = 'Quantity to reveal a clue\'s hint';
$string['hud_item_deleted'] = 'Deleted item (please reconfigure)';
$string['hud_item_disabled'] = '{$a} (disabled)';
$string['hud_noitem'] = 'Disabled (no cost)';
$string['hud_notincourse'] = 'PlayerHUD integration will appear here once the PlayerHUD block is added to this course.';
$string['hud_notinstalled_desc'] = 'The block_playerhud plugin is not installed on this site. Install it, then add the PlayerHUD block to a course, to let teachers reward students with items for PlayerCross rounds.';
$string['hud_notinstalled_heading'] = 'PlayerHUD integration';
$string['hud_round_cost_item'] = 'Item to start a round';
$string['hud_round_cost_qty'] = 'Quantity to start a round';
$string['hud_win_reward_item'] = 'Item awarded for winning a round';
$string['hud_win_reward_item_help'] = 'The student receives this item each time they win a round. To match PlayerHUD\'s own anti-farming rule, no XP is awarded from this item when Maximum rounds per student is Unlimited — the item is still granted, just without XP.';
$string['hud_win_reward_qty'] = 'Quantity awarded for winning a round';
$string['max_attempts_per_clue'] = 'Maximum attempts per clue (0 for unlimited)';
$string['max_attempts_per_clue_help'] = 'The maximum number of guesses a student may submit for a single clue before it is considered unsolved for the rest of the round. Set to 0 to allow unlimited attempts per clue.';
$string['max_length'] = 'Maximum clue word length';
$string['max_rounds'] = 'Maximum rounds per student';
$string['max_rounds_unlimited'] = 'Unlimited';
$string['min_length'] = 'Minimum clue word length';
$string['modulename'] = 'PlayerCross';
$string['modulename_help'] = 'PlayerCross is a deduction crossword-style activity: students solve clues about course concepts, and each correct answer reveals shared letters of a final mystery phrase.';
$string['modulenameplural'] = 'PlayerCross';
$string['num_clues'] = 'Clues per round';
$string['playercross:addinstance'] = 'Add a new PlayerCross activity';
$string['playercross:view'] = 'View PlayerCross activity';
$string['playersourcesheader'] = 'Word sources';
$string['pluginadministration'] = 'PlayerCross administration';
$string['pluginname'] = 'PlayerCross';
$string['resetplayercrossattempts'] = 'Delete PlayerCross round attempts';
$string['scoringmode_locked'] = 'Because this activity has already recorded a real grade for at least one student, the number of clues and the grading method can no longer be changed — changing them afterwards would make past and future rounds count on different scales.';
$string['show_ranking'] = 'Show ranking';
$string['source_glossary'] = 'Glossary';
$string['source_manual'] = 'Manual insertion';
$string['stopwords'] = 'Glossary concept stopwords';
$string['stopwords_help'] = 'Comma-separated list of words to ignore when splitting multi-word glossary concepts into candidate clue words. Leave empty to disable filtering (the minimum word length above still applies). Suggested list: a, an, and, as, at, by, for, from, if, in, into, is, it, its, not, of, on, or, so, the, this, to, up, was, with.';
$string['theme_min_length'] = 'Minimum mystery phrase length';
$string['theme_min_length_help'] = 'The minimum word length required for a word to be eligible as the mystery phrase. Longer words produce more letter slots for clues to cover.';
$string['timer_minutes'] = 'Timer in minutes (0 to disable)';
$string['viewplaceholder'] = 'This activity is being built. Check back soon to play a round.';
$string['wordmode'] = 'Mystery phrase selection mode';
$string['wordmode_random'] = 'Random phrase per round';
$string['wordmode_shared'] = 'Shared sequence (all students receive the same phrase in the same order)';
