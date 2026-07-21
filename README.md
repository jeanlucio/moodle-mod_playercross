# Moodle Activity PlayerCross

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-mod_playercross/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-mod_playercross/actions/workflows/ci.yml)
![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat-square&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat-square)
![Status](https://img.shields.io/badge/Status-Alpha%20%2F%20Pre--release-yellow?style=flat-square)
[![PlayerGames Ecosystem](https://img.shields.io/badge/PlayerGames-Ecosystem-6f42c1?style=flat-square&logo=gamepad&logoColor=white)](https://moodle.org/plugins/browse.php?list=contributor&id=3970322)
![Game Activity](https://img.shields.io/badge/Role-Game_Activity-198754?style=flat-square)

[English](#english) | [Português](#português)

---

## English

**PlayerCross** is a deduction crossword-style vocabulary activity for Moodle. Each round draws a **mystery phrase** (a course concept's own hint) and a set of **clues** (other concepts whose words share letters with it). Solving a clue reveals its shared letters everywhere they occur — in every pending clue and in the mystery phrase itself.

⚠️ **Alpha / pre-release:** this plugin is under active early development (`v0.1.0`) and has not yet been tagged or published to the Moodle Plugins Directory. The mechanics below are implemented and covered by the automated test suite.

The activity integrates with the course **Glossary** (words and definitions are imported automatically), can generate word candidates through **AI**, and integrates with the **PlayerHUD** gamification block (items can be required to start a round or to reveal a clue's hint, and an item can be granted for each round won).

Designed around **retrieval practice** and **spaced repetition**, with an added layer of **associative learning** — the student must hold several concepts in mind at once and notice how they connect through shared letters.

📚 **[Full documentation](https://jeanlucio.github.io/moodle-mod_playercross/)** — features, educational purpose, the PlayerGames ecosystem, usage guide, grading & ranking model, the full 236-case test suite, and security details.

### 🔒 Third-party Service Disclosure

AI word generation is **optional** and disabled by default. When used, the activity topic
(never student data) is sent through `local_aihub` (BYOK) or Moodle's `core_ai` subsystem —
PlayerCross never contacts an AI provider directly.

* **Cost:** None required. AI generation is entirely optional; if used, any cost is whatever
  the underlying provider charges through your own `local_aihub` key, or nothing at all via a
  free/institutional `core_ai` provider the site admin may have already configured.
* **API keys:** Not configured in PlayerCross itself. Obtain and configure a personal or site
  key inside `local_aihub` (see its own documentation), or ask your site administrator to
  configure a `core_ai` provider instead.
* **Demo credentials:** Not applicable — no credentials are required to install or use
  PlayerCross; AI generation is entirely opt-in.

Full disclosure:
[Security & Compliance](https://jeanlucio.github.io/moodle-mod_playercross/#security).

### 📦 Requirements

| Component | Version |
|-----------|---------|
| Moodle    | 4.5+    |
| PHP       | 8.1+    |

### 🛠️ Installation & Configuration

1. Download the `.zip` file or clone this repository.
2. Extract the folder into your Moodle `mod/` directory.
3. Rename the folder to `playercross` (if necessary).
   Final path:
   `your-moodle/mod/playercross/`
4. Visit **Site administration > Notifications** to complete installation.
5. Add a **PlayerCross** activity to any course.

This plugin has no separate site-level settings to configure after installation — every
setting (mystery-phrase length, win condition, grading, PlayerHUD costs, etc.) is configured
by the teacher when adding the activity to a course, as covered in the
[Usage](https://jeanlucio.github.io/moodle-mod_playercross/#usage) section of the full
documentation.

### 🆘 Support

Found a bug or have a question? Open an issue on the
[issue tracker](https://github.com/jeanlucio/moodle-mod_playercross/issues).

### 📄 License

This project is licensed under the **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

### 👤 Maintainer

Maintained by [Jean Lúcio](https://github.com/jeanlucio).

[⬆️ Back to top](#english)

---

## Português

O **PlayerCross** é uma atividade de palavras cruzadas por dedução para o Moodle. Cada rodada sorteia uma **frase-mistério** (a própria dica de um conceito do curso) e um conjunto de **pistas** (outros conceitos cujas palavras compartilham letras com ela). Resolver uma pista revela suas letras compartilhadas em todo lugar onde ocorrem — em todas as pistas pendentes e na própria frase-mistério.

⚠️ **Alfa / pré-lançamento:** este plugin está em desenvolvimento ativo e inicial (`v0.1.0`) e ainda não foi lançado nem publicado no Moodle Plugins Directory. As mecânicas abaixo já estão implementadas e cobertas pela suíte automatizada de testes.

A atividade integra-se com o **Glossário** do curso (palavras e definições são importadas automaticamente), pode gerar candidatas a palavra por **IA**, e integra-se com o bloco de gamificação **PlayerHUD** (itens podem ser exigidos para iniciar uma rodada ou revelar a dica de uma pista, e um item pode ser concedido a cada rodada vencida).

Baseado na **prática de recuperação** e na **repetição espaçada**, com uma camada adicional de **aprendizagem associativa** — o estudante precisa manter vários conceitos em mente ao mesmo tempo e perceber como se conectam por meio de letras compartilhadas.

📚 **[Documentação completa](https://jeanlucio.github.io/moodle-mod_playercross/pt.html)** — funcionalidades, finalidade educacional, ecossistema PlayerGames, guia de uso, modelo de nota e ranking, a suíte completa de 236 testes, e detalhes de segurança.

### 🔒 Divulgação de Serviço de Terceiros

A geração de palavras por IA é **opcional** e vem desativada por padrão. Quando usada, o tema
da atividade (nunca dados de estudante) é enviado através do `local_aihub` (BYOK) ou do
subsistema `core_ai` do Moodle — o PlayerCross nunca contata um provedor de IA diretamente.

* **Custo:** Nenhum é exigido. A geração por IA é totalmente opcional; se usada, qualquer custo
  é o que o provedor cobrar através da sua própria chave no `local_aihub`, ou nenhum custo via
  um provedor `core_ai` gratuito/institucional que o administrador do site já tenha configurado.
* **Chaves de API:** Não são configuradas no PlayerCross. Obtenha e configure uma chave pessoal
  ou do site dentro do `local_aihub` (veja a documentação própria dele), ou peça ao
  administrador do site para configurar um provedor `core_ai`.
* **Credenciais de demonstração:** Não aplicável — nenhuma credencial é exigida para instalar ou
  usar o PlayerCross; a geração por IA é totalmente opcional.

Divulgação completa:
[Segurança e Conformidade](https://jeanlucio.github.io/moodle-mod_playercross/pt.html#security).

### 📦 Requisitos

| Componente | Versão |
|------------|--------|
| Moodle     | 4.5+   |
| PHP        | 8.1+   |

### 🛠️ Instalação e Configuração

1. Baixe o arquivo `.zip` ou clone este repositório.
2. Extraia na pasta `mod/` do seu Moodle.
3. Renomeie para `playercross` (se necessário).
   Caminho final:
   `seu-moodle/mod/playercross/`
4. Acesse **Administração do site > Notificações** para concluir a instalação.
5. Adicione uma atividade **PlayerCross** a qualquer curso.

Este plugin não tem configurações separadas em nível de site após a instalação — toda
configuração (comprimento da frase-mistério, condição de vitória, avaliação, custos do
PlayerHUD etc.) é feita pelo professor ao adicionar a atividade a um curso, conforme
explicado na seção [Como Usar](https://jeanlucio.github.io/moodle-mod_playercross/pt.html#usage)
da documentação completa.

### 🆘 Suporte

Encontrou um bug ou tem alguma dúvida? Abra uma issue no
[rastreador de issues](https://github.com/jeanlucio/moodle-mod_playercross/issues).

### 📄 Licença

Este projeto é licenciado sob a **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

### 👤 Mantenedor

Mantido por [Jean Lúcio](https://github.com/jeanlucio).

[⬆️ Voltar ao topo](#português)
