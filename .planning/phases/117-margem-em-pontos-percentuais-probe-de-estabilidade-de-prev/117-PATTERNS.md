# Fase 117: Margem em pontos percentuais + probe de estabilidade de `prev` — Mapa de Padrões

**Mapeado em:** 2026-07-27
**Arquivos analisados:** 8 (3 modificados + 3 criados + 2 arquivos de teste, sendo 1 já existente e não listado pela pesquisa)
**Análogos encontrados:** 8 / 8

> **Correção importante em relação ao RESEARCH.md:** a pesquisa afirma duas vezes que
> `ShopeeMetricDiffServiceTest` "não existe hoje" / "arquivo não encontrado" e recomenda
> criar `tests/Feature/V21/ShopeeMetricDiffDiffPpTest.php`. **Isso está errado.** O arquivo
> já existe em `tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php` (189 linhas, 7 cenários
> verdes). O executor deve **editar esse arquivo existente**, não criar um novo em
> `tests/Feature/`. Ver `## Achado crítico` abaixo — ele também tem uma asserção estrita de
> `array_keys()` que vai quebrar, então a pesquisa também errou ao dizer que
> `AdmanMetricDiffServiceTest.php:365-368` é "o ÚNICO lugar" com esse padrão.

---

## File Classification

| Arquivo novo/modificado | Papel | Fluxo de dados | Análogo mais próximo | Qualidade do match |
|---|---|---|---|---|
| `app/Services/Metrics/AdmanMetricDiffService.php` | service (domínio, sem persistência) | transform (shape aditivo sobre payload HTTP já cacheado) | ele mesmo — editar in-place; estado atual é o próprio molde | exato (é o arquivo-alvo) |
| `app/Services/Metrics/ShopeeMetricDiffService.php` | service (domínio, leitura local) | transform (CRUD read + agregação) | `AdmanMetricDiffService.php` (mesmo contrato `compute()`) | exato (contrato espelhado por design) |
| `tests/Feature/V18/AdmanMetricDiffServiceTest.php` | test (feature, `Http::fake()`) | request-response (HTTP mockado) | ele mesmo — editar cenário (e) + adicionar cenários novos | exato |
| `tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php` | test (unit/feature híbrido, `RefreshDatabase`, sem HTTP) | CRUD (leitura de `ShopeeMetric` local) | ele mesmo — editar cenário `test_shape_identico_ao_adman` + adicionar cenário `diff_pp` | exato |
| `app/Console/Commands/ProbeMargemPrevStability.php` (novo, `adman:probe-margem-prev`) | console command | batch (iteração empresa × leitura HTTP) | `app/Console/Commands/WarmAdmanDiffCache.php` | role-match forte (mesma forma: itera `Company[]`, chama serviço Adman, loga OK/FAIL) |
| `app/Models/AdmanProbeMargemPrevLeitura.php` (novo) | model (Eloquent, tabela de log/diagnóstico) | CRUD (insert-only, sem update) | `app/Models/MlbSyncVendasLog.php` | exato (mesma filosofia: 1 linha por evento, consultável depois) |
| `database/migrations/2026_07_XX_create_adman_probe_margem_prev_leituras_table.php` (novo) | migration | — | `database/migrations/2026_05_22_100001_create_mlb_sync_vendas_logs_table.php` | exato |
| `tests/Feature/Phase117/ProbeMargemPrevStabilityCommandTest.php` (novo) | test (feature, `Http::fake()`) | request-response + batch | `tests/Feature/V18/AdmanMetricDiffServiceTest.php` (fixture HTTP) + `app/Console/Commands/WarmAdmanDiffCache.php` (estrutura do comando testado) | role-match |

---

## Pattern Assignments

### `app/Services/Metrics/AdmanMetricDiffService.php` (service, transform)

**Análogo:** o próprio arquivo — a mudança é aditiva sobre o padrão já estabelecido nele mesmo. Todos os pontos de edição já foram lidos linha a linha; os trechos abaixo são o **estado atual exato**, para o executor aplicar diffs cirúrgicos e não reescrever do zero.

**Imports (linhas 1-10, inalterado):**
```php
<?php

namespace App\Services\Metrics;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Services\AdmanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
```

**Cache key (linhas 113-122) — ponto único de bump v5→v6:**
```php
        // v2 (2026-07-22): descarta resultados PARCIAIS envenenados (ByMobille).
        // v3 (2026-07-22): margem R$ passou de `profitMargin` para `liquidMargin`
        // (caso Utilarshop) — o valor muda, então o bump invalida o cache v2.
        // v4 (2026-07-24): variação de margem volta ao .diff nativo da Adman
        // (sem cálculo local) — o bump invalida os valores locais errados em cache.
        // v5 (2026-07-27): faturamento cai pro `billing` do endpoint detalhado
        // quando o /performance dá 500 (caso Jf Auto) — o bump invalida as entradas
        // "sem fonte" antigas dessas empresas.
        $cacheKey = "adman:diff:v5:{$marketplace}:{$custId}:{$periodo['current_start']}:{$periodo['current_end']}:" . $this->cacheDay();
```
Seguir o padrão exato do histórico de comentários numerados ao trocar `v5` por `v6` — adicionar a linha:
```php
        // v6 (2026-07-27): shape ganha prev_value/diff_pp (Fase 117, MPP-01/02/03) —
        // bump invalida entradas com shape antigo.
```
e trocar `adman:diff:v5:` por `adman:diff:v6:` na string. **Não tocar em mais nada dessa linha** (ordem dos placeholders é significativa para outros greps/dashboards de cache).

