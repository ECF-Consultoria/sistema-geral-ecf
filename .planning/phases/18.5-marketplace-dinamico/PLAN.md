---
phase: 18.5-marketplace-dinamico
plan: 01
type: execute
wave: 1
mode: mvp
language: pt-BR
depends_on: []
files_modified:
  - database/migrations/2026_06_02_190000_add_marketplace_to_companies.php
  - app/Models/Company.php
  - app/Console/Commands/ImportMarketplaceFromCsv.php
  - app/Services/AdmanService.php
  - app/Jobs/RefreshGrossBillingCacheJob.php
  - app/Jobs/SyncTodasVendasAdmanJob.php
  - app/Jobs/SyncAdmanCompanyJob.php
  - app/Jobs/SyncFaturamentoMensalJob.php
  - app/Services/SugadorAnalysisService.php
  - app/Http/Controllers/DashboardController.php
  - app/Http/Controllers/CompanyController.php
  - app/Http/Controllers/AdminController.php
  - app/Http/Controllers/MlbController.php
  - app/Http/Controllers/SugadorController.php
  - app/Http/Controllers/PortfolioController.php
  - app/Console/Commands/SyncAdmanData.php
  - app/Console/Commands/AuditBillingDivergence.php
  - app/Console/Commands/DiagnoseCustId.php
  - app/Console/Commands/MarkCustIdStatus.php
  - app/Console/Commands/SyncVendasAdman.php
  - app/Console/Commands/SyncThumbnailsPublicacoes.php
  - app/Console/Commands/InspecionarAdman.php
  - app/Console/Commands/DiagnosticSyncVendas.php
  - tests/Feature/Phase18_5/ImportMarketplaceFromCsvTest.php
  - tests/Feature/Phase18_5/AdmanServiceMarketplaceTest.php
autonomous: false
requirements: []
must_haves:
  truths:
    - "Coluna companies.marketplace existe com ENUM ('meli','shopee','amazon') default 'meli'"
    - "Comando dashboard:import-marketplace-from-csv lê CSV oficial, faz match por adman_account_id e atualiza só a coluna marketplace"
    - "AdmanService aceita marketplace dinâmico em todos os 8 endpoints HTTP"
    - "syncCompany usa $company->marketplace para escolher o path correto"
    - "Cache keys de gross_billing e account_metrics passam a incluir marketplace (evita colisão entre marketplaces com mesmo custId)"
    - "Após import + próximo sync, as 34 contas (33 Shopee + 1 Amazon) sincronizam sem 500"
    - "Suíte completa Phase 18 (25 testes) + 7 testes Phase 18.5 = 32 verdes"
  artifacts:
    - path: "database/migrations/2026_06_02_190000_add_marketplace_to_companies.php"
      provides: "Coluna companies.marketplace"
    - path: "app/Console/Commands/ImportMarketplaceFromCsv.php"
      provides: "Comando de import dashboard:import-marketplace-from-csv"
    - path: "tests/Feature/Phase18_5/ImportMarketplaceFromCsvTest.php"
      provides: "Cobertura do import"
    - path: "tests/Feature/Phase18_5/AdmanServiceMarketplaceTest.php"
      provides: "Cobertura do marketplace dinâmico no AdmanService"
  key_links:
    - from: "AdmanService::fetchPerformance"
      to: "$marketplace parameter (string)"
      via: "assinatura de método com default 'meli'"
    - from: "AdmanService::syncCompany"
      to: "$company->marketplace"
      via: "leitura direta do model + propagação para fetchPerformance/fetchCampaigns"
    - from: "RefreshGrossBillingCacheJob::processar"
      to: "$company->marketplace por empresa no loop"
      via: "passar para fetchGrossBilling/fetchAccountMetricsCached"
    - from: "Cache key adman:gross_billing:* e adman:account_metrics:*"
      to: "inclui {marketplace} entre custId e data"
      via: "edit de todas as 6 ocorrências de cache key (fetchGrossBilling, getCachedGrossBilling, hasCachedEntry, getCachedGrossBillingsMany, fetchGrossBillingsBatch, account_metrics equivalentes)"
---

# Phase 18.5: Marketplace dinâmico no AdmanService + import CSV oficial — Plano

## Resumo executivo

A Phase 18 (W3-T1 `dashboard:audit-billing-divergence` e W4-T1 `dashboard:diagnose-cust-id`) revelou que 32 empresas estavam sendo classificadas como `INVALIDO_CONFIRMADO`. Ao cruzar com a planilha oficial da Adman (`accounts-adman.csv`, 170 linhas), descobriu-se que os `cust_id` estão corretos — o que está errado é a dimensão **marketplace**, hardcoded em `'meli'` no `AdmanService` (`linha 35: $this->marketplace = 'meli'`). Para as 34 contas não-Meli (33 Shopee + 1 Amazon, número confirmado por grep no CSV) o path `/{marketplace}/.../` é montado como `/meli/...` e a Adman responde 500.

