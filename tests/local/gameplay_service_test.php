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
 *
 * @covers \mod_playercross\local\gameplay_service
 */
final class gameplay_service_test extends \basic_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
    }

    /**
     * Builds a minimal instance stub matching the docs' worked example: 5 terms, 3
     * attempts per term, 3 attempts for the final guess (max_errors = 12).
     *
     * @param array $overrides Field overrides merged over the defaults.
     * @return \stdClass
     */
    private function make_instance(array $overrides = []): \stdClass {
        return (object)array_merge([
            'grade' => 100.0,
            'num_terms' => 5,
            'max_attempts_per_term' => 3,
            'max_attempts_final_guess' => 3,
            'gradescoringmode' => PLAYERCROSS_SCORING_BINARY,
            'rankingscoringmode' => PLAYERCROSS_SCORING_BINARY,
        ], $overrides);
    }

    /**
     * max_errors sums the wrong-guess budget of every term plus the final guess.
     *
     * @return void
     */
    public function test_calculate_max_errors(): void {
        // 5 * (3 - 1) + (3 - 1) = 12, the docs' worked-example denominator.
        $instance = $this->make_instance();
        $this->assertSame(12, gameplay_service::calculate_max_errors($instance));
    }

    /**
     * A single attempt allowed per term/final guess contributes zero error budget —
     * the correct guess must be the first one.
     *
     * @return void
     */
    public function test_calculate_max_errors_single_attempt_contributes_zero(): void {
        $instance = $this->make_instance(['max_attempts_per_term' => 1, 'max_attempts_final_guess' => 1]);
        $this->assertSame(0, gameplay_service::calculate_max_errors($instance));
    }

    /**
     * Binary scoring always awards the full grade on a completed round, regardless of
     * how many errors were made.
     *
     * @return void
     */
    public function test_binary_scoring_full_credit_on_win(): void {
        $instance = $this->make_instance(['gradescoringmode' => PLAYERCROSS_SCORING_BINARY]);
        $this->assertEqualsWithDelta(
            100.0,
            gameplay_service::calculate_round_score($instance, 8, true, false),
            0.00001
        );
    }

    /**
     * Binary scoring awards nothing on a loss.
     *
     * @return void
     */
    public function test_binary_scoring_zero_on_loss(): void {
        $instance = $this->make_instance(['gradescoringmode' => PLAYERCROSS_SCORING_BINARY]);
        $this->assertSame(0.0, gameplay_service::calculate_round_score($instance, 0, false, false));
    }

    /**
     * Linear scoring with zero errors is always exactly full credit — the formula has
     * no grace period, but a flawless run still reaches 100%.
     *
     * @return void
     */
    public function test_linear_scoring_zero_errors_is_full_credit(): void {
        $instance = $this->make_instance(['gradescoringmode' => PLAYERCROSS_SCORING_LINEAR]);
        $this->assertEqualsWithDelta(
            100.0,
            gameplay_service::calculate_round_score($instance, 0, true, false),
            0.00001
        );
    }

    /**
     * Linear scoring has no grace period: the very first wrong guess already reduces
     * the score, matching the docs' worked-example table (1 error → 92.31).
     *
     * @return void
     */
    public function test_linear_scoring_first_error_already_reduces_score(): void {
        $instance = $this->make_instance(['gradescoringmode' => PLAYERCROSS_SCORING_LINEAR]);
        $this->assertEqualsWithDelta(
            92.30769,
            gameplay_service::calculate_round_score($instance, 1, true, false),
            0.001
        );
    }

    /**
     * At the maximum error budget, Linear scoring floors at grade / (max_errors + 1)
     * — never zero for a genuinely completed win (docs' table: 12 errors → 7.69).
     *
     * @return void
     */
    public function test_linear_scoring_floors_at_max_errors(): void {
        $instance = $this->make_instance(['gradescoringmode' => PLAYERCROSS_SCORING_LINEAR]);
        $this->assertEqualsWithDelta(
            7.69231,
            gameplay_service::calculate_round_score($instance, 12, true, false),
            0.001
        );
        // Never drops below the floor even with more errors than the budget allows.
        $this->assertEqualsWithDelta(
            7.69231,
            gameplay_service::calculate_round_score($instance, 99, true, false),
            0.001
        );
    }

    /**
     * Linear scoring on a not-completed round is always zero, regardless of errors used.
     *
     * @return void
     */
    public function test_linear_scoring_not_completed_is_zero(): void {
        $instance = $this->make_instance(['gradescoringmode' => PLAYERCROSS_SCORING_LINEAR]);
        $this->assertSame(0.0, gameplay_service::calculate_round_score($instance, 0, false, false));
    }

    /**
     * Linear scoring degrades to full credit when either attempts field is unlimited
     * (0) — a defensive fallback for a row persisted before validation blocked this
     * combination, mirroring PlayerWords' own guard.
     *
     * @return void
     */
    public function test_linear_scoring_defensive_fallback_when_unlimited(): void {
        $instanceperterm = $this->make_instance([
            'gradescoringmode' => PLAYERCROSS_SCORING_LINEAR,
            'max_attempts_per_term' => 0,
        ]);
        $this->assertEqualsWithDelta(
            100.0,
            gameplay_service::calculate_round_score($instanceperterm, 5, true, false),
            0.00001
        );

        $instancefinal = $this->make_instance([
            'gradescoringmode' => PLAYERCROSS_SCORING_LINEAR,
            'max_attempts_final_guess' => 0,
        ]);
        $this->assertEqualsWithDelta(
            100.0,
            gameplay_service::calculate_round_score($instancefinal, 5, true, false),
            0.00001
        );
    }

    /**
     * Grade and ranking scoring modes are independently configurable on the same
     * round, and ranking's own points are scored against PLAYERCROSS_RANKING_BASE_
     * POINTS, never the activity's grade — proven here by varying grade and checking
     * the ranking output never moves.
     *
     * @return void
     */
    public function test_grade_and_ranking_modes_are_independent(): void {
        foreach ([0.0, 50.0, 100.0, 500.0] as $grade) {
            $instance = $this->make_instance([
                'grade' => $grade,
                'gradescoringmode' => PLAYERCROSS_SCORING_BINARY,
                'rankingscoringmode' => PLAYERCROSS_SCORING_LINEAR,
            ]);

            $this->assertEqualsWithDelta(
                $grade,
                gameplay_service::calculate_round_score($instance, 6, true, false),
                0.00001
            );
            $this->assertEqualsWithDelta(
                53.84615,
                gameplay_service::calculate_ranking_points($instance, 6, true, false),
                0.001
            );
        }
    }

    /**
     * Ranking points never move regardless of the activity's own configured grade —
     * the core regression test for the fix: ranking used to be scored against
     * $instance->grade, so an ungraded activity (grade=0, the mod_form default) always
     * produced zero ranking points even with show_ranking enabled (also the default).
     *
     * @return void
     */
    public function test_calculate_ranking_points_ignores_grade_value(): void {
        $rankingpointswithzerograde = gameplay_service::calculate_ranking_points(
            $this->make_instance(['grade' => 0.0, 'rankingscoringmode' => PLAYERCROSS_SCORING_BINARY]),
            0,
            true,
            false
        );
        $rankingpointswithrealgrade = gameplay_service::calculate_ranking_points(
            $this->make_instance(['grade' => 250.0, 'rankingscoringmode' => PLAYERCROSS_SCORING_BINARY]),
            0,
            true,
            false
        );

        $this->assertEqualsWithDelta(100.0, $rankingpointswithzerograde, 0.00001);
        $this->assertEqualsWithDelta($rankingpointswithzerograde, $rankingpointswithrealgrade, 0.00001);
    }

    /**
     * The early-guess bonus is a flat percentage of whatever scoring base is passed
     * in — the activity's grade for the grade path, or PLAYERCROSS_RANKING_BASE_POINTS
     * for the ranking path (see calculate_round_score()/calculate_ranking_points()).
     *
     * @return void
     */
    public function test_calculate_early_guess_bonus(): void {
        $this->assertEqualsWithDelta(10.0, gameplay_service::calculate_early_guess_bonus(100.0), 0.00001);
        $this->assertEqualsWithDelta(5.0, gameplay_service::calculate_early_guess_bonus(50.0), 0.00001);
    }

    /**
     * The early-guess bonus is capped at the nominal grade for the grade score — a
     * flawless Binary win plus the bonus would otherwise exceed 100.
     *
     * @return void
     */
    public function test_early_bonus_capped_at_grade_for_score(): void {
        $instance = $this->make_instance(['gradescoringmode' => PLAYERCROSS_SCORING_BINARY]);
        $this->assertEqualsWithDelta(
            100.0,
            gameplay_service::calculate_round_score($instance, 0, true, true),
            0.00001
        );
    }

    /**
     * The early-guess bonus is uncapped for ranking points — a flawless Binary win
     * plus the bonus legitimately exceeds PLAYERCROSS_RANKING_BASE_POINTS (100). Proven
     * with grade=0 specifically, so the 110.0 result can only have come from the fixed
     * ranking base, never from the (zero) grade.
     *
     * @return void
     */
    public function test_early_bonus_uncapped_for_ranking(): void {
        $instance = $this->make_instance(['grade' => 0.0, 'rankingscoringmode' => PLAYERCROSS_SCORING_BINARY]);
        $this->assertEqualsWithDelta(
            110.0,
            gameplay_service::calculate_ranking_points($instance, 0, true, true),
            0.00001
        );
    }

    /**
     * The early-guess bonus never applies to a loss or when not eligible.
     *
     * @return void
     */
    public function test_early_bonus_not_applied_when_ineligible_or_lost(): void {
        $instance = $this->make_instance(['gradescoringmode' => PLAYERCROSS_SCORING_BINARY]);
        $this->assertEqualsWithDelta(
            100.0,
            gameplay_service::calculate_round_score($instance, 0, true, false),
            0.00001
        );
        $this->assertSame(0.0, gameplay_service::calculate_round_score($instance, 0, false, true));
    }

    /**
     * The session key combines cmid and userid uniquely.
     *
     * @return void
     */
    public function test_build_session_key(): void {
        $this->assertSame('7:42', gameplay_service::build_session_key(7, 42));
    }
}
