# Phase 117: Margem em pontos percentuais + probe de estabilidade de `prev` - Context

**Gathered:** 2026-07-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Esta fase entrega **duas coisas, e nada além disso**:

1. **Contrato de métricas ampliado, 100% aditivo.** `AdmanMetricDiffService` passa a expor `prev_value` (nas três métricas) e `diff_pp` (só em `contribution_margin_pct`), sem alterar `value`, `diff_pct`, `diff_source` nem o comportamento de nenhum consumidor atual. Ninguém lê `diff_pp` ainda — quem vai ler é a Fase 119.

2. **Probe de estabilidade de `percentageMargin.prev`, com gate humano.** A decisão D1 da milestone amarra o pagamento de bônus num campo que nunca foi validado, num histórico que já teve dois reverts em 4 dias. Esta fase **mede** essa estabilidade e leva o relatório ao usuário.

**NÃO está nesta fase:** consumir `diff_pp` para calcular nota (Fase 119), mudar a régua (travado em D2), mudar a baseline (travado em D4), congelar `prev` em snapshot ou reintroduzir cálculo local (ver `<deferred>`).

</domain>

<decisions>
## Implementation Decisions

### Critério de aprovação do gate

- **D-01 · A métrica de aprovação é "zero flip de nota", não tolerância em pp.** O probe reprova se **qualquer empresa da amostra mudar de faixa da régua** entre duas leituras. Justificativa: variação de 0,03 pp longe de uma fronteira é irrelevante; 0,03 pp em cima da fronteira `+1` muda a nota de 3 para 4 e muda quanto se paga. Fronteiras da régua (em pp): `−5`, `−2`, `+1`, `+4`.

- **D-02 · Mínimo de 5 leituras espalhadas em 24-48h, com pelo menos uma proposital durante sync concorrente.** O modo de falha conhecido é rate-limit 429 por concorrência com `[MLB SyncTodasVendas]` / `[MLB SyncPub]` na mesma API-key — **não** aparece em chamadas seguidas na mesma janela. Cronograma alvo: madrugada (API ociosa), manhã durante o sync Adman, ~11:20 BRT (`adman:sync-margem`), tarde em pico, e uma repetição +24h.
  **Este desenho existe especificamente para não repetir o erro de 23/07**, quando "3 chamadas deram valores idênticos" concluiu *"o dado não flutua"* e 4 dias depois virou revert.

- **D-03 · Cobertura mínima de `prev` não-nulo: 80%.** Reusa `AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA = 0.8` (linha 70) em vez de inventar patamar novo — evita dois conceitos concorrentes de "cobertura suficiente" dentro do mesmo serviço. Abaixo disso o gate reprova, mesmo que os valores presentes sejam estáveis.

- **D-04 · População medida: carteiras do Luiz (user 3) e Danilo (user 15), competência fechada.** São as duas carteiras do incidente de 23/07, com cobertura local de margem já verificada (99,8% e 100%), e existe leitura histórica delas (`+6,83` / `−3,25` / `+8,63`) para comparar. Amostra enviesada só nas 5 empresas que oscilaram (LUCCAUTO, LYAMDECOR, GARCIA, Hunter, OESTE) foi **rejeitada** — boa pra achar problema, inválida pra aprovar. Varrer todas as empresas Adman também foi rejeitada: o volume do próprio probe viraria fonte de rate-limit e contaminaria a medição.

### Shape do contrato de métricas

- **D-05 · `prev_value` nas três métricas** (`revenue`, `contribution_margin_value`, `contribution_margin_pct`). A Adman já entrega `.prev` em todas — custo zero de chamadas — e o shape uniforme evita reabrir o serviço quando alguma tela quiser mostrar "de X para Y" no faturamento.

- **D-06 · `diff_pp` SÓ em `contribution_margin_pct`.** Pontos percentuais só existem para métrica que já é percentual. `revenue.diff_pp` seria reais, não pontos percentuais — o nome mentiria. Confirmado o gate de D-07 abaixo.

- **D-07 · `diff_pp` é calculado apenas quando `comparison_mode === 'previous_equal_length_window'` e `value` e `prev_value` são ambos numéricos. Fora disso, `null`.** (Herdado de MPP-02; repetido aqui porque é a invariante mais fácil de quebrar sem querer.)

- **D-08 · `quality` ganha indicador de cobertura de `diff_pp`, mas `status` NÃO muda.** Motivo estrutural: `quality.status` governa a política de TTL do cache em `compute()` (`partial` → 10 min, `complete` → 1440 min). Rebaixar para `partial` quando falta `diff_pp` faria empresa sem `prev` cair em TTL curto **permanentemente**, martelando a Adman de 10 em 10 minutos numa empresa que nunca vai ter `prev`. O indicador é informativo e serve para a Fase 121 agregar cobertura sem refazer as chamadas.

