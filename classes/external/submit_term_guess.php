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
 * External function: submit a guess for one term.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playercross\local\round_presenter;
use mod_playercross\local\round_service;

/**
 * Validates a term guess and returns the fully updated round panel.
 *
 * The whole panel is re-rendered server-side after every guess (not just the affected
 * term) because a single correct guess can reveal shared letters across every other
 * pending term and the mystery phrase at once — there is no meaningful "just patch one
 * row" shape for this mechanic, unlike a single Wordle-style grid.
 */
class submit_term_guess extends external_api {
    /**
     * Returns parameter definitions for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'Course module id'),
            'termid' => new external_value(PARAM_INT, 'Term word id'),
            'guess'  => new external_value(PARAM_TEXT, 'Player guess text'),
        ]);
    }

    /**
     * Validates a term guess and returns the updated round panel.
     *
     * @param int $cmid Course module id.
     * @param int $termid Term word id.
     * @param string $guess Player guess.
     * @return array
     */
    public static function execute(int $cmid, int $termid, string $guess): array {
        global $DB, $USER;

        [
            'cmid'   => $cmid,
            'termid' => $termid,
            'guess'  => $guess,
        ] = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'termid' => $termid, 'guess' => $guess]
        );

        $cm = get_coursemodule_from_id('playercross', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playercross:view', $context);

        $instance = $DB->get_record('playercross', ['id' => $cm->instance], '*', MUST_EXIST);
        $userid = (int)$USER->id;

        $state = round_service::load_state($cmid, $userid);
        $state = round_service::ensure_round_state($state, $instance, $cmid, $userid);

        [$state, $resolved, $notification, $notificationtype, $toast] = round_service::submit_term_guess(
            $state,
            $instance,
            $cmid,
            $userid,
            $termid,
            $guess
        );

        round_service::save_state($cmid, $userid, $state);

        return [
            'resolved'         => $resolved,
            'finished'         => !empty($state['finished']),
            'notification'     => $notification ?? '',
            'notificationtype' => $notificationtype ?? '',
            'toast'            => $toast,
            'panel'            => round_presenter::build_round_panel_context($instance, $cm, $state, $userid),
        ];
    }

    /**
     * Returns the structure of the execute() return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'resolved'         => new external_value(PARAM_BOOL, 'Whether this specific term was resolved'),
            'finished'         => new external_value(PARAM_BOOL, 'Whether the round has ended'),
            'notification'     => new external_value(PARAM_TEXT, 'User-facing feedback message', VALUE_DEFAULT, ''),
            'notificationtype' => new external_value(
                PARAM_ALPHA,
                'Notification type: success or warning',
                VALUE_DEFAULT,
                ''
            ),
            'toast' => new external_value(
                PARAM_BOOL,
                'Whether to show the notification as an auto-dismissing toast instead of a persistent one',
                VALUE_DEFAULT,
                false
            ),
            'panel' => self::panel_structure(),
        ]);
    }

    /**
     * Returns the structure shared by every response's round-result fields.
     *
     * Always present, but only meaningful when the response's "finished" field is
     * true — see round_presenter::build_round_result_context() for the security
     * invariant this structure exists to make explicit and testable: the mystery
     * phrase is never populated in the returned array until the round has actually
     * finished server-side.
     *
     * @return external_single_structure
     */
    public static function roundresult_structure(): external_single_structure {
        return new external_single_structure([
            'feedbackmessage'        => new external_value(PARAM_TEXT, 'End-of-round flavour message'),
            'revealthemeword'        => new external_value(PARAM_TEXT, 'The mystery phrase, empty until finished'),
            'revealthemewordlabel'   => new external_value(PARAM_TEXT, 'Label for the revealed mystery phrase'),
            'resulttermslabel'       => new external_value(PARAM_TEXT, 'Heading label for the per-term recap list'),
            'scoreachieved'          => new external_value(PARAM_TEXT, 'Score achieved, formatted to 2 decimals'),
            'scoreachievedlabel'     => new external_value(PARAM_TEXT, 'Label for the achieved score'),
            'cooldownuntil'          => new external_value(PARAM_INT, 'Cooldown expiry epoch, 0 if inactive'),
            'cooldowntext'           => new external_value(PARAM_TEXT, 'Formatted cooldown countdown text'),
            'cooldowncountdownlabel' => new external_value(PARAM_TEXT, 'Label for the cooldown countdown'),
            'cooldownactive'         => new external_value(PARAM_BOOL, 'Whether a cooldown is currently active'),
            'newroundlabel'          => new external_value(PARAM_TEXT, 'Label for the new-round action'),
            'showgradesofar'         => new external_value(PARAM_BOOL, 'Whether the grade-so-far summary is shown'),
            'gradesofarmessage'      => new external_value(
                PARAM_TEXT,
                'Grading method and current computed grade, empty when not applicable'
            ),
            'roundsplayedlabel' => new external_value(
                PARAM_TEXT,
                'Rounds played counter, e.g. "3 / 10", empty until finished'
            ),
            'huditemrewardedlabel' => new external_value(
                PARAM_TEXT,
                'PlayerHUD item rewarded for winning, empty when not applicable'
            ),
        ]);
    }

    /**
     * Returns the structure matching mod_playercross/round_panel, reused by every
     * other mutating endpoint.
     *
     * @return external_single_structure
     */
    public static function panel_structure(): external_single_structure {
        $tilestructure = new external_single_structure([
            'letter'    => new external_value(PARAM_TEXT, 'Uppercase letter, empty when not revealed'),
            'revealed'  => new external_value(PARAM_BOOL, 'Whether this position is revealed'),
            'slotnum'   => new external_value(PARAM_TEXT, 'Mystery-phrase slot number, empty when revealed'),
            'arialabel' => new external_value(PARAM_TEXT, 'Accessible label for this tile'),
        ]);

        $ownfields = [
            'themetiles' => new external_multiple_structure(
                new external_single_structure([
                    'tiles' => new external_multiple_structure(
                        $tilestructure,
                        'One word of the mystery phrase, letter-by-letter'
                    ),
                ]),
                'Mystery phrase tiles, grouped by word'
            ),
            'themelabel' => new external_value(PARAM_TEXT, 'Mystery phrase label'),
            'themeconceptlabel' => new external_value(PARAM_TEXT, 'Theme concept caption label'),
            'themeconcept' => new external_value(PARAM_TEXT, 'Theme concept word, always shown openly'),
            'themesolved' => new external_value(
                PARAM_BOOL,
                'Whether the mystery-phrase tiles/form have collapsed into resolved text'
            ),
            'finalguesscorrect' => new external_value(
                PARAM_BOOL,
                'Whether the mystery phrase has been confirmed correct'
            ),
            'themedisplayword' => new external_value(
                PARAM_TEXT,
                'Mystery phrase text once themesolved, empty otherwise'
            ),
            'terms' => new external_multiple_structure(
                new external_single_structure([
                    'termid'       => new external_value(PARAM_INT, 'Term word id'),
                    'phrase'       => new external_value(PARAM_TEXT, 'Term phrase, always shown'),
                    'resolved'     => new external_value(PARAM_BOOL, 'Whether this term is resolved'),
                    'exhausted'    => new external_value(PARAM_BOOL, 'Whether attempts ran out for this term'),
                    'attemptsused' => new external_value(PARAM_INT, 'Attempts used on this term'),
                    'exhaustedlabel' => new external_value(
                        PARAM_TEXT,
                        'Human-readable "no success after N attempts" label, empty unless exhausted'
                    ),
                    'revealword'   => new external_value(
                        PARAM_TEXT,
                        'This term\'s word, empty unless resolved or the round finished'
                    ),
                    'tiles' => new external_multiple_structure(
                        $tilestructure,
                        'This term\'s own letter-by-letter tile row'
                    ),
                    'canguess' => new external_value(PARAM_BOOL, 'Whether a guess can still be submitted'),
                    'showtermattemptsremaining' => new external_value(
                        PARAM_BOOL,
                        'Whether this term\'s attempts-remaining badge is shown'
                    ),
                    'termattemptsremainingvalue' => new external_value(
                        PARAM_TEXT,
                        'Attempts left for this term, or the infinity glyph when unlimited'
                    ),
                    'termattemptsremaininglabel' => new external_value(
                        PARAM_TEXT,
                        'Accessible label for the term attempts-remaining count'
                    ),
                ]),
                'Term rows'
            ),
            'termsresolved'      => new external_value(PARAM_INT, 'Terms resolved so far'),
            'termstotal'         => new external_value(PARAM_INT, 'Total terms in this round'),
            'termsprogresslabel' => new external_value(PARAM_TEXT, 'Terms resolved / total label'),
            'timerenabled'       => new external_value(PARAM_BOOL, 'Whether the timer is enabled'),
            'timerlabel'         => new external_value(PARAM_TEXT, 'Timer label'),
            'timeleft'           => new external_value(PARAM_INT, 'Seconds remaining, 0 if timer is disabled'),
            'roundfinished'      => new external_value(PARAM_BOOL, 'Whether the round has ended'),
            'guesslabel'         => new external_value(PARAM_TEXT, 'Accessible label for a term answer field'),
            'submittermguess'    => new external_value(PARAM_TEXT, 'Submit term guess button label'),
            'showglobalhint'     => new external_value(PARAM_BOOL, 'Whether the round-wide hint action is available'),
            'globalhintlabel'    => new external_value(PARAM_TEXT, 'Round-wide hint button label'),
            'showhintsremaining' => new external_value(PARAM_BOOL, 'Whether the hint button shows a remaining count'),
            'hintsremainingvalue' => new external_value(
                PARAM_TEXT,
                'Hints left before max_hints_per_round is reached, or the infinity glyph when unlimited'
            ),
            'hintsremaininglabel' => new external_value(
                PARAM_TEXT,
                'Accessible label for the remaining-hints count, empty unless showhintsremaining'
            ),
            'hudhintcost'        => new external_value(PARAM_BOOL, 'Whether revealing costs a PlayerHUD item'),
            'hudhintcostlabel'   => new external_value(PARAM_TEXT, 'PlayerHUD hint cost label'),
            'canaffordhint'      => new external_value(PARAM_BOOL, 'Whether the user can afford the hint'),
            'canfinalguess'      => new external_value(PARAM_BOOL, 'Whether a final guess can still be submitted'),
            'submitfinalguess'   => new external_value(PARAM_TEXT, 'Submit final guess button label'),
            'showfinalguessattemptsremaining' => new external_value(
                PARAM_BOOL,
                'Whether the final-guess attempts-remaining badge is shown'
            ),
            'finalguessattemptsremainingvalue' => new external_value(
                PARAM_TEXT,
                'Attempts left for the mystery phrase, or the infinity glyph when unlimited'
            ),
            'finalguessattemptsremaininglabel' => new external_value(
                PARAM_TEXT,
                'Accessible label for the final-guess attempts-remaining count'
            ),
            'finalguessexhausted' => new external_value(
                PARAM_BOOL,
                'Whether the mystery phrase ran out of attempts'
            ),
            'finalguessexhaustedlabel' => new external_value(
                PARAM_TEXT,
                'Human-readable "no success after N attempts" label, empty unless finalguessexhausted'
            ),
            'keyboardlabel' => new external_value(PARAM_TEXT, 'Virtual keyboard accessible group label'),
            'keyboardbackspacelabel' => new external_value(
                PARAM_TEXT,
                'Virtual keyboard backspace key accessible label'
            ),
            'showcedilla'        => new external_value(PARAM_BOOL, 'Whether the Ç key should be shown'),
            'revealedlettersjson' => new external_value(
                PARAM_RAW,
                'JSON array of round-wide revealed letters, for marking the virtual keyboard'
            ),
            'forfeitlabel'       => new external_value(PARAM_TEXT, 'Forfeit button label'),
            'forfeitconfirm'     => new external_value(PARAM_TEXT, 'Forfeit confirmation message'),
        ];

        return new external_single_structure($ownfields + self::roundresult_structure()->keys);
    }
}
