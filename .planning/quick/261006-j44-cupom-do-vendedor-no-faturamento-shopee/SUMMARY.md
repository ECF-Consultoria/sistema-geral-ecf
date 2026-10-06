---
quick_id: 261006-j44
slug: cupom-do-vendedor-no-faturamento-shopee
date: 2026-10-06
type: quick
status: done
commits:
  - 46b3735c feat(quick-261006-j44) post() assinado no cliente da Shopee
  - 74126a57 feat(quick-261006-j44) faturamento Shopee desconta o cupom do vendedor
  - 04cd094a test(quick-261006-j44) cupom do vendedor no faturamento Shopee
---

# O faturamento Shopee desconta o cupom do vendedor

## O que mudou

O resíduo "pequeno e sempre para cima" que o 261006-fac deixou tinha uma causa só: o cupom que o
**vendedor** banca (`voucher_from_seller`), que o painel da Shopee desconta e nós não.

A régua final do `ShopeeService::fetchOrdersSummary()`:

```
revenue = Σ (pedidos com `pay_time` não vazio)
            [ Σ (itens) model_discounted_price × model_quantity_purchased ]  −  voucher_from_seller
```

Nada mais mudou: sem frete, sem filtro de status, sem descontar `cancelled_qty`/`returned_qty`,
janela do dia em BRT. `orders_count` e `sold_quantity` são **idênticos** ao 261006-fac.

## Os três alvos medidos na API real de produção

| loja | alvo (painel) | itens (antes) | itens − cupom | veredito |
|---|---|---|---|---|
| ITUFARMA1 #225, 30/09 | R$ 603,72 | 609,13 (+0,90%) | **603,72** | **bate ao centavo, 0,00%** |
| CAMILLO MATRIZ #1, 30/09 | R$ 8.953,89 | 9.133,89 (+2,01%) | 8.983,89 (+0,34%) | sobra R$ 30,00 — irredutível |
| DROSSI #217, setembro | R$ 392.422,00 | 403.187,26 (+2,74%) | ~391.537,81 (−0,23%) | cupom = 2,89% dos itens, explica **108%** do gap |

⚠️ Os **R$ 30,00 da CAMILLO são irredutíveis**: todo pedido enviado dela tem cupom de R$ 30, e os
pedidos pagos e **cancelados depois** voltam com `voucher_from_seller = 0` — a Shopee para de
reportar o cupom após o cancelamento. Resíduo conhecido e minúsculo; não tentar recuperar.

⛔ `voucher_from_shopee` **não** é descontado. Medido: descontar os dois passa do alvo
(ITUFARMA −1,76%, CAMILLO −3,20%). O cupom da Shopee é bancado pela plataforma e o painel não o tira
do faturamento do vendedor.

## As três tasks

**T1 — `ShopeeService::post()`** (`46b3735c`). Irmão do `get()`, que ficou **intocado**. Credenciais
(`partner_id`, `timestamp`, `access_token`, `shop_id`, `sign`) na query string, payload como corpo
JSON (`asJson()->post()`), mesmo tratamento de erro (`! successful()` ou `isError($json)` →
`RuntimeException`), devolve `$json['response'] ?? []`. A assinatura é **a mesma** do `get()` porque
o `ShopeeSigner` cobre só partner_id+caminho+timestamp+token+shop_id — **o corpo não participa da
base string**. Existe porque `get_escrow_detail_batch` exige POST.

**T2 — o cupom no `fetchOrdersSummary()`** (`74126a57`). Novo helper privado
`vouchersDoVendedor()`: `POST /api/v2/payment/get_escrow_detail_batch` com corpo
`{"order_sn_list": [...]}`, lendo `escrow_detail.order_income.voucher_from_seller` da LISTA que vem
na `response`. Chunk de **50**, o mesmo do `get_order_detail` → **custo igual ao de hoje**: uma
chamada a mais por lote de 50, não por pedido (`get_escrow_detail` pedido a pedido seria inviável —
a GENUINEAUTOMOTIVE faz ~980 pedidos/dia). O lote pede só os pedidos **pagos** do chunk; pedido
ausente da resposta conta cupom 0; lote vazio não vira chamada.

**Falha do lote não sai calada**: `catch (\Throwable)` → `Log::error` alto (nomeando empresa, janela,
tamanho do lote e dizendo que *"o faturamento deste lote sai MAIOR que o do painel"*) e segue **sem**
o desconto daquele lote, sem exceção. Faturamento maior é justamente o defeito que esta mudança
corrige — sair calado o reintroduziria em silêncio.

**T3 — testes** (`04cd094a`). 16 casos novos em
`tests/Feature/Quick261006J44/CupomDoVendedorNoFaturamentoTest.php`, incluindo os dois que o plano
pediu nominalmente:

- **120 pedidos pagos ⇒ 3 chamadas de lote** (50+50+20), **não 120** — e o chunk é conferido
  tamanho a tamanho (`[50, 50, 20]`), além de provar que o escrow usa o MESMO chunk do detalhe.
- **lote falha ⇒** `revenue` sai sem o desconto, `Log::error` emitido, **sem exceção**; e um lote que
  falha **não cala os outros** (60 pedidos, 1º lote falha, 2º desconta normalmente).

Os demais: valor itens−cupom, o alvo da ITUFARMA fechando ao centavo, `voucher_from_shopee` não
descontado, pedido pago e cancelado com cupom 0 (caso da Camillo), pedido ausente do lote contando 0,
linha malformada ignorada, UNPAID fora do lote, dia sem pago não chamando o lote,
`orders_count`/`sold_quantity` inalterados, e o `post()` (assinatura, query vs. corpo, erro, sem token).

`tests/Feature/ShopeeMetricsTest.php` foi **reescrito, não apagado**: o `fakePedidos()` passou a
fakar também o `get_escrow_detail_batch` (devolvendo lista vazia, cupom 0, para que aqueles casos
sigam afirmando só a régua de itens). Sem esse fake os testes tentavam **rede real**, o `catch` do
`vouchersDoVendedor` engolia e cada caso levava 1,2s — agora levam 0,1s.

## Gate

`C:\xampp\php\php.exe artisan test --filter="Shopee|Phase158|Phase60"`, exit capturado antes de
qualquer pipe:

| momento | resultado |
|---|---|
| antes de editar | **7 falhas, 323 passando** — bate com a referência do plano |
| ao final | **7 falhas, 324 passando** |

As 7 falhas são exatamente as pré-existentes listadas no plano (`DesempenhoShopeeScoreTest` ×3,
`Phase119\CompanyScoreServiceFonteTest` ×3, `Phase158\CalculadoraNoPortalTest` ×1) — **nenhuma
regressão**. O +1 passando é um teste novo meu (`cupom da shopee nao e descontado`) cujo **nome**
casa com o filtro `Shopee`; a classe nova inteira (16 casos) roda por
`--filter="CupomDoVendedorNoFaturamentoTest"` e passa integralmente.

## Arquivos

- `app/Services/Shopee/ShopeeService.php` — `post()` novo, `vouchersDoVendedor()` novo,
  `fetchOrdersSummary()` descontando o cupom, docblock com a régua e os três alvos
- `tests/Feature/Quick261006J44/CupomDoVendedorNoFaturamentoTest.php` — novo
- `tests/Feature/ShopeeMetricsTest.php` — fake do lote de escrow

## Próximo passo (do orquestrador, não deste quick)

A **recoleta** e o **refazer de setembro** vêm depois do deploy. Nada de produção, VPS, `.env`,
`cache:clear` ou chamada real à Shopee foi feito aqui.
