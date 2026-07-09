---
phase: 74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o
plan: 06
subsystem: Frontend Performance (Ranking · Dashboard Carteira · View Individual)
tags: [frontend, react, jsx, ranking, ui, dark-glass]
requires:
  - .planning/phases/74-.../74-04-SUMMARY.md (Wave 3 — big bang v2 backend)
provides:
  - Performance/Dashboard.jsx v2 (4 cards + faixa + toggle)
  - Performance/Index.jsx v2 (ranking sem_carteira filtrado + colunas novas)
  - Performance/Show.jsx v2 (view individual com toggle mês)
affects:
  - resources/js/Pages/Performance/Dashboard.jsx (reescrito)
  - resources/js/Pages/Performance/Index.jsx (seção Consultoria reescrita; Polos preservado)
  - resources/js/Pages/Performance/Show.jsx (reescrito)
  - app/Http/Controllers/PerformanceController.php (show() adaptado — Rule 2)
tech-stack:
  patterns:
    - dark/glass tokens (bg-ecf-card, border-white/[0.08], text-ecf-yellow)
    - cn() utility (clsx + tailwind-merge)
    - lucide-react para iconografia
key-files:
  modified:
    - resources/js/Pages/Performance/Dashboard.jsx
    - resources/js/Pages/Performance/Index.jsx
    - resources/js/Pages/Performance/Show.jsx
    - app/Http/Controllers/PerformanceController.php
decisions:
  - D-18 · Dashboard abre em VIEW MENSAL (fallback para mes_atual se resultado_mes_fechado ausente)
  - D-19 · Show mostra por default o mês fechado mais recente com toggle
  - D-20 · Index (ranking) do mês fechado mais recente + transparência de excluídos
  - Rule 2 · PerformanceController::show() adaptado ao shape v2 (não estava no Wave 3)
metrics:
  duration_min: 45
  completed: 2026-07-09
  tasks_completed: 3-of-3-jsx (build deferido para final da Wave 4)
requirements:
  - DESEMP-04 (UI Faturamento)
  - DESEMP-05 (UI Margem)
  - DESEMP-06 (UI Absenteísmo — Em breve)
  - DESEMP-10 (UI sem_carteira filter + transparência)
  - DESEMP-14 (UI filtro >= 2026-08-01 via mes_fechado do backend)
---

# Phase 74 Plan 06: Frontend Performance — Simplificação para 4 Parâmetros

**One-liner:** UI Performance reescrita para 4 parâmetros (NPS/Faturamento/Margem/Absenteísmo) + faixa de bônus, filtro `sem_carteira` no ranking e placeholder "Em breve" no Absenteísmo — consumindo diretamente o shape v2 do DesempenhoScoreService.

## Objetivo

Reescrever os 3 componentes React do módulo Performance (Dashboard.jsx, Index.jsx, Show.jsx) para consumir o novo shape de props do `DesempenhoScoreService`. Fechar os REQs DESEMP-04, DESEMP-05, DESEMP-06, DESEMP-10 e DESEMP-14 no lado UI.

## Arquivos entregues

### `resources/js/Pages/Performance/Dashboard.jsx` (reescrito · 392 linhas)
- **4 cards de parâmetros:** NPS médio, Faturamento (%vs mês anterior), Margem (%vs mês anterior), Absenteísmo (placeholder "—" + badge amarelo "Em breve")
- **Card destaque Faixa de Bônus** full-width (col-span-4 em xl) com nota_final + badge "Promovida (2 meses consecutivos)" quando aplicável
- **Toggle de view** (mes_fechado / mes_atual / diario) com fallback gracioso: só mostra opções cujos dados o backend forneceu
- **Bloco sem_carteira** amarelo com motivo pt-BR
- **Info carteira** com empresas_com_baseline / empresas_carteira
- **Link "Como calculamos?"** para `/manual/desempenho-bonificacao`
- **NpsPendingWidget** preservado (Phase 72 Plan 03)
- **Lista de empresas em carteira** legacy preservada como visão operacional

### `resources/js/Pages/Performance/Index.jsx` (Consultoria reescrita · Polos intacto · 322 add / 196 del)
- **Filtro DESEMP-10:** `ranking.filter(r => !r.sem_carteira)` no ranking exibido
- **Bloco transparência "Excluídos do ranking · sem carteira no período"** ao pé com chips dos usuários filtrados
- **Título dinâmico:** `Ranking Performance — {mesExtenso(mes_fechado)}` com subtítulo indicando fechado vs parcial
- **Colunas novas:** Posição · Nome+cargo · Nota final (2 casas) · Faixa (badge colorido) · Δ mês · NPS · Var Fat · Var Margem · Empresas (com baseline / total)
- **Badge "PROMOVIDA"** pequeno quando `faixa_promovida=true`
- **Filtro cargo** Geral/Analistas/Estrategistas preservado
- **Bloco colapsável "Como calculamos?"** com fórmula pt-BR + links `/desempenho/configuracao` e `/manual/desempenho-bonificacao`
- **EvolucaoDrawer** adaptado para exibir `nota_final` (0-5) em vez de `score` (0-100)
- **PolosDashboard** e todas as suas dependências (linhas 527-1398) preservados intactos

