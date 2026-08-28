# 🧪 Testes Automatizados

O PlayerCross inclui uma suíte PHPUnit cobrindo lógica de negócio, consultas ao repositório, web
services e conformidade com a Privacy API, além de uma suíte Behat cobrindo o jogo, a integração
com o PlayerHUD e os relatórios de ponta a ponta num navegador real. Todo push de CI executa a
matriz completa (Moodle 4.5 → 5.x, PostgreSQL e MariaDB).

### PHPUnit — Testes Centrais

| Arquivo de teste | Casos |
|-----------------|------:|
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

### Testes de Lógica de Negócio (`tests/local/`)

| Arquivo de teste | Casos |
|-----------------|------:|
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

### Testes de Web Services (`tests/external/`)

| Arquivo de teste | Casos |
|-----------------|------:|
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

| **Total Geral** | **494** |

```bash
vendor/bin/phpunit --testsuite mod_playercross
```

**Cobertura de linhas geral** (`moodle-coverage`, PHPUnit + Xdebug): **90%**.

### Behat — Testes de Ponta a Ponta

| Arquivo de feature | Cenários |
|----------------------|----------:|
| `mod_playercross_smoke.feature` | 1 |
| `mod_playercross_gameplay.feature` | 15 |
| `mod_playercross_playerhud.feature` | 4 |
| `mod_playercross_reports.feature` | 5 |
| `mod_playercross_settings.feature` | 5 |
| `mod_playercross_toolbar.feature` | 9 |
| **Subtotal** | **39** |

[Detalhamento completo teste a teste e tabela de cobertura →]({{ '/testing-pt.html' | relative_url }})
