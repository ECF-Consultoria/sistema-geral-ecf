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
    - "Existe comando Artisan read-only que diagnostica cust_id corrompido e tem flag --fix segura"
    - "Quando os cards caem em fallback DB ou cache parcial, a UI sinaliza com indicador 'aproximado' (sem mascarar dados)"
    - "Dashboard usa cache hibrido per-empresa: cache hit usa Adman exato, cache miss cai em DB local apenas para essa empresa (nao tudo-ou-nada)"
    - "Bug 3 (divergência de faturamento) tem fix aplicado com base nos achados da auditoria 30d — não chute"
  artifacts:
    - path: "app/Http/Controllers/DashboardController.php"
      provides: "getPeriodRange + propagação em todas as queries + cache híbrido per-empresa + filters em snake_case"
    - path: "resources/js/Pages/Dashboard/Admin.jsx"
      provides: "applyFilter usa filters.company_id/consultor_id/estrategista_id (snake_case)"
    - path: "app/Console/Commands/AuditBillingDivergence.php"
      provides: "Comando dashboard:audit-billing-divergence read-only"
    - path: "app/Console/Commands/DiagnoseCustId.php"
      provides: "Comando dashboard:diagnose-cust-id read-only por default; --fix limpa cust_id corrompido apenas quando seguro"
    - path: "tests/Feature/Phase18/DashboardFiltersTest.php"
      provides: "Empilhamento de filtros + período dinâmico + UI flag aproximado"
    - path: "tests/Feature/Phase18/AuditBillingDivergenceTest.php"
      provides: "Auditoria detecta gap propositado"
    - path: "tests/Feature/Phase18/DiagnoseCustIdTest.php"
      provides: "Diagnostico de cust_id classifica corretamente e --fix é seletivo"
    - path: "tests/Feature/Phase18/DashboardCacheHybridTest.php"
      provides: "Cache hibrido per-empresa: cache hit + cache miss coexistem"
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
    - from: "DiagnoseCustId command"
      to: "AdmanService::fetchPerformance + Company.adman_account_id"
      via: "classificacao OK/SUSPEITO_*/INVALIDO_CONFIRMADO/VALIDADO_API com throttle 7s"
    - from: "DashboardController (cache híbrido)"
      to: "getCachedGrossBillingsMany per-empresa"
      via: "loop empresa-a-empresa: cache hit → Adman; cache miss → SUM(adman_metrics) só para essa empresa"
---

# Phase 18: Dashboard precisa e com filtros empilháveis — Plano

## Resumo executivo

Plano em 5 waves para eliminar 3 bugs reportados pelo usuário em 2026-06-02 aplicando as regras-mestras **acertividade** (números batem com a fonte) e **praticidade** (filtros combinam de verdade) na Dashboard admin.

**Bugs alvo (verificados em HEAD contra `DashboardController.php` e `Admin.jsx`):**
1. **Período não afeta os cards principais** — `$dateFrom30d`/`$dateTo30d` são hardcoded em 30 dias (linhas 106-107 do controller) e alimentam todas as queries críticas (linhas 111-112, 156-167, 251-258). O seletor de `$period` só altera o chart de série temporal (linhas 196-204) e queries menores (NPS linha 211, meetings linha 217).
2. **Empresa some ao trocar período** — controller devolve `filters` em camelCase via `compact('companyFilter', 'consultorFilter', 'estrategistaFilter')` (linha 386), mas lê do request em snake_case (linhas 68-70). Frontend espalha `...filters` em camelCase no `applyFilter` (linha 95), gerando URL com chave inválida `?companyFilter=5` que o controller ignora.
3. **Faturamento divergente da Adman** — origem **medida pela auditoria W3-T2** (`AUDIT-OUTPUT-30d.txt`, 363 linhas): diff total R$ 39,3M (71,79%); 125/172 empresas com gap > R$ 1.000; 43 falhas Adman concentradas em IDs recentes (258-291) onde `adman_account_id == ml_store_id` com formato Seller ID ML (10 dígitos); 741 erros HTTP 429 pós-Phase 16 indicando caller residual furando o throttle global de 10 req/min; apenas 4 empresas com 30 dias completos em `adman_metrics`. **Backfill rejeitado** (custoso e dispensável — W4-T3 resolve via cache híbrido).

**Estratégia:** W1 corrige naming (fix barato, alto valor), W2 propaga período dinâmico (fix de raiz), W3 mede a divergência (read-only, ~20min de execução, **JÁ EXECUTADA** — output em `AUDIT-OUTPUT-30d.txt`), W4 ataca as 3 causas-raiz identificadas (cust_id corrompido + rate limit residual + política de cache tudo-ou-nada), W5 fecha com UI feedback + testes.

**Decisão W2-T3 já tomada:** ranges ≠ 30d caem em fallback DB (não pré-warm cache). Justificativa em W2.

**Decisão W4 aprovada pelo usuário (2026-06-02):** A + B + D do CONTEXT — fix de mapeamento + caçada ao rate limit + cache híbrido per-empresa. Estratégia C (backfill histórico) **rejeitada** como cara e dispensável; D resolve a precisão visível sem precisar reconstruir histórico.

## Goal e success criteria (citação literal do ROADMAP)

**Goal:** Aplicar diretamente as duas regras-mestras do projeto (**acertividade** + **praticidade**) na Dashboard, eliminando 3 bugs reportados pelo usuário em 2026-06-02. Os dados mostrados ao admin precisam (a) refletir o período selecionado, (b) preservar todos os filtros simultaneamente, e (c) bater com a Adman para o mesmo range.

**Success criteria (ROADMAP, Phase 18):**

