---
phase: 40-shadow-mode-tabelas-de-compara-o
plan: 04
subsystem: sugadores

tags: [phase-40, sugadores, shadow-mode, command, scheduler, ml-migration, tdd]

requires:
  - phase: 40-shadow-mode-tabelas-de-compara-o
    plan: 02
    provides: "ShadowRunService::run(Company, ?Carbon) — orquestrador shadow Adman+ML consumido pelo comando sugadores:shadow-ml"
  - phase: 40-shadow-mode-tabelas-de-compara-o
    plan: 03
    provides: "ProviderComparisonService::compareWindow(int, Carbon, Carbon) — comparador de paridade consumido pelo comando sugadores:compare-providers"
provides:
  - "App\\Console\\Commands\\SugadoresShadowMl — comando Artisan dispara shadow por empresa+dia (--company={id|all}, --days=N clamp 1..90)"
  - "App\\Console\\Commands\\SugadoresCompareProviders — comando Artisan imprime relatorio paridade (--format=table|json); exit 1 se <95%"
  - "config/sugadores.php — expoe ml_shadow_companies (int[]) a partir da env CSV SUGADORES_ML_SHADOW_COMPANIES"
  - "Scheduler diario 'sugadores-shadow-ml-daily' 13h BRT em routes/console.php (onOneServer, withoutOverlapping)"
  - ".env.example documenta SUGADORES_ML_SHADOW_COMPANIES com exemplo pt-BR"
affects: [phase-41-onboarding-ml, phase-42-cut-over-ml-primary]

tech-stack:
  added: []
  patterns:
    - "Comando CLI Laravel 12 com signature multi-flag e DI no constructor — pattern reutilizado de AnalyzeSugadores (Phase 39-05) e SugadoresMlSmoke (Phase 38)"
    - "config(*) lendo env CSV via array_map('intval', array_filter(array_map('trim', explode(','))))) — pipeline robusto contra espacos e valores vazios"
    - "Exit code automatizavel para CI: 0 = paridade aprovada, 1 = reprovada — viabiliza cron alerts e pipeline de gate de paridade"
    - "Scheduler com timezone explicito 'America/Sao_Paulo' + onOneServer + withoutOverlapping — alinhamento com pattern v3.0+ (sync-polos-faturamento-d1, nps-disparar-mensal)"
    - "Comandos NUNCA gravam diretamente em sugadores — consomem services Plan 40-02/03 que ja garantem gate REQ-40-02"

key-files:
  created:
    - app/Console/Commands/SugadoresShadowMl.php
    - app/Console/Commands/SugadoresCompareProviders.php
    - config/sugadores.php
    - tests/Feature/Phase40/SugadoresShadowMlCommandTest.php
    - tests/Feature/Phase40/SugadoresCompareProvidersCommandTest.php
  modified:
    - routes/console.php
    - .env.example

key-decisions:
  - "Clamp silencioso de --days em [1,90] no SugadoresShadowMl::handle() — mitiga T-40-04-01 (DoS por janela absurda) sem precisar de mensagem de erro adicional. --days=999 vira 90 transparentemente."
  - "--company=all com config vazia ABORTA exit 1 com mensagem pt-BR 'Nenhuma empresa elegivel' — fail-fast em vez de no-op silencioso (operador detecta config faltando)."
  - "Empresa inexistente em --company={id} ABORTA exit 1 ANTES do loop iniciar — implementado via Company::find() em pre-loop + early return; evita estado parcial onde primeira empresa rodou e a segunda falhou."
  - "Boundary >= 95.0 INCLUSIVE no compare-providers (Test 8 cobre paridade exata = 95.0 → exit 0) — alinha com a redacao do plano-migracao §7 ('alvo >=95%') que inclui o limite."
  - "Output pt-BR 'APROVADA' / 'REPROVADA' com label explicito + simbolo '>=95% — APROVADA' / '<95% — REPROVADA' — facilita leitura por operador humano sem precisar interpretar o numero."
  - "config/sugadores.php usa pipeline defensivo (array_filter duplo + array_values + array_map intval) — garante int[] limpo mesmo se env tiver 'a,b,,10, 20 ,' (entradas vazias, espacos, lixo nao-numerico vira 0 e e filtrado)."
  - "Scheduler entry adicionado APENAS no FIM de routes/console.php (linhas 155-162) — zero modificacao em entries existentes, validado via git diff mostrando 10 linhas '+' e zero '-'."
  - "compareWindow ja existe (Plan 40-03), comando apenas o consome — NAO duplica logica de tolerancias/buckets/agregacao. Comando e fino (apenas validacao + render)."

