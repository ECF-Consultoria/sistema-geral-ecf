# Phase 120: Agregação do profissional + feature flag - Pattern Map

**Mapeado:** 2026-07-29
**Arquivos analisados:** 9 (6 modificados + 1 criado/suíte nova + 2 análogos de teste de equivalência)
**Analogs encontrados:** 9 / 9

> Nota de escopo: esta fase **modifica** arquivos já existentes (não cria componentes novos de produção). Por isso os "analogs" abaixo, na maioria dos casos, **são os próprios arquivos-alvo** — o padrão a copiar é o padrão JÁ vigente naquele arquivo (bump anterior de cache, flag anterior, chamada anterior), que a task precisa replicar com a MESMA disciplina.

## File Classification

| Arquivo (modificado/criado) | Papel | Data Flow | Analog mais próximo | Match Quality |
|---|---|---|---|---|
| `app/Services/DesempenhoScoreService.php` (bifurcação `compute()`, shadow, bump cacheKey) | service (motor de bônus) | CRUD/transform (cálculo puro) | Ele mesmo — `cacheKey()` v7→v12 (linhas 308-334) e `Padrão 1` do RESEARCH | exact (auto-análogo) |
| `config/metrics.php` (flag nova) | config | request-response (leitura via `config()`) | `unified_metrics_enabled` no mesmo arquivo (linhas 1-51) | exact |
| `app/Console/Commands/WarmDesempenhoCache.php` (shadow + guard do `Cache::remember`) | command (background, best-effort) | batch | Ele mesmo — chamada atual em `:122` | exact (auto-análogo) |
| `app/Console/Commands/ConsolidarMesDesempenho.php` (shadow) | command (persistência canônica) | batch | Ele mesmo — chamada atual em `:139` | exact (auto-análogo) |
| 4 suítes com `desempenho.compute.v12` hardcoded | test (regressão) | — | `DesempenhoShopeeScoreTest::test_cache_key_bumpado_para_v12` (linhas 354-364) — é o próprio bump anterior (v11→v12) documentado no PR da Fase 116 | exact |
| `tests/Feature/DesempenhoShopeeScoreTest.php` (cenários espelho D-05) | test (feature, regressão + novo cenário) | — | Os 7 testes já existentes no próprio arquivo (linhas 38-365) — molde direto para os 4 espelhos | exact |
| `tests/Feature/Phase120/*` (suíte nova AGRE-01..06) | test (feature) | — | `tests/Feature/Phase119/CompanyScoreServiceReguasTest.php` (teste de equivalência por Reflection) + `tests/Feature/Phase118/NpsJanelaResolverTest.php` (mesmo padrão, mais completo) | exact |
| Teste de "flag off ≡ hoje" (byte-equivalência) | test (equivalência) | — | `Phase118/NpsJanelaResolverTest::test_resolver_concorda_com_computeNpsWindow_nos_tres_casos` (linhas 211-236) | exact |

## Pattern Assignments

### `app/Services/DesempenhoScoreService.php` (service, bifurcação por flag)

**Analog:** o próprio arquivo — histórico de bumps de `cacheKey()` e o ponto de bifurcação já mapeado linha a linha.

**Imports atuais (linhas 1-23)** — `CompanyScoreService` AINDA NÃO está importado; precisa ser adicionado ao bloco de `use` (ordem alfabética dentro do grupo `App\Services\*`, mesma convenção do arquivo):
```php
namespace App\Services;

use App\Models\AdmanMetric;
use App\Models\BonusFaixa;
use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\NpsResponse;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\User;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Metrics\MetricsProviderFactory;
use App\Services\Nps\NpsImputationService;
use App\Services\Nps\NpsScoreCalculator;
use App\Services\Portfolio\CarteiraContextService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
```
→ Adicionar `use App\Services\Desempenho\CompanyScoreService;` entre `use App\Services\Metrics\MetricsProviderFactory;` e `use App\Services\Nps\NpsImputationService;` (ordem alfabética do namespace).

**Injeção via construtor (linhas 118-126)** — padrão de promoted properties já usado para todas as 6 dependências atuais:
```php
public function __construct(
    private MetricsProviderFactory $metricsFactory,
    private NpsScoreCalculator $npsCalculator,
    private CarteiraContextService $carteiraContext,
    private MetricPeriodResolver $periodResolver,
    private MetricDiffDispatcher $diffDispatcher,
    private NpsImputationService $imputationService,
) {
}
```
→ Adicionar `private CompanyScoreService $companyScoreService,` como 7º parâmetro (Laravel resolve via container automaticamente — nenhum call-site de `new DesempenhoScoreService(...)` existe fora do container, todos os ~40 call-sites resolvem via `app(DesempenhoScoreService::class)`/injeção).

