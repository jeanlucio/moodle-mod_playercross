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
 * External function tests for submit_term_guess.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\external;

use core_text;
use mod_playercross\local\round_presenter;
use mod_playercross\local\round_service;

/**
 * Tests for the mod_playercross_submit_term_guess web service.
 *
 * The invariant under test (SCOPE.md §7): no response ever reveals the mystery
 * phrase, nor an unresolved term's word, before the round has actually finished
 * server-side.
 *
 * @covers \mod_playercross\external\submit_term_guess
 * @covers \mod_playercross\external\submit_final_guess
 * @covers \mod_playercross\local\round_presenter
 */
final class submit_term_guess_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    /** @var \mod_playercross_generator Activity module generator. */
    private $modgenerator;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
    }

    /**
     * Creates a ready-to-play instance with a small, deterministic word pool.
     *
     * @param array $overrides Instance field overrides.
     * @return array{0: \stdClass, 1: \stdClass} [instance record, course module]
     */
    private function make_ready_instance(array $overrides = []): array {
        $cm = $this->modgenerator->create_instance($overrides + [
            'course' => $this->course->id,
            'num_terms' => 3,
            'theme_min_length' => 6,
        ]);
        global $DB;
        $instance = $DB->get_record('playercross', ['id' => $cm->id], '*', MUST_EXIST);

        $this->modgenerator->create_word($instance->id, 'escola');
        $this->modgenerator->create_word($instance->id, 'casa');
        $this->modgenerator->create_word($instance->id, 'lobo');
        $this->modgenerator->create_word($instance->id, 'mel');

        return [$instance, $cm];
    }

    /**
     * Every context field round_presenter::build_round_panel_context() can return must
     * also be declared in submit_term_guess::panel_structure() — a field present in the
     * PHP array but absent from the external API's declared schema is silently stripped
     * by external_api::clean_returnvalue() the moment a mutating endpoint actually
     * returns it over AJAX, with no error or warning anywhere on the PHP side. This is
     * exactly what happened when themesolved/finalguesscorrect/themedisplayword were
     * added to build_round_panel_context() without extending panel_structure() to
     * match: the round panel rendered correctly on the very first full-page load (a
     * direct render_from_template() call, which never goes through the external API),
     * but every AJAX round-trip afterwards silently lost those three keys, so the
     * client's own Templates.renderForPromise() call fell back to the tile grid instead
     * of collapsing it into resolved text, no matter how the round actually finished.
     *
     * Comparing the full key sets, rather than asserting a handful of fields exist
     * individually (as a previous version of this guard, in start_round_test.php, did
     * with a single field), is the only way this catches a field added later without a
     * matching schema update — a per-key assertion never fails just because a new key
     * is missing.
     *
     * @return void
     */
    public function test_panel_structure_declares_every_build_round_panel_context_key(): void {
        [$instance, $cm] = $this->make_ready_instance();
        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );

        $context = round_presenter::build_round_panel_context($instance, $cm, $state, $this->student->id);
        $declared = array_keys(submit_term_guess::panel_structure()->keys);

        $missing = array_diff(array_keys($context), $declared);
        $this->assertSame([], $missing, 'panel_structure() is missing: ' . implode(', ', $missing));
    }

    /**
     * Skips the current test when block_playerhud is not installed.
     *
     * @return void
     */
    private function skip_if_no_playerhud(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
    }

    /**
     * Inserts a genuine block_playerhud_items record (with its own block instance).
     *
     * @return int Item id.
     */
    private function make_hud_item(): int {
        global $DB;
        $ctx = \context_course::instance($this->course->id);
        $biid = $DB->insert_record('block_instances', (object)[
            'blockname'         => 'playerhud',
            'parentcontextid'   => $ctx->id,
            'showinsubcontexts' => 0,
            'pagetypepattern'   => 'course-view-*',
            'subpagepattern'    => null,
            'defaultregion'     => 'side-pre',
            'defaultweight'     => 0,
            'configdata'        => base64_encode(serialize(new \stdClass())),
            'timecreated'       => time(),
            'timemodified'      => time(),
        ]);
        return $DB->insert_record('block_playerhud_items', (object)[
            'blockinstanceid' => $biid,
            'name'            => 'Gold Key',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    /**
     * An unresolved term's word is never present in the panel response.
     *
     * @return void
     */
    public function test_wrong_guess_never_leaks_the_term_word(): void {
        [$instance, $cm] = $this->make_ready_instance();
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        round_service::save_state($cm->cmid, $this->student->id, $state);
        $termid = (int)$state['terms'][0]['wordid'];

        $result = submit_term_guess::execute($cm->cmid, $termid, 'zzzzzzz');

        $this->assertFalse($result['resolved']);
        $this->assertFalse($result['finished']);
        foreach ($result['panel']['terms'] as $termrow) {
            $this->assertSame('', $termrow['revealword']);
        }
        $this->assertSame('', $result['panel']['revealthemeword']);
    }

    /**
     * An ordinary mid-round term (others still pending) is flagged toast-worthy, so the
     * player-facing "Pista resolvida!" message auto-dismisses instead of piling up —
     * see round_service::submit_term_guess() and amd/src/game.js::notify().
     *
     * @return void
     */
    public function test_ordinary_resolved_term_is_flagged_as_a_toast(): void {
        [$instance, $cm] = $this->make_ready_instance();
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        round_service::save_state($cm->cmid, $this->student->id, $state);
        $term = $state['terms'][0];

        $result = submit_term_guess::execute($cm->cmid, (int)$term['wordid'], $term['word']);

        $this->assertTrue($result['resolved']);
        $this->assertSame('success', $result['notificationtype']);
        $this->assertTrue($result['toast']);
    }

    /**
     * Resolving every term alone does not finish the round — winning always requires
     * the mystery phrase to be guessed too — and the theme word stays hidden until the
     * round actually finishes.
     *
     * num_terms/reveal_uncovered_slots overridden from make_ready_instance()'s usual 3
     * terms: with all three of casa/lobo/mel selected, their combined coverage always
     * happens to reach every theme letter (a coincidence of this fixed word pool),
     * which would make round_service::reconcile_after_reveal() confirm the phrase —
     * and finish the round — as a side effect of the last term, defeating the very
     * thing this test means to check.
     *
     * @return void
     */
    public function test_resolving_every_term_reveals_theme_word_only_at_the_end(): void {
        [$instance, $cm] = $this->make_ready_instance(['num_terms' => 2, 'reveal_uncovered_slots' => 0]);
        $this->setUser($this->student);

        $state = round_service::ensure_round_state(
            round_service::load_state($cm->cmid, $this->student->id),
            $instance,
            $cm->cmid,
            $this->student->id
        );
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        round_service::save_state($cm->cmid, $this->student->id, $state);

        foreach ($state['terms'] as $term) {
            $result = submit_term_guess::execute($cm->cmid, (int)$term['wordid'], $term['word']);
            $this->assertTrue($result['resolved']);
            $this->assertFalse($result['finished']);
            $this->assertSame('', $result['panel']['revealthemeword']);
        }

        $result = submit_final_guess::execute($cm->cmid, implode(' ', $state['themewords']));

        $this->assertTrue($result['finished']);
        $this->assertSame(core_text::strtoupper(implode(' ', $state['themewords'])), $result['panel']['revealthemeword']);
    }

    /**
     * A student outside the course cannot submit a guess.
     *
     * @return void
     */
    public function test_outsider_cannot_submit_guess(): void {
        [, $cm] = $this->make_ready_instance();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $this->expectException(\require_login_exception::class);
        submit_term_guess::execute($cm->cmid, 1, 'palpite');
    }

    /**
     * Regression test for the round-cost bypass: a student who skips the "Iniciar
     * rodada" button — the only place a configured PlayerHUD round cost is actually
     * charged — and calls mod_playercross_submit_term_guess directly through the real
     * web service dispatch path must be rejected, even with a correct guess for a term
     * already sitting in session (view.php's GET-time ensure_round_state() call puts it
     * there before the student ever clicks anything). The round must not finish and no
     * term may resolve.
     *
     * @return void
     */
    public function test_rejects_term_guess_when_round_not_started_bypassing_hud_cost(): void {
        $this->skip_if_no_playerhud();
        $itemid = $this->make_hud_item();
        [$instance, $cm] = $this->make_ready_instance([
            'hud_round_cost_item' => $itemid,
            'hud_round_cost_qty'  => 1,
        ]);
        $this->setUser($this->student);

        // Load the puzzle into session (what view.php's GET does), but never call
        // start_round — the exact shape of the exploit in the security report's PoC.
        $state = round_service::load_state($cm->cmid, $this->student->id);
        $state = round_service::ensure_round_state($state, $instance, $cm->cmid, $this->student->id);
        round_service::save_state($cm->cmid, $this->student->id, $state);
        $term = $state['terms'][0];

        $result = submit_term_guess::execute($cm->cmid, (int)$term['wordid'], $term['word']);

        $this->assertFalse($result['resolved']);
        $this->assertFalse($result['finished']);
        $this->assertNotEmpty($result['notification']);
    }

    /**
     * Regression test for the timer bypass in the security report's PoC: a student
     * who never calls mod_playercross_end_round(reason=timeout) — because the client
     * only ever arms that call when it first sees timeleft > 0, so simply reloading
     * after the deadline never fires it — must still be stopped from resolving a term
     * through mod_playercross_submit_term_guess after the round's own deadline has
     * passed. The server, not the client, must be the one enforcing the time limit.
     *
     * @return void
     */
    public function test_rejects_term_guess_once_deadline_has_passed(): void {
        [$instance, $cm] = $this->make_ready_instance(['timer_minutes' => 1]);
        $this->setUser($this->student);

        $state = round_service::load_state($cm->cmid, $this->student->id);
        $state = round_service::ensure_round_state($state, $instance, $cm->cmid, $this->student->id);
        [$state] = round_service::start_round($state, $instance, $this->student->id);
        $state['starttime'] = time() - 120;
        round_service::save_state($cm->cmid, $this->student->id, $state);
        $term = $state['terms'][0];

        $result = submit_term_guess::execute($cm->cmid, (int)$term['wordid'], $term['word']);

        $this->assertFalse($result['resolved']);
        $this->assertTrue($result['finished']);
        $this->assertNotEmpty($result['notification']);
    }
}