patterns-established:
  - "Comandos CLI Phase 40+ consomem services via DI no constructor (sem chamadas estaticas)"
  - "Validacao de argumentos obrigatorios feita ANTES de qualquer chamada de service — early return Command::FAILURE com mensagem pt-BR"
  - "Tests Feature de comando: mock do service no container via app->instance(...); helpers privados report() / makeCompany() reduzem boilerplate"

requirements-completed: [REQ-40-04, REQ-40-05, REQ-40-06, REQ-40-07, REQ-40-08]

duration: 10min
completed: 2026-06-25
---

# Phase 40 Plan 40-04: 2 comandos CLI + scheduler + config — Phase 40 inteira completa

**Comandos Artisan `sugadores:shadow-ml --company={id|all} --days=N` e `sugadores:compare-providers --company={id} --from --to --format=table|json` entregues; scheduler diario 13h BRT roda shadow contra empresas em SUGADORES_ML_SHADOW_COMPANIES; comparador retorna exit 0/1 automatizavel para CI. Phase 40 inteira (4/4 plans) fechada.**

## Performance

- **Duration:** ~10 minutos
- **Started:** 2026-06-25T20:40:38Z
- **Completed:** 2026-06-25T20:49:45Z
- **Tasks:** 2 (RED + GREEN)
- **Files created:** 5 (2 comandos + 1 config + 2 tests)
- **Files modified:** 2 (routes/console.php + .env.example — APENAS adicoes)

## Accomplishments

- **`SugadoresShadowMl` entregue** com signature `sugadores:shadow-ml {--company= : ID|all} {--days=1 : clamp 1..90}`. Constructor injeta `ShadowRunService` via DI. Helper privado `resolveCompanies()` aceita 'all' (le `config('sugadores.ml_shadow_companies')`) ou ID numerico isolado (rejeita strings nao-numericas com mensagem clara). Pre-validacao de existencia de TODAS as empresas via `Company::find()` em loop antes de iniciar o shadow — primeira inexistente aborta exit 1 com mensagem pt-BR (`Empresa {id} nao encontrada`). Loop double (empresa × dia) chama `ShadowRunService::run($company, $refDate)` por (empresa+dia); contadores `admanOk`/`mlOk`/`falhas` agregam o resultado por status retornado; summary final em pt-BR. Exit 0 mesmo com falhas individuais (registradas em error da row pelo service).
- **`SugadoresCompareProviders` entregue** com signature `sugadores:compare-providers {--company= : ID obrigatorio} {--from=} {--to=} {--format=table : table|json}`. Constructor injeta `ProviderComparisonService` via DI. Validacoes em cadeia: --company obrigatorio + numerico; --from/--to obrigatorios; --format ∈ ['table','json']; datas parseaveis (try/catch `Carbon::parse`). Render `table` usa `$this->table()` com 7 linhas pt-BR (Coincidencias, Metricas divergentes, Motivos divergentes, Apenas Adman, Apenas ML, Quarentena divergente, TOTAL) + linha final `Paridade de motivos: X,XX% (>=95% — APROVADA)` ou `(<95% — REPROVADA)`. Render `json` usa `json_encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)`. Exit code derivado de `>=95.0 ? SUCCESS : FAILURE` (boundary inclusive).
- **`config/sugadores.php` criado** expondo `ml_shadow_companies` como `int[]` a partir da env `SUGADORES_ML_SHADOW_COMPANIES` (CSV). Pipeline: `explode(',', env())` → `array_map('trim')` → `array_filter` (remove vazios) → `array_map('intval')` → `array_filter` (remove zeros) → `array_values` (reindexa). Tinker confirma: env vazia → `[]`; env `10,20` → `[10,20]`; env com lixo `a,,10, 20 ,b` → `[10, 20]`.
- **`.env.example` ganha bloco documentado em pt-BR** explicando que vazio = scheduler nao roda shadow pra ninguem, com exemplo `SUGADORES_ML_SHADOW_COMPANIES=10,20,42`. Posicionado apos os HUBSPOT_PROP_* (final do arquivo).
- **Scheduler `sugadores-shadow-ml-daily`** adicionado ao fim de `routes/console.php` (linhas 155-162). Roda `sugadores:shadow-ml --company=all --days=1` `dailyAt('13:00')` timezone `America/Sao_Paulo` com `onOneServer()` + `withoutOverlapping()`. **ZERO modificacao em entries existentes** — `git diff routes/console.php` mostra apenas 10 linhas com prefixo `+` e zero linhas com `-`. `php artisan schedule:list` confirma a entry agendada (next due: em 19h).
- **17/17 tests Feature verdes** desta plan (8 shadow-ml + 9 compare-providers, 34 assertions, 2.74s). Cobertura:
  - SugadoresShadowMlCommandTest: comando registrado, --company={id} dispara run, --company=all respeita config CSV via `config(['sugadores.ml_shadow_companies' => [...]])`, --company=all com config vazia aborta exit 1, --days=3 chama service 3x com datas distintas, --company=invalido (string) aborta exit 1, --company=999999 (inexistente) aborta exit 1 com mensagem pt-BR, output exibe nome da empresa + summary pt-BR.
  - SugadoresCompareProvidersCommandTest: comando registrado, falta --company aborta, falta --from/--to aborta, --format=xml aborta, --from='data-invalida' aborta, --format=table paridade 100% APROVADA exit 0, paridade 94.99% REPROVADA exit 1, paridade exata 95.0 APROVADA exit 0 (boundary inclusive), --format=json produz JSON parseavel com 7 chaves esperadas.
