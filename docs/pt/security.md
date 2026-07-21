# 🔐 Segurança e Conformidade

* Controle de acesso baseado em capabilities (`mod/playercross:view`, `mod/playercross:addinstance`)
* Proteção `require_sesskey()` em todas as ações POST; chamadas AJAX são validadas pelo dispatcher `core/ajax` do Moodle
* Aplicação no servidor dos limites de rodada e do intervalo, sempre recalculados a partir das configurações atuais
* O tempo esgotado da rodada é revalidado contra o próprio prazo do servidor, em vez de confiar apenas na contagem regressiva do cliente
* Validação do conjunto de caracteres do palpite — só letras Unicode são aceitas
* Palavras geradas por IA são tratadas como entrada não confiável: só termos de um único token, alfabéticos, dentro do intervalo de comprimento configurado são salvos, e entram pendentes de aprovação do professor
* O estado de sessão da rodada é isolado por instância de atividade e por usuário — um id de palavra ou chave de sessão de uma atividade nunca é aceito por outra
* Um palpite errado de pista ou de frase-mistério nunca vaza a palavra correta; a palavra-tema só é revelada quando a rodada realmente termina
* Compatível com a External API do Moodle
* API de Privacidade totalmente implementada (LGPD/GDPR)

## 🔒 Divulgação de Serviço de Terceiros

A geração de palavras por IA é **opcional** e vem desativada por padrão. Quando um professor a
usa, o tema da atividade (nunca dados de estudante ou registros de tentativa) é enviado através
do `local_aihub` — usando a chave BYOK própria do usuário ou do site, se o plugin estiver
instalado — ou, como alternativa, através do subsistema de IA nativo do Moodle (`core_ai`), que
roteia para o provedor que o administrador do site configurou. O PlayerCross nunca contata um
provedor de IA diretamente; a requisição e sua divulgação/consentimento são inteiramente de
responsabilidade do `local_aihub` ou do `core_ai`. Se nenhum dos dois estiver instalado ou
configurado, a fonte de palavras por IA fica indisponível e todas as demais funcionalidades
continuam funcionando normalmente.
