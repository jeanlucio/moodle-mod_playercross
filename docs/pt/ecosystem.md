# 🕹️ Ecossistema PlayerGames

O PlayerCross faz parte do ecossistema de gamificação **PlayerGames** para Moodle. Sua principal integração direta é com o bloco PlayerHUD:

* **Bloco PlayerHUD (Opcional):** Configure custos em itens para iniciar uma rodada ou revelar a dica de uma pista, e uma concessão de item por rodada vencida.
  👉 https://github.com/jeanlucio/moodle-block_playerhud

* **PlayerGroup (Compatível):** Grupos padrão do Moodle — criados manualmente ou pela atividade PlayerGroup — são respeitados pelo filtro `SEPARATEGROUPS` do ranking.
  👉 https://github.com/jeanlucio/moodle-mod_playergroup

* **PlayerWords (Atividade irmã):** Também parte do ecossistema, o PlayerWords testa a recordação de **um** conceito por rodada em formato estilo Wordle. O PlayerCross reaproveita a mesma arquitetura de pool de palavras/PlayerHUD/diário de notas para adicionar um puzzle que conecta **vários** conceitos em uma única rodada.
  👉 https://github.com/jeanlucio/moodle-mod_playerwords

Veja o [perfil do autor no Moodle Plugins Directory](https://moodle.org/plugins/browse.php?list=contributor&id=3970322) para a família PlayerGames completa.
