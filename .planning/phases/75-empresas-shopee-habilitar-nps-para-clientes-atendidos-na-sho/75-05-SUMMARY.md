---
phase: 75-empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho
plan: 05
subsystem: ui
tags: [react, inertia, nav-tree, rbac, nps, shopee]

# Dependency graph
requires:
  - phase: 75-04
    provides: "ShopeeEmpresasController@index/bulkAssign + rotas shopee.empresas.* gated por permission:shopee.empresas"
  - phase: 75-02
    provides: "permission key shopee.empresas no catálogo estático + contrato de serviço setor 'shopee'"
provides:
  - "Página resources/js/Pages/Shopee/Empresas.jsx (versão enxuta, ML-free): abas Todas/Pendências, badges de pendência, atribuição de responsáveis, botão Gerar NPS por linha"
  - "Grupo 'Shopee' real no NAV_TREE (AppLayout.jsx) com filho 'Empresas' gated por shopee.empresas + Dashboard stub 'Em breve'"
affects: [dashboard-shopee-futuro, nps]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Página enxuta espelhando Companies/Index.jsx sem métrica/cust_id/grant — molde para futuras abas de marketplace sem API"
    - "Gerar NPS por linha via useForm.transform() + post(nps.generate), capturando flash.nps_link em useEffect (evita setData assíncrono)"
    - "Stub de topo → grupo NAV_TREE com children (espelha grupo Mercado Livre); gate por permission no filho, não no grupo"

key-files:
  created:
    - resources/js/Pages/Shopee/Empresas.jsx
  modified:
    - resources/js/Layouts/AppLayout.jsx

key-decisions:
  - "Gerar NPS usa useForm.transform() para injetar company_id no submit (evita bug de setData assíncrono no clique por linha)"
  - "Atribuição de responsáveis via seleção + bulk-assign disponível nas DUAS abas (Todas e Pendências), não só em Pendências — atribuir responsável é ação central da aba, não exclusiva de pendência"
  - "Modal de link ML OAuth do molde foi descartado (empresa Shopee é ML-free); só o modal de link NPS foi mantido"

patterns-established:
  - "Marketplace sem API: aba enxuta reutiliza tabela/abas/pendências do molde ML mas remove toda coluna de métrica/token/grant"

requirements-completed: [DEC-3, DEC-4, DEC-5]

# Metrics
duration: ~20min
completed: 2026-07-14
---

# Phase 75 Plan 05: UI Empresas Shopee (menu + página + Gerar NPS) Summary

**Página React enxuta `Shopee/Empresas.jsx` (abas Todas/Pendências, atribuição de Analista/Estrategista via `shopee.empresas.bulk-assign` e botão Gerar NPS por linha reaproveitando `nps.generate`) + transformação do stub "Shopee — Em breve" num grupo real do NAV_TREE com filho "Empresas" gated por `shopee.empresas`.**

## Performance

- **Duration:** ~20 min
- **Tasks:** 2 de código executadas + 1 checkpoint visual pendente (Tarefa 3)
- **Files modified:** 2 (1 criado, 1 modificado)

## Accomplishments
- Nova página `resources/js/Pages/Shopee/Empresas.jsx` (versão ML-free do `Companies/Index.jsx`): abas Todas/Pendências, cards+filtro de pendências, dicionário DEC-2 (sem_responsavel/sem_contato/empresa_nova), seleção em massa e atribuição de Analista/Estrategista postando em `route('shopee.empresas.bulk-assign')`, e botão "Gerar NPS" por linha via `route('nps.generate')` com captura de `flash.nps_link` num modal de link.
- Stub de topo "Shopee — Em breve" convertido num grupo real do `NAV_TREE` (espelhando "Mercado Livre"): filho "Empresas" (`shopee.empresas.index` / `Shopee/Empresas`) gated por `permission:shopee.empresas`, mais o Dashboard stub `badgeText:'Em breve'` como segundo filho. `itemVisivel()` (já existente) esconde "Empresas" de quem não tem a key; o grupo some se nenhum filho for visível.
- `npm run build` verde (sem erro de bundle / sem ReferenceError de escopo em `.map()`).

