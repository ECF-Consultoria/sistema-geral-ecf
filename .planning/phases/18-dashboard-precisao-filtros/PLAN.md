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
    - "Empresas com cust_id corrompido sem fallback ML ficam marcadas como 'Cust ID Inválido' na UI (badge + filtro), permitindo ao operador identificar e tratar manualmente (conectar OAuth ML ou corrigir cadastro)"
  artifacts:
    - path: "app/Http/Controllers/DashboardController.php"
      provides: "getPeriodRange + propagação em todas as queries + cache híbrido per-empresa + filters em snake_case + cust_id_status em companies_performance"
    - path: "resources/js/Pages/Dashboard/Admin.jsx"
      provides: "applyFilter usa filters.company_id/consultor_id/estrategista_id (snake_case) + indicador ~ aproximado + badge Cust ID Inválido na lista de empresas"
    - path: "app/Console/Commands/AuditBillingDivergence.php"
      provides: "Comando dashboard:audit-billing-divergence read-only"
    - path: "app/Console/Commands/DiagnoseCustId.php"
      provides: "Comando dashboard:diagnose-cust-id read-only por default; --fix limpa cust_id corrompido apenas quando seguro"
    - path: "app/Console/Commands/MarkCustIdStatus.php"
      provides: "Comando dashboard:mark-custid-status que persiste cust_id_status na tabela companies (apenas a flag, nunca o cust_id em si)"
    - path: "database/migrations/2026_06_02_180000_add_cust_id_status_to_companies.php"
      provides: "Coluna cust_id_status ENUM('ok','invalido','desconhecido','nao_aplicavel') default 'desconhecido'"
    - path: "tests/Feature/Phase18/DashboardFiltersTest.php"
      provides: "Empilhamento de filtros + período dinâmico + UI flag aproximado"
    - path: "tests/Feature/Phase18/AuditBillingDivergenceTest.php"
      provides: "Auditoria detecta gap propositado"
    - path: "tests/Feature/Phase18/DiagnoseCustIdTest.php"
      provides: "Diagnostico de cust_id classifica corretamente e --fix é seletivo"
    - path: "tests/Feature/Phase18/DashboardCacheHybridTest.php"
      provides: "Cache hibrido per-empresa: cache hit + cache miss coexistem"
    - path: "tests/Feature/Phase18/MarkCustIdStatusTest.php"
      provides: "Comando mark-custid-status atualiza cust_id_status corretamente por categoria"
    - path: "tests/Feature/Phase18/CompaniesCustIdFilterTest.php"
      provides: "Filtro ?cust_id_status=invalido em /companies retorna apenas as marcadas"
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
    - from: "MarkCustIdStatus command"
      to: "companies.cust_id_status"
      via: "UPDATE apenas a flag (nunca o cust_id), reusa classificação do DiagnoseCustId"
    - from: "Companies/Index.jsx + Dashboard/Admin.jsx + Sugadores/Index.jsx"
      to: "companies.cust_id_status"
      via: "badge visual quando status==='invalido'; controllers expõem a flag em payloads existentes"
---

# Phase 18: Dashboard precisa e com filtros empilháveis — Plano

## Resumo executivo

Plano em 5 waves para eliminar 3 bugs reportados pelo usuário em 2026-06-02 aplicando as regras-mestras **acertividade** (números batem com a fonte) e **praticidade** (filtros combinam de verdade) na Dashboard admin.

**Bugs alvo (verificados em HEAD contra `DashboardController.php` e `Admin.jsx`):**
1. **Período não afeta os cards principais** — `$dateFrom30d`/`$dateTo30d` são hardcoded em 30 dias (linhas 106-107 do controller) e alimentam todas as queries críticas (linhas 111-112, 156-167, 251-258). O seletor de `$period` só altera o chart de série temporal (linhas 196-204) e queries menores (NPS linha 211, meetings linha 217).
2. **Empresa some ao trocar período** — controller devolve `filters` em camelCase via `compact('companyFilter', 'consultorFilter', 'estrategistaFilter')` (linha 386), mas lê do request em snake_case (linhas 68-70). Frontend espalha `...filters` em camelCase no `applyFilter` (linha 95), gerando URL com chave inválida `?companyFilter=5` que o controller ignora.
3. **Faturamento divergente da Adman** — origem **medida pela auditoria W3-T2** (`AUDIT-OUTPUT-30d.txt`, 363 linhas): diff total R$ 39,3M (71,79%); 125/172 empresas com gap > R$ 1.000; 43 falhas Adman concentradas em IDs recentes (258-291) onde `adman_account_id == ml_store_id` com formato Seller ID ML (10 dígitos); 741 erros HTTP 429 pós-Phase 16 indicando caller residual furando o throttle global de 10 req/min; apenas 4 empresas com 30 dias completos em `adman_metrics`. **Backfill rejeitado** (custoso e dispensável — W4-T3 resolve via cache híbrido).

**Estratégia:** W1 corrige naming (fix barato, alto valor), W2 propaga período dinâmico (fix de raiz), W3 mede a divergência (read-only, ~20min de execução, **JÁ EXECUTADA** — output em `AUDIT-OUTPUT-30d.txt`), W4 ataca as 3 causas-raiz identificadas (cust_id corrompido + rate limit residual + política de cache tudo-ou-nada — **W4-T5 já executado em prod**: 32 INVALIDO_CONFIRMADO + 134 VALIDADO_OK + 0 candidatas seguras pro --fix), W5 fecha com persistência do diagnóstico (migration + comando `mark-custid-status`), UI badges "Cust ID Inválido" em 3 sites + filtro em /companies, indicador "≈ aproximado" nos cards, e suíte completa de testes.

**Decisão W2-T3 já tomada:** ranges ≠ 30d caem em fallback DB (não pré-warm cache). Justificativa em W2.

**Decisão W4 aprovada pelo usuário (2026-06-02):** A + B + D do CONTEXT — fix de mapeamento + caçada ao rate limit + cache híbrido per-empresa. Estratégia C (backfill histórico) **rejeitada** como cara e dispensável; D resolve a precisão visível sem precisar reconstruir histórico.

**Decisão W5 (2026-06-02) pós-diagnose em prod:** o diagnóstico W4-T5 revelou que nenhuma das 32 empresas `INVALIDO_CONFIRMADO` tem `ml_oauth_ativo=true` — `--fix` automático seria inseguro (zeraria dados sem fallback). Usuário decidiu: (a) tentar correção manual via XML enviado pelo lado do negócio (TBD, Phase 18.5); (b) enquanto isso, **marcar visualmente** essas empresas como "Cust ID Inválido" na UI para que operadores identifiquem e tratem (conectar OAuth ML ou ajustar cadastro). W5 implementa essa marcação persistida + badges + filtro.

## Goal e success criteria (citação literal do ROADMAP)

**Goal:** Aplicar diretamente as duas regras-mestras do projeto (**acertividade** + **praticidade**) na Dashboard, eliminando 3 bugs reportados pelo usuário em 2026-06-02. Os dados mostrados ao admin precisam (a) refletir o período selecionado, (b) preservar todos os filtros simultaneamente, e (c) bater com a Adman para o mesmo range.

**Success criteria (ROADMAP, Phase 18):**

