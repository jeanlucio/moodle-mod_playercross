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
 * External function: reset the session so the next PlayerCross round builds a fresh puzzle.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playercross\local\round_presenter;
use mod_playercross\local\round_service;

/**
 * Resets the finished round and ensures a fresh puzzle is ready for the lobby.
 */
class new_round extends external_api {
    /**
     * Returns parameter definitions for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Resets the round state and builds a new puzzle when the player is allowed to continue.
     *
     * @param int $cmid Course module id.
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('playercross', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playercross:view', $context);

        $instance = $DB->get_record('playercross', ['id' => $cm->instance], '*', MUST_EXIST);
        $userid = (int)$USER->id;

        round_service::new_round($cmid, $userid);

        $restrictionnotice = round_service::get_round_restriction_notice($instance, $userid);
        if ($restrictionnotice !== null) {
            return [
                'hastheme'         => false,
                'notification'     => $restrictionnotice,
                'notificationtype' => 'warning',
                'lobby'            => round_presenter::build_lobby_context(
                    $instance,
                    round_service::load_state($cmid, $userid),
                    $userid
                ),
            ];
        }

        $state = round_service::load_state($cmid, $userid);
        $state = round_service::ensure_round_state($state, $instance, $userid);
        round_service::save_state($cmid, $userid, $state);

        return [
            'hastheme'         => ((int)$state['themewordid'] > 0),
            'notification'     => '',
            'notificationtype' => '',
            'lobby'            => round_presenter::build_lobby_context($instance, $state, $userid),
        ];
    }

    /**
     * Returns the structure of the execute() return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'hastheme'         => new external_value(PARAM_BOOL, 'Whether a new puzzle is available for the round'),
            'notification'     => new external_value(
                PARAM_TEXT,
                'Notification message when a new round cannot start',
                VALUE_DEFAULT,
                ''
            ),
            'notificationtype' => new external_value(PARAM_ALPHA, 'Notification type', VALUE_DEFAULT, ''),
            'lobby'            => new external_single_structure([
                'cluestotal'        => new external_value(PARAM_INT, 'Total clues in the new round'),
                'timerenabled'      => new external_value(PARAM_BOOL, 'Whether the timer is enabled'),
                'lobbytimerinfo'    => new external_value(PARAM_TEXT, 'Timer info message for the lobby'),
                'hudstartcost'      => new external_value(PARAM_BOOL, 'Whether starting costs a PlayerHUD item'),
                'hudstartcostlabel' => new external_value(PARAM_TEXT, 'PlayerHUD cost label'),
                'canstart'          => new external_value(PARAM_BOOL, 'Whether the user can afford to start'),
                'startlabel'        => new external_value(PARAM_TEXT, 'Start-round button label'),
                'showgradingmethodinfo' => new external_value(
                    PARAM_BOOL,
                    'Whether the grading method info line is shown'
                ),
                'gradingmethodinfo' => new external_value(
                    PARAM_TEXT,
                    'Grading method info line, empty when not applicable'
                ),
                'roundsplayedlabel' => new external_value(PARAM_TEXT, 'Rounds played counter, e.g. "3 / 10"'),
            ]),
        ]);
    }
}
