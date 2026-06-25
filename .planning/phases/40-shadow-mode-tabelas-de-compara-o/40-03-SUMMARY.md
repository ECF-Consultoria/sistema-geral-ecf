---
phase: 40-shadow-mode-tabelas-de-compara-o
plan: 03
subsystem: sugadores

tags: [phase-40, sugadores, shadow-mode, comparison, ml-migration, tdd]

requires:
  - phase: 40-shadow-mode-tabelas-de-compara-o
    plan: 01
    provides: "Models SugadorProviderRun + SugadorProviderItem com casts/relations prontos para leitura agregada"
provides:
  - "App\\Services\\Sugadores\\ProviderComparisonService::compareRuns(int admanRunId, int mlRunId): array — classifica items em 6 buckets + paridade %"
  - "App\\Services\\Sugadores\\ProviderComparisonService::compareWindow(int companyId, Carbon from, Carbon to): array — agrega items de todos os runs do periodo"
  - "Tolerancias §7 do plano-migracao-sugadores-ml-direto.md implementadas como constantes privadas (TOLERANCIA_DINHEIRO_PCT=0.01, TOLERANCIA_DINHEIRO_ABS=0.10, TOLERANCIA_PERCENTUAL_PP=0.5)"
  - "Chave canonica tipo|campaign_id|adgroup_id com fallback tipo|campaign_id|mlb:{id} (Product Ads ML)"
  - "Bucket quarentena_diff tem precedencia sobre metrics_diff/motivo_diff quando apenas um lado marca 'quarentena' em motivos"
affects: [phase-40-04-comandos-scheduler]

tech-stack:
  added: []
  patterns:
    - "Service stateless puro de leitura — sem dependencias injetadas, sem efeito colateral em DB"
    - "Indexacao por chave canonica em-memoria (array PHP) — sem JOIN cross-provider no banco"
    - "Comparacao numerica seletiva por tipo de campo (dinheiro/percentual/inteiro) — campos string/id sao ignorados na comparacao de metricas (entram apenas via chave canonica)"
    - "compareWindow usa whereBetween com datetime completo (Y-m-d H:i:s) para cobrir colunas date armazenadas como datetime em SQLite — fix Rule 1 documentado nas deviations"

key-files:
  created:
    - app/Services/Sugadores/ProviderComparisonService.php
    - tests/Feature/Phase40/ProviderComparisonServiceTest.php
  modified: []

key-decisions:
  - "Classe colocada em tests/Feature/Phase40/ (nao Unit/Phase40/) — service le Eloquent persistido via RefreshDatabase, pattern compativel com ShadowRunServiceTest do Plan 40-02. Plan frontmatter mencionava Unit/, mas o proprio <action> ja indicava dependencia de DB."
  - "Quarentena tem precedencia sobre metrics_diff e motivo_diff — se um lado marca 'quarentena' e outro nao, o bucket e sempre quarentena_diff independente do resto. Reflete a semantica de negocio: quarentena e um estado funcional distinto que ofusca qualquer outra divergencia."
  - "Campos numericos sao comparados via UNIAO das chaves dos dois arrays — qualquer campo presente em qualquer lado conta. Garante deteccao de divergencias quando um provider retorna um campo que o outro nao retorna."
  - "Campos nao-numericos (nome, ids embutidos) sao IGNORADOS na comparacao de metricas — entram apenas via chave canonica para o match. Evita falso-positivos por diferencas de string sem significado semantico."
  - "compareWindow recebe Carbon (nao string) para garantir tipagem forte no contrato publico do service."
  - "paridade_motivos_pct retornada com round(..., 2) — 2 casas decimais ja sao suficientes pro alvo ≥95%; sem necessidade de precisao maior."
  - "total_items adicionado ao retorno alem dos 6 buckets — facilita debug e display em CLI sem reaplicar a soma."

patterns-established:
  - "Service de comparacao Phase 40+: stateless, sem dependencias, le Eloquent direto, retorna array tipado pra ser consumido por comandos CLI (Plan 40-04)"
  - "Tests Feature de service de comparacao: helper makeRun(provider, date) + helper makeItem(runId, overrides) com defaults numericos representativos"

requirements-completed: [REQ-40-03]

duration: 7min
completed: 2026-06-25
---

# Phase 40 Plan 40-03: ProviderComparisonService — classifica divergencias e calcula paridade

