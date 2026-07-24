# Phase 113: Enriquecimento de contato/empresa + escolha de contato principal + dedup - Context

**Gathered:** 2026-07-24
**Status:** Ready for planning
**Source:** Plano canônico `prompt-claude-otimizacao-comercial-hubspot.md` (Fases 5 e 7) — milestone v20.0. Depende de 111 (colunas + métodos batch do client) e 112 (handoff service + DTO).

<domain>
## Phase Boundary

O webhook deixa de tratar HubSpot de forma pobre. Entrega:
1. **Todos os contatos + contato principal determinístico** — troca `fetchAssociatedContactId`/`fetchContact` (singular) por `fetchAssociatedContactIds`/`fetchContacts` (batch, já existem desde 111); escolhe o principal por regra determinística.
2. **Campos estruturados** — grava `companies.nome_contato`, `companies.cargo_contato` (jobtitle), `companies.hubspot_deal_id`/`hubspot_company_id`/`hubspot_contact_id`, `companies.hubspot_domain`, `companies.hubspot_observacao`; email/telefone com fallback incl. `mobilephone`. A linha em `notes` ("Contato (HubSpot): ...") CONTINUA, mas deixa de ser a única fonte.
3. **Dedup de empresa existente** — antes de `Company::create`, procura empresa existente; match forte enriquece (não duplica), match fraco vira warning.
4. **Snapshot completo** — `companies.hubspot_snapshot` guarda deal + company + **todos os contatos** + line_items normalizados.

**FORA do escopo (Fase 114+):** UI Comercial (novos campos/pendências visíveis), pendência `possivel_duplicidade`/`sem_contato` renderizada na tela, comando `hubspot:reprocess-event`. Nesta fase, a pendência de duplicidade fraca vai para `hubspot_eventos.payload` (warning), não para a UI.

</domain>

<decisions>
## Implementation Decisions (LOCKED)

### INVARIANTE DE NÃO-REGRESSÃO
- O caminho comum (deal + 1 company + 1 contato, empresa nova) continua criando a Company com os mesmos campos e a mesma linha em `notes`. Testes atuais `Phase34HubspotWebhookTest` (cria company), `Phase35HubspotV2Test` (fallback email/telefone do contato + nome em notes) DEVEM continuar verdes SEM alterar asserções.
- Dedup só ALTERA comportamento quando JÁ EXISTE empresa correspondente no banco — nos testes atuais (DB fresco) nenhuma existe, então segue criando. A idempotência do mesmo deal já é tratada na guarda upstream (~linha 148), intocada.
- Enriquecimento é ADITIVO: os novos campos estruturados são preenchidos além do que já existe; nenhum campo hoje asserido muda de valor.

### Contato principal (HUB-CONTATO-01)
- Trocar no `processarEvento` (~linha 178-197 de `HubspotWebhookController`): usar `fetchAssociatedContactIds` + `fetchContacts(ids, props)` (batch). Buscar props: firstname, lastname, email, phone, mobilephone, jobtitle, hs_additional_emails.
- Regra de prioridade determinística (prompt Fase 5): (1) label útil de association se disponível no futuro (Decision maker/Billing/Primary/Financeiro — configurável, best-effort); (2) contato com email E telefone; (3) contato com email; (4) contato com telefone/mobilephone; (5) fallback: primeiro contato retornado. Empate → menor `id` (determinístico).
- Extrair a seleção numa unidade testável (ex.: método no handoff service ou classe `HubspotContactSelector` — discrição), com teste unitário próprio.

### Campos estruturados (HUB-CONTATO-02)
- `companies.nome_contato` = `firstname + lastname` (trim) do contato principal.
- `companies.cargo_contato` = `jobtitle` do contato principal.
- `companies.email_cliente` = email da company se existir; senão email do contato principal (mantém prioridade Company > contato já existente).
- `companies.telefone` = phone da company se existir; senão `phone` ou `mobilephone` do contato principal.
- `companies.hubspot_deal_id` = id do deal (`evento->object_id`); `hubspot_company_id` = id da company associada; `hubspot_contact_id` = id do contato principal.
- `companies.hubspot_domain` = `domain` da company (prop nova da 111); `hubspot_observacao` = observação comercial do deal se mapeada (best-effort, null se ausente).
- Manter a linha `notes` "Contato (HubSpot): {nome}" (não remover — fonte legada), mas os campos acima passam a ser a fonte estruturada.

### Dedup (HUB-DEDUP-01 / HUB-DEDUP-02)
- ANTES de criar Company, procurar empresa existente na ordem: (1) `hubspot_company_id`, (2) `cnpj`, (3) `email_cliente`, (4) `hubspot_domain`/domain, (5) `name` normalizado.
- **Match forte** (`hubspot_company_id` OU `cnpj` batem): NÃO duplica.
  - Enriquece apenas campos VAZIOS com dados do HubSpot; NUNCA sobrescreve campo já preenchido manualmente.
  - Adiciona contrato novo só se não existir `ContratoServico` com o mesmo `hubspot_line_item_id`.
  - `empresa_nova`: manter/marcar conforme sentido operacional (discrição — provavelmente não remarca como nova se já existia).
