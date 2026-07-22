@mod @mod_playercross @javascript
Feature: PlayerCross attempt history and ranking
  As a student or teacher
  I want to see attempt history and ranking data
  So that I can track progress across rounds

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

  Scenario: A student sees only their own attempt history, never another student's
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student2 | Student   | Two      | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student2 | C1     | student |
    And the following "activities" exist:
      | activity    | course | name          | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Report  | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Report":
      | word   |
      | escola |
      | livro  |
    And the following PlayerCross attempts exist in activity "Cross Report":
      | user     | word   | score  |
      | student1 | escola | 70.00  |
      | student2 | escola | 45.00  |
    And I log in as "student1"
    And I am on the "Cross Report" "playercross activity" page
    And I click on "a.pc-toolbar-btn[title=\"My attempts\"]" "css_element"
    Then I should see "My attempts"
    And I should see "70.00"
    And I should not see "45.00"

  Scenario: The teacher's all-students report paginates past 30 rows
    Given the following "activities" exist:
      | activity    | course | name              | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Pagination  | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Pagination":
      | word   |
      | escola |
      | livro  |
    And 31 PlayerCross attempts exist for "student1" with word "escola" in activity "Cross Pagination"
    And I log in as "teacher1"
    And I am on the "Cross Pagination" "playercross activity" page
    And I click on "a.pc-toolbar-btn[title=\"Report\"]" "css_element"
    Then I should see "Attempt history — All students"
    And "li[data-page-number=\"2\"] a.page-link" "css_element" should exist
    When I click on "li[data-page-number=\"2\"] a.page-link" "css_element"
    Then I should see "Attempt history — All students"

  Scenario: The teacher's all-students report sorts by clicking a column header
    Given the following "activities" exist:
      | activity    | course | name       | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Sort | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Sort":
      | word   |
      | escola |
      | livro  |
    And the following PlayerCross attempts exist in activity "Cross Sort":
      | user     | word   | score  |
      | student1 | escola | 20.00  |
      | student1 | escola | 90.00  |
    And I log in as "teacher1"
    And I am on the "Cross Sort" "playercross activity" page
    And I click on "a.pc-toolbar-btn[title=\"Report\"]" "css_element"
    When I click on "Score" "link"
    Then I should see "Score ▲" in the "table.mod-playercross-myattempts-table thead" "css_element"
    And I should see "20.00" in the "table.mod-playercross-myattempts-table tbody tr:nth-child(1)" "css_element"

  Scenario: The teacher's all-students report filters to a single student
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student2 | Student   | Two      | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student2 | C1     | student |
    And the following "activities" exist:
      | activity    | course | name         | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross Filter | 1         | 6                | 3          | 15         |
    And the following PlayerCross words exist in activity "Cross Filter":
      | word   |
      | escola |
      | livro  |
    And the following PlayerCross attempts exist in activity "Cross Filter":
      | user     | word   | score  |
      | student1 | escola | 70.00  |
      | student2 | escola | 45.00  |
    And I log in as "teacher1"
    And I am on the "Cross Filter" "playercross activity" page
    And I click on "a.pc-toolbar-btn[title=\"Report\"]" "css_element"
    And I should see "70.00"
    And I should see "45.00"
    When I set the field "playercross-filter-student" to "Student One"
    And I click on "Filter" "button"
    Then I should see "70.00"
    And I should not see "45.00"

  Scenario: The ranking page shows the top 5 plus the current user's row when outside it
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | player3  | Player    | Three    | player3@example.com   |
      | player4  | Player    | Four     | player4@example.com   |
      | player5  | Player    | Five     | player5@example.com   |
      | player6  | Player    | Six      | player6@example.com   |
      | player7  | Player    | Seven    | player7@example.com   |
    And the following "course enrolments" exist:
      | user    | course | role    |
      | player3 | C1     | student |
      | player4 | C1     | student |
      | player5 | C1     | student |
      | player6 | C1     | student |
      | player7 | C1     | student |
    And the following "activities" exist:
      | activity    | course | name          | num_clues | theme_min_length | min_length | max_length | show_ranking |
      | playercross | C1     | Cross Ranking | 1         | 6                | 3          | 15         | 1            |
    And the following PlayerCross words exist in activity "Cross Ranking":
      | word   |
      | escola |
      | livro  |
    And the following PlayerCross attempts exist in activity "Cross Ranking":
      | user     | word   | score  |
      | player3  | escola | 600.00 |
      | player4  | escola | 500.00 |
      | player5  | escola | 400.00 |
      | player6  | escola | 300.00 |
      | player7  | escola | 200.00 |
      | student1 | escola | 100.00 |
    And I log in as "student1"
    And I am on the "Cross Ranking" "playercross activity" page
    And I click on "a.pc-toolbar-btn[title=\"Ranking\"]" "css_element"
    Then I should see "Ranking"
    And I should see "Ties are broken by fewer attempts used on average, then less time spent on average."
    And "tr.pc-ranking-you" "css_element" should exist
