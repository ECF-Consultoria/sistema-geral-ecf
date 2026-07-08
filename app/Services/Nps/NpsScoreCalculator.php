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
        //   media = SUM(pesos das answers) / N_perguntas do template da dimensao
        // Assim perguntas opcionais nao respondidas puxam a media para baixo
        // (proporcional ao "vazio"). Exemplo: 4 perguntas dim=analista, 3
        // respondidas com pesos 4+5+5 = 14, total 14/4 = 3.5. Se todas as 4
        // fossem respondidas 4+5+5+3 = 17, 17/4 = 4.25.
        //
        // Passo 1: busca o template do survey via snapshot (template_id da FK
        //          viva do NpsSurvey — se o admin apagar o template, N_perguntas
        //          vira 0 e retornamos null pra sinalizar "sem base"). O
        //          snapshot per-row em nps_response_answers protege o SUM
        //          contra hard-delete das perguntas.
        $survey = $response->survey;
        if (! $survey || ! $survey->template_id) {
            return null;
        }

        $nPerguntas = NpsTemplateQuestion::query()
            ->where('template_id', $survey->template_id)
            ->where('dimensao', $dimensao)
            ->count();

        if ($nPerguntas === 0) {
            // Dimensao nao existe neste template — null semantico ("sem
            // pergunta"), nao 0.0. Consumidor decide o display.
            return null;
        }

        // Passo 2: SUM dos pesos snapshot da dimensao (indice
        // nps_ans_response_dim_idx cobre response_id + dimensao). Answers
        // ausentes nao entram no SUM, mas o divisor e N_perguntas do
        // template, nao COUNT(answers). Isso e o comportamento pedido.
        $soma = (float) $response->answers()
            ->where('question_dimensao_snapshot', $dimensao)
            ->sum('option_peso_snapshot');

        return $soma / $nPerguntas;
    }
}