- **SC-1 Filtros empilháveis** — frontend e backend usam exclusivamente snake_case (`company_id`, `consultor_id`, `estrategista_id`, `period`); nenhum filtro se perde ao alterar outro.
- **SC-2 Período afeta TODOS os cards** — helper `getPeriodRange(string $period): array{from: string, to: string}`; aplicado em todas as queries do controller.
- **SC-3 Auditoria executada** — `dashboard:audit-billing-divergence [--period=N]` compara `fetchPerformance` vs `SUM(adman_metrics.revenue)`; identifica empresas sem `cust_id`, dias faltantes, magnitude. **EXECUTADA — evidência em `AUDIT-OUTPUT-30d.txt`.**
- **SC-4 Fix do Bug 3 baseado nos achados** — escopo de W4 concretizado nas estratégias A+B+D (fix mapeamento `cust_id` + caçada de caller furando rate limit + cache híbrido per-empresa).
- **SC-5 UI sinaliza incerteza + identifica empresas defeituosas** — cards mostram "≈ valor aproximado" quando fallback DB ativo OU period ≠ 30; empresas com `cust_id_status='invalido'` exibem badge "Cust ID Inválido" em `/companies`, no card de empresa do Dashboard e no card de empresa de Sugadores; `/companies` ganha filtro `?cust_id_status=invalido`.
- **SC-6 Testes** — period preserva `company_id` e vice-versa; range derivado; auditoria detecta gap propositado; diagnóstico cust_id classifica corretamente; cache híbrido funciona empresa-a-empresa; comando `mark-custid-status` atualiza a flag por categoria; filtro `?cust_id_status=invalido` retorna o subset esperado.

## Mapeamento criterion → tasks

| SC | Wave/Task | Resultado observável |
|----|-----------|----------------------|
| SC-1 | W1-T1, W1-T2, W1-T3 | URL final tem `?company_id=5&period=7&consultor_id=12` mesmo ao trocar filtros em qualquer ordem |
| SC-2 | W2-T1, W2-T2 | Trocar `period=7` reduz `total_revenue` em comparação a `period=30` (range menor → menos faturamento) |
| SC-3 | W3-T1, W3-T2, W3-T3 | Output `dashboard:audit-billing-divergence` em produção em `AUDIT-OUTPUT-30d.txt`; gap absoluto R$ 39,3M e 71,79% medidos |
| SC-4 | W4-T1, W4-T2, W4-T3, W4-T4, W4-T5 | Comando diagnose-cust-id classifica + opcionalmente limpa IDs corrompidos; caller furando rate limit identificado e mitigado; controller usa cache híbrido per-empresa; soma do card Faturamento bate com Adman para empresas com cust_id válido; W4-T5 executado em prod confirmou 32 INVALIDO_CONFIRMADO + 0 candidatas seguras para --fix automático |
| SC-5 | W5-T1, W5-T2, W5-T3, W5-T4, W5-T5 | Migration persiste `cust_id_status`; comando `mark-custid-status` rotula as 32 invalidas + 134 ok; badge "Cust ID Inválido" visível em `/companies`, Dashboard `companies_performance` e Sugadores cards; filtro `?cust_id_status=invalido` em `/companies`; cards Dashboard com `~` prefix quando `cards_exatos===false` |
| SC-6 | W1-T3, W2-T4, W3-T3, W4-T4, W5-T6 | Suíte verde cobrindo empilhamento, range dinâmico, auditoria com gap propositado, diagnose cust_id, cache híbrido, `mark-custid-status`, filtro Companies + flag aproximado |

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
- **Action:** documentar a decisão no método `adminDashboard` antes do bloco do cache (próximo à linha 105). Comentário pt-BR de ~6 linhas explicando que cache só é hot para `period=30` (alinhado com RefreshGrossBillingCacheJob); demais ranges caem em fallback DB intencionalmente; UI sinaliza isso em SC-5. Adicionar uma flag derivada `$cardsExatos = $grossCacheCompleto && $period === '30';` e passá-la como prop `cards_exatos` no Inertia render (linha 354+) — W5-T5 vai consumir isso. (Nome `cards_exatos` é positivo — "exatos" quando true, "aproximado" quando false; mais natural em pt-BR que `cards_approx`.)
- **Verify:** prop `cards_exatos` aparece no JSON do Inertia response (smoke via `dd($props)` ou inspeção no DevTools)
- **Done:** Decisão documentada em código; prop nova disponível para W5-T5. **NOTA:** W4-T3 vai refinar `cards_exatos` para refletir o cache híbrido per-empresa (`cache_hits === total_companies_with_cust_id`), substituindo a condição tudo-ou-nada por uma medida mais granular.

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

#### Task W4-T5 — Execução operacional em produção (MANUAL STEP — checkpoint) — ✅ EXECUTADA

- **Type:** `checkpoint:human-action` (blocking)
- **Status:** ✅ Executada em 2026-06-02. Output salvo em `.planning/phases/18-dashboard-precisao-filtros/DIAGNOSE-CUSTID-OUTPUT.txt`.
- **Sumário do diagnóstico em prod:**
  - 168 empresas analisadas (4 menos que a auditoria — 4 inativas excluídas pelo `where('active', true)`)
  - **134 VALIDADO_OK** (cache `adman_metrics` recente — curto-circuito ativado)
  - **32 INVALIDO_CONFIRMADO** (HTTP 500 do Adman; todas com `adman_account_id == ml_store_id` e SEM `ml_oauth_ativo`)
  - 2 ERRO_INDEFINIDO (rate limit ou timeout)
  - **0 candidatas seguras pro `--fix`** — nenhuma das 32 INVALIDO_CONFIRMADO tem ML OAuth como fallback
- **Decisão do usuário (2026-06-02) pós-diagnóstico:**
  - `--fix` **não rodado** em prod (zeraria dados das 32 sem fallback)
  - Plano operacional: aguardar XML de cust_ids corretos (do lado do negócio) para comparar com o DB e atualizar onde houver divergência (Phase 18.5 — TBD)
  - Enquanto isso: marcar essas empresas visualmente como "Cust ID Inválido" na UI (lado operador identifica e trata: conectar OAuth ML ou ajustar cadastro). Esse é o escopo principal do W5 expandido.
- **Files:** `.planning/phases/18-dashboard-precisao-filtros/DIAGNOSE-CUSTID-OUTPUT.txt`
- **Verify:** arquivo existe; sumário consistente com 134+32+2 = 168.
- **Done:** Diagnóstico em prod com decisão registrada; W5 implementa o caminho operacional (marcação visual) em vez de `--fix` automático.

---

### Wave 5 — Persistência do diagnóstico cust_id + UI marcação + indicador aproximado + suíte final (SC-5, SC-6)

**Objetivo:** transformar o diagnóstico W4-T5 em estado persistido + visibilidade UI. Como `--fix` automático foi descartado (0 candidatas seguras), a estratégia muda: persistir uma flag `cust_id_status` por empresa, exibir badge "Cust ID Inválido" nos 3 sites onde o operador interage com empresas, filtrar em `/companies`, manter o indicador "≈ aproximado" original nos cards do Dashboard, e fechar com a suíte completa.

**Wave size:** 6 tasks de implementação + 1 manual step + 1 future deviation, ~40% contexto (migration + comando novo + 3 sites JSX + 3 controllers + 2 arquivos de teste novos + 1 teste estendido). Justifica-se: escopo concentrado em 1 tabela + 3 sites análogos + reuso direto da classificação W4-T1.

