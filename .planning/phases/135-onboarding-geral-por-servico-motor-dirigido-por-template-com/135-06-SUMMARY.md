---
phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com
plan: 06
subsystem: services
tags: [http-fake, laravel-queue, adman, mercado-livre, resolvers, should-be-unique]

# Dependency graph
requires:
  - phase: 135-03
    provides: "Contract OnboardingResolver + OnboardingResolverResultado (3 estados) + OnboardingResolverFactory (registry por chave)"
  - phase: 135-04
    provides: "OnboardingEngineService::aplicarResultado() — traduz o resultado de 3 estados para status/valor/tentativas"
provides:
  - "AdmanGrantResolver — sonda do passo 4 (grant_consultoria_adman, D-18): fetchPerformance de 1 dia, sucesso=grant ativo mesmo com resumo zerado, 400/404/500=sem grant, 429/timeout/rede=indeterminado"
  - "MetricasContaResolver — passo 7 (metricas_da_conta): agrega fetchUserInfo (ML) + fetchGrossBilling 3 meses (Adman) + CompanyGrant::active(), parsing defensivo com valor[nao_obtidos]"
  - "ResolveOnboardingPassoJob — unico ponto de execucao de resolver de rede (ShouldQueue+ShouldBeUnique por passo, tries=3, backoff [60,300,900], failed() marca indeterminado)"
  - "OnboardingResolverFactory::catalogo() agora expoe 4 das 5 chaves (falta acervo_coletado, Plano 07)"
affects: [135-07, 135-09, 135-12]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Throttle isolado em metodo protegido sobrescrevivel (aguardarThrottle()) — permite que o teste troque usleep(7s) real por no-op via subclasse anonima, sem tocar no codigo de producao"
    - "Classificacao de excecao por substring de mensagem (400/404/500 vs. resto) herdada literalmente de DiagnoseCustId::validarViaAdman() — nao reimplementada, replicada"
    - "Parsing defensivo com lista nao_obtidos[] — campo ausente ou fonte que falhou entra na lista com o proprio nome, nunca vira false/0 (evita mentir sobre o dado)"
    - "Job so roda side-effects apos recarregar o passo do banco (->fresh()) e checar 3 guard-clauses (status terminal, auto_fonte ausente, onboarding em rascunho) antes de chamar o resolver"

key-files:
  created:
    - app/Services/Onboarding/Resolvers/AdmanGrantResolver.php
    - app/Services/Onboarding/Resolvers/MetricasContaResolver.php
    - app/Jobs/ResolveOnboardingPassoJob.php
    - tests/Feature/Phase135/OnboardingResolverAdmanGrantTest.php
    - tests/Feature/Phase135/OnboardingResolverMetricasTest.php
  modified:
    - app/Providers/AppServiceProvider.php
    - tests/Feature/Phase135/OnboardingResolversLocaisTest.php

key-decisions:
  - "Docblocks dos 2 resolvers evitam citar literalmente os padroes proibidos pelos greps de aceite (fetchAccountMetrics, /accounts/, cust_id_status, listAccounts, coleta_em_andamento) mesmo em prosa explicativa — mesma disciplina do Plano 03"
  - "AdmanGrantResolver isola o usleep(7_000_000) num metodo protegido aguardarThrottle() especificamente para ser sobrescrito nos testes — evita pagar 7s reais por caminho testado sem alterar o comportamento em producao"
  - "MetricasContaResolver usa AdmanService::fetchGrossBilling() (nao fetchPerformance direto) para o faturamento: o metodo ja captura Throwable internamente e devolve null em falha, entao a falha isolada da Adman cai naturalmente em valor[nao_obtidos] sem try/catch extra no resolver"
  - "MetricasContaResolver distingue 'sem token' (nao_coletado) de '429/timeout' (indeterminado) checando a mensagem da excecao por substring ('sem token valido') — as duas causas lancam a mesma classe RuntimeException em MercadoLivreService, entao o tipo da excecao nao basta para classificar"
  - "OnboardingResolversLocaisTest::catalogo_expoe_exatamente_as_2_chaves teve o nome e a asssercao trocados de assertSame (lista fechada) para assertContains (subconjunto) — o teste do Plano 03 assumia um catalogo que so cresceria depois; o proprio 135-03-SUMMARY.md ja previa que o Plano 06 quebraria essa contagem"

patterns-established:
  - "Todo resolver de rede desta fase (Planos 06/07) so pode ser invocado a partir de ResolveOnboardingPassoJob — nunca inline numa request HTTP (Pitfall 2, T-135-06-02)"

