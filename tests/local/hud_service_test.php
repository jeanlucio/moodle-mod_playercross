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
 * Unit tests for hud_service.
 *
 * Tests for get_block_instance_id always run.
 * Tests for item/inventory operations are skipped when block_playerhud is not installed.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Tests for hud_service.
 */
final class hud_service_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Skips the current test when block_playerhud is not installed.
     *
     * @return void
     */
    private function skip_if_no_playerhud(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
    }

    /**
     * Inserts a block_instances record for block_playerhud in the given course context.
     *
     * @param \stdClass $course Course object.
     * @return int Block instance ID.
     */
    private function make_block_instance(\stdClass $course): int {
        global $DB;
        $ctx = \context_course::instance($course->id);
        return $DB->insert_record('block_instances', (object)[
            'blockname'        => 'playerhud',
            'parentcontextid'  => $ctx->id,
            'showinsubcontexts' => 0,
            'pagetypepattern'  => 'course-view-*',
            'subpagepattern'   => null,
            'defaultregion'    => 'side-pre',
            'defaultweight'    => 0,
            'configdata'       => base64_encode(serialize(new \stdClass())),
            'timecreated'      => time(),
            'timemodified'     => time(),
        ]);
    }

    /**
     * Inserts a block_playerhud_items record for the given block instance.
     *
     * @param int $blockinstanceid Block instance ID.
     * @param string $name         Item display name.
     * @param int $xp              XP awarded per unit collected, 0 for none.
     * @return int Item ID.
     */
    private function make_item(int $blockinstanceid, string $name = 'Gold Key', int $xp = 0): int {
        global $DB;
        return $DB->insert_record('block_playerhud_items', (object)[
            'blockinstanceid' => $blockinstanceid,
            'name'            => $name,
            'xp'              => $xp,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    /**
     * Inserts one available inventory entry for the given user and item.
     *
     * @param int $userid  User ID.
     * @param int $itemid  Item ID.
     * @param int $offset  Seconds to subtract from time() to simulate ordering.
     * @return int Inventory record ID.
     */
    private function make_inventory(int $userid, int $itemid, int $offset = 0): int {
        global $DB;
        return $DB->insert_record('block_playerhud_inventory', (object)[
            'userid'      => $userid,
            'itemid'      => $itemid,
            'dropid'      => 0,
            'source'      => 'manual',
            'timecreated' => time() - $offset,
        ]);
    }

    // Tests for get_block_instance_id (no PlayerHUD tables required).

    /**
     * Tests that null is returned when no playerhud block exists in the course.
     *
     * @covers \mod_playercross\local\hud_service::get_block_instance_id
     * @return void
     */
    public function test_get_block_instance_id_returns_null_when_absent(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->assertNull(hud_service::get_block_instance_id($course->id));
    }

    /**
     * Tests that the correct block instance ID is returned when one exists.
     *
     * @covers \mod_playercross\local\hud_service::get_block_instance_id
     * @return void
     */
    public function test_get_block_instance_id_finds_block(): void {
        $course = $this->getDataGenerator()->create_course();
        $biid   = $this->make_block_instance($course);
        $this->assertSame($biid, hud_service::get_block_instance_id($course->id));
    }

    /**
     * Tests that a block in a different course is not returned.
     *
     * @covers \mod_playercross\local\hud_service::get_block_instance_id
     * @return void
     */
    public function test_get_block_instance_id_ignores_other_course(): void {
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $this->make_block_instance($course1);
        $this->assertNull(hud_service::get_block_instance_id($course2->id));
    }

    /**
     * Tests that is_installed reflects whether the block_playerhud plugin is present on
     * this site, independently of any course having added a block instance.
     *
     * @covers \mod_playercross\local\hud_service::is_installed
     * @return void
     */
    public function test_is_installed_matches_class_presence(): void {
        $this->assertSame(class_exists('\block_playerhud\game'), hud_service::is_installed());
    }

    // Tests that require block_playerhud tables.

    /**
     * Tests that is_available_for_course is true once a block instance exists.
     *
     * @covers \mod_playercross\local\hud_service::is_available_for_course
     * @return void
     */
    public function test_is_available_for_course_true_with_block_instance(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $this->make_block_instance($course);
        $this->assertTrue(hud_service::is_available_for_course($course->id));
    }

    /**
     * Tests that is_available_for_course is false when the course has no block instance,
     * even though the block plugin itself is installed.
     *
     * @covers \mod_playercross\local\hud_service::is_available_for_course
     * @return void
     */
    public function test_is_available_for_course_false_without_block_instance(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $this->assertFalse(hud_service::is_available_for_course($course->id));
    }

    /**
     * Tests that is_available_for_course ignores a block instance living in another course.
     *
     * @covers \mod_playercross\local\hud_service::is_available_for_course
     * @return void
     */
    public function test_is_available_for_course_ignores_other_course(): void {
        $this->skip_if_no_playerhud();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $this->make_block_instance($course1);
        $this->assertFalse(hud_service::is_available_for_course($course2->id));
    }

    /**
     * Tests that get_item_name returns the item name formatted for display.
     *
     * @covers \mod_playercross\local\hud_service::get_item_name
     * @return void
     */
    public function test_get_item_name(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $biid   = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 'Gold Key');
        $this->assertSame('Gold Key', hud_service::get_item_name($biid, $itemid));
    }

    /**
     * Tests that get_item_name returns an empty string for item id zero.
     *
     * @covers \mod_playercross\local\hud_service::get_item_name
     * @return void
     */
    public function test_get_item_name_zero_returns_empty(): void {
        $this->skip_if_no_playerhud();
        $this->assertSame('', hud_service::get_item_name(0, 0));
    }

    /**
     * Tests that get_item_name returns an empty string for an item belonging to a different
     * block instance — the cross-course leak this delegation to external_items prevents.
     *
     * @covers \mod_playercross\local\hud_service::get_item_name
     * @return void
     */
    public function test_get_item_name_empty_for_other_instance_item(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $otherbiid = $this->make_block_instance($othercourse);
        $itemid = $this->make_item($otherbiid, 'Gold Key');

        $this->assertSame('', hud_service::get_item_name($biid, $itemid));
    }

    /**
     * Tests that get_items_for_block returns only enabled items sorted by name.
     *
     * @covers \mod_playercross\local\hud_service::get_items_for_block
     * @return void
     */
    public function test_get_items_for_block(): void {
        global $DB;
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $biid   = $this->make_block_instance($course);
        $this->make_item($biid, 'Zinc Key');
        $this->make_item($biid, 'Alpha Key');
        // Disabled item — must not appear.
        $DB->insert_record('block_playerhud_items', (object)[
            'blockinstanceid' => $biid,
            'name'            => 'Hidden',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 0,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);

        $items = hud_service::get_items_for_block($biid);
        $this->assertCount(2, $items);
        $this->assertSame('Alpha Key', $items[0]->name);
        $this->assertSame('Zinc Key', $items[1]->name);
    }

    /**
     * Tests that consume_items returns false when the user has fewer items than requested.
     *
     * @covers \mod_playercross\local\hud_service::consume_items
     * @return void
     */
    public function test_consume_items_insufficient(): void {
        $this->skip_if_no_playerhud();
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid   = $this->make_block_instance($course);
        $itemid = $this->make_item($biid);
        $this->make_inventory($user->id, $itemid);

        $result = hud_service::consume_items($biid, $user->id, $itemid, 2);
        $this->assertFalse($result);
    }

    /**
     * Tests that consume_items returns true (waived, not blocked) for an item belonging to a
     * different block instance — a foreign or deleted item can never be restocked, so the cost
     * is dispensed rather than locking the student out forever.
     *
     * @covers \mod_playercross\local\hud_service::consume_items
     * @return void
     */
    public function test_consume_items_waived_for_other_instance_item(): void {
        $this->skip_if_no_playerhud();
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $otherbiid = $this->make_block_instance($othercourse);
        $itemid = $this->make_item($otherbiid);

        $result = hud_service::consume_items($biid, $user->id, $itemid, 1);
        $this->assertTrue($result);
    }

    /**
     * Tests that consume_items marks the exact quantity as consumed and returns true.
     *
     * @covers \mod_playercross\local\hud_service::consume_items
     * @return void
     */
    public function test_consume_items_success(): void {
        global $DB;
        $this->skip_if_no_playerhud();
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid   = $this->make_block_instance($course);
        $itemid = $this->make_item($biid);
        $this->make_inventory($user->id, $itemid);
        $this->make_inventory($user->id, $itemid);

        $result = hud_service::consume_items($biid, $user->id, $itemid, 2);
        $this->assertTrue($result);

        $remaining = $DB->count_records_select(
            'block_playerhud_inventory',
            "userid = :uid AND itemid = :iid AND source NOT IN ('revoked','consumed')",
            ['uid' => $user->id, 'iid' => $itemid]
        );
        $this->assertSame(0, $remaining);
    }

    /**
     * Tests that consume_items follows FIFO order (oldest entries consumed first).
     *
     * @covers \mod_playercross\local\hud_service::consume_items
     * @return void
     */
    public function test_consume_items_fifo(): void {
        global $DB;
        $this->skip_if_no_playerhud();
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid   = $this->make_block_instance($course);
        $itemid = $this->make_item($biid);
        $oldest = $this->make_inventory($user->id, $itemid, 100);
        $middle = $this->make_inventory($user->id, $itemid, 50);
        $newest = $this->make_inventory($user->id, $itemid, 0);

        hud_service::consume_items($biid, $user->id, $itemid, 2);

        $this->assertSame('consumed', $DB->get_field('block_playerhud_inventory', 'source', ['id' => $oldest]));
        $this->assertSame('consumed', $DB->get_field('block_playerhud_inventory', 'source', ['id' => $middle]));
        $this->assertNotSame('consumed', $DB->get_field('block_playerhud_inventory', 'source', ['id' => $newest]));
    }

    /**
     * Tests that consume_items with qty zero always returns true without touching the inventory.
     *
     * @covers \mod_playercross\local\hud_service::consume_items
     * @return void
     */
    public function test_consume_items_zero_qty_short_circuits(): void {
        $this->skip_if_no_playerhud();
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid   = $this->make_block_instance($course);
        $itemid = $this->make_item($biid);
        $this->assertTrue(hud_service::consume_items($biid, $user->id, $itemid, 0));
    }

    /**
     * Tests that grant_items creates one inventory row per unit, tagged with the
     * 'playercross' source, and awards the item's XP multiplied by the quantity.
     *
     * @covers \mod_playercross\local\hud_service::grant_items
     * @return void
     */
    public function test_grant_items_creates_inventory_and_awards_xp(): void {
        global $DB;
        $this->skip_if_no_playerhud();
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid   = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 'Gold Key', 30);

        hud_service::grant_items($biid, $user->id, $itemid, 2, false);

        $rows = $DB->get_records('block_playerhud_inventory', ['userid' => $user->id, 'itemid' => $itemid]);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('playercross', $row->source);
            $this->assertSame(0, (int)$row->dropid);
        }

        $currentxp = $DB->get_field('block_playerhud_user', 'currentxp', [
            'blockinstanceid' => $biid,
            'userid'          => $user->id,
        ]);
        $this->assertSame(60, (int)$currentxp);
    }

    /**
     * Tests that grant_items still grants the item, but withholds its XP, when the
     * caller flags the source as unbounded — the same anti-farming outcome
     * block_playerhud itself applies to its own infinite drops.
     *
     * @covers \mod_playercross\local\hud_service::grant_items
     * @return void
     */
    public function test_grant_items_suppresses_xp_when_flagged(): void {
        global $DB;
        $this->skip_if_no_playerhud();
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid   = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 'Gold Key', 30);

        hud_service::grant_items($biid, $user->id, $itemid, 1, true);

        $this->assertSame(1, $DB->count_records('block_playerhud_inventory', ['userid' => $user->id, 'itemid' => $itemid]));
        $currentxp = $DB->get_field('block_playerhud_user', 'currentxp', [
            'blockinstanceid' => $biid,
            'userid'          => $user->id,
        ]);
        $this->assertSame(0, (int)$currentxp);
    }

    /**
     * Tests that a zero-XP item never changes the player's XP, regardless of the
     * suppressxp flag — there is nothing to withhold or award either way.
     *
     * @covers \mod_playercross\local\hud_service::grant_items
     * @return void
     */
    public function test_grant_items_zero_xp_item_awards_nothing(): void {
        global $DB;
        $this->skip_if_no_playerhud();
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid   = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 'Trinket', 0);

        hud_service::grant_items($biid, $user->id, $itemid, 3, false);

        $this->assertSame(3, $DB->count_records('block_playerhud_inventory', ['userid' => $user->id, 'itemid' => $itemid]));
        $this->assertSame(0, (int)$DB->count_records('block_playerhud_user', [
            'blockinstanceid' => $biid,
            'userid'          => $user->id,
        ]));
    }

    /**
     * Tests that granting a non-existent item is a silent no-op.
     *
     * @covers \mod_playercross\local\hud_service::grant_items
     * @return void
     */
    public function test_grant_items_invalid_item_noop(): void {
        global $DB;
        $this->skip_if_no_playerhud();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);

        hud_service::grant_items($biid, $user->id, 999999, 1, false);

        $this->assertSame(0, $DB->count_records('block_playerhud_inventory', ['userid' => $user->id]));
    }

    /**
     * Tests that granting an item belonging to a different block instance is a no-op — the
     * cross-course leak this delegation to external_items prevents.
     *
     * @covers \mod_playercross\local\hud_service::grant_items
     * @return void
     */
    public function test_grant_items_other_instance_item_noop(): void {
        global $DB;
        $this->skip_if_no_playerhud();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $otherbiid = $this->make_block_instance($othercourse);
        $itemid = $this->make_item($otherbiid, 'Gold Key', 30);

        hud_service::grant_items($biid, $user->id, $itemid, 1, false);

        $this->assertSame(0, $DB->count_records('block_playerhud_inventory', ['userid' => $user->id]));
    }

    /**
     * Tests that grant_items with qty zero is a no-op.
     *
     * @covers \mod_playercross\local\hud_service::grant_items
     * @return void
     */
    public function test_grant_items_zero_qty_short_circuits(): void {
        global $DB;
        $this->skip_if_no_playerhud();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);

        hud_service::grant_items($biid, $user->id, 999, 0, false);

        $this->assertSame(0, $DB->count_records('block_playerhud_inventory', ['userid' => $user->id]));
    }
}
