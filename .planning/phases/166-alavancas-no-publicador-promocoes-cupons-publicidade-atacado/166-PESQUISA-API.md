# Fase 166 — Pesquisa de API das alavancas do Mercado Livre

**Feita em:** 2026-10-04, antes de abrir a fase.
**Fonte:** documentação oficial em `developers.mercadolivre.com.br/pt_br/*`. O WebFetch toma 403
(Cloudflare barra robô); `curl -A "<User-Agent de navegador>"` devolve 200 — foi assim que estas
páginas foram lidas. A pesquisa da Fase 44 (Product Ads, junho) não conseguiu ler a fonte oficial e
ficou em fontes secundárias; esta pesquisa leu a oficial.
**Validade:** a API muda com aviso curto (dois desligamentos em 2026, ver §3 e §4). Reler as
páginas antes de planejar escrita.

| Alavanca | Consultar | Criar / alterar | Observação |
|---|---|---|---|
| Central de promoções | sim | sim | API completa, todos os tipos em `/seller-promotions` |
| Cupom do vendedor | sim | sim | só MLB |
| Publicidade (Product Ads) | sim | não documentado no MLB | escrita nunca provada pela ECF |
| Atacado (preço por quantidade) | sim | sim | só contas com a tag `business` |
| Afiliados | não | não | sem API para o vendedor — fora da fase |

---

## 1. Central de promoções — `/seller-promotions` (`gerenciar-ofertas`)

Todas as chamadas levam `?app_version=v2`. Tipos (`promotion_type`): `DEAL` (tradicional),
`MARKETPLACE_CAMPAIGN` (cofinanciada pelo ML), `VOLUME` (leve X pague Y), `DOD` (oferta do dia),
`LIGHTNING` (relâmpago), `PRICE_DISCOUNT` (desconto individual), `PRE_NEGOTIATED` (pré-acordado),
`SELLER_CAMPAIGN` (campanha do vendedor), `SMART` (cofinanciada automatizada), `PRICE_MATCHING`
(preço competitivo), `UNHEALTHY_STOCK` (liquidação de estoque Full), `SELLER_COUPON_CAMPAIGN` (cupom).

**Consultar**
- Convites e promoções da conta: `GET /seller-promotions/users/{user_id}` → `results[]` com `id`,
  `type`, `status`, `start_date`, `finish_date`, `deadline_date` (prazo para aceitar), `name`,
  `benefits` (ex.: `meli_percent`/`seller_percent` na cofinanciada; `buy_quantity`/`pay_quantity` no VOLUME).
- Detalhe: `GET /seller-promotions/promotions/{id}?promotion_type=X`.
- Itens de uma promoção (com status do item): `GET /seller-promotions/promotions/{id}/items?promotion_type=X`
  — filtros `item_id`, `status` (`started|pending|candidate`), `status_item` (`active|paused`);
  paginação SÓ por `search_after` (TTL de 5 min, não volta página), `limit` máx. 50.
- Promoções de um anúncio (status de participação e preço no momento): `GET /seller-promotions/items/{item_id}`.
- Candidato e oferta (vêm por notificação): `GET /seller-promotions/candidates/{id}`, `GET /seller-promotions/offers/{id}`.
- Campos de "boost" (`boosted_offer`, `discount_meli_boosted_percentage`, `total_price_for_boosted_offer`):
  o ML pode dar desconto extra compensado na tarifa em DEAL, PRICE_DISCOUNT, PRE_NEGOTIATED, SMART,
  PRICE_MATCHING e LIGHTNING.

**Escrever**
- Inscrever anúncio: `POST /seller-promotions/items/{item_id}` com `promotion_id`, `promotion_type`,
  `deal_price` e, opcional, `top_deal_price` (preço para Mercado Pontos 3–6). Resposta `{price, original_price}`.
- Modificar: `PUT` no mesmo recurso (tradicional, cofinanciada, VOLUME, campanha do vendedor
  FLEXIBLE_PERCENTAGE — preço só pode BAIXAR). PRICE_DISCOUNT, DOD e LIGHTNING não se editam:
  excluir e aplicar de novo.
- Tirar de uma: `DELETE /seller-promotions/items/{item_id}?promotion_type=X&promotion_id=Y` (VOLUME pede também `offer_id`).
- Tirar de todas de uma vez: `DELETE /seller-promotions/items/{item_id}` (não vale para DOD e LIGHTNING).
- Lista de exclusão das campanhas AUTOMÁTICAS: `GET|POST /seller-promotions/exclusion-list/seller`
  (`exclusion_status`) e `.../exclusion-list/item` (por anúncio).
