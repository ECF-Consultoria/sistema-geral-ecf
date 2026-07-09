# Phase 74: Módulo Desempenho — simplificação para 4 parâmetros + bonificação - Context

**Gathered:** 2026-07-09
**Status:** Ready for planning

<domain>
## Phase Boundary

Reescrita da engine de score de desempenho do time Performance: v1 (`PortfolioScoreService`, 6 métricas ponderadas) → v2 (`DesempenhoScoreService`, 4 parâmetros com média direta), com snapshot mensal fechado + diário coexistindo, config admin de faixas de bônus e artigo dinâmico no `/manual` sincronizado com a config.

Escopo NÃO inclui integração real de absenteísmo (placeholder "Em breve") nem `PublicadorScoreService` (setor MLB).

</domain>

<spec_lock>
## Requirements (locked via SPEC.md)

**14 requirements are locked.** See `74-SPEC.md` for full requirements, boundaries, and acceptance criteria.

Downstream agents MUST read `74-SPEC.md` before planning or implementing. Requirements are not duplicated here.

**In scope (from SPEC.md):**
- `DesempenhoScoreService` (renamed de `PortfolioScoreService`) com engine de 4 parâmetros
- Tabela `bonus_faixas` + Model + seed inicial de 4 faixas
- Comando `desempenho:consolidar-mes` novo (fechado mensal); `desempenho:snapshot-scores` diário atual **preservado**
- Rota admin `/desempenho/configuracao` + Controller + página Inertia
- Rotas REST para editar faixas + toggle active
- Frontend: `Performance/{Dashboard,Index,Show}.jsx` reescritos com view mensal default + toggle diário
- Artigo dinâmico `/manual/desempenho-bonificacao` sincronizado com `bonus_faixas`
- Refactor de callsites de `PortfolioScoreService` para `DesempenhoScoreService`
- Fonte ML OAuth first (via `MetricsProviderFactory`) + Adman fallback para faturamento; Adman canônico para margem
- Suite de testes Feature cobrindo REQ 1-14 com fixture Carlos como âncora

**Out of scope (from SPEC.md):**
- `PublicadorScoreService` (setor MLB) — Phase separada se a diretoria mudar lá também
- Integração real de absenteísmo (biometria/login) — placeholder "Em breve" nesta phase
- Migração de snapshots históricos v1 para v2 — histórico v1 preserva na tabela mas dashboard filtra por `mes_referencia >= '2026-08-01'`
- Comparativo v1 vs v2 na UI — big bang, sem convivência
- Bônus por cargo diferenciado — faixas globais
- Notificação push/email ao analista/estrategista quando fecha o mês — futuro
- Backfill de snapshots mensais para meses anteriores a 2026-08-01

</spec_lock>

<decisions>
## Implementation Decisions

### Snapshot: DB & modos

- **D-01 · Evoluir tabela atual, não criar nova.** `desempenho_score_snapshots` ganha coluna `mes_referencia DATE NULL` via migration alter. Zero perda de histórico v1.
- **D-02 · Uma tabela, duas modalidades.** Snapshot diário: `ref_date` populada, `mes_referencia = NULL`. Snapshot mensal fechado: `ref_date = mes_referencia = YYYY-MM-01`. Distinção via WHERE `mes_referencia IS NULL` vs `WHERE mes_referencia = '...'`.
- **D-03 · Unique key evolutiva.** Dropar unique `(user_id, ref_date)`; criar novo unique `(user_id, ref_date, mes_referencia)` que permite coexistência. Rows históricas v1 têm `mes_referencia = NULL` e continuam válidas.
- **D-04 · Filtro de UI para novo shape.** `Performance/Dashboard.jsx` filtra `WHERE mes_referencia >= '2026-08-01'` (ou coluna versionamento) para não misturar scores v1 e v2 no ranking/gráficos.

### Service: naming & refactor

