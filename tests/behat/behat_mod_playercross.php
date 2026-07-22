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
 * Step definitions for mod_playercross Behat tests.
 *
 * @package    mod_playercross
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing

use Behat\Gherkin\Node\TableNode;

/**
 * Custom Behat step definitions for the PlayerCross activity.
 */
class behat_mod_playercross extends behat_base {
    /**
     * Seeds approved manual words directly into an instance's pool.
     *
     * Bypasses managewords.php and the approval flow, so a scenario can rely on a
     * deterministic pool (e.g. one theme candidate plus one clue candidate) instead
     * of the pseudo-random round selection.
     *
     * @param string $activityname PlayerCross activity name.
     * @param TableNode $data Table with a required "word" column and an optional "hint" one.
     * @Given the following PlayerCross words exist in activity :activityname:
     */
    public function the_following_playercross_words_exist_in_activity(string $activityname, TableNode $data): void {
        $playercrossid = $this->get_playercross_id($activityname);
        $generator = behat_util::get_data_generator()->get_plugin_generator('mod_playercross');

        foreach ($data->getHash() as $row) {
            $generator->create_word($playercrossid, $row['word'], $row['hint'] ?? '');
        }
    }

    /**
     * Seeds finished attempt rows directly, without playing a real round.
     *
     * Used to fill the teacher attempt report or the ranking with enough rows to
     * exercise pagination, sorting and tie-breaking — driving dozens of real rounds
     * through the UI would be slow and flaky. Rows without an explicit "created"
     * column get a strictly decreasing timestamp (one minute apart, in table order)
     * so "sort by date" scenarios have a deterministic order to assert against.
     *
     * @param string $activityname PlayerCross activity name.
     * @param TableNode $data Table with columns: user, word, cluesresolved, cluestotal,
     *     finalguessed, attemptsused, timeused, completed, score, created (all but
     *     user and word are optional).
     * @Given the following PlayerCross attempts exist in activity :activityname:
     */
    public function the_following_playercross_attempts_exist_in_activity(string $activityname, TableNode $data): void {
        global $DB;

        $playercrossid = $this->get_playercross_id($activityname);
        $generator = behat_util::get_data_generator()->get_plugin_generator('mod_playercross');
        $now = time();

        foreach ($data->getHash() as $index => $row) {
            $userid = $DB->get_field('user', 'id', ['username' => $row['user']], MUST_EXIST);
            $themewordid = $DB->get_field_sql(
                'SELECT id FROM {playercross_words} WHERE playercrossid = :pcid AND word = :word',
                ['pcid' => $playercrossid, 'word' => $row['word']],
                MUST_EXIST
            );

            $columnmap = [
                'cluesresolved' => 'cluesresolved',
                'cluestotal'    => 'cluestotal',
                'finalguessed'  => 'finalguessed',
                'attemptsused'  => 'attempts_used',
                'timeused'      => 'time_used',
                'completed'     => 'completed',
                'score'         => 'score',
            ];
            $overrides = [];
            foreach ($columnmap as $column => $field) {
                if (isset($row[$column]) && $row[$column] !== '') {
                    $overrides[$field] = $row[$column];
                }
            }
            $created = (isset($row['created']) && $row['created'] !== '') ? (int) $row['created'] : ($now - $index * 60);
            $overrides['timecreated'] = $created;

            $generator->create_attempt($playercrossid, (int) $userid, (int) $themewordid, $overrides);
        }
    }

    /**
     * Seeds many identical finished attempt rows for one student, for pagination scenarios.
     *
     * Writing 31 rows by hand in a Gherkin table just to cross the 30-per-page
     * boundary would be unreadable noise — this generates them instead, one second
     * apart in table order (newest first, matching the report's default
     * date-descending sort).
     *
     * @param int $count Number of attempts to create.
     * @param string $username Student username.
     * @param string $word Theme word text; must already exist in the activity's pool.
     * @param string $activityname PlayerCross activity name.
     * @Given :count PlayerCross attempts exist for :username with word :word in activity :activityname
     */
    public function n_playercross_attempts_exist_for_user(
        int $count,
        string $username,
        string $word,
        string $activityname
    ): void {
        global $DB;

        $playercrossid = $this->get_playercross_id($activityname);
        $generator = behat_util::get_data_generator()->get_plugin_generator('mod_playercross');
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $themewordid = $DB->get_field_sql(
            'SELECT id FROM {playercross_words} WHERE playercrossid = :pcid AND word = :word',
            ['pcid' => $playercrossid, 'word' => $word],
            MUST_EXIST
        );
        $now = time();

        for ($i = 0; $i < $count; $i++) {
            $created = $now - $i;
            $generator->create_attempt($playercrossid, (int) $userid, (int) $themewordid, [
                'timecreated' => $created,
            ]);
        }
    }

