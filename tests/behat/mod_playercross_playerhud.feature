@mod @mod_playercross @javascript
Feature: PlayerCross PlayerHUD integration
  As a student
  I want item costs and rewards to be enforced and shown accurately
  So that I always know what a round or a hint will cost me, and what I earn

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
    And I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add the "PlayerHUD" block
    And I log out

  Scenario: The lobby blocks starting a round until the student can afford the item cost
    Given a PlayerHUD item "Gold Key" exists in course "C1"
    And the following "activities" exist:
      | activity    | course | name           | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross HudLobby | 1         | 6                | 3          | 15         |
    And the PlayerCross activity "Cross HudLobby" charges 2 PlayerHUD item "Gold Key" to start a round
    And the following PlayerCross words exist in activity "Cross HudLobby":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross HudLobby" "playercross activity" page
    Then I should see "You have 0× Gold Key. Costs 2×."
    And the "#playercross-start-round-button" element should be disabled
    When "student1" has 2 PlayerHUD item "Gold Key" in course "C1"
    And I reload the page
    Then I should see "You have 2× Gold Key. Costs 2×."
    And I click on "Start round" "button"
    And "#playercross-round-play" "css_element" should exist

  Scenario: Revealing a hint requires confirmation and enough balance
    Given a PlayerHUD item "Magnifying Glass" exists in course "C1"
    And the following "activities" exist:
      | activity    | course | name          | num_clues | theme_min_length | min_length | max_length |
      | playercross | C1     | Cross HudHint | 1         | 6                | 3          | 15         |
    And the PlayerCross activity "Cross HudHint" charges 1 PlayerHUD item "Magnifying Glass" to reveal a hint
    And the following PlayerCross words exist in activity "Cross HudHint":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross HudHint" "playercross activity" page
    And I click on "Start round" "button"
    When I click on "#playercross-global-hint-button" "css_element"
    Then I should see "You have 0× Magnifying Glass. Costs 1×."
    And the "[data-action=save]" element should be disabled
    When I click on "[data-action=\"cancel\"]" "css_element"
    And "student1" has 1 PlayerHUD item "Magnifying Glass" in course "C1"
    And I reload the page
    And I click on "#playercross-global-hint-button" "css_element"
    And I click on "[data-action=\"save\"]" "css_element"
    Then I should see "A letter was revealed!"

  Scenario: A round starts and the hint reveals for free when the configured item no longer exists
    Given the following "activities" exist:
      | activity    | course | name          | num_clues | theme_min_length | min_length | max_length | hud_round_cost_item | hud_hint_cost_item |
      | playercross | C1     | Cross HudGone | 1         | 6                | 3          | 15         | 99999                | 99999               |
    And the following PlayerCross words exist in activity "Cross HudGone":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross HudGone" "playercross activity" page
    And I should not see "Costs"
    And I click on "Start round" "button"
    And "#playercross-round-play" "css_element" should exist
    When I click on "#playercross-global-hint-button" "css_element"
    Then I should see "A letter was revealed!"

  Scenario: Winning a round grants the configured PlayerHUD item
    Given a PlayerHUD item "Trophy" exists in course "C1"
    And the following "activities" exist:
      | activity    | course | name         | num_clues | theme_min_length | min_length | max_length | win_condition |
      | playercross | C1     | Cross HudWin | 1         | 6                | 3          | 15         | 2             |
    And the PlayerCross activity "Cross HudWin" grants 1 PlayerHUD item "Trophy" for winning a round
    And the following PlayerCross words exist in activity "Cross HudWin":
      | word   |
      | escola |
      | livro  |
    And I log in as "student1"
    And I am on the "Cross HudWin" "playercross activity" page
    And I click on "Start round" "button"
    And I fill the PlayerCross mystery phrase tiles with "escola"
    And I click on "[data-key=\"ENTER\"]" "css_element"
    Then I should see "You received 1× Trophy!"
