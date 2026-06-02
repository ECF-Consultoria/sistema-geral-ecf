---
phase: 18-dashboard-precisao-filtros
plan: 00
type: execute
mode: mvp
wave: 0
depends_on: []
files_modified: []
autonomous: false
requirements: [DASH-01, DASH-02, DASH-03, DASH-04, DASH-05, DASH-06]
user_setup: []

must_haves:
  truths:
    - "Trocar período (1d/7d/30d/180d) recalcula TODOS os cards principais (Faturamento, Invest. Ads, TACOS médio, Margem, Net Billing)"
    - "Selecionar empresa + período mantém a empresa selecionada — nenhum filtro se perde ao alterar outro"
    - "Existe comando Artisan read-only que mede divergência entre Adman e adman_metrics por empresa"
    - "Quando os cards caem em fallback DB ou cache parcial, a UI sinaliza com indicador 'aproximado' (sem mascarar dados)"
    - "Bug 3 (divergência de faturamento) tem fix aplicado com base nos achados da auditoria — não chute"
  artifacts:
    - path: "app/Http/Controllers/DashboardController.php"
      provides: "getPeriodRange + propagação em todas as queries + filters em snake_case"
    - path: "resources/js/Pages/Dashboard/Admin.jsx"
      provides: "applyFilter usa filters.company_id/consultor_id/estrategista_id (snake_case)"
    - path: "app/Console/Commands/AuditBillingDivergence.php"
      provides: "Comando dashboard:audit-billing-divergence read-only"
    - path: "tests/Feature/Phase18/DashboardFiltersTest.php"
      provides: "Empilhamento de filtros + período dinâmico + UI flag aproximado"
    - path: "tests/Feature/Phase18/AuditBillingDivergenceTest.php"
      provides: "Auditoria detecta gap propositado"
  key_links:
    - from: "DashboardController::adminDashboard"
      to: "getPeriodRange"
      via: "[$dateFromN, $dateToN] = $this->getPeriodRange($period)"
    - from: "Admin.jsx (applyFilter)"
      to: "DashboardController (query params)"
      via: "snake_case exclusivo nos dois lados"
    - from: "AuditBillingDivergence command"
      to: "AdmanService::fetchPerformance + AdmanMetric::sum"
      via: "comparação per-company com throttle 7s (ADMAN_RATE_LIMIT_RPM)"
---

# Phase 18: Dashboard precisa e com filtros empilháveis — Plano

## Resumo executivo

Plano em 5 waves para eliminar 3 bugs reportados pelo usuário em 2026-06-02 aplicando as regras-mestras **acertividade** (números batem com a fonte) e **praticidade** (filtros combinam de verdade) na Dashboard admin.

**Bugs alvo (verificados em HEAD contra `DashboardController.php` e `Admin.jsx`):**
1. **Período não afeta os cards principais** — `$dateFrom30d`/`$dateTo30d` são hardcoded em 30 dias (linhas 106-107 do controller) e alimentam todas as queries críticas (linhas 111-112, 156-167, 251-258). O seletor de `$period` só altera o chart de série temporal (linhas 196-204) e queries menores (NPS linha 211, meetings linha 217).
2. **Empresa some ao trocar período** — controller devolve `filters` em camelCase via `compact('companyFilter', 'consultorFilter', 'estrategistaFilter')` (linha 386), mas lê do request em snake_case (linhas 68-70). Frontend espalha `...filters` em camelCase no `applyFilter` (linha 95), gerando URL com chave inválida `?companyFilter=5` que o controller ignora.
3. **Faturamento divergente da Adman** — origem ainda não medida; pode ser cascata do bug 1, empresas sem `cust_id`, gaps em `adman_metrics`, ou política "tudo-ou-nada" do cache (linhas 117-133). Antes de fix, **auditoria**.

**Estratégia:** W1 corrige naming (fix barato, alto valor), W2 propaga período dinâmico (fix de raiz), W3 mede a divergência (read-only, ~20min de execução), W4 escopo definido por deviation após W3, W5 fecha com UI feedback + testes.

**Decisão W2-T3 já tomada:** ranges ≠ 30d caem em fallback DB (não pré-warm cache). Justificativa em W2.

## Goal e success criteria (citação literal do ROADMAP)

**Goal:** Aplicar diretamente as duas regras-mestras do projeto (**acertividade** + **praticidade**) na Dashboard, eliminando 3 bugs reportados pelo usuário em 2026-06-02. Os dados mostrados ao admin precisam (a) refletir o período selecionado, (b) preservar todos os filtros simultaneamente, e (c) bater com a Adman para o mesmo range.

**Success criteria (ROADMAP, Phase 18):**

