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
 * Form definition for mod_playercross.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../../course/moodleform_mod.php');
require_once(__DIR__ . '/lib.php');

/**
 * Activity settings form for PlayerCross.
 */
class mod_playercross_mod_form extends moodleform_mod {
    /**
     * Source type bit flag for manual words.
     */
    private const SOURCE_MANUAL = 1;

    /**
     * Source type bit flag for glossary words.
     */
    private const SOURCE_GLOSSARY = 2;

    /**
     * Defines forms elements.
     *
     * @return void
     */
    public function definition(): void {
        global $CFG, $COURSE, $DB, $PAGE;

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'playersourcesheader', get_string('playersourcesheader', 'mod_playercross'));
        $mform->setExpanded('playersourcesheader');

        $mform->addElement(
            'advcheckbox',
            'source_manual',
            get_string('source_manual', 'mod_playercross')
        );
        $mform->setType('source_manual', PARAM_INT);
        $mform->setDefault('source_manual', 1);

        $mform->addElement(
            'advcheckbox',
            'source_glossary',
            get_string('source_glossary', 'mod_playercross')
        );
        $mform->setType('source_glossary', PARAM_INT);
        $mform->setDefault('source_glossary', 0);

        $glossaryoptions = [0 => get_string('glossaryid_all', 'mod_playercross')];
        $courseglossaries = $DB->get_records('glossary', ['course' => $COURSE->id], 'name ASC', 'id, name');
        foreach ($courseglossaries as $glossary) {
            $glossaryoptions[$glossary->id] = format_string($glossary->name);
        }
        $mform->addElement(
            'select',
            'glossaryid',
            get_string('glossaryid', 'mod_playercross'),
            $glossaryoptions
        );
        $mform->setType('glossaryid', PARAM_INT);
        $mform->setDefault('glossaryid', 0);
        $mform->hideIf('glossaryid', 'source_glossary', 'notchecked');

        $mform->addElement('textarea', 'stopwords', get_string('stopwords', 'mod_playercross'), ['rows' => 3]);
        $mform->setType('stopwords', PARAM_TEXT);
        $mform->addHelpButton('stopwords', 'stopwords', 'mod_playercross');
        $mform->hideIf('stopwords', 'source_glossary', 'notchecked');

        $mform->addElement('header', 'gameplayheader', get_string('gameplayheader', 'mod_playercross'));
        $mform->setExpanded('gameplayheader');

        $mform->addElement(
            'select',
            'wordmode',
            get_string('wordmode', 'mod_playercross'),
            [
                PLAYERCROSS_WORDMODE_RANDOM => get_string('wordmode_random', 'mod_playercross'),
                PLAYERCROSS_WORDMODE_SHARED => get_string('wordmode_shared', 'mod_playercross'),
            ]
        );
        $mform->setType('wordmode', PARAM_INT);
        $mform->setDefault('wordmode', PLAYERCROSS_WORDMODE_RANDOM);

        $numcluesoptions = [];
        for ($i = 3; $i <= 10; $i++) {
            $numcluesoptions[$i] = $i;
        }
        $mform->addElement('select', 'num_clues', get_string('num_clues', 'mod_playercross'), $numcluesoptions);
        $mform->setType('num_clues', PARAM_INT);
        $mform->setDefault('num_clues', 5);

        $mform->addElement(
            'select',
            'win_condition',
            get_string('win_condition', 'mod_playercross'),
            playercross_get_wincondition_options()
        );
        $mform->setType('win_condition', PARAM_INT);
        $mform->setDefault('win_condition', PLAYERCROSS_WINCONDITION_BOTH);
        $mform->addHelpButton('win_condition', 'win_condition', 'mod_playercross');

        $mform->addElement(
            'advcheckbox',
            'reveal_uncovered_slots',
            get_string('reveal_uncovered_slots', 'mod_playercross')
        );
        $mform->setType('reveal_uncovered_slots', PARAM_INT);
        $mform->setDefault('reveal_uncovered_slots', 1);
        $mform->addHelpButton('reveal_uncovered_slots', 'reveal_uncovered_slots', 'mod_playercross');

        $mform->addElement('text', 'theme_min_length', get_string('theme_min_length', 'mod_playercross'));
        $mform->setType('theme_min_length', PARAM_INT);
        $mform->setDefault('theme_min_length', 6);
        $mform->addRule('theme_min_length', null, 'numeric', null, 'client');
        $mform->addHelpButton('theme_min_length', 'theme_min_length', 'mod_playercross');

