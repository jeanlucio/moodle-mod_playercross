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
 * Top-5 ranking page for a PlayerCross activity — deliberately capped, not paginated, to
 * avoid publicly ranking every student (see ranking_service::TOP_N).
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_playercross\local\ranking_service;

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('playercross', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('playercross', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/playercross:view', $context);

if (empty($instance->show_ranking)) {
    redirect(new moodle_url('/mod/playercross/view.php', ['id' => $cm->id]));
}

$PAGE->set_url('/mod/playercross/ranking.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('ranking_title', 'mod_playercross') . ' — ' . format_string($instance->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css('/mod/playercross/styles.css');

$ranking = ranking_service::get_ranking($instance, $cm, (int)$USER->id);

$templatecontext = [
    'activityname'          => format_string($instance->name, true, ['context' => $context]),
    'activityurl'           => (new moodle_url('/mod/playercross/view.php', ['id' => $cm->id]))->out(false),
    'backlabel'             => get_string('backtogamebutton', 'mod_playercross'),
    'rankingtitle'          => get_string('ranking_title', 'mod_playercross'),
    'rankingpositionlabel'  => get_string('ranking_position', 'mod_playercross'),
    'rankingplayerlabel'    => get_string('ranking_player', 'mod_playercross'),
    'rankingpointslabel'    => get_string('ranking_points', 'mod_playercross'),
    'rankingrows'           => $ranking['rows'],
    'rankinghasoutsider'    => $ranking['hasoutsider'],
    'rankingoutsiderrow'    => $ranking['outsiderrow'],
    'rankingempty'          => $ranking['isempty'],
    'rankingemptylabel'     => get_string('ranking_empty', 'mod_playercross'),
    'rankingtiebreaktext'   => get_string('ranking_tiebreak', 'mod_playercross'),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_playercross/ranking', $templatecontext);
echo $OUTPUT->footer();
