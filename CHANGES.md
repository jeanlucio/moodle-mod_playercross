# Changelog — mod_playercross

All notable changes to this plugin are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v1.0.2] — 2026-08-22

### Fixed
- Item spends to start a round or reveal a clue's hint were recorded in PlayerHUD's ledger
  without attribution to PlayerCross, showing up in a student's history and reports as a
  generic transaction instead of tied to the activity.
- The PlayerHUD integration could throw a fatal error on a site running `block_playerhud` older
  than v1.7.1, instead of falling back to no integration as intended. The plugin's own settings
  page now also tells an outdated PlayerHUD apart from a missing one, pointing the admin at the
  real fix (upgrade the block) instead of a misleading "not installed" message.

---

## [v1.0.1] — 2026-08-22

### Fixed
- Restored `db/upgrade.php`, with no upgrade steps yet — the Moodle Plugins directory
  submission validator requires this file to exist in the plugin archive even when there
  is no migration to run.

---

## [v1.0.0] — 2026-08-22

### Added
- Initial stable release: a deduction crossword-style vocabulary activity for Moodle. Each
  round draws a mystery phrase (a course concept's own hint) and a set of clues (other
  concepts whose words share letters with it); solving a clue reveals its shared letters
  everywhere they occur, by letter identity rather than spatial position. Students can also
  risk a direct mystery-phrase guess at any point, earning a bonus proportional to how many
  clues were still unresolved.
- A configurable win condition: resolving every clue and guessing the mystery phrase, or the
  mystery-phrase guess alone (clues remain optional but still help by revealing letters). A
  clue that runs out of attempts under the "both required" condition ends the round
  immediately as a loss instead of leaving the student stuck.
- Word sources: manual entry, automatic import from the course Glossary (with per-activity
  stopword filtering), and AI-assisted generation via `local_aihub` (BYOK) or Moodle's
  `core_ai` subsystem, respecting the per-course "Enable AI tools" toggle. Inactive pool words
  are flagged automatically for whoever manages the activity.
- A toggleable per-clue hint system: hint reveal can be turned off entirely for the activity,
  capped to a fixed number of reveals per round (default 3), or left unlimited — optionally at
  a PlayerHUD item cost. Revealing enough hints to uncover every letter wins the round on its
  own.
- Configurable round rules: independent length ranges for the mystery phrase and the clue
  words, 3–10 clues per round, an optional attempts-per-clue cap, round limit (1–10 or
  unlimited) with a configurable cooldown between rounds, word mode (random or a shared
  sequence for the whole class), and accent-insensitive matching with true-spelling reveal —
  including a long-press accent picker on the on-screen keyboard's vowel keys.
- Optional integration with `block_playerhud`: an item can be required to start a round or to
  reveal a clue's hint, and an item can be granted for each round won, with an anti-farming
  rule that withholds the bonus XP on activities configured for unlimited rounds.
- Per-clue scoring (full credit on the first two attempts, then scaled down) plus an optional
  bonus for guessing the mystery phrase directly, combined with four grading methods (highest,
  average, first attempt, last attempt), fully integrated with the Moodle gradebook.
- Top-5 ranking leaderboard (respecting separate groups) and per-student attempt history, with
  a paginated report for whoever manages the activity.
- Custom activity completion rule (minimum completed rounds).
- First-visit onboarding: the how-to-play modal opens automatically once, site-wide, the very
  first time a student encounters the activity, and is always reachable again from the
  toolbar.
- Accessibility: WCAG AA contrast on every puzzle tile, non-colour state indicators,
  `aria-label`s on every input, and per-letter-box focus behaviour mirroring a
  verification-code input.
- Backup and restore (moodle2), full Privacy API compliance, and Portuguese (pt_br)
  translation alongside English.

---
