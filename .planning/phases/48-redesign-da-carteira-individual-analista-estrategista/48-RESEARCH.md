# Phase 48 — Research

**Data:** 2026-06-29
**Status:** Ready for planner

---

## §1 — Rota + Controller + Page (estado atual)

### Rotas (`routes/web.php`)

| Rota | URL | Controller::method | Quem acessa |
|------|-----|-------------------|-------------|
| `portfolio.own` | `GET /portfolio` | `PortfolioController::own` | Qualquer auth — bifurca por role |
| `portfolio.show` | `GET /admin/users/{user}/portfolio` | `PortfolioController::show` | Admin + líder de setor |

Linhas relevantes: `routes/web.php:259–263` e `routes/web.php:337–343`.

### Controller: `app/Http/Controllers/PortfolioController.php`

- `own()` (linha 51): bifurca admin → `renderCarteirasConsolidadas()` / líder → consolidada filtrada / profissional → `renderPortfolio()`.
- `show()` (linha 30): gatekeeping granular (admin OR self OR líder-de-setor), delega pra `renderPortfolio()`.
- `renderPortfolio()` (linha 223): o método alvo da Phase 48. Injeta 18 props via `Inertia::render('Portfolio/Show', [...])` (linha 806–834).

### Page React: `resources/js/Pages/Portfolio/Show.jsx`

Arquivo único (≈1050 linhas). Exporta `PortfolioShow` como default. Sub-componentes locais: `KpiCard`, `Chip`, `PerformanceSection`, `MiniMetric`, `ComparacaoLinha`, `ChartTooltip`.

### Janela temporal (como funciona hoje)

- **Parâmetro `period`** (query string, default `now()->format('Y-m')`): seletor de mês calendário.
- Mês atual (`$isMesAtual = true`): `dateFrom = now()-30d`, `dateTo = today` (rolling 30d). Período anterior = 30-60d atrás.
- Mês passado: `dateFrom = startOfMonth`, `dateTo = endOfMonth` do mês selecionado.
- UI exibe: `periodo_amostra.from_label` a `periodo_amostra.to_label` + `mes_label` (ex: "01/06 a 22/06 · Junho de 2026").

### Props injetadas via Inertia (linha 806–834)

```
portfolio_user      → {id, name, role}
companies           → array de empresas com revenue/ad_spend/status/acao_recomendada/meta_achieved_pct
portfolio_goals     → metas de carteira (PortfolioGoal) — a ser REMOVIDO em Phase 48
goals               → metas individuais por empresa (Goal)
summary             → {total_companies, avg_tacos, total_revenue, revenue_growth_pct, total_ad_spend, ...}
period              → string 'YYYY-MM'
available_periods   → últimos 12 meses
has_metric_data     → bool
portfolio_goal_metrics → labels das métricas (admin)
alertas             → {grants_expirando_30d, empresas_em_queda, empresas_sem_ad_spend[], top_3_revenue}
revenue_timeseries  → série diária {date, realizado, meta_acumulada, projecao}
meta_carteira       → {target_value, realized_value, achieved_pct, restante, has_goal} — a ser REMOVIDO
periodo_amostra     → {from, to, from_label, to_label, mes_label, is_mes_atual}
prioridade_do_dia   → int (count)
prioridade_lista    → [{id, name, motivos[]}]
performance_profissional → PortfolioScoreService::compute() result
comparacao_contextual   → ranking/mediana entre pares do mesmo cargo
```

---

## §2 — Estrutura UI atual + pontos de mudança

### Cards/widgets hoje (ordem vertical)

