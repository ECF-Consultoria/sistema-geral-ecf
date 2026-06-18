---
phase: 37-onboarding-comercial-unificado-via-hubspot-line-items
plan: 04
subsystem: hubspot-integration
tags: [hubspot, webhook, line-items, contratos-servico, mapping, transaction, mlb-empresa, idempotencia]
requires:
  - 37-01  # catalogo servicos.setor (define setor por servico)
  - 37-02  # tabela hubspot_line_item_mapping + paraNome case-insensitive
  - 37-03  # HubspotApiClient::fetchDealLineItems resiliente
  - 34-04  # HubspotWebhookController base (HMAC v3 + fluxo deal->Company)
provides:
  - "HubspotWebhookController consome line items e materializa ContratoServico via mapping"
  - "Branch atomico em DB::transaction unica: line items > fluxo legado Phase 34/35"
  - "Warning persistido (line_items_nao_mapeados) preservando status='processado' + log canal ecf-webhooks"
  - "Suite Phase37WebhookLineItemsTest (10 testes / 57 assertions) cobrindo cenarios ponta-a-ponta"
affects:
  - "Plan 37-05 (listagem comercial /comercial/empresas/listagem) consome pendencia sem_servico das empresas com line_items_nao_mapeados"
  - "Plan 37-06 (UI admin /sistema/hubspot-line-items) permite Comercial cadastrar mapping novo sem deploy"
  - "Phase 35-03 EmpresaHubspotPendenteNotification segue disparando para empresas vindas por line items (calcularPendencias inalterado)"
tech-stack:
  added: []
  patterns:
    - "Branch DB::transaction atomico (line items > fluxo legado) sem fragmentar a unidade Company+ContratoServico+MlbEmpresa"
    - "Warnings nao-bloqueantes via HubspotEvento.payload + canal de log (status final 'processado')"
    - "tipo_cobranca anotada em ContratoServico.observacoes (coluna nao existe em contratos_servico; vive em servicos)"
    - "Helpers extraidos como metodos privados (processarLineItems / processarServicoLegado / rotearImplementacao) para clareza + testabilidade"
key-files:
  created:
    - "tests/Feature/Phase37WebhookLineItemsTest.php"
  modified:
    - "app/Http/Controllers/Api/HubspotWebhookController.php"
key-decisions:
  - "tipo_cobranca anotada em observacoes (NAO em coluna nova): contratos_servico nao tem coluna tipo_cobranca; ela vive em Servico. Phase 37 nao altera schema — preserva auditoria sem migration extra"
  - "Branch line items > legado dentro do mesmo DB::transaction: passa \$evento via use() para permitir gravar payload['line_items_nao_mapeados'] na mesma unidade atomica de criarEmpresa"
  - "Guard contra duplicacao MlbEmpresa por empresa: 2 line items mapeados para Polos+Assessoria criam apenas a 1a; rotearImplementacao checa MlbEmpresa::where('company_id', X)->exists() antes de criar"
  - "Mapping inativo (paraNome retorna null via scope ativo) tratado IGUAL a mapping ausente: warning + skip; admin pode desativar sem perder auditoria"
  - "Status='processado' mesmo com line items nao mapeados — webhook retorna 200; pendencia comercial vira responsabilidade da listagem Comercial (Plan 37-05)"
  - "Test setUp limpa servicos+mappings+contratos antes de cada cenario para isolar contagens (RefreshDatabase aplica seeds automaticos do Phase 14/37 que poluiriam Servico::count)"
  - "Http::fake ordem importa (first-match-wins): URLs especificas (associations/companies, line_items/X) ANTES do glob /deals/{id}* — senao o glob de deal capturaria todas as associations"
patterns-established:
  - "Branch DB::transaction por origem de servico (line items vs nome do deal) preservando fluxo legado"
  - "Extracao de blocos legados em metodos privados nomeados — facilita teste e leitura sem mudar comportamento"
  - "Warnings persistidos no payload do evento (auditoria) + log no canal ecf-webhooks (operacional)"
requirements-completed:
  - REQ-37-04
duration: ~22min
completed: 2026-06-18
---

# Phase 37 Plan 37-04: Webhook HubSpot consome line items + materializa ContratoServico Summary