- **SC-1 Filtros empilháveis** — frontend e backend usam exclusivamente snake_case (`company_id`, `consultor_id`, `estrategista_id`, `period`); nenhum filtro se perde ao alterar outro.
- **SC-2 Período afeta TODOS os cards** — helper `getPeriodRange(string $period): array{from: string, to: string}`; aplicado em todas as queries do controller.
- **SC-3 Auditoria executada** — `dashboard:audit-billing-divergence [--period=N]` compara `fetchPerformance` vs `SUM(adman_metrics.revenue)`; identifica empresas sem `cust_id`, dias faltantes, magnitude. **EXECUTADA — evidência em `AUDIT-OUTPUT-30d.txt`.**
- **SC-4 Fix do Bug 3 baseado nos achados** — escopo de W4 concretizado nas estratégias A+B+D (fix mapeamento `cust_id` + caçada de caller furando rate limit + cache híbrido per-empresa).
- **SC-5 UI sinaliza incerteza** — quando fallback DB ativo, cards mostram "≈ valor aproximado" sutil ou tooltip equivalente.
- **SC-6 Testes** — period preserva `company_id` e vice-versa; range derivado; auditoria detecta gap propositado; diagnóstico cust_id classifica corretamente; cache híbrido funciona empresa-a-empresa.

## Mapeamento criterion → tasks

| SC | Wave/Task | Resultado observável |
|----|-----------|----------------------|
| SC-1 | W1-T1, W1-T2, W1-T3 | URL final tem `?company_id=5&period=7&consultor_id=12` mesmo ao trocar filtros em qualquer ordem |
| SC-2 | W2-T1, W2-T2 | Trocar `period=7` reduz `total_revenue` em comparação a `period=30` (range menor → menos faturamento) |
| SC-3 | W3-T1, W3-T2, W3-T3 | Output `dashboard:audit-billing-divergence` em produção em `AUDIT-OUTPUT-30d.txt`; gap absoluto R$ 39,3M e 71,79% medidos |
| SC-4 | W4-T1, W4-T2, W4-T3, W4-T4, W4-T5 | Comando diagnose-cust-id classifica + opcionalmente limpa IDs corrompidos; caller furando rate limit identificado e mitigado; controller usa cache híbrido per-empresa; soma do card Faturamento bate com Adman para empresas com cust_id válido |
| SC-5 | W5-T1 | Card mostra "≈ aproximado" quando `grossCacheCompleto=false` OU `period ≠ 30` |
| SC-6 | W1-T3, W2-T4, W3-T3, W4-T4, W5-T2 | Suíte verde cobrindo empilhamento, período dinâmico, auditoria com gap propositado, diagnose cust_id, cache híbrido, flag aproximado |

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
- **Done:** Decisão documentada em código; prop nova disponível para W5-T1. **NOTA:** W4-T3 vai refinar `cards_exatos` para refletir o cache híbrido per-empresa (`cache_hits === total_companies_with_cust_id`), substituindo a condição tudo-ou-nada por uma medida mais granular.

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

#### Task W3-T2 — Execução em produção (MANUAL STEP) — ✅ EXECUTADA

**Esta task não tem código** — é tarefa operacional do usuário via SSH.

**Status:** ✅ Executada em 2026-06-02. Output salvo em `.planning/phases/18-dashboard-precisao-filtros/AUDIT-OUTPUT-30d.txt` (363 linhas).

**Sumário dos achados (evidência para W4):**
- 172 empresas ativas processadas, 1 sem `cust_id`, **43 falhas Adman** (HTTP 500/400/404), **125 com gap > R$ 1.000**
- Soma Adman: R$ 54.828.385,40 | Soma DB: R$ 15.466.468,55 | **Diff total: R$ 39.361.916,85 (71,79%)**
- Maioria das empresas com gap entre 60% e 85%
- Apenas 4 empresas com 30 dias completos em `adman_metrics`; 129 com 5-14 dias; 34 sem nenhum registro
- 741 erros HTTP 429 observados no log Adman do mesmo período (concorrência interna excedendo 10 req/min)
- Falhas 500 concentradas em IDs 258-291 (cadastros pós-Phase 13) com `adman_account_id == ml_store_id` em formato Seller ID ML (10 dígitos)

- **Files:** `.planning/phases/18-dashboard-precisao-filtros/AUDIT-OUTPUT-30d.txt`
- **Verify:** arquivo existe (363 linhas), sumário extraído acima.
- **Done:** Output disponível e diagnóstico cruzado com 3 causas-raiz que dirigem W4 (ver W4 abaixo).

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

### Wave 4 — Fix do Bug 3 baseado nos achados de W3 (SC-4)

**Escopo aprovado pelo usuário (2026-06-02): A + B + D — sem C (backfill rejeitado).**

A auditoria W3-T2 (`AUDIT-OUTPUT-30d.txt`, 363 linhas) confirmou 3 causas-raiz convergentes para os 71,79% de divergência. Cada causa tem uma task dedicada nesta wave.

**Mapeamento causa → task:**

| Causa identificada na auditoria | Task | Estratégia CONTEXT |
|----------------------------------|------|---------------------|
| Mapeamento `cust_id` corrompido (43 erros HTTP 500 em IDs 258-291) | **W4-T1** | A — Corrigir mapeamento |
| 741 erros HTTP 429 pós-Phase 16 (caller furando throttle de 10 req/min) | **W4-T2** | B — Caçar caller |
| Buracos históricos cumulativos em `adman_metrics` (só 4/172 empresas com 30 dias completos) | **W4-T3** | D — Cache híbrido per-empresa (resolve sem backfill) |
| ~~Backfill histórico (5h+ de Adman)~~ | ~~rejeitado~~ | ~~C — REJEITADO pelo usuário~~ |

