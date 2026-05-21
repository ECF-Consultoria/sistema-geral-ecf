---
phase: "05"
plan: "03"
subsystem: "Admin/Fechamento"
tags: [frontend, inertia, react, accordion, form, sidebar]
dependency_graph:
  requires: ["05-01", "05-02"]
  provides: ["Fechamento UI completa com edição inline por empresa"]
  affects: ["resources/js/Pages/Admin/Financeiro.jsx", "resources/js/Layouts/AppLayout.jsx"]
tech_stack:
  added: []
  patterns: ["accordion exclusivo por useState", "useForm PATCH com onSuccess close", "badge condicional has_adman"]
key_files:
  created: []
  modified:
    - resources/js/Pages/Admin/Financeiro.jsx
    - resources/js/Layouts/AppLayout.jsx
decisions:
  - "Label sidebar 'Financeiro' → 'Fechamento'; routeName admin.financeiro permanece inalterado"
  - "Acordeão com exclusividade: apenas uma empresa expandida por vez via useState(null)"
  - "Badge 'Sem integração' condicional em !empresa.has_adman (flag provida pelo backend)"
metrics:
  duration: "~5 minutos"
  completed: "2026-05-19"
  tasks_completed: 2
  files_changed: 2
---

# Phase 05 Plan 03: Financeiro.jsx Rewrite — Tela de Fechamento Summary

**One-liner:** Reescrita completa de Financeiro.jsx como tela de Fechamento com accordion exclusivo, PATCH inline por empresa, badges de tipo de serviço e integração, e label da sidebar atualizado de "Financeiro" para "Fechamento".

## O que foi feito

### Task 1A — AppLayout.jsx (1 linha)

Localizado o item `{ label: 'Financeiro', routeName: 'admin.financeiro', ... }` na array `NAV_ITEMS` (linha 51) e alterado apenas o `label` para `'Fechamento'`. Todos os demais campos (routeName, page, icon, roles) permaneceram intactos.

### Task 1B — Financeiro.jsx (reescrita completa)

O placeholder "Em desenvolvimento" foi substituído pela tela funcional de Fechamento com:

- **ServiceBadge** — exibe o tipo de serviço (POLO/Assessoria/Incubadora) com cores distintas por tipo; "Sem tipo" quando nulo.
- **IntegrationBadge** — badge âmbar "Sem integração" exibida condicionalmente quando `empresa.has_adman` é falso.
- **FechamentoRow** — linha clicável por empresa; exibe nome, badges e faixa de datas do contrato formatadas via `formatDate()`. O chevron gira 180° quando expandido.
- **ServiceForm** — formulário com 3 campos (tipo de serviço, início e término do contrato); usa `useForm` do Inertia para PATCH em `admin.financeiro.update`; fecha o acordeão via `onSuccess`.
- **FechamentoAccordion** — painel expandido que encapsula o ServiceForm.
- **FechamentoList** — lista com acordeão exclusivo (apenas uma empresa aberta por vez) via `useState(null)`; tela vazia com Building2 icon quando não há empresas.
- **Financeiro** (default export) — layout raiz com `AppLayout title="Fechamento"`, cabeçalho com ícone `Banknote`, max-w-4xl, subtítulo descritivo.

## Resultado do Build

```
vite v7.3.2 building client environment for production...
✓ 4324 modules transformed.
✓ built in 10.28s
```

Build concluído sem erros ou warnings. Artefato gerado: `public/build/assets/Financeiro-D69U9CL6.js` (6.49 kB / gzip 2.17 kB).

## Verificações de Spot-Check

- AppLayout.jsx linha 51: `label: 'Fechamento'` presente, `routeName: 'admin.financeiro'` inalterado.
- Financeiro.jsx contém: `FechamentoList`, `FechamentoRow`, `FechamentoAccordion`, `ServiceBadge`, `IntegrationBadge`, `useForm`, `route('admin.financeiro.update', empresa.id)`.

## Deviations from Plan

Nenhuma — plano executado exatamente como especificado.

## Stubs Conhecidos

Nenhum stub identificado. Todos os props (`companies`, `service_type`, `contract_start`, `contract_end`, `has_adman`) são providos pelo backend via `AdminController@fechamento()` (implementado na Wave 1).

## Status

**AWAITING_HUMAN_VERIFY** — Plano `autonomous: false`. Verificação visual necessária:

1. Acessar `/administrativo/financeiro` no navegador como admin.
2. Confirmar que a sidebar exibe "Fechamento" (não "Financeiro") na seção Administrativo.
3. Confirmar que a lista de empresas aparece com badges de tipo e integração.
4. Expandir uma empresa e salvar alteração — verificar que o acordeão fecha após onSuccess.
5. Testar empresa sem `has_adman` — badge "Sem integração" deve aparecer.

## Commit

- `00ef75b` — `feat(05-03): Financeiro.jsx rewrite + sidebar label "Fechamento" + npm build (Wave 3)`

## Self-Check: PASSED

- `resources/js/Pages/Admin/Financeiro.jsx` — existe e contém todos os componentes requeridos.
- `resources/js/Layouts/AppLayout.jsx` — label 'Fechamento' confirmado na linha 51.
- Commit `00ef75b` — verificado via `git rev-parse --short HEAD`.
- Build Vite — concluído sem erros.
