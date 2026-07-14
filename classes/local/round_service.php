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
 * Round transition service.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use completion_info;

/**
 * Owns every round-state mutation: starting, guessing a clue, guessing the mystery
 * phrase directly, revealing a hint, forfeiting, timing out and starting a new round.
 * This is the single source of truth for what happens on each transition, shared by
 * the classic page render and by the AJAX external functions.
 *
 * Unlike mod_playerwords, PlayerCross has no ephemeral DB table and no reserve-on-start
 * attempt row: the whole puzzle (mystery phrase, clue list, revealed slots) lives only
 * in session state for the duration of the round, and playercross_attempts only ever
 * gains a row once the round actually finishes (see SCOPE.md §5).
 */
class round_service {
    /**
     * Grace window, in seconds, allowed between the configured deadline and when a
     * client-reported timeout is honoured server-side — covers normal network latency
     * between the client's countdown reaching zero and the request arriving.
     */
    private const TIMEOUT_TOLERANCE_SECONDS = 5;

    /**
     * Gets session state, creating defaults when missing.
     *
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return array
     */
    public static function load_state(int $cmid, int $userid): array {
        global $SESSION;

        $sessionkey = gameplay_service::build_session_key($cmid, $userid);
        if (!isset($SESSION->mod_playercross)) {
            $SESSION->mod_playercross = [];
        }
        if (!isset($SESSION->mod_playercross[$sessionkey])) {
            $SESSION->mod_playercross[$sessionkey] = self::default_state();
        }

        return $SESSION->mod_playercross[$sessionkey];
    }

    /**
     * Returns the default (empty) round state shape.
     *
     * @return array
     */
    private static function default_state(): array {
        return [
            'themewordid'   => 0,
            'themeword'     => '',
            'themeslots'    => [],
            'slotcount'     => 0,
            'revealedslots' => [],
            'clues'         => [],
            'cluestotal'    => 0,
            'cluesresolved' => 0,
            'scoreaccumulated' => 0.0,
            'attemptsused'  => 0,
            'starttime'     => 0,
            'endtime'       => 0,
            'roundstarted'  => false,
            'finished'      => false,
            'won'           => false,
            'forfeited'     => false,
            'timedout'      => false,
            'finalguessed'  => false,
        ];
    }

    /**
     * Persists session state.
     *
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @param array $state Current state.
     * @return void
     */
    public static function save_state(int $cmid, int $userid, array $state): void {
        global $SESSION;

        $sessionkey = gameplay_service::build_session_key($cmid, $userid);
        $SESSION->mod_playercross[$sessionkey] = $state;
    }

    /**
     * Resets session state to defaults so the next ensure_round_state() builds a fresh puzzle.
     *
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return void
     */
    public static function new_round(int $cmid, int $userid): void {
        self::save_state($cmid, $userid, self::default_state());
    }

    /**
     * Returns the epoch when the player's cooldown ends, or 0 if none is active.
     *
     * Computed fresh from the last attempt's timestamp and the activity's current
     * cooldown_seconds setting every time — never cached in session state — so a
     * change to the setting takes effect immediately, the same way mod_quiz's
     * inter-attempt delay always uses its current setting.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $userid User id.
     * @return int
     */
    public static function compute_cooldown_until(\stdClass $instance, int $userid): int {
        global $DB;

        if ((int)$instance->cooldown_seconds <= 0) {
            return 0;
        }

        $lastattempttime = $DB->get_field_sql(
            "SELECT MAX(timecreated) FROM {playercross_attempts}"
            . " WHERE playercrossid = :pid AND userid = :uid",
            ['pid' => $instance->id, 'uid' => $userid]
        );
        if (empty($lastattempttime)) {
            return 0;
        }

        $until = (int)$lastattempttime + (int)$instance->cooldown_seconds;
        return $until > time() ? $until : 0;
    }

    /**
     * Counts how many rounds a user has completed for this instance.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $userid User id.
     * @return int
     */
    public static function count_rounds_played(\stdClass $instance, int $userid): int {
        global $DB;

        return $DB->count_records('playercross_attempts', [
            'playercrossid' => $instance->id,
            'userid'        => $userid,
        ]);
    }

    /**
     * Returns a restriction message if the user cannot start a new round, null otherwise.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $userid User id.
     * @return string|null
     */
    public static function get_round_restriction_notice(\stdClass $instance, int $userid): ?string {
        if ((int)$instance->max_rounds > 0) {
            $roundsplayed = self::count_rounds_played($instance, $userid);
            if ($roundsplayed >= (int)$instance->max_rounds) {
                return get_string('roundlimitreached', 'mod_playercross', $instance->max_rounds);
            }
        }

        $cooldownuntil = self::compute_cooldown_until($instance, $userid);
        if ($cooldownuntil > 0) {
            return get_string('cooldownactive', 'mod_playercross', format_time($cooldownuntil - time()));
        }

        return null;
    }

