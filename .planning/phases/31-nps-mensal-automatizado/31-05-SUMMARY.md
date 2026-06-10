---
phase: 31-nps-mensal-automatizado
plan: 05
subsystem: nps
tags: [ui, controller, dashboard, nps, recharts, cleanup]
requires:
  - 31-01 (schema 1-5 + month_reference + auto_generated + email_cliente)
  - 31-02 (NpsController::generate/respond/submitResponse na escala 1-5)
  - 31-03 (Nps/Respond.jsx escala 1-5)
  - 31-04 (CompanyController::show payload já com chaves novas)
provides:
  - "NpsController::index reescrito (filtro mês + cards + série 12m + lista)"
  - "Nps/Index.jsx admin completo (filtro mês + 3 CardMedia + LineChart Recharts 12m + tabela paginada)"
  - "Widget NPS no Dashboard Admin ajustado para escala 1-5 (rótulos Excelente/Bom/Ruim — D-09)"
  - "Cleanup completo de chaves legacy score_consultant/mentor/overall em DashboardController + PerformanceController + Companies/Show.jsx"
affects:
  - "Fecha a Phase 31 NPS Mensal Automatizado (wave 3 de 3)"
  - "Pronta para deploy agrupado de toda a Phase 31"
tech_stack:
  added: []
  patterns:
    - "Recharts LineChart 12 meses × 3 séries (estrategista/analista/empresa) com YAxis [1,5] fixo"
    - "Filtro temporal Inertia via Select shadcn + router.get com preserveState/preserveScroll"
    - "Agregação SQL avg() ignora NULLs naturalmente — score_analista nullable não polui média"
    - "withQueryString() na paginação preserva ?mes= entre páginas"
    - "Map promotor/neutro/detrator continua no shape para o Pie do Dashboard — labels visuais re-rotulados (D-09 sem refactor de shape)"
key_files:
  created:
    - .planning/phases/31-nps-mensal-automatizado/31-05-SUMMARY.md
  modified:
    - app/Http/Controllers/NpsController.php
    - app/Http/Controllers/DashboardController.php
    - app/Http/Controllers/PerformanceController.php
    - resources/js/Pages/Nps/Index.jsx
    - resources/js/Pages/Dashboard/Admin.jsx
    - resources/js/Pages/Companies/Show.jsx
decisions:
  - "D-09 — Opção A escolhida (mapeamento 5=promotor / 4=neutro / 1-3=detrator) em vez de simplificar para 'Média + contagem'. Justificativa: preserva o shape de payload já consumido pelo Pie do Dashboard sem refactor estrutural, e os 3 segments (Excelente/Bom/Ruim) continuam servindo de sinal visual rápido. KPI Cards mostram a média 1-5 explicitamente para deixar a nova escala óbvia."
  - "Série 12 meses do LineChart usa 12 iterações × 3 avg() queries (~36 queries) — trade-off consciente. Para o volume esperado (~150 empresas × 12 meses) é aceitável. Documentado no controller; se virar gargalo, agregar via 1 single query GROUP BY YYYY-MM."
  - "Labels visuais do LineChart usam formato `MMM/YY` em pt-BR via `locale('pt_BR')->isoFormat('MMM/YY')` (ex: 'jun./26') — alinhado com convenção de dashboards Phase 24 e ICs visuais consistentes."
  - "Audiência admin usa OR (month_reference no mês selecionado) OR (created_at no mês selecionado AND month_reference IS NULL) — surveys auto-geradas filtram por month_reference (semântica D-specifics), manuais caem no mês via created_at."
  - "PerformanceController + DashboardController buildRanking refatorados para a taxonomia nova: isMentor()=true → score_estrategista (substitui score_mentor); isMentor()=false → score_analista (substitui score_consultant). Escala 1-5 substitui 0-10 transparentemente, sem mudar as fórmulas de ranking."
  - "Companies/Show.jsx linhas 377+848 trocadas para score_empresa; cor adaptada à escala 1-5 (5=emerald, 4=ecf-yellow, 1-3=red) substituindo o thresh 9/7 anterior."
