@mod @mod_playercross @javascript
Feature: PlayerCross core gameplay loop
  As a student
  I want to play rounds of PlayerCross
  So that I can practise course vocabulary through terms and a mystery phrase

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | teacher1 | Teacher   | One      | teacher1@example.com  |
      | student1 | Student   | One      | student1@example.com  |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And "teacher1" has already seen the playercross intro
    And "student1" has already seen the playercross intro

  Scenario: Student wins a round by guessing the mystery phrase directly, and the timer badge disappears
    Given the following "activities" exist:
      | activity    | course | name      | num_terms | theme_min_length | min_length | max_length | win_condition | timer_minutes |
      | playercross | C1     | Cross Win | 1         | 6                | 3          | 15         | 2             | 1             |
    And the following PlayerCross words exist in activity "Cross Win":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Win" "playercross activity" page
    And I click on "Start round" "button"
    And "#playercross-timer-wrapper" "css_element" should be visible
    When I fill the PlayerCross mystery phrase tiles with "escola"
    And I press enter
    Then I should see "You solved the mystery phrase directly!"
    And I should see "ESCOLA"
    And "#playercross-timer-wrapper" "css_element" should not be visible

  Scenario: Student resolves a term and its shared letters reveal in the mystery phrase
    Given the following "activities" exist:
      | activity    | course | name       | num_terms | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Term | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Term":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Term" "playercross activity" page
    And I click on "Start round" "button"
    When I fill PlayerCross term "1" tiles with "livro"
    And I press enter
    Then "li.mod-playercross-term.is-resolved" "css_element" should exist

  Scenario: Student forfeits an active round with a confirmation dialog
    Given the following "activities" exist:
      | activity    | course | name          | num_terms | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Forfeit | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Forfeit":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Forfeit" "playercross activity" page
    And I click on "Start round" "button"
    When I click on "#playercross-forfeit-button" "css_element"
    And I click on "Yes" "button"
    Then I should see "You gave up this round."

  Scenario: Student's round ends automatically when the timer runs out
    Given the following "activities" exist:
      | activity    | course | name        | num_terms | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Timer | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Timer":
      | word   |
      | escola |
      | livro  |
    And the PlayerCross activity "Cross Timer" has "timer_seconds" set to "2" seconds
    And I log in as "student1"
    And I am on the "Cross Timer" "playercross activity" page
    And I click on "Start round" "button"
    When I wait until "#playercross-round-result" "css_element" exists
    Then I should see "Time is up!"

  Scenario: Reaching the round limit hides the new-round action instead of offering a dead end
    Given the following "activities" exist:
      | activity    | course | name        | num_terms | theme_min_length | min_length | max_length | win_condition | max_rounds |
      | playercross | C1     | Cross Limit | 1         | 6                | 3          | 15         | 2             | 1          |
    And the following PlayerCross words exist in activity "Cross Limit":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Limit" "playercross activity" page
    And I click on "Start round" "button"
    And I fill the PlayerCross mystery phrase tiles with "escola"
    And I press enter
    Then I should see "You solved the mystery phrase directly!"
    And I should see "Rounds played: 1 / 1."
    And "#playercross-new-round-button" "css_element" should not exist

  Scenario: Arrow keys move focus between a term's own boxes without changing values
    Given the following "activities" exist:
      | activity    | course | name        | num_terms | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Arrow | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Arrow":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Arrow" "playercross activity" page
    And I click on "Start round" "button"
    When I fill PlayerCross term "1" tiles with "l"
    And I press the right key
    And I press the right key
    And I press the v key
    Then PlayerCross term "1" tiles should read "l_v__"

  Scenario: Arrow keys move focus between rows, reaching the mystery phrase from the first term
    Given the following "activities" exist:
      | activity    | course | name       | num_terms | theme_min_length | min_length | max_length | reveal_uncovered_slots |
      | playercross | C1     | Cross Rows | 1         | 6                | 3          | 15         | 0                      |
    And the following PlayerCross words exist in activity "Cross Rows":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Rows" "playercross activity" page
    And I click on "Start round" "button"
    When I fill PlayerCross term "1" tiles with "l"
    And I press the up key
    And I press the e key
    Then the PlayerCross mystery phrase tiles should read "e_____"

  Scenario: A term's own submit button stays hidden while the row is still incomplete
    Given the following "activities" exist:
      | activity    | course | name         | num_terms | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Button | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Button":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Button" "playercross activity" page
    And I click on "Start round" "button"
    When I fill PlayerCross term "1" tiles with "livr"
    Then PlayerCross term "1"'s submit button should be "not ready"

  Scenario: A term's own submit button appears once every letter is typed
    Given the following "activities" exist:
      | activity    | course | name         | num_terms | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Button | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Button":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Button" "playercross activity" page
    And I click on "Start round" "button"
    When I fill PlayerCross term "1" tiles with "livro"
    Then PlayerCross term "1"'s submit button should be "ready"

  Scenario: A term can be submitted by tapping its own row button
    Given the following "activities" exist:
      | activity    | course | name       | num_terms | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Tap  | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Tap":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Tap" "playercross activity" page
    And I click on "Start round" "button"
    When I fill PlayerCross term "1" tiles with "livro"
    And I click on "#playercross-terms-list .pc-row-submit" "css_element"
    Then "li.mod-playercross-term.is-resolved" "css_element" should exist

  Scenario: Submitting one term preserves another still-open term's in-progress typing
    Given the following "activities" exist:
      | activity    | course | name           | num_terms | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Preserve | 2         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Preserve":
      | word   |
      | escola |
      | livro  |
      | carro  |
    And I log in as "student1"
    And I am on the "Cross Preserve" "playercross activity" page
    And I click on "Start round" "button"
    When I fill PlayerCross term "1" tiles with "liv"
    And I fill PlayerCross term "2" tiles with "zzzzz"
    And I press enter
    Then I should see "Incorrect guess. Try again."
    And PlayerCross term "1" tiles should read "liv__"

  Scenario: A fresh round shows the configured attempts-remaining count for a term and the mystery phrase
    Given the following "activities" exist:
      | activity    | course | name            | num_terms | theme_min_length | min_length | max_length | max_attempts_per_term | max_attempts_final_guess |
      | playercross | C1     | Cross Attempts  | 1         | 6                 | 3          | 15         | 3                      | 3                         |
    And the following PlayerCross words exist in activity "Cross Attempts":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Attempts" "playercross activity" page
    And I click on "Start round" "button"
    Then "#playercross-terms-list .mod-playercross-attempts-count" "css_element" should exist
    And I should see "×3" in the "#playercross-terms-list" "css_element"
    And "#playercross-final-guess-form .mod-playercross-attempts-count" "css_element" should exist
    And I should see "×3" in the "#playercross-final-guess-form" "css_element"

  Scenario: Running out of attempts for the mystery phrase ends the round as a loss under the default win condition
    Given the following "activities" exist:
      | activity    | course | name           | num_terms | theme_min_length | min_length | max_length | max_attempts_final_guess | cooldown_amount |
      | playercross | C1     | Cross Final Ex | 1         | 6                 | 3          | 15         | 1                         | 0                |
    And the following PlayerCross words exist in activity "Cross Final Ex":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Final Ex" "playercross activity" page
    And I click on "Start round" "button"
    When I fill the PlayerCross mystery phrase tiles with "errado"
    And I press enter
    Then I should see "No attempts left for the mystery phrase"
    And "#playercross-new-round-button" "css_element" should exist

  Scenario: Running out of attempts for the mystery phrase ends the round as a loss under mystery-phrase-only
    Given the following "activities" exist:
      | activity    | course | name             | num_terms | theme_min_length | min_length | max_length | win_condition | max_attempts_final_guess | cooldown_amount |
      | playercross | C1     | Cross Final Only | 1         | 6                 | 3          | 15         | 2              | 1                         | 0                |
    And the following PlayerCross words exist in activity "Cross Final Only":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Final Only" "playercross activity" page
    And I click on "Start round" "button"
    When I fill the PlayerCross mystery phrase tiles with "errado"
    And I press enter
    Then I should see "No attempts left for the mystery phrase"
    And "#playercross-new-round-button" "css_element" should exist

  Scenario: A configured cooldown shows a countdown instead of the new-round button
    Given the following "activities" exist:
      | activity    | course | name           | num_terms | theme_min_length | min_length | max_length | win_condition |
      | playercross | C1     | Cross Cooldown | 1         | 6                | 3          | 15         | 2             |
    And the following PlayerCross words exist in activity "Cross Cooldown":
      | word   |
      | escola |
      | livro  |
    And the PlayerCross activity "Cross Cooldown" has "cooldown_seconds" set to "99999" seconds
    And I log in as "student1"
    And I am on the "Cross Cooldown" "playercross activity" page
    And I click on "Start round" "button"
    And I fill the PlayerCross mystery phrase tiles with "escola"
    And I press enter
    Then I should see "Next round in"
    And "#playercross-cooldown-countdown" "css_element" should exist
    And "#playercross-new-round-button" "css_element" should not exist
