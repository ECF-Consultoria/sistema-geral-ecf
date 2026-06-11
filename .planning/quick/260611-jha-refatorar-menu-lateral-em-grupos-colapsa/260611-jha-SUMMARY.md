---
phase: quick-260611-jha
plan: 01
subsystem: frontend/navigation
tags: [menu, sidebar, ux, appLayout, companies]
tech-stack:
  added: []
  patterns: [collapsible-nav-groups, deep-link-tab-init]
key-files:
  modified:
    - resources/js/Layouts/AppLayout.jsx
    - resources/js/Pages/Companies/Index.jsx
decisions:
  - "NAV_TREE substitui NAV_ITEMS — estrutura de árvore com grupos, itens de topo e filhos"
  - "filterTree() descarta grupo se nenhum filho passa o gating de permissão"
  - "openGroups inicializado com grupos cujo filho está na rota atual (auto-expand)"
  - "Grupo Empresas usa padrão híbrido: Link no label (navega) + button no chevron (toggle)"
  - "Modo collapsed expande sidebar ao clicar em grupo (setCollapsed(false) + toggleGroup)"
  - "URLSearchParams lazy initializer para tab em Companies/Index — roda só no mount"
metrics:
  duration: "~20min"
  completed: "2026-06-11"
  tasks: 2
  files: 2
---

# Quick 260611-jha: Refatoração do menu lateral para grupos colapsáveis

**One-liner:** Menu lateral refatorado de lista plana com separadores para grupos colapsáveis com `NAV_TREE` + `ChevronDown` animado; deep-link `?tab=pendencias` habilitado via `URLSearchParams` em `Companies/Index.jsx`.

## O que foi entregue

### Task 1 — AppLayout.jsx: menu de grupos colapsáveis

- `NAV_ITEMS` (array plano de 35 itens) substituído por `NAV_TREE` (árvore com 7 grupos + itens de topo)
- Grupos criados: **Dados Estratégicos** (LineChart), **Empresas** (Building2, com `headerRoute`), **Dev** (Code2), **Comercial** (Briefcase), **Publicações** (BarChart2), **Polos** (ListChecks — novo, movido de Publicações), **Administrativo** (Shield)
- Novo grupo **Polos** separa Implementação + Projetos de Publicações (conforme spec)
- **Comercial**: "Empresas" renomeado para "Entrada de Empresas"
- `ChevronDown` importado de lucide-react; `rotate-180` quando grupo aberto (transição CSS)
- `filterTree()` em `useMemo([mainRole, permissions])`: descarta grupo se `children.filter(itemVisivel).length === 0`; respeita `permission` própria do grupo (ex: grupo Empresas com `core.empresas`)
- `openGroups` — objeto `{ [groupLabel]: bool }` — inicializado com grupos cujo filho satisfaz `isActive(child.page)`
- Filhos recuados com `ml-3 border-l border-white/[0.06] pl-2`
- Modo collapsed (desktop): clicar no header do grupo chama `setCollapsed(false)` antes de abrir o grupo
- Grupo Empresas: container flex com `<Link>` no label (navega para `companies.index`) + `<button>` no chevron (toggle independente)
- Todas as `permission`, `excludeRoles` e `showBadge` copiadas literalmente do `NAV_ITEMS` original

### Task 2 — Companies/Index.jsx: aba inicial via `?tab`

- `useState('empresas')` substituído por lazy initializer com `URLSearchParams`
- Valida contra `['empresas', 'pendencias', 'grupos']`; fallback `'empresas'` para valor ausente ou inválido
- Habilita deep-link `/companies?tab=pendencias` gerado pelo menu lateral (grupo Empresas › Pendências)
- Nenhuma outra lógica de aba foi alterada

## Commits

| Hash | Tarefa | Descrição |
|------|--------|-----------|
| `c2db2be` | Task 1 | feat(quick-260611-jha): refatora menu lateral para grupos colapsáveis |
| `3c3bc1b` | Task 2 | feat(quick-260611-jha): aba inicial de Companies/Index via query param ?tab |

## Deviações do plano

Nenhuma — plano executado exatamente como especificado.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum — alterações puramente de UI/navegação, sem novos endpoints, rotas ou acesso a dados.

## Self-Check: PASSED

- `resources/js/Layouts/AppLayout.jsx` — presente e modificado (commit c2db2be)
- `resources/js/Pages/Companies/Index.jsx` — presente e modificado (commit 3c3bc1b)
- `npm run build` — concluído sem erro em ambas as tarefas
