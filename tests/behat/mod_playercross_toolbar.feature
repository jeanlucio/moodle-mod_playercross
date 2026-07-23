@mod @mod_playercross @javascript
Feature: PlayerCross toolbar and modals
  As a student or teacher
  I want the toolbar to only show actions that are actually available to me
  So that the activity page stays uncluttered and accurate

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

  Scenario: The manage-words icon only appears for whoever can manage the activity
    Given the following "activities" exist:
      | activity    | course | name           | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Toolbar  | 1         | 6                | 3          | 15         |
    When I log in as "teacher1"
    And I am on the "Cross Toolbar" "playercross activity" page
    Then "a.pc-toolbar-btn[title=\"Manage words\"]" "css_element" should exist
    When I log in as "student1"
    And I am on the "Cross Toolbar" "playercross activity" page
    Then "a.pc-toolbar-btn[title=\"Manage words\"]" "css_element" should not exist

  Scenario: The inactive-words warning appears only for whoever can manage the activity
    Given the following "activities" exist:
      | activity    | course | name           | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Inactive | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Inactive":
      | word   |
      | escola |
      | livro  |
      | oi     |
    When I log in as "teacher1"
    And I am on the "Cross Inactive" "playercross activity" page
    Then I should see "Active words in this game"
    And I should see "Inactive words in this game"
    And I should see "Outside the current length range"
    And I should see "oi"
    When I log in as "student1"
    And I am on the "Cross Inactive" "playercross activity" page
    Then I should not see "Inactive words in this game"
    And I should not see "Active words in this game"

  Scenario: The ranking icon only appears when the activity has ranking enabled
    Given the following "activities" exist:
      | activity    | course | name               | num_clues | theme_min_length | min_length | max_length | show_ranking |
      | playercross | C1     | Cross Ranking On   | 1         | 6                | 3          | 15         | 1            |
      | playercross | C1     | Cross Ranking Off  | 1         | 6                | 3          | 15         | 0            |
    And I log in as "student1"
    And I am on the "Cross Ranking On" "playercross activity" page
    Then "a.pc-toolbar-btn[title=\"Ranking\"]" "css_element" should exist
    When I am on the "Cross Ranking Off" "playercross activity" page
    Then "a.pc-toolbar-btn[title=\"Ranking\"]" "css_element" should not exist

  Scenario: The forfeit icon only appears while a round is active
    Given the following "activities" exist:
      | activity    | course | name           | num_clues | theme_min_length | min_length | max_length | win_condition |
      | playercross | C1     | Cross Forfeit2 | 1         | 6                | 3          | 15         | 2             |
    And the following PlayerCross words exist in activity "Cross Forfeit2":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Forfeit2" "playercross activity" page
    Then "#playercross-forfeit-button" "css_element" should not be visible
    When I click on "Start round" "button"
    Then "#playercross-forfeit-button" "css_element" should be visible
    When I fill the PlayerCross mystery phrase tiles with "escola"
    And I click on "[data-key=\"ENTER\"]" "css_element"
    Then "#playercross-forfeit-button" "css_element" should not be visible

  Scenario: The help modal shows the optional paragraphs when they are all relevant
    Given the following "activities" exist:
      | activity    | course | name            | num_clues | theme_min_length | min_length | max_length | grade | hud_round_cost_item | max_attempts_per_clue |
      | playercross | C1     | Cross HelpFull  | 1         | 6                | 3          | 15         | 100   | 1                    | 3                     |
    And I log in as "student1"
    And I am on the "Cross HelpFull" "playercross activity" page
    When I click on "#playercross-help-button" "css_element"
    Then I should see "How to play"
    And I should see "This activity may require PlayerHUD items"
    And I should see "Grading method:"
    And I should see "Each clue has a limited number of attempts"

  Scenario: The help modal hides the optional paragraphs when none of them are relevant
    Given the following "activities" exist:
      | activity    | course | name               | num_clues | theme_min_length | min_length | max_length | grade |
      | playercross | C1     | Cross HelpMinimal  | 1         | 6                | 3          | 15         | 0     |
    And I log in as "student1"
    And I am on the "Cross HelpMinimal" "playercross activity" page
    When I click on "#playercross-help-button" "css_element"
    Then I should see "How to play"
    And I should not see "This activity may require PlayerHUD items"
    And I should not see "Each clue has a limited number of attempts"
    And I should not see "Grading method:"

  Scenario: The how-to-play modal opens automatically on a player's very first visit, once ever
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | student2 | Student   | Two      | student2@example.com  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student2 | C1     | student |
    And the following "activities" exist:
      | activity    | course | name             | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross AutoIntro  | 1         | 6                | 3          | 15         |
    When I log in as "student2"
    And I am on the "Cross AutoIntro" "playercross activity" page
    Then I should see "How to play"
    And I should see "You can review these instructions anytime by clicking the help icon at the top of the game."
    When I am on the "Cross AutoIntro" "playercross activity" page
    Then I should not see "How to play"
    When I click on "#playercross-help-button" "css_element"
    Then I should see "How to play"

  Scenario: Cancelling the forfeit confirmation leaves the round untouched
    Given the following "activities" exist:
      | activity    | course | name                | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross ForfeitCancel | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross ForfeitCancel":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross ForfeitCancel" "playercross activity" page
    And I click on "Start round" "button"
    When I click on "#playercross-forfeit-button" "css_element"
    Then I should see "Are you sure you want to give up this round?"
    And I wait "1" seconds
    When I click on "[data-action=\"cancel\"]" "css_element"
    Then "#playercross-round-play" "css_element" should exist
    When I fill PlayerCross clue "1" tiles with "livro"
    And I click on "[data-key=\"ENTER\"]" "css_element"
    Then "li.mod-playercross-clue.is-resolved" "css_element" should exist