    /**
     * Ensures a puzzle is loaded in state, building a fresh one via puzzle_builder when
     * needed. Never rebuilds while the current round is finished — that transition
     * belongs exclusively to new_round().
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return array Updated state.
     */
    public static function ensure_round_state(array $state, \stdClass $instance, int $cmid, int $userid): array {
        if (!empty($state['finished']) || (int)$state['themewordid'] > 0) {
            return $state;
        }

        $completedround = self::count_rounds_played($instance, $userid);
        $excludethemeid = words_repository::get_last_played_theme_word_id($instance, $userid);

        try {
            $puzzle = puzzle_builder::build_round($instance, $completedround, $excludethemeid);
        } catch (\moodle_exception $e) {
            return $state;
        }

        $clues = [];
        foreach ($puzzle->clues as $clue) {
            $clues[] = [
                'wordid'       => $clue->wordid,
                'word'         => $clue->word,
                'hint'         => $clue->hint,
                'slots'        => $clue->slots,
                'resolved'     => false,
                'attemptsused' => 0,
                'exhausted'    => false,
            ];
        }

        $state = self::default_state();
        $state['themewordid']   = $puzzle->themewordid;
        $state['themeword']     = $puzzle->themeword;
        $state['themeslots']    = $puzzle->themeslots;
        $state['slotcount']     = $puzzle->slotcount;
        $state['revealedslots'] = $puzzle->alwaysrevealedslots;
        $state['clues']         = $clues;
        $state['cluestotal']    = count($clues);

        $event = \mod_playercross\event\round_started::create([
            'objectid' => $puzzle->themewordid,
            'context'  => \context_module::instance($cmid),
            'other'    => ['cluestotal' => count($clues)],
        ]);
        $event->trigger();

        return $state;
    }

    /**
     * Starts the round timer, optionally consuming a PlayerHUD item cost.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $userid User id.
     * @return array [$state, $notification, $notificationtype]
     */
    public static function start_round(array $state, \stdClass $instance, int $userid): array {
        $roundcostitem = (int)($instance->hud_round_cost_item ?? 0);
        if ($roundcostitem > 0) {
            $blockinstanceid = hud_service::resolve_block_instance_id($instance);
            $consumed = hud_service::consume_items(
                $blockinstanceid,
                $userid,
                $roundcostitem,
                max(1, (int)($instance->hud_round_cost_qty ?? 1))
            );
            if (!$consumed) {
                $itemname = hud_service::get_item_name($blockinstanceid, $roundcostitem);
                return [$state, get_string('hud_insufficient_round', 'mod_playercross', $itemname), 'warning'];
            }
        }

        $state['starttime'] = time();
        $state['roundstarted'] = true;

        return [$state, null, null];
    }

