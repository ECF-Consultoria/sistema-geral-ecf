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

---

## Bloqueio de publicação — achado sobre a Fase 116 (2026-07-28)

**NÃO é um item da Fase 117.** É um achado feito ao preparar o deploy da 117, sobre
trabalho da **Fase 116** (sessão paralela). Registrado aqui porque foi o que impediu a
publicação da 117 e o início da coleta do probe. **Nada foi corrigido — a decisão é da
sessão da Fase 116.**

### O achado

O agendamento diário em `routes/console.php` invoca o comando **sem `--mes`**:

```php
Schedule::command('nps:materializar-nao-respondidos --force')
    ->dailyAt('09:30')
```

E `NpsImputationService::materializarLote()` **só aplica filtro de data quando `$mes` é
não-nulo** (`app/Services/Nps/NpsImputationService.php:220-237`). Sem o parâmetro, a query
é `NpsSurvey::query()->whereNotNull('template_id')` — **todos os surveys da história**.

Com `--force`, o fluxo padrão **pula a confirmação** do operador
(`app/Console/Commands/NpsMaterializarNaoRespondidos.php:143-155`) e segue direto para
gravar, estourar cache e **reconsolidar os snapshots de todas as competências afetadas**,
inclusive **fechadas**.

### Por que isso importa

A decisão travada **D1 da Fase 116** diz o oposto:

> "o comando de backfill roda primeiro em modo `--dry-run` produzindo um relatório
> antes/depois por pessoa e competência, para o usuário conferir o impacto **antes** de
> aplicar. Só aplica com confirmação explícita."

Em regime permanente o cron é inofensivo — `materializar()` nunca mexe em linha já
`definitivo`, então `totalMudancas === 0` e o comando sai na linha 126 sem reconsolidar
nada. **O risco está inteiramente na PRIMEIRA execução após o deploy**, com a tabela
`nps_imputed_assignments` vazia em produção: ela executaria o backfill retroativo inteiro,
sem humano no loop, reconsolidando snapshots de meses fechados.

E cairia em **29/07 09:30 BRT — dois dias antes do freeze de junho** (`desempenho:consolidar-mes`,
31/07 14h), que é justamente o número que paga o bônus.

O comentário acima do agendamento (`routes/console.php:186-197`) afirma que a execução
diária é inócua porque "a competência corrente ainda não tem snapshot mensal (mês aberto)".
Isso vale para a competência **aberta** — não para as **fechadas**, que a varredura sem
filtro também alcança.

### Estado verificado

- A migration `nps_imputed_assignments` **ainda não foi publicada** → a tabela não existe em
  produção → o backfill gated **não pode ter rodado** ainda.
- Portanto a primeira execução do cron seria, de fato, o backfill completo.

### Caminhos possíveis (decisão da sessão da Fase 116, não desta)

1. Escopar o agendamento com `--mes` da competência corrente, deixando o backfill
   retroativo como operação manual e gated (parece o mais alinhado com a D1).
2. Manter como está, mas rodar o backfill gated manualmente logo após o deploy, antes das
   09:30 do dia seguinte — janela curta e, até onde encontrei, não documentada.
3. Deployar sem o agendamento e ligá-lo depois que o backfill gated tiver sido conferido.

### Consequência para a Fase 117

O deploy é tudo-ou-nada nesta árvore compartilhada — não há como publicar só o probe.
Decisão do usuário em 2026-07-28: **não publicar** até a Fase 116 resolver isso. Como
efeito colateral, o probe **não alcança o freeze de junho de 31/07**, e junho congela com a
métrica de margem relativa atual.
