# Phase 120 Plan 01: Rede de proteção + feature flag + shadow Summary

Teste dourado que substitui o gate de sha256sum das Fases 117-119, bump de `cacheKey()` para `v14`, e a feature flag `metrics.performance_company_first_score` (default `false`) com o parâmetro de shadow `$incluirEmpresasScore` em `DesempenhoScoreService::compute()`/`computeCached()` — payload ganha `empresas_score`/`componentes.var_margem_pp` sem mudar nenhum valor legado.

## O que foi entregue

### Task 1 — Teste dourado (gate nº 1)
`tests/Feature/Phase120/PayloadBaselineFlagOffTest.php`, criado e verde **antes** de qualquer edição em `DesempenhoScoreService.php` (confirmado via `git diff --name-only` vazio naquele momento). Fixture: 1 profissional, 3 empresas (Adman completa, Shopee com placeholder de margem, Adman sem baseline na janela anterior). 5 testes: chaves de topo, sub-chaves (`componentes`/`pontos_componentes`/`margem_amostra`/`componentes_disponiveis`), tipos, valores congelados, e shape `sem_carteira`.

### Task 2 — Bump de cache (AGRE-03)
`cacheKey()` `v13 → v14` (regra corrente+1 — a Fase 119.1, paralela, já havia consumido `v13`). Bump aplicado **antes** da mudança de shape da Task 3 (T-120-02). Literal atualizado nas 4 suítes hardcoded: `DesempenhoShopeeScoreTest`, `Phase116/NpsFloorDesempenhoTest`, `Phase96/NpsInvalidacaoRespostaTest`, `V18/DesempenhoMetadadosCacheTest`.

### Task 3 — Flag + shadow + payload aditivo (AGRE-02/04)
- `config/metrics.php`: `performance_company_first_score`, default `false`, mesmo padrão de `unified_metrics_enabled`.
- `DesempenhoScoreService`: `CompanyScoreService` injetado no construtor (8º parâmetro promovido); `compute()`/`computeCached()` ganham `bool $incluirEmpresasScore = false`; shadow inserido logo após o filtro de `$invalidadas`; payload ganha `empresas_score` (raiz) e `componentes.var_margem_pp` (helper `varMargemPpAgregado()`); `shapeSemCarteira()` simétrico.
- `PayloadBaselineFlagOffTest`: `CHAVES_ADITIVAS_PERMITIDAS` → `['empresas_score']`, aditivas de `componentes` → `['var_margem_pp']`. Nenhuma asserção de valor alterada.

## Versão de cache efetivamente consumida

**`v14`**. Confirmado por `grep -n "desempenho\.compute\.v" app/Services/DesempenhoScoreService.php` antes de editar: o literal já estava em `v13` (consumido pela Fase 119.1, sessão paralela, antes desta execução). Regra corrente+1 aplicada → `v14`. Zero ocorrências do literal antigo (`desempenho.compute.v13`) restam em `app/` ou `tests/` (grep exaustivo, exit 1 = sem matches).

## Valores literais congelados pelo teste dourado (referência para a Fase 121)

Capturados **em execução** contra o código não modificado (2026-07-30), fixture de 1 profissional × 3 empresas (Adman completa "A", Shopee "B", Adman sem baseline anterior "C"), mês em curso (agosto/2026, `current_month`):

| Campo | Valor |
|---|---|
| `empresas_carteira` | `3` |
| `empresas_com_baseline` | `2` |
| `vinculos_financeiros` | `3` |
| `score_status` | `official` |
| `faixa_bonus` | `sem_bonus` |
| `componentes.nps_medio` | `1.0` (piso — mês em curso) |
| `componentes.var_faturamento_pct` | `6.50` |
| `componentes.var_margem_pct` | `null` |
| `pontos_componentes.margem` | `1.0` |
| `pontos_componentes.faturamento` | `5.00` |
| `margem_amostra.n_real` | `0` |
| `margem_amostra.n_elegivel` | `2` |
| `margem_amostra.cobertura` | `0.0` |
| `nota_final` | `2.33` |