- **SC-1 Filtros empilháveis** — frontend e backend usam exclusivamente snake_case (`company_id`, `consultor_id`, `estrategista_id`, `period`); nenhum filtro se perde ao alterar outro.
- **SC-2 Período afeta TODOS os cards** — helper `getPeriodRange(string $period): array{from: string, to: string}`; aplicado em todas as queries do controller.
- **SC-3 Auditoria executada** — `dashboard:audit-billing-divergence [--period=N]` compara `fetchPerformance` vs `SUM(adman_metrics.revenue)`; identifica empresas sem `cust_id`, dias faltantes, magnitude.
- **SC-4 Fix do Bug 3 baseado nos achados** — escopo de W4 definido por deviation explícito após W3.
- **SC-5 UI sinaliza incerteza** — quando fallback DB ativo, cards mostram "≈ valor aproximado" sutil ou tooltip equivalente.
- **SC-6 Testes** — period preserva `company_id` e vice-versa; range derivado; auditoria detecta gap propositado.

## Mapeamento criterion → tasks

| SC | Wave/Task | Resultado observável |
|----|-----------|----------------------|
| SC-1 | W1-T1, W1-T2, W1-T3 | URL final tem `?company_id=5&period=7&consultor_id=12` mesmo ao trocar filtros em qualquer ordem |
| SC-2 | W2-T1, W2-T2 | Trocar `period=7` reduz `total_revenue` em comparação a `period=30` (range menor → menos faturamento) |
| SC-3 | W3-T1, W3-T2, W3-T3 | Output `dashboard:audit-billing-divergence` em produção colado em `STATE.md`/PR; gap absoluto e % medidos |
| SC-4 | W4 (escopo TBD) | Soma do card Faturamento bate com Adman dentro de tolerância definida em W3 |
| SC-5 | W5-T1 | Card mostra "≈ aproximado" quando `grossCacheCompleto=false` OU `period ≠ 30` |
| SC-6 | W1-T3, W2-T4, W3-T3, W5-T2 | Suíte verde cobrindo empilhamento, período dinâmico, auditoria com gap propositado |

## Plans (waves)

### Wave 1 — Alinhamento de naming dos filtros (SC-1)

**Objetivo:** eliminar a inconsistência camelCase ↔ snake_case que faz o filtro de empresa desaparecer ao trocar período. Fix barato e auto-contido.

**Wave size:** 3 tasks pequenas, ~20% contexto. Touch único em controller (1 linha) + JSX (4 linhas) + 1 arquivo de teste novo.

#### Task W1-T1 — Backend: `filters` em snake_case

- **Files:** `app/Http/Controllers/DashboardController.php`
- **Action:** substituir a linha 386 que usa `compact('companyFilter', 'consultorFilter', 'estrategistaFilter')` por um array literal explícito com chaves em snake_case:
  ```
  'filters' => [
      'company_id'      => $companyFilter,
      'consultor_id'    => $consultorFilter,
      'estrategista_id' => $estrategistaFilter,
  ],
  ```
  Adicionar comentário pt-BR explicando que o naming é alinhado com os query params lidos nas linhas 68-70 (mesma fonte de verdade). **NÃO renomear** as variáveis PHP (`$companyFilter` etc.) — só a chave do array exportado pro Inertia. Manter `?? $request->get('mentor_id')` na linha 70 (back-compat com chamadas antigas).
- **Verify:**
  - `grep -n "companyFilter" app/Http/Controllers/DashboardController.php` retorna só ocorrências internas (variáveis PHP, não chaves serializadas)
  - Tinker: `app(\App\Http\Controllers\DashboardController::class)` — sintaxe válida
- **Done:** Linha 386 emite array com chaves snake_case; restante do método inalterado.

#### Task W1-T2 — Frontend: `ECFSelect` e `applyFilter` em snake_case

- **Files:** `resources/js/Pages/Dashboard/Admin.jsx`
- **Action:** atualizar 3 referências de `filters.*Filter` em camelCase para snake_case alinhado com W1-T1:
  - Linha 252: `value={filters.companyFilter || ''}` → `value={filters.company_id || ''}`
  - Linha 258: `value={filters.consultorFilter || ''}` → `value={filters.consultor_id || ''}`
  - Linha 264: `value={filters.estrategistaFilter || ''}` → `value={filters.estrategista_id || ''}`
  - `applyFilter` (linhas 93-99): manter `...filters` no spread — agora que o controller devolve snake_case, esse spread propaga corretamente. **NÃO** alterar a função em si.
- **Verify:**
  - `grep -n "Filter:" resources/js/Pages/Dashboard/Admin.jsx` retorna 0 matches
  - `npm run build` finaliza sem erro
  - Manual: abrir `/`, selecionar empresa, depois período → URL final contém `?company_id=...&period=...` e a empresa permanece selecionada no dropdown
- **Done:** Admin.jsx coerente com snake_case; build verde; empresa preservada ao trocar período.

#### Task W1-T3 — Teste de empilhamento de filtros

