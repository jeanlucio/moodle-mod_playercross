# 🧪 Automated Tests

PlayerCross ships with a PHPUnit test suite covering business logic, repository queries, web
services, and Privacy API compliance, plus a Behat suite covering gameplay, PlayerHUD
integration, and reports end-to-end in a real browser. Every CI push runs against the full
matrix (Moodle 4.5 → 5.x, PostgreSQL & MariaDB).

### PHPUnit — Core Tests

| Test file | Cases |
|-----------|------:|
| `backup_restore_test.php` | 8 |
| `cross_instance_security_test.php` | 4 |
| `lib_grant_potential_test.php` | 6 |
| `lib_reset_userdata_test.php` | 4 |
| `lib_supports_test.php` | 2 |
| `completion/custom_completion_test.php` | 6 |
| `privacy/provider_test.php` | 21 |
| `lib_update_grades_test.php` | 2 |
| `mod_form_test.php` | 4 |
| `lib_grade_item_update_test.php` | 2 |
| **Subtotal** | **59** |

### Local Business-Logic Tests (`tests/local/`)

| Test file | Cases |
|-----------|------:|
| `ai_word_generator_test.php` | 19 |
| `attempts_history_service_test.php` | 21 |
| `gameplay_service_test.php` | 16 |
| `hud_service_test.php` | 28 |
| `intro_service_test.php` | 5 |
| `puzzle_builder_test.php` | 9 |
| `ranking_service_test.php` | 9 |
| `round_presenter_test.php` | 60 |
| `round_service_test.php` | 67 |
| `view_page_service_test.php` | 40 |
| `word_normalizer_test.php` | 38 |
| `words_repository_test.php` | 67 |
| **Subtotal** | **379** |

### Web Services Tests (`tests/external/`)

| Test file | Cases |
|-----------|------:|
| `count_eligible_theme_words_test.php` | 5 |
| `count_eligible_words_test.php` | 5 |
| `count_glossary_candidates_test.php` | 5 |
| `end_round_test.php` | 6 |
| `new_round_test.php` | 5 |
| `reveal_hint_test.php` | 8 |
| `start_round_test.php` | 7 |
| `submit_term_guess_test.php` | 8 |
| `submit_final_guess_test.php` | 7 |
| **Subtotal** | **56** |

| **Grand Total** | **494** |

```bash
vendor/bin/phpunit --testsuite mod_playercross
```

**Overall line coverage** (`moodle-coverage`, PHPUnit + Xdebug): **90%**.

### Behat — End-to-End Tests

| Feature file | Scenarios |
|---------------|----------:|
| `mod_playercross_smoke.feature` | 1 |
| `mod_playercross_gameplay.feature` | 15 |
| `mod_playercross_playerhud.feature` | 4 |
| `mod_playercross_reports.feature` | 5 |
| `mod_playercross_settings.feature` | 5 |
| `mod_playercross_toolbar.feature` | 9 |
| **Subtotal** | **39** |

[Full test-by-test breakdown and coverage table →]({{ '/testing.html' | relative_url }})
