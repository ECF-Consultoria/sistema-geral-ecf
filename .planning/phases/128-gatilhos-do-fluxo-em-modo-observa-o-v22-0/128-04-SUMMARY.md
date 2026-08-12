---
phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0
plan: 04
subsystem: api
tags: [laravel, contratos, clicksign, gate, hubspot, comercial]

# Dependency graph
requires:
  - phase: 128-03
    provides: "GatilhoContratoAdministrativoService::dispararSeElegivel() — orquestrador único"
provides:
  - "HubspotWebhookController e ComercialController::store() ligados ao gate administrativo, fora da DB::transaction()"
  - "Prova executável do invariante da fase: falha do gate nunca desfaz o cadastro nem o roteamento operacional (SC4)"
  - "ComercialController::store() persiste nome_contato (bug pré-existente corrigido — campo era validado e descartado)"
affects: ["128-05", "128-06"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Chamada ao orquestrador SEMPRE fora da DB::transaction() que cria a Company — mesmo padrão já usado por notificarComercialSePendente()/activity log"
    - "company->refresh() antes de chamar o gate, para ler contratosServico gravados dentro da transaction que já fechou"
    - "Sem try/catch no controller ao redor de dispararSeElegivel() — a garantia de não-relançar é do próprio service (plano 03)"

key-files:
  created:
    - tests/Feature/Phase128/InvarianteRoteamentoTest.php
    - tests/Feature/Phase128/GatilhoContratoHubspotTest.php
    - tests/Feature/Phase128/GatilhoContratoComercialTest.php
    - .planning/phases/128-gatilhos-do-fluxo-em-modo-observa-o-v22-0/deferred-items.md
  modified:
    - app/Http/Controllers/Api/HubspotWebhookController.php
    - app/Http/Controllers/ComercialController.php

key-decisions:
  - "Fix de Rule 1 em ComercialController::store(): 'nome_contato' já era validado mas nunca persistido em Company::create() — toda empresa cadastrada pelo Comercial ficava presa em 'aguardando_comercial' (pendência sem_contato) mesmo com o contato preenchido no formulário, quebrando o SC2 (mesmo gate, mesmo disparo do caminho HubSpot). Corrigido para persistir o campo já coletado."
  - "Cenário de falha do gate (SC4) provado só pelo caminho Comercial (mock no container lançando RuntimeException) — o mecanismo de posicionamento fora da transaction é idêntico nos dois controllers, um teste por controller seria redundante para este cenário específico."
  - "Prova de 'Gestão + Polos no mesmo deal' migrada para o fluxo de line items (Fase 37) em vez do fluxo legado servico_ecf, porque o legado só suporta 1 serviço por deal — line items é o único jeito real de ter 2 ContratoServico na mesma empresa via webhook."

patterns-established:
  - "Suítes de fiação (Http::fake()/Bus::fake()) documentam explicitamente que NÃO provam Success Criteria 1 (payload real) — só a ordem/resultado do disparo. A prova do envelope real fica para o gate humano do plano 06."

requirements-completed: [REDE-06]

# Metrics
duration: 10min
completed: 2026-08-12
---

# Phase 128 Plano 04: Liga o gate administrativo nos dois pontos de entrada Summary

**Os dois controllers (`HubspotWebhookController` e `ComercialController::store()`) chamam `GatilhoContratoAdministrativoService::dispararSeElegivel()` fora da `DB::transaction()`, com prova executável de que uma falha do gate nunca desfaz o cadastro nem o roteamento operacional já commitados**

## Performance

- **Duration:** ~10 min (14:38–14:48 BRT)
- **Started:** 2026-08-12T14:38:00-03:00
- **Completed:** 2026-08-12T14:48:27-03:00
- **Tasks:** 3
- **Files modified:** 6 (2 modificados, 4 criados)

## Accomplishments

- `HubspotWebhookController::processar()`: a chamada ao gate entra logo depois de
  `notificarComercialSePendente()`, no mesmo bloco pós-commit já existente, com
  `$company->refresh()` antes para garantir que `contratosServico` reflita os registros
  criados dentro da transaction que já fechou.
- `ComercialController::store()`: a mesma chamada entra depois da notificação aos
  líderes de setor, também fora da `DB::transaction()`.
- Nenhum try/catch redundante em nenhum dos dois controllers — `dispararSeElegivel()`
  já engole `\Throwable` internamente (garantia do plano 03).
- `InvarianteRoteamentoTest` (4 cenários): pendência bloqueia o contrato mas não o
  roteamento (Comercial e HubSpot), gate mockado explodindo não desfaz `Company` nem
  `MlbEmpresa`, e o interruptor de emergência (Fase 124) segue desligado em todos os
  cenários.
- `GatilhoContratoHubspotTest` (3 cenários) e `GatilhoContratoComercialTest`
  (2 cenários): os dois caminhos de entrada convergem no mesmo resultado — dados
  completos disparam 1 `ContratoAssinatura` + 1 `GerarContratoAssinaturaJob`; serviço
  isento (Polos) nunca gera contrato mas continua sendo roteado; pendência bloqueia
  pelo caminho HTTP real dos dois controllers.
- Bug pré-existente corrigido (Rule 1): `ComercialController::store()` validava
  `nome_contato` mas nunca persistia — toda empresa cadastrada pelo Comercial ficava
  presa em `aguardando_comercial` mesmo com o contato preenchido, o que quebraria o
  SC2 assim que a Fase 133 ligar o gate de verdade.

## Task Commits

Each task was committed atomically:

1. **Task 1: Ligar o gate nos dois controllers, fora da transação** - `d0116e52` (feat)
2. **Task 2: Provar o invariante do roteamento (SC4) e a não-reversão do cadastro** - `f21c6563` (test)
3. **Task 3: Cobertura dos dois caminhos de entrada (SC1 estrutural e SC2)** - `34410651` (test, inclui o fix de Rule 1 em `ComercialController::store()`)

**Plan metadata:** (este commit)

## Files Created/Modified

- `app/Http/Controllers/Api/HubspotWebhookController.php` - chamada a `dispararSeElegivel()` fora da transaction, no bloco pós-commit
- `app/Http/Controllers/ComercialController.php` - mesma chamada fora da transaction + fix de `nome_contato` nunca persistido
- `tests/Feature/Phase128/InvarianteRoteamentoTest.php` - prova do SC4 (4 cenários)
- `tests/Feature/Phase128/GatilhoContratoHubspotTest.php` - fiação do caminho HubSpot (3 cenários)
- `tests/Feature/Phase128/GatilhoContratoComercialTest.php` - fiação do caminho Comercial (2 cenários)
- `.planning/phases/128-gatilhos-do-fluxo-em-modo-observa-o-v22-0/deferred-items.md` - falhas pré-existentes fora de escopo, descobertas durante a bateria ampla de regressão

## Decisions Made

- Isenção de `nome_contato` como bug de Rule 1 (não Rule 4/arquitetural): campo já
  validado, só faltava uma linha no `Company::create()` — sem mudança de schema, sem
  nova coluna, sem mudança de contrato de API.
- Cenário 3 de `InvarianteRoteamentoTest` (gate explodindo) implementado só via
  `ComercialController::store()` — o mecanismo (chamada fora da transaction) é
  idêntico nos dois controllers; duplicar o mesmo teste no caminho HubSpot não
  agregaria prova nova, só tempo de execução.
- "Gestão + Polos no mesmo deal" (bullet 2 da Task 3) implementado via line items
  (Fase 37) em vez do fluxo legado `servico_ecf`, porque o legado só resolve 1 serviço
  por deal — não há como ter 2 `ContratoServico` na mesma empresa por esse caminho.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `ComercialController::store()` nunca persistia `nome_contato`**
- **Found during:** Task 3 (ao desenhar o cenário "dados completos" do Comercial, SC2)
- **Issue:** O campo `nome_contato` era validado (`'nome_contato' => 'nullable|string|max:255'`)
  mas nunca aparecia no `Company::create([...])` — toda empresa cadastrada pelo Comercial
  nascia com `nome_contato = null`, disparando a pendência `sem_contato` no gate
  administrativo mesmo quando o usuário preenchia o campo corretamente no formulário.
  Isso quebraria o SC2 (mesmo gate, mesmo disparo do caminho HubSpot) assim que a
  Fase 133 ligar o gate de verdade em produção.
- **Fix:** Adicionada a linha `'nome_contato' => $validated['nome_contato'] ?? null,`
  no array de criação da `Company`, com comentário pt-BR explicando o porquê.
- **Files modified:** `app/Http/Controllers/ComercialController.php`
- **Commit:** `34410651`

Nenhum outro desvio — as duas chamadas ao gate foram adicionadas exatamente como o
plano descreveu (fora da transaction, sem try/catch redundante, com `refresh()`).

## Issues Encountered

Nenhum bloqueio. A bateria ampla de regressão do Comercial (`Phase13ComercialTest`,
`Phase14ComercialTest::test_update_ignora_campos_legacy`) revelou 11 falhas
pré-existentes e desatualizadas — documentadas em `deferred-items.md`, fora do escopo
deste plano (não tocam `store()` nem `nome_contato`; `Phase13ComercialTest` usa o
payload legado `service_type`, substituído por `servicos[]` desde a Fase 14, e não é
tocado desde `f58da269`, 2026-05-25).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Os dois pontos de entrada de empresa (webhook HubSpot e cadastro Comercial) chamam
  o mesmo orquestrador único, fora de qualquer transaction — pronto para o Observer do
  plano 05 usar a mesma chamada sem duplicar lógica.
- Baseline `Phase124`/`Phase127`/`Phase128` (27+16+66 = 109 testes) intacto, zero
  regressão nos arquivos tocados por esta fase.
- Invariante da fase (empresa sempre chega ao operacional, gate roda em paralelo)
  provado com o gate mockado explodindo — é o teste de maior confiança desta fase.

---
*Phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0*
*Completed: 2026-08-12*

## Self-Check: PASSED

- FOUND: app/Http/Controllers/Api/HubspotWebhookController.php
- FOUND: app/Http/Controllers/ComercialController.php
- FOUND: tests/Feature/Phase128/InvarianteRoteamentoTest.php
- FOUND: tests/Feature/Phase128/GatilhoContratoHubspotTest.php
- FOUND: tests/Feature/Phase128/GatilhoContratoComercialTest.php
- FOUND: .planning/phases/128-gatilhos-do-fluxo-em-modo-observa-o-v22-0/deferred-items.md
- FOUND commit: d0116e52 (Task 1)
- FOUND commit: f21c6563 (Task 2)
- FOUND commit: 34410651 (Task 3)
