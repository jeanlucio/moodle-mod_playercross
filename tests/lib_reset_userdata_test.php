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
 * Tests for the course reset hooks in lib.php.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross;

/**
 * Tests for playercross_reset_userdata() and its course reset form hooks.
 *
 * @covers ::playercross_reset_userdata
 */
final class lib_reset_userdata_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
    }

    /**
     * Deletes attempts and resets grades when the checkbox is enabled.
     *
     * @return void
     */
    public function test_reset_userdata_deletes_attempts_when_enabled(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $modgenerator->create_instance(['course' => $course->id]);
        $theme = $modgenerator->create_word($instance->id, 'escola');
        $modgenerator->create_attempt($instance->id, $user->id, $theme->id);

        $data = (object)[
            'courseid'                   => $course->id,
            'reset_playercross_attempts' => 1,
        ];
        $status = playercross_reset_userdata($data);

        $this->assertSame(0, $DB->count_records('playercross_attempts', ['playercrossid' => $instance->id]));
        $this->assertCount(1, $status);
        $this->assertFalse($status[0]['error']);
    }

    /**
     * Leaves attempts untouched when the checkbox is not enabled.
     *
     * @return void
     */
    public function test_reset_userdata_keeps_attempts_when_disabled(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instance = $modgenerator->create_instance(['course' => $course->id]);
        $theme = $modgenerator->create_word($instance->id, 'escola');
        $modgenerator->create_attempt($instance->id, $user->id, $theme->id);

        $data = (object)['courseid' => $course->id];
        $status = playercross_reset_userdata($data);

        $this->assertSame(1, $DB->count_records('playercross_attempts', ['playercrossid' => $instance->id]));
        $this->assertSame([], $status);
    }

    /**
     * Never touches attempts of a playercross instance in a different course.
     *
     * @return void
     */
    public function test_reset_userdata_does_not_touch_other_courses(): void {
        global $DB;

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $instancea = $modgenerator->create_instance(['course' => $coursea->id]);
        $instanceb = $modgenerator->create_instance(['course' => $courseb->id]);

        foreach ([$instancea, $instanceb] as $instance) {
            $theme = $modgenerator->create_word($instance->id, 'escola');
            $modgenerator->create_attempt($instance->id, $user->id, $theme->id);
        }

        $data = (object)[
            'courseid'                   => $coursea->id,
            'reset_playercross_attempts' => 1,
        ];
        playercross_reset_userdata($data);

        $this->assertSame(0, $DB->count_records('playercross_attempts', ['playercrossid' => $instancea->id]));
        $this->assertSame(1, $DB->count_records('playercross_attempts', ['playercrossid' => $instanceb->id]));
    }

    /**
     * The reset form defaults enable the checkbox by default.
     *
     * @return void
     */
    public function test_reset_course_form_defaults_enables_checkbox(): void {
        $course = $this->getDataGenerator()->create_course();

        $defaults = playercross_reset_course_form_defaults($course);

        $this->assertSame(['reset_playercross_attempts' => 1], $defaults);
    }
}
