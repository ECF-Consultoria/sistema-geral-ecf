<?php

namespace App\Services;

use App\Models\AdmanMetric;
use App\Models\BonusFaixa;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\NpsResponse;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\User;
use App\Services\Metrics\MetricsProviderFactory;
use App\Services\Nps\NpsScoreCalculator;
use App\Services\Portfolio\CarteiraContextService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
 * Fase 91 (v17.0 · DESEMP-01/04/05/06/07) — `computeUniverso` passou a derivar
 * o universo de empresas dos VÍNCULOS DE SERVIÇO ativos do profissional
 * (`CarteiraContextService::forUser()`), não mais de `$user->companies()`
 * (carteira consolidada por `company_id`). Corrige o bug medido em prod onde
 * um responsável só-Shopee "herdava" faturamento/margem ML de empresas que
 * não gerencia. Os componentes financeiros (`computeVarFaturamento`/
 * `computeVarMargem`) passam a receber só as empresas com pelo menos 1
 * vínculo `financial_metrics_eligible=true`. O shape de `compute()` ganha
 * `score_status` (`official`/`partial`/`blocked`) + 6 metadados de
 * auditoria. `computeNpsMedio` permanece INTOCADO — NPS soma todas as áreas
 * (performance E Shopee), independente de elegibilidade financeira.
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
        private CarteiraContextService $carteiraContext,
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
     *   'empresas_carteira'     => int,     // compat: recebe o valor de empresas_unicas (Fase 91)
     *   'empresas_com_baseline' => int,     // usadas em var_faturamento
     *   'componentes' => [
     *     'nps_medio'           => ?float,  // 0.0 quando user não recebeu notas no mês
     *     'var_faturamento_pct' => ?float,  // null quando nenhuma empresa qualifica
     *     'var_margem_pct'      => ?float,
     *     'absenteismo_pct'     => null,    // sempre null nesta phase (DESEMP-06)
     *   ],
     *   'nota_final'            => ?float,  // 2 decimais; null se todos componentes null
     *                                       // OU quando score_status='blocked' (D-91-01)
     *   'faixa_bonus'           => ?string, // slug de BonusFaixa
     *   'faixa_promovida'       => bool,    // true se DESEMP-08 alterou a faixa
     *   // ── Metadados de elegibilidade (Fase 91 · DESEMP-05) ─────────────────
     *   'empresas_unicas'               => int,    // de CarteiraContextService::contadores()
     *   'vinculos_servico'              => int,
     *   'vinculos_financeiros'          => int,
     *   'vinculos_sem_fonte_financeira' => int,
     *   'score_status'                  => string, // 'official'|'partial'|'blocked'
     *   'componentes_disponiveis' => [
     *     'nps_medio'           => bool, // sempre true (DESEMP-03 força 0.0)
     *     'var_faturamento_pct' => bool, // true se != null
     *     'var_margem_pct'      => bool, // true se != null
     *   ],
     * ]
     * ```
     *
     * Fluxo:
     *  1. `computeUniverso` — deriva o universo dos vínculos de serviço
     *     (`CarteiraContextService::forUser`); se `sem_carteira=true`, retorna
     *     shape com nulls (DESEMP-07: só quando ZERO vínculos ativos).
     *  2. Calcula 4 componentes (NPS/faturamento/margem/absenteísmo) — os
     *     financeiros usam só as empresas `financial_metrics_eligible=true`.
     *  3. `computeNotaFinal` — média direta em escalas naturais.
     *  4. `computeScoreStatus` — classifica `official`/`partial`/`blocked`
     *     (D-91-02); `blocked` força `nota_final=null` (D-91-01).
     *  5. `classificarFaixa` + `promoverPor2MesesConsecutivos` — DESEMP-08.
     *  6. Monta shape final com os metadados de auditoria.
     */
    /**
     * Versão cacheada de `compute()` — mesma resposta, envolvida em cache
     * Redis com TTL adaptativo (mês fechado = 7 dias / mês em curso = 10 min).
     *
     * Ajuste 2026-07-10 (audit performance-lentidao): antes o `/dashboard`,
     * `/dashboard/mercadolivre` (admin) e `/performance` chamavam
     * `compute()` sequencialmente pros 11 analistas/estrategistas a cada
     * request, e cada compute() fazia até 4 HTTP calls síncronas por
     * empresa à API do Mercado Livre. Cold cache = 70s pra carregar uma
     * página. Com este cache, requests subsequentes lêem direto do Redis
     * (~1s pra o dashboard inteiro).
     *
     * Cache é INVALIDADO naturalmente pelo TTL curto (mês em curso) ou
     * pelo passar do tempo (mês fechado). Não precisa invalidar
     * explicitamente — dado se atualiza a cada 10min pro mês em curso.
     *
     * Não use dentro de jobs/commands de snapshot ou consolidação —
     * chame `compute()` direto pra garantir dado fresco.
     */
    public function computeCached(User $user, Carbon $mesReferencia): array
    {
        $mes         = $mesReferencia->copy()->startOfMonth();
        $mesCorrente = Carbon::now()->startOfMonth();
        // Bump v1→v2 em 2026-07-13: correção da dimensão do NPS por cargo
        // invalida os valores cacheados (estrategistas tinham a nota errada).
        // Bump v2→v3 em 2026-07-15 (Fase 80 · DEC-80-C): `computeNpsMedio` passou
        // a somar as atribuições congeladas (`nps_score_assignments`) da Fase 79.
        // Os valores gravados sob a v2 têm a nota ANTIGA — calculada só pelo
        // cruzamento read-time do modelo principal, portanto SEM a nota do NPS
        // Shopee. Sem este bump o `/performance`, os widgets e o PortfolioController
        // continuariam servindo o bônus errado do Redis por até 7 dias (mês fechado)
        // mesmo com o código novo em prod. As chaves v2 viram órfãs e expiram
        // sozinhas por TTL — não precisa (nem deve) rodar `cache:clear`.
        // Bump v3→v4 em 2026-07-16 (Fase 91 · DESEMP-01): `computeUniverso` passou
        // a derivar o universo dos vínculos de serviço (`CarteiraContextService`)
        // em vez da carteira consolidada por `company_id` — os valores gravados
        // sob a v3 têm a nota da carteira consolidada (responsável Shopee
        // "herdando" faturamento/margem de empresas ML que não gerencia). Sem
        // este bump o Redis continuaria servindo o bônus errado por até 7 dias
        // (mês fechado) mesmo com o código novo em prod. As chaves v3 viram
        // órfãs e expiram sozinhas por TTL — não precisa (nem deve) rodar
        // `cache:clear`.
        $cacheKey    = sprintf('desempenho.compute.v4.%d.%s', $user->id, $mes->format('Y-m'));

        // Mês fechado (passado): dado estável, cache longo — invalida só quando
        // rodar o snapshot mensal ou passar do TTL.
        // Mês em curso: dado evolui hora-a-hora conforme vendas entram na Adman/ML,
        // 10min garante frescor razoável sem estourar as HTTP calls remotas.
        $ttl = $mes->lt($mesCorrente)
            ? now()->addDays(7)
            : now()->addMinutes(10);

        return Cache::remember(
            $cacheKey,
            $ttl,
            fn () => $this->compute($user, $mesReferencia),
        );
    }

    public function compute(User $user, Carbon $mesReferencia): array
    {
        $mes = $mesReferencia->copy()->startOfMonth();

        // ── Universo (carteira ativa no mês) ─────────────────────────────────
        $universo = $this->computeUniverso($user, $mes);

        if ($universo['sem_carteira']) {
            return $this->shapeSemCarteira($user, $mes, $universo['motivo']);
        }

        /** @var EloquentCollection<int, \App\Models\Company> $companies */
        $companies = $universo['companies_elegiveis'];
        $contadores = $universo['contadores'];

        // ── 4 componentes independentes ──────────────────────────────────────
        // Faturamento/margem usam SÓ as empresas elegíveis financeiramente
        // (`financial_metrics_eligible=true` — DESEMP-04). NPS continua
        // somando todas as áreas do profissional, sem filtro de elegibilidade
        // financeira (DESEMP-03/D-91-02 — computeNpsMedio INTOCADO).
        $nps        = $this->computeNpsMedio($user, $mes);
        $varFatData = $this->computeVarFaturamento($user, $mes, $companies);
        $varMargem  = $this->computeVarMargem($user, $mes, $companies);
        $absent     = $this->computeAbsenteismo($user, $mes);

        $varFat            = $varFatData['pct'];
        $empresasBaseline  = $varFatData['empresas_com_baseline'];

        // ── Nota final (média direta, sem absenteísmo) ───────────────────────
        $nota = $this->computeNotaFinal($nps, $varFat, $varMargem);

        // ── Status de elegibilidade (Fase 91 · DESEMP-06/D-91-02) ────────────
        $scoreStatus = $this->computeScoreStatus($contadores, $varFat, $varMargem);

        // D-91-01: blocked (zero vínculos financeiros elegíveis — ex.:
        // só-Shopee) força nota_final=null/faixa_bonus=null. Decisão do
        // usuário 2026-07-16 — sem nota oficial até a diretoria aprovar régua
        // de bônus para carteira sem financeiro. Com nota null, a ordenação
        // existente (`sortByDesc(nota ?? -1)`) já manda pro fim do ranking.
        if ($scoreStatus === 'blocked') {
            $nota = null;
        }

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
            // Compat DESEMP-05: empresas_carteira passa a receber o valor de
            // empresas_unicas (Fase 91) — mesmo valor prático pra profissional
            // só-performance; consumidores existentes (Fase 92) não recomputam.
            'empresas_carteira'     => $contadores['empresas_unicas'],
            'empresas_com_baseline' => $empresasBaseline,
            'componentes' => [
                'nps_medio'           => $nps,
                'var_faturamento_pct' => $varFat,
                'var_margem_pct'      => $varMargem,
                'absenteismo_pct'     => $absent,
            ],
            // Ajuste 2026-07-10 · pontos 1-5 por componente (após régua), pra
            // UI expor a conta que gerou a nota (ex: "(3+5+4)/3 = 4,00") em
            // vez do denominador fixo "/5,00". Nulls preservados — só entram
            // na média os componentes disponíveis.
            'pontos_componentes' => [
                'nps'         => $nps !== null ? max(1.0, min(5.0, $nps)) : null,
                'faturamento' => $this->reguaFaturamento($varFat),
                'margem'      => $this->reguaMargem($varMargem),
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
            // ── Metadados de elegibilidade (Fase 91 · DESEMP-05) ──────────────
            'empresas_unicas'               => $contadores['empresas_unicas'],
            'vinculos_servico'              => $contadores['vinculos_servico'],
            'vinculos_financeiros'          => $contadores['vinculos_financeiros'],
            'vinculos_sem_fonte_financeira' => $contadores['vinculos_sem_fonte_financeira'],
            'score_status'                  => $scoreStatus,
            'componentes_disponiveis' => [
                'nps_medio'           => true, // DESEMP-03 força 0.0 — nunca indisponível.
                'var_faturamento_pct' => $varFat !== null,
                'var_margem_pct'      => $varMargem !== null,
            ],
        ];
    }

    // ═══ Métodos privados por componente ═══════════════════════════════════

    /**
     * Verifica se o user tem carteira ativa no mês. Retorna:
     *  - `['sem_carteira' => true, 'motivo' => '...']` quando vazia
     *  - `['sem_carteira' => false, 'contadores' => array, 'companies_elegiveis' => EloquentCollection]`
     *    caso contrário
     *
     * Fase 91 (DESEMP-01/04/07): o universo deriva dos VÍNCULOS DE SERVIÇO
     * ativos do profissional via `CarteiraContextService::forUser()`, não
     * mais de `$user->companies()` (carteira consolidada por `company_id`).
     * `sem_carteira=true` só dispara com ZERO vínculos de QUALQUER setor —
     * um profissional só-Shopee TEM vínculo (Shopee), então permanece com
     * `sem_carteira=false` mesmo sem nenhum vínculo elegível financeiramente
     * (esse caso vira `score_status='blocked'` em `compute()`, não `sem_carteira`).
     *
     * `companies_elegiveis` é a `EloquentCollection<Company>` deduplicada por
     * `company_id` (Pitfall 4 do 91-RESEARCH.md — evita somar a MESMA empresa
     * 2× no SUM financeiro caso ela tenha 2 vínculos elegíveis) contendo só as
     * empresas com pelo menos 1 vínculo `financial_metrics_eligible=true` —
     * é o que alimenta `computeVarFaturamento`/`computeVarMargem` (assinaturas
     * INTOCADAS, só o conjunto de entrada muda).
     *
     * DESEMP-10: "Sem carteira em julho/2026" (motivo pt-BR).
     */
    private function computeUniverso(User $user, Carbon $mes): array
    {
        $vinculos = $this->carteiraContext->forUser($user, ['active' => true]);

        if ($vinculos->isEmpty()) {
            return [
                'sem_carteira' => true,
                'motivo'       => "Sem carteira em {$this->mesExtenso($mes)}",
            ];
        }

        $contadores = $this->carteiraContext->contadores($vinculos);

        $companyIdsElegiveis = $vinculos
            ->where('financial_metrics_eligible', true)
            ->pluck('company_id')
            ->unique();

        $companiesElegiveis = Company::whereIn('id', $companyIdsElegiveis)->get();

        return [
            'sem_carteira'        => false,
            'contadores'          => $contadores,
            'companies_elegiveis' => $companiesElegiveis,
        ];
    }

    /**
     * Classifica o `score_status` do profissional no mês (Fase 91 · DESEMP-06,
     * semântica D-91-02 — resolvida pelo orquestrador a partir das decisões
     * do usuário, ver `91-CONTEXT.md`):
     *
     *  - `blocked`  — ZERO vínculos financeiros elegíveis (ex.: só-Shopee).
     *  - `partial`  — tem vínculo financeiro elegível, mas algum componente
     *    financeiro está indisponível no período (sem baseline no mês).
     *  - `official` — todos os componentes disponíveis. Profissional MISTO
     *    (Performance+Shopee) é OFFICIAL — o financeiro vem só do subconjunto
     *    elegível dele; isto CORRIGE a proposta original do 91-RESEARCH.md,
     *    que sugeria `partial` para misto.
     */
    private function computeScoreStatus(array $contadores, ?float $varFat, ?float $varMargem): string
    {
        if ($contadores['vinculos_financeiros'] === 0) {
            return 'blocked';
        }

        if ($varFat === null || $varMargem === null) {
            return 'partial';
        }

        return 'official';
    }

    /**
     * NPS médio do user no mês — UNIÃO DISJUNTA de dois caminhos POR RESPOSTA
     * (Phase 80 v16.0 · DEC-80-A / DEC-80-B0 / DEC-80-B1 / DEC-80-B / DEC-80-D).
     *
     * ┌─ (A) ATRIBUIÇÕES (Fase 79) ── `nps_score_assignments`, a lista congelada
     * │      de quem era responsável por qual serviço no dia da resposta. Soma
     * │      TODAS as áreas da pessoa (performance/ML **e** shopee) — Ajuste 3 do
     * │      usuário (DEC-80-A). Deduped 1× por (resposta, papel).
     * └─ (B) LEGADO ── o cruzamento read-time histórico (carteira × dimensão do
     *        cargo × modelo principal), preservado INTACTO para as respostas que
     *        o snapshot da Fase 79 não cobriu.
     *
     * As duas metades são DISJUNTAS: nenhuma resposta contribui pelos dois ramos.
     * A média é `$notas->avg()` — uma resposta contada 2× infla o bônus em
     * silêncio (sem exception, sem log). Ver `notasLegado()` para o predicado.
     *
     * ⚠ O ramo legado é FALLBACK PERMANENTE, **não** ponte temporária: empresas
     * sem contrato performance ativo ficaram com `company_users.servico_id = NULL`
     * no backfill (a migration nunca inventa serviço) → `consultorDoServico()`
     * volta vazio → `[NPS Snapshot] responsável faltante` → a resposta NUNCA gera
     * atribuição e SEMPRE cai aqui. Não remover nem afrouxar sem antes reconciliar
     * essas pendências no dado.
     *
     * Regras preservadas (DESEMP-03): média aritmética das notas coletadas; sem
     * respostas → `0.0` (PENALIZA por decisão da diretoria — nunca `null`).
     *
     * Assinatura `(User, Carbon): float` é contrato de fato — testes a invocam
     * por reflection com `assertSame`, que recusa `int`.
     *
     * @return float sempre >= 0.0
     */
    private function computeNpsMedio(User $user, Carbon $mes): float
    {
        $inicio = $mes->copy()->startOfMonth();
        $fim    = $mes->copy()->endOfMonth();

        $notas = collect();

        // ── (A) Atribuições congeladas da Fase 79 — todas as áreas ───────────
        $notas = $notas->merge(
            $this->notasPorAtribuicao($user, $inicio, $fim)->pluck('average_score')
        );

        // ── (B) Caminho legado — só as respostas que o snapshot não cobriu ───
        $notas = $notas->merge($this->notasLegado($user, $inicio, $fim));

        if ($notas->isEmpty()) {
            // DESEMP-03 · Sem respostas no mês FORÇA nps = 0 (penaliza) por
            // decisão da diretoria. Não retornar null aqui.
            return 0.0;
        }

        return round($notas->avg(), 2);
    }

    /**
     * (A) Notas ATRIBUÍDAS ao user no mês — 1× por (`nps_response_id`, `role`).
     *
     * O mês vem de `nps_surveys.completed_at` via JOIN — a MESMA coluna que o
     * ramo legado usa (DEC-80-B0). `nps_score_assignments` **não tem coluna de
     * mês**, e as duas alternativas óbvias são armadilhas:
     *  - `assigned_at` é a data da GRAVAÇÃO. Hoje equivale ao `completed_at`
     *    (mesma transação do submit), mas qualquer backfill futuro gravaria a
     *    data do backfill → a resposta de junho migraria para o mês do backfill,
     *    sumindo do bônus de junho e zerando o mês de um analista real — sem
     *    nenhum erro no log.
     *  - `month_reference` é o mês do DISPARO, não o da resposta (um NPS de junho
     *    respondido em julho tem os dois divergentes), e é NULL em muitas linhas
     *    — inclusive no fixture Carlos, de propósito.
     * Fonte única ⇒ os dois ramos concordam por construção sobre o mês da resposta.
     *
     * Dedup (DEC-80-A): a mesma pessoa responsável por 2 serviços cobertos da
     * MESMA resposta não pode pesar 2× na média. As N linhas de um mesmo par
     * (resposta, papel) vêm do MESMO `nps_response_score` — o `NpsSnapshotService`
     * itera serviços DENTRO da dimensão — logo têm `average_score` idêntico:
     * `MAX()` é determinístico e satisfaz o `ONLY_FULL_GROUP_BY` do MariaDB.
     *
     * NÃO filtrar por `service_setor` (zeraria o Ajuste 3 — o ML da pessoa sumiria
     * da média dela) nem pela carteira viva `company_users` (desfaria o
     * congelamento da Fase 79: trocar o responsável hoje reescreveria o bônus de
     * ontem). O índice `nps_score_assign_user_role_idx (user_id, role)` cobre o WHERE.
     *
     * @return Collection<int, object{response_id:int, role:string, average_score:float}>
     */
    private function notasPorAtribuicao(User $user, Carbon $inicio, Carbon $fim): Collection
    {
        return NpsScoreAssignment::query()
            ->join('nps_responses as r', 'r.id', '=', 'nps_score_assignments.nps_response_id')
            ->join('nps_surveys as s', 's.id', '=', 'r.survey_id')
            ->where('nps_score_assignments.user_id', $user->id)
            ->where('s.status', 'completed')
            ->whereBetween('s.completed_at', [$inicio, $fim])
            // Phase 96 Plan 04 (AB-96-3 · call-site #1) — resposta invalidada
            // pelo admin some do bônus. Ver NpsResponse::scopeValida().
            ->whereNull('r.invalidated_at')
            ->groupBy('nps_score_assignments.nps_response_id', 'nps_score_assignments.role')
            // selectRaw só com nomes de coluna literais — zero interpolação de
            // variável; todos os valores entram por bind (where/whereBetween).
            ->selectRaw(
                'nps_score_assignments.nps_response_id as response_id,'
                .' nps_score_assignments.role as role,'
                .' MAX(nps_score_assignments.average_score) as average_score'
            )
            ->get()
            ->map(fn ($row) => (object) [
                'response_id'   => (int) $row->response_id,
                'role'          => (string) $row->role,
                'average_score' => (float) $row->average_score,
            ]);
    }

    /**
     * (B) Notas do caminho LEGADO — cruzamento read-time histórico, preservado.
     *
     * Cópia fiel do `computeNpsMedio` pré-Fase 80 (dimensão por cargo, carteira
     * ativa, `->principal()`, `NpsScoreCalculator::compute` e o fallback das
     * colunas legacy `score_*`), com uma única adição: o skip que garante a
     * disjunção da união.
     *
     * ⚠⚠ O `->principal()` PERMANECE AQUI — NÃO "limpar" este scope. ⚠⚠
     * O DEC-80-D ("aposentar 'só o principal conta'") vale APENAS para o ramo das
     * atribuições, que já é model-agnostic por construção. Aqui o `->principal()`
     * É o isolamento: sem ele, a resposta de um NPS Shopee entra no escopo legado
     * do analista de ML da MESMA empresa (é da carteira dele, é `completed`, é do
     * mês), ele não tem atribuição nessa resposta, cai no fallback e **recebe uma
     * nota de um trabalho que não é dele**. Isso é exatamente a super-atribuição
     * que o congelamento da Fase 79 existe para impedir. Coberto pelo teste
     * `BonusAtribuicoesNpsTest::test_analista_shopee_nao_recebe_nota_ml_da_mesma_empresa`.
     *
     * PREDICADO DO SKIP (DEC-80-B1 — refinamento do DEC-80-B, confirmado pelo
     * usuário em 2026-07-14): pular a resposta quando ela já tem atribuição **no
     * papel correspondente à dimensão do cargo deste user** — independentemente de
     * quem foi o atribuído. Racional: se o snapshot da Fase 79 nomeou os
     * responsáveis daquele papel naquela resposta, a lista dele é COMPLETA e
     * AUTORITATIVA — quem não está nela não é responsável e não recebe nada dessa
     * resposta.
     *
     * Por que NÃO o predicado literal "(este user, esta resposta)": `User::companies()`
     * não filtra por `servico_id`, então a empresa onde a pessoa é analista APENAS
     * de Shopee está na carteira dela; o NPS Padrão (principal) dessa empresa entra
     * na query legada dela; ela não tem atribuição nessa resposta → cairia no legado
     * e receberia a nota de ML da empresa. Seria a super-atribuição no sentido
     * inverso. O predicado por papel subsume o literal (se o user tem atribuição na
     * resposta, existe atribuição naquele papel → skip) e preserva 100% do histórico
     * (resposta com zero atribuições → zero skip → legado normal).
     *
     * O guard de carteira vazia vive AQUI (e não em `computeNpsMedio`): um user com
     * atribuições e carteira vazia deve receber a média das atribuições, não 0.0.
     *
     * @return Collection<int, float>
     */
    private function notasLegado(User $user, Carbon $inicio, Carbon $fim): Collection
    {
        // 2026-07-13 — dimensão POR CARGO (estrategista/analista), fonte
        // canônica user_setores→cargos. Antes usava isMentor() (role do
        // sistema), o que fazia estrategistas caírem na dimensão 'analista' —
        // estrategista e analista da mesma empresa recebiam a MESMA nota NPS.
        $dim = $user->dimensaoNpsDesempenho();

        $companyIds = $user->companies()
            ->where('active', true)
            ->pluck('companies.id');

        if ($companyIds->isEmpty()) {
            return collect();
        }

        // 2026-07-13 — só o modelo PRINCIPAL conta neste ramo (ver docblock:
        // é o isolamento por serviço, não uma regra de conveniência).
        // scopePrincipal filtra template_id = principal (força vazio se nenhum
        // principal estiver marcado).
        // Phase 96 Plan 04 (AB-96-3 · call-site #2) — eager-load só carrega a
        // response quando ela NÃO está invalidada; o foreach abaixo já faz
        // `if ($response === null) continue;`, então passa a pular sozinho.
        $surveys = NpsSurvey::with(['response' => fn ($q) => $q->valida()])
            ->principal()
            ->whereIn('company_id', $companyIds)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$inicio, $fim])
            ->get();

        // Set de skip da união disjunta — derivado DOS SURVEYS JÁ CARREGADOS
        // (nunca de uma 2ª query com filtro de mês próprio: dois filtros
        // divergem e a resposta escapa, sendo contada pelos dois ramos).
        // Mapa dimensão → papel: espelha NpsSnapshotService::DIMENSAO_ROLE.
        $papelDaDimensao = $dim === 'estrategista' ? 'estrategista' : 'consultor';
        $responseIds     = $surveys->pluck('response.id')->filter()->values();

        // ->flip() + ->has() = lookup O(1) por hash (nunca ->contains() no loop).
        $cobertasNoPapel = $responseIds->isEmpty()
            ? collect()
            : NpsScoreAssignment::whereIn('nps_response_id', $responseIds)
                ->where('role', $papelDaDimensao)
                ->pluck('nps_response_id')
                ->flip();

        $notas = collect();

        foreach ($surveys as $survey) {
            /** @var NpsResponse|null $response */
            $response = $survey->response;
            if ($response === null) {
                continue;
            }

            // ÚNICA adição ao ramo legado — garante a disjunção da união.
            if ($cobertasNoPapel->has($response->id)) {
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

        return $notas;
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

        // Ajuste 2026-07-13 (audit LOJASINVAL + AVF2K): recorte por DIAS
        // COMUNS. Substitui o fix Tomelin (2026-07-10, só gap-no-fim) por
        // versão que cobre gap em qualquer posição em qualquer uma das
        // duas janelas — inclusive empresas cadastradas em meio de mês
        // (LOJASINVAL: 16/06 → dias 01-15/06 nunca tiveram sync) e
        // incidentes de sync no meio da janela (AVF2K: 12-13/06 outage
        // Adman rate-limit).
        //
        // Estratégia: pra cada empresa, buscar os DAYs com margem em cada
        // janela; usar SÓ o subconjunto que existe em ambas. Se junho tem
        // dado só em 06-12 e 06-13 e julho tem 01-12, compara apenas o
        // dia 12 vs dia 12.
        // Helper cross-DB (SQLite não tem DAY()): agrega em PHP após pluck.
        // Carbon lida com string ISO OU objeto Carbon (cast 'date' do model).
        $extrairDiasDoMes = function ($rows) {
            return $rows->pluck('reference_date')
                ->map(fn ($d) => Carbon::parse($d)->day)
                ->unique()
                ->values()
                ->all();
        };

        $diasComMargemAtualPorEmpresa    = collect();
        $diasComMargemAnteriorPorEmpresa = collect();

        if (! $companyIds->isEmpty()) {
            $diasComMargemAtualPorEmpresa = AdmanMetric::whereIn('company_id', $companyIds)
                ->whereDate('reference_date', '>=', $inicioMes->toDateString())
                ->whereDate('reference_date', '<=', $fimMes->toDateString())
                ->whereNotNull('contribution_margin')
                ->get(['company_id', 'reference_date'])
                ->groupBy('company_id')
                ->map($extrairDiasDoMes);

            $diasComMargemAnteriorPorEmpresa = AdmanMetric::whereIn('company_id', $companyIds)
                ->whereDate('reference_date', '>=', $inicioAnter->toDateString())
                ->whereDate('reference_date', '<=', $fimAnter->toDateString())
                ->whereNotNull('contribution_margin')
                ->get(['company_id', 'reference_date'])
                ->groupBy('company_id')
                ->map($extrairDiasDoMes);
        }

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

            // Recorte por DIAS COMUNS (2026-07-13): se as duas janelas têm
            // conjuntos de dias diferentes (por gap de sync ou empresa nova),
            // reagrega SÓ pros dias presentes em AMBAS. Se não sobra nenhum
            // dia coincidente, descarta empresa do cálculo.
            $diasAtual  = $diasComMargemAtualPorEmpresa->get($company->id, []);
            $diasAnter  = $diasComMargemAnteriorPorEmpresa->get($company->id, []);
            $diasComuns = array_values(array_intersect($diasAtual, $diasAnter));

            if (empty($diasComuns)) {
                continue; // sem dia coincidente → não é comparável
            }

            if (count($diasComuns) !== count($diasAtual) || count($diasComuns) !== count($diasAnter)) {
                // Converte offset (dia do mês) em datas concretas — cross-DB.
                $datasAtual = array_map(
                    fn ($d) => $inicioMes->copy()->setDay($d)->toDateString(),
                    $diasComuns,
                );
                $datasAnter = array_map(
                    fn ($d) => $inicioAnter->copy()->setDay($d)->toDateString(),
                    $diasComuns,
                );

                // `whereIn(reference_date, [...])` não bate pois o cast 'date' do
                // model serializa com timestamp ('YYYY-MM-DD 00:00:00'). whereDate
                // aplica DATE() de ambos os lados e funciona em MySQL/SQLite.
                $atual = (float) AdmanMetric::where('company_id', $company->id)
                    ->where(function ($q) use ($datasAtual) {
                        foreach ($datasAtual as $d) {
                            $q->orWhereDate('reference_date', $d);
                        }
                    })
                    ->whereNotNull('contribution_margin')
                    ->sum('contribution_margin');

                $anterior = (float) AdmanMetric::where('company_id', $company->id)
                    ->where(function ($q) use ($datasAnter) {
                        foreach ($datasAnter as $d) {
                            $q->orWhereDate('reference_date', $d);
                        }
                    })
                    ->whereNotNull('contribution_margin')
                    ->sum('contribution_margin');
            }

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
     * Shape padronizado quando o user NÃO tem carteira no mês (DESEMP-10) —
     * ZERO vínculos de qualquer setor (Fase 91 · DESEMP-07).
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
            'pontos_componentes' => [
                'nps'         => null,
                'faturamento' => null,
                'margem'      => null,
            ],
            'nota_final'      => null,
            'faixa_bonus'     => null,
            'faixa_promovida' => false,
            // ── Metadados de elegibilidade (Fase 91 · DESEMP-05) — zerados ────
            'empresas_unicas'               => 0,
            'vinculos_servico'              => 0,
            'vinculos_financeiros'          => 0,
            'vinculos_sem_fonte_financeira' => 0,
            // Coerente com a fórmula de computeScoreStatus: zero vínculos
            // financeiros → blocked.
            'score_status'                  => 'blocked',
            'componentes_disponiveis' => [
                'nps_medio'           => false,
                'var_faturamento_pct' => false,
                'var_margem_pct'      => false,
            ],
        ];
    }
}