## Task Commits

1. **Tarefa 1: Página Shopee/Empresas.jsx (versão enxuta)** - `386031b` (feat)
2. **Tarefa 2: Transformar stub Shopee em grupo real no NAV_TREE** - `aaae910` (feat)
3. **Tarefa 3: Verificação visual** — checkpoint humano PENDENTE (ver abaixo)

## Files Created/Modified
- `resources/js/Pages/Shopee/Empresas.jsx` - Página enxuta da aba Empresas Shopee (abas, pendências, atribuição, Gerar NPS)
- `resources/js/Layouts/AppLayout.jsx` - Grupo "Shopee" no NAV_TREE com filho "Empresas" gated + Dashboard stub

## Decisions Made
- **Gerar NPS por linha via `useForm.transform()`:** o clique setar `setData` seria assíncrono e o `post` enviaria o `company_id` anterior. `transform(() => ({ company_id: company.id, template_id: '' }))` injeta o valor certo no submit síncrono. Atende o key_link `nps.generate` via `useForm.post`.
- **Atribuição nas duas abas:** a barra de bulk-assign aparece tanto em "Todas" quanto em "Pendências" (atribuir responsável é ação central, não exclusiva de pendência). Seleção é limpa ao trocar de aba.
- **Descartado o fluxo ML OAuth** do molde (a empresa Shopee é ML-free): removidos botão/modal de link ML, colunas de métrica/cust_id/grant, edição e exclusão de empresa (não há rota `shopee.empresas.update`/`destroy`).

## Deviations from Plan
None - plan executado conforme escrito. Ajuste cosmético: um comentário que continha a string literal `companies.bulk-assign` foi reescrito para manter limpa a asserção de grep do threat model (T-75-15 — nenhuma referência a `companies.*` na página). Sem impacto funcional.

## Threat Surface
- Defesa em profundidade confirmada (T-75-14): `itemVisivel()` gate o filho "Empresas" por `permission:shopee.empresas` no frontend; o backend (`EnsurePermission` nas rotas `shopee.empresas.*`, Plan 04) é a fonte de verdade.
- T-75-15: a página usa exclusivamente `route('shopee.empresas.bulk-assign')` e `route('nps.generate')`; grep de `companies.bulk-assign|companies.update` retorna vazio.

## Issues Encountered
None.

## Checkpoint Visual PENDENTE (Tarefa 3 — validação humana)

Todo o trabalho de código + build está concluído. O usuário/orquestrador deve validar visualmente:

1. **Pré-requisito manual:** Em `/setores`, criar/editar o Setor Shopee e conceder a key `shopee.empresas`; adicionar um usuário de teste a esse setor.
2. **Como admin:** o grupo "Shopee" aparece na sidebar com os filhos "Empresas" e "Dashboard (Em breve)".
3. **Abrir "Empresas":** listagem das empresas com contrato Shopee, abas Todas/Pendências, e badges (Sem responsável / Sem contato / Empresa nova) coerentes.
4. **Atribuir** Analista + Estrategista a uma empresa (seleção → bulk-assign) → confirmar persistência ao recarregar.
5. **Clicar "Gerar NPS"** numa empresa com estrategista + contato → confirmar que o link NPS é gerado (modal) e copia corretamente.
6. **RBAC:** logar como usuário do Setor Shopee → vê "Empresas"; logar como usuário SEM a key → grupo/item NÃO aparece e `GET /shopee/empresas` retorna 403.

**Sinal de retomada:** "aprovado" ou descrição dos problemas.

## Next Phase Readiness
- Camada de apresentação da aba Empresas Shopee completa e conectada ao backend do Plan 04. Pronto para a validação visual final; nenhuma dependência de código bloqueada.
- Dashboard Shopee permanece stub (`Dashboard/ShopeeShell`, "Em breve") — deferido para milestone futura com Open Platform.

---
*Phase: 75-empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho*
*Completed: 2026-07-14*

## Self-Check: PASSED

- Arquivos: Shopee/Empresas.jsx, AppLayout.jsx, 75-05-SUMMARY.md OK
- Commits: 386031b, aaae910 OK
