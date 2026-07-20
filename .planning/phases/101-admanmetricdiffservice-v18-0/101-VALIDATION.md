# Fase 101 — Validation Architecture

> Extraído de 101-RESEARCH.md (gate Nyquist 8e).

## Validation Architecture

### Test Framework
| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (testsuites `Unit` e `Feature`, diretórios `tests/Unit`/`tests/Feature`) |
| Quick run command | `C:\xampp\php\php.exe artisan test --filter=AdmanMetricDiffServiceTest` |
| Full suite command | `C:\xampp\php\php.exe artisan test` |

### Phase Requirements → Test Map
| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|--------|----------------|----------------|------------------------|------------------|
| ADM-01 | Lê `revenue`/`profitMargin.value/.diff` de `/performance` e `percentageMargin.value/.diff` de `/accounts/metrics` | Feature (`Http::fake`) | `php artisan test --filter=test_le_revenue_profitmargin_e_percentagemargin` | ❌ Wave 0 |
| ADM-02 | `diff_source='adman_diff'` só quando `comparison_mode='previous_equal_length_window'` E Adman devolveu diff; fallback caso contrário | Feature (`Http::fake` + `MetricPeriodResolver` real) | `php artisan test --filter=test_prefere_adman_diff_janela_igual` / `test_fallback_calculado_modo_operacional` | ❌ Wave 0 |
| ADM-03 | Diff de período não persiste como coluna nova em `adman_metrics`; service é live-read | Feature/estrutural (grep de migration + assert de shape de retorno) | `php artisan test --filter=AdmanMetricDiffServiceTest` | ❌ Wave 0 |
| ADM-04 | Backfill de `raw_data` antigo preenche campos quando `.diff` existir, deixa null quando não | Unit/Feature (fixture com `raw_data` real capturado nesta pesquisa) | `php artisan test --filter=test_backfill_raw_data_antigo` | ❌ Wave 0 |
| ADM-05 | Labels de Margem R$ e Margem % nunca se misturam | Feature (assert de chaves distintas no array de retorno) | `php artisan test --filter=test_labels_margem_rs_e_pct_distintos` | ❌ Wave 0 |

### Sampling Rate
- **Por commit de task:** `php artisan test --filter=AdmanMetricDiffServiceTest`
- **Por merge de wave:** `php artisan test --testsuite=Feature`
- **Gate de fase:** suite completa verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase101/AdmanMetricDiffServiceTest.php` — cobre ADM-01, ADM-02, ADM-03, ADM-05 (usar os payloads reais capturados nesta pesquisa como fixtures `Http::fake`)
- [ ] `tests/Unit/Phase101/AdmanMetricDiffBackfillTest.php` (ou Feature, conforme decisão do planner) — cobre ADM-04, usando o `raw_data` real de `AdmanMetric#6937` (company_id=242, reference_date=2026-07-18) capturado nesta pesquisa como fixture
- [ ] Nenhuma dependência de framework nova — PHPUnit já instalado e configurado
