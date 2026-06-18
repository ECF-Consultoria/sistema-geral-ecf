---
phase: 37-onboarding-comercial-unificado-via-hubspot-line-items
plan: 03
subsystem: hubspot-integration
tags: [hubspot, api, line-items, wrapper, http-fake, resiliencia]
requires:
  - 37-01  # catalogo servicos.setor + filtros
  - 37-02  # tabela hubspot_line_item_mapping + paraNome
provides:
  - HubspotApiClient::fetchDealLineItems(string $dealId): array
  - Suite Phase37LineItemsFetchTest (9 testes / 28 assertions)
affects:
  - Plan 37-04 webhook materializa contratos_servico consumindo este metodo
tech-stack:
  added: []
  patterns:
    - Http::fake com URL patterns + wildcard glob para query strings
    - Mockery LoggerInterface mock para validar canal + contexto de log
    - Resiliencia em 2 camadas: associations [] em qualquer 4xx/5xx; line_items individual loga + pula
key-files:
  created:
    - tests/Feature/Phase37LineItemsFetchTest.php
  modified:
    - app/Services/HubspotApiClient.php
decisions:
  - "fetchDealLineItems mantem a mesma estrategia resiliente de fetchAssociatedCompanyId (Phase 34) para associations — 4xx/5xx vira [] sem propagar excecao, alinhado com a semantica 'deal sem line items eh estado valido'"
  - "Falha em line_items/{id} individual NAO derruba o batch: loga warning no canal ecf-webhooks com deal_id+line_item_id+status e continua o foreach"
  - "Cast defensivo de price (is_numeric guard -> float) e quantity (is_numeric guard -> int) para fornecer shape estavel ao consumidor (Plan 37-04 ContratoServico)"
  - "Loop sequencial 1×1 (nao batch POST) — deals tipicamente tem 1-3 line items; otimizacao via /batch/read fica para fase futura se latencia virar gargalo (T-37-06 accept)"
  - "Token NUNCA logado: contexto do warning contem apenas IDs + status HTTP; teste explicito (test_log_de_falha_individual_nao_vaza_o_bearer_token) cobre T-37-05 do threat model"
  - "Loop foreach normaliza ids para (string) e filtra null/vazio antes da segunda camada de chamadas"
metrics:
  duration: ~18 min
  completed: 2026-06-18
---

# Phase 37 Plan 37-03: HubspotApiClient fetchDealLineItems Summary

Wrapper HTTP resiliente que encadeia GET `/associations/line_items` + GET `/line_items/{id}` para fornecer ao Plan 37-04 (webhook) a lista de line items normalizada de um deal HubSpot, com cast defensivo de price/quantity e zero vazamento de Bearer token nos logs.

## What Was Built

Estendeu `HubspotApiClient` (Phase 34) com o metodo publico `fetchDealLineItems(string $dealId): array` — o sexto wrapper da Service, mantendo o padrao das 5 chamadas anteriores (Bearer via Http::withToken + base `https://api.hubapi.com` fixa). Suite Phase37LineItemsFetchTest cobre 9 cenarios via `Http::fake` (deal com 2 itens mapeados, deal sem associations, 404, 500, falha individual em 1-de-N, recurringbillingfrequency null preservado, casts price/quantity, e nao-vazamento de token no log).

## Files Changed

| Arquivo | Tipo | O que mudou |
|---------|------|-------------|
| `app/Services/HubspotApiClient.php` | modified | + import `use Illuminate\Support\Facades\Log;` + novo metodo publico `fetchDealLineItems` (~80 linhas, PHPDoc em pt-BR explicando 2-call pattern + resiliencia) |
| `tests/Feature/Phase37LineItemsFetchTest.php` | created | 9 testes Http::fake / Mockery, sem RefreshDatabase (puro HTTP wrapper) |

## Commits

| Hash | Tipo | Mensagem |
|------|------|----------|
| `3cbb1d6` | test | adiciona suite Phase37LineItemsFetchTest (RED) — 9/9 falham com 'undefined method' |
| `b2adcc7` | feat | HubspotApiClient::fetchDealLineItems resiliente (GREEN) — 9/9 verdes, 28 assertions |

## How It Was Verified

- **Automated:** `php artisan test --filter Phase37LineItemsFetchTest` -> 9 passed (28 assertions, 0.79s)
- **Regression Phase 34:** `php artisan test --filter Phase34HubspotWebhookTest` -> 6 passed (49 assertions) — wrapper anterior intocado
- **Regression Phase 37 completo:** `php artisan test --filter Phase37` -> 35 passed (134 assertions) — Plans 37-01 e 37-02 intactos
- **Inspecao visual:** `grep -n 'Bearer' app/Services/HubspotApiClient.php` -> apenas docblocks (linhas 21 e 153), nenhum log emite o token

## Threat Model Coverage

| Threat ID | Status | Como foi mitigado |
|-----------|--------|-------------------|
| T-37-05 (info disclosure Bearer) | mitigated | Token vem de `config('services.hubspot.access_token')` + via `Http::withToken()`; contexto do warning loga somente `deal_id`, `line_item_id`, `status`; teste `test_log_de_falha_individual_nao_vaza_o_bearer_token` valida via Mockery LoggerInterface mock que nenhuma chamada de `warning()` contem 'fake-token' ou 'Bearer' |
| T-37-06 (DoS loop GET) | accept | Deals tipicos tem 1-3 line items; sem rate-limit local; otimizacao para batch endpoint deferida |
| T-37-07 (tampering payload) | mitigated | Cast defensivo: `price` so vira float quando `is_numeric` retorna true (senao null); `quantity` idem para int; chaves ausentes preservam null |