        $mform->addElement('text', 'min_length', get_string('min_length', 'mod_playercross'));
        $mform->setType('min_length', PARAM_INT);
        $mform->setDefault('min_length', 3);
        $mform->addRule('min_length', null, 'numeric', null, 'client');

        $mform->addElement('text', 'max_length', get_string('max_length', 'mod_playercross'));
        $mform->setType('max_length', PARAM_INT);
        $mform->setDefault('max_length', 15);
        $mform->addRule('max_length', null, 'numeric', null, 'client');

        // Only meaningful once the activity already has a pool to count — on first
        // creation there is no instance id yet and no words have been imported/added.
        if (!empty($this->_instance)) {
            $mform->addElement(
                'static',
                'eligiblewordscount',
                '',
                html_writer::div('', 'alert alert-info py-2 mb-2', [
                    'id'        => 'playercross-eligible-count',
                    'aria-live' => 'polite',
                ])
            );
        } else {
            // No pool exists yet on first creation, but a glossary source already
            // does — preview how many of its entries would fit the configured
            // range. Read-only: nothing is written until the form is actually
            // saved, at which point the real sync creates the pool for real.
            $mform->addElement(
                'static',
                'glossarywordscount',
                '',
                html_writer::div('', 'alert alert-info py-2 mb-2', [
                    'id'        => 'playercross-glossary-preview-count',
                    'aria-live' => 'polite',
                ])
            );
        }

        $mform->addElement(
            'text',
            'max_attempts_per_clue',
            get_string('max_attempts_per_clue', 'mod_playercross')
        );
        $mform->setType('max_attempts_per_clue', PARAM_INT);
        $mform->setDefault('max_attempts_per_clue', 0);
        $mform->addRule('max_attempts_per_clue', null, 'numeric', null, 'client');
        $mform->addHelpButton('max_attempts_per_clue', 'max_attempts_per_clue', 'mod_playercross');

        $mform->addElement('text', 'timer_minutes', get_string('timer_minutes', 'mod_playercross'));
        $mform->setType('timer_minutes', PARAM_INT);
        $mform->setDefault('timer_minutes', 0);
        $mform->addRule('timer_minutes', null, 'numeric', null, 'client');

        $mform->addElement(
            'select',
            'show_ranking',
            get_string('show_ranking', 'mod_playercross'),
            [0 => get_string('no'), 1 => get_string('yes')]
        );
        $mform->setType('show_ranking', PARAM_INT);
        $mform->setDefault('show_ranking', 1);

        $maxroundsoptions = [0 => get_string('max_rounds_unlimited', 'mod_playercross')];
        for ($i = 1; $i <= 10; $i++) {
            $maxroundsoptions[$i] = $i;
        }
        $mform->addElement('select', 'max_rounds', get_string('max_rounds', 'mod_playercross'), $maxroundsoptions);
        $mform->setType('max_rounds', PARAM_INT);
        $mform->setDefault('max_rounds', 0);

        $cooldowngroup = [];
        $cooldowngroup[] = $mform->createElement('text', 'cooldown_amount', '', ['size' => 5]);
        $cooldowngroup[] = $mform->createElement(
            'select',
            'cooldown_unit',
            '',
            [
                'minutes' => get_string('cooldown_unit_minutes', 'mod_playercross'),
                'hours'   => get_string('cooldown_unit_hours', 'mod_playercross'),
                'days'    => get_string('cooldown_unit_days', 'mod_playercross'),
            ]
        );
        $mform->addGroup(
            $cooldowngroup,
            'cooldowngroup',
            get_string('cooldown_label', 'mod_playercross'),
            [' '],
            false
        );
        $mform->setType('cooldown_amount', PARAM_INT);
        $mform->setType('cooldown_unit', PARAM_ALPHA);
        $mform->setDefault('cooldown_amount', 1);
        $mform->setDefault('cooldown_unit', 'days');

        // PlayerHUD integration — only rendered when block_playerhud exists in this course.
        $hudblockid = null;
        if (\mod_playercross\local\hud_service::is_available_for_course((int)$COURSE->id)) {
            $hudblockid = \mod_playercross\local\hud_service::get_block_instance_id($COURSE->id);
        }

