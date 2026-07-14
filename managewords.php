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
 * Manage words for one PlayerCross activity.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_playercross\local\ai_word_generator;
use mod_playercross\local\word_normalizer;
use mod_playercross\local\words_repository;

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('playercross', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('playercross', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/playercross:addinstance', $context);

$sort = optional_param('sort', 'id', PARAM_ALPHA);
$dir  = optional_param('dir', 'DESC', PARAM_ALPHA);

$allowedsorts = ['id', 'word', 'source', 'approved'];
if (!in_array($sort, $allowedsorts, true)) {
    $sort = 'id';
}
$dir = strtoupper($dir);
if (!in_array($dir, ['ASC', 'DESC'], true)) {
    $dir = 'DESC';
}

$editwordid = optional_param('editwordid', 0, PARAM_INT);

$notification = null;
$notificationtype = null;

if (optional_param('syncglossary', 0, PARAM_BOOL)) {
    require_sesskey();
    $imported = words_repository::sync_glossary_words($instance);
    $notification = get_string('glossarysynced', 'mod_playercross', $imported);
    $notificationtype = 'success';
}

if (optional_param('addword', 0, PARAM_BOOL)) {
    require_sesskey();

    $manualword = trim(required_param('manualword', PARAM_TEXT));
    $manualhint = trim(optional_param('manualhint', '', PARAM_TEXT));
    $wordlength = core_text::strlen($manualword);

    if ($manualword === '') {
        $notification = get_string('error_manualwordrequired', 'mod_playercross');
        $notificationtype = 'warning';
    } else if (!word_normalizer::is_valid_charset($manualword)) {
        $notification = get_string('error_manualwordinvalidchars', 'mod_playercross');
        $notificationtype = 'warning';
    } else if ($wordlength < (int)$instance->min_length || $wordlength > (int)$instance->max_length) {
        $notification = get_string(
            'error_manualwordlength',
            'mod_playercross',
            ['min' => (int)$instance->min_length, 'max' => (int)$instance->max_length]
        );
        $notificationtype = 'warning';
    } else if (words_repository::word_exists((int)$instance->id, $manualword)) {
        $notification = get_string('error_manualwordduplicate', 'mod_playercross');
        $notificationtype = 'warning';
    } else {
        words_repository::add_manual_word((int)$instance->id, (int)$USER->id, $manualword, $manualhint);
        $notification = get_string('manualwordadded', 'mod_playercross');
        $notificationtype = 'success';
    }
}

if (optional_param('saveword', 0, PARAM_BOOL)) {
    require_sesskey();

    $wordid    = required_param('wordid', PARAM_INT);
    $manualword = trim(required_param('manualword', PARAM_TEXT));
    $manualhint = trim(optional_param('manualhint', '', PARAM_TEXT));
    $wordlength = core_text::strlen($manualword);

    if ($manualword === '') {
        $notification = get_string('error_manualwordrequired', 'mod_playercross');
        $notificationtype = 'warning';
        $editwordid = $wordid;
    } else if (!word_normalizer::is_valid_charset($manualword)) {
        $notification = get_string('error_manualwordinvalidchars', 'mod_playercross');
        $notificationtype = 'warning';
        $editwordid = $wordid;
    } else if ($wordlength < (int)$instance->min_length || $wordlength > (int)$instance->max_length) {
        $notification = get_string(
            'error_manualwordlength',
            'mod_playercross',
            ['min' => (int)$instance->min_length, 'max' => (int)$instance->max_length]
        );
        $notificationtype = 'warning';
        $editwordid = $wordid;
    } else if (words_repository::word_exists((int)$instance->id, $manualword, $wordid)) {
        $notification = get_string('error_manualwordduplicate', 'mod_playercross');
        $notificationtype = 'warning';
        $editwordid = $wordid;
    } else {
        words_repository::update_word($wordid, (int)$instance->id, $manualword, $manualhint);
        $notification = get_string('wordupdated', 'mod_playercross');
        $notificationtype = 'success';
    }
}

if (optional_param('deleteword', 0, PARAM_BOOL)) {
    require_sesskey();
    $wordid = required_param('wordid', PARAM_INT);
    words_repository::delete_word($wordid, (int)$instance->id);
    $notification = get_string('worddeleted', 'mod_playercross');
    $notificationtype = 'success';
}

$bulkaction = optional_param('bulkaction', '', PARAM_ALPHA);
if ($bulkaction !== '') {
    require_sesskey();
    $wordids = optional_param_array('bulk_ids', [], PARAM_INT);
    $wordids = array_values(array_filter(array_map('intval', $wordids)));
    if ($bulkaction === 'delete') {
        words_repository::delete_words_bulk($wordids, (int)$instance->id);
        $notification = get_string('bulkdeleted', 'mod_playercross');
        $notificationtype = 'success';
    } else if ($bulkaction === 'approve') {
        words_repository::approve_words_bulk($wordids, (int)$instance->id);
        $notification = get_string('bulkapproved', 'mod_playercross');
        $notificationtype = 'success';
    }
}

if (optional_param('generateai', 0, PARAM_BOOL)) {
    require_sesskey();
    $topic = trim(optional_param('aitopic', '', PARAM_TEXT));
    $count = max(1, min(20, (int)optional_param('aicount', 10, PARAM_INT)));
    if ($topic !== '') {
        try {
            $saved = ai_word_generator::generate_and_save($instance, (int)$USER->id, $topic, $count, $context);
            if ($saved > 0) {
                $notification = get_string('aigeneratedsaved', 'mod_playercross', $saved);
                $notificationtype = 'success';
            } else {
                $notification = get_string('aigeneratednone', 'mod_playercross');
                $notificationtype = 'warning';
            }
        } catch (\moodle_exception $e) {
            $notification = get_string('aigenerateerror', 'mod_playercross');
            $notificationtype = 'error';
        }
    }
}

$recentwords = words_repository::get_recent_words((int)$instance->id, 0, $sort, $dir);
$fragmentedconcepts = words_repository::get_fragmented_concepts((int)$instance->id);
$drawcounts = words_repository::get_theme_draw_counts((int)$instance->id);

$editworddata = null;
if ($editwordid > 0) {
    $editworddata = words_repository::get_word_by_id($editwordid, (int)$instance->id);
    if (!$editworddata) {
        $editwordid = 0;
    }
}

$basepageurl = new moodle_url('/mod/playercross/managewords.php', ['id' => $cm->id]);

$sortcols = ['word', 'source', 'approved'];
$sorticons = [];
$sorturls  = [];
foreach ($sortcols as $col) {
    if ($sort === $col) {
        $newdir = ($dir === 'ASC') ? 'DESC' : 'ASC';
        $icon   = ($dir === 'ASC') ? 'fa-sort-up' : 'fa-sort-down';
    } else {
        $newdir = 'ASC';
        $icon   = 'fa-sort';
    }
    $sorticons[$col] = $icon;
    $colurl = clone $basepageurl;
    $colurl->param('sort', $col);
    $colurl->param('dir', $newdir);
    $sorturls[$col] = $colurl->out(false);
}

$templaterows = [];
foreach ($recentwords as $recentword) {
    $sourcelabel = get_string('source_' . $recentword->source, 'mod_playercross');
    if ($recentword->source === 'glossary' && !empty($recentword->glossaryname)) {
        $sourcelabel .= ' (' . format_string($recentword->glossaryname) . ')';
    }
    $statuslabel = ((int)$recentword->approved === 1) ?
        get_string('approvedstatus', 'mod_playercross') :
        get_string('pendingstatus', 'mod_playercross');

    $editurl = clone $basepageurl;
    $editurl->param('sort', $sort);
    $editurl->param('dir', $dir);
    $editurl->param('editwordid', $recentword->id);

    $isfragmentedconcept = $recentword->source === 'glossary'
        && !empty($recentword->concept)
        && in_array($recentword->concept, $fragmentedconcepts, true);

    $fragmentedconceptwarning = '';
    if ($isfragmentedconcept) {
        $fragmentedconceptwarning = get_string(
            'managewords_fragmentedconcept_warning',
            'mod_playercross',
            format_string($recentword->concept)
        );
    }

    $templaterows[] = [
        'id'                       => (int)$recentword->id,
        'word'                     => $recentword->word,
        'source'                   => $sourcelabel,
        'approved'                 => $statuslabel,
        'ispending'                => ((int)$recentword->approved !== 1),
        'editwordurl'              => $editurl->out(false),
        'isfragmentedconcept'      => $isfragmentedconcept,
        'fragmentedconceptwarning' => $fragmentedconceptwarning,
        'drawcount'                => $drawcounts[(int)$recentword->id] ?? 0,
    ];
}

$cancelediteurl = clone $basepageurl;
$cancelediteurl->param('sort', $sort);
$cancelediteurl->param('dir', $dir);

$cansyncglossary = ((int)$instance->sources & PLAYERCROSS_SOURCE_GLOSSARY) !== 0;

$PAGE->set_url('/mod/playercross/managewords.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('managewordslabel', 'mod_playercross'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->requires->js_call_amd('mod_playercross/managewords', 'init');

$templatecontext = [
    'cmid'                   => $cm->id,
    'sesskey'                => sesskey(),
    'backtogameurl'          => (new moodle_url('/mod/playercross/view.php', ['id' => $cm->id]))->out(false),
    'backtogamebutton'       => get_string('backtogamebutton', 'mod_playercross'),
    'cansyncglossary'        => $cansyncglossary,
    'syncglossarybutton'     => get_string('syncglossarybutton', 'mod_playercross'),
    'managewordslabel'       => get_string('managewordslabel', 'mod_playercross'),
    'manualwordlabel'        => get_string('manualwordlabel', 'mod_playercross'),
    'manualhintlabel'        => get_string('manualhintlabel', 'mod_playercross'),
    'manualwordplaceholder'  => get_string('manualwordplaceholder', 'mod_playercross'),
    'manualhintplaceholder'  => get_string('manualhintplaceholder', 'mod_playercross'),
    'addwordbutton'          => get_string('addwordbutton', 'mod_playercross'),
    'hasai'                  => ai_word_generator::has_key($context),
    'aigeneratetitle'        => get_string('aigeneratetitle', 'mod_playercross'),
    'aigeneratetopic'        => get_string('aigeneratetopic', 'mod_playercross'),
    'aigeneratecount'        => get_string('aigeneratecount', 'mod_playercross'),
    'aigeneratebutton'       => get_string('aigeneratebutton', 'mod_playercross'),
    'recentwordslabel'       => get_string('recentwordslabel', 'mod_playercross'),
    'nowordsyet'             => get_string('nowordsyet', 'mod_playercross'),
    'wordcolumnlabel'        => get_string('wordcolumnlabel', 'mod_playercross'),
    'sourcecolumnlabel'      => get_string('sourcecolumnlabel', 'mod_playercross'),
    'statuscolumnlabel'      => get_string('statuscolumnlabel', 'mod_playercross'),
    'drawcountcolumnlabel'   => get_string('drawcountcolumnlabel', 'mod_playercross'),
    'actionscolumnlabel'     => get_string('actionscolumnlabel', 'mod_playercross'),
    'deletewordbutton'       => get_string('deletewordbutton', 'mod_playercross'),
    'deletewordconfirm'      => get_string('deletewordconfirm', 'mod_playercross'),
    'deletewordtitle'        => get_string('deletewordtitle', 'mod_playercross'),
    'bulkapprovebutton'      => get_string('bulkapprovebutton', 'mod_playercross'),
    'bulkapprovebuttontitle' => get_string('bulkapprovebuttontitle', 'mod_playercross'),
    'bulkapproveconfirm'     => get_string('bulkapproveconfirm', 'mod_playercross'),
    'bulkdeletebutton'       => get_string('bulkdeletebutton', 'mod_playercross'),
    'bulkdeleteconfirm'      => get_string('bulkdeleteconfirm', 'mod_playercross'),
    'editwordbutton'         => get_string('editwordbutton', 'mod_playercross'),
    'editwordlabel'          => get_string('editwordlabel', 'mod_playercross'),
    'savewordbutton'         => get_string('savewordbutton', 'mod_playercross'),
    'cancelbutton'           => get_string('cancelbutton', 'mod_playercross'),
    'selectall'              => get_string('selectall', 'mod_playercross'),
    'selectword'             => get_string('selectword', 'mod_playercross'),
    'currentsort'            => $sort,
    'currentdir'             => $dir,
    'sort_word_url'          => $sorturls['word'],
    'sort_word_icon'         => $sorticons['word'],
    'sort_source_url'        => $sorturls['source'],
    'sort_source_icon'       => $sorticons['source'],
    'sort_approved_url'      => $sorturls['approved'],
    'sort_approved_icon'     => $sorticons['approved'],
    'recentwords'            => $templaterows,
    'hasrecentwords'         => !empty($templaterows),
    'haseditword'            => $editwordid > 0 && $editworddata !== null,
    'editword_id'            => $editworddata ? (int)$editworddata->id : 0,
    'editword_word'          => $editworddata ? $editworddata->word : '',
    'editword_hint'          => $editworddata ? ($editworddata->hint ?? '') : '',
    'cancelediteurl'         => $cancelediteurl->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name, true, ['context' => $context]));

if (!empty($notification)) {
    echo $OUTPUT->notification($notification, $notificationtype);
}
echo $OUTPUT->render_from_template('mod_playercross/managewords', $templatecontext);
echo $OUTPUT->footer();
