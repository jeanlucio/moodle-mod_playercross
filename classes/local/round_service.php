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
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use completion_info;

/**
 * Owns every round-state mutation: starting, guessing a term, guessing the mystery
 * phrase directly, revealing a hint, forfeiting, timing out and starting a new round.
 * This is the single source of truth for what happens on each transition, shared by
 * the classic page render and by the AJAX external functions.
 *
 * Like mod_playerwords, PlayerCross reserves a playercross_attempts row the moment a
 * round starts (timefinished=0) and finalizes that same row when it ends
 * (timefinished=time()) — start_round() inserts, finish_round() updates by attemptid.
 * The puzzle content itself (mystery phrase, term list, revealed slots) still lives
 * only in session state; only the reservation/outcome row is persisted early. Every
 * reader that must ignore a still-open reservation filters on timefinished > 0.
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
     * (accented) spelling (themeclue, added for the post-round reveal), that every
     * term's per-position slots array is exactly as long as its own word (before slots
     * became a round-wide, per-position map, see §20.2 v1.7), and that every term
     * carries its own original spelling too (originalword, same reveal purpose as
     * themeclue). A round started under an older version of puzzle_builder can still be
     * sitting in a live PHP session at the moment the plugin is upgraded; without this
     * check, round_presenter would fatal on an undefined array key the first time that
     * stale round is rendered, instead of transparently starting a fresh one.
     *
     * @param array $state Session state.
     * @return bool
     */
    private static function state_is_valid(array $state): bool {
        if (!isset($state['themewords'], $state['themeclue']) || !is_array($state['themewords'])) {
            return false;
        }
        foreach ($state['terms'] ?? [] as $term) {
            if (
                !isset($term['slots'], $term['word'], $term['originalword'])
                || count($term['slots']) !== count(word_normalizer::chars($term['word']))
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
            'themeclue'     => '',
            'themeslots'    => [],
            'slotcount'     => 0,
            'revealedslots' => [],
            'hintsused'     => 0,
            'terms'         => [],
            'termstotal'    => 0,
            'termsresolved' => 0,
            'errorsused'    => 0,
            'attemptsused'  => 0,
            'finalguessattemptsused' => 0,
            'finalguessexhausted' => false,
            'score'         => 0.0,
            'rankingpoints' => 0.0,
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
            'earlybonuseligible' => false,
            'termsexhausted' => false,
            'attemptid'     => 0,
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

        // A still-open reservation (timefinished = 0) must not start the cooldown clock —
        // only a genuinely finished round can.
        $lastattempttime = $DB->get_field_sql(
            "SELECT MAX(timecreated) FROM {playercross_attempts}"
            . " WHERE playercrossid = :pid AND userid = :uid AND timefinished > 0",
            ['pid' => $instance->id, 'uid' => $userid]
        );
        if (empty($lastattempttime)) {
            return 0;
        }

        $until = (int)$lastattempttime + (int)$instance->cooldown_seconds;
        return $until > time() ? $until : 0;
    }

    /**
     * Counts how many rounds count against a user's round limit for this instance.
     *
     * Deliberately unfiltered by timefinished: a reservation still open right now (in
     * progress, or abandoned without ever finishing) already counts, exactly like
     * mod_playerwords' own count_rounds_played(). Without this, a student could abandon
     * a losing round for free and start over indefinitely.
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
     * the single point every caller (page render, and the start_round/submit_term_guess/
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

        $terms = [];
        foreach ($puzzle->terms as $term) {
            $terms[] = [
                'wordid'       => $term->wordid,
                'word'         => $term->word,
                'originalword' => $term->originalword,
                'clue'         => $term->clue,
                'slots'        => $term->slots,
                'resolved'     => false,
                'attemptsused' => 0,
                'exhausted'    => false,
            ];
        }

        $state = self::default_state();
        $state['themewordid']   = $puzzle->themewordid;
        $state['themeconcept']  = $puzzle->themeconcept;
        $state['themewords']    = $puzzle->themewords;
        $state['themeclue']     = $puzzle->themeclue;
        $state['themeslots']    = $puzzle->themeslots;
        $state['slotcount']     = $puzzle->slotcount;
        $state['revealedslots'] = $puzzle->alwaysrevealedslots;
        $state['terms']         = $terms;
        $state['termstotal']    = count($terms);

        $event = \mod_playercross\event\round_started::create([
            'objectid' => $puzzle->themewordid,
            'context'  => \context_module::instance($cmid),
            'other'    => ['termstotal' => count($terms)],
        ]);
        $event->trigger();

        return $state;
    }

    /**
     * Starts the round timer, reserving a playercross_attempts row and optionally
     * consuming a PlayerHUD item cost.
     *
     * The restriction re-check, the PlayerHUD cost charge and the reservation insert are
     * serialised per (playercrossid, userid) with \core\lock\lock_config: a puzzle can
     * sit armed in session state for a while (the student never reaching "Iniciar
     * rodada"), and a second concurrent session for the same user can reach that same
     * armed state — e.g. two open tabs, or the round limit being hit in one session
     * while another already loaded the lobby. Without the lock, two sessions could both
     * pass the restriction check — neither has reserved yet — and then both charge their
     * own PlayerHUD cost and insert their own row, each ending up past
     * max_rounds/cooldown. Reserving here, inside the lock, is also what makes
     * finish_round() itself lock-free: any concurrent session must acquire this same
     * lock before it can reserve, and will already see this reservation via
     * count_rounds_played() the moment it does.
     *
     * The guest account is exempt from the lock, the reservation and the PlayerHUD cost:
     * a course's guest-access visitors all share the single guest user record, so
     * nothing here could be safely attributed to one specific person. Guests play a free
     * demo that leaves no {playercross_attempts} row behind — see finish_round() for the
     * matching exemption on the completion side.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $userid User id.
     * @return array [$state, $notification, $notificationtype, $toast]
     */
    public static function start_round(array $state, \stdClass $instance, int $userid): array {
        global $DB;

        if (isguestuser()) {
            $state['starttime'] = time();
            $state['roundstarted'] = true;
            return [$state, null, null, true];
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_playercross');
        $lock = $lockfactory->get_lock('start_round_' . $instance->id . '_' . $userid, 5);
        if (!$lock) {
            // Could not serialise against a concurrent session within the timeout —
            // refuse to start rather than risk two sessions reserving past the limit.
            return [$state, get_string('roundstartbusy', 'mod_playercross'), 'warning', true];
        }

        try {
            $restrictionnotice = self::get_round_restriction_notice($instance, $userid);
            if ($restrictionnotice !== null) {
                return [$state, $restrictionnotice, 'warning', true];
            }

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
                    return [
                        $state,
                        get_string('hud_insufficient_round', 'mod_playercross', $itemname),
                        'warning',
                        true,
                    ];
                }
            }

            $state['starttime'] = time();
            $state['roundstarted'] = true;

            if (empty($state['attemptid'])) {
                $state['attemptid'] = $DB->insert_record('playercross_attempts', (object)[
                    'playercrossid' => $instance->id,
                    'userid'        => $userid,
                    'themewordid'   => (int)$state['themewordid'],
                    'termstotal'    => (int)$state['termstotal'],
                    'termsresolved' => 0,
                    'finalguessed'  => 0,
                    'attempts_used' => 0,
                    'time_used'     => 0,
                    'completed'     => 0,
                    'score'         => 0,
                    'rankingpoints' => 0,
                    'timecreated'   => time(),
                    'timefinished'  => 0,
                ]);
            }
        } finally {
            $lock->release();
        }

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
     * Finds a term's array index by its word id.
     *
     * @param array $state Current state.
     * @param int $termid Term word id.
     * @return int|null
     */
    private static function find_term_index(array $state, int $termid): ?int {
        foreach ($state['terms'] as $index => $term) {
            if ((int)$term['wordid'] === $termid) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Marks any still-active term as resolved once every one of its own slots is
     * already in revealedslots — reached, for instance, when a term's word happens to
     * be made entirely of letters shared with the mystery phrase, and a correct final
     * guess reveals every phrase slot at once (see submit_final_guess()). Without this,
     * such a term would sit with every tile locked-and-revealed but no editable box
     * left for the player to ever submit its own guess through — resolved stays false
     * and the round can never finish under PLAYERCROSS_WINCONDITION_BOTH.
     *
     * Treated as solved on the first attempt since none was actually made — the player
     * already demonstrated full knowledge of every one of its letters. Terms carry no
     * per-term point value of their own (see gameplay_service), so there is nothing to
     * award here beyond marking the term resolved.
     *
     * @param array $state Current state.
     * @return array Updated state.
     */
    private static function resolve_fully_revealed_terms(array $state): array {
        foreach ($state['terms'] as $index => $term) {
            if ($term['resolved'] || $term['exhausted']) {
                continue;
            }
            if (array_diff($term['slots'], $state['revealedslots']) !== []) {
                continue;
            }

            $state['terms'][$index]['resolved'] = true;
            $state['termsresolved']++;
        }
        return $state;
    }

    /**
     * Marks the mystery phrase itself as correctly guessed once every one of its own
     * slots is already revealed — the same situation resolve_fully_revealed_terms()
     * handles for a single term, but for the phrase's own guess form: reached when
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
     * Runs after every operation that adds to revealedslots — a term resolved
     * (submit_term_guess()), a hint revealed (reveal_hint()), or the phrase itself
     * confirmed (submit_final_guess()) — regardless of which of those three actually
     * triggered it. Any one of them can, via shared slots, incidentally complete a
     * different term or the phrase itself: resolving three ordinary terms can reveal
     * every mystery-phrase slot as a side effect just as easily as a hint or a direct
     * final guess can. Centralizing the consequences here — auto-resolving whatever
     * term is now fully revealed, confirming the phrase if it is too, and finishing the
     * round if that satisfies the win condition — means no entry point that touches
     * revealedslots can be the one left without this safeguard; retrofitting it three
     * separate times, once per call site, is exactly how it went missing from
     * submit_term_guess() the first time around.
     *
     * The round can finish here regardless of which of the three callers triggered it,
     * but the "solved directly" feedback message must only fire when the phrase was
     * actually typed and submitted correctly through submit_final_guess() — never when
     * the same slots simply ended up revealed as a side effect of resolving terms or
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
        $state = self::resolve_fully_revealed_terms($state);
        $state = self::confirm_fully_revealed_theme($state);

        $wincondition = (int)($instance->win_condition ?? PLAYERCROSS_WINCONDITION_BOTH);
        $readytowin = !empty($state['finalguesscorrect'])
            && ($wincondition === PLAYERCROSS_WINCONDITION_FINALONLY || $state['termsresolved'] >= $state['termstotal']);

        if (!$readytowin) {
            return [$state, null];
        }

        // Earlybonuseligible was captured by submit_final_guess() at the moment of the
        // direct guess itself, not re-derived here: under PLAYERCROSS_WINCONDITION_BOTH,
        // winning always requires every term resolved first, so termsresolved would never
        // read 0 at this later point even for a genuinely early guess.
        $earlybonuseligible = !empty($state['finalguesseddirectly']) && !empty($state['earlybonuseligible']);

        $state = self::finish_round(
            $state,
            $instance,
            $cmid,
            $userid,
            true,
            false,
            false,
            !empty($state['finalguesseddirectly']),
            false,
            false,
            $earlybonuseligible
        );

        return [$state, get_string('roundwon', 'mod_playercross')];
    }

    /**
     * Reveals one still-hidden letter anywhere in the round, optionally consuming a
     * PlayerHUD item cost. A global action, not scoped to any single term or to the
     * mystery phrase specifically: the revealed slot lights up in the mystery phrase
     * and in every pending term that shares it, exactly like solving a term would —
     * this reuses the same revealedslots set submit_term_guess() already writes to, so
     * no separate per-term "hint revealed" state is needed.
     *
     * Candidates are every slot in the round-wide slot map (state['slotcount']), which
     * also numbers letters exclusive to a term that never appear in the mystery phrase
     * at all (SCOPE.md §20.2 v1.7) — a hint can therefore reveal a letter inside a
     * single term's own word without touching the mystery phrase's own tile row. This
     * keeps the action useful even after the whole mystery phrase is already revealed,
     * as long as any term still has a hidden letter of its own.
     *
     * Also closes a soft-lock possible once every hidden slot in the round has been
     * hinted away: with no term and no phrase tile left unrevealed, neither the
     * phrase's own guess form nor the last term's has any editable box left, so the
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

        // Requires roundstarted: see submit_term_guess() for why — the hint itself also
        // has its own configurable PlayerHUD cost, but start_round() is where the player
        // commits to the round, and a hint pulled before that point would still be a way
        // to play part of the round for free.
        if (empty($state['roundstarted'])) {
            return [$state, get_string('roundnotstarted', 'mod_playercross'), 'warning', true];
        }

        // See submit_term_guess() for why this is re-checked here too, not just in
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

        // See reconcile_after_reveal(): this hint can, via shared slots, leave a term or
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
     * Validates and applies one guess for a specific term.
     *
     * On a correct guess, every theme slot the term's word covers is revealed — in the
     * mystery phrase and, implicitly, in every other pending term that shares one of
     * those slots too, since revealedslots is a single set shared by the whole puzzle.
     *
     * Under PLAYERCROSS_WINCONDITION_BOTH (the default), resolving the last pending
     * term only finishes the round if the mystery phrase is already known correct —
     * either a direct guess through its own form (submit_final_guess()) or, via
     * reconcile_after_reveal(), every one of its slots happening to be revealed
     * already as a side effect of the terms resolved so far. Under
     * PLAYERCROSS_WINCONDITION_FINALONLY, resolving every term never finishes the round
     * by itself unless that same side effect reveals the whole phrase too.
     *
     * A term running out of attempts under PLAYERCROSS_WINCONDITION_BOTH makes winning
     * this round mathematically impossible from that moment on — termsresolved can
     * never reach termstotal again — so the round ends immediately as a loss instead of
     * being left open with no way forward.
     *
     * @param array $state Current state.
     * @param \stdClass $instance Activity instance.
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @param int $termid Term word id.
     * @param string $guess Raw guess text.
     * @return array [$state, $resolved, $notification, $notificationtype, $toast]
     */
    public static function submit_term_guess(
        array $state,
        \stdClass $instance,
        int $cmid,
        int $userid,
        int $termid,
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

        $index = self::find_term_index($state, $termid);
        if ($index === null) {
            return [$state, false, get_string('termnotavailable', 'mod_playercross'), 'warning', true];
        }

        $term = $state['terms'][$index];
        if ($term['resolved'] || $term['exhausted']) {
            return [$state, false, get_string('termnotavailable', 'mod_playercross'), 'warning', true];
        }

        $normalizedguess = word_normalizer::normalize($guess);
        if (!word_normalizer::is_valid_charset($normalizedguess)) {
            return [$state, false, get_string('error_invalidchars', 'mod_playercross'), 'warning', true];
        }

        $state['attemptsused']++;
        $state['terms'][$index]['attemptsused']++;

        if ($normalizedguess !== $term['word']) {
            $state['errorsused']++;

            $maxattempts = (int)$instance->max_attempts_per_term;
            if ($maxattempts > 0 && $state['terms'][$index]['attemptsused'] >= $maxattempts) {
                $state['terms'][$index]['exhausted'] = true;

                $wincondition = (int)($instance->win_condition ?? PLAYERCROSS_WINCONDITION_BOTH);
                if ($wincondition === PLAYERCROSS_WINCONDITION_BOTH) {
                    $state = self::finish_round($state, $instance, $cmid, $userid, false, false, false, false, true);
                    return [$state, false, get_string('feedback_termsexhausted', 'mod_playercross'), 'warning', true];
                }

                return [$state, false, get_string('termexhausted', 'mod_playercross'), 'warning', true];
            }
            return [$state, false, get_string('termguesswrong', 'mod_playercross'), 'warning', true];
        }

        $state['terms'][$index]['resolved'] = true;
        $state['termsresolved']++;
        $state['revealedslots'] = array_values(array_unique(array_merge($state['revealedslots'], $term['slots'])));

        // See reconcile_after_reveal(): this term's own slots can, via shared letters,
        // also complete a different still-open term or the mystery phrase itself —
        // resolving three ordinary terms can reveal the whole phrase as a side effect
        // just as easily as a hint or a direct final guess can. Finishes the round
        // outright if that satisfies the win condition.
        [$state, $wonmessage] = self::reconcile_after_reveal($state, $instance, $cmid, $userid);
        if ($wonmessage !== null) {
            return [$state, true, $wonmessage, 'success', true];
        }

        if ($state['termsresolved'] >= $state['termstotal']) {
            return [$state, true, get_string('termscompleteneedsfinal', 'mod_playercross'), 'success', true];
        }

        // Every round-flow message is an auto-dismissing toast, milestones included — see
        // the matching note in reveal_hint(): a persistent notification never clears on its
        // own, so a wrong guess and a later "round won" message would otherwise sit stacked
        // on screen together. Toasts fade out and never accumulate that way.
        return [$state, true, get_string('termresolved', 'mod_playercross'), 'success', true];
    }

    /**
     * Validates and applies a direct guess of the mystery phrase, available at any
     * point in the round, even with terms still pending.
     *
     * The guess is normalized the same way the phrase itself was (word_normalizer::
     * normalize_phrase(): split on anything that is not a letter, lowercase and strip
     * accents per word) before comparing word-by-word — tolerant of extra whitespace,
     * casing, accents and stray punctuation, but still requires every word of the
     * phrase, in order.
     *
     * Under PLAYERCROSS_WINCONDITION_BOTH (the default), a correct guess here does not
     * finish the round by itself if terms are still pending — it is recorded
     * (finalguesscorrect), so resolving the last remaining term afterwards finishes the
     * round immediately instead of requiring the same phrase to be guessed twice. Under
     * PLAYERCROSS_WINCONDITION_FINALONLY, a correct guess always finishes the round on
     * the spot, however many terms are still pending.
     *
     * A wrong guess counts against max_attempts_final_guess. Running out always ends
     * the round as a loss — unlike a term running out under PLAYERCROSS_WINCONDITION_BOTH,
     * this is unconditional on win_condition, since the phrase is required to win under
     * both modes (BOTH needs it directly, FINALONLY needs it exclusively).
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

        // Requires roundstarted: see submit_term_guess() for why.
        if (empty($state['roundstarted'])) {
            return [$state, false, get_string('roundnotstarted', 'mod_playercross'), 'warning', true];
        }

        // See submit_term_guess() for why this is re-checked here too, not just in
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
        $state['finalguessattemptsused']++;

        if ($guesswords !== $state['themewords']) {
            $state['errorsused']++;

            $maxattemptsfinal = (int)$instance->max_attempts_final_guess;
            if ($maxattemptsfinal > 0 && $state['finalguessattemptsused'] >= $maxattemptsfinal) {
                $state = self::finish_round($state, $instance, $cmid, $userid, false, false, false, false, false, true);
                return [$state, false, get_string('feedback_finalguessexhausted', 'mod_playercross'), 'warning', true];
            }

            return [$state, false, get_string('finalguesswrong', 'mod_playercross'), 'warning', true];
        }

        $state['finalguesscorrect'] = true;
        $state['finalguesseddirectly'] = true;

        // Captured here, at the moment of the direct guess itself — not later, when the
        // round actually finishes (reconcile_after_reveal()/finish_round()). Under
        // PLAYERCROSS_WINCONDITION_BOTH, winning always requires every term resolved
        // first, so termsresolved would never read 0 at that later point even for a
        // genuinely early guess; capturing it now, before any further term can be
        // resolved, is the only correct place to read "zero terms resolved yet".
        $state['earlybonuseligible'] = ((int)$state['termsresolved'] === 0);

        // Reveals the mystery phrase's own tiles immediately, independently of whether the
        // round finishes here — a correct guess demonstrates the player already knows every
        // letter, so leaving the tiles blank until every term is also solved (WINCONDITION_BOTH)
        // would contradict the positive feedback they just received. themeslots (the phrase's
        // own slot numbers) is used here rather than the round-wide slotcount range, so this
        // never reveals a slot exclusive to a still-unsolved term's own word — same distinction
        // reveal_hint() draws (see its docblock).
        $state['revealedslots'] = array_values(array_unique(array_merge($state['revealedslots'], $state['themeslots'])));

        // See reconcile_after_reveal(): finalguesscorrect is already true above, so this
        // resolves any term left fully revealed as a side effect and finishes the round
        // outright once the win condition is met (immediately under
        // PLAYERCROSS_WINCONDITION_FINALONLY, or once every term is resolved too under
        // PLAYERCROSS_WINCONDITION_BOTH).
        [$state, $wonmessage] = self::reconcile_after_reveal($state, $instance, $cmid, $userid);
        if ($wonmessage !== null) {
            return [$state, true, $wonmessage, 'success', true];
        }

        return [$state, true, get_string('finalguesscorrectneedsterms', 'mod_playercross'), 'success', true];
    }

    /**
     * Handles a forfeit: ends the round without resolving any remaining term.
     *
     * Requires roundstarted: see submit_term_guess() for why — a puzzle armed at
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
     * top of submit_term_guess(), submit_final_guess() and reveal_hint(), which is the
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
     * The single place all finish paths (all terms resolved, direct final guess,
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
     * @param bool $termsexhausted Whether the round ended because a term ran out of
     *     attempts under PLAYERCROSS_WINCONDITION_BOTH, making a win impossible. Only
     *     ever used to pick the right feedback message — not persisted to the attempts
     *     table, which already records the loss via $won.
     * @param bool $finalguessexhausted Whether the round ended because the mystery
     *     phrase ran out of attempts. Unlike $termsexhausted this always forces a loss,
     *     regardless of win_condition — only used to pick the right feedback message.
     * @param bool $earlybonuseligible Whether the phrase was guessed directly before
     *     resolving any term — feeds the early-guess bonus in gameplay_service.
     *
     * The guest account never reaches the persistence block below: no attempt row, no
     * round_completed event, no grade update, no PlayerHUD grant, no completion update.
     * $state still gets 'finished'/'won'/etc. set above the cut so the UI can show the
     * round's outcome — see start_round() for why guests are exempt in the first place.
     * The score/rankingpoints computation happens before that cut too, so a guest still
     * sees a correct score in the UI even though nothing is persisted.
     *
     * Unlike start_round(), no lock is needed here: start_round() already reserved this
     * round's row (via $state['attemptid']) inside its own lock, so no other session can
     * be racing to write to that specific row — it is only ever known to this one
     * session's own state. This just updates it by id.
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
        bool $termsexhausted = false,
        bool $finalguessexhausted = false,
        bool $earlybonuseligible = false
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playercross/lib.php');

        $state['finished']     = true;
        $state['endtime']      = time();
        $state['won']          = $won;
        $state['forfeited']    = $forfeited;
        $state['termsexhausted'] = $termsexhausted;
        $state['finalguessexhausted'] = $finalguessexhausted;
        $state['timedout']     = $timedout;
        $state['finalguessed'] = $finalguessed;

        $errorsused = (int)$state['errorsused'];
        $state['score'] = round(
            gameplay_service::calculate_round_score($instance, $errorsused, $won, $earlybonuseligible),
            5
        );
        $state['rankingpoints'] = round(
            gameplay_service::calculate_ranking_points($instance, $errorsused, $won, $earlybonuseligible),
            5
        );

        if (isguestuser()) {
            $state['attemptid'] = 0;
            return $state;
        }

        $timeused = max(0, time() - (int)$state['starttime']);

        $data = (object)[
            'themewordid'   => (int)$state['themewordid'],
            'termstotal'    => (int)$state['termstotal'],
            'termsresolved' => (int)$state['termsresolved'],
            'finalguessed'  => $finalguessed ? 1 : 0,
            'attempts_used' => (int)$state['attemptsused'],
            'time_used'     => $timeused,
            'completed'     => $won ? 1 : 0,
            'score'         => $state['score'],
            'rankingpoints' => $state['rankingpoints'],
            'timefinished'  => time(),
        ];

        $attemptid = (int)($state['attemptid'] ?? 0);
        if ($attemptid > 0) {
            $data->id = $attemptid;
            $DB->update_record('playercross_attempts', $data);
        } else {
            // No reservation in session state — only reachable for a round that was
            // already mid-play, in a session predating this reservation mechanism, at
            // the moment the plugin was upgraded. Insert fresh, exactly as start_round()
            // would have, so an in-flight session still finishes correctly.
            $data->playercrossid = $instance->id;
            $data->userid = $userid;
            $data->timecreated = time();
            $attemptid = $DB->insert_record('playercross_attempts', $data);
        }
        $state['attemptid'] = 0;

        $event = \mod_playercross\event\round_completed::create([
            'objectid' => $attemptid,
            'context'  => \context_module::instance($cmid),
            'other'    => [
                'completed'     => $won,
                'finalguessed'  => $finalguessed,
                'score'         => $state['score'],
                'termsresolved' => (int)$state['termsresolved'],
                'termstotal'    => (int)$state['termstotal'],
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

        return $state;
    }
}
