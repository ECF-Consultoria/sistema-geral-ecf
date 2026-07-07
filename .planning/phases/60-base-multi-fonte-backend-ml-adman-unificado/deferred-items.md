# Deferred Items — Phase 60

Itens descobertos durante execução da Phase 60 mas **fora do escopo** (pre-existentes ou não relacionados ao objetivo do plan atual). Não bloqueiam a phase.

## Descobertos no Plan 60-02

### DEF-60-02-01 — Falhas pré-existentes em `Phase18/CompaniesCustIdFilterTest`

- **Testes que falham:**
  - `Tests\Feature\Phase18\CompaniesCustIdFilterTest::test_filtro_invalido_retorna_apenas_invalidas`
  - `Tests\Feature\Phase18\CompaniesCustIdFilterTest::test_sem_filtro_retorna_todas_as_empresas`
- **Baseline confirmado:** falhas ocorrem tanto com quanto sem `AdmanMetricsProvider.php` no working tree — **não** causadas pela Phase 60.
- **Stack trace aponta para:** `vendor/inertiajs/inertia-laravel/src/Testing/TestResponseMacros.php:26` (macro de teste Inertia).
- **Suspeita:** provavelmente colateral de mudanças untracked em `app/Http/Controllers/CompanyController.php` (working tree tem `M CompanyController.php` fora do escopo do plan 60-02).
- **Escopo:** fora da Phase 60 — não relacionado à leitura multi-fonte de métricas.
- **Ação sugerida:** criar quick task ou incluir na próxima phase de manutenção de testes.

### DEF-60-02-02 — Timeout de 300s em `MercadoLivreAdsService.php:215` em suite Feature completa

- **Sintoma:** `php artisan test --testsuite=Feature` estoura `max_execution_time = 300` em algum teste que instancia `MercadoLivreAdsService`.
- **Baseline:** não verificado explicitamente, mas o código em `line 215` do serviço é 100% alheio ao Plan 60-02 (`app/Services/Sugadores/`, não `app/Services/Metrics/`).
- **Escopo:** fora da Phase 60 (Sugadores/Phase 39-40).
- **Ação sugerida:** ajustar `max_execution_time` no phpunit.xml ou refatorar o teste que causa o loop.
