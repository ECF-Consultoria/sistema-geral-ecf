---
quick_id: 260810-mbv
slug: margem-no-mes-corrente
description: Trazer a margem no mes corrente na tela Desempenho
date: 2026-08-10
status: complete
commits:
  - 3e6d0eab fix(desempenho) margem volta a aparecer no mes corrente
  - 1c127111 test(119) rotaciona o gate de hash e alinha os testes de Shopee
---

# Quick 260810-mbv — SUMMARY

Item 1 da seção **Desempenho** do PDF "Demandas e Fluxos – Sistema ECF"
(Maycon). Esclarecido com ele antes de começar: a margem não vinha errada —
não vinha **no mês atual**.

## O que era

`MetricPeriodResolver` resolve o mês corrente como
`comparison_mode='same_interval_previous_month'`, e o `diff_pp` da margem só
nascia em `previous_equal_length_window`. Cadeia do sumiço:
`diff_pp=null` → `margem_var_pp=null` → `margem_pontos=null` → coluna Margem
do ranking, card do profissional e célula da tabela por empresa vazios o mês
inteiro.

## Por que o gate existia (e por que a saída não era removê-lo)

O `prev` que a Adman devolve é a janela **imediatamente anterior**, não o mesmo
intervalo do mês passado. Medido ao vivo em 2026-08-10 (LUCCMAX, cust
1039099160):

| janela | value | prev (Adman) |
|---|---|---|
| 2026-08-01..10 | 27,23 | 22,51 |
| 2026-07-01..10 | 21,64 | 19,87 |

Aceitar o `prev` cru daria **+4,72 p.p.** quando o correto contra o baseline
que o faturamento já usa é **+5,59 p.p.**

## O que ficou

`AdmanMetricDiffService::fetchMargemPctBaseline()` lê da própria Adman a margem
% da janela `baseline_start..baseline_end` do período, e `resolveMargemPct()`
faz `diff_pp = atual − baseline`, com `prev_value` apontando para a mesma
janela (senão o "antes → depois" da tabela não fecharia com o p.p. ao lado).
`diff_source='adman_janela_baseline'`.

- O hotfix de 2026-07-24 continua respeitado: os dois lados da subtração são
  valor **nativo** da Adman. Muda a janela apontada, não a fonte.
- `diff_pct` (variação relativa, metadado legado que alimenta
  `componentes.var_margem_pct` e `nota_final_legado`) segue `null` no modo
  operacional — de propósito, para não alargar a superfície.
- Baseline indisponível → volta exatamente ao comportamento anterior
  (fail-open, nunca inventa variação).
- **Mês fechado / competência de bônus: intocado.**

Bumps: `adman:diff:v6` → `v7` e `desempenho.compute.v17` → `v18`.

## Verificação

Dado REAL da Adman, período 2026-08-01..10 vs baseline 2026-07-01..10:

| empresa | atual | baseline | p.p. |
|---|---|---|---|
| LUCCMAX Luccauto Itajaí | 27,23 | 21,64 | 5,59 |
| KAPRAKAZA.COM | 24,77 | 22,78 | 1,99 |
| CARAIBAALUMINIO alumen | 41,04 | 25,35 | 15,69 |
| JBDECORHOME JACOBINI | 41,52 | 31,58 | 9,94 |

Antes: as quatro devolviam `—`. O LUCCMAX fecha com a conta manual.

Suíte `AdmanMetricDiffServiceTest`: 27/27. Suítes
Phase119 + Phase120 + V18: **23 falhas → 6**.

## Achado colateral: o gate de hash da Fase 119 estava vermelho antes desta quick

A constante `HASH_DESEMPENHO_SCORE_SERVICE` dos 5 arquivos da Phase119 ainda
apontava para o hash da Fase 119.1 (`e5e65532`), enquanto o arquivo em HEAD já
estava em `86f61e3b` — as Fases 120/122 e a troca de método da nota (v17,
2026-08-05) mexeram no `DesempenhoScoreService.php` sem rotacionar. **17 testes
falhavam na primeira asserção**, mascarando tudo que vinha depois.

Rotacionado. Com o gate verde, 3 testes revelaram contrato revogado em
2026-08-05: loja Shopee entrando na margem com placeholder fixo de 1,0. Hoje
ela fica fora do denominador (`margem_pontos=null`,
`margin_source='sem_margem_shopee'`, `componentes_esperados=2`). Alinhados ao
código, sem afrouxar o que guardavam.

## O que NÃO foi feito

- **Nenhuma consolidação de competência.** Mês fechado lê snapshot congelado —
  a mudança não altera competência alguma até alguém rodar
  `desempenho:consolidar-mes`, e isso mexe em pagamento.
- **Sem deploy** (não autorizado).
- **Sem `npm run build`** — nenhum arquivo de front foi tocado; o JSX já lê
  `margem_var_pp`/`margem_pontos`.
- **6 falhas pré-existentes deixadas como estão** (`CarteiraPeriodoDiffTest` 2,
  `ConsolidarMesJanelaNpsTest` 2, `DesempenhoPeriodoOficialTest` 1,
  `JanelaNpsBonusTest` 1). Todas cobram o `calculated_fallback` local da
  margem, revogado em 2026-07-24, ou a nota antiga — confirmadas idênticas na
  baseline com as mudanças em stash. Consertar é outra tarefa.

## Pendente da mesma demanda (Desempenho, PDF do Maycon)

- Item 2 — nota final sempre ÷3, indicador ausente = 0 (decisão dele hoje).
  **Muda bônus**: carteira só-Shopee passa a ter teto de 3,33.
- Item 3 — marketplace da conta na tabela por empresa e no card do profissional.

## DEPLOYADO em 2026-08-10

Deploy **isolado** junto com as quicks `260810-mt8` e `260810-n5b`:
`b9c3ca90..2c60d5cc`, `deploy.sh` exit 0, **"Nothing to migrate"**.

Isolamento foi necessário e deliberado: a árvore local tinha **33 commits não
publicados**, e 24 deles eram a fase 134 (Meus Anúncios) de outra sessão, em
andamento, **com duas migrations**. Um push da `main` levaria tudo. A saída foi
worktree a partir de `origin/main` + cherry-pick dos 9 commits de Desempenho,
com o diff dos arquivos tocados contra a `main` local **vazio** (paridade byte
a byte com o código testado). Nenhum arquivo de código colidia com a 134 — a
única interseção era o `.planning/STATE.md`, que auto-mergeou.

Verificado em produção por reconsulta: HEAD `2c60d5cc`, workers RUNNING com
uptime de 25s (prova de que a última linha do script rodou), smoke 302/200/302
e **zero** `production.ERROR` desde o corte do deploy.

**A margem no mês corrente rodou em produção com dado real** — período
2026-08-01..10 vs baseline 2026-07-01..10, `diff_source='adman_janela_baseline'`
nas três empresas medidas: LUCCMAX 26,43 vs 21,64 = **+4,79 p.p.**, KAPRAKAZA
+1,71, CARAIBAALUMINIO +15,23. (Os valores diferem levemente da medição local
de horas antes porque a Adman reprocessa o dia corrente — o que importa é a
fonte da variação, que passou a existir.)
