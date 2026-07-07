---
phase: 60
verified_at: 2026-07-07
status: passed
score: 4/4 must-haves verified
requirements_covered: [DATA-04, DATA-06]
success_criteria_covered: [1, 2, 3, 4]
overrides_applied: 0
gaps: []
deferred:
  - truth: "Testes Phase18/CompaniesCustIdFilterTest 2 falhas pré-existentes"
    addressed_in: "Fora do escopo v14.0 — DEF-60-02-01 registrado"
    evidence: "deferred-items.md confirma falha existe com e sem código Phase 60 no working tree; stack aponta para vendor/inertiajs macro, colateral de mudanças untracked em CompanyController.php (portfolio access — não Phase 60)"
  - truth: "Timeout 300s em MercadoLivreAdsService.php:215 na suite Feature completa"
    addressed_in: "Fora do escopo v14.0 — DEF-60-02-02 registrado"
    evidence: "Código em app/Services/Sugadores/ (Phase 39-40), não app/Services/Metrics/"
---

# Phase 60 — Base Multi-Fonte (Backend ML+Adman Unificado) — Verificação

**Phase Goal:** Estabelecer camada de leitura unificada sobre `company_marketplaces` que suporta empresa fonte-ML, fonte-Adman ou ambas, com regra de precedência explícita e testável.
**Verificado:** 2026-07-07
**Status:** passed
**Re-verificação:** No — verificação inicial

## Success Criteria (ROADMAP) — 4/4 verificados

### SC #1 — Cálculo agregado lê Adman E ML sem quebrar nos 3 casos

**Status:** VERIFIED

**Evidência executável:**
```bash
$ /c/xampp/php/php.exe artisan test tests/Feature/Phase60
Tests: 46 passed (188 assertions)
Duration: 19.80s
```

Os 3 casos ADR DATA-04 estão explicitamente nomeados nos métodos de teste (grep confirmou):
- `test_caso_so_adman_retorna_dto_com_source_adman` — caso só-Adman
- `test_caso_so_ml_retorna_dto_com_source_ml` — caso só-ML
- `test_caso_ambos_retorna_dto_com_source_unified` — caso ambos
- `test_caso_none_retorna_dto_source_none_todos_campos_null` — sentinela none

Cobertura adicional: `test_caso_ambos_ml_dita_revenue_sobre_adman`, `test_caso_ambos_adman_dita_net_billing_sobre_ml`, `test_caso_ambos_ml_com_campo_null_cai_pro_adman`, `test_caso_ambos_tacos_divergente_gera_log_warning`, `test_caso_ambos_tacos_dentro_de_5pct_nao_gera_log`, `test_readforcompany_nunca_lanca_exception_com_provider_com_erro`, `test_source_do_dto_por_caso_exato` (dict-driven matrix cobrindo os 4 casos).

Nenhum teste falha, nenhum teste é `markTestSkipped` — as ocorrências de `markTestSkipped` no `BaselineRegressionTest.php` são strings de mensagem de erro, não chamadas de skip.

### SC #2 — Empresas em AMBAS fontes conciliadas sem duplicação + ADR versionado

**Status:** VERIFIED

**Evidência:**
- ADR presente em `.planning/adrs/DATA-04-precedencia-multifonte.md` — 334 linhas
- Frontmatter `id: DATA-04`, `status: accepted`, `date: 2026-07-07`
- Tabela campo-a-campo com **15 rows** (grep `^\| \`[a-z_]+\`\s+\|` = 15) — cobre 100% dos 15 campos numéricos do `UnifiedMetricsDto`
- Seção "Detecção do caso" no linha 112 do ADR — declara uso de `is_ml_driven` + `adman_account_id` (denormalizado, NÃO pivot)
- 4 valores de `source` enumerados no ADR (`'adman'`, `'ml'`, `'unified'`, `'none'`) e no código (`UnifiedMetricsService.php`: 13 ocorrências totais)
- Regra "ML dita, Adman enriquece" implementada em `UnifiedMetricsService::mergeFields()`:
  ```php
  revenue:       $ml->revenue       ?? $adman->revenue,
  net_billing:   $adman->net_billing,   // ML não expõe
  acos:          $ml->acos,             // Adman não expõe
  ```
- Feature flag `UNIFIED_METRICS_ENABLED` declarado no ADR seção "Rollout e feature flag" (linha ~298); ainda não usado em runtime porque Phase 60 entrega apenas infra — Phase 61 é quem migra consumidores e ativa a flag