    /**
     * Overrides timer_seconds or cooldown_seconds directly in the database.
     *
     * playercross_add_instance() always recomputes both columns from the transient
     * timer_minutes / cooldown_amount+cooldown_unit form fields (see lib.php),
     * ignoring any value passed straight through the generic "activities exist"
     * step — the same reason the PHPUnit suite works around it this way instead
     * (see round_service_test.php). timer_minutes-only granularity cannot express a
     * short enough timer for a fast Behat scenario, so this is the only way to set
     * an exact value.
     *
     * @param string $activityname PlayerCross activity name.
     * @param string $field Either "timer_seconds" or "cooldown_seconds".
     * @param int $seconds Value to store.
     * @Given the PlayerCross activity :activityname has :field set to :seconds seconds
     */
    public function the_playercross_activity_has_field_set_to_seconds(
        string $activityname,
        string $field,
        int $seconds
    ): void {
        global $DB;

        if ($field !== 'timer_seconds' && $field !== 'cooldown_seconds') {
            throw new \coding_exception('Unsupported field: ' . $field);
        }

        $playercrossid = $this->get_playercross_id($activityname);
        $DB->set_field('playercross', $field, $seconds, ['id' => $playercrossid]);
    }

    /**
     * Marks a user as having already seen the automatic how-to-play introduction
     * (see mod_playercross\local\intro_service), so scenarios about anything else
     * are not incidentally interrupted by the modal opening on its own on a fresh
     * user's first visit to any PlayerCross activity — a precondition for the
     * scenario, not the thing under test, same reasoning already used for
     * PlayerHUD items below. The auto-show behaviour itself has its own dedicated
     * scenario in mod_playercross_toolbar.feature, using a user this step is never
     * applied to.
     *
     * @param string $username Moodle username.
     * @Given :username has already seen the playercross intro
     */
    public function user_has_already_seen_the_playercross_intro(string $username): void {
        global $DB;

        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        \mod_playercross\local\intro_service::mark_intro_seen($userid);
    }

    /**
     * Asserts that the given CSS element has the disabled attribute.
     *
     * Custom rather than relying on core's own "should be disabled" step: that step
     * is not defined on every Moodle version this plugin supports (confirmed
     * missing on 4.5/5.0/5.2 for block_playerhud, same ecosystem) — matches the
     * pattern already established there.
     *
     * @param string $selector CSS selector.
     * @Then the :selector element should be disabled
     */
    public function element_is_disabled(string $selector): void {
        $node = $this->find('css', $selector);
        if (!$node->hasAttribute('disabled')) {
            throw new \Exception("Element '{$selector}' is expected to be disabled but is not.");
        }
    }

    /**
     * Fills one clue's own tile inputs with the given word, one letter per box, in
     * position order. $position counts only clues that currently carry a guess
     * form (already-resolved/exhausted clues have none), in the order they appear
     * on the page — the first still-guessable clue is position 1, matching how a
     * scenario author reads the rendered page rather than an internal word id.
     *
     * Targets each tile input directly instead of relying on the app's own
     * click-to-focus-then-type-and-auto-advance behaviour (amd/src/game.js
     * writeLetterIntoActiveBox()/focusAdjacentBox()) — real keystroke simulation
     * across a chain of auto-advancing focus targets is far more brittle over
     * WebDriver than setting each box directly. An already-revealed/locked
     * position (a <span>, not an <input>) still consumes one letter from $word,
     * since it is part of the same word, just pre-filled — only the still-hidden
     * boxes actually receive a setValue() call. Ends by clicking the last box
     * filled, so the page's own "which form is active" tracking (activeInput in
     * game.js) points at this clue's form before an ENTER keypress is simulated.
     *
     * @param int $position 1-based position among the currently guessable clues.
     * @param string $word Full word to type in, letters only.
     * @Given I fill PlayerCross clue :position tiles with :word
     */
    public function i_fill_the_playercross_clue_tiles_with(int $position, string $word): void {
        $tilescontainers = $this->find_all('css', '#playercross-clues-list .mod-playercross-clue-tiles');
        if (!isset($tilescontainers[$position - 1])) {
            throw new \Exception("No guessable PlayerCross clue at position {$position}.");
        }
        $wraps = $tilescontainers[$position - 1]->findAll('css', '.mod-playercross-tile-wrap');
        $this->fill_playercross_tile_wraps($wraps, $word);
    }