**HubspotWebhookController estendido com `processarLineItems` + `processarServicoLegado` + `rotearImplementacao`: empresa criada por webhook nasce COM serviços + valores + setor preenchidos automaticamente via mapeamento HubspotLineItemMapping, dentro da mesma DB::transaction de criarEmpresa, com fallback total para o fluxo Phase 34/35 quando o deal não traz line items.**

## Performance

- **Duration:** ~22 min
- **Started:** 2026-06-18T18:59:56Z
- **Completed:** 2026-06-18T19:06:06Z
- **Tasks:** 2 (TDD: RED → GREEN combinado)
- **Files modified:** 2 (1 controller + 1 nova suite Feature)

## Accomplishments

- Coração da Phase 37 entregue: deal HubSpot fechado-ganho com line items mapeados gera Company + N ContratoServico atomicamente, sem ação manual do Comercial
- Branch implementado dentro da mesma `DB::transaction` do `criarEmpresa` original — atomicidade total preservada
- Line items HubSpot têm prioridade absoluta; quando ausentes, fluxo Phase 34/35 (`servico_ecf` do deal + `amount`) continua funcionando intacto
- Mapeamento desconhecido vira warning no `HubspotEvento.payload['line_items_nao_mapeados']` + log no canal `ecf-webhooks` — webhook retorna 200 e empresa entra na listagem Comercial com pendência (Plan 37-05)
- Roteamento `MlbEmpresa` (Polos/Assessoria/Incubadora) agora é avaliado por CADA serviço criado (line items OU legado), com guard contra duplicação quando 2 line items mapeiam para tipos diferentes
- Idempotência Phase 34-04 preservada: 2º webhook do mesmo deal cai como `ignorado` antes de chamar `criarEmpresa`, sem reentrar no loop
- Zero regressão validada em Phase 34HubspotWebhookTest (6/6), Phase 35OnboardingPrazoTest (6/6), Phase 37 total (45/45)

## Task Commits

Plan executado em TDD enxuto — RED da suíte completa + GREEN único do controller (ambas as tasks materializam o mesmo arquivo controller, então foram entregues juntas no GREEN):

1. **Task 2 RED: Suíte Phase37WebhookLineItemsTest (10 cenários)** — `0f42abb` (test: adiciona suite Phase37WebhookLineItemsTest)
2. **Task 1 GREEN: HubspotWebhookController consome line items + materializa ContratoServico** — `b26b42d` (feat: HubspotWebhookController consome line items)

**Plan metadata:** _commit final docs(37-04) gerado nesta etapa_

## Files Created/Modified

- `app/Http/Controllers/Api/HubspotWebhookController.php` — modificado:
  - `use App\Models\HubspotLineItemMapping;` adicionado
  - `processar()` injeta `$lineItems = $api->fetchDealLineItems(...)` ANTES de `criarEmpresa`
  - `criarEmpresa()` aceita 2 params novos: `array $lineItems = []` + `?HubspotEvento $evento = null`
  - Bloco legado `Tenta vincular ContratoServico` + roteamento MlbEmpresa SUBSTITUÍDO por branch line items vs legado dentro da mesma `DB::transaction`
  - 3 métodos privados novos: `processarLineItems()`, `processarServicoLegado()`, `rotearImplementacao()`
  - Log `[HubSpot Webhook] Empresa criada` agora inclui `line_items_total` no contexto
- `tests/Feature/Phase37WebhookLineItemsTest.php` — criado (10 cenários / 57 assertions):
  - Helpers `assinatura`, `servidor`, `criarServico`, `criarMapping`, `mockHubspot`, `dispararWebhook`
  - T1 deal 1 line item mapeado → 1 ContratoServico
  - T2 deal 2 line items mapeados → 2 ContratoServico distintos
  - T3 line item não mapeado → empresa sem contrato + warning no payload
  - T4 deal sem line items → cai no fluxo legado Phase 34/35 (servico_ecf + amount)
  - T5 `recurringbillingfrequency=monthly` → observações registram `mensal`
  - T6 `recurringbillingfrequency` ausente → observações registram `unica`
  - T7 idempotência: 2º webhook não duplica (1 processado, 1 ignorado)
  - T8 line item mapeado para 'Polos' → cria `MlbEmpresa(tipo=POLO)`
  - T9 mapping inativo → comportamento idêntico a ausente (warning + skip)
  - T10 zero regressão: replica âncora Phase 34 (deal closedwon + servico_ecf + amount)

