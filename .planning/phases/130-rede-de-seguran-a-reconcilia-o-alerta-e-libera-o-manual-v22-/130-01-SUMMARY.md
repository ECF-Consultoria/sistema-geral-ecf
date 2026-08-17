---
phase: 130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-
plan: 01
subsystem: database
tags: [laravel, eloquent, migration, sqlite, mariadb]

# Dependency graph
requires:
  - phase: 129-webhook-clicksign-liberacao-idempotente
    provides: "EmpresaOperacionalRouter::liberarEmpresa() com lock por empresa, guard de idempotencia e via webhook/manual"
provides:
  - "ContratoLiberacao::VIA_RECONCILIACAO (terceira via de liberacao, D-07)"
  - "ContratoLiberacao::MOTIVOS_MANUAIS + MOTIVOS_MANUAIS_LABELS (lista fechada de motivos, D-12)"
  - "coluna motivo_slug em contrato_liberacoes"
  - "coluna ultimo_alerta_em em contrato_assinaturas (D-04, cooldown de alerta)"
  - "EmpresaOperacionalRouter::liberarEmpresa(motivoSlug:) parametro nomeado aditivo"
affects: [130-03-reconciliacao, 130-04-liberacao-manual, 130-05-alerta-contrato-preso]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Migration aditiva com Schema::hasColumn como guard em up() e down() (mesma tecnica de 2026_08_14_100002)"
    - "Coluna de vocabulario fechado sempre string(), nunca enum() de banco — lista imposta em codigo (Rule::in no controller consumidor)"

key-files:
  created:
    - database/migrations/2026_08_15_100000_add_motivo_slug_to_contrato_liberacoes_table.php
    - database/migrations/2026_08_15_100001_add_ultimo_alerta_em_to_contrato_assinaturas_table.php
    - tests/Feature/Phase130/FundacaoContratoLiberacaoTest.php
    - tests/Feature/Phase130/FundacaoContratoAssinaturaTest.php
  modified:
    - app/Models/ContratoLiberacao.php
    - app/Models/ContratoAssinatura.php
    - app/Services/Operacional/EmpresaOperacionalRouter.php

key-decisions:
  - "Nenhuma decisão nova — plano executado exatamente como especificado (schema e constantes puras, zero lógica de negócio)"

patterns-established:
  - "motivo_slug e motivo continuam sendo colunas separadas (categoria + detalhe), nunca concatenadas em uma string — decisão já travada pelo plano, não reinterpretada aqui"

requirements-completed: [REDE-02, REDE-03, REDE-04, DADOS-05]

# Metrics
duration: 25min
completed: 2026-08-13
---

# Phase 130 Plano 01: Fundação da rede de segurança — terceira via, motivos e carimbo de alerta Summary

**Terceira via `reconciliacao` + lista fechada de 4 motivos manuais + coluna `ultimo_alerta_em`, todas aditivas, sem tocar no lock nem na idempotência da Fase 129**

## Performance

- **Duration:** 25 min
- **Started:** 2026-08-13T18:24:00Z
- **Completed:** 2026-08-13T18:49:00Z
- **Tasks:** 2 completadas
- **Files modified:** 7 (3 modificados, 4 criados)

## Accomplishments
- `ContratoLiberacao::VIA_TODAS` passou de 2 para 3 vias (`webhook`, `manual`, `reconciliacao`), preparando o terreno para o job de reconciliação do plano 130-03
- Lista fechada de 4 motivos da liberação manual (`MOTIVOS_MANUAIS`) com rótulos em linguagem simples (`MOTIVOS_MANUAIS_LABELS`), pronta para o controller do plano 130-04 impor via `Rule::in()` e a UI espelhar em JS
- `EmpresaOperacionalRouter::liberarEmpresa()` ganhou o parâmetro nomeado `motivoSlug`, aditivo — zero mudança de comportamento para o único call-site real (`ProcessarEventoClicksignJob.php:220`)
- `contrato_assinaturas.ultimo_alerta_em` criada — o cooldown que o alerta do plano 130-05 vai ler para não repetir aviso todo dia

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Terceira via de liberação + lista fechada de motivos (D-07, D-12)** - `f07c8e1d` (feat)
2. **Task 2: Carimbo de último alerta por contrato (D-04)** - `776b6c15` (feat)

