---
phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com
plan: 07
subsystem: services
tags: [laravel-queue, laravel-scheduler, resolvers, mercado-livre, onboarding]

# Dependency graph
requires:
  - phase: 135-03
    provides: "Contract OnboardingResolver + OnboardingResolverResultado (3 estados) + OnboardingResolverFactory (registry por chave)"
  - phase: 135-04
    provides: "OnboardingEngineService::aplicarResultado()/reavaliar() — traduzem o resultado de 3 estados para status/valor/coleta_iniciada_em"
  - phase: 135-06
    provides: "ResolveOnboardingPassoJob — único ponto de execução de resolver de rede; catálogo com 4 das 5 chaves"
provides:
  - "AcervoColetadoResolver — resolver do passo 8 (acervo_coletado, SC-07/D-11): exists() antes de count() separa 'tabela vazia' de 'zero de verdade'; único resolver da fase autorizado a setar a chave coleta_em_andamento"
  - "OnboardingResolverFactory::catalogo() fecha as 5 chaves de TemplatePasso::AUTO_FONTES"
  - "onboarding:reavaliar-passos — passada periódica agendada a cada 10min que reavalia passos aguardando_coleta/indeterminado/aberto de onboardings em andamento, com try/catch por passo"
affects: [135-09, 135-12]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Redisparo de job assíncrono guardado por janela de tempo local (coleta_iniciada_em + 30min) ANTES de tentar o dispatch — segunda camada de defesa mais barata que o ShouldBeUnique do job"
    - "Comando batch que resolve o catálogo do resolver via OnboardingResolverFactory::for(...)->assincrono() para decidir inline vs. dispatch, sem nunca importar client HTTP — Adman/ML só entram pelo Job (Pitfall 2 do Plano 06)"

key-files:
  created:
    - app/Services/Onboarding/Resolvers/AcervoColetadoResolver.php
    - app/Console/Commands/OnboardingReavaliarPassos.php
    - tests/Feature/Phase135/OnboardingResolverAcervoTest.php
    - tests/Feature/Phase135/OnboardingReavaliacaoCommandTest.php
  modified:
    - app/Providers/AppServiceProvider.php
    - routes/console.php

key-decisions:
  - "por_status guarda o breakdown bruto (countBy) em vez de só ativos/inativos — uma redefinição futura do que é 'inativo' não exige recoleta (nota do plano)"
  - "Teste de resiliência a falha isolada substitui o OnboardingResolverFactory do container por uma subclasse anônima que herda sem chamar parent::__construct() e sobrescreve for() só para a chave sob teste — evita depender de warnings de PHP virarem exceção (caminho frágil) e mantém as demais chaves delegando ao factory real"
  - "Command usa Artisan::expectsOutputToContain() em vez de Artisan::output() para o teste de contagem de falhas — $this->artisan() do Laravel não popula o buffer global de Artisan::output()"

patterns-established:
  - "onboarding:reavaliar-passos é o único lugar (além do dispatch inicial via Observer) que decide inline-vs-job olhando resolver->assincrono() — nenhum outro consumidor deve reimplementar essa decisão"

requirements-completed: [SC-06, SC-07, D-03, D-11]

# Metrics
duration: ~35min
completed: 2026-08-12
---

# Fase 135 Plano 07: AcervoColetadoResolver (passo 8) + reavaliação periódica Summary

**`AcervoColetadoResolver` fecha o catálogo de 5 resolvers provando SC-07 com dois testes de comportamento oposto (tabela vazia nunca conclui; tabela populada com zero `active` fecha o passo com o número real) e é o único resolver autorizado a sinalizar `coleta_em_andamento`; `onboarding:reavaliar-passos`, agendado a cada 10min, é quem volta depois para reconferir os passos que ficaram esperando a coleta assíncrona de até 30 minutos.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-08-12 (aprox., após leitura de contexto)
- **Completed:** 2026-08-12T13:47:48-03:00 (commit da Task 2)
- **Tasks:** 2
- **Files modified:** 6 (2 criados em `app/`, 2 suítes de teste criadas, 1 provider editado, 1 arquivo de rotas editado)

## Accomplishments