- **D-05 · Renomear para `DesempenhoScoreService`.** Novo namespace `App\Services\DesempenhoScoreService` alinhado ao módulo `/desempenho`. IDE rename atualiza 4 callsites: `PerformanceController`, `PortfolioController`, `DashboardController::adminDashboard`, `SnapshotDesempenhoScores`.
- **D-06 · Substituir inplace, sem `@deprecated`.** V1 apagado no mesmo commit. Big bang (SPEC-14). Sem coexistência.
- **D-07 · Nova assinatura `compute(User $u, Carbon $mesReferencia): array`.** Shape do retorno documentado em SPEC-01. Nota final calculada via média direta (SPEC-02).

### Cron: schedules

- **D-08 · Dois comandos, dois schedules.**
  - `desempenho:snapshot-scores` (existente, PRESERVADO): continua rodando diário 13:30 BRT. Grava snapshot diário (`mes_referencia = NULL`). Command reescrito internamente para usar `DesempenhoScoreService` novo (mesma engine, computa nota do mês em curso parcialmente).
  - `desempenho:consolidar-mes` (NOVO): roda dia 1 de cada mês às 14:00 BRT (`->monthlyOn(1, '14:00')`). Calcula fechado do mês anterior via `DesempenhoScoreService::compute(user, Carbon::parse('previous month')->startOfMonth())`. Grava row com `mes_referencia = YYYY-MM-01`.
- **D-09 · Command aceita `--mes=YYYY-MM` para reprocessar** (defesa em profundidade + útil para catch-up após incident).

### Config UI: rota & componente

- **D-10 · Nova rota admin `/desempenho/configuracao`.** Padrão do projeto (consistente com `/nps/configuracao`, `/mlb/configuracao`).
- **D-11 · Controller `DesempenhoConfigController`.** Namespace `App\Http\Controllers`. Métodos: `index()` (lista faixas), `updateFaixa(BonusFaixa)`, `toggleActive(BonusFaixa)`, `createFaixa(Request)`.
- **D-12 · Página `Desempenho/Configuracao.jsx`.** Rota registrada com `middleware('role:admin')`. Item na sidebar dentro do grupo Desempenho existente.
- **D-13 · Validação:** `nota_min < nota_max`; `nota_min >= 0`; `nota_max <= 5`; sem sobreposição entre faixas `ativo=true` (valida em `FormRequest` dedicado).

### `bonus_faixas` schema

- **D-14 · Colunas cheias:**
  - `id` bigInteger PK
  - `slug` varchar(50) UNIQUE — chave de código estável (`sem_bonus`, `basico`, `intermediario`, `maximo`)
  - `nome` varchar(100) — label UI editável pelo admin
  - `descricao` text NULL — texto explicativo renderizado no artigo `/manual`
  - `nota_min` DECIMAL(3,2) — piso inclusivo, [0.00-5.00]
  - `nota_max` DECIMAL(3,2) — teto inclusivo, [0.00-5.00]
  - `ordem` unsignedSmallInteger — order visualization (ascending)
  - `ativo` boolean default true
  - `timestamps`
- **D-15 · Model `App\Models\BonusFaixa`** — `LogsActivity` trait, `fillable` completo, casts (`nota_min => 'decimal:2'`, `nota_max => 'decimal:2'`, `ativo => 'bool'`).
- **D-16 · Seed inicial em migration própria:**
  - `sem_bonus [0.00, 3.99]` ordem=1
  - `basico [4.00, 4.49]` ordem=2
  - `intermediario [4.50, 4.99]` ordem=3
  - `maximo [5.00, 5.00]` ordem=4
- **D-17 · Método `DesempenhoScoreService::classificarFaixa(float $nota): string`** — retorna `slug` da faixa. Lookup DB, cache in-memory por request. Regra "2 meses consecutivos intermediário → máximo" implementada fora do lookup, em método separado `promoverPor2MesesConsecutivos(User, Carbon, string $faixaAtual): string`.

### Frontend: view default & sidebar

