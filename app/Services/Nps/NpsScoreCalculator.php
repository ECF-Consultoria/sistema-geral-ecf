<?php

namespace App\Services\Nps;

use App\Models\NpsResponse;
use App\Models\NpsTemplateQuestion;

/**
 * Calculador de nota por dimensao de uma NpsResponse — Phase 69 v15.0.
 *
 * REQ NPS-B-02 / Plan 69-02. Este service e a UNICA fonte de verdade para
 * "nota por dimensao" na v15.0. Substitui a leitura direta das colunas
 * legacy `score_estrategista/analista/empresa` do `nps_responses` (Phase 31),
 * que a partir da Phase 68 sao NULLABLE e nao sao mais gravadas por
 * respostas novas.
 *
 * Contrato:
 *  compute(NpsResponse $response, string $dimensao): ?float
 *
 * Comportamento (research §1 + §5):
 *  1. Le SEMPRE do snapshot per-row em `nps_response_answers.option_peso_snapshot`
 *     — nunca das FKs vivas `template_option_id` (que sao nullOnDelete). Snapshot
 *     e a verdade historica: hard-delete do template nao corrompe o resultado.
 *  2. AGGREGATE = `AVG(option_peso_snapshot)` filtrado por
 *     `question_dimensao_snapshot`. Uniforme entre tipos `escala` e `opcoes`
 *     (research §5) — nao ha branch por tipo, o snapshot ja normaliza o peso 1..5.
 *  3. Semantica de vazio: retorna `null` quando nao ha answers da dimensao
 *     pedida — significa "nao ha perguntas dessa dimensao neste template",
 *     e NAO "nota zero". Consumidores (Phase 72 dashboards e Phase 73
 *     CalculateGoalResults, NPS-F-03) diferenciam null vs 0.0 no display.
 *  4. Defesa (threat T-69-02-01): se a `$dimensao` recebida nao esta na
 *     whitelist `NpsTemplateQuestion::DIMENSOES`, retorna `null` sem lancar
 *     exception. Callers em rota web podem repassar input do usuario e a
 *     validacao de dimensao e via whitelist strict aqui.
 *
 * Indice consumido: `nps_ans_response_dim_idx (response_id, question_dimensao_snapshot)`
 * criado na migration Plan 68-01 100001. A query gerada bate direto no indice.
 *
 * Consumidores previstos (nao acopla, mas registrado para rastreabilidade):
 *  - Phase 72 dashboards NPS-E-05 (agregacao mensal por dimensao)
 *  - Phase 73 CalculateGoalResults (NPS-F-03: meta NPS lida deste service)
 *
 * Design: classe stateless, sem constructor, resolvida via container Laravel
 * (`app(NpsScoreCalculator::class)`). Nao ha estado interno — cada chamada
 * e uma query isolada. Segue padrao do `UnifiedMetricsService` para servicos
 * de calculo puro.
 *
 * Bugfix 2026-07-15 (quick task 260715-kam): o divisor `N_perguntas` contava
 * TAMBEM perguntas `NpsTemplateQuestion::TIPO_TEXTO_LIVRE` — que NUNCA tem
 * peso (`option_peso_snapshot = NULL`, ver NpsTemplateQuestion) — fazendo
 * cada pergunta aberta entrar como ZERO na media. Nota 5.00 ficava
 * matematicamente inalcancavel em qualquer dimensao com 1+ pergunta aberta.
 * Provado contra o template principal de producao (id=2): cliente que
 * respondeu peso 5 em TODAS as perguntas com peso recebia 4.17 (empresa) /
 * 3.89 (analista) / 3.75 (estrategista) em vez de 5.00.
 *
 * DISTINCAO CRITICA entre os dois bugfixes deste calculator (nao confundir):
 *  - Pergunta `escala`/`opcoes` NAO respondida → CONTINUA no divisor, puxando
 *    a media pra baixo (regra de 2026-07-08, PRESERVADA — ver acima).
 *  - Pergunta `texto_livre` → NUNCA entra no divisor, respondida ou nao (fix
 *    2026-07-15). Nao e sobre "tem answer ou nao" — e sobre o TIPO da
 *    pergunta no template.
 * O divisor correto e `contarPerguntasComPeso()`, unica fonte de verdade
 * consumida por este calculator, pelo `NpsSnapshotService` e pelo comando de
 * backfill `nps:backfill-divisor-texto-livre`.
 *
 * @see .planning/research/v15-nps-templates-schema.md §1 (snapshot per-row)
 * @see .planning/research/v15-nps-templates-schema.md §5 (AVG uniforme escala/opcoes)
 * @see .planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-02-PLAN.md
 * @see app/Models/NpsResponseAnswer.php (colunas snapshot)
 * @see app/Models/NpsTemplateQuestion.php (const DIMENSOES)
 */