**Referência viva:** Reler `DIAGNOSE-CUSTID-OUTPUT.txt` antes de começar — 32 empresas alvo do badge + 134 que ficam OK + comportamento esperado dos counters.

#### Task W5-T1 — Migration `add_cust_id_status_to_companies`

- **Files:**
  - `database/migrations/2026_06_02_180000_add_cust_id_status_to_companies.php` (novo)
  - `app/Models/Company.php` (adicionar à `$fillable` e `$casts`)
- **Action:**
  1. Migration `up()`:
     - `Schema::table('companies', function (Blueprint $table) { ... })`
     - Adicionar coluna `cust_id_status` ENUM com valores `'ok'`, `'invalido'`, `'desconhecido'`, `'nao_aplicavel'` — default `'desconhecido'`, nullable=false
     - Posicionar após `adman_account_id` via `->after('adman_account_id')`
     - Comment em pt-BR via `->comment('Status do mapeamento cust_id; preenchido por dashboard:mark-custid-status (Phase 18 W5)')`
  2. Migration `down()`:
     - `Schema::table('companies', fn($t) => $t->dropColumn('cust_id_status'))`
  3. `Company` model:
     - Adicionar `'cust_id_status'` em `$fillable` (mantendo ordem alfabética dentro do array)
     - Adicionar `'cust_id_status' => 'string'` em `$casts` (ENUM se lê como string em Eloquent)
     - **NÃO** criar accessor — campo é lido direto na UI
  4. **NÃO** criar seeder ou backfill aqui — o comando W5-T2 popula a coluna em separado (separação de responsabilidades + facilita rerun)
- **Verify:**
  - `php artisan migrate` aplica sem erro em ambiente de teste
  - `php artisan tinker` → `App\Models\Company::first()->cust_id_status` retorna `'desconhecido'` para registros existentes
  - `php artisan migrate:rollback` reverte sem erro
  - `grep -n "cust_id_status" app/Models/Company.php` retorna ocorrências em `$fillable` e `$casts`
- **Done:** Coluna persistida com default 'desconhecido' em todas as empresas; model aceita assignment + leitura.

#### Task W5-T2 — Comando `dashboard:mark-custid-status`

- **Files:** `app/Console/Commands/MarkCustIdStatus.php` (novo)
- **Action:** criar classe `MarkCustIdStatus extends Command` com signature `dashboard:mark-custid-status {--dry-run : Mostra mudanças sem persistir}`. Construtor com injeção de `AdmanService` (reusa pra `SUSPEITO_*` que precisam testar API).
  - Comportamento do `handle`:
    1. Reusar a classificação do `DiagnoseCustId` (W4-T1) — extrair a lógica de categorização para um trait/helper compartilhado OU duplicar inline com comentário pt-BR `// Espelha DiagnoseCustId::classificarEmpresa — refator futuro pode extrair pra trait`. Decisão recomendada: duplicar agora (escopo W5 é entregar a marcação; refator de DRY pode entrar em Phase 18.5).
    2. Iterar TODAS as empresas (não só `active=true` — `cust_id_status` faz sentido também pra inativas pra consistência). Mas filtrar `where active = true` se isso conflitar com performance — neste DB de 168 empresas ativas, sem ressalva.
    3. Para cada empresa, determinar `$novoStatus` baseado na categoria:
       - **OK / VALIDADO_OK / VALIDADO_API** → `'ok'`
       - **INVALIDO_CONFIRMADO** → `'invalido'`
       - **ERRO_INDEFINIDO** → **MANTÉM** status anterior (não persiste — categoria transitória; commentar no código)
       - **Empresa sem `cust_id`** (nem `adman_account_id` nem `ml_store_id`) → `'nao_aplicavel'`
       - **SUSPEITO_IGUAIS / SUSPEITO_FORMATO sem upgrade** (não conseguiu testar Adman) → **MANTÉM** status anterior (transitório)
    4. Aplicar throttle 7s entre chamadas Adman (mesma constante `ADMAN_RATE_LIMIT_RPM`). Curto-circuito VALIDADO_OK economiza ~134 chamadas — rodada típica em prod ≈ 32 SUSPEITOS × 7s ≈ 4min, mais 2 ERRO ≈ 14s, total ≈ 5min na prática.
    5. Coletar contadores: `$contadores = ['ok' => 0, 'invalido' => 0, 'nao_aplicavel' => 0, 'mantido' => 0]`
    6. Antes de persistir, comparar `$novoStatus !== $company->cust_id_status` — só faz UPDATE se mudou (economiza writes + activity log).
    7. **Modo padrão (sem `--dry-run`):** `$company->update(['cust_id_status' => $novoStatus])` para cada mudança. **CRÍTICO:** o `update()` só toca a coluna `cust_id_status` — nunca `cust_id`, `adman_account_id` ou `ml_store_id`. Confirmar via Eloquent que `$fillable` cobre só o campo certo (W5-T1 garante isso).
    8. Activity log enxuto: `activity()->performedOn($company)->log("cust_id_status atualizado de '{$antigo}' para '{$novoStatus}' via dashboard:mark-custid-status (Phase 18 W5)")` — somente quando muda.
    9. **Modo `--dry-run`:** mesma classificação, mesmo loop, mas sem `update()`/activity log; só imprime a tabela do que seria mudado.
    10. Sumário final via `$this->info` mostrando os contadores + total processado + tempo decorrido.
  - **NUNCA atualizar `cust_id`/`adman_account_id`/`ml_store_id`** — esse comando é apenas pra flag de status. Repetir esse aviso no docblock da classe.
  - **Schedule (opcional, decisão do planner):** **não** adicionar ao scheduler nesta fase. Justificativa: 5min de chamadas Adman × 1×/dia é leve, mas: (a) o estado é estável dia-a-dia (não vai mudar todo dia), (b) `--fix` automático foi descartado então não há mutação cascata, (c) o operador roda manualmente quando suspeitar de novo cadastro corrompido. Documentar no docblock: "Executar manualmente após cadastros novos ou periodicamente (1x/semana sugerido) via SSH."
- **Verify:**
  - `php artisan list | grep dashboard:mark-custid-status` lista o comando
  - `php artisan dashboard:mark-custid-status --dry-run` em ambiente de testes (DB vazio) roda sem erro
  - `grep -n "->update(\['cust_id_status'" app/Console/Commands/MarkCustIdStatus.php` confirma que o UPDATE é da flag apenas
  - `grep -n "->update(\['cust_id'\|adman_account_id\|ml_store_id" app/Console/Commands/MarkCustIdStatus.php` retorna 0 matches (não há mutação dos IDs)
- **Done:** Comando registrado; classificação reaproveitada do W4-T1; `--dry-run` disponível; UPDATE apenas da flag; activity log preciso.

#### Task W5-T3 — Backend: expor `cust_id_status` nos 3 controllers

- **Files:**
  - `app/Http/Controllers/CompanyController.php`
  - `app/Http/Controllers/DashboardController.php`
  - `app/Http/Controllers/SugadorController.php`
