---
phase: 62-metas-apresenta-o-clara-edi-o-r-pida
plan: 04
subsystem: goals-ui
tags: [inline-edit, goal-history, inertia-form, ux-quick-edit]
requires:
  - 62-01 (endpoint PUT /goals/{goal} + endpoint GET /goals/{goal}/history)
  - 62-03 (<GoalHistoryDrawer /> component)
provides:
  - "Aba Por Empresa em /goals com edicao inline de target_value (Enter/Blur/Escape)"
  - "Icone Clock por meta abrindo drawer de historico (livre para leitura)"
  - "GoalController::index enriquecido com goals[].results (ultimos 12 periodos, ASC por period)"
affects:
  - resources/js/Pages/Goals/Index.jsx (EmpresasSection)
  - app/Http/Controllers/GoalController.php (metodo index)
tech-stack:
  added: []
  patterns:
    - Inertia useForm com preserveScroll para inline PATCH
    - Eager load Company::with(['goals' => fn => with('results')]) com filtro active dentro do closure
    - Data-testids padrao {feature}-{acao}-{id}
key-files:
  created: []
  modified:
    - resources/js/Pages/Goals/Index.jsx
    - app/Http/Controllers/GoalController.php
decisions:
  - "Inline edit APENAS em EmpresasSection (aba Por Empresa) — CarteirasSection e MinhasMetasCarteira intocadas por SC #2 do phase"
  - "Botao Clock (leitura de historico) fica FORA do gate can_manage — qualquer usuario com acesso a rota /goals pode consultar o log"
  - "Botao Cancelar usa onMouseDown={e.preventDefault()} para nao disparar onBlur do input antes do click (evita commit acidental ao clicar em X)"
  - "No-op guard quando editingValue nao mudou (evita PUT + activity_log ruidoso)"
metrics:
  duration: 6min
  tasks_completed: 2
  files_modified: 2
  completed: 2026-07-07
---

# Phase 62 Plan 04: Goals Inline Edit + Clock Drawer Summary

Inline edit em 2 cliques para `target_value` na aba Por Empresa de `/goals`, com feedback pessimista (opacity + disabled durante request), Enter/Blur commit, Escape cancel. Icone Clock por meta abre o `<GoalHistoryDrawer />` criado em 62-03. `GoalController::index` enriquecido com `goals[].results` (ate 12 periodos, ordenado ASC por period) para alimentar chart futuro do painel de progresso.

## Diff resumido da aba Por Empresa

### Antes
```
[Metric Label]          [Pencil] [Trash]  <-- so admin
R$ 50.000,00
[Mensal] [R$]
```

### Depois
```
[Metric Label]          [Clock] [Pencil] [Trash]  <-- Clock livre, Pencil/Trash gated
R$ 50.000,00 (click)            \--- Clock abre <GoalHistoryDrawer />
    |
    v (click transforma em)
[    500       ] [Check] [X]
    ^
    +--- Enter/Blur commit (PUT + preserveScroll)
         Escape cancel
         disabled+opacity-60 durante request
```

## Data-testids adicionados

| Testid                              | Elemento                          | Escopo               |
|-------------------------------------|-----------------------------------|----------------------|
| `goal-inline-edit-trigger-{id}`     | button com valor formatado        | so quando NAO editando |
| `goal-inline-edit-form-{id}`        | `<form>` do inline                | so quando editando   |
| `goal-inline-edit-input-{id}`       | `<Input type=number>` controlled  | so quando editando   |
| `goal-history-open-{id}`            | icone Clock                       | sempre visivel       |

Drawer renderizado ao final do fragment (reutiliza data-testid `goal-history-drawer` do component 62-03).

## Build status

`npm run build` verde em 14.04s — 0 erros, 0 warnings novos. Chunks Goals resultantes bundlam `GoalHistoryDrawer` sem code-split extra.

## Zero regressao confirmada

- Suite `tests/Feature/Phase62/GoalUpdateAuthTest.php + GoalHistoryEndpointTest.php + ActivityLogSubjectFilterTest.php` = 13 passed / 57 assertions
- Dialog "Nova Meta" preservado (4 ocorrencias de `Nova Meta` no arquivo)
- Dialog "Editar Meta" preservado (4 ocorrencias de `Editar Meta` no arquivo)
- `formatGoalValue(goal)` continua sendo chamado em 3 pontos: EmpresasSection (novo condicional), CarteirasSection (linha 532, intocado), MinhasMetasCarteira (linha 735, intocado)
- Tabs `empresas` / `carteiras` continuam funcionando

## Deviations from Plan

**None** — plano executado exatamente como escrito. Detalhes de implementacao (ex: `onMouseDown={e.preventDefault()}` no botao Cancelar) foram acrescimos UX puros dentro do escopo de "feedback visual".

Nota: o plan mencionava substituir `formatGoalValue(goal)` em "linhas 169 e 630" mas depois explicitava "NAO tocar em CarteirasSection nem MinhasMetasCarteira (SC #2 do phase)". Interpretacao coerente: apenas linha 169 (EmpresasSection) foi trocada. Linhas 427 (CarteirasSection) e 630 (MinhasMetasCarteira) permanecem intactas.

## Threat Flags

Nenhuma superficie nova. Auth ja validada em 62-01. Payload inline restrito a `target_value` + `period_type` — `$request->validate()` rejeita chaves extras (T-62-04-02).

## Escopo externo detectado (fora deste plan)

Durante execucao dos tests, aparecem 6 failures em `tests/Feature/Phase62/CompanyShowGoalsPayloadTest.php` (arquivo untracked, criado as 15:43 pelo wave paralelo 62-05). Este teste pertence ao dominio 62-05 (CompanyController::show + Companies/Show.jsx) e nao ao 62-04. Nao investiguei nem modifiquei — cabe ao executor do 62-05 tratar.

## Self-Check: PASSED

- `git log --oneline -3` mostra `7962ad6` e `c8e272e` ✓
- `resources/js/Pages/Goals/Index.jsx` contem 2 ocorrencias de `GoalHistoryDrawer` ✓
- `app/Http/Controllers/GoalController.php` contem 2 ocorrencias de `'results'` + 1 de `sortBy('period')` ✓
- `npm run build` completou sem erros ✓
- Suite Phase62 pre-existente (13 tests) verde ✓