## Decisions Made

- **`tipo_cobranca` anotada em `observacoes` (não em coluna nova):** `contratos_servico` não tem coluna `tipo_cobranca` (ela vive em `servicos`). Phase 37 não altera schema — preserva a informação derivada do `recurringbillingfrequency` em `observacoes` ("tipo_cobranca: mensal (HubSpot line_item: MAP)") sem migration extra. Plan original previa esse fallback ("Se ContratoServico NÃO tem essa coluna, REMOVER esse campo do create. Ajustar action accordingly.")
- **Branch line items vs legado dentro do mesmo `DB::transaction`:** plan deu a opção de mover line items para fora; escolhi DENTRO (D-04 atomicidade Phase 37). `$evento` passado via `use ()` no closure permite gravar `payload['line_items_nao_mapeados']` na mesma unidade atômica de `criarEmpresa`.
- **Guard contra duplicação `MlbEmpresa`:** quando 2 line items mapeiam para Polos+Assessoria na mesma empresa, `rotearImplementacao()` cria a 1ª e pula as demais via `MlbEmpresa::where('company_id', X)->exists()`. Sem isso, o `foreach $servicosCriados` criaria 2 MlbEmpresa por empresa.
- **Mapping inativo == mapping ausente:** `HubspotLineItemMapping::paraNome()` já filtra por `scope ativo()` (Plan 37-02). Adicionei guard defensivo `!$mapping->servico->ativo` para também tratar Servico inativo. Caso T9 valida.
- **Status `processado` mesmo com line items não mapeados:** webhook retorna 200 (HubSpot não retentaria), pendência comercial cai na listagem Comercial (Plan 37-05). Não-mapeado **não é erro** — é estado válido onde admin precisa cadastrar mapeamento novo via UI Plan 37-06/07.
- **Test `setUp` limpa `servicos` + `hubspot_line_item_mapping` + `contratos_servico` antes de cada cenário:** RefreshDatabase aplica seeds automáticos (Phase 14 cadastra catálogo + Phase 37-02 cadastra 8 mappings); sem o reset, contagens explodiriam (`Servico::count() === 1` viraria 9+, mappings idem). Mesmo padrão Plan 37-02.
- **Http::fake ordem importa (first-match-wins):** URLs específicas (`/associations/companies`, `/line_items/{id}*`) ANTES do glob `/deals/{id}*` — senão o glob captura todas as variantes de associations. Diferente do padrão Phase 34 onde só tinha 1 deal + 1 company; com line items + 2 associations + N items, a ordem se tornou crítica.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `tipo_cobranca` registrado em `observacoes` (não em coluna inexistente)**
- **Found during:** Task 1 (durante leitura de `app/Models/ContratoServico.php` + migration `2026_05_26_120002_create_contratos_servico_table.php`)
- **Issue:** Plan diz "tipo_cobranca = ... ? 'mensal' : 'unica'" como campo no `ContratoServico::create`, mas a tabela `contratos_servico` **NÃO TEM** coluna `tipo_cobranca` (vive em `servicos`). Tentar `'tipo_cobranca' => ...` no create geraria SQL error em tempo de execução.
- **Fix:** Anotado o valor derivado em `observacoes` (`"tipo_cobranca: {$tipoCobranca} (HubSpot line_item: {$nome})"`) — preserva a informação Phase 37 + audit trail sem migration extra. Tests T5/T6 ajustados para asserir `assertStringContainsString('mensal'|'unica', $contrato->observacoes)`.
- **Files modified:** `app/Http/Controllers/Api/HubspotWebhookController.php`, `tests/Feature/Phase37WebhookLineItemsTest.php`
- **Verification:** T5+T6 verdes; nenhum SQL erro em produção
- **Committed in:** `b26b42d` (Task 1 GREEN) — explicitamente previsto no plan ("Se ContratoServico NÃO tem essa coluna, REMOVER esse campo do create. Validar no read inicial; ajustar action accordingly.")

**Total deviations:** 1 auto-fixed (1 schema mismatch)
**Impact on plan:** Plan previu explicitamente este ajuste no `<action>` do Passo D; documentação técnica preservada via `observacoes` + comentário explícito no método. Zero scope creep.

## Issues Encountered

