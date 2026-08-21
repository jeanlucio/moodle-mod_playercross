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
 * Attempt deleted event.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\event;

/**
 * Fired by attempts_history_service::delete_attempts() whenever a teacher or manager
 * deletes a student's finished round from the attempts report.
 *
 * Expected data:
 *   objectid      — id of the playercross_attempts record that was deleted
 *   context       — module context
 *   relateduserid — id of the student whose attempt was deleted
 *   other         — ['playercrossid' => int]
 */
class attempt_deleted extends \core\event\base {
    #[\Override]
    protected function init(): void {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'playercross_attempts';
    }

    #[\Override]
    public static function get_name(): string {
        return get_string('event_attempt_deleted', 'mod_playercross');
    }

    #[\Override]
    public function get_description(): string {
        return "The user with id '{$this->userid}' deleted the attempt with id '{$this->objectid}' " .
            "belonging to the playercross activity with course module id '{$this->contextinstanceid}' " .
            "for the user with id '{$this->relateduserid}'.";
    }

    #[\Override]
    protected function validate_data(): void {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }

        if (!isset($this->other['playercrossid'])) {
            throw new \coding_exception('The \'playercrossid\' value must be set in other.');
        }
    }

    #[\Override]
    public static function get_objectid_mapping(): array {
        // The attempt row no longer exists after this event fires, so there is nothing
        // for a course restore to remap it onto.
        return ['db' => 'playercross_attempts', 'restore' => self::NOT_MAPPED];
    }
}
