# Fase 92 — Validation Architecture

> Extraído de `92-RESEARCH.md` para satisfazer o gate Nyquist (Dimension 8e).

## Validation Architecture

### Test Framework

| Propriedade | Valor |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), config `phpunit.xml` |
| Config file | `phpunit.xml` (raiz do projeto) |
| Quick run command | `C:\xampp\php\php.exe vendor/bin/phpunit --filter="Desempenho|Bonus|ComparacaoContextual"` |
| Full suite command | `C:\xampp\php\php.exe vendor/bin/phpunit` |

Convenção de diretório observada nas Fases 88-91 (v17.0): `tests/Feature/V16/*` (nome do diretório é herdado da milestone anterior mas continua sendo usado para testes v17.0 — ex.: `CarteiraContextServiceTest.php` é Fase 88/v17.0 mas mora em `tests/Feature/V16/`). Recomenda-se seguir essa mesma convenção por consistência (não criar `tests/Feature/V17/` nem `tests/Feature/Phase92/` isolado, salvo decisão explícita do planner de padronizar a nomenclatura).

### Phase Requirements → Test Map

| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|--------|----------|-----------|-------------------|-------------|
| DESEMP-08 (SC1) | Ranking permanece único — nenhuma rota/tela nova por marketplace | unit/feature (gate de ausência via grep, mesmo padrão da Fase 91) | `grep -rin "score_shopee\|score_ml\|ranking_shopee\|ranking_ml" app/ resources/js/` deve retornar 0 | ❌ Wave 0 (comando ad-hoc, não teste PHPUnit) |
| DESEMP-08 (SC2) | Payload do ranking (`Performance/Index`) inclui os 6 metadados por linha | feature (assert Inertia props) | `phpunit --filter=PerformanceIndexMetadadosTest` | ❌ Wave 0 — criar `tests/Feature/V16/PerformanceIndexMetadadosTest.php` |
| DESEMP-08 (SC2) | `score_status='blocked'` renderiza sem quebrar (nota null tratada) | feature (fixture com profissional só-Shopee, reusar padrão de `DesempenhoElegibilidadeTest`) | mesmo arquivo acima | ❌ Wave 0 |
| DESEMP-08 (SC3) | `?contexto=performance/shopee/todos` não altera `nota_final`/`score_status` retornado | feature (assert response idêntico exceto flags de exibição) | mesmo arquivo acima ou dedicado | ❌ Wave 0 |
| Pendência Fase 91 | `comparacaoContextual` exclui `blocked` da amostra + `tamanho_amostra` bate com N real | feature (fixture: 1 user official + 1 blocked no mesmo cargo, assert blocked fora de `scoresPares`) | `phpunit --filter=ComparacaoContextualBlockedTest` | ❌ Wave 0 — criar `tests/Feature/V16/ComparacaoContextualBlockedTest.php` (ou nome equivalente) |

### Sampling Rate

- **Por commit de task:** `php vendor/bin/phpunit --filter="Desempenho|Bonus|ComparacaoContextual|PerformanceIndex"`
- **Por merge de wave:** suite completa `php vendor/bin/phpunit`
- **Gate de fase:** suite completa verde (baseline: 75/76 pré-existente, ver Pitfall 3) antes de `/gsd:verify-work`; checkpoint visual humano obrigatório (UI hint: yes no ROADMAP) cobrindo o badge de status + o filtro `?contexto=` + a tela de comparação (`Portfolio/Show.jsx` self-view).

### Wave 0 Gaps

- [ ] `tests/Feature/V16/PerformanceIndexMetadadosTest.php` — cobre SC1/SC2/SC3 do payload de `/performance`
- [ ] `tests/Feature/V16/ComparacaoContextualBlockedTest.php` (ou nome equivalente escolhido pelo planner) — cobre a correção da Distorção A/B, fixture com pelo menos 1 `blocked` + 1 `official` no mesmo cargo
- [ ] Nenhuma instalação de framework necessária — PHPUnit 11 já configurado e em uso pelas suites de Desempenho existentes
