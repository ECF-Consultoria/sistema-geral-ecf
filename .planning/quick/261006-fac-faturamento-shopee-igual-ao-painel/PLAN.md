---
quick_id: 261006-fac
slug: faturamento-shopee-igual-ao-painel
date: 2026-10-06
type: quick
status: pending
---

# O faturamento da Shopee no sistema passa a ser o do painel da Shopee

## A decisão

**Usuário, 2026-10-06, literal:** *"Quero que o faturamento das empresas no sistemas seja exatamente
igual ao faturamento mostrado no painel da shopee, se é com frete ou sem frete não importa."*

Isto **supera** a decisão anterior de somar `total_amount` (que inclui frete em parte dos pedidos),
registrada em `.planning/learnings/shopee-conexao-e-faturamento.md`. ⚠️ **Acrescente** o episódio ao
learning — não reescreva a história, como já foi feito nos episódios anteriores daquele arquivo.

## O que estava errado — medido contra a API real, não re-investigar

`ShopeeService::fetchOrdersSummary()` (`app/Services/Shopee/ShopeeService.php`, ~linha 458) soma
`total_amount` dos pedidos e pula `UNPAID|CANCELLED|IN_CANCEL`. As **duas** coisas estão erradas:

1. **O painel conta no PAGAMENTO.** Pedido pago e cancelado DEPOIS continua sendo faturamento. O
   filtro por status descartava isso: na Camillo Matriz em 30/09 foram R$ 1.434,94 em dois pedidos
   pagos e cancelados pelo comprador em seguida (R$ 1.398,13 e R$ 36,81).
   ⚠️ Provado que o painel conta o pedido pago **por inteiro**: descontar o item cancelado
   (`cancelled_qty`/`returned_qty`) **passa do alvo em 16,6%**. Portanto **não desconte**.
2. **O campo certo é o item, não o pedido.** O painel usa a soma dos itens com desconto, sem frete.
   O `total_amount` às vezes fica acima do preço dos itens e às vezes abaixo (na ITUFARMA um pedido
   tinha total R$ 31,42 contra R$ 48,46 de item — 54% de diferença).

## A regra nova

```
revenue = Σ  (pedidos com `pay_time` não vazio)
            Σ  (itens)  model_discounted_price × model_quantity_purchased
```

- **sem frete**, **sem filtro de status**, **sem descontar item cancelado/devolvido**
- a janela do dia continua `create_time` em **BRT (−03:00)** — cinco fusos testados, só o BRT fecha
  (UTC erra +12%, GMT+8 erra −16%)
- `orders_count` passa a contar os **pedidos pagos**; `sold_quantity`, as quantidades deles. Os três
  saem do mesmo laço hoje — mantenha os três coerentes com a regra nova.
- ⚠️ **PRESERVAR** a trava de truncamento com `Log::error` (quick 261006-dv3): ela não muda.
- `response_optional_fields` precisa passar a pedir `pay_time` além do que já pede.

## Evidência (setembro/2026 inteiro, contra a planilha manual do time)

| loja | planilha | regra ATUAL | regra NOVA |
|---|---|---|---|
| Edumac Parts #144 | 21.149,30 | −6,09% | **0,00% (ao centavo)** |
| Camillo Filial RS #358 | 20.330,13 | −6,75% | **0,00% (ao centavo)** |
| Camillo Matriz #1 | 147.312,67 | −13,71% | −0,44% |
| Tuki Pet #364 | 79.910,90 | −5,92% | +0,30% |
| Interior Magazine #370 | 132.835,11 | −1,24% | +0,49% |
| Camillo Filial SC #131 | 268.932,28 | −10,07% | +0,82% |
| Itadecor Magazine #369 | 55.891,19 | −1,77% | +1,32% |
| Gran Belo #212 | 311.945,63 | −7,33% | +5,53% (único fora) |

