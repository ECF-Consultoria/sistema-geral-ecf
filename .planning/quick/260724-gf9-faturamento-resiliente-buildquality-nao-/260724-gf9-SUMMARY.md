# Quick 260724-gf9: Faturamento resiliente — buildQuality não trata fallback-diff como missing Summary

**One-liner:** `AdmanMetricDiffService::buildQuality()` agora conta `diff_pct` de fallback, não só `value` — sob rate-limit da Adman o baseline local deixa de ser apagado pelo `ERROR_SENTINEL`.

## O que foi feito

Sob rate-limit 429 da Adman, `AdmanMetricDiffService::compute()` apagava o baseline de empresas que TÊM dado local. Causa raiz: quando a leitura ao vivo falha, os `value` das 3 métricas ficam `null`, mas o `calculated_fallback` local preenche `diff_pct` corretamente a partir de `AdmanMetric` já sincronizado. Porém `buildQuality()` decidia o `status` contando só `value` → `comValue=0` → status `'missing'` → `compute()` gravava `ERROR_SENTINEL` no cache → a leitura seguinte devolvia `emptyMetrics()` (tudo `null`), jogando fora o `diff_pct` bom do fallback. Efeito observado em prod: `computeVarFaturamento` contava a empresa fora de `empresas_com_baseline`, de forma não-determinística (cada recompute rate-limitava empresas diferentes → contagem oscilava 15/18/21).

### Task 1 — `buildQuality()` considera `diff_pct` (não só `value`)

Em `app/Services/Metrics/AdmanMetricDiffService.php`, `buildQuality()` agora computa `$comDiff` (métricas com `diff_pct !== null`) além de `$comValue`. Regra do `status`:
- `'complete'` quando `$comValue === count(METRIC_KEYS)` (inalterado).
- `'missing'` SÓ quando `$comValue === 0 && $comDiff === 0` (nada usável — antes bastava `$comValue === 0`).
- `'partial'` no resto — inclui o caso novo (ao-vivo falhou mas o fallback local tem `diff_pct`).

`compute()` não foi tocado: a política de TTL por status (linhas ~185-192) já fazia a coisa certa — `'partial'` grava o resultado real (TTL curto, auto-cura); só `'missing'` grava o sentinela. `resolveField`/`fallbackSomaSimples`/`resolveMargemPct`/fórmula do diff/`cacheKey` — nenhum tocado.

### Task 2 — Teste de regressão

Adicionados 2 cenários em `tests/Feature/V18/AdmanMetricDiffServiceTest.php` (arquivo real da suíte — ver "Desvios"):

- **Cenário (m)** `test_m_falha_total_ao_vivo_com_dado_local_preserva_diff_pct_via_fallback`: os 2 endpoints Adman retornam 500 (simula rate-limit sustentado sem acionar o retry/sleep de 429), mas a empresa tem dado local denso (cobertura 100%) nas duas janelas. Comprova `revenue.diff_pct` não-nulo, `diff_source='calculated_fallback'`, `quality.status='partial'`. A 2ª leitura usa uma instância NOVA do service (sem o memo em memória, só o cache persistente do Laravel) e continua devolvendo o mesmo `diff_pct` — prova que o `ERROR_SENTINEL` não foi gravado.
- **Cenário (n)** `test_n_empresa_sem_dado_nenhum_continua_missing`: guarda — empresa sem NENHUMA linha local e sem ao-vivo continua `'missing'` (comportamento preservado).

## Deviations from Plan

### Auto-fixed Issues

Nenhuma — fix cirúrgico implementado exatamente como especificado no plano.

### Ajustes de execução (não são desvios de Rule 1-4, apenas notas)

**1. Caminho do arquivo de teste divergente do plano**
- O plano referenciava `tests/Unit/Metrics/AdmanMetricDiffServiceTest.php`, mas esse arquivo não existe. A suíte real do service está em `tests/Feature/V18/AdmanMetricDiffServiceTest.php` (Fase 101/110). Os novos testes foram adicionados lá, seguindo o padrão dos cenários (a)-(l) já existentes (fixtures reais, `Http::fake`, helpers `semearAdmanMetric`).

**2. 500 em vez de 429 no fake HTTP dos novos testes**
- `AdmanService::fetchPerformance()` faz retry com `sleep(2s)`/`sleep(4s)` em respostas 429 reais (até 6s de sleep por chamada). Para não deixar a suíte lenta, os testes novos usam status 500 (mesmo `catch (\Throwable)` em `compute()`, efeito idêntico no service sob teste). Documentado em comentário no próprio teste.

## Verificação executada

- `tests/Feature/V18/AdmanMetricDiffServiceTest.php`: **16/16 verde** (14 cenários pré-existentes + 2 novos), 61 assertions.
- Regressão ampla `--filter="Desempenho|V18|V16"`: **301 passed, 1 failed** (302 total, 1336 assertions).
  - Falha: `Tests\Feature\PublicacaoDesempenhoRouteTest > user com mlb dashboard acessa rota e recebe 200` (espera 200, recebe 403). **Pré-existente e fora de escopo** — confirmado via `git show --stat 9444fcef` (commit da Task 1) que o fix tocou SOMENTE `app/Services/Metrics/AdmanMetricDiffService.php`; o teste que falha é sobre roteamento/permissão (`/publicacao/desempenho`), sem qualquer relação com `AdmanMetricDiffService`/`buildQuality`. Reproduz isoladamente (fora do filtro amplo também), não é efeito de ordem de execução. Registrado aqui para rastreabilidade — não corrigido (fora do escopo desta quick task).

## Must-haves (do plano)

- [x] Sob falha da Adman, empresa com dado local mantém `revenue.diff_pct` (fallback) e NÃO é apagada por `ERROR_SENTINEL` — coberto por cenário (m).
- [x] `empresas_com_baseline` deixa de oscilar por rate-limit (determinístico do local) — consequência direta do fix; a oscilação vinha do `ERROR_SENTINEL` apagando o fallback determinístico.
- [x] Empresa genuinamente sem dado segue `'missing'` — coberto por cenário (n).
- [x] NÃO deployado (fica para o próximo deploy coordenado, junto do badge + Fase 111, conforme instrução do plano).

## Key Files

- `app/Services/Metrics/AdmanMetricDiffService.php` (modificado — `buildQuality()`)
- `tests/Feature/V18/AdmanMetricDiffServiceTest.php` (modificado — cenários (m) e (n))

## Commits

- `9444fcef` — `fix(metrics): buildQuality nao trata diff_pct de fallback como missing`
- `32279ded` — `test(metrics): cobre rate-limit-com-local preservando baseline via fallback`

## Known Stubs

Nenhum.

## Threat Flags

Nenhum — mudança é puramente lógica dentro de um service já existente, sem nova superfície de rede/auth/schema.
