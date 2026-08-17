# 🧮 Nota e Ranking

O PlayerCross calcula uma **nota** e um total de **ranking** a partir das mesmas rodadas
terminadas, mas os dois são configurados de forma totalmente independente — o professor pode
manter a nota simples e ainda assim recompensar jogadas eficientes no ranking, ou o contrário.

**Ambos são totalmente opcionais, e cada um é ligado ou desligado de forma independente:**

* **Nota:** deixe o campo padrão `Nota` definido como *Nenhuma* para rodar a atividade totalmente
  sem avaliação — nenhuma nota é calculada ou lançada no diário de notas, e as configurações
  `Método de avaliação` / `Modo de pontuação da nota` somem do formulário.
* **Ranking:** deixe `Mostrar ranking` como *Não* para ocultar o ranking em todo lugar — no jogo,
  na página dedicada de ranking e na coluna extra do histórico de tentativas — e a configuração
  `Modo de pontuação do ranking` some do formulário também.

Desligar um nunca afeta o outro: uma atividade pode ser avaliada sem ranking, ter ranking sem
nota, ambos, ou nenhum dos dois.

**A pontuação por rodada** decide quanto vale uma única rodada, escolhida separadamente para a
nota e para o ranking (configurações `Modo de pontuação da nota` / `Modo de pontuação do
ranking`, ambas com padrão **Binário**). A nota usa a **nota máxima configurada da atividade**
como base; o **ranking sempre usa sua própria base fixa de 100 pontos, totalmente independente da
nota** — inclusive quando a atividade não tem nota nenhuma (`Nota` = *Nenhuma*, o padrão do
formulário), o ranking continua funcionando normalmente:

| Modo | Uma rodada vencida vale... | Uma rodada perdida |
|---|---|---|
| **Binário** (padrão) | A base cheia (nota da atividade, ou 100 pontos fixos no ranking) | Zero |
| **Linear** | Uma fração que diminui a cada palpite errado — em qualquer termo *e* na frase-mistério, contados juntos como um único conjunto | Zero |

Os termos não têm um valor de ponto próprio: todo palpite errado, seja em um termo ou na própria
frase-mistério, consome o mesmo conjunto compartilhado de erros, e o tamanho desse conjunto
determina a pontuação linear da rodada inteira:

```
max_errors = num_terms × (max_attempts_per_term − 1) + (max_attempts_final_guess − 1)
pontos (nota)    = nota × (max_errors − erros_usados + 1) / (max_errors + 1)
pontos (ranking) = 100  × (max_errors − erros_usados + 1) / (max_errors + 1)
```

O modo linear não tem nenhuma tolerância: o primeiro palpite errado já reduz a pontuação. Uma
rodada sem nenhum erro continua sempre valendo exatamente a pontuação cheia, e a pontuação nunca
chega a zero numa vitória genuinamente concluída — ela tem um piso de `base / (max_errors + 1)`
mesmo no limite máximo de erros. Como `max_errors` depende das duas configurações de tentativas,
**escolher Linear para a nota ou para o ranking exige que "Máximo de tentativas por termo" e
"Máximo de tentativas para a frase-mistério" sejam ambos um valor real, não ilimitado** — o
formulário de configurações bloqueia o salvamento caso contrário.

Exemplo prático com 5 termos, 3 tentativas por termo, 3 tentativas para a frase-mistério
(`max_errors = 5 × 2 + 2 = 12`) — a coluna de nota assume uma nota máxima 100, mas a coluna de
ranking vale exatamente assim em **qualquer** atividade, mesmo sem nota nenhuma configurada:

| Erros | Nota (base 100) | Ranking (base 100, sempre) | Erros | Nota | Ranking |
|---:|---:|---:|---:|---:|---:|
| 0 | 100,00 | 100,00 | 7 | 46,15 | 46,15 |
| 1 | 92,31 | 92,31 | 8 | 38,46 | 38,46 |
| 2 | 84,62 | 84,62 | 9 | 30,77 | 30,77 |
| 3 | 76,92 | 76,92 | 10 | 23,08 | 23,08 |
| 4 | 69,23 | 69,23 | 11 | 15,38 | 15,38 |
| 5 | 61,54 | 61,54 | 12 | 7,69 | 7,69 |
| 6 | 53,85 | 53,85 | Não concluída | 0,00 | 0,00 |

