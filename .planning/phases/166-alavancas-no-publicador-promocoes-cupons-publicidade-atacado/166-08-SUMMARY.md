---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 08
subsystem: publicador
tags: [alavancas, campanha-do-vendedor, volume, lista-de-exclusao, cupom, acoes]
requires: [166-05, 166-07]
provides:
  - campanha.criar, campanha.alterar, campanha.excluir (SELLER_CAMPAIGN e VOLUME)
  - exclusao.conta, exclusao.item
  - cupom.criar, cupom.alterar, cupom.excluir
  - LeiturasDaAcao::exclusaoDaConta() e exclusaoDoItem()
affects: [166-11, 166-12, 166-14]
key-files:
  created:
    - app/Services/Publicador/Alavancas/Acoes/CriarCampanha.php
    - app/Services/Publicador/Alavancas/Acoes/AlterarCampanha.php
    - app/Services/Publicador/Alavancas/Acoes/ExcluirCampanha.php
    - app/Services/Publicador/Alavancas/Acoes/GravarExclusaoDaConta.php
    - app/Services/Publicador/Alavancas/Acoes/GravarExclusaoDoItem.php
    - app/Services/Publicador/Alavancas/Acoes/CriarCupom.php
    - app/Services/Publicador/Alavancas/Acoes/AlterarCupom.php
    - app/Services/Publicador/Alavancas/Acoes/ExcluirCupom.php
    - tests/Feature/Publicador/Alavancas/CampanhaVendedorTest.php
    - tests/Feature/Publicador/Alavancas/ExclusaoTest.php
    - tests/Feature/Publicador/Alavancas/CuponsTest.php
    - tests/fixtures-ml/alavancas/doc/acoes/post_promotion_seller_campaign.json
    - tests/fixtures-ml/alavancas/doc/acoes/post_promotion_coupon.json
  modified:
    - app/Services/Publicador/Alavancas/PromocoesLeitura.php
    - app/Services/Publicador/Alavancas/LeiturasDaAcao.php
    - tests/fixtures-ml/alavancas/doc/promocoes/exclusion_seller.json
metrics:
  completed: 2026-10-04
  tasks: 3
---

# Fase 166 Plano 08: Campanha do vendedor, exclusão automática e cupons Summary

Oito ações de escrita que o vendedor CRIA (campanha do vendedor e leve X pague Y, bloqueio das campanhas automáticas por conta e por produto, cupom do vendedor), todas com o estado lido no ML antes de montar a requisição e todas executadas só pelo `EscritorAlavancas`.

## Commits

| Task | Commit |
|---|---|
| 1 | `feat(166-08): campanha do vendedor e leve X pague Y` |
| 2 | `feat(166-08): bloqueio das campanhas automaticas por conta e produto` |
| 3 | `feat(166-08): cupons do vendedor` |

(Hashes: `git log --oneline -4`.)

## Códigos de regra (texto para a tela)

