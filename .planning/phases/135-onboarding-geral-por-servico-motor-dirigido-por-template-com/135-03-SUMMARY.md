---
phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com
plan: 03
subsystem: services
tags: [strategy-pattern, registry, di, laravel, resolvers]

# Dependency graph
requires:
  - phase: 135-02
    provides: "5 tabelas + 5 models (TemplatePasso::AUTO_FONTES/DONOS, Onboarding, OnboardingPasso com 6 estados)"
provides:
  - "Contract OnboardingResolver (chave/label/ajuda/assincrono/resolver) — molde fechado para qualquer resolver automático futuro"
  - "OnboardingResolverResultado — value object readonly de 3 estados (concluido/nao_coletado/indeterminado) com a chave reservada coleta_em_andamento"
  - "OnboardingResolverFactory — registry por chave, singleton no container, falha alto (RuntimeException) para auto_fonte fora do catálogo"
  - "2 resolvers concretos e locais (sem I/O de rede): AdmanAccountIdResolver (passo 3) e MlTokenAtivoResolver (passo 5, D-19)"
affects: [135-04, 135-05, 135-06, 135-07, 135-09, 135-12]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Registry por chave (não fallback entre N candidatos): OnboardingResolverFactory indexa por chave() e falha alto na CONSTRUÇÃO se alguma chave registrada estiver fora de TemplatePasso::AUTO_FONTES — molde adaptado de SugadoresAdsProviderFactory/MetricsProviderFactory"
    - "Value object readonly de 3 estados com named constructors estáticos e construtor privado — nenhuma porta de fuga booleana, provado por Reflection em teste"
    - "Chave reservada dentro do array de valor (coleta_em_andamento) como único canal de sinal de controle entre resolver e engine, sem acoplar a interface a um método extra"

key-files:
  created:
    - app/Contracts/OnboardingResolver.php
    - app/Services/Onboarding/OnboardingResolverResultado.php
    - app/Services/Onboarding/OnboardingResolverFactory.php
    - app/Services/Onboarding/Resolvers/AdmanAccountIdResolver.php
    - app/Services/Onboarding/Resolvers/MlTokenAtivoResolver.php
    - tests/Feature/Phase135/OnboardingResolverCatalogoTest.php
    - tests/Feature/Phase135/OnboardingResolversLocaisTest.php
  modified:
    - app/Providers/AppServiceProvider.php

key-decisions:
  - "OnboardingResolverFactory registrado no AppServiceProvider com edição aditiva mínima (3 commits, cada um só acrescentando 1 linha ao array de resolvers) — Planos 06/07 também editam este arquivo"
  - "AdmanAccountIdResolver e MlTokenAtivoResolver nunca citam DB::table('companies')/ensureValidToken/refreshToken nem no docblock explicativo — os greps de aceite do plano varrem o arquivo inteiro, não só o código executável"
  - "sinalizouColetaEmAndamento() só é true no estado NAO_COLETADO mesmo que a chave reservada apareça em CONCLUIDO — testado explicitamente, é o que impede o engine de ler sinal de controle fora do estado que ele governa"

patterns-established:
  - "Todo resolver automático da fase (Planos 05/06/07) implementa OnboardingResolver e é registrado como item explícito do array no AppServiceProvider — nunca descoberta por diretório"

requirements-completed: [SC-06, SC-07, D-03, D-09, D-11, D-19]

# Metrics
duration: ~25min
completed: 2026-08-11
---

# Fase 135 Plano 03: Catálogo fechado de resolvers + 2 resolvers locais (passos 3 e 5) Summary

**Contract + value object de 3 estados + registry por chave (D-09) para os resolvers automáticos do Onboarding, com os 2 resolvers sem I/O de rede já plugados: `adman_account_id` via accessor Eloquent (Pitfall 1) e `ml_tokens.status` para o passo com `dono=cliente`/`auto_fonte=sistema` (D-19).**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-08-11T17:30:00Z (aprox., após leitura de contexto)
- **Completed:** 2026-08-11T17:56:00Z
- **Tasks:** 3
- **Files modified:** 8 (5 criados em app/, 1 model consultado sem alteração, 2 suítes de teste, 1 provider editado 3x)

