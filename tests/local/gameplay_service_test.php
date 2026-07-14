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
 * Unit tests for gameplay_service.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Tests for gameplay_service's scoring formulas — no database access needed.
 */
final class gameplay_service_test extends \basic_testcase {
    /**
     * Builds a minimal instance stub with the given grade and max_attempts_per_clue.
     *
     * @param float $grade Activity maximum grade.
     * @param int $maxattemptsperclue Maximum attempts per clue, 0 for unlimited.
     * @return \stdClass
     */
    private function make_instance(float $grade, int $maxattemptsperclue): \stdClass {
        return (object)['grade' => $grade, 'max_attempts_per_clue' => $maxattemptsperclue];
    }

    /**
     * Each clue is worth an even share of the round's grade.
     *
     * @covers \mod_playercross\local\gameplay_service::max_points_per_clue
     * @return void
     */
    public function test_max_points_per_clue_splits_grade_evenly(): void {
        $instance = $this->make_instance(100.0, 0);
        $this->assertEqualsWithDelta(20.0, gameplay_service::max_points_per_clue($instance, 5), 0.00001);
    }

    /**
     * With zero clues there is nothing to split the grade across.
     *
     * @covers \mod_playercross\local\gameplay_service::max_points_per_clue
     * @return void
     */
    public function test_max_points_per_clue_zero_clues_returns_zero(): void {
        $instance = $this->make_instance(100.0, 0);
        $this->assertSame(0.0, gameplay_service::max_points_per_clue($instance, 0));
    }

    /**
     * With max_attempts_per_clue unlimited (0), a resolved clue always earns full
     * credit regardless of attempts used — there is no natural denominator to decay
     * against.
     *
     * @covers \mod_playercross\local\gameplay_service::calculate_clue_points
     * @return void
     */
    public function test_calculate_clue_points_unlimited_always_full_credit(): void {
        $instance = $this->make_instance(100.0, 0);
        $this->assertEqualsWithDelta(20.0, gameplay_service::calculate_clue_points($instance, 5, 1), 0.00001);
        $this->assertEqualsWithDelta(20.0, gameplay_service::calculate_clue_points($instance, 5, 9), 0.00001);
    }

    /**
     * The first two attempts on a clue always earn full credit, mirroring the same
     * plateau PlayerWords uses for its whole round.
     *
     * @covers \mod_playercross\local\gameplay_service::calculate_clue_points
     * @return void
     */
    public function test_calculate_clue_points_full_credit_within_first_two_attempts(): void {
        $instance = $this->make_instance(100.0, 6);
        $this->assertEqualsWithDelta(20.0, gameplay_service::calculate_clue_points($instance, 5, 1), 0.00001);
        $this->assertEqualsWithDelta(20.0, gameplay_service::calculate_clue_points($instance, 5, 2), 0.00001);
    }

    /**
     * Beyond the second attempt, points decrease linearly down to the last allowed attempt.
     *
     * @covers \mod_playercross\local\gameplay_service::calculate_clue_points
     * @return void
     */
    public function test_calculate_clue_points_decreases_linearly_after_second_attempt(): void {
        $instance = $this->make_instance(100.0, 6);
        // Maxpoints = 20; at attempt 6 (the last allowed): 20 * (6-6+1)/(6-1) = 4.
        $this->assertEqualsWithDelta(4.0, gameplay_service::calculate_clue_points($instance, 5, 6), 0.00001);
        // Never goes below the floor even if more attempts than allowed are passed in.
        $this->assertEqualsWithDelta(4.0, gameplay_service::calculate_clue_points($instance, 5, 99), 0.00001);
    }

    /**
     * Guessing the mystery phrase before resolving any clue earns the full grade as a
     * bonus — equivalent to having resolved every clue at full credit.
     *
     * @covers \mod_playercross\local\gameplay_service::calculate_final_guess_bonus
     * @return void
     */
    public function test_final_guess_bonus_is_full_grade_when_nothing_resolved(): void {
        $instance = $this->make_instance(100.0, 0);
        $this->assertEqualsWithDelta(100.0, gameplay_service::calculate_final_guess_bonus($instance, 5, 0), 0.00001);
    }

    /**
     * The bonus shrinks as more clues are already resolved, reaching zero once every
     * clue has already been credited.
     *
     * @covers \mod_playercross\local\gameplay_service::calculate_final_guess_bonus
     * @return void
     */
    public function test_final_guess_bonus_shrinks_with_resolved_clues(): void {
        $instance = $this->make_instance(100.0, 0);
        $this->assertEqualsWithDelta(40.0, gameplay_service::calculate_final_guess_bonus($instance, 5, 3), 0.00001);
        $this->assertEqualsWithDelta(0.0, gameplay_service::calculate_final_guess_bonus($instance, 5, 5), 0.00001);
    }

    /**
     * The session key combines cmid and userid uniquely.
     *
     * @covers \mod_playercross\local\gameplay_service::build_session_key
     * @return void
     */
    public function test_build_session_key(): void {
        $this->assertSame('7:42', gameplay_service::build_session_key(7, 42));
    }
}