A solução tem 4 frentes:
1. **Migração** que adiciona `companies.marketplace` (ENUM, default `'meli'`).
2. **Import CSV** (`dashboard:import-marketplace-from-csv`) com `--dry-run`, idempotente, read-only para outros campos.
3. **Refator do `AdmanService`** — Estratégia A escolhida (parâmetro `string $marketplace = 'meli'` em cada método público que monta path). O construtor mantém `$this->marketplace = 'meli'` como **fallback transicional** até todos os callers serem migrados; documentado como dívida a remover em fase futura.
4. **Refator dos callers** (jobs, controllers, comandos) para passar o marketplace por empresa. Inclui cuidado especial com cache keys da Phase 16: passam a incluir `{marketplace}` no padrão (evita colisão teórica entre marketplaces com mesmo custId).

Esta fase é pré-requisito para deploy da W5 da Phase 18, que já está implementada localmente — sem este fix, as badges "Cust ID Inválido" aparecem em empresas que estão corretas (cust_id ok, marketplace errado).

## Phase Goal (user story)

**As a** administrador do ECF Admin, **I want to** importar do CSV oficial da Adman o marketplace correto de cada empresa e ter o `AdmanService` chamando o path certo (`/meli`, `/shopee`, `/amazon`) por empresa, **so that** as 34 contas Shopee/Amazon voltam a sincronizar e a auditoria de divergência da Phase 18 reflita a realidade.

## Goal e success criteria (do CONTEXT.md)

| SC | Critério |
|----|---------|
| 1 | Coluna `companies.marketplace` ENUM `('meli','shopee','amazon')` default `'meli'`, fillable/casts atualizados, migration aplicada local sem afetar dados existentes. |
| 2 | Comando `dashboard:import-marketplace-from-csv {arquivo} [--dry-run]` lê CSV, mapeia marketplace, busca empresa por `adman_account_id = CustId`, UPDATE só `marketplace`, log via Spatie. Sumário com contagens em pt-BR. |
| 3 | `AdmanService` aceita marketplace dinâmico em todos os 8 endpoints HTTP — **Estratégia A** (parâmetro `string $marketplace = 'meli'`). |
| 4 | Todos os callers atuais passam marketplace baseado em `$company->marketplace`. |
| 5 | Import executado em prod via SSH (operacional — não-código). |
| 6 | Sync ressuscitado para 34 contas após próximo ciclo `adman:sync`. |
| 7 | Re-rodar `mark-custid-status` em prod reclassifica as 34 para `'ok'`. |
| 8 | Testes cobrem import CSV (4) + marketplace dinâmico no AdmanService (3) — 7 novos. |

## Mapeamento criterion → tasks

| SC | Tasks |
|----|------|
| SC-1 | W1-T1 |
| SC-2 | W1-T2 |
| SC-3 | W2-T1 |
| SC-4 | W2-T2 |
| SC-5 | W4-T1 |
| SC-6 | W4-T2 |
| SC-7 | W4-T3 |
| SC-8 | W3-T1, W3-T2, W3-T3 |

## Plans (waves)

### Wave 1 — Backend: schema + import (paralelo entre T1 e T2 NÃO — T2 depende da migration de T1)

#### W1-T1 — Migration `add_marketplace_to_companies` (auto, ~5% contexto)

**Files**
- `database/migrations/2026_06_02_190000_add_marketplace_to_companies.php` (novo)
- `app/Models/Company.php` (edit)

**Action**
- Criar migration que adiciona `marketplace` em `companies`:
  - `Schema::table('companies', fn($t) => $t->enum('marketplace', ['meli','shopee','amazon'])->default('meli')->after('adman_account_id')->comment('Marketplace Adman; populado por dashboard:import-marketplace-from-csv (Phase 18.5)'))`.
  - Posição **após `adman_account_id`** (mantém adjacência com `cust_id_status` que veio na Phase 18 W5-T1).
  - Default `'meli'` garante backfill instantâneo e preserva comportamento atual de todas as empresas.
- `down()` faz `dropColumn('marketplace')`.
- Em `Company::$fillable` adicionar `'marketplace'`.
- Em `Company::$casts` **não** adicionar (string nativo via ENUM dispensa cast).
- Atualizar `getActivitylogOptions()->logOnly([...])` para incluir `'marketplace'` (rastreabilidade do que mudar via import).
- Comentários em pt-BR.