- **GATE DE ZERO REGRESSAO ATENDIDO**:
  - Suite Phase 40 acumulada: **52/52 verdes** (8 schema Plan 40-01 + 9 ShadowRunService Plan 40-02 + 18 ProviderComparisonService Plan 40-03 + 17 commands Plan 40-04), 173 assertions, 2.81s
  - Suite Sugador acumulada: **92/92 verdes** (75 baseline pre-40-04 + 17 novos commands = 92, 509 assertions, 29.07s)
  - Suite Phase 39: **48/48 verdes** (208 assertions, 2.45s) — refactor do analyzer e providers intactos
- **Smoke manual `php artisan list`** confirma os 2 comandos novos: `sugadores:shadow-ml` (descricao: 'Phase 40 — roda shadow mode...') + `sugadores:compare-providers` (descricao: 'Phase 40 — imprime relatorio de paridade...'). Junto com os 5 comandos existentes do namespace `sugadores:` (analyze, cleanup-quarentena, limpar-orfaos, ml-smoke, sync-adgroup-mlbs), totalizam 7 comandos.

## Task Commits

1. **Tarefa 1 (RED): Suites Feature dos 2 comandos** — `44f593c` (test) — 17 tests escritos (8 + 9) cobrindo todos os cenarios listados acima. Suite RED 17/17 failed com `CommandNotFoundException: The command "sugadores:shadow-ml" does not exist.` / `"sugadores:compare-providers" does not exist.` — confirmacao RED conforme esperado.
2. **Tarefa 2 (GREEN): Implementa 2 comandos + config + env + scheduler** — `bd85041` (feat) — 5 arquivos criados (2 commands + 1 config + 2 tests ja existiam); 2 arquivos modificados (routes/console.php + .env.example, apenas adicoes). Suite GREEN 17/17 verde (34 assertions). Suite Phase 40 acumulada 52/52 verde. Suite Sugador 92/92 verde. Suite Phase 39 48/48 verde — zero regressao.

**Plan metadata commit:** (sera criado apos este SUMMARY)

## Files Created/Modified

### Criados

