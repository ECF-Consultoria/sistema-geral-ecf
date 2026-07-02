---
phase: 53-inteligencia-detector-sugadores
plan: "53-02"
subsystem: sugadores
status: complete
completed_at: 2026-07-02
tags:
  - sugadores
  - mercadolivre
  - detector
  - tdd
requirements:
  - REQ-53-02
dependency_graph:
  requires:
    - MercadoLivreService::fetchItemStatus (Wave 1)
    - MercadoLivreSugadoresProvider (Wave 1 expondo mlb_sold_quantity_global)
    - SugadorAnalysisService::evaluateMetrics (contrato preservado)
  provides:
    - Chave canonica `sold_global` no payload do provider ML
    - Filtro composto no evaluateMetrics — sold_global>=10 remove gasto_sem_venda
    - Comentario documental do mismatch B3 (raw_data.item_id vs sugador.mlb_id)
  affects:
    - Sugadores ML pipeline (path Adman intocado — sold_global=null preserva legacy)
    - Detector para B2 BARAOSHOP + B3 DINMAP (falso-positivo por venda organica global)
tech_stack:
  added: []
  patterns:
    - Filtro composto DEPOIS da projecao final de motivos (nao altera required/optional)
    - Threshold hardcoded (research §Assumption A1 — configurable = feature futura)
    - Fail-open universal (sold_global=null preserva comportamento legacy)
    - Nome canonico + alias (sold_global aliaseia mlb_sold_quantity_global sem regredir)
key_files:
  created: []
  modified:
    - app/Services/Sugadores/MercadoLivreSugadoresProvider.php
    - app/Services/SugadorAnalysisService.php
    - tests/Unit/Services/Sugadores/MercadoLivreSugadoresProviderFilterTest.php
decisions:
  - Threshold 10 LOCKED (research §Assumptions A1) — hardcoded no evaluateMetrics,
    sem passar por SugadorConfig. Tornar configuravel e feature futura DEFERRED.
  - Nome canonico `sold_global` como alias de `mlb_sold_quantity_global` (Wave 1) —
    nao remove a chave antiga pra nao regredir consumers externos.
  - Filtro roda DEPOIS da projecao final — implementacao lean sem tocar em
    required/optional. Se 'gasto_sem_venda' era o unico motivo, motivos fica []
    e o caller nao persiste.
  - Fix retroativo do mismatch B3 (sugador.mlb_id ≠ raw_data.item_id) DEFERRED —
    e' bulk-update. Registros NOVOS ja ficam sincronizados via raw_data.item_id.
metrics:
  duration_minutes: ~20
  commits: 2
  tests_added: 7
  tests_total_phase53: 17
  tests_regression_verified: 91 (Phase 41: 43 + Phase 52: 25 + Phase 54: 11 +
    Phase 42 baseline preservado + Phase 39 baseline preservado)
---

# Phase 53 Plan 53-02: Filtro sold_global remove gasto_sem_venda (fix B2 + B3) Summary

Fix unificado para os 2 falsos-positivos por **venda organica global** confirmados
em prod: quando o produto vende MUITO no MLB (FULL organico, busca direta) mas o
ads reporta 0 vendas atribuidas, o detector NAO deve flagar o adgroup como
sugador — o cliente pausou/reduziu o ads justamente porque o produto vende sem
ele. Threshold LOCKED em 10 vendas globais nos ultimos 30d.

## Commits

| Tipo | Hash | Mensagem |
|------|------|----------|
| test | `f64e57b` | failing tests para filtro sold_global no evaluateMetrics + regressao de motivos preservados |
| feat | `d41ba5d` | filtro sold_global>=10 remove gasto_sem_venda (fix B2 BARAOSHOP + B3 DINMAP) |

TDD gate compliance: **RED (`test:`) → GREEN (`feat:`)**. Ciclo respeitado;
sem misturar test e feat no mesmo commit.

## Arquivos

**Modificado (3):**