- **Files:** `tests/Feature/Phase18/DashboardFiltersTest.php` (novo)
- **Action:** criar suíte Feature mínima cobrindo:
  - Teste 1: `actingAs(admin)->get('/?company_id=5&period=7')` retorna prop `filters.company_id === '5'` e `period === '7'`
  - Teste 2: simular sequência "selecionar empresa → trocar período" via 2 chamadas GET consecutivas; segunda chamada (`?company_id=5&period=7`) preserva `company_id` na resposta Inertia
  - Teste 3: `?companyFilter=5` (camelCase legacy) **NÃO** filtra — confirma que o naming antigo foi extinto (regression guard)
  - Seed mínima: 1 admin + 2 companies active + 0 metrics (basta a prop bater, não precisa de dados Adman)
- **Verify:** `php artisan test --filter=DashboardFiltersTest` verde
- **Done:** 3 testes verdes; classe segue convenção `Phase{N}` de `tests/Feature/Phase16/` e `tests/Feature/Phase14*`.

---

### Wave 2 — Período dinâmico em todas as queries (SC-2)

**Objetivo:** matar o range hardcoded de 30 dias. Helper `getPeriodRange` central + propagação em ~7 sites de uso.

**Wave size:** 4 tasks, ~35% contexto. Touch denso em `DashboardController::adminDashboard` (linhas 80-258) + 1 teste novo.

#### Task W2-T1 — Helper `getPeriodRange`

- **Files:** `app/Http/Controllers/DashboardController.php`
- **Action:** adicionar método privado `getPeriodRange(string $period): array` logo após `getSince()` (depois da linha 64). Retorna `['from' => string, 'to' => string]` (ambos `Y-m-d` BRT-safe via `->toDateString()`):
  - `'1'` → from = ontem, to = hoje
  - `'7'` → from = -7d, to = hoje
  - `'30'` → from = -30d, to = hoje (default)
  - `'180'` → from = -180d, to = hoje
  - default = '30' (mesmo fallback de `getSince`)
  - Espelha exatamente a tabela de `getSince` — usar `now()->subDays(N)->toDateString()` consistente com linhas 106-107 atuais
  - Docblock pt-BR explicando: "Helper que substitui o range 30d hardcoded; chamado uma vez no topo de adminDashboard e propagado pra todas as queries de cards/totais."
- **Verify:** Tinker: `app(\App\Http\Controllers\DashboardController::class)` resolve; método existe e retorna shape correto via reflection ou unit test inline.
- **Done:** Método existe; `getSince` permanece intacto (são complementares — `getSince` retorna Carbon, novo helper retorna strings prontas pra `whereBetween`).

#### Task W2-T2 — Propagar `getPeriodRange` em todas as queries

- **Files:** `app/Http/Controllers/DashboardController.php`
- **Action:** refator denso no método `adminDashboard`. Substituir as variáveis `$dateFrom30d`/`$dateTo30d` (definidas nas linhas 106-107) por `$dateFromN`/`$dateToN` derivados de `getPeriodRange($period)`. Sites de uso a atualizar:
  - Linhas 106-107: `[$dateFromN, $dateToN] = $this->getPeriodRange($period);` substituindo as duas atribuições
  - Linhas 111-112: `getCachedGrossBillingsMany($custIds, $dateFromN, $dateToN)` e `getCachedAccountMetricsMany(...)` — mudar nome das variáveis usadas
  - Linhas 156-157: `whereBetween('reference_date', [$dateFromN, $dateToN])` no fallback DB de revenue
  - Linhas 251-258: `whereBetween('reference_date', [$dateFromN, $dateToN])->sum('ad_spend')` no fallback DB de ad investment
  - Renomear `$grossBatch30d` → `$grossBatchN`, `$accountBatch30d` → `$accountBatchN`, `$revenue30dByCompany` → `$revenueByCompany`, `$acos30dByCompany` → `$acosByCompany`, `$tacos30dByCompany` → `$tacosByCompany`, `$margin30dByCompany` → `$marginByCompany`, `$sumDb30d` → `$sumDbN`, `$totalAdInvestment30d` → `$totalAdInvestment`, `$custIds30d` → `$custIds`
  - Atualizar referências subsequentes na função (linha 227 `array_sum($revenue30dByCompany)`, linhas 236-247 dos cards, linhas 290-318 do `userPortfolios`, linhas 391-402 do `companies_performance`)
  - Atualizar a chave de saída do Inertia: `'total_ad_investment_30d' => $totalAdInvestment30d` (linha 361) — **MANTER** o nome da prop `total_ad_investment_30d` pra não quebrar Admin.jsx (linha 107/307) — só renomear a variável PHP interna. Adicionar comentário pt-BR explicando que o sufixo `_30d` na prop é legacy e o valor agora reflete `$period`.
  - **Cuidado pitfall 5 do CONTEXT:** `whereBetween('reference_date', [...])` — coluna `reference_date` é cast `date`; já está em formato Y-m-d nas duas pontas, então sem problema.
  - **NÃO mexer** em `userDashboard` (linhas 450-569) — out of scope (CONTEXT "não-objetivos"). Manter `$dateFrom30d`/`$dateTo30d` lá com comentário "TODO Phase 18+ — userDashboard não foi tocado nesta fase".
