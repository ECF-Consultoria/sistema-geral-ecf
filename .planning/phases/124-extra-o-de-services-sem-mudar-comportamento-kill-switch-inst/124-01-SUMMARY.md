---
phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst
plan: 01
subsystem: testing
tags: [phpunit, feature-test, comercial, mlb-empresas, mlb-implementacao, refactor-safety-net]

# Dependency graph
requires: []
provides:
  - "Baseline congelado do comportamento HOJE de ComercialController::store() (5 testes verdes)"
  - "Prova de gmail_colaborador chegando em dados.links_admin.gmail_colaborador na CRIAÇÃO (gap não coberto antes)"
  - "Prova de Incubadora ponta a ponta (MlbEmpresa sem MlbImplementacao)"
  - "Documentação em teste da divergência D-08 (Polos+Assessoria na mesma submissão criam DUAS MlbEmpresa)"
  - "Prova de que, com administrativo_bloqueio_ativo desligado ('0'), o roteamento é idêntico ao de hoje (REDE-01)"
affects: [124-03, 124-04, 124-05]

# Tech tracking
tech-stack:
  added: []
  patterns: ["Teste de caracterização (approval-style) que NÃO pode mudar após a refatoração — se mudar, é regressão"]

key-files:
  created: [tests/Feature/Phase124RegressaoComercialTest.php]
  modified: []

key-decisions:
  - "Estagio default confirmado por leitura das DUAS migrations (2026_04_30 declara 'Não Listado', mas 2026_05_08_000001_make_estagio_nullable_mlb_empresas tornou a coluna nullable/default null) — o literal vigente usado na asserção é null, não o da migration original"
  - "gmail_colaborador marcado explicitamente como comportamento TRANSITÓRIO (D8/D-02) — o teste morre de propósito na Fase 131, não é regressão quando isso acontecer"
  - "Divergência D-08 (duas MlbEmpresa numa submissão) documentada e travada com count()===2 — proibido 'consertar' aplicando o guard do HubSpot no laço do Comercial nesta fase"

patterns-established:
  - "Docblock obrigatório em teste de caracterização explicando POR QUE o comportamento é preservado mesmo quando parece um bug (D-08) ou é sabidamente transitório (gmail_colaborador)"

requirements-completed: [FLUXO-04, FLUXO-07, REDE-01]

duration: ~20min
completed: 2026-08-07
---

# Fase 124 Plano 01: Caracterização do cadastro manual (Comercial) Summary

**5 testes de caracterização travando o comportamento atual de `ComercialController::store()` antes da extração de `EmpresaOperacionalRouter` — cobrindo gmail_colaborador na criação, Incubadora ponta a ponta, a divergência D-08 de duas fichas e o roteamento com o interruptor `administrativo_bloqueio_ativo` desligado.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-08-07T18:2x:xxZ (não capturado com precisão no início da execução)
- **Completed:** 2026-08-07T18:39:24Z (timestamp do commit)
- **Tasks:** 2 (ambas fundidas num único arquivo, conforme o plano previa)
- **Files modified:** 1

## Accomplishments
- Arquivo `tests/Feature/Phase124RegressaoComercialTest.php` criado com 5 testes, todos verdes contra o código de produção intocado
- Gap real de cobertura fechado: `gmail_colaborador` agora está testado na CRIAÇÃO (antes só existia cobertura na edição, por outro endpoint)
- Divergência D-08 (Polos+Assessoria na mesma submissão criam DUAS `MlbEmpresa`, sem guard) documentada em teste com docblock explicando por que não deve ser "consertada" nesta fase
- Comportamento do interruptor desligado (`administrativo_bloqueio_ativo = '0'`) provado idêntico ao roteamento de hoje — é a metade "antes" do Phase Boundary da milestone
- Zero arquivos de produção tocados (`git status --porcelain app/` vazio)

## Task Commits

Ambas as tasks do plano foram implementadas no mesmo arquivo e commitadas juntas (o plano já previa que Task 2 estendesse o arquivo da Task 1; não houve necessidade de dois commits porque nenhuma verificação intermediária dependia de Task 1 estar isolada em commit próprio antes de Task 2 começar):

1. **Task 1 + Task 2: Caracterizar gmail_colaborador, Incubadora, divergência D-08 e interruptor desligado** - `dab006d2` (test)

**Plan metadata:** (a seguir, neste mesmo commit de fechamento)

## Files Created/Modified
- `tests/Feature/Phase124RegressaoComercialTest.php` - 5 testes de caracterização (255 linhas): gmail_colaborador com/sem valor, Incubadora ponta a ponta, divergência D-08, roteamento com interruptor desligado

## Decisions Made
- **Estagio default:** a acceptance criteria do plano pedia para "confirmar o literal na leitura, não presumir". A leitura da migration original (`2026_04_30_000003`) sozinha levaria a `'Não Listado'`, mas existe uma segunda migration (`2026_05_08_000001_make_estagio_nullable_mlb_empresas.php`) que muda a coluna para `nullable()->default(null)`. O literal vigente é `null` — confirmado rodando o teste contra o banco real (SQLite de teste aplica as duas migrations em ordem) e corrigido antes do commit.
- Demais decisões seguiram o plano e o CONTEXT.md sem desvio.

## Deviations from Plan

None - plan executado exatamente como escrito, com uma correção de acurácia dentro da própria diretriz do plano (confirmar o literal do default de `estagio` por leitura, não presumir pela primeira migration).

## Issues Encountered

Na primeira rodada, o teste de Incubadora falhou ao assertar `estagio === 'Não Listado'` — o valor real gravado era `null`. Investigação (via `grep` nas migrations de `mlb_empresas`) revelou a segunda migration que tornou a coluna nullable com default `null`, superando o default original. Corrigido ajustando a asserção para `assertNull($mlbEmp->estagio)`, com comentário explicando a superação de uma migration pela outra. Não é uma regressão de código de produção — é a acurácia da caracterização sendo corrigida antes do commit, exatamente como a acceptance criteria do plano exigia.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `tests/Feature/Phase124RegressaoComercialTest.php` está pronto para servir de baseline no gate da wave (comparação nominal, plano `124-03`)
- O plano `124-02` segue o mesmo padrão para o caminho webhook HubSpot (`Phase124RegressaoHubspotTest.php`)
- Nenhum bloqueio identificado para os planos seguintes da fase

---
*Phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst*
*Completed: 2026-08-07*

## Self-Check: PASSED

- FOUND: tests/Feature/Phase124RegressaoComercialTest.php
- FOUND: commit dab006d2 (test)
- FOUND: commit 29708468 (docs — este mesmo SUMMARY)
