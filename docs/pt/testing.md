# 🧪 Testes Automatizados

O PlayerCross inclui uma suíte PHPUnit cobrindo lógica de negócio, consultas ao repositório, web
services e conformidade com a Privacy API, além de uma suíte Behat cobrindo o jogo, a integração
com o PlayerHUD e os relatórios de ponta a ponta num navegador real. Todo push de CI executa a
matriz completa (Moodle 4.5 → 5.x, PostgreSQL e MariaDB).

### PHPUnit — Testes Centrais

| Arquivo de teste | Casos | O que é coberto |
|-----------------|------:|----------------|
| `backup_restore_test.php` | 7 | Duplicar uma atividade copia o pool de palavras e reconstrói o cache do curso sem criar um item de nota duplicado; toda coluna do `install.xml` de `playercross_attempts` é comparada estaticamente contra os atributos declarados pelo próprio passo de backup, para que uma coluna adicionada depois nunca reverta silenciosamente ao seu padrão na restauração — a proteção adicionada depois que o próprio `win_condition` já esteve faltando nessa lista; o `timemodified` da palavra sobrevive ao backup/restore; uma referência a item do PlayerHUD sobrevive intacta a um "Duplicar atividade" no mesmo curso; um backup/restore completo do curso para um curso novo remapeia a referência para o id do novo item; uma referência a item de outro curso é descartada em vez de continuar apontando para o curso errado |
| `cross_instance_security_test.php` | 4 | O estado de sessão da rodada, buscas de palavra, registros de tentativa e a consulta de histórico de tentativas nunca vazam entre duas instâncias de atividade diferentes, mesmo para o mesmo estudante no mesmo curso |
| `lib_grant_potential_test.php` | 6 | O callback `playerhud_grant_potential`, usado pela própria estimativa de teto de "XP total no jogo" do PlayerHUD: vazio para uma instância de bloco desconhecida, para uma atividade sem item de recompensa configurado, e para uma atividade ilimitada (espelha a regra antifarming da concessão real); uma atividade limitada retorna uma linha no formato das próprias entradas de detalhamento do PlayerHUD; um item de recompensa pertencente à instância de bloco de outro curso não contribui em nada; duas atividades limitadas no mesmo curso contribuem cada uma com sua própria linha |
| `lib_reset_userdata_test.php` | 4 | A redefinição de curso apaga tentativas e reseta notas somente quando a caixa está marcada, somente para o curso alvo, e o padrão do formulário vem com a caixa marcada |
| `completion/custom_completion_test.php` | 6 | Regra de conclusão personalizada ("exigir rodadas concluídas"): incompleta abaixo do limite, completa no limite, regra não reportada como disponível quando desativada, nomes de regras definidas, descrição da regra inclui o número exigido, ordem de exibição |
| `privacy/provider_test.php` | 14 | Declaração de metadados; exportação da preferência de usuário "viu a introdução", em todo o site, tanto ausente quanto definida; contextos por tentativas; contextos por palavras adicionadas; listar usuários no contexto (e no-op para um contexto que não é de módulo); exportar dados do usuário (e no-op para uma contextlist vazia); excluir dados de um único usuário em múltiplos contextos; excluir dados de múltiplos usuários; excluir dados de todos os usuários em um contexto (e no-op para um contexto que não é de módulo) |
| **Subtotal** | **41** | |

### Testes de Lógica de Negócio (`tests/local/`)

