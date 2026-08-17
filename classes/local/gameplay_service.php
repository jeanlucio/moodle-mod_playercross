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
 * Grade and ranking each have their own independently configurable scoring mode
 * (PLAYERCROSS_SCORING_BINARY or PLAYERCROSS_SCORING_LINEAR — see lib.php), mirroring
 * mod_playerwords. Terms have no per-term point value of their own: a single shared
 * error pool (every wrong guess, term or final, never the successful resolving one)
 * feeds the whole round's Linear score. The Linear formula has no grace period —
 * unlike PlayerWords, the first wrong guess already reduces the score.
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
     * The highest number of wrong guesses a completed round can absorb while still
     * reaching every mandatory correct submission (every term, plus the final guess):
     * each of those submissions gets max_attempts_per_term (or max_attempts_final_guess)
     * attempts, one of which must be the correct one, so every attempt before that is a
     * potential error. Used as the denominator of the Linear formula.
     *
     * @param \stdClass $instance Activity instance.
     * @return int
     */
    public static function calculate_max_errors(\stdClass $instance): int {
        $numterms = (int)($instance->num_terms ?? 0);
        $maxperterm = (int)($instance->max_attempts_per_term ?? 0);
        $maxfinal = (int)($instance->max_attempts_final_guess ?? 0);
        return $numterms * max(0, $maxperterm - 1) + max(0, $maxfinal - 1);
    }

    /**
     * Calculates the grade points earned for a round, including the early-guess bonus
     * when eligible — capped at the activity's configured grade, since a gradebook
     * value should never exceed the nominal maximum.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $errorsused Wrong guesses across every term and the final guess.
     * @param bool $completed Whether the round was won.
     * @param bool $earlybonuseligible Whether the phrase was guessed directly before
     *     resolving any term.
     * @return float
     */
    public static function calculate_round_score(
        \stdClass $instance,
        int $errorsused,
        bool $completed,
        bool $earlybonuseligible
    ): float {
        $mode = (int)($instance->gradescoringmode ?? PLAYERCROSS_SCORING_BINARY);
        $base = self::compute_points($instance, $mode, $errorsused, $completed);
        if (!$completed || !$earlybonuseligible) {
            return $base;
        }

        return min((float)$instance->grade, $base + self::calculate_early_guess_bonus($instance));
    }

    /**
     * Calculates the ranking points earned for a round, including the early-guess
     * bonus when eligible — uncapped, so a ranking total can legitimately exceed the
     * activity's nominal grade.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $errorsused Wrong guesses across every term and the final guess.
     * @param bool $completed Whether the round was won.
     * @param bool $earlybonuseligible Whether the phrase was guessed directly before
     *     resolving any term.
     * @return float
     */
    public static function calculate_ranking_points(
        \stdClass $instance,
        int $errorsused,
        bool $completed,
        bool $earlybonuseligible
    ): float {
        $mode = (int)($instance->rankingscoringmode ?? PLAYERCROSS_SCORING_BINARY);
        $base = self::compute_points($instance, $mode, $errorsused, $completed);
        if (!$completed || !$earlybonuseligible) {
            return $base;
        }

        return $base + self::calculate_early_guess_bonus($instance);
    }

    /**
     * Calculates the flat early-guess bonus: 10% of the activity's configured grade.
     *
     * @param \stdClass $instance Activity instance.
     * @return float
     */
    public static function calculate_early_guess_bonus(\stdClass $instance): float {
        return (float)$instance->grade * 0.10;
    }

    /**
     * Computes the base score for one scoring mode, before any early-guess bonus.
     *
     * Binary always awards full credit on a completed round. Linear scales down
     * proportionally to errors used against calculate_max_errors(), with no grace
     * period: even a single wrong guess already reduces the score.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $mode One of the PLAYERCROSS_SCORING_* constants.
     * @param int $errorsused Wrong guesses across every term and the final guess.
     * @param bool $completed Whether the round was won.
     * @return float
     */
    private static function compute_points(\stdClass $instance, int $mode, int $errorsused, bool $completed): float {
        if (!$completed) {
            return 0.0;
        }

        $maxpoints = (float)$instance->grade;
        if ($mode !== PLAYERCROSS_SCORING_LINEAR) {
            return $maxpoints;
        }

        $maxperterm = (int)($instance->max_attempts_per_term ?? 0);
        $maxfinal = (int)($instance->max_attempts_final_guess ?? 0);
        if ($maxperterm <= 0 || $maxfinal <= 0) {
            // Defensive: mod_form::validation() blocks saving Linear + unlimited going
            // forward, but a row persisted before that validation existed could still
            // reach here. Degrade to full credit, same as PlayerWords' own guard.
            return $maxpoints;
        }

        $maxerrors = self::calculate_max_errors($instance);
        $errorsused = min(max($errorsused, 0), $maxerrors);
        return $maxpoints * ($maxerrors - $errorsused + 1) / ($maxerrors + 1);
    }
}
