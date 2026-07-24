---
phase: 112-hubspotvalueresolver-extra-o-do-handoff-service-v20-0-n-cleo
verified: 2026-07-24T14:43:00Z
status: passed
score: 5/5 must-haves verificados
overrides_applied: 0
---

# Phase 112: HubspotValueResolver + extração do handoff service (v20.0 — NÚCLEO) Verification Report

**Phase Goal:** A regra de valor mensal×anual vira uma classe testável isolada (`HubspotValueResolver`, TDD-first) e a normalização do webhook é extraída para um `HubspotDealHandoffService` fino, deixando o controller como orquestrador. `valor_contratado` passa a receber o valor **operacional** correto (mensal quando o serviço é mensal), com o valor bruto/anual e a proveniência gravados nos campos de auditoria da Fase 111.
**Verificado em:** 2026-07-24T14:43:00Z
**Status:** passed
**Re-verificação:** Não — verificação inicial

## Goal Achievement

### Observable Truths (5 Success Criteria do ROADMAP)

| # | Truth (SC do ROADMAP) | Status | Evidência |
|---|------------------------|--------|-----------|
| 1 | `HubspotValueResolver::resolve(Servico, lineItem, dealProps)` retorna o array de 8 chaves (`valor_operacional/valor_original/valor_original_tipo/normalizado_mensal/billing_frequency/billing_period/confidence/warning`) conforme spec §Fase 6; os 6 casos-âncora passam | ✓ VERIFIED | `app/Services/Hubspot/HubspotValueResolver.php:43-52` — assinatura + shape exatos; `tests/Unit/HubspotValueResolverTest.php` — 9 testes (6 âncora do prompt Fase 10 + paridade qty>1 + 2 bordas de tolerância 5%), rodados agora: **9/9 PASS** |
| 2 | Casos-âncora persistidos via E2E do webhook: line item mensal + amount/ARR anual → `ContratoServico.valor_contratado=3000` E `hubspot_valor_original=36000` | ✓ VERIFIED (nuance documentada) | `tests/Feature/Phase112HubspotHandoffWebhookTest.php::test_line_item_annually_p1y_normaliza_36000_para_3000_mensal_com_auditoria` — asserta `valor_contratado=3000.0` E `hubspot_valor_original=36000.0` (linhas 315-323), rodado agora: **PASS**. Nota: o cenário que persiste `hubspot_valor_original=36000` é o line item `annually`+`hs_arr=36000` (não `monthly`+`deal.amount=36000` — nesse caso o `deal.amount` é ignorado por design quando há line item, e `valor_original` reflete o `price` do próprio line item, não o `deal.amount`). Essa é a leitura correta do critério canônico "line item mensal R$3.000 + amount/ARR R$36.000" documentado em `112-CONTEXT.md` linha 108, e bate com o caso-âncora #2 do resolver (`annually price 36000 + P1Y → operacional 3000`) |
| 3 | `HubspotDealHandoffService` + DTO `HubspotHandoffData` extraídos; controller delega (`->build(` presente); `processarLineItems`/`processarServicoLegado` inline removidos; HMAC/idempotência/`DB::transaction` preservados | ✓ VERIFIED | `app/Http/Controllers/Api/HubspotWebhookController.php:328` — `app(HubspotDealHandoffService::class)->build($deal, $lineItems, $propsDeal)`; grep por `function processarLineItems\|function processarServicoLegado` em `app/` = 0 matches (só referências em comentários históricos, linhas 201/348/416); `hash_equals` (linha 83), `jaProcessado`/idempotência (linhas 148-160), `DB::transaction` (linha 275) intactos |
| 4 | As 11 colunas `hubspot_*` gravadas por contrato; `observacoes` legada preservada | ✓ VERIFIED | `app/Models/ContratoServico.php:34-44` — 11 colunas no `$fillable` (line_item_id/product_id/billing_frequency/billing_period/currency/valor_original/valor_original_tipo/valor_normalizado_mensal/valor_confidence/valor_warning/snapshot); `HubspotWebhookController::persistirContratos()` (linha 378-397) grava todas; `observacoes = "tipo_cobranca: ..."` preservada quando `hubspot_line_item_id !== null` (linhas 368-376), confirmado por `test_observacoes_legada_mensal_preservada` — **PASS** |
| 5 | INVARIANTE DE NÃO-REGRESSÃO: monthly+price → `price*quantity` idêntico ao atual; `Phase37WebhookLineItemsTest`/`Phase34`/`Phase35` verdes SEM alteração de asserção de valor | ✓ VERIFIED | `git log` nos arquivos de teste de regressão mostra ZERO commits da Fase 112 (`1eed0439`..`247d04b2`) tocando `Phase37WebhookLineItemsTest.php`, `Phase34HubspotWebhookTest.php`, `Phase35HubspotV2Test.php`, `Phase37LineItemsFetchTest.php` — últimas alterações são de fases anteriores (111/37). Suíte completa rodada agora: **55/55 PASS (280 assertions)** |

