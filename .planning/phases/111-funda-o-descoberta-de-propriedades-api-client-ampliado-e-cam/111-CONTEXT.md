# Phase 111: Fundação — descoberta de propriedades, API client ampliado e campos estruturados - Context

**Gathered:** 2026-07-24
**Status:** Ready for planning
**Source:** Plano canônico `prompt-claude-otimizacao-comercial-hubspot.md` (Fases 1, 3, 4) — milestone v20.0

<domain>
## Phase Boundary

Esta fase entrega **só a fundação** do handoff HubSpot, sem mudar comportamento observável do webhook:

1. **Config de propriedades por env** (`config/services.php` → `services.hubspot.props`) — deal/company/contact ganham novas chaves mapeáveis por `env()` com default seguro.
2. **Comando de diagnóstico** `hubspot:inspect-properties` — valida via Properties API os nomes internos reais da conta ECF (não assumir label = nome interno). NUNCA loga token.
3. **`HubspotApiClient` ampliado** — `fetchDealLineItems` com o conjunto ampliado de propriedades + novos métodos de associações/batch (`fetchAssociatedCompanyIds`, `fetchAssociatedContactIds`, `fetchCompanies`, `fetchContacts`, `fetchAssociations`), **sem quebrar** os métodos atuais.
4. **Migrations estruturadas defensivas** — colunas de origem HubSpot em `companies` e `contratos_servico` (com `Schema::hasColumn` + rollback).

**FORA do escopo desta fase** (ficam para 112+): o `HubspotValueResolver`, o `HubspotDealHandoffService`, qualquer mudança em como `valor_contratado` é calculado, enriquecimento de contato, dedup, UI, replay. Aqui só se **cria a base** — as colunas ficam nullable e não usadas ainda; o fluxo legado (Fases 34–37) continua idêntico.

</domain>

<decisions>
## Implementation Decisions (LOCKED — do prompt canônico)

### Config props (HUB-API-01)
- Estender `config/services.php` bloco `services.hubspot.props` com sub-chaves `deal`, `company`, `contact`, cada valor via `env(...)` com default = nome interno padrão HubSpot.
- `deal`: `observacao`, `description`, `closed_won_reason`, `closedate`, `pipeline`, `hs_mrr`, `hs_arr`, `hs_tcv`, `hs_acv`, `hs_currency`.
- `company`: `domain`, `industry`, `annualrevenue`, `city`, `state`, `country`.
- `contact`: `mobilephone`, `jobtitle`, `additional_emails` (default `hs_additional_emails`).
- Preservar as chaves atuais (`props.deal`: nicho/dor/vende_ml/faturamento_mensal/servico; `props.company`: name/cnpj/email/phone; `props.contact`: firstname/lastname/email/phone) — só ADICIONAR.
- Propriedade ausente na conta → tratar como `null`, nunca quebrar.

### Comando inspect-properties (HUB-API-02)
- `php artisan hubspot:inspect-properties --objects=deals,companies,contacts,line_items` (aceitar `--objects` CSV; default os 4).
- Usa Properties API `/crm/v3/properties/{objectType}`.
- Imprime por objeto: nome interno, label, type, fieldType.
- **NUNCA** imprime/loga o access token. Reusa credenciais de `config('services.hubspot')`.
- Se a chamada falhar (rede/403), reporta erro amigável e continua os demais objetos — não crasha.

### HubspotApiClient (HUB-API-03)
- `fetchDealLineItems(dealId)` retorna props mínimas: `name`, `description`, `price`, `amount`, `quantity`, `hs_product_id`, `hs_sku`, `recurringbillingfrequency`, `hs_recurring_billing_period`, `hs_recurring_billing_start_date`, `hs_recurring_billing_end_date`, `hs_line_item_currency_code`, `hs_mrr`, `hs_arr`, `hs_tcv`, `hs_acv`.
- Novos métodos coexistem com os atuais (`fetchDeal`, `fetchAssociatedCompanyId`, `fetchCompany`, `fetchAssociatedContactId`, `fetchContact`): `fetchAssociatedCompanyIds`, `fetchAssociatedContactIds`, `fetchAssociations(fromObject, fromId, toObject)`, `fetchCompanies(ids, properties)`, `fetchContacts(ids, properties)`.
- Manter a base atual `https://api.hubapi.com/crm/v3/objects/...` — **NÃO** migrar para `/crm/objects/2026-03` agora (documentar a decisão; testes atuais cobrem o contrato v3).
- Todos os testes usam `Http::fake` — nenhuma chamada real ao HubSpot.

### Migrations companies (HUB-SCHEMA-01)
- Migration defensiva (`Schema::hasColumn` antes de cada add + `down()` com rollback) adiciona: `hubspot_deal_id` (string nullable, index), `hubspot_company_id` (string nullable, index), `hubspot_contact_id` (string nullable, index), `nome_contato` (string nullable), `cargo_contato` (string nullable), `hubspot_domain` (string nullable), `hubspot_observacao` (text nullable), `hubspot_snapshot` (json nullable).