**Wave size:** 5 tasks, ~40% contexto distribuído (2 comandos novos + refactor de cache no controller + 2 arquivos de teste + 1 manual step). Justificada pelo escopo concentrado em raiz; não precisa split.

**Referência viva:** Todas as decisões desta wave têm evidência em `.planning/phases/18-dashboard-precisao-filtros/AUDIT-OUTPUT-30d.txt`. Reler esse arquivo antes de executar qualquer task abaixo.

#### Task W4-T1 — Comando `dashboard:diagnose-cust-id` (Causa 1 — fix mapeamento)

- **Files:** `app/Console/Commands/DiagnoseCustId.php` (novo)
- **Action:** criar classe `DiagnoseCustId extends Command` com signature exata: `dashboard:diagnose-cust-id {--fix : Aplica correcoes automaticas (remove adman_account_id corrompido onde for seguro)}`. Construtor com injeção de `AdmanService`. Comando **read-only por default**; `--fix` é explícito e seletivo.
  - Comportamento do `handle`:
    1. Antes de tudo, ler `Company` model (em `app/Models/Company.php`) para confirmar a prioridade do accessor `cust_id` — usuário sinalizou que existe accessor que retorna `ml_store_id ?? adman_account_id` (ver commit `f9d0547`). Isso é crítico: ao decidir limpar `adman_account_id`, precisamos garantir que `ml_store_id` segue sendo retornado como `cust_id` válido pra ML/Adman.
    2. Listar empresas ativas onde `adman_account_id IS NOT NULL` AND `adman_account_id != ''`. Usar query Eloquent: `Company::where('active', true)->whereNotNull('adman_account_id')->where('adman_account_id', '!=', '')->get(['id', 'name', 'adman_account_id', 'ml_store_id', 'ml_oauth_ativo'])` (selecionar só colunas necessárias).
    3. Para cada empresa, calcular categoria inicial:
       - **OK**: `adman_account_id !== ml_store_id` E `adman_account_id` não tem formato Seller ID típico → mapeamento parece distinto e consistente
       - **SUSPEITO_IGUAIS**: `adman_account_id === ml_store_id` (provável engano de cadastro pós-Phase 13)
       - **SUSPEITO_FORMATO**: `strlen($adman_account_id) === 10 && ctype_digit($adman_account_id)` (formato Seller ID ML — Adman usa formato diferente)
    4. **Curto-circuito VALIDADO_OK:** para empresas com 1+ registro em `adman_metrics` nos últimos 60 dias COM `error_message IS NULL` (sync funcionou recentemente), upgrade da categoria para `VALIDADO_OK` independente do formato. Não precisa testar Adman.
       - Query: `AdmanMetric::where('company_id', $company->id)->where('reference_date', '>=', now()->subDays(60)->toDateString())->whereNull('error_message')->exists()`
    5. Para empresas com categoria final `SUSPEITO_IGUAIS` ou `SUSPEITO_FORMATO` (sem upgrade para VALIDADO_OK): chamar `$this->adman->fetchPerformance($company->cust_id, $ontem, $ontem)` com throttle 7s — captura erro e classifica:
       - `INVALIDO_CONFIRMADO` se `\Throwable` com HTTP 500/400/404 (Adman não reconhece — comparar via `str_contains($e->getMessage(), '500')` etc.; o `AdmanService` já encaixa o status code na mensagem)
       - `VALIDADO_API` se retorna 200 (cust_id válido, falso positivo)
       - `ERRO_INDEFINIDO` para outros erros (429, timeout, conexão) — não confunde com inválido
    6. Tabela final via `$this->table` com colunas: `['ID', 'Empresa', 'adman_account_id', 'ml_store_id', 'ml_oauth_ativo', 'Status', 'AÇÃO_SUGERIDA']`. Coluna `AÇÃO_SUGERIDA`:
       - `INVALIDO_CONFIRMADO + ml_oauth_ativo=true` → "Limpar adman_account_id (--fix faz isso)"
       - `INVALIDO_CONFIRMADO + ml_oauth_ativo=false` → "Revisar manualmente (sem ML como fallback)"
       - `SUSPEITO_* + VALIDADO_API` → "Nenhuma (falso positivo)"
       - `OK`/`VALIDADO_OK` → "—"
       - `ERRO_INDEFINIDO` → "Re-rodar diagnóstico"
    7. Sumário final via `$this->info`:
       - Total empresas analisadas
       - Por categoria: OK, VALIDADO_OK, SUSPEITO_IGUAIS, SUSPEITO_FORMATO, INVALIDO_CONFIRMADO, VALIDADO_API, ERRO_INDEFINIDO
       - **Subtotal crítico:** "Empresas com INVALIDO_CONFIRMADO e ml_oauth_ativo: N" (essas são candidatas seguras pro --fix)
    8. **Modo `--fix` (somente quando flag passada):**
       - Filtrar para empresas com `Status === 'INVALIDO_CONFIRMADO'` AND `ml_oauth_ativo === true` (porque essas têm dado real via ML como fallback — não dependem do Adman). **NÃO** limpar quando `ml_oauth_ativo === false` mesmo se INVALIDO_CONFIRMADO — risco de zerar dados.
       - Para cada uma: `Company::where('id', $company->id)->update(['adman_account_id' => null])` (preserva `ml_store_id` que continua válido como `cust_id` via accessor)
       - Log de cada update: `Log::info("[DiagnoseCustId] Limpou adman_account_id corrompido da empresa {$company->id} ({$company->name}); adman_account_id era '{$company->adman_account_id}', preservou ml_store_id='{$company->ml_store_id}'")`
       - Activity log: `activity()->performedOn($company)->log("adman_account_id corrompido removido via dashboard:diagnose-cust-id --fix (Phase 18 W4-T1)")`
       - Reportar via `$this->info` o count final de empresas atualizadas
    9. **Sem `--fix`: zero side effects** — apenas a tabela e o sumário.
  - **Throttle obrigatório:** `usleep(7_000_000)` (7s) entre chamadas Adman. Referenciar em comentário pt-BR: `ADMAN_RATE_LIMIT_RPM = 10` da Phase 16.
  - **Pitfall a confirmar antes de codar:** abrir `app/Models/Company.php` e localizar o accessor de `cust_id`. Se a prioridade for `ml_store_id ?: adman_account_id` (commit `f9d0547`), o fix funciona como descrito. Se for o inverso (`adman_account_id ?: ml_store_id`), reverter a lógica: limpar `adman_account_id` só faz sentido se `ml_store_id` for o que o accessor vai retornar como fallback. Documentar a verificação como comentário no topo do command.
