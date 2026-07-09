---
phase: 74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o
plan: 10
subsystem: Módulo Desempenho — testes bloqueantes de config admin + comando mensal + regressão
tags: [tests, feature, phpunit, sqlite, refresh-database, inertia, artisan, regression]
requires:
  - .planning/phases/74-.../74-01-SUMMARY.md (schema desempenho_score_snapshots + mes_referencia)
  - .planning/phases/74-.../74-02-SUMMARY.md (BonusFaixa Model + seed 4 faixas)
  - .planning/phases/74-.../74-03-SUMMARY.md (DesempenhoScoreService v2)
  - .planning/phases/74-.../74-04-SUMMARY.md (comando ConsolidarMesDesempenho + SnapshotDesempenhoScores reescrito)
  - .planning/phases/74-.../74-05-SUMMARY.md (DesempenhoConfigController + UpdateBonusFaixaRequest)
  - .planning/phases/74-.../74-09-SUMMARY.md (DesempenhoScoreService suite âncora)
provides:
  - Suite Feature RBAC + validação da UI admin de faixas de bônus
  - Suite Feature do comando mensal + idempotência + skip sem carteira + ranking mensal
  - Adaptações mínimas em 4 suites legadas ao shape v2 — zero regressão
affects:
  - tests/Feature/Phase74/DesempenhoConfigControllerTest.php (novo)
  - tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php (novo)
  - tests/Feature/DesempenhoScoreSnapshotTest.php (adaptado ao shape v2)
  - tests/Feature/DesempenhoEvolucaoTest.php (adaptado ao shape v2 + escala 0-5 dos deltas)
  - tests/Feature/PerformanceCargoFilterTest.php (helper agora attach Company para user passar DESEMP-10)
  - tests/Feature/Portfolio/RenderPortfolioTest.php (teste 7 reescrito ao shape v2)
tech-stack:
  patterns:
    - RefreshDatabase + PRAGMA foreign_keys ON (SQLite in-memory)
    - AssertableInertia para inspeção de props Inertia
    - Provider stub via require_once do arquivo do Plan 74-09 (reuso sem duplicação)
    - assertSessionHasErrors + session('errors')->first() para validar erros pt-BR de FormRequest
    - actingAs + patch + assertRedirect + assertSessionHas('success') para happy paths
    - artisan('comando', ['--flag' => ...])->assertSuccessful() para comandos Artisan
key-files:
  created:
    - tests/Feature/Phase74/DesempenhoConfigControllerTest.php (11 testes, 358 linhas)
    - tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php (7 testes, 353 linhas)
  modified:
    - tests/Feature/DesempenhoScoreSnapshotTest.php (fake bind + payloadFake ao shape v2)
    - tests/Feature/DesempenhoEvolucaoTest.php (fake bind + escala 0-5 dos deltas)
    - tests/Feature/PerformanceCargoFilterTest.php (helper attach Company para DESEMP-10)
    - tests/Feature/Portfolio/RenderPortfolioTest.php (teste 7 shape v2)
decisions:
  - D-26 · Namespace Tests\Feature\Phase74 + PHPUnit 11 attribute #[Test]
  - D-11 · Controller admin com 3 endpoints (index, updateFaixa, toggleActive)
  - D-13 · Validação sobreposição via withValidator + regra `slug=maximo` aceita [5,5]
  - D-08 · Comando mensal grava mes_referencia + comando diário grava NULL — coexistem
  - D-09 · Comando aceita --mes=YYYY-MM para reprocessamento (catch-up)
metrics:
  duration_min: 55
  completed: 2026-07-09
  tests_added_new: 18       # 11 controller + 7 command
  tests_adapted_regression: 32
  tests_total_run: 50
  assertions_total: 310
requirements:
  - DESEMP-09 · Frequência mensal fechada + idempotência do comando
  - DESEMP-10 · Sem carteira → snapshot NÃO gravado (mensal e diário)
  - DESEMP-12 · Rota admin /desempenho/configuracao + validação de sobreposição + toggle-active
---

# Phase 74 Plan 10: Suite Feature admin config + comando mensal + regressão zero

Suite Feature bloqueante em 3 tarefas:

1. **DesempenhoConfigControllerTest** — 11 testes cobrindo RBAC, validações e toggle-active da UI admin de faixas.
2. **ConsolidarMesDesempenhoCommandTest** — 7 testes cobrindo comando mensal + idempotência + skip sem carteira + ranking + preservação do diário.
3. **Regressão zero** — 4 suites Feature legadas adaptadas MINIMAMENTE ao shape v2 (imports, fake bind, escala 0-5 dos deltas).

