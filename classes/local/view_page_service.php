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
 * Service to build PlayerCross view page state.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use context_module;

/**
 * Handles the template context of view.php.
 */
class view_page_service {
    /**
     * Builds full page data for view rendering.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $instance Activity instance.
     * @param context_module $context Module context.
     * @param int $userid Current user id.
     * @return array
     */
    public static function build_page_data(
        \stdClass $cm,
        \stdClass $instance,
        context_module $context,
        int $userid
    ): array {
        $shouldautoshowintro = !intro_service::has_seen_intro($userid);
        if ($shouldautoshowintro) {
            intro_service::mark_intro_seen($userid);
        }

        $state = round_service::load_state((int)$cm->id, $userid);

        if ((int)$state['themewordid'] === 0 && empty($state['finished'])) {
            $restrictionnotice = round_service::get_round_restriction_notice($instance, $userid);
            if ($restrictionnotice !== null) {
                $templatectx = self::build_template_context($cm, $instance, $state, $context, $userid);
                $templatectx['hastheme'] = false;
                $templatectx['nogamewords'] = $restrictionnotice;
                return [
                    'templatecontext'     => $templatectx,
                    'cooldownuntil'       => round_service::compute_cooldown_until($instance, $userid),
                    'timeleft'            => 0,
                    'timertotal'          => 0,
                    'shouldautoshowintro' => $shouldautoshowintro,
                ];
            }
        }

        $state = round_service::ensure_round_state($state, $instance, (int)$cm->id, $userid);
        round_service::save_state((int)$cm->id, $userid, $state);

        $templatecontext = self::build_template_context($cm, $instance, $state, $context, $userid);

        return [
            'templatecontext'     => $templatecontext,
            'cooldownuntil'       => round_service::compute_cooldown_until($instance, $userid),
            'timeleft'            => (int)($templatecontext['timeleft'] ?? 0),
            'timertotal'          => (int)$instance->timer_seconds,
            'shouldautoshowintro' => $shouldautoshowintro,
        ];
    }

    /**
     * Builds template context array.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $instance Activity instance.
     * @param array $state Session state.
     * @param context_module $context Module context.
     * @param int $userid Current user id.
     * @return array
     */
    private static function build_template_context(
        \stdClass $cm,
        \stdClass $instance,
        array $state,
        context_module $context,
        int $userid
    ): array {
        $hastheme = (int)$state['themewordid'] > 0;
        $showlobby = $hastheme && empty($state['finished']) && empty($state['roundstarted']);

        $inner = $showlobby
            ? round_presenter::build_lobby_context($instance, $state, $userid)
            : round_presenter::build_round_panel_context($instance, $cm, $state, $userid);

        $canmanage = has_capability('mod/playercross:addinstance', $context);

        return [
            'hastheme' => $hastheme,
            'nogamewords' => get_string('nogamewords', 'mod_playercross'),
            'showforfeit' => !empty($state['roundstarted']) && empty($state['finished']),
            'forfeitlabel' => get_string('forfeitbutton', 'mod_playercross'),
            'forfeitconfirm' => get_string('forfeitconfirm', 'mod_playercross'),
            'showlobby' => $showlobby,
            'roundstarted' => !empty($state['roundstarted']),
            'toolbarmyattempts' => get_string('toolbarmyattempts', 'mod_playercross'),
            'myattemptsurl' => (new \moodle_url('/mod/playercross/myattempts.php', ['id' => $cm->id]))->out(false),
            'canmanage' => $canmanage,
            'toolbarreport' => get_string('toolbarreport', 'mod_playercross'),
            'attemptsreporturl' => (new \moodle_url(
                '/mod/playercross/attemptsreport.php',
                ['id' => $cm->id]
            ))->out(false),
            'toolbarmanagewords' => get_string('toolbarmanagewords', 'mod_playercross'),
            'managewordsurl' => (new \moodle_url('/mod/playercross/managewords.php', ['id' => $cm->id]))->out(false),
            'showranking' => !empty($instance->show_ranking),
            'toolbarranking' => get_string('toolbarranking', 'mod_playercross'),
            'rankingurl' => (new \moodle_url('/mod/playercross/ranking.php', ['id' => $cm->id]))->out(false),
        ]
            + self::build_help_context($instance)
            + self::build_inactive_words_context($instance, $canmanage)
            + $inner;
    }

