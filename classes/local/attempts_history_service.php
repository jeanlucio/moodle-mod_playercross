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
 * Attempt history query service for mod_playercross.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Builds one student's own round history, plus the teacher/manager-facing report
 * across every student. Ported from mod_playerwords\local\attempts_history_service,
 * adapted for the puzzle's own attempt shape (theme word, clues resolved, direct
 * final guess) instead of a single guessed word.
 */
class attempts_history_service {
    /** @var int Default rows per page for the all-students attempt report. */
    const REPORT_PERPAGE = 30;

    /** @var array<string,string> Allow-listed sortable columns for the all-students report. */
    private const SORTABLE_COLUMNS = [
        'student'  => 'studentname',
        'theme'    => 'pw.word',
        'clues'    => 'pa.cluesresolved',
        'attempts' => 'pa.attempts_used',
        'time'     => 'pa.time_used',
        'score'    => 'pa.score',
        'date'     => 'pa.timecreated',
    ];

    /**
     * Returns the round history and current grade for one student.
     *
     * Reads only rows matching both playercrossid and userid — this is the sole
     * security boundary for the "my attempts" page, so the caller must always pass
     * the logged-in user's own id, never one read from the request.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $userid User id.
     * @return array
     */
    public static function get_history(\stdClass $instance, int $userid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playercross/lib.php');

        $sql = "SELECT pa.*, pw.word, pw.concept
                  FROM {playercross_attempts} pa
             LEFT JOIN {playercross_words} pw ON pw.id = pa.themewordid
                 WHERE pa.playercrossid = :instanceid
                       AND pa.userid = :userid
              ORDER BY pa.timecreated ASC";
        $attemptsasc = array_values($DB->get_records_sql($sql, [
            'instanceid' => (int)$instance->id,
            'userid'     => $userid,
        ]));

        $isempty = empty($attemptsasc);

        $rows = array_map(
            fn(\stdClass $attempt): array => self::build_row($attempt),
            array_reverse($attemptsasc)
        );

        $showgrade = !$isempty && (float)$instance->grade > 0;
        $grade = 0.0;
        if ($showgrade) {
            $grade = playercross_calculate_user_grade($instance, $attemptsasc);
        }

        return [
            'rows'            => $rows,
            'isempty'         => $isempty,
            'showgrade'       => $showgrade,
            'grade'           => format_float($grade, 2),
            'maxgrade'        => format_float((float)$instance->grade, 2),
            'grademethodname' => round_presenter::grademethod_name($instance),
        ];
    }

    /**
     * Formats one attempt record into a display row.
     *
     * @param \stdClass $attempt Attempt record, optionally joined with the theme word
     *     and (for the all-students report) a studentname column.
     * @return array
     */
    private static function build_row(\stdClass $attempt): array {
        $minutes = intdiv((int)$attempt->time_used, 60);
        $seconds = (int)$attempt->time_used % 60;

        $row = [
            'themeword'     => $attempt->concept ?: ($attempt->word ?: ''),
            'cluesresolved' => (int)$attempt->cluesresolved,
            'cluestotal'    => (int)$attempt->cluestotal,
            'finalguessed'  => !empty($attempt->finalguessed),
            'attemptsused'  => (int)$attempt->attempts_used,
            'timeused'      => sprintf('%d:%02d', $minutes, $seconds),
            'won'           => !empty($attempt->completed),
            'score'         => format_float((float)$attempt->score, 2),
            'datecreated'   => userdate((int)$attempt->timecreated, get_string('strftimedatetime', 'langconfig')),
        ];

        if (isset($attempt->studentname)) {
            $row['student'] = $attempt->studentname;
        }

        return $row;
    }

    /**
     * Returns the manager exclusion SQL fragment and params, or empty values when
     * nobody with the manage capability holds it in this context.
     *
     * Excludes anyone who can manage the activity (editingteacher, manager) from the
     * report — a teacher previewing the activity should not be tracked as a player in
     * a student-facing report either.
     *
     * @param \context $context Module context.
     * @return array{0: string, 1: array}
     */
    private static function manager_exclusion(\context $context): array {
        $managers = get_users_by_capability($context, 'mod/playercross:addinstance', 'u.id');
        if (empty($managers)) {
            return ['', []];
        }

        global $DB;
        [$notinsql, $notinparams] = $DB->get_in_or_equal(array_keys($managers), SQL_PARAMS_NAMED, 'mgr', false);
        return ["AND pa.userid $notinsql", $notinparams];
    }

    /**
     * Returns one page of attempts across every student, for the teacher/manager-facing report.
     *
     * @param \stdClass $instance Activity instance.
     * @param \context $context Module context.
     * @param int $page Zero-based page number.
     * @param int $perpage Rows per page.
     * @param string $sort Column key from SORTABLE_COLUMNS.
     * @param string $dir Sort direction: 'ASC' or 'DESC'.
     * @param int $filteruserid Restrict to one student, 0 for all.
     * @return array
     */
    public static function get_all_history(
        \stdClass $instance,
        \context $context,
        int $page,
        int $perpage,
        string $sort,
        string $dir,
        int $filteruserid
    ): array {
        global $DB;

        $sortcolumn = self::SORTABLE_COLUMNS[$sort] ?? self::SORTABLE_COLUMNS['date'];
        $dir = (strtoupper($dir) === 'ASC') ? 'ASC' : 'DESC';

        $params = ['instanceid' => (int)$instance->id];
        $userwhere = '';
        if ($filteruserid > 0) {
            $userwhere = 'AND pa.userid = :filteruserid';
            $params['filteruserid'] = $filteruserid;
        }

        [$managerwhere, $managerparams] = self::manager_exclusion($context);
        $params = array_merge($params, $managerparams);

        $fullname = $DB->sql_fullname('u.firstname', 'u.lastname');
        $wheresql = "pa.playercrossid = :instanceid
                       $userwhere
                       $managerwhere";

        $total = $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {playercross_attempts} pa
               JOIN {user} u ON u.id = pa.userid
              WHERE $wheresql",
            $params
        );

        $sql = "SELECT pa.*, pw.word, pw.concept, $fullname AS studentname
                  FROM {playercross_attempts} pa
                  JOIN {user} u ON u.id = pa.userid
             LEFT JOIN {playercross_words} pw ON pw.id = pa.themewordid
                 WHERE $wheresql
              ORDER BY $sortcolumn $dir, pa.id $dir";

        $records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

        $rows = array_map(
            fn(\stdClass $attempt): array => self::build_row($attempt),
            array_values($records)
        );

        return [
            'rows'    => $rows,
            'isempty' => ($total === 0),
            'total'   => (int)$total,
        ];
    }

    /**
     * Returns students with at least one attempt, for the report's filter dropdown.
     * Excludes the same manager set get_all_history() excludes from the report itself.
     *
     * @param \stdClass $instance Activity instance.
     * @param \context $context Module context.
     * @return \stdClass[]
     */
    public static function get_players_for_filter(\stdClass $instance, \context $context): array {
        global $DB;

        [$managerwhere, $managerparams] = self::manager_exclusion($context);
        $params = array_merge(['instanceid' => (int)$instance->id], $managerparams);

        $fullname = $DB->sql_fullname('u.firstname', 'u.lastname');
        $sql = "SELECT DISTINCT u.id, $fullname AS fullname
                  FROM {playercross_attempts} pa
                  JOIN {user} u ON u.id = pa.userid
                 WHERE pa.playercrossid = :instanceid
                       $managerwhere
              ORDER BY fullname ASC";

        return array_values($DB->get_records_sql($sql, $params));
    }
}
