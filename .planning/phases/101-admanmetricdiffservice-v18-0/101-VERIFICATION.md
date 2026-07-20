---
phase: 101-admanmetricdiffservice-v18-0
verified: 2026-07-20T16:20:00-03:00
status: human_needed
score: 8/9 must-haves verified (1 requer decisão humana)
overrides_applied: 0
gaps: []
human_verification:
  - test: "Decidir se o reframe do ADM-04 (Success Criteria #4 do ROADMAP.md) é aceitável como está implementado"
    expected: "Confirmação de que 'backfill de raw_data antigo preenchendo colunas novas' foi deliberadamente substituído por um helper de leitura ao vivo (lerDiffDiarioRawData, scope='daily') — decisão já justificada e documentada no 101-02-PLAN.md/101-02-SUMMARY.md, mas o texto do ROADMAP.md (Success Criteria #4, linha 899) não foi atualizado para refletir o reframe"
    why_human: "É uma decisão de escopo/arquitetura (aceitar a reinterpretação do REQ original), não um fato verificável por grep — o código e os testes provam que o reframe documentado FOI implementado fielmente; o que falta é a ratificação humana de que o reframe é aceitável como fechamento do ADM-04 literal"
---

# Phase 101: AdmanMetricDiffService (v18.0) Verification Report

**Phase Goal:** Existe uma camada dedicada (`AdmanMetricDiffService`) que lê a variação pronta da Adman (`.diff`) em vez de recalcular na mão, com gate por `comparison_mode` e fallback calculado marcado quando a Adman não trouxer diff para a janela.