**Verify**
- `php artisan migrate` local: roda sem erro.
- `php artisan tinker --execute="echo App\Models\Company::first()->marketplace;"` retorna `'meli'`.

**Done**
- Coluna existe, default aplicado a todas as empresas existentes, `Company::$fillable` aceita o campo.

---

#### W1-T2 — Comando `dashboard:import-marketplace-from-csv` (auto, ~15% contexto)

**Files**
- `app/Console/Commands/ImportMarketplaceFromCsv.php` (novo)

**Action**
- Signature: `dashboard:import-marketplace-from-csv {arquivo : Caminho absoluto para o CSV exportado da Adman} {--dry-run : Mostra preview sem aplicar UPDATE}`.
- Descrição em pt-BR.
- Validar que `$arquivo` existe e é legível; abortar com mensagem clara se não.
- Parsing com `fopen` + `fgetcsv` (delimitador `,`, padrão Adman). Não usar `phpoffice/phpspreadsheet` — overkill para CSV simples.
- Leitura por linha:
  - Pular cabeçalho (assumir 1 linha de header; validar que coluna 0 é `Nome`, coluna 1 é `CustId` e última (índice 29) é `Marketplace` — caso contrário abortar).
  - Extrair `$custId = trim($row[1])`, `$marketplaceCsv = trim(end($row))`.
  - Validar não vazios; se vazios, contabilizar como `linhas_invalidas` e seguir.
  - Mapeamento `MercadoLibre → 'meli'`, `Shopee → 'shopee'`, `Amazon → 'amazon'`. Outros valores → contabilizar `marketplace_desconhecido`, listar primeiros 5 no output, pular.
  - Buscar `$company = Company::where('adman_account_id', $custId)->first()`.
  - Se não achar: contabilizar `nao_encontradas`, listar primeiros 10 no output (para o operador checar manualmente).
  - Se achar e `$company->marketplace === $novoMarketplace`: `skipped_iguais++`.
  - Se achar e diferente: registrar `['from' => $company->marketplace, 'to' => $novoMarketplace]`, contabilizar `atualizadas++`.
    - Se `--dry-run`: NÃO chamar `save()`.
    - Se modo real: `$company->marketplace = $novoMarketplace; $company->save();` e `activity()->performedOn($company)->withProperties(['from' => $old, 'to' => $novoMarketplace, 'source' => 'csv'])->log('Marketplace atualizado via import CSV')`.
- Read-only para outros campos — em hipótese alguma tocar em `name`, `cust_id_status`, `adman_account_id`, etc.
- Sumário final (pt-BR) imprime tabela:
  - Total de linhas no CSV
  - Linhas válidas processadas
  - Empresas encontradas no DB
  - Empresas com marketplace já igual ao CSV (skip)
  - Empresas atualizadas (com breakdown meli/shopee/amazon)
  - Empresas não encontradas (listar até 10)
  - Linhas com marketplace desconhecido (listar até 5)
- Em `--dry-run` o sumário diz "[DRY-RUN] Nenhum UPDATE aplicado".
- Comentários e mensagens em pt-BR. Tag de log `[Marketplace Import]`.

**Verify**
- `php artisan dashboard:import-marketplace-from-csv .planning/phases/18.5-marketplace-dinamico/accounts-adman.csv --dry-run` roda local e imprime sumário esperando ~169 linhas válidas e o número exato de não-encontradas conforme cust_ids presentes no DB local.

**Done**
- Comando registrado, executa dry-run sem erro, sumário em pt-BR cobre todos os casos.

---

### Wave 2 — Refator AdmanService + callers (depende da Wave 1)

#### W2-T1 — Decisão e refator do `AdmanService` para marketplace dinâmico (auto, ~25% contexto)

**Decisão: Estratégia A — parâmetro `string $marketplace = 'meli'` por método**

Justificativa: A é a estratégia com menor surface de mudança porque (1) preserva backward-compat via default `'meli'` (todos os call-sites antigos continuam funcionando); (2) não muda assinaturas-base que recebem `string $custId` (não exige refactor dos comandos `Inspecionar*` / `Diagnostic*` que apenas inspecionam); (3) evita ter que decidir agora se `AdmanMcpService` herda o mesmo padrão. B (factory `forCompany`) exigiria construtor com estado mutável + serviço como singleton via container, conflitando com o padrão atual de `new AdmanService()` direto em vários comandos. C (`Company` no lugar de `string $custId`) seria mais limpo mas força refactor cascata em ~20 call-sites, fugindo do escopo lean da fase. Documentar como dívida: após a fase estabilizar, considerar remover o default `'meli'` para forçar passagem explícita.

**Files**
- `app/Services/AdmanService.php` (edit)

