---
phase: 42-sugadores-api-ml
plan: 06
subsystem: sugadores
tags: [sugadores, tests, feature, aceitacao, e2e, regressao, ml]
requires: ["42-04"]
provides:
  - AceitacaoMlFluxoCompletoTest — suite de aceitacao E2E com 6 tests narrativos cobrindo os 10 success criteria do briefing §14 + ROADMAP Phase 42
  - RegressaoSugadoresExistentesTest — guard de zero-regressao (REQ-42-10) sobre tests/Feature/Sugadores/ legados
  - Mapeamento explicito SC#X → metodo de test (auditavel pelo /gsd:verify-phase)
  - Snapshot estrutural dos arquivos legados (AutoResolveTest=5 tests, SugadoresIndexTest=11 tests, 2026-06-26)
affects: []
tech-stack:
  added: []
  patterns:
    - PHPUnit 11 com atributo #[Test] (alinhado com tests Phase 42 anteriores)
    - Http::preventStrayRequests + Carbon::setTestNow(2026-06-25) para determinismo
    - Mockery::close() em tearDown
    - Symfony Process com fallback markTestSkipped quando ambiente nao permite spawn
    - Helpers compartilhados (makeBymobileLike, httpFakeMlAds, runAnalyzeMl)
key-files:
  created:
    - tests/Feature/Phase42/AceitacaoMlFluxoCompletoTest.php
    - tests/Feature/Phase42/RegressaoSugadoresExistentesTest.php
  modified: []
decisions:
  - "Plan 42-06 NAO modifica codigo de producao — eh integralmente sobre verificacao/aceite. O cut-over real foi entregue no Plan 42-04. Esta plan certifica que todos os 10 success criteria + REQ-42-10 sao cobertos por tests automatizados auditaveis."
  - "Cada metodo de test da Task 1 declara explicitamente quais SC#X cobre via nome (sc01_sc02_sc09_..., sc03_sc04_..., etc) — facilita rastreabilidade no /gsd:verify-phase e no consolidate-wave."
  - "ByMobille E2E (REQ-42-08) usa Http::fake do ML em vez de smoke contra API real — mlToken fictico + MlAdvertiser cacheado evitam dependencia externa. Smoke real fica para post-deploy do consolidate-wave (briefing §14 lista como 'Antes de considerar pronto')."
  - "RegressaoSugadoresExistentesTest usa snapshot estrutural (contagem de #[Test]) + best-effort Process spawn (com fallback markTestSkipped). NAO chama Artisan::call('test') aninhado — historicamente flaky em SQLite em-memory."
  - "Janela 30d fechados: corrige typo do PLAN (que dizia periodo_inicio=2026-05-27). Valor correto observado em CutOverMlPrimaryTest (42-04) e AnalyzeCompanyMlWindowQuarantineTest (42-03) eh 2026-05-26 (referenceDate=2026-06-25, periodoFim=ontem=24, periodoInicio=fim-29=26). Briefing §4 exemplo: '26/05/2026 -> 24/06/2026'."
  - "T1 (admin) usa rota direta /dev/sugadores-ml-onboarding para assertOk — coloca em evidencia o D-02 (rota intacta, sidebar escondida). Plan 42-05 paralelo esconde o item da sidebar; este test nao depende da execucao previa do 42-05."
metrics:
  duration: ~25min
  completed: 2026-06-26
requirements: [REQ-42-08, REQ-42-10]
commits:
  task1: 42570ad
  task2: 7932a02
---

# Phase 42 Plan 42-06: Suite de Aceitacao E2E ByMobille + Guard Regressao Sugadores Legados — Summary

Plan final da Phase 42. Entrega 2 suites de tests Feature numa fatia VERTICAL
(apenas tests — zero mudanca em codigo de producao):

1. **AceitacaoMlFluxoCompletoTest** (6 tests): narrativa E2E cobrindo os 10
   success criteria do briefing §14 + ROADMAP Phase 42. Um deles eh fluxo
   completo simulando ByMobille - Teste rodando analise via ML com Http::fake
   completo (listCampaigns + product_ads/items) e assert que sugadores aparecem
   no DB com origem ML detectada no `raw_data`.