class NpsScoreCalculator
{
    /**
     * Retorna a media aritmetica dos pesos snapshot das answers de uma
     * dimensao especifica da NpsResponse.
     *
     * @param  NpsResponse  $response  Response com relacao `answers()` (HasMany).
     * @param  string       $dimensao  Uma das constantes de NpsTemplateQuestion::DIMENSOES.
     * @return float|null  AVG dos pesos snapshot, ou null se: (a) dimensao invalida,
     *                     (b) sem answers da dimensao neste response.
     */
    public function compute(NpsResponse $response, string $dimensao): ?float
    {
        // Defesa contra input arbitrario (threat T-69-02-01). Whitelist strict
        // (parametro $strict=true no in_array) evita cast implicito
        // string->bool que aceitaria '' ou 0 como validos.
        if (! in_array($dimensao, NpsTemplateQuestion::DIMENSOES, true)) {
            return null;
        }

        // Bugfix 2026-07-08 (feedback UX pos-deploy): a formula anterior fazia
        // AVG das answers da dimensao — o que descartava perguntas que o
        // cliente pulou. O contrato de negocio pedido pelo usuario e:
        //   media = SUM(pesos das answers) / N_perguntas COM PESO do template
        // Assim perguntas de escala/opcoes nao respondidas puxam a media para
        // baixo (proporcional ao "vazio"). Exemplo: 4 perguntas escala
        // dim=analista, 3 respondidas com pesos 4+5+5 = 14, total 14/4 = 3.5.
        // Se todas as 4 fossem respondidas 4+5+5+3 = 17, 17/4 = 4.25.
        //
        // Bugfix 2026-07-15 (quick task 260715-kam): "N_perguntas" NAO E
        // "todas as perguntas do template na dimensao" — e "perguntas COM
        // PESO" (escala/opcoes). Pergunta `texto_livre` NUNCA tem peso
        // (option_peso_snapshot sempre NULL) e NUNCA pode entrar no divisor,
        // respondida ou nao. Ver `contarPerguntasComPeso()` — fonte unica do
        // denominador, tambem consumida pelo NpsSnapshotService e pelo backfill.
        //
        // Passo 1: busca o template do survey via snapshot (template_id da FK
        //          viva do NpsSurvey — se o admin apagar o template, o divisor
        //          vira 0 e retornamos null pra sinalizar "sem base"). O
        //          snapshot per-row em nps_response_answers protege o SUM
        //          contra hard-delete das perguntas.
        $survey = $response->survey;
        if (! $survey || ! $survey->template_id) {
            return null;
        }

        $nPerguntas = $this->contarPerguntasComPeso($survey->template_id, $dimensao);

        if ($nPerguntas === 0) {
            // Dimensao sem pergunta COM PESO neste template (inclui o caso
            // "so tem texto_livre") — null semantico ("sem base"), nao 0.0 e
            // nunca divisao por zero. Consumidor decide o display.
            return null;
        }

        // Passo 2: SUM dos pesos snapshot da dimensao (indice
        // nps_ans_response_dim_idx cobre response_id + dimensao). Answers
        // ausentes nao entram no SUM, mas o divisor e N_perguntas COM PESO do
        // template, nao COUNT(answers). Isso e o comportamento pedido.
        //
        // Quick task 260716-jps: `whereIn(dimensoesFonte)` no lugar do `=`
        // cru — a nota de estrategista/analista tambem soma as perguntas da
        // dimensao `ambos` (peso conta pros dois). Para empresa/geral,
        // dimensoesFonte devolve so a propria dimensao (comportamento antigo).
        $soma = (float) $response->answers()
            ->whereIn('question_dimensao_snapshot', NpsTemplateQuestion::dimensoesFonte($dimensao))
            ->sum('option_peso_snapshot');

        return $soma / $nPerguntas;
    }

    /**
     * Conta as perguntas COM PESO (`escala`/`opcoes`) do template numa
     * dimensao — o denominador correto de `compute()`. EXCLUI
     * `NpsTemplateQuestion::TIPO_TEXTO_LIVRE` porque essa pergunta nunca
     * grava peso (`option_peso_snapshot` sempre NULL — ver
     * NpsTemplateQuestion) e contá-la no divisor tornava a nota maxima
     * (5.00) matematicamente inalcancavel (bugfix 2026-07-15, quick task
     * 260715-kam). Caso real: template principal de producao (id=2),
     * dimensao empresa com 6 perguntas / 1 texto_livre → teto era 4.17.
     *
     * Fonte unica de verdade do divisor: consumida por este `compute()`,
     * pelo `NpsSnapshotService::registrar()` (grava `question_count`) e pelo
     * comando `nps:backfill-divisor-texto-livre` — nenhum dos 3 duplica esta
     * query.
     *
     * @param  int     $templateId  FK viva de `NpsSurvey::template_id`.
     * @param  string  $dimensao    Uma das constantes de NpsTemplateQuestion::DIMENSOES.
     * @return int  Numero de perguntas `escala`/`opcoes` do template na dimensao.
     */
    public function contarPerguntasComPeso(int $templateId, string $dimensao): int
    {
        // Quick task 260716-jps: divisor tambem conta as perguntas `ambos`
        // quando a dimensao pedida e estrategista/analista (mesma fonte da SUM
        // em compute() — preserva a invariante score_sum/question_count ==
        // average_score). `dimensoesFonte` devolve so a propria dimensao para
        // empresa/geral.
        return NpsTemplateQuestion::query()
            ->where('template_id', $templateId)
            ->whereIn('dimensao', NpsTemplateQuestion::dimensoesFonte($dimensao))
            ->where('tipo', '!=', NpsTemplateQuestion::TIPO_TEXTO_LIVRE)
            ->count();
    }
}