**Achado não previsto pelo plano, documentado como fotografia do comportamento atual (não regressão desta fase):** `componentes.var_margem_pct` sai `null` **mesmo com dado de margem real sincronizado** (empresa A), porque o mês EM CURSO resolve `comparison_mode = 'same_interval_previous_month'` (`MetricPeriodResolver::resolveCurrentMonth`), e o hotfix de 2026-07-24 em `AdmanMetricDiffService::resolveMargemPct()` faz a variação de margem usar **sempre** o `.diff` nativo da Adman — nunca `calculated_fallback` — o que só é permitido quando `comparison_mode === 'previous_equal_length_window'`. Este comportamento já é a causa de 3 falhas pré-existentes em `DesempenhoShopeeScoreTest` (`test_so_performance_regressao_zero_...`, `test_misto_ml_shopee_...`, `test_invalidacao_empresa_shopee_...`), que fazem parte da baseline conhecida de 14 falhas — **não são regressão introduzida por este plano**. O teste dourado fotografou o valor real (`null`) em vez do valor teórico documentado nesses 3 testes (que ficou obsoleto desde o hotfix).

## Reafirmação: a flag permanece `false`

- `config('metrics.performance_company_first_score')` confirmado `false` por padrão (verificado via bootstrap do container, sem override de ambiente).
- Zero ocorrências de `PERFORMANCE_COMPANY_FIRST_SCORE` em `.env`/`.env.example` (grep, exit 1 = sem matches).
- Nenhum método legado (`computeNotaFinal`, `computeScoreStatus`, `computeVarFaturamento`, `computeVarMargem`, `computeNpsWindow`, `margemPontos`) recebe `$empresasScore` nem lê a flag — confirmado por grep das ocorrências de `empresasScore`/`incluirEmpresasScore`, todas confinadas às assinaturas de `compute()`/`computeCached()`, ao bloco do shadow, ao helper `varMargemPpAgregado()` e ao `return`.
- **Ligar em produção depende de dois pré-requisitos ainda não satisfeitos:** GATE MPP-04 aprovado (hoje `reprovado`) e o delta da Fase 121 aceito pelo usuário. Nenhum dos dois foi endereçado neste plano — a flag e o parâmetro de shadow existem, mas ninguém em produção os aciona (D-04: só os comandos `desempenho:warm-cache`/`desempenho:consolidar-mes`, ainda não conectados — isso é o Plano 02).

## Verificação

- `php artisan test --filter=PayloadBaselineFlagOffTest` — 5/5 verde, com as MESMAS asserções de valor entre a Task 1 e a Task 3 (nenhuma foi alterada).
- `php artisan test --filter="DesempenhoShopeeScoreTest|NpsFloorDesempenhoTest|NpsInvalidacaoRespostaTest|DesempenhoMetadadosCacheTest"` — 37 passed / 3 failed (os 3 já falhavam antes desta fase, ver achado acima).
- `php artisan test --filter=Desempenho` — **14 failed, 94 passed** (idêntico à baseline documentada no VALIDATION; zero regressão nova).
- `grep -rn "desempenho\.compute\.v13" app tests` — zero linhas.
- `grep -n "performance_company_first_score" .env .env.example` — zero linhas.
- `grep -n "empresasScore\|incluirEmpresasScore" app/Services/DesempenhoScoreService.php` — confinado a `compute()`/`computeCached()`, shadow, `varMargemPpAgregado()`, `return`.
- `config('metrics.performance_company_first_score')` — `false` confirmado via bootstrap.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - blocking] Fakes anônimos de `DesempenhoScoreService` quebravam o boot (fatal error de LSP)**
- **Found during:** verificação final `--filter=Desempenho` (Task 3)
- **Issue:** `tests/Feature/DesempenhoEvolucaoTest.php` e `tests/Feature/DesempenhoScoreSnapshotTest.php` têm classes anônimas que `extends DesempenhoScoreService` e sobrescrevem `compute()` com a assinatura antiga (3 parâmetros). O 4º parâmetro `bool $incluirEmpresasScore = false` da Task 3 tornou a assinatura do override incompatível com a do parent (LSP), causando `Fatal error: Declaration ... must be compatible` — não uma falha de teste, um erro de boot do PHP.
- **Fix:** assinatura dos dois overrides atualizada para `compute(User $user, Carbon $mesReferencia, ?array $periodoOverride = null, bool $incluirEmpresasScore = false): array`. Nenhum dos fakes lê o parâmetro novo — é puramente compatibilidade de assinatura, mesmo padrão já usado ali para o bump da Fase 102 (`$periodoOverride`).
- **Files modified:** `tests/Feature/DesempenhoEvolucaoTest.php`, `tests/Feature/DesempenhoScoreSnapshotTest.php`
- **Commit:** `7c01ebd5`

