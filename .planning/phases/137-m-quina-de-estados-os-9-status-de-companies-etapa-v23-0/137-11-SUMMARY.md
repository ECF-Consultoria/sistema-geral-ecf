---
phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
plan: 11
subsystem: ui
tags: [inertia, react, query-params, phpunit, static-gate]

# Dependency graph
requires:
  - phase: 137-07
    provides: "Filtros server-side `?etapa=`/`?com_pendencia=` em `/companies` (ETAPA-05) — os quatro handlers de filtro do cliente que este plano centraliza"
  - phase: 137-08
    provides: "Molde de gate estático com `assertEmpty`/mensagem nomeando o requisito violado, reaproveitado na forma do gate desta plano (embora este seja sobre `.jsx`, não `.php`)"
provides:
  - "`aplicarFiltros(overrides)` — único ponto de `resources/js/Pages/Companies/Index.jsx` que chama `router.get(route('companies.index'))`, partindo dos filtros ATIVOS e aplicando overrides por cima"
  - "Os quatro handlers de filtro (`aplicarCustIdFilter`, `aplicarSort`, `aplicarEtapaFilter`, `aplicarComPendenciaFilter`) viram invólucros finos que delegam ao montador único, sem mudar assinatura pública"
  - "Prova de interseção tripla (`cust_id_status` + `etapa` + `com_pendencia`) no backend, já suportada desde 137-07 mas nunca testada com os três juntos"
  - "Gate estático que trava a centralização contra reintrodução, já visto falhando por prova dirigida"
affects: [138, 139, 141, 142, 143]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Montador único de query Inertia: um handler central lê o estado ATUAL de todos os filtros, aplica overrides do chamador, remove chaves vazias/desligadas, e é o único emissor de `router.get(...)` — os handlers de UI viram invólucros finos que só passam o override do próprio campo"
    - "Gate estático sobre `.jsx` em teste PHPUnit: `File::get()` + regex/`strpos` para provar a FORMA de um arquivo de frontend (contagem de chamadas, delegação por handler), mesmo padrão de varredura estática já usado sobre `.php` em 137-06/137-08"

key-files:
  created: []
  modified:
    - resources/js/Pages/Companies/Index.jsx
    - tests/Feature/Phase137/EtapaFiltroListagemTest.php

key-decisions:
  - "aplicarSort deixou de fixar `tab: 'pendencias'` na marra e passa a herdar a aba CORRENTE do montador único — mesmo resultado hoje (o controle só renderiza dentro da aba Pendências), mas passa a sobreviver a um refresh vindo dos outros três controles sem depender do literal. Melhoria deliberada nomeada no plano, não efeito colateral silencioso."
  - "Gate estático usa `strpos`/substring do corpo do handler (até o próximo `const ` no mesmo nível) em vez de parser AST — suficiente para as quatro arrow functions curtas deste arquivo, mesmo molde de simplicidade já aceito nos gates de 137-06/137-08 para `.php`."
  - "Comentário das linhas 211-215 do Index.jsx reescrito, não ampliado: a afirmação antiga ('cada handler PRESERVA o outro filtro já ativo — D-23') era verdadeira só para o par etapa/pendência; o novo nomeia WR-03 como motivo da centralização e evita repetir a mesma falha de precisão."

patterns-established:
  - "Handler de UI que dispara request server-side nunca monta params por conta própria quando existe mais de um filtro combinável — centralizar num montador único que parte do estado ativo é o padrão a seguir em qualquer tela nova com filtros múltiplos independentes."

requirements-completed: [ETAPA-05]

# Metrics
duration: ~35min
completed: 2026-09-02
---

# Phase 137 Plan 11: Filtros de `/companies` deixam de se apagar entre si (WR-03) Summary