## Accomplishments
- `OnboardingResolver` (contract) + `OnboardingResolverResultado` (value object `readonly` com `concluido()`/`naoColetado()`/`indeterminado()`, construtor privado, sem `fromBool()` nem parâmetro `bool` em nenhum named constructor — provado por Reflection) + `OnboardingResolverFactory` (registry por chave, `singleton` no container, `for()` lança `\RuntimeException` citando a chave desconhecida, `existe()`/`catalogo()` para o Select da Tela 2).
- A chave reservada `CHAVE_COLETA_EM_ANDAMENTO` (`coleta_em_andamento`) é o único canal do sinal "coleta disparada" — `sinalizouColetaEmAndamento()` só é verdadeiro no estado `NAO_COLETADO`, mesmo que a chave apareça (por engano) num resultado `CONCLUIDO`.
- `AdmanAccountIdResolver` fecha o passo 3 lendo `$company->adman_account_id` **pelo accessor Eloquent** — provado com uma empresa cujo dado mora só no pivot `company_marketplaces` (o caso que uma leitura SQL crua erraria).
- `MlTokenAtivoResolver` fecha o passo 5 só pela coluna `ml_tokens.status`, sem chamar refresh/reautenticação — distingue "revogado" de "nunca autorizou" com motivos pt-BR diferentes (D-19: `dono=cliente` e `auto_fonte` preenchido convivem sem contradição).
- 18 testes novos (9 `OnboardingResolverCatalogoTest` + 9 `OnboardingResolversLocaisTest`), suíte `--filter=Phase135` completa em 32/32, gate de regressão de Polos na baseline exata (10 falhas pré-existentes, zero novas) e os 4 arquivos de risco do Observer 52/52 verdes.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Contract, resultado de 3 estados e registry por chave** - `e61ed0f8` (feat)
2. **Task 2: Resolver do passo 3 — adman_account_id preenchido (via accessor)** - `d398b5df` (feat)
3. **Task 3: Resolver do passo 5 — ml_token ativo (D-19)** - `937c86f2` (feat)

**Plan metadata:** (a ser adicionado no commit final de documentação)

## Files Created/Modified
- `app/Contracts/OnboardingResolver.php` - interface do catálogo fechado (chave/label/ajuda/assincrono/resolver)
- `app/Services/Onboarding/OnboardingResolverResultado.php` - value object readonly de 3 estados + chave reservada `coleta_em_andamento`
- `app/Services/Onboarding/OnboardingResolverFactory.php` - registry por chave, singleton, falha alto em chave fora do catálogo
- `app/Services/Onboarding/Resolvers/AdmanAccountIdResolver.php` - passo 3, lê pivot via accessor
- `app/Services/Onboarding/Resolvers/MlTokenAtivoResolver.php` - passo 5, lê `ml_tokens.status`, D-19
- `tests/Feature/Phase135/OnboardingResolverCatalogoTest.php` - 9 testes do catálogo fechado + resultado de 3 estados
- `tests/Feature/Phase135/OnboardingResolversLocaisTest.php` - 9 testes dos 2 resolvers locais + contrato do catálogo
- `app/Providers/AppServiceProvider.php` - registro do `OnboardingResolverFactory` como singleton (3 edições aditivas, uma por task)

## Decisions Made
- Constantes de estado do `OnboardingResolverResultado` nomeadas exatamente `CONCLUIDO`/`NAO_COLETADO`/`INDETERMINADO` (sem prefixo `ESTADO_`), como o `<interfaces>` do plano especificava literalmente.
- `OnboardingResolverFactory` foi registrado no `AppServiceProvider::register()` já na Task 1 com array vazio (`[]`) — os greps de aceite e o teste de catálogo fechado toleram 0 entradas (loop vazio é vacuamente verdadeiro); Tasks 2 e 3 só acrescentaram 1 linha cada, mantendo a edição do provider mínima e aditiva por task, coerente com a nota do ambiente de que os Planos 06/07 também vão editar este arquivo.
- Os critérios de aceite da Task 1 que citam chaves concretas (`adman_account_id_preenchido`) descrevem o estado final do grupo de 3 tasks, não um requisito isolado da Task 1 — confirmados via `tinker` e via teste dedicado só depois que os resolvers concretos das Tasks 2/3 existiram (ver "Issues Encountered").
- Os docblocks dos 2 resolvers evitam citar literalmente os padrões proibidos pelos greps de aceite (`DB::table('companies')`, `ensureValidToken`, `refreshToken`) mesmo em texto explicativo — os greps varrem o arquivo inteiro, não só código executável.

## Deviations from Plan

None - plano executado exatamente como escrito nas 3 tasks.

## Issues Encountered

