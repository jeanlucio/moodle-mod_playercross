# 🧮 Grading & Ranking

PlayerCross computes a **grade** and a **ranking** total from the same finished rounds, but the
two are configured completely independently — a teacher can keep the grade simple while still
rewarding efficient play in the ranking, or the other way around.

**Both are entirely optional, and each is switched on or off on its own:**

* **Grade:** leave the standard `Grade` field set to *None* to run the activity fully ungraded —
  no grade is ever computed or written to the gradebook, and the `Grading method` / `Grade
  scoring mode` settings disappear from the form.
* **Ranking:** leave `Show ranking` set to *No* to hide the ranking everywhere — in-game, on the
  dedicated ranking page, and the extra column in the attempt history — and the `Ranking scoring
  mode` setting disappears from the form too.

Turning one off never affects the other: an activity can be graded with no ranking, ranked with
no grade, both, or neither.

**Per-round scoring** decides how much a single round is worth, chosen separately for the grade
and for the ranking (`Grade scoring mode` / `Ranking scoring mode` settings, both default to
**Binary**):

| Mode | A won round is worth... | A lost round |
|---|---|---|
| **Binary** (default) | The full activity grade | Zero |
| **Linear** | A share that decreases with every wrong guess made — across every term *and* the mystery phrase, counted together as a single pool | Zero |

Terms carry no per-term point value of their own — there is no separate score bucket per term.
Instead every wrong guess, whether on a term or on the mystery phrase itself, draws from the same
shared error pool, and that pool's size determines the whole round's Linear score:

```
max_errors = num_terms × (max_attempts_per_term − 1) + (max_attempts_final_guess − 1)
points     = grade × (max_errors − errors_used + 1) / (max_errors + 1)
```

Unlike a grace period that forgives the first mistake or two, Linear here has none: the very
first wrong guess already reduces the score. A flawless run (zero wrong guesses anywhere) is
still always exactly full credit, and the score never reaches zero for a genuinely completed
win — it floors at `grade / (max_errors + 1)` even at the maximum error budget. Because
`max_errors` depends on both attempts settings, **choosing Linear for either the grade or the
ranking requires `Maximum attempts per term` and `Maximum attempts for the mystery phrase` to
both be a real number, not unlimited** — the settings form blocks saving otherwise.

Worked example with a 100-point grade, 5 terms, 3 attempts per term, 3 attempts for the mystery
phrase (`max_errors = 5 × 2 + 2 = 12`):

| Errors | Points | Errors | Points | Errors | Points |
|---:|---:|---:|---:|---:|---:|
| 0 | 100.00 | 5 | 61.54 | 10 | 23.08 |
| 1 | 92.31 | 6 | 53.85 | 11 | 15.38 |
| 2 | 84.62 | 7 | 46.15 | 12 | 7.69 |
| 3 | 76.92 | 8 | 38.46 | Not completed | 0.00 |
| 4 | 69.23 | 9 | 30.77 | | |

**Early-guess bonus:** guessing the mystery phrase correctly before resolving any term adds a
flat 10% of the activity's grade on top of the base score above. For the **grade**, this is
capped at the activity's nominal maximum — a flawless run already at 100% stays at 100%. For the
**ranking**, it is uncapped — the same flawless run's ranking total becomes 110, legitimately
exceeding the nominal grade, since the ranking rewards efficient early deduction beyond what a
gradebook value can represent.

**Combining several rounds into one final grade** is a separate setting, `Grading method`
(highest grade, average grade, first attempt, last attempt, or average over all required rounds).
It works the same regardless of whether the per-round scoring above is Binary or Linear: it only
ever aggregates whatever value each round already recorded.

**The ranking** is the sum of every finished round's ranking points for a student (`SUM`),
ordered highest first; ties are broken by fewer attempts used on average, then less time spent on
average. It only appears when the teacher enables "Show ranking", and never reveals a round still
in progress.

**Only the top 5 are shown — deliberately, not a bug:** both the in-game ranking widget and the
dedicated ranking page cap the list at 5 rows, to avoid publicly ranking every student in the
class. A student ranked lower still sees exactly where they stand: an extra row, separated by
"…", shows their own real position and score, without exposing anyone else's rank below 5th.
Anyone who can manage the activity (editingteacher, manager) never appears in the ranking at all,
even if they play the activity themselves — the same way their own attempts are excluded from the
attempt report below.

**"Show ranking" only controls visibility, not data collection:** ranking points are computed and
stored for every finished round regardless of whether the setting is on or off at the time.
Turning it on after students have already played reveals the full total accumulated since the
activity started, not just the points earned from that moment forward — nothing is lost, and
nothing needs to be "recovered" by switching it off and back on.

**Locked once graded:** the moment the activity records a real grade for any student, `Terms per
round`, `Grading method`, `Maximum attempts per term`, `Maximum attempts for the mystery phrase`,
`Grade scoring mode` and `Ranking scoring mode` all lock — the same way Moodle already locks a
graded activity's own "Maximum grade" field once real grades exist. Since the Linear formula's
error budget is a direct function of the terms count and both attempts settings, changing any of
them after real scores exist would make earlier and later rounds worth different things; locking
them guarantees every round ever recorded stays internally consistent for the activity's whole
lifetime.

**Attempt history:** each student can review their own past rounds — mystery phrase, terms
resolved, attempts used, time, grade score and (when ranking is enabled) ranking points — from
the toolbar's attempt-history page. Whoever can manage the activity sees the same page turn into
a report covering every student instead: one table, sortable by clicking any column header, and
filterable to a single student. Like the ranking, it never includes a manager's own attempts,
even if they played the activity themselves.
