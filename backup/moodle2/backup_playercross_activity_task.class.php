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
 * Backup task for mod_playercross.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/playercross/backup/moodle2/backup_playercross_stepslib.php');

/**
 * Provides the steps that perform one complete backup of a PlayerCross activity instance.
 */
class backup_playercross_activity_task extends backup_activity_task {
    /**
     * No specific settings for this activity.
     *
     * @return void
     */
    protected function define_my_settings(): void {
    }

    /**
     * Defines the backup step that will serialize the activity into the file.
     *
     * @return void
     */
    protected function define_my_steps(): void {
        $this->add_step(new backup_playercross_activity_structure_step(
            'playercross_structure',
            'playercross.xml'
        ));
    }

    /**
     * Encodes embedded content-links from view.php and index.php into portable backup form.
     *
     * @param string $content HTML content to encode.
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to view.php?id=N.
        $pattern = "/{$base}\/mod\/playercross\/view\.php\?id=([0-9]+)/";
        $content = preg_replace($pattern, '$@PLAYERCROSSVIEWBYID*$1@$', $content);

        // Link to index.php?id=N.
        $pattern = "/{$base}\/mod\/playercross\/index\.php\?id=([0-9]+)/";
        $content = preg_replace($pattern, '$@PLAYERCROSSINDEX*$1@$', $content);

        return $content;
    }
}