- **Action:**
  1. **CompanyController::index (`app/Http/Controllers/CompanyController.php`, método `index` a partir da linha 27):**
     - No `$companies = $companies->map(fn($c) => [...])` (linhas 39-65), adicionar a chave `'cust_id_status' => $c->cust_id_status` (coluna direta do model após W5-T1). Posicionar próximo às demais flags de status (próximo a `'status'` na linha 45).
  2. **DashboardController::adminDashboard:**
     - No bloco que monta `companies_performance` (linhas 391-402 conforme W2-T2), adicionar `'cust_id_status' => $company->cust_id_status` ao array por empresa. Recuperar o valor sem N+1 — `Company::where('active', true)->get(['id', 'name', 'cust_id_status', ...])` (já carrega tudo numa query única; só adicionar a coluna na lista de SELECT se houver lista explícita; caso contrário `->get()` traz tudo).
  3. **SugadorController::index:**
     - O array `companies_summary` é construído em `$companiesSummary` (referenciado na linha 202). Localizar onde o array por empresa é montado (próximo às linhas 165-191 conforme leitura prévia) e adicionar `'cust_id_status' => $company->cust_id_status` ao payload de cada empresa. **Importante:** confirmar via Read antes que o loop tem acesso ao model `Company` (não só ao array já reduzido) — se não tiver, fazer join/lookup mínimo via `Company::find($id)->cust_id_status` cacheado em map. Otimização: usar `Company::whereIn('id', $companyIds)->pluck('cust_id_status', 'id')` antes do loop e ler do map dentro.
- **Verify:**
  - `php artisan tinker` → `app(App\Http\Controllers\CompanyController::class)->index(new Illuminate\Http\Request())` (em ambiente de teste com 1 empresa seedada) retorna `companies[0]['cust_id_status']` populado
  - `grep -n "cust_id_status" app/Http/Controllers/CompanyController.php app/Http/Controllers/DashboardController.php app/Http/Controllers/SugadorController.php` retorna 3 ocorrências (1 por controller)
  - Smoke: abrir `/`, `/companies`, `/sugadores` em dev — DevTools Network → Inertia response inclui `cust_id_status` em cada empresa
- **Done:** Os 3 endpoints expõem a flag; sem N+1; payload retrocompatível (campo novo, não muda os existentes).

#### Task W5-T4 — Frontend: badge "Cust ID Inválido" + filtro em /companies + indicador `~ aproximado`

- **Files:**
  - `resources/js/Pages/Companies/Index.jsx`
  - `resources/js/Pages/Dashboard/Admin.jsx`
  - `resources/js/Pages/Sugadores/Index.jsx`
  - `app/Http/Controllers/CompanyController.php` (filtro query param)
- **Action:**
  1. **Componente badge reutilizável (inline em cada arquivo OU helper compartilhado):** decisão recomendada — **inline em cada arquivo** (sem criar Components/ui novos), porque os 3 sites têm densidade visual diferente e um componente compartilhado adicionaria abstração desnecessária pra um badge de 1 linha. Padrão visual:
     - Texto: `Cust ID Inválido`
     - Cor: amber/red sutil (ex: `bg-red-500/10 text-red-400 border border-red-500/20`)
     - Tamanho: pequeno (`text-[10px] px-1.5 py-0.5 rounded font-semibold tracking-wide`)
     - Renderizar APENAS quando `empresa.cust_id_status === 'invalido'` — `'desconhecido'`/`'ok'`/`'nao_aplicavel'` ficam sem badge
     - **Sem emoji** (regra do projeto)
     - Tooltip `title="Cust ID corrompido — Adman não reconhece. Conectar OAuth ML ou ajustar cadastro."`
  2. **`Companies/Index.jsx`:**
     - Localizar o local onde o nome da empresa é renderizado na lista (linha por empresa)
     - Adicionar badge ao lado do nome quando `company.cust_id_status === 'invalido'`
     - Manter compatibilidade com layout existente (margem `ml-2` ou similar)
  3. **`Dashboard/Admin.jsx` (lista `companies_performance` linhas 392-407):**
     - Dentro do `.map(c => ...)`, no bloco `<p className="text-white/80 text-[13px] font-semibold">{c.name}</p>` (linha 395), envolver em `<div className="flex items-center gap-2">` e adicionar o badge ao lado quando `c.cust_id_status === 'invalido'`
     - Aplicar somente nessa lista; **NÃO** propagar para os cards principais (Faturamento, TACOS etc.) — esses são totais agregados, badge não faz sentido
  4. **`Sugadores/Index.jsx` componente `CompanyCard` (linha 109):**
     - Adicionar prop `card.cust_id_status` (já vem via `companies_summary` populado em W5-T3)
     - Renderizar badge no header do card próximo ao nome da empresa quando `card.cust_id_status === 'invalido'`
     - Layout: usar `flex items-center gap-2` no container do título
  5. **Filtro `?cust_id_status=invalido` em `/companies`:**
     - **Backend (`CompanyController::index`):**
       - Aceitar query param `cust_id_status` via `$request->input('cust_id_status')` no topo do método
       - Validar valor ∈ `['ok', 'invalido', 'desconhecido', 'nao_aplicavel']` — fora disso, ignorar (não filtrar)
       - Aplicar `Company::query()->when($custIdStatusFilter, fn($q) => $q->where('cust_id_status', $custIdStatusFilter))->with(...)->get()` substituindo a query atual
       - Passar `'filters' => ['cust_id_status' => $custIdStatusFilter]` no `Inertia::render`
     - **Frontend (`Companies/Index.jsx`):**
       - Adicionar `<select>` ou `<Checkbox>` simples na barra de filtros: "Mostrar apenas Cust ID Inválido" → query param `cust_id_status=invalido` ao marcar
       - Usar `router.get(route('companies.index'), { cust_id_status: 'invalido' }, { preserveState: true })` ao mudar
       - Estilo consistente com filtros existentes na página (verificar via Read antes de implementar)
       - **NÃO** adicionar em Dashboard/Admin.jsx nem Sugadores/Index.jsx — só em Companies/Index (decisão: evitar poluir UI desses sites que já têm filtros densos)
  6. **Indicador "≈ aproximado" nos cards do Dashboard (escopo original W5-T1 movido pra cá):**
     - Em `resources/js/Pages/Dashboard/Admin.jsx`:
       - Consumir prop `cards_exatos` (boolean) introduzida em W2-T3 e refinada em W4-T3
       - Adicionar `cards_exatos = true` ao desestruturar de props no `AdminDashboard` (linhas 75-90), default `true` por segurança
       - Modificar componente `KpiCard` (linhas 38-64) para aceitar prop opcional `approx?: boolean`
       - Quando `approx`, prepender `~` no value (`'~' + value` ou similar visualmente sutil — não substituir, só anotar) E adicionar atributo `title` no card raiz: `"Dados aproximados — algumas empresas estão em cache parcial ou range não-30d. Use o range padrão para valores exatos."`
       - Aplicar `approx={!cards_exatos}` apenas nos cards que dependem do cache Adman: "Faturamento Total" (linha 308), "Invest. Ads (30d)" (linha 307), "TACOS Médio" (linha 305). Os cards de empresas/NPS são deterministas e não usam o cache (não recebem flag).
       - pt-BR no tooltip; sem emoji; estilo discreto (text-white/40 ou similar)