    /**
     * Fills the mystery-phrase tiles with the given phrase, one letter per box, in
     * position order. Spaces are stripped before typing — the tile grid never
     * renders a box for a space (see word_normalizer::chars()).
     *
     * @param string $phrase Full mystery phrase to type in.
     * @Given I fill the PlayerCross mystery phrase tiles with :phrase
     */
    public function i_fill_the_playercross_theme_tiles_with(string $phrase): void {
        $wraps = $this->find_all('css', '.mod-playercross-theme .mod-playercross-tile-wrap');
        $this->fill_playercross_tile_wraps($wraps, str_replace(' ', '', $phrase));
    }

    /**
     * Shared tile-filling logic for a clue's or the mystery phrase's own tile row.
     *
     * @param \Behat\Mink\Element\NodeElement[] $wraps Every tile-wrap in the form, in position order.
     * @param string $word Letters to type in, one per still-editable tile-wrap.
     */
    private function fill_playercross_tile_wraps(array $wraps, string $word): void {
        $letters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);

        $index = 0;
        $lastinput = null;
        foreach ($wraps as $wrap) {
            $input = $wrap->find('css', '.mod-playercross-tile-input');
            if ($input === null) {
                // Already-revealed/locked tile: still one letter of $word, just pre-filled.
                $index++;
                continue;
            }
            if (!isset($letters[$index])) {
                break;
            }
            $input->setValue($letters[$index]);
            $lastinput = $input;
            $index++;
        }