### `resources/js/Pages/Performance/Show.jsx` (reescrito · 358 add / 228 del)
- **Header:** Nome + chip cargo_label + mês selecionado
- **Toggle de mês** via `<select>` (query param `?mes=YYYY-MM-01`) listando `meses_disponiveis`
- **4 cards de parâmetros + card destaque Faixa** (mesma UX do Dashboard.jsx)
- **Bloco sem_carteira** amarelo grande "Sem carteira em {mês}"
- **Bloco explicativo permanente** com link para o Manual

### `app/Http/Controllers/PerformanceController.php::show()` (Rule 2 deviation)
- Substituído inteiramente: shape antigo (`profile_user`, `companies`, `summary`, `period`) ➜ novo (`user`, `resultado`, `mes_selecionado`, `mes_fechado`, `meses_disponiveis`)
- **Meses disponíveis** filtrados por `mes_referencia >= 2026-08-01` (DESEMP-14)
- **Fonte de resultado:** snapshot mensal se disponível, fallback ao `scoreService->compute()` on-the-fly
- **Cargo canônico** via `user_setores → cargos` (padrão do projeto)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Funcionalidade crítica ausente] Adaptação de `PerformanceController::show()`**
- **Encontrado durante:** Task 3 (Show.jsx)
- **Issue:** Wave 3 (Plan 74-04) refatorou apenas `index()` e `dashboardCarteira()`, mas não `show()`. O Show.jsx do Plan 74-06 espera shape `{user, resultado, mes_fechado, meses_disponiveis}`, incompatível com o shape antigo `{profile_user, companies, summary, period}`.
- **Fix:** Substituído todo o corpo de `show()` para consumir `DesempenhoScoreService` (com fallback para snapshot mensal quando disponível), enviar meses fechados >= 2026-08-01 e cargo canônico via `user_setores → cargos`.
- **Files modified:** `app/Http/Controllers/PerformanceController.php`
- **Commit:** `6c49160`

## Commits gerados

| Task | Commit  | Descrição |
|------|---------|-----------|
| 1    | 7b5cf38 | feat(74-06): reescreve Performance/Dashboard.jsx com shape v2 |
| 2    | 8a0fd84 | feat(74-06): reescreve Performance/Index.jsx (ranking) com shape v2 |
| 3    | 6c49160 | feat(74-06): reescreve Performance/Show.jsx (view individual) + adapta show() do controller |

## Verificações

- Grep de tokens obrigatórios: **OK** (`faixa_bonus`, `faixa_promovida`, `sem_carteira`, `Em breve`, `var_faturamento_pct`, `var_margem_pct`, `nps_medio`, `nota_final`, `delta_vs_mes_passado`, `/desempenho/configuracao`, `/manual/desempenho-bonificacao`)
- Grep de métricas v1 ativas em código: **OK** (só menção em comentário)
- `php -l PerformanceController.php`: **No syntax errors**
- Build do frontend: **DEFERIDO** para o final da Wave 4 (executado uma vez após os 3 plans conforme instrução do orquestrador)

## Requisitos endereçados

- **DESEMP-04** (UI Faturamento) — card com % variação + tendência
- **DESEMP-05** (UI Margem) — card com % variação + label "fonte Adman canônica"
- **DESEMP-06** (UI Absenteísmo) — placeholder "—" + badge "Em breve"
- **DESEMP-10** (UI sem_carteira) — filtro no ranking + bloco transparência + badge grande no Show
- **DESEMP-14** (UI filtro >= 2026-08-01) — via prop `mes_fechado`/`meses_disponiveis` do backend

## Self-Check: PASSED

- FOUND: `resources/js/Pages/Performance/Dashboard.jsx`
- FOUND: `resources/js/Pages/Performance/Index.jsx`
- FOUND: `resources/js/Pages/Performance/Show.jsx`
- FOUND: `app/Http/Controllers/PerformanceController.php` (mod)
- FOUND: commit 7b5cf38 (Dashboard.jsx)
- FOUND: commit 8a0fd84 (Index.jsx)
- FOUND: commit 6c49160 (Show.jsx + controller)