- **Verify:**
  - `npm run build` verde (após cada arquivo JSX modificado)
  - `grep -rn "cust_id_status === 'invalido'" resources/js/Pages/Companies/Index.jsx resources/js/Pages/Dashboard/Admin.jsx resources/js/Pages/Sugadores/Index.jsx` retorna 3 ocorrências (1 por site)
  - `grep -n "Cust ID Inválido" resources/js/Pages/Companies/Index.jsx resources/js/Pages/Dashboard/Admin.jsx resources/js/Pages/Sugadores/Index.jsx` retorna 3 ocorrências
  - Smoke manual:
    1. Abrir `/companies` em prod (após W5-T7) — badges visíveis em 32 empresas; sem badge em 134
    2. Filtro `?cust_id_status=invalido` retorna 32 empresas
    3. Abrir `/` — `companies_performance` mostra badges nas empresas invalidas; cards principais com `~` se cache parcial
    4. Abrir `/sugadores` — cards das 32 empresas invalidas com badge
  - Empresas com `cust_id_status='desconhecido'` (default antes do primeiro mark) **não** mostram badge — confirmar via empresa nova seedada após migration mas antes de `mark-custid-status`
- **Done:** Badge visível nos 3 sites; filtro funcional em `/companies`; sites com `cust_id_status === 'desconhecido'` ficam neutros (sem badge); cards principais do Dashboard com `~` quando aproximado.

#### Task W5-T5 — Build + suíte completa verde (gate operacional)

- **Files:** nenhum (gate operacional)
- **Action:**
  1. `npm run build` — confirmar bundle Vite gerado sem warning JSX (especialmente após mudanças em 3 arquivos JSX)
  2. `php artisan test` — suíte completa (não só Phase18) verde
  3. Se algum teste pré-existente quebrar em arquivo NÃO tocado por Phase 18 (`Phase14*`, `Phase16*`, `Phase17*`, `DevControllerTest`, etc), documentar em `deferred-items.md` no diretório da phase. Não fixar nessa fase — fora do escopo.
  4. Se algum teste pré-existente quebrar em arquivo TOCADO por Phase 18 (`DashboardControllerTest` se existir, `CompanyControllerTest`, `SugadorControllerTest`), **fixar**. Provavelmente exposição do novo campo `cust_id_status` em payloads não quebra contratos (campo aditivo).
- **Verify:** ambos os comandos retornam sucesso; `deferred-items.md` criado se necessário com lista de regressões observadas
- **Done:** Build verde + suíte verde + deferred items rastreados.

#### Task W5-T6 — Testes finais (mark-custid-status + filtro Companies + UI flag aproximado)

- **Files:**
  - `tests/Feature/Phase18/MarkCustIdStatusTest.php` (novo)
  - `tests/Feature/Phase18/CompaniesCustIdFilterTest.php` (novo)
  - `tests/Feature/Phase18/DashboardFiltersTest.php` (estender)
- **Action — MarkCustIdStatusTest (4 testes):**
  - Teste 1: seed 1 empresa com `cust_id` válido + 1 registro recente em `adman_metrics` (curto-circuito VALIDADO_OK). Rodar comando. Assert `Company::first()->cust_id_status === 'ok'`. Assert activity log com mensagem padrão de mudança.
  - Teste 2: seed 1 empresa com `adman_account_id == ml_store_id == '1234567890'` (SUSPEITO) + zero registros recentes em `adman_metrics`. Mockar `AdmanService::fetchPerformance` para arremessar `\RuntimeException("Erro HTTP 500 ...")`. Rodar comando. Assert `Company::first()->cust_id_status === 'invalido'`.
  - Teste 3: seed 1 empresa categoria `ERRO_INDEFINIDO` (mockar `fetchPerformance` para arremessar `\RuntimeException("Erro HTTP 429 ...")`). Pré-popular `cust_id_status='desconhecido'`. Rodar comando. Assert `Company::first()->cust_id_status === 'desconhecido'` (manteve). Assert activity log **NÃO** gravado (sem mudança).
  - Teste 4: seed 1 empresa SEM `cust_id` (nem `adman_account_id` nem `ml_store_id`). Rodar comando. Assert `Company::first()->cust_id_status === 'nao_aplicavel'`.
  - **Importante:** mockar `AdmanService` via `$this->mock(AdmanService::class)`. Mockar throttle dispensável.
- **Action — CompaniesCustIdFilterTest (2 testes):**
  - Teste 1: seed 3 empresas: A com `cust_id_status='invalido'`, B com `'ok'`, C com `'desconhecido'`. `actingAs(admin)->get('/companies?cust_id_status=invalido')` retorna prop `companies` com 1 entrada (A). Assert `count(response.props.companies) === 1` e entrada é A.
  - Teste 2: mesmas 3 empresas, `actingAs(admin)->get('/companies')` (sem filtro) retorna 3 entradas. Assert `count(response.props.companies) === 3`.
- **Action — DashboardFiltersTest (estender com 2 testes — escopo original W5-T2):**
  - Teste 6: `actingAs(admin)->get('/?period=30')` com `Cache::flush()` no setup → prop `cards_exatos === false` (forced fallback)
  - Teste 7: `actingAs(admin)->get('/?period=7')` → prop `cards_exatos === false` mesmo com cache hot (porque period ≠ 30)
- **Verify:**
  - `php artisan test --filter=MarkCustIdStatusTest` verde (4 testes)
  - `php artisan test --filter=CompaniesCustIdFilterTest` verde (2 testes)
  - `php artisan test --filter=DashboardFiltersTest` verde (7 testes totais somando W1+W2+W5-T6)
- **Done:** Comando `mark-custid-status` coberto por testes; filtro `/companies` coberto; flag `cards_exatos` blindada contra regressão.

#### Task W5-T7 — Execução operacional em produção (MANUAL STEP — checkpoint)

- **Type:** `checkpoint:human-action` (blocking)
- **Files:** nenhum (operacional)
- **Action operacional (orchestrator faz via SSH, usuário confirma):**
  1. SSH no VPS Hostinger (`177.7.53.164`); `cd` no path do projeto em produção
  2. **Após deploy** (deploy continua sendo decisão do usuário no W5-T8): rodar `php artisan migrate` para aplicar W5-T1
  3. Rodar `php artisan dashboard:mark-custid-status --dry-run` — capturar output e revisar a tabela. Esperado:
     - ~134 empresas atualizando para `'ok'`
     - ~32 empresas atualizando para `'invalido'`
     - 0-2 empresas com status mantido (ERRO_INDEFINIDO transitório)
  4. Se sumário bater com o esperado: rodar `php artisan dashboard:mark-custid-status` (sem `--dry-run`)
  5. Capturar output e salvar em `.planning/phases/18-dashboard-precisao-filtros/MARK-CUSTID-OUTPUT.txt`
  6. Verificação visual: abrir `/companies?cust_id_status=invalido` em prod — confirmar 32 empresas listadas com badge "Cust ID Inválido"
  7. (Opcional) Re-rodar `dashboard:diagnose-cust-id` em prod — confirmar que classificação está estável (mesmas 32 INVALIDO_CONFIRMADO)
- **What built:** estado persistido em `companies.cust_id_status` para todas as empresas; UI mostra badges nos sites configurados.
- **How to verify:**
  1. `MARK-CUSTID-OUTPUT.txt` existe com contadores
  2. UI `/companies?cust_id_status=invalido` mostra 32 empresas com badge
  3. UI `/` no card `companies_performance` mostra badge nas mesmas 32 empresas
  4. UI `/sugadores` mostra badge nas mesmas 32 empresas (cards)
