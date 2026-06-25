---
phase: 40-shadow-mode-tabelas-de-compara-o
plan: 02
subsystem: sugadores

tags: [phase-40, sugadores, shadow-mode, service, ml-migration, tdd, mockery]

requires:
  - phase: 40-shadow-mode-tabelas-de-compara-o
    plan: 01
    provides: "Models SugadorProviderRun + SugadorProviderItem com casts/relations prontos para receber gravação shadow"
  - phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
    provides: "SugadorAnalysisService::analyzeCompany($company, $ref, $dryRun, $forceProvider) operacional — Plan 40-02 chama com $dryRun=true em loop adman+ml"
provides:
  - "App\\Services\\Sugadores\\ShadowRunService::run(Company, ?Carbon) — orquestra 2 execuções paralelas Adman+ML por (empresa+data)"
  - "Gravação em `sugador_provider_runs` (1 row por provider) + `sugador_provider_items` (1 row por motivo detectado)"
  - "GATE REQ-40-02: gate de segurança HARDCODED `$dryRun=true` na chamada a analyzeCompany — assertion `Sugador::count()` antes==depois==0 validada no Test 3"
  - "Falha isolada por provider: try/catch \\Throwable em método executeForProvider — Adman down não interrompe execução do ML"
  - "raw_hash determinístico: SHA256 sobre (motivos ordenados + metrics ksortRecursive) — duplicatas exatas geram mesmo hash"
affects: [phase-40-03-provider-comparison-service, phase-40-04-comandos-scheduler]

tech-stack:
  added: []
  patterns:
    - "Service stateless com DI de SugadorAnalysisService no constructor — Laravel container resolve automaticamente"
    - "dryRun=true HARDCODED como gate de segurança (REQ-40-02) — não recebe via parâmetro, não pode ser sobrescrito por caller"
    - "Falha isolada por provider via try/catch \\Throwable em método privado executeForProvider — loop continua independente"
    - "raw_hash determinístico via SHA256(motivos sorted + metrics ksortRecursive) — preparação para Plan 40-03 detectar duplicatas/diffs"
    - "Mockery setup com bind $this->app->instance(SugadorAnalysisService::class, $mock) — pattern Phase 39 Plan 39-04 reutilizado"

key-files:
  created:
    - app/Services/Sugadores/ShadowRunService.php
    - tests/Feature/Phase40/ShadowRunServiceTest.php
  modified: []

key-decisions:
  - "dryRun=true HARDCODED na chamada a analyzeCompany — gate REQ-40-02 (não vem como parâmetro do método público, não pode ser sobrescrito). Phase 42 vai introduzir caminho de gravação via service SEPARADO, não modificando este."
  - "Falha de um provider NÃO interrompe o outro — try/catch \\Throwable em método privado executeForProvider; loop em run() segue pro próximo independente. Garante que indisponibilidade Adman não impede coleta ML e vice-versa."
  - "Janela analisada (periodo_inicio/fim) calculada lendo SugadorConfig::forCompany() com EXATAMENTE a mesma lógica do SugadorAnalysisService (periodoFim = refDate-1d; periodoInicio = periodoFim - (dias_analise-1)) — garante alinhamento de período entre o que o analyzer realmente buscou e o que a row Shadow Run declara."
  - "metrics_json recebe TUDO do detalhe que não tem coluna dedicada (tipo/campaign_id/adgroup_id/mlb_id/motivos removidos via unset) — flexível pra Plan 40-03 diffar campos arbitrários sem ficar acoplado ao shape exato de detalhes."
  - "raw_hash = SHA256 de (motivos sorted + metrics ksortRecursive) com JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR — determinístico (ordem motivos/chaves irrelevante). Sem unique constraint na coluna; duplicatas são esperadas e visíveis."
  - "Logging via Log::error com prefixo `[Sugadores Shadow]` — alinhado ao padrão pt-BR `[Sugadores]`/`[MLB SyncVendas]` etc do projeto. Empresa+provider em cada linha pra traceability."
  - "Em vez de exigir `payload['error']` ser substring exata, o test do gate de falha usa `assertStringContainsString` — robusto contra prefixos/sufixos que o Laravel/PHP possa adicionar ao Throwable message."
  - "mb_substr(..., 0, 65000) protege contra mensagens de erro gigantes que ultrapassem TEXT — escolhido limite confortavelmente abaixo de TEXT MySQL (65535) e do que erros reais costumam ter."