## Files Created/Modified
- `app/Models/ContratoLiberacao.php` - `VIA_RECONCILIACAO`, `MOTIVOS_MANUAIS`, `MOTIVOS_MANUAIS_LABELS`, `motivo_slug` no `$fillable`, docblock de classe atualizado
- `app/Services/Operacional/EmpresaOperacionalRouter.php` - parâmetro nomeado `motivoSlug` em `liberarEmpresa()`, gravado em `motivo_slug` do `create()`
- `database/migrations/2026_08_15_100000_add_motivo_slug_to_contrato_liberacoes_table.php` - coluna `motivo_slug` (string 40, nullable, aditiva)
- `database/migrations/2026_08_15_100001_add_ultimo_alerta_em_to_contrato_assinaturas_table.php` - coluna `ultimo_alerta_em` (timestamp, nullable, aditiva)
- `app/Models/ContratoAssinatura.php` - `ultimo_alerta_em` no `$fillable` e no `$casts` (datetime)
- `tests/Feature/Phase130/FundacaoContratoLiberacaoTest.php` - 5 testes: `VIA_TODAS`, `MOTIVOS_MANUAIS`+labels, coluna existe, liberação manual grava slug+detalhe, liberação por reconciliação grava via correta e slug nulo
- `tests/Feature/Phase130/FundacaoContratoAssinaturaTest.php` - 3 testes: coluna existe, factory nasce com nulo, update persiste e volta como `Carbon`

## Decisions Made
Nenhuma decisão nova tomada durante a execução — plano seguido exatamente como especificado. Todas as decisões de domínio (D-04, D-07, D-12) já estavam travadas no `130-CONTEXT.md` e no `130-PATTERNS.md`.

## Deviations from Plan

None - plano executado exatamente como escrito.

## Issues Encountered

`php artisan migrate:status` não pôde ser executado contra o MariaDB local (instabilidade conhecida do ambiente, já documentada em `.planning/learnings/`) — `PDOException: Nenhuma conexão pôde ser feita`. Não é regressão desta task: as migrations foram exercitadas e provadas via `RefreshDatabase` (SQLite em memória) nos 8 testes de `Phase130`, que passam, incluindo as asserções `Schema::hasColumn(...)` para as duas colunas novas.

`requirements.mark-complete REDE-02 REDE-03 REDE-04 DADOS-05` devolveu `not_found` — os IDs vivem em `.planning/REQUIREMENTS-v22.md`, não no `REQUIREMENTS.md` raiz (limitação já registrada na memória do projeto). Optei por **não** marcar os checkboxes à mão em `REQUIREMENTS-v22.md` desta vez: este plano é só a fundação (schema + constantes, "O que este plano NÃO faz" no PLAN.md é explícito) e a fase 130 tem mais 6 planos que entregam o comportamento de fato (reconciliação, alerta, liberação manual). Marcar agora seria declarar entrega antes de existir. Os checkboxes devem ser marcados manualmente quando a fase 130 fechar.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `ContratoLiberacao::VIA_RECONCILIACAO` pronta para o plano 130-03 (`ReconciliarContratoClicksignJob`)
- `ContratoLiberacao::MOTIVOS_MANUAIS`/`MOTIVOS_MANUAIS_LABELS` e o parâmetro `motivoSlug` prontos para o plano 130-04 (`ContratoLiberacaoManualController`)
- `contrato_assinaturas.ultimo_alerta_em` pronta para o plano 130-05 (comando de alerta de contrato preso)
- Suíte `Phase129` (80 testes) verde após a mudança de assinatura — nenhum call-site existente quebrou
- Nenhum bloqueio conhecido para os planos seguintes desta fase

## Self-Check: PASSED

Todos os 4 arquivos criados foram confirmados no disco e os 2 commits de task (`f07c8e1d`, `776b6c15`) foram confirmados em `git log`.

---
*Phase: 130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-*
*Completed: 2026-08-13*