**Ponto de bifurcação — `compute()` linhas 394-465 (trecho exato lido do arquivo atual):**
```php
public function compute(User $user, Carbon $mesReferencia, ?array $periodoOverride = null): array
{
    $mes     = $mesReferencia->copy()->startOfMonth();
    $periodo = $periodoOverride ?? $this->resolvePeriodo($mes);

    // ── Universo (carteira ativa no mês) ─────────────────────────────────
    $universo = $this->computeUniverso($user, $mes);

    if ($universo['sem_carteira']) {
        return $this->shapeSemCarteira($user, $mes, $universo['motivo']);
    }

    /** @var EloquentCollection<int, \App\Models\Company> $companies */
    $companies  = $universo['companies_elegiveis'];
    $contadores = $universo['contadores'];
    $fontes     = $universo['fontes'];

    // ── Invalidação por competência (item 3/4 · 2026-07-21) ──────────────
    $invalidadas = BonusInvalidacao::companyIdsInvalidadas($mes);
    if ($invalidadas->isNotEmpty()) {
        $companies = $companies
            ->reject(fn ($c) => $invalidadas->contains($c->id))
            ->values();
    }
    // ★ PONTO DE INSERÇÃO 1 (shadow) — linha ~421, logo aqui.
    //   $invalidadas, $periodo, $mes já prontos — exatamente os insumos
    //   que CompanyScoreService::computeEmpresasScore() exige.

    // ── 4 componentes independentes ──────────────────────────────────────
    $nps           = $this->computeNpsWindow($user, $mes, $periodo['is_closed'], $invalidadas);
    $varFatData    = $this->computeVarFaturamento($user, $mes, $companies, $periodo, $fontes);
    $varMargemData = $this->computeVarMargem($user, $mes, $companies, $periodo, $fontes);
    $absent        = $this->computeAbsenteismo($user, $mes);

    $varFat           = $varFatData['pct'];
    $empresasBaseline = $varFatData['empresas_com_baseline'];

    $varMargem      = $varMargemData['pct'];
    $nComMargemReal = $varMargemData['n_com_margem_real'];
    $nElegivelAdman = $varMargemData['n_elegivel'];

    $nShopeePlaceholder = $companies
        ->filter(fn ($c) => ($fontes[$c->id] ?? null) === 'shopee')
        ->count();
    $margemPontos = $this->margemPontos($varMargem, $nComMargemReal, $nShopeePlaceholder);

    // ── Nota final (média direta, sem absenteísmo) ───────────────────────
    $nota = $this->computeNotaFinal($nps, $varFat, $margemPontos);                    // linha 462

    // ── Status de elegibilidade (Fase 91 · DESEMP-06/D-91-02; Fase 109) ──
    $scoreStatus = $this->computeScoreStatus($contadores, $varFat, $margemPontos);    // linha 465
    // ★ PONTO DE INSERÇÃO 2 (bifurcação real) — as 2 linhas acima são o alvo.

    if ($scoreStatus === 'blocked') {
        $nota = null;
    }
    // ... resto do método INTOCADO (classificação de faixa, promoção, metadados) ...
```

**Menor superfície de mudança recomendada (não decisão de sintaxe final — do planner/executor):**
```php
// 1) shadow — logo após $invalidadas resolvido:
$empresasScore = null;
if ($incluirEmpresasScore || config('metrics.performance_company_first_score')) {
    $empresasScore = $this->companyScoreService->computeEmpresasScore(
        $user, $mes, $periodo, $invalidadas
    );
}

// 2) 4 componentes legados — ZERO mudança (linhas 436-459 intocadas)

// 3) bifurcação real — substitui só as linhas 462/465:
if (config('metrics.performance_company_first_score') && $empresasScore !== null) {
    [$nota, $scoreStatus] = $this->computeNotaFinalPorEmpresa($empresasScore); // NOVO método, NUNCA estende o legado
} else {
    $nota        = $this->computeNotaFinal($nps, $varFat, $margemPontos);         // INTOCADO
    $scoreStatus = $this->computeScoreStatus($contadores, $varFat, $margemPontos); // INTOCADO
}
```

**`computeScoreStatus()` legado — NUNCA estender, só ler (linhas 691-699), para não quebrar a garantia de árvore de chamada separada:**
```php
private function computeScoreStatus(array $contadores, ?float $varFat, ?float $margemPontos): string
{
    if ($contadores['vinculos_financeiros'] === 0) {
        return 'blocked';
    }

    if ($varFat === null || $margemPontos === null) {
        return 'partial';
    }
    // (return 'official' segue abaixo, fora do trecho lido)
}
```
→ Criar `computeScoreStatusPorEmpresa(Collection $empresasScore): string` como método NOVO e SEPARADO (Pitfall 3 do RESEARCH) — nunca adicionar parâmetro a este.