**Action**
- Manter `private string $marketplace; ... $this->marketplace = 'meli';` no construtor como **fallback transicional**. Documentar como dívida em comentário (`// DÍVIDA: remover após Phase 18.5 estabilizada — todos os callers devem passar explicitamente`).
- Acrescentar parâmetro `string $marketplace = 'meli'` em cada método público que monta path:
  1. `fetchPerformance(string $custId, string $dateFrom, string $dateTo, int $maxRetries = 3, string $marketplace = 'meli')` — linha 205. Path `/{$marketplace}/performance/{$custId}` na linha 212.
  2. `fetchCampaigns(string $custId, string $marketplace = 'meli')` — linha 586. Path `/{$marketplace}/ads/{$custId}/campaigns` na linha 590.
  3. `fetchCampaignMetrics(string $custId, string $campaignId, string $dateFrom, string $dateTo, string $marketplace = 'meli')` — linha 600. Path linha 604.
  4. `fetchAccountMetrics(string $custId, string $dateFrom, string $dateTo, string $marketplace = 'meli')` — linha 638. Path linha 642.
  5. `listAccounts(?string $filter = null, int $page = 1, string $marketplace = 'meli')` — linha 651. Path linha 658.
  6. `fetchAllCampaigns(string $custId, string $marketplace = 'meli')` — linha 703. Path linha 711.
  7. `fetchSugadorCampaigns(string $custId, string $marketplace = 'meli')` — linha 739. Passa para `fetchAllCampaigns($custId, $marketplace)`.
  8. `fetchAdsMetrics(string $custId, string $dateFrom, string $dateTo, int $itemsPerPage = 50, string $marketplace = 'meli')` — linha 861. Path linha 876.
- Cada path interno troca `{$this->marketplace}` por `{$marketplace}`.
- **Wrappers de cache** também ganham parâmetro `$marketplace`:
  9. `fetchGrossBilling(..., int $cacheMinutes = 1440, bool $forceRefresh = false, string $marketplace = 'meli')` — linha 281. Passa para `fetchPerformance(..., $marketplace)`.
  10. `getCachedGrossBilling(..., string $marketplace = 'meli')` — linha 329.
  11. `hasCachedEntry(..., string $marketplace = 'meli')` — linha 345.
  12. `getCachedGrossBillingsMany(array $custIds, ..., string $marketplace = 'meli')` — linha 361. Atenção: marketplace é único por chamada (controllers chamam por lote homogêneo? Verificar — se mixto, precisa virar `array<custId => marketplace>` ou ser chamado por marketplace). **Decisão para o executor:** se controllers atuais já agrupam por algo, manter `string $marketplace`; se mixto, ver caller real e escolher entre passar `array` ou chamar 1×/marketplace. Documentar a escolha em comentário.
  13. `fetchAccountMetricsCached(..., string $marketplace = 'meli')` — linha 426.
  14. `getCachedAccountMetrics(..., string $marketplace = 'meli')` — linha 476.
  15. `hasCachedAccountMetricsEntry(..., string $marketplace = 'meli')` — linha 488.
  16. `getCachedAccountMetricsMany(..., string $marketplace = 'meli')` — linha 501. Mesma decisão do item 12.
  17. `fetchGrossBillingsBatch(..., string $marketplace = 'meli')` — linha 553.
- **Cache keys** passam a incluir marketplace: padrão `"adman:gross_billing:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:{$day}"` e `"adman:account_metrics:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:{$day}"`. Editar todas as 6 ocorrências do padrão antigo no arquivo (linhas 283, 332, 348, 372, 428, 479, 491, 510, 561).
  - **Impacto:** entradas antigas no cache ficam órfãs (key changed). Aceitável: TTL de 24h naturalmente expira, e cache miss faz o que já fazia (chama Adman → cacheia com nova key). Documentar isso no comentário do padrão. NÃO tentar migrar entradas antigas.
- `syncCompany(Company $company, ?string $date = null)` — linha 73: ler `$marketplace = $company->marketplace ?? 'meli'` e passar para `fetchPerformance($custId, $date, $date, 3, $marketplace)` (linha 80). Também passar para `syncCampaigns($company, $custId, $date)` que precisa de novo parâmetro.
- `syncCampaigns(Company $company, string $custId, string $date, string $marketplace = 'meli')` — linha 164: usar `$marketplace` ao chamar `fetchCampaigns($custId, $marketplace)` (linha 166) e `fetchCampaignMetrics($custId, $campaignId, $date, $date, $marketplace)` (linha 173).
- `syncMonthRevenue(Company $company, string $yearMonth)` — linha 668: passar `$company->marketplace` para `fetchGrossBilling` (linha 689).
- Comentários e mensagens de log em pt-BR.
- **NÃO refatorar `AdmanMcpService`** — está fora do escopo declarado no CONTEXT (drilldown Sugadores; uma origem).