1. **Topo (cabeçalho)**: botão voltar + título "Carteira — {nome}" + filtro de período + botão "+ Meta" (admin). — **MANTER** (ajustar estilo)
2. **Card principal de faturamento** (gradiente, linha 576–636): foto-pessoa + faturamento `R$ X.XXM` + chips (crescimento%, Meta%, R$ restante, Score). — **REFORMULAR** hero card conforme briefing; remover chip "Meta%" e "R$ restante" ligados à `meta_carteira` (meta agregada)
3. **KPIs secundários** (grid 2×4, linha 638–696): Empresas | **Meta da carteira** | Prioridade do dia | Investimento Ads. — **Remover KPI "Meta da carteira"**; substituir por bloco diferenciado analista/estrategista
4. **PerformanceSection** (linha 701–706): RadialBar (score 0-100) + RadarChart (6 dimensões) + cards menores. — **MANTER** (parte do design novo)
5. **Gráfico de evolução** (LineChart, linha 708–771): Realizado acumulado / Meta acumulada / Projeção. — **MANTER** (o gráfico usa `meta_carteira.target_value` como base da linha Meta; após Phase 48 usar soma das metas individuais)
6. **Layout 2 colunas** (linha 773–): tabela de empresas (esq.) + painel lateral (dir.).
   - **Tabela** (linha 776–871): colunas Empresa | Faturamento | Meta | Margem | Ads. **Adicionar coluna "Crescimento" (sparkline)**; reintroduzir colunas Status + Ação recomendada que foram removidas (dados já existem no payload: `c.status`, `c.acao_recomendada`)
   - **Painel lateral** (linha 873–1012): Top faturamento | Comparação contextual | Ações estratégicas | Metas (admin). **Adicionar bloco NPS history** e **bloco diferenciado (Sugadores ou PPAs)**.

### O que VAI MUDAR (lista cirúrgica)

| # | Elemento | Mudança |
|---|----------|---------|
| 1 | Chips no hero: `meta_carteira.achieved_pct` e `meta_carteira.restante` | **Remover** — meta_carteira aggregated vai embora |
| 2 | KPI "Meta da carteira" (linha 647–662) | **Remover** |
| 3 | Modal "+ Meta" e todo o CRUD de PortfolioGoal no frontend (linhas 443–483, 1016–) | **Remover** (admin só precisará metas individuais por empresa) |
| 4 | Linha de Meta acumulada no gráfico de evolução | **Adaptar** — base muda pra soma das metas individuais (já existe como caminho B no service) |
| 5 | Tabela de empresas — sem colunas Status e Ação | **Adicionar** Status + Ação recomendada + coluna Crescimento (sparkline) |
| 6 | Painel lateral | **Adicionar** NPS History + bloco analista/estrategista |

### Helpers de formato existentes (`resources/js/lib/utils.js`)

- `formatCurrency(value)` — BRL completo, linha 8
- `formatCurrencyCompact(value)` — compacto K/M, linha 29. **JÁ IMPLEMENTADO** e atende o briefing (891K, 3.47M, 20.20M). **Não precisa criar novo helper**.
- `formatPercent(value, decimals=1)` — linha 53
- `formatDate(date)` / `formatDateTime(date)` — linhas 59, 64

---

## §3 — NPS history por User

### Schema de tabelas (Phases 31-33)

**`nps_surveys`** (Model: `app/Models/NpsSurvey.php`):
- `company_id` FK → companies
- `generated_by` FK → users (quem GEROU, não o profissional avaliado)
- `status` (`pending` / `completed`)
- `completed_at`, `expires_at`, `month_reference` (date YYYY-MM-01), `auto_generated`

**`nps_responses`** (Model: `app/Models/NpsResponse.php`):
- `survey_id` FK → nps_surveys
- `score_estrategista` int (1-5)
- `score_analista` int (1-5)
- `score_empresa` int (1-5)
- `respondent_name`, `comment`

### Vínculo NpsResponse → User (dono da carteira)

**NÃO existe campo direto** `analista_id` ou `estrategista_id` em `nps_surveys` ou `nps_responses`. O vínculo é INDIRETO via empresa:

```
User → company_users (role='consultor'/'estrategista') → companies.id
                                                        ↓
                                               nps_surveys.company_id
                                                        ↓
                                               nps_responses.survey_id
```

### Service/query existente para NPS por User