### SC #3 — Testes automatizados nos 3 casos com RefreshDatabase

**Status:** VERIFIED

**Evidência:**
- Todos os 5 arquivos de teste em `tests/Feature/Phase60/` usam `use RefreshDatabase;` (grep confirmou 10 matches = trait usage + import por arquivo × 5)
- Nomes de teste explicitamente por caso: `test_caso_so_adman`, `test_caso_so_ml`, `test_caso_ambos`, `test_caso_none` (grep retornou 9 correspondências no `UnifiedMetricsServiceTest.php`)
- Fixtures mínimas via helpers `makeCompanySoAdman()`, `makeCompanySoMl()`, `makeCompanyAmbos()`, `makeCompanyNone()` — factory de Company + `AdmanMetric::create` direto + mock de `MercadoLivreService` via `$this->app->instance()`

### SC #4 — Delta regressão = 0 na suite baseline

**Status:** VERIFIED

**Evidência executável:**
```bash
$ git diff f847716..HEAD --stat -- app/Http/Controllers/ app/Services/AdmanService.php app/Services/MercadoLivreService.php
(sem output = 0 linhas alteradas)
```

`f847716` é o commit anterior ao início da Phase 60 (`docs: tag 3 pending todos with resolves_phase after milestone v14.0 roadmap`); HEAD é `d8ba308` (PHASE-SUMMARY 60-04).

**Delta app/ completo da Phase 60:**
```
app/Contracts/MetricsProvider.php               |  84 ++++++++
app/Services/Metrics/AdmanMetricsProvider.php   | 201 ++++++++++++++++++
app/Services/Metrics/MetricsProviderFactory.php | 106 ++++++++++
app/Services/Metrics/MlMetricsProvider.php      | 263 ++++++++++++++++++++++++
app/Services/Metrics/UnifiedMetricsDto.php      | 126 ++++++++++++
app/Services/Metrics/UnifiedMetricsService.php  | 258 +++++++++++++++++++++++
6 files changed, 1038 insertions(+)
```

Todos os arquivos são **novos** — zero modificação em consumidores legados. Baseline pass adicional: `BaselineRegressionTest` (6/6 verdes) prova em runtime que rotas legadas `dashboard`, `admin.financeiro`, `companies.show`, `portfolio.own` ainda retornam 200; que query bruta agregada em `adman_metrics` continua íntegra; e que `AdmanService` legado + `AdmanMetricsProvider` novo coexistem no container Laravel.

**Observação sobre working tree:** existe modificação em `CompanyController.php` (33 linhas, adiciona `userCanViewCompany` + `userIsCompanyEstrategista` + permission checks). Essa mudança é **não relacionada à Phase 60** — refere-se a autorização para portfolio access (arquivo `tests/Feature/CompanyPortfolioAccessTest.php` untracked confirma o work stream paralelo). PHASE-SUMMARY explicitamente disclaimou.

## Requirements — 2/2 cobertos

### DATA-04 — Métricas multi-fonte sem quebrar em 3 casos

**Status:** SATISFIED

**Evidência:**
- ADR canonical em `.planning/adrs/DATA-04-precedencia-multifonte.md`
- Implementação em `UnifiedMetricsService::mergeFields()` + `MetricsProviderFactory::caseFor()`
- Cobertura de teste: T1-T3 do `UnifiedMetricsServiceTest` (nomes explícitos por caso) + `MetricsProviderFactoryTest` (5 casos incluindo `case_for_retorna_string_correta_por_caso`)

### DATA-06 — Conciliação sem duplicação + ADR versionado

**Status:** SATISFIED

**Evidência:**
- ADR versionado em `.planning/adrs/DATA-04-precedencia-multifonte.md` com tabela campo-a-campo (15 rows) definindo precedência **sem duplicação** (cada campo tem uma única fonte primária + fallback declarado)
- Fusão implementada com `??` (null coalescing) em `mergeFields()` — impossível duplicar valor: ou ML dita, ou fallback Adman preenche quando ML é null
- Cobertura de teste T4/T5/T6 (`ml_dita_revenue_sobre_adman`, `adman_dita_net_billing_sobre_ml`, `ml_com_campo_null_cai_pro_adman`)

## Anti-shallow verification — spot-checks executados

