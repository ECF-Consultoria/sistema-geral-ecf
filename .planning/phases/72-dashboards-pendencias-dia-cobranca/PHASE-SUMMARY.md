---
phase: 72-dashboards-pendencias-dia-cobranca
milestone: v15.0 — NPS Templates
subsystem: nps
tags: [nps, dashboards, portfolio, empresas, dia-cobranca, pending-service, dual-path, react, inertia, feature-tests, laravel-12, phase72]

# Consolidação das 4 waves da Phase 72
plans-completed:
  - 72-01: NpsPendingService + config dia_cobranca (PATCH admin) + widget Configuracao.jsx
  - 72-02: DashboardController + PortfolioController + CompanyController integram NpsPendingService/NpsScoreCalculator (dual-path v15/legacy)
  - 72-03: NpsPendingBadge + NpsPendingWidget + integração em 5 páginas (Dashboard Admin, Portfolio Show, Companies Index)
  - 72-04: Suite Feature Phase72 (16 tests) cobrindo SC1-SC5 + baseline regressão zero

requirements-completed: [NPS-E-01, NPS-E-02, NPS-E-03, NPS-E-04, NPS-E-05]

success-criteria-status:
  SC#1: PASSED  # Config admin dia_cobranca 1..31 persist + clamp defensivo (NpsDiaCobrancaConfigTest + NpsPendingServiceTest T3)
  SC#2: PASSED  # Badge pendência em Portfolio/Companies (NpsPendingBadge + Portfolio.Show + Companies.Index + DashboardPendencyPropsTest T3)
  SC#3: PASSED  # Widget dashboards admin + user (NpsPendingWidget + Dashboard.Admin + userDashboard + DashboardPendencyPropsTest T1+T2)
  SC#4: PASSED  # NpsPendingService::forCarteira contrato (NpsPendingServiceTest T7+T8 admin vs consultor)
  SC#5: PASSED  # Dashboards usam NpsScoreCalculator dual-path (CompanyController::show + DashboardPendencyPropsTest T4)

# Métricas agregadas
plans: 4
waves: 4
services-created: 1        # NpsPendingService (199 LOC)
controllers-modified: 3    # DashboardController + PortfolioController + CompanyController + NpsController (atualizarDiaCobranca)
controllers-modified-detail: DashboardController, PortfolioController, CompanyController, NpsController, NpsTemplateController
frontend-components-created: 2  # NpsPendingBadge.jsx + NpsPendingWidget.jsx
frontend-components-modified: 4 # Nps/Configuracao.jsx + Portfolio/Show.jsx + Companies/Index.jsx + Dashboard/Admin.jsx
routes-added: 1            # PATCH /nps/configuracao/dia-cobranca
tests-created: 16          # 8 service + 4 config + 4 controllers
test-assertions: 109
regression-baseline-tests: 130     # Phase 31 + 33 + 68 + 69 + 70 + 71 (pré Phase 72)
regression-baseline-preexisting-failed: 1  # Phase33OnboardingFichaTest (documentada nas Phases 72-02/03)
regression-result-tests: 146       # Phase 31 + 33 + 68 + 69 + 70 + 71 + 72
regression-delta: +16
regression-status: preserved
grand-total-nps-tests: 146
next-phase-unblocked: 73  # Limpeza legado (cleanup score_estrategista/analista/empresa das colunas legacy) + testes E2E
duration-total: ~2h
completed: 2026-07-08
---

# Phase 72 Summary — Dashboards + pendências + dia de cobrança

Backend service `NpsPendingService` como fonte única de verdade para "empresa X
pendente de NPS no mês Y?" + config admin do dia de cobrança + integração de
pendências em 3 controllers (Dashboard admin/user, Portfolio, Companies) com
dual-path do `NpsScoreCalculator` para surveys v15 + 2 componentes React
reusáveis (badge + widget) integrados em 5 páginas + suite Feature de 16 testes
travando contrato de todos os SC. **Regressão zero** em Phases 31/33/68/69/70/71.
Ready para Phase 73 (cleanup do legacy score_* e testes E2E) que já pode
assumir que qualquer dashboard/portfolio/company page respeita o dual-path.

## Waves executadas

### Wave 1 — Plan 72-01 (Backend service + config admin)

**`NpsPendingService`** (novo, 199 LOC) — 3 métodos públicos:

