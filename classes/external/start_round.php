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
 * External function: start the timer for the current PlayerCross round.
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
 * Leaves the lobby and starts the round timer, optionally consuming a PlayerHUD item
 * cost. The puzzle itself is already sitting in session from the page's GET-time
 * ensure_round_state() call; this only starts the clock.
 */
class start_round extends external_api {
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
     * Starts the round timer for the current user.
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

        $state = round_service::load_state($cmid, $userid);
        $state = round_service::ensure_round_state($state, $instance, $cmid, $userid);

        if ((int)$state['themewordid'] === 0 || !empty($state['finished']) || !empty($state['roundstarted'])) {
            return [
                'success'          => false,
                'notification'     => '',
                'notificationtype' => '',
                'toast'            => true,
                'panel'            => round_presenter::build_round_panel_context($instance, $cm, $state, $userid),
            ];
        }

        [$state, $notification, $notificationtype, $toast] = round_service::start_round($state, $instance, $userid);
        round_service::save_state($cmid, $userid, $state);

        return [
            'success'          => ($notification === null),
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
            'success'          => new external_value(PARAM_BOOL, 'Whether the round timer started'),
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
