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
                $templatectx = self::build_template_context($cm, $instance, $state, $userid);
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

        $state = round_service::ensure_round_state($state, $instance, $userid);
        round_service::save_state((int)$cm->id, $userid, $state);

        $templatecontext = self::build_template_context($cm, $instance, $state, $userid);

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
     * @param int $userid Current user id.
     * @return array
     */
    private static function build_template_context(
        \stdClass $cm,
        \stdClass $instance,
        array $state,
        int $userid
    ): array {
        $hastheme = (int)$state['themewordid'] > 0;
        $showlobby = $hastheme && empty($state['finished']) && empty($state['roundstarted']);

        $inner = $showlobby
            ? round_presenter::build_lobby_context($instance, $state, $userid)
            : round_presenter::build_round_panel_context($instance, $cm, $state, $userid);

        return [
            'hastheme' => $hastheme,
            'nogamewords' => get_string('nogamewords', 'mod_playercross'),
            'showforfeit' => !empty($state['roundstarted']) && empty($state['finished']),
            'forfeitlabel' => get_string('forfeitbutton', 'mod_playercross'),
            'forfeitconfirm' => get_string('forfeitconfirm', 'mod_playercross'),
            'showlobby' => $showlobby,
            'roundstarted' => !empty($state['roundstarted']),
        ]
            + self::build_help_context($instance)
            + $inner;
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

        return [
            'helptitle' => get_string('help_title', 'mod_playercross'),
            'introtext' => get_string('help_intro', 'mod_playercross'),
            'legendrevealedlabel' => get_string('help_legend_revealed', 'mod_playercross'),
            'legendhiddenlabel' => get_string('help_legend_hidden', 'mod_playercross'),
            'cluestext' => get_string('help_clues', 'mod_playercross'),
            'hinttext' => get_string('help_hint', 'mod_playercross'),
            'finalguesstext' => get_string('help_finalguess', 'mod_playercross'),
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
