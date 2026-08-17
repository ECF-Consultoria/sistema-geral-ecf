---
phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0
plan: 04
subsystem: dados / contrato-assinatura / catálogo de serviços
tags: [migration, model, config, d-21, d-03, click-08, dados-06, mariadb-pitfalls, tdd]

requires:
  - phase: 127-01
    provides: "contrato_assinaturas.servico_id, prazo_dias, lembrete_dias (colunas), relação servico()"
provides:
  - "servicos.clicksign_template_id — modelo .docx por serviço, com fallback para CLICKSIGN_TEMPLATE_ID"
  - "Servico::clicksignTemplateId() — resolução do modelo, null quando nenhum dos dois existe"
  - "ContratoAssinatura::prazoDiasEfetivo()/lembreteDiasEfetivo() — coluna do contrato ou padrão da config"
  - "config('services.clicksign.prazo_dias_padrao'/'lembrete_dias_padrao') com defaults 30/3"
affects: [127-05, 127-06, 127-07]

tech-stack:
  added: []
  patterns:
    - "modelo/config resolvido por acessor no model, nunca `if` hardcoded por nome de serviço/empresa"

key-files:
  created:
    - database/migrations/2026_08_12_100001_add_clicksign_template_id_to_servicos_table.php
    - tests/Feature/Phase127/ModeloPorServicoTest.php
  modified:
    - app/Models/Servico.php
    - app/Models/ContratoAssinatura.php
    - config/services.php
    - .env.example
    - tests/Feature/Phase127/MigrationsFase127ConvencoesTest.php

key-decisions:
  - "D-21 implementada: coluna servicos.clicksign_template_id, nullable, sem índice — resolução é dado, não código"
  - "D-03: prazo/lembrete promovidos de hardcoded na assinatura de ativarEnvelope() para config, sem mudar a assinatura do client"

patterns-established:
  - "clicksignTemplateId()/prazoDiasEfetivo()/lembreteDiasEfetivo(): acessor no model resolve coluna → fallback de config → null/default, nunca lido direto por chamador"

requirements-completed: [CLICK-08, DADOS-06]

duration: ~35min
completed: 2026-08-12
---

# Phase 127 Plan 04: Modelo por serviço (D-21) + prazo/lembrete efetivos (D-03) Summary

**Coluna `clicksign_template_id` em `servicos` com resolução por serviço (fallback pro `CLICKSIGN_TEMPLATE_ID` global) e acessores `prazoDiasEfetivo()`/`lembreteDiasEfetivo()` em `ContratoAssinatura`, com defaults 30/3 promovidos a configuração.**

## Performance

- **Duration:** ~35 min
- **Tasks:** 3
- **Files modified:** 6 (2 criados, 4 modificados)

## Accomplishments
- Cada serviço do catálogo pode ter seu próprio modelo `.docx` na Clicksign; serviço sem modelo próprio cai no `CLICKSIGN_TEMPLATE_ID` padrão sem quebrar, e sem nenhum modelo configurado devolve `null` (decisão do job de montagem do envelope, plano 127-05).
- Prazo e lembrete de assinatura agora são configuráveis (`CLICKSIGN_PRAZO_DIAS`/`CLICKSIGN_LEMBRETE_DIAS`, defaults 30/3 — os valores MEDIDOS como padrão da própria Clicksign) e resolvidos por contrato via `prazoDiasEfetivo()`/`lembreteDiasEfetivo()`.
- Cadastrar um serviço novo com modelo próprio não exige deploy — é dado (coluna), não `if` no código.

## Task Commits

Cada task foi commitada atomicamente (ciclo RED → GREEN, sem REFACTOR necessário):

1. **Task 1: Testes de resolução de modelo e de prazo (RED)** - `56198a88` (test)
2. **Task 2: Migration da coluna e resolução no model Servico (GREEN)** - `fa477d78` (feat)
3. **Task 3: Prazo e lembrete configuráveis (GREEN)** - `ff0208b9` (feat)

**Plan metadata:** (commit ainda a seguir, junto com STATE.md/ROADMAP.md)