        $lastinput?->click();
    }

    /**
     * Creates a PlayerHUD item in the block already added to the given course.
     *
     * Direct $DB insert rather than going through the block's own management UI,
     * matching the pattern already established in behat_block_playerhud.php for
     * the same reason: the item's existence is a precondition for the scenario,
     * not the thing under test.
     *
     * @param string $itemname Display name for the item.
     * @param string $shortname Course shortname. The PlayerHUD block must already be added.
     * @Given a PlayerHUD item :itemname exists in course :shortname
     */
    public function a_playerhud_item_exists_in_course(string $itemname, string $shortname): void {
        global $DB;

        $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $this->get_playerhud_block_id($shortname),
            'name'            => $itemname,
            'description'     => '',
            'image'           => '🔑',
            'xp'              => 10,
            'secret'          => 0,
            'enabled'         => 1,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    /**
     * Wires an existing PlayerHUD item as the item cost to start a round.
     *
     * @param string $activityname PlayerCross activity name.
     * @param int $qty Quantity required.
     * @param string $itemname PlayerHUD item name; must already exist in the activity's course.
     * @Given the PlayerCross activity :activityname charges :qty PlayerHUD item :itemname to start a round
     */
    public function playercross_activity_charges_item_to_start(string $activityname, int $qty, string $itemname): void {
        $this->set_playercross_hud_field($activityname, $itemname, 'hud_round_cost_item', 'hud_round_cost_qty', $qty);
    }

    /**
     * Wires an existing PlayerHUD item as the item cost to reveal a hint.
     *
     * @param string $activityname PlayerCross activity name.
     * @param int $qty Quantity required.
     * @param string $itemname PlayerHUD item name; must already exist in the activity's course.
     * @Given the PlayerCross activity :activityname charges :qty PlayerHUD item :itemname to reveal a hint
     */
    public function playercross_activity_charges_item_for_hint(string $activityname, int $qty, string $itemname): void {
        $this->set_playercross_hud_field($activityname, $itemname, 'hud_hint_cost_item', 'hud_hint_cost_qty', $qty);
    }

    /**
     * Wires an existing PlayerHUD item as the reward for winning a round.
     *
     * @param string $activityname PlayerCross activity name.
     * @param int $qty Quantity granted.
     * @param string $itemname PlayerHUD item name; must already exist in the activity's course.
     * @Given the PlayerCross activity :activityname grants :qty PlayerHUD item :itemname for winning a round
     */
    public function playercross_activity_grants_item_on_win(string $activityname, int $qty, string $itemname): void {
        $this->set_playercross_hud_field($activityname, $itemname, 'hud_win_reward_item', 'hud_win_reward_qty', $qty);
    }

    /**
     * Grants a user a starting balance of a PlayerHUD item, bypassing
     * external_items::grant() (and therefore any XP side effect) since the
     * scenario only cares about the balance itself, not how it was acquired —
     * mirrors block_playerhud's own inventory-seeding step.
     *
     * @param string $username Moodle username.
     * @param int $qty Quantity to grant.
     * @param string $itemname PlayerHUD item name.
     * @param string $shortname Course shortname.
     * @Given :username has :qty PlayerHUD item :itemname in course :shortname
     */
    public function user_has_playerhud_item(string $username, int $qty, string $itemname, string $shortname): void {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $blockinstanceid = $this->get_playerhud_block_id($shortname);
        $itemid = $DB->get_field(
            'block_playerhud_items',
            'id',
            ['blockinstanceid' => $blockinstanceid, 'name' => $itemname],
            MUST_EXIST
        );

        for ($i = 0; $i < $qty; $i++) {
            $DB->insert_record('block_playerhud_inventory', (object) [
                'userid'      => $userid,
                'itemid'      => $itemid,
                'dropid'      => 0,
                'source'      => 'behat',
                'timecreated' => time(),
                'xpawarded'   => 0,
            ]);
        }
    }

    /**
     * Shared setter for the three hud_*_item/hud_*_qty column pairs.
     *
     * @param string $activityname PlayerCross activity name.
     * @param string $itemname PlayerHUD item name; resolved within the activity's own course.
     * @param string $itemfield Column name for the item id (e.g. hud_round_cost_item).
     * @param string $qtyfield Column name for the quantity (e.g. hud_round_cost_qty).
     * @param int $qty Quantity value to store.
     */
    private function set_playercross_hud_field(
        string $activityname,
        string $itemname,
        string $itemfield,
        string $qtyfield,
        int $qty
    ): void {
        global $DB;

        $playercrossid = $this->get_playercross_id($activityname);
        $courseid = (int) $DB->get_field('playercross', 'course', ['id' => $playercrossid], MUST_EXIST);
        $shortname = (string) $DB->get_field('course', 'shortname', ['id' => $courseid], MUST_EXIST);
        $blockinstanceid = $this->get_playerhud_block_id($shortname);
        $itemid = $DB->get_field(
            'block_playerhud_items',
            'id',
            ['blockinstanceid' => $blockinstanceid, 'name' => $itemname],
            MUST_EXIST
        );

        $DB->set_field('playercross', $itemfield, $itemid, ['id' => $playercrossid]);
        $DB->set_field('playercross', $qtyfield, $qty, ['id' => $playercrossid]);
    }

    /**
     * Resolves the PlayerHUD block instance id for a course. The block must
     * already have been added (e.g. via "I add the PlayerHUD block" while editing
     * the course).
     *
     * @param string $shortname Course shortname.
     * @return int
     */
    private function get_playerhud_block_id(string $shortname): int {
        global $DB;

        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $context = \context_course::instance($courseid);

        return (int) $DB->get_field('block_instances', 'id', [
            'blockname'       => 'playerhud',
            'parentcontextid' => $context->id,
        ], MUST_EXIST);
    }

    /**
     * Resolves a PlayerCross activity name to its instance id.
     *
     * @param string $activityname Activity name as configured in the instance.
     * @return int
     */
    private function get_playercross_id(string $activityname): int {
        global $DB;

        return (int) $DB->get_field('playercross', 'id', ['name' => $activityname], MUST_EXIST);
    }
}
