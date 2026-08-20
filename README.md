# Moodle Activity PlayerCross

![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat)
![Status](https://img.shields.io/badge/Status-Stable-green?style=flat)
[![Latest Release](https://img.shields.io/github/v/release/jeanlucio/moodle-mod_playercross?style=flat)](https://github.com/jeanlucio/moodle-mod_playercross/releases)
[![PlayerGames Ecosystem](https://img.shields.io/badge/PlayerGames-Ecosystem-6f42c1?style=flat&logo=gamepad&logoColor=white)](https://jeanlucio.github.io/playergames/)
![Game Activity](https://img.shields.io/badge/Role-Game_Activity-198754?style=flat)
[![Author](https://img.shields.io/badge/by-Jean_Lucio-6f42c1?style=flat)](https://marketplace.moodle.com/user/984)

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-mod_playercross/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-mod_playercross/actions/workflows/ci.yml)
[![Last Commit](https://img.shields.io/github/last-commit/jeanlucio/moodle-mod_playercross?style=flat)](https://github.com/jeanlucio/moodle-mod_playercross/commits)
[![Open Issues](https://img.shields.io/github/issues/jeanlucio/moodle-mod_playercross?style=flat)](https://github.com/jeanlucio/moodle-mod_playercross/issues)

[English](#english) | [Português](#português)

---

## English

**PlayerCross** is a deduction crossword-style vocabulary activity for Moodle. Each round draws a **mystery phrase** (a course concept's own clue) and a set of **terms** (other concepts whose words share letters with it). Solving a term reveals its shared letters everywhere they occur — in every pending term and in the mystery phrase itself.

The activity integrates with the course **Glossary** (words and definitions are imported automatically), can generate word candidates through **AI**, and integrates with the **PlayerHUD** gamification block (items can be required to start a round or to reveal a term's hint, and an item can be granted for each round won).

Designed around **retrieval practice** and **spaced repetition**, with an added layer of **associative learning** — the student must hold several concepts in mind at once and notice how they connect through shared letters.

📚 **[Full documentation](https://jeanlucio.github.io/moodle-mod_playercross/)** — features, educational purpose, the PlayerGames ecosystem, usage guide, grading & ranking model, the full 421-case PHPUnit suite (87% line coverage) plus a 35-scenario Behat suite, and security details.

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

PlayerCross also periodically sends an **anonymous usage report** — aggregate, non-personal
site and plugin statistics (Moodle/PHP version, country, language, approximate active student
count, course instances, installed companion plugins, internal error counters) — to the
developer's own telemetry service, to help prioritise fixes and improvements. This is **on by
default** and can be turned off at **Site administration > Plugins > Activity modules >
PlayerCross**. No personal data is included; no student data is ever sent.

Full disclosure:
[Security & Compliance](https://jeanlucio.github.io/moodle-mod_playercross/#security).

### 📦 Requirements

| Component | Version |
|-----------|---------|
| Moodle    | 4.5 – 5.2 |
| PHP       | 8.1+    |

### 🛠️ Installation & Configuration

1. Download the `.zip` file or clone this repository.
2. Extract the folder into your Moodle `mod/` directory.
3. Rename the folder to `playercross` (if necessary).
   Final path:
   `your-moodle/mod/playercross/`
4. Visit **Site administration > Notifications** to complete installation.
5. Add a **PlayerCross** activity to any course.

This plugin has no site-level settings for an admin to configure — every setting
(mystery-phrase length, win condition, grading, PlayerHUD costs, etc.) is configured
by the teacher when adding the activity to a course, as covered in the
[Usage](https://jeanlucio.github.io/moodle-mod_playercross/#usage) section of the full
documentation. If `block_playerhud` isn't installed on the site, the plugin's own settings
page under *Site administration → Plugins → Activity modules → PlayerCross* shows an
informational notice about it — there's nothing to configure there either way.

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

O **PlayerCross** é uma atividade de palavras cruzadas por dedução para o Moodle. Cada rodada sorteia uma **frase-mistério** (a própria pista de um conceito do curso) e um conjunto de **termos** (outros conceitos cujas palavras compartilham letras com ela). Resolver um termo revela suas letras compartilhadas em todo lugar onde ocorrem — em todos os termos pendentes e na própria frase-mistério.

A atividade integra-se com o **Glossário** do curso (palavras e definições são importadas automaticamente), pode gerar candidatas a palavra por **IA**, e integra-se com o bloco de gamificação **PlayerHUD** (itens podem ser exigidos para iniciar uma rodada ou revelar a dica de um termo, e um item pode ser concedido a cada rodada vencida).

Baseado na **prática de recuperação** e na **repetição espaçada**, com uma camada adicional de **aprendizagem associativa** — o estudante precisa manter vários conceitos em mente ao mesmo tempo e perceber como se conectam por meio de letras compartilhadas.

📚 **[Documentação completa](https://jeanlucio.github.io/moodle-mod_playercross/pt.html)** — funcionalidades, finalidade educacional, ecossistema PlayerGames, guia de uso, modelo de nota e ranking, a suíte completa de 421 testes PHPUnit (87% de cobertura de linhas) mais uma suíte Behat de 35 cenários, e detalhes de segurança.

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

O PlayerCross também envia periodicamente um **relatório de uso anônimo** — estatísticas
agregadas e não-pessoais do site e do plugin (versão do Moodle/PHP, país, idioma, número
aproximado de estudantes ativos, instâncias da atividade, plugins complementares instalados,
contadores internos de erro) — para o próprio serviço de telemetria do desenvolvedor, para
ajudar a priorizar correções e melhorias. Isso vem **ativado por padrão** e pode ser desligado
em **Administração do site > Plugins > Módulos de atividade > PlayerCross**. Nenhum dado
pessoal é incluído; nenhum dado de estudante é enviado.

Divulgação completa:
[Segurança e Conformidade](https://jeanlucio.github.io/moodle-mod_playercross/pt.html#security).

### 📦 Requisitos

| Componente | Versão |
|------------|--------|
| Moodle     | 4.5 – 5.2 |
| PHP        | 8.1+   |

### 🛠️ Instalação e Configuração

1. Baixe o arquivo `.zip` ou clone este repositório.
2. Extraia na pasta `mod/` do seu Moodle.
3. Renomeie para `playercross` (se necessário).
   Caminho final:
   `seu-moodle/mod/playercross/`
4. Acesse **Administração do site > Notificações** para concluir a instalação.
5. Adicione uma atividade **PlayerCross** a qualquer curso.

Este plugin não tem configurações de nível de site para o administrador ajustar — toda
configuração (comprimento da frase-mistério, condição de vitória, avaliação, custos do
PlayerHUD etc.) é feita pelo professor ao adicionar a atividade a um curso, conforme
explicado na seção [Como Usar](https://jeanlucio.github.io/moodle-mod_playercross/pt.html#usage)
da documentação completa. Se o `block_playerhud` não estiver instalado no site, a própria
página de configurações do plugin em *Administração do site → Plugins → Módulos de atividade
→ PlayerCross* mostra um aviso informativo sobre isso — não há nada a configurar ali de
qualquer forma.

### 🆘 Suporte

Encontrou um bug ou tem alguma dúvida? Abra uma issue no
[rastreador de issues](https://github.com/jeanlucio/moodle-mod_playercross/issues).

### 📄 Licença

Este projeto é licenciado sob a **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

### 👤 Mantenedor

Mantido por [Jean Lúcio](https://github.com/jeanlucio).

[⬆️ Voltar ao topo](#português)
