# 🧪 Testes Automatizados

O PlayerCross inclui uma suíte PHPUnit cobrindo lógica de negócio, consultas ao repositório, web
services e conformidade com a Privacy API. Todo push de CI executa a matriz completa (Moodle
4.5 → 5.x, PostgreSQL e MariaDB).

### PHPUnit — Testes Centrais

| Arquivo de teste | Casos | O que é coberto |
|-----------------|------:|----------------|
| `backup_restore_test.php` | 7 | Duplicar uma atividade copia o pool de palavras e reconstrói o cache do curso sem criar um item de nota duplicado; toda coluna do `install.xml` de `playercross_attempts` é comparada estaticamente contra os atributos declarados pelo próprio passo de backup, para que uma coluna adicionada depois nunca reverta silenciosamente ao seu padrão na restauração — a proteção adicionada depois que o próprio `win_condition` já esteve faltando nessa lista; o `timemodified` da palavra sobrevive ao backup/restore; uma referência a item do PlayerHUD sobrevive intacta a um "Duplicar atividade" no mesmo curso; um backup/restore completo do curso para um curso novo remapeia a referência para o id do novo item; uma referência a item de outro curso é descartada em vez de continuar apontando para o curso errado |
| `cross_instance_security_test.php` | 4 | O estado de sessão da rodada, buscas de palavra, registros de tentativa e a consulta de histórico de tentativas nunca vazam entre duas instâncias de atividade diferentes, mesmo para o mesmo estudante no mesmo curso |
| `lib_grant_potential_test.php` | 6 | O callback `playerhud_grant_potential`, usado pela própria estimativa de teto de "XP total no jogo" do PlayerHUD: vazio para uma instância de bloco desconhecida, para uma atividade sem item de recompensa configurado, e para uma atividade ilimitada (espelha a regra antifarming da concessão real); uma atividade limitada retorna uma linha no formato das próprias entradas de detalhamento do PlayerHUD; um item de recompensa pertencente à instância de bloco de outro curso não contribui em nada; duas atividades limitadas no mesmo curso contribuem cada uma com sua própria linha |
| `lib_reset_userdata_test.php` | 4 | A redefinição de curso apaga tentativas e reseta notas somente quando a caixa está marcada, somente para o curso alvo, e o padrão do formulário vem com a caixa marcada |
| `completion/custom_completion_test.php` | 6 | Regra de conclusão personalizada ("exigir rodadas concluídas"): incompleta abaixo do limite, completa no limite, regra não reportada como disponível quando desativada, nomes de regras definidas, descrição da regra inclui o número exigido, ordem de exibição |
| `privacy/provider_test.php` | 13 | Declaração de metadados (incluindo a preferência de usuário "viu a introdução", em todo o site); contextos por tentativas; contextos por palavras adicionadas; listar usuários no contexto (e no-op para um contexto que não é de módulo); exportar dados do usuário (e no-op para uma contextlist vazia); excluir dados de um único usuário em múltiplos contextos; excluir dados de múltiplos usuários; excluir dados de todos os usuários em um contexto (e no-op para um contexto que não é de módulo) |
| **Subtotal** | **40** | |

### Testes de Lógica de Negócio (`tests/local/`)