- `app/Console/Commands/SugadoresShadowMl.php` — Comando Artisan (~125 linhas). Constructor: `(private ShadowRunService $shadow)`. Handle: validacao --company → resolveCompanies (helper privado) → pre-check de existencia das empresas → loop empresa×dia chamando service → summary pt-BR. PHPDoc pt-BR no topo explica REQ-40-02 e exit codes.
- `app/Console/Commands/SugadoresCompareProviders.php` — Comando Artisan (~115 linhas). Constructor: `(private ProviderComparisonService $comparison)`. Handle: 5 validacoes em cadeia (company, from, to, format, datas) → compareWindow → render por formato → exit code derivado de paridade. PHPDoc pt-BR explica boundary 95.0 inclusive e que comando e fino (consome service).
- `config/sugadores.php` — Config Laravel (24 linhas) com bloco PHPDoc pt-BR e pipeline defensivo de leitura do env CSV.
- `tests/Feature/Phase40/SugadoresShadowMlCommandTest.php` — 8 tests Feature usando RefreshDatabase + Mockery + bind `$this->app->instance(ShadowRunService::class, $mock)`. Helpers privados `makeCompany()` (cria empresa minimal) e `runReturn()` (retorno padrao do mock). tearDown chama Mockery::close().
- `tests/Feature/Phase40/SugadoresCompareProvidersCommandTest.php` — 9 tests Feature usando RefreshDatabase + Mockery + bind ProviderComparisonService. Helpers privados `report()` (monta payload com 8 chaves) e `mockComparison()` (configura mock + bind). tearDown chama Mockery::close().

### Modificados

- `routes/console.php` — APENAS 10 linhas adicionadas no fim (linhas 155-162) declarando a entry `sugadores-shadow-ml-daily`. ZERO linha modificada/removida (validado via `git diff` mostrando apenas `+` linhas, zero `-`). Comentario pt-BR no topo da entry explica: "13h BRT (1h depois do sugadores:analyze)", "Soh dispara pras empresas em SUGADORES_ML_SHADOW_COMPANIES", "NAO escreve em sugadores (gate REQ-40-02)".
- `.env.example` — APENAS 5 linhas adicionadas no fim (apos HUBSPOT_PROP_CONTACT_PHONE) declarando `SUGADORES_ML_SHADOW_COMPANIES=` com bloco PHPDoc-style em pt-BR explicando: "Vazio = scheduler diario nao roda shadow pra ninguem (use --company={id} manual)", "Ex.: SUGADORES_ML_SHADOW_COMPANIES=10,20,42".

### Nao tocados (validado via git diff)

- `app/Services/Sugadores/ShadowRunService.php` (Plan 40-02) — apenas consumido via DI
- `app/Services/Sugadores/ProviderComparisonService.php` (Plan 40-03) — apenas consumido via DI
- `app/Services/SugadorAnalysisService.php` (Phase 39) — nao tocado
- `app/Services/Sugadores/SugadoresAdsProviderFactory.php` (Phase 39)
- `app/Services/Sugadores/AdmanSugadoresProvider.php`, `MercadoLivreSugadoresProvider.php` (Phase 39)
- `app/Services/AdmanService.php`, `MercadoLivreService.php`, `MercadoLivreAdsService.php`
- `app/Jobs/AnalyzeCompanySugadoresJob.php`
- `app/Console/Commands/AnalyzeSugadores.php` (Phase 39 Plan 39-05)
- `app/Console/Commands/SugadoresMlSmoke.php` (Phase 38 Plan 38-02)
- Models 40-01 (`SugadorProviderRun`, `SugadorProviderItem`)
- Migration 40-01
- `app/Models/Sugador.php`, `SugadorConfig.php`, `SugadorAcao.php`

## Decisions Made