### Claude's Discretion

Áreas que o usuário não selecionou para discussão e delegou:

- **D-09 · Comando artisan novo e dedicado para o probe** (nome sugerido `adman:probe-margem-prev`), em vez de estender `mlb:inspecionar-adman` (dump bruto de um item — outra responsabilidade) ou `adman:warm-diff` (aquecimento de cache — outra responsabilidade).

- **D-10 · O probe PERSISTE cada leitura com timestamp antes de agregar.** Um probe que só imprime em stdout não sobrevive a uma execução de 48h em 5 janelas separadas. A agregação (detecção de flip de nota, cobertura) roda depois, sobre as leituras gravadas. Formato exato (tabela vs. arquivo) fica para o planner, mas a propriedade "cada leitura é um fato durável e re-agregável" é obrigatória.
  ⚠️ Nota de coerência com a memória do projeto: o resultado do gate deve ser conferido **por reconsulta ao dado persistido**, nunca por leitura de stdout — mesmo padrão que o gate `FIXMARG-03` já exige.

- **D-11 · O probe roda na VPS, contra a Adman real.** Medir estabilidade contra fixture ou ambiente local não significa nada — o que se quer medir é o comportamento da API sob contenção real.

- **D-12 · Se o gate REPROVAR, a fase ainda entrega o shape.** `prev_value`/`diff_pp` são aditivos e não quebram ninguém, então ficam. O que fica **bloqueado** é a Fase 119 consumir `diff_pp` para nota. Deliberadamente **não** embutir aqui nem o congelamento de `prev` em snapshot nem a volta ao cálculo local: são fases próprias, e a escolha entre elas depende de *como* o `prev` falhou. Reprovando, o relatório volta ao usuário para decisão.

### Folded Todos

- **`.planning/todos/pending/metrica-margem-bonus-fragil.md`** — "Métrica de margem do bônus é estruturalmente frágil" (criado 2026-07-23, criticidade alta). A **opção (A) pontos percentuais** deste todo é exatamente o que esta fase instrumenta; o probe de D-01..D-04 é o que valida a viabilidade da opção escolhida.
  **Fica FORA do escopo desta fase:** o item 2 da "Ação recomendada" do todo — o que fazer com o **freeze de junho/2026 em 31/07 14h BRT**. Esta fase não fica pronta a tempo e não resolve isso. O todo **permanece aberto**.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plano canônico e requirements
- `plano-implementacao-desempenho-por-empresa.md` §2.5 — fonte de faturamento e margem; explica por que `14,09` (`diff_pct`) **não** pode ser usado como pp
- `plano-implementacao-desempenho-por-empresa.md` §4 "Fase 1" — contrato recomendado com `prev_value`/`diff_pp` e as regras de quando calcular
- `.planning/REQUIREMENTS-v21.md` — MPP-01..MPP-06 (escopo desta fase), decisões travadas D1-D5 da milestone

### Decisões anteriores que esta fase toca (LER ANTES DE MEXER)
- `.planning/phases/110-fix-margem-adman-preferir-fallback-local-deterministico-blin/110-CONTEXT.md` `<decisions>` — root cause do rate-limit 429, gate `FIXMARG-03`, e a decisão de preferir fallback local (**posteriormente revertida**)
- `app/Services/Metrics/AdmanMetricDiffService.php:252-290` — hotfix `a413e823` de 2026-07-24 no docblock de `resolveMargemPct()`: *"usa SEMPRE o `.diff` nativo, NUNCA cálculo local"*. **A decisão D1 da milestone v21.0 reabre isso deliberadamente**, porque pp não é expressável pelo `.diff` nativo. O planner precisa atualizar esse docblock, não contorná-lo em silêncio.
- `.planning/phases/109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o/109-CONTEXT.md` `<decisions>` — placeholder de margem Shopee = 1.0 e a regra de que só-Shopee não cai em `blocked`/`partial`

### Pendência de negócio
- `.planning/todos/pending/metrica-margem-bonus-fragil.md` — opções (A)/(B)/(C) da métrica, números de referência do incidente, e o **prazo 31/07 14h BRT do freeze de junho que esta fase NÃO cobre**

