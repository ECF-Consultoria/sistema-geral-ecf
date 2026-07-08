---
phase: 73-limpeza-legado-testes-e2e
milestone: v15.0
plan: 73-03
subsystem: frontend/nps
tags: [nps, frontend, cleanup, react, jsx, promotor-neutro-detrator, score-legacy, phase73]
completed_date: 2026-07-08
duration_minutes: 12
tasks_completed: 3
tasks_total: 3
files_created: []
files_modified:
  - resources/js/Pages/Performance/Dashboard.jsx
  - resources/js/Pages/Companies/Show.jsx
requirements_completed: [NPS-F-01, NPS-F-02]
dependency_graph:
  requires:
    - 73-01 (backend rename `classe` → nota float + `promotores/neutros/detratores` → `positivas/negativas` no PerformanceController + DashboardController)
  provides:
    - "Frontend limpo de Promotor/Neutro/Detrator e score_overall/consultant/mentor (SC#1 + SC#2 do ROADMAP fechados)"
  affects:
    - resources/js/Pages/Dashboard/Admin.jsx (consumidor de nps_distribution — hoje quebrado; ver Deferred Issues)
tech_stack:
  added: []
  patterns:
    - "Cor por threshold direto (>=4 verde / <=3 vermelho / null cinza) — substitui classificação 3-buckets Promotor/Neutro/Detrator"
key_decisions:
  - "Simplificação visual: badge circular com nota + cor (sem pill textual de rótulo) — remove jargão NPS conforme regra sistêmica evitar jargão sem explicação"
  - "Format nota float 2 casas via Number(nota).toFixed(1) no badge — display compacto 1 casa suficiente pra 1-5 (evita '4.50')"
  - "Preservar comentário sobre mapeamento de cor da escala 1-5 no Companies/Show.jsx — informação ativa, não legado"
metrics:
  build_time_seconds: 16.26
  lines_added: 24
  lines_removed: 14
  net_delta: 10
---

# Phase 73 Plan 03: Frontend Cleanup Promotor/Neutro/Detrator + score_overall Summary

Limpeza frontend dos rastros legados NPS — Performance/Dashboard.jsx troca constantes LABEL_CLASSE/BADGE_CLASSE por helper `corPorNota(nota)` com threshold direto; Companies/Show.jsx remove comentário obsoleto sobre score_overall. Fecha SC#1 e SC#2 do ROADMAP Phase 73 (combinado com Plan 73-01 backend).

## Executive Overview

- **Objetivo:** Fechar SC#1 (grep Promotor|Neutro|Detrator → 0 em resources/js/) e SC#2 (grep score_overall|score_consultant|score_mentor → 0 em resources/js/)
- **Resultado:** Ambos os SCs atendidos em resources/js/ (case-sensitive strict grep). Build verde 16.26s.
- **Trade-off aceito:** Pill textual "Positivo/Neutro/Insatisfeito" removida da UI do widget NPS — a nota + cor no badge circular já comunica polaridade; alinhado com regra sistêmica "evitar jargão sem explicação em qualquer UI".

## Task Breakdown

### T1 — Performance/Dashboard.jsx: helper corPorNota + remoção Promotor/Neutro/Detrator

**Ação executada:**
1. **Removidas** as constantes `npsRotulo` e `npsClasse` (linhas 51-64 anteriores) que mapeavam chaves `Promotor/Neutro/Detrator` → rótulos e classes CSS.
2. **Adicionado** helper `corPorNota(nota)` (linhas 51-60): retorna cinza pra null, emerald pra nota >= 4, rose pra nota <= 3.
3. **Substituído** bloco JSX (linhas ~355-369 pós-edit) que usava `r.classe === 'Promotor'|'Neutro'|'Detrator' && '...'` por `cn('...', corPorNota(r.nota))`.
4. **Removido** o pill textual trailing `<span>{npsRotulo[r.classe] ?? r.classe}</span>` — badge circular com nota + cor comunica polaridade sem jargão.
5. **Format numérica**: `{r.nota != null ? Number(r.nota).toFixed(1) : '—'}` — payload agora envia float 2 casas (Plan 73-01), display compacto 1 casa cabe no badge 36px.

**Verificação:**
- `grep -n "Promotor\|Neutro\|Detrator" resources/js/Pages/Performance/Dashboard.jsx` = 0 (case-sensitive)
- `grep -n "corPorNota" resources/js/Pages/Performance/Dashboard.jsx` = 2 hits (definição + uso)
- `grep -n "r\.classe" resources/js/Pages/Performance/Dashboard.jsx` = 0
- Cores emerald/rose ainda usadas em thresholds — grep count >= 2 ✓

### T2 — Companies/Show.jsx: remove comentário obsoleto score_overall

**Ação executada:**
1. **Substituído** o comentário na linha 1001: `// Phase 31 (Plan 05) — score_empresa (1-5) substitui score_overall (0-10).`
2. **Novo comentário**: `// Phase 73 Plan 03 (SC#2) — comentário legado removido. Mapeamento de cor da escala 1-5: 5=emerald, 4=ecf-yellow, 1-3=red.` — preserva info ativa sobre mapeamento sem referência à escala legacy 0-10.
3. **Não alterado** código ativo (`score_empresa`, `scoreColor`, `s.response?.score_empresa`).

**Verificação:**
- `grep -n "score_overall\|score_consultant\|score_mentor" resources/js/Pages/Companies/Show.jsx` = 0