1. **Clamp silencioso de --days em [1,90]** — `max(1, min(90, (int) $this->option('days')))` no inicio do handle. Mitigacao T-40-04-01 sem mensagem extra: --days=0 vira 1, --days=999 vira 90. Operador percebe pelo summary final que apenas 90 dias foram processados.
2. **--company=all com config vazia ABORTA exit 1** — fail-fast com mensagem pt-BR "Nenhuma empresa elegivel. Defina --company={id} ou configure SUGADORES_ML_SHADOW_COMPANIES no .env." Operador detecta config faltando imediatamente; nao roda no-op silencioso (que poderia mascarar problema no scheduler).
3. **Empresa inexistente em --company={id} ABORTA exit 1 ANTES do loop** — `Company::find()` em pre-loop, primeira ausente para tudo. Garante atomicidade do comando: ou todas as empresas validadas rodam, ou nada roda (evita estado parcial onde empresa 10 rodou e empresa 999 falhou no meio).
4. **Boundary >= 95.0 INCLUSIVE no compare-providers** — Test 8 cobre exatamente paridade=95.0 → exit 0. Alinha com a redacao do plano-migracao §7 ("alvo >=95%") que inclui o limite. Comparacao usa `>= 95.0` (nao `> 95.0`).
5. **Output pt-BR explicito 'APROVADA'/'REPROVADA' + simbolo de comparacao** — `Paridade de motivos: 95,12% (>=95% — APROVADA)`. Operador humano nao precisa interpretar o numero contra o limite; o label fala por si.
6. **config/sugadores.php pipeline defensivo** — `explode → array_map('trim') → array_filter → array_map('intval') → array_filter → array_values`. Garante int[] limpo mesmo se env tiver `a,,10, 20 ,b` (entradas vazias, espacos, lixo nao-numerico vira 0 e e filtrado). Resultado: `[10, 20]`.
7. **Scheduler ADICIONADO no FIM de routes/console.php** — entries existentes intocadas. Validado via `git diff routes/console.php` mostrando apenas 10 linhas `+` (linhas 155-162) e zero `-`. Comentario pt-BR no topo da entry contextualiza "13h BRT (1h depois do sugadores:analyze das 12h)".
8. **Comando NAO duplica logica de compareWindow** — comando e fino, apenas valida argumentos e renderiza. Toda a logica de tolerancias §7, classificacao em 6 buckets, agregacao por janela vive no ProviderComparisonService (Plan 40-03). Manutencao centralizada.
9. **Mockery binda services no container** — `$this->app->instance(ShadowRunService::class, $mock)` / `(ProviderComparisonService::class, $mock)`. Pattern ja validado em Plan 40-02 (ShadowRunServiceTest) — comandos consomem via DI, container devolve o mock. Garante que NAO precisamos rodar a Adman/ML reais nos tests Feature do comando.

## Deviations from Plan

### Auto-fixed Issues

**Nenhuma deviacao detectada — plano executado exatamente como escrito.**

Pontos onde poderia ter havido divergencia mas seguiram o spec:

- O plan sugeria signature `--days=1 : quantos dias para trás rodar shadow` sem mencionar clamp explicito; mas o `<threat_model>` (T-40-04-01) e o `<action>` documentavam `max(1, min(90, $days))` → clamp implementado conforme threat model.
- O plan sugeria que --company=all com env vazia DEVERIA abortar com exit 1 + mensagem clara — implementado conforme spec (mensagem em pt-BR "Nenhuma empresa elegivel...").
- O plan documentava que compare-providers boundary deveria ser INCLUSIVE em 95.0 — Test 8 valida exatamente esse caso, implementacao usa `>= 95.0`.
- O acceptance_criteria pedia `git diff routes/console.php` mostrar apenas adicoes — confirmado via `git diff --stat routes/console.php` mostrando apenas `+10 -0`.

**Total deviations:** 0
**Impact on plan:** Nenhum.

## Issues Encountered

- **MariaDB local continua caido** (mesmo bloqueio conhecido das Phases 38/39/40-01/02/03). Tests rodam em SQLite em-memory via PHPUnit `RefreshDatabase`. Smoke real do comando contra MariaDB segue deferido para a mesma quick task `dev:reparar-mariadb-local`. **Nao impacta este plan** — todos os caminhos de validacao do comando + scheduler sao cobertos por tests automatizados; smoke contra MariaDB validaria apenas comportamento end-to-end com dados reais (que depende tambem do smoke ML real Phase 38-02 destravar).

## User Setup Required

**Apenas se quiser ativar o scheduler em producao** (nao bloqueia merge/deploy do codigo):

