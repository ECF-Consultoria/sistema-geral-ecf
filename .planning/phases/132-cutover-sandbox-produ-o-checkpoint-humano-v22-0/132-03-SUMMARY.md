---
phase: 132-cutover-sandbox-produ-o-checkpoint-humano-v22-0
plan: 03
subsystem: backend
tags: [clicksign, webhook, cutover, producao, checkpoint-humano]

requires:
  - phase: 132 plano 02
    provides: "produção apontada para a Clicksign real, com o interruptor ligado"
provides:
  - "SC2 fechado: webhook de produção conferido pela API (URL, estado, eventos e segredo) sem acesso ao painel"
  - "SC3 fechado: envelope real ativado, 3 assinaturas colhidas, eventos chegando com signature_valid=true"
  - "D-06 não manda parar — a cadeia Clicksign→receiver→HMAC→gravação→job→contrato→signatário está provada"
affects: [132-04]

tech-stack:
  added: []
  patterns:
    - "GET {base_url}/webhooks confere o SC2 inteiro por API, inclusive o segredo (comparado por hash_equals, nunca impresso)"
    - "Resolução do contrato por envelope OU documento — o payload do webhook é baseado em documento"
    - "Signatários persistidos na criação do envelope; o sync só atualiza, nunca cria (T-129-16)"

key-files:
  created:
    - tests/Feature/Phase132/WebhookResolvePorDocumentoTest.php
    - tests/Feature/Phase132/SignatariosPersistidosTest.php
  modified:
    - app/Models/ContratoAssinatura.php
    - app/Http/Controllers/Api/ClicksignWebhookController.php
    - app/Jobs/ProcessarEventoClicksignJob.php
    - app/Jobs/GerarContratoAssinaturaJob.php
    - routes/web.php
---

## Accomplishments

- **Task 1 (SC2)** — o usuário havia cadastrado o webhook por uma conta à qual não tem mais
  acesso, então não podia ver a configuração nem disparar evento de teste. Descoberto que
  `GET /webhooks` responde 200 e devolve `endpoint`, `status`, `events` **e o `secret`** — o SC2
  inteiro conferível por API. O plano dizia que isso era impossível.
  **A URL cadastrada apontava para `/administrativo/clicksign`, que não existe** (POST → 404).
  Corrigida por `PATCH` com autorização explícita; 32 eventos e o segredo preservados.
- **Task 2** — empresa fictícia 424 e envelope em rascunho. A janela do interruptor **nunca
  precisou ser aberta**: o gatilho automático da Fase 128 gerou o contrato por fora dele.
- **Task 3 (SC3)** — envelope ativado pelo usuário, 3 das 4 pessoas assinaram. Os 11 eventos do
  documento estão `processado` e ligados ao contrato; `signature_valid=true` em 24 dos 25 eventos.

## Deviations from Plan

Três defeitos precisaram ser corrigidos para o plano poder ser concluído — todos medidos em
produção, todos com teste:

1. **Resolução por envelope enquanto o webhook manda documento** — as três assinaturas eram
   descartadas. Criado `ContratoAssinatura::resolverPorReferenciaClicksign()`.
2. **Rota do receiver removida em produção** por um merge sem conflito da milestone paralela.
   `POST /api/webhooks/clicksign` devolvia 404 das 09:44 às ~11:00. Restauradas 56 linhas.
3. **Nada criava linha de signatário** — tabela vazia na base inteira. O job passou a persistir
   os signatários que a Clicksign devolve.

## Issues Encountered

- Reprocessar evento em massa esbarra no limite de **3/min** do bucket `clicksign-webhook`;
  a primeira tentativa estourou tentativas em 8 dos 11. Refeito em lotes com intervalo.
- O job retorna cedo para evento já `processado` — reaplicar o sync exigiu chamada direta.

## Self-Check: PASSED

`{"assinaram":3,"recusaram":0,"nao_reconhecidos":[]}` — o sistema sabe quem assinou, quando e
quem falta.
