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
 * External function tests for count_glossary_candidates.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use core_external\external_api;

/**
 * Tests for the mod_playercross_count_glossary_candidates web service.
 */
final class count_glossary_candidates_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Teacher with mod/playercross:addinstance. */
    private \stdClass $teacher;

    /** @var \stdClass Student without mod/playercross:addinstance. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Creates a glossary in the test course with one approved entry.
     *
     * @param string $concept Entry concept text.
     * @return \stdClass Glossary module record.
     */
    private function make_glossary(string $concept): \stdClass {
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $this->course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($glossary, [
            'concept'    => $concept,
            'definition' => 'definicao',
            'approved'   => 1,
        ]);
        return $glossary;
    }

    /**
     * Calls the mod_playercross_count_glossary_candidates web service through the
     * real dispatch path.
     *
     * @param int $glossaryid Glossary id, or 0 for every course glossary.
     * @param int $minlength Candidate minimum word length.
     * @param int $maxlength Candidate maximum word length.
     * @param string $stopwords Comma-separated words to ignore when splitting multi-word concepts.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_count(int $glossaryid, int $minlength, int $maxlength, string $stopwords = ''): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playercross_count_glossary_candidates', [
            'courseid'   => $this->course->id,
            'glossaryid' => $glossaryid,
            'minlength'  => $minlength,
            'maxlength'  => $maxlength,
            'stopwords'  => $stopwords,
        ]);
    }

    /**
     * Counts candidate words for a specific glossary within the requested range.
     *
     * @covers \mod_playercross\external\count_glossary_candidates::execute
     * @return void
     */
    public function test_counts_candidates_for_a_specific_glossary(): void {
        $glossary = $this->make_glossary('planeta');

        $this->setUser($this->teacher);
        $response = $this->call_count($glossary->id, 4, 8);

        $this->assertFalse($response['error']);
        $this->assertSame(1, $response['data']['count']);
    }

    /**
     * A word outside the requested length range is excluded from the count.
     *
     * @covers \mod_playercross\external\count_glossary_candidates::execute
     * @return void
     */
    public function test_excludes_words_outside_range(): void {
        $glossary = $this->make_glossary('planeta');

        $this->setUser($this->teacher);
        $response = $this->call_count($glossary->id, 20, 30);

        $this->assertSame(0, $response['data']['count']);
    }

    /**
     * A stopword passed straight from the settings form (not yet saved to any
     * instance) drops the matching token from a multi-word concept before counting.
     *
     * @covers \mod_playercross\external\count_glossary_candidates::execute
     * @return void
     */
    public function test_counts_candidates_respecting_stopwords_param(): void {
        $glossary = $this->make_glossary('sistema solar');

        $this->setUser($this->teacher);
        $withoutstopwords = $this->call_count($glossary->id, 1, 30);
        $withstopwords = $this->call_count($glossary->id, 1, 30, 'solar');

        $this->assertSame(2, $withoutstopwords['data']['count']);
        $this->assertSame(1, $withstopwords['data']['count']);
    }

    /**
     * A user without mod/playercross:addinstance (e.g. a student) is rejected.
     *
     * @covers \mod_playercross\external\count_glossary_candidates::execute
     * @return void
     */
    public function test_requires_addinstance_capability(): void {
        $glossary = $this->make_glossary('planeta');

        $this->setUser($this->student);

        $this->expectException(\required_capability_exception::class);
        \mod_playercross\external\count_glossary_candidates::execute($this->course->id, $glossary->id, 1, 30);
    }
}