- **Http::fake first-match-wins:** versão inicial do helper `mockHubspot` registrava o glob `/deals/{id}*` primeiro, causando 8/10 falhas porque o glob capturava `/deals/{id}/associations/companies` antes do mock específico. Resolvido reordenando: URLs específicas primeiro, glob catchall por último. Mesmo padrão usado em Phase34HubspotWebhookTest.
- **Magic-method hints (`PHP6602`):** IDE diagnostics reportam `Model::__get(): mixed` em todos os accessos `$company->id`, `$contrato->servico_id` etc. Não-bloqueante (consistente com toda a suite do projeto). Sem fix — alinhado com convenção codebase.

## User Setup Required

None — plan backend-only. Sem configuração externa, sem mudança em variáveis de ambiente, sem ação no painel HubSpot. O secret + token + props já estão configurados desde Phase 34-04 / 35-02 / 37-02.

## Threat Model Coverage

| Threat ID | Status | Como foi mitigado |
|-----------|--------|-------------------|
| T-37-08 (Tampering line_item.price) | mitigated | Cast `is_numeric($item['price'])` antes de `(float)`; fallback para `$mapping->servico->valor_padrao ?? 0` quando inválido |
| T-37-09 (Spoofing line_item.name não mapeado) | accept | Warning no `HubspotEvento.payload['line_items_nao_mapeados']` + log; admin pode cadastrar mapping via UI Plan 37-06/07; webhook responde 200 sem bloquear |
| T-37-10 (Repudiation auditoria) | mitigated | `HubspotEvento.payload` guarda raw `line_items_nao_mapeados`; `ContratoServico` herda `LogsActivity` (created event); canal `ecf-webhooks` log estruturado |
| T-37-11 (DoS loop line items) | accept | Deal típico ECF tem 1-3 itens; sem chamadas externas dentro do loop (todas em `fetchDealLineItems` que rodou ANTES do transaction); cap natural pela latência HubSpot |

## Next Phase Readiness

- **Plan 37-05 (listagem `/comercial/empresas/listagem`)** pronto para consumir empresas vindas por webhook: pendência `sem_servico` deriva de `hubspot_eventos.payload->'line_items_nao_mapeados'` exists OR empresa sem `contratosServico` ativo.
- **Plan 37-06/07 (UI admin `/sistema/hubspot-line-items`)** desbloqueia: admin cadastra mapping novo → re-disparo manual via Tinker do `HubspotEvento` → empresa ganha contrato sem deploy.
- **Phase 35-03 `EmpresaHubspotPendenteNotification`**: lógica `calcularPendencias` inalterada — empresas vindas por line items que tenham `sem_responsavel`/`sem_cust_id`/`sem_email_colaborador` continuam notificando Comercial.
- **CRÍTICO:** NÃO fazer deploy do Plan 37-04 sozinho — depende do Plan 37-02 (tabela `hubspot_line_item_mapping` + seed) e Plan 37-03 (`fetchDealLineItems`). Agrupar deploy dos 7 plans da Phase 37 conforme lição Phase 34/35.

## Self-Check: PASSED

Arquivos confirmados:
- FOUND: `app/Http/Controllers/Api/HubspotWebhookController.php` (3 métodos novos linhas 357/436/481)
- FOUND: `tests/Feature/Phase37WebhookLineItemsTest.php` (10 testes / 57 assertions)

Commits confirmados:
- FOUND: `0f42abb` (RED — test suite)
- FOUND: `b26b42d` (GREEN — controller refactor)

Testes:
- FOUND: 10/10 Phase37WebhookLineItemsTest verdes (57 assertions)
- FOUND: 6/6 Phase34HubspotWebhookTest verdes (49 assertions, zero regressão)
- FOUND: 45/45 Phase37 total verdes (191 assertions)
- FOUND: 6/6 Phase35OnboardingPrazoTest verdes (142 assertions)

Verificação plan-spec:
- FOUND: `grep -n 'fetchDealLineItems' app/Http/Controllers/Api/HubspotWebhookController.php` → linha 195 (integração) + 317/427 (comentários)
- FOUND: `grep -n 'processarLineItems|processarServicoLegado|rotearImplementacao'` → 3 métodos privados confirmados (linhas 357, 436, 481)

---
*Phase: 37-onboarding-comercial-unificado-via-hubspot-line-items*
*Completed: 2026-06-18*