- **D-18 · Dashboard abre em VIEW MENSAL.** Card destacado no topo: "Mês em curso: julho/2026 (parcial)" + card "Mês fechado: junho/2026". Toggle "Ver diário" mostra série rolling 30d histórica (do snapshot diário).
- **D-19 · `Performance/Show.jsx` (view individual).** Card por parâmetro (NPS/Faturamento/Margem/Absenteísmo). Absenteísmo placeholder com badge "Em breve" (SPEC-06). Show mostra por default o mês fechado mais recente com toggle para escolher outro mês.
- **D-20 · `Performance/Index.jsx` (lista ranking).** Ranking do mês fechado mais recente. Usuários com `sem_carteira=true` filtrados (SPEC-10).
- **D-21 · Sidebar do AppLayout.** Item "Configuração Desempenho" adicionado no grupo Desempenho existente, gated por `role:admin`.

### Manual doc: componente & sync

- **D-22 · Componente `Manual/Artigos/DesempenhoBonificacao.jsx`.** Segue padrão do `Cronograma.jsx` já existente.
- **D-23 · Entrada em `resources/js/Pages/Manual/artigos.js`.** Slug: `desempenho-bonificacao`. Título: "Régua de Bonificação — Desempenho".
- **D-24 · `ManualController::show()` recebe slug** e faz lookup no `artigos.js` (existente). Para slug `desempenho-bonificacao`, passa prop `bonus_faixas = BonusFaixa::where('ativo', true)->orderBy('ordem')->get()` + prop `metodologia_texto` (texto explicativo estático dos 4 parâmetros).
- **D-25 · Render dinâmico.** Componente renderiza texto estático + tabela dinâmica com nome/faixa/descricao das rows de `bonus_faixas`. Admin edita → doc reflete no próximo page load.

### Test approach

- **D-26 · SQLite RefreshDatabase + factories reais.** Suite `DesempenhoScoreServiceTest.php` e `DesempenhoConfigControllerTest.php` sob `tests/Feature/Phase74/`.
- **D-27 · Provider factory swap para stub.** `MetricsProviderFactory` binding resolvido para stub que retorna revenue conhecidos por empresa. Isola provider externo (ML/Adman HTTP) mas exercita a lógica end-to-end via DB real.
- **D-28 · Fixture Carlos como âncora bloqueante.** Teste `test_fixture_carlos_retorna_nota_3_35_sem_bonus`. Cria User + 3 Companies + NpsSurvey/Response com nota média 4.25 + AdmanMetric stubbed para variações -2%/+7%/+4% (média 3%) + margem stubbed para média 2.8%. Compute retorna `nota_final = 3.35` + `faixa_bonus = 'sem_bonus'`.
- **D-29 · Edge cases cobertos com testes dedicados:**
  - `test_user_sem_carteira_retorna_sem_carteira_true`
  - `test_user_sem_nps_no_mes_forca_nps_zero`
  - `test_empresa_nova_excluida_do_calculo_variacao`
  - `test_2_meses_consecutivos_intermediario_promove_para_maximo`
  - `test_idempotencia_do_command_consolidar_mes`
  - `test_validacao_sobreposicao_de_faixas_ativas`

### Claude's Discretion

- Layout específico dos cards no `Performance/Dashboard.jsx` (proporção grid, cores exatas) — seguir padrão dark/glass já estabelecido em `Nps/Index.jsx` (redesign 2026-07-08).
- Nome dos métodos internos privados de `DesempenhoScoreService` (`computeVarFaturamento`, `computeVarMargem`, etc) — padrão camelCase pt-BR estabelecido.
- Estrutura dos FormRequests para validação — seguir padrão existente do projeto.
- Namespace dos testes Feature — `Tests\Feature\Phase74\*Test`.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Spec locked (source of truth)
- `.planning/phases/74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o/74-SPEC.md` — **Locked requirements — MUST read before planning**. 14 requirements + boundaries + acceptance criteria.

### Código a substituir
- `app/Services/PortfolioScoreService.php` — engine v1 (6 métricas ponderadas). Substituir pelo `DesempenhoScoreService` no mesmo commit.
- `app/Console/Commands/SnapshotDesempenhoScores.php` — command diário existente. Reescrever internamente para usar novo service (mesmo agendamento 13:30 BRT preservado).
- `database/migrations/2026_06_30_000001_create_desempenho_score_snapshots_table.php` — schema atual da tabela snapshot. Nova migration `alter` adiciona `mes_referencia`.

