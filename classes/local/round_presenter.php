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
 * Round presenter service.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use core_text;

/**
 * Builds round-related template context fragments, shared by the full-page render
 * and by the AJAX partial responses.
 *
 * The security invariant every method here must uphold (SCOPE.md §7): the mystery
 * phrase and any unresolved clue's word text are never included in the returned
 * context unless the round has actually finished server-side.
 */
class round_presenter {
    /**
     * Builds the mystery-phrase tile data, grouped by word: one entry per word of the
     * phrase (the theme concept's own hint, see puzzle_builder), each holding that
     * word's own per-position tile row — so the template can render a visual gap
     * between words instead of one continuous, spaceless run of letters. Hidden tiles
     * carry their slot number, so the student can see which clue covers which position
     * before it is revealed.
     *
     * @param array $state Session state.
     * @param bool $roundfinished Whether the current round is finished.
     * @return array Array of {tiles: array} word groups.
     */
    public static function build_phrase_tiles(array $state, bool $roundfinished): array {
        $groups = [];
        $position = 0;
        foreach ($state['themewords'] as $word) {
            $wordlength = count(word_normalizer::chars($word));
            $wordslots = array_slice($state['themeslots'], $position, $wordlength);
            $groups[] = ['tiles' => self::build_word_tiles($word, $wordslots, $state['revealedslots'], $roundfinished)];
            $position += $wordlength;
        }
        return $groups;
    }

    /**
     * Builds one word's per-letter tile row from its own per-position slot array
     * (already computed round-wide by puzzle_builder — see SCOPE.md §20.2 v1.7): every
     * letter always has a slot number, so a solved clue can cross-reveal a shared
     * letter directly in another clue, not only through the mystery phrase.
     *
     * @param string $word A word, already normalized (the theme word or a clue's own word).
     * @param int[] $slots Per-position slot numbers, parallel to $word's characters.
     * @param array $revealedslots Currently revealed slot numbers.
     * @param bool $reveal Whether the whole word is already known (resolved, or round finished).
     * @return array
     */
    private static function build_word_tiles(string $word, array $slots, array $revealedslots, bool $reveal): array {
        $tiles = [];
        foreach (word_normalizer::chars($word) as $position => $char) {
            $slot = $slots[$position];
            $isrevealed = $reveal || in_array($slot, $revealedslots, true);
            $letter = $isrevealed ? core_text::strtoupper($char) : '';

            $tiles[] = [
                'letter'    => s($letter),
                'revealed'  => $isrevealed,
                'slotnum'   => $isrevealed ? '' : (string)$slot,
                'arialabel' => $isrevealed
                    ? get_string('tile_state_revealed', 'mod_playercross', $letter)
                    : get_string('tile_state_hidden_numbered', 'mod_playercross', $slot),
            ];
        }
        return $tiles;
    }

    /**
     * Builds the per-clue view rows. The clue's own phrase (hint) is always included —
     * it is the question itself, not an optional reveal — while the answer is only
     * shown letter-by-letter through build_word_tiles(), or in full once resolved or
     * the round has finished.
     *
     * @param array $state Session state.
     * @param bool $roundfinished Whether the current round is finished.
     * @return array
     */
    public static function build_clue_rows(array $state, bool $roundfinished): array {
        $rows = [];
        foreach ($state['clues'] as $clue) {
            $reveal = $roundfinished || $clue['resolved'];

            $rows[] = [
                'clueid'       => (int)$clue['wordid'],
                'phrase'       => s($clue['hint']),
                'resolved'     => $clue['resolved'],
                'exhausted'    => $clue['exhausted'],
                'attemptsused' => (int)$clue['attemptsused'],
                'exhaustedlabel' => $clue['exhausted']
                    ? get_string('clueexhaustedlabel', 'mod_playercross', (int)$clue['attemptsused'])
                    : '',
                'revealword'   => $reveal ? s(core_text::strtoupper($clue['originalword'])) : '',
                'tiles'        => self::build_word_tiles($clue['word'], $clue['slots'], $state['revealedslots'], $reveal),
                'canguess'     => !$clue['resolved'] && !$clue['exhausted'] && !$roundfinished,
            ];
        }

        return $rows;
    }

