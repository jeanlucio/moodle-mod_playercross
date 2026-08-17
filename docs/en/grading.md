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
**Binary**). The grade is scored against the activity's **own configured maximum grade**; the
**ranking always uses its own fixed 100-point base, completely independent of the grade** — even
when the activity has no grade at all (`Grade` = *None*, the form's default), the ranking still
works normally:

| Mode | A won round is worth... | A lost round |
|---|---|---|
| **Binary** (default) | The full base (the activity's grade, or a fixed 100 points for ranking) | Zero |
| **Linear** | A share that decreases with every wrong guess made — across every term *and* the mystery phrase, counted together as a single pool | Zero |

Terms carry no per-term point value of their own: every wrong guess, whether on a term or on the
mystery phrase itself, draws from the same shared error pool, and that pool's size determines the
whole round's Linear score:

```
max_errors        = num_terms × (max_attempts_per_term − 1) + (max_attempts_final_guess − 1)
points (grade)    = grade × (max_errors − errors_used + 1) / (max_errors + 1)
points (ranking)  = 100   × (max_errors − errors_used + 1) / (max_errors + 1)
```

Linear has no grace period: the very first wrong guess already reduces the score. A flawless run
(zero wrong guesses anywhere) is still always exactly full credit, and the score never reaches
zero for a genuinely completed win — it floors at `base / (max_errors + 1)` even at the maximum
error budget. Because `max_errors` depends on both attempts settings, **choosing Linear for
either the grade or the ranking requires `Maximum attempts per term` and `Maximum attempts for
the mystery phrase` to both be a real number, not unlimited** — the settings form blocks saving
otherwise.

Worked example with 5 terms, 3 attempts per term, 3 attempts for the mystery phrase
(`max_errors = 5 × 2 + 2 = 12`) — the grade column assumes a 100-point maximum grade, but the
ranking column is exactly this in **any** activity, even one with no grade configured at all:

| Errors | Grade (100-point base) | Ranking (100-point base, always) | Errors | Grade | Ranking |
|---:|---:|---:|---:|---:|---:|
| 0 | 100.00 | 100.00 | 7 | 46.15 | 46.15 |
| 1 | 92.31 | 92.31 | 8 | 38.46 | 38.46 |
| 2 | 84.62 | 84.62 | 9 | 30.77 | 30.77 |
| 3 | 76.92 | 76.92 | 10 | 23.08 | 23.08 |
| 4 | 69.23 | 69.23 | 11 | 15.38 | 15.38 |
| 5 | 61.54 | 61.54 | 12 | 7.69 | 7.69 |
| 6 | 53.85 | 53.85 | Not completed | 0.00 | 0.00 |

**Early-guess bonus:** guessing the mystery phrase correctly before resolving any term adds a flat
10% on top of the base score above — 10% of the activity's grade, for the **grade**; 10% of the
fixed 100-point base (i.e. always +10 points), for the **ranking**. For the **grade**, this is
capped at the activity's nominal maximum — a flawless run already at 100% stays at 100%. For the
**ranking**, it is uncapped — the same flawless run's ranking total becomes 110, legitimately
exceeding the nominal 100-point base, since the ranking rewards efficient early deduction beyond
what a gradebook value can represent.

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
round`, `Grading method`, `Maximum attempts per term`, `Maximum attempts for the mystery phrase`
and `Grade scoring mode` all lock — the same way Moodle already locks a graded activity's own
"Maximum grade" field once real grades exist. Since the Linear formula's error budget is a direct
function of the terms count and both attempts settings, changing any of them after real scores
exist would make earlier and later rounds worth different things; locking them guarantees every
round ever recorded stays internally consistent for the activity's whole lifetime.

**`Ranking scoring mode` locks separately, the moment any finished attempt exists** — it doesn't
wait for a real grade, because ranking points are already computed and stored for every finished
round regardless of whether `Grade` or `Show ranking` are even on (see above). An entirely
ungraded, ranking-only activity already accumulates real history from its very first round; locking
the scoring mode once that history exists prevents the same scale inconsistency the lock above
prevents for the grade.

> **Known limitation:** before this fix, ranking points were computed as a fraction of the
> activity's grade — the very bug that motivated making the ranking independent. Ranking totals
> from rounds finished before the fix were **not recalculated** and may not be on the same scale
> as rounds finished afterwards; that history was not migrated.

**Attempt history:** each student can review their own past rounds — mystery phrase, terms
resolved, attempts used, time, grade score and (when ranking is enabled) ranking points — from
the toolbar's attempt-history page. Whoever can manage the activity sees the same page turn into
a report covering every student instead: one table, sortable by clicking any column header, and
filterable to a single student. Like the ranking, it never includes a manager's own attempts,
even if they played the activity themselves.
