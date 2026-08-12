---
phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0
plan: 02
subsystem: clicksign / envelope
tags: [clicksign, d-02, tdd, envelope, rascunho]
dependency-graph:
  requires: [126-02, 126-07]
  provides: ["ClicksignClient::montarEnvelope(..., ativar: false)", "ClicksignClient::montarEnvelopePorModelo(..., ativar: false)"]
  affects: [127-03, 127-04, 127-05, 127-06, 127-07]
tech-stack:
  added: []
  patterns:
    - "parâmetro booleano opcional com default true propagado por 3 métodos, sem duplicar a sequência (if dentro do try existente, mesmo rollback)"
key-files:
  created:
    - tests/Feature/Phase127/ClicksignParaNoRascunhoTest.php
  modified:
    - app/Services/Clicksign/ClicksignClient.php
decisions:
  - "D-02 implementada: $ativar=false remove só a última instrução do try (ativação); try/catch e rollback D-04 via cancelarEnvelope() ficam idênticos ao caminho com ativação"
  - "Nenhuma fixture nova de payload de ENTRADA criada — reuso integral das fixtures da Fase 126"
metrics:
  duration: "~25min"
  completed: "2026-08-12"
---

# Phase 127 Plan 02: Caminho que para no rascunho (D-02) Summary

`ClicksignClient::montarEnvelope()` e `montarEnvelopePorModelo()` ganham um parâmetro opcional
`bool $ativar = true`, repassado por `montarEnvelopeComum()`, que agora envolve a chamada de
ativação (`$this->ativarEnvelope($envelopeId)`) num `if ($ativar)`. Com `ativar: false`, o envelope
é montado por inteiro — documento a partir do modelo, 4 signatários, 8 requisitos — e fica em
`draft`, esperando o Comercial abrir na Clicksign e enviar manualmente (D-02). O `try`/`catch` e o
rollback compartilhado (D-04, via `cancelarEnvelope()`) não mudaram: a ativação era a ÚLTIMA
instrução de dentro do `try`, então removê-la condicionalmente não abre um segundo caminho de
falha nem duplica a sequência de ~50 linhas que `montarEnvelopeComum()` existe para não repetir.

## O que foi construído

1. **Testes RED** (`tests/Feature/Phase127/ClicksignParaNoRascunhoTest.php`), 7 testes, escritos
   antes da mudança de assinatura — falharam com `Unknown named parameter $ativar` (5 dos 7;
   os 2 que já passavam de propósito são os que provam o **default preservado**, rede contra
   regressão da Fase 126):
   - `montarEnvelopePorModelo(..., ativar: false)` não manda a chamada de ativação
     (`Http::assertNotSent()` numa closure que identifica exatamente `PATCH /envelopes/{id}` com
     `attributes.status === 'running'` — necessário porque `consultarEnvelope()` GET e
     `cancelarEnvelope()` DELETE caem no mesmo padrão de URL `/envelopes/*`).
   - a sequência completa continua rodando: 14 chamadas (15 do caminho feliz medido na Fase 126
     menos a ativação), retorno com `envelope_id`/`document_id`/4 signatários intactos.
   - sem o parâmetro novo, `montarEnvelopePorModelo()` continua ativando — 15 chamadas, igual à
     Fase 126.
   - rollback D-04 com `ativar: false`: falha forçada num requisito ainda dispara
     `DELETE /envelopes/{id}` e propaga a exceção original (422), não a de cancelamento — mesmo
     par de asserções do teste equivalente da Fase 126, só acrescentando `ativar: false`.
   - o mesmo par (default preservado + rollback com `ativar: false`) repetido para
     `montarEnvelope()` (caminho de upload de PDF binário), provando que o parâmetro foi propagado
     nos dois métodos públicos, não só num deles.

2. **`app/Services/Clicksign/ClicksignClient.php`**: `bool $ativar = true` como último parâmetro de
   `montarEnvelope()`, `montarEnvelopePorModelo()` e `montarEnvelopeComum()` (private). Dentro de
   `montarEnvelopeComum()`, as duas linhas de ativação (`$passoAtual = 'ativar envelope';
   $this->ativarEnvelope($envelopeId);`) entram num `if ($ativar) { ... }`. Nenhuma outra linha do
   método mudou — o shape do array de retorno (`envelope_id`/`document_id`/`signatarios`) continua
   igual, sem chave `ativado` nova, preservando o contrato de saída que a suíte da Fase 126 assere.
   Docblocks dos dois métodos públicos documentam a D-02 e o efeito colateral já previsto no
   `127-CONTEXT.md`: com `ativar: false`, os parâmetros `$prazoDias`/`$lembreteDias` de
   `ativarEnvelope()` não rodam — prazo e lembrete precisam ir na criação do envelope (D-03), dentro
   de `$dadosEnvelope`, responsabilidade de quem chama.

## Resultado da suíte

`Phase125 + Phase126 + Phase127` = **166 testes verdes** (baseline de 159 registrado no
`127-01-SUMMARY.md` + 7 testes novos deste plano). Zero regressão — a suíte inteira da Fase 126
(que exercita `montarEnvelope()`/`montarEnvelopePorModelo()` sem o parâmetro novo) continua verde
sem alteração, confirmando que o default `true` não muda comportamento de nenhum chamador
existente.

## Deviations from Plan

Nenhuma. O plano foi executado exatamente como escrito: RED → propagação do parâmetro → GREEN,
sem necessidade de ajuste em nenhum outro arquivo.

## Known Stubs

Nenhum. Este plano só acrescenta um parâmetro opcional a um client já existente — nenhuma tela,
nenhum dado exibido ao usuário final.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. `T-127-04` (o próprio propósito desta
mudança: `ativar: false` impede contrato incompleto de chegar ao cliente antes de revisão humana) e
`T-127-05` (rollback D-04 preservado) foram cobertos pelos testes 1/2 e 4/5, respectivamente.
`T-127-06` (logs) não foi tocado — nenhuma linha de log nova neste plano.

## Self-Check: PASSED

- FOUND: tests/Feature/Phase127/ClicksignParaNoRascunhoTest.php
- FOUND: app/Services/Clicksign/ClicksignClient.php (com `bool $ativar = true` em 3 assinaturas)
- FOUND commit 22119d37 (test RED)
- FOUND commit 73bf5a94 (feat — propagação do parâmetro)