- **Verify:**
  - `grep -n "30d\|subDays(30)" app/Http/Controllers/DashboardController.php | grep -v userDashboard` retorna apenas a prop `total_ad_investment_30d` (sufixo legacy intencional)
  - Tinker: dispatch manual de `/` com `?period=7` versus `?period=30` mostra `stats.total_revenue` diferentes (smoke manual antes do teste automatizado)
- **Done:** Range de cards deriva de `$period`; userDashboard intocado; nenhuma referência a `subDays(30)` em `adminDashboard`.

#### Task W2-T3 — Decisão sobre cache para ranges ≠ 30d

**Decisão tomada (não pergunta ao usuário):** ranges 1d/7d/180d **não** terão pre-warm de cache. O cache mantém range fixo 30d alinhado com `RefreshGrossBillingCacheJob` (Phase 16). Quando o controller pede `getCachedGrossBillingsMany($custIds, $from7d, $to7d)` o `$grossCacheCompleto` será `false` por design → cai em fallback DB.

**Por quê:**
- (a) Pre-warm de 4 ranges × 168 empresas × 7s throttle ≈ 80min/dia de chamadas Adman — fora do escopo "NÃO MEXER em RefreshGrossBillingCacheJob"
- (b) Fallback DB para ranges menores é aceitável: o range 30d (default e mais usado) continua com cache exato
- (c) SC-5 cobre a honestidade do dado — UI mostra "≈ aproximado" quando há fallback OU quando `$period !== '30'`

**Trade-off:**
- Custo: ranges 1d/7d/180d sempre vão usar `SUM(adman_metrics.revenue)` em vez do número exato da Adman → herdam o problema (c)/(d) do CONTEXT até W3/W4 fecharem
- Benefício: implementação simples, sem efeito colateral em Phase 16, sem migration

- **Files:** `app/Http/Controllers/DashboardController.php`
- **Action:** documentar a decisão no método `adminDashboard` antes do bloco do cache (próximo à linha 105). Comentário pt-BR de ~6 linhas explicando que cache só é hot para `period=30` (alinhado com RefreshGrossBillingCacheJob); demais ranges caem em fallback DB intencionalmente; UI sinaliza isso em SC-5. Adicionar uma flag derivada `$cardsExatos = $grossCacheCompleto && $period === '30';` e passá-la como prop `cards_exatos` no Inertia render (linha 354+) — W5-T1 vai consumir isso. (Nome `cards_exatos` é positivo — "exatos" quando true, "aproximado" quando false; mais natural em pt-BR que `cards_approx`.)
- **Verify:** prop `cards_exatos` aparece no JSON do Inertia response (smoke via `dd($props)` ou inspeção no DevTools)
- **Done:** Decisão documentada em código; prop nova disponível para W5-T1.

#### Task W2-T4 — Teste de período dinâmico

- **Files:** `tests/Feature/Phase18/DashboardFiltersTest.php` (estender)
- **Action:** adicionar 2 testes na suíte criada em W1-T3:
  - Teste 4: seed 1 empresa + `AdmanMetric` com `revenue=100` em D-10, `revenue=200` em D-20, `revenue=400` em D-40. `actingAs(admin)->get('/?period=30')` retorna `stats.total_revenue === 300.0` (D-10+D-20 caem no range); `?period=180` retorna `700.0` (todos os 3 caem). Forçar fallback DB com `Cache::flush()` no `setUp`.
  - Teste 5: smoke regression — `?period=7` produz range diferente de `?period=30` (assert `total_ad_investment_30d` muda entre os dois). Aceita qualquer diff > 0; o ponto é provar que não está hardcoded.
- **Verify:** `php artisan test --filter=DashboardFiltersTest` verde (5 testes)
- **Done:** Range dinâmico provado por teste automatizado.

---

### Wave 3 — Auditoria de divergência (SC-3)

**Objetivo:** medir a divergência entre fonte autoritativa (Adman `/performance`) e DB local (`SUM(adman_metrics.revenue)`) por empresa, em escala. Read-only.

**Wave size:** 3 tasks, ~30% contexto (comando novo + execução manual + 1 teste).

#### Task W3-T1 — Comando `dashboard:audit-billing-divergence`

