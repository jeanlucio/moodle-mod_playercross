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
     * Gets session state, creating defaults when missing. Also discards state left
     * over from an older, structurally incompatible version of puzzle_builder — see
     * state_is_valid() — so a round that was mid-play across a plugin upgrade starts
     * fresh instead of fataling the next time it is rendered.
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
        if (!isset($SESSION->mod_playercross[$sessionkey]) || !self::state_is_valid($SESSION->mod_playercross[$sessionkey])) {
            $SESSION->mod_playercross[$sessionkey] = self::default_state();
        }

        return $SESSION->mod_playercross[$sessionkey];
    }

    /**
     * Checks that a round's state still matches the shape the current code expects:
     * that it carries the round-wide mystery phrase (themewords — see SCOPE.md §20.2
     * v1.9, before which the mystery was a single themeword string), its own original
     * (accented) spelling (themehint, added for the post-round reveal), that every
     * clue's per-position slots array is exactly as long as its own word (before slots
     * became a round-wide, per-position map, see §20.2 v1.7), and that every clue
     * carries its own original spelling too (originalword, same reveal purpose as
     * themehint). A round started under an older version of puzzle_builder can still be
     * sitting in a live PHP session at the moment the plugin is upgraded; without this
     * check, round_presenter would fatal on an undefined array key the first time that
     * stale round is rendered, instead of transparently starting a fresh one.
     *
     * @param array $state Session state.
     * @return bool
     */
    private static function state_is_valid(array $state): bool {
        if (!isset($state['themewords'], $state['themehint']) || !is_array($state['themewords'])) {
            return false;
        }
        foreach ($state['clues'] ?? [] as $clue) {
            if (
                !isset($clue['slots'], $clue['word'], $clue['originalword'])
                || count($clue['slots']) !== count(word_normalizer::chars($clue['word']))
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns the default (empty) round state shape.
     *
     * @return array
     */
    private static function default_state(): array {
        return [
            'themewordid'   => 0,
            'themeconcept'  => '',
            'themewords'    => [],
            'themehint'     => '',
            'themeslots'    => [],
            'slotcount'     => 0,
            'revealedslots' => [],
            'hintsused'     => 0,
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
            'finalguesscorrect' => false,
            'finalguesseddirectly' => false,
            'cluesexhausted' => false,
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
     * belongs exclusively to new_round(). Also never picks a new puzzle while
     * get_round_restriction_notice() reports max_rounds or cooldown as active — this is
     * the single point every caller (page render, and the start_round/submit_clue_guess/
     * submit_final_guess/reveal_hint/new_round externals) goes through to pick a puzzle,
     * so the restriction is enforced once here instead of at each call site.
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

        if (self::get_round_restriction_notice($instance, $userid) !== null) {
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
                'originalword' => $clue->originalword,
                'hint'         => $clue->hint,
                'slots'        => $clue->slots,
                'resolved'     => false,
                'attemptsused' => 0,
                'exhausted'    => false,
            ];
        }

        $state = self::default_state();
        $state['themewordid']   = $puzzle->themewordid;
        $state['themeconcept']  = $puzzle->themeconcept;
        $state['themewords']    = $puzzle->themewords;
        $state['themehint']     = $puzzle->themehint;
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
     * Revalidates get_round_restriction_notice() again here, not just at the point
     * ensure_round_state() picked the puzzle: a puzzle can sit armed in session state
     * for a while (the student never reaching "Iniciar rodada"), and a second
     * concurrent session for the same user can reach that same armed state — e.g. two
     * open tabs, or the round limit being hit in one session while another already
     * loaded the lobby. Without this check, the second session could still commit the
     * reservation and PlayerHUD cost after the limit/cooldown became active.
     *
     * The guest account is exempt from the PlayerHUD cost: a course's guest-access
     * visitors all share the single guest user record, so nothing charged here could be
     * safely attributed to one specific person. See finish_round() for the matching
     * exemption on the persistence side, which is what actually keeps a guest from
     * leaving any trace in {playercross_attempts}.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $userid User id.
     * @return array [$state, $notification, $notificationtype, $toast]
     */
    public static function start_round(array $state, \stdClass $instance, int $userid): array {
        $isguest = isguestuser();

        if (!$isguest) {
            $restrictionnotice = self::get_round_restriction_notice($instance, $userid);
            if ($restrictionnotice !== null) {
                return [$state, $restrictionnotice, 'warning', true];
            }
        }

        $roundcostitem = (int)($instance->hud_round_cost_item ?? 0);
        if (!$isguest && $roundcostitem > 0) {
            $blockinstanceid = hud_service::resolve_block_instance_id($instance);
            $consumed = hud_service::consume_items(
                $blockinstanceid,
                $userid,
                $roundcostitem,
                max(1, (int)($instance->hud_round_cost_qty ?? 1))
            );
            if (!$consumed) {
                $itemname = hud_service::get_item_name($blockinstanceid, $roundcostitem);
                return [$state, get_string('hud_insufficient_round', 'mod_playercross', $itemname), 'warning', true];
            }
        }

        $state['starttime'] = time();
        $state['roundstarted'] = true;

        return [$state, null, null, true];
    }

    /**
     * Whether a started, timed round's deadline has genuinely passed, with the same
     * tolerance window timeout() applies in the opposite direction: timeout() refuses
     * to close a round up to TIMEOUT_TOLERANCE_SECONDS before the deadline (never trust
     * the client's own countdown alone), so a guess or hint arriving up to that same
     * window after the deadline is still accepted here — normal network latency, not a
     * bypass. An untimed activity (timer_seconds <= 0) or a round that never started
     * never expires by this check.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @return bool
     */
    private static function round_expired(array $state, \stdClass $instance): bool {
        if ((int)$instance->timer_seconds <= 0 || empty($state['roundstarted'])) {
            return false;
        }

        $deadline = (int)$state['starttime'] + (int)$instance->timer_seconds;
        return time() > $deadline + self::TIMEOUT_TOLERANCE_SECONDS;
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
     * Marks any still-active clue as resolved once every one of its own slots is
     * already in revealedslots — reached, for instance, when a clue's word happens to
     * be made entirely of letters shared with the mystery phrase, and a correct final
     * guess reveals every phrase slot at once (see submit_final_guess()). Without this,
     * such a clue would sit with every tile locked-and-revealed but no editable box
     * left for the player to ever submit its own guess through — resolved stays false
     * and the round can never finish under PLAYERCROSS_WINCONDITION_BOTH.
     *
     * Awarded the same points a direct correct guess would, treated as solved on the
     * first attempt since none was actually made — the player already demonstrated
     * full knowledge of every one of its letters.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @return array Updated state.
     */
    private static function resolve_fully_revealed_clues(array $state, \stdClass $instance): array {
        foreach ($state['clues'] as $index => $clue) {
            if ($clue['resolved'] || $clue['exhausted']) {
                continue;
            }
            if (array_diff($clue['slots'], $state['revealedslots']) !== []) {
                continue;
            }

            $state['clues'][$index]['resolved'] = true;
            $state['cluesresolved']++;
            $state['scoreaccumulated'] += gameplay_service::calculate_clue_points(
                $instance,
                (int)$state['cluestotal'],
                (int)$state['clues'][$index]['attemptsused']
            );
        }
        return $state;
    }

    /**
     * Marks the mystery phrase itself as correctly guessed once every one of its own
     * slots is already revealed — the same situation resolve_fully_revealed_clues()
     * handles for a single clue, but for the phrase's own guess form: reached when
     * reveal_hint() alone, never a typed-and-submitted guess through that form, has
     * revealed every theme slot. Without this, finalguesscorrect stays false forever
     * with no editable box left in the phrase's own form to ever set it through, and
     * the round can never finish (PLAYERCROSS_WINCONDITION_BOTH) or award its final
     * bonus (PLAYERCROSS_WINCONDITION_FINALONLY).
     *
     * @param array $state Current state.
     * @return array Updated state.
     */
    private static function confirm_fully_revealed_theme(array $state): array {
        if (empty($state['finalguesscorrect']) && array_diff($state['themeslots'], $state['revealedslots']) === []) {
            $state['finalguesscorrect'] = true;
        }
        return $state;
    }

    /**
     * Runs after every operation that adds to revealedslots — a clue resolved
     * (submit_clue_guess()), a hint revealed (reveal_hint()), or the phrase itself
     * confirmed (submit_final_guess()) — regardless of which of those three actually
     * triggered it. Any one of them can, via shared slots, incidentally complete a
     * different clue or the phrase itself: resolving three ordinary clues can reveal
     * every mystery-phrase slot as a side effect just as easily as a hint or a direct
     * final guess can. Centralizing the consequences here — auto-resolving whatever
     * clue is now fully revealed, confirming the phrase if it is too, and finishing the
     * round if that satisfies the win condition — means no entry point that touches
     * revealedslots can be the one left without this safeguard; retrofitting it three
     * separate times, once per call site, is exactly how it went missing from
     * submit_clue_guess() the first time around.
     *
     * The round can finish here regardless of which of the three callers triggered it,
     * but the "solved directly" feedback message must only fire when the phrase was
     * actually typed and submitted correctly through submit_final_guess() — never when
     * the same slots simply ended up revealed as a side effect of resolving clues or
     * spending a hint. state['finalguesseddirectly'] carries that distinction (set only
     * by submit_final_guess(), never by confirm_fully_revealed_theme()) through to
     * finish_round()'s own $finalguessed parameter.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return array{0: array, 1: ?string} Updated state, and the roundwon notification
     *     if this call finished the round — null if it did not.
     */
    private static function reconcile_after_reveal(
        array $state,
        \stdClass $instance,
        int $cmid,
        int $userid
    ): array {
        $state = self::resolve_fully_revealed_clues($state, $instance);
        $state = self::confirm_fully_revealed_theme($state);

        $wincondition = (int)($instance->win_condition ?? PLAYERCROSS_WINCONDITION_BOTH);
        $readytowin = !empty($state['finalguesscorrect'])
            && ($wincondition === PLAYERCROSS_WINCONDITION_FINALONLY || $state['cluesresolved'] >= $state['cluestotal']);

        if (!$readytowin) {
            return [$state, null];
        }

        $bonus = gameplay_service::calculate_final_guess_bonus(
            $instance,
            (int)$state['cluestotal'],
            (int)$state['cluesresolved']
        );
        $state['scoreaccumulated'] += $bonus;
        $state = self::finish_round(
            $state,
            $instance,
            $cmid,
            $userid,
            true,
            false,
            false,
            !empty($state['finalguesseddirectly'])
        );

        return [$state, get_string('roundwon', 'mod_playercross')];
    }

    /**
     * Reveals one still-hidden letter anywhere in the round, optionally consuming a
     * PlayerHUD item cost. A global action, not scoped to any single clue or to the
     * mystery phrase specifically: the revealed slot lights up in the mystery phrase
     * and in every pending clue that shares it, exactly like solving a clue would —
     * this reuses the same revealedslots set submit_clue_guess() already writes to, so
     * no separate per-clue "hint revealed" state is needed.
     *
     * Candidates are every slot in the round-wide slot map (state['slotcount']), which
     * also numbers letters exclusive to a clue that never appear in the mystery phrase
     * at all (SCOPE.md §20.2 v1.7) — a hint can therefore reveal a letter inside a
     * single clue's own word without touching the mystery phrase's own tile row. This
     * keeps the action useful even after the whole mystery phrase is already revealed,
     * as long as any clue still has a hidden letter of its own.
     *
     * Also closes a soft-lock possible once every hidden slot in the round has been
     * hinted away: with no clue and no phrase tile left unrevealed, neither the
     * phrase's own guess form nor the last clue's has any editable box left, so the
     * player could never submit a guess through them again to actually finish the
     * round. reconcile_after_reveal() covers exactly that, and — unlike a plain hint
     * reveal — a call that completes the round this way finishes it immediately, the
     * same as a correct submit_final_guess() would.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return array [$state, $notification, $notificationtype, $toast]
     */
    public static function reveal_hint(array $state, \stdClass $instance, int $cmid, int $userid): array {
        // The guest account is exempt from the PlayerHUD hint cost — see start_round()
        // for why.
        if (empty($state['themewordid']) || !empty($state['finished'])) {
            return [$state, get_string('roundfinished', 'mod_playercross'), 'warning', true];
        }

        // Requires roundstarted: see submit_clue_guess() for why — the hint itself also
        // has its own configurable PlayerHUD cost, but start_round() is where the player
        // commits to the round, and a hint pulled before that point would still be a way
        // to play part of the round for free.
        if (empty($state['roundstarted'])) {
            return [$state, get_string('roundnotstarted', 'mod_playercross'), 'warning', true];
        }

        // See submit_clue_guess() for why this is re-checked here too, not just in
        // timeout() itself.
        if (self::round_expired($state, $instance)) {
            $state = self::finish_round($state, $instance, $cmid, $userid, false, false, true, false);
            return [$state, get_string('roundtimeout', 'mod_playercross'), 'warning', true];
        }

        if (empty($instance->hints_enabled)) {
            return [$state, get_string('hintsdisabled', 'mod_playercross'), 'warning', true];
        }

        $hiddenslots = array_values(array_diff(range(1, (int)$state['slotcount']), $state['revealedslots']));
        if (empty($hiddenslots)) {
            return [$state, get_string('hintnotavailable', 'mod_playercross'), 'warning', true];
        }

        $maxhints = (int)($instance->max_hints_per_round ?? 0);
        if ($maxhints > 0 && (int)($state['hintsused'] ?? 0) >= $maxhints) {
            return [$state, get_string('hintlimitreached', 'mod_playercross'), 'warning', true];
        }

        $hintcostitem = (int)($instance->hud_hint_cost_item ?? 0);
        if (!isguestuser() && $hintcostitem > 0) {
            $blockinstanceid = hud_service::resolve_block_instance_id($instance);
            $consumed = hud_service::consume_items(
                $blockinstanceid,
                $userid,
                $hintcostitem,
                max(1, (int)($instance->hud_hint_cost_qty ?? 1))
            );
            if (!$consumed) {
                $itemname = hud_service::get_item_name($blockinstanceid, $hintcostitem);
                return [$state, get_string('hud_insufficient_hint', 'mod_playercross', $itemname), 'warning', true];
            }
        }

        sort($hiddenslots);
        $state['revealedslots'][] = $hiddenslots[0];
        $state['hintsused'] = (int)($state['hintsused'] ?? 0) + 1;

        // See reconcile_after_reveal(): this hint can, via shared slots, leave a clue or
        // the phrase itself fully revealed with no editable box left in its own form —
        // and finish the round outright if that satisfies the win condition.
        [$state, $wonmessage] = self::reconcile_after_reveal($state, $instance, $cmid, $userid);
        if ($wonmessage !== null) {
            return [$state, $wonmessage, 'success', true];
        }

        // Every round-flow message is an auto-dismissing toast, milestones included:
        // a persistent notification (Notification::addNotification()) requires the
        // player to close it by hand, and since it never auto-clears, several of them
        // pile up across one round (e.g. a wrong guess still on screen next to the
        // round-won message), reading as contradictory feedback. Toasts fade on their
        // own and never accumulate that way, so every message here uses that channel.
        return [$state, get_string('hintrevealed', 'mod_playercross'), 'success', true];
    }

    /**
     * Validates and applies one guess for a specific clue.
     *
     * On a correct guess, every theme slot the clue's word covers is revealed — in the
     * mystery phrase and, implicitly, in every other pending clue that shares one of
     * those slots too, since revealedslots is a single set shared by the whole puzzle.
     *
     * Under PLAYERCROSS_WINCONDITION_BOTH (the default), resolving the last pending
     * clue only finishes the round if the mystery phrase is already known correct —
     * either a direct guess through its own form (submit_final_guess()) or, via
     * reconcile_after_reveal(), every one of its slots happening to be revealed
     * already as a side effect of the clues resolved so far. Under
     * PLAYERCROSS_WINCONDITION_FINALONLY, resolving every clue never finishes the round
     * by itself unless that same side effect reveals the whole phrase too.
     *
     * A clue running out of attempts under PLAYERCROSS_WINCONDITION_BOTH makes winning
     * this round mathematically impossible from that moment on — cluesresolved can
     * never reach cluestotal again — so the round ends immediately as a loss instead of
     * being left open with no way forward.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @param int $clueid Clue word id.
     * @param string $guess Raw guess text.
     * @return array [$state, $resolved, $notification, $notificationtype, $toast]
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
            return [$state, false, get_string('roundfinished', 'mod_playercross'), 'warning', true];
        }

        // Requires roundstarted: a client that calls this before start_round() —
        // skipping the "Iniciar rodada" button, which is the only place a configured
        // PlayerHUD round cost is actually charged — must not be able to play the round
        // for free.
        if (empty($state['roundstarted'])) {
            return [$state, false, get_string('roundnotstarted', 'mod_playercross'), 'warning', true];
        }

        // The client is expected to call end_round(timeout) itself once its own
        // countdown reaches zero, but nothing forces it to — a client that simply
        // never fires that call (or a reload after the deadline, since the timer is
        // only ever armed client-side when timeleft > 0) would otherwise keep this
        // round playable, and scoring, indefinitely past its configured time limit.
        // Closing it here, the same way timeout() itself would, makes the server the
        // one actually enforcing the limit.
        if (self::round_expired($state, $instance)) {
            $state = self::finish_round($state, $instance, $cmid, $userid, false, false, true, false);
            return [$state, false, get_string('roundtimeout', 'mod_playercross'), 'warning', true];
        }

        $index = self::find_clue_index($state, $clueid);
        if ($index === null) {
            return [$state, false, get_string('cluenotavailable', 'mod_playercross'), 'warning', true];
        }

        $clue = $state['clues'][$index];
        if ($clue['resolved'] || $clue['exhausted']) {
            return [$state, false, get_string('cluenotavailable', 'mod_playercross'), 'warning', true];
        }

        $normalizedguess = word_normalizer::normalize($guess);
        if (!word_normalizer::is_valid_charset($normalizedguess)) {
            return [$state, false, get_string('error_invalidchars', 'mod_playercross'), 'warning', true];
        }

        $state['attemptsused']++;
        $state['clues'][$index]['attemptsused']++;

        if ($normalizedguess !== $clue['word']) {
            $maxattempts = (int)$instance->max_attempts_per_clue;
            if ($maxattempts > 0 && $state['clues'][$index]['attemptsused'] >= $maxattempts) {
                $state['clues'][$index]['exhausted'] = true;

                $wincondition = (int)($instance->win_condition ?? PLAYERCROSS_WINCONDITION_BOTH);
                if ($wincondition === PLAYERCROSS_WINCONDITION_BOTH) {
                    $state = self::finish_round($state, $instance, $cmid, $userid, false, false, false, false, true);
                    return [$state, false, get_string('feedback_cluesexhausted', 'mod_playercross'), 'warning', true];
                }

                return [$state, false, get_string('clueexhausted', 'mod_playercross'), 'warning', true];
            }
            return [$state, false, get_string('clueguesswrong', 'mod_playercross'), 'warning', true];
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

        // See reconcile_after_reveal(): this clue's own slots can, via shared letters,
        // also complete a different still-open clue or the mystery phrase itself —
        // resolving three ordinary clues can reveal the whole phrase as a side effect
        // just as easily as a hint or a direct final guess can. Finishes the round
        // outright if that satisfies the win condition.
        [$state, $wonmessage] = self::reconcile_after_reveal($state, $instance, $cmid, $userid);
        if ($wonmessage !== null) {
            return [$state, true, $wonmessage, 'success', true];
        }

        if ($state['cluesresolved'] >= $state['cluestotal']) {
            return [$state, true, get_string('cluescompleteneedsfinal', 'mod_playercross'), 'success', true];
        }

        // Every round-flow message is an auto-dismissing toast, milestones included — see
        // the matching note in reveal_hint(): a persistent notification never clears on its
        // own, so a wrong guess and a later "round won" message would otherwise sit stacked
        // on screen together. Toasts fade out and never accumulate that way.
        return [$state, true, get_string('clueresolved', 'mod_playercross'), 'success', true];
    }

    /**
     * Validates and applies a direct guess of the mystery phrase, available at any
     * point in the round, even with clues still pending.
     *
     * The guess is normalized the same way the phrase itself was (word_normalizer::
     * normalize_phrase(): split on anything that is not a letter, lowercase and strip
     * accents per word) before comparing word-by-word — tolerant of extra whitespace,
     * casing, accents and stray punctuation, but still requires every word of the
     * phrase, in order.
     *
     * Under PLAYERCROSS_WINCONDITION_BOTH (the default), a correct guess here does not
     * finish the round by itself if clues are still pending — it is recorded
     * (finalguesscorrect), so resolving the last remaining clue afterwards finishes the
     * round immediately instead of requiring the same phrase to be guessed twice. Under
     * PLAYERCROSS_WINCONDITION_FINALONLY, a correct guess always finishes the round on
     * the spot, however many clues are still pending.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @param string $guess Raw guess text.
     * @return array [$state, $correct, $notification, $notificationtype, $toast]
     */
    public static function submit_final_guess(
        array $state,
        \stdClass $instance,
        int $cmid,
        int $userid,
        string $guess
    ): array {
        if (!empty($state['finished'])) {
            return [$state, false, get_string('roundfinished', 'mod_playercross'), 'warning', true];
        }

        // Requires roundstarted: see submit_clue_guess() for why.
        if (empty($state['roundstarted'])) {
            return [$state, false, get_string('roundnotstarted', 'mod_playercross'), 'warning', true];
        }

        // See submit_clue_guess() for why this is re-checked here too, not just in
        // timeout() itself.
        if (self::round_expired($state, $instance)) {
            $state = self::finish_round($state, $instance, $cmid, $userid, false, false, true, false);
            return [$state, false, get_string('roundtimeout', 'mod_playercross'), 'warning', true];
        }

        $guesswords = word_normalizer::normalize_phrase($guess);
        if ($guesswords === []) {
            return [$state, false, get_string('error_invalidchars', 'mod_playercross'), 'warning', true];
        }

        $state['attemptsused']++;

        if ($guesswords !== $state['themewords']) {
            return [$state, false, get_string('finalguesswrong', 'mod_playercross'), 'warning', true];
        }

        $state['finalguesscorrect'] = true;
        $state['finalguesseddirectly'] = true;

        // Reveals the mystery phrase's own tiles immediately, independently of whether the
        // round finishes here — a correct guess demonstrates the player already knows every
        // letter, so leaving the tiles blank until every clue is also solved (WINCONDITION_BOTH)
        // would contradict the positive feedback they just received. themeslots (the phrase's
        // own slot numbers) is used here rather than the round-wide slotcount range, so this
        // never reveals a slot exclusive to a still-unsolved clue's own word — same distinction
        // reveal_hint() draws (see its docblock).
        $state['revealedslots'] = array_values(array_unique(array_merge($state['revealedslots'], $state['themeslots'])));

        // See reconcile_after_reveal(): finalguesscorrect is already true above, so this
        // resolves any clue left fully revealed as a side effect and finishes the round
        // outright once the win condition is met (immediately under
        // PLAYERCROSS_WINCONDITION_FINALONLY, or once every clue is resolved too under
        // PLAYERCROSS_WINCONDITION_BOTH).
        [$state, $wonmessage] = self::reconcile_after_reveal($state, $instance, $cmid, $userid);
        if ($wonmessage !== null) {
            return [$state, true, $wonmessage, 'success', true];
        }

        return [$state, true, get_string('finalguesscorrectneedsclues', 'mod_playercross'), 'success', true];
    }

    /**
     * Handles a forfeit: ends the round without resolving any remaining clue.
     *
     * Requires roundstarted: see submit_clue_guess() for why — a puzzle armed at
     * view.php's GET-time ensure_round_state() call, before the "Iniciar rodada"
     * button is ever clicked, must not be endable at all, let alone spend one of the
     * student's max_rounds for a round they never actually played.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return array [$state, $notification, $notificationtype, $toast]
     */
    public static function forfeit(array $state, \stdClass $instance, int $cmid, int $userid): array {
        if (empty($state['themewordid']) || !empty($state['finished'])) {
            return [$state, get_string('roundfinished', 'mod_playercross'), 'warning', true];
        }

        if (empty($state['roundstarted'])) {
            return [$state, get_string('roundnotstarted', 'mod_playercross'), 'warning', true];
        }

        $state = self::finish_round($state, $instance, $cmid, $userid, false, true, false, false);

        return [$state, get_string('roundforfeited', 'mod_playercross'), 'warning', true];
    }

    /**
     * Handles a timer expiry: identical to forfeit but records a timedout flag.
     *
     * Requires roundstarted, checked before the deadline itself is computed: with
     * starttime still at its default of 0, $deadline would sit in the remote past and
     * the tolerance check below would pass unconditionally, defeating the very
     * anti-forgery purpose it documents.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return array [$state, $notification, $notificationtype, $toast]
     */
    public static function timeout(array $state, \stdClass $instance, int $cmid, int $userid): array {
        if (empty($state['themewordid']) || !empty($state['finished'])) {
            return [$state, get_string('roundfinished', 'mod_playercross'), 'warning', true];
        }

        if (empty($state['roundstarted'])) {
            return [$state, get_string('roundnotstarted', 'mod_playercross'), 'warning', true];
        }

        // The client fires this the moment its own countdown reaches zero — never trust
        // that alone. Re-check the deadline server-side (with a small tolerance for
        // normal network latency) so neither clock drift nor a premature/forged call can
        // end a round before its configured time has actually run out.
        $deadline = (int)$state['starttime'] + (int)$instance->timer_seconds;
        if ((int)$instance->timer_seconds <= 0 || time() < $deadline - self::TIMEOUT_TOLERANCE_SECONDS) {
            return [$state, get_string('roundnottimedout', 'mod_playercross'), 'warning', true];
        }

        $state = self::finish_round($state, $instance, $cmid, $userid, false, false, true, false);

        return [$state, get_string('roundtimeout', 'mod_playercross'), 'warning', true];
    }

    /**
     * Closes a started round if its deadline has already passed, the same way
     * end_round(timeout) would. Used by view_page_service so a page reload after the
     * deadline renders the round as finished immediately, instead of it only closing
     * on the player's next guess/hint attempt (round_expired() is also checked at the
     * top of submit_clue_guess(), submit_final_guess() and reveal_hint(), which is the
     * actual security boundary — this is purely about not rendering stale playable
     * forms on a read-only GET).
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @return array Updated state.
     */
    public static function close_if_expired(array $state, \stdClass $instance, int $cmid, int $userid): array {
        if (self::round_expired($state, $instance)) {
            return self::finish_round($state, $instance, $cmid, $userid, false, false, true, false);
        }
        return $state;
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
     * @param bool $cluesexhausted Whether the round ended because a clue ran out of
     *     attempts under PLAYERCROSS_WINCONDITION_BOTH, making a win impossible. Only
     *     ever used to pick the right feedback message — not persisted to the attempts
     *     table, which already records the loss via $won.
     *
     * The guest account never reaches the persistence block below: no attempt row, no
     * round_completed event, no grade update, no PlayerHUD grant, no completion update.
     * $state still gets 'finished'/'won'/etc. set above the cut so the UI can show the
     * round's outcome — see start_round() for why guests are exempt in the first place.
     *
     * The persistence block is serialised per (playercrossid, userid) with
     * \core\lock\lock_config and re-checks get_round_restriction_notice() immediately
     * before the insert. start_round() already revalidates the same restriction, but
     * that check and this insert are not otherwise atomic: two concurrent sessions of
     * the same user (two open tabs) can both pass start_round()'s check — neither has
     * inserted an attempt yet — and then both reach this insert, each ending up past
     * max_rounds/cooldown and each granting its own PlayerHUD item. The lock closes
     * that window; a session that loses the race ends its round without persisting an
     * attempt or a grant, the same outcome get_round_restriction_notice() already
     * produces elsewhere when the limit is genuinely reached.
     *
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
        bool $finalguessed,
        bool $cluesexhausted = false
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playercross/lib.php');

        $state['finished']     = true;
        $state['endtime']      = time();
        $state['won']          = $won;
        $state['forfeited']    = $forfeited;
        $state['cluesexhausted'] = $cluesexhausted;
        $state['timedout']     = $timedout;
        $state['finalguessed'] = $finalguessed;

        if (isguestuser()) {
            return $state;
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_playercross');
        $lock = $lockfactory->get_lock('finish_round_' . $instance->id . '_' . $userid, 5);
        if (!$lock) {
            // Could not serialise against a concurrent session within the timeout —
            // degrade the same way a genuinely reached restriction does below, rather
            // than risk two sessions inserting past the limit anyway.
            return $state;
        }

        try {
            if (self::get_round_restriction_notice($instance, $userid) !== null) {
                // A concurrent session already consumed the last available round or
                // cooldown slot between this round's start_round() check and this
                // insert. The round still shows its outcome to this player (flags set
                // above), it just is not counted a second time.
                return $state;
            }

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

            // Grants only on a genuine win, never on forfeit/timeout. Items are still
            // granted on an unlimited-rounds activity, but their XP is withheld — the
            // same anti-farming rule block_playerhud itself applies to its own infinite
            // drops, replicated here since this grant never goes through a real drop.
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

            // Automatic completion (e.g. the "require completed rounds" custom rule)
            // is only recomputed and persisted when something explicitly asks for it —
            // Moodle has no cron sweep for this, unlike grading. Trigger it here so the
            // activity page's completion badge reflects a finished round immediately.
            $cm = get_coursemodule_from_id('playercross', $cmid, 0, false, MUST_EXIST);
            $course = get_course($instance->course);
            $completioninfo = new completion_info($course);
            if ($completioninfo->is_enabled($cm)) {
                $completioninfo->update_state($cm, COMPLETION_COMPLETE, $userid);
            }
        } finally {
            $lock->release();
        }

        return $state;
    }
}