- **Verify:**
  - `php artisan list | grep dashboard:diagnose-cust-id` lista o comando
  - `php artisan dashboard:diagnose-cust-id` (sem `--fix`) roda em DB de testes vazio sem erro
  - Tabela mostra colunas declaradas; sumário inclui o subtotal de "INVALIDO_CONFIRMADO + ml_oauth_ativo"
  - `git diff app/Models/Company.php` retorna vazio (não mexer no model)
- **Done:** Comando registrado; signature exata; categoria + AÇÃO_SUGERIDA por empresa; `--fix` seletivo e logado; throttle 7s; activity log gravado.

#### Task W4-T2 — Caçar caller que fura rate limit (Causa 2 — investigação primeiro, fix depois)

- **Files:** investigação primeiro (grep + leitura, sem código); depois 1-2 arquivos de fix conforme identificado
- **Action — Fase 1: Investigação (não código):**
  1. `grep -rn "fetchPerformance\|fetchGrossBilling\|fetchAccountMetrics\|fetchCampaigns\|fetchAdsMetrics" app/` — listar **todos** os callers do `AdmanService`.
  2. Para cada caller identificado, abrir o arquivo e verificar:
     - Tem throttle local (`usleep`, `sleep`, batch delay, `->delay()`)?
     - Roda em loop? Sobre quantas empresas?
     - É invocado em paralelo com outro caller? (ex: `RefreshGrossBillingCacheJob` rodando ao mesmo tempo que `adman:sync` semanal)
  3. **Suspeitos prioritários a confirmar (lista derivada do CONTEXT):**
     - `RefreshGrossBillingCacheJob` (`app/Jobs/RefreshGrossBillingCacheJob.php`) — já tem throttle 7s; confirmar que respeitado quando job é re-disparado por gatilhos manuais (ex: `cache:refresh-now`)
     - `AdmanController::syncAll` / `AdmanController::syncNow` — comentário diz que foi removido na Phase 16; **confirmar via grep** que o método não existe mais E que nenhuma rota aponta pra ele
     - `MlbController::syncTodasVendasAdman` — quick task `260602-k3e` restaurou despacho assíncrono; **verificar se** chama `fetchPerformance` por empresa e se tem throttle ou batch com delay (`->onQueue()->delay()`)
     - Comandos Artisan `adman:*` — listar via `php artisan list adman` e inspecionar cada um
     - `MercadoLivreService` — não deveria chamar Adman; só confirmar via grep
     - Jobs assíncronos em geral: `grep -rn "AdmanService" app/Jobs/` para descobrir consumidores indiretos
  4. **Output da fase 1:** lista priorizada de callers + análise de throttle de cada um. Documentar em comentário no topo do diff de W4-T2 OU como bloco anexo no `AUDIT-OUTPUT-30d.txt` (append).
- **Action — Fase 2: Mitigação (código):**
  - Para cada caller identificado SEM throttle adequado:
    - Se rodar em loop: adicionar `usleep(7_000_000)` entre chamadas (consistente com `RefreshGrossBillingCacheJob`)
    - Se for job único disparado em paralelo: envolver em `->delay()` ou serializar via queue connection dedicada
    - Se for endpoint HTTP (controller): mover pra job assíncrono que respeita o throttle
  - **Cenário limite documentado:** este task pode descobrir que não há caller óbvio violando o throttle. Nesse caso, a hipótese alternativa é **concorrência interna entre 2+ instâncias do `RefreshGrossBillingCacheJob`** (ex: cron despachando antes do anterior terminar — loop de 168 empresas × 7s = ~20min; se outro `adman:sync` rodar simultaneamente, dobra a quota). Mitigação para esse cenário:
    - Adicionar lock no job via `Cache::lock('refresh-gross-billing', 1800)` (30min) — pular execução se outro processo já tem o lock
    - Documentar a hipótese e mitigação no comentário do job
  - **NÃO mexer** na lógica de retry do `AdmanService::fetchPerformance` em si — ela é defensiva downstream e o problema é upstream (callers violando throttle).
- **Verify:**
  - `grep -rn "fetchPerformance" app/ | wc -l` retorna lista completa de callers documentada
  - Cada caller listado tem throttle confirmado por inspeção visual
  - Se lock adicionado: `Cache::lock('refresh-gross-billing', ...)` aparece no diff
  - Sem regressão em testes existentes do `RefreshGrossBillingCacheJob`
- **Done:** Lista completa de callers documentada; mitigação aplicada onde necessário OU hipótese de concorrência interna mitigada com lock; sem alteração no `AdmanService` core.

