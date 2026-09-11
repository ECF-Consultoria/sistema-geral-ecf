---
phase: 152-checklist-administrativo-trava-de-finaliza-o-v23-0
plan: 03
subsystem: backend
tags: [checklist-administrativo, value-object, catalogo-em-codigo, contrato-de-resolver]

# Dependency graph
requires:
  - phase: 152-02
    provides: "Tabela checklist_administrativo_itens no MariaDB (company_id) + model App\\Models\\ChecklistAdministrativoItem"
provides:
  - "App\\Contracts\\ChecklistResolver — interface síncrona de resolver, sem método de assincronismo"
  - "App\\Services\\ChecklistAdministrativo\\ChecklistResolverResultado — value object de 3 estados (concluido|nao_coletado|indeterminado)"
  - "App\\Services\\ChecklistAdministrativo\\ChecklistAdministrativoDefinicao — catálogo fechado dos 9 itens do §5, com montagem condicional do grupo Contrato (D-07)"
affects: [152-04, 152-05, 152-06, 152-07, 152-08, 152-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Catálogo em código estático (sem versionamento, sem cópia para colunas) para lista fechada e travada por decisão — molde reduzido de DefinicaoOnboarding"
    - "Value object final readonly de 3 estados para resultado de resolver — nunca bool — mesmo shape de OnboardingResolverResultado, sem os ramos de coleta assíncrona"
    - "Montagem condicional de grupo (D-07) em vez de estado 'não aplicável' marcado — item que não se aplica simplesmente não é instanciado"

key-files:
  created:
    - app/Contracts/ChecklistResolver.php
    - app/Services/ChecklistAdministrativo/ChecklistResolverResultado.php
    - app/Services/ChecklistAdministrativo/ChecklistAdministrativoDefinicao.php
    - tests/Unit/Phase152/ChecklistDefinicaoCatalogoTest.php
  modified: []

key-decisions:
  - "D-01/D-03 implementados: os 9 itens do catálogo são exatamente os do §5, na ordem 1..9, cada um com grupo/natureza/auto_fonte declarados em código"
  - "D-07 implementado: itens(false) filtra o grupo Contrato por montagem — devolve 6 itens — em vez de marcar 3 itens como 'não aplicável' (proibido pela D-02)"
  - "D-10 implementado: ChecklistResolverResultado copia o shape de 3 estados de OnboardingResolverResultado, mas sem CHAVE_COLETA_EM_ANDAMENTO/sinalizouColetaEmAndamento — os 4 resolvers desta fase são síncronos"
  - "Interface ChecklistResolver deliberadamente sem método de assincronismo (diferente de OnboardingResolver::assincrono()) — os 4 resolvers desta fase leem coluna local, sem rede"

patterns-established:
  - "Docblock explicativo de ausência de identificador proibido escrito em prosa acentuada ('não aplicável'), nunca no identificador snake_case literal ('nao_aplicavel') — evita falso positivo do grep de auditoria do próprio plano (lição herdada do 152-02)"

requirements-completed: [ADMIN-01, ADMIN-02, ADMIN-03, ADMIN-04]

# Metrics
duration: ~20min
completed: 2026-09-09
---

# Phase 152 Plan 03: Contrato de resolver, value object de 3 estados e catálogo fechado dos 9 itens Summary

**Vocabulário fechado do checklist administrativo: interface síncrona de resolver, value object de 3 estados e o catálogo em código dos 9 itens do §5 — com `itens(false)` devolvendo só os 6 do grupo Entrada para serviço isento de contrato (D-07), sem nenhum estado "não aplicável" (D-02).**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-09-09T19:00:00Z (aprox.)
- **Completed:** 2026-09-09T19:07:00Z
- **Tasks:** 3/3
- **Files modified:** 4 (todos criados novos)

## Accomplishments

- `app/Contracts/ChecklistResolver.php` — interface com `chave()`, `label()`, `ajuda()` e `resolver(Company $company): ChecklistResolverResultado`; sem método de assincronismo, diferença deliberada em relação a `OnboardingResolver` registrada em docblock pt-BR.
- `app/Services/ChecklistAdministrativo/ChecklistResolverResultado.php` — `final readonly class` com os três estados `CONCLUIDO`/`NAO_COLETADO`/`INDETERMINADO`, três construtores estáticos e três predicados; sem `CHAVE_COLETA_EM_ANDAMENTO`/`sinalizouColetaEmAndamento()`.
- `app/Services/ChecklistAdministrativo/ChecklistAdministrativoDefinicao.php` — catálogo fechado com os 9 itens do §5 na ordem 1..9, cada um com `ordem`/`chave`/`titulo`/`grupo`/`natureza`/`auto_fonte`/`ajuda`; `itens(true)` devolve 9, `itens(false)` devolve os 6 do grupo Entrada; `item()` devolve `null` para chave desconhecida; `chaves()` expõe só os slugs para `Rule::in()`.
- `tests/Unit/Phase152/ChecklistDefinicaoCatalogoTest.php` — 7 testes/58 assertions provando D-01 (9/6), D-07 (ausência do grupo Contrato para isento), D-03 (ordem e natureza/auto_fonte de cada item), a defesa de chave órfã e a grafia "Adman".
- Suíte de regressão `tests/Unit/Phase150 + tests/Feature/Phase150 + tests/Feature/Phase151` rodou **123 testes / 444 assertions, 100% verde** — idêntica à baseline do `152-BASELINE-TESTES.md`. `tests/Unit/Phase152` completo (incluindo o plano 02) rodou **12 testes / 71 assertions, 100% verde**.

## Task Commits

Each task was committed atomically:

1. **Task 1: Contrato ChecklistResolver e value object de 3 estados** - `697195e4` (feat)
2. **Task 2: Catálogo fechado dos 9 itens (ChecklistAdministrativoDefinicao)** - `c2dcddcc` (feat)
3. **Task 3: Teste unitário do catálogo (D-01, D-03, D-07)** - `1dad3cf8` (test)

## Files Created/Modified

- `app/Contracts/ChecklistResolver.php` - interface síncrona de resolver
- `app/Services/ChecklistAdministrativo/ChecklistResolverResultado.php` - value object de 3 estados
- `app/Services/ChecklistAdministrativo/ChecklistAdministrativoDefinicao.php` - catálogo fechado dos 9 itens
- `tests/Unit/Phase152/ChecklistDefinicaoCatalogoTest.php` - prova de D-01, D-03, D-07

## Decisions Made

Nenhuma decisão nova — as três tasks implementam à letra D-01, D-02, D-03, D-04, D-05, D-06, D-07, D-10 e D-13, já travadas no `152-CONTEXT.md`. A única disciplina aplicada durante a escrita foi a lição herdada do plano 02: os docblocks explicando por que o estado "não aplicável" não existe usam a prosa acentuada, nunca o identificador literal `nao_aplicavel`, para não produzir falso positivo no grep de auditoria do próprio plano.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

Uma correção de sintaxe durante a escrita da Task 3: `assertNotContains()` na versão do PHPUnit deste projeto (11.5) não aceita mais o parâmetro de comparação estrita como terceiro argumento (assinatura mudou a partir do PHPUnit 10). Corrigido inline para `assertFalse(in_array(..., true))` antes de rodar os testes pela primeira vez — não chegou a gerar um commit com erro, então não é deviation, é ajuste de rascunho.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `App\Contracts\ChecklistResolver`, `App\Services\ChecklistAdministrativo\ChecklistResolverResultado` e `App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao` estão prontos para o plano 152-04 (os 4 resolvers síncronos) e o 152-05 (`ChecklistAdministrativoService`) consumirem — nomes e assinaturas não devem ser renomeados (contrato citado no bloco `<interfaces>` do próprio plano).
- `ChecklistAdministrativoDefinicao::chaves()` já está pronta para alimentar `Rule::in()` no controller do plano 152-08 (T-152-03-01).
- Nenhum bloqueio identificado para o plano 152-04.

---
*Phase: 152-checklist-administrativo-trava-de-finaliza-o-v23-0*
*Completed: 2026-09-09*

## Self-Check: PASSED

- FOUND: `app/Contracts/ChecklistResolver.php`
- FOUND: `app/Services/ChecklistAdministrativo/ChecklistResolverResultado.php`
- FOUND: `app/Services/ChecklistAdministrativo/ChecklistAdministrativoDefinicao.php`
- FOUND: `tests/Unit/Phase152/ChecklistDefinicaoCatalogoTest.php`
- FOUND commit: `697195e4`
- FOUND commit: `c2dcddcc`
- FOUND commit: `1dad3cf8`
- FOUND commit: `e56e7fde`
