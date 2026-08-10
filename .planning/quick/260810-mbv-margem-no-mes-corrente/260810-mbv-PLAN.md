---
quick_id: 260810-mbv
slug: margem-no-mes-corrente
description: Trazer a margem no mes corrente na tela Desempenho
date: 2026-08-10
status: planned
---

# Quick 260810-mbv — Margem no mês corrente

Demanda do Maycon (PDF "Demandas e Fluxos – Sistema ECF", seção Desempenho,
item 1): *"Corrigir o campo de Margem"*. Esclarecido com ele em 2026-08-10:
o valor **não está errado — está ausente no mês atual**, e deveria aparecer.

## Diagnóstico (causa raiz confirmada)

`MetricPeriodResolver::resolveCurrentMonth()` devolve
`comparison_mode = 'same_interval_previous_month'`, e
`AdmanMetricDiffService::resolveMargemPct()` só produz `diff_pp` quando o modo
é `previous_equal_length_window` (gate D-07/MPP-02 da Fase 117).

Cadeia do sumiço:
`diff_pp = null` → `CompanyScoreService.margem_var_pp = null` →
`margem_pontos = null` → coluna Margem do ranking, card do profissional e
célula da tabela por empresa todos vazios no mês em curso.

O gate NÃO é descuido: o `prev` que a Adman devolve é a **janela
imediatamente anterior**, não o mesmo intervalo do mês passado. Medido ao vivo
em 2026-08-10 (LUCCMAX, cust 1039099160):

| janela | value | prev (Adman) |
|---|---|---|
| 2026-08-01..10 | 27,23 | 22,51 |
| 2026-07-01..10 | 21,64 | 19,87 |

O `prev` de agosto (22,51) **não** é a margem de 01–10/jul (21,64). Usar o
`prev` nativo daria +4,72 p.p. quando o correto contra o baseline que o
faturamento já usa é **+5,59 p.p.**

## Decisão

No modo operacional, pedir à Adman a margem % da **janela baseline exata**
(`baseline_start..baseline_end` do próprio `$periodo`) e fazer
`diff_pp = value_atual − value_baseline`.

Continua valendo o hotfix de 2026-07-24 ("variação de margem SEMPRE do valor
que a Adman disponibiliza, nunca cálculo local"): os dois lados da subtração
são valores nativos da Adman — é a mesma operação que a Fase 117 já faz com
`value − prev_value`, só que apontada para a janela certa.

`prev_value` passa a refletir essa mesma janela, senão a tabela por empresa
exibiria "antes → depois" que não fecha com o p.p. ao lado.

`diff_pct` (variação relativa) **continua null** no modo operacional — é o
metadado legado que alimenta `componentes.var_margem_pct` e
`nota_final_legado`; mexer nele alargaria a superfície sem necessidade.

**Fora de escopo:** mês fechado / competência de bônus. O caminho
`previous_equal_length_window` fica byte a byte como está.

## Custo operacional

+1 chamada ao endpoint detalhado da Adman por empresa **apenas no mês
corrente**, ~2s medidos, com cache próprio de 1 dia
(`adman:account_metrics_detailed:...`) e o cache do diff por período. O
endpoint já tem retry com backoff + jitter (6 tentativas) contra 429.

## Tarefas

### T1 — Backend: margem da janela baseline no modo operacional
- `app/Services/Metrics/AdmanMetricDiffService.php`
- Helper `fetchMargemPctBaseline()` — lê `percentageMargin.value` da janela
  baseline; fail-open (null).
- `resolveMargemPct()` ganha 3º parâmetro `?float $baselinePct`.
- Bump `adman:diff:v6` → `v7` (entradas antigas do mês corrente têm
  `prev_value` nativo e `diff_pp` null — shape semanticamente diferente).
- **verify:** `test_b` e `test_p` refletem o novo contrato; janela-igual
  inalterada.

### T2 — Bump da chave de cálculo do desempenho
- `app/Services/DesempenhoScoreService.php`: `desempenho.compute.v17` → `v18`
- Testes com a string hardcoded: `DesempenhoShopeeScoreTest`,
  `Phase116/NpsFloorDesempenhoTest`, `Phase96/NpsInvalidacaoRespostaTest`,
  `V18/DesempenhoMetadadosCacheTest`.
- **verify:** suíte dos arquivos acima passa.

### T3 — Testes do novo comportamento
- `tests/Feature/V18/AdmanMetricDiffServiceTest.php`
- Fake por range (a resposta de `*/accounts/*/metrics*` precisa variar entre
  janela atual e baseline, senão o teste prova zero).
- Cenários: baseline presente → `diff_pp` e `prev_value` da janela baseline;
  baseline indisponível → mantém o comportamento de hoje (`diff_pp` null).
- **verify:** `php artisan test --filter=AdmanMetricDiffService`

## must_haves

- `diff_pp` nasce no mês corrente quando a Adman responde a janela baseline
- `prev_value` do mês corrente = margem % da janela baseline (tela fecha)
- mês fechado/bônus inalterado
- nenhuma consolidação de competência rodada