| Código | Texto |
|---|---|
| ALAV-CAMP-01 | A campanha não pode começar no passado. |
| ALAV-CAMP-02 | A campanha do vendedor dura no máximo 14 dias. / O fim da campanha não pode ser antes do início. |
| ALAV-CAMP-04 | Campanha iniciada: a data de início não muda. |
| ALAV-CAMP-05 | Nada mudou. (alterar campanha sem diferença para o ML) |
| ALAV-CAMP-06 | Não consegui ler esta campanha no Mercado Livre (ela pode já ter sido apagada). |
| ALAV-CAMP-07 | Só campanha programada ou ativa pode ser alterada. (novo, não estava no plano) |
| ALAV-VOL-01 | Escolha o tipo do leve mais, pague menos... / Informe a quantidade a comprar... / Informe a quantidade que o cliente paga... / A quantidade paga precisa ser menor que a levada... / Informe o percentual de desconto... (um texto por campo que falta) |
| ALAV-VOL-02 | Leve mais, pague menos ativo: só o nome muda. |
| ALAV-VOL-03 | As datas do leve mais, pague menos não mudam. |
| ALAV-EXC-01 | A conta já está assim. / O produto já está assim. |
| ALAV-EXC-02 | Produto não encontrado nesta conta. (inclui item de outro vendedor com code 200 no multiget) |
| ALAV-CUP-01 | Cupom do vendedor só existe no Mercado Livre Brasil. |
| ALAV-CUP-02 | O cupom vale de 1 a 31 dias, a partir de hoje. |
| ALAV-CUP-03 | O orçamento do cupom só pode aumentar (hoje é R$ X). |
| ALAV-CUP-04 | Cupom ativo: só a data de fim, o orçamento (para mais) e o nome mudam. |
| ALAV-CUP-05 | Nada mudou. |
| ALAV-CUP-06 | Só cupom programado ou ativo pode ser alterado. (novo) |
| ALAV-CUP-07 | Este cupom é de valor fixo / percentual: o outro tipo de desconto não se aplica. (novo; a doc diz "somente para o subtipo") |
| ALAV-CUP-08 | Não consegui ler este cupom no Mercado Livre (ele pode já ter sido apagado). (novo, excluir) |

Avisos de resumo para a tela: criar campanha "Depois de criar, inclua os produtos na campanha."; excluir campanha "Os produtos saem da campanha."; criar cupom "Cupom sem produtos não vale para nenhuma venda: inclua os produtos depois de criar." mais a linha "Código" (5 primeiras letras do apelido da loja + o código enviado); exclusão da conta/produto "As campanhas automáticas do Mercado Livre deixam de incluir produtos desta conta." (ou "voltam a poder incluir").

## Releitura da documentação (04/10/2026, `curl` só em developers.mercadolivre.com.br)

Páginas: `campanhas-do-vendedor` (28/08/2025), `cupons-do-vendedor` (28/08/2025), `campanhas-de-desconto-por-quantidade` (23/01/2025) e `gerenciar-ofertas` (seção "Gestão da lista de exclusão"). As duas fixtures de criação estão como `"origem": "doc-oficial"` com a URL (respostas copiadas da doc).

Divergências encontradas e como foram tratadas:

1. **Lista de exclusão (166-04 supôs `exclusion_status` na LEITURA): a doc mostra `GET .../exclusion-list/seller` devolvendo `{"excluded": "not_excluded" | "excluded"}`.** O POST de fato usa `exclusion_status` em string (conforme o plano). Ajustei `PromocoesLeitura` (novo `estaExcluido()`): lê `excluded` e, por tolerância, ainda aceita `exclusion_status`. A fixture `doc/promocoes/exclusion_seller.json` foi trocada por `doc-oficial` com `{"excluded": "excluded"}`. Os 15 testes do `PromocoesLeituraTest` (que também exercitam `exclusion_status` nas rotas inline) seguem verdes. A doc NÃO traz exemplo de resposta para a leitura por item (`.../seller/{item_id}`): assumi o mesmo formato (`excluded`), com a tolerância acima. Isso fica `[ASSUMED]` até a sondagem na VPS.
2. **Código do cupom (`partial_coupon_code`)**: a doc diz "Máximo 10 caracteres"; o plano supunha 15. Usei `alpha_num|max:10` no valor ENVIADO (os exemplos da doc têm código final de 12 caracteres, coerente com 5 do apelido + até 10 enviados). A parte "só letras e números" segue `[ASSUMED]`.
3. **Orçamento do cupom**: a doc só diz "só pode aumentar" para cupom iniciado; o plano pediu a regra em qualquer estado e foi o que implementei (ALAV-CUP-03), por segurança (T-166-35).
4. **VOLUME**: `allow_combination` é obrigatório no ML (a doc o lista como obrigatório); a ação o envia sempre, padrão `false` quando o pedido não traz. A doc não fixa teto de dias do VOLUME (o exemplo é de ~1 mês): o limite de 14 dias vale só para SELLER_CAMPAIGN, como o plano mandou.
5. **Campanha do vendedor**: a doc diz que na criação só `SELLER_CAMPAIGN` é permitido; o VOLUME tem página própria e o mesmo endpoint, então `CriarCampanha` aceita os dois. O corpo do `PUT` só leva o que muda (doc: "o único obrigatório é promotion_type").

