# Fase 90 — Validation Architecture

> Extraído de `90-RESEARCH.md` para satisfazer o gate Nyquist (Dimension 8e). Conteúdo idêntico ao embutido no research.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (DB_CONNECTION=sqlite, DB_DATABASE=:memory:) |
| Quick run command | `php artisan test --filter=NomeDoTeste` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CART-06 | Cards separam empresas únicas de vínculos; Shopee-only não herda ML | Feature (controller) | `php artisan test --filter=CarteirasConsolidadasContextoTest` | ❌ Wave 0 |
| CART-07 | Filtro de contexto funcional nas 2 telas + contadores | Feature (controller) | `php artisan test --filter=CarteirasConsolidadasContextoTest` (backend) — UI só via checkpoint visual | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter={TestClassDaTask}`
- **Per wave merge:** `php artisan test --filter=V16` (suíte V16 completa — baseline de regressão herdada da Fase 88/89) + `php artisan test --filter=Portfolio`
- **Phase gate:** `php artisan test` completo verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/V16/CarteirasConsolidadasContextoTest.php` — cobre CART-06 (dedup entre profissionais) + CART-07 backend (filtro `?contexto=`)
- [ ] Nenhum framework novo a instalar — PHPUnit 11.x já configurado, trait de fixtures já existe (`CriaCenarioResponsaveis`)