## Deviations from Plan

Nenhuma. O plan foi executado exatamente como escrito.

Pequeno ajuste tecnico durante GREEN: a versao inicial do teste `test_line_item_individual_500_pula_item_e_loga_warning` usava `Log::spy()` + `Log::shouldHaveReceived('channel')`, mas `Log::spy()` retorna null em `Log::channel('ecf-webhooks')` (a propria implementacao do spy nao encadeia o canal). Substituido por `Log::shouldReceive('channel')->andReturn($loggerMock)` com Mockery — mesmo padrao usado no teste de nao-vazamento de token. Nao caracteriza desvio do plan: cobre a mesma garantia (warning chamado com IDs+status corretos), apenas com primitiva Mockery correta.

## Authentication Gates

Nenhum. Plano backend-only, sem interacao externa real (Http::fake mocka tudo).

## Known Stubs

Nenhum stub introduzido.

## Decisoes Tecnicas em Detalhe

### Por que loop 1×1 e nao /batch/read

O endpoint `POST /crm/v3/objects/line_items/batch/read` aceitaria todos os IDs em 1 chamada e seria a otimizacao obvia para deals com muitos line items. Mantive 1×1 porque:

1. **Realidade ECF:** deals fechados ganhos da Phase 37 tem tipicamente 1-3 line items (1 servico principal + eventualmente upsell);
2. **Resiliencia granular:** se 1 item retorna 500 num batch, o endpoint do HubSpot pode retornar 200 OK com o item ausente — silencioso. No loop 1×1, cada falha eh visivel via warning;
3. **Threat T-37-06 marcado como `accept`:** custo de latencia (~3 GETs em vez de 1) eh aceitavel no contexto do webhook assincrono;
4. **Refactor futuro trivial** se 95th percentile crescer.

### Por que `(string)` no ID e `is_numeric` em price/quantity

HubSpot retorna `id` como `int` no `results.0.id` da associations mas `string` em algumas variantes do payload — normalizar para string sempre evita comparacao tipo-fraca downstream (Plan 37-04 vai fazer lookup em `hubspot_line_item_mapping.hs_product_id`, tipado `string`).

Para `price` e `quantity`, HubSpot envia string mesmo para valores numericos (`'500'`, `'1.0'`). O `is_numeric` guard cobre o edge case onde o campo vem como `null` ou string vazia (deal mal preenchido pelo comercial no HubSpot) — em vez de propagar `(float) null = 0.0` (que pareceria valor real), retorna null e deixa o consumidor decidir.

### Canal `ecf-webhooks` reusado

Em vez de criar um novo canal `hubspot-line-items`, reusei `ecf-webhooks` (criado na Phase 26 receiver HMAC, ja consumido pelo Plan 34-04 webhook controller). Mantem todos os eventos da pipeline HubSpot no mesmo arquivo rotativo diario, com prefixo `[HubSpot Webhook]` distinguindo dos `[ECF Webhook]` da integracao Drive.

## Para o Plan 37-04 (Wave 2 — webhook estendido)

A assinatura final:

```php
public function fetchDealLineItems(string $dealId): array
// Retorna array<int, array{
//   id: string,
//   name: ?string,
//   price: ?float,
//   quantity: ?int,
//   hs_product_id: ?string,
//   recurringbillingfrequency: ?string,
// }>
```

Pode ser consumido dentro de `DB::transaction(...)` apos `criarEmpresa(...)`:

```php
$lineItems = $client->fetchDealLineItems($dealId);
foreach ($lineItems as $li) {
    $servicoId = $mapping[$li['hs_product_id']] ?? HubspotLineItemMapping::paraNome($li['name'])?->servico_id;
    if (!$servicoId) {
        // emit pendencia 'servico_nao_reconhecido' (Plan 37-05)
        continue;
    }
    ContratoServico::create([
        'company_id'    => $company->id,
        'servico_id'    => $servicoId,
        'valor_contratado' => $li['price'],
        'tipo_cobranca' => $li['recurringbillingfrequency'] ? 'mensal' : 'unica',
        // ...
    ]);
}
```

Resiliencia ja embutida: se `fetchDealLineItems` retorna `[]` (deal sem line items, associations 4xx/5xx), o loop simplesmente nao executa — comportamento que o Plan 37-04 pode tratar como pendencia `sem_servico`.

## Self-Check: PASSED

Arquivos confirmados:
- FOUND: `app/Services/HubspotApiClient.php` (metodo fetchDealLineItems linha ~141)
- FOUND: `tests/Feature/Phase37LineItemsFetchTest.php` (9 testes)

Commits confirmados:
- FOUND: `3cbb1d6` (test RED)
- FOUND: `b2adcc7` (feat GREEN)

Testes:
- FOUND: 9/9 Phase37LineItemsFetchTest verdes (28 assertions)
- FOUND: 6/6 Phase34HubspotWebhookTest verdes (49 assertions, zero regressao)
- FOUND: 35/35 Phase37 (todos plans) verdes (134 assertions)
