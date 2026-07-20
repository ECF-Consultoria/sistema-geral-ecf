# Fase 103 — Validation Architecture

> Extraído de 103-RESEARCH.md (gate Nyquist).

## Validation Architecture

### Test Framework
| Propriedade | Valor |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), config em `phpunit.xml` |
| Config file | `phpunit.xml` (raiz do projeto) |
| Comando rápido | `C:\xampp\php\php.exe artisan test --filter=CarteiraFinanceiroElegibilidadeTest` (ou o nome do teste alvo) |
| Suite completa | `C:\xampp\php\php.exe artisan test` |

### Requisitos da Fase → Testes
| REQ ID | Comportamento | Tipo | Comando automatizado | Arquivo existe? |
|--------|----------------|------|------------------------|------------------|
| CAR-01 | Mês fechado não usa `now()`/mês em curso | Feature | `php artisan test --filter=CarteiraIndividualContextoTest` (adaptar valores esperados) | ✅ existe, precisa de ajuste de valores (Pitfall 2) |
| CAR-02 | Variação de margem vem do diff Adman quando disponível; elegibilidade preservada | Feature | Novo teste com `Http::fake` no padrão de `tests/Feature/V18/DesempenhoPeriodoOficialTest.php` | ❌ Wave 0 — criar `tests/Feature/V18/CarteiraDiffAdmanTest.php` (ou nome equivalente) |
| CAR-03 | Coerência de janela entre todos os blocos da mesma tela | Feature | Assert nos campos `periodo.current_start/end`/`baseline_start/end` do payload Inertia | ❌ Wave 0 — pode ser coberto no mesmo teste de CAR-02 |

### Sampling Rate
- **Por commit de task:** `php artisan test --filter=Carteira` (roda os 3 arquivos V16 + novos)
- **Por merge de wave:** `php artisan test` completo
- **Gate de fase:** suite completa verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/V18/CarteiraDiffAdmanTest.php` (ou nome equivalente) — cobre CAR-02/CAR-03 com `Http::fake` provando `diff_source='adman_diff'` quando a Adman responde, e fallback quando não
- [ ] Recalcular valores esperados de baseline em `CarteiraIndividualContextoTest`/`CarteiraFinanceiroElegibilidadeTest` para o novo modo mês-fechado (janela-de-mesmo-tamanho)
- Framework: nenhum install novo — PHPUnit já configurado