| Check | Comando | Resultado |
|-------|---------|-----------|
| Suite Phase60 verde | `php artisan test tests/Feature/Phase60` | 46 passed / 188 assertions — bate com PHASE-SUMMARY |
| UnifiedMetricsService existe + readForCompany | `test -f && grep -q readForCompany` | EXISTS + HAS_readForCompany |
| Case 'unified' implementado | `grep -q "'unified'"` | HAS_unified |
| Case 'none' sentinela existe | `grep -q "'none'"` | HAS_none |
| Log divergência TACOS implementado | `grep "TACOS divergente"` | Linha 218: `Log::warning('[UnifiedMetrics] TACOS divergente', [...])` |
| Nomes de teste por caso ADR | `grep test_caso_so_adman\|test_caso_so_ml\|test_caso_ambos\|test_caso_none` | 9 matches (todos os casos nomeados) |
| `markTestSkipped` em Baseline test | `grep markTestSkipped` | 5 ocorrências — **todas em strings de erro** (`fail()` messages), nenhuma chamada real de skip |
| RefreshDatabase em todos os 5 testes | `grep RefreshDatabase` | 5/5 arquivos usam a trait |
| Delta legacy = 0 | `git diff f847716..HEAD --stat -- <legacy>` | 0 bytes / 0 arquivos modificados |
| Entidades novas Phase 60 | `git diff f847716..HEAD --stat -- app/` | 6 novos arquivos, 1038 inserções, **0 modificações em legacy** |
| ADR 15 rows na tabela precedência | `grep count "^| \`[a-z_]+\`\s+\|"` | 15 = número exato de campos numéricos do DTO |
| ADR 4 valores de source | `grep count "'adman'\|'ml'\|'unified'\|'none'"` | 11 ocorrências (redundância intencional em várias seções do ADR) |
| ADR "Detecção do caso" presente | `grep "Detecção do caso"` | Linha 112 |

## Coexistência com legado

`git diff f847716..HEAD --stat -- app/Http/Controllers/DashboardController.php app/Http/Controllers/AdminController.php app/Http/Controllers/PortfolioController.php app/Http/Controllers/CompanyController.php app/Services/AdmanService.php app/Services/MercadoLivreService.php`

Resultado: **sem output** → 0 linhas alteradas. Coexistência 100% comprovada — Phase 60 não tocou nenhum consumidor legacy. Comitado em `d8ba308` (HEAD atual).

## Deferred / riscos residuais

Ambos itens abaixo estão registrados em `.planning/phases/60-base-multi-fonte-backend-ml-adman-unificado/deferred-items.md` como pré-existentes e fora do escopo da Phase 60. **Não bloqueiam esta verificação.**

1. **DEF-60-02-01** — Falhas pré-existentes em `Phase18/CompaniesCustIdFilterTest` (2 testes: `test_filtro_invalido_retorna_apenas_invalidas`, `test_sem_filtro_retorna_todas_as_empresas`). Stack aponta pra `vendor/inertiajs/inertia-laravel/src/Testing/TestResponseMacros.php:26` — provável colateral de mudanças untracked em `CompanyController.php` (autorização portfolio, work stream paralelo). Sugestão: quick task ou próxima phase de manutenção de testes.

2. **DEF-60-02-02** — Timeout 300s em `MercadoLivreAdsService.php:215` na suite Feature completa. Código em `app/Services/Sugadores/` (Phase 39-40), 100% alheio ao escopo backend metrics.

## Veredito

**Phase 60 atendeu integralmente os 4 Success Criteria do ROADMAP e cobriu 2/2 requirements (DATA-04, DATA-06).**

- Backend multi-fonte ML+Adman implementado: Contract `MetricsProvider` + DTO imutável `UnifiedMetricsDto` (19 props) + 2 providers (Adman DB-read + ML LIVE cache 15min) + Factory + `UnifiedMetricsService` orquestrador.
- ADR canonical DATA-04 documentando decisão + tabela precedência campo-a-campo + 4 valores de `source` travados.
- 46 testes verdes / 188 assertions (mesmo número do PHASE-SUMMARY — nada inflado).
- Regressão zero em consumidores legados (diff = 0 nos 6 arquivos legacy alvo).
- Deferred items registrados são pré-existentes e não relacionados ao objetivo desta phase.

Ready for Phase 61 (dashboards multi-fonte + badge de origem).

---

_Verificado: 2026-07-07_
_Verificador: Claude (gsd-verifier)_