| Arquivo de teste | Casos | O que é coberto |
|-----------------|------:|----------------|
| `ai_word_generator_test.php` | 12 | Parsing da resposta de IA (wrappers `words`/`concepts` legado, lista simples, bloco de código markdown removido, JSON malformado/não-array, dica recorre a `definition`, entradas não-array ignoradas) e validação de termo de entrada não confiável (palavra alfabética única aceita; termos vazios, com múltiplas palavras ou não alfabéticos rejeitados) — tudo via reflection, sem chamada real de IA |
| `attempts_history_service_test.php` | 5 | Histórico de tentativas próprio restrito ao usuário informado; resumo de nota oculto para atividade sem avaliação; relatório de todos os estudantes pagina e recorre a uma coluna de ordenação segura para uma chave desconhecida; o filtro por estudante restringe às linhas de um único estudante; um usuário que gerencia a atividade é excluído do relatório |
| `gameplay_service_test.php` | 8 | O teto de pontos por pista divide a nota igualmente entre `num_clues` (e é zero com zero pistas); os pontos da pista são sempre crédito total com tentativas ilimitadas; crédito total nas duas primeiras tentativas, depois decrescendo linearmente; o bônus da frase-mistério é igual à nota total quando nada foi resolvido ainda e diminui conforme mais pistas são resolvidas; o construtor da chave de sessão |
| `hud_service_test.php` | 22 | Delega para a própria API de itens do block_playerhud em toda operação, validando a posse contra a instância de bloco do próprio chamador: busca de bloco entre cursos; se o block_playerhud está instalado; disponibilidade no curso (com/sem instância de bloco, ignorando a de outro curso); resolução de nome de item; listagem de itens; consumo de itens (saldo insuficiente, sucesso, ordem FIFO, atalho para quantidade zero, dispensado para item de instância estrangeira); concessão de itens (inventário mais XP concedido, XP suprimido quando sinalizado como ilimitado, itens de XP zero não concedem nada, itens inválidos/de instância estrangeira/quantidade zero são no-ops) |
| `intro_service_test.php` | 5 | A preferência de usuário "viu a introdução", em todo o site: falsa por padrão; vira verdadeira e permanece (idempotente); isolada por usuário; o nome da preferência é prefixado com o Frankenstyle do plugin |
| `puzzle_builder_test.php` | 8 | Cobertura total dos slots entre tema e pistas; uma letra exclusiva das pistas ainda compartilha corretamente seu slot; degradação graciosa para uma letra da frase-mistério não cobrível, e essa degradação pode ser desativada; o texto da frase-mistério vem da própria dica da palavra-tema, nunca do seu conceito; falha rígida quando o pool de palavras é insuficiente; determinismo do modo de palavra compartilhado; o desempate da seleção gulosa de pistas é determinístico |
| `ranking_service_test.php` | 5 | Ranking vazio; ordenação decrescente por pontuação; truncamento top-5 com linha extra para o usuário atual em posição inferior; `SEPARATEGROUPS` filtra pelo grupo do estudante; um usuário que gerencia a atividade nunca aparece no ranking, mesmo com tentativas próprias |
| `round_presenter_test.php` | 30 | Renderização das peças da frase-mistério (respeita os slots revelados, peças ocultas carregam seu número de slot, todas reveladas ao finalizar, agrupadas por palavra); renderização das linhas de pista (palavra não resolvida oculta, revelada ao finalizar a rodada, revelada ao ser resolvida, rótulo de tentativas esgotadas mostrado só quando realmente esgotado, a frase-mistério sempre é mostrada, uma letra compartilhada cruzadamente revelada é refletida); texto do intervalo (inativo/ativo, reflete uma mudança posterior de configuração); mensagem de feedback varia conforme o resultado; informação de relevância do método de avaliação; resumo da nota até agora (ausente sem item de nota, mostrado ao finalizar); contexto do lobby (custo/saldo do PlayerHUD, pode iniciar com saldo suficiente, informação do cronômetro só quando ativado, contagem de pistas desta rodada); contexto do painel de rodada (tempo restante zero antes de começar, oculta revelar enquanto ativo, disponibilidade da dica global); contexto do resultado da rodada (em branco até finalizar, revela ao finalizar, rótulo de concessão do PlayerHUD mostrado só numa vitória real) |
| `round_service_test.php` | 20 | Estado padrão da rodada e descarte de estado estruturalmente obsoleto; construção do puzzle sob demanda; envio de palpite de pista (errado incrementa tentativas, correto resolve e revela slots compartilhados); resolver todas as pistas sozinho não finaliza a rodada; um palpite final correto sozinho não finaliza a rodada; um palpite final errado mantém a rodada aberta; pistas-depois-palpite-final e palpite-final-depois-pistas finalizam e vencem a rodada; o esgotamento de uma pista encerra a rodada como derrota em "ambos obrigatórios", mas não em "só a frase-mistério"; em "só a frase-mistério", resolver todas as pistas sozinho ainda não finaliza a rodada, enquanto o palpite final sozinho vence imediatamente; desistência encerra a rodada como derrota; tempo esgotado rejeitado antes do prazo; uma nova rodada reseta o estado; contagem de rodadas jogadas e intervalo; os eventos `round_started` e `round_completed` disparam no momento certo |
| `view_page_service_test.php` | 15 | Ramificações de montagem da página: lobby recém-iniciado, um puzzle sorteado persiste entre chamadas, uma rodada finalizada calcula um intervalo real, aviso de restrição quando o limite de rodadas é atingido; ação de desistir mostrada só durante uma rodada ativa; URLs da barra de ferramentas sempre presentes, barra de ferramentas de gestor oculta para estudantes; link de ranking oculto quando o ranking está desativado; ajuda do PlayerHUD mostrada quando uma recompensa de vitória está configurada; auto-exibição da introdução sinalizada uma vez no lobby e não se repete numa atividade diferente, e também é sinalizada corretamente nas ramificações de rodada finalizada e aviso de restrição; o contexto de ajuda sempre carrega o ponteiro de dica de revisão |
| `word_normalizer_test.php` | 21 | Normalização insensível a acentos em 8 combinações de acento/maiúscula-minúscula; `is_valid_charset` aceita só letras (incluindo acentuadas) e rejeita dígitos, espaços, um hífen, um apóstrofo e uma string vazia, em 8 casos; `chars()` divide uma palavra normalizada em caracteres individuais em 4 casos sem rasgar sequências multibyte — o motivo pelo qual `puzzle_builder::cipher_slots()` usa esse método em vez de uma divisão simples por byte |
| `words_repository_test.php` | 8 | Candidatas a frase-mistério respeitam o comprimento mínimo próprio sem teto por padrão, e respeitam um comprimento máximo real quando configurado; candidatas a pista são limitadas pelo próprio intervalo de comprimento independente; a seleção de tema em modo compartilhado é determinística entre chamadas para o mesmo número de rodada; o modo aleatório evita um id de tema excluído enquanto existe alternativa; o último id de palavra-tema jogado retorna o mais recente, e zero quando não há tentativas ainda; verificações de existência de palavra são insensíveis a maiúsculas/minúsculas e restritas à própria instância de atividade |
| **Subtotal** | **159** | |

