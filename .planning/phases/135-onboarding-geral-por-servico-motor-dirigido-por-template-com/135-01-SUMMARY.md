---
phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com
plan: 01
subsystem: testing
tags: [phpunit, factory, eloquent, contrato-servico, baseline]

# Dependency graph
requires: []
provides:
  - "135-BASELINE-TESTES.md com a contagem numérica pré-existente das 6 suítes vigiadas pelo gate SC-02/D-02 (denominador da fase)"
  - "ContratoServicoFactory (com state paraServico()) para os testes do Observer do Plano 04"
  - "Diretório tests/Feature/Phase135/ com a primeira suíte verde"
affects: [135-04, 135-12]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Factory de model sem catálogo dedicado (Servico) resolve a FK criando o registro inline via closure no definition(), em vez de depender de uma factory que ainda não existe"

key-files:
  created:
    - .planning/phases/135-onboarding-geral-por-servico-motor-dirigido-por-template-com/135-BASELINE-TESTES.md
    - database/factories/ContratoServicoFactory.php
    - tests/Feature/Phase135/OnboardingWave0Test.php
  modified:
    - app/Models/ContratoServico.php

key-decisions:
  - "Baseline confirma 10 falhas pré-existentes (6 em PolosControllerTest + 4 em PolosFaturamentoSnapshotTest), batendo com o número documentado em painel-polos-status-e-meta.md §2"
  - "Os 4 arquivos de risco do Observer (Phase112/113/37) estão 100% verdes hoje — qualquer falha neles a partir do Plano 04 é regressão real, não ruído pré-existente"
  - "ContratoServicoFactory cria o Servico de apoio inline (setor Performance, mensal) em vez de depender de ServicoFactory, que não existe no projeto"

patterns-established:
  - "Baseline de regressão datada e amarrada a SHA do HEAD, registrada em doc de fase, como denominador auditável de gates de não-regressão"

requirements-completed: [SC-02, D-02, D-13]

# Metrics
duration: 9min
completed: 2026-08-11
---

# Fase 135 Plano 01: Baseline de regressão + fixture de ContratoServico Summary

**Baseline numérica das 6 suítes vigiadas pelo gate de regressão de Polos, e `ContratoServicoFactory` com state `paraServico()` para os testes do Observer virem no Plano 04.**

## Performance

- **Duration:** ~9 min
- **Started:** 2026-08-11T16:39:00Z (aprox.)
- **Completed:** 2026-08-11T16:47:32Z
- **Tasks:** 2
- **Files modified:** 4 (1 criado em `.planning/`, 2 criados em `database/`/`tests/`, 1 modificado em `app/`)

## Accomplishments
- `135-BASELINE-TESTES.md` registra, suíte a suíte, o "antes" numérico exigido pelo gate SC-02 — 10 falhas pré-existentes em Polos (já documentadas em `painel-polos-status-e-meta.md`) e os 4 arquivos de risco do Observer 100% verdes.
- `ContratoServicoFactory` criada no molde minimalista de `CompanyFactory`, cobrindo só os campos obrigatórios, com state `paraServico()` para os testes futuros do Observer.
- `ContratoServico` ganhou `HasFactory` sem tocar em `$fillable`, `$casts`, `getActivitylogOptions()` nem nos scopes existentes (diff = 2 linhas adicionadas, zero removidas).
- `tests/Feature/Phase135/OnboardingWave0Test.php` prova as duas pontas: persistência via factory (`ativo=true`, `data_vencimento=null`) e o state `paraServico()` fixando o `servico_id` pedido.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Registrar a baseline de falhas pré-existentes** - `f9cfec0c` (docs)
2. **Task 2: ContratoServicoFactory + diretório da suíte Phase135** - `04d6ba05` (feat)

**Plan metadata:** (a ser adicionado no commit final de documentação)

## Files Created/Modified
- `.planning/phases/135-onboarding-geral-por-servico-motor-dirigido-por-template-com/135-BASELINE-TESTES.md` - baseline numérica das 6 suítes, com SHA do HEAD e data da coleta
- `database/factories/ContratoServicoFactory.php` - factory de `ContratoServico` com state `paraServico()`
- `app/Models/ContratoServico.php` - adicionado `use HasFactory` (import + trait)
- `tests/Feature/Phase135/OnboardingWave0Test.php` - primeira suíte da fase (2 testes)

## Decisions Made
- Servico de apoio da factory é criado inline (`Servico::create(...)`, setor Performance, cobrança mensal) porque não existe `ServicoFactory` no projeto — decisão já prevista no plano ("se existir, senão criando inline").
- A tabela da baseline usa nota de rodapé para explicar por que a coluna `Errors` fica em 0 mesmo havendo 2 `ArgumentCountError`: o printer (`Collision`) do `artisan test` não separa erro não-capturado de falha de asserção no resumo — ambos caem no bucket `FAILED`. Documentado explicitamente para não confundir leitura futura.

## Deviations from Plan

None - plano executado exatamente como escrito.

## Issues Encountered
None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- O Plano 02 (ou o próximo da Wave 1) pode seguir para o schema/model do motor de Onboarding com a baseline de regressão já travada como denominador.
- O Plano 04 (Observer) já tem a fixture que precisa (`ContratoServico::factory()->paraServico($servicoGestao)->create()`).
- Nenhum arquivo de Polos ou de MlbImplementacao foi tocado — D-02 permanece intacto.

---
*Phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com*
*Completed: 2026-08-11*

## Self-Check: PASSED

- FOUND: `.planning/phases/135-onboarding-geral-por-servico-motor-dirigido-por-template-com/135-BASELINE-TESTES.md`
- FOUND: `database/factories/ContratoServicoFactory.php`
- FOUND: `tests/Feature/Phase135/OnboardingWave0Test.php`
- FOUND: commit `f9cfec0c`
- FOUND: commit `04d6ba05`