    /**
     * Returns a formatted countdown string, or empty if no cooldown is active.
     *
     * @param int $cooldownuntil Epoch when the cooldown ends, 0 if inactive.
     * @return string
     */
    public static function build_cooldown_text(int $cooldownuntil): string {
        if ($cooldownuntil <= 0) {
            return '';
        }
        $remaining = $cooldownuntil - time();
        return $remaining > 0 ? format_time($remaining) : '';
    }

    /**
     * Returns the end-of-round flavour message.
     *
     * @param array $state Session state.
     * @return string
     */
    public static function build_feedback_message(array $state): string {
        if (empty($state['finished'])) {
            return '';
        }
        if (!empty($state['forfeited'])) {
            return get_string('feedback_forfeited', 'mod_playercross');
        }
        if (!empty($state['timedout'])) {
            return get_string('feedback_timeout', 'mod_playercross');
        }
        if (!empty($state['cluesexhausted'])) {
            return get_string('feedback_cluesexhausted', 'mod_playercross');
        }
        if (!empty($state['finalguessed'])) {
            return get_string('feedback_finalguessed', 'mod_playercross');
        }
        if (!empty($state['won'])) {
            return get_string('feedback_won', 'mod_playercross');
        }
        return get_string('feedback_lost', 'mod_playercross');
    }

    /**
     * Whether the grading method is meaningful to surface to the student: grading must
     * be enabled with a numeric point scale, and more than one round must be possible.
     *
     * @param \stdClass $instance Activity instance.
     * @return bool
     */
    private static function grading_info_relevant(\stdClass $instance): bool {
        return (float)$instance->grade > 0 && (int)$instance->max_rounds !== 1;
    }

    /**
     * Resolves the localized name of the instance's configured grading method.
     *
     * @param \stdClass $instance Activity instance.
     * @return string
     */
    public static function grademethod_name(\stdClass $instance): string {
        global $CFG;
        require_once($CFG->dirroot . '/mod/playercross/lib.php');

        $options = playercross_get_grademethod_options();
        return $options[(int)$instance->grademethod] ?? $options[PLAYERCROSS_GRADE_HIGHEST];
    }

    /**
     * Builds the grading-method explanation shown in the lobby before a round starts.
     *
     * @param \stdClass $instance Activity instance.
     * @return array
     */
    public static function build_grading_method_info(\stdClass $instance): array {
        if (!self::grading_info_relevant($instance)) {
            return ['showgradingmethodinfo' => false, 'gradingmethodinfo' => ''];
        }

        return [
            'showgradingmethodinfo' => true,
            'gradingmethodinfo' => get_string(
                'gradingmethodinfo',
                'mod_playercross',
                self::grademethod_name($instance)
            ),
        ];
    }