- **Files:** `app/Console/Commands/AuditBillingDivergence.php` (novo)
- **Action:** criar classe `AuditBillingDivergence extends Command` com signature exata: `dashboard:audit-billing-divergence {--period=30 : Número de dias (1, 7, 30, 180)}`. Construtor com injeção de `AdmanService`. Comportamento do `handle`:
  1. Validar `--period` ∈ {1, 7, 30, 180}; abortar com `$this->error()` + return 1 caso contrário
  2. Calcular `$from`/`$to` no formato Y-m-d (reusar lógica do `getPeriodRange` — pode duplicar como helper privado no command já que é read-only)
  3. Buscar `Company::where('active', true)->get()` com eager load mínimo
  4. Inicializar `$rows = []`, `$semCustId = 0`, `$totalAdman = 0.0`, `$totalDb = 0.0`
  5. Loop com `progressBar` (`$this->withProgressBar`) sobre as empresas:
     - Se `!$company->cust_id`: incrementar `$semCustId`, registrar linha `['name' => $company->name, 'cust_id' => '—', 'adman' => 'N/A', 'db' => '0,00', 'diff_abs' => 'N/A', 'diff_pct' => 'N/A', 'status' => 'sem_cust_id']`, continue
     - Tentar `$this->adman->fetchPerformance($company->cust_id, $from, $to)`; em `\Throwable`: registrar linha com `status='erro_adman'`, `adman='ERR'`, log do erro, continue
     - Extrair `$admanRevenue = (float) ($result['summarizedData']['grossBilling']['value'] ?? 0)`
     - Calcular `$dbRevenue = (float) AdmanMetric::where('company_id', $company->id)->whereBetween('reference_date', [$from, $to])->whereNotNull('revenue')->sum('revenue')`
     - `$diffAbs = $admanRevenue - $dbRevenue`; `$diffPct = $admanRevenue > 0 ? round(($diffAbs / $admanRevenue) * 100, 2) : null`
     - Status: `match(true) { abs($diffAbs) < 1 => 'ok', abs($diffAbs) < 1000 => 'pequeno', default => 'gap' }`
     - Acumular `$totalAdman += $admanRevenue; $totalDb += $dbRevenue;`
     - Registrar linha formatada (currency em pt-BR via `number_format($v, 2, ',', '.')`)
     - **Throttle obrigatório:** `usleep(7_000_000)` (7s) entre chamadas — referenciar `ADMAN_RATE_LIMIT_RPM = 10` da Phase 16 em comentário pt-BR
  6. Após o loop: `usort($rows, fn($a,$b) => abs($b['diff_pct'] ?? 0) <=> abs($a['diff_pct'] ?? 0))` (ordena por |diff %| DESC; entradas N/A vão pro fim por causa do `?? 0`)
  7. Render via `$this->table(['Empresa', 'cust_id', 'Adman R$', 'DB R$', 'Diff Abs', 'Diff %', 'Status'], $rows)`
  8. Sumário final via `$this->info`:
     - Total empresas processadas
     - Empresas sem `cust_id` (count)
     - Empresas com erro Adman (count)
     - Empresas com `|diff| > R$ 1.000` (count)
     - Diff total absoluto: `$totalAdman - $totalDb` em currency
     - Diff total % se `$totalAdman > 0`
  9. **READ-ONLY:** nenhum UPDATE/INSERT em nenhum lugar. Adicionar assertion defensiva no topo do `handle`: `DB::beginTransaction(); DB::rollBack();` é desnecessário (não há writes); só comentar pt-BR "Comando read-only — não altera nada no DB."
- **Verify:**
  - `php artisan list | grep dashboard:audit-billing-divergence` lista o comando
  - `php artisan dashboard:audit-billing-divergence --period=999` retorna erro com mensagem clara
  - Smoke local: rodar com 0 empresas active não quebra (DB de testes vazio)
- **Done:** Comando registrado, signature válida, throttle 7s presente, read-only confirmado.

#### Task W3-T2 — Execução em produção (MANUAL STEP)

**Esta task não tem código** — é tarefa operacional do usuário via SSH.

- **Files:** nenhum
- **Action operacional (para o usuário executar):**
  1. SSH no VPS Hostinger (`177.7.53.164`)
  2. `cd` no path do projeto em produção
  3. Rodar `php artisan dashboard:audit-billing-divergence --period=30` — aguardar ~20min (168 empresas × ~7s)
  4. Copiar output COMPLETO (tabela + sumário)
  5. Colar em `.planning/phases/18-dashboard-precisao-filtros/AUDIT-OUTPUT.md` (novo arquivo) e commitar
  6. (Opcional) Rodar também `--period=7` para sanity check de range curto — output em `AUDIT-OUTPUT-7d.md`
- **Verify:** existência de `AUDIT-OUTPUT.md` com tabela populada e sumário; usuário confirma "auditoria rodou".
- **Done:** Output disponível para informar W4. **STOP point:** executor pausa e devolve controle ao usuário para análise.

#### Task W3-T3 — Teste de auditoria com gap propositado