- `app/Services/Sugadores/MercadoLivreSugadoresProvider.php` — nova chave canonica
  `sold_global` no payload de cada adgroup (alias de `mlb_sold_quantity_global`
  da Wave 1, com o nome que `evaluateMetrics` espera). Comentario documenta o
  mismatch B3 (`sugador.mlb_id` diverge de `raw_data.item_id` em 1 caractere —
  `raw_data.item_id` prevalece).
- `app/Services/SugadorAnalysisService.php` — filtro composto no
  `evaluateMetrics`: se motivo `gasto_sem_venda` bate mas `sold_global >= 10`,
  remove o motivo do array final. Roda DEPOIS da projecao final; nao altera
  required/optional. Se era o unico motivo → adgroup nao persiste.
- `tests/Unit/Services/Sugadores/MercadoLivreSugadoresProviderFilterTest.php` —
  7 testes novos cobrindo threshold, fail-open, e preservacao dos outros criterios.

## Resultado dos testes

### Phase 53 acumulado (17/17 verdes)

```
PHPUnit 11.5.55
.................                                                 17 / 17 (100%)
Tests: 17, Assertions: 31
```

Cobertura Wave 2 (7 novos):

| Teste | Cenario | Resultado |
|-------|---------|-----------|
| test_evaluate_metrics_generates_sugador_when_sold_global_zero | baseline hit legitimo | verde |
| test_evaluate_metrics_removes_gasto_sem_venda_when_sold_global_gte_10 | fix B2 (781 vendas) | verde |
| test_evaluate_metrics_threshold_exact_10_removes_gasto_sem_venda | limite exato 10 | verde |
| test_evaluate_metrics_keeps_gasto_sem_venda_when_sold_global_below_threshold | sold_global=9 preserva | verde |
| test_evaluate_metrics_keeps_gasto_sem_venda_when_sold_global_null_fail_open | fail-open Wave 1 | verde |
| test_evaluate_metrics_preserves_acos_alto_when_sold_global_high | acos_alto intocado | verde |
| test_evaluate_metrics_preserves_cpc_alto_when_sold_global_high | cpc_alto + remove gasto (cross-criterio) | verde |

Wave 1 (10 testes) tambem verdes — sem regressao no cache listCampaigns nem no
fetchItemStatus nem no filtro mlb_status paused/closed/under_review.

### Regressao

| Suite | Resultado | Delta vs baseline pre-53-02 |
|-------|-----------|-----------------------------|
| Phase 41 (MercadoLivreAdsService) | 43/43 verde | zero mudanca |
| Phase 42 (CutOverMlPrimary + service) | 47/52 (5 failures) | zero mudanca — mesmas 5 falhas pre-existentes da Wave 1 |
| Phase 39 (provider ML + factory) | 46/48 (2 failures) | zero mudanca — mesmas 2 falhas pre-existentes da Wave 1 |
| Phase 52 (Sugador CRUD + analista) | 25/25 verde | zero mudanca |
| Phase 54 (filtro analista + periodo) | 11/11 verde | zero mudanca |

**Confirmacao de zero-regressao:** falhas Phase 42 (5) e Phase 39 (2) estao
documentadas no `53-01-SUMMARY.md` como pre-existentes ao trabalho da Phase 53.
Diagnostico: quick 260626-qgf (`id` vs `ad_group_id`) + `auto_resolvido` recycling
em suites legacy. Fora do escopo desta wave.

## Desvios do plano

**Nenhum desvio de escopo.** As 2 tarefas mapeadas no PLAN foram executadas
exatamente como especificado. Observacoes:

- **Tarefa 1 RED — apenas 3 dos 7 testes falharam.** Esperado — dos 7 testes,
  4 descrevem comportamento LEGACY que ja funciona (sold_global=0/9/null gera
  sugador; acos_alto nao depende de sold_global). Eles servem como REGRESSAO —
  garantem que a implementacao GREEN nao quebra o comportamento legacy. Os
  3 que falham cobrem o NOVO comportamento:
  1. `test_evaluate_metrics_removes_gasto_sem_venda_when_sold_global_gte_10`
  2. `test_evaluate_metrics_threshold_exact_10_removes_gasto_sem_venda`
  3. `test_evaluate_metrics_preserves_cpc_alto_when_sold_global_high` (cross-criterio)

  Isso e' TDD correto: RED nao precisa ser 100% falha — a validacao e' "os
  cenarios que exigem comportamento NOVO falham antes do GREEN".

