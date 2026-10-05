---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 06
subsystem: publicador
tags: [alavancas, analise, voce-recebe, margem, alertas, precificacao]
requires: [166-04]
provides:
  - CustoDoAnuncioService (custos(conta, itemIds))
  - AnaliseAlavancasService (analisar(conta, pedidos))
  - LimiteDaAnaliseEstourado (exceção interna)
affects: [166-10, 166-11, 166-13, 166-14]
requirements: [AL166-16, AL166-17]
key-files:
  created:
    - app/Services/Publicador/Alavancas/CustoDoAnuncioService.php
    - app/Services/Publicador/Alavancas/AnaliseAlavancasService.php
    - app/Services/Publicador/Alavancas/LimiteDaAnaliseEstourado.php
    - tests/Feature/Publicador/Alavancas/CustoDoAnuncioTest.php
    - tests/Feature/Publicador/Alavancas/AnaliseRecebeTest.php
    - tests/fixtures-ml/alavancas/doc/analise/listing_prices.json
metrics:
  completed: 2026-10-04
  tasks: 2
---

# Fase 166 Plano 06: Quanto a loja recebe, margem e alertas Summary

O servidor passa a dizer, por produto, quanto a loja recebe no preço normal e no da promoção (tarifa e frete reais da API, mesmo cálculo do `SimuladorVoceRecebe`), quanto o ML banca (como estimativa, fora da soma), a margem quando o Portal tem custo, e os alertas do D-12. Nada de cálculo vai para o navegador.

## Commits

| Task | Descrição |
|---|---|
| 1 | `feat(166-06): custo do anúncio pela Precificação do Portal` |
| 2 | `feat(166-06): análise de quanto a loja recebe na promoção` |

(Hashes: `git log --oneline -3`.)

## Formato de `codigo_mlb` encontrado

Gravado **normalizado**: `MLB` + dígitos, sem hífen nem espaço (`EstruturaAnuncio::normalizarMlb()`), igual ao id do ML. Não foi preciso normalizar dos dois lados: os ids da análise já passam pelo filtro `^MLB\d+$` e a consulta usa `whereIn` direto. A reserva (`pub_publicacao_itens.ml_item_id`) também guarda `MLB…`.

## Forma da resposta de `analisar()` (para a tela)

`analisar(ContaAlavanca, list<pedido>): {itens: list, parcial: bool}`. A ordem de `itens` é a do pedido (sem ranking).

Pedido: `item_id` (obrigatório), `preco_promocao`, `promotion_type`, `meli_percentage`, `seller_percentage`, `boost {ativo, desconto_ml, preco_boost}`, `estoque_minimo`.

Item calculado (`calculado: true`):
`item_id, titulo, tipo, preco_atual, preco_promocao, desconto_percentual, ml_banca, desconto_extra_ml, recebe_normal, recebe_promocao, estimativa, depende_do_carrinho, margem, alertas, avisos, calculado`.

- `recebe_normal` / `recebe_promocao`: formato do `SimuladorVoceRecebe` (`preco, tarifa, frete, voce_recebe, percentual, frete_conhecido`), ou `null` (sem tarifa lida, ou `recebe_promocao` sem preço de promoção / desconto no carrinho).
- `ml_banca` = `preco_original (ou preço atual) x meli_percentage / 100`, só para as cofinanciadas; `desconto_extra_ml` = boost. Ambos marcam `estimativa: true` e **não** entram em `voce_recebe`.
- `depende_do_carrinho: true` (VOLUME e SELLER_COUPON_CAMPAIGN): `preco_promocao`, `recebe_promocao`, `desconto_percentual` e `margem` saem `null`.
- `margem`: `{valor, percentual, custo, imposto_percentual}` ou `null`. `valor = recebe_base - custo - preco_base x imposto/100`, base = promoção, ou o preço atual quando não há promoção.
- `alertas`: `[{codigo: recebido|estoque|reputacao, texto}]`, não bloqueiam.
- `avisos`: textos de "calculado sem frete" / "tarifa não lida" para a tela exibir.
- Item fora da conta (inclusive id de outro vendedor): `{item_id, erro: 'Produto não encontrado nesta conta.', calculado: false}`.
- Item que o limite deixou de fora: `{item_id, titulo, tipo, preco_atual, calculado: false}` e `parcial: true`.

## Decisões

- Tarifa: `publico('/sites/MLB/listing_prices')` com `logistic_type` e `shipping_mode` do `shipping` do anúncio (omitidos quando o anúncio não os traz). Aceita a resposta como objeto (API) e como lista (formato mostrado na doc).
- Frete: `free_shipping === false` -> 0,0 conhecido sem chamada; sem dimensões (nem `shipping.dimensions` nem `SELLER_PACKAGE_*`) -> `null` sem chamada, com aviso; senão `shipping_options/free` -> `coverage.all_country.list_cost`.
- Cache de tarifa e frete (1 h) só para valor lido; falha nunca é guardada. O limite por minuto (`chamadas_analise_por_minuto`, por `chaveConta()`) só é gasto por chamada que não veio do cache.
- `margem` fica `null` em desconto no carrinho: base em preço normal enganaria quem está olhando a promoção.
- Só GET ao ML (a guarda `UnicoCaminhoDeEscritaTest` segue verde).

## Origem da fixture

`tests/fixtures-ml/alavancas/doc/analise/listing_prices.json`: `"origem": "doc-oficial"`, URL `https://developers.mercadolivre.com.br/pt_br/comissao-por-vender`, lida por curl na página pública da documentação em 04/10/2026 (nenhuma chamada à API). O exemplo da doc é do site MLA, em lista; o formato é o mesmo no MLB. O frete usa a fixture real já existente `sondagem/conta/shipping_options_free_79`.

## Testes

- Novos: `CustoDoAnuncioTest` 7 (14 asserções), `AnaliseRecebeTest` 15 (60 asserções).
- `tests/Unit/Publicador`: 229 testes, 708 asserções, exit 0 (antes: 211).
- `tests/Feature/Publicador`: 383 testes, 2246 asserções, exit 0 (antes: 307). Sem falha nova.

## Deviations from Plan

Nenhum desvio de regra de negócio. Duas observações:
- A exceção interna `LimiteDaAnaliseEstourado` ficou em arquivo próprio (PSR-4), em vez de dentro do serviço.
- Dois heredocs do Bash truncaram o arquivo de teste na primeira tentativa; refeito com a ferramenta Write, sem efeito no resultado.

## Known Stubs

Nenhum.

## Notas

Cofinanciada e boost seguem como estimativa até a validação na #459 (A3 do RESEARCH). Nenhuma chamada de rede ao ML (tudo `Http::fake`); STATE.md e ROADMAP.md intocados; sem push nem deploy.

## Self-Check: PASSED