**`resolveField()` — estado atual (linhas 231-249), usado por `revenue` e `contribution_margin_value` — SEM `diff_pp` (D-06):**
```php
    private function resolveField(?array $adman, bool $isJanelaIgual, callable $fallback): array
    {
        $value = isset($adman['value']) ? (float) $adman['value'] : null;
        $adminDiff = $adman['diff'] ?? null;

        if ($isJanelaIgual && $adminDiff !== null) {
            return [
                'value'       => $value,
                'diff_pct'    => (float) $adminDiff,
                'diff_source' => 'adman_diff',
            ];
        }

        return [
            'value'       => $value,
            'diff_pct'    => $fallback(),
            'diff_source' => 'calculated_fallback',
        ];
    }
```
Adicionar `'prev_value' => isset($adman['prev']) ? (float) $adman['prev'] : null` (linha extra logo após `$value = ...`) e incluir `'prev_value' => $prevValue` nos DOIS `return` — **incondicional ao gate `$isJanelaIgual`** (D-05: prev_value não é gateado, só `diff_pp` é).

**`resolveMargemPct()` — estado atual (linhas 272-290), único lugar onde `diff_pp` nasce:**
```php
    private function resolveMargemPct(?array $marginPctAdman, bool $isJanelaIgual): array
    {
        $value     = isset($marginPctAdman['value']) ? (float) $marginPctAdman['value'] : null;
        $adminDiff = $marginPctAdman['diff'] ?? null;

        if ($isJanelaIgual && $adminDiff !== null) {
            return [
                'value'       => $value,
                'diff_pct'    => (float) $adminDiff,
                'diff_source' => 'adman_diff',
            ];
        }

        return [
            'value'       => $value,
            'diff_pct'    => null,
            'diff_source' => 'adman_indisponivel',
        ];
    }
```
Docblock desta função (linhas 251-271, hotfix `a413e823` de 2026-07-24) contém a frase **"NUNCA cálculo local"** — atualizar o docblock explicitamente para registrar que a Fase 117 reabre isso deliberadamente para `diff_pp` (pp não é expressável pelo `.diff` nativo), sem reverter a decisão de `diff_pct` continuar sendo sempre `.diff` nativo. Não "contornar em silêncio" — é uma instrução explícita do CONTEXT.

**`emptyMetrics()` — estado atual (linhas 550-555):**
```php
    private function emptyMetrics(): array
    {
        $vazio = ['value' => null, 'diff_pct' => null, 'diff_source' => null];

        return array_fill_keys(self::METRIC_KEYS, $vazio);
    }
```
Precisa deixar de ser uniforme (D-06: só `contribution_margin_pct` tem `diff_pp`). Construir explicitamente por chave — ver Pitfall 5 do RESEARCH: decisão recomendada é chave **AUSENTE** em `revenue`/`contribution_margin_value` (não presente com `null`), para não sugerir que `diff_pp` é conceito válido ali.

**`buildQuality()` — estado atual (linhas 567-591):**
```php
    private function buildQuality(array $metrics): array
    {
        $comValue = collect($metrics)->filter(fn ($m) => $m['value'] !== null)->count();
        $comDiff  = collect($metrics)->filter(fn ($m) => $m['diff_pct'] !== null)->count();

        $status = match (true) {
            $comValue === count(self::METRIC_KEYS) => 'complete',
            $comValue === 0 && $comDiff === 0      => 'missing',
            default                                => 'partial',
        };

        return [
            'status'      => $status,
            'source'      => 'adman',
            'computed_at' => now()->toIso8601String(),
        ];
    }
```
Adicionar `'diff_pp_disponivel' => $metrics['contribution_margin_pct']['diff_pp'] !== null` ao array de retorno — **sem tocar em `$status`** (D-08 é explícito: rebaixar `status` prenderia empresa sem `prev` em TTL curto permanente).

---

### `app/Services/Metrics/ShopeeMetricDiffService.php` (service, CRUD local)

**Análogo:** `AdmanMetricDiffService.php` (mesmo contrato `compute()`), mas o ponto de mudança real é local ao próprio arquivo.

**`margemNula()` — estado atual (linhas 186-190):**
```php
    /** Bloco de margem — sempre null nos 3 campos (Shopee não tem CMV). */
    private function margemNula(): array
    {
        return ['value' => null, 'diff_pct' => null, 'diff_source' => null];
    }
```
Trocar para incluir `prev_value` e `diff_pp`, ambos `null` (MPP-05):
```php
    private function margemNula(): array
    {
        return ['value' => null, 'prev_value' => null, 'diff_pct' => null, 'diff_pp' => null, 'diff_source' => null];
    }
```

**`calcularRevenue()` (linhas 102-115) e `calcularInvestimento()` (linhas 124-137)** — ambos já calculam `$anterior` localmente. Por simetria de shape (recomendação de discrição do RESEARCH, custo zero), adicionar `'prev_value' => $anterior` no array de retorno de cada um:
```php
        return [
            'value'       => $atual,
            'prev_value'  => $anterior,  // NOVO — simetria de shape com Adman, custo zero (já calculado)
            'diff_pct'    => $this->diffPctGuardado($atual, $anterior),
            'diff_source' => 'calculated_fallback',
        ];
```
Isso NÃO é exigido por MPP-05 (que só cobre `diff_pp`), mas evita shapes divergentes entre fontes — decisão do planner, documentar a escolha feita.

