# 🧪 Automated Tests

PlayerCross ships with a PHPUnit test suite covering business logic, repository queries, web
services, and Privacy API compliance, plus a Behat suite covering the puzzle's gameplay, HUD
integration, and reports end-to-end in a real browser. Every CI push runs against the full
matrix (Moodle 4.5 → 5.x, PostgreSQL & MariaDB).

### PHPUnit — Core Tests

| Test file | Cases | What is covered |
|-----------|------:|----------------|
| `backup_restore_test.php` | 7 | Duplicating an activity copies its word pool and rebuilds the course cache without creating a duplicate grade item; every `install.xml` column of `playercross_attempts` is statically diffed against the backup step's own declared attributes, so a column added later can never silently revert to its default on restore — the regression guard added after `win_condition` itself was once missing from this list; word `timemodified` survives backup/restore; a PlayerHUD item reference survives a same-course "Duplicate activity" unchanged; a full course backup/restore into a new course remaps the reference to the new item's id; a reference to another course's item is dropped rather than kept pointing at the wrong course |
| `cross_instance_security_test.php` | 4 | Session round state, word lookups, attempt records, and the attempt-history query never leak between two different activity instances, even for the same student in the same course |
| `lib_grant_potential_test.php` | 6 | The `playerhud_grant_potential` callback discovered by PlayerHUD's own "Total XP in the game" ceiling estimate: empty for an unrecognised block instance, for an activity with no win-grant item configured, and for an unlimited activity (mirrors the anti-farming rule on the real grant); a bounded activity returns one row shaped like PlayerHUD's own breakdown entries; a win-grant item belonging to a different course's block instance contributes nothing; two bounded activities in the same course each contribute their own row |
| `lib_reset_userdata_test.php` | 4 | Course reset deletes attempts and resets grades only when the checkbox is enabled, only for the target course, and the form default enables it |
| `completion/custom_completion_test.php` | 6 | Custom completion rule ("require completed rounds"): incomplete below threshold, complete at threshold, rule not reported as available when disabled, defined rule names, rule description includes the required count, display sort order |
| `privacy/provider_test.php` | 14 | Metadata declaration; export of the site-wide "seen intro" user preference, both absent and set; contexts by attempts; contexts by words added; list users in context (and no-op for a non-module context); export user data (and no-op for an empty contextlist); delete data for a single user across multiple contexts; delete data for multiple users; delete all users' data in a context (and no-op for a non-module context) |
| **Subtotal** | **41** | |

### Local Business-Logic Tests (`tests/local/`)