| Arquivo de teste | Casos | O que é coberto |
|-----------------|------:|----------------|
| `ai_word_generator_test.php` | 12 | Parsing da resposta de IA (wrappers `words`/`concepts` legado, lista simples, bloco de código markdown removido, JSON malformado/não-array, dica recorre a `definition`, entradas não-array ignoradas) e validação de termo de entrada não confiável (palavra alfabética única aceita; termos vazios, com múltiplas palavras ou não alfabéticos rejeitados) — tudo via reflection, sem chamada real de IA |
| `attempts_history_service_test.php` | 11 | Histórico de tentativas próprio restrito ao usuário informado, mais recente primeiro, e vazio sem nenhuma tentativa; a nota reportada bate com `playercross_calculate_user_grade()` para o método de avaliação configurado; uma linha recorre à palavra crua quando nenhum conceito foi registrado; o tempo usado é formatado como m:ss; resumo de nota oculto para atividade sem avaliação; relatório de todos os estudantes pagina e recorre a uma coluna de ordenação segura para uma chave desconhecida, filtra por um único estudante, ordena por pontuação crescente e lista todos os estudantes mais recente primeiro; um usuário que gerencia a atividade é excluído tanto do relatório quanto do menu de filtro por estudante |
| `gameplay_service_test.php` | 8 | O teto de pontos por pista divide a nota igualmente entre `num_clues` (e é zero com zero pistas); os pontos da pista são sempre crédito total com tentativas ilimitadas; crédito total nas duas primeiras tentativas, depois decrescendo linearmente; o bônus da frase-mistério é igual à nota total quando nada foi resolvido ainda e diminui conforme mais pistas são resolvidas; o construtor da chave de sessão |
| `hud_service_test.php` | 22 | Delega para a própria API de itens do block_playerhud em toda operação, validando a posse contra a instância de bloco do próprio chamador: busca de bloco entre cursos; se o block_playerhud está instalado; disponibilidade no curso (com/sem instância de bloco, ignorando a de outro curso); resolução de nome de item; listagem de itens; consumo de itens (saldo insuficiente, sucesso, ordem FIFO, atalho para quantidade zero, dispensado para item de instância estrangeira); concessão de itens (inventário mais XP concedido, XP suprimido quando sinalizado como ilimitado, itens de XP zero não concedem nada, itens inválidos/de instância estrangeira/quantidade zero são no-ops) |
| `intro_service_test.php` | 5 | A preferência de usuário "viu a introdução", em todo o site: falsa por padrão; vira verdadeira e permanece (idempotente); isolada por usuário; o nome da preferência é prefixado com o Frankenstyle do plugin |
| `puzzle_builder_test.php` | 9 | Cobertura total dos slots entre tema e pistas; uma letra exclusiva das pistas ainda compartilha corretamente seu slot; degradação graciosa para uma letra da frase-mistério não cobrível, e essa degradação pode ser desativada; o texto da frase-mistério vem da própria dica da palavra-tema, nunca do seu conceito; a ortografia acentuada original sobrevive junto à palavra-pista e à dica do tema normalizadas; falha rígida quando o pool de palavras é insuficiente; determinismo do modo de palavra compartilhado; o desempate da seleção gulosa de pistas é determinístico |
| `ranking_service_test.php` | 5 | Ranking vazio; ordenação decrescente por pontuação; truncamento top-5 com linha extra para o usuário atual em posição inferior; `SEPARATEGROUPS` filtra pelo grupo do estudante; um usuário que gerencia a atividade nunca aparece no ranking, mesmo com tentativas próprias |
| `round_presenter_test.php` | 36 | Renderização das peças da frase-mistério (respeita os slots revelados, peças ocultas carregam seu número de slot, todas reveladas ao finalizar, agrupadas por palavra); renderização das linhas de pista (palavra não resolvida oculta, revelada ao finalizar a rodada, revelada ao ser resolvida, rótulo de tentativas esgotadas mostrado só quando realmente esgotado, a frase-mistério sempre é mostrada, uma letra compartilhada cruzadamente revelada é refletida); texto do intervalo (inativo/ativo, reflete uma mudança posterior de configuração); mensagem de feedback varia conforme o resultado; informação de relevância do método de avaliação; resumo da nota até agora (ausente sem item de nota, mostrado ao finalizar); contexto do lobby (custo/saldo do PlayerHUD, pode iniciar com saldo suficiente, informação do cronômetro só quando ativado, contagem de pistas desta rodada); contexto do painel de rodada (tempo restante zero antes de começar, oculta revelar enquanto ativo, disponibilidade da dica global, contagem de dicas restantes mostrada, o botão de dica se oculta ao atingir o limite configurado de revelações, o botão de dica mostra/omite seu custo do PlayerHUD, pode pagar a dica com saldo suficiente, disponibilidade de cedilha reflete o pool de palavras); contexto do resultado da rodada (em branco até finalizar, revela ao finalizar, rótulo de concessão do PlayerHUD mostrado só numa vitória real, e omitido numa derrota) |
| `round_service_test.php` | 37 | Estado padrão da rodada e descarte de estado estruturalmente obsoleto, incluindo estado sem os campos de ortografia de revelação de uma sessão mais antiga; construção do puzzle sob demanda; revelar uma dica para de funcionar ao atingir o limite configurado por rodada, e dicas sozinhas podem finalizar e vencer a rodada; envio de palpite de pista (errado incrementa tentativas, correto resolve e revela slots compartilhados); resolver todas as pistas sozinho não finaliza a rodada; um palpite final correto sozinho não finaliza a rodada, e resolve automaticamente qualquer pista restante feita inteiramente de letras já compartilhadas; um palpite final errado mantém a rodada aberta; pistas-depois-palpite-final e palpite-final-depois-pistas finalizam e vencem a rodada; o esgotamento de uma pista encerra a rodada como derrota em "ambos obrigatórios", mas não em "só a frase-mistério"; em "só a frase-mistério", resolver todas as pistas sozinho ainda não finaliza a rodada, enquanto o palpite final sozinho vence imediatamente; desistência encerra a rodada como derrota e nunca concede o item de vitória; tempo esgotado rejeitado antes do prazo; uma nova rodada reseta o estado; contagem de rodadas jogadas e intervalo; variantes do aviso de restrição (limite de rodadas atingido, intervalo ativo, sem restrição); cálculo do intervalo (desativado, expirado pelo tempo, reflete uma mudança posterior de configuração); os eventos `round_started` e `round_completed` disparam no momento certo; vencer concede o item configurado do PlayerHUD com XP quando limitado e sem XP quando ilimitado; iniciar uma rodada ou revelar uma dica dispensa seu custo do PlayerHUD quando o item configurado foi excluído ou pertence a outro curso, mas ainda bloqueia quando o item está apenas desativado e o saldo é insuficiente |
| `view_page_service_test.php` | 22 | Ramificações de montagem da página: lobby recém-iniciado, um puzzle sorteado persiste entre chamadas, uma rodada finalizada calcula um intervalo real, aviso de restrição quando o limite de rodadas é atingido; ação de desistir mostrada só durante uma rodada ativa; URLs da barra de ferramentas sempre presentes, barra de ferramentas de gestor oculta para estudantes e mostrada para professores; palavras inativas ocultas para estudantes, mostradas para um gestor, e a contagem de ativas mostrada sozinha quando nada está inativo; link de ranking oculto quando o ranking está desativado; ajuda do PlayerHUD mostrada quando uma recompensa de vitória está configurada; o texto de ajuda da condição de vitória parte de "ambos obrigatórios" e reflete a configuração "só a frase-mistério"; o aviso de perda por pista é mostrado quando as tentativas por pista são limitadas e oculto quando ilimitadas; auto-exibição da introdução sinalizada uma vez no lobby e não se repete numa atividade diferente, e também é sinalizada corretamente nas ramificações de rodada finalizada e aviso de restrição; o contexto de ajuda sempre carrega o ponteiro de dica de revisão |
| `word_normalizer_test.php` | 21 | Normalização insensível a acentos em 8 combinações de acento/maiúscula-minúscula; `is_valid_charset` aceita só letras (incluindo acentuadas) e rejeita dígitos, espaços, um hífen, um apóstrofo e uma string vazia, em 8 casos; `chars()` divide uma palavra normalizada em caracteres individuais em 4 casos sem rasgar sequências multibyte — o motivo pelo qual `puzzle_builder::cipher_slots()` usa esse método em vez de uma divisão simples por byte |
| `words_repository_test.php` | 51 | Candidatas a frase-mistério e a pista respeitam seus próprios intervalos de comprimento independentes; seleção de tema compartilhada e aleatória, e o último id de palavra-tema jogado; verificações de existência de palavra (insensível a maiúsculas/minúsculas, restrita à instância, ignorando um id excluído, independente da fonte); detecção de palavra com cedilha (presente, ausente, ignora não aprovadas, restrita à própria instância); inserção manual e por IA, busca, atualização e exclusão de palavras (todas restritas à instância dona); exclusão e aprovação em lote; listagem de palavras recentes, incluindo o nome do glossário; sincronização com o Glossário (desativada sem o bit de fonte, conceitos de uma e várias palavras, stopwords configuradas, atualização da dica na ressincronização, remoção de órfãs, escopo a um ou todos os glossários do curso, ignorando palavra pertencente a outra fonte); relato de conceitos fragmentados (conceitos de várias palavras divididos, conceitos de uma palavra excluídos, fontes não-Glossário ignoradas, restrito à própria instância); detecção de palavras inativas (descompasso de comprimento, charset inválido, uma palavra válida só para o papel de tema não é relatada, palavras não aprovadas ignoradas); contagem de sorteios do tema (ausente, somada independente do resultado, restrita à própria instância); contagem de candidatas do Glossário (dentro do intervalo, em todos os glossários do curso, tokens deduplicados, restrita ao próprio curso, zero quando não há glossários) |
| **Subtotal** | **239** | |