**Service stateless `ProviderComparisonService` recebe dois runs (ou uma janela de datas) de `sugador_provider_runs` + `sugador_provider_items` e classifica cada item em 6 buckets de divergencia + calcula `paridade_motivos_pct`. Tolerancias §7 do plano-migracao implementadas como constantes privadas. Service e puro de leitura — nao toca em nenhuma tabela em escrita.**

## Performance

- **Duration:** ~7 minutos
- **Started:** 2026-06-25T20:31:01Z
- **Completed:** 2026-06-25T20:37:58Z
- **Tasks:** 2 (RED + GREEN)
- **Files created:** 2 (1 service + 1 test Feature)
- **Files modified:** 0 (zero modificacao em arquivos do "nao-tocar")

## Accomplishments

- **`ProviderComparisonService` entregue** com 2 metodos publicos (`compareRuns`, `compareWindow`) e 5 metodos privados (`compareCollections`, `classifyPair`, `keyFor`, `metricsMatch`, `isCampoNumerico`). Constructor sem dependencias — service e stateless. PHPDoc pt-BR no topo explica tolerancias §7, match key, semantica de quarentena, e que o service NUNCA grava em nenhuma tabela.
- **6 buckets classificadores implementados** conforme spec do CONTEXT.md:
  - `matched` — chave igual + metricas dentro da tolerancia + motivos iguais (sort estrito)
  - `metrics_diff` — chave igual mas metricas divergem alem da tolerancia
  - `motivo_diff` — chave igual + metricas iguais mas motivos diferentes
  - `apenas_adman` — chave so aparece em items Adman
  - `apenas_ml` — chave so aparece em items ML
  - `quarentena_diff` — apenas um lado marca 'quarentena' no campo motivos (precedencia sobre os outros)
- **Tolerancias §7 do plano canonico implementadas como constantes privadas**:
  - `TOLERANCIA_DINHEIRO_PCT = 0.01` (1%) — relativa, valida via `|a-b|/max(|a|,|b|,1) <= 0.01`
  - `TOLERANCIA_DINHEIRO_ABS = 0.10` (R$0,10) — absoluta, valida via `|a-b| <= 0.10`
  - `TOLERANCIA_PERCENTUAL_PP = 0.5` (0,5 ponto percentual em escala 0-100) — `|a-b| <= 0.5`
  - Campos de dinheiro: `investment`, `revenue`, `organic_amount`, `cpc`, `roas`
  - Campos percentuais: `acos`, `ctr`
  - Campos inteiros (igualdade estrita): `clicks`, `impressions`, `sold_quantity`, `organic_units`
  - null/null → match; null/numero → divergencia
- **Match por chave canonica com fallback MLB**: `tipo|campaign_id|adgroup_id` (default) ou `tipo|campaign_id|mlb:{mlb_id}` quando `adgroup_id` e null/vazio. Resolve o caso Product Ads ML onde nao ha equivalente direto de adgroup.
- **`compareWindow` agrega items de TODAS as runs Adman vs ML no intervalo** via 2 queries Eloquent com `whereHas('run', ...)`. Comparacao final usa o mesmo `compareCollections` privado — mesma logica de classificacao, mesmas tolerancias.
- **`paridade_motivos_pct` calculada com seguranca de divisao por zero** — quando `total_items == 0`, retorna 100.0 (zero items comparaveis significa zero divergencia detectada). Resultado arredondado para 2 casas decimais via `round(..., 2)`.
- **18/18 tests Feature verdes** (`tests/Feature/Phase40/ProviderComparisonServiceTest`, 66 assertions, 1.49s) cobrindo: 6 buckets isolados + 6 cenarios de tolerancia (dinheiro 1%, dinheiro R$0,10, dinheiro fora, percentual 0,5pp, percentual fora, inteiro estrito) + 3 edge cases (null/null, null/numero, motivos ordem diferente) + fallback MLB + paridade composta (7/10=70%) + compareWindow agregando runs em datas diferentes.
- **GATE DE ZERO REGRESSAO ATENDIDO**:
  - Suite Phase 40 total: **35/35 verdes** (8 do Plan 40-01 + 9 do Plan 40-02 + 18 do Plan 40-03), 139 assertions, 2.19s
  - Suite Sugador acumulada: **75/75 verdes** (sem regressao em relacao ao baseline Plan 40-02), 475 assertions, 41.88s
  - Suite Phase 39: **48/48 verdes** (208 assertions, 2.41s) — refactor do analyzer e providers intactos
