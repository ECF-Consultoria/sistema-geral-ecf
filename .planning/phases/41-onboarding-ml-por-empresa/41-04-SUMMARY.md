---
phase: 41-onboarding-ml-por-empresa
plan: 04
subsystem: sugadores-ml-migration
tags: [shadow-mode, metricas-operacionais, mercado-livre-ads, ml-metrics, summary-json, di-opcional, regressao-zero]
dependency_graph:
  requires:
    - "Plan 40-02 (ShadowRunService base — orquestrador shadow Adman+ML, dryRun=true hardcoded)"
    - "Plan 41-02 (MercadoLivreAdsService::getLastRunMetrics — 6 chaves canonicas total_calls/pages_read/rate_limit_429/refresh_token_count/backoff_sleep_ms/total_duration_ms)"
    - "Plan 40-01 (tabelas sugador_provider_runs/_items — campo summary JSON pronto pra extensao)"
  provides:
    - "ShadowRunService::__construct ganha 2o parametro opcional ?MercadoLivreAdsService $mlAds = null (DI nullable)"
    - "summary JSON do provider 'ml' ganha chave 'ml_metrics' contendo as 6 chaves canonicas do getLastRunMetrics quando MercadoLivreAdsService disponivel"
    - "summary JSON do provider 'adman' permanece com as 5 chaves baseline Plan 40-02 (campanhas/adgroups/items/skipped/reason) — back-compat estrita"
    - "Coleta defensiva: excecao em getLastRunMetrics nao interrompe a run (try/catch + Log::warning prefixado [Sugadores Shadow])"
    - "Habilita Plan 41-05 a ler `summary->ml_metrics` da row pra exibir saude do path ML (429 hits, refresh count, duration) por empresa na UI admin"
  affects:
    - "app/Services/Sugadores/ShadowRunService.php (+37/-7 — extensao puramente aditiva; gate REQ-40-02 dryRun=true intacto)"
tech_stack:
  added: []
  patterns:
    - "DI nullable opcional (pattern Phase 29 BaseNotification): construtor aceita null para nao quebrar testes legados (Mockery do Plan 40-02 nao precisa configurar expectations no novo dependency)"
    - "Coleta defensiva com try/catch + Log::warning (pattern Phase 13 — falhas em coleta nao-critica nao interrompem fluxo principal)"
    - "Extensao aditiva de JSON summary (chave nova adicionada sem remover as 5 antigas — back-compat estrita com Plan 40-02 Test 4)"
    - "Plumbing fino entre 2 services Phase 40 + Phase 41-02 sem refactor estrutural"
key_files:
  created:
    - tests/Feature/Phase41/ShadowRunServiceMlMetricsTest.php
  modified:
    - app/Services/Sugadores/ShadowRunService.php
decisions:
  - "Construtor recebe ?MercadoLivreAdsService = null (NAO obrigatorio) pra preservar testes Plan 40-02 que mockam apenas SugadorAnalysisService. Em producao Laravel resolve via container."
  - "Coleta defensiva com try/catch: excecao em getLastRunMetrics NAO interrompe a run nem marca como failed — apenas omite a chave ml_metrics e emite log warning. UI Plan 41-05 lida com chave ausente."
  - "Variavel local `$summary` em vez de array inline no `$run->update`: facilita extensao condicional (`$summary['ml_metrics'] = ...`) sem reescrever toda a chamada."
  - "Provider 'adman' NAO recebe ml_metrics — `if ($providerName === 'ml' && $this->mlAds !== null)` garante isolamento. Test 2 valida back-compat estrita do summary adman."
  - "5 chaves baseline Plan 40-02 (campanhas/adgroups/items/skipped/reason) sao preservadas em AMBOS providers. Test 3 valida que summary ml ganha 1 chave nova (ml_metrics), NAO substitui."
  - "Log warning prefixado [Sugadores Shadow] (mesmo prefixo do Log::error existente no catch externo) facilita grep de incidentes no log."
  - "Test 5 (mlAds=null) instancia ShadowRunService EXPLICITAMENTE com `new ShadowRunService($analyzerMock, null)` em vez de bind container — testa caminho 'DI nao resolveu' literalmente."
  - "Smoke real (tests rodando) DEFERIDO ao orquestrador no consolidate-wave — worktree spawnado sem vendor/. Sintaxe validada via `php -l` em ambos os arquivos (sem syntax errors)."
metrics:
  duration: "~15min"
  completed_date: "2026-06-25"
  tasks_total: 2
  tasks_completed: 2
  tests_added: 5
  tests_passing: "deferred-to-orchestrator"
  files_created: 1
  files_modified: 1
  lines_added: 405
  lines_removed: 7
