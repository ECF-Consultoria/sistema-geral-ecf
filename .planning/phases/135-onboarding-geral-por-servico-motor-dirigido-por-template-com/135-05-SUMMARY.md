---
phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com
plan: 05
subsystem: api
tags: [laravel, eloquent-observer, state-machine, hubspot-webhook, phpunit]

# Dependency graph
requires:
  - phase: 135-04
    provides: "OnboardingEngineService completo (criarParaContrato/montarPassos/reavaliar/aplicarResultado/avaliarCondicao/concluirManualmente) + OnboardingTemplateGestaoSeeder"
provides:
  - "ContratoServicoObserver registrado via #[ObservedBy] em ContratoServico — cobre os 4 call-sites de criação de contrato numa tacada só (D-13)"
  - "OnboardingEngineService::sugerirResponsavel/confirmarResponsavel/podeIniciar — transição rascunho→andamento com responsável sugerido (D-17) e trava de SLA (D-05/SC-04)"
  - "Onboarding::ROLES_RESPONSAVEL_SUGERIDO — catálogo de discrição documentado (consultor→estrategista)"
  - "Prova E2E dos 4 call-sites reais (webhook HubSpot, ComercialController::store, CompanyController::storeContrato, CompanyGroupController::atribuirServico) sem tocar nenhum dos 4 controllers"
  - "135-BASELINE-TESTES.md com medição 'depois do Observer' — zero regressão nas 4 suítes de risco + gate de Polos"
affects: [135-06, 135-07, 135-09, 135-12]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Observer leve (sem I/O de rede) delegando para o service de domínio, com try/catch (\\Throwable) — mesmo molde do MlbEmpresaObserver, mas com guarda de falha explícita porque o contrato não pode falhar por causa do onboarding"
    - "wasRecentlyCreated como guarda de log — activity() só registrada quando o Observer de fato criou algo, não quando o guard de duplicidade do engine devolveu um onboarding já existente"
    - "Sugestão gravada na criação sem mudar status — 'sugerir não é confirmar' (D-05): responsavel_id pode estar preenchido num onboarding em rascunho, e isso não liga o SLA"

key-files:
  created:
    - app/Observers/ContratoServicoObserver.php
    - tests/Feature/Phase135/OnboardingTransicaoStatusTest.php
    - tests/Feature/Phase135/OnboardingObserverCallSitesTest.php
  modified:
    - app/Models/ContratoServico.php
    - app/Models/Onboarding.php
    - app/Services/Onboarding/OnboardingEngineService.php
    - tests/Feature/Phase135/OnboardingSchemaTest.php
    - .planning/phases/135-onboarding-geral-por-servico-motor-dirigido-por-template-com/135-BASELINE-TESTES.md

key-decisions:
  - "Onboarding::ROLES_RESPONSAVEL_SUGERIDO = ['consultor', 'estrategista'] — leitura de discrição documentada em docblock (Assumption A2), não fato de negócio confirmado com o usuário; é o único ponto a mexer se a régua mudar"
  - "sugerirResponsavel() grava no nascimento do onboarding (dentro de criarParaContrato), mas o status permanece rascunho — só confirmarResponsavel() liga o SLA"
  - "podeIniciar() compara a contagem de OnboardingPasso montados contra a contagem de TemplatePasso do template (não hardcoda '13'), generalizando a checagem sem se prender ao template específico de Gestão"

patterns-established:
  - "Todo teste que precisa provar comportamento do Observer usa a rota/controller REAL, nunca chama ContratoServicoObserver::created() diretamente — é o que garante que o Observer está de fato conectado ao evento do Eloquent"

requirements-completed: [SC-01, SC-03, SC-04, D-01, D-05, D-13, D-17]

# Metrics
duration: ~40min
completed: 2026-08-11
---

# Fase 135 Plano 05: Observer de ContratoServico + transição rascunho→andamento Summary

**`ContratoServicoObserver` cobre os 4 call-sites de criação de contrato numa tacada só (webhook HubSpot, cadastro Comercial, contrato avulso e atribuição em massa por grupo), sem tocar em nenhum dos 4 controllers, e o onboarding só sai de rascunho — carimbando `disponivel_em` e ligando o SLA — quando `confirmarResponsavel()` confirma quem atende, com sugestão automática pelo vínculo já existente da empresa (D-17).**

## Performance

- **Duration:** ~40 min
- **Started:** 2026-08-11 (aprox., após leitura de contexto)
- **Completed:** 2026-08-11T19:31:23Z (commit final da plan)
- **Tasks:** 3
- **Files modified:** 8 (3 criados, 5 modificados — incluindo 1 fixture ajustada por deviation e a baseline atualizada)