**`computeNotaFinal()` legado (linhas 1258-1276) — mesma regra, NUNCA tocar:**
```php
private function computeNotaFinal(?float $nps, ?float $varFat, ?float $margemPontos): ?float
{
    $npsPts = $nps !== null ? max(1.0, min(5.0, $nps)) : null;

    $fatPts    = $this->reguaFaturamento($varFat);
    $margemPts = $margemPontos;

    $componentes = collect([$npsPts, $fatPts, $margemPts])
        ->reject(fn ($v) => $v === null);

    if ($componentes->isEmpty()) {
        return null;
    }

    return round($componentes->sum() / $componentes->count(), 2);
}
```

**Array de retorno na ÍNTEGRA (linhas 494-588 — nenhuma chave pode sumir/mudar de tipo, AGRE-04):**
```php
return [
    'user_id'               => $user->id,
    'user_name'             => $user->name,
    'mes_referencia'        => $mes->toDateString(),
    'sem_carteira'          => false,
    'motivo'                => null,
    'empresas_carteira'     => $contadores['empresas_unicas'],
    'empresas_com_baseline' => $empresasBaseline,
    'margem_amostra' => [
        'n_real'     => $nComMargemReal,
        'n_elegivel' => $nElegivelAdman,
        'cobertura'  => $nElegivelAdman > 0 ? round($nComMargemReal / $nElegivelAdman, 4) : 1.0,
    ],
    'componentes' => [
        'nps_medio'           => $nps,
        'var_faturamento_pct' => $varFat,
        'var_margem_pct'      => $varMargem,
        'absenteismo_pct'     => $absent,
        // ★ NOVO (AGRE-04): 'var_margem_pp' => $empresasScore?->avg('margem_var_pp'),
    ],
    'pontos_componentes' => [
        'nps'         => $nps !== null ? max(1.0, min(5.0, $nps)) : null,
        'faturamento' => $this->reguaFaturamento($varFat),
        'margem'      => $margemPontos,
    ],
    'nota_final'      => $nota,
    'faixa_bonus'     => $faixaFinal,
    'faixa_promovida' => $faixaPromovida,
    'periodo_meta' => [
        'em_curso'        => $ehMesEmCurso,
        'dias_decorridos' => $diasDecorridos,
        'dias_no_mes'     => $diasNoMes,
    ],
    'periodo' => [
        'current_start'   => $periodo['current_start'],
        'current_end'     => $periodo['current_end'],
        'baseline_start'  => $periodo['baseline_start'],
        'baseline_end'    => $periodo['baseline_end'],
        'mode'            => $periodo['mode'],
        'comparison_mode' => $periodo['comparison_mode'],
    ],
    'bonus' => [
        'competence_month' => $ehMesEmCurso ? null : $mes->format('Y-m'),
        'payment_month'    => $ehMesEmCurso ? null : $mes->copy()->addMonthNoOverflow()->format('Y-m'),
    ],
    'empresas_unicas'               => $contadores['empresas_unicas'],
    'vinculos_servico'              => $contadores['vinculos_servico'],
    'vinculos_financeiros'          => $contadores['vinculos_financeiros'],
    'vinculos_sem_fonte_financeira' => $contadores['vinculos_sem_fonte_financeira'],
    'score_status'                  => $scoreStatus,
    'componentes_disponiveis' => [
        'nps_medio'           => $nps !== null,
        'var_faturamento_pct' => $varFat !== null,
        'var_margem_pct'      => $varMargem !== null,
    ],
    // ★ NOVO (AGRE-04): 'empresas_score' => $empresasScore?->values()->all() ?? [],
];
```
**Todas as 20 chaves de topo + 6 sub-chaves aninhadas acima devem permanecer com o MESMO shape.** Só `empresas_score` (raiz) e `componentes.var_margem_pp` são adições.

**`shapeSemCarteira()` (linhas 1448-1486) — simetria obrigatória (Pitfall 4), array COMPLETO já lido:**
```php
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
            // ★ NOVO simétrico: 'var_margem_pp' => null,
        ],
        'pontos_componentes' => [
            'nps'         => null,
            'faturamento' => null,
            'margem'      => null,
        ],
        'nota_final'      => null,
        'faixa_bonus'     => null,
        'faixa_promovida' => false,
        'empresas_unicas'               => 0,
        'vinculos_servico'              => 0,
        'vinculos_financeiros'          => 0,
        'vinculos_sem_fonte_financeira' => 0,
        'score_status'                  => 'blocked',
        'componentes_disponiveis' => [
            'nps_medio'           => false,
            'var_faturamento_pct' => false,
            'var_margem_pct'      => false,
        ],
        // ★ NOVO simétrico: 'empresas_score' => [],
    ];
}
```