**Score:** 5/5 truths verificados

### Required Artifacts

| Artefato | Esperado | Status | Detalhes |
|----------|----------|--------|----------|
| `app/Services/Hubspot/HubspotValueResolver.php` | Classe pura, `resolve()` com as 6 regras | ✓ VERIFIED | 322 linhas, sem I/O/banco; `resolverComLineItem`/`resolverSemLineItem` + helpers `aproximadamente()`/`numericoOu()`/`numericoOuNull()` |
| `app/Services/Hubspot/HubspotHandoffData.php` | DTO com `deal_data`/`line_items`/`contracts_to_create`/`warnings`/`confidence` + `company_data`/`contact_data` nullable reservados | ✓ VERIFIED | Construtor com defaults; `company_data`/`contact_data` `?array = null` (linhas 57-58), nunca preenchidos no fluxo atual (confirmado por grep — nenhum ponto de atribuição fora do default) |
| `app/Services/Hubspot/HubspotDealHandoffService.php` | `build(deal, lineItems, propsDeal): HubspotHandoffData` | ✓ VERIFIED | 156 linhas; multi-line-item resolvido individualmente; `menorConfianca()` agrega confidence do DTO |
| `app/Http/Controllers/Api/HubspotWebhookController.php` | Controller fino, delega ao handoff, persiste auditoria | ✓ VERIFIED | `criarEmpresa()` chama `build()` (linha 328) + `persistirContratos()` (linha 359); `processarLineItems`/`processarServicoLegado` removidos por completo |
| `app/Models/ContratoServico.php` | 11 colunas `hubspot_*` fillable + casts | ✓ VERIFIED | `$fillable` (linhas 34-44) e `$casts` (decimal:2 para valores, array para snapshot) |
| `tests/Unit/HubspotValueResolverTest.php` | 6 casos-âncora + paridade + tolerância | ✓ VERIFIED | 9 métodos, todos passando |
| `tests/Feature/Phase112HandoffServiceTest.php` | Suite isolada do handoff (sem HTTP) | ✓ VERIFIED | 8 testes, 34 asserções, todos passando |
| `tests/Feature/Phase112HubspotHandoffWebhookTest.php` | E2E via webhook real (Http::fake) | ✓ VERIFIED | 6 testes, 31 asserções, todos passando — inclui o critério de aceite âncora 36k→3k |

### Key Link Verification

| De | Para | Via | Status | Detalhes |
|----|------|-----|--------|----------|
| `HubspotWebhookController::criarEmpresa` | `HubspotDealHandoffService::build()` | `app(HubspotDealHandoffService::class)->build($deal, $lineItems, $propsDeal)` | ✓ WIRED | Linha 328; resultado usado imediatamente em `persistirContratos()` |
| `HubspotDealHandoffService::build()` | `HubspotValueResolver::resolve()` | DI via construtor (`private readonly HubspotValueResolver $resolver`) | ✓ WIRED | `HubspotDealHandoffService.php:26-29`, chamado nas linhas 66 e 79 |
| `HubspotWebhookController::persistirContratos()` | `ContratoServico::create()` | Loop sobre `$handoff->contracts_to_create` | ✓ WIRED | Linhas 363-397; grava as 11 colunas + `valor_contratado` |
| `HubspotWebhookController::persistirContratos()` | `HubspotEvento->payload['line_items_nao_mapeados']` | Warnings roteados por shape | ✓ WIRED | Linhas 417-442; `notes` da Company para warning legado, payload do evento para line item não mapeado |

### Behavioral Spot-Checks / Execução de Testes

