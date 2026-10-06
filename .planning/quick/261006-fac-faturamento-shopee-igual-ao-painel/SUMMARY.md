---
quick_id: 261006-fac
slug: faturamento-shopee-igual-ao-painel
date: 2026-10-06
type: quick
status: complete
commits:
  - f633edfd fix(quick-261006-fac) — a coleta
  - 0b5370d9 test(quick-261006-fac) — os testes
---

# O faturamento da Shopee no sistema passa a ser o do painel da Shopee

O `ShopeeService::fetchOrdersSummary()` deixou de somar `total_amount` dos pedidos
com status pago e passou a somar os **itens** de todo pedido com `pay_time`:

```
revenue = Σ (pedidos com `pay_time` não vazio)
            Σ (itens)  model_discounted_price × model_quantity_purchased
```

Sem frete, **sem filtro de status**, **sem descontar item cancelado/devolvido**.
`orders_count` conta os pedidos pagos e `sold_quantity`, as quantidades deles.
A janela do dia continua em **BRT (−03:00)**.

Decisão do usuário em 2026-10-06, literal: *"Quero que o faturamento das empresas
no sistemas seja exatamente igual ao faturamento mostrado no painel da shopee, se
é com frete ou sem frete não importa."* Isto **supera** a decisão anterior de
manter `total_amount` com frete.

## O que mudou

### T1 — a coleta (`f633edfd`)

`app/Services/Shopee/ShopeeService.php`, só `fetchOrdersSummary()`:

- o laço do detalhe passa a filtrar por `empty($order['pay_time'])` em vez de
  `in_array($status, ['UNPAID','CANCELLED','IN_CANCEL'])`;
- o valor sai de `model_discounted_price × model_quantity_purchased` de cada item,
  no mesmo laço que já acumulava `sold_quantity`;
- `pay_time` acrescentado ao `response_optional_fields`
  (`total_amount,order_status,item_list,pay_time`);
- docblock reescrito com a data, o porquê e os números da Camillo Matriz;
- a trava de truncamento com `Log::error` (quick 261006-dv3) **preservada
  intacta**, e o `MAX_ORDERS_POR_JANELA` não foi tocado.

Nenhum outro arquivo de `app/` foi alterado. Os 11 consumidores de
`shopee_metrics.revenue`, o `FechamentoFaixaResolver::classificar()`, o
`FechamentoSnapshotWriter`, o `FechamentoEmpresasDoMes` e o
`podeUsarApiDaAdman()` **não** foram tocados — `fetchOrdersSummary()` é ponto
único e a correção propaga sozinha para fechamento, dashboard, carteira e
desempenho.

### T2 — os testes (`0b5370d9`)

`tests/Feature/ShopeeMetricsTest.php` e `tests/Feature/ShopeeRelerDiasTest.php`
reescritos (não apagados), preservando o que segue valendo: o truncamento grita,
dia sem venda não grava linha, dia zerado com linha existente baixa para zero,
releitura não duplica linha, leitura sem token não bate na API, janela BRT.

Casos novos (os seis obrigatórios + três de borda):

| caso | resultado afirmado |
|---|---|
| pedido pago e depois `CANCELLED`/`IN_CANCEL` | **entra** — R$ 1.398,13 + R$ 36,81 = R$ 1.434,94 |
| pedido `UNPAID` sem `pay_time` (e com `pay_time` = 0) | **não** entra — revenue 0,00 |
| `total_amount` ≠ soma dos itens | vale o **item** — 48,46 + 150,00 = 198,46 (total seria 231,42) |
| item com `cancelled_qty` / `returned_qty` > 0 | **não** descontado — 100×3 + 25×2 = 350,00 |
| pedido com 2 itens e quantidade > 1 | 25,50×2 + 10,25×3 = 81,75 |
| `orders_count` / `sold_quantity` | 2 pedidos pagos / 7 unidades (as 9 do `UNPAID` ficam fora) |
| `response_optional_fields` | pede `pay_time` e `item_list` |
| janela do dia | `time_from`/`time_to` em −03:00 |
| releitura de dia pago-e-cancelado | **não** zera o dia |

