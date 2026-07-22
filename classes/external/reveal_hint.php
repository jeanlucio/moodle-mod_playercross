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
 * External function: reveal one mystery-phrase letter.
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
 * Reveals one still-hidden mystery-phrase slot, optionally consuming a PlayerHUD item
 * cost. A single round-wide action, not scoped to any clue: the revealed slot lights
 * up in the mystery phrase and in every pending clue that shares it, the same way
 * solving a clue would (see round_service::reveal_hint()). Can, in the edge case where
 * this was the very last hidden slot in the whole round, finish the round on the spot —
 * see round_service::resolve_fully_revealed_clues() and ::confirm_fully_revealed_theme().
 */
class reveal_hint extends external_api {
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
     * Reveals one mystery-phrase letter and returns the updated round panel.
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

        [$state, $notification, $notificationtype, $toast] = round_service::reveal_hint($state, $instance, $cmid, $userid);
        round_service::save_state($cmid, $userid, $state);

        return [
            'notification'     => $notification ?? '',
            'notificationtype' => $notificationtype ?? '',
            'toast'            => $toast,
            'finished'         => !empty($state['finished']),
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
            'notification'     => new external_value(PARAM_TEXT, 'User-facing feedback message', VALUE_DEFAULT, ''),
            'notificationtype' => new external_value(
                PARAM_ALPHA,
                'Notification type: success or warning',
                VALUE_DEFAULT,
                ''
            ),
            'toast' => new external_value(
                PARAM_BOOL,
                'Whether to show the notification as an auto-dismissing toast instead of a persistent one',
                VALUE_DEFAULT,
                false
            ),
            'finished' => new external_value(PARAM_BOOL, 'Whether the round has ended', VALUE_DEFAULT, false),
            'panel' => submit_clue_guess::panel_structure(),
        ]);
    }
}