    /**
     * Finds a clue's array index by its word id.
     *
     * @param array $state Current state.
     * @param int $clueid Clue word id.
     * @return int|null
     */
    private static function find_clue_index(array $state, int $clueid): ?int {
        foreach ($state['clues'] as $index => $clue) {
            if ((int)$clue['wordid'] === $clueid) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Reveals one still-hidden mystery-phrase slot, optionally consuming a PlayerHUD
     * item cost. A global action, not scoped to any single clue: the revealed slot
     * lights up in the mystery phrase and in every pending clue that shares it, exactly
     * like solving a clue would — this reuses the same revealedslots set submit_clue_guess()
     * already writes to, so no separate per-clue "hint revealed" state is needed.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $userid User id.
     * @return array [$state, $notification, $notificationtype]
     */
    public static function reveal_hint(array $state, \stdClass $instance, int $userid): array {
        if (empty($state['themewordid']) || !empty($state['finished'])) {
            return [$state, get_string('roundfinished', 'mod_playercross'), 'warning'];
        }

        $hiddenslots = array_values(array_diff(range(1, (int)$state['slotcount']), $state['revealedslots']));
        if (empty($hiddenslots)) {
            return [$state, get_string('hintnotavailable', 'mod_playercross'), 'warning'];
        }

        $hintcostitem = (int)($instance->hud_hint_cost_item ?? 0);
        if ($hintcostitem > 0) {
            $blockinstanceid = hud_service::resolve_block_instance_id($instance);
            $consumed = hud_service::consume_items(
                $blockinstanceid,
                $userid,
                $hintcostitem,
                max(1, (int)($instance->hud_hint_cost_qty ?? 1))
            );
            if (!$consumed) {
                $itemname = hud_service::get_item_name($blockinstanceid, $hintcostitem);
                return [$state, get_string('hud_insufficient_hint', 'mod_playercross', $itemname), 'warning'];
            }
        }

        sort($hiddenslots);
        $state['revealedslots'][] = $hiddenslots[0];

        return [$state, get_string('hintrevealed', 'mod_playercross'), 'success'];
    }

    /**
     * Validates and applies one guess for a specific clue.
     *
     * On a correct guess, every theme slot the clue's word covers is revealed — in the
     * mystery phrase and, implicitly, in every other pending clue that shares one of
     * those slots too, since revealedslots is a single set shared by the whole puzzle.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @param int $clueid Clue word id.
     * @param string $guess Raw guess text.
     * @return array [$state, $resolved, $notification, $notificationtype]
     */
    public static function submit_clue_guess(
        array $state,
        \stdClass $instance,
        int $cmid,
        int $userid,
        int $clueid,
        string $guess
    ): array {
        if (!empty($state['finished'])) {
            return [$state, false, get_string('roundfinished', 'mod_playercross'), 'warning'];
        }

        $index = self::find_clue_index($state, $clueid);
        if ($index === null) {
            return [$state, false, get_string('cluenotavailable', 'mod_playercross'), 'warning'];
        }

        $clue = $state['clues'][$index];
        if ($clue['resolved'] || $clue['exhausted']) {
            return [$state, false, get_string('cluenotavailable', 'mod_playercross'), 'warning'];
        }

        $normalizedguess = word_normalizer::normalize($guess);
        if (!word_normalizer::is_valid_charset($normalizedguess)) {
            return [$state, false, get_string('error_invalidchars', 'mod_playercross'), 'warning'];
        }

        $state['attemptsused']++;
        $state['clues'][$index]['attemptsused']++;

        if ($normalizedguess !== $clue['word']) {
            $maxattempts = (int)$instance->max_attempts_per_clue;
            if ($maxattempts > 0 && $state['clues'][$index]['attemptsused'] >= $maxattempts) {
                $state['clues'][$index]['exhausted'] = true;
                return [$state, false, get_string('clueexhausted', 'mod_playercross'), 'warning'];
            }
            return [$state, false, null, null];
        }

        $state['clues'][$index]['resolved'] = true;
        $state['cluesresolved']++;
        $state['revealedslots'] = array_values(array_unique(array_merge($state['revealedslots'], $clue['slots'])));

        $points = gameplay_service::calculate_clue_points(
            $instance,
            (int)$state['cluestotal'],
            $state['clues'][$index]['attemptsused']
        );
        $state['scoreaccumulated'] += $points;

        if ($state['cluesresolved'] >= $state['cluestotal']) {
            $state = self::finish_round($state, $instance, $cmid, $userid, true, false, false, false);
            return [$state, true, get_string('roundwon', 'mod_playercross'), 'success'];
        }

        return [$state, true, get_string('clueresolved', 'mod_playercross'), 'success'];
    }

    /**
     * Validates and applies a direct guess of the mystery phrase, available at any
     * point in the round, even with clues still pending.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @param string $guess Raw guess text.
     * @return array [$state, $correct, $notification, $notificationtype]
     */
    public static function submit_final_guess(
        array $state,
        \stdClass $instance,
        int $cmid,
        int $userid,
        string $guess
    ): array {
        if (!empty($state['finished'])) {
            return [$state, false, get_string('roundfinished', 'mod_playercross'), 'warning'];
        }

        $normalizedguess = word_normalizer::normalize($guess);
        if (!word_normalizer::is_valid_charset($normalizedguess)) {
            return [$state, false, get_string('error_invalidchars', 'mod_playercross'), 'warning'];
        }

        $state['attemptsused']++;

        if ($normalizedguess !== $state['themeword']) {
            return [$state, false, get_string('finalguesswrong', 'mod_playercross'), 'warning'];
        }

        $bonus = gameplay_service::calculate_final_guess_bonus(
            $instance,
            (int)$state['cluestotal'],
            (int)$state['cluesresolved']
        );
        $state['scoreaccumulated'] += $bonus;

        $state = self::finish_round($state, $instance, $cmid, $userid, true, false, false, true);

        return [$state, true, get_string('roundwon', 'mod_playercross'), 'success'];
    }

    /**
     * Handles a forfeit: ends the round without resolving any remaining clue.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return array [$state, $notification, $notificationtype]
     */
    public static function forfeit(array $state, \stdClass $instance, int $cmid, int $userid): array {
        if (empty($state['themewordid']) || !empty($state['finished'])) {
            return [$state, get_string('roundfinished', 'mod_playercross'), 'warning'];
        }

        $state = self::finish_round($state, $instance, $cmid, $userid, false, true, false, false);

        return [$state, get_string('roundforfeited', 'mod_playercross'), 'warning'];
    }

    /**
     * Handles a timer expiry: identical to forfeit but records a timedout flag.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return array [$state, $notification, $notificationtype]
     */
    public static function timeout(array $state, \stdClass $instance, int $cmid, int $userid): array {
        if (empty($state['themewordid']) || !empty($state['finished'])) {
            return [$state, get_string('roundfinished', 'mod_playercross'), 'warning'];
        }

        // The client fires this the moment its own countdown reaches zero — never trust
        // that alone. Re-check the deadline server-side (with a small tolerance for
        // normal network latency) so neither clock drift nor a premature/forged call can
        // end a round before its configured time has actually run out.
        $deadline = (int)$state['starttime'] + (int)$instance->timer_seconds;
        if ((int)$instance->timer_seconds <= 0 || time() < $deadline - self::TIMEOUT_TOLERANCE_SECONDS) {
            return [$state, get_string('roundnottimedout', 'mod_playercross'), 'warning'];
        }

        $state = self::finish_round($state, $instance, $cmid, $userid, false, false, true, false);

        return [$state, get_string('roundtimeout', 'mod_playercross'), 'warning'];
    }

    /**
     * Applies the shared "round just finished" bookkeeping.
     *
     * The single place all finish paths (all clues resolved, direct final guess,
     * forfeit, timeout) go through: flags, score persistence, the attempts row,
     * completion state and the grade update.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @param bool $won Whether the player won the round.
     * @param bool $forfeited Whether the player gave up.
     * @param bool $timedout Whether the timer expired.
     * @param bool $finalguessed Whether the round ended via a correct direct guess.
     * @return array Updated state.
     */
    private static function finish_round(
        array $state,
        \stdClass $instance,
        int $cmid,
        int $userid,
        bool $won,
        bool $forfeited,
        bool $timedout,
        bool $finalguessed
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playercross/lib.php');

        $state['finished']     = true;
        $state['endtime']      = time();
        $state['won']          = $won;
        $state['forfeited']    = $forfeited;
        $state['timedout']     = $timedout;
        $state['finalguessed'] = $finalguessed;

        $timeused = max(0, time() - (int)$state['starttime']);
        $score = round((float)$state['scoreaccumulated'], 5);

        $attemptid = $DB->insert_record('playercross_attempts', (object)[
            'playercrossid' => $instance->id,
            'userid'        => $userid,
            'themewordid'   => (int)$state['themewordid'],
            'cluestotal'    => (int)$state['cluestotal'],
            'cluesresolved' => (int)$state['cluesresolved'],
            'finalguessed'  => $finalguessed ? 1 : 0,
            'attempts_used' => (int)$state['attemptsused'],
            'time_used'     => $timeused,
            'completed'     => $won ? 1 : 0,
            'score'         => $score,
            'timecreated'   => time(),
        ]);

        $event = \mod_playercross\event\round_completed::create([
            'objectid' => $attemptid,
            'context'  => \context_module::instance($cmid),
            'other'    => [
                'completed'     => $won,
                'finalguessed'  => $finalguessed,
                'score'         => $score,
                'cluesresolved' => (int)$state['cluesresolved'],
                'cluestotal'    => (int)$state['cluestotal'],
                'attemptsused'  => (int)$state['attemptsused'],
                'timeused'      => $timeused,
                'themewordid'   => (int)$state['themewordid'],
            ],
        ]);
        $event->trigger();

        playercross_update_grades($instance, $userid);

        // Grants only on a genuine win, never on forfeit/timeout. Items are still granted
        // on an unlimited-rounds activity, but their XP is withheld — the same
        // anti-farming rule block_playerhud itself applies to its own infinite drops,
        // replicated here since this grant never goes through a real drop.
        if ($won) {
            $grantitem = (int)($instance->hud_win_reward_item ?? 0);
            if ($grantitem > 0) {
                hud_service::grant_items(
                    hud_service::resolve_block_instance_id($instance),
                    $userid,
                    $grantitem,
                    max(1, (int)($instance->hud_win_reward_qty ?? 1)),
                    (int)$instance->max_rounds === 0
                );
            }
        }

        // Automatic completion (e.g. the "require completed rounds" custom rule) is only
        // recomputed and persisted when something explicitly asks for it — Moodle has no
        // cron sweep for this, unlike grading. Trigger it here so the activity page's
        // completion badge reflects a finished round immediately.
        $cm = get_coursemodule_from_id('playercross', $cmid, 0, false, MUST_EXIST);
        $course = get_course($instance->course);
        $completioninfo = new completion_info($course);
        if ($completioninfo->is_enabled($cm)) {
            $completioninfo->update_state($cm, COMPLETION_COMPLETE, $userid);
        }

        return $state;
    }
}