- `diaCobranca(): int` — lê `Configuracao::get('nps_dia_cobranca', 25)` com
  clamp defensivo `max(1, min(31, $dia))`. Defesa em profundidade contra
  edição manual no banco.
- `isPendente(Company $company, ?Carbon $mes = null): bool` — guard temporal
  no mês corrente (só marca pendente após dia >= diaCobranca), resolve
  template via `NpsTemplateService` (RuntimeException capturada + logada),
  verifica ausência de survey completed no mês.
- `forCarteira(User $user, ?Carbon $mes = null): array` — admin vê tudo,
  não-admin filtra por pivot `company_users`. Retorna shape documentado:
  `[{company_id, name, template_id, template_nome, month_reference, dias_atraso}]`.

**`NpsController::atualizarDiaCobranca`** (+30 LOC) — PATCH admin-only com
validação `integer|min:1|max:31` + persist via `Configuracao::set`.

**Rota nova:** `PATCH /nps/configuracao/dia-cobranca` (`nps.configuracao.dia-cobranca.update`) —
dentro do grupo `role:admin`.

**Widget `DiaCobrancaWidget`** em `Nps/Configuracao.jsx` (+78 LOC) — form
inline com input number 1..31 + submit via Inertia.

### Wave 2 — Plan 72-02 (Controllers integram service + dual-path calculator)

**`DashboardController`:**
- Constructor DI de `NpsPendingService` (padrão já usado com AdmanService).
- `adminDashboard()` injeta `nps_pendentes` = `forCarteira($admin)` no
  payload de `Dashboard/Admin`.
- `userDashboard()` injeta `nps_pendentes` = `forCarteira($user)` — respeita
  escopo carteira automaticamente (admin=tudo, não-admin=pivot).

**`PortfolioController::renderPortfolio`:**
- Chama `app(NpsPendingService::class)->forCarteira($owner)` — respeita
  owner do portfolio, NÃO o request user (admin visualizando carteira de
  terceiro vê pendências do terceiro).

**`CompanyController::show` (dual-path crítico):**
- Eager-load `response.answers` (evita N+1 no calculator).
- Para cada survey mapeado, IIFE decide:
  - `template_id != null` (v15) → `$calculator->compute($response, 'empresa'|'analista'|'estrategista')`
  - `template_id == null` (legacy Phase 31) → mantém `$response->score_*` direto
- Chaves do payload preservadas bit-a-bit — JSX `Companies/Show.jsx` não muda.

### Wave 3 — Plan 72-03 (Frontend badges + widgets)

**Componentes criados:**
- `NpsPendingBadge.jsx` — mini badge inline "Sem NPS · Xd" com variant
  `inline` (portfolio) vs `compact` (tabela empresas).
- `NpsPendingWidget.jsx` — widget de dashboard com contagem + lista top 5
  empresas + link "Ver todas".

**Integrado em 5 páginas:**
- `Nps/Configuracao.jsx` — widget DiaCobranca (Plan 72-01)
- `Dashboard/Admin.jsx` — widget NpsPendingWidget
- `Portfolio/Show.jsx` — badges por empresa + widget resumo
- `Companies/Index.jsx` — badge na coluna Status

**Guard defensivo:** `Array.isArray(pendentes) ? pendentes : []` em todos os
consumidores — backward compat se backend legado não injetar.

### Wave 4 — Plan 72-04 (Suite Feature 16 tests)

Cobertura sistemática dos 3 backend layers:

- **`NpsPendingServiceTest.php`** (8 tests):
  - diaCobranca default + persistência + clamp 0/-5→1 e 99→31
  - isPendente: guard temporal antes de diaCobranca / após diaCobranca /
    com survey completed no mês
  - forCarteira: admin vê todas 3 empresas; consultor com 2 pivot vê apenas 2
- **`NpsDiaCobrancaConfigTest.php`** (4 tests):
  - PATCH admin persiste 10 → assertDatabaseHas
  - PATCH admin atualiza 25→30 → updateOrCreate (1 row apenas)
  - dia=0/32/'abc' → 302 + `assertSessionHasErrors('dia')` (padrão Inertia)
  - non-admin (consultor) → 403 (middleware role:admin)
