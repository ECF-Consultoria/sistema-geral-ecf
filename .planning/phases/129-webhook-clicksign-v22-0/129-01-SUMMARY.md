---
phase: 129-webhook-clicksign-v22-0
plan: 01
subsystem: database
tags: [laravel, clicksign, hmac, webhook, migration, artisan-command]

# Dependency graph
requires:
  - phase: 125-estrutura-de-dados-administrativa
    provides: "contrato_assinaturas / ContratoAssinatura model (FK de contrato_assinatura_eventos)"
  - phase: 126-client-clicksign-pdf-do-contrato-v22-0
    provides: "padrão de comando-sonda (ClicksignSondarModelo) reusado no molde deste comando"
provides:
  - "Tabela contrato_assinatura_eventos (DADOS-03) com payload_hash UNIQUE nomeado à mão"
  - "Model ContratoAssinaturaEvento (fillable explícito, sem LogsActivity)"
  - "ClicksignHmacVarredura — classe pura que calcula/identifica as 4 candidatas de HMAC; FORMULA_CONFIRMADA=null (gate A1 aberto)"
  - "Comando clicksign:verificar-assinatura (D-09) — verifica evento já gravado sem superfície pública"
  - "Rota-sonda POST /api/webhooks/clicksign-sonda (TEMPORÁRIA, removida pelo plano 129-02)"