---

### `tests/Feature/V18/AdmanMetricDiffServiceTest.php` (test, feature/HTTP)

**Fixture âncora — já existe, NÃO recriar (linhas 52-62):**
```php
    private function respostaAccountMetrics(): array
    {
        return [
            'metrics' => [
                'billing'           => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                'liquidMargin'      => ['value' => 141428.81, 'diff' => 147.96, 'prev' => 57036.05],
                'percentageMargin'  => ['value' => 27.47,     'diff' => 14.09,  'prev' => 24.08],
                'investment'        => ['value' => 9990.82,   'diff' => 80.36,  'prev' => 5539.43],
            ],
        ];
    }
```

**Helper de fake HTTP — já existe, é o ponto de plugue para cenários novos (linhas 115-128):**
```php
    private function fakeAdmanEndpoints(?float $percentageMarginDiff = 14.09): void
    {
        $accountMetrics = $this->respostaAccountMetrics();
        if ($percentageMarginDiff === null) {
            unset($accountMetrics['metrics']['percentageMargin']['diff']);
        } else {
            $accountMetrics['metrics']['percentageMargin']['diff'] = $percentageMarginDiff;
        }

        Http::fake([
            '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
            '*/accounts/*/metrics*' => Http::response($accountMetrics, 200),
        ]);
    }
```
**Como um cenário novo se pluga nisso:** para MPP-02/MPP-06, chamar `$this->fakeAdmanEndpoints()` sem argumento (usa `diff=14.09` default, `prev=24.08` da fixture) e comparar contra `periodoJanelaIgual()`. Para o cenário negativo (D-07, `diff_pp=null` fora de janela-igual), reusar o MESMO `fakeAdmanEndpoints()` mas trocar `periodoJanelaIgual()` por `periodoOperacional()` (linhas 159-177, já existe) — **não criar fixture nova**, o `prev=24.08` já está presente no payload padrão e o teste prova que `diff_pp` é `null` mesmo com `prev` presente (D-07 é sobre `comparison_mode`, não sobre ausência de `prev`).

Exemplo completo do cenário MPP-06 (já desenhado no RESEARCH, copiar literalmente):
```php
public function test_r_fixture_ancora_diff_pp_3_39_nao_deriva_de_diff_pct(): void
{
    $this->fakeAdmanEndpoints(); // percentageMargin: value=27.47, diff=14.09, prev=24.08 (fixture já existente)
    $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

    $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

    $margem = $resultado['metrics']['contribution_margin_pct'];
    $this->assertSame(27.47, $margem['value']);
    $this->assertSame(24.08, $margem['prev_value']);
    $this->assertSame(3.39, $margem['diff_pp']);       // 27.47 - 24.08, NÃO deriva de diff_pct
    $this->assertSame(14.09, $margem['diff_pct']);     // inalterado — continua sendo a variação relativa nativa
}
```

**Para MPP-03 (cache v5→v6), padrão de "pré-popular cache antigo" — não existe helper pronto, mas o padrão de `Cache::flush()`/`Cache::put()` já é usado no arquivo (cenário h, linha 477 `Cache::flush()`). Esboço:**
```php
public function test_q_cache_v6_nao_reaproveita_shape_v5(): void
{
    $this->fakeAdmanEndpoints();
    $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);
    $periodo = $this->periodoJanelaIgual();

    // Simula shape v5 (sem prev_value/diff_pp) já cacheado na chave ANTIGA.
    $cacheKeyV5 = "adman:diff:v5:meli:CUST1:{$periodo['current_start']}:{$periodo['current_end']}:" . now()->toDateString();
    Cache::put($cacheKeyV5, ['metrics' => ['contribution_margin_pct' => ['value' => 1.0, 'diff_pct' => 1.0, 'diff_source' => 'adman_diff']]], 1440);

    $resultado = app(AdmanMetricDiffService::class)->compute($company, $periodo);

    // v6 não leu a v5 velha — o resultado tem o shape NOVO com prev_value/diff_pp.
    $this->assertArrayHasKey('prev_value', $resultado['metrics']['contribution_margin_pct']);
}
```

**Asserção estrita que VAI QUEBRAR e precisa ser atualizada, não contornada (linhas 364-370):**
```php
        $this->assertSame(
            ['revenue', 'contribution_margin_value', 'contribution_margin_pct'],
            array_keys($resultado['metrics'])
        );
        foreach ($resultado['metrics'] as $metric) {
            $this->assertSame(['value', 'diff_pct', 'diff_source'], array_keys($metric));
        }
```
Atualizar o `foreach` para verificar shapes DIFERENTES por métrica (decisão do Pitfall 5 do RESEARCH — chave ausente, não presente com null):
```php
        $this->assertSame(['value', 'prev_value', 'diff_pct', 'diff_source'], array_keys($resultado['metrics']['revenue']));
        $this->assertSame(['value', 'prev_value', 'diff_pct', 'diff_source'], array_keys($resultado['metrics']['contribution_margin_value']));
        $this->assertSame(['value', 'prev_value', 'diff_pct', 'diff_pp', 'diff_source'], array_keys($resultado['metrics']['contribution_margin_pct']));
```