    /**
     * Builds the context for the word-pool status shown to whoever can manage the
     * activity — a student never sees any of this. Always includes a count of
     * currently playable words, a quick "the pool isn't empty" reassurance, and
     * conditionally the "inactive words" warning: approved pool words that
     * words_repository::get_candidate_words() would silently exclude from play.
     *
     * @param \stdClass $instance Activity instance.
     * @param bool $canmanage Whether the current user can manage the activity.
     * @return array
     */
    private static function build_inactive_words_context(\stdClass $instance, bool $canmanage): array {
        if (!$canmanage) {
            return ['showwordsstatus' => false, 'hasinactivewords' => false];
        }

        $activecount = count(words_repository::get_candidate_words($instance));
        $inactive = words_repository::get_inactive_words($instance);

        $lengthwords = [];
        $charsetwords = [];
        foreach ($inactive as $entry) {
            if ($entry['reason'] === 'invalidchars') {
                $charsetwords[] = $entry['word'];
            } else {
                $lengthwords[] = $entry['word'];
            }
        }

        return [
            'showwordsstatus' => true,
            'activewordscount' => get_string('activewordscount', 'mod_playercross', $activecount),
            'hasinactivewords' => !empty($inactive),
            'inactivewordstitle' => get_string('inactivewords_title', 'mod_playercross'),
            'haslengthissues' => !empty($lengthwords),
            'lengthissuestext' => !empty($lengthwords) ? get_string('inactivewords_length', 'mod_playercross', (object)[
                'count' => count($lengthwords),
                'words' => implode(', ', $lengthwords),
            ]) : '',
            'hascharsetissues' => !empty($charsetwords),
            'charsetissuestext' => !empty($charsetwords)
                ? get_string('inactivewords_invalidchars', 'mod_playercross', (object)[
                    'count' => count($charsetwords),
                    'words' => implode(', ', $charsetwords),
                ])
                : '',
        ];
    }

    /**
     * Builds the context for the how-to-play help content, rendered as a hidden
     * template in the page and shown in a modal (see mod_playercross/help_body).
     *
     * @param \stdClass $instance Activity instance.
     * @return array
     */
    private static function build_help_context(\stdClass $instance): array {
        $showgrading = (float)$instance->grade > 0;
        $showhud = ((int)($instance->hud_round_cost_item ?? 0) > 0)
            || ((int)($instance->hud_hint_cost_item ?? 0) > 0)
            || ((int)($instance->hud_win_reward_item ?? 0) > 0);

        $wincondition = (int)($instance->win_condition ?? PLAYERCROSS_WINCONDITION_BOTH);
        $winconditionstring = $wincondition === PLAYERCROSS_WINCONDITION_FINALONLY
            ? 'help_wincondition_finalonly'
            : 'help_wincondition_both';

        // The automatic-loss risk only exists under "both required" and only when a clue
        // can actually run out of attempts — under "mystery-phrase only", an exhausted clue
        // never ends the round (see round_service::reconcile_after_reveal()).
        $showclueloss = $wincondition === PLAYERCROSS_WINCONDITION_BOTH
            && (int)($instance->max_attempts_per_clue ?? 0) > 0;

        return [
            'helptitle' => get_string('help_title', 'mod_playercross'),
            'introtext' => get_string('help_intro', 'mod_playercross'),
            'legendrevealedlabel' => get_string('help_legend_revealed', 'mod_playercross'),
            'legendhiddenlabel' => get_string('help_legend_hidden', 'mod_playercross'),
            'cluestext' => get_string('help_clues', 'mod_playercross'),
            'hinttext' => get_string('help_hint', 'mod_playercross'),
            'finalguesstext' => get_string('help_finalguess', 'mod_playercross'),
            'winconditiontext' => get_string($winconditionstring, 'mod_playercross'),
            'showclueloss' => $showclueloss,
            'cluelosstext' => $showclueloss ? get_string('help_clueexhausted', 'mod_playercross') : '',
            'timertext' => get_string('help_timer', 'mod_playercross'),
            'showhud' => $showhud,
            'hudtext' => $showhud ? get_string('help_hud', 'mod_playercross') : '',
            'showgrading' => $showgrading,
            'gradingtext' => $showgrading
                ? get_string('help_grading', 'mod_playercross', round_presenter::grademethod_name($instance))
                : '',
            'reviewhint' => get_string('help_reviewhint', 'mod_playercross'),
        ];
    }
}
