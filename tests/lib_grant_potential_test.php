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
 * Tests for the playerhud_grant_potential callback in lib.php.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross;

/**
 * Tests for playercross_playerhud_grant_potential().
 *
 * @covers ::playercross_playerhud_grant_potential
 */
final class lib_grant_potential_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
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
            'blockname'         => 'playerhud',
            'parentcontextid'   => $ctx->id,
            'showinsubcontexts' => 0,
            'pagetypepattern'   => 'course-view-*',
            'subpagepattern'    => null,
            'defaultregion'     => 'side-pre',
            'defaultweight'     => 0,
            'configdata'        => base64_encode(serialize(new \stdClass())),
            'timecreated'       => time(),
            'timemodified'      => time(),
        ]);
    }

    /**
     * Inserts a block_playerhud_items record for the given block instance.
     *
     * @param int $blockinstanceid Block instance ID.
     * @param int $xp XP awarded per unit collected.
     * @return int Item ID.
     */
    private function make_item(int $blockinstanceid, int $xp): int {
        global $DB;
        return $DB->insert_record('block_playerhud_items', (object)[
            'blockinstanceid' => $blockinstanceid,
            'name'            => 'Gold Key',
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
     * An unrecognised block instance ID (does not resolve to any course) contributes nothing.
     *
     * @return void
     */
    public function test_returns_empty_for_unknown_block_instance(): void {
        $this->assertSame([], playercross_playerhud_grant_potential(999999));
    }

    /**
     * An activity with no win-reward item configured contributes nothing.
     *
     * @return void
     */
    public function test_returns_empty_when_no_reward_item_configured(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $this->getDataGenerator()->create_module('playercross', ['course' => $course->id]);

        $this->assertSame([], playercross_playerhud_grant_potential($biid));
    }

    /**
     * An activity with unlimited rounds contributes nothing — the anti-farming rule applied
     * to the real grant applies equally to the potential estimate.
     *
     * @return void
     */
    public function test_returns_empty_when_unlimited_rounds(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 50);
        $this->getDataGenerator()->create_module('playercross', [
            'course'              => $course->id,
            'hud_win_reward_item' => $itemid,
            'hud_win_reward_qty'  => 1,
            'max_rounds'          => 0,
        ]);

        $this->assertSame([], playercross_playerhud_grant_potential($biid));
    }

    /**
     * A bounded activity contributes qty x max_rounds x item xp, in a row shaped like
     * block_playerhud's own item/quest breakdown entries.
     *
     * @return void
     */
    public function test_returns_row_for_bounded_activity(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 100);
        $this->getDataGenerator()->create_module('playercross', [
            'course'              => $course->id,
            'hud_win_reward_item' => $itemid,
            'hud_win_reward_qty'  => 2,
            'max_rounds'          => 5,
        ]);

        $rows = playercross_playerhud_grant_potential($biid);

        $this->assertCount(1, $rows);
        $this->assertSame(200, $rows[0]['xp_each']);
        $this->assertSame(5, $rows[0]['total_uses']);
        $this->assertSame(1000, $rows[0]['xp_total']);
        $this->assertFalse($rows[0]['is_quest']);
    }

    /**
     * A win-reward item belonging to a different course's block instance contributes nothing —
     * the cross-course leak external_items::get_xp() prevents.
     *
     * @return void
     */
    public function test_returns_empty_when_reward_item_belongs_to_other_course(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $othercourse = $this->getDataGenerator()->create_course();
        $otherbiid = $this->make_block_instance($othercourse);
        $itemid = $this->make_item($otherbiid, 100);
        $this->getDataGenerator()->create_module('playercross', [
            'course'              => $course->id,
            'hud_win_reward_item' => $itemid,
            'hud_win_reward_qty'  => 1,
            'max_rounds'          => 5,
        ]);

        $this->assertSame([], playercross_playerhud_grant_potential($biid));
    }

    /**
     * Two bounded activities in the same course each contribute their own row.
     *
     * @return void
     */
    public function test_returns_one_row_per_bounded_activity(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 10);
        $this->getDataGenerator()->create_module('playercross', [
            'course' => $course->id, 'hud_win_reward_item' => $itemid, 'max_rounds' => 3,
        ]);
        $this->getDataGenerator()->create_module('playercross', [
            'course' => $course->id, 'hud_win_reward_item' => $itemid, 'max_rounds' => 4,
        ]);

        $this->assertCount(2, playercross_playerhud_grant_potential($biid));
    }
}
