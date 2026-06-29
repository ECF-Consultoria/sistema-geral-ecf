# Phase 45 — Research

**Data:** 2026-06-29
**Status:** Ready for planner

---

## §1 — Widget "Desempenho da equipe" (estado atual)

### Render path

- Controller: `app/Http/Controllers/DashboardController.php` — método `adminDashboard()` (linha 104)
- Prop Inertia: `performance_equipe` (linha 640)
- Página React: `resources/js/Pages/Dashboard/Admin.jsx` — linhas 388–475
- Widget consome o array `performance_equipe` diretamente como `data` do `BarChart`

### Como a prop é montada (DashboardController.php, linhas 583–615)

```php
// linha 587
$scoreService = app(\App\Services\PortfolioScoreService::class);

// linha 589–593 — filtro: users do setor Performance (cargo slug analista ou estrategista)
$perfMembrosQuery = User::where('active', true)
    ->whereExists(function ($q) {
        $q->from('user_setores as us')
          ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
          ->whereColumn('us.user_id', 'users.id')
          ->whereIn('c.slug', ['analista', 'estrategista']);
    });
// linha 604–614 — mapeia cada user chamando $scoreService->compute($u)
$perfMembros = $perfMembrosQuery->get(['id', 'name'])
    ->map(fn($u) => [
        'id'            => $u->id,
        'name'          => $u->name,
        'score'         => $r['score'],
        'classificacao' => $r['classificacao'],
    ])
    ->sortByDesc('score')
    ->values();
```

**O widget delega 100% da lógica para `PortfolioScoreService::compute()`.**

### PortfolioScoreService::compute() — janela e fórmula

Arquivo: `app/Services/PortfolioScoreService.php`

**Janela temporal:** hardcoded a 30 dias rolling (linhas 71–76):
```php
$atualFrom = now()->subDays(30)->toDateString();
$atualTo   = now()->toDateString();
```
Não recebe `$period` como parâmetro — **ignorou o filtro de período do Dashboard**.

**Fonte de métricas:** exclusivamente `AdmanMetric` (linhas 88–93 e 206–217):
```php
$sumAtual = AdmanMetric::whereIn('company_id', $companyIds)
    ->whereBetween('reference_date', [$atualFrom, $atualTo])
    ->selectRaw('company_id, SUM(revenue) as rev, SUM(revenue_prev_period) as rev_prev, SUM(ad_spend) as ads')
    ->groupBy('company_id')
    ->get();
```

**Complemento de cache Adman (gross billing):** linha 97 — `$this->adman->getCachedGrossBillingsMany(...)`.
Cache usa `cust_id` da empresa (`adman_account_id ?: ml_store_id`).

**Fórmula de score** (pesos com redistribuição quando categoria sem dado):

| Categoria | Peso | Lógica |
|---|---|---|
| Crescimento ajustado | 30% | `(totalAtual - totalAnterior) / totalAnterior * 100`, cap ±20%, linear 0→100 |
| % empresas em crescimento | 20% | `emCrescimento / eligiveis * 100` |
| Atingimento de meta | 20% | `metaRealizado / metaTarget * 100`, cap 100 |
| Recuperação | 15% | empresas que estavam em queda na 2ª quinzena e voltaram na 1ª |
| Execução de Ads | 10% | **DESCONTINUADA** — sempre `null` (linha 249); peso redistribuído |
| Qualidade | 5% | NPS (70%) + presença em reuniões (30%) |

**Classificações** (linhas 392–398):
- `excelente` ≥ 85 pts
- `bom` ≥ 70 pts
- `atencao` ≥ 55 pts
- `critico` < 55 pts

**Critério de inclusão de empresas:** `$user->companies()` — todas do pivot `company_users` sem filtro de setor (linha 78).
Empresas elegíveis pra crescimento: pelo menos uma das duas (`revAtual > 0` OU `revAnterior > 0`) (linhas 114–119).

---

## §2 — Página /performance (estado atual)

### Rota

`routes/web.php` linhas 242–247:
```php
Route::get('/performance', [PerformanceController::class, 'index'])
    ->middleware('permission:core.performance')
    ->name('performance.index');
Route::get('/performance/{user}', [PerformanceController::class, 'show'])
    ->middleware('permission:core.performance')
    ->name('performance.show');
```