### Testes de Web Services (`tests/external/`)

| Arquivo de teste | Casos | O que é coberto |
|-----------------|------:|----------------|
| `count_eligible_theme_words_test.php` | 5 | Conta só dicas aprovadas cujo total de letras cai no intervalo pedido; exclui uma dica acima de um comprimento máximo real (não-zero); exclui palavras não aprovadas; restrito à própria instância de atividade; exige a capability `mod/playercross:addinstance` (rejeita um estudante) |
| `count_eligible_words_test.php` | 5 | Conta só palavras aprovadas do pool cujo comprimento cai no intervalo pedido; exclui palavras não aprovadas e fora do intervalo; restrito à própria instância; exige a capability `mod/playercross:addinstance` |
| `count_glossary_candidates_test.php` | 4 | Conta palavras candidatas para um glossário específico dentro do intervalo de comprimento pedido; exclui palavras fora do intervalo; uma stopword vinda diretamente do formulário de configurações remove o token correspondente antes de contar; exige a capability `mod/playercross:addinstance` |
| `end_round_test.php` | 4 | Desistência finaliza a rodada; tempo esgotado finaliza a rodada; um valor de `reason` inválido é rejeitado; a capability `mod/playercross:view` é exigida |
| `new_round_test.php` | 3 | Uma nova rodada sorteia um puzzle novo; bloqueada quando o limite de rodadas já foi atingido; a capability `mod/playercross:view` é exigida |
| `reveal_hint_test.php` | 6 | Revela mais uma peça; rejeitada quando todos os slots já estão revelados; rejeitada quando o limite configurado de dicas por rodada é atingido; a capability `mod/playercross:view` é exigida; saldo insuficiente de item do PlayerHUD bloqueia a revelação; um custo apontando para um item excluído é dispensado |
| `start_round_test.php` | 5 | A rodada inicia; rejeitada quando já iniciada; a capability `mod/playercross:view` é exigida; saldo insuficiente de item do PlayerHUD bloqueia o início; um custo apontando para um item excluído é dispensado |
| `submit_clue_guess_test.php` | 4 | Um palpite errado de pista nunca vaza a palavra da pista; uma pista resolvida comum é sinalizada para um toast, não para o banner de fim de rodada; resolver todas as pistas só revela a palavra-tema quando a rodada realmente finaliza; um usuário sem matrícula/capability não pode enviar um palpite |
| `submit_final_guess_test.php` | 3 | Um palpite final errado nunca vaza a palavra-tema; um palpite final correto sozinho não vence a rodada nem revela a palavra-tema (em "ambos obrigatórios"); resolver todas as pistas e depois acertar a frase final vence a rodada e revela a palavra-tema |
| **Subtotal** | **39** | |

