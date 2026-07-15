# Phase 81: NPS config UX — duplicar/excluir modelo + modal gerar-link multi-step (v16.0) - Context

**Gathered:** 2026-07-14
**Status:** Ready for planning
**Source:** Ajustes do usuário (mensagens de 2026-07-14) + estado do NPS multi-modelo (Fase 79).

<domain>
## Phase Boundary

Melhorias de configuração/UX do NPS que o multi-modelo (Fase 79) tornou necessárias. **Precisam ficar prontas ANTES do deploy da Fase 79** (decisão do usuário — deploy do pacote NPS junto).

**IN SCOPE (3 features):**
1. **Duplicar modelo** — na tela `/nps/configuracao`, ação "Duplicar" que clona um modelo de NPS (nome + perguntas + opções + serviços cobertos) num novo modelo (`is_default=false`). Uso principal: duplicar o 'NPS | Performance ECF...' → trocar o serviço coberto pra Shopee (complementa o seed 79-02; dá controle ao admin).
2. **Excluir modelo** — ação "Excluir" um modelo de NPS (com guardas: não excluir o `is_default`/principal; tratar surveys/respostas que referenciam o modelo).
3. **Modal "Gerar link" multi-step** — no `/nps` (Nps/Index.jsx), o modal "Gerar Link NPS" passa a ter o **select de MODELO primeiro**; ao escolher um modelo, o select de **empresa lista SÓ empresas com serviço ativo coberto por aquele modelo** (serviços cobertos ∩ contratos ativos). Pode ser multi-step. Ex.: modelo Shopee → só empresas com Gestão de ADS Shopee.

**OUT:** motor de disparo/snapshot (Fase 79, já feito); bônus (Fase 80).
</domain>

<decisions>
## Implementation Decisions

### DEC-81-1 — Duplicar modelo
- Backend: `NpsTemplateController::duplicate(NpsTemplate)` (ou método análogo) que, em transação, cria um novo template copiando os campos de config (nome = "{nome} (cópia)" editável, active, envio_automatico_mensal, priority; **is_default=false SEMPRE**) + clona todas as perguntas (texto/tipo/dimensao/obrigatoria/ordem) + opções (label/peso/ordem) + os `nps_template_service_scopes`. Rota + gate igual ao CRUD de templates existente (`sistema.*`/admin — confirmar na pesquisa).
- Frontend: botão "Duplicar" por modelo na config; após duplicar, abre o editor do novo modelo (ou recarrega a lista).

### DEC-81-2 — Excluir modelo
- Backend: `NpsTemplateController::destroy(NpsTemplate)`. **Guardas:** NUNCA excluir o `is_default=true` (retorna erro claro — trocar o principal antes). Definir o tratamento de surveys/respostas que apontam pro template: preferir preservar histórico — `nps_surveys.template_id` e `nps_responses`/snapshots devem sobreviver (o snapshot da Fase 79 já congela os dados; então FK `nullOnDelete` ou bloquear a exclusão se houver respostas). Confirmar o comportamento das FKs existentes na pesquisa e escolher a opção segura (preservar histórico).
- Frontend: botão "Excluir" + confirmação; feedback claro se bloqueado (is_default / tem respostas).

### DEC-81-3 — Modal gerar-link multi-step
- Reordenar o modal `Nps/Index.jsx` (~:1109-1153): **passo 1 = escolher o MODELO** (obrigatório agora, não mais "__auto__"); **passo 2 = escolher a EMPRESA** — lista filtrada pelos serviços cobertos do modelo ∩ contratos ativos da empresa.
- Backend: endpoint que retorna as empresas elegíveis para um `template_id` (empresas com contrato ativo de um serviço em `nps_template_service_scopes` do template), OU enriquecer o payload da página com o mapa modelo→empresas elegíveis. Preferir endpoint dedicado (`GET /nps/templates/{template}/empresas-elegiveis`) para não inflar o payload inicial.
- O `NpsController::generate` já aceita `template_id` — manter; a mudança é de UX (ordem + filtro) e do endpoint de empresas elegíveis. Reusar a lógica de "serviços cobertos ∩ ativos" da Fase 79 (`NpsTemplateService`/service_scopes).

### Claude's Discretion
- Se o modal vira wizard de 2 passos ou só reordena os selects com filtro reativo.
- Nome exato dos métodos/rotas.
- Excluir: bloquear-se-tem-respostas vs nullOnDelete (escolher preservando histórico).
</decisions>

<constraints>
## Constraints
- **Testes em `tests/Feature/V16/`**.
- Reusar padrões: `NpsTemplateController` (CRUD existente Phase 70), `Nps/Index.jsx` (modal), `nps_template_service_scopes`, `NpsTemplateService`. Tokens ecf-*, shadcn/ui, `cn()`; `npm run build` (gotcha var em `.map()`).
- Preservar histórico do NPS (snapshots da Fase 79 são imutáveis). Gate RBAC igual ao CRUD de templates atual.
- Dev em paralelo (anunciar-ml) — reconciliar antes de deploy. pt-BR.
</constraints>

<canonical_refs>
## Canonical References
- Config NPS (Phase 70): `app/Http/Controllers/NpsTemplateController.php` (store/update/toggleActive/setPrincipal/syncScopes — molde para duplicate/destroy), `resources/js/Pages/Nps/Configuracao.jsx`. Rotas `nps.configuracao.templates.*`.
- Modal gerar-link: `resources/js/Pages/Nps/Index.jsx:1109-1153` (form company_id/template_id, submit `nps.generate`), `NpsController::generate :353-412` (aceita template_id).
- Serviços cobertos: `nps_template_service_scopes`; `NpsTemplateService::resolveForCompany :70-112`; a lógica de "cobertos ∩ ativos" da Fase 79 (`NpsDispararMensal` reescrito + `NpsSnapshotService`).
- `NpsTemplate` model (relations questions/options/serviceScopes).
</canonical_refs>

<validation>
## Validation Architecture (Nyquist)
Feature tests em `tests/Feature/V16/`:
1. Duplicar: POST duplicate → novo template is_default=false com perguntas/opções/scopes clonados; original intocado.
2. Excluir: destroy de um modelo não-principal funciona (histórico preservado); destroy do is_default é bloqueado; destroy de modelo com respostas segue a regra escolhida (preservar histórico).
3. Empresas elegíveis: endpoint retorna só empresas com serviço ativo coberto pelo template (modelo Shopee → só empresas com Gestão de ADS Shopee; modelo performance → empresas ML; modelo sem cobertura → vazio).
4. Frontend: build verde; modal com modelo-first + filtro (checkpoint visual).
5. Regressão: CRUD de templates + gerar-link atuais não quebram.
</validation>

<deferred>
## Deferred (Fase 80)
- Bônus lê `nps_score_assignments` somando TODAS as atribuições da pessoa (ML + Shopee) — Ajuste 3 do usuário (Gustavo).
</deferred>

---
*Phase: 81 — v16.0 (v2)*