### Callsites de `PortfolioScoreService` a refatorar
- `app/Http/Controllers/PerformanceController.php:13,22,61,190` — DI + uso em index/dashboardCarteira
- `app/Http/Controllers/PortfolioController.php:16,27,666,898` — DI + uso em renderPortfolio
- `app/Http/Controllers/DashboardController.php:752` — inline `app(PortfolioScoreService::class)` no adminDashboard

### Integrações Adman/ML (fonte de dados)
- `app/Services/AdmanService.php` — método `getCachedGrossBillingsMany` (cache Redis gross billing per range)
- `app/Services/Metrics/MetricsProviderFactory.php` — factory que decide provider por empresa (Phase 61 flow ML-first + Adman fallback)
- Providers implementados: `AdmanSugadoresProvider`, `MlSugadoresProvider`, `UnifiedProvider` — factory `caseFor(Company)` retorna caso ADR DATA-04

### NPS integration
- `app/Services/Nps/NpsScoreCalculator.php` — dual-path v15/legacy usado por componente NPS
- `.planning/phases/72-.../72-01-SUMMARY.md` — Phase 72 estabeleceu padrão de dual-path para score NPS por dimensão

### Frontend a reescrever
- `resources/js/Pages/Performance/Dashboard.jsx` — dashboard consolidado
- `resources/js/Pages/Performance/Index.jsx` — ranking
- `resources/js/Pages/Performance/Show.jsx` — view individual do analista/estrategista
- `resources/js/Pages/Manual/artigos.js` — catálogo do manual (adicionar entry `desempenho-bonificacao`)
- `resources/js/Pages/Manual/Artigos/Cronograma.jsx` — padrão de referência para novo `DesempenhoBonificacao.jsx`

### Layouts & padrões visuais
- `resources/js/Layouts/AppLayout.jsx` — sidebar; adicionar item "Configuração Desempenho" no grupo Desempenho existente (admin-only)
- `resources/js/Pages/Nps/Index.jsx` — redesign dark/glass 2026-07-08 como padrão de reference

### ROADMAP entry
- `.planning/ROADMAP.md` — Phase 74 entry (top of file, tail da milestone v15.0)

### Convenções do projeto
- `CLAUDE.md` — stack, naming, convenções, constraints (PT-BR outputs para GSD)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `Company::estrategista()` / `Company::consultor()` — belongsToMany via `company_users` pivot (role='estrategista'/'consultor'). Usado para resolver carteira do user.
- `User->companies()` — belongsToMany reverso. Padrão usado em `NpsPendingService::forCarteira` e `PortfolioScoreService::compute` atual.
- `MetricsProviderFactory` (Phase 61) — resolve provider (ML/Adman/Unified) por empresa. Reuso direto para faturamento (fallback Adman). Margem SEMPRE via Adman (SPEC-05).
- `NpsScoreCalculator::compute(NpsResponse, dimensao)` — dual-path v15/legacy. Reuso direto para componente NPS por analista/estrategista.
- `AdmanMetric` — coluna `contribution_margin` (margem contribuição por dia). SUM por mês para variação. Coluna `revenue_prev_period` NÃO usada (v2 compara mês a mês fechado, não rolling).
- `Configuracao::get/set` — key-value store. NÃO usar para faixas de bônus (SPEC-07 exige tabela dedicada).
- `LogsActivity` trait (Spatie) — Model `BonusFaixa` implementa `getActivitylogOptions()` seguindo padrão do `NpsTemplate::getActivitylogOptions()`.

