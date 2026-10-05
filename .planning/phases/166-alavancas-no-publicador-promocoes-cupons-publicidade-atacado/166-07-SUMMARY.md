---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 07
subsystem: publicador
tags: [alavancas, promocoes, convites, desconto-individual, acoes, leituras-compartilhadas]
requires: [166-03, 166-04]
provides:
  - LeiturasDaAcao (memória de leituras da prévia/confirmação/fatia)
  - convite.inscrever, convite.alterar, convite.remover, convite.remover_todas
  - RegrasDeDesconto, desconto.criar, desconto.remover
affects: [166-08, 166-11, 166-14]
key-files:
  created:
    - app/Services/Publicador/Alavancas/LeiturasDaAcao.php
    - app/Services/Publicador/Alavancas/Acoes/InscreverNoConvite.php
    - app/Services/Publicador/Alavancas/Acoes/AlterarNoConvite.php
    - app/Services/Publicador/Alavancas/Acoes/RemoverDoConvite.php
    - app/Services/Publicador/Alavancas/Acoes/RemoverDeTodas.php
    - app/Services/Publicador/Alavancas/RegrasDeDesconto.php
    - app/Services/Publicador/Alavancas/Acoes/CriarDescontoIndividual.php
    - app/Services/Publicador/Alavancas/Acoes/RemoverDescontoIndividual.php
    - tests/Feature/Publicador/Alavancas/ConvitesMatrizTest.php
    - tests/Feature/Publicador/Alavancas/PriceDiscountTest.php
    - tests/Unit/Publicador/Alavancas/PriceDiscountRegrasTest.php
    - tests/fixtures-ml/alavancas/doc/acoes/post_item_ok.json
    - tests/fixtures-ml/alavancas/doc/acoes/delete_todas_parcial.json
  modified:
    - app/Services/Publicador/Alavancas/Acoes/AcaoAlavanca.php
    - app/Services/Publicador/Alavancas/TiposDePromocao.php
metrics:
  completed: 2026-10-04
  tasks: 2
---

# Fase 166 Plano 07: Convites do ML e desconto individual Summary

Seis ações de escrita da Central de promoções (inscrever, alterar, tirar, tirar de todas, criar e remover desconto individual) que montam exatamente o método, caminho, query e corpo da matriz por tipo, leem estado e `offer_id` no servidor por uma memória compartilhada (`LeiturasDaAcao`) e só são executadas pelo `EscritorAlavancas`.

## Commits

| Task | Commit |
|---|---|
| 1 | `feat(166-07): inscrever, alterar e tirar produto dos convites do ML` |
| 2 | `feat(166-07): desconto individual com as regras do Mercado Livre` |

(Hashes: `git log --oneline -3`.)

## O que foi construído

- **`LeiturasDaAcao`**: `para($conta)`, `preCarregar($itens)`, `produto`, `entrada`, `promocoesDoItem`, `promocao`. `preCarregar` faz UM `porIds` (o serviço fatia em 20) e UMA passada `entradasDaPromocao` por par (promoção, tipo); DOD/LIGHTNING/PRICE_DISCOUNT leem uma vez por item. Quem não é achado na passada fica sem memória e a ação lê por item. Testado: 3 itens do mesmo convite = 1 chamada a `/items` e 1 passada; os 3 `validar()` seguintes não fazem leitura nova.
- **`AcaoAlavanca`** (acréscimo): `usarLeituras()` e `leituras()`.
- **`TiposDePromocao`** (acréscimo): `ROTULOS` e `rotulo($tipo)`, os mesmos textos do `rotulos.js` do 166-12.
- Nenhuma ação chama `daConta(` nem `porIds(`/`itemNaPromocao(` direto; `UnicoCaminhoDeEscritaTest` segue verde.
- Para `SEM_PROMOTION_ID` a entrada vem de `itemNaPromocao` (sem cache), não do `promocoesDoItem` com cache de 60 s, para que o status de DOD/LIGHTNING/PRICE_DISCOUNT seja o de agora.

