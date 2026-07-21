# 🧮 Grading & Ranking

Unlike PlayerWords, PlayerCross has no configurable per-round scoring mode (no Binary/Linear
toggle) — points are computed by a single fixed formula that rewards both careful clue-solving
and confident direct guessing of the mystery phrase. The same computed score feeds both the
**grade** and the **ranking**.

**Both are entirely optional, and each is switched on or off on its own:**

* **Grade:** leave the standard `Grade` field set to *None* (the default) to run the activity
  fully ungraded — no grade is ever computed or written to the gradebook.
* **Ranking:** leave `Show ranking` set to *No* to hide the ranking everywhere — in-game, on the
  dedicated ranking page, and the extra column in the attempt history.

Turning one off never affects the other: an activity can be graded with no ranking, ranked with
no grade, both, or neither.

**How a round earns points:**

* The activity's configured grade is split evenly across the round's clues: `grade ÷ num_clues`
  is the ceiling a single resolved clue can ever be worth.
* **Resolving a clue** earns full credit on the first two attempts — a confident second guess is
  not treated as less deserving than a first-try one — then scales down linearly as `Maximum
  attempts per clue` is approached. With unlimited attempts per clue (0), a resolved clue always
  earns full credit, since there is no natural denominator to scale against.
* **Guessing the mystery phrase directly** earns a bonus inversely proportional to how many
  clues were already resolved at that moment: guessing before solving anything is worth as much
  as every remaining clue would have been at full credit each, rewarding early deduction;
  guessing after every clue is already solved earns no bonus, since full credit was already
  collected from the clues themselves.
* The total a round can ever earn — resolved-clue points plus the final-guess bonus — is always
  bounded by the activity's configured grade, regardless of `Win condition`.

**Combining several rounds into one final grade** is a separate setting, `Grading method`
(highest grade, average grade, first attempt, last attempt, or average over all required
rounds). It only ever aggregates whatever score each round already recorded.

**The ranking** is the sum of every finished round's score for a student (`SUM`), ordered
highest first; ties are broken by fewer attempts used on average, then less time spent on
average. It only appears when the teacher enables "Show ranking", and never reveals a round
still in progress.

**Only the top 5 are shown — deliberately, not a bug:** both the in-game ranking widget and the
dedicated ranking page cap the list at 5 rows, to avoid publicly ranking every student in the
class. A student ranked lower still sees exactly where they stand: an extra row, separated by
"…", shows their own real position and score, without exposing anyone else's rank below 5th.
Anyone who can manage the activity (editingteacher, manager) never appears in the ranking at
all, even if they play the activity themselves — the same way their own attempts are excluded
from the attempt report below.

**"Show ranking" only controls visibility, not data collection:** scores are computed and
stored for every finished round regardless of whether the setting is on or off at the time.
Turning it on after students have already played reveals the full total accumulated since the
activity started, not just the points earned from that moment forward.

**Locked once graded:** the moment the activity records a real grade for any student, `Clues per
round` (`num_clues`) and `Grading method` both lock — the same way Moodle already locks a
graded activity's own "Maximum grade" field once real grades exist. Since the score-per-clue
denominator depends directly on `num_clues`, changing it after real scores exist would make
earlier and later rounds worth different things; locking it guarantees every round ever
recorded stays internally consistent for the activity's whole lifetime.

**Attempt history:** each student can review their own past rounds — mystery phrase, clues
resolved, attempts used, time, and score — from the toolbar's attempt-history page. Whoever can
manage the activity sees the same page turn into a report covering every student instead: one
table, sortable by clicking any column header, and filterable to a single student. Like the
ranking, it never includes a manager's own attempts, even if they played the activity
themselves.
