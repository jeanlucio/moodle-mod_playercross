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
 * Library functions for mod_playercross.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Source type bit flag for manual clue words. */
define('PLAYERCROSS_SOURCE_MANUAL', 1);

/** Source type bit flag for glossary clue words. */
define('PLAYERCROSS_SOURCE_GLOSSARY', 2);

/** Theme word selection mode: a random theme word is picked each round. */
define('PLAYERCROSS_WORDMODE_RANDOM', 1);

/** Theme word selection mode: all students receive the same theme word per round number. */
define('PLAYERCROSS_WORDMODE_SHARED', 2);

/** Grade aggregation: highest score across all rounds. */
define('PLAYERCROSS_GRADE_HIGHEST', 1);

/** Grade aggregation: average score across all rounds. */
define('PLAYERCROSS_GRADE_AVERAGE', 2);

/** Grade aggregation: score from the first round. */
define('PLAYERCROSS_GRADE_FIRST', 3);

/** Grade aggregation: score from the last round. */
define('PLAYERCROSS_GRADE_LAST', 4);

/** Grade aggregation: average over all required rounds (uses max_rounds as denominator). */
define('PLAYERCROSS_GRADE_AVERAGE_ALL', 5);

/**
 * Tells Moodle this plugin uses a branded icon (disables purpose recolour filter).
 *
 * @return bool
 */
function mod_playercross_is_branded(): bool {
    return true;
}

/**
 * Builds the clue-word source bitmask from form data.
 *
 * @param stdClass $data Form data.
 * @return int
 */
function playercross_build_sources(stdClass $data): int {
    $sources = 0;

    if (!empty($data->source_manual)) {
        $sources |= PLAYERCROSS_SOURCE_MANUAL;
    }
    if (!empty($data->source_glossary)) {
        $sources |= PLAYERCROSS_SOURCE_GLOSSARY;
    }
    return $sources;
}

/**
 * Creates or updates the grade item for a playercross instance.
 *
 * @param stdClass $instance Activity instance (must have id, course, name, grade, gradepass).
 * @param mixed $grades Grade object(s), null to update item only, or 'reset' to reset grades.
 * @return int GRADE_UPDATE_OK or error constant.
 */
function playercross_grade_item_update(stdClass $instance, mixed $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname' => $instance->name,
        'idnumber' => $instance->cmidnumber ?? '',
    ];

    if ((int)$instance->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = (float)$instance->grade;
        $params['grademin']  = 0.0;
    } else if ((int)$instance->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -(int)$instance->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    if (!empty($instance->gradepass)) {
        $params['gradepass'] = (float)$instance->gradepass;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update(
        'mod/playercross',
        $instance->course,
        'mod',
        'playercross',
        $instance->id,
        0,
        $grades,
        $params
    );
}

/**
 * Returns the available grading method options, keyed by their PLAYERCROSS_GRADE_* constant.
 *
 * @return array<int, string>
 */
function playercross_get_grademethod_options(): array {
    return [
        PLAYERCROSS_GRADE_HIGHEST     => get_string('grademethod_highest', 'mod_playercross'),
        PLAYERCROSS_GRADE_AVERAGE     => get_string('grademethod_average', 'mod_playercross'),
        PLAYERCROSS_GRADE_FIRST       => get_string('grademethod_first', 'mod_playercross'),
        PLAYERCROSS_GRADE_LAST        => get_string('grademethod_last', 'mod_playercross'),
        PLAYERCROSS_GRADE_AVERAGE_ALL => get_string('grademethod_average_all', 'mod_playercross'),
    ];
}

/**
 * Calculates a single user's final grade from their round attempts.
 *
 * @param stdClass $instance Activity instance.
 * @param array $attempts Attempt records for this user, ordered by timecreated ASC.
 * @return float
 */
function playercross_calculate_user_grade(stdClass $instance, array $attempts): float {
    if (empty($attempts)) {
        return 0.0;
    }

    $scores = array_map(fn($a) => (float)$a->score, $attempts);
    $grademethod = (int)($instance->grademethod ?? PLAYERCROSS_GRADE_HIGHEST);

    switch ($grademethod) {
        case PLAYERCROSS_GRADE_AVERAGE:
            return array_sum($scores) / count($scores);
        case PLAYERCROSS_GRADE_FIRST:
            return $scores[array_key_first($scores)];
        case PLAYERCROSS_GRADE_LAST:
            return $scores[array_key_last($scores)];
        case PLAYERCROSS_GRADE_AVERAGE_ALL:
            $totalrounds = (int)($instance->max_rounds ?? 0);
            if ($totalrounds <= 0) {
                return array_sum($scores) / count($scores);
            }
            return array_sum($scores) / $totalrounds;
        case PLAYERCROSS_GRADE_HIGHEST:
        default:
            return max($scores);
    }
}

/**
 * Updates gradebook grades for one or all users of a playercross instance.
 *
 * @param stdClass $instance Activity instance.
 * @param int $userid User id, 0 to update all users.
 * @return void
 */
function playercross_update_grades(stdClass $instance, int $userid = 0): void {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    // The playercross_attempts table is append-only and only ever gains a row once a
    // round is fully finished (see SCOPE.md §5) — every row already qualifies, unlike
    // PlayerWords' timefinished > 0 filter for its "reserved but unfinished" state.
    $sql = "SELECT a.id, a.userid, a.score, a.timecreated
              FROM {playercross_attempts} a
             WHERE a.playercrossid = :instanceid";
    $params = ['instanceid' => $instance->id];

    if ($userid > 0) {
        $sql .= ' AND a.userid = :userid';
        $params['userid'] = $userid;
    }

    $sql .= ' ORDER BY a.timecreated ASC';
    $attempts = $DB->get_records_sql($sql, $params);

    if (empty($attempts)) {
        playercross_grade_item_update($instance);
        return;
    }

    $userattempts = [];
    foreach ($attempts as $attempt) {
        $userattempts[$attempt->userid][] = $attempt;
    }

    $grades = [];
    foreach ($userattempts as $uid => $userattemptlist) {
        $grade = new stdClass();
        $grade->userid = $uid;
        $grade->rawgrade = playercross_calculate_user_grade($instance, $userattemptlist);
        $grades[$uid] = $grade;
    }

    playercross_grade_item_update($instance, $grades);
}

/**
 * Normalises playercross form/instance data shared by add_instance() and update_instance():
 * the clue-word source bitmask, the completion rule toggle, and the cooldown/timer unit
 * groups collapsed into their stored seconds columns.
 *
 * @param stdClass $data Form data, mutated in place.
 * @return void
 */
function playercross_normalise_instance_data(stdClass $data): void {
    if (empty($data->completionroundsenabled)) {
        $data->completionrounds = 0;
    }
    unset($data->completionroundsenabled);

    $data->gradepass = isset($data->gradepass) ? (float)$data->gradepass : 0.0;

    $data->sources = playercross_build_sources($data);
    unset($data->source_manual, $data->source_glossary);

    $multipliers = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];
    $unit        = $data->cooldown_unit ?? 'days';
    $amount      = (int)($data->cooldown_amount ?? 0);
    $data->cooldown_seconds = $amount * ($multipliers[$unit] ?? 86400);
    unset($data->cooldown_amount, $data->cooldown_unit);

    $data->timer_seconds = max(0, (int)($data->timer_minutes ?? 0)) * 60;
    unset($data->timer_minutes);
}

