# Phase 78: Comercial e aba Shopee — gerenciar serviço/responsáveis e revisar ações (revisa Phase 75) (v16.0) - Context

**Gathered:** 2026-07-14
**Status:** Ready for planning
**Source:** `.planning/milestones/v16.0-brief.md` + clarificação explícita do usuário (resolver pendência com selects escopados ao Setor Shopee) + estado atual do código (Fases 75/76/77).

<domain>
## Phase Boundary

Primeira fase com **UI visível** da v16.0. Fazer a aba `/shopee/empresas` (Phase 75) e o Comercial gerenciarem **responsáveis por serviço** (Shopee) de forma correta, listando **apenas profissionais do Setor Shopee**, e revisar as ações da aba conforme o spec v2.

**IN SCOPE:**
1. **Selects escopados ao Setor Shopee** (clarificação do usuário): na aba Shopee, o select de Analista lista **apenas analistas do Setor Shopee** (cargo `analista` no setor `shopee`) e o de Estrategista lista **apenas estrategistas do Setor Shopee** (cargo `estrategista` no setor `shopee`) — NÃO os cargos globais. Fonte: `user_setores` join `cargos` filtrando `setor_id`=Shopee.
2. **Botão "Resolver" na aba Pendências** → abre um **popup** para editar a empresa, cujas edições incluem: atribuir **Analista Shopee** (select escopado) e **Estrategista Shopee** (select escopado); e editar o **contato** (email do cliente) para limpar a pendência `sem_contato`. Ao preencher os obrigatórios, a pendência some.
3. **Remover o botão "Gerar NPS"** da aba (spec v2: o NPS passa a ser por modelo/disparo, Fase 79 — não gerar link avulso aqui).
4. **Ações da tabela:** abrir/ver detalhes, **Editar**, **Excluir** — onde **Excluir = cancelar/desativar SÓ o contrato de serviço Shopee** da empresa (não apagar a empresa nem outros serviços/ML).
5. **Comercial:** ao adicionar o serviço Shopee a uma empresa (fluxo `AtribuirServico` já existente) ou editar, permitir definir Analista/Estrategista Shopee (opcional; se faltar → empresa aparece pendente na aba Shopee). Manter os responsáveis ML inalterados.

**OUT (fases seguintes):** NPS multi-modelo + snapshot (79); reescrita do bônus + relatórios (80).
</domain>

<decisions>
## Implementation Decisions

### DEC-78-1 — Selects escopados ao Setor Shopee (clarificação LOCKED do usuário)
- Substituir, na aba Shopee, o `usersPorCargo('analista'/'estrategista')` GLOBAL (hoje em `ShopeeEmpresasController::index` :121-134) por consulta **escopada ao Setor Shopee**: users com `user_setores.setor_id` = (setor slug `shopee`) e `cargo` = analista / estrategista **daquele setor**. Se o Setor Shopee não existir (fallback defensivo), lista vazia + comentário.
- A escrita (`bulkAssign`) já é por-serviço (Fase 76, servico_id shopee) — manter. A atribuição grava `company_users` role consultor("Analista")/estrategista com `servico_id`=serviço Shopee.

### DEC-78-2 — Popup "Resolver pendência"
- Na aba **Pendências**, cada linha ganha botão **"Resolver"** que abre um popup (modal) de edição da empresa.
- Campos do popup: **Analista Shopee** (select DEC-78-1), **Estrategista Shopee** (select DEC-78-1), e **email do cliente** (`companies.email_cliente`) para resolver `sem_contato`. Opcional: marcar "empresa vista" (limpa `empresa_nova`) se admin.
- Salvar → grava responsáveis (bulkAssign por-serviço) + atualiza email; a(s) pendência(s) resolvida(s) some(m) da lista ao recarregar. Reaproveitar o modal/uso já existente em `Shopee/Empresas.jsx` (Phase 75) e o padrão do modal de `Companies/Index.jsx`.

### DEC-78-3 — Remover "Gerar NPS" da aba
- Remover o botão/ação "Gerar NPS" por linha de `Shopee/Empresas.jsx` (o deep-link `nps.generate`). O NPS Shopee passará pelo disparo por modelo (Fase 79). Não remover o motor NPS.

### DEC-78-4 — Excluir = cancelar só o serviço Shopee
- A ação **Excluir** na aba Shopee **cancela/desativa o contrato de serviço Shopee** da empresa (`contratos_servico.ativo=false` do contrato setor shopee), NÃO deleta a empresa. Se a empresa tiver outros serviços (ML), ela continua existindo e some só da aba Shopee. Reusar endpoint de contrato existente (`destroyContrato`/`updateContrato` em ComercialController/CompanyController — confirmar na pesquisa). Guard RBAC: `permission:shopee.empresas`; guard de escopo (só contratos shopee).