## O que foi feito

### `tests/Feature/Phase74/DesempenhoConfigControllerTest.php` (novo — 11 testes)

| # | Teste | REQ | Descrição |
|---|-------|-----|-----------|
| 1 | `test_get_configuracao_como_analista_retorna_403` | DESEMP-12 | Guard role:admin bloqueia analista |
| 2 | `test_get_configuracao_como_estrategista_retorna_403` | DESEMP-12 | Guard role:admin bloqueia estrategista |
| 3 | `test_get_configuracao_como_admin_retorna_200_com_faixas_seed` | DESEMP-12 | Admin recebe 200 + 4 faixas seed nas props Inertia |
| 4 | `test_patch_faixa_atualiza_nota_min_e_nota_max_com_sucesso` | DESEMP-12 | Happy path: intermediario passa de [4.50, 4.99] para [4.60, 4.99] |
| 5 | `test_patch_faixa_rejeita_nota_min_maior_que_nota_max` | DESEMP-12 | Regra `gte:nota_min` bloqueia payload invertido |
| 6 | `test_patch_faixa_permite_igualdade_para_slug_maximo` | DESEMP-12 (D-13) | Faixa `maximo` aceita [5.00, 5.00] (invariante do seed) |
| 7 | `test_patch_faixa_rejeita_range_fora_de_0_5` | DESEMP-12 | Regra `between:0,5` — bloqueia -1 e 6 |
| 8 | `test_validacao_sobreposicao_de_faixas_ativas` | DESEMP-12 (D-13) | basico expandindo para [4.00, 4.60] invade intermediario → erro "Sobreposição com a faixa" |
| 9 | `test_toggle_active_alterna_ativo_true_false` | DESEMP-12 | Toggle preserva row (histórico intacto) |
| 10 | `test_toggle_active_como_analista_retorna_403` | DESEMP-12 | Guard middleware protege toggle-active |
| 11 | `test_faixa_desativada_nao_participa_de_validacao_sobreposicao` | DESEMP-12 (D-13) | Faixa inativa fica fora do check de sobreposição |

### `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php` (novo — 7 testes)

| # | Teste | REQ | Descrição |
|---|-------|-----|-----------|
| 1 | `test_comando_grava_snapshot_com_mes_referencia_do_mes_anterior_quando_sem_flag` | DESEMP-09 | Sem --mes, Carbon congelado em 2026-08-01 → mes_referencia = 2026-07-01 |
| 2 | `test_comando_aceita_mes_flag_yyyy_mm` | DESEMP-09 | --mes=2026-06 força reprocessamento |
| 3 | `test_idempotencia_do_command_consolidar_mes` | DESEMP-09 | 2 execuções consecutivas → 1 row (updateOrCreate) |
| 4 | `test_comando_pula_user_sem_carteira_no_mes` | DESEMP-10 | User sem company_users → snapshot não gravado |
| 5 | `test_comando_popular_ranking_pos_por_mes_referencia` | DESEMP-09 | Ranking populado por (mes_referencia, score DESC) |
| 6 | `test_command_snapshot_diario_preservado_grava_mes_referencia_null` | D-02 | Comando diário mantém mes_referencia=NULL — coexiste com mensal |
| 7 | `test_command_snapshot_diario_pula_user_sem_carteira` | DESEMP-10 | DESEMP-10 vale também para o diário |

**Padrão de reuso** — o `ConsolidarMesDesempenhoCommandTest` faz `require_once` do arquivo do Plan 74-09 para reutilizar o provider stub, evitando duplicação da lógica de mock.

### Regressão zero — 4 suites Feature legadas adaptadas

| Suite | Mudança minimal | Motivo |
|-------|-----------------|--------|
| `DesempenhoScoreSnapshotTest` | Import + fake bind + payloadFake reescrito ao shape v2. Unique constraint agora testa `(user_id, ref_date, mes_referencia)` | Phase 74 D-05/D-06 apagou `PortfolioScoreService`; D-03 substituiu o unique key. |
| `DesempenhoEvolucaoTest` | Import + fake bind + compute assinatura `(User, Carbon)`. Deltas agora expressos em escala 0-5 (nota_final) em vez de 0-100 (score legado) | Controller v2 divide o score do snapshot por 20 antes de calcular delta contra nota_final v2. |
| `PerformanceCargoFilterTest` | Helper `criarUserComCargo` agora attach Company via `company_users` | DESEMP-10 removeria os users do ranking sem carteira; teste ficaria vazio. |
| `Portfolio/RenderPortfolioTest` | Teste 7 renomeado + reescrito: assert que shape v2 NÃO tem chave legada `metricas` e TEM `componentes/nota_final/faixa_bonus` | Concept `atingimento_meta.origem` do v1 foi apagado — teste preserva o intent original (verificar que legacy path foi removido). |