/**
 * Creates a new playercross activity instance.
 *
 * @param stdClass $data Form data.
 * @return int New instance id.
 */
function playercross_add_instance(stdClass $data): int {
    global $DB;

    playercross_normalise_instance_data($data);
    $data->timecreated = time();
    $data->timemodified = time();
    $data->id = $DB->insert_record('playercross', $data);

    playercross_grade_item_update($data);
    \mod_playercross\local\words_repository::sync_glossary_words($data);

    return $data->id;
}

/**
 * Updates an existing playercross activity instance.
 *
 * @param stdClass $data Form data, including the instance id in $data->instance.
 * @return bool
 */
function playercross_update_instance(stdClass $data): bool {
    global $DB;

    playercross_normalise_instance_data($data);
    $data->id = $data->instance;
    $data->timemodified = time();
    $result = $DB->update_record('playercross', $data);

    playercross_grade_item_update($data);
    \mod_playercross\local\words_repository::sync_glossary_words($data);

    return $result;
}

/**
 * Deletes a playercross activity instance and its owned data.
 *
 * @param int $id Instance id.
 * @return bool True if the instance existed and was deleted.
 */
function playercross_delete_instance(int $id): bool {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $instance = $DB->get_record('playercross', ['id' => $id], 'id, course', IGNORE_MISSING);
    if (!$instance) {
        return false;
    }

    grade_update(
        'mod/playercross',
        $instance->course,
        'mod',
        'playercross',
        $id,
        0,
        null,
        ['deleted' => 1]
    );

    $DB->delete_records('playercross_attempts', ['playercrossid' => $id]);
    $DB->delete_records('playercross_words', ['playercrossid' => $id]);
    $DB->delete_records('playercross', ['id' => $id]);

    return true;
}

/**
 * Return the features this module supports.
 *
 * @param string $feature FEATURE_xx constant for requested feature.
 * @return mixed True if module supports feature, null if doesn't know.
 */
function playercross_supports(string $feature): mixed {
    switch ($feature) {
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_INTERACTIVECONTENT;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        default:
            return null;
    }
}

/**
 * Populates the course module info object with custom completion rule data.
 *
 * Called by Moodle when building cm_info. Stores the required round count in
 * customdata so activity_custom_completion::get_available_custom_rules() can
 * determine whether the rule is enabled for this instance, and so
 * mod_playercross\completion\custom_completion::get_state() can evaluate it.
 *
 * @param stdClass $coursemodule The raw course_modules row (id, instance, …).
 * @return cached_cm_info|false A populated info object, or false on failure.
 */
