# Phase 112: HubspotValueResolver + extração do handoff service (NÚCLEO) - Context

**Gathered:** 2026-07-24
**Status:** Ready for planning
**Source:** Plano canônico `prompt-claude-otimizacao-comercial-hubspot.md` (Fases 2 e 6) — milestone v20.0. Depende da Fase 111 (colunas de auditoria já existem).

<domain>
## Phase Boundary

Esta é a fase-NÚCLEO da milestone: resolve o bug real R$ 36.000 (anual) × R$ 3.000 (mensal).

**Entrega:**
1. **`HubspotValueResolver`** (classe pura, testável, TDD-first) — decide o valor OPERACIONAL de um contrato a partir de `(Servico, lineItem, dealProps)`, distinguindo mensal × anual, e devolve também a proveniência (valor original, tipo, confiança, warning).
2. **`HubspotDealHandoffService`** (+ DTO `HubspotHandoffData`) — extrai do `HubspotWebhookController` a lógica de normalização de **valor/contratos** num serviço fino e testável. O controller passa a orquestrar (validar → idempotência → handoff → persistir → atualizar evento).
3. **Persistência do valor correto** — `contratos_servico.valor_contratado` recebe o valor operacional (mensal quando o serviço é mensal); as colunas de auditoria da Fase 111 (`hubspot_valor_original`, `hubspot_valor_original_tipo`, `hubspot_valor_normalizado_mensal`, `hubspot_valor_confidence`, `hubspot_valor_warning`, `hubspot_billing_frequency`, `hubspot_billing_period`, `hubspot_currency`, `hubspot_line_item_id`, `hubspot_product_id`, `hubspot_snapshot`) guardam como o valor foi obtido.

**FORA do escopo (Fase 113+):** escolha de contato principal, enriquecimento de company/contato, dedup de empresa existente, UI Comercial, comando de replay. O `HubspotDealHandoffService` nasce aqui cobrindo **valor/contratos**; ganha `contact_data`/`company_data`/dedup na Fase 113.

</domain>

<decisions>
## Implementation Decisions (LOCKED)

### INVARIANTE DE NÃO-REGRESSÃO (crítico)
- Hoje o line item faz `valor = price * quantity` (fallback `Servico.valor_padrao`) e o legado usa `deal.amount` (fallback `valor_padrao`) — ver `HubspotWebhookController::processarLineItems`/`processarServicoLegado`.
- Para os casos em que a lógica atual JÁ dá o valor operacional certo (line item com `recurringbillingfrequency = monthly` e `price` numérico), o resolver DEVE retornar **exatamente o mesmo valor** (`price * quantity`), `confidence=high`. Assim os testes atuais (`Phase37LineItemsFetchTest`, `Phase37WebhookLineItemsTest`, `Phase34/35`) continuam verdes sem alteração de asserção de valor.
- A MUDANÇA de comportamento só ocorre no caso **serviço mensal + valor anual** (frequency=annually ou deal.amount anual), que hoje gravava o valor cheio e passa a gravar o mensal normalizado. Esse caso ganha testes NOVOS.

### `HubspotValueResolver::resolve(Servico $servico, array $lineItem, array $dealProps): array`
Saída (array associativo):
- `valor_operacional` (float) — o que vai para `valor_contratado`
- `valor_original` (float|null) — valor bruto observado no HubSpot
- `valor_original_tipo` (string) — ex.: `unit_price`, `net_price`, `mrr`, `arr`, `deal_amount`, `deal_amount_annual`
- `normalizado_mensal` (float|null)
- `billing_frequency` (string|null) — ex.: `monthly`, `annually`
- `billing_period` (string|null) — ex.: `P1Y`
- `confidence` (string) — `high` | `medium` | `low`
- `warning` (string|null)

Regras (prompt Fase 6):
1. **Line item + `Servico::tipo_cobranca === Servico::TIPO_MENSAL`:**
   - `recurringbillingfrequency === 'monthly'` + `price` numérico → `valor_operacional = price * quantity`; `confidence=high`. (= comportamento atual, sem regressão.)
   - `recurringbillingfrequency === 'annually'` + `price` numérico → se `hs_mrr` numérico usar `hs_mrr`; senão `price * quantity / 12`; `confidence=high` se `hs_recurring_billing_period` indicar 12 meses (`P1Y`), senão `medium`.
   - `hs_mrr` numérico presente → fonte forte para mensal.
   - só `amount` do line item e parece anual → se `amount / 12` ≈ `Servico.valor_padrao` ou ≈ `price` (tolerância), usar `amount / 12` + `warning` de inferência.
2. **Line item + `Servico::tipo_cobranca === Servico::TIPO_UNICA`:** usar `amount` do line item se numérico, senão `price * quantity`; **NUNCA dividir por 12**.
3. **Sem line item (fluxo legado, `deal.amount`):**
   - serviço mensal e `deal.amount / 12` ≈ `Servico.valor_padrao` (tolerância) → `deal.amount / 12`.
   - serviço mensal e `deal.amount` parece mensal (≈ valor_padrao) → `deal.amount`.
   - indecidível → usar o valor mais conservador E marcar pendência `valor_revisar` (via warning; a pendência na UI é da Fase 114).
4. **Nunca sobrescrever valor de baixa confiança sem deixar rastro** (warning + colunas de auditoria).
5. **Tolerância:** helper com margem percentual pequena (default 5%, configurável) para comparar `amount/12` com `valor_padrao`/`price`. Ex.: `36000/12 = 3000`, catálogo `3000` → usar `3000`.
6. **Multi-line-item:** resolver CADA line item individualmente; contratos separados por `hubspot_line_item_id` distinto (não consolidar).