- **Gate estatico das tolerancias confirmado via grep**: `TOLERANCIA_DINHEIRO_PCT|TOLERANCIA_DINHEIRO_ABS|TOLERANCIA_PERCENTUAL_PP` retorna **6 ocorrencias** (3 declaracoes + 3 usos) — todas as 3 constantes presentes e usadas.

## Task Commits

1. **Tarefa 1 (RED): Suite Feature ProviderComparisonService com 18 cenarios** — `9eed601` (test) — 18 tests escritos cobrindo todos os 6 buckets + 6 tolerancias + 3 edge cases + 1 fallback MLB + 1 paridade composta + 1 compareWindow. Suite RED 18/18 failed com `Class "App\Services\Sugadores\ProviderComparisonService" does not exist` — confirmacao RED conforme esperado.
2. **Tarefa 2 (GREEN): Implementa ProviderComparisonService** — `7a1ac89` (feat) — Service criado (286 linhas, PHPDoc pt-BR explicita sobre tolerancias §7 e ausencia de efeito colateral). Suite GREEN 18/18 verde (66 assertions, 1.49s). Suite Phase 40 acumulada 35/35 verde. Suite Sugador 75/75 verde. Suite Phase 39 48/48 verde — zero regressao.

**Plan metadata commit:** (sera criado apos este SUMMARY)

## Files Created/Modified

### Criados

- `app/Services/Sugadores/ProviderComparisonService.php` — Service stateless com 2 metodos publicos (`compareRuns`, `compareWindow`) e 5 privados (`compareCollections`, `classifyPair`, `keyFor`, `metricsMatch`, `isCampoNumerico`). 286 linhas. PHPDoc pt-BR no topo explica tolerancias §7, semantica de match key + fallback MLB, semantica de quarentena, e que o service e puro de leitura. Constantes privadas no inicio agrupam tolerancias e listas de campos por tipo.
- `tests/Feature/Phase40/ProviderComparisonServiceTest.php` — 18 tests Feature usando `RefreshDatabase`. Helpers privados `makeRun(provider, ?date)` cria SugadorProviderRun, `makeItem(runId, overrides)` cria SugadorProviderItem com defaults numericos representativos (`investment=100, revenue=50, clicks=10, acos=200`). Setup com `Carbon::create(2026, 6, 25)->startOfDay()` fixo para determinismo. Atributo `#[Test]` (PHPUnit 12 compatible) — sem docblocks `/** @test */`.

### Modificados

Nenhum. Zero modificacao em:

- `app/Models/Sugador.php`, `SugadorConfig.php`, `SugadorAcao.php`
- `app/Models/SugadorProviderRun.php`, `SugadorProviderItem.php` (Plan 40-01) — apenas consumidos como leitura
- `app/Services/SugadorAnalysisService.php` (Phase 39) — nao consumido
- `app/Services/Sugadores/ShadowRunService.php` (Plan 40-02) — nao consumido
- `app/Services/Sugadores/SugadoresAdsProviderFactory.php` (Phase 39)
- `app/Services/Sugadores/AdmanSugadoresProvider.php`, `MercadoLivreSugadoresProvider.php` (Phase 39)
- `app/Services/AdmanService.php`, `MercadoLivreService.php`, `MercadoLivreAdsService.php`
- `app/Jobs/AnalyzeCompanySugadoresJob.php`
- `app/Console/Commands/AnalyzeSugadores.php` (Phase 39)
- `routes/console.php` (scheduler e Plan 40-04)
- `config/sugadores.php` (criacao e Plan 40-04)
- Migrations da Phase 40-01

Validado via `git diff --name-only HEAD~2 HEAD` mostrando apenas os 2 arquivos novos acima.

## Decisions Made