function playercross_get_coursemodule_info(stdClass $coursemodule): cached_cm_info|false {
    global $DB;

    $fields = 'id, name, completionrounds';
    $playercross = $DB->get_record('playercross', ['id' => $coursemodule->instance], $fields);
    if (!$playercross) {
        return false;
    }

    $info = new cached_cm_info();
    $info->name = $playercross->name;

    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionrounds'] = (int)$playercross->completionrounds;
    }

    return $info;
}

/**
 * Describes the active custom completion rules.
 *
 * @param stdClass|cm_info $cm The course module info.
 * @return array An array of active completion rule descriptions.
 */
function playercross_get_completion_active_rule_descriptions(stdClass|cm_info $cm): array {
    $descriptions = [];

    $rules = $cm->customdata['customcompletionrules'] ?? [];
    if (!empty($rules['completionrounds'])) {
        $descriptions[] = get_string('completionrounds_desc', 'mod_playercross', $rules['completionrounds']);
    }

    return $descriptions;
}

/**
 * Adds the course reset form elements for playercross.
 *
 * @param MoodleQuickForm $mform The course reset form.
 * @return void
 */
function playercross_reset_course_form_definition(MoodleQuickForm $mform): void {
    $mform->addElement('header', 'playercrossheader', get_string('modulenameplural', 'mod_playercross'));
    $mform->addElement('advcheckbox', 'reset_playercross_attempts', get_string('resetplayercrossattempts', 'mod_playercross'));
}

/**
 * Returns the default values for the PlayerCross course reset form.
 *
 * @param stdClass $course The course being reset.
 * @return array
 */
function playercross_reset_course_form_defaults(stdClass $course): array {
    return ['reset_playercross_attempts' => 1];
}

/**
 * Removes student round attempts and recalculates grades when a course is reset.
 *
 * @param stdClass $data Reset form data, must contain courseid.
 * @return array Status messages for the course reset report.
 */
function playercross_reset_userdata(stdClass $data): array {
    global $DB;

    $status = [];
    if (empty($data->reset_playercross_attempts)) {
        return $status;
    }

    $instances = $DB->get_records('playercross', ['course' => $data->courseid]);
    if (empty($instances)) {
        return $status;
    }

    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($instances), SQL_PARAMS_NAMED, 'pid');
    $DB->delete_records_select('playercross_attempts', "playercrossid $insql", $inparams);

    foreach ($instances as $instance) {
        playercross_grade_item_update($instance, 'reset');
    }

    $status[] = [
        'component' => get_string('modulenameplural', 'mod_playercross'),
        'item'      => get_string('resetplayercrossattempts', 'mod_playercross'),
        'error'     => false,
    ];

    return $status;
}

/**
 * Reports the total XP potentially earnable through this course's win-reward configuration,
 * for block_playerhud's "Total XP no jogo" ceiling estimate.
 *
 * Discovered automatically by block_playerhud via get_plugins_with_function() — see
 * \block_playerhud\local\analytics::game_xp_totals(). Only called when block_playerhud is
 * active, so \block_playerhud\local\external_items is always available here. An unlimited
 * activity (max_rounds = 0) contributes nothing, mirroring the same anti-farming rule applied
 * to the real grant in round_service::finish_round().
 *
 * @param int $blockinstanceid PlayerHUD block instance ID to report potential XP for.
 * @return array Rows shaped like block_playerhud's own item/quest breakdown entries.
 */
function playercross_playerhud_grant_potential(int $blockinstanceid): array {
    global $DB;

    $courseid = $DB->get_field_sql(
        "SELECT ctx.instanceid
           FROM {block_instances} bi
           JOIN {context} ctx ON bi.parentcontextid = ctx.id
          WHERE bi.id = :biid AND ctx.contextlevel = :clevel",
        ['biid' => $blockinstanceid, 'clevel' => CONTEXT_COURSE]
    );

    if (!$courseid) {
        return [];
    }

    $instances = $DB->get_records_select(
        'playercross',
        'course = :courseid AND hud_win_reward_item > 0 AND max_rounds > 0',
        ['courseid' => $courseid],
        '',
        'id, name, hud_win_reward_item, hud_win_reward_qty, max_rounds'
    );

    $rows = [];
    foreach ($instances as $instance) {
        $itemid = (int)$instance->hud_win_reward_item;
        $itemxp = \block_playerhud\local\external_items::get_xp($blockinstanceid, $itemid);
        if ($itemxp <= 0) {
            // Zero-XP item, or the item does not belong to this block instance (e.g. stale
            // config copied from another course) — either way, nothing to add here.
            continue;
        }

        $qty = max(1, (int)$instance->hud_win_reward_qty);

        $rows[] = [
            'name'       => format_string($instance->name),
            'xp_each'    => $itemxp * $qty,
            'drop_count' => 0,
            'total_uses' => (int)$instance->max_rounds,
            'xp_total'   => $itemxp * $qty * (int)$instance->max_rounds,
            'is_quest'   => false,
            'infinite'   => false,
        ];
    }

    return $rows;
}
