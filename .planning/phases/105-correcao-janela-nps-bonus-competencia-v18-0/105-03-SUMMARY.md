---
phase: 105-correcao-janela-nps-bonus-competencia-v18-0
plan: 03
subsystem: bonus-desempenho
tags: [nps, bonus, janela-competencia, cache, v18.0]

# Dependency graph
requires:
  - phase: 105-01
    provides: "DesempenhoScoreService::computeNpsWindow() com janela de NPS deslocada +1; cacheKey v6"
  - phase: 105-02
    provides: "cron desempenho:consolidar-mes reagendado para lastDayOfMonth('14:00')"
provides:
  - "NpsController::bustarCacheDoBonus busta a competência X-1 (não X), via cacheKey() helper"
  - "Fixtures de NPS dos testes-âncora (Carlos, dual-path, elegibilidade) recolocadas na janela M+1"
  - "Literais de cache v5→v6 atualizados em toda a suíte (fallout do bump da 105-01)"
  - "Bug pré-existente corrigido: criarEmpresaNaCarteira em ConsolidarMesDesempenhoCommandTest nunca forçava companies.created_at"
affects: [futuras fases que toquem NpsController ou DesempenhoScoreService cache/janela]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "subMonthNoOverflow() para navegar competência↔janela-de-leitura em ambas as direções (105-01 desloca +1 pra ler NPS; 105-03 desloca -1 pra bustar a chave certa)"

key-files:
  created: []
  modified:
    - app/Http/Controllers/NpsController.php
    - tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php
    - tests/Feature/Phase74/DesempenhoScoreServiceTest.php
    - tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php
    - tests/Feature/V16/DesempenhoElegibilidadeTest.php
    - tests/Feature/V16/BonusDualPathRegressaoTest.php
    - tests/Feature/V18/DesempenhoMetadadosCacheTest.php

key-decisions:
  - "bustarCacheDoBonus busta $mesCompletado->subMonthNoOverflow() (competência X-1), sempre via cacheKey() — nunca monta a chave à mão"
  - "Âncora Carlos: NPS movido de julho para agosto (M+1); setTestNow mantido em 2026-08-01 (não movido pra setembro) para não quebrar o filtro 'empresa nova' que usa offsets relativos '-3 months'"
  - "test_nps_medio_e_zero: mês em curso + M+1 ainda em coleta agora retorna null (excluído), não mais 0.0 — comportamento NOVO documentado, não regressão; teste complementar criado para cobrir o caso 0.0 (M+1 já fechada)"
  - "V16/DesempenhoElegibilidadeTest: 2 testes com mês EM CURSO recalculados (nps_medio null; nota_final só com componentes financeiros disponíveis)"
  - "Bug pré-existente descoberto e corrigido (Rule 1): ConsolidarMesDesempenhoCommandTest::criarEmpresaNaCarteira nunca forçava companies.created_at, causando var_faturamento/var_margem sempre null (filtro 'empresa nova' DESEMP-04) — não relacionado à janela de NPS, mas bloqueava o teste"

patterns-established:
  - "Testes de golden com deslocamento de janela documentam a conta no comentário (não silenciam divergência) — ver docblock de criarCarlosCompleto()"

requirements-completed: [NPSWIN-03, NPSWIN-04]

duration: ~90min
completed: 2026-07-21
---

# Fase 105 Plano 03: Cache-bust por competência X-1 + fallout de regressão Summary

**`bustarCacheDoBonus` passa a invalidar a competência X-1 (não X); âncoras Carlos/dual-path/elegibilidade recalibradas para a janela M+1; literais de cache v5→v6 corrigidos em toda a suíte relevante — fechando o deslocamento +1 iniciado na 105-01.**

## Performance

- **Tasks:** 3/3 completas
- **Files modified:** 7
- **Commits:** 3 atômicos

## Accomplishments

- `NpsController::bustarCacheDoBonus` agora busta `cacheKey($userId, $mesCompletado->subMonthNoOverflow())` — uma resposta coletada em X invalida a chave da competência X-1 (a que lê X como janela de NPS pós-105-01), corrigindo o bug em que o `Cache::forget` apagava uma chave que ninguém lia.
- Âncora Carlos (`DesempenhoScoreServiceTest::test_fixture_carlos_retorna_nota_4_42_basico`) e mais 3 testes recolocados na janela M+1 — golden numérico preservado (4.25/4.42/basico), aritmética documentada em comentário.
- `test_nps_medio_e_zero_quando_user_sem_respostas_no_mes` recalibrado para a semântica nova (mês em curso + M+1 em coleta → `null`, não `0.0`); novo teste complementar cobre o caso `0.0` (M+1 já fechada).
- Fallout de versão v5→v6 corrigido em `DesempenhoElegibilidadeTest`, `DesempenhoMetadadosCacheTest` e `BonusDualPathRegressaoTest` (métodos renomeados, literais e mensagens atualizados).
- Fallout de janela em `DesempenhoElegibilidadeTest` (2 testes com mês em curso recalculados: nps excluído, nota_final recalculada só com financeiro).
- Bug pré-existente descoberto e corrigido (Rule 1, fora da causa raiz de NPS): `ConsolidarMesDesempenhoCommandTest::criarEmpresaNaCarteira` nunca forçava `companies.created_at`, fazendo toda fixture da suíte cair no filtro "empresa nova" (DESEMP-04) e zerar `var_faturamento_pct`/`var_margem_pct`.

