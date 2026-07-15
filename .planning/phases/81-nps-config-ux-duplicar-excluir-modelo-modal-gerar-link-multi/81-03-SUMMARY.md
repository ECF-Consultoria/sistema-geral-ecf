---
phase: 81-nps-config-ux-duplicar-excluir-modelo-modal-gerar-link-multi
plan: 03
subsystem: ui
tags: [nps, inertia, react, ziggy, config-ux]

# Dependency graph
requires:
  - phase: 81-01
    provides: "rotas nps.configuracao.templates.duplicate (POST) e .destroy (DELETE) + NpsTemplateController@duplicate/@destroy"
provides:
  - "Botões Duplicar e Excluir no editor de modelo NPS (TemplateEditForm)"
  - "handlers duplicar()/excluir() com refresh da lista via onSaved/onDeleted"
  - "Guard UI: Excluir desabilitado no modelo principal (is_default)"
affects: [81-04]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Ações de mutação no editor via router.post/router.delete (não useForm), espelhando alternarAtivo/definirPrincipal"
    - "onDeleted callback separado de onSaved para navegação pós-exclusão (evita seleção órfã)"

key-files:
  created:
    - .planning/phases/81-nps-config-ux-duplicar-excluir-modelo-modal-gerar-link-multi/81-03-SUMMARY.md
  modified:
    - resources/js/Components/Nps/Config/TemplateEditForm.jsx
    - resources/js/Pages/Nps/Configuracao.jsx

key-decisions:
  - "Após duplicar, recarrega a lista via onSaved(template.id) (NÃO abre o editor do clone) — DEC-81-1 revisado"
  - "Excluir usa callback onDeleted → volta pra lista; sem ele o editor ficaria com selectedId órfão apontando pra um modelo inexistente"
  - "Erro 422 (is_default/tem respostas) não é tratado manualmente — surfaced pelo flash global de erro (HandleInertiaRequests)"

patterns-established:
  - "Flags de guarda (podeExcluir) derivadas no escopo do componente — seguro pois não há .map() (gotcha Rollup Pitfall 4)"

requirements-completed: [DEC-81-1, DEC-81-2]

# Metrics
duration: 12min
completed: 2026-07-14
---

# Phase 81 Plan 03: Botões Duplicar/Excluir no editor de modelo NPS Summary

**Editor de modelo NPS ganhou botões Duplicar (clona modelo via POST) e Excluir (DELETE com confirmação), com Excluir desabilitado no modelo principal e recarga da lista após cada ação.**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-07-14
- **Completed:** 2026-07-14
- **Tasks:** 1
- **Files modified:** 2

## Accomplishments
- `duplicar()`: `router.post(route('nps.configuracao.templates.duplicate', id))` — após sucesso, toast + `onSaved(id)` recarrega a lista (não abre o clone).
- `excluir()`: guarda UI (`if (template.is_default) return`) + `confirm()` + `router.delete(route('...destroy', id))`; após sucesso volta pra lista via `onDeleted`.
- Dois botões no header do editor (ícones `Copy` e `Trash2`), consistentes com os vizinhos (Definir principal / Serviços cobertos / Ativar-Desativar), usando tokens `ecf-*` e `cn()`.
- Botão Excluir `disabled` quando `is_default`, com `title`/hint explicando "Defina outro como principal antes".
- Erro 422 do backend (is_default / tem respostas) aparece pelo flash global — sem tratamento manual.

## Task Commits

1. **Tarefa 1: handlers e botões Duplicar/Excluir no TemplateEditForm** - `a2e9890` (feat)

## Files Created/Modified
- `resources/js/Components/Nps/Config/TemplateEditForm.jsx` - handlers `duplicar()`/`excluir()`, prop `onDeleted`, botões Duplicar/Excluir no header, flag `podeExcluir`, import de `Copy`/`Trash2`.
- `resources/js/Pages/Nps/Configuracao.jsx` - passa `onDeleted` ao `TemplateEditForm` (volta pra lista + refresh após exclusão).

## Decisions Made
- **Duplicar recarrega a lista** (não abre o editor do clone) — DEC-81-1 conforme revisão no PLAN task.
- **onDeleted separado de onSaved** para navegação pós-exclusão — ver deviation abaixo.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Adicionado callback `onDeleted` para navegação pós-exclusão**
- **Found during:** Task 1
- **Issue:** O plano especificava `excluir()` chamando `onSaved(template.id)` no sucesso. Mas o handler `onSaved` do parent faz `setSelectedId(savedId)` mantendo a seleção no modelo recém-excluído. Após o `refresh`, `selected` vira `null` mas o `mode` continua `'edit'` (selectedId != null) — o `TemplateEditForm` recebe `template={null}` e renderiza o **formulário de criação**, não a lista. Isso viola o must_have "em sucesso a lista recarrega".
- **Fix:** Adicionada prop `onDeleted` ao `TemplateEditForm`/`FormEdicao`; `excluir()` chama `onDeleted()` (fallback `onSaved`). `Configuracao.jsx` passa `onDeleted={() => { voltarParaLista(); refresh(); }}`.
- **Files modified:** resources/js/Components/Nps/Config/TemplateEditForm.jsx, resources/js/Pages/Nps/Configuracao.jsx
- **Verification:** `npm run build` verde; fluxo lógico: após excluir → `voltarParaLista()` zera selectedId → modo `list` → `refresh()` recarrega templates.
- **Committed in:** `a2e9890` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Correção necessária para cumprir o must_have "a lista recarrega" após exclusão. `Configuracao.jsx` já constava em `files_modified` do frontmatter — sem scope creep.

## Issues Encountered
- `npx eslint` falhou (ESLint 10 sem config no projeto) — esperado pelo plano; validação feita via `npm run build` (verde), conforme critical notes.

## Next Phase Readiness
- 81-04 (checkpoint visual + build) pode validar a UX dos botões.
- Rotas backend (81-01) e testes Feature/V16 (DuplicarModeloTest/ExcluirModeloTest) já existem.

## Self-Check: PASSED

- FOUND: resources/js/Components/Nps/Config/TemplateEditForm.jsx
- FOUND: resources/js/Pages/Nps/Configuracao.jsx
- FOUND: 81-03-SUMMARY.md
- FOUND: commit a2e9890
- FOUND: referência a `templates.duplicate` no TemplateEditForm

---
*Phase: 81-nps-config-ux-duplicar-excluir-modelo-modal-gerar-link-multi*
*Completed: 2026-07-14*
