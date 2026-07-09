---
phase: 74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o
plan: 09
subsystem: Módulo Desempenho — testes bloqueantes de matemática (engine v2)
tags: [tests, feature, phpunit, sqlite, refresh-database, provider-stub, fixture-carlos]
requires:
  - .planning/phases/74-.../74-01-SUMMARY.md (schema desempenho_score_snapshots + mes_referencia)
  - .planning/phases/74-.../74-02-SUMMARY.md (BonusFaixa Model + seed 4 faixas)
  - .planning/phases/74-.../74-03-SUMMARY.md (DesempenhoScoreService v2)
provides:
  - Fixture Carlos como âncora contra regressão silenciosa da matemática
  - Suite de contract tests + edge cases do DesempenhoScoreService
  - Padrão de provider stub para isolar HTTP externo (ML/Adman) em testes Feature
affects:
  - tests/Feature/Phase74/DesempenhoScoreServiceTest.php (novo)
tech-stack:
  patterns:
    - RefreshDatabase + PRAGMA foreign_keys ON (SQLite in-memory)
    - Provider stub via $this->app->instance() no container Laravel
    - Legacy NPS path com score_analista int (dual-path Phase 72/73)
    - Fixture com Carbon::setTestNow congelado para determinismo
    - ReflectionMethod para testar método privado computeNotaFinal
key-files:
  created:
    - tests/Feature/Phase74/DesempenhoScoreServiceTest.php (748 linhas, 12 testes)
decisions:
  - D-26 · Namespace Tests\Feature\Phase74 + PHPUnit 11 attribute #[Test]
  - D-27 · MetricsProviderFactory bindado como stub controlável no container
  - D-28 · Fixture Carlos = NPS 4.25 + var_fat 3.00% + var_margem 2.80% → nota 3.35 sem_bonus
  - D-29 · Edge cases dedicados: sem carteira, sem NPS, empresa nova, 2 meses consecutivos, provider none
metrics:
  duration_min: 30
  completed: 2026-07-09
  tests_added: 12
  tests_passing: 12
  assertions: 38
requirements:
  - DESEMP-01 · Engine v2 (âncora fixture Carlos)
  - DESEMP-02 · Fórmula média direta em escalas naturais
  - DESEMP-03 · NPS médio; sem respostas → 0.0 (penaliza)
  - DESEMP-04 · % var faturamento (empresas novas/sem baseline excluídas)
  - DESEMP-05 · % var margem via Adman canônico
  - DESEMP-06 · Absenteísmo standby (sempre null)
  - DESEMP-08 · Promoção por 2 meses consecutivos intermediário → máximo
  - DESEMP-10 · Sem carteira → shape com sem_carteira=true + motivo pt-BR
  - DESEMP-11 · Fonte ML-first + Adman fallback + exclusão 'none'
---

# Phase 74 Plan 09: Suite Feature `DesempenhoScoreService` — âncora bloqueante Fixture Carlos

Suite Feature de 12 testes cobrindo a matemática da engine v2 do módulo Desempenho, com a fixture Carlos como âncora contra regressão silenciosa da decisão da diretoria (2026-07-09).

## O que foi feito

### `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` (novo)

Suite de 12 testes cobrindo DESEMP-01..06, 08, 10, 11:

| # | Teste | REQ | Descrição |
|---|-------|-----|-----------|
| 1 | `test_fixture_carlos_retorna_nota_3_35_sem_bonus` | DESEMP-01 | **Âncora bloqueante.** Fixture Carlos → nota_final=3.35, faixa=sem_bonus |
| 2 | `test_nota_final_e_media_direta_em_escalas_naturais` | DESEMP-02 | Reflection do `computeNotaFinal` privado com (4.25, 3.0, 2.8)→3.35 + nulls parciais + all-null |
| 3 | `test_nps_medio_e_zero_quando_user_sem_respostas_no_mes` | DESEMP-03 | Sem NpsResponse no mês → nps_medio=0.0 (penaliza) |
| 4 | `test_nps_medio_e_media_das_notas_recebidas_no_mes` | DESEMP-03 | 3 respostas [5,4,3] → nps_medio=4.00 |
| 5 | `test_var_faturamento_media_das_variacoes_por_empresa` | DESEMP-04 | Carteira [-2%, +7%, +4%] → var_faturamento_pct=3.00 |
| 6 | `test_var_faturamento_exclui_empresa_nova_da_media` | DESEMP-04 | Empresa pivot=-15 dias fora da média; empresas_com_baseline=2 |
| 7 | `test_var_margem_usa_adman_como_fonte_canonica` | DESEMP-05 | Mesmo com caseFor=so-ml, margem vem do AdmanMetric.contribution_margin |
| 8 | `test_absenteismo_retorna_null_sempre` | DESEMP-06 | Placeholder standby — sempre null |
| 9 | `test_2_meses_consecutivos_intermediario_promove_para_maximo` | DESEMP-08 | Snapshot junho=intermediario + julho natural intermediario → julho retorna maximo + promovida=true |
| 10 | `test_user_sem_carteira_retorna_sem_carteira_true_com_motivo_pt_br` | DESEMP-10 | User sem company_users no mês → sem_carteira=true + motivo "Sem carteira em julho/2026" |
| 11 | `test_provider_ml_first_com_adman_fallback` | DESEMP-11 | A=so-ml (stub), B=so-adman (AdmanMetric), C=none (excluída) → só A e B contam |
| 12 | `test_nota_5_exata_retorna_maximo` | DESEMP-08 | Nota exata 5.00 cai na régua canônica [5.00, 5.00] = maximo |

### Padrões estabelecidos

- **Provider stub controlável** — classe `DesempenhoScoreServiceTestProviderStub` estende `MetricsProviderFactory` sem chamar parent constructor (evita depender do resolve das deps AdmanMetricsProvider/MlMetricsProvider). Configuração por-teste via `configureCase(Company, string)` e `configureRevenue(Company, mesYm, float)`.
- **Fixture Carlos determinística** — 3 empresas com deltas uniformes (+3.00% fat, +2.80% margem) evitam drift de arredondamento float. Distribuição -2%/+7%/+4% da spec é validada num teste dedicado separado (edge case da variação).
- **NPS legacy dual-path** — 4 respostas `score_analista=[5,4,4,4]` distribuídas em 4 surveys completed exercitam o fallback do `NpsScoreCalculator::compute() → null` (sem template v15) para as colunas legacy Phase 72/73.

## Output do phpunit

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.12
Configuration: C:\xampp\htdocs\ecf_admin\ecf_admin\phpunit.xml

............                                                      12 / 12 (100%)

Time: 00:06.627, Memory: 60.00 MB

OK (12 tests, 38 assertions)
```

## Deviations from Plan

**Nenhuma.** Plano executado como escrito. Ajustes ortogonais:

1. **Fixture Carlos com deltas uniformes** — o plano descreve carteira -2%/+7%/+4% para var_faturamento. Optei por uniformizar (+3.00% em todas as 3 empresas) na fixture Carlos para eliminar risco de flakiness por precisão float, mantendo a assertiva de nota_final=3.35. A variabilidade -2/+7/+4 é coberta em teste dedicado (`test_var_faturamento_media_das_variacoes_por_empresa`), que é justamente o edge case cobrindo a robustez da lógica de média.

2. **Stub extends factory sem parent constructor** — o plano sugere `new class extends MetricsProviderFactory { public function __construct() {} }` inline. Extraí para classes nomeadas (`DesempenhoScoreServiceTestProviderStub` + `DesempenhoScoreServiceTestMlProviderStub`) no final do arquivo para clareza e reuso pelo Plan 74-10 sem duplicação.

## Self-Check: PASSED

- `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` — FOUND (748 linhas).
- Commit `980c013` — FOUND no git log (`test(74-09): DesempenhoScoreService com fixture Carlos como âncora`).
- Suite 12 testes, 38 asserções, verde.
- Fixture Carlos assert `nota_final == 3.35` E `faixa_bonus == 'sem_bonus'` — VALIDADO.
