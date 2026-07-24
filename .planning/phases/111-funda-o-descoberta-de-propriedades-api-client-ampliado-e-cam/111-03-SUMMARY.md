---
phase: 111-funda-o-descoberta-de-propriedades-api-client-ampliado-e-cam
plan: 03
subsystem: database
tags: [hubspot, migrations, eloquent, tdd, schema]

requires:
  - phase: 111-01
    provides: services.hubspot.props ampliado (deal/company/contact) — fonte de nomes de propriedade que a Fase 112 vai ler para popular estas colunas
  - phase: 111-02
    provides: HubspotApiClient ampliado (fetchDealLineItems + associações/batch) — fonte dos dados que a Fase 112 vai gravar nestas colunas
provides:
  - "8 colunas HubSpot em companies (hubspot_deal_id/company_id/contact_id indexadas, nome_contato, cargo_contato, hubspot_domain, hubspot_observacao, hubspot_snapshot json), nullable e sem uso"
  - "11 colunas HubSpot em contratos_servico (hubspot_line_item_id indexado, product_id, billing_frequency/period, currency, valor_original + valor_original_tipo, valor_normalizado_mensal, valor_confidence, valor_warning, hubspot_snapshot json), nullable e sem uso"
  - "Company e ContratoServico com $fillable + casts (array/decimal:2) refletindo as novas colunas"
affects: [112-hubspot-value-resolver, 113-enriquecimento-dedup, 114-ui-comercial-replay]

tech-stack:
  added: []
  patterns: [migration-defensiva-schema-hascolumn, tdd-red-green-schema-contract-test]

key-files:
  created:
    - tests/Feature/Phase111HubspotSchemaTest.php
    - database/migrations/2026_07_24_111001_add_hubspot_fields_to_companies_table.php
    - database/migrations/2026_07_24_111002_add_hubspot_fields_to_contratos_servico_table.php
  modified:
    - app/Models/Company.php
    - app/Models/ContratoServico.php

key-decisions:
  - "Campos operacionais direto em companies/contratos_servico + snapshot em JSON (preferência do prompt canônico) — tabela auxiliar company_hubspot_handoffs descartada nesta fase"
  - "1 migration por tabela (não 1 migration combinada), ambas defensivas via Schema::hasColumn, seguindo o padrão de 2026_06_12_300001_add_close_fields_to_companies_table.php"
  - "TDD invertido (RED-guard): a suite Phase111HubspotSchemaTest foi criada na Task 1 ANTES das migrations, provando falha genuína por coluna ausente — evita o falso-verde de --filter em classe inexistente"

requirements-completed: [HUB-SCHEMA-01, HUB-SCHEMA-02]

duration: ~15min
completed: 2026-07-24
---

# Phase 111 Plan 03: Fundação — migrations estruturadas HubSpot (companies + contratos_servico) Summary

**Migrations defensivas e reversíveis adicionam 8 colunas HubSpot em `companies` e 11 em `contratos_servico` (todas nullable, snapshot em JSON), refletidas em `$fillable`/casts de `Company`/`ContratoServico` — colunas de proveniência para o handoff HubSpot da Fase 112+, sem alterar nenhum comportamento do fluxo legado (Fases 34-37).**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-07-24T09:47:00Z
- **Completed:** 2026-07-24T10:05:00Z
- **Tasks:** 3 completos (TDD RED→GREEN)
- **Files modified:** 5 (3 criados, 2 modificados)

## Accomplishments
- `Phase111HubspotSchemaTest` criada primeiro (RED genuíno — 2/3 métodos falham por coluna ausente, guard anti-falso-verde confirmado via `grep -Eq "FAIL|Tests:.*failed|error"`)
- Migration `companies`: 8 colunas (3 indexadas: `hubspot_deal_id`/`hubspot_company_id`/`hubspot_contact_id`), defensiva (`Schema::hasColumn` em cada uma) + `down()` reversível
- Migration `contratos_servico`: 11 colunas (`hubspot_line_item_id` indexado, 2 decimais `12,2`, 1 json), defensiva + `down()` reversível
- `Company`/`ContratoServico` com `$fillable` estendido e casts (`hubspot_snapshot => array`; `hubspot_valor_original`/`hubspot_valor_normalizado_mensal => decimal:2`)
- Suite `Phase111HubspotSchemaTest` 100% verde (3/3, 29 assertions) — inclui teste explícito de zero-impacto (Company/ContratoServico criados SEM nenhum campo HubSpot continuam salvando normalmente)
- Regressão exigida pelo plano verde: `Phase34HubspotWebhookTest` (6/6) e `Phase37WebhookLineItemsTest` (10/10)
- Regressão ampliada (`Phase34|Phase35|Phase37|Phase14`, 146 testes): nenhuma falha nova introduzida — todas as 9 falhas encontradas são pré-existentes e em código não tocado por este plano (documentado em `deferred-items.md`)

## Task Commits

Each task was committed atomically (TDD RED→GREEN):

