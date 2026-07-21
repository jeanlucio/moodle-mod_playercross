# ✨ Features

* 🧩 **Deduction Crossword Gameplay:** Each round picks a **mystery phrase** (a course concept's own hint) and a set of **clues** (other concepts whose words share letters with the mystery phrase). Solving a clue reveals its shared letters everywhere they occur — in every pending clue and in the mystery phrase itself — by letter identity, not by spatial position.
* 🎯 **Direct Mystery-Phrase Guess:** Students can risk a direct guess of the mystery phrase at any point in the round, without waiting to resolve every clue first, earning a bonus proportional to how many clues were still unresolved at the moment of the guess.
* ⚖️ **Configurable Win Condition:** Choose whether a round is won only by resolving every clue **and** guessing the mystery phrase, or by the mystery-phrase guess alone (clues still help by revealing letters, but are optional to finish the round). See [Grading & Ranking](grading.html).
* 🛡️ **Automatic Loss on Clue Exhaustion:** Under the "both required" win condition, a clue that runs out of attempts makes winning mathematically impossible — the round ends immediately as a loss instead of leaving the student stuck.
* 🔡 **Uncovered-Slot Handling (Configurable):** A mystery-phrase letter that no selected clue happens to share is, by default, revealed automatically from the start of the round (no clue could ever reveal it "for free" otherwise); teachers can disable this so it stays hidden until a paid hint or the round's end.
* 📖 **Glossary Integration:** Import concepts from one or all course glossaries as the word pool, with definitions used as clue hints.
* 🚧 **Configurable Stopwords:** A per-activity, comma-separated list of words (e.g. "the, of, and") to ignore when splitting a multi-word glossary concept into candidates.
* 🤖 **AI Word Generation (Optional):** Generate candidate words and hints for a given topic via `local_aihub` (BYOK) or Moodle's `core_ai` fallback. Generated words are treated as untrusted input — only single-token, purely alphabetic terms within the configured length bounds are saved, and they enter the pool pending teacher approval.
* ✍️ **Manual Word Pool:** Teachers can add, edit, approve, and delete words directly from the management page.
* 🔀 **Word Modes:** Random mystery phrase per round (default) or shared sequence mode, where every student receives the same puzzle in the same order.
* 📏 **Independent Length Ranges:** The mystery phrase's minimum (and optional maximum) letter-count is configured separately from the clue words' own length range, so a long, descriptive hint can still produce a manageable puzzle.
* 🔢 **Configurable Clue Count:** 3 to 10 clues per round (default 5), balancing puzzle difficulty against pool size.
* ⏳ **Configurable Attempts Per Clue:** Cap the number of guesses allowed per individual clue (0 = unlimited), independently from the mystery-phrase guess itself.
* 💡 **Per-Clue Hint System:** Each clue's own hint (its concept definition) is hidden by default and revealed independently — not one shared hint per round — optionally at an item cost via PlayerHUD.
* 🏳️ **Give Up:** Students can forfeit the current round at any time — the correct mystery phrase and every clue word are revealed immediately.
* ⏱️ **Configurable Cooldown:** Minimum wait between rounds (minutes, hours, or days), always recomputed from the activity's current setting.
* 🔢 **Round Limit:** Teachers can cap the total number of rounds per student (1–10 or unlimited).
* 🔡 **Accent-Insensitive Matching:** Diacritics are always stripped before comparing a guess and its target.
* 🧮 **Per-Clue Scoring:** Points accumulate as each clue is resolved (full credit on the first two attempts, then scaled down as the attempt limit is approached), plus an optional bonus for guessing the mystery phrase directly — see [Grading & Ranking](grading.html).
* 📊 **Grading Methods:** Highest grade, average grade, first attempt, last attempt, or average over all required rounds.
* 📋 **Gradebook Integration:** Grades are written automatically on every round completion.
* ✅ **Custom Completion Rule:** Minimum number of completed rounds, evaluated and applied immediately after each round.
* 🔄 **Course Reset Support:** "Reset course" clears student attempts and resets grades for the activity, scoped to the target course only.
* 🏆 **Top 5 Ranking:** Leaderboard scoped to the activity, capped to the top 5, with an outsider row so a lower-ranked student still sees their own real position. Respects `SEPARATEGROUPS`.
* 📋 **Attempt History:** Students review every finished round of their own — mystery phrase, clues resolved, attempts used, time, and score — via the toolbar. Whoever can manage the activity sees every student's history instead, in one paginated report.
* ❓ **In-Game Help:** A dedicated help page explains the puzzle mechanic, attempts, hints, timer, and the activity's win condition and grading method.
* 👋 **First-Visit Onboarding:** The how-to-play modal opens automatically the very first time a user visits any PlayerCross activity on the site — once, ever, site-wide — and never repeats after that; the toolbar help icon always reopens it on demand.
* ♿ **Accessibility:** WCAG AA contrast on every puzzle tile; non-colour state indicators; `aria-label` on every input; per-letter-box focus behaviour mirrors a verification-code input for predictable keyboard and click navigation.
* ⚡ **AJAX-Powered:** Every round transition (clue guess, final guess, hint, forfeit, timeout, start, new round) happens without a page reload.
* 🎮 **PlayerHUD Integration (Optional):** Require inventory items to start a round or to reveal a clue's hint, with atomic FIFO consumption. Can also **grant** an item for each round won; matching PlayerHUD's own anti-farming rule, no XP is awarded from that item while the activity allows unlimited rounds.
* 🛡️ **Safe Cross-Course Integration:** Every PlayerHUD item reference is validated against the course's own block instance, never a stale or another course's item — even after backup/restore or course duplication.
* 📦 **Backup & Restore:** Full Moodle 2 backup/restore support, including the "Duplicate activity" action, word pool, attempts, and safe PlayerHUD item remapping.
* 🔐 **Privacy API:** GDPR/LGPD compliant — complete data export and deletion for all stored personal data.
