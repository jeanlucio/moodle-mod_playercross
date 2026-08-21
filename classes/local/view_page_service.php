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
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use context_module;
use html_writer;

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
        // A round that timed out while the player simply never fired end_round(timeout)
        // (or reloaded after the deadline, since the client only ever arms the timer
        // when it first sees timeleft > 0) must not keep rendering playable forms.
        $state = round_service::close_if_expired($state, $instance, (int)$cm->id, $userid);
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

        $canmanage = has_capability('mod/playercross:managewords', $context);
        $canviewreports = has_capability('mod/playercross:viewreports', $context);

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
            'canviewreports' => $canviewreports,
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
     * activity — a student never sees any of this. Always includes the count of
     * words currently playable in each of the two roles (term, theme concept), and
     * conditionally the "inactive words" warning: approved pool words that
     * words_repository::get_inactive_words() reports as missing from one or both
     * roles.
     *
     * @param \stdClass $instance Activity instance.
     * @param bool $canmanage Whether the current user can manage the activity.
     * @return array
     */
    private static function build_inactive_words_context(\stdClass $instance, bool $canmanage): array {
        if (!$canmanage) {
            return ['showwordsstatus' => false, 'hasinactivewords' => false];
        }

        $termcount = count(words_repository::get_candidate_words($instance));
        $themecount = count(words_repository::get_theme_candidate_words($instance));
        $inactive = words_repository::get_inactive_words($instance);
        $notterm = $inactive['notterm'];
        $nottheme = $inactive['nottheme'];

        return [
            'showwordsstatus' => true,
            'activewordscountterm' => get_string('activewordscountterm', 'mod_playercross', $termcount),
            'activewordscounttheme' => get_string('activewordscounttheme', 'mod_playercross', $themecount),
            'hasinactivewords' => !empty($notterm) || !empty($nottheme),
            'inactivewordstitle' => get_string('inactivewords_title', 'mod_playercross'),
            'hasnotterm' => !empty($notterm),
            'nottermtext' => !empty($notterm) ? get_string('inactivewords_notterm', 'mod_playercross', (object)[
                'count' => count($notterm),
                'words' => implode(', ', $notterm),
            ]) : '',
            'hasnottheme' => !empty($nottheme),
            'notthemetext' => !empty($nottheme) ? get_string('inactivewords_nottheme', 'mod_playercross', (object)[
                'count' => count($nottheme),
                'words' => implode(', ', $nottheme),
            ]) : '',
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
        $showhint = !empty($instance->hints_enabled);
        $showtimer = (int)($instance->timer_seconds ?? 0) > 0;

        $wincondition = (int)($instance->win_condition ?? PLAYERCROSS_WINCONDITION_BOTH);
        $winconditionstring = $wincondition === PLAYERCROSS_WINCONDITION_FINALONLY
            ? 'help_wincondition_finalonly'
            : 'help_wincondition_both';

        // The automatic-loss risk only exists under "both required" and only when a term
        // can actually run out of attempts — under "mystery-phrase only", an exhausted term
        // never ends the round (see round_service::reconcile_after_reveal()).
        $showtermloss = $wincondition === PLAYERCROSS_WINCONDITION_BOTH
            && (int)($instance->max_attempts_per_term ?? 0) > 0;

        // Unlike the term-exhaustion risk above, this always ends the round as a loss,
        // regardless of win_condition — the phrase is unconditionally required to win
        // under both modes (see round_service::submit_final_guess()).
        $showfinalguessloss = (int)($instance->max_attempts_final_guess ?? 0) > 0;

        $gradescoringmode = (int)($instance->gradescoringmode ?? PLAYERCROSS_SCORING_BINARY);
        $rankingscoringmode = (int)($instance->rankingscoringmode ?? PLAYERCROSS_SCORING_BINARY);
        $showranking = !empty($instance->show_ranking);
        $gradeislinear = $gradescoringmode === PLAYERCROSS_SCORING_LINEAR;
        // Deliberately NOT gated on $showgrading: ranking's own Linear formula is
        // meaningful on its own (see gameplay_service::calculate_ranking_points()),
        // independent of whether the activity uses grading at all — an ungraded,
        // ranking-only activity with rankingscoringmode Linear must still see this.
        $showscoringformula = $gradeislinear
            || ($showranking && $rankingscoringmode === PLAYERCROSS_SCORING_LINEAR);
        // Showscoringformula can be true purely because ranking is Linear while the
        // grade itself stays Binary (the two scoring modes are independent settings) —
        // help_scoringformula describes the grade as Linear, so that wording is only
        // accurate when the grade really is; the ranking-only case needs its own text.
        $scoringformulastring = $gradeislinear ? 'help_scoringformula' : 'help_scoringformula_rankingonly';

        // A real example of the on-screen attempts-remaining pill (.mod-playercross-
        // attempts-count, styled in styles.css), injected into help_termexhausted/
        // help_finalguessexhausted through their own {$a} placeholder — keeps the CSS
        // class name out of the lang strings themselves, and keeps the placeholder
        // free to move wherever each translation's own sentence structure needs it.
        $attemptscountexample = html_writer::tag('span', '×N', ['class' => 'mod-playercross-attempts-count']);

        return [
            'helptitle' => get_string('help_title', 'mod_playercross'),
            'introtext' => get_string('help_intro', 'mod_playercross'),
            'legendrevealedlabel' => get_string('help_legend_revealed', 'mod_playercross'),
            'legendhiddenlabel' => get_string('help_legend_hidden', 'mod_playercross'),
            'termstext' => get_string('help_terms', 'mod_playercross'),
            'accentstext' => get_string('help_accents', 'mod_playercross'),
            'submittext' => get_string('help_submit', 'mod_playercross'),
            'showhint' => $showhint,
            'hinttext' => $showhint ? get_string('help_hint', 'mod_playercross') : '',
            'finalguesstext' => get_string('help_finalguess', 'mod_playercross'),
            'showfinalguessbonus' => $showgrading,
            'finalguessbonustext' => $showgrading
                ? get_string(
                    'help_finalguessbonus',
                    'mod_playercross',
                    (int)round(PLAYERCROSS_EARLY_GUESS_BONUS_RATIO * 100)
                )
                : '',
            // Independent of $showgrading: the ranking bonus is scored against the
            // fixed PLAYERCROSS_RANKING_BASE_POINTS, so it applies whenever ranking is
            // on, whether or not the activity also uses grading.
            'showrankingbonus' => $showranking,
            'rankingbonustext' => $showranking
                ? get_string(
                    'help_rankingbonus',
                    'mod_playercross',
                    (int)round(PLAYERCROSS_EARLY_GUESS_BONUS_RATIO * 100)
                )
                : '',
            'winconditiontext' => get_string($winconditionstring, 'mod_playercross'),
            'showtermloss' => $showtermloss,
            'termlosstext' => $showtermloss
                ? get_string('help_termexhausted', 'mod_playercross', $attemptscountexample)
                : '',
            'showfinalguessloss' => $showfinalguessloss,
            'finalguesslosstext' => $showfinalguessloss
                ? get_string('help_finalguessexhausted', 'mod_playercross', $attemptscountexample)
                : '',
            'showtimer' => $showtimer,
            'timertext' => $showtimer
                ? get_string('help_timer', 'mod_playercross', (int)round(((int)$instance->timer_seconds) / 60))
                : '',
            'showhud' => $showhud,
            'hudtext' => $showhud ? get_string('help_hud', 'mod_playercross') : '',
            'showgrading' => $showgrading,
            'gradingtext' => $showgrading
                ? get_string('help_grading', 'mod_playercross', round_presenter::grademethod_name($instance))
                : '',
            'showscoringformula' => $showscoringformula,
            'scoringformulatext' => $showscoringformula ? get_string($scoringformulastring, 'mod_playercross') : '',
            'reviewhint' => get_string('help_reviewhint', 'mod_playercross'),
        ];
    }
}