| Test file | Cases | What is covered |
|-----------|------:|----------------|
| `ai_word_generator_test.php` | 12 | AI response parsing (`words`/legacy `concepts` wrappers, bare list, markdown code fence stripped, malformed/non-array JSON, hint falls back to `definition`, non-array entries skipped) and untrusted-input term validation (single alphabetic word accepted; empty, multi-word, and non-alphabetic terms rejected) — all via reflection, no real AI call |
| `attempts_history_service_test.php` | 11 | Own attempt history scoped to the given user, most recent first, and empty without any attempts; the reported grade matches `playercross_calculate_user_grade()` for the configured grading method; a row falls back to the raw word when no concept was recorded; time used is formatted as m:ss; grade summary hidden for an ungraded activity; all-students report paginates and falls back to a safe sort column on an unknown key, filters to one student, sorts by score ascending, and lists every student most recent first; a user who can manage the activity is excluded from both the report and the student-filter dropdown |
| `gameplay_service_test.php` | 8 | Per-clue point ceiling splits the grade evenly across `num_clues` (and is zero with zero clues); clue points are always full credit with unlimited attempts; full credit within the first two attempts, then decreasing linearly afterwards; the final-guess bonus equals the full grade when nothing is resolved yet and shrinks as more clues are resolved; the session key builder |
| `hud_service_test.php` | 26 | Delegates to block_playerhud's own item API for every operation, validating ownership against the caller's own block instance: block lookup across courses; whether block_playerhud is installed; course availability (with/without a block instance, ignoring another course's); item name resolution; item list retrieval; consume items (insufficient funds, success, FIFO order, zero-quantity short-circuit, waived for a foreign-instance item); grant items (inventory plus XP awarded, XP withheld when flagged unbounded, zero-XP items award nothing, invalid/foreign-instance/zero-quantity items are no-ops); bulk XP resolution across several items in one pass, an item from another block instance omitted from the map, an empty id list short-circuits without querying, and duplicate ids are resolved once |
| `intro_service_test.php` | 5 | The site-wide "seen intro" user preference: false by default; flips true and stays true (idempotent); isolated per user; the preference name is prefixed with the plugin's Frankenstyle component |
| `puzzle_builder_test.php` | 9 | Full slot coverage across theme and clues; a letter exclusive to the clues still shares its slot correctly; graceful degradation for an uncoverable mystery-phrase letter, and that degradation can be disabled; the mystery phrase text comes from the theme word's own hint, never its concept; original accented spelling survives alongside the normalized clue word and theme hint; a hard failure when the word pool is insufficient; shared word-mode determinism; the greedy clue-selection tie-break is deterministic |
| `ranking_service_test.php` | 6 | Empty ranking; score-descending ordering; top-5 truncation with an outsider row for a lower-ranked current user; `SEPARATEGROUPS` filters to the student's own group, and unions the deduplicated membership of every group the student belongs to; a user who can manage the activity never appears in the ranking, even with attempts of their own |
| `round_presenter_test.php` | 39 | Mystery-phrase tile rendering (respects revealed slots, hidden tiles carry their slot number, all tiles revealed once finished, grouped by word); clue-row rendering (unresolved word hidden, revealed once the round finishes, revealed once resolved, exhausted-attempts label shown only when actually exhausted, the mystery phrase is always shown, a cross-revealed shared letter is reflected); cooldown text (inactive/active, reflects a later settings change); feedback message varies by outcome; grading-method relevance info; grade-so-far summary (absent with no grade item, shown once finished); lobby context (PlayerHUD cost/balance, can-start with enough balance, timer info only when enabled, clues-this-round count, no cost shown to the guest account); round-panel context (timeleft zero before start, hides reveal while active, global-hint availability, remaining-hints count shown, the hint button hides once the configured reveal limit is reached, hint button shows/omits its PlayerHUD cost, can-afford-hint with enough balance, no hint cost shown to the guest account, cedilla availability reflects the word pool); round-result context (blank until finished, reveals on finish, PlayerHUD win-grant label shown only on an actual win, omitted on a loss, and omitted for the guest account even on a win) |
| `round_service_test.php` | 44 | Round state defaults and discarding structurally stale state, including state missing the reveal-spelling fields from an older session; building the puzzle on demand, and refusing to pick a fresh puzzle once `max_rounds`/cooldown restricts the student; a hint reveal stops once the configured per-round limit is reached, and hints alone can finish and win the round; clue-guess submission (wrong increments attempts, correct resolves and reveals shared slots); resolving every clue alone does not finish the round; a correct final guess alone does not finish the round, and auto-resolves any clue left made entirely of already-shared letters; a wrong final guess keeps the round open; clues-then-final-guess and final-guess-then-clues both finish and win the round; clue exhaustion ends the round as a loss under "both required", but not under "mystery-phrase only"; under "mystery-phrase only", resolving every clue alone still does not finish the round, while the final guess alone wins immediately; forfeit ends the round as a loss and never grants the win item; timeout rejected before the deadline; a new round resets state; rounds-played count and cooldown; restriction-notice variants (round limit reached, cooldown active, unrestricted); cooldown computation (disabled, expired by time, reflects a later settings change); the `round_started` and `round_completed` events both fire at the right moment; winning grants the configured PlayerHUD item with XP when bounded and without XP when unlimited; starting a round or revealing a hint waives its PlayerHUD cost when the configured item was deleted or belongs to another course, but still blocks when the item is merely disabled and the balance is insufficient; submitting a clue guess, a final guess, or revealing a hint is rejected outright before the round has actually been started, closing the bypass that would otherwise skip its configured PlayerHUD cost; the guest account is never charged starting a round or revealing a hint, and a won round leaves no attempt row, grade update, or PlayerHUD grant behind |
| `view_page_service_test.php` | 23 | Page-assembly branches: fresh lobby, a picked puzzle persists across calls, a finished round computes a real cooldown, restriction notice when the round limit is reached; forfeit action shown only during an active round; toolbar URLs always present, manager-only toolbar hidden from students and shown for teachers, and a non-editing teacher (report-only capability) sees the report link but not the manage-words one; inactive words hidden from students, shown to a manager, and the active count shown alone when nothing is inactive; ranking link hidden when ranking is disabled; PlayerHUD help shown when a win reward is configured; win-condition help text defaults to "both required" and reflects the "mystery-phrase only" setting; the clue-loss warning is shown when attempts per clue are limited and hidden when unlimited; auto-show intro flagged once on the lobby and does not repeat across a different activity, and is also flagged correctly on the finished-round and restriction-notice branches; the help context always carries the review-hint pointer |
| `word_normalizer_test.php` | 29 | Accent-insensitive normalisation across 8 diacritic/case combinations; `is_valid_charset` accepts letters only (including accented ones) and rejects digits, spaces, a hyphen, an apostrophe, and an empty string, across 8 cases; `chars()` splits a normalized word into individual characters across 4 cases without tearing multi-byte sequences — the reason `puzzle_builder::cipher_slots()` relies on this method instead of a plain byte split; `normalize_phrase()` splits a free-text phrase into normalized word tokens across 8 cases, treating digits/punctuation/hyphens/apostrophes as separators and collapsing extra whitespace — the exact split `submit_final_guess()` runs a player's mystery-phrase guess through |
| `words_repository_test.php` | 51 | Theme-word and clue candidates respect their own independent length ranges; shared and random theme-word selection, and the last-played theme word id; word existence checks (case-insensitive, scoped, ignoring an excluded id, regardless of source); cedilla-word detection (present, absent, ignores unapproved, scoped to its own instance); manual and AI word insertion, lookup, update, and delete (all scoped to the owning instance); bulk delete and bulk approve; recent-words listing, including the glossary name; Glossary sync (disabled without the source bit, single- and multi-word concepts, configured stopwords, hint resync, orphan removal, scope to one or all course glossaries, skipping a word owned by another source); fragmented-concept reporting (split multi-word concepts, single-word concepts excluded, non-Glossary sources ignored, scoped to its own instance); inactive-word detection (length mismatch, invalid charset, a word valid for the theme role only is not reported, unapproved words ignored); theme draw counts (absent, summed regardless of outcome, scoped to its own instance); Glossary candidate counting (within range, across all course glossaries, deduplicated tokens, scoped to its own course, zero when no glossaries exist) |
| **Subtotal** | **263** | |

### Web Services Tests (`tests/external/`)

| Test file | Cases | What is covered |
|-----------|------:|----------------|
| `count_eligible_theme_words_test.php` | 5 | Counts only approved hints whose total letter count falls within the requested range; excludes a hint over a real (non-zero) maximum length; excludes unapproved words; scoped to its own activity instance; requires the `mod/playercross:managewords` capability (rejects a student) |
| `count_eligible_words_test.php` | 5 | Counts only approved pool words whose length falls within the requested range; excludes unapproved words and words outside the range; scoped to its own activity instance; requires the `mod/playercross:managewords` capability |
| `count_glossary_candidates_test.php` | 4 | Counts candidate words for a specific glossary within the requested length range; excludes words outside the range; a stopword passed straight from the settings form drops the matching token before counting; requires the `mod/playercross:addinstance` capability — the one call site that keeps it, since it runs from the "add activity" form itself, before a course module exists |
| `end_round_test.php` | 4 | Forfeit finishes the round; timeout finishes the round; an invalid `reason` value is rejected; the `mod/playercross:view` capability is required |
| `new_round_test.php` | 3 | A new round picks a fresh puzzle; blocked when the round limit was already reached; the `mod/playercross:view` capability is required |
| `reveal_hint_test.php` | 7 | Reveals one more tile; rejected once every slot is already revealed; rejected once the configured per-round hint limit is reached; the `mod/playercross:view` capability is required; an insufficient PlayerHUD item balance blocks the reveal; a cost pointing at a deleted item is waived instead; rejected when the round was never actually started, bypassing the PlayerHUD hint cost |
| `start_round_test.php` | 7 | Round starts; rejected when already started; the `mod/playercross:view` capability is required; an insufficient PlayerHUD item balance blocks starting; a cost pointing at a deleted item is waived instead; blocked when the round limit was already reached, even starting from a fresh session; the guest account plays a free demo without being charged |
| `submit_clue_guess_test.php` | 5 | A wrong clue guess never leaks the clue word; an ordinary resolved clue is flagged for a toast notification rather than the round-ending banner; resolving every clue only reveals the theme word once the round actually finishes; an outsider (no enrolment/capability) cannot submit a guess; rejected when the round was never actually started, bypassing the PlayerHUD round cost |
| `submit_final_guess_test.php` | 5 | A wrong final guess never leaks the theme word; a correct final guess alone does not win the round or reveal the theme word (under "both required"); resolving all clues and then guessing the final phrase wins the round and reveals the theme word; the guest account plays a free demo through to a win without persisting an attempt or a PlayerHUD grant; rejected when the round was never actually started, bypassing the PlayerHUD round cost |
| **Subtotal** | **45** | |

| **Grand Total** | **349** | |

```bash
vendor/bin/phpunit --testsuite mod_playercross
```

**Line coverage by class (PHPUnit + Xdebug):**

| Class | Line coverage |
|-------|:-------------:|
| `completion\custom_completion` | 100% |
| `external\count_eligible_theme_words` | 100% |
| `external\count_eligible_words` | 100% |
| `external\count_glossary_candidates` | 100% |
| `external\end_round` | 100% |
| `external\new_round` | 100% |
| `external\reveal_hint` | 100% |
| `external\start_round` | 100% |
| `external\submit_clue_guess` | 25% |
| `external\submit_final_guess` | 67% |
| `local\ai_word_generator` | 37% |
| `local\attempts_history_service` | 99% |
| `local\gameplay_service` | 94% |
| `local\hud_service` | 95% |
| `local\intro_service` | 100% |
| `local\puzzle_builder` | 98% |
| `local\ranking_service` | 98% |
| `local\round_presenter` | 98% |
| `local\round_service` | 95% |
| `local\view_page_service` | 96% |
| `local\word_normalizer` | 100% |
| `local\words_repository` | 98% |
| `privacy\provider` | 95% |
| **Overall** | **81%** |

The `event/*.php` classes aren't listed — Moodle only loads them lazily when the
corresponding event actually fires, so the instrumentation never sees them.

`submit_clue_guess` and `submit_final_guess` stay low because most of their own
lines belong to web-service schema-declaration methods (`execute_returns()`,
`panel_structure()`, `roundresult_structure()`) — pure structure, no branches, never
exercised by a test that calls `execute()` directly instead of going through a real
webservice round-trip. `ai_word_generator`'s own external AI-calling methods
(`call_ai()`, `call_core_ai()`, `generate_and_save()`...) are the other deliberate
gap: covering them would mean mocking the actual `local_aihub`/`core_ai` HTTP calls,
judged not worth it for now.

### Behat — End-to-End Tests

PlayerCross also ships a Behat suite that drives the puzzle in a real browser session,
covering gameplay, PlayerHUD integration, teacher-facing reports, and the toolbar/modals —
areas a PHPUnit unit test cannot exercise (JavaScript-driven UI, real page navigation).

| Feature file | Scenarios | What is covered |
|--------------|----------:|----------------|
| `mod_playercross_smoke.feature` | 1 | The lobby loads and a round can be started — the baseline sanity check the rest of the suite builds on |
| `mod_playercross_gameplay.feature` | 12 | Winning a round by guessing the mystery phrase directly hides the timer badge; resolving a clue cross-reveals its shared letters in the mystery phrase; forfeiting an active round asks for confirmation; a round ends automatically once its timer runs out; reaching the round limit hides the new-round action instead of a dead end; a configured cooldown shows a countdown instead of the new-round button; arrow keys move focus between a clue's own boxes without changing any value; arrow keys move focus between rows, reaching the mystery phrase from the first clue; a clue's own submit button stays hidden while its row is still incomplete and appears once every letter is typed; a clue can be submitted by tapping its own row button, not just Enter; submitting one clue preserves another still-open clue's in-progress typing |
| `mod_playercross_playerhud.feature` | 4 | The lobby blocks starting a round until the student can afford the configured item cost; revealing a hint asks for confirmation and enough balance; a round starts and the hint reveals for free once the configured item no longer exists; winning a round grants the configured PlayerHUD item |
| `mod_playercross_reports.feature` | 5 | A student sees only their own attempt history, never another student's; the teacher's all-students report paginates past 30 rows, sorts by clicking a column header, and filters to a single student; the ranking page shows the top 5 plus the current user's own row when they fall outside it |
| `mod_playercross_settings.feature` | 4 | Clue count and grading method freeze once a real grade exists; adding a manual word already in the pool, or containing a character the game cannot use, is rejected; a PlayerHUD item that no longer exists stays selected in the settings form instead of silently resetting |
| `mod_playercross_toolbar.feature` | 8 | The manage-words icon and the inactive-words warning only appear for whoever can manage the activity; the ranking icon only appears when ranking is enabled; the forfeit icon only appears while a round is active; the help modal shows its optional paragraphs only when relevant, and hides them otherwise; the how-to-play modal opens automatically on a player's very first visit, once ever; cancelling the forfeit confirmation leaves the round untouched |
| **Subtotal** | **34** | |

```bash
vendor/bin/behat --config public/behat.yml --profile=chrome --tags @mod_playercross
```