affects: ["129-02", "129-03", "130", "131"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "STRING + constantes em vez de enum de banco para name/status/origem (D-04 herdada da Fase 125)"
    - "payload_hash UNIQUE nomeado à mão como mecanismo de idempotência (não exists())"
    - "Classe de suporte sem I/O (secret sempre por parâmetro, nunca config()/Log:: internos)"

key-files:
  created:
    - database/migrations/2026_08_14_100000_create_contrato_assinatura_eventos_table.php
    - app/Models/ContratoAssinaturaEvento.php
    - app/Support/Clicksign/ClicksignHmacVarredura.php
    - app/Console/Commands/ClicksignVerificarAssinatura.php
    - app/Http/Controllers/Api/ClicksignSondaHmacController.php
    - tests/Unit/Phase129/ClicksignHmacVarreduraTest.php
    - tests/Feature/Phase129/ContratoAssinaturaEventoSchemaTest.php
    - tests/Feature/Phase129/ClicksignVerificarAssinaturaCommandTest.php
    - tests/Feature/Phase129/ClicksignSondaHmacTest.php
  modified:
    - routes/web.php

key-decisions:
  - "FORMULA_CONFIRMADA permanece null neste plano — a fórmula só é fixada no plano 129-02, depois de medir contra webhook real vindo do túnel (D-08)"
  - "payload é JSON genérico em toda a cadeia — nenhum campo do document embutido foi promovido a coluna própria"
  - "Rota-sonda sempre responde 200, mesmo com assinatura inválida ou JSON malformado — evita que a Clicksign pare de reentregar durante a janela de medição"

patterns-established:
  - "Toda FK/índice novo desta fase nomeado à mão (cae_payload_hash_uniq, cae_contrato_fk, cae_envelope_idx, cae_status_created_idx)"
  - "Idempotência de webhook por captura de QueryException com (string) $e->getCode() === '23000', nunca errorInfo[1]"

requirements-completed: [DADOS-03]
# CLICK-03 ("recusa webhook cuja assinatura não confere") listada no
# frontmatter do plano mas NÃO fechada aqui de propósito: a sonda desta
# task sempre responde 200, mesmo com assinatura inválida (D-08), e
# `confere()` lança RuntimeException enquanto FORMULA_CONFIRMADA for null.
# A recusa de verdade só existe quando o receiver de produção (129-03)
# ligar depois do gate A1 fechar.

# Metrics
duration: 14min
completed: 2026-08-13
---

# Phase 129 Plan 01: Instrumento de medição do gate A1 + schema de evento bruto Summary

**Tabela `contrato_assinatura_eventos` (idempotente por `payload_hash` UNIQUE), classe pura `ClicksignHmacVarredura` que varre as 4 fórmulas candidatas de HMAC, comando `clicksign:verificar-assinatura` e rota-sonda temporária — nenhuma fórmula foi eleita, por desenho.**

## Performance

- **Duration:** 14 min (entre os 3 commits de task)
- **Started:** 2026-08-13T09:02:28-03:00
- **Completed:** 2026-08-13T09:08:09-03:00
- **Tasks:** 3/3
- **Files modified:** 9 criados, 1 modificado (routes/web.php)

## Accomplishments
- `contrato_assinatura_eventos` criada e migrada com sucesso contra o MariaDB local (`Ran`, não `Pending` — a armadilha de nome de índice > 64 chars da Fase 122 foi evitada de propósito)
- `ClicksignHmacVarredura` calcula e identifica as 4 candidatas de HMAC sem nenhum I/O; `confere()` lança `RuntimeException` enquanto o gate A1 não fechar — fusível que impede um receiver de produção chutar a fórmula
- `clicksign:verificar-assinatura` lê um evento já gravado e diz qual candidata bate, sem imprimir o secret nem o hash inteiro
- Rota-sonda `POST /api/webhooks/clicksign-sonda` pronta para receber o webhook real via túnel — mede, grava bruto, sempre 200

## Task Commits

Each task was committed atomically:

1. **Task 1: Tabela `contrato_assinatura_eventos` + model (DADOS-03)** - `355bc4c4` (feat)
2. **Task 2: Varredura pura das 4 candidatas + comando `clicksign:verificar-assinatura`** - `cda0c42c` (feat)
3. **Task 3: Rota-sonda temporária do gate A1** - `7562619b` (feat)

_Nenhuma task teve TDD — plano `type="execute"` padrão._

## Files Created/Modified
- `database/migrations/2026_08_14_100000_create_contrato_assinatura_eventos_table.php` - schema do evento bruto, payload JSON genérico, FK/índices nomeados à mão
- `app/Models/ContratoAssinaturaEvento.php` - fillable explícito, constantes STATUS_*/ORIGEM_*, sem LogsActivity
- `app/Support/Clicksign/ClicksignHmacVarredura.php` - as 4 candidatas, `identificarTodas()`/`vencedora()`/`confere()`, `FORMULA_CONFIRMADA = null`
- `app/Console/Commands/ClicksignVerificarAssinatura.php` - comando `clicksign:verificar-assinatura`, leitura read-only de evento já gravado
- `app/Http/Controllers/Api/ClicksignSondaHmacController.php` - receiver temporário do túnel, log seguro, idempotência por SQLSTATE 23000
- `routes/web.php` - rota `webhooks.clicksign.sonda`, CSRF isento + throttle:60,1

## Decisions Made
- Nenhuma fórmula de HMAC foi eleita — `FORMULA_CONFIRMADA` permanece `null` por desenho do gate A1; isso é o propósito deste plano, não uma pendência.
- Migration aplicada localmente contra o MariaDB (não só SQLite dos testes) para confirmar as duas armadilhas conhecidas (1830 e 1059) não ocorreram — `migrate:status` mostra `Ran`.
- **CLICK-03 NÃO foi marcada como concluída** apesar de listada no frontmatter do plano: "recusa webhook cuja assinatura não confere" exige um receiver que rejeite de verdade, e a sonda desta task sempre responde 200 (D-08, deliberado — a Clicksign pararia de reentregar durante a janela de medição). A recusa real só existe no receiver de produção do plano 129-03, depois do gate A1 fechar. `DADOS-03` foi marcada como `Done` em `.planning/REQUIREMENTS-v22.md` — essa sim genuinamente satisfeita (todo evento é gravado bruto e idempotente).

## Deviations from Plan

None - plan executado exatamente como escrito.

## Issues Encountered
- A execução do comando `clicksign:verificar-assinatura --ultimo` contra o banco local de desenvolvimento (MariaDB) inicialmente falhou porque a migration ainda não tinha sido aplicada fora do ambiente de teste (SQLite). Resolvido rodando `artisan migrate --path=...` explicitamente para a tabela nova — não é regressão, é o passo natural de verificar a migration contra o MariaDB real antes de considerar a task pronta (mesma disciplina exigida no `<verification>` do plano).

## User Setup Required

None - nenhuma configuração de serviço externo é necessária neste plano. O `CLICKSIGN_WEBHOOK_SECRET` já existe em `config/services.php` desde antes desta fase; a medição real contra o sandbox (túnel + webhook) é escopo do plano 129-02.

## Next Phase Readiness
- O plano 129-02 pode subir um túnel local, apontar o painel do sandbox Clicksign para `/api/webhooks/clicksign-sonda`, rodar `clicksign:verificar-assinatura --ultimo` sobre o evento capturado, e gravar a candidata vencedora em `ClicksignHmacVarredura::FORMULA_CONFIRMADA`.
- Nenhum bloqueio identificado. A rota-sonda e o comando estão prontos para uso imediato.
- Lembrete herdado do CONTEXT: se a varredura ampla não bater em nenhuma candidata, a D-08 manda **parar a fase** e abrir investigação dedicada — o comando já imprime essa instrução.

## Self-Check: PASSED

Todos os 9 arquivos criados/modificados confirmados em disco; os 3 hashes de commit confirmados em `git log`.

---
*Phase: 129-webhook-clicksign-v22-0*
*Completed: 2026-08-13*