- **Resume signal:** "aplicado e validado" ou descrição de issue
- **Done:** 32 empresas marcadas `invalido` em prod; operador identifica e trata manualmente (conectar OAuth ML ou ajustar cadastro).

#### Task W5-T8 — Checkpoint humano UX final + decisão de deploy

- **Type:** `checkpoint:human-verify` (blocking)
- **What built:** Dashboard com período dinâmico + filtros empilháveis + indicador "≈ aproximado" + cache híbrido per-empresa + badges "Cust ID Inválido" + filtro Companies + comando `mark-custid-status` rodado em prod.
- **How to verify (passos para o usuário):**
  1. Login como admin
  2. Em `/`, conferir badge "Atualizado em DD/MM HH:mm · D-1 da Adman" presente (Phase 16, não regrediu)
  3. Selecionar empresa "X" no dropdown — observar URL: `?company_id=...`
  4. Trocar período para "Últimos 7 dias" — URL deve virar `?company_id=...&period=7` (empresa permanece)
  5. Cards "Faturamento", "Invest. Ads", "TACOS Médio" devem mostrar `~` prefix (aproximado, porque period ≠ 30 cai em DB)
  6. Voltar para "Últimos 30 dias" — `~` some se cache estiver hot E todas as empresas com cust_id válido tiverem hit (pós-W4-T3 + W5-T7)
  7. Lista `companies_performance` no Dashboard: 32 empresas com badge "Cust ID Inválido" (cor vermelha discreta) ao lado do nome
  8. Abrir `/companies` — barra com filtro "Mostrar apenas Cust ID Inválido"; ao marcar, lista reduz para 32 empresas
  9. Abrir `/sugadores` — cards das 32 empresas têm badge "Cust ID Inválido"
  10. Comparar `total_revenue` exibido na Dashboard vs último output de `dashboard:audit-billing-divergence --period=30` em produção — números devem se aproximar (não bater exatamente porque DB ainda tem buracos históricos)
  11. Trocar analista/estrategista combinado com empresa e período — URL preserva todos
- **Resume signal:** "approved" ou descrição do problema
- **Deploy:** **NÃO executar deploy automaticamente.** Deploy é decisão do usuário após este checkpoint. Sequência sugerida pós-aprovação: deploy → migrate → `mark-custid-status` → verificação UI (passos 7-9 acima). Comentar isso no resumo final do executor.

---

### Wave 5.5 — Import XML de cust_ids corretos (DEFERRED — Phase 18.5)

**Status:** DIFERIDO. Aguardando o XML do lado do negócio com cust_ids corretos das 32 empresas.

**Por que separar em Phase 18.5 e não embutir em W5:**
1. O arquivo XML ainda não chegou — escopo não tem entrada
2. O formato/esquema do XML é desconhecido — não dá pra modelar parser sem ver o arquivo (nome/CNPJ como chave? estrutura do XML? encoding?)
3. W5 já fecha o goal observável (badges + filtro + persistência) sem precisar do XML
4. Quando o XML chegar, Phase 18.5 implementará `dashboard:import-cust-id-from-xml {arquivo}` que:
   - Faz parse do XML (provavelmente `simplexml_load_file`)
   - Para cada empresa do XML, busca no DB por identificador (nome? CNPJ? — depende do XML)
   - Se `cust_id` do XML difere do nosso, atualiza `adman_account_id` (com confirmação dry-run) E re-roda `mark-custid-status` para refletir o novo estado
   - Sumário final do que atualizou + activity log por empresa
5. Operacionalmente: assim que o XML chegar, `/gsd-quick` ou `/gsd-execute-phase 18.5` para criar a phase de import

**Aceito como deviation explícita:** o badge "Cust ID Inválido" persiste em prod até Phase 18.5 fechar OU até alguém conectar OAuth ML para essas empresas (que então automaticamente passariam pelo curto-circuito VALIDADO_OK na próxima rodada de `mark-custid-status`).

## Pitfalls e mitigações

| # | Pitfall | Mitigação |
|---|---------|-----------|
| 1 | `compact('companyFilter')` gera chave camelCase | W1-T1 usa array literal explícito; **não** renomear as variáveis PHP (back-compat com mentor_id na linha 70) |
| 2 | ECFSelect em 3 lugares (linhas 252/258/264) | W1-T2 muda todos numa task só com grep verification |
| 3 | `$dateFrom30d` usado em 7+ sites (111, 112, 156-167, 251-258, 502) | W2-T2 lista todos explicitamente; site 502 (userDashboard) intencionalmente **não** tocado (out of scope) |
| 4 | Cache `RefreshGrossBillingCacheJob` cacheia só range 30d | Decisão W2-T3: ranges ≠ 30d caem em fallback DB intencionalmente; SC-5 sinaliza |
| 5 | `whereBetween('reference_date', [...])` em SQLite/MySQL com cast `date` | Strings Y-m-d em ambos os lados (não Carbon); pitfall Phase 15 já aplicado |
| 6 | Auditoria em produção = ~20min de execução | W3-T1 inclui `withProgressBar`; W3-T2 é MANUAL STEP — **JÁ EXECUTADO**, output em `AUDIT-OUTPUT-30d.txt` |
| 7 | Cache parcial tudo-ou-nada gera oscilação | W4-T3 substitui pela política híbrida per-empresa; W5-T4 indica visualmente quando ainda há miss |
| 8 | ~~W4 escopo TBD~~ | **Resolvido:** A+B+D aprovado pelo usuário; tasks concretas W4-T1 a W4-T5 |
| 9 | `AdmanService::fetchPerformance` pode retornar 429 mesmo com throttle 7s | Comando W3-T1, W4-T1 e W5-T2 usam o método existente (que já tem retry exponential interno linhas 219-227); W4-T2 ataca a raiz (caller upstream furando o throttle global) |
| 10 | Auditoria pode revelar gap sistemático que exige nova tabela | Usuário rejeitou backfill/nova tabela; W4-T3 (cache híbrido) resolve sem migration de dados — **mas W5-T1 introduz migration nova (apenas coluna `cust_id_status`)** que não é backfill de dados, só metadado |
| 11 | **Mapeamento `cust_id` corrompido** — 43 empresas com `adman_account_id == ml_store_id` em formato Seller ID ML (10 dígitos) | W4-T1 diagnostica + classifica; W4-T5 confirmou em prod (32 atuais); W5-T2 persiste a marca; W5-T4 mostra badge; W5.5 (futuro) corrige via XML |
| 12 | **Rate limit residual pós-Phase 16** — 741 erros HTTP 429 indicam caller ainda violando o throttle global de 10 req/min | W4-T2 inicia com grep abrangente de `fetchPerformance`; aplica throttle ou batch delay no caller faltante; cenário limite (sem caller óbvio) → mitiga via `Cache::lock` em `RefreshGrossBillingCacheJob` contra concorrência interna |
| 13 | **Cache híbrido (mistura Adman+DB)** — política nova pode confundir usuário se números oscilarem entre requests | W4-T3 documenta no código (reavaliação do trade-off das linhas 91-95); cache 24h estável da Phase 16 minimiza oscilação intra-dia; `cards_exatos` granular avisa o usuário quando há mistura |
| 14 | **`Company::cust_id` accessor** — fix de W4-T1 depende da prioridade de fallback no accessor (ml_store_id ?: adman_account_id, commit f9d0547) | W4-T1 começa lendo `app/Models/Company.php` para confirmar a ordem antes de codar; documenta a verificação como comentário no topo do command |
| 15 | **`--fix` agressivo limparia dados de empresas sem fallback ML** | W4-T1 é seletivo: só limpa quando `Status === INVALIDO_CONFIRMADO` AND `ml_oauth_ativo === true`. Empresas sem fallback ML ficam como AÇÃO_SUGERIDA="Revisar manualmente" — **W4-T5 confirmou 0 candidatas seguras**, daí W5 adota estratégia de marcação visual em vez de mutação |
| 16 | **Migration W5-T1 em prod** — adicionar coluna em tabela com 168 registros é seguro (default 'desconhecido' não causa lock longo em MySQL/MariaDB) | Default 'desconhecido' = backfill instantâneo; W5-T7 explicita ordem (migrate → mark-custid-status) para evitar badge incorreto antes da rotulagem |
| 17 | **Comando `mark-custid-status` pode mutar campos errados** se alguém estender a classe | `$fillable` em W5-T1 inclui só `cust_id_status`; `update(['cust_id_status' => ...])` é explícito; testes W5-T6 verificam o estado dos demais campos; verify gate em W5-T2 grepa por matches indevidos |
| 18 | **Badge em 3 sites JSX** — manter consistência visual quando o design mudar | Decisão W5-T4: badge inline em cada site (sem extrair para `Components/ui`), porque os 3 layouts são diferentes e abstração prematura seria pior. Aceito: mudança visual futura exige edit em 3 arquivos (grep facilita) |
| 19 | **Filtro em /companies pode quebrar paginação ou ordenação existente** | W5-T4 usa `Company::query()->when(...)` antes do `->orderBy('name')->get()` — `when()` é no-op quando o filtro é vazio, preserva comportamento atual |
| 20 | **Empresas novas cadastradas após `mark-custid-status` ficam com `'desconhecido'` indefinidamente** | Aceito: `'desconhecido'` = neutro (não mostra badge). Decisão operacional: re-rodar `mark-custid-status` periodicamente (1×/semana sugerido no docblock do comando) ou após batch de cadastros. **NÃO** auto-agendar (decisão W5-T2). |
| 21 | **XML do lado do negócio pode nunca chegar** (Phase 18.5 fica órfã) | Aceito: o badge persistente em UI já dá visibilidade ao operador; sem o XML, a única saída é conexão de OAuth ML caso-a-caso, que naturalmente faz a empresa virar `'ok'` na próxima rodada de `mark-custid-status` |