- **Chave canonica `sold_global` mantida junto de `mlb_sold_quantity_global`.**
  Wave 1 ja expunha `mlb_sold_quantity_global`. Wave 2 adiciona `sold_global`
  (nome esperado pelo evaluateMetrics) sem remover a antiga — nao regride quem
  ja lia a Wave 1. Decisao consciente (plano must_have: "manter ambas").

## Como o filtro se compoe com required/optional

O filtro roda DEPOIS da projecao final de motivos. Sequencia:

1. `evaluateMetrics` avalia cada criterio (cria `$criteria[]` com hit + logic)
2. Valida required (todos precisam bater) e optional (pelo menos 1)
3. Projeta motivos → `$motivos = ['gasto_sem_venda', 'cpc_alto', ...]`
4. **Phase 53-02:** se `sold_global >= 10`, filtra `gasto_sem_venda` de `$motivos`
5. Retorna `$motivos` (possivelmente vazio)

**Impacto no caller (`analyzeCompany`):** se filtro deixa `$motivos` vazio, o
caller ja tem `if (empty($motivos)) continue;` — adgroup NAO e persistido.
Nao ha risco de persistir sugador sem motivo.

**Impacto em `required=gasto_sem_venda`:** cenario nao testado explicitamente
mas seguro por design. Se um cliente configurar `gasto_minimo_logic=required`
e `sold_global>=10`, o adgroup passa pelo required (hit=true na projecao) mas
depois o motivo e' filtrado. Resultado: motivos vazios → nao persiste. E'
exatamente o comportamento desejado — o filtro protege contra falso-positivo
independente da politica required/optional.

## Threshold LOCKED — por que 10 e nao configuravel

Research §Assumption A1 fixou o valor em `10 vendas globais em 30d` com
justificativa:

- < 10 vendas em 30d = produto de baixo volume; ads pode ser genuinamente
  necessario e ausencia de venda ads pode ser sinal real
- >= 10 vendas em 30d = produto vende consistentemente via FULL/organico;
  se ads reporta 0, filtro operacional (nao sugador)

Tornar configuravel via `SugadorConfig` e' feature futura — briefing decidiu
LEAN pra Wave 2 (usuario nao pediu configuravel; caso pratico e' resolver
B2 e B3). Se rodada UAT indicar necessidade de calibrar por empresa,
adicionar em phase futura via nova coluna `sold_global_min_threshold_locked`.

## Fail-open confirmado (sold_global=null preserva legacy)

Path Adman **NAO expoe** `sold_global` no payload (AdmanSugadoresProvider nao
foi tocado nem na Wave 1 nem na Wave 2). Consequencia:

- Adgroups path Adman → `sold_global` sempre nao presente no metrics
- `$metrics['sold_global'] ?? null` retorna null
- Filtro so ativa se `sold_global !== null && >= 10` → NAO ativa para Adman
- Comportamento legacy 100% preservado

Path ML tambem preserva legacy quando fetchItemStatus fail-open (rate-limit,
timeout, sem mlb_id) → sold_global=null. Cliente Bymobille + casos legitimos
NAO regridem.

## Mismatch B3 — nota operacional

Descoberta em `53-RESEARCH §Caso B3`: DINMAP tem sugador com
`mlb_id = MLB4359551779` mas `raw_data.item_id = MLB4359551777` (1 caractere
de diferenca — herdado de sync antigo). Solucao Wave 2:

- **Registros NOVOS:** provider ja usa `$r['item_id']` (raw_data) como
  fonte-verdade em Wave 1. Comentario Wave 2 documenta isso explicitamente
  no codigo (`MercadoLivreSugadoresProvider.php:222-224`).
- **Registros ANTIGOS:** permanecem com mismatch. Fix retroativo e' bulk-update:
  `UPDATE sugadores SET mlb_id = JSON_EXTRACT(raw_data, '$.item_id') WHERE
  mlb_id != JSON_EXTRACT(raw_data, '$.item_id') AND tipo='adgroup'` — decisao
  operacional DEFERRED, nao entra nesta wave.