**Padrão de bump de `cacheKey()` (linhas 302-336) — docblock de justificativa de CADA bump anterior, formato a seguir para v12→v13:**
```php
public function cacheKey(int $userId, Carbon $mes): string
{
    $mes         = $mes->copy()->startOfMonth();
    $mesCorrente = Carbon::now()->startOfMonth();
    $periodKey   = $mes->equalTo($mesCorrente) ? 'current_month' : $mes->format('Y-m');

    // v7 (2026-07-21): remoção do filtro created_at no faturamento (item 1)
    // + piso NPS 1.0 no mês em curso (item 2).
    // v8 (2026-07-21): trava de cobertura no fallback Adman do faturamento
    // (remove inflação do baseline parcial de maio). Cada bump força
    // recomputo — senão o Redis serve a versão anterior por até 7 dias.
    // v9 (2026-07-22): faturamento UNIFICADO com a carteira — var_faturamento
    // agora vem do AdmanMetricDiffService (revenue.diff_pct)...
    // v10 (2026-07-23, Fase 109 · SHOP-DES-01/02): vínculos Shopee entram no
    // universo/faturamento/score via MetricDiffDispatcher...
    // v11 (2026-07, Fase 110 · FIXMARG-01/02): margem de contribuição passa
    // a preferir o calculated_fallback LOCAL determinístico...
    // v12 (2026-07-27, Fase 116 · NPSFLOOR): NPS disparado e não respondido
    // passa a contar nota 1 na média do bônus (3º ramo `notasImputadas`).
    // O valor muda para quem tem surveys sem resposta na competência. Sem
    // este bump o Redis serviria o bônus antigo por até 7 dias (mês
    // fechado) mesmo com o código novo em prod. As chaves v11 viram
    // órfãs e expiram sozinhas por TTL — não precisa (nem deve) rodar
    // `cache:clear`.
    return sprintf('desempenho.compute.v12.%d.%s', $userId, $periodKey);
}
```
**Estrutura obrigatória do comentário do bump v13 (mesma disciplina, 4 elementos):** (1) o que mudou tecnicamente, (2) por que o valor cacheado antigo está ERRADO/desatualizado sob a mudança, (3) a consequência de NÃO bumpar (Redis serve dado antigo por até 7 dias em mês fechado), (4) que as chaves antigas viram órfãs e expiram por TTL — nunca `cache:clear` manual.

**`computeCached()` — docblock de bump paralelo (linhas 228-289), MESMA disciplina, precisa do parâmetro de shadow propagado:**
```php
public function computeCached(User $user, Carbon $mesReferencia, ?array $periodoOverride = null): array
{
    $mes         = $mesReferencia->copy()->startOfMonth();
    $mesCorrente = Carbon::now()->startOfMonth();
    // Bump v1→v2 ... v5→v6 (histórico completo de justificativas, cada bloco
    // de comentário aponta fase/decisão + consequência de não bumpar)
    $cacheKey = $this->cacheKey($user->id, $mes);

    $ttl = $mes->lt($mesCorrente)
        ? now()->addDays(7)
        : now()->addMinutes(10);

    return Cache::remember(
        $cacheKey,
        $ttl,
        fn () => $this->compute($user, $mesReferencia, $periodoOverride),
    );
}
```
→ Nova assinatura: `computeCached(User $user, Carbon $mesReferencia, ?array $periodoOverride = null, bool $incluirEmpresasScore = false): array`, propagando `$incluirEmpresasScore` na closure: `fn () => $this->compute($user, $mesReferencia, $periodoOverride, $incluirEmpresasScore)`.

---

### `config/metrics.php` (config, flag nova)

**Analog:** o próprio arquivo — `unified_metrics_enabled` (arquivo INTEIRO, 52 linhas, já lido):
```php
<?php

/*
|--------------------------------------------------------------------------
| Métricas multi-fonte (ADR DATA-04)
|--------------------------------------------------------------------------
|
| Referência canônica: `.planning/adrs/DATA-04-precedencia-multifonte.md`
| (seção "Rollout e feature flag" — linhas 298-303).
|
| Feature flag `unified_metrics_enabled` controla o rollout gradual do
| enriquecimento de dashboards com o campo `source` (`adman|ml|unified|none`)
| via `MetricsProviderFactory::caseFor()`.
|
|  - Default (`false`): consumidores (PortfolioController, DashboardController)
|    preservam 100% do comportamento legado — leituras `AdmanMetric` diretas
|    + cache Adman intocados. `UnifiedMetricsService` fica bootável em testes
|    mas não é consumido em runtime de produção.
|
|  - Ativado (`true`): consumidores enriquecem cada linha de empresa com
|    `source` + agregam `source_counts` em stats/carteiras. O caminho legado
|    continua ativo em paralelo (coexistência) — a flag apenas ADICIONA
|    metadados no payload Inertia, nunca substitui dados existentes.
|
| IMPORTANTE: consumidores devem ler via `config('metrics.unified_metrics_enabled')`
| — NUNCA `env('UNIFIED_METRICS_ENABLED')` direto em runtime. O Laravel invalida
| `env()` fora de config files quando o cache de config está aquecido em produção
| (`php artisan config:cache`).
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Feature flag da camada multi-fonte
    |----------------------------------------------------------------------
    |
    | Ativa/desativa o enriquecimento com `source` nos dashboards Phase 61.
    | Casting explícito via `filter_var(..., FILTER_VALIDATE_BOOLEAN)` aceita
    | `'true'`, `'1'`, `'on'` (case-insensitive) da env como bool `true` — e
    | rejeita valores ambíguos como `'yes'`, `'sim'`, `'off'` preservando
    | semântica estrita (defesa contra tampering T-61-01-01 do threat model).
    |
    */
    'unified_metrics_enabled' => filter_var(
        env('UNIFIED_METRICS_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),

];
```