### Testes de Web Services (`tests/external/`)

| Arquivo de teste | Casos | O que é coberto |
|-----------------|------:|----------------|
| `count_eligible_theme_words_test.php` | 5 | Conta só dicas aprovadas cujo total de letras cai no intervalo pedido; exclui uma dica acima de um comprimento máximo real (não-zero); exclui palavras não aprovadas; restrito à própria instância de atividade; exige a capability `mod/playercross:addinstance` (rejeita um estudante) |
| `count_eligible_words_test.php` | 5 | Conta só palavras aprovadas do pool cujo comprimento cai no intervalo pedido; exclui palavras não aprovadas e fora do intervalo; restrito à própria instância; exige a capability `mod/playercross:addinstance` |
| `count_glossary_candidates_test.php` | 4 | Conta palavras candidatas para um glossário específico dentro do intervalo de comprimento pedido; exclui palavras fora do intervalo; uma stopword vinda diretamente do formulário de configurações remove o token correspondente antes de contar; exige a capability `mod/playercross:addinstance` |
| `end_round_test.php` | 4 | Desistência finaliza a rodada; tempo esgotado finaliza a rodada; um valor de `reason` inválido é rejeitado; a capability `mod/playercross:view` é exigida |
| `new_round_test.php` | 3 | Uma nova rodada sorteia um puzzle novo; bloqueada quando o limite de rodadas já foi atingido; a capability `mod/playercross:view` é exigida |
| `reveal_hint_test.php` | 5 | Revela mais uma peça; rejeitada quando todos os slots já estão revelados; a capability `mod/playercross:view` é exigida; saldo insuficiente de item do PlayerHUD bloqueia a revelação; um custo apontando para um item excluído é dispensado |
| `start_round_test.php` | 5 | A rodada inicia; rejeitada quando já iniciada; a capability `mod/playercross:view` é exigida; saldo insuficiente de item do PlayerHUD bloqueia o início; um custo apontando para um item excluído é dispensado |
| `submit_clue_guess_test.php` | 3 | Um palpite errado de pista nunca vaza a palavra da pista; resolver todas as pistas só revela a palavra-tema quando a rodada realmente finaliza; um usuário sem matrícula/capability não pode enviar um palpite |
| `submit_final_guess_test.php` | 3 | Um palpite final errado nunca vaza a palavra-tema; um palpite final correto sozinho não vence a rodada nem revela a palavra-tema (em "ambos obrigatórios"); resolver todas as pistas e depois acertar a frase final vence a rodada e revela a palavra-tema |
| **Subtotal** | **37** | |

| **Total Geral** | **236** | |

```bash
vendor/bin/phpunit --testsuite mod_playercross
```

**Cobertura de linhas por classe (PHPUnit + Xdebug):**

| Classe | Cobertura de linhas |
|-------|:-------------:|
| `completion\custom_completion` | 100% |
| `external\count_eligible_theme_words` | 70% |
| `external\count_eligible_words` | 70% |
| `external\count_glossary_candidates` | 55% |
| `external\end_round` | 73% |
| `external\new_round` | 49% |
| `external\reveal_hint` | 57% |
| `external\start_round` | 74% |
| `external\submit_clue_guess` | 22% |
| `external\submit_final_guess` | 66% |
| `local\ai_word_generator` | 25% |
| `local\attempts_history_service` | 75% |
| `local\gameplay_service` | 94% |
| `local\hud_service` | 91% |
| `local\intro_service` | 100% |
| `local\puzzle_builder` | 43% |
| `local\ranking_service` | 78% |
| `local\round_presenter` | 69% |
| `local\round_service` | 44% |
| `local\view_page_service` | 25% |
| `local\word_normalizer` | 30% |
| `local\words_repository` | 28% |
| `privacy\provider` | 86% |
| **Geral** | **48%** |

As classes `event/*.php` não estão listadas — o Moodle só as carrega de forma preguiçosa
quando o evento correspondente realmente dispara, então a instrumentação nunca as enxerga.