- Erros conhecidos: `423_ENTITY_LOCKED` (item bloqueado por segundos — tentar de novo), `400_BAD_REQUEST`.
- Teste: só com usuário e itens de teste cadastrados por formulário do ML e `version=test`.

**Desconto individual (PRICE_DISCOUNT)** — `POST /seller-promotions/items/{item_id}` com `deal_price`,
`top_deal_price`, `start_date`, `finish_date`. Regras: reputação verde, item ativo, exposição não grátis;
desconto de 5% a <80%; prazo máx. 14 dias (datas inteiras, 00:00–23:59); a faixa Mercado Pontos 3–6
precisa ser ≥5 p.p. melhor (≥10 p.p. acima de 35%); **aumentar o preço do item remove o desconto
sozinho** (interage com o preço que o Publicador e a Precificação gravam); se houver DEAL ativo, o
desconto só começa quando o DEAL acaba. Remover: `DELETE ...?promotion_type=PRICE_DISCOUNT` (remove a
oferta inteira, não por nível).

**Campanha do vendedor (SELLER_CAMPAIGN)** — o vendedor cria: `POST /seller-promotions/promotions`
(`promotion_type`, `name`, `sub_type: FLEXIBLE_PERCENTAGE` — o FIXED_PERCENTAGE saiu em jul/2025 —,
`start_date`, `finish_date`); `PUT .../promotions/{id}` (só os campos que mudam + `promotion_type`);
`DELETE .../promotions/{id}?promotion_type=SELLER_CAMPAIGN`. Prazo máx. 14 dias; start não pode ser
passado nem editado com a campanha `started`; desconto máx. 80%. Os itens entram por POST como acima.

**Leve X pague Y (VOLUME)** — o vendedor também cria (`POST /seller-promotions/promotions`) e indica
itens; subtipos `BNGM`, `BNSP`, `SPONTH`; `allow_combination`.

## 2. Cupom do vendedor — `SELLER_COUPON_CAMPAIGN` (`cupons-do-vendedor`) — só MLB

- Criar: `POST /seller-promotions/promotions` com `name` (só o vendedor vê), `sub_type`
  (`FIXED_AMOUNT` | `FIXED_PERCENTAGE`), `fixed_amount` ou `fixed_percentage`, `min_purchase_amount`
  (obrigatório), `max_purchase_amount` (teto de desconto, obrigatório no percentual), `budget`
  (obrigatório; a campanha acaba quando esgota), `start_date`, `finish_date`, `partial_coupon_code`
  (opcional: com código só quem tem o código usa; o código final = 5 primeiras letras do apelido do
  vendedor + o enviado). Sem código = todo comprador que vê o anúncio pode usar.
- Prazo máx. 31 dias; `redeems_per_user` sempre 1; um cupom por venda; acumula com a promoção ativa;
  o desconto vale sobre o total da venda dos produtos participantes.
- Atualizar (`PUT .../promotions/{id}`): valor, percentual, teto; **orçamento só aumenta**.
- Excluir: `DELETE .../promotions/{id}?promotion_type=SELLER_COUPON_CAMPAIGN`.
- Consultar: detalhe (`remaining_budget`) e itens, como no §1.
- Requisitos: reputação verde, item ativo, exposição não grátis.

## 3. Publicidade — Product Ads (`product-ads-para-catalogo-e-user-products-leitura`, `bonificacoes-para-product-ads`)

- **Leitura documentada (MLB):** anunciante (`/advertising/advertisers?product_id=PADS`, `Api-Version: 1`);
  campanhas e métricas (`/advertising/{site}/advertisers/{advertiser_id}/product_ads/campaigns/search`,
  `api-version: 2`, com métricas diárias e sumarizadas, detalhe de uma campanha); anúncios
  (`.../product_ads/ads/search`); Ad Groups (agrupamento por `family_id`/`catalog_product_id` — todas as
  variantes de um produto numa campanha só); métricas de competitividade; bonificações
  (`GET /advertising/advertisers/bonifications`: créditos por certificação, Seller Startup, Smart
  Benefits e manuais, com saldo e validade).