- **Files:** `tests/Feature/Phase18/AuditBillingDivergenceTest.php` (novo)
- **Action:** suíte Feature/Unit do comando:
  - Teste 1: seed 1 empresa com `cust_id` válido + 0 registros em `adman_metrics`; mockar `AdmanService::fetchPerformance` para retornar `['summarizedData' => ['grossBilling' => ['value' => 50000.0]]]`. Rodar comando via `Artisan::call('dashboard:audit-billing-divergence', ['--period' => 30])`. Assert que `Artisan::output()` contém o nome da empresa, "50.000,00", e a string "gap" (status). Diff esperado: R$ 50.000.
  - Teste 2: seed 1 empresa SEM `cust_id` (nem `adman_account_id` nem `ml_store_id`). Rodar comando. Assert output contém "sem_cust_id" e o sumário marca "Empresas sem cust_id: 1".
  - Teste 3: validação de `--period`: `Artisan::call(..., ['--period' => 999])` retorna `1` (erro) e output contém mensagem de período inválido.
  - **Importante:** mockar `AdmanService` via `$this->mock(AdmanService::class)` ou similar para não chamar API real. Mockar também o `usleep` é dispensável (testes locais aceitam o sleep curto se for só 1 empresa).
- **Verify:** `php artisan test --filter=AuditBillingDivergenceTest` verde (3 testes)
- **Done:** Comando coberto por testes; regressão na lógica de diff/status detectável.

---

### Wave 4 — Fix do Bug 3 baseado em achados de W3 (SC-4)

**ESCOPO TBD — DEFINIDO POR DEVIATION APÓS W3.**

Esta wave não tem tasks concretas pré-planejadas. O planner explicitamente recusa estimá-las antes de a auditoria rodar, porque o tipo de fix depende inteiramente da natureza do gap encontrado.

**Possíveis estratégias (CONTEXT, a/b/c/d):**

| Estratégia | Quando aplicar | Esforço estimado |
|------------|----------------|------------------|
| (a) Preencher `cust_id` faltante | Auditoria mostra N empresas com status `sem_cust_id` e ml_store_id/adman_account_id disponível em outra fonte | Pequeno: SQL UPDATE ou comando 1-shot |
| (b) Backfill de `adman_metrics` para dias falhados | Auditoria mostra gaps de N dias em empresas específicas (último sync em D-7 mas hoje é D-1) | Médio: comando `adman:backfill-missing-days --since=YYYY-MM-DD` reusando `SyncAdmanData` |
| (c) Revisar política tudo-ou-nada do cache | Auditoria mostra que cache parcial é causa-raiz mais comum do que dias faltantes | Médio: modificar `DashboardController` linhas 117-133 para aceitar parcial + sinalizar via SC-5 (já implementado em W5) |
| (d) Criar tabela `dashboard_daily_totals` | Auditoria mostra gap sistemático e crescente que não é (a)/(b)/(c) | Grande: requer migration + job de snapshot diário — **escalar ao usuário antes** (deviation contract) |

**Quando definir o escopo:**
1. Após `AUDIT-OUTPUT.md` existir
2. Executor lê output, identifica os 2-3 padrões dominantes
3. Executor propõe Plan W4 com 1-2 estratégias acima
4. Usuário aprova (especialmente se for (d) — touches schema)
5. Plan novo criado em `18-04-PLAN.md` ou `18-04.1-PLAN.md` dependendo da complexidade

**Risco residual:** se a auditoria mostrar que o gap é cumulativo de várias causas, W4 pode virar 2-3 sub-tasks paralelas. Aceito.

---

### Wave 5 — UI feedback e testes finais (SC-5, SC-6)

**Objetivo:** sinalizar honestamente quando o dado é aproximado e fechar a suíte de testes.

**Wave size:** 4 tasks, ~25% contexto.

#### Task W5-T1 — Indicador "≈ valor aproximado" nos cards

- **Files:** `resources/js/Pages/Dashboard/Admin.jsx`
- **Action:** consumir a prop `cards_exatos` (boolean) introduzida em W2-T3. Quando `cards_exatos === false`:
  - Modificar componente `KpiCard` (linhas 38-64) para aceitar prop opcional `approx?: boolean`
  - Quando `approx`, prepender `~` no value (`'~' + value` ou similar visualmente sutil — não substituir, só anotar) E adicionar atributo `title` no card raiz com texto: `"Dados aproximados — cache parcial ou período diferente de 30 dias. Use o range padrão para valores exatos."`
  - Aplicar `approx={!cards_exatos}` apenas nos cards que dependem do cache Adman: "Faturamento Total" (linha 308), "Invest. Ads (30d)" (linha 307), "TACOS Médio" (linha 305). Os cards de empresas/NPS são deterministas e não usam o cache (não recebem flag).
  - Adicionar `cards_exatos = true` ao desestrurar de props no `AdminDashboard` (linha 75-90), default `true` por segurança
  - pt-BR no tooltip; sem emoji; estilo discreto (text-white/40 ou similar)
- **Verify:**
  - `npm run build` verde
  - Manual: forçar fallback (limpar cache Redis) → cards mostram `~` e tooltip aparece; com cache hot + `period=30` não há indicador
- **Done:** UI distingue claramente exato vs aproximado nos 3 cards Adman-dependentes.

#### Task W5-T2 — Teste de UI flag aproximado