---

### `tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php` (test, unit/DB — ARQUIVO JÁ EXISTE)

**Achado crítico (contradiz o RESEARCH.md):** este arquivo existe (189 linhas, `namespace Tests\Unit\Metrics`, usa `RefreshDatabase` mas SEM HTTP — leitura 100% local de `ShopeeMetric`). O RESEARCH.md e o VALIDATION.md afirmam que essa suíte "não existe hoje" e recomendam criar `tests/Feature/V21/ShopeeMetricDiffDiffPpTest.php`. **O executor NÃO deve criar um arquivo novo** — deve editar este.

**Ele TAMBÉM tem uma asserção `array_keys()` estrita que vai quebrar (linhas 176-183), contradizendo a afirmação do RESEARCH de que `AdmanMetricDiffServiceTest.php:365-368` é "o ÚNICO lugar" com esse padrão:**
```php
    public function test_shape_identico_ao_adman(): void
    {
        $company = Company::factory()->create();
        $periodo = $this->periodo();
        $this->semear($company, '2026-07-01', 100.0, 10.0);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $periodo);

        $this->assertSame($company->id, $resultado['company_id']);
        $this->assertSame($periodo, $resultado['period']);
        $this->assertSame(
            ['revenue', 'contribution_margin_value', 'contribution_margin_pct'],
            array_keys($resultado['metrics'])
        );
        foreach ($resultado['metrics'] as $metric) {
            $this->assertSame(['value', 'diff_pct', 'diff_source'], array_keys($metric));
        }
        $this->assertSame(['value', 'diff_pct', 'diff_source'], array_keys($resultado['investment']));
        $this->assertSame(['status', 'source', 'computed_at'], array_keys($resultado['quality']));
        $this->assertSame('shopee', $resultado['quality']['source']);
        // Margem sempre null -> nunca 'complete' no critério herdado do Adman.
        $this->assertSame('partial', $resultado['quality']['status']);
    }
```
Atualizar o `foreach` do mesmo jeito que em `AdmanMetricDiffServiceTest` — shapes diferentes por métrica (`contribution_margin_pct` ganha `diff_pp`, as outras duas + `investment` ganham `prev_value` mas não `diff_pp`).

**Cenário novo a adicionar (MPP-05), usando o padrão de setup já estabelecido no arquivo (`semear()`, `periodo()`):**
```php
public function test_margem_diff_pp_sempre_null(): void
{
    $company = Company::factory()->create();
    $this->semear($company, '2026-07-01', 100.0);

    $resultado = app(ShopeeMetricDiffService::class)->compute($company, $this->periodo());

    $this->assertNull($resultado['metrics']['contribution_margin_pct']['diff_pp']);
    $this->assertArrayNotHasKey('diff_pp', $resultado['metrics']['revenue']);
}
```

---

### `app/Console/Commands/ProbeMargemPrevStability.php` (novo, console command, batch)

**Análogo:** `app/Console/Commands/WarmAdmanDiffCache.php` (íntegro, 96 linhas) — mesma forma estrutural: injeção de serviço via construtor, resolve período(s), itera `Collection<Company>`, `try/catch (\Throwable)` por item com log de falha (fail-open), relatório final com contadores OK/FAIL.

**Assinatura/flags — padrão a seguir (linhas 29-41 do análogo):**
```php
class WarmAdmanDiffCache extends Command
{
    protected $signature = 'adman:warm-diff
        {--period= : period_key específico (ex.: 2026-06); default = mês atual + mês fechado}';

    protected $description = 'Aquece o cache do AdmanMetricDiffService (carteira/transparência/desempenho lêem daqui). Cache diário — rodar após o sync Adman.';

    public function __construct(
        private AdmanMetricDiffService $diff,
        private MetricPeriodResolver $resolver,
    ) {
        parent::__construct();
    }
```
Para o probe, seguir o mesmo padrão de injeção construtor + `parent::__construct()`, mas injetando `AdmanService` (não `AdmanMetricDiffService` — Pitfall 1) + `MetricPeriodResolver`. Sugestão de assinatura (D-02/D-04/D-09/Open Question 2 do RESEARCH):
```php
protected $signature = 'adman:probe-margem-prev
    {--mes= : período fechado YYYY-MM fixo entre leituras (obrigatório na 1ª leitura da rodada)}
    {--janela= : rótulo humano da janela (madrugada|sync_adman|pico|repeticao_24h|manual)}
    {--relatorio : agrega as leituras já persistidas e emite o veredito, sem ler a Adman}';
```

**Iteração fail-open por item — padrão exato a copiar (linhas 68-84):**
```php
        foreach ($periodos as $periodo) {
            foreach ($companies as $c) {
                try {
                    // compute() faz Cache::remember interno — em cache-miss
                    // popula; em cache-hit não custa nada (idempotente).
                    $this->diff->compute($c, $periodo);
                    $ok++;
                } catch (\Throwable $e) {
                    $fail++;
                    Log::warning('[AdmanDiffWarm] falhou', [
                        'company_id' => $c->id,
                        'periodo'    => $periodo['current_start'] . '..' . $periodo['current_end'],
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }
```
**Diferença crítica para o probe (Pitfall 1 do RESEARCH):** trocar `$this->diff->compute($c, $periodo)` por chamada DIRETA a `AdmanService::fetchAccountMetricsDetailedCached(..., forceRefresh: true)` — NUNCA `AdmanMetricDiffService::compute()`. Ver seção "Comando de probe — leitura fresca sem cache" abaixo.