### Testes que definem o comportamento atual
- `tests/Feature/V18/AdmanMetricDiffServiceTest.php` — fixture `percentageMargin.value=27,47` / `diff=14,09` / `prev=24,08`; cenários b, c, g, h, i, k, l foram atualizados para native-first em 24/07
- `tests/Feature/V18/AdmanMetricDiffBackfillTest.php` — comportamento de backfill do diff

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`AdmanMetricDiffService::emptyMetrics()` (linha 550)** — ponto único que define o shape vazio via `array_fill_keys(self::METRIC_KEYS, $vazio)`. Adicionar `prev_value`/`diff_pp` aqui propaga para todos os caminhos de retorno.
- **`AdmanMetricDiffService::buildResult()` (557) / `buildQuality()` (567)** — montagem e classificação centralizadas; o indicador de cobertura de D-08 entra em `buildQuality()`.
- **`AdmanMetricDiffService::resolveField()` (231) e `resolveMargemPct()` (272)** — os dois lugares que constroem o array por métrica. `resolveField` serve `revenue` e `contribution_margin_value`; `resolveMargemPct` é dedicado à margem % e é onde `diff_pp` nasce.
- **`app/Console/Commands/WarmAdmanDiffCache.php`** — comando existente que itera empresas e chama o diff service; bom modelo estrutural para o comando de probe (iteração, flags, logging), **sem** estender o próprio comando (D-09).
- **`app/Console/Commands/InspecionarAdman.php`** — mostra como acessar `AdmanService` e dumpar payload bruto; útil para confirmar que `.prev` chega no shape esperado.
- **`AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA = 0.8` (linha 70)** — patamar reusado por D-03.

### Established Patterns
- **Cache versionado por string na chave** — `adman:diff:v5:{marketplace}:{custId}:{start}:{end}:{cacheDay}` (linha 122). O bump para `v6` é obrigatório porque o shape muda (MPP-03).
- **Política de TTL por qualidade** — `missing` → `ERROR_SENTINEL` + 10 min; `partial` → 10 min; `complete` → 1440 min. **Esta é a razão estrutural de D-08.**
- **Memo por request** (`$this->memo[$cacheKey]`) protege a 2ª chamada ao mesmo (empresa, período) na mesma passada.
- **Comentários em pt-BR com data e origem da decisão** no docblock — convenção forte neste arquivo; o planner deve seguir ao documentar a reabertura do hotfix de 24/07.

### Integration Points
- **Consumidores de `metrics.*` não podem mudar de comportamento.** Adicionar chaves é seguro; qualquer código que faça destructuring posicional ou `array_keys()` estrito sobre o shape da métrica precisa ser verificado.
- **`ShopeeMetricDiffService`** precisa retornar `diff_pp = null` (MPP-05) para manter o shape simétrico entre as fontes, sem perturbar o placeholder 1.0 da Fase 109.
- **`MetricDiffDispatcher`** é o ponto por onde `DesempenhoScoreService` chega às duas fontes — não deve precisar de mudança nesta fase.

</code_context>

<specifics>
## Specific Ideas

- **Caso âncora numérico obrigatório em teste** (do plano §2.5 e da fixture existente): `percentageMargin.value = 27,47`, `prev = 24,08`, `diff = 14,09` → `diff_pp = 3,39` e `diff_pct = 14,09` (inalterado). O teste deve provar explicitamente que `diff_pp` **não** deriva de `diff_pct`.
- **Segundo caso âncora, da régua** (§7 dos critérios de aceite): margem `15,0% → 18,2%` produz `margem_var_pp = 3,2`, que na régua reusada dá nota `4`.
- **Número de referência para o relatório do probe:** a leitura rápida da carteira do Luiz no todo deu `~−0,59 pp`, que na régua reusada cai em nota `3` — contra régua `5` no snapshot congelado atual e régua `1` no cálculo local determinístico. O relatório do probe deve tornar essa comparação visível.

</specifics>

<deferred>
## Deferred Ideas

- **Congelar `percentageMargin.prev` em snapshot diário próprio** — só faz sentido se o probe reprovar por instabilidade intermitente. Fase própria, decidida com o relatório na mão (ver D-12).
- **Reintroduzir cálculo local de margem a partir de `adman_metrics`** — foi revertido em 24/07 por divergir da Adman (Relojoaria Wenus: Adman +15%, local −7,5%). Só volta à mesa se o probe reprovar E o modo de falha indicar que a Adman é a fonte errada. Fase própria.
- **Recalibrar a régua de margem para pp** — travado em D2 da milestone (régua atual reusada conscientemente). Se a distribuição medida na Fase 121 mostrar compressão inaceitável, vira pauta de diretoria numa milestone futura.
- **Expor `diff_pp`/`prev_value` na UI** — Fase 123.
- **Decidir o freeze de junho/2026 (prazo 31/07 14h BRT)** — decisão de negócio imediata, fora desta fase e fora desta milestone. Continua aberta em `.planning/todos/pending/metrica-margem-bonus-fragil.md`.

</deferred>

---

*Phase: 117-margem-em-pontos-percentuais-probe-de-estabilidade-de-prev*
*Context gathered: 2026-07-27*