1. **No VPS, editar `.env`** — adicionar a linha:
   ```
   SUGADORES_ML_SHADOW_COMPANIES=
   ```
   Inicialmente vazio (scheduler nao rodara nada). Quando o smoke ML real Phase 38-02 destravar e o usuario decidir quais empresas piloto entram, popular com CSV de IDs.
2. **Rodar `php artisan config:cache`** apos editar .env — Laravel le config do cache em producao.
3. **Sem necessidade de reiniciar supervisor** — scheduler ja roda via `* * * * * php artisan schedule:run` (cron raiz existente).

Smoke manual (apos popular env):
```
php artisan sugadores:shadow-ml --company={id_piloto} --days=1
php artisan sugadores:compare-providers --company={id_piloto} --from=2026-06-25 --to=2026-06-25 --format=table
```

## Next Phase Readiness

- **Phase 40 INTEIRA FECHADA** — 4/4 plans entregues (Plan 40-01 schema; Plan 40-02 ShadowRunService; Plan 40-03 ProviderComparisonService; Plan 40-04 commands+scheduler).
- **REQ-40-04, REQ-40-05, REQ-40-06, REQ-40-07, REQ-40-08 fechados.**
- **Pronto para deploy** — infra de shadow mode completa: 2 tabelas auxiliares, 2 services, 2 comandos CLI, scheduler diario, env + config documentadas. Smoke real contra producao depende apenas do MariaDB local voltar + smoke ML Phase 38-02 destravar.
- **Phase 41 (Onboarding ML) destravada** — Phase 40 entrega a infra de medicao de paridade que Phase 41 vai expor visualmente (admin UI de shadow runs + tela de aprovacao "empresa pronta para primary").
- **Phase 42 (cut-over ML) destravada parcialmente** — depende ainda de Phase 41 (UI de aprovacao) E de uma decisao manual com base nos dados medidos pela Phase 40 (paridade real ≥ 95% por empresa).
- **Nenhum blocker para Phase 41/42** alem do ja documentado (MariaDB local — nao afeta planejamento nem implementacao).

## Self-Check

- Arquivo `app/Console/Commands/SugadoresShadowMl.php` existe — FOUND
- Arquivo `app/Console/Commands/SugadoresCompareProviders.php` existe — FOUND
- Arquivo `config/sugadores.php` existe — FOUND
- Arquivo `tests/Feature/Phase40/SugadoresShadowMlCommandTest.php` existe — FOUND
- Arquivo `tests/Feature/Phase40/SugadoresCompareProvidersCommandTest.php` existe — FOUND
- Commit `44f593c` (RED) presente em `git log` — FOUND
- Commit `bd85041` (GREEN) presente em `git log` — FOUND
- Suite `php artisan test --filter=SugadoresShadowMlCommandTest` retorna 8/8 PASS (12 assertions) — VERIFIED
- Suite `php artisan test --filter=SugadoresCompareProvidersCommandTest` retorna 9/9 PASS (22 assertions) — VERIFIED
- Suite `php artisan test --filter=Phase40` retorna 52/52 PASS (173 assertions) — VERIFIED
- Suite `php artisan test --filter=Sugador` retorna 92/92 PASS (509 assertions) — VERIFIED (zero regressao sobre 75 baseline)
- Suite `php artisan test --filter=Phase39` retorna 48/48 PASS (208 assertions) — VERIFIED
- `php artisan list | grep sugadores:shadow-ml` retorna a linha — VERIFIED
- `php artisan list | grep sugadores:compare-providers` retorna a linha — VERIFIED
- `php artisan schedule:list | grep shadow-ml` mostra entry agendada 0 13 * * * — VERIFIED
- `git diff routes/console.php` mostra apenas linhas `+` (10 adicoes, zero modificacoes) — VERIFIED
- `grep -c SUGADORES_ML_SHADOW_COMPANIES .env.example` retorna 2 — VERIFIED
- `php artisan tinker --execute="echo json_encode(config('sugadores.ml_shadow_companies'));"` retorna `[]` com env vazia — VERIFIED

**## Self-Check: PASSED**

---
*Phase: 40-shadow-mode-tabelas-de-compara-o*
*Completed: 2026-06-25*