## Output do phpunit

### Phase 74 (30 testes verdes)

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.12
Configuration: C:\xampp\htdocs\ecf_admin\ecf_admin\phpunit.xml

..............................                                    30 / 30 (100%)

Time: 00:13.917, Memory: 72.00 MB

OK (30 tests, 112 assertions)
```

### Regressão (32 testes verdes — 4 suites adaptadas)

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
--filter="DesempenhoScoreSnapshotTest|DesempenhoEvolucaoTest|PerformanceCargoFilterTest|RenderPortfolioTest"

................................                                  32 / 32 (100%)

Time: 00:57.622, Memory: 90.00 MB

OK (32 tests, 198 assertions)
```

## Deviations from Plan

**Nenhuma deviation crítica.** Ajustes cirúrgicos documentados:

1. **[Rule 3 - Blocking] Fix bind de fake em suites legadas** — 3 suites (`DesempenhoScoreSnapshotTest`, `DesempenhoEvolucaoTest`, `Portfolio/RenderPortfolioTest`) importavam `App\Services\PortfolioScoreService`, que foi APAGADA em Plan 74-03 (D-05/D-06). Sem esta correção, as suites daria classe-não-encontrada em runtime. Adaptação minimal: rename import + fake bind + shape do compute (v1 → v2). Não deleto testes; preservo o INTENT das assertions e traduzo para o novo shape.

2. **[Rule 3 - Blocking] Fix helper de user em PerformanceCargoFilterTest** — a suite não bindava `PortfolioScoreService` (falha diferente), mas o `criarUserComCargo` não attach carteira. Como o controller agora remove `sem_carteira=true` do ranking (DESEMP-10), o teste ficava com ranking vazio. Adição minimal: attach 1 empresa via pivot com pivot `created_at=-3 months` (fora do filtro "empresa nova" do DESEMP-04).

3. **Escala dos deltas em DesempenhoEvolucaoTest** — as assertions antigas comparavam score 0-100 (v1). Controller v2 agora divide por 20 antes de computar delta (retorna nota 0-5). Atualizei os valores esperados de forma minimal (5.0 → 0.25, 20.0 → 1.0, -10.0 → -0.5, 10.0 → 0.5) preservando o intent.

## Deferred Issues

**`PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200` (1 falha pré-existente)**

- Ao rodar o smoke broader `--filter="Desempenho|Performance"`, este teste falha com 403 quando esperado 200.
- **Não é regressão da Phase 74** — validei via `git stash` que já falha na main sem minhas mudanças.
- Causa provável: permission `mlb.dashboard` wiring do Setor "Publicação" — fora do escopo do módulo Desempenho.
- Registrado como pré-existente para eventual quick task futura (não bloqueia Phase 74).

## Self-Check: PASSED

- `tests/Feature/Phase74/DesempenhoConfigControllerTest.php` — FOUND (11 testes)
- `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php` — FOUND (7 testes)
- `tests/Feature/DesempenhoScoreSnapshotTest.php` — MODIFIED (8 testes verdes, adaptado)
- `tests/Feature/DesempenhoEvolucaoTest.php` — MODIFIED (11 testes verdes, adaptado)
- `tests/Feature/PerformanceCargoFilterTest.php` — MODIFIED (6 testes verdes, adaptado)
- `tests/Feature/Portfolio/RenderPortfolioTest.php` — MODIFIED (7 testes verdes, adaptado)
- Commit `24134c0` — FOUND (test(74-10): DesempenhoConfigController + ConsolidarMesDesempenho command)
- Commit `096d72f` — FOUND (test(74-10): adapta 4 suites Feature legadas ao shape v2)
- Suite Phase74 completa: 30 testes verdes, 112 asserções
- Regressão: 32 testes verdes, 198 asserções
- Fixture Carlos assert `nota_final == 3.35` E `faixa_bonus == 'sem_bonus'` — VALIDADO (Plan 74-09)