O `PortfolioScoreService::compute()` já usa NPS por User em produção (linha 253–260, `app/Services/PortfolioScoreService.php`):

```php
$surveys = NpsSurvey::with('response')
    ->whereIn('company_id', $companyIds)   // $companyIds = carteira do user
    ->where('status', 'completed')
    ->where('completed_at', '>=', $atualFrom)
    ->get();
$scoreField = $user->isMentor() ? 'score_estrategista' : 'score_analista';
$avgNps = $surveys->avg(fn ($s) => $s->response?->$scoreField ?? 0);
```

### Pipeline proposto para histórico NPS por User

```php
// Histórico NPS (últimos N meses) para o dono da carteira
$npsHistory = NpsSurvey::with('response')
    ->whereIn('company_id', $companyIds)
    ->where('status', 'completed')
    ->whereNotNull('completed_at')
    ->orderBy('month_reference')
    ->get()
    ->map(fn ($s) => [
        'month'             => $s->month_reference?->format('Y-m'),
        'company_name'      => $s->company->name,
        'score_analista'    => $s->response?->score_analista,
        'score_estrategista'=> $s->response?->score_estrategista,
    ]);
// Agregar por mês: média do score do profissional
$npsHistoryByMonth = $npsHistory->groupBy('month')->map(fn ($rows) => [
    'month'   => $rows->first()['month'],
    'avg'     => round($rows->avg(fn ($r) => $r[$scoreField] ?? null), 2),
    'count'   => $rows->filter(fn ($r) => $r[$scoreField] !== null)->count(),
]);
```

Injetar como prop `nps_history` no `renderPortfolio()`. Campo `score_estrategista` x `score_analista` conforme `$user->isMentor()`.

---

## §4 — Counters de Sugador por User (analista)

### Schema `app/Models/Sugador.php`

**Status disponíveis** (constantes definidas, linhas 98–113):

| Constante | valor string | Semântica |
|-----------|-------------|-----------|
| `STATUS_PENDENTE` | `'pendente'` | Detectado, aguardando ação |
| `STATUS_EM_ACAO` | `'em_acao'` | Em tratamento manual |
| `STATUS_RESOLVIDO` | `'resolvido'` | Tratado e fechado |
| `STATUS_IGNORADO` | `'ignorado'` | Descartado manualmente |
| `STATUS_MOVIDO` | `'movido'` | Movido para outra campanha |
| `STATUS_AUTO_RESOLVIDO` | `'auto_resolvido'` | Baixa automática (Phase 15) |

`STATUS_TRAVADOS` = `[em_acao, resolvido, ignorado, movido, auto_resolvido]` — não sobrescritos por re-análise.

**Não existe** campo `user_id` direto em `sugadores`. Vínculo via `company_id` → `company_users`.

### Scope existente (`Sugador::scopeDaCarteira`)

```php
// app/Models/Sugador.php linha 148–155
public function scopeDaCarteira(Builder $q, User $user): Builder
{
    return $q->whereIn('company_id', function ($sub) use ($user) {
        $sub->select('company_id')
            ->from('company_users')
            ->where('user_id', $user->id);
    });
}
```

### Atribuição via company_users

Analista = user com `company_users.role = 'consultor'` (conforme `User::consultorCompanies()`, linha 166–170 em User.php).

O scope `scopeDaCarteira` usa **todos os roles** do pivot (consultor + estrategista + outros). Para contagem específica de analista, restringir cargo:

```php
$companyIdsAnalista = DB::table('company_users')
    ->where('user_id', $user->id)
    ->where('role', 'consultor')
    ->pluck('company_id');
```

### Mapeamento status → blocos UI (proposto pelo planner)

| Bloco UI | Status | Justificativa |
|----------|--------|---------------|
| "Resolvidos" | `resolvido`, `movido`, `auto_resolvido` | Todos os desfechos positivos/encerrados |
| "Pendentes" | `pendente`, `em_acao` | Ainda requerem atenção |
| "Não-resolvidos" | `ignorado` | Explicitamente descartado sem resolução |

