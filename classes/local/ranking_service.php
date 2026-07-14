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
 * Ranking query service for mod_playercross.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Builds the accumulated ranking for a PlayerCross activity.
 */
class ranking_service {
    /** @var int Maximum rows returned in the top list. */
    const TOP_N = 5;

    /**
     * Returns the accumulated ranking for an activity.
     *
     * One row per student: SUM(score) DESC, AVG(attempts_used) ASC, AVG(time_used) ASC.
     * Respects SEPARATEGROUPS: filters to members of the current user's group. Excludes anyone
     * who can manage the activity (editingteacher, manager) even if they have attempts of their
     * own — a teacher previewing the activity should not pollute the student-facing ranking.
     * Returns up to TOP_N rows plus the current user's row when outside the top.
     *
     * Unlike mod_playerwords, playercross_attempts is append-only and only ever gains a row
     * once a round is fully finished (see SCOPE.md §5), so every row already qualifies — there
     * is no "reserved but unfinished" state to filter out here.
     *
     * @param \stdClass $instance Activity instance record.
     * @param \stdClass $cm Course module record.
     * @param int $userid Current user id.
     * @return array {rows, outsiderrow, hasoutsider, isempty}
     */
    public static function get_ranking(\stdClass $instance, \stdClass $cm, int $userid): array {
        global $DB;

        $useridfilter = self::resolve_user_filter($cm, $userid);

        $params = ['instanceid' => (int)$instance->id];
        $userwhere = '';
        if ($useridfilter !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($useridfilter, SQL_PARAMS_NAMED, 'uid');
            $userwhere = "AND pa.userid $insql";
            $params = array_merge($params, $inparams);
        }

        $context = \context_module::instance($cm->id);
        $managers = get_users_by_capability($context, 'mod/playercross:addinstance', 'u.id');
        if (!empty($managers)) {
            [$notinsql, $notinparams] = $DB->get_in_or_equal(array_keys($managers), SQL_PARAMS_NAMED, 'mgr', false);
            $userwhere .= " AND pa.userid $notinsql";
            $params = array_merge($params, $notinparams);
        }

        $fullname = $DB->sql_fullname('u.firstname', 'u.lastname');
        $sql = "SELECT u.id,
                       $fullname AS fullname,
                       SUM(pa.score) AS totalscore,
                       AVG(pa.attempts_used) AS avgattempts,
                       AVG(pa.time_used) AS avgtime
                  FROM {playercross_attempts} pa
                  JOIN {user} u ON u.id = pa.userid
                 WHERE pa.playercrossid = :instanceid
                       $userwhere
              GROUP BY u.id, u.firstname, u.lastname
              ORDER BY SUM(pa.score) DESC, AVG(pa.attempts_used) ASC, AVG(pa.time_used) ASC";

        $records = $DB->get_records_sql($sql, $params);

        $rows = [];
        $outsiderrow = null;
        $position = 1;

        foreach ($records as $record) {
            $iscurrent = ((int)$record->id === $userid);
            $row = [
                'position'      => $position,
                'fullname'      => $record->fullname,
                'totalscore'    => format_float((float)$record->totalscore, 2),
                'iscurrentuser' => $iscurrent,
            ];

            if ($position <= self::TOP_N) {
                $rows[] = $row;
            }

            if ($iscurrent && $position > self::TOP_N) {
                $outsiderrow = $row;
            }

            $position++;
        }

        return [
            'rows'        => $rows,
            'outsiderrow' => $outsiderrow,
            'hasoutsider' => ($outsiderrow !== null),
            'isempty'     => empty($records),
        ];
    }

    /**
     * Resolves the user id filter based on groupmode.
     *
     * Returns null when no filter is needed (all course users visible).
     * Returns an array of user ids when SEPARATEGROUPS is active.
     *
     * @param \stdClass $cm Course module record.
     * @param int $userid Current user id.
     * @return int[]|null
     */
    private static function resolve_user_filter(\stdClass $cm, int $userid): ?array {
        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode != SEPARATEGROUPS) {
            return null;
        }

        $groups = groups_get_all_groups($cm->course, $userid, $cm->groupingid);
        if (empty($groups)) {
            return [$userid];
        }

        $memberids = [];
        foreach ($groups as $group) {
            $members = groups_get_members($group->id, 'u.id');
            foreach ($members as $member) {
                $memberids[$member->id] = $member->id;
            }
        }

        return array_keys($memberids);
    }
}