- **Escrita (criar campanha, orçamento, ACOS, pausar, mover anúncio): NÃO aparece na documentação
  brasileira.** A Fase 44 tem endpoints de fonte secundária (`POST .../product_ads/campaigns`,
  `PUT .../product_ads/ads/{item_id}`) e o comando `sugadores:ml-write-smoke`, que **nunca rodou**:
  falta ativar a permissão "Advertising — access, create and manage campaigns" no app ECF do DevCenter
  (todo `.planning/todos/pending/270626-resume-44-01-smoke-bymobille.md`). O token já pede
  `read write offline_access` (`MercadoLivreService.php:109`). O smoke aponta para a Bymobille (#298,
  conta de CLIENTE) — pela regra do usuário a prova de escrita deveria ser na #459.
- **"Campanha de lançamento" não existe na API**; é da tela do ML. Se a escrita for provada, dá para
  imitar (ao publicar, o anúncio entra numa campanha "Lançamento").
- **⚠️ Desligamento de 2026-05-27:** os endpoints legados de Product Ads passaram a responder 404, entre
  eles `GET /advertising/advertisers/{id}/product_ads/items` — usado por
  `MercadoLivreAdsService::listAds()` (`ENDPOINT_ADS_ITEMS`, Sugadores). A listagem de anúncios dos
  Sugadores pode estar quebrada desde então. **Não conferido em produção.**

## 4. Atacado — preço por quantidade (`precos-por-quantidade`, `pxq-porcentagem-b2b`, `preco-por-quantidade-b2c`)

- Só contas habilitadas pelo ML com a tag `business` (`GET /users/{id}` → `tags`); MLB, MLM, MLC, MLA.
  O preço por faixa só aparece para comprador B2B (`context_restrictions: user_type_business`).
- **O formato absoluto (`POST /items/{id}/prices/standard/quantity`) é descontinuado em 2026-10-27**
  para PxQ B2B (continua só para "preços líquidos por quantidade"). Usar o **% B2B**:
  `POST /items/{id}/prices/price-per-quantity` com `x-version` (obtido em
  `GET /items/{id}/prices?display_version=true` + header `show-all-prices: true`) e
  `price_per_quantity[]` de `discount_percentage` com `min_purchase_unit`. Até 5 faixas, desconto
  crescente com a quantidade; o % incide sobre o preço vigente (inclusive em promoção). Há endpoint de
  **recomendação de faixas** e guia de migração absoluto → %. Enviar a lista inteira: omitir uma faixa = excluí-la.
- B2C (todo comprador): hoje só pneus (MLB2233), data no MLB "a definir"; 2 faixas fixas (2 e 4 unidades).
- Notificação no tópico `items prices` quando o PxQ muda.

## 5. Afiliados — fora

Comissão de 5% a 16% paga pelo ML por categoria; o vendedor não configura nada e não há página de
API na documentação de desenvolvedores. Vendedor nem pode ser afiliado.

## 6. Pré-requisitos e riscos para a discussão

- **Permissões do app ECF no DevCenter** ("Promoções" e "Advertising") — ação de quem administra o app;
  sem elas toda escrita dá 403 mesmo com o escopo `write`.
- **Escrita em conta de cliente**: é o objetivo da fase, mas a regra de 2026-10-01 só libera escrita de
  teste na #459. Decidir como liberar (trava por conta, como `publicador.contas_liberadas`?).
- **Interação com preço**: aumentar o preço remove PRICE_DISCOUNT; o Publicador e a Precificação do
  Portal mexem em preço.
- **Reputação verde** é pré-requisito de desconto, campanha e cupom — mostrar antes de oferecer a ação.
- **Notificações** (`public candidate`, offers, `items prices`) existem; a fase pode começar só por
  consulta sob demanda.

## Páginas lidas (todas `https://developers.mercadolivre.com.br/pt_br/…`)

`gerenciar-ofertas`, `cupons-do-vendedor`, `campanhas-do-vendedor`, `desconto-individua`,
`campanhas-de-desconto-por-quantidade`, `campanhas-tradicionais`, `promocoes-precificacao`,
`precos-por-quantidade`, `pxq-porcentagem-b2b`, `preco-por-quantidade-b2c`, `precos-liquidos`,
`introducao-ao-mercado-ads`, `product-ads-para-catalogo-e-user-products-leitura`,
`bonificacoes-para-product-ads`; afiliados: `https://mercadolivre.com.br/l/afiliados-home`.
