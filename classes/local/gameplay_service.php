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
 * Gameplay helper service.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Holds the scoring formulas for the puzzle mechanic.
 *
 * Unlike PlayerWords, PlayerCross has no configurable grading/ranking scoring mode —
 * SCOPE.md §4 describes a single fixed shape: points accumulate per clue as it is
 * resolved (decreasing with attempts used on that clue), plus an optional bonus for
 * guessing the mystery phrase directly before every clue is solved.
 */
class gameplay_service {
    /**
     * Builds one session key by module and user.
     *
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return string
     */
    public static function build_session_key(int $cmid, int $userid): string {
        return $cmid . ':' . $userid;
    }

    /**
     * The full grade divided evenly across every clue in the round — the ceiling a
     * single resolved clue can ever be worth, and the unit the final-guess bonus is
     * expressed in multiples of.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $cluestotal Number of clues in the current round.
     * @return float
     */
    public static function max_points_per_clue(\stdClass $instance, int $cluestotal): float {
        if ($cluestotal <= 0) {
            return 0.0;
        }
        return (float)$instance->grade / $cluestotal;
    }

    /**
     * Calculates the points earned for resolving one clue, based on attempts used on
     * that specific clue.
     *
     * Full credit on the first two attempts — a confident second guess is not
     * meaningfully less deserving than a first-try one — then scales down linearly as
     * max_attempts_per_clue is approached, mirroring the curve PlayerWords uses for its
     * whole round. When max_attempts_per_clue is 0 (unlimited), there is no natural
     * denominator to scale against, so a resolved clue always earns full credit
     * regardless of how many attempts it took.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $cluestotal Number of clues in the current round.
     * @param int $attemptsusedonclue Attempts used on this clue before resolving it.
     * @return float
     */
    public static function calculate_clue_points(
        \stdClass $instance,
        int $cluestotal,
        int $attemptsusedonclue
    ): float {
        $maxpoints = self::max_points_per_clue($instance, $cluestotal);
        if ($maxpoints <= 0.0) {
            return 0.0;
        }

        $maxattempts = (int)$instance->max_attempts_per_clue;
        if ($maxattempts <= 0) {
            return $maxpoints;
        }

        $attemptsusedonclue = min(max($attemptsusedonclue, 1), $maxattempts);
        if ($attemptsusedonclue <= 2 || $maxattempts <= 2) {
            return $maxpoints;
        }

        return $maxpoints * ($maxattempts - $attemptsusedonclue + 1) / ($maxattempts - 1);
    }

    /**
     * Calculates the bonus earned for a correct direct guess of the mystery phrase.
     *
     * Inversely proportional to how many clues were already resolved at the moment of
     * the guess: guessing before solving anything is worth as much as the remaining
     * clues would have been at full credit each, rewarding early deduction; guessing
     * after every clue is already solved earns no bonus, since full credit was already
     * collected from the clues themselves. The total a round can ever earn — resolved
     * clue points plus this bonus — is always bounded by the activity's configured grade.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $cluestotal Number of clues in the current round.
     * @param int $cluesresolved Clues already resolved at the moment of the final guess.
     * @return float
     */
    public static function calculate_final_guess_bonus(
        \stdClass $instance,
        int $cluestotal,
        int $cluesresolved
    ): float {
        $maxpoints = self::max_points_per_clue($instance, $cluestotal);
        $remaining = max(0, $cluestotal - $cluesresolved);

        return $maxpoints * $remaining;
    }
}
