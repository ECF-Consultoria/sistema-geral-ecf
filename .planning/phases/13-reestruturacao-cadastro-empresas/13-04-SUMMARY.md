---
phase: 13-reestruturacao-cadastro-empresas
plan: 04
subsystem: ui
tags: [react, inertia, sidebar, tailwind, frontend, comercial, pendentes]

# Dependency graph
requires:
  - phase: 13-reestruturacao-cadastro-empresas
    plan: 03
    provides: "ComercialController + rotas /comercial/* + EmpresaCadastradaNotification"
  - phase: 13-reestruturacao-cadastro-empresas
    plan: 02
    provides: "permission 'comercial.cadastrar_empresa' + setor Comercial"
  - phase: 13-reestruturacao-cadastro-empresas
    plan: 01
    provides: "companies.status + mlb_empresas.company_id"
provides:
  - "Item 'Cadastro de Empresas' no sidebar com separador 'COMERCIAL' gateado por permissão"
  - "NovaEmpresa.jsx — formulário completo com validação frontend, campos dinâmicos e feedback visual"
  - "Seção 'Pendentes' em /mlb/empresas para companies status='pendente' de polos/assessoria"
  - "Seção 'Pendentes' em /companies para companies status='pendente' de publicidade/gestao"
  - "Props 'empresas_pendentes' injetadas via MlbController::empresas() e CompanyController::index()"
affects:
  - "AppLayout.jsx — padrão de separadores de seção no sidebar"
  - "MlbController — empresas_pendentes prop para Mlb/Empresas.jsx"
  - "CompanyController — empresas_pendentes prop + campo 'status' no map()"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Separador de seção no sidebar via flag 'comercialSeparatorBefore: true' no NAV_ITEMS"
    - "Seção Pendentes com badge ecf-yellow e card border-ecf-yellow/20 bg-ecf-yellow/[0.03]"
    - "useForm() do Inertia para formulário com validação backend e feedback inline de erros"
    - "Prop 'empresas_pendentes' injetada pelos controllers para renderização condicional no frontend"

key-files:
  created:
    - resources/js/Pages/Comercial/NovaEmpresa.jsx
  modified:
    - resources/js/Layouts/AppLayout.jsx
    - app/Http/Controllers/MlbController.php
    - app/Http/Controllers/CompanyController.php
    - resources/js/Pages/Mlb/Empresas.jsx
    - resources/js/Pages/Companies/Index.jsx

key-decisions:
  - "Separador 'Comercial' posicionado antes dos itens MLB (mlbSeparatorBefore) no NAV_ITEMS — mantém ordenação lógica de negócio"
  - "empresas_pendentes injetada via controller (não via HandleInertiaRequests) — dado específico de módulo, não global"
  - "Seção Pendentes exibida apenas quando array não vazio — zero impacto visual para usuários sem empresas pendentes"
  - "Campo 'subtipo' omitido do formulário NovaEmpresa.jsx nesta fase — service_types 'polos' e 'assessoria' são valores diretos, sem necessidade de subtipo adicional"

patterns-established:
  - "Separador de seção no sidebar: flag 'xSeparatorBefore: true' no NAV_ITEMS + handler condicional no SidebarInner"
  - "Badge de pendentes: bg-ecf-yellow/10 text-ecf-yellow border-ecf-yellow/20 — reutilizável em outros módulos"
  - "Prop _pendentes: controllers injetam array filtrado por status='pendente' para renderização condicional"

requirements-completed:
  - COM-01
  - COM-02
  - COM-03
  - COM-09

# Metrics
duration: 15min
completed: 2026-05-25
---

# Phase 13 Plan 04: UI Frontend — Sidebar Comercial + NovaEmpresa + Seções Pendentes

**Sidebar com separador "COMERCIAL" e item gateado por permissão, formulário NovaEmpresa.jsx com validação e feedback visual, e seções "Pendentes" em /mlb/empresas e /companies — checkpoint humano aprovado com 29/29 testes GREEN.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-05-25T14:05:00Z
- **Completed:** 2026-05-25T14:20:00Z
- **Tasks:** 3 (Tarefa 1 auto + Tarefa 2 auto + Tarefa 3 checkpoint humano aprovado)
- **Files modified:** 6

## Accomplishments

