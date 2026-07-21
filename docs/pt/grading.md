# 🧮 Nota e Ranking

Diferente do PlayerWords, o PlayerCross não tem um modo de pontuação por rodada configurável
(sem alternância Binária/Linear) — os pontos são calculados por uma única fórmula fixa que
recompensa tanto a resolução cuidadosa das pistas quanto o palpite direto e confiante na
frase-mistério. A mesma pontuação calculada alimenta tanto a **nota** quanto o **ranking**.

**Ambos são totalmente opcionais, e cada um é ligado ou desligado de forma independente:**

* **Nota:** deixe o campo padrão `Nota` definido como *Nenhuma* (o padrão) para rodar a atividade
  totalmente sem avaliação — nenhuma nota é calculada ou lançada no diário de notas.
* **Ranking:** deixe `Mostrar ranking` como *Não* para ocultar o ranking em todo lugar — no jogo,
  na página dedicada de ranking e na coluna extra do histórico de tentativas.

Desligar um nunca afeta o outro: uma atividade pode ser avaliada sem ranking, ter ranking sem
nota, ambos, ou nenhum dos dois.

**Como uma rodada ganha pontos:**

* A nota configurada da atividade é dividida igualmente entre as pistas da rodada: `nota ÷
  num_clues` é o teto que uma única pista resolvida pode valer.
* **Resolver uma pista** ganha crédito total nas duas primeiras tentativas — um segundo palpite
  confiante não é tratado como menos merecedor que um de primeira tentativa — depois decresce
  linearmente conforme o `Máximo de tentativas por pista` se aproxima. Com tentativas ilimitadas
  por pista (0), uma pista resolvida sempre ganha crédito total, já que não há um denominador
  natural para escalar.
* **Acertar a frase-mistério diretamente** ganha um bônus inversamente proporcional a quantas
  pistas já foram resolvidas naquele momento: acertar antes de resolver qualquer pista vale tanto
  quanto todas as pistas restantes valeriam em crédito total cada uma, recompensando a dedução
  precoce; acertar depois de já resolver todas as pistas não ganha bônus nenhum, já que o crédito
  total já foi coletado das próprias pistas.
* O total que uma rodada pode ganhar — pontos das pistas resolvidas mais o bônus da frase-mistério
  — é sempre limitado pela nota configurada da atividade, independente da `Condição de vitória`.

**Combinar várias rodadas em uma nota final** é uma configuração separada, `Método de avaliação`
(maior nota, média das notas, primeira tentativa, última tentativa, ou média sobre todas as
rodadas exigidas). Ela só agrega o que cada rodada já registrou.

**O ranking** é a soma da pontuação de cada rodada finalizada de um estudante (`SUM`), ordenada
da maior para a menor; empates são desfeitos por menos tentativas usadas em média, depois menos
tempo gasto em média. Só aparece quando o professor habilita "Mostrar ranking", e nunca revela
uma rodada ainda em andamento.

**Só os 5 primeiros são mostrados — de propósito, não é um bug:** tanto o widget de ranking no
jogo quanto a página dedicada de ranking limitam a lista a 5 linhas, para evitar um ranking
público de toda a turma. Um estudante em posição mais baixa ainda vê exatamente onde está: uma
linha extra, separada por "…", mostra sua posição e pontuação reais, sem expor a posição de
ninguém abaixo do 5º lugar. Quem gerencia a atividade (editingteacher, manager) nunca aparece no
ranking, mesmo que jogue a atividade — da mesma forma que suas próprias tentativas são excluídas
do relatório de tentativas abaixo.

**"Mostrar ranking" controla só a visibilidade, não a coleta de dados:** as pontuações são
calculadas e armazenadas para toda rodada finalizada, independente de o ajuste estar ligado ou
desligado no momento. Ativá-lo depois que estudantes já jogaram revela o total completo
acumulado desde o início da atividade, não só os pontos ganhos a partir daquele momento.

**Trava assim que há avaliação:** no momento em que a atividade registra uma nota real para
qualquer estudante, `Pistas por rodada` (`num_clues`) e `Método de avaliação` travam ambos — da
mesma forma que o Moodle já trava o campo "Nota máxima" de uma atividade avaliada assim que notas
reais existem. Como o denominador de pontos por pista depende diretamente de `num_clues`, mudá-lo
depois que já existem pontuações reais faria rodadas anteriores e posteriores valerem coisas
diferentes; travá-lo garante que toda rodada já registrada permaneça internamente consistente
durante toda a vida da atividade.

**Histórico de tentativas:** cada estudante pode revisar suas rodadas passadas — frase-mistério,
pistas resolvidas, tentativas usadas, tempo e pontuação — pela página de histórico da barra de
ferramentas. Quem gerencia a atividade vê a mesma página se transformar em um relatório cobrindo
todos os estudantes: uma tabela, ordenável clicando em qualquer cabeçalho de coluna, e filtrável
para um único estudante. Assim como o ranking, nunca inclui as próprias tentativas de um gestor,
mesmo que ele tenha jogado a atividade.