**Bônus de acerto antecipado:** acertar a frase-mistério corretamente antes de resolver qualquer
termo soma um bônus fixo de 10% em cima da pontuação base acima — 10% da nota da atividade, para a
**nota**; 10% da base fixa de 100 (ou seja, sempre +10 pontos), para o **ranking**. Para a
**nota**, isso tem um teto no máximo nominal da atividade — uma rodada perfeita já em 100%
permanece em 100%. Para o **ranking**, não há teto — a mesma rodada perfeita tem seu total de
ranking em 110, ultrapassando legitimamente a base nominal de 100, já que o ranking recompensa a
dedução precoce eficiente além do que um valor de diário de notas consegue representar.

**Combinar várias rodadas em uma nota final** é uma configuração separada, `Método de avaliação`
(maior nota, média das notas, primeira tentativa, última tentativa, ou média sobre todas as
rodadas exigidas). Ela funciona da mesma forma independente de a pontuação por rodada acima ser
Binária ou Linear: ela só agrega o que cada rodada já registrou.

**O ranking** é a soma dos pontos de ranking de cada rodada finalizada de um estudante (`SUM`),
ordenada da maior para a menor; empates são desfeitos por menos tentativas usadas em média,
depois menos tempo gasto em média. Só aparece quando o professor habilita "Mostrar ranking", e
nunca revela uma rodada ainda em andamento.

**Só os 5 primeiros são mostrados — de propósito, não é um bug:** tanto o widget de ranking no
jogo quanto a página dedicada de ranking limitam a lista a 5 linhas, para evitar um ranking
público de toda a turma. Um estudante em posição mais baixa ainda vê exatamente onde está: uma
linha extra, separada por "…", mostra sua posição e pontuação reais, sem expor a posição de
ninguém abaixo do 5º lugar. Quem gerencia a atividade (editingteacher, manager) nunca aparece no
ranking, mesmo que jogue a atividade — da mesma forma que suas próprias tentativas são excluídas
do relatório de tentativas abaixo.

**"Mostrar ranking" controla só a visibilidade, não a coleta de dados:** os pontos de ranking são
calculados e armazenados para toda rodada finalizada, independente de o ajuste estar ligado ou
desligado no momento. Ativá-lo depois que estudantes já jogaram revela o total completo
acumulado desde o início da atividade, não só os pontos ganhos a partir daquele momento — nada se
perde, e nada precisa ser "recuperado" desligando e religando o ajuste.

**Trava assim que há avaliação:** no momento em que a atividade registra uma nota real para
qualquer estudante, `Termos por rodada`, `Método de avaliação`, `Máximo de tentativas por termo`,
`Máximo de tentativas para a frase-mistério` e `Modo de pontuação da nota` travam todos — da mesma
forma que o Moodle já trava o campo "Nota máxima" de uma atividade avaliada assim que notas reais
existem. Como o orçamento de erros da fórmula linear é uma função direta do número de termos e das
duas configurações de tentativas, mudar qualquer um deles depois que já existem pontuações reais
faria rodadas anteriores e posteriores valerem coisas diferentes; travá-los garante que toda
rodada já registrada permaneça internamente consistente durante toda a vida da atividade.

**`Modo de pontuação do ranking` trava separadamente, assim que existe qualquer tentativa
finalizada** — não espera uma nota real, porque os pontos de ranking já são calculados e gravados
em toda rodada terminada independente de `Nota` ou `Mostrar ranking` estarem ligados (ver acima).
Uma atividade sem nota nenhuma, só com ranking, já acumula histórico real desde a primeira rodada;
travar o modo de pontuação assim que esse histórico existe evita a mesma inconsistência de escala
que a trava acima evita para a nota.

**Histórico de tentativas:** cada estudante pode revisar suas próprias rodadas passadas —
frase-mistério, termos resolvidos, tentativas usadas, tempo, pontuação da nota e (quando o
ranking está ativado) pontos de ranking — numa página dedicada da barra de ferramentas. Quem
gerencia a atividade também vê essa mesma página, incluindo as suas próprias tentativas caso já
tenha jogado a atividade. Já o relatório para todos os estudantes vive numa página separada,
visível só para quem gerencia: uma tabela com todas as tentativas de todos os estudantes,
ordenável clicando em qualquer cabeçalho de coluna e filtrável para um único estudante. Assim
como o ranking, esse relatório nunca inclui as próprias tentativas de um gestor.
