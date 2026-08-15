# 🧪 Automated Tests

PlayerCross ships with a PHPUnit test suite covering business logic, repository queries, web
services, and Privacy API compliance, plus a Behat suite covering gameplay, PlayerHUD
integration, and reports end-to-end in a real browser. Every CI push runs against the full
matrix (Moodle 4.5 → 5.x, PostgreSQL & MariaDB).

### PHPUnit — Core Tests

| Test file | Cases |
|-----------|------:|
| `backup_restore_test.php` | 7 |
| `cross_instance_security_test.php` | 4 |
| `lib_grant_potential_test.php` | 6 |
| `lib_reset_userdata_test.php` | 4 |
| `completion/custom_completion_test.php` | 6 |
| `privacy/provider_test.php` | 14 |
| **Subtotal** | **41** |

### Local Business-Logic Tests (`tests/local/`)

| Test file | Cases |
|-----------|------:|
| `ai_word_generator_test.php` | 12 |
| `attempts_history_service_test.php` | 11 |
| `gameplay_service_test.php` | 8 |
| `hud_service_test.php` | 26 |
| `intro_service_test.php` | 5 |
| `puzzle_builder_test.php` | 9 |
| `ranking_service_test.php` | 6 |
| `round_presenter_test.php` | 39 |
| `round_service_test.php` | 44 |
| `view_page_service_test.php` | 23 |
| `word_normalizer_test.php` | 29 |
| `words_repository_test.php` | 51 |
| **Subtotal** | **263** |

### Web Services Tests (`tests/external/`)

| Test file | Cases |
|-----------|------:|
| `count_eligible_theme_words_test.php` | 5 |
| `count_eligible_words_test.php` | 5 |
| `count_glossary_candidates_test.php` | 4 |
| `end_round_test.php` | 4 |
| `new_round_test.php` | 3 |
| `reveal_hint_test.php` | 7 |
| `start_round_test.php` | 7 |
| `submit_clue_guess_test.php` | 5 |
| `submit_final_guess_test.php` | 5 |
| **Subtotal** | **45** |

| **Grand Total** | **349** |

```bash
vendor/bin/phpunit --testsuite mod_playercross
```

**Overall line coverage** (`moodle-coverage`, PHPUnit + Xdebug): **81%**.

### Behat — End-to-End Tests

| Feature file | Scenarios |
|---------------|----------:|
| `mod_playercross_smoke.feature` | 1 |
| `mod_playercross_gameplay.feature` | 10 |
| `mod_playercross_playerhud.feature` | 4 |
| `mod_playercross_reports.feature` | 5 |
| `mod_playercross_settings.feature` | 4 |
| `mod_playercross_toolbar.feature` | 8 |
| **Subtotal** | **32** |

[Full test-by-test breakdown and coverage table →]({{ '/testing.html' | relative_url }})
