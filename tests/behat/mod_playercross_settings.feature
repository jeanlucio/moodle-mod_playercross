@mod @mod_playercross @javascript
Feature: PlayerCross teacher-facing settings behaviour
  As a teacher
  I want the settings form to protect data that is already in use
  So that changing a setting later cannot silently corrupt past results

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

  Scenario: The clue count and grading method settings freeze once a real grade exists
    Given the following "activities" exist:
      | activity    | course | name         | num_clues | theme_min_length | min_length | max_length | win_condition | grade |
      | playercross | C1     | Cross Freeze | 1         | 6                | 3          | 15         | 2              | 100   |
    And the following PlayerCross words exist in activity "Cross Freeze":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross Freeze" "playercross activity" page
    And I click on "Start round" "button"
    And I fill the PlayerCross mystery phrase tiles with "escola"
    And I click on "[data-key=\"ENTER\"]" "css_element"
    And I should see "You solved the mystery phrase directly!"
    And I log in as "teacher1"
    And I am on the "Cross Freeze" "playercross activity editing" page
    And I click on "a.collapseexpand" "css_element"
    Then I should see "Because this activity has already recorded a real grade"
    And "select#id_num_clues" "css_element" should not exist
    And "select#id_grademethod" "css_element" should not exist

  Scenario: Adding a manual word that already exists in the pool is rejected
    Given the following "activities" exist:
      | activity    | course | name          | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Manage  | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Manage":
      | word   |
      | escola |
      | livro  |
    And I log in as "teacher1"
    And I am on the "Cross Manage" "playercross activity" page
    And I click on "a.pc-toolbar-btn[title=\"Manage words\"]" "css_element"
    When I set the field "playercross-manualword" to "escola"
    And I click on "Add word" "button"
    Then I should see "This word already exists in this activity's word pool."

  Scenario: Adding a manual word with a character the game cannot use is rejected
    Given the following "activities" exist:
      | activity    | course | name               | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross InvalidChars | 1         | 6                | 4          | 12         |
    And I log in as "teacher1"
    And I am on the "Cross InvalidChars" "playercross activity" page
    And I click on "a.pc-toolbar-btn[title=\"Manage words\"]" "css_element"
    When I set the field "playercross-manualword" to "test123"
    And I click on "Add word" "button"
    Then I should see "Word must contain letters only"

  Scenario: A PlayerHUD item that no longer exists stays selected instead of resetting silently
    Given the following "activities" exist:
      | activity    | course | name        | num_clues | theme_min_length | min_length | max_length | hud_round_cost_item |
      | playercross | C1     | Cross Hud   | 1         | 6                | 3          | 15         | 99999                |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add the "PlayerHUD" block
    And I am on the "Cross Hud" "playercross activity editing" page
    And I click on "a.collapseexpand" "css_element"
    Then I should see "Deleted item (please reconfigure)"
    When I press "Save and return to course"
    And I am on the "Cross Hud" "playercross activity editing" page
    And I click on "a.collapseexpand" "css_element"
    Then I should see "Deleted item (please reconfigure)"
