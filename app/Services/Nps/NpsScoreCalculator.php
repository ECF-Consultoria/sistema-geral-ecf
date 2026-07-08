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

        // Query direta na relacao HasMany. O WHERE composto casa exatamente
        // com o indice nps_ans_response_dim_idx (response_id + dimensao).
        // AVG do Query Builder retorna null nativamente quando ha zero rows —
        // e essa a semantica que queremos propagar.
        $media = $response->answers()
            ->where('question_dimensao_snapshot', $dimensao)
            ->avg('option_peso_snapshot');

        if ($media === null) {
            // Zero answers da dimensao pedida -> null semantico ("nao tem
            // pergunta desta dimensao"), nao 0.0. Consumidor decide o display.
            return null;
        }

        // MySQL AVG retorna string decimal ("4.5000"); SQLite retorna float
        // nativo. Cast unifica em float para o contrato ?float do metodo.
        return (float) $media;
    }
}