### DEC-78-5 — Comercial: responsáveis ao adicionar/editar serviço Shopee
- No fluxo `Comercial/AtribuirServico` (que já cria contrato Shopee em empresa existente), permitir selecionar Analista/Estrategista Shopee (selects escopados DEC-78-1) — opcional; se não definir, a empresa aparece pendente na aba Shopee. Gravar por-serviço (servico_id shopee). NÃO alterar responsáveis ML.

### Claude's Discretion
- Se o popup "Resolver" reusa o mesmo modal de "Editar" ou é um modal enxuto dedicado.
- Se o cancelamento do serviço usa `ativo=false` (soft) ou um status `cancelled` (o schema `contratos_servico` tem `ativo` boolean — usar isso).
- Ordenação/label dos selects.
- Se a edição de email fica no popup Resolver e/ou no Editar geral.
</decisions>

<constraints>
## Constraints
- **Testes em `tests/Feature/V16/`** (namespace Tests\Feature\V16).
- Frontend: tokens `ecf-*`, shadcn/ui, `cn()`; espelhar o modal de `Companies/Index.jsx` / `Shopee/Empresas.jsx`. `npm run build` ao final (gotcha: variáveis usadas dentro de `.map()` — ver memória `feedback_rollup_map_scope_bug`).
- RBAC: tudo gated por `permission:shopee.empresas` (nunca core.empresas); guards de escopo Shopee (anti-IDOR, herdar da Phase 75/76).
- Atribuição SEMPRE por-serviço (servico_id shopee) — não regredir para company-level.
- Dev em paralelo (anunciar-ml) — reconciliar antes de deploy; validar no VPS.
- pt-BR nos comentários.
</constraints>

<canonical_refs>
## Canonical References
- Aba Shopee (Phase 75): `app/Http/Controllers/ShopeeEmpresasController.php` — index (listas :121-144, pendências :109), bulkAssign por-serviço (:160-207, servico_id shopee :183-207, guard IDOR :168-172); `resources/js/Pages/Shopee/Empresas.jsx` (modal/atribuição, botão Gerar NPS a remover).
- Selects escopados: `user_setores`(2026_05_20_200004) join `cargos`(200003) por `setor_id`; padrão `CompanyController::usersPorCargo :206-222` (adaptar com filtro de setor); Setor Shopee criado na Phase 77 (slug `shopee`, cargos analista/estrategista).
- Contrato/serviço (excluir=cancelar): `ContratoServico` (`ativo` bool, scopeActive); `ComercialController` updateContrato/destroyContrato (:760-806) ; `CompanyController::storeContrato` (rota `empresas.contratos.store`, web.php:645); `Comercial/AtribuirServico.jsx`.
- Atribuição por-serviço (Fase 76): `Company::consultorDoServico()/estrategistaDoServico()`; `company_users.servico_id`.
- Rotas Shopee: `routes/web.php` grupo `permission:shopee.empresas` (:509-513) — adicionar rotas de resolver/editar/excluir-serviço se preciso.
- Pendências DEC-2 (Phase 75): `sem_responsavel` / `sem_contato` (email_cliente E digisac vazios) / `empresa_nova`.
</canonical_refs>

<validation>
## Validation Architecture (Nyquist)
Feature tests em `tests/Feature/V16/`:
1. Selects escopados: `index` retorna em `analistas`/`estrategistas` SOMENTE users com cargo analista/estrategista no Setor Shopee (não os de outros setores). Cenário: user X analista Shopee aparece; user Y analista só do Performance NÃO aparece.
2. Resolver pendência: POST do resolver atribui analista/estrategista Shopee (company_users servico_id shopee) e atualiza email_cliente → pendências `sem_responsavel`/`sem_contato` somem no index seguinte.
3. Excluir serviço: a ação cancela só o contrato shopee (`ativo=false`), a empresa e o contrato ML permanecem; empresa some da aba Shopee mas continua na aba ML.
4. Gerar NPS removido: `Shopee/Empresas.jsx` não referencia `nps.generate` (grep) — build verde.
5. RBAC: resolver/excluir gated por `shopee.empresas`; guard de escopo (empresa fora do escopo shopee → 403/422).
6. Comercial: adicionar serviço Shopee com responsáveis grava por-serviço sem tocar ML.
</validation>

<deferred>
## Deferred
- NPS multi-modelo/snapshot/atribuição → Phase 79.
- Bônus/relatórios por serviço → Phase 80.
</deferred>

---
*Phase: 78 — v16.0 (v2)*