| **Total Geral** | **319** | |

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
| `external\end_round` | 64% |
| `external\new_round` | 49% |
| `external\reveal_hint` | 49% |
| `external\start_round` | 64% |
| `external\submit_clue_guess` | 21% |
| `external\submit_final_guess` | 59% |
| `local\ai_word_generator` | 25% |
| `local\attempts_history_service` | 75% |
| `local\gameplay_service` | 94% |
| `local\hud_service` | 91% |
| `local\intro_service` | 100% |
| `local\puzzle_builder` | 42% |
| `local\ranking_service` | 78% |
| `local\round_presenter` | 66% |
| `local\round_service` | 71% |
| `local\view_page_service` | 23% |
| `local\word_normalizer` | 30% |
| `local\words_repository` | 93% |
| `privacy\provider` | 86% |
| **Geral** | **60%** |

As classes `event/*.php` não estão listadas — o Moodle só as carrega de forma preguiçosa
quando o evento correspondente realmente dispara, então a instrumentação nunca as enxerga.

### Behat — Testes de Ponta a Ponta

O PlayerCross também inclui uma suíte Behat que conduz o jogo numa sessão de navegador real,
cobrindo o jogo em si, a integração com o PlayerHUD, os relatórios voltados ao professor e a
barra de ferramentas/modais — áreas que um teste unitário PHPUnit não consegue exercitar
(interface dirigida por JavaScript, navegação real de página).