**Regras do padrão (a flag nova DEVE seguir EXATAMENTE, 4 pontos):**
1. Chave em `snake_case`, valor booleano, default `false` via `env(..., false)`.
2. Casting via `filter_var(..., FILTER_VALIDATE_BOOLEAN)` — rejeita ambíguos.
3. Docblock no topo do bloco explicando rollout + fase/gate/pré-requisito.
4. Comentário explícito: consumidores leem via `config('metrics.NOME')`, nunca `env()` direto.

**Adição recomendada (mesmo array, nova entrada):**
```php
'performance_company_first_score' => filter_var(
    env('PERFORMANCE_COMPANY_FIRST_SCORE', false),
    FILTER_VALIDATE_BOOLEAN
),
```
Com docblock citando a Fase 120, o gate MPP-04 e a Fase 121 (delta aceito) como pré-requisitos de ativação em produção — mesmo espírito do docblock do `unified_metrics_enabled` que cita `UnifiedMetricsService` como "bootável em testes mas não consumido em produção" enquanto a flag estiver `false`.

---

### `app/Console/Commands/WarmDesempenhoCache.php` (command, shadow + guard do `Cache::remember`)

**Analog:** o próprio arquivo — chamada atual, linhas 116-124:
```php
foreach ($mesesAlvo as $mesReferencia) {
    foreach ($users as $user) {
        $tUser = microtime(true);
        try {
            // computeCached() faz Cache::remember internamente — se cache
            // ainda quente do run anterior, retorna instantâneo.
            $this->scoreService->computeCached($user, $mesReferencia);
            $elapsed = round(microtime(true) - $tUser, 2);
            $this->line("  ✓ user={$user->id} ({$user->name}) mes={$mesReferencia->format('Y-m')} — {$elapsed}s");
            $ok++;
        } catch (\Throwable $e) {
            Log::warning("[Desempenho] Warm cache falhou pra user {$user->id} mes {$mesReferencia->format('Y-m')}", [
                'error' => $e->getMessage(),
            ]);
            $this->error("  ✗ user={$user->id} mes={$mesReferencia->format('Y-m')} — {$e->getMessage()}");
            $fail++;
        }
    }
}
```

**Mudança recomendada (C-02 do CONTEXT — guard do `Cache::remember`, "nem forçar sempre, nem aceitar o gap"):**
```php
// Chamar com shadow=true; se o cache já estava quente E sem 'empresas_score',
// recomputar UMA VEZ (evita pular o shadow silenciosamente — C-02).
$resultado = $this->scoreService->computeCached($user, $mesReferencia, incluirEmpresasScore: true);
if (! array_key_exists('empresas_score', $resultado)) {
    // payload cacheado é de ANTES da Fase 120 (ou foi populado por leitura
    // interativa com shadow=false) — Cache::remember não reexecutou o
    // closure porque a chave já existia. Custa 1 leitura de cache por user;
    // NÃO usar Cache::forget() incondicional (reintroduziria recompute a
    // cada ciclo de 8min — exatamente o custo que o warm existe pra evitar).
    Cache::forget($this->scoreService->cacheKey($user->id, $mesReferencia));
    $resultado = $this->scoreService->computeCached($user, $mesReferencia, incluirEmpresasScore: true);
}
```
(Import de `Illuminate\Support\Facades\Cache` já precisa existir no arquivo — conferir antes de adicionar.)

**Padrão de log estabelecido no arquivo (linhas 110, 124, 127-130) — manter o mesmo estilo `[Desempenho]` + contadores:**
```php
$this->info("[Desempenho] Warming cache — {$users->count()} users elegíveis × " . count($mesesAlvo) . ' competência(s).');
...
Log::warning("[Desempenho] Warm cache falhou pra user {$user->id} mes {$mesReferencia->format('Y-m')}", [
    'error' => $e->getMessage(),
]);
```

---

### `app/Console/Commands/ConsolidarMesDesempenho.php` (command, shadow garantido)