Dias isolados lidos pelo usuário no painel: Camillo Matriz 30/09 → painel R$ 8.953,89 (nosso
R$ 6.953,31, regra nova R$ 9.133,89); ITUFARMA1 #225 30/09 → painel R$ 603,72 (nosso R$ 597,17,
regra nova R$ 609,13).

⚠️ O resíduo é pequeno e **sempre para cima** (provavelmente desconto aplicado no pedido, não no
item). Fica como pendência conhecida — **não tente fechá-lo neste quick.**

## T1 — A coleta

Só `fetchOrdersSummary()`. ⛔ **É o ponto único**: os 11 consumidores leem
`shopee_metrics.revenue` (`FechamentoRollupService`, `ConsolidarMesFechamento`,
`VerificarConsolidacaoFechamento`, `FechamentoConferenciaFaturamentoService`, `AdminController`,
`DashboardController`, `PortfolioController`, `ShopeeMetricDiffService`, `RelerDiasShopee`,
`ShopeeMetric`), então corrigir a coleta propaga para fechamento, dashboard, carteira e desempenho de
uma vez — era exatamente o pedido do usuário ("não aplique a correção num ponto só").

⛔ **Não alterar nenhum desses consumidores.**
⛔ Não tocar em `FechamentoFaixaResolver::classificar()`, `FechamentoSnapshotWriter`,
`FechamentoEmpresasDoMes`, `podeUsarApiDaAdman()`.

Deixe no docblock do método **por que** mudou, com a data e os números da Camillo Matriz.

## T2 — Os testes

`tests/Feature/ShopeeMetricsTest.php` e `tests/Feature/ShopeeRelerDiasTest.php` afirmam a regra
ANTIGA. ⛔ **Não apague**: reescreva preservando o que segue valendo (o truncamento grita; dia sem
venda; janela BRT) e troque a expectativa, registrando no docblock a data e a medição.

⚠️ Confira também `DesempenhoShopeeScoreTest`, `PortfolioShopeeCarteiraTest`,
`ShopeeSyncAdsCommandTest`, `WarmShopeeDiffCacheCommandTest` — se alguma fixture assumir o valor
antigo, ajuste a fixture, **nunca** o invariante.

Casos novos obrigatórios:

| caso | espera |
|---|---|
| pedido pago e depois `CANCELLED` | **entra** no faturamento |
| pedido `UNPAID` sem `pay_time` | **não** entra |
| `total_amount` ≠ soma dos itens (fixture onde DIFEREM) | vale a soma dos **itens** |
| item com `cancelled_qty` > 0 | **não** é descontado |
| pedido com 2 itens e quantidade > 1 | soma preço × quantidade |
| `orders_count` / `sold_quantity` | coerentes com a regra nova |

## Gate

`--filter="Shopee|Phase158|Phase60"` — rodar **antes** de editar (para ter a referência) e ao final.
Capture o exit **antes** de qualquer pipe.

## Fora de escopo

⛔ Nada de produção, VPS, `plink`, `pscp`, `.env`, deploy ou `cache:clear`. O classificador bloqueia
subagente nesses comandos de qualquer forma. A **recoleta de setembro** das 19 empresas e o refazer do
fechamento são passo humano separado, feito pelo orquestrador depois do deploy.

## Restrições de árvore

⚠️ Árvore compartilhada com outro dev e com outras sessões. Nunca `git add -A` / `git add .` /
`git commit -a` / `git stash`. `git status --porcelain app/ tests/ .planning/` antes de cada commit e
commitar **só** pelos seus caminhos; arquivo novo precisa de `git add -- <caminho>`.
`tests/Feature/CompanyPortfolioAccessTest.php` não é seu. Não use `gsd-sdk query state.advance-plan`.

PHP: `C:\xampp\php\php.exe`. Comentários, copy e commits em pt-BR, terminando com
`Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

Ao final grave `SUMMARY.md` nesta pasta com os números literais da tabela de evidência.
