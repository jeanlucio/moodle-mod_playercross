# 🧪 Automated Tests

PlayerCross ships with a PHPUnit test suite covering business logic, repository queries, web
services, and Privacy API compliance. Every CI push runs against the full matrix (Moodle 4.5 →
5.x, PostgreSQL & MariaDB).

### PHPUnit — Core Tests

| Test file | Cases | What is covered |
|-----------|------:|----------------|
| `backup_restore_test.php` | 7 | Duplicating an activity copies its word pool and rebuilds the course cache without creating a duplicate grade item; every `install.xml` column of `playercross_attempts` is statically diffed against the backup step's own declared attributes, so a column added later can never silently revert to its default on restore — the regression guard added after `win_condition` itself was once missing from this list; word `timemodified` survives backup/restore; a PlayerHUD item reference survives a same-course "Duplicate activity" unchanged; a full course backup/restore into a new course remaps the reference to the new item's id; a reference to another course's item is dropped rather than kept pointing at the wrong course |
| `cross_instance_security_test.php` | 4 | Session round state, word lookups, attempt records, and the attempt-history query never leak between two different activity instances, even for the same student in the same course |
| `lib_grant_potential_test.php` | 6 | The `playerhud_grant_potential` callback discovered by PlayerHUD's own "Total XP in the game" ceiling estimate: empty for an unrecognised block instance, for an activity with no win-grant item configured, and for an unlimited activity (mirrors the anti-farming rule on the real grant); a bounded activity returns one row shaped like PlayerHUD's own breakdown entries; a win-grant item belonging to a different course's block instance contributes nothing; two bounded activities in the same course each contribute their own row |
| `lib_reset_userdata_test.php` | 4 | Course reset deletes attempts and resets grades only when the checkbox is enabled, only for the target course, and the form default enables it |
| `completion/custom_completion_test.php` | 6 | Custom completion rule ("require completed rounds"): incomplete below threshold, complete at threshold, rule not reported as available when disabled, defined rule names, rule description includes the required count, display sort order |
| `privacy/provider_test.php` | 13 | Metadata declaration (including the site-wide "seen intro" user preference); contexts by attempts; contexts by words added; list users in context (and no-op for a non-module context); export user data (and no-op for an empty contextlist); delete data for a single user across multiple contexts; delete data for multiple users; delete all users' data in a context (and no-op for a non-module context) |
| **Subtotal** | **40** | |

### Local Business-Logic Tests (`tests/local/`)

