---
phase: 151-rea-comercial-conectada-etapa-v23-0
plan: 08
subsystem: ui
tags: [inertia, react, laravel, navigation, permissions]

requires:
  - phase: 151-05
    provides: "permissão comercial.entrada, rota comercial.entrada.index, ComercialEntradaController::index() e a casca mínima de Comercial/Entrada.jsx"
  - phase: 151-06
    provides: "os 8 campos do §2 em ContratoAdminController::index()/Admin/Contratos.jsx"
provides:
  - "Comercial/Entrada.jsx como componente React completo — tabela com os 8 campos do §2, busca/ordenação server-side, coluna única de Pendências com as duas pendências (fluxo/cadastro) visualmente separadas"
  - "NAV_TREE reorganizado: grupo Comercial mostra Cadastro de Empresas, Grupos, Contrato e Entrada; Administrativo perdeu os itens Empresas e Contratos"
  - "Regressão travada por teste de que D-15 (permission de Contrato preservada) e D-16 (tela de Empresas sobrevive por URL direta) não quebraram com a mudança de menu"
affects: [139]

tech-stack:
  added: []
  patterns:
    - "Mover item de menu edita só o NAV_TREE — rota/controller/página do módulo movido não mudam de lugar (evita o anti-padrão de re-export puro que some do manifest do Vite)"
    - "Coluna única de Pendências renderiza duas fontes de dado (pendencia_fluxo/pendencias_cadastro) como badges visualmente distintos na mesma célula, nunca somadas (D-11)"

key-files:
  created:
    - tests/Feature/Phase151/ComercNavegacaoReorganizadaTest.php
  modified:
    - resources/js/Pages/Comercial/Entrada.jsx
    - resources/js/Layouts/AppLayout.jsx

key-decisions:
  - "Coluna 'Pendências' única (não duas colunas separadas) seguindo literalmente o padrão visual já em produção em Admin/Contratos.jsx — as duas pendências continuam visualmente distintas (badge destructive vs. badges warning) dentro da mesma célula, nunca somadas"
  - "Status do contrato reaproveita o mesmo ContratoBadge de Comercial/EmpresasListagem.jsx (situação + 'há N dias', nunca link) em vez de inventar uma representação nova para o mesmo dado"

patterns-established:
  - "Item de NAV_TREE é desacoplado de rota/controller/página — reorganizar navegação nunca exige mover arquivo"

requirements-completed: [COMERC-02]

duration: ~35min
completed: 2026-09-03
---

# Phase 151 Plan 08: Reorganização de navegação — Contrato e Entrada no Comercial Summary

**`Entrada.jsx` virou componente React completo com os 8 campos do §2, e o `NAV_TREE` foi reorganizado para o Comercial ganhar os módulos Contrato e Entrada enquanto `Administrativo › Empresas` some do menu sem apagar rota, controller ou página.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-09-03T18:45:00-03:00 (aprox.)
- **Completed:** 2026-09-03T18:56:00-03:00
- **Tasks:** 3/3
- **Files modified:** 3 (2 modificados, 1 criado)

## Accomplishments
- `Comercial/Entrada.jsx` deixou de ser a casca mínima do plano 151-05 e ganhou busca/ordenação server-side, badge de origem (HubSpot/Manual), badge de status do contrato, coluna de etapa (com "Sem etapa (legado)" para o legado nunca carimbado) e a coluna única de Pendências mostrando `pendencia_fluxo` e `pendencias_cadastro` como badges visualmente distintos, nunca somados (D-11)
- `NAV_TREE` reorganizado: grupo Comercial agora mostra, nesta ordem, Cadastro de Empresas → Grupos → Contrato → Entrada; grupo Administrativo perdeu os itens Empresas (D-16) e Contratos (D-15, migrado)
- Item "Contrato" preserva `routeName`, `page` e `permission: admin.contratos` idênticos ao item "Contratos" original — só o `label` e o grupo mudaram
- Regressão de D-15/D-16 travada em `ComercNavegacaoReorganizadaTest.php` (8 casos), reusando `ContratoAdminPermissaoTest.php` sem modificação

