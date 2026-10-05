---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 04
subsystem: publicador
tags: [alavancas, promocoes, leitura, matriz-de-tipos, alertas, produtos-da-conta]
requires: [166-02, 166-03]
provides:
  - TiposDePromocao (matriz de regras por tipo, capacidades(tipo, item))
  - DatasDoMl (datas no fuso de São Paulo)
  - AlertasAlavancas (doConvite, doItem)
  - ProdutosDaContaService (listar, porIds, normalizar)
  - PromocoesLeitura (convites, promocao, itensDaPromocao, promocoesDoItem, itemNaPromocao, entradasDaPromocao, exclusaoDaConta, exclusaoDoItem)
affects: [166-05, 166-06, 166-07, 166-08]
key-files:
  created:
    - app/Services/Publicador/Alavancas/TiposDePromocao.php
    - app/Services/Publicador/Alavancas/DatasDoMl.php
    - app/Services/Publicador/Alavancas/AlertasAlavancas.php
    - app/Services/Publicador/Alavancas/ProdutosDaContaService.php
    - app/Services/Publicador/Alavancas/PromocoesLeitura.php
    - tests/Unit/Publicador/Alavancas/TiposDePromocaoTest.php
    - tests/Unit/Publicador/Alavancas/AlertasTest.php
    - tests/Feature/Publicador/Alavancas/ProdutosDaContaTest.php
    - tests/Feature/Publicador/Alavancas/PromocoesLeituraTest.php
    - tests/fixtures-ml/alavancas/doc/produtos/items_search.json
    - tests/fixtures-ml/alavancas/doc/produtos/items_multiget.json
    - tests/fixtures-ml/alavancas/doc/promocoes/ (users_promotions, promotion_DEAL, promotion_items_DEAL, promotion_items_SMART, items_promotions, exclusion_seller)
metrics:
  completed: 2026-10-04
  tasks: 3
---

# Fase 166 Plano 04: Leituras das promoções e matriz por tipo Summary

A regra de cada tipo de promoção virou dado num lugar só (`TiposDePromocao::capacidades`), com datas em São Paulo, alertas do D-12 (sem bloquear, sem ordenar), produtos da conta listados ao vivo para as duas âncoras e a leitura completa da Central de promoções (só GET, `app_version=v2`).

## Commits

| Task | Descrição |
|---|---|
| 1 | `feat(166-04): matriz dos tipos de promoção e alertas das Alavancas` |
| 2 | `feat(166-04): produtos da conta ao vivo e fixtures da doc para as Alavancas` |
| 3 | `feat(166-04): leitura da Central de promoções` |

(Hashes: `git log --oneline -4`.)

## Contratos para as próximas ondas

- `TiposDePromocao::capacidades(string $tipo, ?array $item): array{inscrever, preco, pede_estoque, alterar, remover, motivo}`. `$item` sem `status` conta como `candidate`. Tipos do vendedor (SELLER_CAMPAIGN, SELLER_COUPON_CAMPAIGN, PRICE_DISCOUNT) oferecem inscrever a qualquer item fora de pending/started (o ML decide a elegibilidade, `[ASSUMED]`). SMART/PRICE_MATCHING só inscrevem com `offer_id` `CANDIDATE-*` vindo da leitura.
- `DatasDoMl`: `hoje()`, `ler()`, `diasAte()`, `inicioDoDia()`, `fimDoDia()`, `diasInclusivos()`.
- `AlertasAlavancas::doConvite($convite, ?$hoje)` e `doItem($linha, $conta)`; ordem fixa recebido, estoque, reputação (prazo só do convite).
- `ProdutosDaContaService::listar($c, ?$busca, $pagina)` devolve `{itens, total, pagina, por_pagina, aviso, busca_local}`; `porIds($c, $ids)` devolve mapa por id só com itens do vendedor da conta.
- `PromocoesLeitura`: `convites`, `promocao`, `itensDaPromocao` (devolve `{itens, proximo, reiniciado}`; cada item com `capacidades`, `titulo`, `preco_atual`, `estoque`), `promocoesDoItem` (entradas com `tipo`, `promocao_id`, `capacidades`), `itemNaPromocao` e `entradasDaPromocao` (sem cache), `exclusaoDaConta`, `exclusaoDoItem`.
- Erro de entrada: `RegraViolada('ALAV-ENT')` antes de qualquer HTTP; falha do ML nas leituras lança `\RuntimeException` (o cache nunca guarda erro).