## Task Commits

1. **Task 1: bustarCacheDoBonus busta a competência X-1** - `4fffd35` (fix) — commit atômico e isolado (só `NpsController.php` + `NpsInvalidacaoRespostaTest.php`), `git status` verificado antes; sem conflito com a sessão paralela de NPS que também tocou o arquivo depois (commit `43ee94e`, merge limpo confirmado).
2. **Task 2: Ancora Carlos e dual-path com fixtures em M+1** - `12d19cc` (test)
3. **Task 3: Fallout v5→v6 e janela M+1 (elegibilidade/cache/consolidar-mes)** - `61d5346` (test)

_Plano `type=execute` sem tasks TDD formais — Task 1 seguiu o fluxo RED-ish (ajustar teste + implementação juntos, já que o bug era conhecido e coberto pelo teste existente)._

## Files Created/Modified

- `app/Http/Controllers/NpsController.php` - `bustarCacheDoBonus` busta X-1 via `subMonthNoOverflow()` + docblock explicando o motivo
- `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` - 2 testes com chave v6 + competência X-1 (2026-05)
- `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` - 4 testes com NPS em M+1 (agosto); novo teste `test_nps_medio_e_zero_com_m1_fechada_penaliza_com_0`
- `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php` - NPS semeado em M+1; `criarEmpresaNaCarteira` corrigido (Rule 1)
- `tests/Feature/V16/DesempenhoElegibilidadeTest.php` - 2 testes com mês em curso recalculados; `test_cache_bumpado_para_v5` → `v6`
- `tests/Feature/V16/BonusDualPathRegressaoTest.php` - `test_cache_bumpado_para_v5` → `v6` (só nomenclatura, sem mudança funcional)
- `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` - 3 testes com literal v5→v6

## Decisions Made

- **subMonthNoOverflow() em ambas as direções:** 105-01 desloca +1 (M lê NPS de M+1); 105-03 desloca -1 no bust (resposta em X afeta competência X-1). Ambos usam `subMonthNoOverflow`/`addMonthNoOverflow` para evitar overflow de dia-do-mês.
- **setTestNow do Carlos mantido em agosto (não movido pra setembro):** tentativa inicial de mover para setembro (pra garantir M+1 sempre "fechada") quebrou 3 testes financeiros não relacionados a NPS, porque `computeVarFaturamento` usa offsets relativos (`-3 months`) que colidem com o limite exato do filtro "empresa nova" quando "agora" muda. Revertido — mantido agosto, com o teste de exclusão-vs-penalização tratado localmente (novo teste com seu próprio `setTestNow`).
- **Bug financeiro do ConsolidarMesDesempenhoCommandTest não é fallout de NPS:** investigação (`fwrite(STDERR, ...)` temporário, removido) revelou que `empresas_com_baseline=0` vinha de `companies.created_at` nunca sendo forçado nesse helper local — corrigido com o mesmo padrão já usado em `Phase74/DesempenhoScoreServiceTest`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug pré-existente] `ConsolidarMesDesempenhoCommandTest::criarEmpresaNaCarteira` nunca forçava `companies.created_at`**
- **Found during:** Task 3, investigando por que `test_comando_grava_snapshot_com_mes_referencia_do_mes_anterior_quando_sem_flag` continuava `nota_final=null` mesmo após mover o NPS para M+1.
- **Issue:** O helper local desta suíte criava a empresa via `Company::factory()->create()` sem forçar `created_at` (só o pivot `company_users` recebia o timestamp `-3 months`). `computeVarFaturamento` (DESEMP-04, "Ajuste 2026-07-09") filtra por `companies.created_at`, não pelo pivot — a empresa sempre caía no filtro "nova", zerando `var_faturamento_pct`/`var_margem_pct`/`empresas_com_baseline` independente da janela de NPS. Bug pré-existente, não introduzido pela 105-01/105-02.
- **Fix:** Aplicado o mesmo padrão de `forceFill(['created_at' => $ts])` já usado em `Phase74/DesempenhoScoreServiceTest::criarEmpresaNaCarteira`.
- **Files modified:** `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php`
- **Verification:** Suíte completa do arquivo 7/7 verde.
- **Committed in:** `61d5346` (Task 3 commit)

---

**Total deviations:** 1 auto-fixado (Rule 1 — bug pré-existente fora da causa raiz de NPS, mas bloqueava o teste listado como fallout esperado pela 105-01/105-02).
**Impact on plan:** Necessário para deixar a suíte verde; não é regressão introduzida por esta fase.

## Issues Encountered