**Relatório final — padrão exato de formatação (linhas 86-94):**
```php
        $seg = round(microtime(true) - $inicio, 1);
        $msg = sprintf(
            '[AdmanDiffWarm] concluído em %ss — empresas=%d períodos=%d OK=%d FAIL=%d',
            $seg, $companies->count(), count($periodos), $ok, $fail
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
```
O probe deve seguir o MESMO padrão (`sprintf` com tag `[AdmanProbeMargemPrev]`, `Log::info` + `$this->info` duplicados, `return self::SUCCESS`), mas **nunca incluir valores de margem/revenue por empresa no log** — só contadores agregados (empresas, OK, FAIL, cobertura). Os valores individuais vão para a tabela, não para o log (mesmo raciocínio de "não expor dado financeiro fora do DB" do Security Domain do RESEARCH).

**Padrão de logging com tag entre colchetes confirmado no domínio (múltiplos exemplos reais, nenhum loga token/credencial):**
```php
Log::warning("[Adman] Campanhas empresa {$company->id}: " . $e->getMessage());
Log::warning("[Adman/AccountMetricsDetailed] custId={$custId} range={$dateFrom}..{$dateTo} apos 3 tentativas: " . $lastError->getMessage());
Log::info("[Shopee] Sync manual (job) empresa {$this->companyId} — {$from} → {$to}");
```
Confirmado por grep em `app/Services/AdmanService.php` e `app/Jobs/`: todos os logs do domínio usam `company_id`/`custId`/mensagem de exceção — **nenhum loga `ADMAN_API_KEY` nem qualquer header de auth**. O probe deve seguir a mesma disciplina: tag `[AdmanProbeMargemPrev]`, `company_id`, período, e o resultado (`value`/`prev`/`http_falhou`) — nunca a chave de API.

**Comando de probe — leitura fresca sem cache (o ponto mais fácil de errar, D-11b):**
```php
// NUNCA fazer isto no loop do probe (mede o cache, não a Adman):
//   app(AdmanMetricDiffService::class)->compute($company, $periodo);
//
// Fazer isto (bypassa os DOIS caches — o do diff service nem é chamado,
// e o forceRefresh=true bypassa o cache interno do AdmanService):
$admanService = app(AdmanService::class);
$detalhado = $admanService->fetchAccountMetricsDetailedCached(
    custId: $custId,
    dateFrom: $periodo['current_start'],
    dateTo: $periodo['current_end'],
    cacheMinutes: 1440,
    forceRefresh: true,       // ← bypassa a LEITURA do cache; ainda GRAVA o valor fresco (linha 870 de AdmanService.php)
    marketplace: $marketplace,
);
$percentageMargin = $detalhado['percentageMargin'] ?? null; // {value, diff, prev} ou null
```
Assinatura real confirmada em `app/Services/AdmanService.php:805`:
```php
public function fetchAccountMetricsDetailedCached(string $custId, string $dateFrom, string $dateTo, int $cacheMinutes = 1440, bool $forceRefresh = false, string $marketplace = 'meli'): ?array
```
`forceRefresh=true` só pula o `Cache::get()` (linhas 809-814); o `Cache::put()` final roda incondicionalmente — não usar `Cache::flush()` (destrutivo em produção, apaga sessões e outros caches).

**Como obter as empresas da carteira (pergunta 4 — `CarteiraContextService::forUser()`):**
Assinatura real: `public function forUser(User $user, array $filters = []): Collection` (`app/Services/Portfolio/CarteiraContextService.php:106`). Retorna `Collection` de arrays associativos com o shape:
```php
[
  'user_id' => int, 'company_id' => int, 'company_name' => string,
  'servico_id' => ?int, 'servico_nome' => ?string, 'setor' => string,
  'role' => string, 'role_label' => string,
  'has_financial_source' => bool, 'financial_source' => ?string,
  'financial_metrics_eligible' => bool,
]
```
Uso concreto no probe:
```php
$luiz   = User::findOrFail(3);
$danilo = User::findOrFail(15);

$vinculosLuiz   = app(CarteiraContextService::class)->forUser($luiz)
    ->where('financial_metrics_eligible', true)
    ->where('financial_source', 'adman'); // só empresas Adman entram no probe de percentageMargin
$vinculosDanilo = app(CarteiraContextService::class)->forUser($danilo)
    ->where('financial_metrics_eligible', true)
    ->where('financial_source', 'adman');

$companyIds = $vinculosLuiz->pluck('company_id')
    ->concat($vinculosDanilo->pluck('company_id'))
    ->unique()
    ->values();

$companies = Company::whereIn('id', $companyIds)->get(['id', 'name', 'adman_account_id', 'ml_store_id', 'marketplace']);
```
Filtrar por `financial_source === 'adman'` é obrigatório: `percentageMargin` só existe no caminho Adman.

