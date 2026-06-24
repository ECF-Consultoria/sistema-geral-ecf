---
phase: quick-260624-igv
plan: 01
subsystem: mlb-polos-nav
tags: [nav, polos, frontend, backend, inertia]
decisions:
  - "polosEmpresas() reutiliza 100% da lógica de agrupamento de projetos() sem duplicar lógica separada"
  - "Building2 já importado no AppLayout — sem novo import necessário para o item Empresas por M"
  - "Contagens incluem M com zero empresas para a página poder exibir zeros se necessário"
metrics:
  duration: "~10 min"
  completed: "2026-06-24"
  tasks: 3
  files: 4
key-files:
  created:
    - resources/js/Pages/Polos/EmpresasPorM.jsx
  modified:
    - routes/web.php
    - app/Http/Controllers/MlbController.php
    - resources/js/Layouts/AppLayout.jsx
---

# Quick Task 260624-igv: Reorganizar nav Polos/Comercial e nova página Empresas por M

**One-liner:** Nav reorganizada (Projetos migrou para Comercial; Empresas por M adicionado em Polos) com nova rota `mlb.polos-empresas` + grid de cards M0–M4 por empresa POLOS.

## Tarefas Executadas

### T1 — Backend: rota + método (commit `5b1f8c0`)

- Adicionada rota `GET /mlb/polos-empresas` nomeada `mlb.polos-empresas` em `routes/web.php` logo após `/projetos`.
- Criado método `polosEmpresas()` em `MlbController` imediatamente após `projetos()`, reutilizando a lógica de mapeamento e agrupamento POLOS por fase M0–M4 com contagens para todas as fases e totalPolos.
- Método `projetos()` não foi alterado (seção POLOS continua intacta na página Projetos).

### T2 — Frontend: página Polos/EmpresasPorM.jsx (commit `7d84ebd`)

- Criado `resources/js/Pages/Polos/EmpresasPorM.jsx` com componente default `EmpresasPorM({ grupos, contagens, totalPolos })`.
- Grid `grid-cols-1 md:grid-cols-2 xl:grid-cols-3` de cards compactos por fase M.
- Cada card: nome + prioridade (PRIORIDADE_COR), estágio (emerald/purple), fase (sky), barra de progresso (verde/amarelo/violeta), chip de problema (AlertTriangle + texto), responsável, rodapé com links Link2/BookUser de implementação.
- Estado vazio se nenhum grupo disponível.
- Tokens ecf-*, cn(), comentários pt-BR, appUrl via `asset_url` do usePage().

### T3 — Nav + build (commit `8b82478`)

- Item "Projetos" removido do grupo Polos e adicionado como último sub-item do grupo Comercial (mesma rota/page/permission/ícone FolderKanban).
- Novo item "Empresas por M" adicionado no grupo Polos (icon Building2, já importado; permission mlb.projetos; routeName mlb.polos-empresas; page Polos/EmpresasPorM).
- `npm run build` concluído sem erros — Vite 7, 5074 módulos transformados em 20.32s.

## Deviations from Plan

None — plano executado exatamente como escrito.

## Known Stubs

None — dados vêm diretamente do backend via props Inertia (mesmo pipeline de projetos()).

## Self-Check: PASSED

- [x] `routes/web.php` contém `polos-empresas`
- [x] `MlbController` contém `function polosEmpresas` e `Inertia::render('Polos/EmpresasPorM'`
- [x] `resources/js/Pages/Polos/EmpresasPorM.jsx` existe, exporta default, usa grid-cols-1/2/3 e card-ecf
- [x] `AppLayout.jsx` — grupo Polos contém `mlb.polos-empresas`; grupo Comercial contém `mlb.projetos`; grupo Polos NÃO contém mais `mlb.projetos`
- [x] `npm run build` sem erros
- [x] Commits 5b1f8c0 / 7d84ebd / 8b82478 criados