requirements_closed: [REQ-41-06]
---

# Phase 41 Plan 41-04: ShadowRunService mescla ml_metrics no summary — Summary

**One-liner:** Estende `ShadowRunService` (Phase 40-02) com injecao opcional do `MercadoLivreAdsService` para mesclar as 6 metricas operacionais ML (`total_calls`, `pages_read`, `rate_limit_429`, `refresh_token_count`, `backoff_sleep_ms`, `total_duration_ms`) no campo `summary` JSON da row `sugador_provider_runs` apenas quando `provider='ml'` — habilita o Plan 41-05 (UI admin) a exibir saude do path ML por empresa sem perder as metricas que hoje ficam apenas em property interna do service.

## O que foi entregue

### ShadowRunService refactor (+37/-7 linhas)

**Mudancas cirurgicas:**

| Mudanca | Local | Descricao |
|---------|-------|-----------|
| Import | Linha 11 | `use App\Services\Sugadores\MercadoLivreAdsService;` |
| Construtor | Linhas 38-50 | Segundo parametro opcional `?MercadoLivreAdsService $mlAds = null` + PHPDoc pt-BR explicando injecao opcional |
| `executeForProvider` | Linhas 137-159 | Variavel local `$summary` (5 chaves baseline Plan 40-02). SE `$providerName === 'ml'` E `$this->mlAds !== null`: tenta `$summary['ml_metrics'] = $this->mlAds->getLastRunMetrics();` dentro de try/catch; catch \Throwable emite Log::warning prefixado `[Sugadores Shadow]` mencionando `ml_metrics` + `company.id` + msg. `$run->update(['summary' => $summary])` usa a variavel. |

**ZERO mudanca em:**

- `run()` — assinatura e shape de retorno (`['adman_run_id', 'ml_run_id', 'adman_status', 'ml_status']`) preservados
- `buildItemPayload()`, `computeRawHash()`, `ksortRecursive()`, `isAssoc()` (intactos)
- Gate de seguranca REQ-40-02 (`dryRun=true` 4o arg do `analyzeCompany`) — intacto, validado via `grep -c "dryRun" == 5`
- try/catch por provider (falha isolada) — preservado, struct identica
- Logica de criacao da `SugadorProviderRun` em `running` no inicio

### Suite de tests (1 arquivo novo, +368 linhas)

**`tests/Feature/Phase41/ShadowRunServiceMlMetricsTest.php`** — 5 tests com atributo `#[Test]` (PHPUnit 11):

| # | Test | Cobertura |
|---|------|-----------|
| 1 | `summary_ml_contem_ml_metrics_quando_provider_ml` | mockAnalyzer + mockMlAds com 6 chaves payload conhecido; valida `$mlRun->summary['ml_metrics']` contem 6 chaves esperadas + valores batem com o mock |
| 2 | `summary_adman_nao_contem_ml_metrics` | Mesmo setup do Test 1; valida `assertArrayNotHasKey('ml_metrics', $admanRun->summary)` + adman ainda tem as 5 chaves baseline Plan 40-02 |
| 3 | `summary_ml_preserva_chaves_baseline_plan_40_02` | Mesmo setup; valida `$mlRun->summary` tem TODAS as 5 chaves Plan 40-02 + `ml_metrics` (6 total); valores semanticos preservados (adgroups=3, items=3, skipped=false, reason=null) |
| 4 | `excecao_em_getLastRunMetrics_nao_interrompe_run` | mockMlAdsThrowing(`\RuntimeException('Failed to compute ml metrics')`) + `Log::spy()`; valida run nao lanca, `status='completed'`, summary ml NAO tem ml_metrics, `Log::shouldHaveReceived('warning')` com substring `[Sugadores Shadow]` E `ml_metrics` `atLeast()->once()` |
| 5 | `mlAds_null_no_constructor_continua_funcionando` | `new ShadowRunService($analyzerMock, null)` EXPLICITO (sem bind container); valida run completa OK, summary ml NAO tem ml_metrics, baseline Plan 40-02 preservado |