**Verify**
- `php -l app/Services/AdmanService.php` sem erro de sintaxe.
- `php artisan test --filter=AdmanServiceMarketplaceTest` (criado em W3-T2) verde.
- Suíte completa Phase 18 não regride.

**Done**
- 8 endpoints + 9 wrappers de cache aceitam `$marketplace`, cache keys incluem marketplace, `syncCompany` lê do model, default `'meli'` preserva compat.

---

#### W2-T2 — Atualizar callers para passar marketplace (auto, ~20% contexto)

**Files** (lista exata identificada via grep prévio)
- `app/Jobs/RefreshGrossBillingCacheJob.php` (linhas 154, 171)
- `app/Jobs/SyncTodasVendasAdmanJob.php` (linha 78)
- `app/Jobs/SyncAdmanCompanyJob.php` (linha 35 — passa `$this->company` já, mas confirmar que `syncCompany` lê `$company->marketplace`)
- `app/Jobs/SyncFaturamentoMensalJob.php` (linha 28 — idem)
- `app/Services/SugadorAnalysisService.php` (linhas 140, 213, 528 — `fetchAdsMetrics`, `fetchCampaignsRange`, `fetchAllCampaigns`)
- `app/Http/Controllers/DashboardController.php` (linha 488 via `AdminController` na verdade — mas verificar `getCachedGrossBillingsMany` e `getCachedAccountMetricsMany` em qualquer controller)
- `app/Http/Controllers/CompanyController.php` (linhas 191, 195 — `fetchGrossBilling`, `fetchAccountMetricsCached`)
- `app/Http/Controllers/AdminController.php` (linha 399 `syncMonthRevenue`, linha 488 `fetchGrossBillingsBatch`)
- `app/Http/Controllers/MlbController.php` (linhas 814, 1854, 1936 — `fetchPerformance` direto)
- `app/Http/Controllers/SugadorController.php` (linha 376 — `fetchSugadorCampaigns`)
- `app/Http/Controllers/PortfolioController.php` (depende de uso real; grep não mostrou call específica — verificar)
- `app/Console/Commands/SyncAdmanData.php` (linhas 32, 53, 56 — `listAccounts`, `syncHistorical`, `syncCompany`)
- `app/Console/Commands/AuditBillingDivergence.php` (linha 105 — `fetchPerformance`)
- `app/Console/Commands/DiagnoseCustId.php` (linha 293 — `fetchPerformance`)
- `app/Console/Commands/MarkCustIdStatus.php` (linha 330 — `fetchPerformance`)
- `app/Console/Commands/SyncVendasAdman.php` (linha 42 — `fetchPerformance` direto)
- `app/Console/Commands/SyncThumbnailsPublicacoes.php` (linha 35 — `fetchAdsMetrics`)
- `app/Console/Commands/InspecionarAdman.php` (linhas 35, 45 — diagnóstico manual; aceitar default `'meli'` se não tiver `Company`)
- `app/Console/Commands/DiagnosticSyncVendas.php` (linha 31 — `fetchPerformance`; diagnóstico)

**Action**
- Para call-sites que têm `$company` (jobs + maioria dos controllers + comandos batch): ler `$marketplace = $company->marketplace ?? 'meli'` e passar como último parâmetro.
- Para call-sites que recebem só `$custId` (comandos de diagnóstico tipo `InspecionarAdman`, `DiagnosticSyncVendas`): adicionar argumento opcional `--marketplace=meli` no signature do comando; quando não passado, usa `'meli'` (preserva o que faziam antes); o operador passa explicitamente em conta Shopee/Amazon.
- `MlbController` linhas 814/1854/1936: lê `$empresa` (model `MlbEmpresa`). Investigar como obter marketplace — se MlbEmpresa tem `company_id`, carregar `$company = $empresa->company` e usar `$company->marketplace`. Se não houver relação direta, fallback `'meli'` (pioria o que já está; documentar para revisão futura).
- `RefreshGrossBillingCacheJob::processar` — passar `$company->marketplace` em ambos os `fetchGrossBilling` e `fetchAccountMetricsCached`. Atenção: o loop processa `$companies` em chunk; basta ler `$company->marketplace` dentro do loop.
- `SugadorAnalysisService::analyzeCompany` — método já recebe `$company`. Adicionar leitura de `$company->marketplace` e passar para os 3 calls (`fetchAdsMetrics`, `fetchCampaignsRange`, `fetchAllCampaigns`).
- Para `getCachedGrossBillingsMany` / `getCachedAccountMetricsMany` (batch reads em `AdminController:488` e potencialmente `DashboardController`): se a lista de empresas é mista (meli+shopee+amazon), o controller precisa agrupar por marketplace e chamar 1× por marketplace, ou usar o formato `array<custId => marketplace>` se decidido em W2-T1. **Decisão a documentar:** começar agrupando por marketplace (`->groupBy('marketplace')` em coleção Eloquent) e chamar `getCachedGrossBillingsMany` 1× por marketplace, merge dos resultados. Simples, sem refator de assinatura do batch.
- pt-BR em comentários e logs.

