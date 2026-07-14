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
 * External function: submit a direct guess of the mystery phrase.
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
 * Validates a direct guess of the mystery phrase, available at any point in the
 * round even with clues still pending.
 */
class submit_final_guess extends external_api {
    /**
     * Returns parameter definitions for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'  => new external_value(PARAM_INT, 'Course module id'),
            'guess' => new external_value(PARAM_TEXT, 'Player guess text'),
        ]);
    }

    /**
     * Validates the direct guess and returns the updated round panel.
     *
     * @param int $cmid Course module id.
     * @param string $guess Player guess.
     * @return array
     */
    public static function execute(int $cmid, string $guess): array {
        global $DB, $USER;

        [
            'cmid'  => $cmid,
            'guess' => $guess,
        ] = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'guess' => $guess]
        );

        $cm = get_coursemodule_from_id('playercross', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playercross:view', $context);

        $instance = $DB->get_record('playercross', ['id' => $cm->instance], '*', MUST_EXIST);
        $userid = (int)$USER->id;

        $state = round_service::load_state($cmid, $userid);
        $state = round_service::ensure_round_state($state, $instance, $userid);

        [$state, $correct, $notification, $notificationtype] = round_service::submit_final_guess(
            $state,
            $instance,
            $cmid,
            $userid,
            $guess
        );

        round_service::save_state($cmid, $userid, $state);

        return [
            'correct'          => $correct,
            'finished'         => !empty($state['finished']),
            'notification'     => $notification ?? '',
            'notificationtype' => $notificationtype ?? '',
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
            'correct'          => new external_value(PARAM_BOOL, 'Whether the mystery phrase guess was correct'),
            'finished'         => new external_value(PARAM_BOOL, 'Whether the round has ended'),
            'notification'     => new external_value(PARAM_TEXT, 'User-facing feedback message', VALUE_DEFAULT, ''),
            'notificationtype' => new external_value(
                PARAM_ALPHA,
                'Notification type: success or warning',
                VALUE_DEFAULT,
                ''
            ),
            'panel' => submit_clue_guess::panel_structure(),
        ]);
    }
}
