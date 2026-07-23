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
 * External function: end the current PlayerCross round by forfeit or timeout.
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
use invalid_parameter_exception;
use mod_playercross\local\round_presenter;
use mod_playercross\local\round_service;

/**
 * Ends a round without a winning guess: either the player forfeits or the timer expired.
 */
class end_round extends external_api {
    /** @var string[] Allowed values for the "reason" parameter. */
    private const REASONS = ['forfeit', 'timeout'];

    /**
     * Returns parameter definitions for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'Course module id'),
            'reason' => new external_value(PARAM_ALPHA, 'End reason: forfeit or timeout'),
        ]);
    }

    /**
     * Ends the current round for the given reason.
     *
     * @param int $cmid Course module id.
     * @param string $reason Either "forfeit" or "timeout".
     * @return array
     */
    public static function execute(int $cmid, string $reason): array {
        global $DB, $USER;

        [
            'cmid'   => $cmid,
            'reason' => $reason,
        ] = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'reason' => $reason]
        );

        if (!in_array($reason, self::REASONS, true)) {
            throw new invalid_parameter_exception('Invalid reason: ' . $reason);
        }

        $cm = get_coursemodule_from_id('playercross', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playercross:view', $context);

        $instance = $DB->get_record('playercross', ['id' => $cm->instance], '*', MUST_EXIST);
        $userid = (int)$USER->id;

        $state = round_service::load_state($cmid, $userid);

        [$state, $notification, $notificationtype, $toast] = $reason === 'forfeit'
            ? round_service::forfeit($state, $instance, $cmid, $userid)
            : round_service::timeout($state, $instance, $cmid, $userid);

        round_service::save_state($cmid, $userid, $state);

        return [
            'finished'         => !empty($state['finished']),
            'notification'     => $notification ?? '',
            'notificationtype' => $notificationtype ?? '',
            'toast'            => $toast,
            'panel'            => round_presenter::build_round_panel_context($instance, $cm, $state, $userid),
        ];
    }

    /**
     * Returns the structure of the execute() return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'finished'         => new external_value(PARAM_BOOL, 'Whether the round has ended'),
            'notification'     => new external_value(PARAM_TEXT, 'User-facing feedback message', VALUE_DEFAULT, ''),
            'notificationtype' => new external_value(PARAM_ALPHA, 'Notification type', VALUE_DEFAULT, ''),
            'toast' => new external_value(
                PARAM_BOOL,
                'Whether to show the notification as an auto-dismissing toast instead of a persistent one',
                VALUE_DEFAULT,
                false
            ),
            'panel'            => submit_clue_guess::panel_structure(),
        ]);
    }
}