**Como montar `$periodo` de competência fechada (pergunta 5 — `MetricPeriodResolver`):**
```php
$periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => $this->option('mes') ?? '2026-06']);
// $periodo['comparison_mode'] === 'previous_equal_length_window'
// $periodo['current_start'] / $periodo['current_end'] fixos e reprodutíveis entre leituras
```
**Usar `'YYYY-MM'` explícito, NUNCA `'last_closed_month'`** — `last_closed_month` pode apontar para competências diferentes se a execução cruzar a virada de mês entre as 5+ leituras espalhadas em 24-48h, invalidando a comparação "mesma janela, valores diferentes" que é o objeto do probe. Confirmado em `app/Services/Metrics/MetricPeriodResolver.php:87-101`: `preg_match('/^\d{4}-\d{2}$/', $periodKey)` cai em `resolveSpecificMonth()`, que produz `comparison_mode = 'previous_equal_length_window'` (linha 243) — igual a `last_closed_month`, mas com `current_start`/`current_end` fixos por string em vez de recalculados a cada chamada.

**Régua de margem — duplicação documentada (pergunta 6):**
`DesempenhoScoreService::reguaMargem()` é `private` — copiar o corpo EXATO, byte a byte nas fronteiras (`app/Services/DesempenhoScoreService.php:1311-1319`):
```php
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
```
Duplicar no comando do probe (nome local `notaRegua()` ou `reguaMargem()`) com docblock explícito: **"duplicação TEMPORÁRIA e INTENCIONAL, cópia fiel de `DesempenhoScoreService::reguaMargem()` (linha 1311), NÃO tornar `public static` nesta fase — D-12 do CONTEXT proíbe tocar `DesempenhoScoreService`. A extração de um helper compartilhado fica para a Fase 119."** — mesmo padrão de disclaimer já usado no docblock de classe de `AdmanMetricDiffService` ("calculated_fallback — duplicação TEMPORÁRIA e INTENCIONAL").

---

### `app/Models/AdmanProbeMargemPrevLeitura.php` (novo, model)

**Análogo:** `app/Models/MlbSyncVendasLog.php` (íntegro, 47 linhas) — precedente direto do projeto para "tabela de log/diagnóstico, 1 linha por evento, escrita por comando, sem `LogsActivity`".

**Padrão exato a seguir:**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent para o log de execuções do SyncTodasVendasAdmanJob.
 *
 * Registra início, fim, totais e erros de cada disparo do job de sync
 * de vendas MLB, tornando o processo completamente observável no painel
 * /dev/desenvolvimento sem precisar acessar o storage/logs/laravel.log.
 */
class MlbSyncVendasLog extends Model
{
    // ─── Constantes de status ─────────────────────────────────────────────────

    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    // ─── Campos preenchíveis ──────────────────────────────────────────────────

    protected $fillable = [
        'user_id',
        'date_from',
        'date_to',
        'status',
        'total_empresas',
        'total_itens',
        'com_venda',
        'encontradas',
        'erros',
        'empresas_com_erro',
        'started_at',
        'finished_at',
    ];

    // ─── Casts de tipos ───────────────────────────────────────────────────────

    protected $casts = [
        'empresas_com_erro' => 'array',
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
    ];
}
```
**Confirmado:** `MlbSyncVendasLog` NÃO usa `LogsActivity` (não é um modelo de domínio auditável — é o próprio log). `AdmanProbeMargemPrevLeitura` deve seguir o mesmo: sem `LogsActivity`, `$fillable` explícito, `$casts` para datetime (`lida_em`) e qualquer campo booleano/decimal que precise de tipo forte no PHP.

Esboço de model análogo (adaptando ao schema recomendado pelo RESEARCH):
```php
class AdmanProbeMargemPrevLeitura extends Model
{
    protected $fillable = [
        'company_id',
        'periodo_key',
        'lida_em',
        'janela_esperada',
        'value',
        'prev',
        'diff_nativo',
        'margem_var_pp',
        'nota_regua',
        'http_falhou',
    ];

    protected $casts = [
        'lida_em'     => 'datetime',
        'value'       => 'float',
        'prev'        => 'float',
        'diff_nativo' => 'float',
        'margem_var_pp' => 'float',
        'nota_regua'  => 'integer',
        'http_falhou' => 'boolean',
    ];

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
```

---

### `database/migrations/2026_07_XX_create_adman_probe_margem_prev_leituras_table.php` (nova migration)

**Análogo:** `database/migrations/2026_05_22_100001_create_mlb_sync_vendas_logs_table.php` — precedente exato de convenção de nome de arquivo, comentário de topo em pt-BR, uso de `enum`, FK nullable+`nullOnDelete`, e índice para "ordenação eficiente do histórico".

**Migration análoga, íntegra:**
```php
<?php

// pt-BR: Migration que cria a tabela de log de execuções do SyncTodasVendasAdmanJob.
// Cada linha representa um disparo do job de sync de vendas MLB, registrando início,
// fim, totais de itens/publicações e quais empresas falharam durante o processamento.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_sync_vendas_logs', function (Blueprint $table) {
            $table->id();

            // Usuário que disparou o sync (null se disparado pelo scheduler ou sem autenticação)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Período solicitado no sync (formato YYYY-MM-DD vindo do request validate)
            $table->string('date_from');
            $table->string('date_to');

            // Estado da execução: running → completed | failed
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');

            // Totais acumulados durante o processamento
            $table->unsignedInteger('total_empresas')->nullable();
            // ... (demais colunas)

            // JSON com [{nome, motivo}] das empresas que falharam durante o loop
            $table->json('empresas_com_erro')->nullable();