1. **Classe colocada em `tests/Feature/Phase40/` (nao `Unit/Phase40/`)** — o frontmatter do PLAN mencionava `tests/Unit/Phase40/ProviderComparisonServiceTest.php` mas o proprio `<action>` da Tarefa 1 ja indicava "embora Unit, depende de Eloquent persistido". Decisao alinha o teste com o pattern ja estabelecido pelo Plan 40-02 (`ShadowRunServiceTest` em Feature) e mantem `RefreshDatabase` funcionando consistentemente. Documentado como deviation leve abaixo.
2. **Quarentena tem precedencia sobre metrics_diff e motivo_diff** — se um lado marca `'quarentena'` no campo `motivos` e outro nao, o bucket e SEMPRE `quarentena_diff` independente das metricas/motivos restantes. Reflete a semantica de negocio: quarentena e um estado funcional distinto (campanha congelada) que ofusca qualquer outra divergencia. Validado pelo Test 16 (`quarentena_em_um_lado_e_nao_no_outro_vira_quarentena_diff`).
3. **Comparacao de metricas via UNIAO das chaves dos 2 arrays** — qualquer campo presente em qualquer lado conta para a comparacao. Garante deteccao de divergencias quando um provider retorna um campo que o outro nao retorna (ex: ML retorna `organic_amount` mas Adman nao). Combinado com a logica null/numero=divergencia (Test 14), produz `metrics_diff` corretamente.
4. **Campos nao-numericos sao IGNORADOS na comparacao de metricas** — `nome`, ids embutidos, strings em geral nao entram na comparacao por tolerancia (entram apenas via chave canonica para o match). Evita falso-positivos por diferencas de string sem significado semantico (ex: Adman pode retornar `nome="CAMP X"` e ML `nome="CAMP X "` por whitespace).
5. **Fix Rule 1 — `compareWindow` usa datetime completo no `whereBetween`** — descoberto durante GREEN: SQLite armazena colunas `date` como datetime com hora 00:00:00, e `BETWEEN '2026-06-23' AND '2026-06-24'` perderia `'2026-06-24 00:00:00'` por comparacao lexicografica. Fix: formatar `Y-m-d H:i:s` em vez de `Y-m-d` (range `'2026-06-23 00:00:00'` a `'2026-06-24 23:59:59'`). Documentado em comentario inline no service.
6. **`paridade_motivos_pct` com seguranca de divisao por zero** — quando `total_items == 0`, retorna `100.0` (Test 1: dois runs vazios → 100%). Decisao: zero items comparaveis significa zero divergencia detectada, nao zero paridade. Semantica alinhada com "ainda nao detectei nada que diverge".
7. **`total_items` retornado alem dos 6 buckets** — facilita debug, display em CLI (`Plan 40-04`) e calculo independente de paridade sem reaplicar a soma. Especificado no briefing do orquestrador.
8. **`#[Test]` em vez de `/** @test */`** — pattern ja estabelecido na Phase 40-01 (decisao 5) e Phase 40-02 (decisao 9). PHPUnit 12 compatible.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocker] Pasta de tests: Feature em vez de Unit**

- **Found during:** Tarefa 1 (criacao da suite RED)
- **Issue:** O frontmatter do PLAN listava `tests/Unit/Phase40/ProviderComparisonServiceTest.php` mas a pasta `tests/Unit/Phase40/` nao existe e o proprio `<action>` da Tarefa 1 ja indicava "embora Unit, depende de Eloquent persistido". O briefing do orquestrador tambem pedia `tests/Feature/Phase40/`.
- **Fix:** Criada em `tests/Feature/Phase40/ProviderComparisonServiceTest.php` (alinhado ao pattern do Plan 40-02 `ShadowRunServiceTest.php`). Sem efeito funcional — mesmo `RefreshDatabase`, mesma cobertura, mesma execucao via `php artisan test --filter=ProviderComparisonService`.
- **Files modified:** somente o test criado em Feature/ — nenhum arquivo Unit/Phase40 criado.
- **Verification:** Suite `--filter=Phase40` mostra os 3 tests Feature (`CreateSugadorProviderTables`, `ShadowRunService`, `ProviderComparisonService`) rodando juntos consistentemente.
- **Committed in:** `9eed601` (RED).

**2. [Rule 1 - Bug] `compareWindow` perdia o ultimo dia em SQLite**