**Verify**
- `php artisan test --filter=Dashboard\|AuditBilling\|DiagnoseCustId\|MarkCustIdStatus\|Companies` — todos verdes.
- `php artisan dashboard:audit-billing-divergence --period=7` local (com seed mínima) roda sem erro de assinatura.

**Done**
- Todos os call-sites identificados pelo grep prévio passam marketplace. Default `'meli'` preserva compat para diagnostic commands.

---

### Wave 3 — Testes (depende da Wave 2 para alguns; mas T1 pode rodar após W1-T2 e T2 após W2-T1)

#### W3-T1 — `ImportMarketplaceFromCsvTest.php` (auto, 4 testes, ~7% contexto)

**Files**
- `tests/Feature/Phase18_5/ImportMarketplaceFromCsvTest.php` (novo)

**Action**
- Setup: criar fixture CSV temporário (`tmpfile()` ou arquivo dentro de `storage/framework/testing/`) com cabeçalho + 1 linha de cada marketplace + 1 cust_id desconhecido + 1 marketplace inválido.
- Teste 1 (`atualiza_marketplace_meli_quando_linha_csv_mercadolibre`): empresa com `adman_account_id='X'`, `marketplace='shopee'` → roda `dashboard:import-marketplace-from-csv` → marketplace vira `'meli'` + activity log criado.
- Teste 2 (`atualiza_marketplace_shopee_quando_linha_csv_shopee`): empresa default `'meli'` → linha Shopee → marketplace vira `'shopee'`.
- Teste 3 (`pula_e_contabiliza_quando_cust_id_nao_existe_no_db`): linha CSV com cust_id sem empresa → asserta exit code 0, sumário contém "não encontradas: 1", DB inalterado.
- Teste 4 (`dry_run_nao_aplica_update`): empresa com marketplace `'meli'` → linha Shopee → roda com `--dry-run` → marketplace continua `'meli'`, nenhum activity log gerado.
- Todos em pt-BR.

**Verify**
- `php artisan test --filter=ImportMarketplaceFromCsvTest` 4 verdes.

**Done**
- 4 testes cobrem cenários autoritativos do comando.

---

#### W3-T2 — `AdmanServiceMarketplaceTest.php` (auto, 3 testes, ~6% contexto)

**Files**
- `tests/Feature/Phase18_5/AdmanServiceMarketplaceTest.php` (novo)

**Action**
- Usar `Http::fake()` para interceptar chamadas.
- Teste 1 (`fetch_performance_meli_chama_path_meli`): `(new AdmanService())->fetchPerformance('CUST1', '2026-06-01', '2026-06-01', 3, 'meli')` → asserta `Http::assertSent` com URL contendo `/meli/performance/CUST1`.
- Teste 2 (`fetch_performance_shopee_chama_path_shopee`): mesma chamada mas com `'shopee'` → asserta URL contendo `/shopee/performance/CUST1`.
- Teste 3 (`fetch_performance_sem_marketplace_continua_meli_para_compat`): chamada sem `$marketplace` (default) → asserta URL contendo `/meli/performance/CUST1`.
- Em pt-BR.

**Verify**
- `php artisan test --filter=AdmanServiceMarketplaceTest` 3 verdes.

**Done**
- 3 testes provam dinamismo do marketplace e backward compat.

---

#### W3-T3 — Verificar regressão na suíte completa (auto, ~3% contexto)

**Files**
- nenhum (read-only)

**Action**
- Rodar `php artisan test`.
- Esperar 25 testes da Phase 18 + 7 novos = 32 verdes.
- Em caso de regressão, listar testes que quebraram e abrir deviation (não tentar fix cego).

**Verify**
- Suíte 100% verde com pelo menos 32 testes envolvidos nos filtros principais.

**Done**
- Nenhuma regressão; logs e comentários ainda em pt-BR.

---

### Wave 4 — Operacional (MANUAL — orchestrator/dev faz; cada item é checkpoint)

> Estas tasks NÃO modificam código. Existem para garantir que o roll-out em prod aconteça na ordem certa.