        if ($hudblockid !== null) {
            $huditems = \mod_playercross\local\hud_service::get_items_for_block($hudblockid);
            $itemoptions = [0 => get_string('hud_noitem', 'mod_playercross')];
            foreach ($huditems as $item) {
                $itemoptions[$item->id] = format_string($item->name);
            }

            $mform->addElement('header', 'hudheader', get_string('hud_header', 'mod_playercross'));

            $mform->addElement(
                'select',
                'hud_round_cost_item',
                get_string('hud_round_cost_item', 'mod_playercross'),
                $this->add_stale_hud_item_option($itemoptions, $hudblockid, 'hud_round_cost_item')
            );
            $mform->setType('hud_round_cost_item', PARAM_INT);
            $mform->setDefault('hud_round_cost_item', 0);

            $mform->addElement('text', 'hud_round_cost_qty', get_string('hud_round_cost_qty', 'mod_playercross'));
            $mform->setType('hud_round_cost_qty', PARAM_INT);
            $mform->setDefault('hud_round_cost_qty', 1);
            $mform->addRule('hud_round_cost_qty', null, 'numeric', null, 'client');
            $mform->hideIf('hud_round_cost_qty', 'hud_round_cost_item', 'eq', 0);

            $mform->addElement(
                'select',
                'hud_hint_cost_item',
                get_string('hud_hint_cost_item', 'mod_playercross'),
                $this->add_stale_hud_item_option($itemoptions, $hudblockid, 'hud_hint_cost_item')
            );
            $mform->setType('hud_hint_cost_item', PARAM_INT);
            $mform->setDefault('hud_hint_cost_item', 0);

            $mform->addElement('text', 'hud_hint_cost_qty', get_string('hud_hint_cost_qty', 'mod_playercross'));
            $mform->setType('hud_hint_cost_qty', PARAM_INT);
            $mform->setDefault('hud_hint_cost_qty', 1);
            $mform->addRule('hud_hint_cost_qty', null, 'numeric', null, 'client');
            $mform->hideIf('hud_hint_cost_qty', 'hud_hint_cost_item', 'eq', 0);

            $mform->addElement(
                'select',
                'hud_win_reward_item',
                get_string('hud_win_reward_item', 'mod_playercross'),
                $this->add_stale_hud_item_option($itemoptions, $hudblockid, 'hud_win_reward_item')
            );
            $mform->setType('hud_win_reward_item', PARAM_INT);
            $mform->setDefault('hud_win_reward_item', 0);
            $mform->addHelpButton('hud_win_reward_item', 'hud_win_reward_item', 'mod_playercross');

            $mform->addElement('text', 'hud_win_reward_qty', get_string('hud_win_reward_qty', 'mod_playercross'));
            $mform->setType('hud_win_reward_qty', PARAM_INT);
            $mform->setDefault('hud_win_reward_qty', 1);
            $mform->addRule('hud_win_reward_qty', null, 'numeric', null, 'client');
            $mform->hideIf('hud_win_reward_qty', 'hud_win_reward_item', 'eq', 0);
        } else if (\mod_playercross\local\hud_service::is_installed()) {
            // The block plugin is installed on this site, but no instance was added to this
            // course yet — tell the teacher the option exists instead of leaving it silently
            // absent.
            $mform->addElement('header', 'hudheader', get_string('hud_header', 'mod_playercross'));
            $mform->addElement(
                'static',
                'hudnotincourse',
                '',
                html_writer::div(get_string('hud_notincourse', 'mod_playercross'), 'alert alert-info py-2 mb-0')
            );
        }

        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 0);

        $mform->addElement(
            'select',
            'grademethod',
            get_string('grademethod', 'mod_playercross'),
            playercross_get_grademethod_options()
        );
        $mform->setType('grademethod', PARAM_INT);
        $mform->setDefault('grademethod', PLAYERCROSS_GRADE_HIGHEST);
        $mform->addHelpButton('grademethod', 'grademethod', 'mod_playercross');
        $mform->hideIf('grademethod', 'grade[modgrade_type]', 'eq', 'none');

        $PAGE->requires->js_call_amd('mod_playercross/grademethod', 'init', [
            'id_max_rounds',
            'id_grademethod',
            (string) PLAYERCROSS_GRADE_AVERAGE_ALL,
            (string) PLAYERCROSS_GRADE_HIGHEST,
        ]);

        if (!empty($this->_instance)) {
            $PAGE->requires->js_call_amd('mod_playercross/eligiblewords', 'init', [
                (int) $this->_cm->id,
                'id_min_length',
                'id_max_length',
                'playercross-eligible-count',
            ]);
        } else {
            $PAGE->requires->js_call_amd('mod_playercross/glossarypreview', 'init', [
                (int) $COURSE->id,
                'id_source_glossary',
                'id_glossaryid',
                'id_min_length',
                'id_max_length',
                'id_stopwords',
                'playercross-glossary-preview-count',
            ]);
        }

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Freezes settings that feed the puzzle's point scale once the activity has
     * recorded a real grade for any student.
     *
     * num_clues and grademethod are baked into every already-scored round at the
     * moment it finishes. Changing either afterwards would make past and future
     * rounds count on different scales, both for the grade and for the ranking
     * total. This mirrors the same condition core already uses to freeze the
     * "Maximum grade" field itself — the modgrade element in lib/form/modgrade.php
     * disables it once $gradeitem->has_grades() is true.
     *
     * @return void
     */
    #[\Override]
    public function definition_after_data(): void {
        parent::definition_after_data();

        if (empty($this->_instance)) {
            return;
        }

        global $COURSE;
        $gradeitem = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'playercross',
            'iteminstance' => $this->_instance,
            'itemnumber'   => 0,
            'courseid'     => $COURSE->id,
        ]);

        if (!$gradeitem || !$gradeitem->has_grades()) {
            return;
        }

        $mform = $this->_form;
        $lockedfields = ['num_clues', 'grademethod'];
        $mform->freeze($lockedfields);

        $warninghtml = html_writer::div(get_string('scoringmode_locked', 'mod_playercross'), 'alert alert-warning');
        $mform->insertElementBefore(
            $mform->createElement('static', 'scoringmodelockedmsg', '', $warninghtml),
            'num_clues'
        );
    }

    /**
     * Adds the currently stored item as an extra select option when it fell out of the
     * enabled-items list (disabled or deleted after being configured).
     *
     * Without this, saving the form for any unrelated reason would silently wipe the field
     * back to "no item" the moment the browser submits whatever option happens to render as
     * selected, since a <select> with no matching option cannot preserve the real value.
     *
     * @param array $options Base options (enabled items only), keyed by item id.
     * @param int $blockinstanceid Block instance ID the stored value must belong to.
     * @param string $field Field name to read the stored value from $this->current.
     * @return array
     */
    private function add_stale_hud_item_option(array $options, int $blockinstanceid, string $field): array {
        $storedid = (int)($this->current->{$field} ?? 0);
        if ($storedid <= 0 || isset($options[$storedid])) {
            return $options;
        }

        $itemname = \mod_playercross\local\hud_service::get_item_name($blockinstanceid, $storedid);
        $options[$storedid] = ($itemname !== '')
            ? get_string('hud_item_disabled', 'mod_playercross', $itemname)
            : get_string('hud_item_deleted', 'mod_playercross');

        return $options;
    }

    /**
     * Custom validation for PlayerCross settings.
     *
     * @param array $data Form data.
     * @param array $files Submitted files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $sources = 0;
        if (!empty($data['source_manual'])) {
            $sources |= self::SOURCE_MANUAL;
        }
        if (!empty($data['source_glossary'])) {
            $sources |= self::SOURCE_GLOSSARY;
        }
        if ($sources === 0) {
            $errors['source_manual'] = get_string('error_atleastonesource', 'mod_playercross');
        }

        if ((int)$data['theme_min_length'] < 1) {
            $errors['theme_min_length'] = get_string('error_thememinlength', 'mod_playercross');
        }

        if ((int)$data['min_length'] < 1) {
            $errors['min_length'] = get_string('error_minlength', 'mod_playercross');
        }

        if ((int)$data['max_length'] < (int)$data['min_length']) {
            $errors['max_length'] = get_string('error_maxlength', 'mod_playercross');
        }

        if ((int)$data['max_attempts_per_clue'] < 0) {
            $errors['max_attempts_per_clue'] = get_string('error_maxattemptsperclue', 'mod_playercross');
        }

        if ((int)$data['timer_minutes'] < 0) {
            $errors['timer_minutes'] = get_string('error_timerseconds', 'mod_playercross');
        }

        if (!empty($data['hud_round_cost_item']) && (int)$data['hud_round_cost_qty'] < 1) {
            $errors['hud_round_cost_qty'] = get_string('error_hud_cost_qty', 'mod_playercross');
        }

        if (!empty($data['hud_hint_cost_item']) && (int)$data['hud_hint_cost_qty'] < 1) {
            $errors['hud_hint_cost_qty'] = get_string('error_hud_cost_qty', 'mod_playercross');
        }

        if (!empty($data['hud_win_reward_item']) && (int)$data['hud_win_reward_qty'] < 1) {
            $errors['hud_win_reward_qty'] = get_string('error_hud_cost_qty', 'mod_playercross');
        }

        if ((int)$data['cooldown_amount'] < 0) {
            $errors['cooldowngroup'] = get_string('error_cooldown', 'mod_playercross');
        }

        if (
            !empty($data['completionroundsenabled']) &&
            ((int)$data['completionrounds'] < 1)
        ) {
            $errors['completionroundsgroup'] = get_string('error_completionrounds', 'mod_playercross');
        }

        // Irrelevant, and must not block saving, once grading is off: grademethod has no
        // effect at all in that state, and this field can end up disabled and unreadable by
        // the teacher once real grades already exist (Moodle core locks the grade type
        // then), so its stale stored value must never veto an otherwise valid change.
        if (
            (float)$data['grade'] > 0 &&
            (int)$data['grademethod'] === PLAYERCROSS_GRADE_AVERAGE_ALL &&
            (int)$data['max_rounds'] === 0
        ) {
            $errors['grademethod'] = get_string('error_grademethod_average_all', 'mod_playercross');
        }

        return $errors;
    }

    /**
     * Adds custom completion rules to Moodle completion section.
     *
     * @return array
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;

        $group = [];
        $group[] = $mform->createElement('checkbox', 'completionroundsenabled', '', '');
        $group[] = $mform->createElement('text', 'completionrounds', '', ['size' => 3]);
        $mform->addGroup(
            $group,
            'completionroundsgroup',
            get_string('completionroundsgroup', 'mod_playercross'),
            [' '],
            false
        );

        $mform->setType('completionrounds', PARAM_INT);
        $mform->setDefault('completionrounds', 1);
        $mform->disabledIf('completionrounds', 'completionroundsenabled', 'notchecked');

        return ['completionroundsgroup'];
    }

    /**
     * Returns whether at least one completion rule is enabled.
     *
     * @param array $data Form data.
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        return !empty($data['completionroundsenabled']) && (int)$data['completionrounds'] > 0;
    }

    /**
     * Normalises form data before saving.
     *
     * @param array $defaultvalues Default form values.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues): void {
        parent::data_preprocessing($defaultvalues);

        if (isset($defaultvalues['grade']) && (float)$defaultvalues['grade'] > 0) {
            $defaultvalues['grade'] = (int)round((float)$defaultvalues['grade']);
        }

        if (!empty($defaultvalues['sources'])) {
            $defaultvalues['source_manual'] = (int)(($defaultvalues['sources'] & self::SOURCE_MANUAL) !== 0);
            $defaultvalues['source_glossary'] = (int)(($defaultvalues['sources'] & self::SOURCE_GLOSSARY) !== 0);
        }

        if (!empty($defaultvalues['completionrounds'])) {
            $defaultvalues['completionroundsenabled'] = 1;
        }

        if (isset($defaultvalues['cooldown_seconds'])) {
            $seconds = (int)$defaultvalues['cooldown_seconds'];
            if ($seconds === 0) {
                $defaultvalues['cooldown_amount'] = 0;
                $defaultvalues['cooldown_unit']   = 'minutes';
            } else if ($seconds % 86400 === 0) {
                $defaultvalues['cooldown_amount'] = $seconds / 86400;
                $defaultvalues['cooldown_unit']   = 'days';
            } else if ($seconds % 3600 === 0) {
                $defaultvalues['cooldown_amount'] = $seconds / 3600;
                $defaultvalues['cooldown_unit']   = 'hours';
            } else {
                $defaultvalues['cooldown_amount'] = max(1, (int) round($seconds / 60));
                $defaultvalues['cooldown_unit']   = 'minutes';
            }
        }

        if (isset($defaultvalues['timer_seconds'])) {
            $defaultvalues['timer_minutes'] = (int)round((int)$defaultvalues['timer_seconds'] / 60);
        }
    }
}