#### Task W4-T3 — Cache híbrido per-empresa no DashboardController (Causa 3 / Estratégia D)

- **Files:** `app/Http/Controllers/DashboardController.php`
- **Action:** refator do bloco de cache (linhas 117-167) **invertendo a precedência atual** de "tudo-ou-nada" para "por-empresa":
  - **Comportamento atual (a substituir):**
    - Tenta `getCachedGrossBillingsMany($custIds, $from, $to)` → retorna `[$cacheHits, $grossCacheCompleto]`
    - Se `$grossCacheCompleto === false`: descarta o cache inteiro e usa `SUM(adman_metrics)` para TODAS as empresas (linhas 117-133, 156-167)
    - Resultado: 1 empresa sem cache → toda a Dashboard cai pra DB
  - **Comportamento novo (após W4-T3):**
    - Tenta `getCachedGrossBillingsMany($custIds, $from, $to)` → ainda retorna o batch e a flag, mas a flag passa a ser **informativa** (não decide o caminho)
    - Para CADA empresa individual no loop de cálculo de revenue/ads:
      1. Se `isset($grossBatch[$company->cust_id])` (cache hit) → usar valor exato do Adman (`$grossBatch[$cust_id]['value']`)
      2. Se cache miss → consultar `AdmanMetric::where('company_id', $company->id)->whereBetween('reference_date', [$dateFromN, $dateToN])->sum('revenue')` **somente para essa empresa** (uma query indexada por company_id, cheap)
      3. Acumular no total: `$totalRevenue += $valor_da_empresa`; em paralelo, incrementar contador `$cacheHitsCount` ou `$cacheMissesCount`
    - Resultado: a Dashboard mistura fontes (Adman cache para empresas com hit; DB para empresas sem hit) — mais preciso que o tudo-ou-nada, e empresas com cust_id corrompido (que W4-T1 ainda não limpou) ainda contribuem com o valor DB em vez de zero.
  - **Avaliação do trade-off documentado nas linhas 91-95:** o comentário atual ("totais oscilantes em ±R$ 20M entre requests") refere-se ao comportamento ANTES da Phase 16, quando `getCachedGrossBillingsMany` ainda chamava a API em runtime sem TTL estável. Com cache 24h estável (Phase 16) e período fixo 30d alinhado, a oscilação é mínima — o cache hit/miss tende a ser estável dentro do mesmo dia. Documentar a reavaliação como comentário pt-BR de ~8 linhas substituindo o bloco original.
  - **Refinar `cards_exatos`:** mudar de "true sse `$grossCacheCompleto && $period === '30'`" (W2-T3) para "true sse `$cacheHitsCount === count($custIdsValidos) && $period === '30'`". `$custIdsValidos` = empresas com `cust_id` não-nulo (empresas sem cust_id ficam fora da medida pra não poluir a flag).
  - **Manter decisão W2-T3 intacta para period ≠ 30:** ranges 1d/7d/180d continuam caindo em fallback DB inteiro (porque o `RefreshGrossBillingCacheJob` só preenche 30d). O cache híbrido per-empresa só se aplica quando `$period === '30'`.
  - **NÃO mexer** em `userDashboard` (linhas 450-569) — out of scope; manter "TODO Phase 18+" comentado de W2-T2.
  - **NÃO mexer** em `RefreshGrossBillingCacheJob` — out of scope; ele segue preenchendo cache para 30d.
- **Verify:**
  - `grep -n "grossCacheCompleto" app/Http/Controllers/DashboardController.php` retorna ocorrências apenas em comentários ou na flag informativa (não mais como gate do fallback)
  - Smoke manual: forçar `Cache::flush()` em 1 empresa específica via Tinker; abrir `/` → essa empresa contribui com DB, demais com Adman; `cards_exatos === false` (porque tem pelo menos 1 miss)
  - Comparar `total_revenue` antes vs depois: deve **subir** em direção ao número Adman para a maioria das empresas (porque empresas com cache hit deixam de ser zeradas pela política tudo-ou-nada)
- **Done:** Política tudo-ou-nada eliminada; `cards_exatos` granular; range ≠ 30 mantém fallback DB completo; userDashboard intocado; `RefreshGrossBillingCacheJob` intocado.

#### Task W4-T4 — Testes de W4 (Diagnóstico cust_id + Cache híbrido)

- **Files:**
  - `tests/Feature/Phase18/DiagnoseCustIdTest.php` (novo)
  - `tests/Feature/Phase18/DashboardCacheHybridTest.php` (novo)
- **Action — DiagnoseCustIdTest:**
  - Teste 1: seed 1 empresa com `adman_account_id='ABC123'` (distinto de `ml_store_id='999888777'`) → comando classifica como `OK` no output. Mockar `AdmanService` para não ser chamado (assert `shouldNotReceive('fetchPerformance')` para essa empresa).
  - Teste 2: seed 1 empresa com `adman_account_id == ml_store_id == '1234567890'` (mesmo valor, 10 dígitos) → categoria inicial `SUSPEITO_IGUAIS`; mockar `fetchPerformance` para arremessar `\RuntimeException("Erro HTTP 500 ...")` → categoria final `INVALIDO_CONFIRMADO`. Assert output contém "INVALIDO_CONFIRMADO".
  - Teste 3: seed 1 empresa categoria `INVALIDO_CONFIRMADO` com `ml_oauth_ativo=true` + 1 empresa `INVALIDO_CONFIRMADO` com `ml_oauth_ativo=false`. Rodar com `--fix`. Assert que **apenas** a empresa com `ml_oauth_ativo=true` teve `adman_account_id` zerado (refetch via Eloquent); a outra permanece intacta. Assert activity log gravado para a empresa modificada.
  - Teste 4: seed 1 empresa `SUSPEITO_FORMATO`; mockar `fetchPerformance` retornando dados válidos → categoria final `VALIDADO_API`. Assert que `--fix` **não** mexe nessa empresa.
  - **Importante:** mockar `AdmanService` via `$this->mock(AdmanService::class)`. Mockar throttle dispensável (testes locais aceitam o sleep curto para 1-2 empresas).
