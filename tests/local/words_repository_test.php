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
 * Unit tests for words_repository.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Tests for words_repository — focused on the methods that diverge from the
 * mod_playerwords port (theme-word candidates/selection, themewordid-based
 * attempts lookups), since the rest is a direct, unmodified port. Requires database.
 */
final class words_repository_test extends \advanced_testcase {
    /** @var \stdClass Course used to host test instances. */
    private \stdClass $course;

    /** @var \mod_playercross_generator Activity module generator. */
    private $modgenerator;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
    }

    /**
     * With theme_max_length left at its default (0 = unlimited), theme candidates
     * respect theme_min_length with no upper bound.
     *
     * @covers \mod_playercross\local\words_repository::get_theme_candidate_words
     * @return void
     */
    public function test_get_theme_candidate_words_respects_min_length_only(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'theme_min_length' => 6,
            'min_length' => 3,
            'max_length' => 5,
        ]);
        $this->modgenerator->create_word($instance->id, 'gato');       // 4 letters, too short for theme.
        $this->modgenerator->create_word($instance->id, 'floresta');   // 8 letters, eligible for theme.

        $themecandidates = words_repository::get_theme_candidate_words($instance);
        $this->assertCount(1, $themecandidates);
        $this->assertSame('floresta', reset($themecandidates)->word);
    }

    /**
     * With theme_max_length set to a real ceiling, a hint whose total letter count
     * exceeds it is excluded from theme eligibility, even though it clears
     * theme_min_length comfortably.
     *
     * @covers \mod_playercross\local\words_repository::get_theme_candidate_words
     * @return void
     */
    public function test_get_theme_candidate_words_respects_max_length_when_set(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'theme_min_length' => 6,
            'theme_max_length' => 10,
        ]);
        $this->modgenerator->create_word($instance->id, 'sol', 'floresta');        // 8 letters, eligible.
        $this->modgenerator->create_word($instance->id, 'mar', 'incrivelmente');   // 13 letters, too long.

        $themecandidates = words_repository::get_theme_candidate_words($instance);

        $this->assertCount(1, $themecandidates);
        $this->assertSame('sol', reset($themecandidates)->word);
    }

    /**
     * Clue candidates are bounded by min_length/max_length, same rule as PlayerWords.
     *
     * @covers \mod_playercross\local\words_repository::get_candidate_words
     * @return void
     */
    public function test_get_candidate_words_respects_length_range(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'min_length' => 3,
            'max_length' => 5,
        ]);
        $this->modgenerator->create_word($instance->id, 'floresta'); // 8 letters, too long for a clue.
        $this->modgenerator->create_word($instance->id, 'gato');     // 4 letters, within range.

        $cluecandidates = words_repository::get_candidate_words($instance);
        $this->assertCount(1, $cluecandidates);
        $this->assertSame('gato', reset($cluecandidates)->word);
    }

    /**
     * PLAYERCROSS_WORDMODE_SHARED must be deterministic across independent calls for
     * the same round number.
     *
     * @covers \mod_playercross\local\words_repository::pick_theme_word
     * @return void
     */
    public function test_pick_theme_word_shared_is_deterministic(): void {
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'theme_min_length' => 6,
            'wordmode' => PLAYERCROSS_WORDMODE_SHARED,
        ]);
        $this->modgenerator->create_word($instance->id, 'floresta');
        $this->modgenerator->create_word($instance->id, 'escritor');
        $this->modgenerator->create_word($instance->id, 'lanterna');

        $round0a = words_repository::pick_theme_word($instance, 0);
        $round0b = words_repository::pick_theme_word($instance, 0);
        $this->assertNotNull($round0a);
        $this->assertSame($round0a->word, $round0b->word);
    }

    /**
     * In random mode, an excluded theme id is avoided while an alternative exists.
     *
     * @covers \mod_playercross\local\words_repository::pick_theme_word
     * @return void
     */
    public function test_pick_theme_word_random_avoids_excluded_id_when_alternative_exists(): void {
        global $DB;
        $instance = $this->modgenerator->create_instance([
            'course' => $this->course->id,
            'theme_min_length' => 6,
        ]);
        $this->modgenerator->create_word($instance->id, 'floresta');
        $this->modgenerator->create_word($instance->id, 'escritor');
        $excludeid = (int)$DB->get_field('playercross_words', 'id', ['word' => 'floresta'], MUST_EXIST);

        for ($i = 0; $i < 10; $i++) {
            $word = words_repository::pick_theme_word($instance, 0, $excludeid);
            $this->assertNotNull($word);
            $this->assertSame('escritor', $word->word);
        }
    }

    /**
     * get_last_played_theme_word_id() reads straight off playercross_attempts, with no
     * "reserved but unfinished" state to filter — every row already represents a
     * completed round (SCOPE.md §5).
     *
     * @covers \mod_playercross\local\words_repository::get_last_played_theme_word_id
     * @return void
     */
    public function test_get_last_played_theme_word_id_returns_most_recent(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $user = $this->getDataGenerator()->create_user();
        $wordone = $this->modgenerator->create_word($instance->id, 'floresta');
        $wordtwo = $this->modgenerator->create_word($instance->id, 'escritor');

        $this->modgenerator->create_attempt($instance->id, $user->id, $wordone->id, ['timecreated' => time() - 100]);
        $this->modgenerator->create_attempt($instance->id, $user->id, $wordtwo->id, ['timecreated' => time()]);

        $this->assertSame((int)$wordtwo->id, words_repository::get_last_played_theme_word_id($instance, $user->id));
    }

    /**
     * With no attempts at all, the last played theme word id is 0.
     *
     * @covers \mod_playercross\local\words_repository::get_last_played_theme_word_id
     * @return void
     */
    public function test_get_last_played_theme_word_id_returns_zero_when_none(): void {
        $instance = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame(0, words_repository::get_last_played_theme_word_id($instance, $user->id));
    }

    /**
     * word_exists() is case-insensitive and scoped to the given activity instance.
     *
     * @covers \mod_playercross\local\words_repository::word_exists
     * @return void
     */
    public function test_word_exists_is_case_insensitive_and_scoped(): void {
        $instancea = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $instanceb = $this->modgenerator->create_instance(['course' => $this->course->id]);
        $this->modgenerator->create_word($instancea->id, 'Floresta');

        $this->assertTrue(words_repository::word_exists($instancea->id, 'floresta'));
        $this->assertFalse(words_repository::word_exists($instanceb->id, 'floresta'));
    }
}