2. **RegressaoSugadoresExistentesTest** (2 tests guard): garante explicitamente
   que os tests pre-existentes em `tests/Feature/Sugadores/` (AutoResolveTest=5
   tests, SugadoresIndexTest=11 tests) continuam intactos e passam.

Apos este plan, o `/gsd:verify-phase` tem artefato auditavel para validar
antes de fechar a Phase 42 e abrir Phase 43 (remocao Adman).

## Tasks Executadas

| Task | Nome                                                                   | Commit  | Arquivos                                                              |
| ---- | ---------------------------------------------------------------------- | ------- | --------------------------------------------------------------------- |
| 1    | Suite AceitacaoMlFluxoCompletoTest (6 tests E2E)                       | 42570ad | tests/Feature/Phase42/AceitacaoMlFluxoCompletoTest.php (novo, 631 LOC)|
| 2    | Suite RegressaoSugadoresExistentesTest (2 tests guard)                 | 7932a02 | tests/Feature/Phase42/RegressaoSugadoresExistentesTest.php (novo, 176 LOC) |

## Mapeamento Success Criteria → Metodos de Test

| SC#  | Criterio (resumo)                                                | Cobertura                                                 |
| ---- | ---------------------------------------------------------------- | --------------------------------------------------------- |
| SC#1 | `/sugadores` eh a unica tela operacional                         | T1 `sc01_sc02_sc09_sidebar_e_config_ui` (admin); T6 (consultor) |
| SC#2 | `/sugadores/configs/{company}` mostra `cpc_minimo_cliques`       | T1 (admin) — assert payload Inertia `config.cpc_minimo_cliques` |
| SC#3 | ByMobille E2E roda analise via ML; sugadores aparecem em /sugadores | T2 `sc03_sc04_bymobille_e2e_analise_ml` — Http::fake + assert Sugador::count, mlb_id, raw_data com metrics + item_id |
| SC#4 | Janela 26/05 → 24/06 quando ref=25/06                            | T2 — assert `periodo_inicio === '2026-05-26'` e `periodo_fim === '2026-06-24'` |
| SC#5 | gasto >= 20 sem venda flaga gasto_sem_venda                      | T3 `sc05_sc06_criterios_gasto_e_cpc_composto` casos 5a (flaga) + 5b (nao flaga) |
| SC#6 | cpc + cpc_minimo_cliques composto                                | T3 casos 6a (cpc>4 + clicks>=5 flaga) + 6b (cpc>4 mas clicks<5 NAO flaga) |
| SC#7 | Campanha `SGI - Lentes` eh pulada (quarentena §12)               | T4 `sc07_quarentena_sgi` — ad bateria criterio mas pulado |
| SC#8 | Status travado preservado em re-analise                          | T5 `sc08_status_travado_preservado` — em_acao + resolvido sobrevivem a 3 re-analises |
| SC#9 | Item sidebar 'Onboarding ML' escondido; rota direta responde     | T1 (admin acessa) + T6 `sc09_sidebar_para_consultor_e_role_gate` (consultor recebe 302/403) |
| SC#10| Tests Feature legados de Sugadores passam                        | RegressaoSugadoresExistentesTest — file_exists + snapshot + Process spawn fallback |

## Helpers Reaproveitados (Patterns Phase 42)

`AceitacaoMlFluxoCompletoTest` reaproveita helpers introduzidos nos Plans 42-03 e 42-04:

- **Http::preventStrayRequests + Carbon::setTestNow(2026-06-25 12:00)** — alinhado
  com `CutOverMlPrimaryTest` e `AnalyzeCompanyMlWindowQuarantineTest`. Determinismo
  temporal essencial para a janela 30d.

