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
 * A student's own round history for a playercross instance.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_playercross\local\attempts_history_service;

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('playercross', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('playercross', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/playercross:view', $context);

$PAGE->set_url('/mod/playercross/myattempts.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('myattempts_title', 'mod_playercross') . ' — ' . format_string($instance->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css('/mod/playercross/styles.css');

// Always the logged-in user's own data — never a userid read from the request.
$history = attempts_history_service::get_history($instance, (int)$USER->id);

$templatecontext = [
    'activityname'      => format_string($instance->name, true, ['context' => $context]),
    'activityurl'       => (new moodle_url('/mod/playercross/view.php', ['id' => $cm->id]))->out(false),
    'backlabel'         => get_string('backtogamebutton', 'mod_playercross'),
    'myattemptstitle'   => get_string('myattempts_title', 'mod_playercross'),
    'themelabel'        => get_string('myattempts_theme', 'mod_playercross'),
    'clueslabel'        => get_string('myattempts_clues', 'mod_playercross'),
    'finalguessedlabel' => get_string('myattempts_finalguessed', 'mod_playercross'),
    'attemptslabel'     => get_string('myattempts_attempts', 'mod_playercross'),
    'timelabel'         => get_string('myattempts_time', 'mod_playercross'),
    'scorelabel'        => get_string('myattempts_score', 'mod_playercross'),
    'datelabel'         => get_string('myattempts_date', 'mod_playercross'),
    'emptylabel'        => get_string('myattempts_empty', 'mod_playercross'),
    'yeslabel'          => get_string('yes'),
    'nolabel'           => get_string('no'),
    'rows'              => $history['rows'],
    'isempty'           => $history['isempty'],
    'showgrade'         => $history['showgrade'],
    'gradesummary'      => get_string('gradesofar', 'mod_playercross', (object)[
        'method'   => $history['grademethodname'],
        'mygrade'  => $history['grade'],
        'maxgrade' => $history['maxgrade'],
    ]),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_playercross/myattempts', $templatecontext);
echo $OUTPUT->footer();
