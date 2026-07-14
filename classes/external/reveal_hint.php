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
 * External function: reveal the hint for one clue.
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
use mod_playercross\local\round_service;

/**
 * Reveals a specific clue's hint, optionally consuming a PlayerHUD item cost.
 *
 * Each clue has its own independent hint cost — never a single hint shared by the
 * whole round — so this always operates on exactly one clue id.
 */
class reveal_hint extends external_api {
    /**
     * Returns parameter definitions for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'Course module id'),
            'clueid' => new external_value(PARAM_INT, 'Clue word id'),
        ]);
    }

    /**
     * Reveals the hint for the given clue.
     *
     * @param int $cmid Course module id.
     * @param int $clueid Clue word id.
     * @return array
     */
    public static function execute(int $cmid, int $clueid): array {
        global $DB, $USER;

        [
            'cmid'   => $cmid,
            'clueid' => $clueid,
        ] = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'clueid' => $clueid]
        );

        $cm = get_coursemodule_from_id('playercross', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playercross:view', $context);

        $instance = $DB->get_record('playercross', ['id' => $cm->instance], '*', MUST_EXIST);
        $userid = (int)$USER->id;

        $state = round_service::load_state($cmid, $userid);
        $state = round_service::ensure_round_state($state, $instance, $userid);

        [$state, $notification, $notificationtype] = round_service::reveal_hint($state, $instance, $userid, $clueid);
        round_service::save_state($cmid, $userid, $state);

        $hintvalue = '';
        foreach ($state['clues'] as $clue) {
            if ((int)$clue['wordid'] === $clueid && $clue['hintrevealed']) {
                $hintvalue = $clue['hint'];
                break;
            }
        }

        return [
            'success'          => ($notification === null),
            'clueid'           => $clueid,
            'hintvalue'        => $hintvalue,
            'notification'     => $notification ?? '',
            'notificationtype' => $notificationtype ?? '',
        ];
    }

    /**
     * Returns the structure of the execute() return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'          => new external_value(PARAM_BOOL, 'Whether the hint was revealed'),
            'clueid'           => new external_value(PARAM_INT, 'Clue word id'),
            'hintvalue'        => new external_value(PARAM_RAW, 'Hint text, empty when not revealed'),
            'notification'     => new external_value(PARAM_TEXT, 'User-facing feedback message', VALUE_DEFAULT, ''),
            'notificationtype' => new external_value(
                PARAM_ALPHA,
                'Notification type: success or warning',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }
}
