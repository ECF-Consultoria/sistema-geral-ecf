---
phase: 92-ui-de-desempenho-ranking-metadados-v17-0
plan: 02
subsystem: ui (desempenho — ranking, drill-down, self-view da carteira)
tags: [desempenho, react, inertia, ecf-tokens, score-status]

requires:
  - phase: 92-01
    provides: "Passthrough dos 6 metadados de elegibilidade (empresas_unicas, vinculos_servico, vinculos_financeiros, vinculos_sem_fonte_financeira, score_status, componentes_disponiveis) no payload do ranking `/performance`; filtro ?contexto= view-only; correção da distorção A/B no comparacaoContextual (blocked não vira 0.0, comparacao_contextual=null na self-view do blocked)"
provides:
  - "Badge de status da nota (Aguarda régua Shopee/Parcial/Oficial) com labels travados no ranking, no drill-down individual e implícito na self-view do blocked"
  - "Metadados de elegibilidade visíveis via tooltip (ranking) e bloco discreto (drill-down)"
  - "Select de contexto (Todos/Mercado Livre/Shopee) view-only, filtro client-side sobre o payload já autorizado — nunca recalcula nota"
  - "Self-view do profissional blocked mostra explicação em vez de comparação de pares distorcida/vazia"
affects:
  - "Qualquer UI futura de Desempenho/NPS que precise reexibir score_status ou os metadados de elegibilidade (padrão de labels já estabelecido aqui)"

tech-stack:
  added: []
  patterns:
    - "SCORE_STATUS_LABEL/BADGE_CLS/TOOLTIP duplicado em Performance/Index.jsx e Performance/Show.jsx (sem módulo compartilhado — só 2 usos, mesmo padrão já usado pra CONTEXTO_OPTIONS em Carteiras.jsx/AdminCarteira.jsx)"
    - "Filtro de contexto client-side via useMemo (não round-trip ao backend) — decisão herdada do key-decision do 92-01-SUMMARY.md"

key-files:
  created: []
  modified:
    - resources/js/Pages/Performance/Index.jsx
    - resources/js/Pages/Performance/Show.jsx
    - resources/js/Pages/Portfolio/Show.jsx

key-decisions:
  - "Nota+badge: quando score_status !== 'official', o badge de status substitui a linha 'conta que gerou a nota' na célula Nota do ranking (em vez de empilhar os dois) — mantém a densidade visual da coluna de 6rem"
  - "Metadados do ranking (SC2) expostos via tooltip nativo na célula Empresas (não nova coluna) — grid de 10 colunas já apertado, conforme a research"
  - "Select de contexto é local state (useState) client-side, não sincronizado com a URL — reflete a decisão do 92-01 de que a filtragem visual fica inteiramente no frontend, sem round-trip nem recalcular nota"
  - "Guard defensivo extra em Portfolio/Show.jsx: a condição do card de comparação agora também exige score_status !== 'blocked' (além de comparacao_contextual && performance_profissional) — nunca renderiza os dois cards juntos mesmo que o backend mude de comportamento no futuro"

patterns-established: []

requirements-completed: [DESEMP-08]

duration: ~30min
completed: 2026-07-17
---

# Phase 92 Plan 02: Frontend — badges de status, metadados e self-view do blocked Summary

**Ranking `/performance`, drill-down `/performance/{id}` e self-view `/portfolio/{id}` passam a exibir o status da nota (labels travados, nunca slug cru), os metadados de elegibilidade e um select de auditoria por contexto — tudo consumindo o payload que o Plan 92-01 já entrega, sem nenhuma chamada nova ao backend.**

## Performance

- **Duration:** ~30 min
- **Tasks:** 2/2 completas (Task 3 é checkpoint visual — não executada, ver seção abaixo)
- **Files modified:** 3

## Accomplishments