requirements-completed: [SC-06, SC-07, D-03, D-09, D-11, D-18]

# Metrics
duration: ~40min
completed: 2026-08-11
---

# Fase 135 Plano 06: Resolvers de rede (grant Adman + métricas da conta) + Job dedicado Summary

**`AdmanGrantResolver` prova o grant do passo 4 pela resposta real da Adman para o `cust_id` da empresa (D-18) — sucesso HTTP fecha o passo mesmo com o resumo do dia zerado, `400/404/500` vira "sem grant", e `429`/timeout/rede vira `indeterminado`, nunca "sem grant"; `MetricasContaResolver` agrega reputação/Full do Mercado Livre + faturamento Adman de 3 meses + medalha do parceiro no passo 7 com parsing defensivo (`valor['nao_obtidos']`); e `ResolveOnboardingPassoJob` é o único ponto do sistema de onde qualquer um dos dois pode ser chamado.**

## Performance

- **Duration:** ~40 min
- **Started:** 2026-08-11 (aprox., após leitura de contexto)
- **Completed:** 2026-08-11T20:17:38Z (commit da Task 3)
- **Tasks:** 3
- **Files modified:** 7 (3 criados em `app/`, 2 suítes de teste criadas, 1 suíte de teste do Plano 03 ajustada, 1 provider editado 2x)

## Accomplishments

- `AdmanGrantResolver` fecha o passo 4 herdando literalmente a classificação de `DiagnoseCustId::validarViaAdman()`: `fetchPerformance()` de 1 dia, sucesso HTTP = grant ativo (mesmo com `summarizedData` zerado — é o caso que separa "sem grant" de "grant ok, conta sem movimento"), exceção citando `400/404/500` = sem grant, qualquer outra exceção (`429`, timeout, rede) = `indeterminado`. `usleep(7_000_000)` roda em todo caminho que chamou a API, isolado num método protegido `aguardarThrottle()` para os testes poderem trocá-lo por no-op sem tocar produção.
- `MetricasContaResolver` fecha o passo 7 agregando 3 fontes: `MercadoLivreService::fetchUserInfo()` (nickname, reputação, indicador de Full a partir de `tags`), `AdmanService::fetchGrossBilling()` (faturamento dos últimos 3 meses — método que já captura falha internamente e devolve `null`, então a falha isolada da Adman nunca derruba o passo) e `Company::activeGrant` (medalha/programa do parceiro ML). Cada campo não obtido entra em `valor['nao_obtidos']`, nunca vira `false`/`0` que mentiria sobre o dado.
- `ResolveOnboardingPassoJob` é o único lugar de onde os dois resolvers de rede podem rodar: `ShouldQueue`+`ShouldBeUnique` por passo (`uniqueFor` 1800s), `tries=3`, `backoff [60,300,900]`. Recarrega o passo do banco, ignora passo já `concluido`/`nao_aplicavel`, ignora passo sem `auto_fonte` (defesa contra despacho errado) e ignora onboarding em `rascunho` (D-05 — rascunho não corre SLA nem consome quota da Adman). `failed()` marca o passo `indeterminado` com `ultimo_erro` — falha definitiva de job nunca finge que ninguém tentou (T-135-06-05).
- Teste negativo do sinal de coleta em **ambos** os resolvers: nos ramos `nao_coletado` (sem `cust_id`/404 no grant; sem token no ML), `sinalizouColetaEmAndamento()` é `false` e aplicar o resultado via `OnboardingEngineService::aplicarResultado()` deixa o passo em `status='aberto'` — nunca `aguardando_coleta`. É o teste que protege o SC-11 contra um grant travado por falta de `cust_id` sumir do painel.
- `OnboardingResolverFactory::catalogo()` agora expõe 4 das 5 chaves fechadas (`adman_account_id_preenchido`, `adman_grant_ativo`, `ml_token_ativo`, `metricas_conta`) — falta só `acervo_coletado`, que é do Plano 07.
- 16 testes novos (10 em `OnboardingResolverAdmanGrantTest` incluindo os 3 do Job, 6 em `OnboardingResolverMetricasTest`), suíte `--filter=Phase135` fecha em 91/91. As 4 suítes de risco do Observer batem 52/52 (igual à baseline). O gate de regressão de Polos bate exatamente com a baseline (6 `PolosControllerTest` + 4 `PolosFaturamentoSnapshotTest` = 10 falhas pré-existentes, zero novas).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: AdmanGrantResolver — a sonda do passo 4 (D-18) com 3 estados** - `6c344ec9` (feat)
2. **Task 2: MetricasContaResolver — passo 7, parsing defensivo** - `8ef2dd05` (feat)
3. **Task 3: ResolveOnboardingPassoJob — o único lugar de onde resolver de rede roda** - `003c389e` (feat)

