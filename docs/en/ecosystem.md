# 🕹️ PlayerGames Ecosystem

PlayerCross is part of the **PlayerGames** gamification ecosystem for Moodle. Its main direct integration is with the PlayerHUD block:

* **PlayerHUD Block (Optional):** Configure item costs for starting a round or revealing a clue's hint, and an item grant for each round won.
  👉 https://github.com/jeanlucio/moodle-block_playerhud

* **PlayerGroup (Compatible):** Standard Moodle groups — created manually or via the PlayerGroup activity — are honoured by the ranking's `SEPARATEGROUPS` filtering.
  👉 https://github.com/jeanlucio/moodle-mod_playergroup

* **PlayerWords (Sibling Activity):** Also part of the ecosystem, PlayerWords tests recall of **one** concept per round in a Wordle-style format. PlayerCross builds on the same word-pool/PlayerHUD/gradebook architecture to add a puzzle that connects **several** concepts in a single round.
  👉 https://github.com/jeanlucio/moodle-mod_playerwords

See the author's [Moodle Plugins Directory profile](https://moodle.org/plugins/browse.php?list=contributor&id=3970322) for the full PlayerGames family.