### Migrations contratos_servico (HUB-SCHEMA-02)
- Migration defensiva adiciona: `hubspot_line_item_id` (string nullable, index), `hubspot_product_id` (string nullable), `hubspot_billing_frequency` (string nullable), `hubspot_billing_period` (string nullable), `hubspot_currency` (string nullable), `hubspot_valor_original` (decimal 12,2 nullable), `hubspot_valor_original_tipo` (string nullable), `hubspot_valor_normalizado_mensal` (decimal 12,2 nullable), `hubspot_valor_confidence` (string nullable), `hubspot_valor_warning` (text nullable), `hubspot_snapshot` (json nullable).
- Preferência do prompt: campos operacionais direto em `companies`/`contratos_servico` (não tabela auxiliar) + snapshot em JSON. Tabela `company_hubspot_handoffs` é OPCIONAL/descartada nesta fase salvo evidência forte.
- Atualizar `$fillable` (e casts json de snapshot) em `Company` e `ContratoServico`.

### Claude's Discretion
- Nome exato dos arquivos de migration (timestamp), organização interna do comando Artisan, se `fetchCompanies/fetchContacts` usam batch read API (`/crm/v3/objects/{obj}/batch/read`) ou N GETs — escolher o padrão já usado no client.
- Se cria 1 migration por tabela ou 1 migration com as duas (preferir 1 por tabela, defensivas).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plano canônico (fonte de verdade da milestone)
- `prompt-claude-otimizacao-comercial-hubspot.md` — Fases 1 (config+inspect), 3 (API client), 4 (migrations). Contém as props mínimas, os nomes de método e a lista de colunas exatas.

### Código a estender (source of truth do estado atual)
- `config/services.php` — bloco `services.hubspot` atual a estender (não substituir).
- `app/Services/HubspotApiClient.php` — métodos atuais (`fetchDeal`/`fetchAssociatedCompanyId`/`fetchCompany`/`fetchAssociatedContactId`/`fetchContact`/`fetchDealLineItems`); base URL v3; padrão de auth.
- `app/Models/Company.php` — `$fillable`/casts a estender; relações `hubspotEventoOrigem()`/`hubspotEventos()`.
- `app/Models/ContratoServico.php` — `$fillable` atual (`company_id`/`servico_id`/`valor_contratado`/`data_contratacao`/`data_vencimento`/`ativo`/`observacoes`).
- `app/Http/Controllers/Api/HubspotWebhookController.php` — consumidor atual do client (NÃO alterar comportamento nesta fase; só garantir que as props ampliadas não quebrem o fluxo).

### Testes a manter verdes (regressão)
- `tests/Feature/Phase37LineItemsFetchTest.php` — contrato atual de `fetchDealLineItems` (props hoje: `name,price,quantity,hs_product_id,recurringbillingfrequency`). Ampliar props sem quebrar este teste.
- `tests/Feature/Phase34HubspotWebhookTest.php`, `tests/Feature/Phase35HubspotV2Test.php`, `tests/Feature/Phase37WebhookLineItemsTest.php` — fluxo webhook legado.

### Migrations de referência (padrão do projeto)
- `database/migrations/2026_06_12_300001_add_close_fields_to_companies_table.php` — padrão defensivo de add-columns em `companies`.

</canonical_refs>

<specifics>
## Specific Ideas

- Doc HubSpot Properties API: `/crm/properties/{objectType}` — usada pelo comando de diagnóstico.
- Line item recorrente: `recurringbillingfrequency` (weekly/biweekly/monthly/quarterly/annually/...) + `hs_recurring_billing_period` (ISO-8601, ex. `P1Y`).
- Prompt §"Cuidados": `Schema::hasColumn` em toda migration; tokens nunca em log; propriedade ausente = null no snapshot; não migrar de `/crm/v3/objects` agora.
- Critério de aceite âncora da milestone (contexto — implementado na 112, não aqui): line item mensal R$3.000 + deal amount R$36.000 → `valor_contratado=3.000`. As colunas de auditoria criadas AQUI é que vão guardar os R$36.000/proveniência.

</specifics>

<deferred>
## Deferred Ideas

- `HubspotValueResolver` + `HubspotDealHandoffService` → Phase 112.
- Escolha de contato principal + enriquecimento + dedup → Phase 113.
- UI Comercial + pendências novas + comando `hubspot:reprocess-event` → Phase 114.
- Suite E2E ampla + doc da regra de valor → Phase 115.
- Tabela auxiliar `company_hubspot_handoffs` (alternativa ao snapshot JSON) — não nesta fase.

</deferred>

---

*Phase: 111-funda-o-descoberta-de-propriedades-api-client-ampliado-e-cam*
*Context gathered: 2026-07-24 — sintetizado do plano canônico (lean, sem discuss round-trip)*