**Plan metadata:** (a ser adicionado no commit final de documentação)

## Files Created/Modified

- `app/Services/Onboarding/Resolvers/AdmanGrantResolver.php` - sonda do passo 4, 3 estados, throttle sobrescrevível
- `app/Services/Onboarding/Resolvers/MetricasContaResolver.php` - passo 7, agrega ML + Adman + CompanyGrant com parsing defensivo
- `app/Jobs/ResolveOnboardingPassoJob.php` - único ponto de execução de resolver de rede
- `tests/Feature/Phase135/OnboardingResolverAdmanGrantTest.php` - 10 testes (4 classificações + assíncrono + 2 negativos de coleta + 3 do Job)
- `tests/Feature/Phase135/OnboardingResolverMetricasTest.php` - 6 testes (payload completo, sem reputação, sem token, 429, falha isolada da Adman, negativo de coleta)
- `app/Providers/AppServiceProvider.php` - registro dos 2 novos resolvers no `OnboardingResolverFactory` (2 edições aditivas, uma por task)
- `tests/Feature/Phase135/OnboardingResolversLocaisTest.php` - teste de catálogo do Plano 03 ajustado de `assertSame` (lista fechada) para `assertContains` (subconjunto), conforme já previsto no `135-03-SUMMARY.md`

## Decisions Made

- Throttle de `AdmanGrantResolver` isolado num método protegido `aguardarThrottle()` — sugestão explícita do plano ("encapsular a espera num método protegido sobrescrevível é aceitável e preferível"). Os testes usam uma subclasse anônima que sobrescreve o método com no-op; a suíte inteira roda em ~31s mesmo com um teste (429) pagando o retry real de 6s embutido em `AdmanService::fetchPerformance()`.
- `MetricasContaResolver` usa `fetchGrossBilling()` em vez de `fetchPerformance()` direto para o faturamento — o método já captura `\Throwable` internamente e cacheia erro, devolvendo `?float`. Isso elimina a necessidade de um `try/catch` extra no resolver: falha isolada da Adman cai naturalmente em `nao_obtidos`.
- `MetricasContaResolver` distingue "cliente não autorizou" de "429/timeout" checando a mensagem da exceção por substring (`'sem token válido'`) — as duas causas lançam a mesma classe `\RuntimeException` em `MercadoLivreService`, então o tipo sozinho não classifica; foi preciso inspecionar a mensagem, no mesmo espírito da bifurcação 400/404/500 vs. resto usada no resolver do passo 4.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Teste de catálogo do Plano 03 quebrado pela chave nova registrada nesta plan**
- **Found during:** Task 1 (verificação `--filter=Phase135` após registrar `AdmanGrantResolver`)
- **Issue:** `OnboardingResolversLocaisTest::catalogo_expoe_exatamente_as_2_chaves_locais_registradas_ate_aqui()` fazia `assertSame` contra uma lista fechada de exatamente 2 chaves. Ao registrar `AdmanGrantResolver` (3ª chave) no `AppServiceProvider`, o catálogo passou a ter 3 entradas e o teste quebrou — comportamento já antecipado pelo próprio `135-03-SUMMARY.md` ("hoje expõe as 2 chaves locais; crescerá para 5 no Plano 06").
- **Fix:** Renomeado o teste e trocado `assertSame` por duas chamadas `assertContains` — prova que os 2 resolvers locais continuam registrados, sem assumir que o catálogo tem exatamente 2 chaves no total (invariante que deixou de valer assim que este plano começou a registrar resolvers de rede).
- **Files modified:** `tests/Feature/Phase135/OnboardingResolversLocaisTest.php`
- **Verification:** `--filter=Phase135` voltou a 0 failures (82/82 após a Task 1; 91/91 ao final da plan).
- **Committed in:** `6c344ec9` (Task 1 commit)

