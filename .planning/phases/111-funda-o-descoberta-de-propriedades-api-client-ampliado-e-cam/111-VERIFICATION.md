---
phase: 111-funda-o-descoberta-de-propriedades-api-client-ampliado-e-cam
verified: 2026-07-24T00:00:00Z
status: passed
score: 5/5 must-haves verified
overrides_applied: 0
---

# Phase 111: Fundação — descoberta de propriedades, API client ampliado e campos estruturados Verification Report

**Phase Goal:** A base do handoff existe sem mudar comportamento: `config/services.php` aceita as novas props HubSpot por env (deal/company/contact/line_item) com fallback seguro; comando `hubspot:inspect-properties` valida nomes internos reais da conta via Properties API (sem vazar token); `HubspotApiClient` busca line items e associações com o conjunto ampliado de propriedades; migrations defensivas adicionam os campos estruturados de origem HubSpot em `companies` e `contratos_servico`. Fluxo legado e testes atuais intactos.
**Verificado em:** 2026-07-24
**Status:** passed
**Re-verificação:** Não — verificação inicial

## Goal Achievement

### Observable Truths (5 Success Criteria do ROADMAP)

| # | Truth (Success Criteria) | Status | Evidência |
|---|---|---|---|
| 1 | `config('services.hubspot.props')` expõe deal (observacao/description/closed_won_reason/closedate/pipeline/hs_mrr/hs_arr/hs_tcv/hs_acv/hs_currency), company (domain/industry/annualrevenue/city/state/country) e contact (mobilephone/jobtitle/hs_additional_emails), cada um via `env()` com default; ausência não quebra nada | VERIFIED | `config/services.php` linhas 128-170: as 10 chaves novas de `deal`, 6 de `company`, 3 de `contact` existem, todas via `env('HUBSPOT_PROP_*', 'default_interno')`; chaves antigas (nicho/dor/vende_ml/faturamento_mensal/servico, name/cnpj/email/phone, firstname/lastname/email/phone) preservadas intactas. `Phase111HubspotConfigPropsTest` 4/4 verde (defaults seguros sem env). |
| 2 | `php artisan hubspot:inspect-properties --objects=deals,line_items,companies,contacts` imprime nome interno + label + type + fieldType por objeto; nenhum token aparece na saída/log | VERIFIED | `app/Console/Commands/HubspotInspectProperties.php`: GET `/crm/v3/properties/{objeto}`, monta tabela `['name (interno)', 'label', 'type', 'fieldType']` (linhas 68-75); token nunca impresso — mensagens de erro usam só `get_class($e)` ou status HTTP (linha 79); falha por objeto (403/500/ConnectionException) é capturada via try/catch isolado e sempre retorna `self::SUCCESS` (nunca crasha). `Phase111InspectPropertiesTest` 5/5 verde, incluindo teste dedicado "saída nunca contém o token nem a string bearer". |
| 3 | `HubspotApiClient::fetchDealLineItems` retorna as props mínimas do prompt; métodos novos `fetchAssociated*Ids`/`fetchCompanies`/`fetchContacts` coexistem com os atuais; base v3 mantida | VERIFIED | `app/Services/HubspotApiClient.php`: `fetchDealLineItems` (linhas 182-292) retorna as 16 props exigidas (name/description/price/amount/quantity/hs_product_id/hs_sku/recurringbillingfrequency/hs_recurring_billing_period/hs_recurring_billing_start_date/hs_recurring_billing_end_date/hs_line_item_currency_code/hs_mrr/hs_arr/hs_tcv/hs_acv); métodos novos `fetchAssociations`/`fetchAssociatedCompanyIds`/`fetchAssociatedContactIds`/`fetchCompanies`/`fetchContacts` (linhas 305-436) coexistem com os singulares antigos (`fetchDeal`/`fetchAssociatedCompanyId`/`fetchCompany`/`fetchAssociatedContactId`/`fetchContact`, linhas 50-137, intactos). `grep -c "2026-03" HubspotApiClient.php` = 0 (base v3 confirmada). `Phase111HubspotApiClientTest` 14/14 + `Phase37LineItemsFetchTest` 9/9 verdes. |
| 4 | Migration defensiva (`Schema::hasColumn`) adiciona em `companies` as 8 colunas exigidas, com rollback | VERIFIED | `database/migrations/2026_07_24_111001_add_hubspot_fields_to_companies_table.php`: 8 colunas exatas (`hubspot_deal_id`/`hubspot_company_id`/`hubspot_contact_id` indexadas, `nome_contato`, `cargo_contato`, `hubspot_domain`, `hubspot_observacao` text, `hubspot_snapshot` json), cada uma guardada por `Schema::hasColumn` antes do `add`; `down()` remove só as 8 colunas de forma defensiva. `app/Models/Company.php`: `$fillable` (linhas 44-48) e cast `hubspot_snapshot => array` (linha 68) presentes. |
| 5 | Migration defensiva adiciona em `contratos_servico` as 11 colunas exigidas, com rollback | VERIFIED | `database/migrations/2026_07_24_111002_add_hubspot_fields_to_contratos_servico_table.php`: 11 colunas exatas (`hubspot_line_item_id` indexado, `hubspot_product_id`, `hubspot_billing_frequency`, `hubspot_billing_period`, `hubspot_currency`, `hubspot_valor_original` decimal(12,2), `hubspot_valor_original_tipo`, `hubspot_valor_normalizado_mensal` decimal(12,2), `hubspot_valor_confidence`, `hubspot_valor_warning` text, `hubspot_snapshot` json), defensiva + `down()` reversível. `app/Models/ContratoServico.php`: `$fillable` (linhas 34-44) e casts `decimal:2`/`array` (linhas 53-55) presentes. |