`DesempenhoShopeeScoreTest`, `PortfolioShopeeCarteiraTest`,
`ShopeeSyncAdsCommandTest` e `WarmShopeeDiffCacheCommandTest` foram conferidos:
**nenhum depende do valor antigo** — todos semeiam `shopee_metrics` direto no
banco e nenhum faz fake de `get_order_detail` (confirmado por busca de
`total_amount`/`get_order_detail` em `tests/`). Nada a ajustar neles.

## Evidência literal (setembro/2026 inteiro, contra a planilha manual do time)

| loja | planilha | regra ATUAL (antiga) | regra NOVA |
|---|---|---|---|
| Edumac Parts #144 | 21.149,30 | −6,09% | **0,00% (ao centavo)** |
| Camillo Filial RS #358 | 20.330,13 | −6,75% | **0,00% (ao centavo)** |
| Camillo Matriz #1 | 147.312,67 | −13,71% | −0,44% |
| Tuki Pet #364 | 79.910,90 | −5,92% | +0,30% |
| Interior Magazine #370 | 132.835,11 | −1,24% | +0,49% |
| Camillo Filial SC #131 | 268.932,28 | −10,07% | +0,82% |
| Itadecor Magazine #369 | 55.891,19 | −1,77% | +1,32% |
| Gran Belo #212 | 311.945,63 | −7,33% | +5,53% (único fora) |

Dias isolados lidos pelo usuário no painel:

| loja | dia | painel | nosso (antigo) | regra nova |
|---|---|---|---|---|
| Camillo Matriz #1 | 30/09 | 8.953,89 | 6.953,31 | 9.133,89 |
| ITUFARMA1 #225 | 30/09 | 603,72 | 597,17 | 609,13 |

O resíduo é pequeno e **sempre para cima** (provavelmente desconto aplicado no
pedido, não no item). Fica como **pendência conhecida** — não foi perseguida
neste quick, por decisão do plano.

## Gates

`C:\xampp\php\php.exe artisan test --filter="Shopee|Phase158|Phase60"`
(exit code capturado antes de qualquer pipe):

| momento | exit | resultado |
|---|---|---|
| **antes** de editar | 1 | 7 failed, **314 passed** (1404 assertions), 136,98s |
| **depois** | 1 | 7 failed, **323 passed** (1423 assertions), 162,92s |

As **mesmas 7 falhas** nas duas rodadas, todas pré-existentes e sem relação com a
coleta:

- `DesempenhoShopeeScoreTest` (3) — `var_margem_pct` vem `null` onde o teste
  espera 2.8
- `Phase119\CompanyScoreServiceFonteTest` (3)
- `Phase158\CalculadoraNoPortalTest` (1) — ícone ausente no mapa `ICONES` de
  `PortalClienteLayout.jsx`

Delta: **+9 testes passando, zero falha nova.** `php -l` limpo nos três arquivos.

## Registro

Episódio acrescentado ao final de
`.planning/learnings/shopee-conexao-e-faturamento.md` (seção 6), marcando que ele
**supera** o parágrafo final da seção 2 sem reescrever a história — a régua antiga
explica os valores gravados antes de 06/10.

## Pendente (passo humano, fora deste quick)

1. **Deploy** — não executado (não autorizado neste quick).
2. **Recoletar setembro/2026 das 19 empresas** com a régua nova
   (`shopee:sync --company= --from= --to=`) e **refazer o fechamento**: muda
   competência passada e, por tabela, carteira/desempenho/bônus. Mês fechado lê
   snapshot congelado, então sem `desempenho:consolidar-mes --mes=` o número novo
   não chega ao ranking.
3. Resíduo "sempre para cima" da régua nova — pendência conhecida, exige medição
   nova.
