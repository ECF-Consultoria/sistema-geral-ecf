<?php

namespace App\Services\Nps;

use App\Models\NpsResponse;
use App\Models\NpsResponseCoveredService;
use App\Models\NpsResponseScore;
use App\Models\NpsScoreAssignment;
use App\Models\NpsTemplateQuestion;
use Illuminate\Support\Facades\Log;

/**
 * Congelador do SNAPSHOT imutável de uma NpsResponse — Phase 79 v16.0 (DEC-79-D).
 *
 * Chamado pelo `NpsController::submitResponseV15` DENTRO da transação do submit e
 * DEPOIS de gravar as `nps_response_answers`. Congela, de forma append-only e
 * IMUTÁVEL, três coisas no momento da resposta:
 *
 *  1. `nps_response_scores`           — a média por dimensão (estrategista/analista/
 *                                       empresa), via `NpsScoreCalculator::compute()`.
 *                                       Dimensão sem pergunta no template (compute()
 *                                       == null) NÃO gera linha.
 *  2. `nps_response_covered_services` — os serviços cobertos pelo modelo (template)
 *                                       no momento da resposta, com `service_setor`
 *                                       congelado (independe de edições futuras do
 *                                       catálogo).
 *  3. `nps_score_assignments`         — a atribuição média×pessoa×role×serviço, SÓ
 *                                       para os responsáveis dos serviços cobertos
 *                                       ∩ ATIVOS na empresa. A dimensão EMPRESA fica
 *                                       apenas em scores — NUNCA vira nota de pessoa
 *                                       (meritocracia). Responsável faltante → sem
 *                                       assignment + `Log::warning` de pendência.
 *
 * POR QUE CONGELAR: trocar o responsável (`company_users`), o modelo ou o escopo
 * de serviços DEPOIS não pode reescrever as notas já respondidas — o bônus da
 * Fase 80 lê exatamente o que valia no dia da resposta.
 *
 * ORDEM CRÍTICA (79-RESEARCH Pitfall 3): `registrar()` roda DEPOIS do foreach das
 * answers (senão o calculator leria zero) e DENTRO da transação do submit — este
 * service NÃO abre transação própria (para reverter junto no dedup 23000).
 *
 * Design: classe stateless resolvida via container (`app(NpsSnapshotService::class)`),
 * com `NpsScoreCalculator` injetado. Segue o padrão do próprio calculator.
 *
 * NÃO toca `DesempenhoScoreService`/`->principal()` — as atribuições são aditivas
 * e só serão consumidas na Fase 80 (DEC-79-E).
 *
 * @see app/Services/Nps/NpsScoreCalculator.php (fonte da média por dimensão)
 * @see app/Models/Company.php:197-209 (consultorDoServico/estrategistaDoServico)
 * @see .planning/phases/79-.../79-04-PLAN.md
 */
class NpsSnapshotService
{
    /**
     * Dimensões que geram score de pessoa/empresa — NÃO inclui 'geral'.
     */
    private const DIMENSOES = [
        NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
        NpsTemplateQuestion::DIMENSAO_ANALISTA,
        NpsTemplateQuestion::DIMENSAO_EMPRESA,
    ];

    /**
     * Mapa dimensão → role da pivot `company_users` (DEC-79-C / A3): a dimensão
     * ANALISTA atribui ao slot 'consultor'; ESTRATEGISTA ao 'estrategista'. A
     * dimensão EMPRESA NÃO está aqui — fica só em scores, sem assignment.
     */
    private const DIMENSAO_ROLE = [
        NpsTemplateQuestion::DIMENSAO_ANALISTA     => 'consultor',
        NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA => 'estrategista',
    ];

    public function __construct(private NpsScoreCalculator $calculator)
    {
    }