### PerformanceController::index() — setor consultoria

Arquivo: `app/Http/Controllers/PerformanceController.php`

**Método:** `index()` (linha 22) com `$setor === 'consultoria'` (default)

**Filtro de users:** linhas 39–43:
```php
$users = User::where('active', true)
    ->whereIn('role', ['consultor', 'mentor'])
    ->whereNull('publication_role')
    ->get();
```
**DIFERENÇA CRÍTICA:** o `PerformanceController` usa `role ∈ ['consultor','mentor']` com `publication_role IS NULL`, mas não filtra por cargo no setor Performance via `user_setores → cargos`. O DashboardController (widget) filtra por `cargo.slug ∈ ['analista','estrategista']` via pivot.

**Lógica de score:** linha 59 — `$this->scoreService->compute($u)` — **chama o mesmo `PortfolioScoreService`**.

**Período:** `$period` vem do request (linhas 30–37), suporta 7/30/90/180 dias — **mas NÃO é passado para `PortfolioScoreService::compute()`**, que tem janela hardcoded de 30d. O filtro de período na página `/performance` é **decorativo** — não afeta o score calculado.

**Prop Inertia enviada:** `ranking` (linha 105) com shape rico incluindo `crescimento_ajustado_pct`, `empresas_em_crescimento_pct`, `atingimento_meta_pct`, etc.

### Performance/Index.jsx

`resources/js/Pages/Performance/Index.jsx` — recebe `{ ranking, period, setor }` (linha 75).
Exibe tabela completa com breakdown das 6 categorias de score.

### Performance/Show.jsx

`resources/js/Pages/Performance/Show.jsx` — per-user. `PerformanceController::show()` (linha 232) busca `AdmanMetric::where('company_id', ...)` diretamente (linhas 279–282), **sem passar por `PortfolioScoreService`**.

---

## §3 — Discrepâncias widget vs página (root cause)

### Tabela de discrepâncias

| Dimensão | Widget (Dashboard) | Página /performance | Impacto |
|---|---|---|---|
| **Filtro de users** | `user_setores → cargos` onde `slug ∈ ['analista','estrategista']` (DashboardController.php:589) | `role ∈ ['consultor','mentor'] AND publication_role IS NULL` (PerformanceController.php:39) | Diverge quem aparece em cada lista |
| **Score engine** | `PortfolioScoreService::compute()` (DashboardController.php:606) | `PortfolioScoreService::compute()` (PerformanceController.php:59) | **Mesma função** — ok |
| **Janela temporal** | Hardcoded 30d dentro do service (PortfolioScoreService.php:71) | Hardcoded 30d dentro do service — `$period` não é passado (PortfolioScoreService.php:71) | Score idêntico mas `$period` na UI é mentira |
| **Ordenação** | `sortByDesc('score')` simples (DashboardController.php:614) | `tem_base_comparativa` primeiro, depois score (PerformanceController.php:97) | Ranking pode ter posições diferentes |
| **Prop enviada** | `['id','name','score','classificacao']` — shape mínimo | Shape rico com 12+ campos incluindo crescimento, meta, NPS | Widget tem menos info para debug |
| **Critério de inclusão de empresas** | `$user->companies()` — pivot `company_users` completo (PortfolioScoreService.php:78) | Idem — mesmo service | Sem divergência aqui |

### Root cause do bug de classificação divergente

O filtro de usuários difere: um usuário que tem `role='consultor'` sem `user_setores` com cargo `analista` aparece na `/performance` mas **não** no widget. E vice-versa: um usuário com cargo `analista` mas sem `role='consultor'` aparece no widget mas não na `/performance`.

### Recomendação de fonte canônica

**Usar o filtro do widget (cargo via `user_setores`)** como fonte canônica. É mais preciso e alinhado com a realidade pós-quick 260610-f69 (pivot de cargo). O `PerformanceController` deve adotar o mesmo filtro.

---

## §4 — Empresas ML-only no scoring (estado atual e bug)

### Chain User → companies → métricas

