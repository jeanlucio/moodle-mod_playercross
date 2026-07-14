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
 * External function tests for count_eligible_words.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use core_external\external_api;

/**
 * Tests for the mod_playercross_count_eligible_words web service.
 */
final class count_eligible_words_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Teacher with mod/playercross:addinstance. */
    private \stdClass $teacher;

    /** @var \stdClass Student without mod/playercross:addinstance. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Creates a playercross instance and returns it with a ->cmid field added.
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass
     */
    private function make_instance(array $overrides = []): \stdClass {
        global $DB;

        $record = array_merge(['course' => $this->course->id], $overrides);
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_playercross')->create_instance($record);
        $record = $DB->get_record('playercross', ['id' => $instance->id], '*', MUST_EXIST);
        $record->cmid = $instance->cmid;
        return $record;
    }

    /**
     * Inserts one word directly into an instance's pool.
     *
     * @param int $instanceid Activity instance id.
     * @param string $word Word text.
     * @param int $approved Approval status (1 = approved, 0 = pending).
     * @return void
     */
    private function make_word(int $instanceid, string $word, int $approved = 1): void {
        global $DB;
        $DB->insert_record('playercross_words', (object)[
            'playercrossid' => $instanceid,
            'word'          => $word,
            'concept'       => $word,
            'hint'          => '',
            'source'        => 'manual',
            'glossaryid'    => 0,
            'approved'      => $approved,
            'timecreated'   => time(),
            'timemodified'  => time(),
            'addedby'       => $this->teacher->id,
        ]);
    }

    /**
     * Calls the mod_playercross_count_eligible_words web service through the real
     * dispatch path.
     *
     * @param int $cmid Course module id.
     * @param int $minlength Candidate minimum word length.
     * @param int $maxlength Candidate maximum word length.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_count(int $cmid, int $minlength, int $maxlength): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playercross_count_eligible_words', [
            'cmid'      => $cmid,
            'minlength' => $minlength,
            'maxlength' => $maxlength,
        ]);
    }

    /**
     * Counts only approved words whose length falls within the given range.
     *
     * @covers \mod_playercross\external\count_eligible_words::execute
     * @return void
     */
    public function test_counts_approved_words_within_range(): void {
        $instance = $this->make_instance();
        $this->make_word($instance->id, 'boca');
        $this->make_word($instance->id, 'casa');
        $this->make_word($instance->id, 'planeta');

        $this->setUser($this->teacher);
        $response = $this->call_count($instance->cmid, 4, 4);

        $this->assertFalse($response['error']);
        $this->assertSame(2, $response['data']['count']);
    }

    /**
     * Pending (unapproved) words are never counted, regardless of length.
     *
     * @covers \mod_playercross\external\count_eligible_words::execute
     * @return void
     */
    public function test_excludes_unapproved_words(): void {
        $instance = $this->make_instance();
        $this->make_word($instance->id, 'boca', 0);

        $this->setUser($this->teacher);
        $response = $this->call_count($instance->cmid, 4, 4);

        $this->assertSame(0, $response['data']['count']);
    }

    /**
     * A word outside the requested length range is excluded from the count.
     *
     * @covers \mod_playercross\external\count_eligible_words::execute
     * @return void
     */
    public function test_excludes_words_outside_range(): void {
        $instance = $this->make_instance();
        $this->make_word($instance->id, 'boca');

        $this->setUser($this->teacher);
        $response = $this->call_count($instance->cmid, 5, 8);

        $this->assertSame(0, $response['data']['count']);
    }

    /**
     * The count is scoped to its own activity instance — a matching word in another
     * instance must never leak into this one's count.
     *
     * @covers \mod_playercross\external\count_eligible_words::execute
     * @return void
     */
    public function test_is_scoped_to_its_own_instance(): void {
        $instance = $this->make_instance();
        $otherinstance = $this->make_instance();
        $this->make_word($otherinstance->id, 'boca');

        $this->setUser($this->teacher);
        $response = $this->call_count($instance->cmid, 4, 4);

        $this->assertSame(0, $response['data']['count']);
    }

    /**
     * A user without mod/playercross:addinstance (e.g. a student) is rejected.
     *
     * @covers \mod_playercross\external\count_eligible_words::execute
     * @return void
     */
    public function test_requires_addinstance_capability(): void {
        $instance = $this->make_instance();

        $this->setUser($this->student);

        $this->expectException(\required_capability_exception::class);
        \mod_playercross\external\count_eligible_words::execute($instance->cmid, 4, 6);
    }
}
