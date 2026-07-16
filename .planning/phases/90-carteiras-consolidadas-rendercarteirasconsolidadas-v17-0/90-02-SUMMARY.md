---
phase: 90-carteiras-consolidadas-rendercarteirasconsolidadas-v17-0
plan: 02
subsystem: ui
tags: [react, inertia, laravel, carteira, filtro-contexto, tdd]

# Dependency graph
requires:
  - phase: 90-01
    provides: "renderCarteirasConsolidadas()/renderCarteiraProfissional() com contexto/totais/contadores prontos + alias companies_count temporario"
provides:
  - "Select de contexto (Todos/Mercado Livre/Shopee) funcional nas DUAS telas de carteira (consolidada + individual)"
  - "Contadores empresas_unicas x vinculos_servico por card + banner agregado no topo da consolidada"
  - "Chip ambar 'sem fonte financeira' no card da consolidada (mesmo padrao visual da Fase 89)"
  - "Alias legado companies_count removido do payload (backend + frontend no mesmo commit)"
affects: [91-desempenho, 92-desempenho-ui]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Segundo <select> reaproveitando classes Tailwind do select existente, sem novo componente"
    - "router.get/router.visit sempre reenviando os DOIS parametros de filtro juntos (period+contexto ou mes+contexto) para nao perder estado entre trocas"
    - "Flag booleana usada dentro de .map() computada inline no JSX (nunca variavel de escopo do componente) — pitfall Rollup"

key-files:
  created: []
  modified:
    - resources/js/Pages/Portfolio/Carteiras.jsx
    - resources/js/Pages/Portfolio/AdminCarteira.jsx
    - app/Http/Controllers/PortfolioController.php
    - tests/Feature/V16/CarteirasConsolidadasContextoTest.php

key-decisions:
  - "CONTEXTO_OPTIONS duplicada em Carteiras.jsx e AdminCarteira.jsx (sem modulo compartilhado para so 2 usos) — mesma decisao ja tomada para SETOR_LABELS na Fase 89"
  - "Select de mes do AdminCarteira passa a reenviar ?contexto= junto e o select de contexto reenvia ?mes= junto — nenhum dos dois filtros pode 'resetar' o outro ao trocar"

patterns-established:
  - "Filtro duplo (contexto + outro eixo) sempre viaja junto na mesma chamada de router.get/visit — nunca dispara so o parametro que mudou"

requirements-completed: [CART-07]

# Metrics
duration: ~15min (Tasks 1-2; Task 3 aguardando checkpoint humano)
completed: 2026-07-16
---

# Phase 90 Plan 02: UI do filtro de contexto + contadores nas telas de Carteira Summary

**Select de contexto (Todos/Mercado Livre/Shopee) e contadores empresas×vínculos entregues nas duas telas de carteira (consolidada + individual), com o alias legado `companies_count` removido do payload no mesmo commit que a troca do `.jsx`.**

## Performance

- **Duration:** ~15min (Tasks 1-2 — código); Task 3 (checkpoint visual) aguardando aprovação humana
- **Started:** 2026-07-16T20:27:42Z
- **Tasks:** 2/3 (Task 3 é checkpoint humano — não executado por este agente, ver seção "Checkpoint pendente" abaixo)
- **Files modified:** 4 (3 código + 1 teste)

## Accomplishments

- **Carteiras.jsx (consolidada):** select de contexto ao lado do de período (dispara `router.get` reenviando os dois filtros juntos); banner agregado no topo com `totais.empresas_unicas`/`totais.vinculos_servico`; card troca `companies_count` por `empresas_unicas · vinculos_servico`; chip âmbar quando `vinculos_sem_fonte_financeira > 0`; empty state contextual quando o filtro ativo esconde todos os cards.
- **AdminCarteira.jsx (individual):** select de contexto no header, ao lado do select de mês existente — cada um preserva o valor do outro na URL ao trocar; subtítulo do header evolui para contadores `empresas_unicas`/`vinculos_servico`; chip âmbar de "sem fonte financeira"; docblock do topo atualizado (a nota "filtro completo é Fase 90" foi removida).
- **PortfolioController.php:** alias temporário `companies_count` removido dos dois pontos em `renderCarteirasConsolidadas()` — mudança feita no MESMO commit que a troca do `.jsx` (Pitfall 4, conforme decisão documentada no 90-01-SUMMARY).
- **Teste TDD:** `test_payload_consolidada_nao_emite_companies_count` adicionado à suite existente — RED confirmado antes da remoção do alias (1 falha, 10 passando), GREEN confirmado depois (11/11 passando).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1a (RED):** `7a4bd06` — `test(90-02): RED — payload consolidada nao deve emitir companies_count`
2. **Task 1b (GREEN):** `b40ac55` — `feat(90-02): Carteiras.jsx — select contexto + contadores por card + banner agregado` (backend + frontend juntos)
3. **Task 2:** `8400931` — `feat(90-02): AdminCarteira.jsx — select contexto no header + contadores no topo`