**Analog:** o próprio arquivo — chamada atual, linha 139 (sem cache, portanto shadow SEMPRE roda garantido):
```php
foreach ($users as $user) {
    try {
        $result = $this->scoreService->compute($user, $mes);

        // DESEMP-10 — sem carteira: pula (não grava row).
        if (($result['sem_carteira'] ?? false) === true) {
            ...
        }

        // Fase 110 (Plan 02 · FIXMARG-03) — gate de qualidade da amostra de
        // margem ANTES do updateOrCreate ...
        $margemAmostra = $result['margem_amostra'] ?? ['n_real' => 0, 'n_elegivel' => 0, 'cobertura' => 1.0];
        $nElegivel     = (int) ($margemAmostra['n_elegivel'] ?? 0);
        $cobertura     = (float) ($margemAmostra['cobertura'] ?? 1.0);

        if ($nElegivel > 0 && $cobertura < self::MARGEM_COBERTURA_MINIMA_CONGELAMENTO) {
            // ... recusa persistir, loga com Log::error, preserva snapshot anterior ...
            $degradado++;
            continue;
        }

        DesempenhoScoreSnapshot::updateOrCreate(
            ['user_id' => $user->id, 'mes_referencia' => $mesStr],
            [
                'ref_date'             => $mes,
                'score'                => (int) round(($result['nota_final'] ?? 0.0) * 20),
                'classificacao'        => $result['faixa_bonus'] ?? '',
                'tem_base_comparativa' => $result['nota_final'] !== null,
                'empresas_carteira'    => (int) ($result['empresas_carteira'] ?? 0),
                'empresas_eligiveis'   => (int) ($result['empresas_com_baseline'] ?? 0),
                'breakdown_json'       => $result,
            ]
        );
        $ok++;
    } catch (\Throwable $e) {
        Log::error("[Desempenho Mensal] Falha user {$user->id} ({$user->name}) mês {$mesLabel}: {$e->getMessage()}");
        $fail++;
    }
}
```

**Mudança recomendada — 1 linha, sem tocar no resto do fluxo:**
```php
$result = $this->scoreService->compute($user, $mes, incluirEmpresasScore: true);
```
`breakdown_json => $result` já persiste `empresas_score` "de graça" (Fase 122 estrutura a persistência; aqui só o payload bruto muda). A constante `MARGEM_COBERTURA_MINIMA_CONGELAMENTO = 0.7` (linha 76) é o valor que D-03 reusa para o novo patamar de cobertura — **não duplicar o número como magic literal**, considerar `self::MARGEM_COBERTURA_MINIMA_CONGELAMENTO` como fonte se o planner quiser reduzir 2 conceitos a 1 (decisão do executor).

---

### 4 suítes com `desempenho.compute.v12` hardcoded (bump literal)

**Analog:** `tests/Feature/DesempenhoShopeeScoreTest.php:355-364` — o próprio teste que fixou v12 (bump anterior, v11→v12):
```php
#[Test]
public function test_cache_key_bumpado_para_v12(): void
{
    $user = $this->criarUserComCargo('Cache V12 116');
    $mes  = Carbon::parse('2026-08-01');

    $service = app(DesempenhoScoreService::class);
    $chave   = $service->cacheKey($user->id, $mes);

    $this->assertSame('desempenho.compute.v12.' . $user->id . '.current_month', $chave);
}
```
→ Renomear/atualizar para v13: `$this->assertSame('desempenho.compute.v13.' . $user->id . '.current_month', $chave);` (nome do método pode virar `test_cache_key_bumpado_para_v13` para não ficar obsoleto).

**Lista completa e verificada (grep exaustivo, RESEARCH Q6) — nenhuma ocorrência adicional:**
| Arquivo | Linha(s) | Ocorrências |
|---|---|---|
| `app/Services/DesempenhoScoreService.php` | 335 | 1 (definição canônica) |
| `tests/Feature/DesempenhoShopeeScoreTest.php` | 363 | 1 |
| `tests/Feature/Phase116/NpsFloorDesempenhoTest.php` | 388 | 1 |
| `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` | 246, 348 | 2 |
| `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` | 232, 258, 260, 277 | 4 |

Arquivos que usam o helper `$service->cacheKey(...)` (nunca hardcode) **não precisam de edição** — já recebem a versão nova automaticamente: `Phase106/WarmDesempenhoCacheTest.php`, `Phase106/PerformanceControllerWarmDegradationTest.php`, `V16/DesempenhoElegibilidadeTest.php`, `V16/BonusDualPathRegressaoTest.php`, `Unit/DesempenhoScoreServiceCacheTest.php`, `NpsMaterializarNaoRespondidosCommandTest.php`.

---

### `tests/Feature/DesempenhoShopeeScoreTest.php` (cenários espelho D-05)

**Analog:** os 4 testes que dependem de `margemPontos()` no próprio arquivo — molde para os cenários espelho (mesmo cenário, avaliado com a flag ligada, mesma classe):

Imports do arquivo (linhas 1-19) — os cenários espelho reusam os MESMOS:
```php
namespace Tests\Feature;

use App\Models\AdmanMetric;
use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;
```