## Files Created/Modified
- `database/migrations/2026_08_12_100001_add_clicksign_template_id_to_servicos_table.php` - coluna nova, nullable, sem índice/enum/nullOnDelete (nenhuma das 3 armadilhas de MariaDB se aplica)
- `app/Models/Servico.php` - `clicksign_template_id` no `$fillable` e no activity log; `clicksignTemplateId()` resolve coluna → fallback de config → `null`
- `app/Models/ContratoAssinatura.php` - `prazoDiasEfetivo()`/`lembreteDiasEfetivo()` resolvem coluna → fallback de config
- `config/services.php` - `services.clicksign.prazo_dias_padrao`/`lembrete_dias_padrao` (defaults 30/3, `env()`)
- `.env.example` - `CLICKSIGN_PRAZO_DIAS`/`CLICKSIGN_LEMBRETE_DIAS` documentadas; `CLICKSIGN_TEMPLATE_ID` continua sem valor (checado explicitamente)
- `tests/Feature/Phase127/ModeloPorServicoTest.php` - 7 testes novos (RED → GREEN)
- `tests/Feature/Phase127/MigrationsFase127ConvencoesTest.php` - generalizado para `caminhosMigrations()`, cobrindo os 2 arquivos de migration da fase

## Decisions Made
- **D-21 (herdada da Fase 126):** modelo `.docx` é dado por serviço (coluna `servicos.clicksign_template_id`), não `if` por nome de serviço — porque a cláusula 2.1 de Gestão de ADS não pode sair num contrato de Shopee, e um serviço novo com modelo próprio não pode exigir deploy.
- **D-03:** prazo/lembrete são aplicados na CRIAÇÃO do envelope (não na ativação), porque a ativação acontece fora do sistema (D-02, interface da Clicksign) e o valor se perderia. A assinatura de `ClicksignClient::ativarEnvelope(string $envelopeId, int $prazoDias = 30, int $lembreteDias = 3)` NÃO foi alterada — os defaults hardcoded ali continuam batendo com os novos defaults de configuração (30/3), mas quem monta o envelope (plano 127-05) deve passar `prazoDiasEfetivo()`/`lembreteDiasEfetivo()` explicitamente, não confiar nos defaults da assinatura.
- **CLICK-08:** o lembrete é nativo da Clicksign (`remind_interval`) — nenhum scheduler próprio foi criado, evitando notificação duplicada.

## Deviations from Plan

None - plano executado exatamente como escrito. O único ajuste foi generalizar `MigrationsFase127ConvencoesTest` de um caminho único (`caminhoMigration()`) para `caminhosMigrations()` (array), exatamente como a `<action>` da Task 1 instruiu ("acrescentar o caminho da migration nova em `caminhosMigrations()`").

## Issues Encountered
None.

## User Setup Required

None - `.env.example` documenta as duas variáveis novas (`CLICKSIGN_PRAZO_DIAS`, `CLICKSIGN_LEMBRETE_DIAS`) com defaults que já funcionam sem configuração adicional. `CLICKSIGN_TEMPLATE_ID` continua sem valor de exemplo (decisão preexistente da Fase 126, confirmada intacta).

## Next Phase Readiness

Modelo por serviço e prazo/lembrete efetivos estão prontos para o job de montagem do envelope (plano 127-05) consumir: `Servico::clicksignTemplateId()` e `ContratoAssinatura::prazoDiasEfetivo()`/`lembreteDiasEfetivo()`. O plano 127-05 precisa tratar `clicksignTemplateId() === null` como falha com mensagem clara (não gerar contrato errado) — comportamento intencionalmente deixado para lá, coberto pelo Teste 3 desta suíte.

Suíte combinada `Phase125 + Phase126 + Phase127` = **187 testes verdes** (baseline 179 intacto + 8 novos). Zero regressão. Nenhuma chamada real à Clicksign; nenhum deploy.

---
*Phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0*
*Completed: 2026-08-12*

## Self-Check: PASSED

- FOUND: database/migrations/2026_08_12_100001_add_clicksign_template_id_to_servicos_table.php
- FOUND: tests/Feature/Phase127/ModeloPorServicoTest.php
- FOUND: app/Models/Servico.php (com clicksignTemplateId)
- FOUND: app/Models/ContratoAssinatura.php (com prazoDiasEfetivo/lembreteDiasEfetivo)
- FOUND: config/services.php (prazo_dias_padrao/lembrete_dias_padrao)
- FOUND: .env.example (CLICKSIGN_PRAZO_DIAS/CLICKSIGN_LEMBRETE_DIAS)
- FOUND commit 56198a88 (test RED)
- FOUND commit fa477d78 (feat — coluna + Servico)
- FOUND commit ff0208b9 (feat — prazo/lembrete)