```
User
 └─ companies() [pivot company_users, role in (consultor, estrategista)]
     └─ cada Company
         ├─ cust_id = adman_account_id ?: ml_store_id  [Company.php:76]
         └─ is_ml_driven = (mlToken->status === 'active')  [Company.php:90]
             └─ PortfolioScoreService::compute()
                 ├─ AdmanMetric::whereIn('company_id', ...) [PortfolioScoreService.php:88]
                 └─ AdmanService::getCachedGrossBillingsMany($custIds, ...) [PortfolioScoreService.php:97]
```

### O bug concreto para empresas ML-only (ex: Bymobille #298)

**Situação esperada:**
- Empresa Bymobille tem `adman_account_id = null` e `ml_store_id = '298'`
- `cust_id = ml_store_id = '298'` (via accessor)
- `is_ml_driven = true` (mlToken ativo)
- Métricas de faturamento armazenadas em `adman_metrics` via sync ML (Phase 41 escrevia em `adman_metrics` como shadow) **OU** não armazenadas

**Gap no PortfolioScoreService (linhas 88–112):**

1. `AdmanMetric::whereIn('company_id', ...)` — **lê `adman_metrics`**. Se Bymobille não tem dados aí, `revAtual[298] = 0` e `revAnterior[298] = 0`.
2. `getCachedGrossBillingsMany($custIds, ...)` — cache Adman usa `adman_account_id` como chave. Para Bymobille com `adman_account_id = null`, `cust_id = ml_store_id`. A Adman API provavelmente retorna erro/vazio para um `ml_store_id` sem conta Adman.
3. Resultado: empresa Bymobille passa no filtro de elegibilidade? **NÃO** — `revAtual = 0` E `revAnterior = 0` → excluída das `$eligiveis` (linha 115–118).
4. Impacto no profissional que gerencia Bymobille: `empresas_eligiveis` cai, peso das categorias de crescimento pode mudar via redistribuição — mas o impacto principal é Bymobille simplesmente não contar.

**Confirmação da ausência de tabela ML separada:** Grep por `adman_metrics_ml`, `MlMetric`, `ml_metrics` retornou zero resultados em `app/Models/`. A Phase 41 (shadow comparison) escrevia em `adman_metrics` mesmo (comparação lado a lado), não em tabela separada. **Não existe modelo `MlMetric`.**

### Contrato proposto para CompanyMetricsProvider

Análogo ao `SugadoresAdsProvider` (Contracts/SugadoresAdsProvider.php), o novo contract deve expor:

```php
interface CompanyMetricsProvider {
    public function supports(Company $company): bool;
    public function name(): string;
    /**
     * Retorna revenue atual, revenue anterior e ad_spend para a empresa
     * no período informado.
     * @return array{rev: float, rev_prev: float, ads: float}
     */
    public function fetchScoreMetrics(Company $company, string $from, string $to): array;
}
```

- `AdmanMetricsProvider::supports()` → `!empty($company->adman_account_id)` (espelha AdmanSugadoresProvider.php:37)
- `MlMetricsProvider::supports()` → `optional($company->mlToken)->status === 'active'` (espelha MercadoLivreSugadoresProvider.php:52)
- Factory: ML preferido quando `$mlProvider->supports()` → idêntico à lógica de SugadoresAdsProviderFactory.php:59

**Onde chamar o factory no service:** substituir as linhas 88–112 do `PortfolioScoreService.php` por chamada ao `CompanyMetricsProviderFactory::for($company)->fetchScoreMetrics(...)`.

**Fonte de dados para MlMetricsProvider:** tabela `adman_metrics` filtrada pela empresa com `is_ml_driven = true` **OU** um endpoint ML direto. A pesquisa sugere que `adman_metrics` recebe dados de sync ML (via Phase 41 e jobs posteriores), portanto `MlMetricsProvider` pode ler a mesma tabela com a mesma query — a diferença é apenas no cache Adman (que não deve ser acionado para empresas ML-only). **Planner deve confirmar** se `adman_metrics` realmente tem dados para Bymobille #298 em produção antes de assumir que basta pular o cache Adman.

---

## §5 — Pattern v11.0 a copiar

### Arquivos do pattern Sugadores (molde)