            // Timestamps do ciclo de vida do job (separados de created_at/updated_at)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // Índice para ordenação eficiente do histórico na página /dev/desenvolvimento
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_sync_vendas_logs');
    }
};
```

**ALERTA de armadilha de migration (memória do projeto) — aplicada ao schema recomendado pelo RESEARCH:**

O schema sugerido pelo RESEARCH usa:
```php
$table->foreignId('company_id')->constrained()->cascadeOnDelete();
```
Esta FK é **NOT NULL com `cascadeOnDelete()`** (não `nullOnDelete()`) — portanto **não** cai na armadilha de "MySQL: FK SET NULL exige coluna nullable (erro 1830)" registrada na memória do projeto (essa armadilha só se aplica a `nullOnDelete()`). Se o planner ou executor decidir, em algum ponto, trocar `cascadeOnDelete()` por `nullOnDelete()` nessa coluna (ex.: para não perder leituras históricas quando uma empresa for removida), **é obrigatório adicionar `->nullable()` antes de `->constrained()`** — exatamente como o próprio `mlb_sync_vendas_logs` faz em `user_id` (`->nullable()->constrained('users')->nullOnDelete()`). Sinalizando explicitamente porque este é um erro que já quebrou deploy em produção nesta base de código (MariaDB aceita CREATE mas falha em 1830 se a FK apontar para uma coluna NOT NULL com ON DELETE SET NULL) e o SQLite dos testes locais NÃO pega esse erro — só aparece no VPS.

**Segundo alerta (enum + SQLite CHECK):** o schema do RESEARCH não usa nenhum `enum()` — usa `unsignedTinyInteger('nota_regua')` e `boolean('http_falhou')`. Isso evita completamente a armadilha "enum + SQLite: CHECK enforçado nos testes" registrada na memória do projeto (que só se aplica quando se ADICIONA um valor a um enum JÁ EXISTENTE via `string()->change()`). Como esta é uma tabela NOVA sem enum, **não há necessidade de branch de compatibilidade SQLite** — mas se o planner decidir usar `enum('janela_esperada', [...])` em vez de `string()->nullable()` (como o RESEARCH já recomenda), a migration continua segura no create inicial (o CHECK reflete os valores certos desde o início); o risco só nasceria se uma migration FUTURA precisasse adicionar um valor novo a esse enum.

**Convenção de nome de arquivo:** `YYYY_MM_DD_HHMMSS_create_adman_probe_margem_prev_leituras_table.php`, seguindo exatamente `2026_05_22_100001_create_mlb_sync_vendas_logs_table.php` (verbo `create` + nome da tabela + `_table`).

---

### `tests/Feature/Phase117/ProbeMargemPrevStabilityCommandTest.php` (novo teste)

**Análogo de estrutura de diretório:** convenção dominante do projeto é `tests/Feature/PhaseNN/` (ex.: `Phase106`, `Phase110`, `Phase111HubspotApiClientTest.php` no padrão flat, `Phase113HubspotDedupTest.php`) — **não** `tests/Feature/V21/` (esse padrão só existe para `V16`/`V18`, versões de milestone antigas). Usar `tests/Feature/Phase117/` é mais consistente com o padrão mais recente do repositório.

**Análogo de fixture HTTP:** `tests/Feature/V18/AdmanMetricDiffServiceTest.php` (`respostaAccountMetrics()` + `Http::fake()` — reusar a MESMA fixture, já que o probe lê o mesmo endpoint `/accounts/{custId}/metrics`).

**Análogo de estrutura de comando testado:** `app/Console/Commands/WarmAdmanDiffCache.php` não tem teste dedicado no repositório atual — mas o padrão de teste de Artisan Command mais próximo (assertar saída via `Artisan::call()`/`$this->artisan(...)`) é o convencional do Laravel; usar `$this->artisan('adman:probe-margem-prev', [...])->assertExitCode(0)` e depois consultar o banco via Eloquent (`AdmanProbeMargemPrevLeitura::count()`), nunca `expectsOutput()` como fonte de verdade (D-10: reconsulta ao dado persistido, nunca stdout).

**Esboço de teste, combinando os dois padrões:**
```php
<?php

namespace Tests\Feature\Phase117;

