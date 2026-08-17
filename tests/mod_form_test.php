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
 * Unit tests for mod_playercross_mod_form's field-freezing behaviour.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross;

/**
 * Tests for mod_playercross_mod_form.
 *
 * @covers \mod_playercross_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Instantiates mod_playercross_mod_form for an existing instance and runs
     * definition_after_data() on it, returning the underlying MoodleQuickForm so the
     * test can inspect frozen state via isElementFrozen().
     *
     * @param \stdClass $instance Activity instance record.
     * @param \stdClass $cm Course module record.
     * @return \MoodleQuickForm
     */
    private function build_form_after_data(\stdClass $instance, \stdClass $cm): \MoodleQuickForm {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/mod/playercross/mod_form.php');

        $PAGE->set_course($this->course);

        $data = (object)[
            'instance' => $instance->id,
            'id'       => $cm->id,
            'course'   => $this->course->id,
        ];

        $form = new \mod_playercross_mod_form($data, 0, $cm, $this->course);
        $form->definition_after_data();

        $refclass = new \ReflectionClass(\mod_playercross_mod_form::class);
        $formprop = $refclass->getProperty('_form');
        $formprop->setAccessible(true);

        return $formprop->getValue($form);
    }

    /**
     * rankingscoringmode freezes the moment the activity has any finished attempt at
     * all — even ungraded (grade=0), the exact scenario the ranking/grade decoupling
     * fix is about, since ranking points are computed and stored regardless.
     *
     * @return void
     */
    public function test_rankingscoringmode_freezes_once_an_attempt_exists_even_when_ungraded(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $generator->create_instance(['course' => $this->course->id, 'grade' => 0]);
        $cm = get_coursemodule_from_instance('playercross', $instance->id);

        $now = time();
        $DB->insert_record('playercross_attempts', (object)[
            'playercrossid' => $instance->id,
            'userid'        => 2,
            'themewordid'   => 0,
            'termstotal'    => 0,
            'termsresolved' => 0,
            'finalguessed'  => 0,
            'attempts_used' => 0,
            'time_used'     => 0,
            'completed'     => 1,
            'score'         => 0,
            'rankingpoints' => 100,
            'timecreated'   => $now,
            'timefinished'  => $now,
        ]);

        $mform = $this->build_form_after_data($instance, $cm);

        $this->assertTrue($mform->isElementFrozen('rankingscoringmode'));
    }

    /**
     * A still-open reservation (round started but not yet finished, timefinished=0)
     * carries no real ranking outcome yet and must not freeze rankingscoringmode —
     * only a genuinely finished round does.
     *
     * @return void
     */
    public function test_rankingscoringmode_not_frozen_by_a_pending_reservation(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $generator->create_instance(['course' => $this->course->id, 'grade' => 0]);
        $cm = get_coursemodule_from_instance('playercross', $instance->id);

        $DB->insert_record('playercross_attempts', (object)[
            'playercrossid' => $instance->id,
            'userid'        => 2,
            'themewordid'   => 0,
            'termstotal'    => 0,
            'termsresolved' => 0,
            'finalguessed'  => 0,
            'attempts_used' => 0,
            'time_used'     => 0,
            'completed'     => 0,
            'score'         => 0,
            'rankingpoints' => 0,
            'timecreated'   => time(),
            'timefinished'  => 0,
        ]);

        $mform = $this->build_form_after_data($instance, $cm);

        $this->assertFalse($mform->isElementFrozen('rankingscoringmode'));
    }

    /**
     * A fresh activity with zero attempts leaves rankingscoringmode editable.
     *
     * @return void
     */
    public function test_rankingscoringmode_not_frozen_for_fresh_activity_with_no_attempts(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $generator->create_instance(['course' => $this->course->id, 'grade' => 0]);
        $cm = get_coursemodule_from_instance('playercross', $instance->id);

        $mform = $this->build_form_after_data($instance, $cm);

        $this->assertFalse($mform->isElementFrozen('rankingscoringmode'));
    }

    /**
     * Regression guard: splitting rankingscoringmode into its own freeze trigger must
     * not disturb gradescoringmode's own, unchanged, has_grades()-gated freeze — it
     * still only locks once the activity's grade item has real grades, not merely
     * because an attempt row exists.
     *
     * @return void
     */
    public function test_gradescoringmode_freeze_unaffected_by_ranking_freeze_split(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $generator->create_instance(['course' => $this->course->id, 'grade' => 0]);
        $cm = get_coursemodule_from_instance('playercross', $instance->id);

        $now = time();
        $DB->insert_record('playercross_attempts', (object)[
            'playercrossid' => $instance->id,
            'userid'        => 2,
            'themewordid'   => 0,
            'termstotal'    => 0,
            'termsresolved' => 0,
            'finalguessed'  => 0,
            'attempts_used' => 0,
            'time_used'     => 0,
            'completed'     => 1,
            'score'         => 0,
            'rankingpoints' => 100,
            'timecreated'   => $now,
            'timefinished'  => $now,
        ]);

        $mform = $this->build_form_after_data($instance, $cm);

        // An attempt exists, but no real grade item — gradescoringmode stays editable.
        $this->assertFalse($mform->isElementFrozen('gradescoringmode'));
        $this->assertTrue($mform->isElementFrozen('rankingscoringmode'));
    }
}
