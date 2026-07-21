# Fase 106 — Validation Architecture

> Extraído de 106-RESEARCH.md (gate Nyquist 8e).

## Validation Architecture

`.planning/config.json` não define `workflow.nyquist_validation` explicitamente — tratado como habilitado (default).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), config `phpunit.xml` |
| Config file | `phpunit.xml` (raiz do projeto) |
| Quick run command | `php artisan test --filter=Phase106` (ou nome real da suite escolhida no plan) |
| Full suite command | `php artisan test` |

Nota de ambiente Windows: usar `php` do XAMPP (`C:\xampp\php\php.exe`) se `php` não estiver no PATH global — confirmar no plan qual binário o shell resolve por padrão antes de rodar.

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|---------------------|--------------|
| PERF-01 (SC1) | `desempenho:warm-cache` aquece mês corrente E último mês fechado (2 chamadas de `computeCached`, chaves distintas) | unit/feature | `php artisan test --filter=WarmDesempenhoCacheTest` | ❌ Wave 0 — nenhum teste existente para este command (não encontrado em `tests/`) |
| PERF-01 (SC2) | Controller: usuário frio em mês fechado NÃO chama `compute()` síncrono; retorna `calculando=true` e dispara warm | feature | `php artisan test --filter=PerformanceControllerWarmDegradationTest` | ❌ Wave 0 |
| PERF-01 (SC2) | `DesempenhoScoreService::isCached()` reflete corretamente `Cache::has()` na MESMA chave de `computeCached()` | unit | `php artisan test --filter=DesempenhoScoreServiceCacheTest` | ❌ Wave 0 |
| PERF-01 (SC3) | Modo "Em curso" não é afetado — ranking segue chamando `computeCached()` direto, sem checagem de `isCached` | feature | reusa `PerformanceControllerWarmDegradationTest` (caso negativo) | ❌ Wave 0 |
| PERF-01 (SC4) | Números do ranking (nota_final, faixa_bonus) para usuário JÁ quente permanecem idênticos ao baseline pré-106 | regressão | suite completa `php artisan test` (delta 0) | ✓ (suite existente cobre `DesempenhoScoreServiceTest`, `PerformanceController*Test` se existirem) |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=Phase106` (ou nome real definido no plan)
- **Per wave merge:** `php artisan test` (suite completa)
- **Phase gate:** Suite completa verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase106/WarmDesempenhoCacheTest.php` — cobre SC1 (2 competências aquecidas, chaves de cache distintas verificáveis via `Cache::has`)
- [ ] `tests/Feature/Phase106/PerformanceControllerWarmDegradationTest.php` — cobre SC2/SC3 (frio→placeholder+dispatch; quente→número normal; em-curso intocado). Necessita `Queue::fake()` para asserção do `Artisan::queue()` sem processar de verdade
- [ ] `tests/Unit/DesempenhoScoreServiceCacheTest.php` (ou adicionar casos ao arquivo Unit/Feature existente do service) — cobre `isCached()` como novo método público

*(Nenhum framework novo — PHPUnit + `Queue::fake()`/`Cache::` já são padrão Laravel usados no projeto.)*
