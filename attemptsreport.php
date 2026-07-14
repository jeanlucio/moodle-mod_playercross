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
 * Paginated, sortable, filterable report of every student's round history.
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
require_capability('mod/playercross:addinstance', $context);

$page = optional_param('page', 0, PARAM_INT);
$sort = optional_param('sort', 'date', PARAM_ALPHA);
$dir = (strtoupper(optional_param('dir', 'DESC', PARAM_ALPHA)) === 'ASC') ? 'ASC' : 'DESC';
$filteruserid = optional_param('studentid', 0, PARAM_INT);
$perpage = attempts_history_service::REPORT_PERPAGE;

$PAGE->set_url('/mod/playercross/attemptsreport.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('attemptsreport_title', 'mod_playercross') . ' — ' . format_string($instance->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css('/mod/playercross/styles.css');

$history = attempts_history_service::get_all_history(
    $instance,
    $context,
    $page,
    $perpage,
    $sort,
    $dir,
    $filteruserid
);
$players = attempts_history_service::get_players_for_filter($instance, $context);

$columns = [
    ['key' => 'student', 'label' => get_string('myattempts_student', 'mod_playercross'), 'alignend' => false],
    ['key' => 'theme', 'label' => get_string('myattempts_theme', 'mod_playercross'), 'alignend' => false],
    ['key' => 'clues', 'label' => get_string('myattempts_clues', 'mod_playercross'), 'alignend' => true],
    ['key' => 'attempts', 'label' => get_string('myattempts_attempts', 'mod_playercross'), 'alignend' => true],
    ['key' => 'time', 'label' => get_string('myattempts_time', 'mod_playercross'), 'alignend' => true],
    ['key' => 'score', 'label' => get_string('myattempts_score', 'mod_playercross'), 'alignend' => true],
    ['key' => 'date', 'label' => get_string('myattempts_date', 'mod_playercross'), 'alignend' => false],
];

foreach ($columns as &$column) {
    $active = ($column['key'] === $sort);
    $nextdir = ($active && $dir === 'ASC') ? 'DESC' : 'ASC';
    $columnurl = new moodle_url('/mod/playercross/attemptsreport.php', [
        'id'        => $cm->id,
        'sort'      => $column['key'],
        'dir'       => $nextdir,
        'studentid' => $filteruserid,
    ]);
    $column['url'] = $columnurl->out(false);
    $column['active'] = $active;
    $column['arrow'] = $active ? ($dir === 'ASC' ? ' ▲' : ' ▼') : '';
}
unset($column);

$studentoptions = [
    ['id' => 0, 'name' => get_string('myattempts_allstudents', 'mod_playercross'), 'selected' => ($filteruserid === 0)],
];
foreach ($players as $player) {
    $studentoptions[] = [
        'id'       => $player->id,
        'name'     => $player->fullname,
        'selected' => ((int)$player->id === $filteruserid),
    ];
}

$baseurl = new moodle_url('/mod/playercross/attemptsreport.php', [
    'id' => $cm->id, 'sort' => $sort, 'dir' => $dir, 'studentid' => $filteruserid,
]);
$pagingbar = $OUTPUT->paging_bar($history['total'], $page, $perpage, $baseurl);

$templatecontext = [
    'activityname'      => format_string($instance->name, true, ['context' => $context]),
    'activityurl'       => (new moodle_url('/mod/playercross/view.php', ['id' => $cm->id]))->out(false),
    'backlabel'         => get_string('backtogamebutton', 'mod_playercross'),
    'cmid'              => $cm->id,
    'reporttitle'       => get_string('attemptsreport_title', 'mod_playercross'),
    'emptylabel'        => get_string('attemptsreport_empty', 'mod_playercross'),
    'finalguessedlabel' => get_string('myattempts_finalguessed', 'mod_playercross'),
    'yeslabel'          => get_string('yes'),
    'nolabel'           => get_string('no'),
    'rows'              => $history['rows'],
    'isempty'           => $history['isempty'],
    'columns'           => $columns,
    'filterlabel'       => get_string('myattempts_student', 'mod_playercross'),
    'filterbuttonlabel' => get_string('myattempts_filterbutton', 'mod_playercross'),
    'studentoptions'    => $studentoptions,
    'filterurl'         => (new moodle_url('/mod/playercross/attemptsreport.php'))->out(false),
    'currentsort'       => $sort,
    'currentdir'        => $dir,
    'pagingbar'         => $pagingbar,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_playercross/attempts_report', $templatecontext);
echo $OUTPUT->footer();