**2. [Rule 1 - Bug] Assert de estado inicial (`tentativas`/`status`) lendo modelo em memória em vez do banco**
- **Found during:** Task 3 (primeira execução dos testes do Job)
- **Issue:** Dois testes novos afirmavam `$onboarding->status === 'rascunho'` e `$passo->tentativas === 0` logo após `Onboarding::create()`/`OnboardingPasso::create()`, sem `->fresh()`. Como as colunas `status`/`tentativas` têm `default()` no schema mas não são passadas explicitamente ao `create()`, o Eloquent não reconsulta o banco após o insert — o atributo em memória fica `null` em vez do default da coluna, e as duas asserções falhavam (`null` ≠ `'rascunho'`, `null` ≠ `0`).
- **Fix:** Trocado `$onboarding->status`/`$passo->tentativas` por `$onboarding->fresh()->status`/`$passo->fresh()->tentativas` nas duas asserções afetadas — prova o valor real persistido, não a suposição em memória.
- **Files modified:** `tests/Feature/Phase135/OnboardingResolverAdmanGrantTest.php`
- **Verification:** `--filter=OnboardingResolverAdmanGrantTest` voltou a 10/10 (era 8 passed, 2 failed).
- **Committed in:** `003c389e` (Task 3 commit — o fix aconteceu antes do commit, nunca foi versionado quebrado)

---

**Total deviations:** 2 auto-fixed (ambos Rule 1 — bugs em testes, nenhum em código de produção)
**Impact on plan:** Nenhum código de produção foi alterado por deviation. O primeiro fix preserva a intenção do teste do Plano 03 (provar que os resolvers locais continuam registrados) sem reintroduzir uma invariante que o próprio plano anterior já sabia que ia quebrar. O segundo fix corrige uma suposição errada sobre o comportamento do Eloquent (`create()` não reconsulta defaults de coluna), sem mudar o que estava sendo testado.

## Issues Encountered

- `AdmanService::fetchPerformance()` tem retry embutido para `429` (até 3 tentativas, com `sleep()` real de 2s+4s entre elas) que não pode ser mockado via `Carbon::setTestNow` (é `sleep()`/wall-clock, não hora lógica). Isso é comportamento de produção fora do escopo deste plano — os 2 testes que provocam 429 (um em `AdmanGrantResolver`, um via `ResolveOnboardingPassoJob`) pagam esse custo real (~6-7s cada), mas o total da suíte (~31s) segue bem dentro do teto de 60s do `135-VALIDATION.md`.
- `MercadoLivreService::get()` tem retry embutido equivalente para 429 (até 3 tentativas extras, `sleep()` real de 1+2+4s) — o teste de 429 do `MetricasContaResolver` paga ~7s pelo mesmo motivo, sem necessidade de ação adicional.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `OnboardingResolverFactory` pronto para o Plano 07 acrescentar a 5ª chave (`AcervoColetadoResolver`, `acervo_coletado`) — mesmo padrão de edição aditiva mínima no `AppServiceProvider`.
- `ResolveOnboardingPassoJob` pronto para ser despachado por qualquer consumidor (comando de reavaliação do Plano 12, painel operacional do Plano 09) sem precisar conhecer o resolver por trás do `auto_fonte` — resolve pela `TemplatePasso::auto_fonte` do passo.
- Nenhum dos dois resolvers desta plan seta `CHAVE_COLETA_EM_ANDAMENTO` — confirmado por grep (0 ocorrências nos dois arquivos) e por teste negativo. Essa chave continua exclusividade do `AcervoColetadoResolver` do Plano 07.
- Gate de regressão conferido nesta sessão: as 4 suítes de risco do Observer (52/52) e o gate de Polos (10 falhas pré-existentes, zero novas) batem exatamente com a baseline — `.planning/phases/135-.../135-BASELINE-TESTES.md` não precisou de nova seção porque nenhum Observer foi tocado nesta plan.
- `git diff --name-only` desta plan não contém nenhum arquivo de Polos (`Polos*`, `*olos*`) — D-02 intacto.

---
*Phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com*
*Completed: 2026-08-11*

## Self-Check: PASSED

- FOUND: `app/Services/Onboarding/Resolvers/AdmanGrantResolver.php`
- FOUND: `app/Services/Onboarding/Resolvers/MetricasContaResolver.php`
- FOUND: `app/Jobs/ResolveOnboardingPassoJob.php`
- FOUND: `tests/Feature/Phase135/OnboardingResolverAdmanGrantTest.php`
- FOUND: `tests/Feature/Phase135/OnboardingResolverMetricasTest.php`
- FOUND: commit `6c344ec9`
- FOUND: commit `8ef2dd05`
- FOUND: commit `003c389e`
