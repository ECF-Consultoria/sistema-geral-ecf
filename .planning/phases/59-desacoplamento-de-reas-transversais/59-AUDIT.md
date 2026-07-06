# Phase 59 — AUDIT.md

Deliverable central do CROSS-01 (+ parte do CROSS-02). Mapeamento exaustivo do
acoplamento incorreto a Mercado Livre nos 3 controllers hotspot identificados
pelo scout do `59-CONTEXT.md` (ComercialController, CompanyController,
AdminController), confirmação de que Publicação (`pub.*`) já é transversal, e
baseline de testes para o gate de zero-regressão do Plan 03.

## Baseline pré-fix (Plan 01)

**Data:** 2026-07-06T13:47:34Z (ISO)

**Comando de referência:** `php artisan test` (suite completa, `phpunit.xml` —
testsuites `Unit` + `Feature`).

**Nota metodológica — limitação de infraestrutura descoberta durante a
captura do baseline:** `php artisan test` (e `vendor/bin/phpunit` direto)
crasham de forma determinística com `Fatal error: Maximum execution time of
300 seconds exceeded` em `app/Services/Sugadores/MercadoLivreAdsService.php`
ou em `vendor/symfony/process/Pipes/WindowsPipes.php`, **mesmo com
`-d max_execution_time=0`** passado explicitamente ao processo PHP. Causa raiz
identificada: `App\Console\Commands\SyncGrantsFromEcfDrive::handle()` chama
`set_time_limit(300)` (linha 23) — código de produção legítimo para requests
HTTP longos (Phase 20). `tests/Feature/Phase20/SyncGrantsFromEcfDriveTest.php`
invoca esse comando real (`Artisan::call('grants:sync-ecf')`) **12 vezes** no
mesmo processo PHPUnit (não há isolamento de processo por teste nesta suite).
Cada chamada reseta o contador de `max_execution_time` do processo inteiro
para 300s a partir daquele instante — sobrepondo qualquer `-d` passado na
invocação do CLI. Como a suite Phase 41/42 (`MercadoLivreAdsServiceBackoffTest`
e afins) usa `usleep()` real para simular backoff exponencial (não mockado),
o tempo acumulado de execução após o último reset ultrapassa 300s antes do
fim da suite, derrubando o processo inteiro com fatal error — **não é um teste
vermelho, é o processo PHP inteiro morrendo**, impedindo qualquer contagem.

**Contorno usado (sem alterar código de produção):** suite rodada em 2
lotes via `vendor/bin/phpunit` direto (bypassando o wrapper `artisan test`):
(1) suite completa exceto `SyncGrantsFromEcfDriveTest` via
`--filter '^(?!.*SyncGrantsFromEcfDriveTest).*$'`; (2) o arquivo excluído
rodado sozinho. Os totais abaixo são a SOMA dos 2 lotes. Este é um problema de
infraestrutura de teste pré-existente, não uma regressão desta plan — nenhum
arquivo de produção foi alterado para obter o baseline.

**Resultado agregado (lote 1 + lote 2):**

| Métrica | Lote 1 (943 testes, exclui SyncGrantsFromEcfDriveTest) | Lote 2 (12 testes, arquivo isolado) | **Total** |
|---|---|---|---|
| Tests | 943 | 12 | **955** |
| Assertions | 4707 | 41 | **4748** |
| Errors | 15 | 0 | **15** |
| Failures | 48 | 0 | **48** |
| Skipped | 1 | 0 | **1** |
| Tempo | 10:56.385 | 00:01.405 | ~10:58 |

**Total de vermelhos no baseline: 63** (15 errors + 48 failures), **892
passaram**, **1 skipped**, de **955 testes coletados**.

**Phase 57 (regressão específica — CONFIRMADO verde):** `--filter Phase57` →
**20/20 passed**, 26 assertions, 10.198s. Bate com `58-VERIFICATION.md`.

**Phase 58 (regressão específica — CONFIRMADO verde):** `--filter Phase58` →
**16/16 passed**, 62 assertions, 32.500s. Bate com `58-VERIFICATION.md`.