1. **Task 1: (RED) Suite Phase111HubspotSchemaTest** - `4b23eea3` (test, RED)
2. **Task 2: (GREEN) Migration companies + Company model** - `c1a79d98` (feat, GREEN)
3. **Task 3: (GREEN) Migration contratos_servico + ContratoServico model** - `874296d7` (feat, GREEN)

**Plan metadata:** (a seguir, commit desta SUMMARY + STATE + ROADMAP)

_TDD: 1 commit RED (Task 1) + 2 commits GREEN (Tasks 2 e 3), sem necessidade de refactor._

## Files Created/Modified
- `tests/Feature/Phase111HubspotSchemaTest.php` (novo) - 3 métodos: colunas companies (8) + persistência/cast, colunas contratos_servico (11) + persistência/cast, zero-impacto no fluxo legado (nullable)
- `database/migrations/2026_07_24_111001_add_hubspot_fields_to_companies_table.php` (novo) - 8 colunas defensivas + down() reversível
- `database/migrations/2026_07_24_111002_add_hubspot_fields_to_contratos_servico_table.php` (novo) - 11 colunas defensivas + down() reversível
- `app/Models/Company.php` - `$fillable` +8 colunas HubSpot; `$casts['hubspot_snapshot'] = 'array'`
- `app/Models/ContratoServico.php` - `$fillable` +11 colunas HubSpot; casts `hubspot_valor_original`/`hubspot_valor_normalizado_mensal => decimal:2`, `hubspot_snapshot => array`

## Decisions Made
- Colunas operacionais direto nas tabelas de negócio (não tabela auxiliar) + snapshot JSON — conforme preferência travada do prompt canônico
- 1 migration por tabela, ambas seguindo o padrão defensivo já usado no projeto (`Schema::hasColumn` por coluna, `down()` remove só o que foi adicionado)
- Nenhuma branch SQLite necessária (sem enum/CHECK — só `string`/`text`/`json`/`decimal(12,2)`, todos com precedente cross-driver no projeto: `marketplaces_extras=>array`, `faturamento_mensal=>decimal:2`)

## Deviations from Plan

None - plan executado exatamente como escrito.

## Known Stubs

Nenhum. As 19 colunas novas nascem `nullable` e não são lidas/escritas por nenhum fluxo ainda — não são stubs de UI, são schema de auditoria a ser populado pela Fase 112 (`HubspotValueResolver`/`HubspotDealHandoffService`).

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. As migrations só adicionam colunas nullable sem FK/CHECK; T-111-05 (idempotência) e T-111-06 (cross-driver json/decimal) mitigados conforme registrado — sem novo endpoint, rota ou caminho de auth.

## Issues Encountered

Nenhum bloqueio. Durante a escrita do teste RED, 2 ajustes de fixture foram necessários (não deviations de plano, apenas correção de dados de teste): `Servico::create` precisa de `tipo_cobranca` (NOT NULL na migration) e `ContratoServico::create` precisa de `data_contratacao` (NOT NULL na migration) — ambos adicionados aos 3 cenários do teste para que a suite exercite o fluxo real de criação.

`gsd-sdk query requirements.mark-complete HUB-SCHEMA-01 HUB-SCHEMA-02` retornou `not_found` — `.planning/REQUIREMENTS.md` ainda reflete só o milestone v17.0 (Carteira/Desempenho), nunca foi atualizado para a v20.0 (Handoff HubSpot). Gap pré-existente da abertura do milestone (fora do escopo deste plano de execução); não corrigido aqui.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 111 completa (3/3 planos): config de propriedades (111-01), `HubspotApiClient` ampliado (111-02), schema estruturado (111-03) — a fundação do handoff HubSpot está pronta.
- Fase 112 (`HubspotValueResolver` + `HubspotDealHandoffService`) pode ler `config('services.hubspot.props')`, chamar os novos métodos do `HubspotApiClient` e escrever nas 19 colunas criadas aqui (todas nullable, prontas para popular).
- Nenhum bloqueio conhecido. Falhas pré-existentes documentadas em `deferred-items.md` não impedem a Fase 112.

## Self-Check

- [x] `tests/Feature/Phase111HubspotSchemaTest.php` existe
- [x] `database/migrations/2026_07_24_111001_add_hubspot_fields_to_companies_table.php` existe
- [x] `database/migrations/2026_07_24_111002_add_hubspot_fields_to_contratos_servico_table.php` existe
- [x] `app/Models/Company.php` / `app/Models/ContratoServico.php` existem e modificados
- [x] Commits `4b23eea3`, `c1a79d98`, `874296d7` existem no histórico
- [x] `php artisan test --filter=Phase111HubspotSchemaTest` → 3/3 verde
- [x] Regressão `Phase34HubspotWebhookTest`/`Phase37WebhookLineItemsTest` → verdes

## Self-Check: PASSED

---
*Phase: 111-funda-o-descoberta-de-propriedades-api-client-ampliado-e-cam*
*Completed: 2026-07-24*