## Task Commits

Each task was committed atomically:

1. **Task 1: Página Entrada como componente React de verdade** - `ca5f6404` (feat)
2. **Task 2: Reorganizar o NAV_TREE** - `5e0d3b94` (feat)
3. **Task 3: Regressão das duas decisões de navegação** - `ae5c7b3d` (test)

**Plan metadata:** _(próximo commit deste executor)_

## Files Created/Modified
- `resources/js/Pages/Comercial/Entrada.jsx` - componente completo: tabela dos 8 campos do §2, busca/ordenação server-side, badges de origem/contrato/pendências, texto informativo sem nenhum controle de checklist (Fase 152)
- `resources/js/Layouts/AppLayout.jsx` - `NAV_TREE`: item Contrato movido para o Comercial (permission preservada), item Entrada acrescentado, item Empresas removido do Administrativo com comentário citando D-16
- `tests/Feature/Phase151/ComercNavegacaoReorganizadaTest.php` - 8 casos cobrindo D-15 (permission de Contrato preservada) e D-16 (Empresas sobrevive por URL direta)

## Decisions Made
- Cabeçalho "Pendências" único na tabela (não duas colunas separadas como a casca do 151-05 tinha) — decisão de forma exata deixada a critério do executor pelo CONTEXT ("Forma exata dos componentes de lista", Claude's Discretion). Escolhido o mesmo padrão já em produção em `Admin/Contratos.jsx`, para as duas telas não divergirem visualmente enquanto mostram o mesmo shape de payload.
- Ver "Deviations from Plan" para o ajuste de um comentário que colidia com o próprio grep de acceptance criteria.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Comentário duplicava o grep do critério de aceite da Task 2**
- **Found during:** Task 2 (verificação dos critérios de aceite)
- **Issue:** O comentário pt-BR escrito acima do item "Contrato" citava o literal `permission: 'admin.contratos'` entre crases, fazendo `grep -c "permission: 'admin.contratos'"` devolver `2` em vez do `1` esperado (o critério de aceite existe para confirmar que a permission não foi duplicada nem trocada, mas a contagem também capturava a menção em prosa).
- **Fix:** Reescrito o comentário para descrever a mesma informação sem repetir o literal entre aspas simples exatamente igual ao código.
- **Files modified:** `resources/js/Layouts/AppLayout.jsx`
- **Verification:** `grep -c "permission: 'admin.contratos'" resources/js/Layouts/AppLayout.jsx` volta a `1`; `npm run build` reexecutado com sucesso.
- **Committed in:** `5e0d3b94` (Task 2, antes do commit — não gerou commit extra)

---

**Total deviations:** 1 auto-fixed (Rule 1, cosmético — não alterou nenhum middleware nem comportamento de rota).
**Impact on plan:** Nenhum. Nenhum scope creep.

## Issues Encountered
None.

## User Setup Required
None - nenhuma configuração de serviço externo.

## Next Phase Readiness
- A Fase 152 pode acrescentar o checklist dos 8 itens do módulo Entrada e os 4 do módulo Contrato sem qualquer dependência de navegação pendente — os dois módulos já são acessíveis pelo menu certo, com a permission certa.
- `resources/js/Pages/Admin/Empresas.jsx`, `AdminController::empresas()/updateEmpresa()` e a rota `admin.empresas` continuam vivos e cobertos por teste — a remoção real (D-16) é trabalho próprio futuro, fora desta fase.
- Nenhum arquivo de Contrato mudou de lugar — a Fase 152 pode continuar editando `ContratoAdminController.php`/`Admin/Contratos.jsx` nos mesmos caminhos de sempre.

---
*Phase: 151-rea-comercial-conectada-etapa-v23-0*
*Completed: 2026-09-03*

## Self-Check: PASSED

Todos os 3 arquivos criados/modificados confirmados em disco e os 3 commits de task (`ca5f6404`, `5e0d3b94`, `ae5c7b3d`) confirmados em `git log --oneline --all`.