- **Match fraco** (só `name` normalizado bate, sem match forte): NÃO faz merge automático de campos críticos; gera warning `possivel_duplicidade` no `hubspot_eventos.payload` (a pendência na UI é da Fase 114). Segue criando a empresa (ou não? decisão: CRIAR a empresa nova + sinalizar duplicidade, para não perder o handoff; o humano decide depois via Comercial).
- Normalização de nome: helper (lowercase + trim + colapsar espaços + remover acentos/pontuação leve). Testar contra falso positivo.

### Snapshot completo (HUB-DEDUP-03)
- `companies.hubspot_snapshot` (json, coluna da 111) recebe: `{ deal: {...}, company: {...}, contacts: [todos os contatos normalizados], primary_contact_id, line_items: [normalizados], warnings: [...] , captured_at }`.
- Guardar TODOS os contatos associados, não só o principal.

### Handoff service / DTO
- Estender `HubspotDealHandoffService` e `HubspotHandoffData` (DTO já nasceu com `company_data`/`contact_data` nullable na 112) para preencher `company_data` (normalizado + campos estruturados) e `contact_data` (contato principal + lista completa). O `build()` pode ganhar os contatos/company como parâmetro adicional (discrição) OU um método novo — manter a assinatura de valor/contratos intacta para não regredir 112.
- O controller `criarEmpresa` passa a: resolver dedup → enriquecer/criar Company com os campos estruturados → gravar snapshot → delegar contratos ao handoff (já existente da 112).

### Claude's Discretion
- Onde vive a seleção de contato (método privado, classe dedicada) e a normalização de nome.
- Se o handoff service ganha os contatos por parâmetro novo em `build()` ou um método separado.
- Se `empresa_nova` é remarcada em match forte.
- Formato exato do warning `possivel_duplicidade` no payload.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plano canônico
- `prompt-claude-otimizacao-comercial-hubspot.md` — Fase 5 (contato principal + enriquecimento) e Fase 7 (dedup + enriquecimento de empresa existente).

### Código a refatorar/estender (source of truth)
- `app/Http/Controllers/Api/HubspotWebhookController.php` — `processarEvento` (~178-205: fetch single company/contact → trocar por batch) e `criarEmpresa` (~261-345: Company::create + notes + fallback email/telefone — vira dedup + enrich + campos estruturados). Preservar HMAC/idempotência/DB::transaction/roteamento MlbEmpresa/notificação.
- `app/Services/HubspotApiClient.php` — `fetchAssociatedContactIds`, `fetchContacts`, `fetchAssociatedCompanyIds`, `fetchCompanies` (já existem desde 111).
- `app/Services/Hubspot/HubspotDealHandoffService.php` + `HubspotHandoffData.php` — DTO já tem `company_data`/`contact_data` nullable (112) — preencher agora.
- `app/Models/Company.php` — `$fillable` já com `nome_contato`, `cargo_contato`, `hubspot_deal_id`/`company_id`/`contact_id`, `hubspot_domain`, `hubspot_observacao`, `hubspot_snapshot` (adicionados na 111-03); cast json de snapshot.
- `config/services.php` — `services.hubspot.props.contact` (mobilephone/jobtitle/additional_emails ampliados na 111); `props.company.domain`.

### Testes de regressão (DEVEM continuar verdes SEM alterar asserções)
- `tests/Feature/Phase34HubspotWebhookTest.php` — cria company.
- `tests/Feature/Phase35HubspotV2Test.php` — fallback email/telefone do contato + nome do contato em notes.
- `tests/Feature/Phase112HubspotHandoffWebhookTest.php` — valor/contratos (não deve regredir).

### Fases anteriores
- `.planning/phases/111-.../111-*-SUMMARY.md` (colunas + métodos batch), `.planning/phases/112-.../112-*-SUMMARY.md` (handoff service + DTO).

</canonical_refs>

<specifics>
## Specific Ideas

- Casos de teste âncora (prompt Fase 10 — `PhaseHubspotEnrichmentTest` + `PhaseHubspotDedupTest`):
  1. Deal com vários contatos escolhe o que tem email+telefone.
  2. Company sem phone usa `contact.mobilephone`.
  3. Grava `nome_contato`/`cargo_contato` estruturados.
  4. Grava `hubspot_deal_id`/`hubspot_company_id`/`hubspot_contact_id`.
  5. Snapshot contém deal/company/**todos os contatos**/line_items.
  6. Empresa existente por CNPJ é enriquecida (campos vazios), NÃO duplicada.
  7. Empresa existente por `hubspot_company_id` recebe novo contrato sem duplicar (guard `hubspot_line_item_id`).
  8. Match fraco por nome gera warning/pendência no payload, NÃO merge agressivo de campos críticos.
- `hs_additional_emails` pode vir como string separada — normalizar best-effort (não falhar se ausente).

</specifics>

<deferred>
## Deferred Ideas

- UI Comercial: exibir nome_contato/cargo/observação/IDs + valor operacional/original/confiança/warning + pendências `sem_contato`/`valor_revisar`/`possivel_duplicidade` → Fase 114.
- Comando `hubspot:reprocess-event` → Fase 114.
- Suite E2E ampla + doc → Fase 115.
- Labels de association úteis (Decision maker/Billing) — só best-effort agora; refinamento futuro se o HubSpot expuser.

</deferred>

---

*Phase: 113-enriquecimento-de-contato-empresa-escolha-de-contato-princip*
*Context gathered: 2026-07-24 — sintetizado do plano canônico + código real (lean)*