## Códigos de regra criados (texto para a tela)

| Código | Texto |
|---|---|
| ALAV-CONV-00 | Produto não encontrado nesta conta. |
| ALAV-CONV-01 | Inscrever: "Este produto não está como candidato nesta promoção agora." (convite do ML) ou "Este produto já está nesta promoção." (tipo do vendedor já pending/started). Alterar/remover: "Este produto não está nesta promoção agora." |
| ALAV-CONV-02 | Esta promoção não aceita preço: o Mercado Livre define. |
| ALAV-CONV-02b | Informe o preço da promoção. |
| ALAV-CONV-03 | Aceite no Mercado Livre: a leitura não trouxe o código da oferta (offer_id) deste convite. (SMART/PRICE_MATCHING sem `CANDIDATE-`; também PRE_NEGOTIATED/UNHEALTHY_STOCK sem offer_id e remoção sem offer_id nos tipos que o exigem, com texto próprio) |
| ALAV-CONV-04 | MARKETPLACE/VOLUME: "Para mudar o preço: tire o produto, ajuste o preço do anúncio e inscreva de novo." Demais (DOD, LIGHTNING, PRICE_DISCOUNT): "Esta oferta não se edita: tire e crie de novo." |
| ALAV-CONV-05 | Oferta ativa não pode ser retirada; pause o anúncio no Mercado Livre se precisar. |
| ALAV-CONV-06 | O preço da promoção precisa ficar entre R$ {min} e R$ {max}. |
| ALAV-CONV-07 | Informe o estoque da oferta relâmpago (entre {min} e {max}). |
| ALAV-CAMP-03 | Campanha iniciada: o preço só pode baixar e o preço do Mercado Pontos não entra nem sai. |
| ALAV-DESC-01 | O desconto precisa ser de pelo menos 5% (ficou X%). |
| ALAV-DESC-02 | O desconto precisa ser menor que 80% (ficou X%). (vale também para o desconto do Mercado Pontos) |
| ALAV-DESC-03 | Preço do Mercado Pontos 3–6 menor que o geral e desconto pelo menos 5 p.p. maior (10 p.p. se o geral passa de 35%). |
| ALAV-DESC-04 | O desconto não pode começar numa data que já passou. |
| ALAV-DESC-05 | O fim não pode ser antes do início / dura no máximo 14 dias (ficou N). |
| ALAV-DESC-06 | O preço do desconto precisa ficar entre R$ {min} e R$ {max}, a faixa que o Mercado Livre aceita para este produto. |
| ALAV-DESC-07 | Desconto individual só vale para anúncio ativo, novo e que não seja grátis. |
| ALAV-DESC-08 | Este produto já tem desconto individual; remova antes de criar outro (o Mercado Livre não edita desconto individual). |
| ALAV-DESC-09 | Este produto não tem desconto individual para remover. |

Códigos que o plano não listou, criados por necessidade (não inventam regra de negócio, só recusam pedido inválido antes de enviar): **ALAV-CONV-08** "Escolha a promoção." (falta `promotion_id` fora de `SEM_PROMOTION_ID`), **ALAV-CONV-09** "Tipo de promoção inválido para este caminho." (PRICE_DISCOUNT ou tipo desconhecido em convite), **ALAV-CONV-10** "Informe o que mudar na promoção." (alterar sem campo), **ALAV-CONV-11** "Este produto não está em nenhuma promoção." (tirar de todas sem entrada pending/started).

## Origem das fixtures e releitura da doc

