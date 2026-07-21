# 🧪 Automated Tests

PlayerCross ships with a PHPUnit test suite covering business logic, repository queries, web
services, and Privacy API compliance. Every CI push runs against the full matrix (Moodle 4.5 →
5.x, PostgreSQL & MariaDB).

### PHPUnit — Core Tests

| Test file | Cases |
|-----------|------:|
| `backup_restore_test.php` | 7 |
| `cross_instance_security_test.php` | 4 |
| `lib_grant_potential_test.php` | 6 |
| `lib_reset_userdata_test.php` | 4 |
| `completion/custom_completion_test.php` | 6 |
| `privacy/provider_test.php` | 13 |
| **Subtotal** | **40** |

### Local Business-Logic Tests (`tests/local/`)

| Test file | Cases |
|-----------|------:|
| `ai_word_generator_test.php` | 12 |
| `attempts_history_service_test.php` | 5 |
| `gameplay_service_test.php` | 8 |
| `hud_service_test.php` | 22 |
| `intro_service_test.php` | 5 |
| `puzzle_builder_test.php` | 8 |
| `ranking_service_test.php` | 5 |
| `round_presenter_test.php` | 30 |
| `round_service_test.php` | 20 |
| `view_page_service_test.php` | 15 |
| `word_normalizer_test.php` | 21 |
| `words_repository_test.php` | 8 |
| **Subtotal** | **159** |

### Web Services Tests (`tests/external/`)

| Test file | Cases |
|-----------|------:|
| `count_eligible_theme_words_test.php` | 5 |
| `count_eligible_words_test.php` | 5 |
| `count_glossary_candidates_test.php` | 4 |
| `end_round_test.php` | 4 |
| `new_round_test.php` | 3 |
| `reveal_hint_test.php` | 5 |
| `start_round_test.php` | 5 |
| `submit_clue_guess_test.php` | 3 |
| `submit_final_guess_test.php` | 3 |
| **Subtotal** | **37** |

| **Grand Total** | **236** |

```bash
vendor/bin/phpunit --testsuite mod_playercross
```

**Overall line coverage** (`moodle-coverage`, PHPUnit + Xdebug): **48%**.

[Full test-by-test breakdown and coverage table →]({{ '/testing.html' | relative_url }})