- **Files:** `tests/Feature/Phase18/DashboardFiltersTest.php` (estender)
- **Action:** adicionar 2 testes:
  - Teste 6: `actingAs(admin)->get('/?period=30')` com `Cache::flush()` no setup → prop `cards_exatos === false` (forced fallback)
  - Teste 7: `actingAs(admin)->get('/?period=7')` → prop `cards_exatos === false` mesmo com cache hot (porque period ≠ 30)
- **Verify:** `php artisan test --filter=DashboardFiltersTest` verde (7 testes totais somando W1+W2+W5)
- **Done:** Contrato `cards_exatos` blindado contra regressão.

#### Task W5-T3 — Build + suíte completa verde

- **Files:** nenhum (gate operacional)
- **Action:**
  1. `npm run build` — confirmar bundle Vite gerado sem warning JSX
  2. `php artisan test` — suíte completa (não só Phase18) verde
  3. Se algum teste pré-existente quebrar em arquivo NÃO tocado por Phase 18 (`Phase14*`, `Phase16*`, `Phase17*`, `DevControllerTest`, etc), documentar em `deferred-items.md` no diretório da phase. Não fixar nessa fase — fora do escopo.
  4. Se algum teste pré-existente quebrar em arquivo TOCADO por Phase 18 (`DashboardFiltersTest` nunca existiu antes, então só vai existir se já implementarmos — ok), **fixar**. Provavelmente nada quebra porque `DashboardController` não tinha cobertura Feature dedicada antes.
- **Verify:** ambos os comandos retornam sucesso; `deferred-items.md` criado se necessário com lista de regressões observadas
- **Done:** Build verde + suíte verde + deferred items rastreados.

#### Task W5-T4 — Checkpoint humano UX (deferred)

- **Type:** `checkpoint:human-verify` (blocking)
- **What built:** dashboard com período dinâmico + filtros empilháveis + indicador de aproximação
- **How to verify (passos para o usuário):**
  1. Login como admin
  2. Em `/`, conferir badge "Atualizado em DD/MM HH:mm · D-1 da Adman" presente (Phase 16, não regrediu)
  3. Selecionar empresa "X" no dropdown — observar URL: `?company_id=...`
  4. Trocar período para "Últimos 7 dias" — URL deve virar `?company_id=...&period=7` (empresa permanece)
  5. Cards "Faturamento", "Invest. Ads", "TACOS Médio" devem mostrar `~` prefix (aproximado, porque period ≠ 30 cai em DB)
  6. Voltar para "Últimos 30 dias" — `~` some se cache estiver hot (pode levar alguns segundos se o Job `RefreshGrossBillingCacheJob` ainda não rodou)
  7. Trocar analista/estrategista combinado com empresa e período — URL preserva todos
- **Resume signal:** "approved" ou descrição do problema
- **Deploy:** **NÃO executar deploy nesta fase.** Deploy é decisão do usuário após este checkpoint. Comentar isso no resumo final do executor.

## Pitfalls e mitigações

| # | Pitfall | Mitigação |
|---|---------|-----------|
| 1 | `compact('companyFilter')` gera chave camelCase | W1-T1 usa array literal explícito; **não** renomear as variáveis PHP (back-compat com mentor_id na linha 70) |
| 2 | ECFSelect em 3 lugares (linhas 252/258/264) | W1-T2 muda todos numa task só com grep verification |
| 3 | `$dateFrom30d` usado em 7+ sites (111, 112, 156-167, 251-258, 502) | W2-T2 lista todos explicitamente; site 502 (userDashboard) intencionalmente **não** tocado (out of scope) |
| 4 | Cache `RefreshGrossBillingCacheJob` cacheia só range 30d | Decisão W2-T3: ranges ≠ 30d caem em fallback DB intencionalmente; SC-5 sinaliza |
| 5 | `whereBetween('reference_date', [...])` em SQLite/MySQL com cast `date` | Strings Y-m-d em ambos os lados (não Carbon); pitfall Phase 15 já aplicado |
| 6 | Auditoria em produção = ~20min de execução | W3-T1 inclui `withProgressBar`; W3-T2 é MANUAL STEP com aviso explícito |
| 7 | Cache parcial tudo-ou-nada gera oscilação | W5-T1 indica visualmente; W4 pode decidir mudar a política se auditoria apontar |
| 8 | W4 escopo TBD | Documentado como deviation explícito; planner recusa estimar antes de W3 |
| 9 | `AdmanService::fetchPerformance` pode retornar 429 mesmo com throttle 7s | Comando W3-T1 usa o método existente (que já tem retry exponential interno linhas 219-227); registra erro como `status=erro_adman` e continua |
| 10 | Auditoria pode revelar gap sistemático que exige (d) nova tabela | W4 marca opção (d) como **escalar ao usuário antes** — não decidir sozinho |

## Não-objetivos (out of scope)