- **Acceptance criteria da Task 1 referenciando resolvers só criados nas Tasks 2/3:** o bloco `acceptance_criteria` da Task 1 inclui `existe('adman_account_id_preenchido')` retorna `true`, mas `AdmanAccountIdResolver` só é criado na Task 2 (`<files>` da Task 1 não o lista). Interpretei esse bullet como descrevendo o estado final do grupo de 3 tasks (mesmo padrão do bullet da Task 3 sobre "catálogo com exatamente 2 chaves"), não um requisito isolado da Task 1. Verifiquei via `artisan tinker` logo após a Task 2 (`existe('adman_account_id_preenchido') === true`) e via teste dedicado (`catalogo_expoe_exatamente_as_2_chaves_locais_registradas_ate_aqui`) ao final da Task 3 — ambos confirmados. Nenhuma mudança de código foi necessária, só a leitura correta de quando cada critério se aplica.
- **Grep de aceite pegou texto de docblock explicativo, não só código:** o primeiro rascunho do docblock de `AdmanAccountIdResolver` citava `DB::table('companies')->value(...)` como exemplo do que NÃO fazer (mesmo padrão pedagógico do `135-02-SUMMARY.md` para `OnboardingLink`). O grep de aceite (`grep -Ec "DB::table\('companies'\)|..."`) varre o arquivo inteiro e contou 1 ocorrência no comentário. Reescrevi o trecho sem citar o padrão proibido literalmente antes do commit — nenhum código com a string chegou a ser versionado. Aplicado o mesmo cuidado preventivamente no docblock de `MlTokenAtivoResolver` (evitando `ensureValidToken`/`refreshToken`/`Http::` mesmo em prosa).
- **`ml_tokens` exige `access_token`/`refresh_token`/`expires_at` NOT NULL:** o primeiro rascunho dos testes de `MlTokenAtivoResolver` criava `MlToken::create()` só com `company_id`/`ml_user_id`/`status`/`connected_at`, e a migration (`2026_05_28_100001_create_ml_tokens_table.php`) exige `access_token`/`refresh_token`/`expires_at` sem default. Corrigido preenchendo os 3 campos com valores fake, seguindo o mesmo padrão de `tests/Feature/Phase38/MercadoLivreAdsServiceTest.php::makeCompanyWithMlToken()`.
- **Task 3 exigia ≥ 6 testes na suíte combinada:** a primeira versão tinha só 5 (2 da Task 2 + 3 da Task 3, um deles cobrindo 2 casos numa única asserção). Separei o teste combinado "revoked + sem-token" em 2 testes independentes e acrescentei 2 testes de `assincrono() === false` (um por resolver) — a suíte final tem 9 testes, todos cobrindo comportamento explícito do plano, sem inflar por padding.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `OnboardingResolver` + `OnboardingResolverFactory` prontos para os Planos 05 (avaliador de dependências, que vai chamar `factory->for($autoFonte)->resolver(...)`) e 06 (resolvers de rede — `AdmanGrantResolver`, `AnunciosAtivosInativosResolver`, `MetricasContaResolver` — que só precisam implementar `OnboardingResolver` e se registrar no mesmo array do `AppServiceProvider`).
- A chave reservada `coleta_em_andamento` está pronta para o Plano 07 (`AcervoColetadoResolver`) ser o único emissor legítimo desse sinal, e para o Plano 04 (`OnboardingEngineService::aplicarResultado()`) consumi-la sem precisar conhecer qual resolver a produziu.
- `OnboardingResolverFactory::catalogo()` já está pronto para alimentar o `Select` da Tela 2 (Plano 07/09) com `chave`/`label`/`ajuda`/`assincrono` — hoje expõe as 2 chaves locais; crescerá para 5 no Plano 06.
- Gate de regressão de Polos conferido nesta sessão: 10 falhas pré-existentes (6 `PolosControllerTest` + 4 `PolosFaturamentoSnapshotTest`), batendo exatamente com a baseline. Zero falha nova.
- Os 4 arquivos de risco do Observer (`Phase112HubspotHandoffWebhookTest`, `Phase113HubspotDedupTest`, `Phase37ComercialListagemTest`, `Phase37CompaniesPerformanceFilterTest`) seguem 100% verdes (52/52) — nenhum Observer foi tocado nesta task, então o resultado é o esperado antes do Plano 04 nascer.
- `grep -rn "MlbImplementacao" app/Contracts/OnboardingResolver.php app/Services/Onboarding/` — zero ocorrências (D-02 intacto).

---
*Phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com*
*Completed: 2026-08-11*

## Self-Check: PASSED

- FOUND: `app/Contracts/OnboardingResolver.php`
- FOUND: `app/Services/Onboarding/OnboardingResolverResultado.php`
- FOUND: `app/Services/Onboarding/OnboardingResolverFactory.php`
- FOUND: `app/Services/Onboarding/Resolvers/AdmanAccountIdResolver.php`
- FOUND: `app/Services/Onboarding/Resolvers/MlTokenAtivoResolver.php`
- FOUND: `tests/Feature/Phase135/OnboardingResolverCatalogoTest.php`
- FOUND: `tests/Feature/Phase135/OnboardingResolversLocaisTest.php`
- FOUND: commit `e61ed0f8`
- FOUND: commit `d398b5df`
- FOUND: commit `937c86f2`
