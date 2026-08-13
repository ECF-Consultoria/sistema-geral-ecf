---
phase: 129-webhook-clicksign-v22-0
plan: 03
subsystem: api
tags: [laravel, clicksign, webhook, fila, hmac, reconsulta]

# Dependency graph
requires:
  - phase: 129-02
    provides: "ClicksignHmacVarredura::FORMULA_CONFIRMADA fechada (hmac_body_chave_secret), gate A1 medido contra 5/5 eventos reais"
  - phase: 129-01
    provides: "tabela contrato_assinatura_eventos + ContratoAssinaturaEvento"
provides:
  - "POST /api/webhooks/clicksign — receiver de produção, janela síncrona blindada (valida, grava, enfileira)"
  - "ProcessarEventoClicksignJob — reconsulta o envelope na fila, nunca decide pelo payload (D-06/D-07)"
  - "ContratoSignatariosSyncService — traduz eventos do documento em situação de signatário, reusável pela Fase 130 (REDE-04)"
affects: ["129-04", "129-05", "130", "131", "132"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Allowlist de eventos tratados (sign/refusal), nunca denylist — lista documentada da Clicksign não é exaustiva"
    - "Reconsulta sempre vence o payload do webhook — provado por teste dedicado (D-07)"
    - "WithoutOverlapping por chave dinâmica (por envelope), não global — permite paralelismo entre envelopes diferentes"
    - "Bucket de rate limiter com aritmética explícita no comentário (chamadas por evento × eventos/min ≤ janela medida)"

key-files:
  created:
    - app/Services/Clicksign/ContratoSignatariosSyncService.php
    - app/Jobs/ProcessarEventoClicksignJob.php
    - app/Http/Controllers/Api/ClicksignWebhookController.php
    - tests/Feature/Phase129/ContratoSignatariosSyncTest.php
    - tests/Feature/Phase129/ProcessarEventoClicksignJobTest.php
    - tests/Feature/Phase129/ClicksignWebhookAssinaturaTest.php
    - tests/Feature/Phase129/ClicksignWebhookIdempotenciaTest.php
    - tests/Feature/Phase129/ClicksignWebhookDespachaFilaTest.php
  modified:
    - app/Providers/AppServiceProvider.php
    - routes/web.php

key-decisions:
  - "Resposta 401 (não 200) para assinatura inválida — divergência deliberada do HubspotWebhookController, mas o evento é gravado bruto mesmo assim (DADOS-03)"
  - "extração de name/envelopeId pelo caminho MEDIDO no gate A1 (event.name/document.key), com fallback defensivo para a forma JSON:API que a doc oficial também mostra"
  - "ContratoSignatariosSyncService nunca cria signatário novo — sign/refusal de e-mail desconhecido vira nao_reconhecidos, nunca uma linha nova (T-129-16)"
  - "Job não decide assinado/recusado/expirado — ponto de extensão explícito deixado para o plano 129-05 (depende do gate de liberação do 129-04)"
  - "Nenhum novo pacote instalado — hash_hmac/hash_equals nativos, fila database já configurada"

requirements-completed: [CLICK-03, CLICK-04, CLICK-06, DADOS-03]

# Metrics
duration: ~1h40min
completed: 2026-08-13
---

# Phase 129 Plan 03: Receiver de produção do webhook Clicksign — validação, gravação, fila e reconsulta Summary

**POST /api/webhooks/clicksign valida `Content-Hmac` (401 se inválido, mas grava mesmo assim), grava o evento bruto com dedup por `payload_hash`, e enfileira `ProcessarEventoClicksignJob`, que reconsulta o envelope na fila e nunca decide pelo payload que chegou.**

## Performance

- **Duration:** ~1h40min
- **Started:** 2026-08-13 (leitura de contexto + execução das 3 tasks)
- **Completed:** 2026-08-13
- **Tasks:** 3/3
- **Files modified:** 8 criados, 2 modificados

## Accomplishments

- **`ContratoSignatariosSyncService`** (Task 1) — único lugar que traduz eventos `sign`/`refusal` do documento em `situacao` do signatário. Idempotente (estado absoluto, nunca incremento), imune a entrega fora de ordem (ordena por `attributes.created`, não pela ordem de chegada — mitiga o gate #11 permanentemente não medido), e nunca cria signatário novo a partir de webhook (T-129-16).
- **`ProcessarEventoClicksignJob`** (Task 2) — trabalho pesado da fila: reconsulta `consultarEnvelope()` + `listarEventosDoDocumento()` do envelope QUE MUDOU (nunca todos da empresa), guard de reentrega no topo (at-least-once), `WithoutOverlapping` por envelope + bucket `clicksign-webhook` (3/min global, aritmética documentada), e `failed()` grava `status='erro'`+`erro_msg` podado de PII — o sinal que o alerta da Fase 130 (REDE-03) vai varrer.
- **`ClicksignWebhookController` + rota `/api/webhooks/clicksign`** (Task 3) — janela síncrona estreita: valida assinatura (401 se inválida, grava mesmo assim), decodifica defensivamente (200 se JSON malformado), extrai `name`/`envelopeId` pela forma REAL medida no gate A1 (`event.name`/`document.key`), grava com dedup por `payload_hash` (SQLSTATE 23000, nunca `errorInfo[1]`), enfileira e responde. Zero chamada HTTP à Clicksign na janela síncrona — provado por `Http::assertNothingSent()`.
- Matriz de status code da D-10 documentada em tabela no docblock da classe, junto da divergência deliberada em relação ao `HubspotWebhookController` (401 vs 200 em assinatura inválida).

## Task Commits

1. **Task 1: `ContratoSignatariosSyncService`** — `c70e7e8e` (feat) — 5 testes, 18 assertions
2. **Task 2: `ProcessarEventoClicksignJob` + bucket `clicksign-webhook`** — `069b33e7` (feat) — 5 testes, 12 assertions
3. **Task 3: `ClicksignWebhookController` + rota de produção** — `689abcd5` (feat) — 6 testes, 22 assertions

_Nenhuma task teve TDD — plano `type="execute"` padrão._

## Files Created/Modified

- `app/Services/Clicksign/ContratoSignatariosSyncService.php` (novo) — `aplicar(ContratoAssinatura, array $eventosDoDocumento): array` puro, sem HTTP; allowlist `sign`/`refusal`, localiza signatário por `clicksign_signer_key` ou e-mail case-insensitive, nunca cria linha nova
- `app/Jobs/ProcessarEventoClicksignJob.php` (novo) — reconsulta na fila, `tries=3`/`timeout=120`/`backoff=[60,300,900]`, `WithoutOverlapping` por envelope + `RateLimited('clicksign-webhook')`, `failed()` com `podarPii()`
- `app/Providers/AppServiceProvider.php` — bucket `clicksign-webhook` (3/min global), aritmética explícita (2 chamadas/evento × 3/min = 6, folga para o bucket `clicksign-envelope` no mesmo minuto)
- `app/Http/Controllers/Api/ClicksignWebhookController.php` (novo) — `receive()` em 4 passos, docblock com a matriz de status code da D-10 em tabela
- `routes/web.php` — rota `webhooks.clicksign` registrada no lugar do comentário da rota-sonda removida
- 5 arquivos de teste novos em `tests/Feature/Phase129/` (16 testes, 52 assertions no total desta plano)

## Decisions Made

- Ver `key-decisions` no frontmatter para o registro completo.
- Contrato não encontrado para um `envelopeId` não é tratado como erro em nenhuma das duas camadas (controller grava com `contrato_assinatura_id` nulo; job marca `ignorado` sem lançar) — é comportamento esperado de corrida com o commit do envelope ou de envelope criado direto no painel da Clicksign.

## Deviations from Plan

None — plano executado exatamente como escrito. Os 3 arquivos de teste do controller (`ClicksignWebhookAssinaturaTest`, `ClicksignWebhookIdempotenciaTest`, `ClicksignWebhookDespachaFilaTest`) usam o helper `servidor()` (conversão de headers para `$_SERVER`) copiado literalmente de `Phase34HubspotWebhookTest` — já previsto no `read_first` da Task 3, não é desvio.

## Issues Encountered

None.

## User Setup Required

None — nenhuma configuração nova de ambiente. `CLICKSIGN_WEBHOOK_SECRET` já existe no `.env` (medido no gate A1, plano 129-02).

⚠️ Lembrete herdado do ambiente (não desta task): o túnel cloudflared e o `php artisan serve` seguem rodando desta sessão — **não foram tocados** por esta execução, conforme instrução explícita.

## Next Phase Readiness

- O receiver de produção está ligado e testado (fiação, não ponta a ponta — ver aviso no docblock do job). A prova real contra o sandbox é o gate humano do plano 129-07.
- O plano 129-04 (gate de liberação) e o 129-05 (estados `assinado`/`recusado`/`expirado`) têm o ponto de extensão já marcado em `ProcessarEventoClicksignJob::handle()`.
- A Fase 130 (REDE-03/REDE-04) pode: (a) reusar `ContratoSignatariosSyncService::aplicar()` no job de reconciliação sem duplicar regra; (b) varrer `contrato_assinatura_eventos.status = 'erro'` para o alerta de contrato preso.
- Nenhum bloqueio para o plano 129-04 iniciar.

## Self-Check: PASSED

- `app/Services/Clicksign/ContratoSignatariosSyncService.php` → FOUND
- `app/Jobs/ProcessarEventoClicksignJob.php` → FOUND
- `app/Http/Controllers/Api/ClicksignWebhookController.php` → FOUND
- Commit `c70e7e8e` → FOUND em `git log`
- Commit `069b33e7` → FOUND em `git log`
- Commit `689abcd5` → FOUND em `git log`
- `php artisan route:list --path=webhooks/clicksign` → lista `webhooks.clicksign` (POST), não lista `clicksign-sonda`
- `git diff --name-only` não inclui `bootstrap/app.php`
- Suíte `Phase129` → 35 passed / 98 assertions, exit 0
- Suíte cumulativa `Phase124|Phase125|Phase126|Phase127|Phase128|Phase129` → 303 passed / 997 assertions, exit 0 (baseline 287/945 + 16 testes/52 assertions desta plano — sem regressão)

---
*Phase: 129-webhook-clicksign-v22-0*
*Completed: 2026-08-13*