Releitura por `curl` em 04/10/2026 (só developers.mercadolivre.com.br): `gerenciar-ofertas` e `desconto-individua`.
- `acoes/delete_todas_parcial.json`: `"origem": "doc-oficial"`. O formato `successful_ids[{offer_id, error}]` e `errors[]` e a nota "não vale para DOD/LIGHTNING" conferem com a doc; o caso PARCIAL (errors preenchido) é montado por nós a partir desse formato (a doc só mostra o caso sem erros), dito na fixture.
- `acoes/post_item_ok.json`: `"origem": "doc-oficial-resumo"`. A página `gerenciar-ofertas` não traz o exemplo de resposta do POST de inscrição; o `{price, original_price}` segue o resumo do RESEARCH.
- `desconto-individua` confirma o corpo do POST (`deal_price`, `top_deal_price`, `start_date`, `finish_date`, `promotion_type`), o DELETE com `promotion_type=PRICE_DISCOUNT&app_version=v2`, 5% ≤ desc < 80%, 14 dias, datas inteiras (00:00:00 e 23:59:59 por padrão), Mercado Pontos 5 p.p. (10 p.p. acima de 35%) e os erros (`buyer_discount_not_in_range` etc.).
- Divergência com as fixtures de LEITURA do 166-04 (`doc/promocoes/*.json`, "doc-oficial-resumo"): nada que as ações deste plano usem divergiu nas duas páginas relidas (lista de itens traz `id`, `status`, `price`, `original_price`; `top_deal_price` é campo do item). As páginas de campanhas tradicionais, co-participação, SMART/preço competitivo, ofertas do dia/relâmpago e pré-acordado NÃO foram relidas: o corpo de inscrição desses tipos segue a matriz do RESEARCH (`[CITED]` lá) e continua pendente de conferência fina (as fixtures desses tipos seguem "doc-oficial-resumo"). Nenhuma fixture do 166-04 foi alterada.

## Testes

- `tests/Unit/Publicador`: 239 testes, 728 asserções, exit 0 (novo neste plano: PriceDiscountRegras 10).
- `tests/Feature/Publicador`: 432 testes, 2532 asserções, exit 0. Sem falha nova.
- Novos: ConvitesMatrizTest (43 execuções com data providers, inclusive "offer_id do navegador é ignorado", item de outro vendedor com code 200 e pré-carregamento), PriceDiscountTest 9, PriceDiscountRegrasTest 10.

## Deviations from Plan

**1. [Rule 2 - texto] Mensagem de ALAV-CONV-03 fixa em vez de vir de `capacidades`**
- O plano pedia a mensagem com o texto de `TiposDePromocao::capacidades` e, no `<behavior>`, "aceite no Mercado Livre". O motivo do 166-04 ("Aceite este convite no Mercado Livre: ...") não contém a sequência literal. A ação usa texto próprio que contém "Aceite no Mercado Livre". Alterar o texto do 166-04 foi evitado para não mexer nos testes dele.

**2. [Rule 3] Acréscimos em arquivos de outros planos**
- `TiposDePromocao::ROTULOS`/`rotulo()` (166-04) para o resumo de "tirar de todas" ter rótulos pt-BR; só acréscimo.

**3. Alterar campanha do vendedor iniciada: `top_deal_price` "sumir"**
- O normalizador de `PromocoesLeitura` (166-04) não guarda `top_deal_price` da entrada, então não dá para saber se a campanha já tinha preço Mercado Pontos. A regra recusa (ALAV-CAMP-03) qualquer pedido de PUT que traga a chave `top_deal_price` numa campanha iniciada (entrar ou sair); não tocar no campo é permitido.

**4. Tirar de todas só considera entradas pending/started**
- Candidato não tem o que tirar; sem nenhuma entrada o produto é recusado (ALAV-CONV-11). O resumo lista só as que de fato saem.

## Known Stubs

Nenhum. O registro das ações e a rota HTTP vêm no 166-11 (conforme o plano).

## Notas

Nenhuma chamada de rede ao ML (tudo `Http::fake`, a releitura de doc foi só nas páginas públicas do DevCenter); STATE.md e ROADMAP.md intocados; sem push nem deploy.

## Self-Check: PASSED