- **Action — DashboardCacheHybridTest:**
  - Teste 1: seed 2 empresas ativas com `cust_id` válido (A com cust_id=AAA, B com cust_id=BBB). Pré-popular cache para A apenas (`Cache::put(...)` simulando hit) com valor R$ 10.000. Seed `AdmanMetric` para B com `revenue=R$ 5.000` no range 30d. Rodar `actingAs(admin)->get('/?period=30')`. Assert que `total_revenue === 15000.0` (A do cache + B do DB) e `cards_exatos === false` (porque B caiu em fallback).
  - Teste 2: seed 2 empresas A e B; pré-popular cache para ambas com valores conhecidos; assert `cards_exatos === true` e `total_revenue === soma_dos_caches`.
  - Teste 3 (regression guard): seed 1 empresa sem `cust_id` + 1 empresa com `cust_id` cache hit. Empresa sem cust_id não conta no denominador de `cards_exatos`. Assert `cards_exatos === true` (porque a única empresa com cust_id teve cache hit).
- **Verify:**
  - `php artisan test --filter=DiagnoseCustIdTest` verde (4 testes)
  - `php artisan test --filter=DashboardCacheHybridTest` verde (3 testes)
- **Done:** Lógica de classificação + `--fix` seletivo + cache híbrido cobertos por testes; regressão detectável.

#### Task W4-T5 — Execução operacional em produção (MANUAL STEP — checkpoint)

- **Type:** `checkpoint:human-action` (blocking)
- **Files:** nenhum
- **Action operacional (para o usuário executar, com apoio do orchestrator via SSH):**
  1. SSH no VPS Hostinger (`177.7.53.164`); `cd` no path do projeto em produção
  2. Rodar `php artisan dashboard:diagnose-cust-id` (sem `--fix`) — aguardar ~tempo proporcional ao número de SUSPEITOS × 7s (estimativa baseada em auditoria: ~43 empresas inválidas × 7s ≈ 5min se todas precisarem checagem Adman, menos se VALIDADO_OK curto-circuitar)
  3. Copiar output COMPLETO (tabela + sumário) e colar em `.planning/phases/18-dashboard-precisao-filtros/DIAGNOSE-CUSTID-OUTPUT.md` (novo arquivo)
  4. **Decisão do usuário:**
     - Revisar quais empresas estão como `INVALIDO_CONFIRMADO + ml_oauth_ativo=true` (candidatas seguras)
     - Decidir: rodar `--fix` automático OU fazer correção manual empresa-por-empresa via admin UI
  5. Se decisão for `--fix`: rodar `php artisan dashboard:diagnose-cust-id --fix` em produção; capturar output e log; commitar `DIAGNOSE-CUSTID-FIX-OUTPUT.md`
  6. (Opcional) Re-rodar `dashboard:audit-billing-divergence --period=30` pós-fix para confirmar redução do diff total — salvar como `AUDIT-OUTPUT-30d-pos-w4.txt`
- **What built:** comando `dashboard:diagnose-cust-id` operacional; usuário com visibilidade total dos cust_ids corrompidos antes de qualquer mutação
- **How to verify:**
  1. Arquivo `DIAGNOSE-CUSTID-OUTPUT.md` existe com tabela populada
  2. Sumário mostra contagem de cada categoria
  3. Se `--fix` rodou: arquivo `DIAGNOSE-CUSTID-FIX-OUTPUT.md` mostra empresas afetadas
- **Resume signal:** "fix aplicado" / "fix recusado" / descrição de issue
- **Done:** Diagnóstico rodado em produção; decisão do usuário registrada; output em artefatos rastreáveis.

---

### Wave 5 — UI feedback e testes finais (SC-5, SC-6)

**Objetivo:** sinalizar honestamente quando o dado é aproximado e fechar a suíte de testes.

**Wave size:** 4 tasks, ~25% contexto.

#### Task W5-T1 — Indicador "≈ valor aproximado" nos cards