use App\Models\AdmanProbeMargemPrevLeitura;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProbeMargemPrevStabilityCommandTest extends TestCase
{
    use RefreshDatabase;

    private function respostaAccountMetrics(float $value, float $diff, float $prev): array
    {
        return [
            'metrics' => [
                'percentageMargin' => ['value' => $value, 'diff' => $diff, 'prev' => $prev],
            ],
        ];
    }

    public function test_leitura_persiste_uma_linha_por_empresa_com_forceRefresh(): void
    {
        Http::fake([
            '*/accounts/*/metrics*' => Http::response($this->respostaAccountMetrics(27.47, 14.09, 24.08), 200),
        ]);

        // Vincular Luiz (id fixo do CONTEXT) a uma empresa Adman elegível...
        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06'])->assertExitCode(0);

        $this->assertDatabaseCount('adman_probe_margem_prev_leituras', /* N empresas */ 1);
        $leitura = AdmanProbeMargemPrevLeitura::first();
        $this->assertSame(27.47, $leitura->value);
        $this->assertSame(24.08, $leitura->prev);
        $this->assertFalse($leitura->http_falhou);
    }

    public function test_falha_http_persiste_http_falhou_sem_abortar_a_leitura(): void
    {
        Http::fake(['*/accounts/*/metrics*' => Http::response([], 429)]);

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06'])->assertExitCode(0);

        $this->assertTrue(AdmanProbeMargemPrevLeitura::first()->http_falhou);
    }

    public function test_relatorio_detecta_flip_de_nota_entre_leituras(): void
    {
        // Persistir 2 leituras da MESMA empresa com nota_regua diferente...
        // Rodar --relatorio e assertar veredito reprovado via consulta ao banco
        // (nunca via expectsOutput — D-10).
    }
}
```

---

## Shared Patterns

### Fail-open por item em loops batch (`\Throwable` + log + contador)
**Fonte:** `app/Console/Commands/WarmAdmanDiffCache.php:70-83`
**Aplicar a:** `ProbeMargemPrevStability::handle()`
```php
try {
    // ... trabalho por empresa
    $ok++;
} catch (\Throwable $e) {
    $fail++;
    Log::warning('[TagDoComando] falhou', [
        'company_id' => $c->id,
        'error'      => $e->getMessage(),
    ]);
}
```

### Logging com tag entre colchetes, sem PII/credencial
**Fonte:** `app/Services/AdmanService.php` (múltiplas ocorrências), `app/Jobs/SyncShopeeCompanyJob.php`
**Aplicar a:** `ProbeMargemPrevStability` — tag sugerida `[AdmanProbeMargemPrev]`.
```php
Log::warning("[Adman] Campanhas empresa {$company->id}: " . $e->getMessage());
```
Confirmado: nenhum log do domínio inclui `ADMAN_API_KEY` ou headers — só IDs e mensagens de exceção.

### Migration com FK nullable + nullOnDelete (só quando a FK for opcional)
**Fonte:** `database/migrations/2026_05_22_100001_create_mlb_sync_vendas_logs_table.php:19`
**Aplicar a:** qualquer FK nova para `companies`/`users` que use `nullOnDelete()` — **nunca** sem `->nullable()` antes de `->constrained()` (erro 1830 no MariaDB de produção, não pego pelo SQLite dos testes locais).
```php
$table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
```

### Bump de cache key versionado com comentário datado
**Fonte:** `app/Services/Metrics/AdmanMetricDiffService.php:113-121`
**Aplicar a:** o próprio arquivo, no bump v5→v6 desta fase.
```php
// vN (YYYY-MM-DD): <motivo em 1 linha> — o bump invalida <o quê>.
```

### Régua de margem duplicada intencionalmente (documentar a duplicação)
**Fonte:** `app/Services/DesempenhoScoreService.php:1311-1319` (original) + docblock de classe de `AdmanMetricDiffService.php:32-39` (padrão de disclaimer de duplicação)
**Aplicar a:** cópia local no comando do probe — usar o MESMO texto de disclaimer ("duplicação TEMPORÁRIA e INTENCIONAL").

---

## No Analog Found

Nenhum arquivo desta fase ficou sem análogo — todos os 8 arquivos (3 editados + 3 criados + 2 testes) têm precedente direto e lido integralmente no codebase.

---

## Achados que corrigem o RESEARCH.md (repassar ao planner)

1. **`tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php` JÁ EXISTE** (189 linhas, 7 cenários) — o RESEARCH e o VALIDATION afirmam o contrário duas vezes e recomendam criar em `tests/Feature/V21/`. O executor deve EDITAR o arquivo existente em `tests/Unit/Metrics/`, não criar um novo.
2. **Há DUAS asserções `array_keys()` estritas que vão quebrar, não uma.** Além de `AdmanMetricDiffServiceTest.php:365-369` (já identificada pelo RESEARCH), `ShopeeMetricDiffServiceTest.php:180-182` (`test_shape_identico_ao_adman`) tem o MESMO padrão estrito e também precisa ser atualizado. Ambas as atualizações são acompanhamento de mudança de contrato, não mascaramento de regressão.
3. **Convenção de diretório de teste novo:** usar `tests/Feature/Phase117/` (padrão dominante recente: `Phase106`, `Phase110`, `Phase111*`, `Phase113*`), não `tests/Feature/V21/` (sugestão do VALIDATION.md, que usa nomenclatura de milestone antiga `V16`/`V18` já em desuso para fases novas).

---

## Metadata

**Escopo da busca de análogos:** `app/Services/Metrics/`, `app/Console/Commands/`, `app/Models/`, `database/migrations/`, `tests/Feature/V18/`, `tests/Unit/Metrics/`, `tests/Feature/` (listagem de convenção de diretórios)
**Arquivos lidos integralmente ou por seção-alvo:** `AdmanMetricDiffService.php` (3 seções), `ShopeeMetricDiffService.php` (1 seção), `AdmanMetricDiffServiceTest.php` (3 seções), `ShopeeMetricDiffServiceTest.php` (íntegro), `WarmAdmanDiffCache.php` (íntegro), `InspecionarAdman.php` (íntegro), `MlbSyncVendasLog.php` + migration (íntegros), `CarteiraContextService.php` (trecho `forUser`), `MetricPeriodResolver.php` (trecho `resolve` + 4 modos), `DesempenhoScoreService.php` (trecho `reguaMargem`), `AdmanService.php` (assinatura `fetchAccountMetricsDetailedCached` + grep de logs)
**Data de extração:** 2026-07-27