## Não-objetivos (out of scope)

- Multi-select de empresas
- Range custom de datas (de/até livre)
- Refactor de `userDashboard` (linhas 450-569 do controller) — fica para Phase futura
- Mudar `RefreshGrossBillingCacheJob` (Phase 16) salvo o `Cache::lock` defensivo em W4-T2 (cenário limite)
- Reescrever `AdmanService::fetchPerformance` (estável; retry exponential já presente)
- Migration nova nesta fase salvo a coluna `cust_id_status` em W5-T1 (metadado, não dados) — usuário rejeitou estratégia (d) "dashboard_daily_totals"; W4-T3 cobre via cache híbrido
- Backfill histórico de `adman_metrics` para os 60+ dias faltantes — rejeitado pelo usuário (custo 5h+ Adman; W4-T3 resolve a precisão visível sem precisar reconstruir histórico)
- Otimizar performance da Dashboard além do necessário pros bugs
- Pre-warm de cache para ranges 1d/7d/180d (decisão W2-T3)
- Deploy automático no fim — deploy é decisão do usuário pós-W5-T8
- Import XML de cust_ids corretos — deferido para Phase 18.5 (aguardando arquivo do lado do negócio)
- Auto-agendamento do `mark-custid-status` no scheduler — operacional manual pelo usuário (W5-T2 decisão)
- Refator DRY entre `DiagnoseCustId` e `MarkCustIdStatus` (classificação duplicada) — aceito; extrair pra trait pode entrar em Phase 18.5

## Deviation contract

**Pare e pergunte ao usuário se:**

1. **W2-T3 cache strategy precisar mudar** — ex: se durante W2-T2 ficar claro que algum cache secundário no controller (linha 117-133) precisa ser modificado para suportar ranges variados. Atual decisão é fallback DB; mudar requer aprovação.
2. **W4-T2 não conseguir identificar caller furando rate limit** após grep + inspeção visual exaustiva — propor `Cache::lock` em `RefreshGrossBillingCacheJob` como mitigação contra concorrência interna e validar com o usuário antes de aplicar.
3. **W4-T1 detectar que `Company::cust_id` accessor não prioriza `ml_store_id`** — `--fix` ficaria perigoso; pausar e validar com o usuário se a ordem do accessor deve mudar OU se `--fix` deve ser desativado.
4. **Suíte de testes existente regredir em arquivos NÃO tocados** — documentar em `deferred-items.md` e seguir; não fixar nessa fase.
5. **Comando `dashboard:audit-billing-divergence`, `dashboard:diagnose-cust-id` ou `dashboard:mark-custid-status` falhar com erro de credencial Adman em ambiente de teste local** — orientar usuário a rodar em produção (W3-T2, W4-T5, W5-T7 são manual steps justamente por isso).
6. **W4-T5 diagnose em produção revelar > 80 empresas como INVALIDO_CONFIRMADO** (auditoria estimou ~43; prod confirmou 32) — auditar se a categorização está super-agressiva antes de qualquer fix. **Status:** já executado; 32 confirmadas; sem ação adicional necessária.
7. **`AdmanService::fetchPerformance` retornar shape diferente do esperado** (sem `summarizedData.grossBilling.value`) — pausar e revalidar contrato Adman antes de continuar W3 ou W4.
8. **W5-T7 mark-custid-status em prod mostrar contadores muito diferentes do esperado** (ex: < 100 ok, > 50 invalido) — pausar antes de persistir; revisar se o curto-circuito VALIDADO_OK está funcionando ou se a classificação mudou desde W4-T5.
9. **SugadorController::index não tiver acesso ao model `Company` no loop de `companies_summary`** — confirmar via leitura antes de W5-T3; se não tiver, criar map lookup via `Company::whereIn('id', ...)->pluck('cust_id_status', 'id')` como otimização. NÃO fazer N+1.
10. **Migration W5-T1 em prod travar por lock de schema longo** — ENUM com default constante deveria ser instantâneo em MariaDB; se demorar, considerar `ALTER TABLE ... ALGORITHM=INPLACE, LOCK=NONE` ou rodar em janela de manutenção.

## Por que este plano entrega o goal?

**Goal:** Dashboard precisa (acertividade) e filtros empilháveis (praticidade), eliminando 3 bugs do dia 2026-06-02.