Impacto do mismatch remanescente: baixo. A analise futura sempre re-avalia com
raw_data.item_id — o upsert cria registro novo com mlb_id correto ou atualiza
o antigo (unique key inclui adgroup_id, memoria
`project_sugadores_unique_key_inclui_adgroup_id`).

## Preparacao Wave 3 (UAT)

Pontos a validar em prod apos deploy:

1. **CAMILLO PARTS** (fix Wave 1): rodar analise e confirmar que adgroup
   1902557017 (MLB `paused`) NAO aparece mais como sugador novo.
2. **BARAOSHOP VARIEDADES** (fix Wave 2 principal): rodar analise em janela
   de 30d e confirmar que adgroup 496843010 (MLB3759543315 com 781 vendas
   globais) NAO gera sugador se o unico motivo era `gasto_sem_venda`.
3. **DINMAP** (fix Wave 2 secundario): confirmar que adgroup 1784220962
   (MLB4359551777 com 6 vendas globais) preserva comportamento — 6 esta abaixo
   do threshold 10, entao ainda gera sugador. Se decidido calibrar, ajustar
   o threshold em phase futura (nao mudar nesta wave).
4. **Regressao operacional:** confirmar que empresas rodando analise diaria
   nao ficam com queda >20% no volume de sugadores novos — se acontecer,
   revisar threshold e reagir.

Grep de sanity pos-deploy:

```bash
# 1) Chave `sold_global` esta sendo passada pelo provider ML no payload
grep -n "'sold_global'" app/Services/Sugadores/MercadoLivreSugadoresProvider.php
# 2) Filtro esta ativo no evaluateMetrics
grep -n "sold_global" app/Services/SugadorAnalysisService.php
# 3) Threshold hardcoded 10
grep -n ">= 10" app/Services/SugadorAnalysisService.php
```

## Success criteria (do PLAN)

- [x] 7 testes novos escritos, todos verdes
- [x] Total 17 testes verdes na suite Phase 53
- [x] `MercadoLivreSugadoresProvider` expoe chave canonica `sold_global`
      no payload de cada adgroup
- [x] `SugadorAnalysisService::evaluateMetrics` remove motivo `gasto_sem_venda`
      quando `sold_global >= 10`
- [x] Motivos adicionais (`cliques_sem_conversao`, `acos_alto`, `cpc_alto`)
      sao PRESERVADOS quando o filtro remove so `gasto_sem_venda`
- [x] `sold_global = null` (fail-open) NAO ativa o filtro → path Adman e
      cenarios de instabilidade ML mantem comportamento legacy
- [x] Threshold `10` hardcoded (nao passa por SugadorConfig)
- [x] Comentario do mismatch B3 documentado no provider
      (`MercadoLivreSugadoresProvider.php:222-224`)
- [x] Regressao Phase 41 + Phase 42 verde (delta zero — mesmas 5 failures
      pre-existentes da Wave 1)
- [x] Regressao Phase 52 (25) + Phase 54 (11) verde
- [x] Commits RED e GREEN separados (2 commits nesta wave)

## Self-Check: PASSED

- Arquivo `app/Services/Sugadores/MercadoLivreSugadoresProvider.php` modificado:
  chave `'sold_global'` adicionada (1 grep hit)
- Arquivo `app/Services/SugadorAnalysisService.php` modificado: filtro composto
  presente (3 grep hits — comentario + variavel + condicional)
- Commit `f64e57b` (RED) — presente
- Commit `d41ba5d` (GREEN) — presente
- Testes Phase 53: 17/17 verdes
- Regressao Phase 41: 43/43 verde
- Regressao Phase 52: 25/25 verde
- Regressao Phase 54: 11/11 verde
- Regressao Phase 42: 47/52 (5 pre-existentes)
- Regressao Phase 39: 46/48 (2 pre-existentes)
- Comentario mismatch B3 no provider: `MLB4359551779` + `MLB4359551777` presentes