**Os quatro handlers de filtro de `/companies` (`cust_id_status`, `etapa`, `com_pendencia`, `sort`) passaram a delegar a um único montador `aplicarFiltros(overrides)` que reenvia todos os filtros ativos a cada mudança — combinação tripla provada no backend, travada no cliente por gate estático, e confirmada por um humano no round-trip real do navegador.**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-09-02
- **Tasks:** 3/3
- **Files modified:** 2

## Accomplishments

- **G3/WR-03 fechado:** antes deste plano, cada um dos quatro handlers de filtro de `/companies` montava a query do zero e só reenviava o(s) filtro(s) que ele próprio conhecia. Escolher uma etapa apagava em silêncio o `cust_id_status` já ativo; escolher Cust ID apagava etapa e pendência; trocar a ordenação na aba Pendências apagava etapa e pendência. O comentário que a própria Fase 137 tinha escrito ("cada handler PRESERVA o outro filtro já ativo — D-23") era verdadeiro só para o par etapa/pendência — falso para a interação com `cust_id_status` e com `sort`.
- `aplicarFiltros(overrides)` nasceu como o único ponto do arquivo que chama `router.get(route('companies.index'))`: parte dos filtros ATUALMENTE ativos (`cust_id_status`, `etapa`, `com_pendencia`, `sort`, `tab`), aplica por cima o `overrides` de quem chamou, remove chaves vazias/desligadas, e mantém `preserveState`/`preserveScroll` como antes. Os quatro handlers viraram invólucros finos com a mesma assinatura pública — nenhum ponto de chamada no JSX mudou.
- Backend provado com os três filtros combinados de verdade: `test_filtros_triplos_combinados_intersectam_cust_id_etapa_e_pendencia` monta quatro empresas, cada uma satisfazendo um subconjunto diferente dos três critérios, e afirma que só a que bate nos três aparece; `test_prop_filters_reflete_os_tres_valores_aplicados_simultaneamente` prova que a prop `filters` ecoa os três valores ao mesmo tempo.
- Gate estático (`test_gate_index_jsx_tem_um_unico_montador_de_query_e_os_quatro_handlers_delegam_a_ele`) trava a centralização: (a) no máximo uma chamada a `router.get(route('companies.index')` no arquivo inteiro; (b) cada um dos quatro handlers delega a `aplicarFiltros(` sem conter `router.get(` no próprio corpo. Mensagem de falha nomeia WR-03 e o dano concreto ("escolher um filtro vai apagar os outros em silêncio"), nunca "assertion failed". **Prova dirigida:** `aplicarCustIdFilter` foi revertido temporariamente para a forma antiga (monta `router.get` por conta própria) e o gate FALHOU com "2 handler(s) voltaram a chamar router.get(...) por conta própria" — reversão desfeita, diff byte-idêntico confirmado antes de seguir.
- **Verificação humana aprovada** (Task 3): o usuário combinou os três filtros na tela real de `/companies` neste worktree e confirmou que nenhum apaga os outros. Evidência concreta — a URL produzida ao aplicar `cust_id_status` e, **sem limpá-lo**, escolher "Sem etapa (legado)" em seguida:

  ```
  http://localhost/ecf_fluxo_entrada/public/companies?cust_id_status=invalido&etapa=sem_etapa&tab=empresas
  ```

  Antes de `add870ca`, `aplicarEtapaFilter` rebuild a query do zero e descartava `cust_id_status` em silêncio — exatamente o passo 3 do roteiro de verificação (`<how-to-verify>` do plano), o único que já tinha falhado numa verificação anterior da fase. `tab=empresas` também sobrevive, confirmando que o montador único preserva a aba corrente mesmo vindo de um handler que não é `aplicarSort`.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Centralizar a montagem da query num único `aplicarFiltros(overrides)`** - `add870ca` (fix)
2. **Task 2: Provar a combinação no backend + gate estático** - `afbe85e0` (test)
3. **Task 3: Verificação humana — combinar filtros na tela real** - checkpoint, sem commit próprio (aprovado pelo usuário; evidência registrada acima)

## Files Created/Modified