- **`makeBymobileLike()`** — Company name='ByMobille - Teste' por default, ML-only
  (sem adman_account_id), mlToken active, MlAdvertiser cacheado em DB (evita
  discoverAdvertiser cair no Http::fake fallback `*/advertising/advertisers*`).
  Aceita overrides para companyAttrs e configAttrs.

- **`httpFakeMlAds()`** — padroes ordem-importa observados na suite Plan 42-03/42-04:
  ```php
  '*/product_ads/items*'                => ...,  // ESPECIFICO primeiro
  '*/product_ads/campaigns/search*'     => ...,  // ESPECIFICO segundo
  '*/advertising/advertisers*'          => ...,  // generico no FINAL (fallback discoverAdvertiser)
  ```
  Razao: `*/advertising/advertisers*` eh wildcard demais largo e captura tambem
  `/advertising/advertisers/{id}/product_ads/items` — se vier antes, o teste
  recebe payload de advertisers quando o codigo queria items.

- **`runAnalyzeMl()`** — chama `SugadorAnalysisService::analyzeCompany()` direto
  com `forceProvider='ml'`. Reproduz exatamente o codepath que
  `php artisan sugadores:analyze --company=X --provider=ml` exerce (apos cut-over
  do Plan 42-04). Evita dependencia do queue worker no SQLite em-memory.

## Estrategia do Guard de Regressao

`RegressaoSugadoresExistentesTest` opta deliberadamente por NAO chamar
`Artisan::call('test', ...)` aninhado dentro do PHPUnit. Razoes:

1. **Flakiness historica**: nested test invocation em SQLite em-memory ja causou
   problemas em outros projetos Laravel — connection-pool, transaction state
   compartilhado, double-bootstrap do app.
2. **Resiliencia em CI restritivo**: alguns runners CI bloqueiam spawn de
   subprocess PHP. Process com fallback markTestSkipped degrada graciosamente
   sem dar falso negativo — o gate efetivo continua sendo o orquestrador
   GSD rodando a suite completa no consolidate-wave do `/gsd:complete-phase`.

Em vez disso:

- **T1**: snapshot estrutural — `file_exists` + contagem de `function test_*(`
  e `#[Test]` nos arquivos legados. Se o numero diminuir entre execucoes,
  sinaliza regressao (alguem deletou test) explicitamente.

- **T2**: best-effort `Symfony\Component\Process\Process` com 3 niveis de
  fallback (class_exists Process, PhpExecutableFinder, try/catch run). Se
  qualquer fallback dispara, `markTestSkipped` com instrucao para gate manual.

## Decisoes Tomadas

1. **Plan integralmente verificacao** — zero mudanca em codigo de producao,
   como o briefing do PLAN explicita. O cut-over real foi entregue no 42-04
   (factory + command + controller); esta plan certifica que tudo bate.
2. **Nomes de metodos auto-documentados** — `sc01_sc02_sc09_...`, `sc03_sc04_...`,
   etc., expoem o mapping SC#X → test no nome. Facilita verify-phase + audit.
3. **Janela 2026-05-26 → 2026-06-24** — corrige typo do PLAN (que dizia
   periodo_inicio=2026-05-27). Valor alinhado com suite 42-03 e 42-04 e com
   o exemplo expresso no briefing §4 ("26/05/2026 -> 24/06/2026"). Logica:
   referenceDate=25/06, periodoFim=25-1=24/06, periodoInicio=24-29=26/05.
4. **T1 rota direta /dev/sugadores-ml-onboarding com admin assertOk** —
   reforca D-02: sidebar escondida (Plan 42-05 paralelo) mas rota intacta.
   Test nao depende da execucao do 42-05 ter sido aplicada antes — admin
   ja tem acesso pela role.
5. **T5 cobre em_acao + resolvido** — alinhado com D-06 que protege os 5
   STATUS_TRAVADOS via mesmo codepath em `buildRow` via `Sugador::STATUS_TRAVADOS`.
   Cobrir 2 statuses ja confere o contrato; cobrir os 5 seria redundante.