**Planner deve confirmar** se `auto_resolvido` vai em "resolvidos" ou se conta separado.

### Query proposta (counters no controller)

```php
$sugadorCompanyIds = $user->consultorCompanies()
    ->where('active', true)
    ->pluck('companies.id');

$sugadorCounters = [
    'resolvidos'      => Sugador::whereIn('company_id', $sugadorCompanyIds)
                            ->whereIn('status', ['resolvido', 'movido', 'auto_resolvido'])
                            ->count(),
    'pendentes'       => Sugador::whereIn('company_id', $sugadorCompanyIds)
                            ->whereIn('status', ['pendente', 'em_acao'])
                            ->count(),
    'nao_resolvidos'  => Sugador::whereIn('company_id', $sugadorCompanyIds)
                            ->where('status', 'ignorado')
                            ->count(),
];
```

Injetar como prop `sugador_counters` no `renderPortfolio()` (null se user não é analista).

---

## §5 — PPAs (existência + como contar)

### PPA é entidade própria — confirmado

Model: `app/Models/Ppa.php` (linha 9).

**Campos relevantes:**
- `company_id` FK → companies
- `mentor_id` FK → users (o Estrategista responsável pelo PPA) — **VÍNCULO DIRETO com User**
- `status` (valores a confirmar — ver migration; o model não define constantes de status, usa string livre)
- `completed_at` datetime — preenchido quando o PPA é concluído
- `due_date` date — prazo do PPA
- `sent_at` datetime

**Relações:**
- `Ppa::company()` → Company
- `Ppa::mentor()` → User via `mentor_id`
- `Ppa::tasks()` → PpaTask (hasMany)

### Critério "concluído" canônico

`completed_at IS NOT NULL` — campo explícito de conclusão. Opcionalmente checar `status = 'completed'` se a migration define esse enum (planner deve confirmar via migration ou `php artisan db:show --table ppas`).

### Query proposta para Estrategista

```php
// PPAs onde este user é o mentor (estrategista responsável)
$ppaCounters = [
    'concluidos_mes'  => Ppa::where('mentor_id', $user->id)
                            ->whereNotNull('completed_at')
                            ->whereMonth('completed_at', now()->month)
                            ->whereYear('completed_at', now()->year)
                            ->count(),
    'em_andamento'    => Ppa::where('mentor_id', $user->id)
                            ->whereNull('completed_at')
                            ->count(),
    'total'           => Ppa::where('mentor_id', $user->id)->count(),
];
```

Injetar como prop `ppa_counters` no `renderPortfolio()` (null se user não é estrategista).

### Distinção analista × estrategista no controller

O `cargoSlug` já é derivado via `user_setores` no método `renderPortfolio()` (linha 708–712):

```php
$cargoSlug = DB::table('user_setores as us')
    ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
    ->where('us.user_id', $user->id)
    ->whereIn('c.slug', ['analista', 'estrategista'])
    ->value('c.slug');
```

Usar esta lógica já existente para condicionar qual bloco calcular.

---

## §6 — Categoria "atingimento de meta" no PortfolioScoreService

### Estado atual (`app/Services/PortfolioScoreService.php`, linhas 159–199)

A categoria **já implementa dois caminhos**:

**Caminho A** (meta agregada da carteira via PortfolioGoal): usa `PortfolioGoal` revenue ativo do user. `metaOrigem = 'portfolio'`. — **Este caminho será DESCONTINUADO após Phase 48** (quando o CRUD de PortfolioGoal de revenue for removido).

**Caminho B** (soma de metas individuais por empresa): já funciona automaticamente se não houver PortfolioGoal. Usa `Goal::where('active', true)->where('metric', 'revenue')->whereIn('company_id', $companyIds)`. `metaOrigem = 'empresas:N'`.