- `Performance/Index.jsx`: badge de status na célula Nota do ranking (blocked → "Aguarda régua Shopee", partial → "Parcial", official sem badge), tooltip com os 3 metadados na célula Empresas, select Todos/Mercado Livre/Shopee na toolbar filtrando `rankingFiltrado` client-side via `useMemo` (nunca recalcula `nota_final`; o drawer de evolução continua usando o grupo elegível inteiro, não o filtrado).
- `Performance/Show.jsx`: `FaixaBonusCard` ganha o badge de status ao lado da nota final, a explicação pt-BR (blocked/partial) complementando o texto "Sem dados suficientes...", e um bloco discreto com os 4 metadados de elegibilidade.
- `Portfolio/Show.jsx`: quando `performance_profissional.score_status === 'blocked'`, o card de comparação de pares é substituído por uma mensagem "Sua nota ainda não é oficial — aguarda régua de bônus da Shopee" (o backend já garante `comparacao_contextual=null` nesse caso, evitando 0.0 fantasma).
- `npm run build` → exit 0 (dois builds, um por task).

## Task Commits

Each task was committed atomically:

1. **Task 1: Badge de status + metadados + select de contexto no ranking (Performance/Index.jsx)** - `3e6944c` (feat)
2. **Task 2: Badge de status + metadados no drill-down (Performance/Show.jsx) + self-view blocked (Portfolio/Show.jsx)** - `d287327` (feat)

_Nota: commit atômico por task, conforme diretriz do usuário. Não usados comandos `gsd-sdk state.*` nem tocado `STATE.md`/`ROADMAP.md`/`REQUIREMENTS.md` — sessão paralela ativa na Fase 96, conforme constraint explícita desta execução._

## Files Created/Modified

- `resources/js/Pages/Performance/Index.jsx` — `SCORE_STATUS_LABEL`/`SCORE_STATUS_BADGE_CLS`/`SCORE_STATUS_TOOLTIP` + `CONTEXTO_OPTIONS`; badge na célula Nota; tooltip de metadados na célula Empresas; select de contexto view-only na toolbar; `rankingElegivel`/`rankingFiltrado` separados (filtro de contexto não afeta o grupo do drawer de evolução).
- `resources/js/Pages/Performance/Show.jsx` — mesmos `SCORE_STATUS_*` (duplicados, sem módulo compartilhado) + `SCORE_STATUS_EXPLICACAO`; `FaixaBonusCard` com badge, explicação complementar e bloco de 4 metadados.
- `resources/js/Pages/Portfolio/Show.jsx` — import do ícone `Clock`; card de mensagem "Sua nota ainda não é oficial" condicionado a `performance_profissional?.score_status === 'blocked'`; guard defensivo adicional na condição do card de comparação normal.

## Decisions Made

Ver `key-decisions` no frontmatter acima.

## Deviations from Plan

None - plan executado como escrito. As duas opções de implementação do select de contexto oferecidas pelo plano (round-trip vs client-side) foram resolvidas a favor de client-side, conforme a decisão já registrada no `92-01-SUMMARY.md` (evita N+1 no backend). O guard defensivo extra em `Portfolio/Show.jsx` (linha do `comparacao_contextual`) é uma adição de robustez (Rule 2 — não deixa os dois cards coexistirem mesmo em cenário futuro inesperado), sem mudar comportamento observável hoje (o backend já entrega `comparacao_contextual=null` quando blocked).

## Issues Encountered

None.

## User Setup Required

None.

## Next Phase Readiness

Código pronto para o checkpoint visual humano (Task 3 do plano, não executada por este agente — é `type="checkpoint:human-verify"`). Roteiro de verificação sugerido (mesmo do PLAN.md):

1. `/performance` — profissional só-Shopee mostra badge "Aguarda régua Shopee" e nota "—"; tooltip da célula Empresas mostra os 3 metadados; select Todos/Mercado Livre/Shopee muda linhas exibidas sem mudar nenhuma nota.
2. `/performance/{profissional blocked}` — card mostra badge de status + explicação pt-BR + os 4 metadados.
3. `/portfolio/{profissional blocked}` (self-view) — mensagem "sua nota ainda não é oficial" no lugar da comparação de pares.
4. `/portfolio/{profissional official}` com pares — comparação normal, "vs N analistas" não conta o blocked (já validado por teste no 92-01).

Nenhum bloqueio conhecido. `npm run build` verde nos dois commits.

---
*Phase: 92-ui-de-desempenho-ranking-metadados-v17-0*
*Completed: 2026-07-17*
