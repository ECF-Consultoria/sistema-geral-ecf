---
phase: 150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
plan: 06
subsystem: database
tags: [laravel, phpunit, mass-assignment, static-analysis, security-regression]

# Dependency graph
requires:
  - phase: 150-03
    provides: "EtapaTransicaoService — único ponto de escrita de companies.etapa (transicionar()/carimbarBackfill())"
  - phase: 150-05
    provides: "EtapaBackfill.php — delega toda escrita a EtapaTransicaoService::carimbarBackfill(), não escreve etapa diretamente"
provides:
  - "Teste de regressão permanente (EtapaPontoUnicoTest) que prova companies.etapa e pendencia_* não são graváveis por mass assignment em CompanyController::update()"
  - "Varredura estática de app/ escopada por corpo de função, provada por injeção temporária de violação"
  - "Aviso escrito em CompanyController::update() apontando para o serviço e para o teste que cobra"
affects: [150-07, 138, 139, 141, 142]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Gate estático por corpo de função (token_get_all + balanceamento de chaves), não por linha nem por arquivo inteiro — evita falso positivo quando duas colunas homônimas (companies.etapa vs onboarding_passos.etapa) coexistem no mesmo app/"
    - "Prova de gate por injeção temporária: antes de aceitar um teste de regressão como válido, introduzir a violação de propósito, confirmar falha nomeando arquivo:linha, reverter, confirmar volta ao verde"

key-files:
  created:
    - tests/Feature/Phase150/EtapaPontoUnicoTest.php
  modified:
    - app/Http/Controllers/CompanyController.php

key-decisions:
  - "Rule 1 (auto-fix): o padrão cru \"'etapa' =>\" descrito literalmente no plano falso-positiva contra ~10 escritas/leituras legítimas de OnboardingPasso::etapa (coluna homônima e não relacionada a companies.etapa) em 5 arquivos de app/Console/Commands e app/Services/Onboarding e app/Http/Controllers/OnboardingController.php — implementá-lo ao pé da letra faria o teste nascer vermelho contra o próprio acceptance criteria do plano (\"passa com o estado atual do repositório\"). Corrigido escopando a chave 'etapa' => a corpos de função que TAMBÉM contêm uma chamada de escrita reconhecida no model Company (Company::create/updateOrCreate/firstOrCreate/forceCreate, \$company->update/fill/forceFill, ou Company:: combinado com ->update() no mesmo corpo — cobre o padrão que EtapaTransicaoService::carimbarBackfill() já usa)."
  - "Extração de corpo de função via token_get_all() + balanceamento de chaves, não regex de linha — imune a { / } dentro de strings/comentários, e escopa a detecção por FUNÇÃO em vez de por ARQUIVO INTEIRO (arquivos grandes como CompanyController.php/OnboardingController.php têm dezenas de métodos; escopo por arquivo criaria falso positivo cruzado entre métodos não relacionados)"
  - "\$company->etapa = (atribuição direta) continua sendo padrão de arquivo inteiro sem exigir coocorrência — zero ocorrências hoje em todo app/ fora de comparações ===, e é inequivocamente uma escrita quando existe"

patterns-established:
  - "Gate de ponto único de escrita: quando duas colunas homônimas existem em models diferentes (aqui: companies.etapa e onboarding_passos.etapa), a varredura estática precisa escopar por corpo de função com sinal de receiver (Company::/\$company->), nunca por string solta"

requirements-completed: [ETAPA-03]

duration: ~25min
completed: 2026-09-01
---

# Phase 150 Plan 06: Ponto único de escrita de companies.etapa — regressão permanente Summary

**Teste de regressão que prova, por comportamento HTTP real e por varredura estática escopada por corpo de função, que só `EtapaTransicaoService` escreve `companies.etapa`; aviso-guarda deixado em `CompanyController::update()`.**

## Performance

- **Duration:** ~25 min
- **Completed:** 2026-09-01T20:02:42Z
- **Tasks:** 2/2
- **Files modified:** 2 (1 criado, 1 modificado)

