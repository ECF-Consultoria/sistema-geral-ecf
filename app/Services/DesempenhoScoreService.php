<?php

namespace App\Services;

use App\Models\AdmanMetric;
use App\Models\BonusFaixa;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\User;
use App\Services\Metrics\MetricsProviderFactory;
use App\Services\Nps\NpsScoreCalculator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Engine v2 de score do módulo Desempenho — Phase 74 (D-05, D-06, D-07, D-17).
 *
 * Substitui in-place o `PortfolioScoreService` v1 (6 métricas ponderadas com
 * cap ±20% em crescimento + normalização de faixas 1-5). A partir da decisão
 * da diretoria/gestão da ECF em 2026-07-09 (SPEC-01 a SPEC-14), a régua de
 * bonificação foi drasticamente simplificada:
 *
 *   • 4 parâmetros — NPS médio | var. faturamento vs mês anterior |
 *     var. margem de contribuição vs mês anterior | absenteísmo (standby).
 *   • Média direta em escalas naturais (DESEMP-02) — sem ponderação, sem
 *     redistribuição, sem normalização régua 1-5.
 *   • Consolidação mensal fechada — sem rolling 30d.
 *   • Faixa de bônus vem da tabela editável `bonus_faixas` (Plan 74-02).
 *   • Regra suplementar: 2 meses consecutivos em `intermediario` promove
 *     para `maximo` (DESEMP-08).
 *
 * Requirements endereçados por este service:
 *  - DESEMP-01 · Engine v2 de score
 *  - DESEMP-02 · Fórmula média direta em escalas naturais
 *  - DESEMP-03 · NPS = média das notas do mês; sem respostas → 0 (penaliza)
 *  - DESEMP-04 · % variação de faturamento (empresas novas / sem baseline excluídas)
 *  - DESEMP-05 · % variação de margem de contribuição via Adman canônico
 *  - DESEMP-06 · Absenteísmo standby (placeholder null)
 *  - DESEMP-07 · Faixa vem de `BonusFaixa::classificar()` (delegação, sem hardcode)
 *  - DESEMP-08 · Promoção por 2 meses consecutivos em `intermediario` → `maximo`
 *  - DESEMP-10 · Sem carteira → shape com flag `sem_carteira=true` + motivo pt-BR
 *  - DESEMP-11 · Fonte de dados: ML OAuth first (MetricsProviderFactory) + Adman fallback
 *
 * Consumidores previstos (não acopla, apenas rastreabilidade):
 *  - Plan 74-04 · comando `desempenho:consolidar-mes` (cron mensal dia 1)
 *  - Plan 74-04 · comando `desempenho:snapshot-scores` (cron diário 13:30, reescrito)
 *  - Plan 74-06 · `Performance/Dashboard.jsx` e `Show.jsx` (view individual)
 *  - Plans 74-09 / 74-10 · testes Feature com fixture Carlos como âncora
 *
 * Design:
 *  - Stateless entre chamadas exceto pelo cache in-memory de `BonusFaixa`
 *    (invalidado naturalmente entre requests / instâncias do container).
 *  - `MetricsProviderFactory` + `NpsScoreCalculator` injetados via DI padrão
 *    Laravel — o service NUNCA instancia providers/calculators direto.
 *  - Métodos privados retornam tipos escalares (`?float` / `array`) — nunca
 *    DTOs próprios. O shape de retorno público é um array (documentado abaixo
 *    e em `.planning/phases/74-.../74-03-PLAN.md` `<interfaces>`).
 *
 * @see .planning/phases/74-.../74-CONTEXT.md §D-05, D-06, D-07, D-17
 * @see .planning/phases/74-.../74-SPEC.md DESEMP-01..08, DESEMP-10, DESEMP-11
 */
class DesempenhoScoreService
{
    /**
     * Cache in-memory das faixas ativas — evita re-query em loops de ranking
     * (ex: consolidação mensal itera 15-20 users e cada um chama classificar).
     * Preenche na primeira chamada de `classificarFaixa()`; invalidação natural
     * entre requests (o container instancia o service uma vez por request).
     *
     * @var EloquentCollection<int, BonusFaixa>|null
     */
    private ?EloquentCollection $faixasCache = null;