Suposições anotadas (plano): teto de 60 caracteres do nome da campanha e do cupom é `[ASSUMED]` (a doc não cita; o ML decide e o que recusar volta pelo `MapeadorErroAlavanca`).

## Decisões de implementação

- Alterar compara cada campo com o que o ML tem hoje: campo igual ao atual não conta como mudança nem vai no PUT. Por isso "nada mudou" (ALAV-CAMP-05 / ALAV-CUP-05) é detectado de verdade.
- VOLUME programado com atributo alterado: o PUT leva `sub_type`, `buy_quantity`, `pay_quantity` OU `discount_percentage`, `allow_combination` e `name`, mesclando leitura e pedido; ao trocar de subtipo o atributo do subtipo novo vem obrigatoriamente do pedido (não herda o do subtipo antigo).
- Em VOLUME, mudança de data é checada ANTES de mudança de atributo (ALAV-VOL-03 antes de ALAV-VOL-02), por ser a mais específica.
- Produtos no cupom e na campanha: nenhuma classe nova; `InscreverNoConvite`/`RemoverDoConvite` do 166-07 já dão `alavanca = 'cupom'` e foram cobertos por teste (corpo `{promotion_id, promotion_type}` sem preço).
- `LeiturasDaAcao` ganhou `exclusaoDaConta()` e `exclusaoDoItem($id)` (memória por instância), então as ações de exclusão leem por `$this->leituras()` como o plano pediu. `CriarCupom` lê `/users/me` por `LeitorContaAlavancas` (cache da conta) para o site e o apelido.
- A leitura do estado das promoções passa pelo cache de 60 s do `PromocoesLeitura::promocao`; toda escrita bem-sucedida já invalida a conta (166-03), então o orçamento e o status que a próxima ação lê são os de depois da escrita.

## Testes

- `tests/Unit/Publicador`: 239 testes, 736 asserções, exit 0.
- `tests/Feature/Publicador`: 476 testes, 2731 asserções, exit 0 (antes do plano: 432). Sem falha nova.
- `tests/Feature/Publicador/Alavancas`: 238 testes, 1257 asserções, exit 0.
- Novos: `CampanhaVendedorTest` 19, `ExclusaoTest` 9, `CuponsTest` 16 (44 testes). `UnicoCaminhoDeEscritaTest` e `SemEndpointLegadoTest` seguem verdes com as 8 novas ações.

## Deviations from Plan

**1. [Rule 1 - Bug de leitura do 166-04] Formato da leitura da lista de exclusão**
- Encontrado ao reler a doc (ver divergência 1). Correção mínima em `PromocoesLeitura` + fixture; testes do 166-04 mantidos verdes sem alteração.

**2. [Rule 2 - Códigos extras]** ALAV-CAMP-07, ALAV-CUP-06, ALAV-CUP-07 e ALAV-CUP-08 (recusa de estado/subtipo que o ML não aceita, leitura da campanha que falha). Não inventam regra de negócio, só evitam um PUT/DELETE inútil.

**3. Limite do código do cupom**: 10 em vez de 15 (doc, ver acima).

## Known Stubs

Nenhum. O registro das ações e a rota HTTP vêm no 166-11 (conforme o plano).

## Notas

Nenhuma chamada de rede ao ML (tudo `Http::fake`; a releitura de doc foi só nas páginas públicas do DevCenter); STATE.md e ROADMAP.md intocados; sem push nem deploy.

## Self-Check: PASSED
