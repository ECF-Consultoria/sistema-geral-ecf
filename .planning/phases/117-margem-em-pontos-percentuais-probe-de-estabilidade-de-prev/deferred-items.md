# Deferred Items — Fase 117 Plano 01

Descobertas fora do escopo deste plano durante a Task 3 (gate de não-regressão).
Nenhum destes itens foi corrigido — todos são **pré-existentes** e não relacionados
às mudanças de `prev_value`/`diff_pp` desta fase. Confirmado bit-a-bit: os mesmos
testes falham exatamente da mesma forma rodando contra o código de
`AdmanMetricDiffService.php`/`ShopeeMetricDiffService.php` de ANTES da Fase 117
(commit `a166f8da`, checkout temporário via `git show`, restaurado depois).

## Falhas pré-existentes confirmadas (não são regressão da Fase 117)

### `tests/Feature/DesempenhoShopeeScoreTest` (3 falhas)
- `so performance regressao zero margem pontos e nota identicos ao...`
- `misto ml shopee margem pontos blend ponderado`
- `invalidacao empresa shopee nao infla denominador do blend`

### `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest` (4 falhas)
- `comando grava snapshot com mes referencia do m...`
- `comando aceita mes flag yyyy mm`
- `idempotencia do command consolidar mes`
- `comando popular ranking pos por mes referencia`

### `tests/Feature/Phase74/DesempenhoScoreServiceTest` (4 falhas)
- `fixture carlos retorna nota 4 42 basico`
- `var margem usa adman como fonte canonica`
- `var margem nao inverte sinal quando janela atual tem d...`
- `2 meses consecutivos intermediario promove para maximo`

### `tests/Feature/PublicacaoDesempenhoRouteTest` (1 falha)
- `user com mlb dashboard acessa rota e recebe 200` — 403 em vez de 200 (parece
  problema de permissão/role não relacionado a métricas de diff)

### `tests/Feature/V16/DesempenhoElegibilidadeTest` (1 falha)
- `misto e official com financeiro so do vinculo elegivel` — espera
  `var_margem_pct = 2.8`, recebe `null`

### `tests/Feature/V18/DesempenhoPeriodoOficialTest` (1 falha)
- `var margem pct cai no calculated fallback quando diff au...` — espera `20.0`,
  recebe `null`. **Causa raiz aparente:** este teste assume que
  `resolveMargemPct()` ainda tem um `calculated_fallback` para margem %, mas o
  hotfix `a413e823` (2026-07-24) REMOVEU esse fallback deliberadamente (margem %
  agora é sempre `null` quando a Adman não manda `.diff`, nunca calculada
  localmente). Este teste ficou desatualizado em relação ao hotfix e nunca foi
  corrigido — não é causado pela Fase 117.

### `tests/Feature/Phase61/PortfolioMultiFonteE2ETest` (2 falhas)
- `flag on portfolio carteiras admin expoe source counts por user`
- `flag off portfolio carteiras admin nao expoe source counts`
- Erro: `Property [user_portfolios] does not have the expected size. Failed
  asserting that actual size 0 matches expected size 1.` — parece problema de
  setup/fixture de carteira, não relacionado ao shape de métricas.

### `tests/Feature/Phase61/PortfolioSourceEnrichmentTest` (1 falha)
- `flag on portfolio own admin enriquece user portfolios com source counts` —
  mesmo erro de `user_portfolios` tamanho 0.

### `tests/Feature/V18/CarteiraPeriodoDiffTest` (2 falhas)
- `margem variacao pct fallback calculado mes fechado` — espera `9.41`, recebe
  `null`. Mesma causa raiz do `DesempenhoPeriodoOficialTest` acima: o teste
  assume um `calculated_fallback` de margem % que foi removido pelo hotfix
  `a413e823` de 2026-07-24 — anterior à Fase 117.
- `variacao margem mes em curso byte identico ao calculo manual` — espera
  `25.0`, recebe `null`. Mesma causa raiz.

## Método de verificação

Para cada grupo acima, os arquivos `app/Services/Metrics/AdmanMetricDiffService.php`
e `app/Services/Metrics/ShopeeMetricDiffService.php` foram temporariamente
revertidos (via `git show a166f8da:<path>`) para o estado imediatamente anterior
ao primeiro commit desta fase (`3137814a`), a suíte relevante foi re-executada, e
a MESMA falha ocorreu com o MESMO texto de erro. Os arquivos foram restaurados
em seguida (`git diff --stat` confirmou zero diff residual).

## Ação recomendada

Nenhuma ação nesta fase — fora de escopo (Rule/Scope Boundary do executor). Os
dois grupos com causa raiz identificada (`DesempenhoPeriodoOficialTest` e
`CarteiraPeriodoDiffTest`) parecem ser testes desatualizados em relação ao
hotfix `a413e823` (2026-07-24) e podem ser corrigidos numa tarefa dedicada de
limpeza de dívida técnica. Os demais (Shopee score, ConsolidarMes, Carlos
fixture, rota 403, PortfolioMultiFonte/SourceEnrichment) precisam de
investigação própria fora desta fase.