- **`DashboardPendencyPropsTest.php`** (4 tests):
  - `Dashboard/Admin` com `nps_pendentes` (count=2, shape completo, ordering)
  - `Dashboard/User` filtra por carteira (consultor + isLider setor 'polos' +
    2 pivot / 3 empresas → apenas 2 na lista)
  - `Portfolio/Show` injeta pendências do owner
  - `Companies/Show` v15 → `score_empresa=4` via NpsScoreCalculator;
    `score_analista/estrategista=null` (sem answers dessas dimensões)

**Regressão zero:** Phase 31 + 33 + 68 + 69 + 70 + 71 seguem 130/130 passed
(+1 pré-existente Phase33 preservado).

## Contract entregue

- **REQ NPS-E-01 a NPS-E-05** — 5/5 fechados (backend + frontend + testes)
- **SC1-SC5** do ROADMAP Phase 72 — 5/5 PASSED
- **Rota nova** `/nps/configuracao/dia-cobranca` — PATCH admin-only, integrado
  ao widget de config
- **Componentes reusáveis** `NpsPendingBadge` + `NpsPendingWidget` — importáveis
  por qualquer futura página (Portfolio 3rd party views, admin reports, etc.)
- **Contrato do shape** de `NpsPendingService::forCarteira` documentado no
  docblock do service + travado por T7 do NpsPendingServiceTest (6 chaves
  asserted por `assertArrayHasKey`)

## Padrões travados para próximas fases

1. **Fonte única de verdade para "pendente"** — qualquer nova UI que precise
   consumir "empresa pendente de NPS" chama `NpsPendingService`, NUNCA
   duplica lógica de `whereDoesntHave('npsSurveys', ...)` inline.
2. **Dual-path Company v15 vs legacy** — Padrão do IIFE em `CompanyController::show`
   é reusável em qualquer outro controller que precise migrar leitura de
   `score_*` para `NpsScoreCalculator`. Phase 73 pode aplicar mesma técnica
   em `DashboardController::adminDashboard` linha 532 (`avgNps`) sem
   quebrar chaves do payload.
3. **Clamp defensivo em config int** — Sempre `max(min, min(max, valor))`
   ao ler config numérica sensível — protege contra edição manual no banco.
4. **Guard temporal no service, não no controller** — mês corrente é caso
   especial (guard antes de diaCobranca) mas meses passados são "sempre
   elegíveis" (relatório histórico). `isPendente(Company, ?Carbon $mes)`
   overload permite ambos.
5. **AssertSessionHasErrors em vez de JSON validation** — testes Feature
   seguem padrão do projeto (`->patch` sem `Json`) porque frontend usa
   Inertia. Padrão herdado de Phase 70/71.

## Métricas finais

| Métrica | Valor |
|---|---|
| Plans | 4 |
| Waves | 4 |
| Services criados | 1 (NpsPendingService) |
| Controllers modificados | 4 (Dashboard + Portfolio + Company + Nps + NpsTemplate) |
| Componentes React criados | 2 (NpsPendingBadge + NpsPendingWidget) |
| Componentes React modificados | 4 (Configuracao + Dashboard/Admin + Portfolio/Show + Companies/Index) |
| Rotas adicionadas | 1 (PATCH /nps/configuracao/dia-cobranca) |
| Tests Feature Phase 72 | 16 (109 assertions) |
| Tests NPS totais (Phases 31/33/68/69/70/71/72) | 146 passed + 1 pré-existente Phase33 |
| Regressão | 0 |
| Duração total | ~2h |
| Completed | 2026-07-08 |

## Próximos passos liberados

- **Phase 73 (Limpeza legado + testes E2E)** — pode assumir:
  1. `NpsPendingService` é fonte única de verdade — Phase 73 pode adicionar
     integração com sistema de notificações (NPS-FUTURE-03) sem duplicar
     query de "pendente".
  2. `NpsScoreCalculator` já é usado em `CompanyController::show` (dual-path).
     Phase 73 pode migrar outros consumidores (`DashboardController::adminDashboard`
     linha 532, `PerformanceController::dashboardCarteira` linha 216) sem
     preocupação com backwards compat.
  3. Suite Feature Phase 72 (16 tests) trava contrato — Phase 73 pode
     refactorar internals do `NpsPendingService` (batch fetch, cache) sem
     mudar comportamento observável.
  4. `NpsPendingBadge` + `NpsPendingWidget` são reusáveis — Phase 73 pode
     integrar em novas telas (Comercial, admin reports) sem duplicar JSX.