**Score:** 5/5 truths verificadas

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `config/services.php` | 19 chaves novas em `services.hubspot.props` via env, preservando as antigas | VERIFIED | Confirmado por leitura direta + `Phase111HubspotConfigPropsTest` 4/4 |
| `app/Console/Commands/HubspotInspectProperties.php` | Comando `hubspot:inspect-properties`, sem vazar token, resiliente | VERIFIED | Confirmado por leitura + `Phase111InspectPropertiesTest` 5/5 |
| `app/Services/HubspotApiClient.php` | `fetchDealLineItems` ampliado + 5 métodos novos, base v3 mantida | VERIFIED | Confirmado por leitura + `grep -c "2026-03"` = 0 + `Phase111HubspotApiClientTest` 14/14 |
| `database/migrations/2026_07_24_111001_add_hubspot_fields_to_companies_table.php` | 8 colunas defensivas + rollback | VERIFIED | Leitura completa da migration |
| `database/migrations/2026_07_24_111002_add_hubspot_fields_to_contratos_servico_table.php` | 11 colunas defensivas + rollback | VERIFIED | Leitura completa da migration |
| `app/Models/Company.php` | `$fillable` + cast `hubspot_snapshot=array` | VERIFIED | Leitura direta, linhas 44-48 e 68 |
| `app/Models/ContratoServico.php` | `$fillable` + casts decimal/array | VERIFIED | Leitura direta, linhas 34-44 e 53-55 |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `HubspotInspectProperties.php` | `https://api.hubapi.com/crm/v3/properties/{objectType}` | `Http::withToken()->get()` | WIRED | Linha 54 do comando |
| `HubspotApiClient::fetchDealLineItems` | props ampliadas do line item | string `$properties` com 16 nomes | WIRED | Linhas 226-229 |
| `HubspotApiClient::fetchAssociatedCompanyIds/ContactIds` | `fetchAssociations` genérico | reuso DRY | WIRED | Linhas 326-352 |
| `Company.php` / `ContratoServico.php` | colunas HubSpot nas tabelas | `$fillable` + `$casts` | WIRED | Confirmado; colunas nullable e sem uso em qualquer fluxo de escrita atual (grep não encontrou consumidor no webhook) |

### Escopo de Fundação — confirmações adicionais

- **Fluxo legado do webhook intacto:** `git log --since="2026-07-24 00:00" -- app/Http/Controllers/Api/HubspotWebhookController.php` não retorna nenhum commit — o controller não foi tocado nesta fase. Nenhum `HubspotValueResolver`, `HubspotDealHandoffService`, enriquecimento de contato ou dedup vazou para esta fase (fora do escopo, confirmado por ausência no código).
- **Colunas nullable não usadas:** confirmado por leitura das migrations/models — nenhum controller/service grava ou lê as 19 colunas novas fora dos testes da própria fase 111.
- **Regressão executada nesta verificação** (não apenas citada pelo SUMMARY): `php artisan test tests/Feature/Phase111HubspotConfigPropsTest.php tests/Feature/Phase111InspectPropertiesTest.php tests/Feature/Phase111HubspotApiClientTest.php tests/Feature/Phase111HubspotSchemaTest.php tests/Feature/Phase34HubspotWebhookTest.php tests/Feature/Phase37LineItemsFetchTest.php` → **41/41 passed (190 assertions)**, 7.19s. Nenhuma falha.

