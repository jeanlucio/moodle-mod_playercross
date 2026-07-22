@mod @mod_playercross @javascript
Feature: PlayerCross smoke test
  As a student
  I want to open a PlayerCross activity
  In order to start playing

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
    And the following "activities" exist:
      | activity    | course | name       | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Game | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Game":
      | word    | hint    |
      | escola  | escola  |
      | livro   | livro   |
    And "student1" has already seen the playercross intro

  Scenario: Student opens the lobby and can start a round
    When I log in as "student1"
    And I am on the "Cross Game" "playercross activity" page
    Then I should see "Start round"
    And "#playercross-start-round-button" "css_element" should exist
