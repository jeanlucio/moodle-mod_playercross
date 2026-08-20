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
 * Tests for the diagnostics counters used by the anonymous usage report.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use advanced_testcase;

/**
 * Tests for the diagnostics counters used by the anonymous usage report.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_playercross\local\diagnostics
 */
final class diagnostics_test extends advanced_testcase {
    /**
     * Set up the test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * A fresh site has no counters recorded.
     */
    public function test_get_all_returns_empty_array_when_never_written(): void {
        $this->assertSame([], diagnostics::get_all());
    }

    /**
     * Corrupted config value never breaks payload building — it is treated the same as
     * "no counters yet", not as a fatal error.
     */
    public function test_get_all_returns_empty_array_when_config_is_corrupted(): void {
        set_config(diagnostics::CONFIG_KEY, 'not valid json', 'mod_playercross');
        $this->assertSame([], diagnostics::get_all());
    }

    /**
     * The first increment starts a counter at 1.
     */
    public function test_increment_starts_counter_at_one(): void {
        diagnostics::increment('ai_generation_failed');
        $this->assertSame(['ai_generation_failed' => 1], diagnostics::get_all());
    }

    /**
     * Repeated increments of the same counter accumulate.
     */
    public function test_increment_accumulates_on_repeated_calls(): void {
        diagnostics::increment('ai_generation_failed');
        diagnostics::increment('ai_generation_failed');
        diagnostics::increment('ai_generation_failed');
        $this->assertSame(['ai_generation_failed' => 3], diagnostics::get_all());
    }

    /**
     * Distinct counters are tracked independently, side by side.
     */
    public function test_increment_tracks_distinct_counters_independently(): void {
        diagnostics::increment('ai_generation_failed');
        diagnostics::increment('ai_generation_failed');
        diagnostics::increment('ai_generation_partial');

        $this->assertSame([
            'ai_generation_failed' => 2,
            'ai_generation_partial' => 1,
        ], diagnostics::get_all());
    }

    /**
     * clear() resets every counter back to an empty set.
     */
    public function test_clear_resets_all_counters(): void {
        diagnostics::increment('ai_generation_failed');
        diagnostics::increment('ai_generation_partial');

        diagnostics::clear();

        $this->assertSame([], diagnostics::get_all());
    }
}