| Arquivo de feature | Cenários | O que é coberto |
|---------------------|----------:|----------------|
| `mod_playercross_smoke.feature` | 1 | O lobby carrega e uma rodada pode ser iniciada — a verificação básica sobre a qual o resto da suíte se apoia |
| `mod_playercross_gameplay.feature` | 6 | Vencer uma rodada acertando a frase-mistério diretamente esconde o selo do cronômetro; resolver uma pista revela cruzadamente suas letras compartilhadas na frase-mistério; desistir de uma rodada ativa pede confirmação; uma rodada termina automaticamente quando seu cronômetro se esgota; atingir o limite de rodadas oculta a ação de nova rodada em vez de um beco sem saída; um intervalo configurado mostra uma contagem regressiva em vez do botão de nova rodada |
| `mod_playercross_playerhud.feature` | 4 | O lobby bloqueia o início de uma rodada até o estudante poder pagar o custo do item configurado; revelar uma dica pede confirmação e saldo suficiente; uma rodada inicia e a dica é revelada de graça quando o item configurado não existe mais; vencer uma rodada concede o item configurado do PlayerHUD |
| `mod_playercross_reports.feature` | 5 | Um estudante vê só o próprio histórico de tentativas, nunca o de outro estudante; o relatório de todos os estudantes do professor pagina além de 30 linhas, ordena ao clicar num cabeçalho de coluna e filtra para um único estudante; a página de ranking mostra o top 5 mais a linha do usuário atual quando ele fica fora dele |
| `mod_playercross_settings.feature` | 4 | Número de pistas e método de avaliação travam assim que existe uma nota real; adicionar uma palavra manual já existente no pool, ou com um caractere que o jogo não consegue usar, é rejeitado; um item do PlayerHUD que não existe mais permanece selecionado no formulário de configurações em vez de resetar silenciosamente |
| `mod_playercross_toolbar.feature` | 8 | O ícone de gerenciar palavras e o aviso de palavras inativas só aparecem para quem gerencia a atividade; o ícone de ranking só aparece quando o ranking está ativado; o ícone de desistir só aparece durante uma rodada ativa; o modal de ajuda mostra seus parágrafos opcionais só quando relevantes, e os oculta caso contrário; o modal "Como jogar" abre automaticamente na primeiríssima visita de um jogador, uma única vez; cancelar a confirmação de desistência deixa a rodada intocada |
| **Subtotal** | **28** | |

```bash
vendor/bin/behat --config public/behat.yml --profile=chrome --tags @mod_playercross
```