patterns-established:
  - "Services Phase 40+ que orquestram analyzer em modo shadow: dryRun=true HARDCODED, try/catch por provider, gravação em tabelas auxiliares Plan 40-01"
  - "Tests Feature com Mockery + bind app->instance — setup do mock no helper privado mockAnalyzer(adman_payload, ml_payload) com expectativas Mockery::type(Carbon::class) no 2º arg"

requirements-completed: [REQ-40-02]

duration: 22min
completed: 2026-06-25
---

# Phase 40 Plan 40-02: ShadowRunService — orquestrador shadow paralelo (Adman + ML) sem gravar em sugadores

**Service `ShadowRunService::run(Company, ?Carbon)` orquestra 2 execuções paralelas de `SugadorAnalysisService::analyzeCompany` (uma forçando `forceProvider='adman'`, outra `'ml'`, AMBAS com `$dryRun=true` HARDCODED) gravando 1 row por execução em `sugador_provider_runs` + N rows em `sugador_provider_items` — sem tocar na tabela canônica `sugadores`.**

## Performance

- **Duration:** ~22 minutos
- **Started:** 2026-06-25T20:18:00Z
- **Completed:** 2026-06-25T20:40:00Z
- **Tasks:** 2 (RED + GREEN)
- **Files created:** 2 (1 service + 1 test Feature)
- **Files modified:** 0 (zero modificação em arquivos do "não-tocar")

## Accomplishments

- **`ShadowRunService` entregue** com 1 método público (`run`), 4 métodos privados (`executeForProvider`, `buildItemPayload`, `computeRawHash`, `ksortRecursive`/`isAssoc`). Constructor recebe `SugadorAnalysisService` via DI — Laravel container resolve automaticamente. Loop fixo sobre `['adman', 'ml']` no `run()` garante que toda chamada processa AMBOS os providers (mesmo que um falhe).
- **GATE DE SEGURANÇA REQ-40-02 IMPLEMENTADO**: chamada a `analyzeCompany` em `executeForProvider` linha 113 passa `true` HARDCODED no 4º argumento (`$dryRun`). Não vem como parâmetro do método público. Comentário pt-BR em código explicita que esse gate é central pra Phase 40 e que Phase 42 vai introduzir caminho de gravação SEPARADO. **Validado em runtime via Test 3 (gate crítico): `Sugador::count()` antes==depois==0 mesmo com 20 detalhes mockados.**
- **Falha isolada por provider**: try/catch `\Throwable` dentro de `executeForProvider`; loop em `run()` continua para o próximo provider mesmo se um falhar. Quando falha, row recebe `status='failed'` + `error=msg` (mb_substr 65000). Test 5 valida cenário Adman→down + ML→ok; Test 6 valida o oposto.
- **Janela analisada exatamente alinhada ao analyzer**: lê `SugadorConfig::forCompany($company)` e aplica `periodoFim = refDate-1d` + `periodoInicio = periodoFim - (dias_analise - 1)` — mesma lógica das linhas 136-137 do `SugadorAnalysisService`. Garante que a row de `sugador_provider_runs` declara a janela REAL que o analyzer buscou.
- **`raw_hash` determinístico**: SHA256 de `{motivos: sorted, metrics: ksortRecursive}` com `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`. Test 7 valida: 2 itens idênticos no payload produzem mesmo hash; via reflection chamando `computeRawHash` direto, valida que `['cpc_alto','sem_conversao']` e `['sem_conversao','cpc_alto']` geram mesmo hash (ordem irrelevante).
- **Retorno do método público padronizado**: `['adman_run_id' => int, 'ml_run_id' => int, 'adman_status' => 'completed'|'failed', 'ml_status' => 'completed'|'failed']` — Test 8 valida shape exato (4 keys via `assertEqualsCanonicalizing`).
- **9/9 tests Feature verdes** (`tests/Feature/Phase40/ShadowRunServiceTest`, 50 assertions, 1.49s) cobrindo todos os comportamentos contratuais + gate crítico zero gravação.
- **GATE DE ZERO REGRESSÃO ATENDIDO**:
  - Suite Phase 40 total: **17/17 verdes** (8 do Plan 40-01 + 9 do Plan 40-02), 73 assertions, 1.84s
  - Suite Sugador acumulada (filtro `Sugador`): **75/75 verdes** (era 73 baseline 40-01 + 2 detectados pelo filtro tocando `Sugadores` namespace do service novo), 475 assertions, 34.53s
  - Suite Phase 39: **48/48 verdes** (208 assertions, 3.18s) — refactor do analyzer e providers intactos