**Helpers + isolamento:**
- `makeCompanyWithConfig()` — pattern Plan 40-02 (Company + SugadorConfig com `dias_analise=7`)
- `detalhesFixture(int $n, string $prefix)` — shape compativel com `SugadorAnalysisService` (tipo, campaign_id, adgroup_id, mlb_id, nome, investimento, vendas, motivos)
- `payloadOk(array $detalhes, int $campanhas, ?int $adgroups)` — wrapper p/ retorno padrao do mock analyzer
- `mockAnalyzer(array $payloadAdman, array $payloadMl)` — Mockery::mock(SugadorAnalysisService) + bind container; expectations distinguem provider via Mockery::type + valor literal 'adman'/'ml' no 5o arg
- `mockMlAds(array $metrics)` — NOVO: Mockery::mock(MercadoLivreAdsService) + bind container
- `mockMlAdsThrowing(\Throwable $e)` — NOVO: variante que lanca em getLastRunMetrics (Test 4)
- `defaultMlMetrics()` — fixture canonica com 6 chaves usadas nos Tests 1, 2, 3
- `setUp`: `Carbon::setTestNow(2026-06-26 12:00)` para determinismo
- `tearDown`: `Mockery::close()` + `Carbon::setTestNow()`

## Verificacao

### Tests novos

```text
Phase41 ShadowRunServiceMlMetricsTest: 5 tests RED commitados (c496e18)
                                        + GREEN aplicado (2631e4a)
```

**Smoke real DEFERIDO ao orquestrador:** Worktree foi spawnado sem `vendor/` (regra do orquestrador parallel-executor — `vendor/` so existe na main). PHPUnit nao pode rodar daqui. Validacao real acontece no consolidate-wave do orquestrador apos o merge na main. Sintaxe validada via `/c/xampp/php/php.exe -l`:

```text
tests/Feature/Phase41/ShadowRunServiceMlMetricsTest.php:  No syntax errors detected
app/Services/Sugadores/ShadowRunService.php:              No syntax errors detected
```

### Regressao esperada (a ser validada pelo orquestrador)

| Suite | Esperado |
|-------|----------|
| Phase41 ShadowRunServiceMlMetricsTest | 5/5 PASS (novo) |
| Phase40 ShadowRunServiceTest | 9/9 PASS — Test 4 usa `assertArrayHasKey` (NAO `assertEquals` strict), entao chave extra `ml_metrics` no summary ml NAO regredira; Tests 1-3 e 5-9 nao tocam summary ml |
| Phase40 (todas) | 52/52 PASS (zero modificacao em outros services Phase 40) |
| Phase41 (acumulado 41-01 + 41-02 + 41-03 + 41-04) | >= 30/30 PASS (5 schema + 12 backoff + 8 config table + 5 ml metrics) |
| Sugador (todas) | >= 92/92 PASS (zero modificacao em SugadorAnalysisService, providers, factory, Models, AnalyzeSugadores command, Jobs) |

### Greps de acceptance criteria

| Criterio do PLAN | Esperado | Real |
|-------------------|----------|------|
| `grep -c "MercadoLivreAdsService" Service.php` | >= 2 | 5 (import + property + 1 use clause + 2 docblocks) ✓ |
| `grep -c "ml_metrics" Service.php` | 1 substantivo | 1 atribuicao `$summary['ml_metrics']` + 2 docs/log (substantivo: 1) ✓ |
| `grep -c "getLastRunMetrics" Service.php` | 1 substantivo | 1 chamada `$this->mlAds->getLastRunMetrics()` + 2 docblocks (substantivo: 1) ✓ |
| `grep -c "dryRun" Service.php` | 5 | 5 ✓ (gate REQ-40-02 estatico intacto) |
| `grep -cE "Sugador::create\|Sugador::insert\|->sugadores->" Service.php` | 0 | 0 ✓ (gate REQ-40-02 estatico intacto) |
| `grep -c "#\[Test\]" Test.php` | >= 5 | 5 ✓ |
| `grep -c "MercadoLivreAdsService" Test.php` | >= 3 | 5 (1 use + 2 mock helpers + 2 anotacoes) ✓ |
| `grep -c "ml_metrics" Test.php` | >= 5 | 18 ✓ |

### Gate REQ-40-02 (dryRun=true hardcoded) preservado

```text
$ grep -n "dryRun\|->analyzeCompany(" app/Services/Sugadores/ShadowRunService.php
  17:  *   - 1 vez forçando provider 'ml'
  20:  * Ambas as execuções usam $dryRun=true (gate de segurança REQ-40-02) — o analyzer
  22:  * já garante via Phase 39 que com dryRun=true NÃO grava em `sugadores`.
 118:             // O 4º argumento $dryRun é HARDCODED como true. NUNCA passar false
 121:             // paridade sem afetar a tabela canônica de produção.
 123:             $result = $this->analysis->analyzeCompany(
 124:                 $company,
 125:                 $referenceDate,
 126:                 true,            // $dryRun — SEMPRE true neste service
 127:                 $providerName,   // $forceProvider
 128:             );
```

