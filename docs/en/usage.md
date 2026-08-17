# 📖 Usage

1. Add a **PlayerCross** activity to your course.
2. Configure:
   * Mystery-phrase length range (independent from the term words' own length range) and number of terms per round
   * Win condition (both terms and mystery phrase required, or the mystery-phrase guess alone) and whether uncovered mystery-phrase letters are auto-revealed
   * Maximum attempts per term and for the mystery phrase, cooldown between rounds, and round limit
   * Whether hints are allowed at all, and if so, the maximum reveals per round (default 3, or unlimited)
   * Word mode (random or shared sequence)
   * Grading method, grade and ranking scoring mode (Binary or Linear), and gradebook settings
   * Word sources (manual, Glossary, AI), Glossary source, and a stopword list to skip when splitting multi-word glossary concepts (all optional)
   * PlayerHUD item costs and win grant (optional, when PlayerHUD block is present)
3. Open the **Manage words** page to add, generate with AI, approve, edit, or delete words.
4. Students play directly from the activity page — resolving terms, guessing the mystery phrase, revealing hints, and forfeiting rounds, with no page reload. The page's own toolbar gives access to the rules (help), attempt history, and the ranking.
5. Grades and ranking update automatically after each round.
