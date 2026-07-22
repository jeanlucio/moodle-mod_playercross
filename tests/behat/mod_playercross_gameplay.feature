@mod @mod_playercross @javascript
Feature: PlayerCross core gameplay loop
  As a student
  I want to play rounds of PlayerCross
  So that I can practise course vocabulary through clues and a mystery phrase

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
      | activity    | course | name      | num_clues | theme_min_length | min_length | max_length | win_condition | timer_minutes |
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
    And I click on "[data-key=\"ENTER\"]" "css_element"
    Then I should see "You solved the mystery phrase directly!"
    And I should see "The mystery phrase was:"
    And I should see "ESCOLA"
    And "#playercross-timer-wrapper" "css_element" should not be visible

  Scenario: Student resolves a clue and its shared letters reveal in the mystery phrase
    Given the following "activities" exist:
      | activity    | course | name       | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Clue | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Clue":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Clue" "playercross activity" page
    And I click on "Start round" "button"
    When I fill PlayerCross clue "1" tiles with "livro"
    And I click on "[data-key=\"ENTER\"]" "css_element"
    Then "li.mod-playercross-clue.is-resolved" "css_element" should exist

  Scenario: Student forfeits an active round with a confirmation dialog
    Given the following "activities" exist:
      | activity    | course | name          | num_clues | theme_min_length | min_length | max_length |
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
      | activity    | course | name        | num_clues | theme_min_length | min_length | max_length |
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
      | activity    | course | name        | num_clues | theme_min_length | min_length | max_length | win_condition | max_rounds |
      | playercross | C1     | Cross Limit | 1         | 6                | 3          | 15         | 2             | 1          |
    And the following PlayerCross words exist in activity "Cross Limit":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Limit" "playercross activity" page
    And I click on "Start round" "button"
    And I fill the PlayerCross mystery phrase tiles with "escola"
    And I click on "[data-key=\"ENTER\"]" "css_element"
    Then I should see "You solved the mystery phrase directly!"
    And I should see "Rounds played: 1 / 1."
    And "#playercross-new-round-button" "css_element" should not exist

  Scenario: A configured cooldown shows a countdown instead of the new-round button
    Given the following "activities" exist:
      | activity    | course | name           | num_clues | theme_min_length | min_length | max_length | win_condition |
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
    And I click on "[data-key=\"ENTER\"]" "css_element"
    Then I should see "Next round in"
    And "#playercross-cooldown-countdown" "css_element" should exist
    And "#playercross-new-round-button" "css_element" should not exist