## Accomplishments

- `PUT /companies/{company}` provado, por request HTTP real (não mock), incapaz de mover `etapa` ou marcar `pendencia_*` mesmo enviando o payload completo aceito hoje pela validação — T-150-01 e T-150-14 fechados
- Varredura estática permanente de `app/` que falha nomeando arquivo e linha se `companies.etapa` for escrito fora de `EtapaTransicaoService` — provada de verdade por injeção temporária de uma violação em `CompanyController::update()`, confirmando que o gate pega, e revertida antes do commit
- Aviso escrito no exato ponto onde a furada aconteceria, citando o serviço, o requisito (ETAPA-03/ETAPA-04) e o teste que cobra
- Success Criteria nº 2 da Fase 150 provado automatizado e permanente

## Task Commits

Each task was committed atomically:

1. **Task 1: Teste de regressão do ponto único de escrita** - `8c7c0482` (test)
2. **Task 2: Aviso escrito no lugar exato onde a furada aconteceria** - `1676b65a` (docs)

_Nenhum commit adicional de metadados nesta lista — o commit final de STATE/ROADMAP/REQUIREMENTS vem depois deste Summary._

## Files Created/Modified

- `tests/Feature/Phase150/EtapaPontoUnicoTest.php` - 4 testes: 3 comportamentais (PUT com etapa válida, etapa inválida, pendência) + 1 de varredura estática escopada por corpo de função
- `app/Http/Controllers/CompanyController.php` - comentário-guarda de 16 linhas acima de `$request->validate([` em `update()`; zero linha de código alterada (confirmado por `git diff` linha a linha)

## Decisions Made