    /**
     * Congela o snapshot completo (scores + covered_services + assignments) de uma
     * NpsResponse já com as answers gravadas.
     *
     * Idempotência de nível de fluxo é garantida pela transação do submit + dedup
     * composto (Phase 68-04): não há re-registro para a mesma resposta.
     */
    public function registrar(NpsResponse $response): void
    {
        $survey = $response->survey;
        if (! $survey || ! $survey->template_id) {
            // Fluxo legacy (sem template) não gera snapshot — nada a congelar.
            return;
        }

        $company = $survey->company;
        if (! $company) {
            return;
        }

        $agora = now();

        // ─── 1. Médias por dimensão → nps_response_scores ────────────────────
        // Guardamos a referência de cada score por dimensão para amarrar o
        // nps_response_score_id do assignment (chave DEC-79-C).
        $scoresPorDimensao = [];

        foreach (self::DIMENSOES as $dimensao) {
            $media = $this->calculator->compute($response, $dimensao);
            if ($media === null) {
                // Dimensão sem pergunta neste template → NÃO grava score (semântica
                // "sem base", não zero — ver NpsScoreCalculator).
                continue;
            }

            // question_count = nº de perguntas COM PESO do template na dimensão
            // (texto_livre não conta — denominador do cálculo, NÃO count de
            // answers). Vem da MESMA fonte de verdade do calculator
            // (`contarPerguntasComPeso`) para preservar a invariante
            // score_sum / question_count == average_score (bugfix 2026-07-15,
            // quick task 260715-kam — duplicar a query aqui recriaria o bug).
            $questionCount = $this->calculator->contarPerguntasComPeso($survey->template_id, $dimensao);

            $scoreSum = (float) $response->answers()
                ->where('question_dimensao_snapshot', $dimensao)
                ->sum('option_peso_snapshot');

            $scoresPorDimensao[$dimensao] = NpsResponseScore::create([
                'nps_response_id' => $response->id,
                'company_id'      => $company->id,
                'dimensao'        => $dimensao,
                'score_sum'       => $scoreSum,
                'question_count'  => $questionCount,
                'average_score'   => $media,
                'calculated_at'   => $agora,
            ]);
        }

        // ─── 2. Serviços cobertos do modelo → nps_response_covered_services ──
        // Congela TODOS os serviços cobertos pelo template (setor snapshot),
        // independentemente de estarem ativos — é a foto do que o modelo cobria.
        $cobertos = $survey->template->serviceScopes()->get();

        foreach ($cobertos as $servico) {
            NpsResponseCoveredService::create([
                'nps_response_id' => $response->id,
                'servico_id'      => $servico->id,
                'service_setor'   => $servico->setor,
                'captured_at'     => $agora,
            ]);
        }

        // ─── 3. Interseção cobertos ∩ ativos → nps_score_assignments ────────
        // Assignment SÓ para serviços cobertos que também são contrato ATIVO da
        // empresa (blindagem T-79-04-01: nunca atribui a serviço não contratado).
        $ativos = $company->contratosServico()->active()->pluck('servico_id')->all();
        $intersecao = $cobertos->filter(fn ($servico) => in_array($servico->id, $ativos, true));

        foreach ($intersecao as $servico) {
            foreach (self::DIMENSAO_ROLE as $dimensao => $role) {
                $score = $scoresPorDimensao[$dimensao] ?? null;
                if (! $score) {
                    // Sem nota nessa dimensão (template sem a pergunta) → nada a atribuir.
                    continue;
                }

                $responsaveis = $role === 'consultor'
                    ? $company->consultorDoServico($servico->id)->get()
                    : $company->estrategistaDoServico($servico->id)->get();

                if ($responsaveis->isEmpty()) {
                    // Responsável faltante: NÃO cria assignment vazio — registra
                    // pendência para reconciliação (DEC-79-D item 3 / T-79-04-05).
                    Log::warning('[NPS Snapshot] responsável faltante — atribuição não gerada', [
                        'company_id' => $company->id,
                        'servico_id' => $servico->id,
                        'role'       => $role,
                        'dimensao'   => $dimensao,
                    ]);
                    continue;
                }

                foreach ($responsaveis as $user) {
                    NpsScoreAssignment::create([
                        'nps_response_id'       => $response->id,
                        'nps_response_score_id' => $score->id,
                        'company_id'            => $company->id,
                        'servico_id'            => $servico->id,
                        'service_setor'         => $servico->setor,
                        'role'                  => $role,
                        'user_id'               => $user->id,
                        'average_score'         => $score->average_score,
                        'assigned_at'           => $agora,
                    ]);
                }
            }
        }
    }
}
