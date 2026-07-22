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
 * External function tests for reveal_hint.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use core_external\external_api;

/**
 * Tests for the mod_playercross_reveal_hint web service.
 *
 * reveal_hint is a round-wide action (SCOPE.md §20.2 v1.5), not scoped to any single
 * clue or to the mystery phrase specifically (v1.8: it can reveal a letter exclusive
 * to a clue too) — it always picks the smallest still-hidden slot number anywhere in
 * the round, the same way solving a clue would reveal its own letters.
 *
 * make_instance_with_pool() seeds a deterministic two-word pool: theme "escola" (slots
 * 1..6) and the sole clue "livro" (l,i,v,r,o), which shares l and o with the theme
 * (slots 5 and 4) and introduces i, v, r as its own exclusive slots (7, 8, 9 — always
 * numbered after every theme slot, see puzzle_builder::expand_slots_by_letter()). The
 * other 4 theme letters (e,s,c,a) are never covered by any clue, so they are
 * always-revealed from the start (puzzle_builder's graceful degradation). That leaves
 * exactly 5 slots hidden (4, 5, 7, 8, 9) — 5 reveal_hint calls exhaust them, making "no
 * more hints" easy to reach deterministically. Because slots are always numbered
 * lowest-first for the theme, the first two calls specifically land on the theme's own
 * 4 and 5 (revealing the whole mystery phrase) before ever touching livro's exclusive
 * 7, 8, 9 — a useful, deterministic ordering this suite relies on more than once below.
 */