- `AcervoColetadoResolver` implementa os 3 ramos do passo 8: guard de `mlToken` inativo (pendência do cliente, `nao_coletado` **sem** a chave reservada, passo continua `aberto` e visível — par positivo do teste negativo do Plano 06); tabela vazia (`exists()===false`) dispara `SyncMlAcervoCompanyJob::dispatch()` e devolve `nao_coletado` com `CHAVE_COLETA_EM_ANDAMENTO=true`, **nunca** conclui; tabela com ≥1 linha conta `active` vs. não-`active` e devolve `concluido` com `ativos`/`inativos`/`por_status`/`coletado_em`.
- Redisparo controlado: só despacha `SyncMlAcervoCompanyJob` de novo quando `coleta_iniciada_em` é `null` ou mais antigo que a janela de 30 minutos (o `timeout` do job) — provado com dois testes espelhados (5min atrás não redispara, 45min atrás redispara).
- `OnboardingResolverFactory::catalogo()` agora expõe as **5** chaves de `TemplatePasso::AUTO_FONTES` — catálogo fechado da Fase 135 completo.
- `onboarding:reavaliar-passos` varre passos `aguardando_coleta`/`indeterminado`/`aberto`-com-`auto_fonte` de onboardings em `andamento` (rascunho nunca entra, D-05), resolve síncronos inline e despacha `ResolveOnboardingPassoJob` para assíncronos — nunca chama Adman/ML diretamente (confirmado por grep: 0 ocorrências de `fetchPerformance`/`fetchUserInfo`/`Http::` no arquivo do comando). `try/catch (\Throwable)` por passo (molde `WarmDesempenhoCache`) garante que uma falha isolada não derruba o lote; ao final, `reavaliar()` roda para cada onboarding tocado.
- Agendado em `routes/console.php` a cada 10 minutos com `withoutOverlapping()` — cadência casada com o `timeout=1800` (30min) do job de acervo e o rate limit de 10 rpm da Adman.
- 17 testes novos (9 `OnboardingResolverAcervoTest` + 8 `OnboardingReavaliacaoCommandTest`), suíte `--filter=Phase135` fecha em 129/129. As 4 suítes de risco do Observer batem 52/52 (igual à baseline). Nenhum arquivo de Polos tocado (D-02 intacto, `git status --porcelain | grep -i polos` vazio em todos os checkpoints).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: AcervoColetadoResolver — o passo 8 e a diferença entre vazio e zero (SC-07)** - `02cece69` (feat)
2. **Task 2: Comando onboarding:reavaliar-passos + agendamento** - `aafac9a3` (feat)

**Plan metadata:** (a ser adicionado no commit final de documentação)

## Files Created/Modified

- `app/Services/Onboarding/Resolvers/AcervoColetadoResolver.php` - resolver do passo 8, 3 ramos, único autorizado a sinalizar coleta em andamento
- `app/Console/Commands/OnboardingReavaliarPassos.php` - passada periódica, sync inline / async via Job, try/catch por passo
- `app/Providers/AppServiceProvider.php` - registro do `AcervoColetadoResolver` no `OnboardingResolverFactory` (edição aditiva de 1 linha)
- `routes/console.php` - agendamento `onboarding:reavaliar-passos` a cada 10min com `withoutOverlapping()`
- `tests/Feature/Phase135/OnboardingResolverAcervoTest.php` - 9 testes (guard token, vazio×zero real, mix, janela de redisparo, catálogo fechado)
- `tests/Feature/Phase135/OnboardingReavaliacaoCommandTest.php` - 8 testes (rascunho ignorado, sync inline, async despachado, resiliência a falha, `--limite`, `--onboarding=`, idempotência)

## Decisions Made