| Goal element | Plano cobre via |
|--------------|-----------------|
| **Bug 1 — período não muda dados** | W2-T1 (helper) + W2-T2 (propagação em 7 sites) + W2-T4 (teste regression). Após W2 fechar, trocar `?period=7` recalcula `total_revenue`, `total_ad_investment_30d`, `avg_tacos`, e os fallbacks DB usam o range correto. **SC-2 ✓** |
| **Bug 2 — combinar filtros perde a empresa** | W1-T1 (backend snake_case) + W1-T2 (frontend snake_case) + W1-T3 (teste empilhamento). Após W1 fechar, controller devolve e lê a mesma chave; spread `...filters` no `applyFilter` propaga corretamente. **SC-1 ✓** |
| **Bug 3 — faturamento divergente** | W3 mediu (auditoria 30d: diff R$ 39,3M / 71,79%, 43 cust_id corrompidos, 741 erros 429, 4/172 empresas com 30 dias completos). W4 ataca as 3 causas-raiz convergentes: **W4-T1** classifica e (quando seguro) limpa `cust_id` corrompido; **W4-T2** identifica e mitiga o caller residual furando o throttle global de 10 req/min; **W4-T3** substitui a política de cache "tudo-ou-nada" por híbrido per-empresa. W4-T5 confirmou em prod que `--fix` automático é inviável (0 candidatas seguras de 32 INVALIDO_CONFIRMADO). Backfill rejeitado pelo usuário; D resolve a precisão visível sem reconstruir histórico. **SC-3 ✓ (auditoria executada), SC-4 ✓ (A+B+D aprovado e modelado; W4-T5 executado em prod)** |
| **Acertividade** (regra-mestra) | W4-T3 elimina a perda massiva onde 1 empresa sem cache zerava o pool inteiro. W4-T2 estanca o sangramento contínuo (429s gerando metrics com `error_message`). W5-T4 garante que a UI nunca mascara dados aproximados — quando há fallback ou period ≠ 30, `~` + tooltip explicam. O usuário **nunca vê dado errado camuflado de exato**. As 32 empresas com cust_id corrompido sem fallback ML ficam visíveis via badge persistente, permitindo ao operador identificar e tratar (conectar OAuth ML ou ajustar cadastro). **SC-5 ✓** |
| **Praticidade** (regra-mestra) | W1 alinha naming; filtros combinam sem perder; W2 garante que trocar período entrega resposta diferente; W5-T4 adiciona filtro `?cust_id_status=invalido` em `/companies` para o operador isolar as 32 empresas defeituosas em um clique. O operador faz fluxo natural de filtragem + diagnóstico sem precisar recarregar com mão. **SC-1, SC-2, SC-5 ✓** |
| **Empresas defeituosas visíveis** | W5-T1 (migration) + W5-T2 (`mark-custid-status`) persistem o diagnóstico do W4-T5 em DB. W5-T3 expõe a flag nos 3 controllers (Companies, Dashboard, Sugadores). W5-T4 renderiza badge "Cust ID Inválido" nos 3 sites + filtro em Companies. W5-T7 roda em prod para popular as 32 invalidas + 134 ok. O operador agora **vê quais empresas estão defeituosas** e pode tratá-las caso-a-caso (Phase 18.5 fecha o ciclo com import XML quando o arquivo chegar). **SC-5 ✓** |
| **Cobertura de testes** | W1-T3 (3 testes) + W2-T4 (2 testes) + W3-T3 (3 testes) + W4-T4 (4 testes DiagnoseCustId + 3 testes CacheHybrid = 7 testes) + W5-T6 (4 MarkCustIdStatus + 2 CompaniesCustIdFilter + 2 DashboardFilters = 8 testes) = **23 testes Phase 18**, cobrindo empilhamento, range dinâmico, auditoria com gap, diagnóstico cust_id (incluindo `--fix` seletivo), cache híbrido per-empresa, marcação `cust_id_status`, filtro Companies, e flag aproximado. **SC-6 ✓** |

**Camadas de defesa contra regressão futura:**
1. Teste 3 (W1-T3) é guard contra reaparecimento de camelCase
2. Teste 5 (W2-T4) é guard contra range hardcoded
3. Teste 7 (W5-T6 estendido) é guard contra cards exatos mascarando fallback
4. Teste W4-T4 DiagnoseCustIdTest #3 é guard contra `--fix` virar agressivo e zerar empresas sem fallback ML
5. Teste W4-T4 DashboardCacheHybridTest #1 é guard contra reversão da política tudo-ou-nada
6. Teste W5-T6 MarkCustIdStatusTest #3 é guard contra `ERRO_INDEFINIDO` (transitório) ser persistido como definitivo
7. Teste W5-T6 CompaniesCustIdFilterTest #2 é guard contra filtro vazio retornar lista filtrada por engano
8. Comando W3-T1 pode ser re-executado a qualquer momento para reverificar a divergência (forma viva de monitoring); comandos W4-T1 e W5-T2 podem ser re-executados periodicamente para detectar novos cust_id corrompidos em empresas recém-cadastradas

**Nota sobre evidência:** A auditoria 30d documentada em `.planning/phases/18-dashboard-precisao-filtros/AUDIT-OUTPUT-30d.txt` (363 linhas) e o diagnóstico documentado em `DIAGNOSE-CUSTID-OUTPUT.txt` são as duas fontes de verdade que orientam o escopo concreto de W4 e W5. Toda decisão "A+B+D sem C" do usuário em 2026-06-02 + a decisão de "marcar em vez de fix automático" pós-diagnose estão ancoradas nesses números (R$ 39,3M de diff, 32 empresas confirmadas INVALIDO sem fallback ML, 134 OK por curto-circuito). Antes de executar qualquer task de W4 ou W5, reler esses arquivos é obrigatório.

**Risco residual conhecido:**
- W4-T2 pode descobrir que não há caller óbvio violando o throttle — nesse caso, a hipótese é concorrência interna no `RefreshGrossBillingCacheJob` e a mitigação será `Cache::lock` defensivo. Aceito como deviation #2.
- Ranges ≠ 30d sempre vão usar fallback DB (W2-T3). Se o usuário insistir em dados exatos em qualquer período, requer Phase futura mudando `RefreshGrossBillingCacheJob` ou cache key strategy.
- As 32 empresas com `cust_id_status='invalido'` continuam exibindo dados imprecisos no Dashboard (cache híbrido vai usar o DB local, que tem buracos) até alguém conectar OAuth ML para essas empresas OU até Phase 18.5 importar o XML corrigido. O badge garante que o operador sabe disso. Aceito por decisão explícita do usuário (2026-06-02): "Marque como Cust id inválido na UI para que possamos saber qual empresa esta defeituosa e conectar o Oauth ML".
- Backfill histórico não foi feito → dias faltantes em `adman_metrics` permanecem; cache híbrido mitiga ao usar Adman exato para empresas com cust_id válido, mas empresas que estão no DB com dados parciais (5-14 dias dos 30) ainda vão divergir do Adman quando o cache miss as empurra para o fallback DB. Aceito por decisão explícita do usuário (custo > benefício).
- Empresas novas cadastradas após `mark-custid-status` ficam com `cust_id_status='desconhecido'` até a próxima rodada manual do comando. Aceito: `'desconhecido'` é neutro (não mostra badge incorreto); operador re-roda quando suspeitar de batch novo.
- Refator DRY entre `DiagnoseCustId` e `MarkCustIdStatus` (classificação duplicada inline) — aceito; extrair para trait pode entrar em Phase 18.5 junto com o import XML.