metrics:
  duration_minutes: 14
  tasks_completed: 4
  files_modified: 6
  files_created: 1
  commits: 4
  completed_at: "2026-06-10T22:35:00Z"
---

# Phase 31 Plan 05: Admin /nps + cleanup legacy (Summary)

**One-liner:** Entrega o admin section completo do REQ-31-07 (filtro mês + 3 cards de média + LineChart Recharts 12 meses + tabela paginada com origem Mensal/Manual), ajusta o widget NPS do Dashboard Admin para a escala 1-5 com rótulos Excelente/Bom/Ruim (D-09), e elimina toda a referência legacy `score_consultant/mentor/overall` em código de produção — fecha a Phase 31 e a deixa pronta para deploy agrupado.

## O que foi feito

4 tasks executadas, 6 arquivos modificados, 1 SUMMARY criado, 4 commits atômicos por escopo. Toda a suite Phase 31 (19/19) continua verde após cada commit. Build npm verde. Zero referência a colunas legacy em código de produção.

### Task 1 — `NpsController::index` reescrito

`app/Http/Controllers/NpsController.php`:

- **Filtro de mês** `?mes=YYYY-MM` (default = mês corrente). Inválido cai no mês atual.
- **Audiência:** surveys com `month_reference` no mês selecionado **OU** `month_reference IS NULL AND created_at` no mês (D-specifics — "Audiência admin"). Surveys auto-geradas (mensais) usam `month_reference`; manuais caem no mês via `created_at`.
- **3 cards** (`cards.estrategista|analista|empresa`): cada um com `media` (round 2 casas, 0 se sem respostas) + `total` (count de respostas não-NULL).
- **Série 12 meses** (`serie_12m`): array de 12 itens com `mes` (label pt-BR `MMM/YY`), `mes_iso` (YYYY-MM), e médias das 3 dimensões.
- **Lista paginada** (`surveys`): com chaves nova taxonomia `score_estrategista|score_analista|score_empresa`, badge `auto_generated`, `created_at` formatado, link para Dialog de copy.
- Mantém **scope por carteira** para não-admin via `$user->companies()->pluck('companies.id')`.
- `withQueryString()` na paginação preserva `?mes=` entre páginas.
- As outras 3 actions (`generate`, `respond`, `submitResponse`) **inalteradas** (Plan 31-02 fez o trabalho).

**Trade-off documentado no código:** 12 iterações × 3 `avg()` queries = ~36 queries por request. Aceitável no volume esperado (~150 empresas × 12 meses); se virar gargalo, agregar via 1 single query `GROUP BY DATE_FORMAT(month_reference, '%Y-%m')`.

### Task 2 — Cleanup legacy em Dashboard + Performance + Companies/Show

**`app/Http/Controllers/DashboardController.php`:**

- Linha 363 (`adminDashboard`): `avgNps` agora usa `score_empresa` (D-07 dimensão geral "A ECF está atendendo suas expectativas?").
- Linhas 394-398 (`npsDistribution`): mapeamento **D-09** — `score_empresa==5` → promotor, `==4` → neutro, `1-3` → detrator. Shape `{promotores, neutros, detratores}` **preservado** porque o Pie de `Dashboard/Admin.jsx` ainda consome esse formato (labels re-rotulados no JSX).
- Linha 605 (`buildRanking`): `$scoreField = $u->isMentor() ? 'score_estrategista' : 'score_analista'` substitui `score_mentor / score_consultant`.
- Linha 727 (`userDashboard`): mesmo refactor de `buildRanking`.

**`app/Http/Controllers/PerformanceController.php`:**

- Linhas 58-59 (`ranking` map): mesma taxonomia nova.
- Linha 264 (`performance per-company`): mesma taxonomia nova.

**`resources/js/Pages/Companies/Show.jsx`:**

- Linha 377 (`avgNps` da ficha): consome `score_empresa` (substitui `score_overall`).
- Linha 848 (NpsScore na lista de NPS respondidos): consome `score_empresa` e adapta thresholds de cor à escala 1-5 (5=emerald, 4=ecf-yellow, 1-3=red).

### Task 3 — `Nps/Index.jsx` reescrito (UI admin completa)