**Plan metadata:** ainda não commitado — orquestrador cuida do commit final de docs após aprovação do checkpoint (Task 3).

## Files Created/Modified

- `resources/js/Pages/Portfolio/Carteiras.jsx` — select de contexto, banner agregado, contadores por card, chip âmbar, empty state contextual
- `resources/js/Pages/Portfolio/AdminCarteira.jsx` — select de contexto no header (preservando `?mes=`), contadores + chip âmbar no subtítulo, docblock atualizado
- `app/Http/Controllers/PortfolioController.php` — remoção do alias `companies_count` (2 ocorrências em `renderCarteirasConsolidadas()`)
- `tests/Feature/V16/CarteirasConsolidadasContextoTest.php` — teste 11 (`test_payload_consolidada_nao_emite_companies_count`)

## Decisions Made

- Reaproveitar 100% das classes Tailwind do select de período/mês existentes para o novo select de contexto — zero componente novo, mantém consistência visual sem esforço extra.
- Contadores e totais são exibidos exatamente como o backend envia (nenhum recálculo no frontend) — reforça a decisão do 90-01 de que `totais` é união de `company_id`, não soma ingênua.

## Deviations from Plan

None — plano executado exatamente como escrito nas Tasks 1 e 2.

## Issues Encountered

None.

## Checkpoint pendente

**Task 3 (`checkpoint:human-verify`, gate `blocking`) NÃO foi executada por este agente**, conforme instrução explícita do orquestrador. O roteiro de verificação visual (telas `/portfolio` consolidada e `AdminCarteira` individual, com os 3 valores de contexto) está descrito no `90-02-PLAN.md` (Task 3) e precisa da aprovação humana antes de:
- Fazer o commit final de docs (SUMMARY + STATE + ROADMAP + REQUIREMENTS)
- Rodar `state advance-plan` / `state update-progress` (que também está bloqueado nesta execução por causa da sessão paralela ativa na Fase 94 — ver alerta de sessão abaixo)
- Considerar CART-07 (SC3/SC4) 100% fechado

**Sem MariaDB local funcional** (incidente conhecido, documentado em memória do projeto) — validação visual deve ocorrer no VPS após deploy do pacote, OU localmente com SQLite/seed se disponível, conforme nota do próprio plano.

## Nota sobre sessão paralela (Fase 94 — NPS)

Durante a execução deste plano, outra sessão commitou em paralelo na mesma árvore (`ba2100e docs(94-03): completa o plano NpsDispararMensal instrumentado + gate final da fase`, entre os commits `7a4bd06` e `b40ac55` deste plano). Nenhum conflito ocorreu — todos os `git add` desta execução usaram caminhos explícitos (nunca `git add .`/`-A`), e cada `git status --porcelain`/`git diff --name-only --staged` foi conferido antes de cada commit. `.planning/STATE.md` apareceu modificado pela sessão paralela durante a Task 2 (Current Position mutado por ela) — este arquivo foi deliberadamente NÃO tocado/commitado por esta execução, conforme instrução explícita de fronteira.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- CART-07 (SC3/SC4) funcionalmente completo no código (backend do 90-01 + UI deste plano); falta só a aprovação do checkpoint visual humano para fechar a Fase 90.
- Fases 91/92 (Desempenho) podem consumir o mesmo padrão de `CarteiraContextService::contadores()` e o padrão de filtro duplo (contexto + outro eixo sempre viajando juntos) estabelecido aqui.

---
*Phase: 90-carteiras-consolidadas-rendercarteirasconsolidadas-v17-0*
*Plan: 02 (Tasks 1-2 completas; Task 3 aguardando checkpoint humano)*
