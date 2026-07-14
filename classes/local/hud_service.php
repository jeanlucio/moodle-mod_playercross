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
 * PlayerHUD integration helpers for mod_playercross.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Provides item-cost integration between mod_playercross and block_playerhud.
 *
 * Direct port of mod_playerwords\local\hud_service — all methods gracefully return
 * neutral values when PlayerHUD is not installed.
 */
class hud_service {
    /**
     * Returns the PlayerHUD block instance ID for the given course, or null if none.
     *
     * @param int $courseid Course ID.
     * @return int|null
     */
    public static function get_block_instance_id(int $courseid): ?int {
        global $DB;

        $sql = "SELECT bi.id
                  FROM {block_instances} bi
                  JOIN {context} ctx ON bi.parentcontextid = ctx.id
                 WHERE bi.blockname = 'playerhud'
                   AND ctx.contextlevel = :clevel
                   AND ctx.instanceid  = :courseid";

        $id = $DB->get_field_sql($sql, [
            'clevel'   => CONTEXT_COURSE,
            'courseid' => $courseid,
        ]);

        return ($id !== false) ? (int)$id : null;
    }

    /**
     * Whether the block_playerhud plugin is installed on this site at all, regardless of
     * whether any course has actually added a block instance yet.
     *
     * @return bool
     */
    public static function is_installed(): bool {
        return class_exists('\block_playerhud\game');
    }

    /**
     * Whether PlayerHUD integration should be offered for this course: the block
     * plugin must be installed, and a block_playerhud instance must actually exist
     * at course level in this specific course.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    public static function is_available_for_course(int $courseid): bool {
        return self::is_installed() && self::get_block_instance_id($courseid) !== null;
    }

    /**
     * Resolves the block instance ID for an activity's own course, returning 0 (not null)
     * when PlayerHUD is unavailable — callers that gate on "<= 0 means unavailable" can use
     * this directly without a separate null check.
     *
     * @param \stdClass $instance Activity instance record.
     * @return int
     */
    public static function resolve_block_instance_id(\stdClass $instance): int {
        return (int)(self::get_block_instance_id((int)$instance->course) ?? 0);
    }

    /**
     * Returns enabled items for a block instance, sorted by name.
     *
     * @param int $blockinstanceid Block instance ID.
     * @return \stdClass[] Array of objects with id and name fields.
     */
    public static function get_items_for_block(int $blockinstanceid): array {
        global $DB;
        return array_values($DB->get_records(
            'block_playerhud_items',
            ['blockinstanceid' => $blockinstanceid, 'enabled' => 1],
            'name ASC',
            'id, name'
        ));
    }

    /**
     * Returns how many available (not consumed or revoked) units of an item a user
     * currently holds. Zero if the item does not belong to $blockinstanceid.
     *
     * @param int $blockinstanceid Block instance ID the item must belong to.
     * @param int $userid User ID.
     * @param int $itemid Item ID.
     * @return int
     */
    public static function get_available_quantity(int $blockinstanceid, int $userid, int $itemid): int {
        return \block_playerhud\local\external_items::get_available_quantity($blockinstanceid, $itemid, $userid);
    }

    /**
     * Returns the formatted display name of an item, or empty string if it does not belong to
     * $blockinstanceid.
     *
     * @param int $blockinstanceid Block instance ID the item must belong to.
     * @param int $itemid Item ID.
     * @return string
     */
    public static function get_item_name(int $blockinstanceid, int $itemid): string {
        return \block_playerhud\local\external_items::get_name($blockinstanceid, $itemid);
    }

    /**
     * Atomically consumes $qty items of $itemid from $userid's inventory, FIFO (oldest first).
     *
     * Returns true both on a genuine successful consumption and when the item does not belong
     * to $blockinstanceid (deleted, or configured for a different course) — a cost that can
     * never be paid should be waived, not block the student forever. Returns false only for a
     * genuine insufficient balance on a valid item.
     *
     * @param int $blockinstanceid Block instance ID the item must belong to.
     * @param int $userid User ID.
     * @param int $itemid Item ID from block_playerhud_items.
     * @param int $qty Number of items to consume.
     * @return bool
     */
    public static function consume_items(int $blockinstanceid, int $userid, int $itemid, int $qty): bool {
        return \block_playerhud\local\external_items::consume($blockinstanceid, $itemid, $userid, $qty) !== false;
    }

    /**
     * Grants $qty units of $itemid to $userid, awarding the item's own XP value unless
     * $suppressxp is set. A no-op when the item does not belong to $blockinstanceid or is
     * disabled.
     *
     * @param int $blockinstanceid Block instance ID the item must belong to.
     * @param int $userid User ID.
     * @param int $itemid Item ID from block_playerhud_items.
     * @param int $qty Number of items to grant.
     * @param bool $suppressxp Whether to withhold the item's XP even though it was granted.
     * @return void
     */
    public static function grant_items(int $blockinstanceid, int $userid, int $itemid, int $qty, bool $suppressxp): void {
        \block_playerhud\local\external_items::grant($blockinstanceid, $itemid, $userid, $qty, 'playercross', $suppressxp);
    }
}