### `HubspotDealHandoffService` + `HubspotHandoffData` (DTO)
- Namespace novo `App\Services\Hubspot\`.
- Nesta fase o DTO carrega ao menos: `deal_data`, `line_items` (normalizados), `contracts_to_create` (cada um já com valor operacional + campos de auditoria resolvidos), `warnings`, `confidence`. `company_data`/`contact_data`/dedup ficam para a Fase 113 (deixar o DTO extensível, não obrigatório preencher agora).
- O service recebe o deal já buscado (ou o `dealId` + client injetado) e produz o DTO; a decisão de valor delega ao `HubspotValueResolver`.
- Controller fica fino: mantém validação HMAC, filtro de stage, idempotência, persistência (DB::transaction), atualização de `hubspot_eventos`, notificação Comercial — mas a montagem de contratos/valor sai para o handoff service. **Comportamento observável do fluxo legado preservado** (só o valor mensal×anual muda, conforme invariante acima).

### Persistência
- `valor_contratado` = `valor_operacional`.
- Preencher as colunas de auditoria da Fase 111 no `ContratoServico`: `hubspot_line_item_id`, `hubspot_product_id`, `hubspot_billing_frequency`, `hubspot_billing_period`, `hubspot_currency`, `hubspot_valor_original`, `hubspot_valor_original_tipo`, `hubspot_valor_normalizado_mensal`, `hubspot_valor_confidence`, `hubspot_valor_warning`, `hubspot_snapshot`.
- Manter a linha em `observacoes` (`tipo_cobranca: ...`) que a Phase 37 grava? SIM — não remover fonte legada; as colunas dedicadas são adicionais (prompt: não transformar `observacoes` em fonte única, mas também não quebrar o que já existe).

### Claude's Discretion
- Assinatura exata do handoff service (recebe `dealId` + client, ou o array já buscado), organização interna, nomes de métodos privados.
- Se o resolver é injetável no handoff service via DI ou instanciado direto.
- Nome exato do helper de tolerância e onde vive (no resolver como método privado é aceitável).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plano canônico
- `prompt-claude-otimizacao-comercial-hubspot.md` — Fase 2 (handoff service + DTO) e Fase 6 (regras do resolver, saída, casos de teste âncora).

### Código a refatorar/estender (source of truth)
- `app/Http/Controllers/Api/HubspotWebhookController.php` — `processarLineItems()` (~linha 360-472: valor=price*qty + tipo_cobranca em observacoes) e `processarServicoLegado()` (~linha 486-520: valor=deal.amount). É daqui que a lógica de valor sai para o handoff service.
- `app/Models/Servico.php` — `TIPO_MENSAL='mensal'` / `TIPO_UNICA='unica'` (linhas 44-45); `tipo_cobranca`, `valor_padrao` (cast decimal:2).
- `app/Models/ContratoServico.php` — `$fillable` já com as colunas `hubspot_*` da Fase 111 (adicionadas em 111-03); casts json de snapshot.
- `app/Services/HubspotApiClient.php` — `fetchDealLineItems` já retorna as 17 props (Fase 111) incluindo `amount`, `hs_mrr`, `hs_arr`, `hs_recurring_billing_period`, `hs_line_item_currency_code`, `hs_product_id`.

### Testes de regressão (DEVEM continuar verdes)
- `tests/Feature/Phase37WebhookLineItemsTest.php` — cria contrato com valor a partir de line item.
- `tests/Feature/Phase37LineItemsFetchTest.php` — contrato de fetch (não muda aqui).
- `tests/Feature/Phase34HubspotWebhookTest.php`, `tests/Feature/Phase35HubspotV2Test.php` — fluxo legado deal.amount.

### Fase anterior
- `.planning/phases/111-.../111-CONTEXT.md` e `111-*-SUMMARY.md` — colunas de auditoria e client ampliado que esta fase consome.

</canonical_refs>

<specifics>
## Specific Ideas

- Casos de teste âncora do `HubspotValueResolverTest` (prompt Fase 10):
  1. monthly price 3000 + deal amount 36000 → `valor_operacional=3000` (high).
  2. annually price 36000 + period P1Y → `valor_operacional=3000`.
  3. line item `hs_mrr=3000`, `hs_arr=36000` → usa MRR (3000).
  4. serviço único amount 36000 → **não** divide por 12 (36000).
  5. sem line item, deal.amount 36000, `valor_padrao=3000` → 3000 com warning de inferência.
  6. sem line item, deal.amount 35000, sem `valor_padrao` compatível → marca `valor_revisar`.
- `recurringbillingfrequency` válidas incluem monthly/annually/quarterly/etc; `hs_recurring_billing_period` ISO-8601 (`P1Y`).
- Critério de aceite âncora da milestone: deal fechado ganho com line item mensal R$3.000 + amount/ARR R$36.000 → `valor_contratado=3.000`; os R$36.000 ficam em `hubspot_valor_original`.

</specifics>

<deferred>
## Deferred Ideas

- Escolha de contato principal + `company_data`/`contact_data` no DTO + enriquecimento estruturado → Fase 113.
- Dedup de empresa existente (hubspot_company_id/cnpj/domain/nome) → Fase 113.
- UI Comercial (valor operacional/original/confiança/warning + pendência `valor_revisar`) → Fase 114.
- Comando `hubspot:reprocess-event` → Fase 114.
- Suite E2E ampla + doc da regra de valor → Fase 115.

</deferred>

---

*Phase: 112-hubspotvalueresolver-extra-o-do-handoff-service-v20-0-n-cleo*
*Context gathered: 2026-07-24 — sintetizado do plano canônico + código real (lean)*
