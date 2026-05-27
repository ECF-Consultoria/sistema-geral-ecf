---
slug: dashboard-sync-inconsistente-v2
status: resolved
trigger: "Pós-Phase-14: (1) algumas empresas cadastradas não exibem números no dashboard (sintoma NOVO desde 2026-05-26); (2) possivelmente ainda há oscilação em cards/controllers fora do escopo do fix anterior (AdminController, CompanyController)."
created: 2026-05-26
updated: 2026-05-27
resolved: 2026-05-27
goal: find_and_fix
predecessor: dashboard-sync-inconsistente
---

# Debug Session: dashboard-sync-inconsistente-v2

## Symptoms

**Sintoma 1 — oscilação** (recorrente do debug anterior em locais não cobertos): valores de faturamento/cobrança no Fechamento oscilam entre requests para empresas com `ml_store_id` + `adman_account_id` ambos preenchidos.

**Sintoma 2 — empresas sem números** (NOVO desde 2026-05-26): empresas com APENAS `ml_store_id` (sem `adman_account_id`) aparecem zeradas no Dashboard.

## Hypotheses (resultado final)

1. **H-NOVO-1** — CONFIRMADA (raiz comum dos dois sintomas).
2. **H-NOVO-2** (Phase 14 regrediu DashboardController) — REFUTADA: `git log 0906ee4..HEAD -- DashboardController.php` vazio.
3. **H-NOVO-3** (AdminController mistura por empresa) — CONFIRMADA + corrigida.
4. **H-NOVO-4** (cache inconsistente pós-drop) — REFUTADA: job/service não tocados pela Phase 14.
5. **H-REGRESSAO-FIX-ANTERIOR** — REFUTADA: DashboardController intacto desde `0906ee4`.

## Evidence

- timestamp: 2026-05-26T23:55:00Z
  observation: "Git log entre 0906ee4 (fix dashboard) e HEAD: nenhum commit modificou DashboardController.php."
  source: "git log 0906ee4..HEAD -- app/Http/Controllers/DashboardController.php"
  refutes: "H-NOVO-2 e H-REGRESSAO-FIX-ANTERIOR"

- timestamp: 2026-05-26T23:58:00Z
  observation: "RefreshGrossBillingCacheJob filtrava só adman_account_id e usava \$c->adman_account_id como cache key. Empresas com apenas ml_store_id nunca eram processadas pelo job."
  source: "app/Jobs/RefreshGrossBillingCacheJob.php:60-82 (pré-fix)"
  proves: "Sintoma 2"

- timestamp: 2026-05-26T23:59:00Z
  observation: "AdminController::fechamento cache batch read usava pluck('adman_account_id') mas lookup era por ml_store_id ?: adman_account_id. Para empresa com ml_store_id set, cache miss perpétuo."
  source: "app/Http/Controllers/AdminController.php:192-216 (pré-fix)"
  proves: "Sintoma 1 em Fechamento"

- timestamp: 2026-05-27T00:00:00Z
  observation: "AdmanService::syncCompany usa ml_store_id ?: adman_account_id como custId canônico. Sync DB é coerente; falta era no cache layer."
  source: "app/Services/AdmanService.php:66, 627"
  proves: "Canonicalização correta é ml_store_id ?: adman_account_id"

- timestamp: 2026-05-27T00:01:00Z
  observation: "Fallback ml_store_id introduzido em 7b7a2a9 (25/05/2026, antes do fix 0906ee4). Fix dashboard não atualizou cache writer; bug latente desde 7b7a2a9."
  source: "git log -S 'ml_store_id ?: \$c->adman_account_id'"
  proves: "Bug pré-existente, exposto pelos cadastros via Comercial (Phase 13/14)"

- timestamp: 2026-05-27T00:30:00Z
  observation: "Smoke test do accessor cust_id: ml_store_id='ML123' → 'ML123'; adman_account_id='AD999' → 'AD999'; ambos → 'ML' (preferência ml_store_id); nenhum → null; ml_store_id='' + adman_account_id='AD' → 'AD'."
  source: "php artisan tinker --execute"
  proves: "Semântica do accessor bate com AdmanService::syncCompany"

## Eliminated

- H-NOVO-2 (Phase 14 regrediu DashboardController)
- H-NOVO-4 (cache inconsistente pós-drop)
- H-REGRESSAO-FIX-ANTERIOR

## Resolution

### Root Cause

**Cache key (`$custId`) inconsistente entre writer e readers do faturamento Adman.**

| Componente | Resolução custId pré-fix | Comportamento |
|---|---|---|
| `RefreshGrossBillingCacheJob` (writer) | `adman_account_id` apenas | Não warm-a empresas só-ml_store_id |
| `DashboardController` (reader) | `adman_account_id` apenas | Exclui empresas só-ml_store_id (`if !$c->adman_account_id continue`) |
| `AdminController::fechamento` (reader) | `ml_store_id ?: adman_account_id` | Cache miss perpétuo p/ empresas com ml_store_id set |
| `AdminController::gerarRelatorio` / `gerarRelatorioGeral` | `adman_account_id` apenas | Empresas só-ml_store_id sem faturamento Adman |
| `CompanyController::show` (reader) | `adman_account_id` apenas | Empresas só-ml_store_id sem faturamento Adman na página da empresa |
| `AdmanService::syncCompany` (DB writer) | `ml_store_id ?: adman_account_id` | Canônico — usa o ID correto para a API |