| Arquivo | Função no molde | Análogo em Phase 45 |
|---|---|---|
| `app/Contracts/SugadoresAdsProvider.php` | Interface/contract | `app/Contracts/CompanyMetricsProvider.php` (novo) |
| `app/Services/Sugadores/AdmanSugadoresProvider.php` | Implementação Adman | `app/Services/Performance/AdmanMetricsProvider.php` (novo) |
| `app/Services/Sugadores/MercadoLivreSugadoresProvider.php` | Implementação ML | `app/Services/Performance/MlMetricsProvider.php` (novo) |
| `app/Services/Sugadores/SugadoresAdsProviderFactory.php` | Factory de resolução | `app/Services/Performance/CompanyMetricsProviderFactory.php` (novo) |

### Diff esperado em estrutura

```
app/
  Contracts/
    SugadoresAdsProvider.php   ← já existe (molde)
    CompanyMetricsProvider.php  ← NOVO Phase 45

  Services/
    PortfolioScoreService.php   ← MODIFICAR (injetar factory, remover AdmanMetric direto)
    Sugadores/                  ← já existe (molde)
    Performance/                ← NOVO diretório Phase 45
      AdmanMetricsProvider.php
      MlMetricsProvider.php
      CompanyMetricsProviderFactory.php
```

**O `PortfolioScoreService` continua sendo a classe central** — não é substituído, apenas refatorado para delegar a busca de métricas ao factory em vez de chamar `AdmanMetric::whereIn()` e `AdmanService::getCachedGrossBillingsMany()` diretamente.

**Mudança na assinatura de `compute()`:** adicionar parâmetro opcional `?string $period = '30'` (ou `int $days = 30`) para que DashboardController e PerformanceController possam eventualmente passar o período — desacoplando o score da janela hardcoded. **Cuidado:** o score baseado em `revenue_prev_period` (que vem da Adman) funciona só com 30d rolling (comentário nas linhas 62–67); para outros períodos o dado pode não existir. O `$period` na UI é cosmético hoje — **planner decide se destrava isso em Phase 45 ou deixa para Phase 46**.

### Ponto de integração: registro no ServiceProvider

Verificar `app/Providers/AppServiceProvider.php` — o factory Sugadores provavelmente é registrado lá. O `CompanyMetricsProviderFactory` precisa do mesmo tratamento. **Planner deve confirmar** se o binding já acontece via construtor (auto-resolve do container) ou precisa de `$this->app->bind(...)` explícito.

---

## §6 — Pitfalls

1. **Janela hardcoded no `PortfolioScoreService`** — `now()->subDays(30)` está em dois lugares (linhas 71–76): `$atualFrom` e nas halvings (`$half1From`, `$half2From`). Qualquer mudança de janela precisa atualizar todas as referências, inclusive a lógica de recuperação que compara as duas metades de 30d.

2. **`revenue_prev_period` só existe em `adman_metrics`** — coluna populada pelo sync Adman. Para empresas ML-only, se `adman_metrics` estiver vazio, `rev_prev = 0` e a categoria de crescimento (`crescimentoAjustadoPct`) ficará `null`. O `MlMetricsProvider` precisa decidir como fornecer esse "baseline anterior": ou ler da tabela de sync ML (se existir), ou calcular comparando janelas (`now()-60d` a `now()-30d`).

3. **MariaDB local corrompido** — testes via SQLite in-memory (memory `project_mariadb_local_corrompido`). Todos os testes PHPUnit devem usar `RefreshDatabase` + SQLite. Não executar `php artisan migrate` em ambiente local sem verificar `tasklist | grep mysqld`.

4. **Divergência de filtro de users** — corrigir `PerformanceController.php:39` para alinhar ao filtro do widget (cargo via `user_setores`) pode remover/adicionar usuários no ranking. Comunicar ao usuário antes de deployar — comportamento visível.

5. **Cache Adman não cobre empresas ML-only** — `getCachedGrossBillingsMany()` usa `cust_id` que para ML-only é o `ml_store_id`. A Adman API provavelmente rejeita esse ID. O `AdmanMetricsProvider` deve checar `supports()` antes de chamar o cache — nunca chamar cache Adman para empresa `is_ml_driven`.

