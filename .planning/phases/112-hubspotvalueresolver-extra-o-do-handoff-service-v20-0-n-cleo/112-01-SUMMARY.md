---
phase: 112-hubspotvalueresolver-extra-o-do-handoff-service-v20-0-n-cleo
plan: 01
subsystem: api
tags: [hubspot, value-resolver, tdd, comercial, php]

# Dependency graph
requires:
  - phase: 111-fundacao
    provides: "colunas de auditoria hubspot_* em contratos_servico + HubspotApiClient com 17 props de line item (base v3)"
provides:
  - "App\\Services\\Hubspot\\HubspotValueResolver — classe pura que resolve valor operacional mensal x anual"
  - "Suite unitária HubspotValueResolverTest com 9 casos (6 âncora + paridade + 2 bordas de tolerância 5%)"
affects: [112-02, 112-03, 113, 114]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Namespace App\\Services\\Hubspot\\ para o núcleo comercial HubSpot"
    - "Classe pura (sem I/O/banco) testável via new Model([...]) em memória"
    - "Helper de tolerância percentual com epsilon no denominador (evita /0)"

key-files:
  created:
    - app/Services/Hubspot/HubspotValueResolver.php
    - tests/Unit/HubspotValueResolverTest.php
  modified: []

key-decisions:
  - "9 métodos de teste (não 10) — o bloco <behavior> do plano enumera exatamente 9 casos (1, 1b, 2, 3, 4, 5, 5b, 5c, 6); o número '10' na acceptance_criteria do plano é uma inconsistência de contagem do próprio plano, não uma lacuna de cobertura — todos os 6 casos-âncora + paridade + 2 bordas de tolerância estão cobertos"
  - "hs_mrr é tratado como fonte forte incondicional (confidence=high mesmo sem hs_recurring_billing_period=P1Y) — distinto do ramo price/12 que só é high quando o período confirma 12 meses; ambos batem com os casos-âncora do prompt"
  - "valor_original no ramo hs_mrr usa hs_arr quando presente (fallback price*quantity) — não estava 100% explícito no plano, decisão de implementação consistente com 'valor bruto observado no HubSpot'"

patterns-established:
  - "Guard is_numeric()+cast (float) em toda leitura de price/amount/valor_padrao antes de qualquer cálculo — nunca lança exceção, cai no ramo conservador com warning"
  - "confidence=low sempre acompanhado de warning contendo a marca 'valor_revisar' (grep-ável para telas futuras de pendência)"

requirements-completed: [HUB-VAL-01]

# Metrics
duration: ~20min
completed: 2026-07-24
---

# Phase 112 Plan 01: HubspotValueResolver Summary

**Classe pura `App\Services\Hubspot\HubspotValueResolver::resolve()` que decide o valor operacional mensal×anual de um contrato HubSpot (bug real R$36.000 anual × R$3.000 mensal), com proveniência completa e tolerância de 5% para inferência sem line item.**

## Performance

- **Duration:** ~20 min
- **Tasks:** 2 (TDD RED + GREEN)
- **Files modified:** 2 (1 criado na Tarefa 1, editado na Tarefa 2)

## Accomplishments
- `HubspotValueResolver::resolve(Servico, lineItem, dealProps): array` implementado cobrindo as 6 regras do prompt Fase 6 (line item mensal monthly/annually/hs_mrr, serviço único nunca /12, fluxo legado deal.amount com/sem tolerância, ramo conservador com `valor_revisar`).
- INVARIANTE de não-regressão preservado e provado por teste: `monthly` + `price` numérico → `price*quantity` exato, `confidence=high` — idêntico ao comportamento atual do `HubspotWebhookController::processarLineItems`.
- 6 casos-âncora do prompt (Fase 10) + paridade qty>1 + 2 bordas do limite de tolerância de 5% (dentro/fora) todos verdes.
- Suite de regressão `Phase37WebhookLineItemsTest` (10 testes) intacta — o resolver ainda não está plugado no controller (isso é o plano 112-02/112-03).

## Task Commits

Each task was committed atomically (TDD RED→GREEN):

1. **Tarefa 1 (RED): esqueleto + suite dos 6 casos-âncora** - `1eed0439` (test)
2. **Tarefa 2 (GREEN): implementar resolve() com as regras 1..6** - `d0dc2c51` (feat)

## Files Created/Modified
- `app/Services/Hubspot/HubspotValueResolver.php` - classe pura com `resolve()` + 2 métodos privados de resolução (com/sem line item) + helper de tolerância `aproximadamente()` + casts defensivos `numericoOu()`/`numericoOuNull()`.
- `tests/Unit/HubspotValueResolverTest.php` - suite unitária, 9 métodos, sem `RefreshDatabase`, `Servico` instanciado em memória com `new Servico([...])`.

## Decisions Made
- **Contagem de testes 9 vs "10" do plano:** o bloco `<behavior>` da Tarefa 1 enumera exatamente 9 casos numerados (1, 1b, 2, 3, 4, 5, 5b, 5c, 6). A lista de nomes de método no `<action>` também tem 9 itens. O texto "10 métodos no total" no mesmo parágrafo e no `<acceptance_criteria>` é uma inconsistência interna do próprio plano — implementei os 9 casos que o `<behavior>` de fato descreve, o que satisfaz 100% do `must_haves.truths` e dos 6 casos-âncora + paridade + tolerância.
- **hs_mrr sempre confidence=high:** quando `hs_mrr` está presente no line item, a regra decisória do prompt ("hs_mrr numérico presente → fonte forte para mensal") foi implementada como incondicional (não depende de `hs_recurring_billing_period`), distinto do ramo `price*quantity/12` que exige período P1Y para ser high. O caso-âncora 3 do prompt (hs_mrr=3000, sem billing_period no teste) confirma essa leitura.
- **valor_original no ramo hs_mrr:** usa `hs_arr` quando presente (senão `price*quantity`, senão o próprio `hs_mrr`) — não estava explícito letra-por-letra no plano, mas é a leitura mais coerente de "valor bruto observado no HubSpot" e bate com o caso-âncora 3 (hs_arr=36000).

## Deviations from Plan

None (funcionais) - plano executado conforme escrito, com a única ressalva documentada acima sobre a contagem de métodos de teste (9, não 10 — o plano tem uma inconsistência interna nesse número específico, sem afetar a cobertura exigida).

## Issues Encountered
- `php` não estava no PATH do shell Git Bash; usado caminho absoluto `/c/xampp/php/php.exe artisan test` (ambiente Windows/XAMPP). Nenhuma mudança de código necessária.

## User Setup Required
None - nenhuma configuração de serviço externo necessária. Classe pura sem I/O.

## Next Phase Readiness
- `HubspotValueResolver` pronto para ser consumido pelo `HubspotDealHandoffService` no plano 112-02 (extração da lógica de `processarLineItems`/`processarServicoLegado` do controller).
- Nenhum bloqueio conhecido. O controller (`HubspotWebhookController`) ainda não foi tocado — isso é intencional, escopo do 112-02/112-03.

---
*Phase: 112-hubspotvalueresolver-extra-o-do-handoff-service-v20-0-n-cleo*
*Completed: 2026-07-24*

## Self-Check: PASSED

- FOUND: app/Services/Hubspot/HubspotValueResolver.php
- FOUND: tests/Unit/HubspotValueResolverTest.php
- FOUND: 1eed0439 (RED commit)
- FOUND: d0dc2c51 (GREEN commit)
