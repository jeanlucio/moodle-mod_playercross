# 🧪 Testes Automatizados

O PlayerCross inclui uma suíte PHPUnit cobrindo lógica de negócio, consultas ao repositório, web
services e conformidade com a Privacy API. Todo push de CI executa a matriz completa (Moodle
4.5 → 5.x, PostgreSQL e MariaDB).

### PHPUnit — Testes Centrais

| Arquivo de teste | Casos |
|-----------------|------:|
| `backup_restore_test.php` | 7 |
| `cross_instance_security_test.php` | 4 |
| `lib_grant_potential_test.php` | 6 |
| `lib_reset_userdata_test.php` | 4 |
| `completion/custom_completion_test.php` | 6 |
| `privacy/provider_test.php` | 13 |
| **Subtotal** | **40** |

### Testes de Lógica de Negócio (`tests/local/`)

| Arquivo de teste | Casos |
|-----------------|------:|
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

### Testes de Web Services (`tests/external/`)

| Arquivo de teste | Casos |
|-----------------|------:|
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

| **Total Geral** | **236** |

```bash
vendor/bin/phpunit --testsuite mod_playercross
```

**Cobertura de linhas geral** (`moodle-coverage`, PHPUnit + Xdebug): **48%**.

[Detalhamento completo teste a teste e tabela de cobertura →]({{ '/testing-pt.html' | relative_url }})
