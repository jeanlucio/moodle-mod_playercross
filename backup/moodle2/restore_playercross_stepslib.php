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
 * Restore structure step for mod_playercross.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Processes the XML tree produced by backup and rebuilds the database records.
 */
class restore_playercross_activity_structure_step extends restore_activity_structure_step {
    /**
     * Returns the path elements the restore engine should process.
     *
     * @return restore_path_element[]
     */
    protected function define_structure(): array {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('playercross', '/activity/playercross');
        $paths[] = new restore_path_element(
            'playercross_word',
            '/activity/playercross/words/word'
        );

        if ($userinfo) {
            $paths[] = new restore_path_element(
                'playercross_attempt',
                '/activity/playercross/attempts/attempt'
            );
        }

        // Wrap with the generic '/activity' path so the base class's process_activity()
        // runs: it registers the old-to-new context mapping and the old activity id.
        // Without this, restore_calendarevents_structure_step::after_execute() (a generic
        // step that runs for every activity) fails with unknown_context_mapping, and
        // course_format\local\cmactions::duplicate() never reaches its post-restore
        // cleanup (renaming to "(copy)", moving to the target section, rebuilding the
        // course cache) since the exception aborts the restore plan first.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Resolves a backed-up PlayerHUD item ID to the item that should be referenced in the
     * restored copy, or 0 if none applies.
     *
     * Tries, in order: (1) the playerhud_item restore mapping, when the PlayerHUD block was
     * part of the same restore (full course backup/restore, or "Import") — block_playerhud's
     * own restore task always runs before this activity's, so the mapping is already
     * available here, no after_execute()/after_restore() deferral needed; (2) if no mapping
     * was registered, whether the original ID still legitimately belongs to the destination
     * course's own PlayerHUD block instance ("Duplicate activity", which never restores the
     * block, so nothing needs remapping); (3) otherwise the original ID belongs to a
     * different course's PlayerHUD, or PlayerHUD isn't installed on this site at all, and
     * must be dropped rather than risk operating on the wrong course's item —
     * block_playerhud_items.id is a single site-wide sequence, not scoped per course.
     *
     * @param int $oldid Backed-up item ID, 0 if the field was not configured.
     * @return int
     */
    private function resolve_hud_item(int $oldid): int {
        if ($oldid <= 0) {
            return 0;
        }

        $mapped = $this->get_mappingid('playerhud_item', $oldid);
        if ($mapped) {
            return (int)$mapped;
        }

        if (!class_exists('\block_playerhud\local\external_items')) {
            return 0;
        }

        $blockinstanceid = \mod_playercross\local\hud_service::get_block_instance_id($this->get_courseid());
        if ($blockinstanceid === null) {
            return 0;
        }

        return \block_playerhud\local\external_items::belongs_to_instance($oldid, $blockinstanceid) ? $oldid : 0;
    }

    /**
     * Restores the root playercross instance record.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playercross(array|object $data): void {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->course = $this->get_courseid();
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        // Remap glossaryid if the glossary was included in the backup.
        if (!empty($data->glossaryid)) {
            $data->glossaryid = $this->get_mappingid('glossary', $data->glossaryid, 0);
        }

        $data->hud_round_cost_item = $this->resolve_hud_item((int)($data->hud_round_cost_item ?? 0));
        $data->hud_hint_cost_item = $this->resolve_hud_item((int)($data->hud_hint_cost_item ?? 0));
        $data->hud_win_reward_item = $this->resolve_hud_item((int)($data->hud_win_reward_item ?? 0));

        $newitemid = $DB->insert_record('playercross', $data);
        $this->apply_activity_instance($newitemid);
        $this->set_mapping('playercross', $oldid, $newitemid);
    }

    /**
     * Restores a word record belonging to the activity.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playercross_word(array|object $data): void {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->playercrossid = $this->get_new_parentid('playercross');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // Remap addedby to the restored user; fall back to 0 (anonymous) when unmapped.
        $data->addedby = (int)$this->get_mappingid('user', $data->addedby, 0);

        // Remap the source glossary when it was included in the backup.
        if (!empty($data->glossaryid)) {
            $data->glossaryid = $this->get_mappingid('glossary', $data->glossaryid, 0);
        }

        $newitemid = $DB->insert_record('playercross_words', $data);
        // Register mapping so attempts can resolve their themewordid cross-reference.
        $this->set_mapping('playercross_words', $oldid, $newitemid);
    }

    /**
     * Restores a student attempt record (only when userinfo is enabled).
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playercross_attempt(array|object $data): void {
        global $DB;

        $data = (object)$data;

        $data->playercrossid = $this->get_new_parentid('playercross');
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        $data->userid = (int)$this->get_mappingid('user', $data->userid);
        $data->themewordid = (int)$this->get_mappingid('playercross_words', $data->themewordid);

        // Skip orphaned attempts (theme word or user not mapped).
        if (empty($data->userid) || empty($data->themewordid)) {
            return;
        }

        $DB->insert_record('playercross_attempts', $data);
    }

    /**
     * Restores files embedded in the activity's intro editor field.
     *
     * The grade item itself is not touched here: restore_activity_grades_structure_step
     * (added generically by restore_activity_task for every gradable module) already
     * restores it. Calling playercross_grade_item_update() again here would race against
     * that generic step and leave two grade_items for the same instance.
     *
     * @return void
     */
    protected function after_execute(): void {
        $this->add_related_files('mod_playercross', 'intro', null);
    }
}