- `por_status` guarda o breakdown bruto por status (`countBy('status')`) em vez de só `ativos`/`inativos` — nota do plano: uma redefinição futura do que conta como "inativo" não deveria exigir nova coleta, só reler o breakdown já persistido.
- Teste de resiliência a falha isolada (Task 2) troca o `OnboardingResolverFactory` do container por uma subclasse anônima que **não** chama `parent::__construct()` (evita reconstruir o catálogo) e sobrescreve só `for()` para lançar exceção numa chave específica, delegando todas as outras ao factory real — mais direto e determinístico do que tentar provocar uma exceção de verdade via dado malformado (ex.: `company_id` órfão), que dependeria de warnings de PHP virarem `ErrorException` no handler do Laravel — comportamento correto mas frágil de garantir num teste.
- O teste de contagem de falhas usa `$this->artisan(...)->expectsOutputToContain('falhas=1')` em vez de capturar `Artisan::output()` — `$this->artisan()` (helper de teste do Laravel) não popula o buffer estático de `Artisan::output()`; `expectsOutputToContain()` é o mecanismo suportado para essa asserção.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Comentário do agendamento continha a própria string "withoutOverlapping" em prosa, inflando o grep de aceite em +2 em vez de +1**
- **Found during:** Task 2 (verificação `grep -c withoutOverlapping routes/console.php`)
- **Issue:** O comentário explicativo acima do novo `Schedule::command('onboarding:reavaliar-passos')` citava `withoutOverlapping()` em texto explicativo, além da chamada real no código — o grep de aceite do plano (`grep -c 'withoutOverlapping' routes/console.php` deve aumentar em exatamente 1) contou 31 em vez dos 30 esperados (baseline 29 + 1).
- **Fix:** Reescrito o trecho do comentário para não citar o nome do método literalmente ("a trava de não sobreposição abaixo é obrigatória" em vez de "`withoutOverlapping()` é obrigatório").
- **Files modified:** `routes/console.php`
- **Verification:** `grep -c 'withoutOverlapping' routes/console.php` voltou a 30 (29 + 1, exatamente o esperado).
- **Committed in:** `aafac9a3` (Task 2 commit — o fix aconteceu antes do commit, nunca foi versionado com a contagem errada)

---

**Total deviations:** 1 auto-fixed (Rule 1 — grep de aceite, nenhum código de produção alterado além do texto do comentário)
**Impact on plan:** Nenhum comportamento mudou; só a prosa do comentário foi ajustada para não colidir com o próprio grep de aceite que o plano define.

## Issues Encountered

- Sessão paralela (Fase 136) está ativa no mesmo working tree durante esta execução, com arquivos modificados/untracked que não pertencem a este plano (`DesempenhoMetricasManuaisController.php`, `StoreMetricaManualRequest.php`, `DesempenhoMetricaManual.php`, `Servico.php`, `ManualMetricOverrideService.php`, testes de `Phase136`, uma migration nova e `prompt-metas-dev-v2.md`). Confirmado por `git diff` de cada arquivo tocado por mim (`AppServiceProvider.php` e `routes/console.php`) que o diff contém **só** a minha edição antes de cada `git add` — nenhum desses arquivos alheios foi staged ou commitado por esta execução. Nenhuma ação corretiva necessária, só disciplina de stage seletivo (regra do `shared_file_rule`).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Catálogo de resolvers da Fase 135 está **fechado**: as 5 chaves de `TemplatePasso::AUTO_FONTES` têm resolver registrado, confirmado por teste dedicado e por `tinker` (`catalogo()` retorna exatamente 5).
- `onboarding:reavaliar-passos` pronto para o painel operacional (Plano 09) referenciar como o mecanismo que garante que nenhum passo fica preso em `aguardando_coleta` para sempre — o painel só precisa mostrar o estado, não reimplementar a reavaliação.
- Watchdog do UI-SPEC (Tela 1) — "coletando" vs. "coleta demorando mais que o esperado" — tem o dado que precisa: `coleta_iniciada_em` + a janela de 30min já é a mesma regra que o resolver usa para decidir redisparo; o Plano 09 só precisa comparar `now() - coleta_iniciada_em` contra o mesmo limite ao renderizar o alerta.
- Gate de regressão conferido nesta sessão: suíte `--filter=Phase135` 129/129 (era 112/112 na baseline desta plan), as 4 suítes de risco do Observer 52/52 (igual à baseline), e `git status --porcelain | grep -i polos` vazio em todos os checkpoints (D-02 intacto).
- `grep -Ec "fetchPerformance|fetchUserInfo|Http::" app/Console/Commands/OnboardingReavaliarPassos.php` retorna 0 — o comando de reavaliação nunca toca rede diretamente, só decide inline-vs-job.

---
*Phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com*
*Completed: 2026-08-12*

## Self-Check: PASSED

- FOUND: `app/Services/Onboarding/Resolvers/AcervoColetadoResolver.php`
- FOUND: `app/Console/Commands/OnboardingReavaliarPassos.php`
- FOUND: `tests/Feature/Phase135/OnboardingResolverAcervoTest.php`
- FOUND: `tests/Feature/Phase135/OnboardingReavaliacaoCommandTest.php`
- FOUND: commit `02cece69`
- FOUND: commit `aafac9a3`