```php
// linhas 165–195 — PortfolioScoreService.php
$metaModel = PortfolioGoal::where('user_id', $user->id)
    ->where('metric', 'revenue')
    ->active()
    ->orderByDesc('id')
    ->first();

if ($metaModel?->target_value !== null) {
    // Caminho A: meta de carteira (DESCONTINUADO no Phase 48)
    $metaTarget    = (float) $metaModel->target_value;
    $metaRealizado = (float) $totalAtual;
    $metaOrigem    = 'portfolio';
} else {
    // Caminho B: soma das metas individuais (FICA — vira o único caminho)
    $goalsIndividuais = Goal::where('active', true)->where('metric', 'revenue')
        ->whereIn('company_id', $companyIds)->get(...);
    // ...
}
```

### Mudança proposta (Phase 48)

**Remover o Caminho A** do service (bloco `if ($metaModel?->target_value !== null)`), forçando sempre o Caminho B. O cálculo resultante é: realizado = soma de revenue das empresas **que têm meta**; target = soma das metas individuais ativas.

**Ponto de mudança:** `PortfolioScoreService.php`, função `compute()`, linhas 165–195. Apenas deletar o bloco `if/else` e deixar só o corpo do `else` (Caminho B).

### Coluna `meta` em companies

O model `Company` não usa um campo de meta único — as metas individuais são gerenciadas por `Goal` (tabela `goals`, `company_id`, `metric='revenue'`, `target_value`). Não há coluna `meta_mensal` ou `meta_inicial` em `companies`.

### No controller `renderPortfolio()` (linhas 596–611)

A prop `meta_carteira` é derivada de `PortfolioGoal revenue ativo` + fallback `totalRevenueAtual`. Após Phase 48, esta prop será **REMOVIDA** do payload (ou substituída por um `meta_carteira_calculada` baseado apenas em metas individuais somadas — para alimentar os chips do hero e o gráfico de evolução).

### Impacto em testes

`php artisan test --filter PortfolioScore` — verificar se existe suite. Se existir, o teste de "atingimento_meta.origem = 'portfolio'" vai quebrar com a remoção do Caminho A.

---

## §7 — Pitfalls

1. **MariaDB local corrompido** (memory: `project_mariadb_local_corrompido`): rodar `tasklist | findstr mysqld` antes de qualquer comando que dependa de DB local. Testes: SQLite in-memory ou Mockery.

2. **Legacy columns Phase 7** (memory: `project_legacy_columns_rename`): `users.role` ainda é a coluna de role do sistema (admin/consultor/mentor). Não confundir com `setor_legacy`/`cargo_legacy` que foram renomeadas. O `cargoSlug` que diferencia analista×estrategista vem de `user_setores → cargos`, NÃO de `users.role`.

3. **`portfolio_user.role`** é passado como `$user->role` (admin/consultor/mentor) — NÃO é o cargo (analista/estrategista). O `ROLE_LABEL` no Show.jsx mapeia `consultor → 'Analista'` e `mentor → 'Estrategista'` (linha 45–51), mas isso é um mapeamento aproximado. A distinção precisa para o bloco diferenciado vem do `cargoSlug` derivado de `user_setores`.

4. **Mockup HTML `carteira-analistas-ui-proposta.html` NÃO existe no repo**. Foi mencionado no briefing UI mas não foi commitado. Usar apenas o briefing textual em `48-UI-BRIEF.md` como referência visual.

5. **Recharts já está no projeto** — usar para sparklines via `<LineChart>` mini sem eixos ou `<AreaChart>`. Não adicionar `react-sparklines`. Padrão existente: `resources/js/Pages/Portfolio/Show.jsx` linhas 718–770.

6. **`status` e `acao_recomendada` das empresas JÁ existem no payload** (derivados no controller, linhas 420–463) mas foram removidos da tabela UI em hotfix 2026-06-19 (comentário linha 778: "filtro de Status removido junto com as colunas Status e Acao por pedido do usuario"). Phase 48 reintroduz essas colunas — dados já chegam no frontend, só precisam ser exibidos novamente.