1. **Padrão de varredura estática re-desenhado (Rule 1 — bug no próprio plano).** O plano especificava literalmente: procurar `'etapa' =>` OU `->etapa =` em qualquer `.php` de `app/` fora de `EtapaTransicaoService.php`, com exceção só para 3 arquivos nomeados (`Company.php`, `CompanyEtapaTransicao.php`, `EtapaBackfill.php`). Ao implementar, descobri que `OnboardingPasso` também tem uma coluna `etapa` — sem relação nenhuma com `companies.etapa` — escrita/lida por `'etapa' => ...` em pelo menos 5 arquivos: `app/Console/Commands/OnboardingAplicarPassosNovos.php`, `app/Console/Commands/OnboardingSincronizarEstrutura.php` (essa inclusive dentro de um `OnboardingPasso::whereKey(...)->update([...])` — uma escrita real, só que no model errado para este gate), `app/Services/Onboarding/OnboardingEngineService.php`, `app/Services/Onboarding/OnboardingLinkService.php` e `app/Http/Controllers/OnboardingController.php` (essa, aliás, importa `App\Models\Company` e tem `Company $company` em outro método completamente não relacionado — um sinal de "arquivo menciona Company" teria falso-positivado aqui também). Implementar o padrão ao pé da letra faria o teste nascer **vermelho no estado atual do repositório**, contradizendo o próprio acceptance criteria do plano ("O teste de varredura passa com o estado atual do repositório"). Resolvido escopando a detecção por **corpo de função/método** (via `token_get_all()` + balanceamento de chaves, não regex de linha nem de arquivo inteiro): a chave `'etapa' =>` só conta como violação quando o MESMO corpo de função também contém uma chamada de escrita reconhecida no model `Company`. `$company->etapa =` (atribuição direta) continua sendo um sinal sozinho, sem precisar de coocorrência, porque tem zero ocorrências legítimas hoje em qualquer lugar de `app/`.
2. **Prova do gate por injeção temporária**, exatamente como o acceptance criteria do plano pede ("comprove desfazendo temporariamente e refazendo"): adicionei `'etapa' => 'nullable|string',` à validação de `CompanyController::update()`, rodei o teste, confirmei a falha (`CompanyController.php:853 — chave 'etapa' => dentro de uma escrita no model Company`), revertei com `git diff` confirmando arquivo idêntico ao original antes do commit real da Task 2.
3. Nenhuma outra decisão fora do escopo do plano — Task 2 não tocou em nenhuma outra linha de `update()`, confirmado por `git diff` (16 linhas adicionadas, zero removidas/alteradas).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Padrão de varredura estática ("'etapa' =>" cru) redesenhado para escopo por corpo de função**
- **Found during:** Task 1 (montagem do Grupo 2 do teste)
- **Issue:** O padrão literal do plano (`'etapa' =>` OU `->etapa =` em qualquer `.php` de `app/` fora de 3 arquivos nomeados) falso-positiva contra ~10 usos legítimos de `OnboardingPasso::etapa` — coluna homônima em model diferente, sem relação com `companies.etapa`. Implementado ao pé da letra, o teste falharia hoje sem nenhuma violação real, contradizendo o acceptance criteria "passa com o estado atual do repositório".
- **Fix:** Detecção redesenhada para escopo por corpo de função/método (`token_get_all()` + balanceamento de chaves): `'etapa' =>` só conta se o mesmo corpo também contiver uma chamada de escrita reconhecida no model `Company` (`Company::create/updateOrCreate/firstOrCreate/forceCreate`, `$company->update/fill/forceFill`, ou `Company::` combinado com `->update(` no mesmo corpo — cobre o padrão de mass-update que `EtapaTransicaoService::carimbarBackfill()` já usa). `$company->etapa =` continua sinalizando sozinho.
- **Files modified:** tests/Feature/Phase150/EtapaPontoUnicoTest.php
- **Verification:** Suite roda 4/4 verde no estado atual do repositório (zero exceção por arquivo nomeado); gate provado ativo por injeção temporária em `CompanyController.php` (falha aponta arquivo:linha corretos), revertido, suite volta a 4/4 verde.
- **Committed in:** `8c7c0482` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug no desenho do padrão de varredura, corrigido antes de qualquer código de produção ser tocado)
**Impact on plan:** O redesenho é estritamente mais preciso que o padrão original — mantém a mesma cobertura pretendida (nenhum escritor de `companies.etapa` fora do serviço, incluindo mass-update via query builder) sem abrir exceção por nome de arquivo, que teria crescido a cada novo uso legítimo de `OnboardingPasso::etapa`. Nenhum scope creep: só o arquivo de teste do plano foi afetado.

## Issues Encountered

None além da deviation documentada acima.

## User Setup Required

None - nenhuma configuração de serviço externo.

## Next Phase Readiness

- Success Criteria nº 2 da Fase 150 ("nenhum outro ponto do código grava companies.etapa além do serviço de transição") está provado e permanente — `tests/Feature/Phase150/EtapaPontoUnicoTest.php` roda a cada `Quick run command` (`tests/Unit/Phase150 tests/Feature/Phase150`, 37/37 verde) e a cada `Baseline command` (24/24 verde, sem regressão)
- Plano 150-07 (filtro server-side de etapa em `/companies`) pode tocar `CompanyController.php` livremente na LISTAGEM (`index()`) sem risco de esbarrar neste gate — o gate só reage a escrita, nunca a leitura (`where('etapa', ...)` já é tolerado, é o próprio padrão que o filtro do 150-07 vai usar)
- Nenhum bloqueio para a Fase 151 (webhook → etapa 1) nem para as fases seguintes que vão chamar `EtapaTransicaoService::transicionar()` pela primeira vez em produção — o gate deste plano garante que elas serão, de fato, o único caminho de escrita

---
*Phase: 150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0*
*Completed: 2026-09-01*

## Self-Check: PASSED

- FOUND: tests/Feature/Phase150/EtapaPontoUnicoTest.php
- FOUND: .planning/phases/150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/150-06-SUMMARY.md
- FOUND commit: 8c7c0482 (Task 1)
- FOUND commit: 1676b65a (Task 2)
- FOUND commit: f8198859 (docs: summary)