### Requirements Coverage

| Requirement | Source Plan | Descrição | Status | Evidência |
|---|---|---|---|---|
| HUB-API-01 | 111-01 | Config props por env | SATISFIED | `config/services.php` + `Phase111HubspotConfigPropsTest` |
| HUB-API-02 | 111-01 | Comando `hubspot:inspect-properties` | SATISFIED | `HubspotInspectProperties.php` + `Phase111InspectPropertiesTest` |
| HUB-API-03 | 111-02 | `fetchDealLineItems` + associações ampliadas | SATISFIED | `HubspotApiClient.php` + `Phase111HubspotApiClientTest` + `Phase37LineItemsFetchTest` |
| HUB-SCHEMA-01 | 111-03 | Colunas `companies` | SATISFIED | Migration `2026_07_24_111001...` + `Company.php` + `Phase111HubspotSchemaTest` |
| HUB-SCHEMA-02 | 111-03 | Colunas `contratos_servico` | SATISFIED | Migration `2026_07_24_111002...` + `ContratoServico.php` + `Phase111HubspotSchemaTest` |

**Nota (informativa, não bloqueante):** `.planning/REQUIREMENTS.md` ainda reflete só a milestone v17.0 — não existe `REQUIREMENTS-v20.md` nem entradas HUB-* no arquivo atual. Isso é um gap de processo documental (já registrado pelo próprio 111-03-SUMMARY como "gap pré-existente da abertura do milestone"), não um gap de código — não impede a fase nem bloqueia 112. Nenhum requisito HUB-* foi encontrado órfão porque nenhum dos 5 aparece no REQUIREMENTS.md atual (arquivo desatualizado, não fonte de verdade nesta milestone — o ROADMAP.md já documenta os 5 requisitos e todos foram mapeados/satisfeitos acima).

### Anti-Patterns Found

Nenhum `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER` encontrado nos arquivos modificados pela fase (`config/services.php`, `HubspotInspectProperties.php`, `HubspotApiClient.php`, ambas migrations, `Company.php`, `ContratoServico.php`). Nenhum stub de retorno vazio/hardcoded fora de teste. Métodos novos são wrappers HTTP reais (mesmo padrão dos existentes).

### Behavioral Spot-Checks / Probe Execution

Não aplicável nesta fase como testes end-to-end de servidor rodando — a fase é fundação de config/client/schema, sem endpoint HTTP novo exposto ao usuário final. A verificação comportamental foi feita via suíte de testes Feature (`Http::fake`), que é o padrão do projeto para este tipo de código, e todos passaram (41/41) rodados diretamente por este verificador (não apenas citados pelo SUMMARY).

### Human Verification Required

Nenhum item requer verificação humana. Todos os 5 Success Criteria são verificáveis programaticamente (config, comando artisan, client HTTP, schema de migration) e foram confirmados por leitura de código + execução real de testes.

### Gaps Summary

Nenhum gap bloqueador encontrado. Os 5 Success Criteria do ROADMAP batem exatamente com o código: config ampliada (só adição, defaults seguros), comando de diagnóstico resiliente e sem vazamento de token, `HubspotApiClient` ampliado mantendo base v3 e métodos antigos intactos, e as duas migrations defensivas com as colunas exatas especificadas (8 em `companies`, 11 em `contratos_servico`), refletidas em `$fillable`/casts dos models. O fluxo legado do webhook (Fases 34-37) não foi tocado nesta fase — confirmado por ausência de commits no controller desde a abertura da fase — e nenhuma lógica de `HubspotValueResolver`/enriquecimento/dedup vazou para este escopo. Regressão completa (41/41 testes) executada diretamente por este verificador, não apenas citada do SUMMARY.

Único ponto informativo (não gap, não bloqueia): `.planning/REQUIREMENTS.md` não foi atualizado para a milestone v20.0 — os requisitos HUB-* vivem só no ROADMAP.md. Isso é um débito de processo documental, já autoidentificado no 111-03-SUMMARY, sem impacto no código ou na Fase 112.

---

_Verificado em: 2026-07-24_
_Verificador: Claude (gsd-verifier)_
