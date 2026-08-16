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
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Holds the scoring formulas for the puzzle mechanic.
 *
 * Unlike PlayerWords, PlayerCross has no configurable grading/ranking scoring mode —
 * SCOPE.md §4 describes a single fixed shape: points accumulate per term as it is
 * resolved (decreasing with attempts used on that term), plus an optional bonus for
 * guessing the mystery phrase directly before every term is solved.
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
     * The full grade divided evenly across every term in the round — the ceiling a
     * single resolved term can ever be worth, and the unit the final-guess bonus is
     * expressed in multiples of.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $termstotal Number of terms in the current round.
     * @return float
     */
    public static function max_points_per_term(\stdClass $instance, int $termstotal): float {
        if ($termstotal <= 0) {
            return 0.0;
        }
        return (float)$instance->grade / $termstotal;
    }

    /**
     * Calculates the points earned for resolving one term, based on attempts used on
     * that specific term.
     *
     * Full credit on the first two attempts — a confident second guess is not
     * meaningfully less deserving than a first-try one — then scales down linearly as
     * max_attempts_per_term is approached, mirroring the curve PlayerWords uses for its
     * whole round. When max_attempts_per_term is 0 (unlimited), there is no natural
     * denominator to scale against, so a resolved term always earns full credit
     * regardless of how many attempts it took.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $termstotal Number of terms in the current round.
     * @param int $attemptsusedonterm Attempts used on this term before resolving it.
     * @return float
     */
    public static function calculate_term_points(
        \stdClass $instance,
        int $termstotal,
        int $attemptsusedonterm
    ): float {
        $maxpoints = self::max_points_per_term($instance, $termstotal);
        if ($maxpoints <= 0.0) {
            return 0.0;
        }

        $maxattempts = (int)$instance->max_attempts_per_term;
        if ($maxattempts <= 0) {
            return $maxpoints;
        }

        $attemptsusedonterm = min(max($attemptsusedonterm, 1), $maxattempts);
        if ($attemptsusedonterm <= 2 || $maxattempts <= 2) {
            return $maxpoints;
        }

        return $maxpoints * ($maxattempts - $attemptsusedonterm + 1) / ($maxattempts - 1);
    }

    /**
     * Calculates the bonus earned for a correct direct guess of the mystery phrase.
     *
     * Inversely proportional to how many terms were already resolved at the moment of
     * the guess: guessing before solving anything is worth as much as the remaining
     * terms would have been at full credit each, rewarding early deduction; guessing
     * after every term is already solved earns no bonus, since full credit was already
     * collected from the terms themselves. The total a round can ever earn — resolved
     * term points plus this bonus — is always bounded by the activity's configured grade.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $termstotal Number of terms in the current round.
     * @param int $termsresolved Terms already resolved at the moment of the final guess.
     * @return float
     */
    public static function calculate_final_guess_bonus(
        \stdClass $instance,
        int $termstotal,
        int $termsresolved
    ): float {
        $maxpoints = self::max_points_per_term($instance, $termstotal);
        $remaining = max(0, $termstotal - $termsresolved);

        return $maxpoints * $remaining;
    }
}