- **Found during:** Tarefa 2 (GREEN) — primeiro run da suite mostrou 17/18 passando, falha exata em `compare_window_agrega_runs_no_intervalo` com `matched=2 vs 3 esperado`.
- **Issue:** SQLite armazena colunas `date` como datetime com hora `00:00:00` (verificado via `DB::table()->pluck('reference_date')` retornando `'2026-06-24 00:00:00'`). O `whereBetween('reference_date', ['2026-06-23','2026-06-24'])` com strings apenas-data fazia o lexicografico `'2026-06-24 00:00:00' > '2026-06-24'` ser true — perdendo silenciosamente o ultimo dia do range.
- **Fix:** Trocado `->toDateString()` por `->format('Y-m-d H:i:s')` na construcao do range no `compareWindow`. Agora `whereBetween` cobre o dia inteiro de fato. Comentario inline no service documenta o porque do datetime completo.
- **Files modified:** `app/Services/Sugadores/ProviderComparisonService.php` (durante o desenvolvimento do GREEN, antes do commit).
- **Verification:** 18/18 tests verdes apos o fix. Cenario `compare_window_agrega_runs_no_intervalo` valida explicitamente que items de 2 datas distintas sao agregados corretamente.
- **Committed in:** `7a1ac89` (GREEN — fix ja incorporado).

---

**Total deviations:** 2 auto-fixed (1 Rule 3 - pasta de test; 1 Rule 1 - bug SQLite date BETWEEN)
**Impact on plan:** Sem scope creep. Fix do `compareWindow` previne bug latente que apareceria em MySQL/MariaDB se a coluna fosse alterada para datetime no futuro — agora a query e drive-agnostic. Pasta Feature/ em vez de Unit/ alinha 100%% com o pattern Phase 40-02.

## Issues Encountered

- **MariaDB local continua caido** (mesmo bloqueio conhecido das Phases 38/39/40-01/40-02). Tests rodam em SQLite em-memory via PHPUnit `RefreshDatabase`. Bug do SQLite `BETWEEN` em coluna `date` virou item de aprendizado — o fix aplicado e robusto contra ambos os drivers (SQLite + MySQL/MariaDB).

## User Setup Required

Nenhum — Plan 40-03 so cria 1 service novo (leitura pura). Sem env nova, sem rota HTTP, sem dependencia externa, sem migration. O service so sera exercido em producao quando Plan 40-04 entregar o comando `sugadores:compare-providers`. Ate la, ele existe apenas como ferramenta consumivel.

## Next Phase Readiness

- **Wave 3 do Plan 40 destravada** — Plan 40-04 (comandos `sugadores:shadow-ml` + `sugadores:compare-providers` + scheduler + env + config) pode rodar agora. Ambos os comandos vao consumir respectivamente `ShadowRunService` (Plan 40-02) e `ProviderComparisonService` (este plan).
- **REQ-40-03 fechado.**
- **Nenhum blocker para Plan 40-04** alem do ja documentado (MariaDB local — nao afeta tests automatizados).
- **Self-check:** todos os arquivos commitados existem em git (`9eed601` test, `7a1ac89` feat), suite Phase 40 35/35 verde, suite Sugador 75/75 verde (zero regressao), suite Phase 39 48/48 verde.

## Self-Check

- Arquivo `tests/Feature/Phase40/ProviderComparisonServiceTest.php` existe — FOUND
- Arquivo `app/Services/Sugadores/ProviderComparisonService.php` existe — FOUND
- Commit `9eed601` (RED) presente em `git log` — FOUND
- Commit `7a1ac89` (GREEN) presente em `git log` — FOUND
- Suite `php artisan test --filter=ProviderComparisonService` retorna 18/18 PASS (66 assertions) — VERIFIED
- Suite `php artisan test --filter=Phase40` retorna 35/35 PASS (139 assertions) — VERIFIED
- Suite `php artisan test --filter=Sugador` retorna 75/75 PASS (475 assertions) — VERIFIED
- Suite `php artisan test --filter=Phase39` retorna 48/48 PASS (208 assertions) — VERIFIED
- Grep `TOLERANCIA_DINHEIRO_PCT|TOLERANCIA_DINHEIRO_ABS|TOLERANCIA_PERCENTUAL_PP` em `ProviderComparisonService.php` retorna 6 matches (3 declaracoes + 3 usos) — VERIFIED
- Zero modificacao em arquivos do "nao-tocar" — VERIFIED via `git diff --name-only HEAD~2 HEAD`

**## Self-Check: PASSED**

---
*Phase: 40-shadow-mode-tabelas-de-compara-o*
*Completed: 2026-06-25*