- `resources/js/Pages/Companies/Index.jsx` - `aplicarFiltros(overrides)` centraliza a única chamada a `router.get(route('companies.index'))`; os quatro handlers (`aplicarCustIdFilter`, `aplicarSort`, `aplicarEtapaFilter`, `aplicarComPendenciaFilter`) passam a delegar a ele; comentário das linhas 211-215 corrigido para nomear WR-03; `npm run build` rodado, `public/build/` não commitado (gitignored)
- `tests/Feature/Phase137/EtapaFiltroListagemTest.php` - 2 testes novos de combinação tripla + 1 gate estático sobre `Index.jsx`; os 9 testes pré-existentes inalterados (diff só tem linhas adicionadas)

## Decisions Made

- `aplicarSort` deixou de fixar `tab: 'pendencias'` na marra e passa a herdar a aba corrente do montador único — mesmo resultado hoje (o controle só existe dentro da aba Pendências), mas sobrevive a um refresh vindo dos outros três controles. Registrado como melhoria deliberada, não efeito colateral, tanto no comentário do código quanto no commit.
- O gate estático usa varredura por substring (`strpos` até o próximo `const ` no mesmo nível de indentação) em vez de parser AST — suficiente para as quatro arrow functions curtas do arquivo, mesmo grau de simplicidade já aceito nos gates estáticos de 137-06/137-08 sobre `.php`.
- Comentário das linhas 211-215 foi **reescrito**, não ampliado: a afirmação antiga ("cada handler PRESERVA o outro filtro já ativo — D-23") induzia ao erro por ser verdadeira só para um dos três pares possíveis. O novo nomeia WR-03 como motivo da centralização, evitando repetir o mesmo tipo de imprecisão.

## Deviations from Plan

None - plan executado exatamente como escrito, incluindo a prova dirigida do gate estático (revert temporário → falha esperada → reversão desfeita) e o checkpoint humano da Task 3.

## Issues Encountered

None.

## User Setup Required

None - nenhuma configuração de serviço externo.

## Next Phase Readiness

**Os 4 planos de gap closure da Fase 137 (137-08 G1, 137-09 G2, 137-10 G4+G5+G6, 137-11 G3) estão fechados.** Combinados aos 7 planos originais, a Fase 137 completa seus **11/11 planos** — todas as 6 Success Criteria, os 6 requirements (ETAPA-01..06) e todos os achados CRITICAL/WARNING de `137-VERIFICATION.md`/`137-REVIEW.md` estão endereçados. `ETAPA-05` não foi remarcada em `REQUIREMENTS-v23.md` — já estava `[x]` desde 137-07; este plano apenas endureceu a garantia que ela já reivindicava.

A Fase 138 (Área Comercial conectada à etapa) é a próxima da milestone v23.0 e ainda **não foi planejada** (`Plans: TBD` no `ROADMAP.md`) — requer `/gsd-plan-phase 138` antes de qualquer execução. Nenhum bloqueio deixado por este plano.

---
*Phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0*
*Completed: 2026-09-02*

## Self-Check: PASSED

- FOUND: commit `add870ca` (Task 1)
- FOUND: commit `afbe85e0` (Task 2)
- FOUND: `resources/js/Pages/Companies/Index.jsx`
- FOUND: `tests/Feature/Phase137/EtapaFiltroListagemTest.php`
- CONFIRMED: `phpunit tests/Unit/Phase137 tests/Feature/Phase137 --colors=never` = 58/58 (re-executado nesta sessão)
- CONFIRMED: `phpunit tests/Feature/Phase37CompaniesPerformanceFilterTest.php tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php --colors=never` = 24/24 (re-executado nesta sessão)
- CONFIRMED: `public/build/manifest.json` com mtime 2026-09-02 10:09 (build da Task 1 desta sessão anterior), sem `public/hot` presente
- CONFIRMED: checkpoint da Task 3 aprovado pelo usuário com evidência de URL (`cust_id_status=invalido&etapa=sem_etapa&tab=empresas`)