`resources/js/Pages/Nps/Index.jsx` reescrito do zero preservando a estrutura externa (AppLayout + 2 Dialogs):

- **Header:** Select shadcn de mês (12 opções da série), contador de pesquisas e CTA "Gerar Link NPS Manualmente" (REQ-31-08 preservado).
- **3 CardMedia local:** Estrategista (`ecf-yellow`/Briefcase), Analista (`emerald`/Users), Empresa (`blue`/Building2). Cada card mostra média `X.YY/5` + contagem ou `—` em estado vazio. Tokens `card-ecf rounded-2xl` consistentes com Phase 18/24.
- **LineChart Recharts:** 12 meses × 3 séries (Estrategista #ffe600, Analista #19e06a, Empresa #60a5fa), YAxis fixo em [1,5] com ticks 1-5, CartesianGrid sutil, empty state se série toda zerada.
- **Tabela paginada:** Empresa | Origem (Badge `auto_generated ? 'Mensal' : 'Manual'`) | Respondente | 3 notas com `NpsScore` colorido por valor (gradiente vermelho→emerald escala 1-5) | Comentário (truncate + tooltip) | Data | Status | Link.
- **Paginação:** preserva `?mes=` via `router.get(route('nps.index'), { mes: mes_filtro, page: ... })`.
- **Dialog "Gerar Link NPS Manualmente":** mesmo fluxo do Plan 31-02 — Select de empresa + form POST `nps.generate`. Dialog do link gerado com copy-to-clipboard preservado.

### Task 4 — `Dashboard/Admin.jsx` ajuste do widget NPS

`resources/js/Pages/Dashboard/Admin.jsx`:

- **`npsData` re-rotulado** (linhas 141-149): segmentos do Pie agora são `Excelente (5)` / `Bom (4)` / `Ruim (1-3)` (D-09). Shape `{promotores, neutros, detratores}` no payload **mantido** porque o backend já mapeia transparente — só os labels visuais mudaram.
- **KpiCard TV mode + standard:** sub agora exibe `Média X.YY/5` em vez de `Score: NN`, refletindo a nova escala 1-5. O `npsScore` (fórmula clássica `(excelente - ruim) / total * 100`) continua sendo exibido ao lado do Pie como sinal rápido de saúde geral.

## Arquivos afetados

### Criados
- `.planning/phases/31-nps-mensal-automatizado/31-05-SUMMARY.md`

### Modificados
- `app/Http/Controllers/NpsController.php` — `index()` reescrito
- `app/Http/Controllers/DashboardController.php` — 4 sites cleanup
- `app/Http/Controllers/PerformanceController.php` — 2 sites cleanup
- `resources/js/Pages/Nps/Index.jsx` — reescrito (UI admin completa)
- `resources/js/Pages/Dashboard/Admin.jsx` — Pie labels + KpiCard sub
- `resources/js/Pages/Companies/Show.jsx` — 2 sites cleanup

## Commits

| Hash      | Mensagem                                                                                       |
| --------- | ---------------------------------------------------------------------------------------------- |
| `e1fba19` | `feat(31-05): reescreve NpsController::index com filtro mes + cards + serie 12m`               |
| `9de3b25` | `feat(31-05): cleanup legacy score_* em Dashboard, Performance e Companies/Show`               |
| `4a8d258` | `feat(31-05): reescreve Nps/Index.jsx com filtro mes + cards + LineChart 12m`                  |
| `d33019f` | `feat(31-05): ajusta widget NPS no Dashboard Admin para escala 1-5`                            |

## Sites onde havia refs legacy + onde estão agora

| Site original (legacy)                                                  | Estado final (Plan 31-05)                                                          |
| ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `NpsController::index` linhas 54-56 (`score_consultant/mentor/overall`) | Action reescrita; payload agora usa `score_estrategista/analista/empresa`         |
| `DashboardController::adminDashboard` linha 363 (`score_overall`)       | Substituído por `score_empresa` (D-07 dimensão geral)                              |
| `DashboardController::adminDashboard` linhas 395-397 (`score_overall`)  | Mapeamento D-09: `score_empresa` 5/4/1-3 → promotor/neutro/detrator                |
| `DashboardController::buildRanking` linha 605 (`score_mentor/consultant`) | `score_estrategista` (Estrategista) / `score_analista` (Analista)                  |
| `DashboardController::userDashboard` linha 727 (`score_mentor/consultant`) | Mesma taxonomia nova                                                              |
| `PerformanceController::index` linhas 58-59 (`score_mentor/consultant`) | `score_estrategista` / `score_analista`                                            |
| `PerformanceController` linha 264 (`score_mentor/consultant`)           | `score_estrategista` / `score_analista`                                            |
| `CompanyController::show` linhas 309-314 (chaves legacy no payload)     | **Corrigido inline no Plan 31-04** — payload já entrega `score_empresa/analista/estrategista` |
| `Companies/Show.jsx` linha 377 (`score_overall`)                        | Substituído por `score_empresa`                                                    |
| `Companies/Show.jsx` linha 848 (`score_overall`)                        | Substituído por `score_empresa` + thresholds de cor adaptados a 1-5                |
| `Nps/Index.jsx` linhas 82-84 (`score_consultant/mentor/overall`)        | UI reescrita do zero — usa `score_estrategista/analista/empresa`                   |
| `Nps/Respond.jsx` (escala 0-10)                                         | **Corrigido no Plan 31-03** — sliders 1-5 + 3 dimensões                            |

## Verificações finais

**Backend (PHP):**
```bash
grep -rn "score_consultant\|score_mentor\|score_overall" app/Http
# Saída: 3 hits — TODOS em comentários (CompanyController.php:312 docstring,
# PerformanceController.php:59-60 comentário explicativo). ZERO em código executável.
```

**Frontend (JSX):**
```bash
grep -rn "score_consultant\|score_mentor\|score_overall" resources/js/Pages
# Saída: 1 hit — Companies/Show.jsx:850 (comentário documentando a migração).
# ZERO em código executável.
```

**Testes:**
```
Tests: 19 passed (110 assertions)
Duration: 11.14s
```
Suite Phase 31 completa verde após cada commit. Nenhuma regressão.

**Build:**
```
✓ built in 14.48s
```
`npm run build` verde.

**Smoke de schema (tinker):**
```
NPS_RESPONSES_COLS=comment,created_at,id,respondent_name,
                   score_analista,score_empresa,score_estrategista,
                   survey_id,updated_at
```
Schema confirmado: as 3 colunas novas (`score_estrategista`, `score_analista`, `score_empresa`) estão presentes em prod local; nenhuma referência a colunas dropadas.

## Deviations from Plan

### Auto-fixed issues

**1. [Rule 2 - Critical functionality] PerformanceController.php linhas 58-59 e 264 entraram no escopo do Plan 31-05**

- **Found during:** Task 2 (grep inicial de `score_consultant/mentor/overall` em `app/`)
- **Issue:** O Plan listava `PerformanceController` no prompt do usuário mas a `<tasks>` originais focavam só em `DashboardController`. Sem ajustar `PerformanceController`, a página `/performance` retornaria SQL error em produção após o deploy agrupado (mesmo problema que motivou o Plan 31-05).
- **Fix:** Refactor de 2 sites com taxonomia nova: `isMentor() → score_estrategista`, else `score_analista`. Mesmo padrão idiomático do `DashboardController::buildRanking`.
- **Files modified:** `app/Http/Controllers/PerformanceController.php`
- **Commit:** `9de3b25`

**Nenhuma deviation arquitetural — Rule 4 não disparou.** Os ajustes foram mecânicos (substituição de chaves) e a UI Nps/Index.jsx seguiu o blueprint do Plan a risca.

### Out-of-scope deferrals (logged here, not fixed)

Nenhum. Todo o escopo do prompt foi coberto:
- `NpsController::index` ✓
- `Nps/Index.jsx` ✓ (filtro + cards + LineChart + lista)
- `DashboardController` (4 sites) ✓
- `Dashboard/Admin.jsx` widget ✓
- `PerformanceController` (2 sites) ✓
- `Companies/Show.jsx` (linhas 377 + 848) ✓
- Grep final 0 hits em produção ✓

## Threat Flags

Nenhuma. Mudanças são exclusivamente refactor de chaves + reescrita de UI consumindo payload já validado. Nenhuma nova superfície de rede, auth boundary, file access ou schema change. O endpoint `/nps` mantém o middleware `EnsureUserHasRole` e `auth:sanctum` já configurados (sem mudança); o filtro `?mes=` é regex-validated server-side (`/^\d{4}-\d{2}$/`) para impedir SQL injection ou path traversal.

## Gotchas / Próximos passos

### Para o usuário — pronto para deploy agrupado

A Phase 31 está **deploy-ready**. Antes de subir, recomenda-se:

1. **Deploy agrupado obrigatório** (Plans 31-01..31-05 juntos):
   - Plan 31-01 dropa+recria `nps_responses` e adiciona colunas em `nps_surveys` / `companies` — sem ele em prod, nada funciona.
   - Plan 31-02 traz o comando + schedule + Mailable — sem ele, o NPS mensal não dispara.
   - Plan 31-03 traz o form público 1-5 — sem ele, cliente vê a UI antiga (0-10) e o controller rejeita.
   - Plan 31-04 traz o input de `email_cliente` — sem ele, operador não consegue preencher o destinatário.
   - Plan 31-05 (este) reescreve `/nps` admin + ajusta Dashboard + Performance — sem ele, prod retorna `Unknown column` SQL error em `/dashboard`, `/performance`, `/companies/{id}` e `/nps`.

2. **Após deploy:**
   - Rodar `php artisan migrate --force` no VPS (aplica as 4 migrations da Phase 31).
   - Rodar `php artisan cache:clear` no VPS (limpa cache de schema/config).
   - Rodar `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
   - **Preencher `email_cliente`** das empresas ativas que devem entrar no fluxo mensal (via `/companies/{id}` ou `/comercial/empresas`) — sem isso o `nps:disparar-mensal` pula silenciosamente (D-04). É a tarefa operacional obrigatória pós-deploy.
   - Confirmar via `php artisan schedule:list` que `nps:disparar-mensal` está agendado para `0 9 * * *` (BRT).
   - No primeiro dia útil pós-deploy às 09:00 BRT, conferir `storage/logs/laravel.log` por mensagens `[NPS Mensal]` e validar que as empresas elegíveis receberam o email.

3. **Smoke recomendado em prod (admin):**
   - `/dashboard` → widget NPS renderiza sem console error; Pie mostra 3 segmentos com labels Excelente/Bom/Ruim.
   - `/nps` → filtro de mês com 12 opções; 3 cards (zero respostas até o primeiro disparo); LineChart com 12 ticks; tabela vazia ou com surveys de teste manuais; botão "Gerar Link NPS Manualmente" funcional.
   - `/companies/{id}` → ficha 360 renderiza sem erro; seção "NPS Respondidos" funciona se houver respostas.
   - `/performance` → ranking renderiza médias na escala 1-5 (sem mudança visual exceto magnitude do número).

## Self-Check: PASSED

- ✓ `app/Http/Controllers/NpsController.php` MODIFIED (`index()` reescrito)
- ✓ `app/Http/Controllers/DashboardController.php` MODIFIED (4 sites)
- ✓ `app/Http/Controllers/PerformanceController.php` MODIFIED (2 sites)
- ✓ `resources/js/Pages/Nps/Index.jsx` MODIFIED (reescrito do zero)
- ✓ `resources/js/Pages/Dashboard/Admin.jsx` MODIFIED (labels + KpiCard sub)
- ✓ `resources/js/Pages/Companies/Show.jsx` MODIFIED (2 sites)
- ✓ Commits `e1fba19`, `9de3b25`, `4a8d258`, `d33019f` existem em `git log`
- ✓ `grep "score_consultant|score_mentor|score_overall" app/Http resources/js/Pages` → ZERO hits em código executável (apenas 4 hits em comentários documentando a migração)
- ✓ Suite Phase 31 completa: **19/19 testes verdes**
- ✓ `npm run build` verde
- ✓ Schema `nps_responses` confirma colunas novas via tinker
- ✓ Routes `nps.index/generate/respond/submit` registradas
