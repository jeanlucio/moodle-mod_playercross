<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Brazilian Portuguese language strings for mod_playercross.
 *
 * @package mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['completionrounds_desc'] = 'Completar ao menos {$a} rodada(s)';
$string['completionroundsgroup'] = 'Exigir rodadas concluídas';
$string['cooldown_label'] = 'Intervalo entre rodadas';
$string['cooldown_unit_days'] = 'Dias';
$string['cooldown_unit_hours'] = 'Horas';
$string['cooldown_unit_minutes'] = 'Minutos';
$string['error_atleastonesource'] = 'Selecione ao menos uma fonte de palavras.';
$string['error_completionrounds'] = 'O número de rodadas exigidas deve ser ao menos 1.';
$string['error_cooldown'] = 'O intervalo deve ser 0 ou um valor positivo.';
$string['error_grademethod_average_all'] = 'Este método de avaliação exige que o número máximo de rodadas por estudante não seja Ilimitado.';
$string['error_hud_cost_qty'] = 'A quantidade deve ser ao menos 1.';
$string['error_insufficientpool'] = 'Esta atividade precisa de ao menos {$a} palavras aprovadas no banco antes que uma rodada possa começar.';
$string['error_maxattemptsperclue'] = 'O número máximo de tentativas por pista deve ser 0 ou um valor positivo.';
$string['error_maxlength'] = 'O comprimento máximo deve ser maior ou igual ao comprimento mínimo.';
$string['error_minlength'] = 'O comprimento mínimo deve ser ao menos 1.';
$string['error_thememinlength'] = 'O comprimento mínimo da frase-mistério deve ser ao menos 1.';
$string['error_timerseconds'] = 'O cronômetro deve ser 0 ou um valor positivo.';
$string['gameplayheader'] = 'Configurações de jogo';
$string['glossaryid'] = 'Glossário';
$string['glossaryid_all'] = 'Todos os glossários do curso';
$string['grademethod'] = 'Método de avaliação';
$string['grademethod_average'] = 'Média das notas';
$string['grademethod_average_all'] = 'Média sobre todas as rodadas exigidas';
$string['grademethod_first'] = 'Primeira tentativa';
$string['grademethod_help'] = 'Determina como a nota final é calculada a partir das rodadas do estudante: <ul><li><strong>Maior nota:</strong> a melhor pontuação entre todas as rodadas.</li><li><strong>Média das notas:</strong> a média apenas das rodadas realmente jogadas.</li><li><strong>Primeira tentativa:</strong> a pontuação apenas da primeira rodada.</li><li><strong>Última tentativa:</strong> a pontuação apenas da rodada mais recente.</li><li><strong>Média sobre todas as rodadas exigidas:</strong> a soma das pontuações das rodadas dividida pelo número máximo de rodadas configurado, de modo que qualquer rodada não jogada conta como zero. Exige que o número máximo de rodadas por estudante não seja Ilimitado.</li></ul>';
$string['grademethod_highest'] = 'Maior nota';
$string['grademethod_last'] = 'Última tentativa';
$string['hud_header'] = 'Integração com o PlayerHUD';
$string['hud_hint_cost_item'] = 'Item para revelar a dica de uma pista';
$string['hud_hint_cost_qty'] = 'Quantidade para revelar a dica de uma pista';
$string['hud_item_deleted'] = 'Item excluído (reconfigure este campo)';
$string['hud_item_disabled'] = '{$a} (desabilitado)';
$string['hud_noitem'] = 'Desabilitado (sem custo)';
$string['hud_notincourse'] = 'A integração com o PlayerHUD aparecerá aqui assim que o bloco PlayerHUD for adicionado a este curso.';
$string['hud_notinstalled_desc'] = 'O plugin block_playerhud não está instalado neste site. Instale-o e adicione o bloco PlayerHUD a um curso para permitir que professores recompensem estudantes com itens nas rodadas do PlayerCross.';
$string['hud_notinstalled_heading'] = 'Integração com o PlayerHUD';
$string['hud_round_cost_item'] = 'Item para iniciar uma rodada';
$string['hud_round_cost_qty'] = 'Quantidade para iniciar uma rodada';
$string['hud_win_reward_item'] = 'Item concedido ao vencer uma rodada';
$string['hud_win_reward_item_help'] = 'O estudante recebe este item toda vez que vence uma rodada. Para seguir a mesma regra antifarming do PlayerHUD, nenhum XP é concedido por este item quando o número máximo de rodadas por estudante é Ilimitado — o item ainda é concedido, apenas sem XP.';
$string['hud_win_reward_qty'] = 'Quantidade concedida ao vencer uma rodada';
$string['max_attempts_per_clue'] = 'Máximo de tentativas por pista (0 para ilimitado)';
$string['max_attempts_per_clue_help'] = 'O número máximo de palpites que um estudante pode enviar para uma única pista antes que ela seja considerada não resolvida pelo restante da rodada. Use 0 para permitir tentativas ilimitadas por pista.';
$string['max_length'] = 'Comprimento máximo da palavra de pista';
$string['max_rounds'] = 'Máximo de rodadas por estudante';
$string['max_rounds_unlimited'] = 'Ilimitado';
$string['min_length'] = 'Comprimento mínimo da palavra de pista';
$string['modulename'] = 'PlayerCross';
$string['modulename_help'] = 'PlayerCross é uma atividade de palavras cruzadas por dedução: o estudante resolve pistas de conceitos do curso e cada acerto revela letras compartilhadas de uma frase-mistério final.';
$string['modulenameplural'] = 'PlayerCross';
$string['num_clues'] = 'Pistas por rodada';
$string['playercross:addinstance'] = 'Adicionar uma nova atividade PlayerCross';
$string['playercross:view'] = 'Ver atividade PlayerCross';
$string['playersourcesheader'] = 'Fontes de palavras';
$string['pluginadministration'] = 'Administração do PlayerCross';
$string['pluginname'] = 'PlayerCross';
$string['resetplayercrossattempts'] = 'Excluir tentativas de rodada do PlayerCross';
$string['scoringmode_locked'] = 'Como esta atividade já registrou uma nota real para ao menos um estudante, o número de pistas e o método de avaliação não podem mais ser alterados — mudá-los agora faria rodadas passadas e futuras contarem em escalas diferentes.';
$string['show_ranking'] = 'Mostrar ranking';
$string['source_glossary'] = 'Glossário';
$string['source_manual'] = 'Inserção manual';
$string['stopwords'] = 'Palavras a ignorar em conceitos do glossário';
$string['stopwords_help'] = 'Lista separada por vírgulas de palavras a ignorar ao dividir conceitos de glossário com múltiplas palavras em palavras de pista candidatas. Deixe vazio para desabilitar o filtro (o comprimento mínimo acima continua valendo). Lista sugerida: a, o, de, da, do, das, dos, e, ou, para, com, em, um, uma, os, as, no, na, nos, nas.';
$string['theme_min_length'] = 'Comprimento mínimo da frase-mistério';
$string['theme_min_length_help'] = 'O comprimento mínimo de palavra exigido para que ela seja elegível como frase-mistério. Palavras mais longas produzem mais slots de letras para as pistas cobrirem.';
$string['timer_minutes'] = 'Cronômetro em minutos (0 para desabilitar)';
$string['viewplaceholder'] = 'Esta atividade ainda está em construção. Volte em breve para jogar uma rodada.';
$string['wordmode'] = 'Modo de seleção da frase-mistério';
$string['wordmode_random'] = 'Frase aleatória por rodada';
$string['wordmode_shared'] = 'Sequência compartilhada (todos os estudantes recebem a mesma frase na mesma ordem)';