    public function __construct(
        private MetricsProviderFactory $metricsFactory,
        private NpsScoreCalculator $npsCalculator,
    ) {
    }

    /**
     * Computa o score de desempenho completo do user para o mês de referência.
     *
     * Shape de retorno (locked em `74-03-PLAN.md` `<interfaces>`):
     *
     * ```
     * [
     *   'user_id'               => int,
     *   'user_name'             => string,
     *   'mes_referencia'        => string,  // YYYY-MM-01
     *   'sem_carteira'          => bool,
     *   'motivo'                => ?string, // "Sem carteira em julho/2026" quando sem_carteira
     *   'empresas_carteira'     => int,
     *   'empresas_com_baseline' => int,     // usadas em var_faturamento
     *   'componentes' => [
     *     'nps_medio'           => ?float,  // 0.0 quando user não recebeu notas no mês
     *     'var_faturamento_pct' => ?float,  // null quando nenhuma empresa qualifica
     *     'var_margem_pct'      => ?float,
     *     'absenteismo_pct'     => null,    // sempre null nesta phase (DESEMP-06)
     *   ],
     *   'nota_final'            => ?float,  // 2 decimais; null se todos componentes null
     *   'faixa_bonus'           => ?string, // slug de BonusFaixa
     *   'faixa_promovida'       => bool,    // true se DESEMP-08 alterou a faixa
     * ]
     * ```
     *
     * Fluxo:
     *  1. `computeUniverso` — se `sem_carteira=true`, retorna shape com nulls.
     *  2. Calcula 4 componentes (NPS/faturamento/margem/absenteísmo).
     *  3. `computeNotaFinal` — média direta em escalas naturais.
     *  4. `classificarFaixa` + `promoverPor2MesesConsecutivos` — DESEMP-08.
     *  5. Monta shape final.
     */
    public function compute(User $user, Carbon $mesReferencia): array
    {
        $mes = $mesReferencia->copy()->startOfMonth();

        // ── Universo (carteira ativa no mês) ─────────────────────────────────
        $universo = $this->computeUniverso($user, $mes);

        if ($universo['sem_carteira']) {
            return $this->shapeSemCarteira($user, $mes, $universo['motivo']);
        }

        /** @var EloquentCollection<int, \App\Models\Company> $companies */
        $companies = $universo['companies'];

        // ── 4 componentes independentes ──────────────────────────────────────
        $nps        = $this->computeNpsMedio($user, $mes);
        $varFatData = $this->computeVarFaturamento($user, $mes, $companies);
        $varMargem  = $this->computeVarMargem($user, $mes, $companies);
        $absent     = $this->computeAbsenteismo($user, $mes);

        $varFat            = $varFatData['pct'];
        $empresasBaseline  = $varFatData['empresas_com_baseline'];

        // ── Nota final (média direta, sem absenteísmo) ───────────────────────
        $nota = $this->computeNotaFinal($nps, $varFat, $varMargem);

        // ── Classificação + promoção DESEMP-08 ───────────────────────────────
        $faixaInicial   = $nota !== null ? $this->classificarFaixa($nota) : null;
        $faixaPromovida = false;
        $faixaFinal     = $faixaInicial;

        if ($faixaInicial !== null) {
            $promocao       = $this->promoverPor2MesesConsecutivos($user, $mes, $faixaInicial, $nota);
            $faixaFinal     = $promocao['faixa'];
            $faixaPromovida = $promocao['promovida'];
        }

        // ── Metadados do período (UI mostra aviso "mês em curso") ─────────────
        $hoje          = now();
        $mesCorrente   = $mes->copy()->startOfMonth();
        $ehMesEmCurso  = $hoje->between($mesCorrente, $mesCorrente->copy()->endOfMonth());
        $diasDecorridos = $ehMesEmCurso ? $hoje->day : $mesCorrente->daysInMonth;
        $diasNoMes      = $mesCorrente->daysInMonth;

        return [
            'user_id'               => $user->id,
            'user_name'             => $user->name,
            'mes_referencia'        => $mes->toDateString(),
            'sem_carteira'          => false,
            'motivo'                => null,
            'empresas_carteira'     => $companies->count(),
            'empresas_com_baseline' => $empresasBaseline,
            'componentes' => [
                'nps_medio'           => $nps,
                'var_faturamento_pct' => $varFat,
                'var_margem_pct'      => $varMargem,
                'absenteismo_pct'     => $absent,
            ],
            'nota_final'      => $nota,
            'faixa_bonus'     => $faixaFinal,
            'faixa_promovida' => $faixaPromovida,
            // Metadata para UI mostrar aviso e ajudar analistas a entender por
            // que variações podem parecer baixas no início do mês. Comparação
            // já é justa (dia 1..hoje vs mesmo range mês anterior), mas número
            // de dias na amostra afeta significância estatística.
            'periodo_meta' => [
                'em_curso'        => $ehMesEmCurso,
                'dias_decorridos' => $diasDecorridos,
                'dias_no_mes'     => $diasNoMes,
            ],
        ];
    }