## Decisões e registros

- `entradasDaPromocao` lê os itens da promoção SEM filtro de status (o endpoint aceita `status` como filtro opcional, então devolve todos). Isso se apoia na doc em resumo (ver origem das fixtures); se o ML na prática exigir uma passada por status, trocar aqui. Cursor expirado no meio do percurso encerra a busca com o que já foi achado.
- Cursor (`search_after`) aceita `search_after` ou `searchAfter` na resposta (a fixture usa `searchAfter`); validado por regex antes de virar query.
- `exclusaoDoItem` lê `/seller-promotions/exclusion-list/seller/{item}` como o plano mandou; o formato do corpo é suposição (`exclusion_status`).
- O motivo do SMART sem `offer_id` é "Aceite este convite no Mercado Livre: a leitura não trouxe o código da oferta (offer_id)." (texto do plano; contém "no Mercado Livre", não a sequência literal "aceite no Mercado Livre").
- Teto de offset: `offset > 1000` devolve aviso sem HTTP (página 21, offset 1000, ainda lê).

## Origem de cada fixture

Todas as 8 estão com `"origem": "doc-oficial-resumo"` e `lida_em: 2026-10-04`: montadas pelos campos citados no RESEARCH e na PESQUISA-API, SEM reler as páginas da doc por curl nesta execução (nenhuma consulta de rede foi feita). Nenhum dado de conta de cliente; ids sintéticos (`MLB1000000001`...).

| Fixture | Base |
|---|---|
| `produtos/items_search.json` | estrutura da resposta real da #459 (`items_search_seller_sku.json`), 50 ids sintéticos, total 120 |
| `produtos/items_multiget.json` | lista `[{code, body}]` sintética: 404, grátis, usado, pausado, outro vendedor (code 200) e um só com atributo SELLER_SKU |
| `promocoes/users_promotions.json` | doc resumo: DEAL, MARKETPLACE_CAMPAIGN, SMART, SELLER_CAMPAIGN, SELLER_COUPON_CAMPAIGN, VOLUME, DOD |
| `promocoes/promotion_DEAL.json` | doc resumo |
| `promocoes/promotion_items_DEAL.json` | doc resumo (candidate, pending, started; `searchAfter`) |
| `promocoes/promotion_items_SMART.json` | doc resumo (um com `CANDIDATE-`, um sem `offer_id`, boost) |
| `promocoes/items_promotions.json` | doc resumo (DEAL started, DOD pending, PRICE_DISCOUNT candidate) |
| `promocoes/exclusion_seller.json` | doc resumo |

Na hora de ligar a escrita (166-07/08), vale reler as páginas `gerenciar-ofertas`, `campanhas-tradicionais` e `campanhas-smart-price-matching` e trocar por `doc-oficial`. Grafia conferida no índice do git (`tests/fixtures-ml/alavancas/doc/...`).

## Testes

- Novos: TiposDePromocao 11 (com datas), Alertas 7, ProdutosDaConta 10, PromocoesLeitura 15.
- `tests/Unit/Publicador`: 211 testes, 681 asserções, exit 0 (antes: 193).
- `tests/Feature/Publicador`: 307 testes, 1764 asserções, exit 0 (antes: 282). Sem falha nova contra o baseline.
- O `UnicoCaminhoDeEscritaTest` (guarda do plano 03) passa com as novas classes (nenhum `Http::` nem escrita nelas).

## Deviations from Plan

None - plano executado como escrito. Observação: o primeiro bloco de criação de arquivos por heredoc no Bash falhou por erro de sintaxe do shell e nada foi gravado; os arquivos foram refeitos com a ferramenta Write, sem efeito no resultado.

## Known Stubs

Nenhum.

## Notas

Nenhuma chamada de rede ao ML (tudo `Http::fake`); STATE.md e ROADMAP.md intocados; sem push nem deploy.

## Self-Check: PASSED