Resultados visíveis:
1. **Sintoma 2 (empresas sem números)**: empresa com apenas `ml_store_id` set era silenciosamente excluída do dashboard (`if (!$c->adman_account_id) continue`) — tinha dados em `adman_metrics` (sync escreve via FK company_id), mas a UI mostrava zerado.
2. **Sintoma 1 (oscilação) no Fechamento**: empresa com `ml_store_id` set tinha cache key gerado pelo job (`adman_account_id`) ≠ cache key consultado pelo controller (`ml_store_id`). Cache miss perpétuo → fallback DB. Empresas só-`adman_account_id` continuavam pegando cache. Mistura por empresa → oscilação.

### Fix Applied — Canonicalização via `Company::cust_id`

Adicionado accessor `Company::getCustIdAttribute()` que devolve `ml_store_id ?: adman_account_id` (mesma semântica do `AdmanService::syncCompany`). Todos os call-sites do cache de faturamento Adman trocados para usar `$company->cust_id`.

### Files Changed

- `app/Models/Company.php`
  - **NEW** accessor `getCustIdAttribute(): ?string` (linhas ~39-58) — fonte única da verdade para o custId Adman.
- `app/Jobs/RefreshGrossBillingCacheJob.php`
  - Filtro de empresas aceita `ml_store_id OR adman_account_id` (igual `AdmanService::syncAll`).
  - Cache key via `$c->cust_id`.
  - Carrega `ml_store_id` no select de colunas.
- `app/Http/Controllers/DashboardController.php`
  - `adminDashboard()` e `userDashboard()`: pluck por `cust_id`, lookup por `cust_id`, condições `if (!$c->cust_id)`. Política tudo-ou-nada preservada.
- `app/Http/Controllers/AdminController.php`
  - `fechamento()`: pluck por `cust_id`, `$custId = $c->cust_id`.
  - `gerarRelatorio()`: pluck por `cust_id`, `$faturamentoOf` usa `$emp->cust_id`.
  - `gerarRelatorioGeral()`: pluck por `cust_id`, `$faturamentoOf` usa `$emp->cust_id`.
- `app/Http/Controllers/CompanyController.php`
  - `show()`: usa `$company->cust_id` para chamar `fetchGrossBilling`/`fetchAccountMetricsCached`.

### Verification

1. `php -l` em todos os 5 arquivos → No syntax errors.
2. `npm run build` → 0 erros JS; build em 9.63s.
3. `php artisan test --filter="Phase14AdminControllerCobrancaTest|Phase14FechamentoUiTest"` → 6 testes verdes que exercitam `AdminController::fechamento` (incluindo `financeiro companies inclui chave servicos_contratados`, `financeiro cobranca mensal soma contratos a faixa`).
   - 1 falha pré-existente (`Phase14AdminControllerCobrancaTest::fechamento_props_inclui_servicos_contratados`) — esperava `service_type` que foi dropada na Phase 14, sem relação com este fix.
4. Smoke-test do accessor `cust_id` para 5 combinações de input (ml-only, adman-only, both, neither, empty-ml).

### Verification Plan (runtime — pós-deploy em produção)

1. Empresas com apenas `ml_store_id` aparecem com faturamento real no dashboard e no Fechamento (não mais zeradas).
2. Após o próximo ciclo do `RefreshGrossBillingCacheJob` (até 30min), recarregar Fechamento 3-5x: valores devem manter-se estáveis para empresas com ml_store_id (antes oscilavam entre cache hit/DB SUM).
3. Log `[RefreshAdmanCache] empresas=N` deve mostrar contagem alinhada com `WHERE active AND (ml_store_id NOT NULL OR adman_account_id NOT NULL)`.

### Files NOT Touched (escopo)

- `app/Services/AdmanService.php` — já usa `ml_store_id ?: adman_account_id` corretamente em `syncCompany` (linha 66) e `syncMonthRevenue` (linha 627). Sem ação necessária.
- `resources/js/Pages/*` — sem mudança de payload (chaves UI preservadas: `adman_account_id`, `ml_store_id` continuam expostas; `cust_id` é interno PHP).
- Activity log — accessor não persiste no DB, não afeta `logOnly` config.

### Risks / Side-Effects

- **Aumento marginal de chamadas Adman no warm-up**: o job agora processa também empresas só-ml_store_id (antes ignoradas). Throttle de 1.5s entre chamadas preserva o limite Adman; impacto: tempo total do job +N×3s para N empresas só-ml_store_id (geralmente <10).
- **Empresas "ambíguas"** (com ambos ml_store_id e adman_account_id set, valores diferentes): agora usam `ml_store_id` consistentemente. Se algum dado histórico foi cacheado por `adman_account_id` antes do fix, ficará órfão até o TTL expirar (60min). Aceito.
