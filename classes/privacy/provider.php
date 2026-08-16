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
 * Privacy API provider for mod_playercross.
 *
 * Personal data stored:
 *   - playercross_attempts: one record per round per user (userid).
 *   - playercross_words: addedby (userid of the teacher/user who added the word),
 *     plus the word, clue, source and timecreated of that same row. See get_metadata()
 *     for why concept/glossaryid/approved/timemodified are deliberately excluded.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_playercross\local\intro_service;

/**
 * Privacy provider implementation.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'playercross_attempts',
            [
                'userid'        => 'privacy:metadata:playercross_attempts:userid',
                'playercrossid' => 'privacy:metadata:playercross_attempts:playercrossid',
                'themewordid'   => 'privacy:metadata:playercross_attempts:themewordid',
                'termstotal'    => 'privacy:metadata:playercross_attempts:termstotal',
                'termsresolved' => 'privacy:metadata:playercross_attempts:termsresolved',
                'finalguessed'  => 'privacy:metadata:playercross_attempts:finalguessed',
                'attempts_used' => 'privacy:metadata:playercross_attempts:attempts_used',
                'time_used'     => 'privacy:metadata:playercross_attempts:time_used',
                'completed'     => 'privacy:metadata:playercross_attempts:completed',
                'score'         => 'privacy:metadata:playercross_attempts:score',
                'timecreated'   => 'privacy:metadata:playercross_attempts:timecreated',
            ],
            'privacy:metadata:playercross_attempts'
        );

        // Every column of playercross_words that is not declared below has an explicit
        // reason to be excluded: concept mirrors word verbatim for manual/AI-sourced
        // rows and otherwise carries glossary content on rows imported with addedby=0
        // (never attributable to a real user, and mod_glossary's own privacy provider
        // already covers the source glossary entry); glossaryid only has a meaning on
        // those same addedby=0 rows; approved is a moderation flag a manager sets,
        // which may never be the addedby user at all; timemodified likewise can be
        // updated by a manager approving or editing someone else's word, so it is not
        // reliably a trace of the addedby user's own action.
        $collection->add_database_table(
            'playercross_words',
            [
                'addedby'       => 'privacy:metadata:playercross_words:addedby',
                'playercrossid' => 'privacy:metadata:playercross_words:playercrossid',
                'word'          => 'privacy:metadata:playercross_words:word',
                'clue'          => 'privacy:metadata:playercross_words:clue',
                'source'        => 'privacy:metadata:playercross_words:source',
                'timecreated'   => 'privacy:metadata:playercross_words:timecreated',
            ],
            'privacy:metadata:playercross_words'
        );

        $collection->add_user_preference(
            intro_service::get_preference_name(),
            'privacy:metadata:preference:seenintro'
        );

        return $collection;
    }

    #[\Override]
    public static function export_user_preferences(int $userid): void {
        if (!intro_service::has_seen_intro($userid)) {
            return;
        }

        writer::export_user_preference(
            'mod_playercross',
            intro_service::get_preference_name(),
            transform::yesno(true),
            get_string('privacy:metadata:preference:seenintro', 'mod_playercross')
        );
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                       AND ctx.contextlevel = :ctxlevel1
                  JOIN {modules} m1 ON m1.id = cm.module AND m1.name = :modname1
                  JOIN {playercross} pc ON pc.id = cm.instance
                  JOIN {playercross_attempts} pa ON pa.playercrossid = pc.id
                 WHERE pa.userid = :userid1";
        $contextlist->add_from_sql(
            $sql,
            ['ctxlevel1' => CONTEXT_MODULE, 'modname1' => 'playercross', 'userid1' => $userid]
        );

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                       AND ctx.contextlevel = :ctxlevel2
                  JOIN {modules} m2 ON m2.id = cm.module AND m2.name = :modname2
                  JOIN {playercross} pc ON pc.id = cm.instance
                  JOIN {playercross_words} pcw ON pcw.playercrossid = pc.id
                 WHERE pcw.addedby = :userid2";
        $contextlist->add_from_sql(
            $sql,
            ['ctxlevel2' => CONTEXT_MODULE, 'modname2' => 'playercross', 'userid2' => $userid]
        );

        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('playercross', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $instanceid = (int)$cm->instance;

        $params = ['pid' => $instanceid];

        $userlist->add_from_sql(
            'userid',
            "SELECT pa.userid FROM {playercross_attempts} pa WHERE pa.playercrossid = :pid",
            $params
        );

        $addedbyids = $DB->get_fieldset_sql(
            'SELECT DISTINCT addedby FROM {playercross_words} WHERE playercrossid = :pid AND addedby > 0',
            $params
        );
        foreach ($addedbyids as $userid) {
            $userlist->add_user((int) $userid);
        }
    }

    /**
     * Bulk-resolves the playercross instance id for every context_module context in the
     * list in a single query, instead of calling get_coursemodule_from_id() once per
     * context — shared by export_user_data() and delete_data_for_user(), the two
     * places that need to walk every context in an approved_contextlist.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return array<int,int> Course module id => playercross instance id.
     */
    private static function get_instance_ids_by_cmid(approved_contextlist $contextlist): array {
        global $DB;

        $cmids = [];
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_module) {
                $cmids[] = $context->instanceid;
            }
        }
        if (empty($cmids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT cm.id, cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE cm.id $insql",
            array_merge(['modname' => 'playercross'], $inparams)
        );

        $map = [];
        foreach ($records as $record) {
            $map[(int)$record->id] = (int)$record->instance;
        }
        return $map;
    }

    /**
     * Bulk-loads every attempt row for $userid across all given instance ids in a single
     * query, grouped by playercrossid — the export_user_data() counterpart to
     * get_instance_ids_by_cmid(), avoiding one get_records_select() per context.
     *
     * @param int $userid User id.
     * @param int[] $instanceids Playercross instance ids to load attempts for.
     * @return array<int,\stdClass[]> Playercrossid => attempt records, timecreated ASC.
     */
    private static function get_attempts_by_instanceid(int $userid, array $instanceids): array {
        global $DB;

        if (empty($instanceids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($instanceids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(
            'playercross_attempts',
            "userid = :userid AND playercrossid $insql",
            array_merge(['userid' => $userid], $inparams),
            'timecreated ASC'
        );

        $grouped = [];
        foreach ($records as $record) {
            $grouped[(int)$record->playercrossid][] = $record;
        }
        return $grouped;
    }

    /**
     * Bulk-loads every word row added by $userid across all given instance ids in a
     * single query, grouped by playercrossid — the export_user_data() counterpart to
     * get_attempts_by_instanceid() for the playercross_words table.
     *
     * @param int $userid User id.
     * @param int[] $instanceids Playercross instance ids to load words for.
     * @return array<int,\stdClass[]> Playercrossid => word records, timecreated ASC.
     */
    private static function get_words_by_instanceid(int $userid, array $instanceids): array {
        global $DB;

        if (empty($instanceids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($instanceids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(
            'playercross_words',
            "addedby = :addedby AND playercrossid $insql",
            array_merge(['addedby' => $userid], $inparams),
            'timecreated ASC',
            'id, playercrossid, word, clue, source, timecreated'
        );

        $grouped = [];
        foreach ($records as $record) {
            $grouped[(int)$record->playercrossid][] = $record;
        }
        return $grouped;
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $instanceidsbycmid = self::get_instance_ids_by_cmid($contextlist);
        $instanceids = array_values($instanceidsbycmid);
        $attemptsbyinstanceid = self::get_attempts_by_instanceid($userid, $instanceids);
        $wordsbyinstanceid = self::get_words_by_instanceid($userid, $instanceids);

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $instanceid = $instanceidsbycmid[$context->instanceid] ?? null;
            if ($instanceid === null) {
                continue;
            }

            $attempts = $attemptsbyinstanceid[$instanceid] ?? [];
            if (!empty($attempts)) {
                $rows = array_values(array_map(function (\stdClass $a): array {
                    return [
                        'themewordid'   => (int)$a->themewordid,
                        'termstotal'    => (int)$a->termstotal,
                        'termsresolved' => (int)$a->termsresolved,
                        'finalguessed'  => transform::yesno($a->finalguessed),
                        'attempts_used' => (int)$a->attempts_used,
                        'time_used'     => (int)$a->time_used,
                        'completed'     => transform::yesno($a->completed),
                        'score'         => (float)$a->score,
                        'timecreated'   => transform::datetime($a->timecreated),
                    ];
                }, $attempts));

                writer::with_context($context)->export_data(
                    [
                        get_string('pluginname', 'mod_playercross'),
                        get_string('privacy:attempts', 'mod_playercross'),
                    ],
                    (object)['attempts' => $rows]
                );
            }

            $words = $wordsbyinstanceid[$instanceid] ?? [];
            if (!empty($words)) {
                $rows = array_values(array_map(function (\stdClass $w): array {
                    return [
                        'word'        => $w->word,
                        'clue'        => $w->clue,
                        'source'      => $w->source,
                        'timecreated' => transform::datetime($w->timecreated),
                    ];
                }, $words));

                writer::with_context($context)->export_data(
                    [
                        get_string('pluginname', 'mod_playercross'),
                        get_string('privacy:words', 'mod_playercross'),
                    ],
                    (object)['words' => $rows]
                );
            }
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('playercross', $context->instanceid);
        if (!$cm) {
            return;
        }

        $DB->delete_records('playercross_attempts', ['playercrossid' => (int)$cm->instance]);
        $DB->set_field('playercross_words', 'addedby', 0, ['playercrossid' => (int)$cm->instance]);
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $instanceidsbycmid = self::get_instance_ids_by_cmid($contextlist);

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $instanceid = $instanceidsbycmid[$context->instanceid] ?? null;
            if ($instanceid === null) {
                continue;
            }

            $DB->delete_records('playercross_attempts', [
                'userid'        => $userid,
                'playercrossid' => $instanceid,
            ]);
            $DB->set_field_select(
                'playercross_words',
                'addedby',
                0,
                'addedby = :addedby AND playercrossid = :pid',
                ['addedby' => $userid, 'pid' => $instanceid]
            );
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('playercross', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');

        $DB->delete_records_select(
            'playercross_attempts',
            "userid $insql AND playercrossid = :pid",
            array_merge($inparams, ['pid' => (int)$cm->instance])
        );
        $DB->set_field_select(
            'playercross_words',
            'addedby',
            0,
            "addedby $insql AND playercrossid = :pid",
            array_merge($inparams, ['pid' => (int)$cm->instance])
        );
    }
}