#### W4-T1 — Rodar import em prod após deploy (checkpoint:human-action)

**O que fazer**
- Deploy de Phase 18.5 (não automático — esperar autorização explícita do usuário).
- SSH no VPS `177.7.53.164`.
- `cd /var/www/ecf_admin`.
- Copiar `accounts-adman.csv` para `storage/app/private/imports/accounts-adman.csv` (criar diretório se necessário).
- `php artisan dashboard:import-marketplace-from-csv storage/app/private/imports/accounts-adman.csv --dry-run` → revisar sumário com o usuário.
- Confirmar números: esperado ~33 Shopee + 1 Amazon mudando, ~135 meli skip-iguais, total CSV 169.
- Após confirmação, rodar sem `--dry-run`.
- Salvar output completo em `.planning/phases/18.5-marketplace-dinamico/IMPORT-OUTPUT.md`.

**Resume signal**: "import aplicado, sumário em IMPORT-OUTPUT.md".

#### W4-T2 — Aguardar próximo `adman:sync` (11:00 BRT) ou disparar manual (checkpoint:human-verify)

**O que fazer**
- Opção A: aguardar próximo ciclo 11:00 BRT.
- Opção B: disparar manual via `php artisan adman:sync` no VPS.
- `supervisorctl status ecf-worker:*` para confirmar que workers estão processando.
- `tail -f storage/logs/laravel.log` filtrando por `[Adman]` — esperar ver entradas de empresas Shopee/Amazon sincronizando sem erro 500.

**Resume signal**: "sync concluiu; contas Shopee/Amazon aparecem com `synced_at` recente em `adman_metrics` ou `adman_sync_logs`".

#### W4-T3 — Re-rodar `dashboard:mark-custid-status` em prod (checkpoint:human-action)

**O que fazer**
- `php artisan dashboard:mark-custid-status --dry-run` no VPS → revisar sumário com usuário.
- Esperar ~34 empresas mudando de `'invalido'` → `'ok'`.
- Após confirmação, rodar sem `--dry-run`.

**Resume signal**: "34 empresas reclassificadas para 'ok'".

#### W4-T4 — Re-rodar auditoria de divergência (checkpoint:human-verify)

**O que fazer**
- `php artisan dashboard:audit-billing-divergence --period=30` no VPS.
- Comparar com `.planning/phases/18-dashboard-precisao-filtros/AUDIT-OUTPUT-30d.txt` (que mostrou 71,79% divergência).
- Esperar divergência caindo significativamente (provavelmente <10%, mas registrar valor real).
- Salvar output em `.planning/phases/18.5-marketplace-dinamico/AUDIT-OUTPUT-POS-IMPORT.md`.

**Resume signal**: "divergência caiu para X%; output salvo".

#### W4-T5 — Liberar deploy da W5 da Phase 18 (checkpoint:decision)

**O que fazer**
- Os commits W5 da Phase 18 já existem localmente. Após W4-T1..T4 OK, é seguro fazer push e deploy de W5.
- Confirmar com o usuário se prossegue com `git push` + deploy de W5.

**Resume signal**: aprovação do usuário ou nova orientação.

## Pitfalls e mitigações

| # | Pitfall | Mitigação |
|---|---------|-----------|
| 1 | Refator do `AdmanService` quebra callers que mockam o serviço | Default `'meli'` em todos os parâmetros novos preserva assinaturas; testes existentes seguem passando. |
| 2 | `AdmanMcpService` pode ter o mesmo bug | Fora do escopo declarado; W2-T1 documenta na sua docstring que MCP é separado e foi mantido. Revisar em fase futura se aparecer queixa. |
| 3 | Empresas Shopee/Amazon **não importadas** continuam falhando | Aceito: o import via CSV é a porta de entrada. Default `'meli'` é seguro para empresas existentes (era o comportamento anterior) e exige import explícito para corrigir. |
| 4 | Cache da Phase 16 colide entre marketplaces com mesmo custId | Cache keys passam a incluir `{marketplace}` (W2-T1). Entradas antigas ficam órfãs e expiram naturalmente em 24h. |
| 5 | `getCachedGrossBillingsMany` recebe lista mista | W2-T2 agrupa por marketplace no caller (`->groupBy('marketplace')`) e chama 1× por marketplace. Assinatura do batch fica simples. |
| 6 | Sequenciamento W4 — `mark-custid-status` antes do sync com marketplace correto | Documentado: ordem T1→T2→T3→T4 é mandatória; T3 depende de T2 ter rodado pelo menos 1×. |
| 7 | CSV pode vir com encoding/CRLF inesperado | `fgetcsv` lida com CRLF automaticamente; encoding UTF-8 esperado (header da Adman não tem acentos). Se vier outra coisa, abortar com mensagem clara. |

