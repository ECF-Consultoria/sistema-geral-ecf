---
phase: 42-sugadores-api-ml
plan: 01
subsystem: sugadores
tags: [sugadores, schema, migration, cpc, composta]
requires: []
provides:
  - sugador_configs.cpc_minimo_cliques (unsignedInteger nullable)
  - SugadorConfig::$fillable + $casts + DEFAULTS para cpc_minimo_cliques
  - SugadorAnalysisService::evaluateMetrics — gate composto em cpc_alto (D-01)
affects:
  - app/Models/SugadorConfig.php
  - app/Services/SugadorAnalysisService.php
  - database/migrations/2026_06_26_420101_add_cpc_minimo_cliques_to_sugador_configs_table.php
tech-stack:
  added: []
  patterns:
    - migration idempotente via Schema::hasColumn
    - cast Eloquent integer round-trip nullable
    - PHPUnit 11 atributo #[Test]
key-files:
  created:
    - database/migrations/2026_06_26_420101_add_cpc_minimo_cliques_to_sugador_configs_table.php
    - tests/Feature/Phase42/CpcMinimoCliquesSchemaTest.php
    - tests/Unit/Phase42/EvaluateMetricsCpcCompostoTest.php
  modified:
    - app/Models/SugadorConfig.php
    - app/Services/SugadorAnalysisService.php
decisions:
  - "D-01 (CONTEXT): adotada Opcao B do briefing §8 — campo cpc_minimo_cliques opcional no proprio criterio cpc_alto, sem novo motivo"
  - "Default null preserva legacy: empresas existentes nao sao afetadas (opt-in)"
  - "Cast `integer`: round-trip preserva null e int (T3 do schema test cobre)"
  - "Posicionamento: cpc_minimo_cliques sempre apos cpc_maximo_logic em fillable/casts/DEFAULTS e no banco (after('cpc_maximo_logic')) — alinha com nocao visual de campo dependente do criterio CPC"
metrics:
  duration: ~12min
  completed: 2026-06-26
requirements: [REQ-42-04]
gates:
  red: b78f777
  green: 566fd24
---

# Phase 42 Plan 42-01: cpc_minimo_cliques + Gate Composto cpc_alto — Summary

Adiciona o campo `cpc_minimo_cliques` (nullable int) em `sugador_configs` conforme decisao
D-01 do CONTEXT (Opcao B do briefing §8) e integra a logica composta diretamente no criterio
`cpc_alto` do `SugadorAnalysisService::evaluateMetrics`. Quando `cpc_minimo_cliques` eh
`null`, comportamento legacy eh preservado (zero regressao); quando preenchido, o criterio
exige tambem `clicks >= cpc_minimo_cliques` (boundary inclusivo) antes de marcar hit.

## Tasks Executadas

| Task | Nome                                                                  | Commit  | Arquivos                                                                                                                                  |
| ---- | --------------------------------------------------------------------- | ------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| 1    | RED — suites de tests para schema e logica composta                   | b78f777 | tests/Feature/Phase42/CpcMinimoCliquesSchemaTest.php, tests/Unit/Phase42/EvaluateMetricsCpcCompostoTest.php                                |
| 2    | GREEN — migration + Model + logica composta no evaluator              | 566fd24 | database/migrations/2026_06_26_420101_add_cpc_minimo_cliques_to_sugador_configs_table.php, app/Models/SugadorConfig.php, app/Services/SugadorAnalysisService.php |

## TDD Gate Compliance

- RED (b78f777): `test(42-01): suites schema cpc_minimo_cliques + logica composta cpc_alto RED — 9 tests`
- GREEN (566fd24): `feat(42-01): GREEN cpc_minimo_cliques + logica composta cpc_alto (REQ-42-04)`
- REFACTOR: nao aplicavel (mudanca minima — 1 expressao composta no service, migration + edit em fillable/casts/DEFAULTS)

Ordem dos commits respeitada: RED → GREEN, conforme PLAN.md `type: tdd`.

## Tests

**Feature suite (`tests/Feature/Phase42/CpcMinimoCliquesSchemaTest.php`) — 4 tests:**

- `schema_tem_coluna_cpc_minimo_cliques` — valida `Schema::hasColumn` apos migrate
- `fillable_inclui_cpc_minimo_cliques` — mass-assign + persistencia como int 5
- `cast_integer_preserva_null` — config sem o campo mantem `null` (NAO 0, NAO false)
- `defaults_inclui_chave_com_valor_null` — chave presente em DEFAULTS

**Unit suite (`tests/Unit/Phase42/EvaluateMetricsCpcCompostoTest.php`) — 5 tests:**