### Established Patterns
- **Controllers admin**: `middleware('role:admin')` aplicado no grupo de rotas. Padrão `Nps/EnvioAutomatico`, `Nps/Configuracao`, `Sugadores`, etc.
- **FormRequest**: validação encapsulada em classes dedicadas (`app/Http/Requests/*`) quando complexa; inline `$request->validate()` para casos simples.
- **Inertia render**: `Inertia::render('Path/Component', ['prop' => ...])` — page component em `resources/js/Pages/`.
- **Comandos artisan**: signature `desempenho:acao` (kebab-case), constructor DI, `handle()` retorna `int` (`SUCCESS`/`FAILURE`), `Log::info` estruturado com prefix `[Modulo]`.
- **Cron schedule**: `Schedule::command(...)->dailyAt(...)/monthlyOn(1, ...)->timezone('America/Sao_Paulo')->name(...)->onOneServer()->withoutOverlapping()`.
- **Testes Feature**: `RefreshDatabase` + `DB::statement('PRAGMA foreign_keys = ON')` no setUp. `use PHPUnit\Framework\Attributes\Test;`. Factories padrão do projeto (`User::factory`, `Company::factory`).
- **Dark/glass design tokens**: `bg-ecf-card`, `border-white/[0.08]`, `text-ecf-yellow`, `cn()` para composição. Rodar `npm run build` após mudanças frontend.

### Integration Points
- **`SnapshotDesempenhoScores` command**: preservar schedule 13:30 BRT diário. Substituir chamada interna `app(PortfolioScoreService::class)` → `app(DesempenhoScoreService::class)`.
- **`DashboardController::adminDashboard` widget `performance_equipe`**: consumir do snapshot mais recente (mensal fechado se existe, senão diário). Não recalcula on-the-fly no dashboard admin.
- **`PerformanceController::index` e `dashboardCarteira`**: consumir também do snapshot; UI mostra mês fechado como default (D-18).
- **`PortfolioController::renderPortfolio`**: mesmo pattern — usa `DesempenhoScoreService::compute` (ou snapshot) para score do owner da carteira.
- **`ManualController::show`**: pequena mudança — para slug `desempenho-bonificacao`, passa prop `bonus_faixas` (evita fetch client-side).
- **Sidebar `AppLayout.jsx`**: item novo "Configuração Desempenho" no grupo Desempenho existente, gated por `role:admin` (não `permission`).

</code_context>

<specifics>
## Specific Ideas

- **Fixture "Carlos" como âncora bloqueante** — sistema de teste explicitamente reproduz o exemplo da spec (NPS 4.25 + var_fat 3% + var_margem 2.8% → nota 3.35 → sem bônus). Se este teste passar, a matemática está fiel à decisão da diretoria. Serve como contra regressão silenciosa.
- **Coexistência diário + mensal como semântica primária** — usuário explicitou "quero snapshot diário E mensal" durante discussão. Não é escolha binária; ambos coexistem na mesma tabela.
- **Renomear para `DesempenhoScoreService` (não manter `PortfolioScoreService`)** — usuário escolheu semântica clara sobre menor churn. Reflete que o service não é sobre "portfolio" (carteira) mas sobre "desempenho profissional".
- **Manual doc sincronizado com config em tempo real** — `ManualController::show` faz fetch de `bonus_faixas` na render. Sem cache, sem staleness. Admin edita config → recarrega doc → vê mudança.

</specifics>

<deferred>
## Deferred Ideas

- **Integração real de absenteísmo** — biometria facial da porta OU login-based. Fonte de dados em definição pela diretoria. Standby até definição.
- **Notificação push/email quando fecha o mês** — ao consolidar mês (dia 1), notificar analista/estrategista do resultado. Fora de escopo desta phase.
- **Bônus com valor em R$** — coluna `bonus_valor` na tabela `bonus_faixas` para tornar bônus calculável monetariamente. Scope creep — a spec só define faixas, não valores. Deferido.
- **`PublicadorScoreService` (setor MLB) receber mesma simplificação** — se a diretoria decidir mudar lá também, abrir Phase 75 dedicada.
- **Bônus diferenciado por cargo (analista vs estrategista)** — v2 usa faixas globais. Se diretoria quiser diferenciação, alterar schema para `faixa_cargo_id` FK. Futuro.
- **Comparativo v1 vs v2 na UI por período de transição** — decidido big bang (SPEC-14); sem convivência.
- **Backfill de snapshots mensais para meses anteriores a 2026-08-01** — snapshot mensal começa em agosto/2026. Sem histórico retroativo.

</deferred>

---

*Phase: 74-modulo-desempenho-simplificacao-4-parametros-bonificacao*
*Context gathered: 2026-07-09*
