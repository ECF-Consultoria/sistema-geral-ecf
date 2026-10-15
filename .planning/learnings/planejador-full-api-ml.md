# Planejador FULL — o que a API do Mercado Livre entrega (e o que não)

Medido ao vivo na conta RELOJOARIA WENUS (company 2, seller 570267839) em 15/10/2026,
só com GET. O módulo (`/planejador-full`, `full:coletar` às 06:30) foi desenhado em cima
disto — leia antes de mexer na coleta.

## 1. "Entrada pendente" NÃO existe na API para vendedor do Brasil

A planilha "Relatório geral de estoque" do FULL tem a coluna Entrada pendente (mercadoria
enviada que ainda não chegou). A API não tem equivalente:

- `/inventories/{id}/stock/fulfillment` só conta o que já está no CD. LJCQ24460 tinha
  **6.000** pendentes na planilha e `total: 0` na API.
- `/inbounds/{id}` e `/stock/fulfillment/inbounds/{id}` → 403; `/marketplace/fbm/inbounds` →
  404/405. A API de inbound é só de Fulfillment China / Global Selling.

Por isso o envio é **registrado no módulo** (`full_envios`) e a coleta abate o que chegou.
Não tente "descobrir" o pendente pela API — não dá.

## 2. A chegada aparece, com o número do envio

`/stock/fulfillment/operations/search?type=INBOUND_RECEPTION` lista cada recebimento, com
`external_references: [{type: "inbound_id", value: "75714964"}]` e a quantidade em
`detail.available_quantity` (+ `not_available_detail` se entrou avariado). Um envio é
recebido aos poucos, em vários dias (o 75714964 entrou de 08/10 a 11/10).

- `type` em **MAIÚSCULAS** (`INBOUND_RECEPTION`). Minúsculo dá 400 "invalid value", embora o
  `available_filters` da própria resposta liste em minúsculo.
- Paginação por `scroll` (o valor vem em `paging.scroll`), 50 por página. `limit` maior é ignorado.
- Janela de no máximo ~60 dias por consulta (o código quebra em janelas de 60).
- Sem o número do envio, a chegada é atribuída ao envio registrado mais antigo cuja data já
  passou (FIFO) — chegada de envio criado direto no ML, sem registro aqui, pode "baixar" um
  envio nosso por engano. O número do envio no ML resolve.

## 3. Estoque e vendas

- Anúncios no FULL: `/users/{id}/items/search?logistic_type=fulfillment&search_type=scan` (scroll;
  offset estoura em ~1.000). WENUS: 1.044 anúncios, 448 ativos.
- Código ML = `inventory_id` do item; com variação, `inventory_id` é **por variação** e o do item
  vem `null`. `seller_custom_field` às vezes é null — o `seller_sku` do pedido completa.
- "Em transferência" da planilha = `not_available_detail` com `status: "transfer"` (49 × 50 na
  planilha). Os demais (`lost`, `withdrawal`, `internalProcess`, `notSupported`) não entram na posição.
- Vendas: `/orders/search` pago, dia a dia (WENUS ~400 pedidos/dia, 9.808 em 30 dias; offset
  de 2.000 funcionou). `order_items[].item.id` + `variation_id` ligam o pedido ao Código ML.
- A sugestão do ML (estoque a enviar, urgência, unidades mínimas) não foi achada na API.

## 4. Teste de tela com puppeteer

Nas páginas com o `AppLayout`, o Chrome headless fica com `document.hasFocus() = false`:
`el.focus()` muda o `activeElement` mas **não dispara focus/blur**, e o teclado não chega.
Parece bug de "campo não grava ao sair". Preencha pelo setter nativo + `input` e dispare
`focusout` à mão. Na tela de login (sem o layout) o teclado funciona.