- Multi-select de empresas
- Range custom de datas (de/até livre)
- Refactor de `userDashboard` (linhas 450-569 do controller) — fica para Phase futura
- Mudar `RefreshGrossBillingCacheJob` (Phase 16) salvo se W3 identificar gap originado lá
- Reescrever `AdmanService::fetchPerformance` (estável)
- Migration ANTES de W3 (W4 pode introduzir `dashboard_daily_totals` SE auditoria mostrar gap sistemático e usuário aprovar)
- Otimizar performance da Dashboard além do necessário pros bugs
- Pre-warm de cache para ranges 1d/7d/180d (decisão W2-T3)
- Deploy automático no fim — deploy é decisão do usuário pós-W5-T4

## Deviation contract

**Pare e pergunte ao usuário se:**

1. **W2-T3 cache strategy precisar mudar** — ex: se durante W2-T2 ficar claro que algum cache secundário no controller (linha 117-133) precisa ser modificado para suportar ranges variados. Atual decisão é fallback DB; mudar requer aprovação.
2. **W4 precisar de migration** (estratégia (d) `dashboard_daily_totals`) — pause e proponha a migration ao usuário antes de criar.
3. **Suíte de testes existente regredir em arquivos NÃO tocados** — documentar em `deferred-items.md` e seguir; não fixar nessa fase.
4. **Comando `dashboard:audit-billing-divergence` falhar com erro de credencial Adman em ambiente de teste local** — orientar usuário a rodar em produção (W3-T2 é manual step justamente por isso).
5. **W3 auditoria revelar > 30% das empresas sem `cust_id`** — provavelmente é regressão de Phase 13 ou 16; pausar e investigar antes de criar W4 ((a) preencher cust_id) — pode ser causa-raiz em outra phase.
6. **`AdmanService::fetchPerformance` retornar shape diferente do esperado** (sem `summarizedData.grossBilling.value`) — pausar e revalidar contrato Adman antes de continuar W3.

## Por que este plano entrega o goal?

**Goal:** Dashboard precisa (acertividade) e filtros empilháveis (praticidade), eliminando 3 bugs do dia 2026-06-02.

| Goal element | Plano cobre via |
|--------------|-----------------|
| **Bug 1 — período não muda dados** | W2-T1 (helper) + W2-T2 (propagação em 7 sites) + W2-T4 (teste regression). Após W2 fechar, trocar `?period=7` recalcula `total_revenue`, `total_ad_investment_30d`, `avg_tacos`, e os fallbacks DB usam o range correto. **SC-2 ✓** |
| **Bug 2 — combinar filtros perde a empresa** | W1-T1 (backend snake_case) + W1-T2 (frontend snake_case) + W1-T3 (teste empilhamento). Após W1 fechar, controller devolve e lê a mesma chave; spread `...filters` no `applyFilter` propaga corretamente. **SC-1 ✓** |
| **Bug 3 — faturamento divergente** | W3 mede; W4 fixa (escopo TBD por deviation). SC-3 (auditoria) blindada por W3-T1 (comando) + W3-T2 (execução manual) + W3-T3 (teste). SC-4 (fix) tem 4 estratégias documentadas (a-d) que cobrem todas as causas-raiz hipotetizadas no CONTEXT. **SC-3 ✓, SC-4 ✓ (após W3)** |
| **Acertividade** (regra-mestra) | W5-T1 garante que a UI nunca mascara dados aproximados — quando há fallback ou period ≠ 30, `~` + tooltip explicam. O usuário **nunca vê dado errado camuflado de exato**. **SC-5 ✓** |
| **Praticidade** (regra-mestra) | W1 alinha naming; filtros combinam sem perder; W2 garante que trocar período entrega resposta diferente — o operador faz fluxo natural de filtragem sem precisar recarregar com mão. **SC-1, SC-2 ✓** |
| **Cobertura de testes** | W1-T3 (3 testes) + W2-T4 (2 testes) + W3-T3 (3 testes) + W5-T2 (2 testes) = **10 testes Phase 18**, cobrindo empilhamento, range dinâmico, auditoria com gap, e flag aproximado. **SC-6 ✓** |

**Camadas de defesa contra regressão futura:**
1. Teste 3 (W1-T3) é guard contra reaparecimento de camelCase
2. Teste 5 (W2-T4) é guard contra range hardcoded
3. Teste 7 (W5-T2) é guard contra cards exatos mascarando fallback
4. Comando W3-T1 pode ser re-executado a qualquer momento para reverificar a divergência (forma viva de monitoring)

**Risco residual conhecido:**
- W4 não tem escopo concreto até W3 rodar. Plano explicitamente aceita isso. Se W3 mostrar que o fix é estratégia (d) (nova tabela), executor para e escala ao usuário — não decide sozinho.
- Ranges ≠ 30d sempre vão usar fallback DB (W2-T3). Se o usuário insistir em dados exatos em qualquer período, requer Phase futura mudando `RefreshGrossBillingCacheJob` ou cache key strategy.