**Nota de delta:** o plan estimou `min_lines_delta: 5` mas a mudança mínima cirúrgica (1 comentário substituído por 2 linhas) gerou delta 4 (2 add + 2 del). SC#2 satisfeito 100% via zero occurrences — o desvio de estimativa é técnico, não funcional.

### T3 — Build + verificação final SC#1 + SC#2

**Ação executada:**
1. `npm run build` — verde em 16.26s
2. `grep Promotor|Neutro|Detrator resources/js/` = 0 (case-sensitive strict)
3. `grep score_overall|score_consultant|score_mentor resources/js/` = 0

**Suite baseline PHP:** não executada nesta task (escopo puramente frontend; Plan 73-01 já validou baseline após rename do payload backend).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Comentário na linha 51 mantinha "Promotor/Neutro/Detrator" no texto do helper corPorNota**
- **Found during:** T1 verificação pós-edit
- **Issue:** primeira revisão do comentário do helper mantinha as três palavras exatas ("herança NPS 0-10 clássico" + "Promotor/Neutro/Detrator"), o que fazia o grep case-sensitive SC#1 retornar 1 hit em vez de 0
- **Fix:** reescrito comentário como "sem classificação legada de 3 buckets" — preserva contexto histórico sem repetir as palavras vetadas
- **Files modified:** resources/js/Pages/Performance/Dashboard.jsx (linhas 51-54)
- **Rationale:** o contrato do usuário exige zero hits estritos; comentários também contam

### Deferred Issues

**1. Dashboard/Admin.jsx consome `nps_distribution.promotores/neutros/detratores` — BROKEN após Plan 73-01**

- **Escopo do plan 73-03 (do usuário):** "Zero mudança em app/ ou outros JSX" — Dashboard/Admin.jsx foi explicitamente excluído
- **Estado atual:** Plan 73-01 renomeou payload backend de `promotores/neutros/detratores` → `positivas/negativas`. Admin.jsx (linhas 101, 148, 150, 154-160) ainda referencia os nomes antigos usando lowercase (`promotores`, `neutros`, `detratores`) — não fere o SC#1 grep (case-sensitive), mas o widget NPS do Admin Dashboard renderizará com zeros/NaN até ser atualizado.
- **Ocorrências identificadas** (case-insensitive):
  - `resources/js/Pages/Dashboard/Admin.jsx:101` — default `nps_distribution = { promotores: 0, neutros: 0, detratores: 0 }`
  - `resources/js/Pages/Dashboard/Admin.jsx:148,150` — cálculo de `npsTotal`/`npsScore`
  - `resources/js/Pages/Dashboard/Admin.jsx:154-160` — comentário + Pie data (`Excelente 5` / `Bom 4` / `Ruim 1-3`)
- **Ação recomendada:** plan follow-up (v15.0 Phase 73 wave 3 ou v14.0 hotfix) para migrar Admin.jsx de `{promotores, neutros, detratores}` → `{positivas, negativas}` — provavelmente colapsando Pie de 3 fatias pra 2 (positiva verde / negativa vermelha), mantendo o npsScore em `(positivas - negativas) / total * 100`.

**2. Dashboard/User.jsx — a verificar**
- Plan 73-03 lista User.jsx no `must_haves.truths` mas escopo do usuário exclui. Não foi inspecionado nesta execução; recomenda-se verificar durante o follow-up de Admin.jsx.

## Known Stubs

Nenhum. Nenhuma prop hardcoded, nenhum placeholder novo introduzido.

## Threat Flags

Nenhum. Mudanças são de UI/render puro — sem novos endpoints, sem auth paths, sem file access, sem schema.

## Commits

- `4c7888f` (HEAD anterior — Plan 73-01/03 backend do outro dev)
- **[será registrado no commit desta task]** — feat(73-03): frontend cleanup Performance/Dashboard.jsx cor por threshold + Companies/Show.jsx remove refs obsoletas

## Verification Contract

| Critério                                                                     | Alvo       | Real  | Status |
|------------------------------------------------------------------------------|------------|-------|--------|
| `grep Promotor\|Neutro\|Detrator resources/js/` (case-sensitive)             | 0          | 0     | PASS   |
| `grep score_overall\|score_consultant\|score_mentor resources/js/`           | 0          | 0     | PASS   |
| `npm run build`                                                              | verde      | verde | PASS   |
| Zero mudança em `app/`                                                       | 0 arquivos | 0     | PASS   |
| Zero mudança em outros JSX (fora dos 2 do escopo)                            | 0 arquivos | 0     | PASS   |
| Presença helper `corPorNota` em Performance/Dashboard.jsx                    | >= 1       | 2     | PASS   |
| Ambas cores emerald + rose ainda usadas para threshold                       | >= 2       | 2     | PASS   |

## Self-Check: PASSED

- [x] resources/js/Pages/Performance/Dashboard.jsx — modificado, corPorNota helper presente, r.classe/npsRotulo/npsClasse removidos
- [x] resources/js/Pages/Companies/Show.jsx — modificado, comentário score_overall removido, código ativo preservado
- [x] Build passa
- [x] Grep contracts SC#1 + SC#2 = 0 em resources/js/
- [x] Zero mudança fora do escopo (app/, outros JSX)
- [x] Deferred issue documentado (Dashboard/Admin.jsx)