    // ═══ Métodos privados por componente ═══════════════════════════════════

    /**
     * Verifica se o user tem carteira ativa no mês. Retorna:
     *  - `['sem_carteira' => true, 'motivo' => '...']` quando vazia
     *  - `['sem_carteira' => false, 'companies' => Collection]` caso contrário
     *
     * DESEMP-10: "Sem carteira em julho/2026" (motivo pt-BR).
     */
    private function computeUniverso(User $user, Carbon $mes): array
    {
        $companies = $user->companies()->where('active', true)->get();

        if ($companies->isEmpty()) {
            return [
                'sem_carteira' => true,
                'motivo'       => "Sem carteira em {$this->mesExtenso($mes)}",
            ];
        }

        return [
            'sem_carteira' => false,
            'companies'    => $companies,
        ];
    }

    /**
     * NPS médio do user no mês na dimensão apropriada.
     *
     * Regras (DESEMP-03):
     *  - Dimensão: `estrategista` se `$user->isMentor()`, senão `analista`
     *    (mesmo mapeamento do v1 + dual-path Phase 72/73).
     *  - Iterar surveys `completed` do mês cujas empresas estejam na carteira.
     *  - Para cada resposta: tentar `NpsScoreCalculator::compute` (v15 path).
     *    Se retornar null, fallback DIRETO para `score_estrategista` ou
     *    `score_analista` legacy (Phase 72/73 dual-path).
     *  - Média aritmética das notas coletadas.
     *  - Sem respostas → retorna `0.0` (PENALIZA por decisão da diretoria).
     *
     * @return float sempre >= 0.0
     */
    private function computeNpsMedio(User $user, Carbon $mes): float
    {
        $dim = $user->isMentor() ? 'estrategista' : 'analista';

        $companyIds = $user->companies()
            ->where('active', true)
            ->pluck('companies.id');

        if ($companyIds->isEmpty()) {
            return 0.0;
        }

        $surveys = NpsSurvey::with('response')
            ->whereIn('company_id', $companyIds)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [
                $mes->copy()->startOfMonth(),
                $mes->copy()->endOfMonth(),
            ])
            ->get();

        $notas = collect();

        foreach ($surveys as $survey) {
            /** @var NpsResponse|null $response */
            $response = $survey->response;
            if ($response === null) {
                continue;
            }

            // ── v15 path (canonical) ────────────────────────────────────────
            $nota = $this->npsCalculator->compute($response, $dim);

            // ── legacy fallback (Phase 72/73 dual-path) ──────────────────────
            // Quando o template não tem perguntas na dimensão pedida ou o
            // survey é anterior à v15.0 (sem template_id), `compute` retorna
            // null. Cai para as colunas legacy `score_estrategista/analista`
            // do próprio NpsResponse (populadas antes da Phase 68).
            if ($nota === null) {
                $legacyField = $dim === 'estrategista' ? 'score_estrategista' : 'score_analista';
                $legacyScore = $response->{$legacyField} ?? null;
                if ($legacyScore !== null && $legacyScore > 0) {
                    $nota = (float) $legacyScore;
                }
            }

            if ($nota !== null) {
                $notas->push($nota);
            }
        }

