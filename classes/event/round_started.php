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
 * Round started event.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\event;

/**
 * Fired by round_service::ensure_round_state() when a fresh puzzle is built for a round.
 *
 * Expected data:
 *   objectid  — id of the playercross_words record used as the mystery phrase
 *   context   — module context
 *   other     — ['cluestotal' => int]
 */
class round_started extends \core\event\base {
    #[\Override]
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'playercross_words';
    }

    #[\Override]
    public static function get_name(): string {
        return get_string('event_round_started', 'mod_playercross');
    }

    #[\Override]
    public function get_description(): string {
        return "The user with id '{$this->userid}' started a new round in the playercross activity " .
            "with course module id '{$this->contextinstanceid}'.";
    }

    #[\Override]
    public static function get_objectid_mapping(): array {
        return ['db' => 'playercross_words', 'restore' => 'playercross_words'];
    }
}