**Trecho do teste "misto" (linhas 340-350) — cenário candidato ao espelho:**
```php
$this->assertSame(2, $r['empresas_com_baseline']);

// Margem: nComMargemReal=1 (empresa A); nShopeePlaceholder=1 (só B —
// C invalidada NÃO conta). Se a invalidação vazasse, seria
// (4.0×1 + 1.0×2)/3 = 2.00 (bug); o correto é (4.0×1 + 1.0×1)/2 = 2.50.
$this->assertEqualsWithDelta(2.80, $r['componentes']['var_margem_pct'], 0.001,
    'var_margem_pct real só reflete a empresa A (única com margem real).');
$this->assertEqualsWithDelta(2.50, $r['pontos_componentes']['margem'], 0.001,
    'nShopeePlaceholder pós-invalidação=1 (só empresaB) — se a invalidada contasse, margemPontos seria 2.00.');
```

**Molde do espelho (D-05 — cenário espelho, NÃO reescrever o teste original):** duplicar o setup do teste (`test_so_performance_regressao_zero_...`/`test_so_shopee_official_...`/`test_misto_ml_shopee_...`/`test_invalidacao_empresa_shopee_...`) em um método NOVO no mesmo arquivo, ativar a flag via `config(['metrics.performance_company_first_score' => true])` dentro do teste, chamar `compute()` (não `computeCached()`, para não interferir no cache do teste legado), e assertar o valor ESPERADO do caminho novo (que pode divergir numericamente — a média-das-notas-por-empresa não é o blend por contagem, ver risco herdado da Fase 119). Os 7 testes ORIGINAIS permanecem intocados, exceto o literal do `cacheKey()` (teste 7, v12→v13).

---

### `tests/Feature/Phase120/` — suíte nova (AGRE-01..06 + byte-equivalência)

**Analog 1 — teste de equivalência por Reflection:** `tests/Feature/Phase119/CompanyScoreServiceReguasTest.php` (arquivo INTEIRO, 129 linhas, já lido) — o molde mais direto para provar "flag desligada ≡ comportamento atual":
```php
namespace Tests\Feature\Phase119;

use App\Services\Desempenho\CompanyScoreService;
use App\Services\DesempenhoScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class CompanyScoreServiceReguasTest extends TestCase
{
    use RefreshDatabase;

    private function invocarRegua(object $service, string $metodo, ?float $pct): ?float
    {
        $ref = new ReflectionMethod($service, $metodo);
        $ref->setAccessible(true);

        return $ref->invoke($service, $pct);
    }

    #[Test]
    public function test_regua_faturamento_concorda_com_desempenho_score_service_em_todos_os_boundaries(): void
    {
        $original = app(DesempenhoScoreService::class);
        $novo     = app(CompanyScoreService::class);

        foreach ($this->boundaries() as $pct) {
            $this->assertSame(
                $this->invocarRegua($original, 'reguaFaturamento', $pct),
                $this->invocarRegua($novo, 'reguaFaturamento', $pct),
                "reguaFaturamento diverge do original no boundary pct=" . var_export($pct, true)
            );
        }
    }
}
```

**Analog 2 — teste de equivalência com 3 casos de boundary + docblock explicando o QUE está sendo protegido:** `tests/Feature/Phase118/NpsJanelaResolverTest.php` (linhas 23-37, 117-124, 211-236) — este é o molde mais rico, aplica-se DIRETAMENTE ao teste "flag off ≡ hoje" desta fase:
```php
/**
 * O teste mais importante desta suíte é
 * `test_resolver_concorda_com_computeNpsWindow_nos_tres_casos`: prova que a
 * classe nova concorda com `DesempenhoScoreService::computeNpsWindow()`
 * (invocado por reflection) nos 3 casos de boundary — é essa comparação
 * entre DUAS implementações fisicamente separadas que vigia a duplicação
 * deliberada da C-01 (118-CONTEXT.md).
 */
class NpsJanelaResolverTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private function invocarComputeNpsWindow(User $user, Carbon $mes, bool $mesFechado, ?\Illuminate\Support\Collection $invalidadas = null): ?float
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsWindow');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $mes, $mesFechado, $invalidadas);
    }

    #[Test]
    public function test_resolver_concorda_com_computeNpsWindow_nos_tres_casos(): void
    {
        $cenario  = $this->montarCenarioVazio();
        $resolver = new NpsJanelaResolver();

        $r1 = $this->invocarComputeNpsWindow($cenario['analista'], Carbon::parse('2026-06-01'), false);
        $this->assertSame(1.0, $r1);
        // ... caso 2 e 3, sempre comparando resultado da chamada nova
        //     contra a chamada por Reflection do método legado ...
    }
}
```