| Test file | Cases | What is covered |
|-----------|------:|----------------|
| `ai_word_generator_test.php` | 12 | AI response parsing (`words`/legacy `concepts` wrappers, bare list, markdown code fence stripped, malformed/non-array JSON, hint falls back to `definition`, non-array entries skipped) and untrusted-input term validation (single alphabetic word accepted; empty, multi-word, and non-alphabetic terms rejected) — all via reflection, no real AI call |
| `attempts_history_service_test.php` | 5 | Own attempt history scoped to the given user; grade summary hidden for an ungraded activity; all-students report paginates and falls back to a safe sort column on an unknown key; the student filter restricts to one student's own rows; a user who can manage the activity is excluded from the report |
| `gameplay_service_test.php` | 8 | Per-clue point ceiling splits the grade evenly across `num_clues` (and is zero with zero clues); clue points are always full credit with unlimited attempts; full credit within the first two attempts, then decreasing linearly afterwards; the final-guess bonus equals the full grade when nothing is resolved yet and shrinks as more clues are resolved; the session key builder |
| `hud_service_test.php` | 22 | Delegates to block_playerhud's own item API for every operation, validating ownership against the caller's own block instance: block lookup across courses; whether block_playerhud is installed; course availability (with/without a block instance, ignoring another course's); item name resolution; item list retrieval; consume items (insufficient funds, success, FIFO order, zero-quantity short-circuit, waived for a foreign-instance item); grant items (inventory plus XP awarded, XP withheld when flagged unbounded, zero-XP items award nothing, invalid/foreign-instance/zero-quantity items are no-ops) |
| `intro_service_test.php` | 5 | The site-wide "seen intro" user preference: false by default; flips true and stays true (idempotent); isolated per user; the preference name is prefixed with the plugin's Frankenstyle component |
| `puzzle_builder_test.php` | 8 | Full slot coverage across theme and clues; a letter exclusive to the clues still shares its slot correctly; graceful degradation for an uncoverable mystery-phrase letter, and that degradation can be disabled; the mystery phrase text comes from the theme word's own hint, never its concept; a hard failure when the word pool is insufficient; shared word-mode determinism; the greedy clue-selection tie-break is deterministic |
| `ranking_service_test.php` | 5 | Empty ranking; score-descending ordering; top-5 truncation with an outsider row for a lower-ranked current user; `SEPARATEGROUPS` filters to the student's own group; a user who can manage the activity never appears in the ranking, even with attempts of their own |
| `round_presenter_test.php` | 30 | Mystery-phrase tile rendering (respects revealed slots, hidden tiles carry their slot number, all tiles revealed once finished, grouped by word); clue-row rendering (unresolved word hidden, revealed once the round finishes, revealed once resolved, exhausted-attempts label shown only when actually exhausted, the mystery phrase is always shown, a cross-revealed shared letter is reflected); cooldown text (inactive/active, reflects a later settings change); feedback message varies by outcome; grading-method relevance info; grade-so-far summary (absent with no grade item, shown once finished); lobby context (PlayerHUD cost/balance, can-start with enough balance, timer info only when enabled, clues-this-round count); round-panel context (timeleft zero before start, hides reveal while active, global-hint availability); round-result context (blank until finished, reveals on finish, PlayerHUD win-grant label shown only on an actual win) |
| `round_service_test.php` | 20 | Round state defaults and discarding structurally stale state; building the puzzle on demand; clue-guess submission (wrong increments attempts, correct resolves and reveals shared slots); resolving every clue alone does not finish the round; a correct final guess alone does not finish the round; a wrong final guess keeps the round open; clues-then-final-guess and final-guess-then-clues both finish and win the round; clue exhaustion ends the round as a loss under "both required", but not under "mystery-phrase only"; under "mystery-phrase only", resolving every clue alone still does not finish the round, while the final guess alone wins immediately; forfeit ends the round as a loss; timeout rejected before the deadline; a new round resets state; rounds-played count and cooldown; the `round_started` and `round_completed` events both fire at the right moment |
| `view_page_service_test.php` | 15 | Page-assembly branches: fresh lobby, a picked puzzle persists across calls, a finished round computes a real cooldown, restriction notice when the round limit is reached; forfeit action shown only during an active round; toolbar URLs always present, manager-only toolbar hidden from students; ranking link hidden when ranking is disabled; PlayerHUD help shown when a win reward is configured; auto-show intro flagged once on the lobby and does not repeat across a different activity, and is also flagged correctly on the finished-round and restriction-notice branches; the help context always carries the review-hint pointer |
| `word_normalizer_test.php` | 21 | Accent-insensitive normalisation across 8 diacritic/case combinations; `is_valid_charset` accepts letters only (including accented ones) and rejects digits, spaces, a hyphen, an apostrophe, and an empty string, across 8 cases; `chars()` splits a normalized word into individual characters across 4 cases without tearing multi-byte sequences — the reason `puzzle_builder::cipher_slots()` relies on this method instead of a plain byte split |
| `words_repository_test.php` | 8 | Theme-word candidates respect the mystery phrase's own minimum length with no upper bound by default, and respect a real maximum length once one is configured; clue candidates are bounded by their own independent length range; shared word-mode theme selection is deterministic across calls for the same round number; random mode avoids an excluded theme id while an alternative exists; the last-played theme word id returns the most recent one, and zero when there are no attempts yet; word existence checks are case-insensitive and scoped to their own activity instance |
| **Subtotal** | **159** | |

### Web Services Tests (`tests/external/`)

| Test file | Cases | What is covered |
|-----------|------:|----------------|
| `count_eligible_theme_words_test.php` | 5 | Counts only approved hints whose total letter count falls within the requested range; excludes a hint over a real (non-zero) maximum length; excludes unapproved words; scoped to its own activity instance; requires the `mod/playercross:addinstance` capability (rejects a student) |
| `count_eligible_words_test.php` | 5 | Counts only approved pool words whose length falls within the requested range; excludes unapproved words and words outside the range; scoped to its own activity instance; requires the `mod/playercross:addinstance` capability |
| `count_glossary_candidates_test.php` | 4 | Counts candidate words for a specific glossary within the requested length range; excludes words outside the range; a stopword passed straight from the settings form drops the matching token before counting; requires the `mod/playercross:addinstance` capability |
| `end_round_test.php` | 4 | Forfeit finishes the round; timeout finishes the round; an invalid `reason` value is rejected; the `mod/playercross:view` capability is required |
| `new_round_test.php` | 3 | A new round picks a fresh puzzle; blocked when the round limit was already reached; the `mod/playercross:view` capability is required |
| `reveal_hint_test.php` | 5 | Reveals one more tile; rejected once every slot is already revealed; the `mod/playercross:view` capability is required; an insufficient PlayerHUD item balance blocks the reveal; a cost pointing at a deleted item is waived instead |
| `start_round_test.php` | 5 | Round starts; rejected when already started; the `mod/playercross:view` capability is required; an insufficient PlayerHUD item balance blocks starting; a cost pointing at a deleted item is waived instead |
| `submit_clue_guess_test.php` | 3 | A wrong clue guess never leaks the clue word; resolving every clue only reveals the theme word once the round actually finishes; an outsider (no enrolment/capability) cannot submit a guess |
| `submit_final_guess_test.php` | 3 | A wrong final guess never leaks the theme word; a correct final guess alone does not win the round or reveal the theme word (under "both required"); resolving all clues and then guessing the final phrase wins the round and reveals the theme word |
| **Subtotal** | **37** | |

| **Grand Total** | **236** | |

```bash
vendor/bin/phpunit --testsuite mod_playercross
```

**Line coverage by class (PHPUnit + Xdebug):**

| Class | Line coverage |
|-------|:-------------:|
| `completion\custom_completion` | 100% |
| `external\count_eligible_theme_words` | 70% |
| `external\count_eligible_words` | 70% |
| `external\count_glossary_candidates` | 55% |
| `external\end_round` | 73% |
| `external\new_round` | 49% |
| `external\reveal_hint` | 57% |
| `external\start_round` | 74% |
| `external\submit_clue_guess` | 22% |
| `external\submit_final_guess` | 66% |
| `local\ai_word_generator` | 25% |
| `local\attempts_history_service` | 75% |
| `local\gameplay_service` | 94% |
| `local\hud_service` | 91% |
| `local\intro_service` | 100% |
| `local\puzzle_builder` | 43% |
| `local\ranking_service` | 78% |
| `local\round_presenter` | 69% |
| `local\round_service` | 44% |
| `local\view_page_service` | 25% |
| `local\word_normalizer` | 30% |
| `local\words_repository` | 28% |
| `privacy\provider` | 86% |
| **Overall** | **48%** |

The `event/*.php` classes aren't listed — Moodle only loads them lazily when the
corresponding event actually fires, so the instrumentation never sees them.
