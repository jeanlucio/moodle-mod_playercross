# 🧩 Integração Opcional: Central de IA

A funcionalidade opcional de **Geração de palavras por IA** do PlayerCross pode usar o **Central de IA** (`local_aihub`, do mesmo autor, parte dos serviços compartilhados do ecossistema PlayerGames). Quando o Central de IA está instalado, qualquer chave pessoal ou do site que um professor ou administrador já tenha configurado lá fica automaticamente disponível para o PlayerCross — sem precisar reconfigurar a chave. O PlayerCross nunca contata um provedor de IA diretamente; sem o Central de IA instalado, ele recorre ao subsistema `core_ai` do próprio Moodle, roteando para o provedor que o administrador do site tiver configurado lá.

👉 <https://github.com/jeanlucio/moodle-local_aihub>