6. **Categoria `execucao_ads` descontinuada** — peso 10% já redistribuído via `scoreFinal()` (PortfolioScoreService.php:239–250). Qualquer refactor deve manter `$execucaoPct = null` para não regredir o comportamento.

7. **`cust_id` accessor (`adman_account_id ?: ml_store_id`)** — Company.php linha 76. Para Bymobille, `cust_id = ml_store_id`. O cache Adman gross (`getCachedGrossBillingsMany`) foi escrito para uso com `adman_account_id`. Existe risco de colisão de chave de cache se dois IDs diferentes de fontes diferentes coincidirem numericamente (improvável mas documentar).

8. **Test coverage atual** — `PortfolioScoreService` provavelmente tem teste. Verificar em `tests/Feature/` antes de refatorar para garantir que testes existentes ainda passam.

9. **Sem migration necessária** — Phase 45 é refactor de leitura pura. Se `adman_metrics` já tem dados ML (Phase 41), zero mudança de schema.

---

## §7 — Recomendações pro planner

1. **Wave 1 — Corrigir filtro de users no `PerformanceController`** (task atômica, baixo risco): alinhar o `whereIn('role', ...)` ao filtro do widget via `user_setores → cargos`. Isso resolve o bug de "pessoas diferentes no ranking" imediatamente sem tocar no score. Commit único, testável via smoke.

2. **Wave 2 — Criar `CompanyMetricsProvider` contract e as 2 implementações** (tasks paralelas se possível):
   - `app/Contracts/CompanyMetricsProvider.php` — interface com `supports()`, `name()`, `fetchScoreMetrics(Company, string $from, string $to): array`
   - `app/Services/Performance/AdmanMetricsProvider.php` — encapsula `AdmanMetric::whereIn()` + `getCachedGrossBillingsMany()`; `supports()` = `!empty($company->adman_account_id)`
   - `app/Services/Performance/MlMetricsProvider.php` — lê `adman_metrics` sem cache Adman; `supports()` = `optional($company->mlToken)->status === 'active'`
   - `app/Services/Performance/CompanyMetricsProviderFactory.php` — ML preferido sobre Adman (espelha `SugadoresAdsProviderFactory.php:59`)

3. **Wave 3 — Refatorar `PortfolioScoreService`**: substituir `AdmanMetric::whereIn()` + `getCachedGrossBillingsMany()` (linhas 88–112) por loop que chama `CompanyMetricsProviderFactory::for($company)->fetchScoreMetrics(...)`. **Confirmar primeiro** se `adman_metrics` tem dados para Bymobille em produção (`SELECT COUNT(*) FROM adman_metrics WHERE company_id = 298`).

4. **Wave 4 — Testes PHPUnit**: Feature test com empresa Adman-only (score inalterado), empresa ML-only (score agora calculado), empresa híbrida (ML preferido). Usar SQLite in-memory.

5. **Wave 5 — Widget unificado**: verificar que `DashboardController.php:587` já chama o `PortfolioScoreService` diretamente (confirmado) — após refactor do service, o widget herda a correção automaticamente. Nenhuma mudança adicional no controller.

6. **Decisão para o planner — janela `$period`**: o filtro de período em `/performance` é decorativo (não afeta score). Phase 45 pode manter assim (janela hardcoded 30d) ou destravá-lo passando `$days` para `compute()`. Recomendação: manter 30d hardcoded em Phase 45 (coerente com `revenue_prev_period` disponível), e atacar a janela configurável em Phase 46 junto com o histórico longitudinal.

7. **Confirmar Bymobille #298 em produção**: antes de planejar o `MlMetricsProvider`, rodar via Tinker/Artisan no VPS: `Company::find(298)?->adman_account_id` e `SELECT COUNT(*) FROM adman_metrics WHERE company_id = 298`. Isso define se o provider ML precisa de fonte alternativa ou se `adman_metrics` já tem dados.

---

*Phase: 45-compatibilidade-ml-em-m-tricas-unifica-o-widget-desempenho*
*Research gerado: 2026-06-29 — pesquisa cirúrgica do codebase (sem survey exaustivo)*