    /**
     * Builds the "grade so far" summary shown after a round finishes, read straight
     * from the gradebook item so it always matches what the teacher sees.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $userid Current user id.
     * @return array
     */
    public static function build_grade_so_far(\stdClass $instance, int $userid): array {
        $blank = ['showgradesofar' => false, 'gradesofarmessage' => ''];

        if (!self::grading_info_relevant($instance)) {
            return $blank;
        }

        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $gradeitem = \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'playercross',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
            'courseid'     => $instance->course,
        ]);
        if (!$gradeitem) {
            return $blank;
        }

        $grade = $gradeitem->get_grade($userid, false);
        if ($grade === null || $grade->finalgrade === null) {
            return $blank;
        }

        $a = (object)[
            'method'   => self::grademethod_name($instance),
            'mygrade'  => format_float((float)$grade->finalgrade, 2),
            'maxgrade' => format_float((float)$instance->grade, 2),
        ];

        return [
            'showgradesofar' => true,
            'gradesofarmessage' => get_string('gradesofar', 'mod_playercross', $a),
        ];
    }

    /**
     * Builds the "you received X× item" text shown after a won round, when the
     * activity has a win-reward item configured. Blank when there is nothing to
     * announce.
     *
     * @param \stdClass $instance Activity instance record.
     * @param array $state Session state.
     * @return string
     */
    private static function build_hud_reward_label(\stdClass $instance, array $state): string {
        $rewarditem = (int)($instance->hud_win_reward_item ?? 0);
        if ($rewarditem <= 0 || empty($state['won'])) {
            return '';
        }

        $itemname = hud_service::get_item_name(hud_service::resolve_block_instance_id($instance), $rewarditem);
        if ($itemname === '') {
            return '';
        }

        return get_string('hud_rewardedlabel', 'mod_playercross', (object)[
            'qty'  => max(1, (int)($instance->hud_win_reward_qty ?? 1)),
            'item' => $itemname,
        ]);
    }

    /**
     * Builds the "X of Y× item" balance/cost text for a PlayerHUD-gated action, and
     * whether the user currently has enough to afford it.
     *
     * @param int $blockinstanceid Block instance ID the item must belong to.
     * @param int $itemid PlayerHUD item id, 0 disables the check.
     * @param int $requiredqty Quantity required by the activity's configuration.
     * @param int $userid Current user id.
     * @return array {applies: bool, label: string, canafford: bool}
     */
    private static function build_hud_cost_info(int $blockinstanceid, int $itemid, int $requiredqty, int $userid): array {
        $blank = ['applies' => false, 'label' => '', 'canafford' => true];

        if ($itemid <= 0) {
            return $blank;
        }

        $itemname = hud_service::get_item_name($blockinstanceid, $itemid);
        if ($itemname === '') {
            return $blank;
        }

        $requiredqty = max(1, $requiredqty);
        $availableqty = hud_service::get_available_quantity($blockinstanceid, $userid, $itemid);

        $label = get_string('hud_balancecost', 'mod_playercross', (object)[
            'available' => $availableqty,
            'required'  => $requiredqty,
            'item'      => $itemname,
        ]);

        return [
            'applies' => true,
            'label' => $label,
            'canafford' => ($availableqty >= $requiredqty),
        ];
    }

    /**
     * Builds the "rounds played" counter text (e.g. "3 / 10" or "3 / ∞").
     *
     * @param \stdClass $instance Activity instance record.
     * @param int $userid Current user id.
     * @return string
     */
    private static function build_rounds_played_label(\stdClass $instance, int $userid): string {
        $maxrounds = (int)$instance->max_rounds;
        $maxlabel = $maxrounds > 0 ? (string)$maxrounds : "\u{221E}";

        return get_string('roundsplayed', 'mod_playercross', (object)[
            'played' => round_service::count_rounds_played($instance, $userid),
            'max'    => $maxlabel,
        ]);
    }

    /**
     * Builds the pre-round lobby context.
     *
     * @param \stdClass $instance Activity instance record.
     * @param array $state Session state.
     * @param int $userid Current user id.
     * @return array
     */
    public static function build_lobby_context(\stdClass $instance, array $state, int $userid): array {
        $hudstartcost = false;
        $hudstartcostlabel = '';
        $canstart = true;
        $roundcostitem = (int)($instance->hud_round_cost_item ?? 0);
        if ($roundcostitem > 0) {
            $info = self::build_hud_cost_info(
                hud_service::resolve_block_instance_id($instance),
                $roundcostitem,
                (int)($instance->hud_round_cost_qty ?? 1),
                $userid
            );
            $hudstartcost = $info['applies'];
            $hudstartcostlabel = $info['label'];
            $canstart = $info['canafford'];
        }

        return [
            'cluesthisround' => get_string('cluesthisround', 'mod_playercross', (int)$state['cluestotal']),
            'timerenabled' => ((int)$instance->timer_seconds > 0),
            'lobbytimerinfo' => (
                (int)$instance->timer_seconds > 0
                    ? get_string('lobby_timerinfo', 'mod_playercross', format_time((int)$instance->timer_seconds))
                    : ''
            ),
            'hudstartcost' => $hudstartcost,
            'hudstartcostlabel' => $hudstartcostlabel,
            'canstart' => $canstart,
            'startlabel' => get_string('startround', 'mod_playercross'),
            'roundsplayedlabel' => self::build_rounds_played_label($instance, $userid),
        ] + self::build_grading_method_info($instance);
    }

    /**
     * Builds the active-round panel context: theme tiles, clue rows, final-guess form
     * data, timer, and whichever result context applies.
     *
     * @param \stdClass $instance Activity instance record.
     * @param \stdClass $cm Course module record.
     * @param array $state Session state.
     * @param int $userid Current user id.
     * @return array
     */
    public static function build_round_panel_context(
        \stdClass $instance,
        \stdClass $cm,
        array $state,
        int $userid
    ): array {
        $roundfinished = !empty($state['finished']);

        $timeleft = 0;
        if (
            !$roundfinished
            && (int)$instance->timer_seconds > 0
            && !empty($state['roundstarted'])
            && !empty($state['starttime'])
        ) {
            $timeleft = max(0, (int)$instance->timer_seconds - (time() - (int)$state['starttime']));
        }

        return [
            'themetiles' => self::build_phrase_tiles($state, $roundfinished),
            'themelabel' => get_string('themewordlabel', 'mod_playercross'),
            'themeconceptlabel' => get_string('themeconceptlabel', 'mod_playercross'),
            'themeconcept' => s($state['themeconcept']),
            'clues' => self::build_clue_rows($state, $roundfinished),
            'cluesresolved' => (int)$state['cluesresolved'],
            'cluestotal' => (int)$state['cluestotal'],
            'cluesprogresslabel' => get_string('cluesprogress', 'mod_playercross', (object)[
                'resolved' => (int)$state['cluesresolved'],
                'total'    => (int)$state['cluestotal'],
            ]),
            'timerenabled' => ((int)$instance->timer_seconds > 0),
            'timerlabel' => get_string('timerlabel', 'mod_playercross'),
            'timeleft' => $timeleft,
            'roundfinished' => $roundfinished,
            'guesslabel' => get_string('guesslabel', 'mod_playercross'),
            'submitclueguess' => get_string('submitclueguess', 'mod_playercross'),
            'canfinalguess' => !$roundfinished,
            'submitfinalguess' => get_string('submitfinalguess', 'mod_playercross'),
            'forfeitlabel' => get_string('forfeitbutton', 'mod_playercross'),
            'forfeitconfirm' => get_string('forfeitconfirm', 'mod_playercross'),
            'keyboardlabel' => get_string('keyboard_label', 'mod_playercross'),
            'keyboardenterlabel' => get_string('keyboard_enter', 'mod_playercross'),
            'keyboardentertext' => get_string('keyboard_enter_text', 'mod_playercross'),
            'keyboardbackspacelabel' => get_string('keyboard_backspace', 'mod_playercross'),
            'showcedilla' => words_repository::has_cedilla_word((int)$instance->id),
        ] + self::build_global_hint_context($instance, $state, $userid, $roundfinished)
          + self::build_round_result_context($instance, $cm, $state, $userid, $roundfinished);
    }

    /**
     * Builds the single, round-wide "reveal a letter" hint action — replaces the old
     * per-clue hint reveal (SCOPE.md §20.2 v1.5): since revealing a letter here writes
     * to the same revealedslots set a solved clue would, there is nothing left to hint
     * once every slot in the round is already revealed. Not restricted to the mystery
     * phrase's own slots — a letter exclusive to a clue is a valid candidate too, so
     * the action stays available even once the whole mystery phrase is revealed, as
     * long as some clue still has a hidden letter of its own (SCOPE.md §20.2 v1.8).
     *
     * @param \stdClass $instance Activity instance record.
     * @param array $state Session state.
     * @param int $userid Current user id.
     * @param bool $roundfinished Whether the current round is finished.
     * @return array
     */
    private static function build_global_hint_context(
        \stdClass $instance,
        array $state,
        int $userid,
        bool $roundfinished
    ): array {
        $hiddenslots = array_diff(range(1, (int)$state['slotcount']), $state['revealedslots']);

        $blank = [
            'showglobalhint'   => false,
            'globalhintlabel'  => get_string('hintbuttonlabel', 'mod_playercross'),
            'hudhintcost'      => false,
            'hudhintcostlabel' => '',
            'canaffordhint'    => true,
        ];

        if ($roundfinished || empty($hiddenslots)) {
            return $blank;
        }

        $blank['showglobalhint'] = true;

        $hintcostitem = (int)($instance->hud_hint_cost_item ?? 0);
        if ($hintcostitem > 0) {
            $info = self::build_hud_cost_info(
                hud_service::resolve_block_instance_id($instance),
                $hintcostitem,
                (int)($instance->hud_hint_cost_qty ?? 1),
                $userid
            );
            $blank['hudhintcost'] = $info['applies'];
            $blank['hudhintcostlabel'] = $info['label'];
            $blank['canaffordhint'] = $info['canafford'];
        }

        return $blank;
    }

    /**
     * Builds the post-round result context: reveal, cooldown and grade-so-far.
     *
     * When $roundfinished is false, every reveal-related field is structurally blank —
     * this is the security boundary AJAX callers rely on: the mystery phrase is never
     * populated in the returned array until the round has actually finished server-side.
     *
     * The per-clue recap shown alongside this (each clue's own hint and answer word,
     * templates/round_result.mustache) reuses the 'clues' key build_round_panel_context()
     * already sets from build_clue_rows($state, $roundfinished) — with $roundfinished
     * true, every clue's revealword is populated there regardless of whether the
     * student actually resolved it, so this method does not need to build its own copy.
     *
     * @param \stdClass $instance Activity instance record.
     * @param \stdClass $cm Course module record.
     * @param array $state Session state.
     * @param int $userid Current user id.
     * @param bool $roundfinished Whether the current round is finished.
     * @return array
     */
    public static function build_round_result_context(
        \stdClass $instance,
        \stdClass $cm,
        array $state,
        int $userid,
        bool $roundfinished
    ): array {
        $blank = [
            'feedbackmessage'     => '',
            'revealthemeword'     => '',
            'revealthemewordlabel' => get_string('revealthemewordlabel', 'mod_playercross'),
            'resultclueslabel'    => get_string('resultclueslabel', 'mod_playercross'),
            'scoreachieved'       => '',
            'scoreachievedlabel'  => get_string('scoreachievedlabel', 'mod_playercross'),
            'cooldownuntil'       => 0,
            'cooldowntext'        => '',
            'cooldowncountdownlabel' => get_string('cooldowncountdownlabel', 'mod_playercross'),
            'cooldownactive'      => false,
            'newroundlabel'       => get_string('newroundlabel', 'mod_playercross'),
            'showgradesofar'      => false,
            'gradesofarmessage'   => '',
            'roundsplayedlabel'   => '',
            'huditemrewardedlabel' => '',
        ];

        if (!$roundfinished) {
            return $blank;
        }

        $cooldownuntil = round_service::compute_cooldown_until($instance, $userid);
        $restricted = round_service::get_round_restriction_notice($instance, $userid) !== null;

        return [
            'feedbackmessage'      => self::build_feedback_message($state),
            'revealthemeword'      => s(core_text::strtoupper($state['themehint'])),
            'revealthemewordlabel' => $blank['revealthemewordlabel'],
            'resultclueslabel'     => $blank['resultclueslabel'],
            'scoreachieved'        => format_float((float)$state['scoreaccumulated'], 2),
            'scoreachievedlabel'   => $blank['scoreachievedlabel'],
            'cooldownuntil'        => $cooldownuntil,
            'cooldowntext'         => self::build_cooldown_text($cooldownuntil),
            'cooldowncountdownlabel' => $blank['cooldowncountdownlabel'],
            'cooldownactive'       => $restricted,
            'newroundlabel'        => $blank['newroundlabel'],
            'roundsplayedlabel'    => self::build_rounds_played_label($instance, $userid),
            'huditemrewardedlabel' => self::build_hud_reward_label($instance, $state),
        ] + self::build_grade_so_far($instance, $userid);
    }
}