**Os 63 vermelhos são TODOS pré-existentes e NÃO relacionados ao escopo desta
Phase (Comercial/Company/Admin acoplamento ML)** — inspeção linha a linha de
cada erro/falha confirma:

- **9 errors** — `Tests\Unit\CalcularFaixaTest::*` — `ArgumentCountError` em
  `new AdminController()` sem o `AdmanService` promovido no construtor
  (`AdminController.php:21`). Teste legado desatualizado desde que
  `AdmanService` virou dependência obrigatória; não relacionado a
  marketplace/ML.
- **2 errors** — `Tests\Feature\Phase13MigrationTest::test_derivacao_service_type_*`
  — `Attempt to read property "service_type" on null` (coluna legacy já
  dropada em fase posterior; teste de migration histórica).
- **4 errors** — `Tests\Feature\Phase14MigrationTest::*` — `Carbon\Exceptions\InvalidFormatException:
  ... The timezone could not be found in the database` ao parsear
  `contract_start` — bug de ambiente Windows/ICU local (timezone DB), não
  relacionado a código da Phase 59.
- **48 failures** — distribuídas em `CompanyServiceTypeTest`,
  `MercadoLivreSugadoresProviderTest` (Phase 39), `AdminFechamentoControllerTest`
  (5 já documentadas como pré-existentes em `deferred-items.md` da Phase 14),
  `DevControllerTest`, `ExampleTest` (scaffold padrão Laravel — redirect em vez
  de 200, ambiente sem seed), `FechamentoMigrationTest`, `Phase13ComercialTest`
  (11 falhas — coluna legacy `service_type` obrigatória num teste que não a
  popula mais), `Phase14AdminControllerCobrancaTest`, `Phase14ComercialTest`,
  `Phase14MlbControllerFiltroTest`, `Phase14VerificarCobrancaTest`,
  `Phase18\CompaniesCustIdFilterTest`, `Phase33OnboardingFichaTest`,
  `Phase37ServicoSetorTest`, **`Phase38\PolosControllerTest`** (6 falhas — já
  documentadas em `.planning/phases/38-smoke-ml-piloto-bymobile/deferred-items.md`
  como fora de escopo, dev paralelo), **`Phase42\*`** (4 falhas — conhecidas do
  acúmulo de contexto do `STATE.md`, ligadas ao cutover ML shadow mode),
  `Polos\PolosFaturamentoSnapshotTest`.
- **Nenhuma falha/erro referencia `ComercialController`, `CompanyController`
  ou `AdminController` nos métodos tocados pelo escopo desta Phase**
  (`store`, `update`, `listagem`, `index`, `show`, `empresas`,
  `fechamento`/`syncFaturamento`) de forma relacionada a marketplace/ML —
  `AdminFechamentoControllerTest` e `Phase14AdminControllerCobrancaTest`
  tocam `AdminController::fechamento()` mas as falhas são sobre colunas
  legacy `service_type`/`contract_start` (Phase 14), não sobre
  `ml_store_id`/`adman_account_id`/`marketplaces_extras`.

**Implicação para o Plan 03 (gate de zero-regressão):** o baseline de
comparação é **63 vermelhos pré-existentes de 955 coletados** (943+12,
excluindo o crash de infraestrutura). Plan 03 deve confirmar que, após os
fixes do Plan 02, a suite continua produzindo **exatamente os mesmos 63
vermelhos (ou menos)** — nenhum novo erro/falha introduzido pelos fixes
`ComercialController`/`CompanyController`/`AdminController`. O mesmo
contorno de 2 lotes (excluir `SyncGrantsFromEcfDriveTest`, rodar separado)
deve ser reaplicado no Plan 03 para obter uma contagem comparável.

---

_(Seções "Metodologia", "Comercial", "Company", "Admin", "Publicação — CONFIRMED transversal"
e "Sumário" serão preenchidas na Task 2 deste mesmo plan — scout completo com
classificação linha a linha dos 56 refs identificados nos 3 controllers hotspot.)_