- `cpc_alto_legacy_quando_minimo_cliques_eh_null` — `cpc_minimo_cliques=null`, clicks=2 → `cpc_alto` marcado (legacy)
- `cpc_alto_bloqueado_quando_clicks_abaixo_minimo` — `cpc_minimo_cliques=5`, clicks=3 → `[]` (gate)
- `cpc_alto_marca_hit_no_boundary_inclusivo` — `cpc_minimo_cliques=5`, clicks=5 → marca (`>=`)
- `cpc_alto_marca_hit_acima_do_minimo` — `cpc_minimo_cliques=5`, clicks=10 → marca
- `cpc_alto_nao_marca_quando_venda_existe` — `sold_quantity=1`, clicks=10 → `[]` (criterio so vale com zero vendas)

Total: 9 tests (4 Feature + 5 Unit). Padrao PHPUnit 11 com atributo `#[Test]`.

**NOTA sobre execucao:** PHPUnit NAO foi executado dentro do worktree (regra do
parallel_execution: tests serao rodados pelo orquestrador apos merge na main).
Validacao de sintaxe via `php -l` passou nos 5 arquivos modificados/criados.

## Decisoes Tomadas

1. **D-01 (Opcao B do briefing §8) implementada como gate dentro de `cpc_alto`**
   — sem motivo novo, sem campo de logic separado. Mantem UI atual (Plan 42-02 adiciona
   apenas o input no formulario sem criar coluna nova na listagem de motivos).
2. **Default null preserva legacy** — empresas existentes nao sao afetadas. O analista
   opta-in editando a config da empresa em `/sugadores/config/{company}` (Plan 42-02).
3. **Posicionamento consistente** — `cpc_minimo_cliques` sempre imediatamente apos
   `cpc_maximo_logic` em fillable, casts, DEFAULTS e no banco (`after('cpc_maximo_logic')`).
   Refleta a nocao visual: campo dependente do criterio CPC.
4. **Cast `integer`** — round-trip via Eloquent preserva null e int sem conversoes
   acidentais (0 / false). Cobre o risco T-42-01-02 do threat register.
5. **Migration idempotente** — `Schema::hasColumn` antes do ADD/DROP em up/down. Cobre
   T-42-01-01 (rollback testado via SQLite em-memory no RefreshDatabase).

## Deviations from Plan

None — plano executado exatamente como escrito.

## Threat Mitigations

- **T-42-01-01 (Tampering — migration sem rollback testado):** mitigado via guard
  `Schema::hasColumn` em up()/down(). RefreshDatabase exercita o ciclo completo a cada
  teste.
- **T-42-01-02 (Information disclosure — cast falha vira string):** mitigado via cast
  `integer` no Model + `(int)` defensivo na expressao do service. T1 do schema test
  cobre round-trip explicito.
- **T-42-01-03 (DoS — false negative em larga escala):** aceito conforme plano. Mudanca
  afeta apenas novas analises; status travados (em_acao/resolvido/ignorado/movido)
  permanecem inalterados.
- **T-42-01-SC (Tampering — installs):** nao aplicavel — esta phase nao instala packages.

## Verificacao dos Success Criteria

1. ✅ Schema: `cpc_minimo_cliques` unsignedInteger nullable apos `cpc_maximo_logic`
2. ✅ Model: campo em fillable + casts(integer) + DEFAULTS(null); cobertura por T2/T3/T4 do schema test
3. ✅ Service: criterio `cpc_alto` aplica gate composto; null preserva legacy; T1-T5 do unit test cobrem
4. ⚠️ Tests: 9 suites criadas + sintaxe validada via `php -l`; execucao PHPUnit pelo orquestrador apos merge
5. ✅ REQ-42-04 backend coberto; frontend fica para Plan 42-02

## Self-Check: PASSED

- `tests/Feature/Phase42/CpcMinimoCliquesSchemaTest.php` — FOUND
- `tests/Unit/Phase42/EvaluateMetricsCpcCompostoTest.php` — FOUND
- `database/migrations/2026_06_26_420101_add_cpc_minimo_cliques_to_sugador_configs_table.php` — FOUND
- `app/Models/SugadorConfig.php` — FOUND (modificado)
- `app/Services/SugadorAnalysisService.php` — FOUND (modificado)
- Commit b78f777 — FOUND
- Commit 566fd24 — FOUND
- `grep -c "'cpc_minimo_cliques'" app/Models/SugadorConfig.php` retorna 3 (fillable + casts + DEFAULTS) ✅
- `grep -c "cpc_minimo_cliques" app/Services/SugadorAnalysisService.php` retorna 4 (>=1) ✅
- `grep -c "Phase 42 D-01" app/Services/SugadorAnalysisService.php` retorna 1 ✅
- `grep -c "#\[Test\]"` Feature retorna 4, Unit retorna 5 ✅

## Known Stubs

Nenhum. Backend completo; integracao com formulario fica para Plan 42-02.

## Threat Flags

Nenhuma surface nova fora do `<threat_model>` do plano. Mudanca eh interna ao service
e schema da config — nao introduz endpoint, auth path ou trust boundary.
