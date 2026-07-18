---
phase: 93-menu-grupo-transversal-gestao-ecf-v17-0
plan: 01
subsystem: frontend-menu
tags: [menu, sidebar, nav, reorganizacao]
requires: []
provides:
  - "NAV_TREE com grupo transversal 'Gestão ECF' (Carteira, Desempenho, Metas)"
affects:
  - "resources/js/Layouts/AppLayout.jsx"
tech-stack:
  added: []
  patterns:
    - "Reordenamento puro de array de navegação (sem tocar lógica de gating/isActive/openGroups)"
key-files:
  created: []
  modified:
    - "resources/js/Layouts/AppLayout.jsx"
decisions:
  - "D-93-01/02/03/04 do plano aplicadas literalmente: posição, ícone FolderKanban, defaultOpen true, ordem Carteira→Desempenho→Metas"
  - "Empresas permanece no grupo Mercado Livre (pergunta em aberto do plano — não decidida nesta execução; fica para o checkpoint visual)"
metrics:
  duration: "~10min"
  completed: "2026-07-17"
---

# Phase 93 Plan 01: Grupo transversal "Gestão ECF" Summary

Reorganização do array `NAV_TREE` em `AppLayout.jsx`: criado grupo transversal "Gestão ECF"
(ícone `FolderKanban`, `defaultOpen: true`) posicionado entre o item de topo "ECF Dashboard" e
o grupo "Mercado Livre", contendo Carteira, Desempenho e Metas — movidos de dentro do grupo
Mercado Livre, que agora mantém apenas Dashboard, Empresas, Sugadores, PPA + seções Dados
Estratégicos e Polos.

## O que foi feito

- **Task 1 (auto)** — commit `ec8bb15`: reorganização pura do array `NAV_TREE`.
  - Removidos do grupo `Mercado Livre`: itens `Desempenho` (`performance.index`), `Carteira`
    (`portfolio.own`) e `Metas` (`goals.index`), junto com o bloco de comentários antigo do
    Desempenho (substituído por comentário explicando a Phase 93).
  - Inserido novo grupo `{ group: 'Gestão ECF', icon: FolderKanban, defaultOpen: true, children: [...] }`
    entre `ECF Dashboard` e `Mercado Livre`, com os 3 itens movidos **exatamente como estavam**
    (mesmo `permission`, mesmo `page`, mesmo `routeName`, mesmo `icon`).
  - `Desempenho` preservou o array literal de 3 páginas
    `['Performance/Index', 'Performance/Show', 'Desempenho/Configuracao']` — nenhuma simplificação.
  - `FolderKanban` já estava importado (linha 7 de `lucide-react`) — nenhum import novo adicionado.
  - Nenhuma alteração em `itemVisivel`, `filteredTree`, `openGroups`, `isActive` — lógica de
    gating e highlight intocada, é 100% genérica e cobre o novo grupo automaticamente.
  - Grupo `Mercado Livre`: manteve Empresas (decisão do plano canônico — não movida) na ordem
    Dashboard → Empresas → Sugadores → PPA → divider Dados Estratégicos → seção → divider Polos → seção.

- **Task 2 (checkpoint:human-verify)** — **NÃO executada**, conforme constraint do prompt.
  O código está pronto para o checkpoint visual (ver seção abaixo).

## Verificação automatizada (gates do plano)

- `npm run build` — **exit 0** (warnings pré-existentes de dependências de terceiros —
  `duration-[900ms]` ambíguo no Tailwind e `/*#__PURE__*/` do `@glideapps/*` — não relacionados
  a esta mudança).
- `grep -c "group: 'Gestão ECF'"` → `1` (exatamente um grupo criado).
- `grep -v '^ *//' ... | grep -c "routeName: 'performance.index'"` → `1` (item movido, não duplicado).
- `git status --short` confirmado: **apenas** `resources/js/Layouts/AppLayout.jsx` modificado;
  nenhum arquivo NPS (`NpsController`, `Nps*.jsx`, `nps_*`) staged — sessão paralela das Fases
  94/95/96 não foi tocada.
- Diff revisado manualmente linha a linha antes do commit: reordenamento puro, nenhuma rota,
  controller, permissão ou lógica de componente alterada.

## Deviations from Plan

None — plano executado exatamente como escrito. Comentários de seção adicionados em pt-BR
conforme instrução da Task 1 (item 4).

## Auth gates

Nenhum.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum novo. Reordenamento client-side puro; `permission`/`excludeRoles` preservados exatos —
não altera quem vê o quê (gating idêntico), apenas onde o item aparece visualmente. Autorização
real continua no backend (inalterado nesta fase).

## Commits

| Task | Tipo | Hash | Descrição |
|------|------|------|-----------|
| 1 | feat | `ec8bb15` | grupo transversal Gestão ECF (Carteira/Desempenho/Metas) |

## Próximo passo — Checkpoint visual pendente

A Task 2 (`checkpoint:human-verify`, gate `blocking`) do plano **não foi executada** por
constraint explícita desta execução. Está pronta para verificação humana:

1. `npm run dev` (ou ambiente já servido) → login como **admin**.
2. Confirmar ordem na sidebar: ECF Dashboard → **Gestão ECF** (aberto) → Mercado Livre → Shopee.
3. Expandir "Gestão ECF": Carteira, Desempenho, Metas (nessa ordem).
4. Expandir "Mercado Livre": confirmar ausência de Carteira/Desempenho/Metas.
5. Clicar em "Desempenho" → highlight correto (não engole Dashboard do ML); navegar
   Performance/Index e Performance/Show — highlight permanece em Desempenho.
6. Login como analista/estrategista → confirmar Carteira e Desempenho visíveis em Gestão ECF.
7. Confirmar Shopee idêntico.
8. Decisão em aberto D-93: manter Empresas no Mercado Livre ou mover para Gestão ECF numa
   fase futura (fora do escopo travado desta fase).

## Self-Check: PASSED

- FOUND: `resources/js/Layouts/AppLayout.jsx` (modificado, existe).
- FOUND: commit `ec8bb15` (`git log --oneline --all | grep ec8bb15` confirma).
- FOUND: `npm run build` executado com exit 0 nesta sessão.
