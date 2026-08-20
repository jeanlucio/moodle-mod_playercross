# 🔐 Segurança e Conformidade

* Controle de acesso baseado em capabilities (`mod/playercross:view`, `mod/playercross:addinstance`, `mod/playercross:managewords`, `mod/playercross:viewreports`)
* Proteção `require_sesskey()` em todas as ações POST; chamadas AJAX são validadas pelo dispatcher `core/ajax` do Moodle
* Aplicação no servidor dos limites de rodada e do intervalo, sempre recalculados a partir das configurações atuais
* O tempo esgotado da rodada é revalidado contra o próprio prazo do servidor, em vez de confiar apenas na contagem regressiva do cliente
* Validação do conjunto de caracteres do palpite — só letras Unicode são aceitas
* Palavras geradas por IA são tratadas como entrada não confiável: só termos de um único token, alfabéticos, dentro do intervalo de comprimento configurado são salvos, e entram pendentes de aprovação do professor
* O estado de sessão da rodada é isolado por instância de atividade e por usuário — um id de palavra ou chave de sessão de uma atividade nunca é aceito por outra
* Um palpite errado de termo ou de frase-mistério nunca vaza a palavra correta; a palavra-tema só é revelada quando a rodada realmente termina
* Compatível com a External API do Moodle
* API de Privacidade totalmente implementada (LGPD/GDPR)

## 🔒 Divulgação de Serviço de Terceiros

A geração de palavras por IA é **opcional** e vem desativada por padrão. Quando um professor a
usa, o tema da atividade (nunca dados de estudante ou registros de tentativa) é enviado através
do [Central de IA](#aihub) (`local_aihub`) — usando a chave BYOK própria do usuário ou do site, se
o plugin estiver instalado — ou, como alternativa, através do subsistema de IA nativo do Moodle
(`core_ai`), que roteia para o provedor que o administrador do site configurou. O PlayerCross
nunca contata um provedor de IA diretamente; a requisição e sua divulgação/consentimento são
inteiramente de responsabilidade do `local_aihub` ou do `core_ai`. Se nenhum dos dois estiver
instalado ou configurado, a fonte de palavras por IA fica indisponível e todas as demais
funcionalidades continuam funcionando normalmente.

* **Custo:** Nenhum é exigido pelo próprio PlayerCross. Se usada, qualquer custo é o que o
  provedor cobrar através de uma chave BYOK no `local_aihub`, ou nenhum custo via um provedor
  `core_ai` gratuito/institucional que o administrador do site já tenha configurado.
* **Chaves de API / credenciais:** Não são configuradas no PlayerCross. Obtenha e configure uma
  chave pessoal ou do site dentro do [Central de IA](#aihub) (`local_aihub`), ou peça ao
  administrador do site para configurar um provedor `core_ai`.
* **Credenciais de demonstração:** Não aplicável — nenhuma credencial é exigida para instalar ou
  usar o PlayerCross; a geração por IA é totalmente opcional.

## 📊 Estatísticas de Uso Anônimas

O PlayerCross envia periodicamente um relatório de uso anônimo e agregado para o próprio
serviço de telemetria do desenvolvedor, para ajudar a priorizar correções e melhorias. É um
mecanismo separado do recurso de IA acima e não exige que ele esteja configurado.

### É obrigatório?

Não. Vem **ativado por padrão**, mas é totalmente opcional: pode ser desligado a qualquer
momento em **Administração do site > Plugins > Módulos de atividade > PlayerCross** ("Enviar
estatísticas de uso anônimas"). Nada nos recursos da atividade muda se for desligado.

### O que é enviado

Um único payload JSON, no máximo uma vez a cada 21 dias:

- **Informações do site:** versão/release do Moodle, versão do PHP, país e idioma do site, se
  o site roda um fork não-padrão do Moodle (Totara/IOMAD/Workplace) e sua versão.
- **Uso do plugin:** versão/release do próprio PlayerCross instalado, uma contagem aproximada
  de estudantes ativos (estudantes com uma tentativa de rodada nos últimos 90 dias), quantas
  instâncias da atividade existem no site, e se os plugins complementares ([Central de
  IA](#aihub) e PlayerHUD) estão instalados e suas versões.
- **Contadores internos de erro:** contagens agregadas de dois pontos de código já conhecidos
  como frágeis na geração de termos por IA — todas as tentativas de provedor falharem, ou a IA
  responder com menos termos utilizáveis do que o pedido (incluindo todo candidato que falhar
  na checagem própria de validade de dica da atividade) — nunca o conteúdo do erro em si, só
  quantas vezes cada um aconteceu.
- **Identificador do site:** o identificador anônimo próprio do Moodle
  (`get_site_identifier()`) e a URL do site, para que relatórios repetidos do mesmo site sejam
  reconhecidos como uma única instalação ao longo do tempo, em vez de contados como instalações
  novas.

### O que nunca é enviado

Nenhum dado de estudante, nenhum dado pessoal de qualquer usuário (nome, e-mail, ID), nenhum
conteúdo do banco de palavras/dicas, nenhum prompt ou resposta de IA, e nenhum dado de cursos
que não têm a atividade adicionada.

### Para onde os relatórios são enviados

Os relatórios são enviados para `https://plugintelemetry.duckdns.org/report.php`, um serviço
operado pelo próprio desenvolvedor do plugin — não um fornecedor de análise de terceiros.

Declarado no próprio Privacy Provider do plugin (`classes/privacy/provider.php`) como uma
localização externa, seguindo a prática padrão da Privacy API do Moodle para qualquer plugin
que transmite dados para fora do site.
