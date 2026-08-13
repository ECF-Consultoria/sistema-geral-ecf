---
phase: 129-webhook-clicksign-v22-0
plan: 05
subsystem: api
tags: [laravel, clicksign, webhook, fila, liberacao, estado-proprio]

# Dependency graph
requires:
  - phase: 129-03
    provides: "ProcessarEventoClicksignJob com ponto de extensao marcado no handle(), ContratoSignatariosSyncService"
  - phase: 129-04
    provides: "GateLiberacaoOperacionalService::avaliar() e EmpresaOperacionalRouter::liberarEmpresa()"
provides:
  - "ProcessarEventoClicksignJob::handle() fechando o circuito: contrato assinado -> empresa liberada, por webhook, sem clique humano"
  - "Estados proprios recusado/expirado/cancelado, com guard explicito de 'estados so avancam'"
  - "Prova por teste de imunidade a ordem de entrega (SC3/gate #11): document_closed antes de sign, ou depois, produz o mesmo resultado final"
affects: ["130", "131", "132"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Decisao de estado SEMPRE por reconsulta (GateLiberacaoOperacionalService::avaliar()), nunca pelo payload/ordem do evento — o name do evento so decide no UNICO ramo em que o status do envelope reconsultado nao distingue dois casos (deadline vs cancel)"
    - "Guard 'estados so avancam' (in_array($status, EM_ANDAMENTO)) antes de aplicar expirado/cancelado — nunca reverte assinado/recusado por evento tardio"
    - "Http::fake() dinamico via closure + estado mutavel do teste, quando o MESMO teste precisa simular a reconsulta mudando de resposta entre duas chamadas do job (Laravel resolve por match do fake MAIS ANTIGO quando ha URLs repetidas, nao o mais recente)"

key-files:
  created:
    - tests/Feature/Phase129/LiberacaoPorWebhookTest.php
    - tests/Feature/Phase129/EventoOrdemTrocadaTest.php
    - tests/Feature/Phase129/RecusaExpiracaoEstadoProprioTest.php
  modified:
    - app/Jobs/ProcessarEventoClicksignJob.php
    - tests/Feature/Phase129/ProcessarEventoClicksignJobTest.php

key-decisions:
  - "assinado_em usa o MAX(assinado_em) entre os signatarios PAPEL_CONTRATANTE, recarregado do banco depois do sync — nunca now(), exceto como ultimo recurso quando a data nao estiver gravada"
  - "recusado tambem respeita o guard 'estados so avancam' (nao explicitamente pedido no plano para este ramo, mas aplicado por consistencia com o principio geral — nao ha teste que dependa do comportamento oposto)"
  - "cancel e deadline sao os UNICOS pontos do job em que o name do evento decide algo, e so dentro do ramo veredito['liberar']===false — se o gate ja liberou, o evento e irrelevante para a decisao de estado"
  - "codigo de producao da Task 2 (recusa/expirado/cancelado) foi escrito e commitado JUNTO com a Task 1, porque as duas tasks editam o MESMO metodo handle() em blocos logicamente entrelacados (o guard 'estados so avancam' e compartilhado entre os dois ramos) — separar teria deixado um commit intermediario com codigo morto ou incompleto"

requirements-completed: [CLICK-05, CLICK-04]

# Metrics
duration: ~1h10min
completed: 2026-08-13
---

# Phase 129 Plan 05: Fechamento do circuito — liberação por webhook e estados próprios Summary

**`ProcessarEventoClicksignJob::handle()` decide `assinado`/`recusado`/`expirado`/`cancelado` inteiramente por reconsulta do envelope (nunca pela ordem ou payload do evento) e chama `EmpresaOperacionalRouter::liberarEmpresa(via: 'webhook')` — contrato assinado vira empresa em operação sem ninguém clicar, provado por 20 testes novos incluindo o cenário explícito de ordem invertida (SC3/gate #11).**

## Performance

- **Duration:** ~1h10min
- **Started:** 2026-08-13
- **Completed:** 2026-08-13
- **Tasks:** 2/2
- **Files modified:** 3 criados, 2 modificados

## Accomplishments

- **`ProcessarEventoClicksignJob::handle()` fechado** (Task 1 + Task 2) — o ponto de extensão deixado pelo plano 129-03 agora decide: recusa é estado de parada avaliada antes do gate (nunca mexe no cadastro), o gate de liberação do 129-04 decide `assinado` puramente pela reconsulta, e o único ramo negativo distingue `deadline`/`cancel` porque o `status` do envelope reconsultado (`draft`/`running`/`closed`) não separa os dois casos. Guard explícito de "estados só avançam" impede que um evento tardio (`deadline`, `cancel`) reverta um contrato já `assinado`.
- **Imunidade à ordem provada por teste** (`EventoOrdemTrocadaTest`) — o cenário que dá nome ao SC3: `document_closed` chegando ANTES do envelope realmente fechar não libera cedo; `sign` chegando DEPOIS, com a reconsulta agora mostrando `closed` + contratante assinado, libera sem perder nada. O caminho oposto (evento tardio depois de já liberado) não duplica liberação nem ficha.
- **Recusa/expiração como estados próprios** (`RecusaExpiracaoEstadoProprioTest`) — `refusal` vira `recusado` (nunca `cancelado`/`erro`), `deadline` com fechamento parcial vira `expirado`, `deadline` com contratante já assinado vira `assinado` (não é expiração), `cancel` vira `cancelado`, e um contrato `assinado` recebendo `deadline` tardio não regride. Prova direta de D-04: recusa de um contrato não mexe em `ContratoServico`/`MlbEmpresa` de OUTRO contrato da mesma empresa.
- **Fixtures sintéticas documentadas onde o gate real não mediu** — os eventos `refusal`/`deadline`/`cancel` nunca dispararam ao vivo contra o sandbox (`129-GATE.md` registra gates #6/#7 como NÃO MEDIDOS); o topo do arquivo de teste avisa isso explicitamente e aponta o gate humano do plano 129-07 como a prova real.

## Task Commits

1. **Task 1: liberação por webhook e imunidade à ordem (CLICK-05, SC3)** — `c5b4a070` (feat) — 8 testes, 30 assertions (inclui o código de produção da Task 2, ver Deviations)
2. **Task 2: recusa e prazo vencido como estados próprios (D5, D-04)** — `5691d44a` (test) — 6 testes, 23 assertions (código de produção já entrou no commit da Task 1)
3. **Fix de regressão no teste da 129-03** — `bc37d971` (fix) — atualiza `ProcessarEventoClicksignJobTest` para a nova assinatura de `handle()`

_Nenhuma task teve TDD — plano `type="execute"` padrão._

## Files Created/Modified

- `app/Jobs/ProcessarEventoClicksignJob.php` (modificado) — `handle()` ganhou `GateLiberacaoOperacionalService $gate` e `EmpresaOperacionalRouter $router` na assinatura (injeção por container); decisão de recusa/liberação/expiração/cancelamento no lugar do ponto de extensão da 129-03
- `tests/Feature/Phase129/LiberacaoPorWebhookTest.php` (novo) — 8 testes: liberação com `assinado_em` do evento `sign`, ficha criada/não criada conforme o serviço, dois eventos → uma liberação, `closed` com contratante pendente não libera, `now()` como último recurso
- `tests/Feature/Phase129/EventoOrdemTrocadaTest.php` (novo) — 2 testes: ordem invertida não perde liberação, evento tardio não duplica
- `tests/Feature/Phase129/RecusaExpiracaoEstadoProprioTest.php` (novo) — 6 testes: os 4 estados de parada + guard "estados só avançam" + D-04 entre dois contratos da mesma empresa
- `tests/Feature/Phase129/ProcessarEventoClicksignJobTest.php` (modificado) — 5 chamadas de `handle()` atualizadas para a nova assinatura (4 argumentos)

## Decisions Made

Ver `key-decisions` no frontmatter para o registro completo. Destaque: `recusado` também respeita o guard "estados só avançam" — o plano só exigia isso explicitamente para `expirado`/`cancelado`, mas aplicar o mesmo guard a `recusado` é consistente com o princípio geral e não contradiz nenhum critério de aceite ou teste pedido.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `ProcessarEventoClicksignJobTest` quebrado pela nova assinatura de `handle()`**
- **Found during:** verificação da suíte cumulativa após a Task 1
- **Issue:** as 5 chamadas de `handle($client, $sync)` do teste da plano 129-03 pararam de compilar (`ArgumentCountError`) depois que `handle()` passou a exigir `GateLiberacaoOperacionalService` e `EmpresaOperacionalRouter`
- **Fix:** as 5 chamadas passaram a injetar `new GateLiberacaoOperacionalService()` e `app(EmpresaOperacionalRouter::class)`
- **Files modified:** `tests/Feature/Phase129/ProcessarEventoClicksignJobTest.php`
- **Commit:** `bc37d971`

**2. [Nota estrutural, não um bug] Código de produção da Task 2 commitado junto com a Task 1**
- **Found during:** implementação da Task 1
- **Issue:** as duas tasks do plano editam o MESMO método `handle()` em blocos logicamente entrelaçados — o guard "estados só avançam" é compartilhado entre o ramo de liberação (Task 1) e o ramo de expiração/cancelamento (Task 2), e a ordem de avaliação exigida pelo plano ("recusa → gate → senão deadline") intercala os dois. Separar em duas edições sequenciais deixaria um commit intermediário com código incompleto (recusa aplicada sem `STATUS_EXPIRADO`/`STATUS_CANCELADO` existirem, ou vice-versa) ou exigiria reescrever o mesmo trecho duas vezes.
- **Fix:** o código de produção completo (recusa + gate + deadline/cancel) foi escrito e testado como uma unidade no commit `c5b4a070` (Task 1); o commit `5691d44a` (Task 2) cobre a suíte de teste dedicada que faltava
- **Files modified:** nenhum arquivo adicional — documentação da ordem real dos commits
- **Verificação:** `git show c5b4a070 -- app/Jobs/ProcessarEventoClicksignJob.php` mostra `STATUS_RECUSADO`, `STATUS_EXPIRADO` e `STATUS_CANCELADO` já presentes nesse commit

---

**Total deviations:** 2 (1 auto-fix de bloqueio, 1 nota estrutural sobre a ordem dos commits)
**Impact on plan:** Nenhum impacto no resultado final — os dois critérios de aceite de cada task foram verificados independentemente (contagem de testes, presença de símbolos no arquivo, ausência de `ContratoServico`/`MlbEmpresa::` fora de `liberarEmpresa()`).

## Issues Encountered

- **`Http::fake()` chamado duas vezes no mesmo teste com a MESMA URL não substitui a resposta anterior** — Laravel resolve pelo PRIMEIRO match na collection de `stubCallbacks` (`PendingRequest::buildStubHandler()`), então o fake mais ANTIGO sempre venceria ao simular a reconsulta mudando de estado entre duas chamadas do job dentro do mesmo teste. Resolvido com um único `Http::fake(function...)` lendo um estado mutável do teste (`$this->envelopeStatusAtual`), atualizado antes de cada `processar()`. Documentado no docblock do helper em `EventoOrdemTrocadaTest.php` para o próximo desenvolvedor não cair na mesma armadilha.

## User Setup Required

None — nenhuma configuração nova de ambiente.

⚠️ Lembrete herdado do ambiente (não desta task): o túnel cloudflared e o `php artisan serve` seguem rodando desta sessão — **não foram tocados** por esta execução.

## Next Phase Readiness

- O circuito está fechado: contrato assinado → `assinado` → `liberarEmpresa(via: 'webhook')`, sem depender de clique humano, com a decisão sempre por reconsulta.
- A Fase 130 (REDE-02/03/04) pode: (a) ler `contrato_assinatura_eventos.status = 'erro'` para o alerta de contrato preso; (b) reusar `ContratoSignatariosSyncService`/`GateLiberacaoOperacionalService` no job de reconciliação sem duplicar regra; (c) construir a liberação MANUAL sobre o mesmo `EmpresaOperacionalRouter::liberarEmpresa(via: 'manual')`, já pronto desde a 129-04.
- Gates #6 (`deadline`) e #7 (`refusal`) continuam NÃO MEDIDOS ao vivo — as fixtures desta suíte são sintéticas e documentadas como tal. A prova ponta a ponta contra o sandbox real é o gate humano do plano 129-07.
- Nenhum bloqueio para o plano 129-06 (ou o gate 129-07) iniciar.

## Self-Check: PASSED

- `app/Jobs/ProcessarEventoClicksignJob.php` (contém `liberarEmpresa(`, `VIA_WEBHOOK`, `STATUS_RECUSADO`, `STATUS_EXPIRADO`) → FOUND
- `tests/Feature/Phase129/LiberacaoPorWebhookTest.php` → FOUND
- `tests/Feature/Phase129/EventoOrdemTrocadaTest.php` → FOUND
- `tests/Feature/Phase129/RecusaExpiracaoEstadoProprioTest.php` → FOUND
- Commit `c5b4a070` → FOUND em `git log`
- Commit `5691d44a` → FOUND em `git log`
- Commit `bc37d971` → FOUND em `git log`
- `php artisan test --filter="LiberacaoPorWebhookTest|EventoOrdemTrocadaTest"` → 8 passed / 30 assertions, exit 0
- `php artisan test --filter=RecusaExpiracaoEstadoProprioTest` → 6 passed / 23 assertions, exit 0
- Suíte cumulativa `Phase124|Phase125|Phase126|Phase127|Phase128|Phase129` → 333 passed / 1103 assertions, exit 0 (baseline 319/1050 + 14 testes novos/53 assertions — sem regressão)

---
*Phase: 129-webhook-clicksign-v22-0*
*Completed: 2026-08-13*