**Verified:** 2026-07-20T16:20:00-03:00
**Status:** human_needed
**Re-verification:** Não — verificação inicial

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `compute()` lê `revenue`/`profitMargin.value/.diff` de `/performance` e `percentageMargin.value/.diff` de `/accounts/metrics` | ✓ VERIFIED | `AdmanMetricDiffService.php:107-120` chama `fetchPerformance()` e `fetchAccountMetricsDetailedCached()`; testes `test_a_janela_igual_usa_adman_diff`, `test_d_margem_rs_e_pct_em_chaves_distintas` passam com fixtures reais (empresa 242) |
| 2 | Gate: `diff_source='adman_diff'` SÓ quando `comparison_mode==='previous_equal_length_window'` E Adman devolveu `.diff`; senão `calculated_fallback` | ✓ VERIFIED | `resolveField()` linhas 158-176: `if ($isJanelaIgual && $adminDiff !== null)`. Testes provam OS DOIS ramos: `test_a_janela_igual_usa_adman_diff` (janela-igual→adman_diff) e `test_b_modo_operacional_forca_calculated_fallback_mesmo_com_diff_adman` (operacional→fallback MESMO com `.diff` presente) + `test_c_janela_igual_sem_diff_da_adman_usa_fallback` (janela-igual sem `.diff`→fallback) |
| 3 | Diff de período é retornado com contexto de período e fonte — não vira fato diário; fato diário (`AdmanMetric`) continua guardando o valor do dia | ✓ VERIFIED | `buildResult()` retorna `{company_id, period (objeto inteiro), metrics, quality}`; `test_e_shape_e_quality_completos` confirma o shape; resultado só é cacheado (Cache::put, TTL 24h), nunca gravado como coluna/linha em `adman_metrics` |
| 4 | Backfill: quando `raw_data` antigo tiver `profitMargin.diff`/`percentageMargin.diff`, preencher os novos campos; quando não tiver, `null` | ? UNCERTAIN | **Reframado conscientemente.** Não há backfill de coluna (decisão travada: Fase 101 é live-read, ADM-03). `lerDiffDiarioRawData()` lê `raw_data.summarizedData.{grossBilling,profitMargin}.diff` (nunca `percentageMargin`, que nunca existiu em `raw_data`) e retorna `scope='daily'`. Implementação fiel ao que o `101-02-PLAN.md`/SUMMARY documentaram como desvio consciente — mas o texto do ROADMAP.md (SC #4) não foi atualizado. Ver seção "Decisão Pendente" abaixo |
| 5 | Margem R$ (`profitMargin`/`contribution_margin_value`) e Margem % (`percentageMargin`/`contribution_margin_pct`) em chaves distintas, nunca misturadas | ✓ VERIFIED | `test_d_margem_rs_e_pct_em_chaves_distintas`: `contribution_margin_value.value=141428.81` (profitMargin) ≠ `contribution_margin_pct.value=27.47` (percentageMargin); nunca usa `liquidMargin` como substituto |
| 6 | `calculated_fallback` reusa os guards cicatrizados de produção (interseção de dias-comuns + guard `margem_dias`) — gap de sync parcial NÃO produz variação artificial (~-100%) | ✓ VERIFIED | `somasComGuards()` linhas 237-287: guard `margem_dias` (linha 257: `if ($rowsAtual->isEmpty() ...) return null`) + interseção de offsets (linha 266). `test_f_gap_de_sync_parcial_nao_produz_variacao_artificial` prova `diff_pct=null` (não `-100.0`) quando `contribution_margin` é NULL na janela atual inteira, espelhando `audit-margem-luiz-ana.md` |
| 7 | Nenhuma migration desta fase adiciona coluna de diff de período em `adman_metrics` (live-read) | ✓ VERIFIED | `git show --stat` nos 4 commits da fase (`cdf5b52`, `663e872`, `548b661`, `76c0d6e`) não lista nenhum arquivo em `database/migrations/`; nenhuma migration nova com "diff" no nome no repositório |
| 8 | `fetchAccountMetricsCached()` (5 consumidores) permanece inalterado; leitura detalhada é aditiva | ✓ VERIFIED | `git diff` do método mostra 0 linhas alteradas dentro de `fetchAccountMetricsCached()` — só um bloco novo (`fetchAccountMetricsDetailedCached`) inserido logo após. Teste de regressão `test_fetch_account_metrics_cached_simplificado_permanece_inalterado` passa. `--filter=Adman` roda 100 testes/439 assertions verdes (nenhuma regressão nos consumidores existentes). Nota: só 2 call-sites externos encontrados em código de produção (`CompanyController.php`, `RefreshGrossBillingCacheJob.php`), não 5 como o comentário do código sugere — discrepância cosmética na documentação, sem impacto no comportamento (o método em si está comprovadamente intacto) |
| 9 | `lerDiffDiarioRawData` retorna `scope='daily'` e NUNCA `diff_source='adman_diff'` (guard anti-confusão 101-02) | ✓ VERIFIED | `lerDiffDiarioRawData()` linhas 336-350: retorno fixo `scope='daily'`, sem chave `diff_source`. `test_invariante_retorno_nunca_contem_diff_source_ou_adman_diff` asserta `assertArrayNotHasKey('diff_source', ...)` e `assertNotContains('adman_diff', ..., true)` |

**Score:** 8/9 truths verificadas automaticamente; 1 requer decisão humana (ver abaixo)

### Decisão Pendente — ADM-04 / Success Criteria #4

O texto do `ROADMAP.md` (linha 899, ainda não atualizado) diz: *"Backfill preenche os novos campos quando `raw_data` antigo já tiver `profitMargin.diff`/`percentageMargin.diff`; quando não tiver, os campos ficam `null`"*.

O `101-02-PLAN.md` e o `101-02-SUMMARY.md` documentam, com justificativa do research, que esse texto original é tecnicamente inviável dentro da decisão arquitetural da própria fase (ADM-03, live-read sem colunas novas):
- Não há "backfill de coluna" possível porque não existe coluna nova (decisão travada desde o Plan 01).
- `raw_data.*.diff` é sempre **diário** (resultado de `fetchPerformance` com `dateFrom=dateTo`) — nunca "diff de período". Reaproveitá-lo como diff de período seria o Pitfall 1 do research.
- `percentageMargin` nunca esteve em `raw_data` — logo o texto original ("quando `raw_data` antigo já tiver `percentageMargin.diff`") descreve um caso que **nunca ocorre**.

**A implementação entregue é fiel ao reframe documentado** (`lerDiffDiarioRawData`, testado, guardado contra confusão) — isto não é um caso de trabalho incompleto ou "esquecido". É uma mudança de escopo decidida durante a execução, com justificativa técnica sólida, mas que diverge da letra do Success Criteria do ROADMAP.md sem que o ROADMAP.md tenha sido atualizado para refletir isso.

**Isto parece intencional.** Para aceitar este desvio, adicione ao frontmatter deste VERIFICATION.md:

```yaml
overrides:
  - must_have: "Backfill preenche os novos campos quando raw_data antigo já tiver profitMargin.diff/percentageMargin.diff"
    reason: "Reframado pelo research: não há coluna nova pra fazer backfill (ADM-03, live-read); raw_data.diff é sempre diário (fetchPerformance dia único), nunca de período; percentageMargin nunca existiu em raw_data. Substituído por lerDiffDiarioRawData() — helper de leitura auxiliar com scope='daily' e guard anti-confusão, testado."
    accepted_by: "{seu nome}"
    accepted_at: "{timestamp ISO atual}"
```

Depois, re-rode a verificação para aplicar. Recomenda-se também atualizar o texto do ROADMAP.md (SC #4, linha 899) para refletir o reframe, evitando que a mesma discrepância reapareça em auditorias futuras de milestone.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/Metrics/AdmanMetricDiffService.php` | `compute()` live-read com gate + fallback guardado; `min_lines: 100` | ✓ VERIFIED | 396 linhas; `compute()` + `lerDiffDiarioRawData()` + 6 métodos privados de suporte, todos substantivos (sem stubs) |
| `app/Services/AdmanService.php` | `fetchAccountMetricsDetailedCached()` aditivo, `fetchAccountMetricsCached()` intacto | ✓ VERIFIED | Método novo de 62 linhas inserido; método existente com 0 linhas alteradas (`git diff` confirma) |
| `tests/Feature/V18/AdmanMetricDiffServiceTest.php` | Suite `Http::fake` cobrindo ADM-01/02/03/05 + gap de sync | ✓ VERIFIED | 8 testes, todos verdes, cobrindo os 6 cenários obrigatórios do plano (a-f) |
| `tests/Feature/V18/AdmanMetricDiffBackfillTest.php` | Teste com `raw_data` real provando diff diário + `percentageMargin` ausente | ✓ VERIFIED | 5 testes, todos verdes (fixture real + guard de invariante) |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `AdmanMetricDiffService::compute` | `AdmanService::fetchPerformance` | leitura de revenue/profitMargin | ✓ WIRED | Chamado diretamente em `compute()` linha 107 |
| `AdmanMetricDiffService::compute` | `AdmanService::fetchAccountMetricsDetailedCached` | leitura de percentageMargin com diff | ✓ WIRED | Chamado diretamente em `compute()` linha 117 |
| `AdmanMetricDiffService::compute` | `periodo['comparison_mode']` | gate adman_diff vs calculated_fallback | ✓ WIRED | `resolveField()` consome `$isJanelaIgual` derivado de `comparison_mode` |
| `AdmanMetricDiffService::somasComGuards` | `App\Models\AdmanMetric` | soma diária com guards no fallback | ✓ WIRED | Query direta em `AdmanMetric::where(...)`, testada em cenários b/c/f |

### Behavioral Spot-Checks / Execução Real de Testes

Rodei a suite (não confiei no SUMMARY):

| Comando | Resultado | Status |
|---------|-----------|--------|
| `php artisan test tests/Feature/V18/` | 13 passed (52 assertions) | ✓ PASS |
| `php artisan test --filter=Adman` | 100 passed (439 assertions) — inclui os 13 da V18 + regressão de todos os outros testes relacionados a Adman | ✓ PASS |
| `php artisan test --filter=CompanyController` | 9 passed (18 assertions) — confirma consumidor de `fetchAccountMetricsCached` sem regressão | ✓ PASS |

### Fronteira da Fase (commits)

Os 4 commits da fase (`cdf5b52`, `663e872`, `548b661`, `76c0d6e`) tocam exclusivamente:
- `app/Services/AdmanService.php` (método aditivo)
- `app/Services/Metrics/AdmanMetricDiffService.php` (arquivo novo)
- `tests/Feature/V18/AdmanMetricDiffServiceTest.php` e `tests/Feature/V18/AdmanMetricDiffBackfillTest.php` (arquivos novos)

Nenhuma migration, nenhum controller, nenhum `DesempenhoScoreService.php`, nenhum arquivo de NPS/STATE tocado por estes 4 commits — fronteira respeitada conforme o `<duplicacao_temporaria_intencional>` do plano (a duplicação dos guards é intencional e documentada para a Fase 102 remover).

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| ADM-01 | 101-01 | Lê revenue/profitMargin/percentageMargin com value+diff | ✓ SATISFIED | Truths #1 |
| ADM-02 | 101-01 | Gate por comparison_mode | ✓ SATISFIED | Truths #2 |
| ADM-03 | 101-01 | Diff de período com contexto, live-read sem coluna nova | ✓ SATISFIED | Truths #3, #7 |
| ADM-04 | 101-02 | Backfill de raw_data antigo (reframado) | ? NEEDS HUMAN | Truths #4 — reframe implementado fielmente, decisão de aceitação pendente |
| ADM-05 | 101-01 | Labels Margem R$/% distintos | ✓ SATISFIED | Truths #5 |

Nenhum requisito órfão encontrado (todos os 5 ADM-* declarados no ROADMAP.md aparecem nos frontmatters dos dois plans).

### Anti-Patterns Found

Nenhum. Scan por `TODO|FIXME|XXX|TBD|HACK|PLACEHOLDER` nos dois arquivos de produção modificados (`AdmanMetricDiffService.php`, `AdmanService.php`) não retornou nenhuma ocorrência. Todo acesso a payload externo usa `?? null` (fail-open, conforme threat model da fase). Nenhum `return null`/`return []` disfarçando lógica não implementada — todos os retornos vazios são caminhos de erro/fail-open intencionais e testados.

### Human Verification Required

### 1. Aceitar o reframe do ADM-04 (Success Criteria #4)

**Test:** Revisar a justificativa documentada em `101-02-PLAN.md` (bloco `<objective>`) e `101-02-SUMMARY.md` (seção "Decisions Made") sobre por que o backfill literal de coluna foi substituído por `lerDiffDiarioRawData()`.
**Expected:** Confirmar que a reinterpretação é aceitável como fechamento do ADM-04, OU solicitar replanejamento se o backfill literal (mesmo que semanticamente incorreto) for considerado obrigatório.
**Why human:** É uma decisão de escopo/arquitetura, não um fato verificável por grep — o código implementa fielmente o que foi documentado como desvio consciente, mas o ROADMAP.md não foi atualizado para refletir a mudança de escopo.

### Gaps Summary

Não há gaps técnicos — todos os artefatos existem, são substantivos, estão conectados corretamente (wiring confirmado) e os 13 testes da fase passam de fato (rodados por mim, não só lidos do SUMMARY). O único item pendente é uma decisão de aceitação humana sobre o reframe consciente do ADM-04, já bem documentado pelo executor mas não refletido no texto do ROADMAP.md. Isto NÃO bloqueia a Fase 102 — o `AdmanMetricDiffService::compute()` (o núcleo consumido pelo bônus) está completo, testado e com o gate por `comparison_mode` comprovado nos dois ramos.

---

_Verified: 2026-07-20T16:20:00-03:00_
_Verifier: Claude (gsd-verifier)_