## Accomplishments

- `ContratoServicoObserver::created()` delega para `OnboardingEngineService::criarParaContrato()`, registrado via `#[ObservedBy(ContratoServicoObserver::class)]` em `ContratoServico` — cobre os 4 pontos de criação (`Api/HubspotWebhookController`, `ComercialController`, `CompanyController`, `CompanyGroupController`) sem duplicar lógica em nenhum deles e sem que nenhum dos 4 arquivos apareça no diff da plan (provado por `git diff --name-only`).
- Observer deliberadamente sem I/O de rede — proibição de `Http::`/`Artisan::call`/`dispatchSync`/`AdmanService`/`MercadoLivreService` provada por grep (0 ocorrências), e o cenário mais caro (`CompanyGroupController::atribuirServico` com 3 empresas em loop sem transação) provado com `Http::fake()` + `Http::assertNothingSent()`.
- `try/catch (\Throwable)` com `Log::error('[Onboarding] ...')` — uma falha ao montar o onboarding não derruba a criação do contrato (o dado de negócio sobrevive à falha da consequência).
- `sugerirResponsavel()`/`confirmarResponsavel()`/`podeIniciar()` acrescentados ao `OnboardingEngineService`: o onboarding nasce em rascunho já com a sugestão de responsável (vínculo `consultor`, com fallback `estrategista`), mas só sai de rascunho — e só aí `reavaliar()` carimba `disponivel_em` nos 5 passos sem dependência — quando a Coordenação confirma via `confirmarResponsavel()`. Confirmar um onboarding que não está em rascunho lança `\DomainException`.
- Prova E2E dos 4 call-sites via rota/controller real (nenhum teste chama o Observer diretamente): webhook HubSpot com HMAC v3 válido, `ComercialController::store`, `CompanyController::storeContrato` e `CompanyGroupController::atribuirServico` com 3 empresas — todos geram onboarding em rascunho, 13 passos, `template_id` na versão ativa. Teste negativo confirma que serviço sem template publicado não cria onboarding (D-08).
- As 4 suítes de risco (`Phase112HubspotHandoffWebhookTest`, `Phase113HubspotDedupTest`, `Phase37ComercialListagemTest`, `Phase37CompaniesPerformanceFilterTest`) e o gate de Polos (`PolosControllerTest`, `PolosFaturamentoSnapshotTest`) foram rodados e batem número-a-número com a baseline pré-Observer — zero falha nova, zero queda de `Passed`. `135-BASELINE-TESTES.md` ganhou a seção "depois do Observer" com as duas medições lado a lado.
- `--filter=Phase135` fecha em 75/75 (63 herdados do Plano 04 + 7 da transição + 5 dos call-sites).

## Task Commits

Cada task foi commitada atomicamente (mais um commit de correção de fixture entre as tasks 1 e 2, ver Deviations):

1. **Task 1: ContratoServicoObserver — leve, nos 4 call-sites de uma vez** - `d7f86c25` (feat)
2. **Fix de fixture (deviation Rule 1, causada pela Task 1)** - `47be2771` (fix)
3. **Task 2: Transição rascunho → andamento com responsável sugerido** - `d8e0bcaa` (feat)
4. **Task 3: Prova os 4 call-sites + confere as 4 suítes de risco contra a baseline** - `ec542775` (test)

**Plan metadata:** (a ser adicionado no commit final de documentação)

## Files Created/Modified

- `app/Observers/ContratoServicoObserver.php` - Observer leve; cria onboarding via engine, sem I/O de rede, log-then-continue em falha
- `app/Models/ContratoServico.php` - registro do Observer via `#[ObservedBy]`
- `app/Models/Onboarding.php` - constante `ROLES_RESPONSAVEL_SUGERIDO` (D-17, discrição documentada)
- `app/Services/Onboarding/OnboardingEngineService.php` - `sugerirResponsavel`/`confirmarResponsavel`/`podeIniciar`; `criarParaContrato` passa a gravar a sugestão
- `tests/Feature/Phase135/OnboardingTransicaoStatusTest.php` - 7 testes da transição rascunho→andamento
- `tests/Feature/Phase135/OnboardingObserverCallSitesTest.php` - 5 testes E2E dos 4 call-sites + negativo D-08
- `tests/Feature/Phase135/OnboardingSchemaTest.php` - fixture ajustada (deviation) para o teste de constraint SC-01 continuar provando o banco, não o Observer
- `.planning/phases/135-.../135-BASELINE-TESTES.md` - seção "depois do Observer" acrescentada