- **Files:** `resources/js/Pages/Dashboard/Admin.jsx`
- **Action:** consumir a prop `cards_exatos` (boolean) introduzida em W2-T3 e refinada em W4-T3. Quando `cards_exatos === false`:
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
- **What built:** dashboard com período dinâmico + filtros empilháveis + indicador de aproximação + cache híbrido per-empresa
- **How to verify (passos para o usuário):**
  1. Login como admin
  2. Em `/`, conferir badge "Atualizado em DD/MM HH:mm · D-1 da Adman" presente (Phase 16, não regrediu)
  3. Selecionar empresa "X" no dropdown — observar URL: `?company_id=...`
  4. Trocar período para "Últimos 7 dias" — URL deve virar `?company_id=...&period=7` (empresa permanece)
  5. Cards "Faturamento", "Invest. Ads", "TACOS Médio" devem mostrar `~` prefix (aproximado, porque period ≠ 30 cai em DB)
  6. Voltar para "Últimos 30 dias" — `~` some se cache estiver hot E todas as empresas com cust_id válido tiverem hit (pós-W4-T1 + W4-T5, a maioria das 43 empresas inválidas terá sido limpa)
  7. Comparar `total_revenue` exibido na Dashboard vs último output de `dashboard:audit-billing-divergence --period=30` em produção — números devem se aproximar (não bater exatamente porque DB ainda tem buracos históricos)
  8. Trocar analista/estrategista combinado com empresa e período — URL preserva todos
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
| 6 | Auditoria em produção = ~20min de execução | W3-T1 inclui `withProgressBar`; W3-T2 é MANUAL STEP — **JÁ EXECUTADO**, output em `AUDIT-OUTPUT-30d.txt` |
| 7 | Cache parcial tudo-ou-nada gera oscilação | W4-T3 substitui pela política híbrida per-empresa; W5-T1 indica visualmente quando ainda há miss |
| 8 | ~~W4 escopo TBD~~ | **Resolvido:** A+B+D aprovado pelo usuário; tasks concretas W4-T1 a W4-T5 |
| 9 | `AdmanService::fetchPerformance` pode retornar 429 mesmo com throttle 7s | Comando W3-T1 e W4-T1 usam o método existente (que já tem retry exponential interno linhas 219-227); W4-T2 ataca a raiz (caller upstream furando o throttle global) |
| 10 | Auditoria pode revelar gap sistemático que exige nova tabela | Usuário rejeitou backfill/nova tabela; W4-T3 (cache híbrido) resolve sem migration |
| 11 | **Mapeamento `cust_id` corrompido** — 43 empresas com `adman_account_id == ml_store_id` em formato Seller ID ML (10 dígitos) | W4-T1 diagnostica + classifica + opcionalmente limpa via `--fix` seletivo (apenas empresas com `ml_oauth_ativo=true` para preservar fallback ML); ativa accessor `Company::cust_id` que prioriza `ml_store_id` |
| 12 | **Rate limit residual pós-Phase 16** — 741 erros HTTP 429 indicam caller ainda violando o throttle global de 10 req/min | W4-T2 inicia com grep abrangente de `fetchPerformance`; aplica throttle ou batch delay no caller faltante; cenário limite (sem caller óbvio) → mitiga via `Cache::lock` em `RefreshGrossBillingCacheJob` contra concorrência interna |
| 13 | **Cache híbrido (mistura Adman+DB)** — política nova pode confundir usuário se números oscilarem entre requests | W4-T3 documenta no código (reavaliação do trade-off das linhas 91-95); cache 24h estável da Phase 16 minimiza oscilação intra-dia; `cards_exatos` granular avisa o usuário quando há mistura |
| 14 | **`Company::cust_id` accessor** — fix de W4-T1 depende da prioridade de fallback no accessor (ml_store_id ?: adman_account_id, commit f9d0547) | W4-T1 começa lendo `app/Models/Company.php` para confirmar a ordem antes de codar; documenta a verificação como comentário no topo do command |
| 15 | **`--fix` agressivo limparia dados de empresas sem fallback ML** | W4-T1 é seletivo: só limpa quando `Status === INVALIDO_CONFIRMADO` AND `ml_oauth_ativo === true`. Empresas sem fallback ML ficam como AÇÃO_SUGERIDA="Revisar manualmente" |

## Não-objetivos (out of scope)

- Multi-select de empresas
- Range custom de datas (de/até livre)
- Refactor de `userDashboard` (linhas 450-569 do controller) — fica para Phase futura
- Mudar `RefreshGrossBillingCacheJob` (Phase 16) salvo o `Cache::lock` defensivo em W4-T2 (cenário limite)
- Reescrever `AdmanService::fetchPerformance` (estável; retry exponential já presente)
- Migration nova nesta fase — usuário rejeitou estratégia (d) "dashboard_daily_totals"; W4-T3 cobre via cache híbrido
- Backfill histórico de `adman_metrics` para os 60+ dias faltantes — rejeitado pelo usuário (custo 5h+ Adman; W4-T3 resolve a precisão visível sem precisar reconstruir histórico)
- Otimizar performance da Dashboard além do necessário pros bugs
- Pre-warm de cache para ranges 1d/7d/180d (decisão W2-T3)
- Deploy automático no fim — deploy é decisão do usuário pós-W5-T4

## Deviation contract

**Pare e pergunte ao usuário se:**

1. **W2-T3 cache strategy precisar mudar** — ex: se durante W2-T2 ficar claro que algum cache secundário no controller (linha 117-133) precisa ser modificado para suportar ranges variados. Atual decisão é fallback DB; mudar requer aprovação.
2. **W4-T2 não conseguir identificar caller furando rate limit** após grep + inspeção visual exaustiva — propor `Cache::lock` em `RefreshGrossBillingCacheJob` como mitigação contra concorrência interna e validar com o usuário antes de aplicar.
3. **W4-T1 detectar que `Company::cust_id` accessor não prioriza `ml_store_id`** — `--fix` ficaria perigoso; pausar e validar com o usuário se a ordem do accessor deve mudar OU se `--fix` deve ser desativado.
4. **Suíte de testes existente regredir em arquivos NÃO tocados** — documentar em `deferred-items.md` e seguir; não fixar nessa fase.
5. **Comando `dashboard:audit-billing-divergence` ou `dashboard:diagnose-cust-id` falhar com erro de credencial Adman em ambiente de teste local** — orientar usuário a rodar em produção (W3-T2 e W4-T5 são manual steps justamente por isso).
6. **W4-T5 diagnose em produção revelar > 80 empresas como INVALIDO_CONFIRMADO** (auditoria estimou ~43) — auditar se a categorização está super-agressiva antes de rodar `--fix`; possivelmente refinar a heurística SUSPEITO_FORMATO.
7. **`AdmanService::fetchPerformance` retornar shape diferente do esperado** (sem `summarizedData.grossBilling.value`) — pausar e revalidar contrato Adman antes de continuar W3 ou W4.