final class reveal_hint_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Creates a playercross instance with a deterministic two-word pool: a theme
     * candidate ("escola") and the sole clue ("livro").
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass Instance record, with ->cmid added.
     */
    private function make_instance_with_pool(array $overrides = []): \stdClass {
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $record = array_merge([
            'course'           => $this->course->id,
            'num_clues'        => 1,
            'theme_min_length' => 6,
            'min_length'       => 3,
            'max_length'       => 15,
        ], $overrides);

        $instance = $modgenerator->create_instance($record);
        $modgenerator->create_word($instance->id, 'escola');
        // Hint "dica" (4 letters) stays under theme_min_length (6), so livro can never
        // be picked as the theme concept itself — its own word ("livro") is 5 letters,
        // also under the threshold, so neither its word nor its hint could introduce
        // ambiguity there.
        $modgenerator->create_word($instance->id, 'livro', 'dica');

        return $instance;
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
     * Inserts a genuine block_playerhud_items record (with its own block instance), so
     * cost-checking tests exercise a real item rather than a bare numeric ID.
     *
     * @return int Item ID.
     */
    private function make_hud_item(): int {
        global $DB;
        $ctx = \context_course::instance($this->course->id);
        $biid = $DB->insert_record('block_instances', (object)[
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
        return $DB->insert_record('block_playerhud_items', (object)[
            'blockinstanceid' => $biid,
            'name'            => 'Gold Key',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    /**
     * Calls the mod_playercross_reveal_hint web service through the real dispatch path.
     *
     * @param int $cmid Course module id.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_reveal_hint(int $cmid): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playercross_reveal_hint', ['cmid' => $cmid]);
    }

    /**
     * Flattens the mystery phrase's own tiles, grouped by word in the response, into a
     * single letter-by-letter list.
     *
     * @param array $panel Panel data from the response.
     * @return array
     */
    private function flatten_theme_tiles(array $panel): array {
        $tiles = [];
        foreach ($panel['themetiles'] as $group) {
            $tiles = array_merge($tiles, $group['tiles']);
        }
        return $tiles;
    }

    /**
     * Counts how many mystery-phrase tiles are currently revealed in a panel response.
     *
     * @param array $panel Panel data from the response.
     * @return int
     */
    private function count_revealed_tiles(array $panel): int {
        return count(array_filter($this->flatten_theme_tiles($panel), fn(array $tile): bool => $tile['revealed']));
    }

    /**
     * Returns every slot number still shown as hidden anywhere in a panel response —
     * the mystery phrase's own tile row plus every clue's own tile row. A single
     * successful hint always removes exactly one distinct slot number from this set,
     * however many tile positions that slot maps to (a repeated letter reveals more
     * than one position from the same call).
     *
     * @param array $panel Panel data from the response.
     * @return string[] Distinct hidden slot numbers.
     */
    private function hidden_slotnums(array $panel): array {
        $nums = [];
        foreach ($this->flatten_theme_tiles($panel) as $tile) {
            if ($tile['slotnum'] !== '') {
                $nums[] = $tile['slotnum'];
            }
        }
        foreach ($panel['clues'] as $clue) {
            foreach ($clue['tiles'] as $tile) {
                if ($tile['slotnum'] !== '') {
                    $nums[] = $tile['slotnum'];
                }
            }
        }
        return array_values(array_unique($nums));
    }

    /**
     * Tests that revealing a hint reveals exactly one more mystery-phrase tile. Valid
     * for the first two calls specifically because the theme's own slots (4, 5) are
     * always numbered lower than livro's exclusive ones (7, 8, 9) — see class docblock
     * — so they are always exhausted first, before the hint ever touches a slot that
     * would not show up in the mystery phrase's own tile row.
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_reveals_one_more_tile(): void {
        $instance = $this->make_instance_with_pool();
        $this->setUser($this->student);

        $before = $this->call_reveal_hint($instance->cmid);
        // The very first call both builds the round (ensure_round_state) and reveals a
        // hint, so compare against a fresh panel fetch instead of a pre-round baseline.
        $revealedbefore = $this->count_revealed_tiles($before['data']['panel']);

        $after = $this->call_reveal_hint($instance->cmid);

        $this->assertFalse($after['error']);
        $this->assertSame('success', $after['data']['notificationtype']);
        $this->assertSame($revealedbefore + 1, $this->count_revealed_tiles($after['data']['panel']));
    }

    /**
     * Tests that once every slot in the round is revealed, a further call is rejected
     * instead of erroring — the pool here has exactly 5 slots left to hint (see class
     * docblock): livro's own 2 shared theme letters plus its 3 exclusive ones.
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_rejects_once_every_slot_is_revealed(): void {
        $instance = $this->make_instance_with_pool();
        $this->setUser($this->student);

        $panel = null;
        for ($i = 0; $i < 5; $i++) {
            $response = $this->call_reveal_hint($instance->cmid);
            $panel = $response['data']['panel'];
        }
        $this->assertSame([], $this->hidden_slotnums($panel));

        $rejected = $this->call_reveal_hint($instance->cmid);

        $this->assertFalse($rejected['error']);
        $this->assertSame('warning', $rejected['data']['notificationtype']);
        $this->assertSame([], $this->hidden_slotnums($rejected['data']['panel']));
    }

    /**
     * Tests that max_hints_per_round blocks further reveals once the configured
     * teacher-set cap is reached, even though hidden slots remain (the pool here has 5
     * hintable slots left, see class docblock — the cap of 2 must stop the round well
     * before that natural exhaustion point).
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_rejects_once_hint_limit_is_reached(): void {
        $instance = $this->make_instance_with_pool(['max_hints_per_round' => 2]);
        $this->setUser($this->student);

        $this->call_reveal_hint($instance->cmid);
        $second = $this->call_reveal_hint($instance->cmid);
        $revealedaftertwo = $this->count_revealed_tiles($second['data']['panel']);

        $third = $this->call_reveal_hint($instance->cmid);

        $this->assertFalse($third['error']);
        $this->assertSame('warning', $third['data']['notificationtype']);
        $this->assertSame($revealedaftertwo, $this->count_revealed_tiles($third['data']['panel']));
    }

    /**
     * Tests that a user without the view capability in the module context is rejected.
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_requires_view_capability(): void {
        $instance = $this->make_instance_with_pool();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $result = $this->call_reveal_hint($instance->cmid);

        $this->assertTrue($result['error']);
    }

    /**
     * Tests that an insufficient PlayerHUD item balance blocks revealing the hint.
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_hud_insufficient_item_blocks_reveal(): void {
        $this->skip_if_no_playerhud();
        $itemid = $this->make_hud_item();
        $instance = $this->make_instance_with_pool([
            'hud_hint_cost_item' => $itemid, 'hud_hint_cost_qty' => 1,
        ]);
        $this->setUser($this->student);

        $result = $this->call_reveal_hint($instance->cmid);

        $this->assertFalse($result['error']);
        $this->assertSame('warning', $result['data']['notificationtype']);
        $this->assertNotEmpty($result['data']['notification']);
        $this->assertSame(4, $this->count_revealed_tiles($result['data']['panel']));
    }

    /**
     * Tests that a hint cost pointing at a PlayerHUD item that does not exist (e.g. it was
     * deleted after the activity was configured) is waived rather than blocking the hint
     * forever with a broken notification.
     *
     * @covers \mod_playercross\external\reveal_hint::execute
     * @return void
     */
    public function test_hud_deleted_item_waives_reveal_cost(): void {
        $instance = $this->make_instance_with_pool([
            'hud_hint_cost_item' => 999999, 'hud_hint_cost_qty' => 1,
        ]);
        $this->setUser($this->student);

        $result = $this->call_reveal_hint($instance->cmid);

        $this->assertFalse($result['error']);
        $this->assertSame('success', $result['data']['notificationtype']);
        $this->assertSame(5, $this->count_revealed_tiles($result['data']['panel']));
    }
}
