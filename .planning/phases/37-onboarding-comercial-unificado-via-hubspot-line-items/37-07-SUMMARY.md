---
phase: 37-onboarding-comercial-unificado-via-hubspot-line-items
plan: 07
subsystem: ui
tags: [admin, ui, mapping, sidebar, reorg, hubspot, inertia, react, laravel]

# Dependency graph
requires:
  - phase: 37-02
    provides: model HubspotLineItemMapping + tabela hubspot_line_item_mapping
  - phase: 37-04
    provides: HubspotWebhookController consumindo paraNome()
  - phase: 37-05
    provides: rota comercial.empresas.listagem (apontada pelo sub-item Grupos)
provides:
  - CRUD admin de HubspotLineItemMapping (4 rotas /sistema/hubspot-line-items.*)
  - Inertia page Sistema/HubspotLineItems com modal create/edit/delete + busca client-side
  - Sidebar consolidada do setor Comercial (5 sub-itens, Serviços movido do raiz)
  - Sub-item HubSpot Line Items visivel apenas a role:admin (excludeRoles)
affects:
  - phase-38+: futuras adicoes ao grupo Comercial seguem o pattern de sub-item agrupado
  - operacao-comercial: cadastro de novos mappings sem deploy (REQ-37-02 conclusao)

# Tech tracking
tech-stack:
  added: []  # nenhuma dependencia nova — usa Radix Dialog/Table ja existentes
  patterns:
    - "Controller em namespace Sistema\\ (1o do projeto) para CRUD admin sem prefixo /admin"
    - "Activity log via spatie/laravel-activitylog em log_name='sistema' com descricao pt-BR"
    - "excludeRoles na sidebar para gate admin-only sem criar permission_key nova"
    - "Rule::exists com where('ativo', true) garante mapping aponta para Servico ativo"
    - "Rule::unique->ignore($id) no update para nao bloquear PUT que preserva line_item_name"

key-files:
  created:
    - app/Http/Controllers/Sistema/HubspotLineItemMappingController.php
    - resources/js/Pages/Sistema/HubspotLineItems.jsx
    - tests/Feature/Phase37HubspotLineItemMappingAdminTest.php
    - .planning/phases/37-onboarding-comercial-unificado-via-hubspot-line-items/37-07-SUMMARY.md
  modified:
    - routes/web.php
    - resources/js/Layouts/AppLayout.jsx

key-decisions:
  - "Hard delete em destroy() (não soft via ativo=false) — admin sabe o que faz; activity_log preserva o nome removido para auditoria"
  - "Props snake_case (mappings, servicos_disponiveis) — convenção Phase 37 mantida"
  - "excludeRoles em vez de permission_key dedicada — evita poluir catálogo de permissões com chave de uso restrito"
  - "Sub-item 'Grupos' aponta para listagem unificada (mesma URL do 'Empresas todos os setores'); helper de menu não suporta query param na rota nomeada — TODO refinar para ?tab=grupos quando suportar"
  - "Defesa em profundidade SEM abort_unless redundante no controller — middleware role:admin é a porta primária; duplicar checagem seria ruído"
  - "Native HTML select no modal (não shadcn Select) — universo de serviços é pequeno (~10) e padrão Servicos/Index.jsx usa native select"
  - "Modal de confirmacao de delete separado do modal de edicao — UX clara, evita dupla acao acidental"

patterns-established:
  - "Namespace Sistema\\ para CRUD admin de catalogos de baixo trafego (mappings, lookups)"
  - "Activity log channel 'sistema' para mudancas em metadados infraestruturais"
  - "Grupo Comercial como home de TUDO que pertence ao setor Comercial (cadastros, listagens, catalogos, mappings)"

requirements-completed:
  - REQ-37-02
  - REQ-37-09

# Metrics
duration: 28min
completed: 2026-06-18
---

# Phase 37 Plan 37-07: UI admin HubSpot Line Items + Reorg final da sidebar Comercial

**CRUD admin completo de hubspot_line_item_mapping via /sistema/hubspot-line-items + sidebar consolidada do setor Comercial com 5 sub-itens (Serviços e Grupos migrados do raiz, HubSpot Line Items novo).**

## Performance

- **Duration:** ~28 min
- **Started:** 2026-06-18T21:15:00Z
- **Completed:** 2026-06-18T21:43:00Z
- **Tasks:** 4 (3 de implementação + 1 suíte de testes TDD)
- **Files modified:** 4 (2 criados + 2 modificados; +1 teste novo + SUMMARY)

## Accomplishments

