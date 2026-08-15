# 🧪 Testes Automatizados

O PlayerCross inclui uma suíte PHPUnit cobrindo lógica de negócio, consultas ao repositório, web
services e conformidade com a Privacy API, além de uma suíte Behat cobrindo o jogo, a integração
com o PlayerHUD e os relatórios de ponta a ponta num navegador real. Todo push de CI executa a
matriz completa (Moodle 4.5 → 5.x, PostgreSQL e MariaDB).

### PHPUnit — Testes Centrais

| Arquivo de teste | Casos |
|-----------------|------:|
| `backup_restore_test.php` | 7 |
| `cross_instance_security_test.php` | 4 |
| `lib_grant_potential_test.php` | 6 |
| `lib_reset_userdata_test.php` | 4 |
| `completion/custom_completion_test.php` | 6 |
| `privacy/provider_test.php` | 14 |
| **Subtotal** | **41** |

### Testes de Lógica de Negócio (`tests/local/`)

| Arquivo de teste | Casos |
|-----------------|------:|
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

### Testes de Web Services (`tests/external/`)

| Arquivo de teste | Casos |
|-----------------|------:|
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

| **Total Geral** | **349** |

```bash
vendor/bin/phpunit --testsuite mod_playercross
```

**Cobertura de linhas geral** (`moodle-coverage`, PHPUnit + Xdebug): **81%**.

### Behat — Testes de Ponta a Ponta

| Arquivo de feature | Cenários |
|----------------------|----------:|
| `mod_playercross_smoke.feature` | 1 |
| `mod_playercross_gameplay.feature` | 12 |
| `mod_playercross_playerhud.feature` | 4 |
| `mod_playercross_reports.feature` | 5 |
| `mod_playercross_settings.feature` | 4 |
| `mod_playercross_toolbar.feature` | 8 |
| **Subtotal** | **34** |

[Detalhamento completo teste a teste e tabela de cobertura →]({{ '/testing-pt.html' | relative_url }})
