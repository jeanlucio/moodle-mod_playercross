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
 * Unit tests for view_page_service.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

use context_module;

/**
 * Tests for view_page_service::build_page_data() — requires database and session.
 *
 * This is the orchestration entry point used by view.php on every GET: it decides
 * between showing the lobby, a round in progress, a finished round, or a
 * restriction notice (cooldown/round limit).
 */
final class view_page_service_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Student used by the tests. */
    private \stdClass $user;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playercross/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->user   = $this->getDataGenerator()->create_user();
    }

    /**
     * Creates a playercross instance with a deterministic two-word pool: a theme
     * candidate ("escola") and the sole clue ("livro").
     *
     * @param array $overrides Instance field overrides.
     * @return array{0: \stdClass, 1: \stdClass, 2: context_module} [instance, cm, context]
     */
    private function make_instance(array $overrides = []): array {
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $record = array_merge([
            'course'           => $this->course->id,
            'num_clues'        => 1,
            'theme_min_length' => 6,
            'min_length'       => 3,
            'max_length'       => 15,
            'max_rounds'       => 0,
            'cooldown_amount'  => 0,
        ], $overrides);

        $instance = $modgenerator->create_instance($record);
        $modgenerator->create_word($instance->id, 'escola');
        $modgenerator->create_word($instance->id, 'livro');

        $cm = get_coursemodule_from_instance('playercross', $instance->id, $this->course->id, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        return [$instance, $cm, $context];
    }

    /**
     * A fresh visit builds a puzzle and shows the lobby, without starting the timer.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_shows_lobby_for_fresh_round(): void {
        [$instance, $cm, $context] = $this->make_instance();

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $ctx = $pagedata['templatecontext'];

        $this->assertTrue($ctx['hastheme']);
        $this->assertTrue($ctx['showlobby']);
        $this->assertFalse($ctx['roundstarted']);
        $this->assertSame(0, $pagedata['cooldownuntil']);
        $this->assertSame(0, $pagedata['timeleft']);
    }

    /**
     * The puzzle built on a fresh visit is persisted to session, so a second call
     * sees the same round instead of building another puzzle.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_persists_picked_puzzle_across_calls(): void {
        [$instance, $cm, $context] = $this->make_instance();

        view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $state = round_service::load_state((int)$cm->id, $this->user->id);

        $this->assertGreaterThan(0, $state['themewordid']);

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $statesecond = round_service::load_state((int)$cm->id, $this->user->id);

        $this->assertSame($state['themewordid'], $statesecond['themewordid']);
        $this->assertTrue($pagedata['templatecontext']['hastheme']);
    }

    /**
     * Once the round is finished, build_page_data reports it as such and computes
     * a real cooldown from the attempt that was just recorded, instead of building
     * a fresh puzzle.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_reflects_finished_round_and_computes_cooldown(): void {
        [$instance, $cm, $context] = $this->make_instance(['cooldown_amount' => 2, 'cooldown_unit' => 'minutes']);

        $state = round_service::load_state((int)$cm->id, $this->user->id);
        $state = round_service::ensure_round_state($state, $instance, (int)$cm->id, $this->user->id);
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $clue = $state['clues'][0];
        [$state] = round_service::submit_clue_guess(
            $state,
            $instance,
            (int)$cm->id,
            $this->user->id,
            (int)$clue['wordid'],
            $clue['word']
        );
        [$state] = round_service::submit_final_guess($state, $instance, (int)$cm->id, $this->user->id, 'escola');
        round_service::save_state((int)$cm->id, $this->user->id, $state);

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);

        $this->assertGreaterThan(0, $pagedata['cooldownuntil']);
        $this->assertSame(0, $pagedata['timeleft']);
        $this->assertSame(0, $pagedata['timertotal']);
    }

    /**
     * The forfeit button is only meaningful while a round is actively being played:
     * hidden in the lobby (nothing to forfeit yet), shown once the round starts, and
     * hidden again once it finishes.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_shows_forfeit_only_during_active_round(): void {
        [$instance, $cm, $context] = $this->make_instance();

        $lobbypagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $this->assertFalse($lobbypagedata['templatecontext']['showforfeit']);

        $state = round_service::load_state((int)$cm->id, $this->user->id);
        $state = round_service::ensure_round_state($state, $instance, (int)$cm->id, $this->user->id);
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        round_service::save_state((int)$cm->id, $this->user->id, $state);

        $activepagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $this->assertTrue($activepagedata['templatecontext']['showforfeit']);

        [$state] = round_service::forfeit($state, $instance, (int)$cm->id, $this->user->id);
        round_service::save_state((int)$cm->id, $this->user->id, $state);

        $finishedpagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $this->assertFalse($finishedpagedata['templatecontext']['showforfeit']);
    }

    /**
     * The template context always carries the attempt-history toolbar URL and the
     * how-to-play help content shown in the in-game modal, regardless of round state.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_includes_toolbar_urls(): void {
        [$instance, $cm, $context] = $this->make_instance();

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $ctx = $pagedata['templatecontext'];

        $this->assertStringContainsString('myattempts.php', $ctx['myattemptsurl']);
        $this->assertNotEmpty($ctx['helptitle']);
        $this->assertNotEmpty($ctx['introtext']);
        $this->assertFalse($ctx['showhud']);
        $this->assertSame('', $ctx['hudtext']);
    }

    /**
     * A student — who cannot manage the activity — never sees the manage-words or
     * report toolbar links.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_hides_manager_toolbar_for_student(): void {
        [$instance, $cm, $context] = $this->make_instance();

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $ctx = $pagedata['templatecontext'];

        $this->assertFalse($ctx['canmanage']);
    }

    /**
     * Whoever can manage the activity sees the manage-words and report toolbar links.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_shows_manager_toolbar_for_teacher(): void {
        [$instance, $cm, $context] = $this->make_instance();

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $teacher->id);
        $ctx = $pagedata['templatecontext'];

        $this->assertTrue($ctx['canmanage']);
        $this->assertStringContainsString('managewords.php', $ctx['managewordsurl']);
        $this->assertStringContainsString('attemptsreport.php', $ctx['attemptsreporturl']);
    }

    /**
     * A student — who cannot manage the activity — never sees the inactive-words
     * warning, even when the pool genuinely has an inactive word.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_hides_inactive_words_for_non_manager(): void {
        [$instance, $cm, $context] = $this->make_instance();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        // Two letters: below both the clue range (min_length 3) and theme_min_length (6).
        $modgenerator->create_word($instance->id, 'oi');

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);

        $this->assertFalse($pagedata['templatecontext']['showwordsstatus']);
        $this->assertFalse($pagedata['templatecontext']['hasinactivewords']);
    }

    /**
     * Whoever can manage the activity sees the inactive-words warning, naming the
     * word and its exclusion reason, alongside the active-word count.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_shows_inactive_words_for_manager(): void {
        [$instance, $cm, $context] = $this->make_instance();
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $modgenerator->create_word($instance->id, 'oi');

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $teacher->id);
        $ctx = $pagedata['templatecontext'];

        $this->assertTrue($ctx['showwordsstatus']);
        // Only "escola" and "livro" (make_instance()'s own default pool) count as active.
        $this->assertStringContainsString('2', $ctx['activewordscount']);
        $this->assertTrue($ctx['hasinactivewords']);
        $this->assertTrue($ctx['haslengthissues']);
        $this->assertStringContainsString('oi', $ctx['lengthissuestext']);
        $this->assertFalse($ctx['hascharsetissues']);
    }

    /**
     * The active-word count is shown to a manager even when the pool has no
     * inactive words at all — it is a standing reassurance, not tied to a warning.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_shows_active_count_without_any_inactive_words(): void {
        [$instance, $cm, $context] = $this->make_instance();

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $teacher->id);
        $ctx = $pagedata['templatecontext'];

        $this->assertTrue($ctx['showwordsstatus']);
        $this->assertStringContainsString('2', $ctx['activewordscount']);
        $this->assertFalse($ctx['hasinactivewords']);
    }

    /**
     * The ranking toolbar link reflects the activity's show_ranking setting.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_hides_ranking_link_when_disabled(): void {
        [$instance, $cm, $context] = $this->make_instance(['show_ranking' => 0]);

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);

        $this->assertFalse($pagedata['templatecontext']['showranking']);
    }

    /**
     * The help modal shows the PlayerHUD explanation as soon as any of the round cost,
     * hint cost, or win-reward settings is configured.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_shows_hud_help_when_win_reward_configured(): void {
        [$instance, $cm, $context] = $this->make_instance(['hud_win_reward_item' => 999999]);

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $ctx = $pagedata['templatecontext'];

        $this->assertTrue($ctx['showhud']);
        $this->assertNotEmpty($ctx['hudtext']);
    }

    /**
     * When the round limit is reached before a puzzle is even built, the
     * restriction notice is surfaced instead of a fresh theme.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_shows_restriction_notice_when_round_limit_reached(): void {
        [$instance, $cm, $context] = $this->make_instance(['max_rounds' => 1]);
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($instance->id, 'caderno');
        $modgenerator->create_attempt($instance->id, $this->user->id, $theme->id);

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $ctx = $pagedata['templatecontext'];

        $this->assertFalse($ctx['hastheme']);
        $this->assertStringContainsString('1', $ctx['nogamewords']);
        $this->assertSame(0, $pagedata['cooldownuntil']);
    }

    /**
     * A user's very first page load of any PlayerCross activity is flagged to
     * auto-show the how-to-play intro, and that first load immediately marks the
     * site-wide preference so it is never repeated — including on the very same
     * lobby, matching intro_service's own contract.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_flags_autoshow_intro_once_on_lobby(): void {
        [$instance, $cm, $context] = $this->make_instance();

        $this->assertFalse(intro_service::has_seen_intro($this->user->id));

        $first = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $this->assertTrue($first['shouldautoshowintro']);
        $this->assertTrue(intro_service::has_seen_intro($this->user->id));

        $second = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);
        $this->assertFalse($second['shouldautoshowintro']);
    }

    /**
     * The auto-show flag is site-wide, not per-activity: a user who already saw
     * the intro on one activity must not see it again on a second, different one.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_autoshow_intro_does_not_repeat_across_activities(): void {
        [$instancea, $cma, $contexta] = $this->make_instance();
        [$instanceb, $cmb, $contextb] = $this->make_instance();

        $firstactivity = view_page_service::build_page_data($cma, $instancea, $contexta, $this->user->id);
        $this->assertTrue($firstactivity['shouldautoshowintro']);

        $secondactivity = view_page_service::build_page_data($cmb, $instanceb, $contextb, $this->user->id);
        $this->assertFalse($secondactivity['shouldautoshowintro']);
    }

    /**
     * The auto-show flag is still surfaced on the finished-round branch of
     * build_page_data, not only on the fresh-lobby branch.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_flags_autoshow_intro_on_finished_round_branch(): void {
        [$instance, $cm, $context] = $this->make_instance();

        $state = round_service::load_state((int)$cm->id, $this->user->id);
        $state = round_service::ensure_round_state($state, $instance, (int)$cm->id, $this->user->id);
        [$state] = round_service::start_round($state, $instance, $this->user->id);
        $clue = $state['clues'][0];
        [$state] = round_service::submit_clue_guess(
            $state,
            $instance,
            (int)$cm->id,
            $this->user->id,
            (int)$clue['wordid'],
            $clue['word']
        );
        [$state] = round_service::submit_final_guess($state, $instance, (int)$cm->id, $this->user->id, 'escola');
        round_service::save_state((int)$cm->id, $this->user->id, $state);

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);

        $this->assertTrue($pagedata['shouldautoshowintro']);
        $this->assertTrue(intro_service::has_seen_intro($this->user->id));
    }

    /**
     * The auto-show flag is still surfaced on the round-restriction branch of
     * build_page_data (round limit already reached before a puzzle is even built).
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_flags_autoshow_intro_on_restriction_branch(): void {
        [$instance, $cm, $context] = $this->make_instance(['max_rounds' => 1]);
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_playercross');
        $theme = $modgenerator->create_word($instance->id, 'caderno');
        $modgenerator->create_attempt($instance->id, $this->user->id, $theme->id);

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);

        $this->assertTrue($pagedata['shouldautoshowintro']);
        $this->assertTrue(intro_service::has_seen_intro($this->user->id));
    }

    /**
     * The help modal content always carries the review hint pointing back to the
     * toolbar help icon, regardless of round state.
     *
     * @covers \mod_playercross\local\view_page_service::build_page_data
     * @return void
     */
    public function test_build_page_data_includes_review_hint_in_help_context(): void {
        [$instance, $cm, $context] = $this->make_instance();

        $pagedata = view_page_service::build_page_data($cm, $instance, $context, $this->user->id);

        $this->assertNotEmpty($pagedata['templatecontext']['reviewhint']);
    }
}
