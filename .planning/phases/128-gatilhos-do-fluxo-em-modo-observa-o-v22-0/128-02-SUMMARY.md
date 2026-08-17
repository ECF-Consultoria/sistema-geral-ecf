---
phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0
plan: 02
subsystem: comercial
tags: [laravel, eloquent, pendencias, gate-administrativo, tdd]

# Dependency graph
requires:
  - phase: 128-01
    provides: "Servico::exigeContrato() + scopeExigeContrato() (isenção de Polos no catálogo, não usada aqui de propósito)"
provides:
  - "PendenciasComerciaisService::calcularUniversais() — as 4 pendências universais (sem_servico, sem_valor, sem_setor, sem_contato) calculáveis para QUALQUER empresa, inclusive cadastro manual sem HubspotEvento"
  - "4 helpers privados (pendenciaSemServico/pendenciaSemValor/pendenciaSemSetor/pendenciaSemContato) compartilhados entre calcular() e calcularUniversais()"
affects: [128-03, 128-04]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Método irmão (Opção B da pesquisa) em vez de flag/parâmetro em calcular() — preserva byte-a-byte o comportamento observável já travado por testes de fase anterior"
    - "Helpers privados retornando ?string (slug ou null) compartilhados entre dois métodos públicos com escopos de origem diferentes"

key-files:
  created:
    - tests/Feature/Phase128/PendenciasUniversaisTest.php
  modified:
    - app/Services/Comercial/PendenciasComerciaisService.php

key-decisions:
  - "calcularUniversais() não checa is_origem_hubspot nem exige_contrato — a isenção de Polos fica para o orquestrador do gate no plano 03, para não misturar dois conceitos no mesmo método"
  - "As 3 checagens hubspot-only (servico_nao_reconhecido, valor_revisar, possivel_duplicidade) permanecem inline em calcular(), sem extração — não há motivo para movê-las"

patterns-established:
  - "Helper privado por checagem de pendência, corpo copiado literalmente da condição original — zero reescrita de lógica ao extrair"

requirements-completed: [REDE-06]

# Metrics
duration: 12min
completed: 2026-08-12
---

# Phase 128 Plan 02: Pendências universais do gate administrativo Summary

**`PendenciasComerciaisService::calcularUniversais()` habilita as 4 pendências universais para qualquer empresa (inclusive cadastro manual) sem mudar uma linha do comportamento observável de `calcular()`, que alimenta a listagem do Comercial.**

## Performance

- **Duration:** 12 min
- **Started:** 2026-08-12T14:09:00-03:00 (aprox.)
- **Completed:** 2026-08-12T14:21:23-03:00
- **Tasks:** 2/2 completas
- **Files modified:** 2 (1 modificado, 1 criado)

## Accomplishments
- `calcular()` refatorado para chamar 4 helpers privados extraídos por cópia literal (`pendenciaSemServico`, `pendenciaSemValor`, `pendenciaSemSetor`, `pendenciaSemContato`), sem alterar o array de slugs retornado nem a ordem — provado pelos 2 suites de regressão (Phase37 + Phase114) verdes sem edição
- `calcularUniversais(Company $c): array` novo — reusa os mesmos 4 helpers, ignora `is_origem_hubspot`, nunca retorna as 3 pendências hubspot-only
- Teste dedicado prova a D-01 nos dois sentidos: o gate enxerga pendência em empresa de cadastro manual (`calcularUniversais() !== []`) e a listagem do Comercial continua cega para a mesma empresa (`calcular() === []`)

## Task Commits

Each task was committed atomically:

1. **Task 1: Extrair as 4 checagens universais para helpers privados (refactor puro)** - `eb2c95f6` (refactor)
2. **Task 2: calcularUniversais() + teste da D-01 nos dois sentidos** - `ec2d47d9` (feat, inclui o teste TDD)

## Files Created/Modified
- `app/Services/Comercial/PendenciasComerciaisService.php` - 4 helpers privados extraídos + `calcularUniversais()` novo
- `tests/Feature/Phase128/PendenciasUniversaisTest.php` - 7 testes cobrindo as 4 universais isoladamente, a exclusão das 3 hubspot-only e a regressão da D-01

## Decisions Made
- Reafirmada a decisão do plano: `calcularUniversais()` não sabe nada sobre `exige_contrato` (isenção de Polos) — essa responsabilidade é do orquestrador do gate, plano 128-03. Misturar os dois quebraria a listagem, que precisa continuar vendo Polos.
- Nenhuma checagem hubspot-only foi movida — permanecem inline em `calcular()`, exatamente como o plano determinou.

## Deviations from Plan

**1. [TDD gate] Commits de teste e implementação combinados em um único commit na Task 2**
- **Found during:** Task 2
- **Issue:** O fluxo padrão de TDD pede commit `test(...)` (RED) separado do commit `feat(...)` (GREEN). Nesta execução o teste foi escrito, confirmado falhando (RED verificado via `artisan test` — 7 falhas, incluindo `Call to undefined method calcularUniversais()`), a implementação foi feita, o teste confirmado passando (GREEN — 7 verdes), mas os dois arquivos foram commitados juntos em `ec2d47d9`.
- **Impact:** Nenhum no comportamento entregue — RED e GREEN foram executados e verificados na ordem correta, só a granularidade do commit ficou menor que o padrão. Ver seção "TDD Gate Compliance" abaixo.
- **Files modified:** app/Services/Comercial/PendenciasComerciaisService.php, tests/Feature/Phase128/PendenciasUniversaisTest.php
- **Committed in:** ec2d47d9

---

**Total deviations:** 1 (processo de commit, sem impacto funcional)
**Impact on plan:** Nenhum — comportamento entregue e verificado exatamente como especificado; só a rastreabilidade do commit RED→GREEN ficou combinada em um commit em vez de dois.

## TDD Gate Compliance

A Task 2 tem `tdd="true"`. O ciclo RED→GREEN foi executado e verificado (teste escrito e rodado com falha esperada antes da implementação; depois implementado e rodado com sucesso), mas **não há um commit `test(...)` separado do commit `feat(...)`** no git log — ambos os arquivos foram commitados juntos em `ec2d47d9`. A verificação em `git log` não encontra o padrão RED-commit-antes-de-GREEN-commit exigido pelo gate de conformidade. Comportamento correto entregue; rastreabilidade de commit fica como nota para a próxima execução TDD desta fase.

## Issues Encountered
None.

## Next Phase Readiness
- `calcularUniversais()` está pronto para ser consumido pelo orquestrador do gate administrativo (plano 128-03), que ainda precisa aplicar a isenção de Polos (`Servico::exigeContrato()` do plano 128-01) por cima do resultado.
- Nenhum bloqueio conhecido.

---
*Phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0*
*Completed: 2026-08-12*
