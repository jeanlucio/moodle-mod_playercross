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
 * Usage report scheduled task.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\task;

use mod_playercross\local\usage_reporter;

/**
 * Scheduled task that periodically sends an anonymous usage report.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_report extends \core\task\scheduled_task {
    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskusagereport', 'mod_playercross');
    }

    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute(): void {
        if (!usage_reporter::is_due()) {
            mtrace('mod_playercross: usage report not due, skipping.');
            return;
        }

        $sent = usage_reporter::send();
        mtrace($sent
            ? 'mod_playercross: usage report sent.'
            : 'mod_playercross: usage report send failed, will retry next run.');
    }
}
