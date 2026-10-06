---
quick_id: 261006-j44
slug: cupom-do-vendedor-no-faturamento-shopee
date: 2026-10-06
type: quick
status: pending
---

# O faturamento Shopee desconta o cupom do vendedor

## Por que mexer de novo na mesma conta

O quick 261006-fac (de hoje, já no ar) acertou a régua: itens com desconto dos pedidos PAGOS, sem
frete. Ficou um resíduo **pequeno e sempre PARA CIMA** — o usuário conferiu contra a planilha e
relatou: *"a diferença é pouca e sempre pra cima, sempre o valor do sistema é maior que o da
planilha"*.

**A causa é o cupom que o vendedor banca** (`voucher_from_seller`), que o painel da Shopee desconta e
nós não. Medido na API real, três lojas independentes:

| loja | alvo | itens (hoje) | itens − cupom | veredito |
|---|---|---|---|---|
| ITUFARMA1 #225, 30/09 | R$ 603,72 | 609,13 (+0,90%) | **603,72** | **bate ao centavo, 0,00%** |
| CAMILLO MATRIZ #1, 30/09 | R$ 8.953,89 | 9.133,89 (+2,01%) | 8.983,89 (+0,34%) | sobra R$ 30,00 — ver abaixo |
| DROSSI #217, setembro | R$ 392.422,00 | 403.187,26 (+2,74%) | ~391.537,81 (−0,23%) | cupom = 2,89% dos itens, explica **108%** do gap |

⚠️ **Os R$ 30,00 que sobram na Camillo são irredutíveis**: todo pedido enviado dela tem cupom de
R$ 30, e os pedidos **pagos e cancelados depois** voltam com `voucher_from_seller = 0` — a Shopee para
de reportar o cupom após o cancelamento. Não tente recuperar isso; é resíduo conhecido e minúsculo.

⛔ **NÃO descontar `voucher_from_shopee`** — medido: descontar os dois passa do alvo (ITUFARMA −1,76%,
Camillo −3,20%). O cupom da Shopee é bancado pela plataforma e o painel não o tira do faturamento.

## A regra final

```
revenue = Σ (pedidos com `pay_time` não vazio)
            [ Σ (itens) model_discounted_price × model_quantity_purchased ]  −  voucher_from_seller
```

Nada mais muda: sem frete, sem filtro de status, sem descontar `cancelled_qty`/`returned_qty`, janela
do dia em BRT.

## T1 — `post()` no cliente da Shopee

`ShopeeService::get()` só faz GET, e o endpoint em lote **exige POST**. Acrescente um `post()` irmão
(⛔ **não altere o `get()`**), com a MESMA assinatura — `ShopeeSigner::sign($path, $ts, $accessToken,
$shopId)`, que cobre só caminho+timestamp+token+shop_id, **não o corpo**:

- credenciais vão na **query string** (`partner_id`, `timestamp`, `access_token`, `shop_id`, `sign`)
- o payload vai como **corpo JSON**
- mesmo tratamento de erro do `get()`: `! successful()` ou `isError($json)` → `\RuntimeException`
- devolve `$json['response'] ?? []`, igual ao `get()`

## T2 — O cupom entra no `fetchOrdersSummary()`

Sonda real já feita em produção (**não re-investigar, e não chame a API**):

```
POST /api/v2/payment/get_escrow_detail_batch
  corpo: {"order_sn_list": ["260930...", "261001..."]}
  resposta['response'] = LISTA de { escrow_detail: { order_sn, order_income: { ...92 campos... }, ... } }
  o valor está em  escrow_detail.order_income.voucher_from_seller
```

- chunk de **50**, o mesmo do `get_order_detail` → **custo igual ao que já gastamos**: uma chamada a
  mais por lote de 50, não por pedido. ⛔ **Nunca** `get_escrow_detail` pedido a pedido (a GENUINE faz
  ~980 pedidos/dia; seria inviável).
- peça o lote só dos pedidos **pagos** daquele chunk
- case por `order_sn`; pedido sem entrada no lote → cupom 0
- ⚠️ **se a chamada do lote falhar**, não invente: `Log::error` alto (mesmo espírito da trava de
  truncamento do 261006-dv3, que grita quando o dia sai incompleto) e siga **sem** o desconto daquele
  lote. Faturamento maior é o defeito conhecido que esta mudança corrige — sair calado o reintroduz.
- `orders_count` e `sold_quantity` **não mudam**.
- Atualize o docblock: a régua, os três alvos medidos, e por que o cupom da Shopee NÃO é descontado.

## T3 — Testes

Reescreva o que o 261006-fac deixou (⛔ sem apagar) em `tests/Feature/ShopeeMetricsTest.php` e
acrescente, em `tests/Feature/Quick261006J44/`:

| caso | espera |
|---|---|
| pedido pago com cupom do vendedor | `revenue` = itens − cupom |
| cupom da Shopee preenchido | **não** é descontado |
| pedido pago e cancelado, cupom 0 na resposta | entra pelos itens, sem desconto (é o caso da Camillo) |
| lote devolve menos pedidos que o pedido | os ausentes contam cupom 0 |
| lote **falha** (exceção/HTTP erro) | `revenue` sai sem o desconto, `Log::error` emitido, sem exceção |
| 120 pedidos pagos | **3** chamadas de lote (chunk 50), não 120 |
| `orders_count` / `sold_quantity` | idênticos ao 261006-fac |
| `post()` assina igual ao `get()` | mesma `sign`, credenciais na query, corpo em JSON |

**Gate:** `--filter="Shopee|Phase158|Phase60"` — rodar **antes** de editar e ao final, capturando o
exit **antes** de qualquer pipe. Referência de hoje: 7 falhas pré-existentes
(`DesempenhoShopeeScoreTest` ×3, `Phase119\CompanyScoreServiceFonteTest` ×3,
`Phase158\CalculadoraNoPortalTest` ×1) e 323 passando — **não** são regressão de quem mexe na coleta.

## Fora de escopo

⛔ Nada de produção, VPS, `plink`, `pscp`, `.env`, deploy, `cache:clear` ou chamada real à Shopee.
⛔ Não alterar os consumidores de `shopee_metrics.revenue`, `FechamentoFaixaResolver`,
`FechamentoSnapshotWriter`, `FechamentoEmpresasDoMes`, `podeUsarApiDaAdman()` nem `ShopeeService::get()`.
⛔ Não tocar em `app/Services/Fechamento/TabelaDeContratoAssinadoService.php` nem em
`tests/Feature/Quick261006/` (são do quick 261006-gf5, de hoje).
A **recoleta** e o **refazer de setembro** são passo do orquestrador, depois do deploy.

## Restrições de árvore

⚠️ Árvore compartilhada com outro dev e outras sessões. Nunca `git add -A` / `git add .` /
`git commit -a` / `git stash`. `git status --porcelain app/ tests/ .planning/` antes de cada commit e
commitar **só** pelos seus caminhos; arquivo novo precisa de `git add -- <caminho>`.
`tests/Feature/CompanyPortfolioAccessTest.php` não é seu. Não use `gsd-sdk query state.advance-plan`.

PHP: `C:\xampp\php\php.exe`. Comentários, copy e commits em pt-BR, terminando com
`Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

Ao final grave `SUMMARY.md` nesta pasta com a tabela das três lojas medidas.