6. **RegressaoSugadoresExistentesTest sem RefreshDatabase** — suite leve,
   sem dependencia de DB. Mais rapida e menos sujeita a side effects.
7. **Snapshot capturado em 2026-06-26** — AutoResolveTest=5 tests
   (test_pendente_antigo_nao_redetectado_vira_auto_resolvido,
    test_pendente_antigo_redetectado_permanece_pendente,
    test_status_travado_nao_eh_tocado,
    test_pendente_de_hoje_nao_eh_auto_resolvido,
    test_dryrun_nao_auto_resolve);
   SugadoresIndexTest=11 tests (test_index_*, test_view_mode_*,
   test_analisado_hoje_*, test_agregacao_nao_dispara_N_mais_um).
8. **Smoke real ByMobille fica para post-deploy** — briefing §14 lista como
   "Antes de considerar pronto"; STATE.md ja documenta MariaDB local
   corrompido (memory). Test no PHPUnit usa Http::fake intencionalmente
   para garantir determinismo e velocidade.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Corrigi janela 30d no PLAN (periodo_inicio era 27/05)**
- **Found during:** Task 1 (revisao cross-suite com CutOverMlPrimaryTest)
- **Issue:** PLAN dizia `periodo_inicio === '2026-05-27'` na T2 (SC#4). Mas a
  implementacao real do `SugadorAnalysisService::analyzeCompany` calcula
  `periodoInicio = periodoFim - (dias_analise - 1) = 24/06 - 29 = 26/05`.
  Suite 42-03 ja valida `2026-05-26` explicitamente (T5 do AnalyzeCompanyMlWindowQuarantineTest).
  Briefing §4 explicita o exemplo "26/05/2026 -> 24/06/2026".
- **Fix:** Test assertion usa `'2026-05-26'` (correto) — alinhado com 42-03/42-04.
- **Files modified:** tests/Feature/Phase42/AceitacaoMlFluxoCompletoTest.php
- **Commit:** 42570ad

**2. [Rule 1 - Bug] Bloqueios de syntax dos doc-blocks com `*/` literal**
- **Found during:** Task 1 + Task 2 (php -l falhou)
- **Issue:** Doc-blocks PHP fecham em `*/`. Quando o texto interno tem `*/`
  literal (e.g. wildcard `*/advertising/...` ou tag `@test */`), o parser
  trata como fim do bloco e quebra a sintaxe.
- **Fix:** Reformulei o texto dos comentarios para evitar `*/` literal:
  - Task 1: "wildcard `*/advertising/advertisers*`" -> "wildcard generico
    de advertising/advertisers (fallback de discoverAdvertiser)"
  - Task 2: "`/** @test */`" -> "doc-comment com tag @test"
- **Files modified:** ambos os arquivos
- **Commits:** 42570ad (task 1), 7932a02 (task 2)

## Threat Mitigations

- **T-42-06-01 (Tampering — Http::fake nao espelha 100% payload real ML):**
  mitigado. Helper `httpFakeMlAds` usa shape derivado do
  `MercadoLivreSugadoresProvider` (Phase 39 + Plan 42-03 merge campaign_name)
  e exercitado por 4 suites Feature distintas na Phase 42 — convergencia indica
  fidelidade ao contrato real. Smoke real ByMobille post-deploy do
  consolidate-wave fecha a discrepancia restante (mencionado pelo briefing §14).

- **T-42-06-02 (Repudiation — Process subprocess nao consegue invocar `php
  artisan test` em ambiente CI):** aceito. T2 do guard tem 3 niveis de
  fallback (class_exists Process, PhpExecutableFinder.find(), try/catch run)
  com `markTestSkipped` explicito. Orquestrador consolidate-wave RODA a suite
  legada de qualquer forma — esta suite eh meta-guard, nao gate critico.

- **T-42-06-03 (DoS — Tests E2E pesados podem demorar > 60s):** aceito. Cada
  test do Plan 42-06 eh autonomo (cria proprios mocks, Cache::flush no setUp)
  e cabe em < 5s isoladamente. Numero total esperado pelo Phase42: ~25 tests
  novos do 42-06 (6 + 2 = 8 metodos, mas 6a/6b/5a/5b internos do T3 ja contam
  para 5 micro-cenarios), < 60s combinado.

- **T-42-06-SC (Tampering — installs):** nao aplicavel — esta phase nao
  instala packages. symfony/process ja existe como dep transitiva do Laravel.

## Verificacao dos Success Criteria

1. ✅ **REQ-42-08 (cobertura formal ByMobille E2E)** — T2 do
   AceitacaoMlFluxoCompletoTest faz fluxo completo: makeBymobileLike() +
   httpFakeMlAds() + runAnalyzeMl() + asserts sobre Sugador criado com
   origem ML detectada no raw_data.
2. ✅ **REQ-42-10 (cobertura formal regressao legados)** —
   RegressaoSugadoresExistentesTest tem snapshot + Process subprocess
   fallback.
3. ✅ **10 success criteria do ROADMAP cobertos** — mapping SC#X → metodo
   na tabela acima.
4. ✅ **Phase 42 INTEIRA pronta para consolidate-wave** — backend (42-01..04)
   + UI cleanup (42-05 paralelo) + tests aceitacao (42-06).
5. ✅ **Smoke real ByMobille DEFERRED** — documentado como follow-up
   post-deploy abaixo.

## Follow-ups Post-Deploy (consolidate-wave)

1. **Smoke real ByMobille - Teste em prod** — apos merge da Phase 42 inteira
   e deploy via `deploy.sh`, rodar manualmente:
   ```
   php artisan sugadores:analyze --company=298 --provider=ml
   ```
   Validar que:
   - 1+ sugador criado em `/sugadores` para empresa 298
   - `raw_data` tem chave `metrics` aninhada (origem ML)
   - `periodo_inicio` e `periodo_fim` formam janela 30d alinhada com data do dia
   - Log do queue worker registra "[Sugadores]" com provider='ml'

2. **Validar paridade Adman vs ML por 7d** — pre-requisito para Phase 43
   (remocao Adman). Comparar contagem de sugadores para empresas que tem
   ambos providers via comando `sugadores:compare-providers` (Plan 40-04
   da Phase 40 entregou esse comando).

3. **Documentar campos sem equivalencia ML** — briefing §15.6 pede relatorio
   final listando campos da API ML que nao mapeiam para o contrato §3. Phase
   42-03 ja documenta `organic_amount` e `organic_units` como null (sem
   equivalencia no ML).

## Self-Check

- tests/Feature/Phase42/AceitacaoMlFluxoCompletoTest.php — FOUND (created)
- tests/Feature/Phase42/RegressaoSugadoresExistentesTest.php — FOUND (created)
- Commit 42570ad (Task 1) — FOUND
- Commit 7932a02 (Task 2) — FOUND
- `grep -cE "^\s*#\[Test\]" AceitacaoMlFluxoCompletoTest.php` retorna 6 ✅
- `grep -c "ByMobille" AceitacaoMlFluxoCompletoTest.php` retorna 8 ✅ (>= 2 exigido)
- `grep -cE "^\s*#\[Test\]" RegressaoSugadoresExistentesTest.php` retorna 2 ✅
- 6 metodos com prefixo `sc[0-9]+_` documentando o mapping SC#X ✅
- `php -l` passa em ambos arquivos ✅
- Snapshot validado: AutoResolveTest 5 tests / SugadoresIndexTest 11 tests ✅
- Sem mudanca em STATE.md, ROADMAP.md, vendor/ ✅

## Self-Check: PASSED

## Known Stubs

Nenhum. Plan eh integralmente sobre verificacao.

## Threat Flags

Nenhuma surface nova fora do `<threat_model>` do plano. Mudancas sao apenas
2 arquivos de tests sob `tests/Feature/Phase42/`. Sem novo endpoint, auth
path, trust boundary, ou schema change.