- `AppLayout.jsx` recebe separador "COMERCIAL" com item "Cadastro de Empresas" gateado por `permission: 'comercial.cadastrar_empresa'` — visível apenas para quem tem a permissão
- `NovaEmpresa.jsx` entrega formulário completo: campos Nome, CNPJ, Tipo de Serviço (Select shadcn), validação backend com erros inline, feedback de sucesso via flash
- `MlbController::empresas()` e `CompanyController::index()` injetam `empresas_pendentes` como prop Inertia — cada página filtra por service_type adequado
- Seções "Pendentes" em `/mlb/empresas` (polos/assessoria) e `/companies` (publicidade/gestao) com badge amarelo `ecf-yellow` e card destacado
- Checkpoint humano aprovado: sidebar, formulário, criação, pendentes, guard de duplicata e integração com `/administrativo/financeiro` todos verificados visualmente

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: AppLayout sidebar + backend props** - `13d4cd8` (feat)
2. **Tarefa 2: NovaEmpresa.jsx + seções Pendentes + npm run build** - `1e095de` (feat)
3. **Tarefa 3: checkpoint:human-verify** — aprovado pelo usuário; sem commit adicional

**Metadata do plano:** a ser gerado por este SUMMARY

## Files Created/Modified

- `resources/js/Layouts/AppLayout.jsx` — Separador "COMERCIAL" + item "Cadastro de Empresas" com `comercialSeparatorBefore` flag e handler no SidebarInner
- `app/Http/Controllers/MlbController.php` — Método `empresas()` injetando `empresas_pendentes` (polos/assessoria status=pendente)
- `app/Http/Controllers/CompanyController.php` — Método `index()` incluindo campo `status` no map() e `empresas_pendentes` (publicidade/gestao status=pendente)
- `resources/js/Pages/Comercial/NovaEmpresa.jsx` — Formulário completo com `useForm()`, Select para service_type, validação e feedback de sucesso/erro
- `resources/js/Pages/Mlb/Empresas.jsx` — Seção "Empresas Pendentes" no topo com badge ecf-yellow quando `empresas_pendentes` não vazio
- `resources/js/Pages/Companies/Index.jsx` — Seção "Pendentes" no topo com badge ecf-yellow para publicidade/gestao

## Decisions Made

- **Separador posicionado antes dos itens MLB**: ordenação lógica do fluxo de negócio — o Comercial cadastra, depois o MLB/Admin gerencia
- **`empresas_pendentes` como prop de controller, não global**: dado específico de módulo; injetar via `HandleInertiaRequests` seria desperdício em páginas que não exibem pendentes
- **Campo `subtipo` omitido**: plan especificava omitir nesta fase, pois `service_type` (`polos`, `assessoria`, `publicidade`, `gestao`) já é suficiente para o backend — subtipo é evolução futura
- **Seção Pendentes renderizada condicionalmente**: `empresas_pendentes.length > 0` antes de renderizar — sem impacto visual quando vazio

## Deviations from Plan

Nenhum — plano executado exatamente como escrito. Tarefas 1 e 2 auto concluídas sem desvios. Checkpoint humano (Tarefa 3) aprovado sem problemas reportados.

## Issues Encountered

Nenhum. Os 29 testes da fase 13 continuaram GREEN após as modificações de frontend.

## User Setup Required

Nenhum — sem configuração externa necessária.

## Next Phase Readiness

- Fluxo completo Comercial operacional: cadastro → pendentes → visibilidade nos setores de destino
- Phase 13 waves 1-4 concluídas: schema, permissões, controller e UI todos entregues
- Requirements COM-01 a COM-09 atendidos (COM-01, COM-02, COM-03, COM-09 neste plano; demais no 13-03)
- Padrão de separadores de seção no sidebar documentado — futuras seções seguem o mesmo flag pattern
- Padrão de badge "pendentes" disponível para reutilização em outros módulos que precisem de destaque visual

---
*Phase: 13-reestruturacao-cadastro-empresas*
*Completed: 2026-05-25*

## Self-Check

**Verificação de arquivos criados/modificados:**
- [x] `resources/js/Layouts/AppLayout.jsx` — contém `comercialSeparatorBefore` e item "Cadastro de Empresas"
- [x] `resources/js/Pages/Comercial/NovaEmpresa.jsx` — formulário completo com `useForm()`
- [x] `resources/js/Pages/Mlb/Empresas.jsx` — seção Pendentes implementada
- [x] `resources/js/Pages/Companies/Index.jsx` — seção Pendentes implementada
- [x] `app/Http/Controllers/MlbController.php` — prop `empresas_pendentes` injetada
- [x] `app/Http/Controllers/CompanyController.php` — prop `empresas_pendentes` + campo `status` no map()

**Verificação de commits:**
- [x] Commit `13d4cd8` existe (feat AppLayout + backend)
- [x] Commit `1e095de` existe (feat NovaEmpresa + Pendentes + build)

**Verificação de testes e build:**
- [x] `artisan test --filter=Phase13` → 29/29 PASSED
- [x] `npm run build` → 0 erros
- [x] Checkpoint humano → APROVADO (sidebar, formulário, pendentes, duplicata, financeiro verificados)

## Self-Check: PASSED