## Decisions Made

- `Onboarding::ROLES_RESPONSAVEL_SUGERIDO = ['consultor', 'estrategista']` — decisão de discrição do plano (Assumption A2), documentada em docblock pt-BR como leitura não verificada com o negócio; é o único ponto a alterar se a régua mudar.
- `podeIniciar()` compara a contagem de passos montados contra a contagem de `TemplatePasso` do template ativo em vez de hardcodar "13" — generaliza a checagem sem se prender ao template específico de Gestão, mesmo a v1 só tendo esse template.
- Sugestão de responsável é gravada na criação do onboarding (dentro de `criarParaContrato`), mas nunca muda o status — reforça D-05 ("sugerir não é confirmar").

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixture de `OnboardingSchemaTest` quebrada pelo novo Observer**
- **Found during:** Task 1 (verificação `--filter=Phase135` após criar o Observer)
- **Issue:** `dois_onboardings_do_mesmo_contrato_lancam_query_exception_sc01` (suíte do Plano 02, não uma das 4 suítes de risco rastreadas) criava `ContratoServico` via Eloquent com um `OnboardingTemplate` já ativo para o mesmo serviço. Com o Observer presente, essa criação já gerava o primeiro onboarding sozinha — o `Onboarding::create()` explícito seguinte (que o teste esperava que fosse o PRIMEIRO) já colidia com a constraint de unicidade antes do ponto em que o teste chamava `expectException()`, derrubando o teste com uma exceção não tratada.
- **Fix:** o `ContratoServico` do teste passou a nascer via `DB::table('contratos_servico')->insertGetId(...)` em vez de Eloquent — contorna o Observer sem alterar a prova pretendida (a constraint UNIQUE do banco continua sendo o que é testado).
- **Files modified:** `tests/Feature/Phase135/OnboardingSchemaTest.php`
- **Verification:** `--filter=Phase135` voltou a 0 failures (63/63 → depois 75/75 com as tasks seguintes).
- **Committed in:** `47be2771` (commit isolado, entre as tasks 1 e 2, para manter a árvore de commits rastreável por causa)

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug em fixture de teste, não em código de produção)
**Impact on plan:** Nenhum código de produção foi alterado pela deviation; o fix preserva a intenção original do teste (provar a constraint de banco) sem depender de um efeito colateral novo do Observer. Nenhuma das 4 suítes de risco rastreadas pela baseline precisou de ajuste.

## Issues Encountered

- O `<verify>` da Task 1 no plano referenciava `--filter=OnboardingObserverCallSitesTest`, arquivo que só é criado na Task 3. Rodado `--filter=Phase135` no lugar após a Task 1 (verificação mais ampla, cobrindo o mesmo território); o filtro específico foi executado normalmente ao final da Task 3, quando o arquivo já existia.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `ContratoServicoObserver` e a transição `confirmarResponsavel()` prontos para o painel operacional (Plano 09) expor o botão de confirmar responsável na Tela 1.
- `sugerirResponsavel()`/`podeIniciar()` prontos para qualquer consumidor que precise decidir se mostra "sugestão" vs. "precisa escolher manualmente".
- Gate de regressão conferido nesta sessão: as 4 suítes de risco e o gate de Polos batem exatamente com a baseline pré-Observer (135-BASELINE-TESTES.md, seção "depois do Observer") — zero regressão.
- `git diff --name-only` desta plan não contém nenhum dos 4 controllers de call-site nem arquivo de Polos — D-02/D-13 intactos.
- Observer aplica apenas ao evento `created` de `ContratoServico` — reavaliação periódica de passos `aguardando_coleta`/`indeterminado` (Plano 12) e os resolvers de rede (Planos 06/07) seguem como trabalho separado, fora do escopo deste Observer por desenho (Pitfall 5).

---
*Phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com*
*Completed: 2026-08-11*

## Self-Check: PASSED

- FOUND: `app/Observers/ContratoServicoObserver.php`
- FOUND: `tests/Feature/Phase135/OnboardingTransicaoStatusTest.php`
- FOUND: `tests/Feature/Phase135/OnboardingObserverCallSitesTest.php`
- FOUND: `.planning/phases/135-onboarding-geral-por-servico-motor-dirigido-por-template-com/135-05-SUMMARY.md`
- FOUND: commit `d7f86c25`
- FOUND: commit `47be2771`
- FOUND: commit `d8e0bcaa`
- FOUND: commit `ec542775`