- **Gate estático confirmado via grep**: `Sugador::create` / `Sugador::insert` / `->sugadores->` / `->sugador()->create` retornam **0 matches** em `app/Services/Sugadores/ShadowRunService.php`. O service NUNCA grava direto na tabela canônica.

## Task Commits

1. **Tarefa 1 (RED): Suite Feature ShadowRunService com Mockery** — `49d3c57` (test) — 9 tests escritos cobrindo: 2 rows criadas, items persistidos, gate crítico zero gravação, status completed+summary, falha Adman não interrompe ML, falha ML não interrompe Adman, raw_hash determinístico, retorno tem 4 keys, dryRun=true sempre. Suite RED 9/9 failed com `Class App\Services\Sugadores\ShadowRunService does not exist` — confirmação RED conforme esperado.
2. **Tarefa 2 (GREEN): Implementa ShadowRunService** — `01f0bf0` (feat) — Service criado (237 linhas, PHPDoc pt-BR explícito sobre gate REQ-40-02). Suite GREEN 9/9 verde (50 assertions, 1.49s). Suite Phase 40 acumulada 17/17 verde. Suite Sugador 75/75 verde. Suite Phase 39 48/48 verde — zero regressão.

**Plan metadata commit:** (será criado após este SUMMARY)

## Files Created/Modified

### Criados

- `app/Services/Sugadores/ShadowRunService.php` — Service stateless com 1 método público (`run`) e 4 privados (`executeForProvider`, `buildItemPayload`, `computeRawHash`, `ksortRecursive`/`isAssoc`). 237 linhas. PHPDoc pt-BR no topo explica gate REQ-40-02, propósito Phase 40, e que Phase 42 vai introduzir caminho de gravação SEPARADO. Comentário inline na linha do `analyzeCompany($company, $referenceDate, true, $providerName)` marca o `true` como HARDCODED gate de segurança.
- `tests/Feature/Phase40/ShadowRunServiceTest.php` — 9 tests Feature usando `RefreshDatabase` + Mockery + bind `$this->app->instance(SugadorAnalysisService::class, $mock)`. Helpers privados `makeCompanyWithConfig`, `detalhesFixture`, `mockAnalyzer`, `payloadOk`. Setup com `Carbon::create(2026, 6, 25)->startOfDay()` fixo para determinismo. tearDown chama `Mockery::close()`.

### Modificados

Nenhum. Zero modificação em:

- `app/Models/Sugador.php`, `SugadorConfig.php`, `SugadorAcao.php`
- `app/Models/SugadorProviderRun.php`, `SugadorProviderItem.php` (Plan 40-01) — apenas consumidos
- `app/Services/SugadorAnalysisService.php` (Phase 39) — apenas consumido via DI
- `app/Services/Sugadores/SugadoresAdsProviderFactory.php` (Phase 39)
- `app/Services/Sugadores/AdmanSugadoresProvider.php`, `MercadoLivreSugadoresProvider.php` (Phase 39)
- `app/Services/AdmanService.php`, `MercadoLivreService.php`, `MercadoLivreAdsService.php`
- `app/Jobs/AnalyzeCompanySugadoresJob.php`
- `app/Console/Commands/AnalyzeSugadores.php` (Phase 39)
- `routes/console.php` (scheduler é Plan 40-04)
- `config/sugadores.php` (criação é Plan 40-04)
- Models 40-01 (`SugadorProviderRun`, `SugadorProviderItem`)
- Migration 40-01

Validado via `git diff --name-only HEAD~2 HEAD` mostrando apenas os 2 arquivos novos acima.

## Decisions Made

1. **`$dryRun=true` HARDCODED, não recebe como parâmetro do `run()`** — gate REQ-40-02 central da Phase 40. Decisão é não confiar em "caller passa true" — o método `run()` da `ShadowRunService` literalmente não tem como passar false. Phase 42 vai introduzir caminho de gravação ML em service SEPARADO, não modificando este.
2. **Falha isolada por provider via try/catch \\Throwable dentro de `executeForProvider`** — loop em `run()` itera `['adman', 'ml']` e cada iteração captura próprias falhas. Adman down → row Adman.status='failed' + ML continua normalmente. Validado por Test 5/6.
3. **Janela analisada lida via `SugadorConfig::forCompany()`** — alinhamento exato com a janela que o `analyzeCompany` realmente buscou (linhas 136-137 do service). Garante consistência entre o que a row Shadow Run declara como periodo_inicio/fim e o que o analyzer leu da Adman/ML.
4. **`metrics_json` = todo o detalhe minus campos dedicados** — `unset($metricsJson['tipo'], ['campaign_id'], ['adgroup_id'], ['mlb_id'], ['motivos'])` preserva chaves que não têm coluna dedicada (`nome`, `investimento`, `vendas`, etc) sem ficar acoplado ao shape exato. Plan 40-03 vai poder diffar essas métricas sem mudar o service.
5. **`raw_hash` = SHA256(motivos sorted + metrics ksortRecursive)** — determinístico via JSON_UNESCAPED_UNICODE + JSON_THROW_ON_ERROR. Sem unique constraint na coluna; duplicatas são esperadas (mesmo sugador detectado por Adman E ML deve gerar 2 itens distintos com mesmo hash — útil pro Plan 40-03 identificar matches exatos).
6. **`mb_substr($e->getMessage(), 0, 65000)`** — protege contra mensagens gigantes que ultrapassem TEXT MySQL (65535). Limite confortável abaixo do máximo + uso de `mb_substr` para evitar quebra em meio a caractere multibyte (UTF-8 — pt-BR).
7. **Padrão log `[Sugadores Shadow]`** — prefixo bracketed alinhado ao padrão pt-BR `[Sugadores]`/`[MLB SyncVendas]` do projeto. Inclui empresa.id + providerName em cada linha de erro para traceability.
8. **Mock SugadorAnalysisService bindado via `$this->app->instance(SugadorAnalysisService::class, $mock)`** — mesmo pattern já validado em Phase 39 Plan 39-04. O ShadowRunService consome via DI; o container resolve com o mock no lugar do real. Garante que NÃO precisamos rodar a Adman/ML reais nem o factory de providers nos tests Feature do orquestrador.
9. **Helper `mockAnalyzer($payloadAdman, $payloadMl)` com expectativa `Mockery::type(Carbon::class)` no 2º arg + literal `true` no 4º + literal `'adman'`/`'ml'` no 5º** — Mockery seleciona o payload certo por provider e ainda valida em runtime que dryRun=true é passado. Se o service por engano passar false, Mockery falha com "no matching expectation" no setUp do test 9 que tem `->once()`.

## Deviations from Plan

### Auto-fixed Issues

**Nenhuma deviação detectada — plano executado exatamente como escrito.**

Pontos onde poderia ter havido divergência mas seguiram o spec:

- O `<context><interfaces>` do plan documentava que o shape do `detalhes[]` retornado pelo analyzer NÃO inclui chave `metrics` nem `mlb_id` em todos os casos. O service trata isso elegantemente via `$det['metrics'] ?? []` e `$det['mlb_id'] ?? null` — combinado com a estratégia "metrics_json = unset(campos dedicados)" garante que qualquer shape válido funciona.
- O plan sugeria comentário inline pt-BR no `analyzeCompany($company, $referenceDate, true, $providerName)` marcando o `true` como gate REQ-40-02 — entregue conforme especificado (linhas 105-113 do service).
- O plan exigia greps específicos passando: `dryRun` aparece 5x no service (linhas 20, 21, 80, 106, 113); `Sugador::create`/`->sugadores->create` = 0 matches. Ambos confirmados via Grep tool.

**Total deviations:** 0
**Impact on plan:** Nenhum.

## Issues Encountered

- **MariaDB local continua caído** (mesmo bloqueio conhecido das Phases 38/39/40-01). Tests rodam em SQLite em-memory via PHPUnit `RefreshDatabase`. Smoke real do `ShadowRunService` contra MariaDB segue deferido para a mesma quick task `dev:reparar-mariadb-local`. **Não impacta este plan** — o gate de zero gravação em `sugadores` é validado via assertion em SQLite em-memory que comprova `Sugador::count()` antes==depois mesmo com 20 detalhes mockados.

## User Setup Required

Nenhum — Plan 40-02 só cria 1 service novo. Sem env nova, sem rota HTTP, sem dependência externa, sem migration. O service só será exercido em produção quando Plan 40-04 entregar o comando `sugadores:shadow-ml` e o scheduler. Até lá, ele existe apenas como ferramenta consumível.

## Next Phase Readiness

- **Wave 2 do Plan 40 destravada parcialmente** — Plan 40-02 entregue; Plan 40-03 (ProviderComparisonService) pode rodar agora sem precisar esperar mais nada (depende apenas do schema 40-01 que está pronto).
- **Plan 40-04 (comandos + scheduler) destravado** assim que Plan 40-03 entregar — comandos `sugadores:shadow-ml` e `sugadores:compare-providers` vão consumir respectivamente `ShadowRunService` (este plan) e `ProviderComparisonService` (Plan 40-03).
- **REQ-40-02 fechado.**
- **Nenhum blocker para Plans seguintes** além do já documentado (MariaDB local — não afeta tests automatizados).

## Self-Check

- Arquivo `tests/Feature/Phase40/ShadowRunServiceTest.php` existe — FOUND
- Arquivo `app/Services/Sugadores/ShadowRunService.php` existe — FOUND
- Commit `49d3c57` (RED) presente em `git log` — FOUND
- Commit `01f0bf0` (GREEN) presente em `git log` — FOUND
- Suite `php artisan test --filter=ShadowRunService` retorna 9/9 PASS (50 assertions) — VERIFIED
- Suite `php artisan test --filter=Phase40` retorna 17/17 PASS (73 assertions) — VERIFIED
- Suite `php artisan test --filter=Sugador` retorna 75/75 PASS (475 assertions) — VERIFIED
- Suite `php artisan test --filter=Phase39` retorna 48/48 PASS (208 assertions) — VERIFIED
- Grep `Sugador::create|Sugador::insert|->sugadores->|->sugador()->create` em `app/Services/Sugadores/ShadowRunService.php` retorna **0 matches** — VERIFIED (gate REQ-40-02 estático)
- Grep `dryRun` em `app/Services/Sugadores/ShadowRunService.php` retorna 5 matches (linhas 20, 21, 80, 106, 113) — VERIFIED
- Test 3 (`run_NAO_grava_em_sugadores_GATE_CRITICO`) passou — VERIFIED (gate REQ-40-02 runtime)

**## Self-Check: PASSED**

---
*Phase: 40-shadow-mode-tabelas-de-compara-o*
*Completed: 2026-06-25*