## Por que este plano entrega o goal?

**Goal:** Dashboard precisa (acertividade) e filtros empilháveis (praticidade), eliminando 3 bugs do dia 2026-06-02.

| Goal element | Plano cobre via |
|--------------|-----------------|
| **Bug 1 — período não muda dados** | W2-T1 (helper) + W2-T2 (propagação em 7 sites) + W2-T4 (teste regression). Após W2 fechar, trocar `?period=7` recalcula `total_revenue`, `total_ad_investment_30d`, `avg_tacos`, e os fallbacks DB usam o range correto. **SC-2 ✓** |
| **Bug 2 — combinar filtros perde a empresa** | W1-T1 (backend snake_case) + W1-T2 (frontend snake_case) + W1-T3 (teste empilhamento). Após W1 fechar, controller devolve e lê a mesma chave; spread `...filters` no `applyFilter` propaga corretamente. **SC-1 ✓** |
| **Bug 3 — faturamento divergente** | W3 mediu (auditoria 30d: diff R$ 39,3M / 71,79%, 43 cust_id corrompidos, 741 erros 429, 4/172 empresas com 30 dias completos). W4 ataca as 3 causas-raiz convergentes: **W4-T1** limpa mapeamento `cust_id` corrompido com `--fix` seletivo (preserva fallback ML), **W4-T2** identifica e mitiga o caller residual furando o throttle global de 10 req/min, **W4-T3** substitui a política de cache "tudo-ou-nada" por híbrido per-empresa (cache hit usa Adman exato; cache miss usa DB local apenas para essa empresa, sem zerar o resto). Backfill rejeitado pelo usuário; D resolve a precisão visível sem reconstruir histórico. **SC-3 ✓ (auditoria executada), SC-4 ✓ (A+B+D aprovado e modelado)** |
| **Acertividade** (regra-mestra) | W4-T3 elimina a perda massiva onde 1 empresa sem cache zerava o pool inteiro. W4-T1 corrige a fonte da divergência crônica (cust_id ML em vez de Adman). W4-T2 estanca o sangramento contínuo (429s gerando metrics com `error_message`). W5-T1 garante que a UI nunca mascara dados aproximados — quando há fallback ou period ≠ 30, `~` + tooltip explicam. O usuário **nunca vê dado errado camuflado de exato**. **SC-5 ✓** |
| **Praticidade** (regra-mestra) | W1 alinha naming; filtros combinam sem perder; W2 garante que trocar período entrega resposta diferente — o operador faz fluxo natural de filtragem sem precisar recarregar com mão. **SC-1, SC-2 ✓** |
| **Cobertura de testes** | W1-T3 (3 testes) + W2-T4 (2 testes) + W3-T3 (3 testes) + W4-T4 (4 testes DiagnoseCustId + 3 testes CacheHybrid = 7 testes) + W5-T2 (2 testes) = **17 testes Phase 18**, cobrindo empilhamento, range dinâmico, auditoria com gap, diagnóstico cust_id (incluindo `--fix` seletivo), cache híbrido per-empresa, e flag aproximado. **SC-6 ✓** |

**Camadas de defesa contra regressão futura:**
1. Teste 3 (W1-T3) é guard contra reaparecimento de camelCase
2. Teste 5 (W2-T4) é guard contra range hardcoded
3. Teste 7 (W5-T2) é guard contra cards exatos mascarando fallback
4. Teste W4-T4 DiagnoseCustIdTest #3 é guard contra `--fix` virar agressivo e zerar empresas sem fallback ML
5. Teste W4-T4 DashboardCacheHybridTest #1 é guard contra reversão da política tudo-ou-nada
6. Comando W3-T1 pode ser re-executado a qualquer momento para reverificar a divergência (forma viva de monitoring); comando W4-T1 pode ser re-executado periodicamente para detectar novos cust_id corrompidos em empresas recém-cadastradas

**Nota sobre evidência:** A auditoria 30d documentada em `.planning/phases/18-dashboard-precisao-filtros/AUDIT-OUTPUT-30d.txt` (363 linhas) é a única fonte de verdade que orienta o escopo concreto de W4. Toda decisão "A+B+D sem C" do usuário em 2026-06-02 está ancorada nesses números (R$ 39,3M de diff, 125 empresas com gap, 43 falhas Adman, 741 erros 429, 4/172 empresas com 30 dias completos). Antes de executar qualquer task de W4, reler esse arquivo é obrigatório.

**Risco residual conhecido:**
- W4-T2 pode descobrir que não há caller óbvio violando o throttle — nesse caso, a hipótese é concorrência interna no `RefreshGrossBillingCacheJob` e a mitigação será `Cache::lock` defensivo. Aceito como deviation #2.
- Ranges ≠ 30d sempre vão usar fallback DB (W2-T3). Se o usuário insistir em dados exatos em qualquer período, requer Phase futura mudando `RefreshGrossBillingCacheJob` ou cache key strategy.
- Empresas com `cust_id` corrompido e `ml_oauth_ativo=false` ficam de fora do `--fix` automático — precisam revisão manual pelo usuário (output do W4-T5 mostra essas como AÇÃO_SUGERIDA="Revisar manualmente"). Aceito: limpar adman_account_id sem fallback ML zeraria os dados dessas empresas na Dashboard.
- Backfill histórico não foi feito → dias faltantes em `adman_metrics` permanecem; cache híbrido mitiga ao usar Adman exato para empresas com cust_id válido, mas empresas que estão no DB com dados parciais (5-14 dias dos 30) ainda vão divergir do Adman quando o cache miss as empurra para o fallback DB. Aceito por decisão explícita do usuário (custo > benefício).