7. **PortfolioGoal** (meta agregada de carteira): o model/CRUD continuará existindo (pode ter outros tipos de meta além de revenue). Remover **apenas** a exibição de `meta_carteira` revenue e o cálculo `PortfolioScoreService` Caminho A. O CRUD admin pode ficar oculto ou restrito a métricas não-revenue.

8. **NPS `score_analista` x `score_estrategista`**: a decisão já existe no `PortfolioScoreService` (linha 258: `$scoreField = $user->isMentor() ? 'score_estrategista' : 'score_analista'`). Reusar exatamente a mesma lógica no histórico NPS do Phase 48.

9. **Período NPS history**: o campo `month_reference` em `nps_surveys` é `date` (YYYY-MM-01) e está disponível para agrupamento temporal. Surveys sem `month_reference` (anteriores à Phase 31 D-12) podem usar `completed_at`.

---

## §8 — Recomendações pro planner

1. **Backend primeiro, UI depois** — o gráfico de evolução e os chips do hero dependem de `meta_carteira` recalculada. Refatorar o service/controller antes de redesenhar o hero.

2. **Wave 1 — Backend / Remoção da meta agregada:**
   - Remover Caminho A do `PortfolioScoreService::compute()` (linhas 165–178)
   - Remover props `meta_carteira` e `portfolio_goals` do payload `renderPortfolio()` (controller linhas 368–391, 596–611, 820–826, 810)
   - Manter linha de "Meta acumulada" no gráfico mas recalcular `$metaCarteiraTarget` como soma das metas individuais (`Goal::metric='revenue'` das empresas da carteira)
   - Adicionar queries `nps_history`, `sugador_counters` e `ppa_counters` no controller (condicionais por `$cargoSlug`)
   - Remover CRUD storeGoal/updateGoal/destroyGoal de `portfolio.goals.*` (rotas `routes/web.php:341-343`) OU deixar restrito a métricas não-revenue

3. **Wave 2 — Hero + KPIs + Tabela:**
   - Reformular card principal (hero): remover chips de `meta_carteira`; manter crescimento e score
   - Substituir KPI "Meta da carteira" pelo bloco diferenciado analista/estrategista (props `sugador_counters` OU `ppa_counters`)
   - Tabela: reintroduzir colunas Status + Ação + nova coluna Crescimento (sparkline Recharts mini)
   - Responsividade: tabela → cards no mobile

4. **Wave 3 — Painel lateral (NPS + bloco diferenciado):**
   - Bloco "Histórico NPS": mini LineChart Recharts (média mensal por ponto) + última nota + count
   - Bloco analista (se `cargoSlug === 'analista'`): cards de counters Sugadores
   - Bloco estrategista (se `cargoSlug === 'estrategista'`): cards de counters PPAs

5. **Componentes a extrair** (candidatos — decisão final do planner):
   - `Carteira/SparklineCrescimento.jsx` — mini LineChart sem eixos, verde/vermelho/cinza
   - `Carteira/NpsHistory.jsx` — bloco lateral com gráfico + stats
   - `Carteira/SugadorCounters.jsx` — bloco analista
   - `Carteira/PpaCounters.jsx` — bloco estrategista

6. **Critério de cor do sparkline**: usar `queda_mom_pct` já presente no payload de cada empresa (linha 353 controller). Threshold sugerido: `> +2%` = verde, `< -2%` = vermelho, entre = cinza. Planner confirma.

7. **Testes backend**: PHPUnit Feature test cobrindo: (a) prop `meta_carteira` ausente do payload; (b) counters sugadores para analista; (c) counters PPAs para estrategista; (d) nps_history por month; (e) PortfolioScoreService caminho B exclusivo.

8. **Não há bloco consolidado admin** na `Portfolio/Carteiras.jsx` que seja afetado — essa página usa `renderCarteirasConsolidadas()` separado e não renderiza `meta_carteira` individual.

---

## RESEARCH COMPLETE

Arquivo: `.planning/phases/48-redesign-da-carteira-individual-analista-estrategista/48-RESEARCH.md`