        if ($notas->isEmpty()) {
            // DESEMP-03 · Sem respostas no mês FORÇA nps = 0 (penaliza) por
            // decisão da diretoria. Não retornar null aqui.
            return 0.0;
        }

        return round($notas->avg(), 2);
    }

    /**
     * % variação de faturamento vs mês anterior — média das % por empresa.
     *
     * Regras (DESEMP-04, DESEMP-11):
     *  - Descartar empresas NOVAS (associadas ao user via `company_users`
     *    há menos de 2 meses — proxy: `pivot->created_at`).
     *  - Descartar empresas com `caseFor === 'none'` (sem provider aplicável).
     *  - Fonte primária: ML via `MlMetricsProvider::readForCompany()` quando
     *    `caseFor in ['ambos', 'so-ml']`. Fallback ao `AdmanMetric` local.
     *  - Descartar empresas com `rev_anterior <= 0` (sem baseline).
     *  - Retornar média das variações ou `null` se nenhuma qualifica.
     *
     * TODO Plan 74-09: cobrir edge case "empresa nova" via factory que
     * sobreescreva `company_users.created_at` para exercitar o filtro.
     *
     * @param  EloquentCollection<int, \App\Models\Company>  $companies  carteira ativa
     * @return array{pct: ?float, empresas_com_baseline: int}
     */
    private function computeVarFaturamento(User $user, Carbon $mes, EloquentCollection $companies): array
    {
        // ── Filtro "empresa nova na carteira" ────────────────────────────────
        // Ajuste 2026-07-09 (força tarefa): a spec original DESEMP-04 dizia
        // "empresa nova (menos de 2 meses na carteira) não conta". O código
        // usava `company_users.created_at` como proxy — MAS o pivot foi
        // recriado recentemente para praticamente todas as empresas (rebind
        // administrativo), o que fez 97% das empresas serem consideradas
        // "novas" e o ranking ficar quase vazio (6 empresas qualificadas
        // de 212 na equipe toda).
        //
        // Diagnóstico do VPS mostrou que trocar o filtro para
        // `companies.created_at` (data de CADASTRO da empresa no sistema)
        // sobe a qualificação para 160 de 212 (~75%) — que é o resultado
        // que faz sentido semanticamente: filtrar empresas RECÉM cadastradas
        // no sistema, não empresas com vínculo recém-recriado.
        $limiteNova = $mes->copy()->subMonth()->startOfMonth();

        $companiesQualificadas = $companies->filter(function ($company) use ($limiteNova) {
            $createdAt = $company->created_at;
            if ($createdAt === null) {
                return true; // fallback: não descartar por erro de dado
            }
            $createdCarbon = $createdAt instanceof Carbon
                ? $createdAt
                : Carbon::parse($createdAt);

            return $createdCarbon->lt($limiteNova);
        });

        if ($companiesQualificadas->isEmpty()) {
            return ['pct' => null, 'empresas_com_baseline' => 0];
        }

        // ── Filtro "provider aplicável" ──────────────────────────────────────
        $companiesQualificadas = $companiesQualificadas->filter(
            fn ($c) => $this->metricsFactory->caseFor($c) !== 'none'
        );

        if ($companiesQualificadas->isEmpty()) {
            return ['pct' => null, 'empresas_com_baseline' => 0];
        }

        $companyIds  = $companiesQualificadas->pluck('id');

        // ── Comparação JUSTA de período (Ajuste 2026-07-09) ──────────────────
        // Quando o mês de referência é o MÊS CORRENTE (ainda não fechou), comparar
        // o intervalo dia 1 até HOJE com o MESMO range no mês anterior — evita a
        // distorção de comparar "9 dias de julho" com "30 dias de junho" que
        // gerava variações artificialmente negativas (-70%+) e distorcia toda a
        // régua de bônus dos analistas/estrategistas.
        //
        // Quando o mês de referência é um MÊS FECHADO (passado), usar meses
        // calendário completos (comportamento original).
        $hoje       = now();
        $mesCorrente = $mes->copy()->startOfMonth();
        $ehMesEmCurso = $hoje->between($mesCorrente, $mesCorrente->copy()->endOfMonth());

        if ($ehMesEmCurso) {
            $diaAtual   = $hoje->day;
            $inicioMes  = $mesCorrente->copy();
            $fimMes     = $hoje->copy()->endOfDay();
            $inicioAnter = $mesCorrente->copy()->subMonth();
            $fimAnter    = $inicioAnter->copy()->setDay(min($diaAtual, $inicioAnter->daysInMonth))->endOfDay();
        } else {
            $inicioMes   = $mes->copy()->startOfMonth();
            $fimMes      = $mes->copy()->endOfMonth();
            $inicioAnter = $mes->copy()->subMonth()->startOfMonth();
            $fimAnter    = $mes->copy()->subMonth()->endOfMonth();
        }

        // Adman fallback: 2 queries agregadas (mês atual + anterior).
        // whereDate para robustez SQLite (padrão SnapshotDesempenhoScores).
        $admanAtual = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereDate('reference_date', '>=', $inicioMes->toDateString())
            ->whereDate('reference_date', '<=', $fimMes->toDateString())
            ->selectRaw('company_id, SUM(revenue) as rev')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $admanAnterior = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereDate('reference_date', '>=', $inicioAnter->toDateString())
            ->whereDate('reference_date', '<=', $fimAnter->toDateString())
            ->selectRaw('company_id, SUM(revenue) as rev')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $vars              = collect();
        $empresasBaseline  = 0;

        foreach ($companiesQualificadas as $company) {
            $case = $this->metricsFactory->caseFor($company);

            // Ajuste 2026-07-09 (fix Luiz): baseline (revenue anterior) deve vir
            // da MESMA fonte que o atual. Antes, atual vinha do ML (real, fresh)
            // e anterior sempre do Adman local — quando Adman sincronizou pouco
            // no mês passado pra empresa OAuth, o baseline ficava ridículo
            // (LAURA LAR: Adman R$ 299 vs ML R$ 632.601 → +211.189% distorção).
            //
            // Regra nova: se a empresa é lida via ML no atual, TAMBÉM ler o
            // baseline via ML. Se ML falhar em qualquer janela, cair para Adman
            // em AMBAS (nunca misturar fontes = evita bug de baseline).
            $revAtual    = null;
            $revAnterior = null;
            $fonteConsistente = null;

            if (in_array($case, ['ambos', 'so-ml'], true)) {
                $providers = $this->metricsFactory->forCompany($company);
                if (! empty($providers)) {
                    try {
                        $dtoAtual  = $providers[0]->readForCompany($company, $inicioMes,  $fimMes);
                        $dtoAnter  = $providers[0]->readForCompany($company, $inicioAnter, $fimAnter);
                        if ($dtoAtual->revenue !== null && $dtoAnter->revenue !== null) {
                            $revAtual         = (float) $dtoAtual->revenue;
                            $revAnterior      = (float) $dtoAnter->revenue;
                            $fonteConsistente = 'ml';
                        }
                    } catch (\Throwable $e) {
                        // ML provider já loga internamente; cai pro Adman abaixo.
                    }
                }
            }

            // Fallback (ou fonte única para so-adman): Adman em AMBAS as janelas.
            if ($fonteConsistente === null) {
                $revAtual    = (float) ($admanAtual->get($company->id)?->rev ?? 0.0);
                $revAnterior = (float) ($admanAnterior->get($company->id)?->rev ?? 0.0);
            }

            if ($revAnterior <= 0) {
                continue; // sem baseline — descarta (DESEMP-04)
            }

            $empresasBaseline++;
            $vars->push((($revAtual - $revAnterior) / $revAnterior) * 100.0);
        }

        if ($vars->isEmpty()) {
            return ['pct' => null, 'empresas_com_baseline' => 0];
        }

        return [
            'pct'                   => round($vars->avg(), 2),
            'empresas_com_baseline' => $empresasBaseline,
        ];
    }

    /**
     * % variação de margem de contribuição vs mês anterior.
     *
     * Regras (DESEMP-05):
     *  - Fonte SEMPRE `AdmanMetric` (spec conhece o gap: ML não expõe custo).
     *  - SQL agregado `SUM(contribution_margin)` por empresa em ambos os meses.
     *  - Descartar `margem_anterior <= 0`.
     *  - Retornar média das variações ou `null` se nenhuma qualifica.
     *
     * @param  EloquentCollection<int, \App\Models\Company>  $companies
     */
    private function computeVarMargem(User $user, Carbon $mes, EloquentCollection $companies): ?float
    {
        if ($companies->isEmpty()) {
            return null;
        }

        $companyIds  = $companies->pluck('id');

        // ── Comparação JUSTA de período (Ajuste 2026-07-09) ──────────────────
        // Mesmo pattern de computeVarFaturamento: mês em curso compara dia 1..hoje
        // vs mesmo range no mês anterior, evitando queda artificial de margem
        // por diferença de dias entre mês corrente parcial e mês passado completo.
        $hoje         = now();
        $mesCorrente  = $mes->copy()->startOfMonth();
        $ehMesEmCurso = $hoje->between($mesCorrente, $mesCorrente->copy()->endOfMonth());

        if ($ehMesEmCurso) {
            $diaAtual   = $hoje->day;
            $inicioMes  = $mesCorrente->copy();
            $fimMes     = $hoje->copy()->endOfDay();
            $inicioAnter = $mesCorrente->copy()->subMonth();
            $fimAnter    = $inicioAnter->copy()->setDay(min($diaAtual, $inicioAnter->daysInMonth))->endOfDay();
        } else {
            $inicioMes   = $mes->copy()->startOfMonth();
            $fimMes      = $mes->copy()->endOfMonth();
            $inicioAnter = $mes->copy()->subMonth()->startOfMonth();
            $fimAnter    = $mes->copy()->subMonth()->endOfMonth();
        }

        // Ajuste 2026-07-09 (fix Luiz): traz margem_dias (COUNT de linhas com
        // contribution_margin NOT NULL) para distinguir "sem dados Adman" de
        // "margem zero real". Sem esse guard, empresas OAuth com Adman
        // sincronizado só numa das duas janelas puxavam a média para -100%
        // artificial (via 0/positive = -100%).
        $margemAtual = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereDate('reference_date', '>=', $inicioMes->toDateString())
            ->whereDate('reference_date', '<=', $fimMes->toDateString())
            ->selectRaw('company_id, SUM(contribution_margin) as margem, SUM(CASE WHEN contribution_margin IS NOT NULL THEN 1 ELSE 0 END) as margem_dias')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $margemAnterior = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereDate('reference_date', '>=', $inicioAnter->toDateString())
            ->whereDate('reference_date', '<=', $fimAnter->toDateString())
            ->selectRaw('company_id, SUM(contribution_margin) as margem, SUM(CASE WHEN contribution_margin IS NOT NULL THEN 1 ELSE 0 END) as margem_dias')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $vars = collect();

        foreach ($companies as $company) {
            $rowAtual    = $margemAtual->get($company->id);
            $rowAnterior = $margemAnterior->get($company->id);

            // Precisa TER dados de margem em AMBAS as janelas — senão pula
            // (evita o -100% artificial quando Adman sincronizou só uma delas).
            $temDadosAtual    = $rowAtual    !== null && (int) $rowAtual->margem_dias    > 0;
            $temDadosAnterior = $rowAnterior !== null && (int) $rowAnterior->margem_dias > 0;
            if (! $temDadosAtual || ! $temDadosAnterior) {
                continue;
            }

            $atual    = (float) $rowAtual->margem;
            $anterior = (float) $rowAnterior->margem;

            if ($anterior <= 0) {
                continue; // sem baseline de margem — descarta
            }

            $vars->push((($atual - $anterior) / $anterior) * 100.0);
        }

        if ($vars->isEmpty()) {
            return null;
        }

        return round($vars->avg(), 2);
    }

    /**
     * Absenteísmo — placeholder retorna null sempre.
     *
     * DESEMP-06 · standby — fonte de dados em definição pela diretoria
     * (biometria facial da porta OU login-based). Método existe para futuro
     * plumbing sem quebrar o shape do `compute()`. Fica placeholder até phase
     * futura decidir a fonte.
     */
    private function computeAbsenteismo(User $user, Carbon $mes): ?float
    {
        return null;
    }

    /**
     * Nota final = média direta em escalas naturais dos componentes não-null.
     *
     * DESEMP-02: sem normalização régua 1-5, sem redistribuição de pesos.
     * Absenteísmo NUNCA entra no cálculo — é excluído por spec (DESEMP-06).
     *
     * Ajuste 2026-07-09 (pós-deploy): variação bruta em % permitia notas fora
     * do range [1, 5] (ex: analista com var_fat=-15% + var_margem=-20% ficava
     * com nota ~-10) e distorcia toda a régua de bônus. Fix: as variações
     * passam pelas réguas 1-5 antes de entrar na média — todos os 3 componentes
     * ficam na mesma escala 1-5, e a nota final SEMPRE fica em [1.0, 5.0].
     *
     * @return ?float 2 decimais em [1.0, 5.0]; null quando TODOS os componentes são null
     */
    private function computeNotaFinal(?float $nps, ?float $varFat, ?float $varMargem): ?float
    {
        // NPS já é 1-5 (escala do formulário) — clamp defensivo.
        $npsPts = $nps !== null ? max(1.0, min(5.0, $nps)) : null;

        // Variações passam pelas réguas 1-5 (SPEC-04/SPEC-05) para caber na
        // mesma escala do NPS e produzir média significativa.
        $fatPts    = $this->reguaFaturamento($varFat);
        $margemPts = $this->reguaMargem($varMargem);

        $componentes = collect([$npsPts, $fatPts, $margemPts])
            ->reject(fn ($v) => $v === null);

        if ($componentes->isEmpty()) {
            return null;
        }

        return round($componentes->sum() / $componentes->count(), 2);
    }

    /**
     * Régua de FATURAMENTO — aplica pontuação 1-5 pts à % de variação de faturamento
     * vs mês anterior por empresa (média da carteira).
     *
     * Ancorada no SPEC-04 "Régua de Faturamento" da diretoria, adaptada à
     * interpretação vs-mês-anterior escolhida em spec-phase Q1:
     *   ≤ -6%  → 1 pt (queda severa)
     *   ≤ -1%  → 2 pts (queda leve)
     *   <  1%  → 3 pts (estável / meta)
     *   ≤  5%  → 4 pts (crescimento saudável)
     *   >  5%  → 5 pts (crescimento excelente)
     */
    private function reguaFaturamento(?float $pct): ?float
    {
        if ($pct === null) return null;
        if ($pct <= -6)    return 1.0;
        if ($pct <= -1)    return 2.0;
        if ($pct <   1)    return 3.0;
        if ($pct <=  5)    return 4.0;
        return 5.0;
    }

    /**
     * Régua de MARGEM DE CONTRIBUIÇÃO — aplica pontuação 1-5 pts à % de variação
     * de margem vs mês anterior por empresa (média da carteira).
     *
     * Ancorada no SPEC-05 "Régua de Margem" da diretoria:
     *   ≤ -5%  → 1 pt
     *   ≤ -2%  → 2 pts
     *   ≤  1%  → 3 pts
     *   ≤  4%  → 4 pts
     *   >  4%  → 5 pts
     */
    private function reguaMargem(?float $pct): ?float
    {
        if ($pct === null) return null;
        if ($pct <= -5)    return 1.0;
        if ($pct <= -2)    return 2.0;
        if ($pct <=  1)    return 3.0;
        if ($pct <=  4)    return 4.0;
        return 5.0;
    }

    /**
     * Classifica a nota na régua ATIVA de `bonus_faixas`.
     *
     * DESEMP-07: sem hardcode — delega para `BonusFaixa::classificar()`, que
     * consulta a régua editável pelo admin. Cache in-memory garante que loops
     * de ranking (15-20 users) só bata no DB uma vez por request.
     *
     * IMPORTANTE: NÃO aplica DESEMP-08 aqui — a regra de promoção depende de
     * histórico do snapshot mensal e é responsabilidade do
     * `promoverPor2MesesConsecutivos`.
     *
     * @return ?string slug da faixa (`sem_bonus`, `basico`, `intermediario`,
     *                 `maximo`) ou null se nenhuma cobre.
     */
    private function classificarFaixa(float $nota): ?string
    {
        if ($this->faixasCache === null) {
            $this->faixasCache = BonusFaixa::ativas()->ordenadas()->get();
        }

        foreach ($this->faixasCache as $faixa) {
            $min = (float) $faixa->nota_min;
            $max = (float) $faixa->nota_max;
            if ($nota >= $min && $nota <= $max) {
                return $faixa->slug;
            }
        }

        return null;
    }

    /**
     * Aplica a regra DESEMP-08: se a faixa atual é `intermediario` E:
     *   (a) a nota do mês corrente é >= 5.00 exato, OU
     *   (b) o snapshot mensal do MESMO user do mês M-1 também foi `intermediario`,
     * promove para `maximo`.
     *
     * Consulta o snapshot mensal via scope `mensal()` do Model + filtro por
     * `mes_referencia` = mês anterior (`YYYY-MM-01`). Não usa `ref_date`
     * porque em snapshots mensais `ref_date = mes_referencia` mas o índice
     * canonical é sobre `mes_referencia` (Plan 74-01 D-03).
     *
     * @param  string  $faixaAtual  slug da faixa classificada por `classificarFaixa`
     * @param  ?float  $nota        nota corrente (opcional; permite regra suplementar)
     * @return array{faixa: string, promovida: bool}
     */
    private function promoverPor2MesesConsecutivos(
        User $user,
        Carbon $mes,
        string $faixaAtual,
        ?float $nota = null,
    ): array {
        if ($faixaAtual !== 'intermediario') {
            return ['faixa' => $faixaAtual, 'promovida' => false];
        }

        // Regra suplementar: nota corrente já >= 5.00 sobe direto para máximo.
        if ($nota !== null && $nota >= 5.00) {
            return ['faixa' => 'maximo', 'promovida' => true];
        }

        $mesAnterior = $mes->copy()->subMonth()->startOfMonth();

        $prev = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', $mesAnterior->toDateString())
            ->first();

        if ($prev?->classificacao === 'intermediario') {
            return ['faixa' => 'maximo', 'promovida' => true];
        }

        return ['faixa' => 'intermediario', 'promovida' => false];
    }

    /**
     * Formata o mês em pt-BR: "julho/2026".
     *
     * Usa `translatedFormat` com locale `pt_BR` — depende do
     * `carbon/laravel-lang` já configurado no projeto (padrão do painel).
     */
    private function mesExtenso(Carbon $mes): string
    {
        return $mes->copy()->locale('pt_BR')->translatedFormat('F/Y');
    }

    /**
     * Shape padronizado quando o user NÃO tem carteira no mês (DESEMP-10).
     */
    private function shapeSemCarteira(User $user, Carbon $mes, string $motivo): array
    {
        return [
            'user_id'               => $user->id,
            'user_name'             => $user->name,
            'mes_referencia'        => $mes->toDateString(),
            'sem_carteira'          => true,
            'motivo'                => $motivo,
            'empresas_carteira'     => 0,
            'empresas_com_baseline' => 0,
            'componentes' => [
                'nps_medio'           => null,
                'var_faturamento_pct' => null,
                'var_margem_pct'      => null,
                'absenteismo_pct'     => null,
            ],
            'nota_final'      => null,
            'faixa_bonus'     => null,
            'faixa_promovida' => false,
        ];
    }
}