## Não-objetivos (do CONTEXT.md)

- Reescrever `AdmanMcpService`.
- Importar outros campos do CSV (faturamento, ACOS, etc).
- Criar UI para editar marketplace manualmente.
- Backfill histórico do `adman_metrics` para empresas Shopee/Amazon que ficaram zeradas.
- Suportar marketplaces adicionais (apenas meli/shopee/amazon).
- Mexer no JSX — fase puramente backend.
- Deploy automático.

## Deviation contract

Pare e abra deviation se:

- Estratégia A se mostrar inviável (e.g. algum caller precisa refator estrutural não previsto).
- Cache da Phase 16 colidir de modo não previsto entre marketplaces (e.g. invalidação massiva afetando dashboard ao vivo).
- Algum caller estiver em código de outra fase em planejamento ativo (pause e alinhe com o orchestrator).
- CSV tiver linhas com formato inesperado (CRLF híbrido, encoding não-UTF8, colunas faltando).
- Migration falhar local (a tabela `companies` tem ~170 linhas em prod; deve aplicar instantaneamente).
- `MlbController` (linhas 814/1854/1936) não tiver relação clara com `Company` para obter marketplace.
- Suíte completa Phase 18 regredir (não tentar fix cego — listar e parar).

## Por que este plano entrega o goal?

| Goal Outcome | Wave / Task | Como |
|--------------|-------------|------|
| Eliminar o bug raiz `$marketplace = 'meli'` hardcoded | W2-T1 | Refator do `AdmanService` aceita `$marketplace` por chamada; `syncCompany` lê do model. |
| Saber o marketplace de cada empresa | W1-T1 + W1-T2 | Schema (`companies.marketplace`) + import CSV oficial. |
| Sync ressuscitado para 34 contas | W2-T2 + W4-T2 | Callers passam marketplace; próximo ciclo `adman:sync` exercita o path correto. |
| Auditoria reflete a realidade | W4-T4 | Re-rodar `dashboard:audit-billing-divergence` deve mostrar divergência caindo dramaticamente. |
| Phase 18 W5 deployável corretamente | W4-T3 + W4-T5 | `mark-custid-status` reclassifica as 34 antes do push de W5 → badges aparecem só onde fazem sentido. |
| Não quebrar o que já funciona | W2-T1 default `'meli'` + W3-T3 | Backward compat + suíte completa verde. |
| Testes provam a mudança | W3-T1 + W3-T2 | 4 testes do import + 3 testes do marketplace dinâmico = 7 novos. |

## Callers identificados (referência para o executor)

Lista completa do grep `AdmanService|fetchPerformance|fetchAccountMetrics|fetchCampaigns|fetchAdsMetrics|fetchGrossBilling|listAccounts|fetchSugadorCampaigns|syncCompany|fetchAllCampaigns|fetchCampaignsRange|syncMonthRevenue|syncHistorical` em `app/`:

**Jobs**
- `app/Jobs/RefreshGrossBillingCacheJob.php:154,171`
- `app/Jobs/SyncTodasVendasAdmanJob.php:78`
- `app/Jobs/SyncAdmanCompanyJob.php:35`
- `app/Jobs/SyncFaturamentoMensalJob.php:28`

**Services**
- `app/Services/SugadorAnalysisService.php:140,213,528`

**Controllers**
- `app/Http/Controllers/CompanyController.php:191,195`
- `app/Http/Controllers/AdminController.php:399,488`
- `app/Http/Controllers/MlbController.php:809,814,1854,1936`
- `app/Http/Controllers/SugadorController.php:376`
- `app/Http/Controllers/DashboardController.php:22` (DI; uso real depende do código)
- `app/Http/Controllers/PortfolioController.php:17` (DI; uso real depende do código)

**Commands**
- `app/Console/Commands/SyncAdmanData.php:32,53,56`
- `app/Console/Commands/AuditBillingDivergence.php:105`
- `app/Console/Commands/DiagnoseCustId.php:293`
- `app/Console/Commands/MarkCustIdStatus.php:330`
- `app/Console/Commands/SyncVendasAdman.php:42`
- `app/Console/Commands/SyncThumbnailsPublicacoes.php:35`
- `app/Console/Commands/InspecionarAdman.php:35,45`
- `app/Console/Commands/DiagnosticSyncVendas.php:31`

`AdmanMcpService` é separado e está **fora do escopo** desta fase.

## Output

Ao concluir todas as waves, marcar `.planning/ROADMAP.md` Phase 18.5 como `[x]` e criar SUMMARY conforme template padrão GSD.