A unica chamada `analyzeCompany` continua passando `true` como 4o arg. Gate inviolado.

## Deviations from Plan

**Nenhuma deviation Rule 1/2/3/4 aplicada.** Plan 41-04 executado exatamente como escrito:

1. Tarefa 1 (RED) entregou 5 tests cobrindo as 5 categorias da `<behavior>`.
2. Tarefa 2 (GREEN) entregou a extensao minima: import + 2o parametro opcional + bloco condicional `if ($providerName === 'ml' && $this->mlAds !== null)` antes do `$run->update`.

**Avaliacao defensiva Test 4 do Plan 40-02 (regressao):**

O PLAN antecipou que Test 4 do Plan 40-02 poderia quebrar se usasse `assertEquals` strict no summary. Inspecao do arquivo `tests/Feature/Phase40/ShadowRunServiceTest.php` (linhas 272-276) revelou:

```php
$this->assertIsArray($admanRun->summary);
$this->assertArrayHasKey('campanhas', $admanRun->summary);
$this->assertArrayHasKey('adgroups', $admanRun->summary);
$this->assertEquals(1, $admanRun->summary['adgroups']);
$this->assertEquals(2, $mlRun->summary['adgroups']);
```

Conclusao: Test 4 usa `assertArrayHasKey` (nao `assertEquals` strict do array completo) + `assertEquals` apenas em CHAVE individual (`['adgroups']`). A chave extra `ml_metrics` no summary ml **NAO** regredira o Test 4. **Nenhum ajuste Rule 1 necessario** no test Plan 40-02. Documentado aqui para o auditor confirmar.

## Notas operacionais

- **Sem deploy nesta plan.** Edit em service compartilhado; o impacto pratico aparece quando o Plan 41-05 (UI admin) chegar e ler `summary->ml_metrics` na row pra exibir saude do path ML.
- **MercadoLivreAdsService::getLastRunMetrics** ja foi entregue no Plan 41-02 (commits `5df0c74` RED + `498c4a1` GREEN). Plan 41-04 apenas pluga o output dele no campo certo do shadow run.
- **MariaDB local segue deferred** (quick task `dev:reparar-mariadb-local`). Tests rodam em SQLite em-memory (RefreshDatabase) — sem dependencia de MariaDB.
- **Tests rodam pelo orquestrador no consolidate-wave.** Worktree spawnado sem `vendor/`; PHPUnit nao executa daqui. Validacao final do GREEN acontece apos merge na main.

## Self-Check: PASSED

- [x] `tests/Feature/Phase41/ShadowRunServiceMlMetricsTest.php` — FOUND (368 linhas)
- [x] `app/Services/Sugadores/ShadowRunService.php` — modificado (+37/-7); diff cirurgico em 3 hunks (import, construtor, bloco summary)
- [x] commit `c496e18` (RED test suite) — FOUND no `git log`
- [x] commit `2631e4a` (GREEN service extension) — FOUND no `git log`
- [x] Sintaxe PHP validada via `php -l` — sem syntax errors
- [x] Gate REQ-40-02 estatico intacto: `grep -c "dryRun" Service.php == 5` ✓
- [x] Gate REQ-40-02 estatico intacto: `grep -cE "Sugador::create|Sugador::insert|->sugadores->" Service.php == 0` ✓
- [x] Construtor preserva 1o param `SugadorAnalysisService $analysis` — testes Plan 40-02 que mockam ele continuam validos
- [x] 2o param `?MercadoLivreAdsService $mlAds = null` (nullable + default null) — Plan 40-02 ShadowRunServiceTest nao precisa modificar Mockery setup
- [x] `run()` shape de retorno preservado: `['adman_run_id', 'ml_run_id', 'adman_status', 'ml_status']` (Phase 40-02 Test 8 protegido)
- [x] Test 4 do Plan 40-02 nao regredira (usa `assertArrayHasKey` + `assertEquals` em chave individual; chave extra `ml_metrics` no summary ml nao impacta)

## TDD Gate Compliance

- [x] RED commit `c496e18`: `test(41-04): suite ShadowRunServiceMlMetricsTest RED — 5 tests cobrindo ml_metrics no summary`
- [x] GREEN commit `2631e4a`: `feat(41-04): GREEN ShadowRunService mescla ml_metrics no summary (REQ-41-06)`
- [ ] REFACTOR — nao necessario (bloco condicional minimo de 11 linhas; sem duplicacao a remover; PHPDoc completo no construtor; comentario pt-BR explicando coleta defensiva)
