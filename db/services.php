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
 * External function definitions for mod_playercross.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_playercross_submit_clue_guess' => [
        'classname'     => 'mod_playercross\external\submit_clue_guess',
        'description'   => 'Submit a guess for one clue of the current PlayerCross round.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'mod/playercross:view',
        'loginrequired' => true,
    ],
    'mod_playercross_submit_final_guess' => [
        'classname'     => 'mod_playercross\external\submit_final_guess',
        'description'   => 'Submit a direct guess of the mystery phrase.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'mod/playercross:view',
        'loginrequired' => true,
    ],
    'mod_playercross_reveal_hint' => [
        'classname'     => 'mod_playercross\external\reveal_hint',
        'description'   => 'Reveal the hint for one clue of the current PlayerCross round.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'mod/playercross:view',
        'loginrequired' => true,
    ],
    'mod_playercross_end_round' => [
        'classname'     => 'mod_playercross\external\end_round',
        'description'   => 'End the current PlayerCross round by forfeit or timeout.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'mod/playercross:view',
        'loginrequired' => true,
    ],
    'mod_playercross_start_round' => [
        'classname'     => 'mod_playercross\external\start_round',
        'description'   => 'Start the timer for the current PlayerCross round.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'mod/playercross:view',
        'loginrequired' => true,
    ],
    'mod_playercross_new_round' => [
        'classname'     => 'mod_playercross\external\new_round',
        'description'   => 'Reset the session so the next PlayerCross round builds a fresh puzzle.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'mod/playercross:view',
        'loginrequired' => true,
    ],
];