### Achado documentado (não é bug desta fase)

`componentes.var_margem_pct` sai `null` para QUALQUER empresa Adman no mês em curso, mesmo com margem real sincronizada — comportamento vigente desde o hotfix de 2026-07-24 em `AdmanMetricDiffService::resolveMargemPct()` (ver seção "Valores literais congelados" acima). Já é causa de 3 falhas na baseline conhecida de 14 (`DesempenhoShopeeScoreTest`). Fora de escopo desta fase (não é código tocado por este plano) — registrado aqui porque o teste dourado capturou o valor real (`null`) em vez do valor teórico que os 3 testes desatualizados esperam.

## Known Stubs

Nenhum — este plano é puramente aditivo em produção (flag desligada, shadow com default `false`) e todo o código novo é exercitado pelo teste dourado.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano — T-120-01 (tampering na flag), T-120-02 (cache), T-120-03 (elevation via flag em produção) e T-120-04 (DoS via shadow em leitura interativa) já cobrem tudo que foi tocado.

## Self-Check

- `tests/Feature/Phase120/PayloadBaselineFlagOffTest.php` — FOUND
- `app/Services/DesempenhoScoreService.php` — FOUND (modificado)
- `config/metrics.php` — FOUND (modificado)
- Commit `f1b1fbb9` (Task 1) — FOUND em `git log --oneline`
- Commit `f49263c9` (Task 2) — FOUND em `git log --oneline`
- Commit `7c01ebd5` (Task 3) — FOUND em `git log --oneline`

## Self-Check: PASSED

---

**Dependency graph:**
- **requires:** `app/Services/Desempenho/CompanyScoreService.php` (Fase 119, já pronto)
- **provides:** feature flag `metrics.performance_company_first_score` (desligada); parâmetro `$incluirEmpresasScore`; payload aditivo `empresas_score`/`componentes.var_margem_pp`; `cacheKey()` em `v14`
- **affects:** `app/Services/DesempenhoScoreService.php` (primeira fase que o modifica de propósito desde a criação do gate de hash na Fase 117)

**Tech stack:** nenhuma dependência nova (`composer.json`/`package.json` inalterados).

**Key files:**
- Created: `tests/Feature/Phase120/PayloadBaselineFlagOffTest.php`
- Modified: `app/Services/DesempenhoScoreService.php`, `config/metrics.php`, `tests/Feature/DesempenhoShopeeScoreTest.php`, `tests/Feature/Phase116/NpsFloorDesempenhoTest.php`, `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php`, `tests/Feature/V18/DesempenhoMetadadosCacheTest.php`, `tests/Feature/DesempenhoEvolucaoTest.php`, `tests/Feature/DesempenhoScoreSnapshotTest.php`

**Metrics:**
- Duration: ~1h30 (execução completa das 3 tasks + investigação do achado de margem)
- Tasks: 3/3
- Files touched: 9 (1 criado, 8 modificados)
- Completed: 2026-07-30