| Suite | Comando | Resultado | Status |
|-------|---------|-----------|--------|
| `HubspotValueResolverTest` | `php artisan test tests/Unit/HubspotValueResolverTest.php` | 9/9 PASS | ✓ PASS |
| `Phase112HandoffServiceTest` | `php artisan test tests/Feature/Phase112HandoffServiceTest.php` | 8/8 PASS (34 asserções) | ✓ PASS |
| `Phase112HubspotHandoffWebhookTest` | `php artisan test tests/Feature/Phase112HubspotHandoffWebhookTest.php` | 6/6 PASS (31 asserções) | ✓ PASS |
| `Phase37WebhookLineItemsTest` (regressão) | `php artisan test tests/Feature/Phase37WebhookLineItemsTest.php` | 10/10 PASS | ✓ PASS |
| `Phase37LineItemsFetchTest` (regressão) | `php artisan test tests/Feature/Phase37LineItemsFetchTest.php` | 9/9 PASS | ✓ PASS |
| `Phase34HubspotWebhookTest` (regressão) | `php artisan test tests/Feature/Phase34HubspotWebhookTest.php` | 6/6 PASS | ✓ PASS |
| `Phase35HubspotV2Test` (regressão) | `php artisan test tests/Feature/Phase35HubspotV2Test.php` | 7/7 PASS | ✓ PASS |

**Total: 55/55 testes passando (280 asserções), executados nesta verificação (não apenas citados pelo SUMMARY).**

### Requirements Coverage

| Requisito | Plano de origem | Descrição | Status | Evidência |
|-----------|-----------------|-----------|--------|-----------|
| HUB-VAL-01 | 112-01 | Resolver mensal×anual | ✓ SATISFIED | `HubspotValueResolver::resolve()` + 9 testes unitários |
| HUB-VAL-02 | 112-02, 112-03 | Multi-line-item | ✓ SATISFIED | `HubspotDealHandoffService::build()` resolve cada item individualmente; teste `multi_line_item_cria_contratos_separados_por_line_item_id` |
| HUB-VAL-03 | 112-03 | Proveniência+confidence+warning gravados | ✓ SATISFIED | `persistirContratos()` grava as 11 colunas de auditoria |
| HUB-VAL-04 | 112-02 | Handoff service extraído | ✓ SATISFIED | `HubspotDealHandoffService` + `HubspotHandoffData` no namespace `App\Services\Hubspot` |
| HUB-VAL-05 | 112-03 | Controller fino, comportamento preservado | ✓ SATISFIED | `criarEmpresa()`/`persistirContratos()` orquestram; suíte de regressão 100% verde |

Nenhum requisito órfão encontrado — `REQUIREMENTS.md` não possui entradas formais `HUB-VAL-*` (milestone v20.0 documenta requisitos via ROADMAP.md diretamente), mas todos os 5 IDs citados no ROADMAP são cobertos por pelo menos um plano.

### Anti-Patterns Found

Nenhum `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER` encontrado nos arquivos de `app/Services/Hubspot/` ou no controller modificado. Nenhuma referência a `dedup`, `possivel_duplicidade`, `reprocess-event`, preenchimento de `company_data`/`contact_data` (escopo da Fase 113/114) vazando para o código desta fase.

### Escopo (fora do que é da Fase 112)

Confirmado via `git diff --stat` nos commits `1eed0439..247d04b2`: nenhum arquivo em `resources/js/` ou `app/Console/Commands/` foi tocado. `company_data`/`contact_data` do DTO permanecem `null` (nunca atribuídos fora do default do construtor) — nascem reservados para a Fase 113, conforme decisão registrada em `112-CONTEXT.md`.

### Deviation Registrada (não é gap — correção necessária ao próprio gate de regressão)

Durante o plano 112-03, o executor encontrou e corrigiu um bug real no ramo "indecidível" de `HubspotValueResolver::resolverSemLineItem()`: antes da correção, esse ramo sempre devolvia `amount/12` mesmo sem evidência de que o valor observado era anual, o que quebrava a própria INVARIANTE de não-regressão (`Phase34`/`Phase37` esperavam `valor_contratado=1500.0` e passariam a receber `125.0`). A correção (manter o valor bruto quando indecidível, com `confidence=low` + warning `valor_revisar`) foi commitada junto com o refactor do controller (`247d04b2`) e é pré-condição para o próprio SC5. Verificado: a suíte de regressão citada está 100% verde com esse fix aplicado.

## Gaps Summary

Nenhum gap encontrado. Os 5 Success Criteria do ROADMAP para a Fase 112 estão implementados, testados e comprovados por execução real da suíte (não apenas por citação em SUMMARY.md). O critério de aceite âncora da milestone v20.0 (line item mensal + amount/ARR anual → `valor_contratado` normalizado, `hubspot_valor_original` preservado) está fechado e coberto por teste E2E via webhook real (`Http::fake`, sem chamada de rede real). O escopo foi respeitado — nada de contato principal/enriquecimento/dedup/UI/replay (Fases 113/114) vazou para esta fase.

---

*Verificado: 2026-07-24T14:43:00Z*
*Verificador: Claude (gsd-verifier)*