- Admin agora cadastra/edita/desativa/remove mapeamentos HubSpot line_item → Serviço pela UI em /sistema/hubspot-line-items SEM precisar de deploy quando o Comercial cria nomes novos no HubSpot — fecha REQ-37-02.
- Sidebar consolidada: setor Comercial concentra Empresas (todos setores), Cadastrar empresa, Grupos, Serviços e HubSpot Line Items num único grupo expansível; Serviços não polui mais o nível raiz — fecha REQ-37-09.
- Suíte Phase37HubspotLineItemMappingAdminTest com 13 testes cobrindo render Inertia, CRUD completo, validações (unique line_item_name + servico_id ativo), activity_log em pt-BR e autorização role:admin nas 4 rotas — todos verdes.
- Phase 37 inteira pronta para deploy AGRUPADO (Plans 37-01..07): zero regressão Phase 34/35/36/37 confirmada (124/124 testes — 607 assertions).

## Task Commits

Cada task commitada atomicamente (com ciclo TDD RED/GREEN no Task 1):

1. **Task 4 (RED): Suíte Phase37HubspotLineItemMappingAdminTest** — `364b99b` (test)
   13 testes criados antes do controller — todos falham com 404 (rotas inexistentes).
2. **Task 1 (GREEN): HubspotLineItemMappingController + 4 rotas admin** — `03409fe` (feat)
   Controller no namespace Sistema\ + rotas dentro do grupo role:admin existente. 11/13 testes verdes (2 restantes falham por Vite manifest ainda sem o JSX).
3. **Task 2: Sistema/HubspotLineItems.jsx — UI admin com modal Dialog** — `94a1aec` (feat)
   Página React renderizada por /sistema/hubspot-line-items. GREEN total: 13/13 testes verdes (45 assertions).
4. **Task 3: AppLayout sidebar reorg — REQ-37-09** — `67701cc` (feat)
   Item "Serviços" removido do raiz; grupo Comercial expandido com 3 sub-itens novos (Grupos, Serviços, HubSpot Line Items). Estrutura final: 5 sub-itens.

**Plan metadata (este SUMMARY + state):** próximo commit `docs(37-07)`.

_Nota: Plan 37-07 não roda como `type: tdd` no frontmatter, mas Task 1 seguiu RED→GREEN explícito; demais tasks são incrementos UI/sidebar onde TDD não se aplica natural._

## Files Created/Modified

- `app/Http/Controllers/Sistema/HubspotLineItemMappingController.php` (NEW, 120 linhas) — CRUD admin do mapping; index() retorna Inertia 'Sistema/HubspotLineItems' com mappings (servico embedado) + servicos_disponiveis; store/update validam unique line_item_name + Rule::exists('servicos','id')->where('ativo', true); update usa Rule::unique->ignore($mapping->id); destroy preserva nome para activity_log antes do delete.
- `resources/js/Pages/Sistema/HubspotLineItems.jsx` (NEW, 318 linhas) — Tabela com 6 colunas (Nome HubSpot mono, Serviço, Setor badge, Ativo pill, Observações truncate, Ações), busca client-side por nome/serviço, modal Dialog para create/edit (Input + native select + Textarea + checkbox accent-ecf-yellow), modal separado de confirmação de delete com cor red-600, dark theme tokens ecf-*.
- `tests/Feature/Phase37HubspotLineItemMappingAdminTest.php` (NEW, 336 linhas) — 13 testes; helpers actingAsAdmin/actingAsConsultor/criarServico/criarMapping; cobre render Inertia + CRUD + validações + activity_log + autorização nas 4 rotas (consultor → 403 em GET/POST/PUT/DELETE).
- `routes/web.php` — 4 rotas novas dentro do grupo role:admin: GET/POST /sistema/hubspot-line-items + PUT/DELETE /sistema/hubspot-line-items/{mapping}; import `use App\Http\Controllers\Sistema\HubspotLineItemMappingController` adicionado.
- `resources/js/Layouts/AppLayout.jsx` — Item "Serviços" removido do nível raiz (estava entre Reuniões e Usuários); grupo Comercial agora com 5 sub-itens (Grupos com ListChecks, Serviços movido, HubSpot Line Items com Link2 + excludeRoles).

## Decisions Made

- **Hard delete em destroy()** (não soft via `ativo=false`): admin sabe o que faz; activity_log com nome capturado antes do delete preserva auditoria. Soft-deactivate é redundante quando já existe `ativo` editável via UI.
- **Props snake_case** (`mappings`, `servicos_disponiveis`): convenção Phase 37 (mesmo padrão de Comercial/EmpresasListagem e companies/Index).
- **excludeRoles em vez de permission_key dedicada** para o sub-item "HubSpot Line Items": evita poluir catálogo de permissões com chave de uso restrito (admin only). Defesa em profundidade via middleware `role:admin` no grupo de rotas.
- **Sub-item 'Grupos' aponta para listagem unificada** (mesma URL de "Empresas todos os setores"): o helper de menu atual do AppLayout não suporta query param na rota nomeada — `routeName: 'comercial.empresas.listagem'` sem query. Usuário clica na aba "Grupos" ao chegar. TODO marcado nos comentários para refinar quando o helper aceitar `query: { tab: 'grupos' }`.
- **Sem `abort_unless('admin')` redundante no controller**: middleware `role:admin` é a porta primária; duplicar checagem seria ruído de código.
- **Native HTML `<select>` no modal** (não shadcn Select): universo de serviços é pequeno (~10), padrão Servicos/Index.jsx já usa native select, evita import extra.
- **Modal de confirmação de delete separado** do modal de edição: UX clara, evita dupla ação acidental e permite cor red-600 no botão Remover sem poluir o modal de edição.