**Aplicação direta a esta fase (teste "flag off ≡ hoje", Pitfall 2/3 do RESEARCH):** em vez de comparar DUAS classes por Reflection (como 118/119), aqui o teste roda `compute()` DUAS VEZES na MESMA fixture — uma com `incluirEmpresasScore: true` e outra com `false` — e assere que `componentes.nps_medio`, `componentes.var_faturamento_pct`, `componentes.var_margem_pct`, `nota_final`, `score_status` são **idênticos** (`assertSame`) entre as duas chamadas; só `empresas_score` diverge (presente vs `[]`). Mesmo espírito de "comparar implementação nova × original", adaptado para "mesmo método, 2 parâmetros diferentes" em vez de "2 classes diferentes".

**Convenção de suíte confirmada:** `tests/Feature/Phase120/` segue o padrão de todas as fases anteriores (`Phase116/`, `Phase118/`, `Phase119/`, `V16/`, `V18/` — todos subdiretórios de `tests/Feature/`, namespace `Tests\Feature\PhaseNNN`), usando `use RefreshDatabase;` + `#[Test]` (PHPUnit 11 attributes, não `/** @test */` docblock) — mesmo padrão dos 2 analogs acima.

## Shared Patterns

### Feature flag em `config/metrics.php`
**Fonte:** `config/metrics.php` (arquivo inteiro, `unified_metrics_enabled`)
**Aplica a:** a chave nova `performance_company_first_score` + o docblock que a acompanha.
```php
'performance_company_first_score' => filter_var(
    env('PERFORMANCE_COMPANY_FIRST_SCORE', false),
    FILTER_VALIDATE_BOOLEAN
),
```
Leitura em runtime SEMPRE via `config('metrics.performance_company_first_score')` — nunca `env()` direto (`config:cache` invalida `env()` fora de config files em produção).

### Dois sinais independentes (flag × shadow)
**Fonte:** `120-CONTEXT.md` C-01, `120-RESEARCH.md` Padrão 1
**Aplica a:** `DesempenhoScoreService::compute()`/`computeCached()`, `WarmDesempenhoCache`, `ConsolidarMesDesempenho`
```php
// (a) shadow — SÓ controla SE CompanyScoreService::computeEmpresasScore() roda:
bool $incluirEmpresasScore = false   // default false; true SÓ nos 2 commands

// (b) flag — SÓ controla QUAL resultado vira nota_final/score_status:
config('metrics.performance_company_first_score')   // default false
```
Nunca misturar os dois em uma única condição fora do `compute()` orquestrador — nenhum método legado (`computeNotaFinal`, `computeScoreStatus`, `computeVarFaturamento`, `computeVarMargem`, `computeNpsWindow`, `margemPontos`) deve receber `$empresasScore` como argumento nem ler a flag internamente.

### Log com prefixo `[Desempenho]` / `[Desempenho Mensal]`
**Fonte:** `WarmDesempenhoCache.php:110,127` / `ConsolidarMesDesempenho.php:143,181,193,223`
**Aplica a:** qualquer log novo nos 2 commands modificados
```php
Log::warning("[Desempenho] Warm cache falhou pra user {$user->id} mes {$mesReferencia->format('Y-m')}", ['error' => $e->getMessage()]);
Log::error("[Desempenho Mensal] Falha user {$user->id} ({$user->name}) mês {$mesLabel}: {$e->getMessage()}");
```

### Teste de equivalência via Reflection para métodos privados
**Fonte:** `tests/Feature/Phase119/CompanyScoreServiceReguasTest.php` + `tests/Feature/Phase118/NpsJanelaResolverTest.php`
**Aplica a:** `tests/Feature/Phase120/*` — prova de byte-equivalência flag-off
```php
private function invocarMetodo(object $service, string $metodo, ...$args)
{
    $ref = new ReflectionMethod($service, $metodo);
    $ref->setAccessible(true);
    return $ref->invoke($service, ...$args);
}
```

## No Analog Found

Nenhum arquivo desta fase ficou sem analog — todos são modificações de arquivos já existentes, e o próprio histórico de cada arquivo (bumps anteriores de flag/cache, chamadas atuais) já serve como molde direto. O único método verdadeiramente NOVO (`computeNotaFinalPorEmpresa`/`computeScoreStatusPorEmpresa`) não tem analog de implementação porque a lógica em si é escopo do planner (aritmética de agregação sobre `CompanyScoreService::computeEmpresasScore()`, já pronta) — mas o MOLDE do método vizinho (`computeScoreStatus()`, linhas 691-699) mostra a convenção de assinatura/docblock a seguir.

## Metadata

**Escopo de busca de analog:** `app/Services/DesempenhoScoreService.php`, `app/Services/Desempenho/CompanyScoreService.php`, `config/metrics.php`, `app/Console/Commands/WarmDesempenhoCache.php`, `app/Console/Commands/ConsolidarMesDesempenho.php`, `tests/Feature/DesempenhoShopeeScoreTest.php`, `tests/Feature/Phase118/`, `tests/Feature/Phase119/`
**Arquivos lidos nesta sessão:** 9
**Data de extração:** 2026-07-29