- Tentativa inicial de mover o `setTestNow` global de `Phase74/DesempenhoScoreServiceTest` para setembro (visando M+1 sempre fechada) quebrou 3 testes financeiros não relacionados a NPS (edge exato do filtro "empresa nova" com offsets `-3 months`). Revertido para agosto; resolvido com um teste complementar dedicado para o cenário "M+1 já fechada".
- O ambiente local não suporta `php artisan test --testsuite=Feature` num único processo — comandos com `set_time_limit(300)` pré-existentes em `SyncGrantsFromSftp`/`SyncGrantsFromEcfDrive`/`GrantController` resetam o relógio de execução acumulado do processo PHPUnit, e a suíte inteira (~1000+ testes) excede esse orçamento bem antes de terminar. Contornado rodando a suíte em lotes por diretório (ver seção de verificação abaixo) — mesma abordagem que os executores da 105-01/105-02 já haviam adotado implicitamente (rodaram subconjuntos, não o comando literal `--testsuite=Feature`).
- Durante a execução, uma sessão paralela ativa fez merge de `origin/main` na branch (`43ee94e` + merge `268d519`), incluindo um outro commit que também tocou `NpsController.php` (`fix(nps): Faltantes por (empresa, modelo)`). Verificado explicitamente: sem conflito, `bustarCacheDoBonus` permaneceu intacto após o merge, suíte de NPS re-executada e confirmada verde.

## Verificação — Regressão da fase inteira

**Suíte diretamente mandatada pelo constraint (66/66 verde):**
`JanelaNpsBonusTest` (5) · `ConsolidarMesJanelaNpsTest` (2) · `NpsInvalidacaoRespostaTest` (10) · `Phase74/DesempenhoScoreServiceTest` (15) · `Phase74/ConsolidarMesDesempenhoCommandTest` (7) · `V16/DesempenhoElegibilidadeTest` (7) · `V16/BonusAtribuicoesNpsTest` (4) · `V16/BonusDualPathRegressaoTest` (6) · `V18/DesempenhoMetadadosCacheTest` (10) = **66 passed, 314 assertions**.

**Sweep adicional da suíte Feature completa** (rodada em lotes por diretório, já que o comando único `--testsuite=Feature` não completa neste ambiente — ver Issues Encountered). Cobertura: praticamente toda `tests/Feature/` exceto os diretórios de ML Ads/Sugadores com backoff real (`Phase38` parcial, `Phase39`, `Phase41`, `Phase42`, `Sugadores` — sampled individualmente, ex. `MercadoLivreAdsServiceBackoffTest` 13/13 verde em isolamento, só lento por `usleep` real).

Falhas residuais encontradas — **todas pré-existentes e não relacionadas a NPS/Desempenho/Bônus** (confirmado por leitura de código: nenhuma toca `DesempenhoScoreService`, `computeNpsWindow`, `cacheKey`, ou `NpsController::bustarCacheDoBonus`):

| Arquivo | Causa (não relacionada à 105) |
|---|---|
| `PublicacaoDesempenhoRouteTest` | **Conhecida** (constraint do prompt) — 403 em vez de 200 |
| `AdminFechamentoControllerTest` (6) | Validação/contract_end/periodo_coberto — módulo Fechamento |
| `DevControllerTest` (4) | Props `empresas`/rotas dev — módulo Dev |
| `ExampleTest`, `FechamentoMigrationTest` | Setup genérico/migration |
| `PerformanceCargoFilterTest` (5) | Fixture não populada com `contratos_servico`/`servico_id` exigido pelo `CarteiraContextService` (Fase 91) — pré-existente, não usa NPS |
| `Phase13ComercialTest/MigrationTest` (9) | Validação de formulário/migração retroativa — módulo Comercial |
| `Phase14*` (4) | `service_type`, filtro pendentes, cobrança |
| `Phase18/CompaniesCustIdFilterTest` (2) | Filtro de custId |
| `Phase31NpsSubmitTest`, `Phase33OnboardingFichaTest` | `auto_generated` flag / mensagem de grants — não relacionados à janela de bônus |
| `Phase37ServicoSetorTest`, `Phase61*` (3), `Phase69/NpsPhase69IntegrationTest` | Contagem de setores / source_counts Portfolio / drift de data relativa a "hoje" real |
| `Phase75/RascunhoCompanyIdImutavelTest`, `Phase77/PayloadCompletoTest` (2) | HTTP real ao MLB Catálogo (token 400) — infra de teste, não app |
| `Phase38/PolosControllerTest` (5), `Phase38Publicador/MeuPainelControllerTest`, `Polos/PolosFaturamentoSnapshotTest` (4) | Módulo Polos/Publicador — assinatura de Job, drift de mês relativo a "hoje" |

Nenhuma falha residual toca a lógica da 105 (janela de NPS, cache-bust, dual-path). Guardrail do plano respeitado — nenhuma regressão financeira/elegibilidade/dual-path foi mascarada.

## Known Stubs

Nenhum.

## Next Phase Readiness

Fase 105 (v18.0) completa: motor (105-01), cron (105-02) e cache-bust + fallout (105-03) todos entregues e verdes. Nenhum bloqueio conhecido para a próxima fase da milestone v18.0.

---
*Phase: 105-correcao-janela-nps-bonus-competencia-v18-0*
*Completed: 2026-07-21*