## Deviations from Plan

**None — plano executado exatamente como escrito.**

O plano original previa `type="checkpoint:human-verify"` ao final (validação visual da sidebar pelo usuário). Esse checkpoint foi PRÉ-APROVADO pelo orquestrador antes do executor rodar (autorização permanente de execução do usuário). Documentado aqui para rastreabilidade.

## Issues Encountered

Nenhum — todas as 3 tarefas de implementação rodaram sem retrabalho:
- Task 1 (TDD): RED confirmado (13/13 falhas com 404 → 11/13 verdes pós-controller, 2 restantes apenas por Vite manifest faltando o JSX da Task 2).
- Task 2 (JSX): build verde de primeira; Vite manifest populado; 13/13 testes verdes.
- Task 3 (sidebar): primeiro `Edit` falhou por discrepância na palavra "completa" vs "unificada" no comentário do Plan 37-05 — re-li o arquivo e fiz o replace com o texto exato. Build verde de primeira após o edit.

## User Setup Required

Nenhum — sem configuração externa. Após deploy agrupado:
1. Admin pode acessar `/sistema/hubspot-line-items` direto pela sidebar (grupo Comercial > HubSpot Line Items).
2. Mapeamentos pré-existentes seedados pelo Plan 37-02 (MAP, MAP PREMIUM, Polo, POLO, Brigada, Gestão, Mentoria, Publicação) aparecem na listagem.
3. Novos line items vindos do HubSpot que não tenham mapping ativo cairão como "servico_nao_reconhecido" na listagem `/comercial/empresas/listagem` (Plan 37-05) — admin então cadastra o mapping na nova UI sem precisar de deploy.

## Threat Flags

Nenhum surface novo introduzido fora do `<threat_model>` do plan:
- T-37-17 (Elevation of Privilege /sistema/hubspot-line-items) — mitigado via grupo `role:admin` + `excludeRoles` na sidebar. Tests test_acesso_admin_apenas_* validam 403 em todos os 4 endpoints para consultor.
- T-37-18 (Tampering mapping malicioso) — aceito; activity log via spatie registra each ação `causedBy admin`.
- T-37-19 (Repudiation) — mitigado; activity_log com timestamp + causer_id + properties (`line_item_name`, `servico_id`) preserva autoria.

## Self-Check

Files exist:
- `app/Http/Controllers/Sistema/HubspotLineItemMappingController.php` — FOUND
- `resources/js/Pages/Sistema/HubspotLineItems.jsx` — FOUND
- `tests/Feature/Phase37HubspotLineItemMappingAdminTest.php` — FOUND
- `.planning/phases/37-onboarding-comercial-unificado-via-hubspot-line-items/37-07-SUMMARY.md` — FOUND (this file)

Commits exist (via `git log --oneline`):
- `364b99b` test(37-07): RED — FOUND
- `03409fe` feat(37-07): GREEN controller + routes — FOUND
- `94a1aec` feat(37-07): JSX page — FOUND
- `67701cc` feat(37-07): sidebar reorg — FOUND

Tests pass: 13/13 `Phase37HubspotLineItemMappingAdminTest` (45 assertions); regressão Phase 34/35/36/37 124/124 (607 assertions).
Build clean: `npm run build` produziu manifest com `Sistema/HubspotLineItems`.

## Self-Check: PASSED

## Next Phase Readiness

- **Phase 37 fechada** (7 plans em 3 waves). Pronta para `/gsd:complete-phase 37` + `/gsd:complete-milestone` (decisão do usuário). Deploy AGRUPADO recomendado: Plans 37-01 (schema servicos.setor) + 37-02 (HubspotLineItemMapping schema+seed) + 37-03 (HubspotEvento.company_id_criada) + 37-04 (HubspotWebhookController processando line_items) + 37-05 (Comercial/EmpresasListagem.jsx) + 37-06 (Companies/Index foca Performance) + 37-07 (UI admin + sidebar) devem ser deployados juntos.
- **Sem blockers**. Phase 37 entrega completa do REQ-37-02/05/06/08/09/10 conforme `<must_haves>` de cada plan.
- **Migração de hábito operacional pós-deploy**: time Comercial passa a usar `/comercial/empresas/listagem` (todos os setores) como porta de entrada; `/companies` vira específico de Performance; novos line items do HubSpot são cadastrados na nova UI admin pelo time de dev/admin sem deploy.

---
*Phase: 37-onboarding-comercial-unificado-via-hubspot-line-items*
*Plan: 07*
*Completed: 2026-06-18*
