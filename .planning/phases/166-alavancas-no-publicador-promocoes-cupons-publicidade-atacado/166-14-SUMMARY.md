---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 14
subsystem: publicador
tags: [alavancas, react, promocoes, desconto-individual, campanha-do-vendedor, volume, exclusao]
requires: [166-12, 166-13]
provides:
  - SeletorDeProdutos (produtos da conta ao vivo, para as duas âncoras)
  - DescontoIndividual, CampanhasDoVendedor (inclui leve mais, pague menos), CampanhasAutomaticas, TirarDeTodas
  - AdicionarProdutos (reaproveitável pelos cupons no 166-15, comPreco=false)
  - somarDias em formato.js
affects: [166-15, 166-16]
key-files:
  created:
    - resources/js/Components/Mlb/Alavancas/SeletorDeProdutos.jsx
    - resources/js/Components/Mlb/Alavancas/Promocoes/DescontoIndividual.jsx
    - resources/js/Components/Mlb/Alavancas/Promocoes/AdicionarProdutos.jsx
    - resources/js/Components/Mlb/Alavancas/Promocoes/CampanhasDoVendedor.jsx
    - resources/js/Components/Mlb/Alavancas/Promocoes/CampanhasAutomaticas.jsx
    - resources/js/Components/Mlb/Alavancas/Promocoes/TirarDeTodas.jsx
    - tests/js/publicador-alavancas-vendedor.test.js
  modified:
    - resources/js/Components/Mlb/Alavancas/AbaPromocoes.jsx
    - resources/js/Components/Mlb/Alavancas/Promocoes/ItensDoConvite.jsx
    - resources/js/Components/Mlb/Alavancas/formato.js
metrics:
  completed: 2026-10-05
  tasks: 2
---

# Fase 166 Plano 14: Desconto individual, campanhas do vendedor e automáticas na tela Summary

As quatro escritas de promoção do D-07 que partem de um produto da conta (desconto individual, campanha do vendedor, leve X pague Y e bloqueio das campanhas automáticas) mais "Tirar de todas as promoções", todas pela janela de confirmação do 166-13, com um seletor de produtos da conta que serve às duas âncoras.

## Commits

| Task | Commit | Mensagem |
|---|---|---|
| 1 | `91da999c` | `feat(166-14): produtos da conta e desconto individual na tela` |
| 2 | `db4fd890` | `feat(166-14): campanhas do vendedor, campanhas automáticas e tirar de todas na tela` |

## Rótulos da doc (releitura em 05/10/2026 de `campanhas-de-desconto-por-quantidade`, por curl)

| Subtipo | Doc | Rótulo na tela | Campos |
|---|---|---|---|
| `BNGM` | "Buy N get M: pague 3 e leva 9" (o exemplo de corpo da doc é `buy_quantity: 3`, `pay_quantity: 2`) | "Leve N e pague M (ex.: leve 3, pague 2)" | `buy_quantity` = quanto leva, `pay_quantity` = quanto paga (menor que o levado) |
| `BNSP` | "Buy N save P%: 50% OFF comprando 2" | "Desconto de P% comprando N" | `buy_quantity`, `discount_percentage` |
| `SPONTH` | "Save P% on the Nth: 50% OFF na 2a unidade" | "Desconto de P% na N-ésima unidade" | `buy_quantity` = número da unidade, `discount_percentage` |
| `allow_combination` | "caso true, permite a combinação de itens. Obrigatório." | "Permitir combinar produtos diferentes na mesma compra" | sempre enviado na criação (`false` quando desmarcado) |

Divergência da própria doc: a frase de apresentação do BNGM ("pague 3 e leva 9") não bate com o exemplo de corpo (3 leva, 2 paga). A tela segue o exemplo de corpo e o servidor exige `pay_quantity` menor que `buy_quantity` (166-08).

## O que foi feito

- **`SeletorDeProdutos`**: busca por SKU/título com espera de 400 ms, `useLeitura('produtos', conta, { busca, pagina })`, paginação "Página X de Y" (por_pagina do servidor), `aviso` do teto de 1.000, `busca_local`, pílula "não elegível" com os `motivos`, `soElegiveis`, teto `maximo` com "Até N produtos por vez.", `selecionavel` e `acaoDaLinha`.
- **`DescontoIndividual`**: lê `produtos.promocoes` por produto, mostra o desconto atual (entrada `PRICE_DISCOUNT` pending/started, com "Remover desconto" pela janela `desconto.remover`), a faixa sugerida do candidato, preço pré-preenchido com o sugerido, % calculado, Mercado Pontos opcional (`top_deal_price`), datas (início a partir de hoje, fim até 13 dias depois), análise (`TabelaAnalise`) e "Revisar e criar desconto" (`desconto.criar`).
- **`CampanhasDoVendedor`**: lista `SELLER_CAMPAIGN` e `VOLUME` de `promocoes`; formulário de criar/alterar; "Excluir"; "Produtos" abre `ItensDoConvite` (todos os filtros, inclusive Programados) e `AdicionarProdutos` (`comPreco` só na campanha do vendedor). Alterar manda só o que mudou; campanha iniciada trava o início; VOLUME trava as datas e, depois de iniciado, tudo menos o nome. `sub_type` só vai no VOLUME.
- **`ItensDoConvite`**: caixa "Tirar o preço do Mercado Pontos" (`remove_loyalty: true`) nas linhas alteráveis de `SELLER_CAMPAIGN`, desligada com o item ativo, com a dica "Campanha iniciada: o preço só pode baixar.".
- **`CampanhasAutomaticas`**: estado da conta (`exclusao`) e botão Bloquear/Liberar para a conta inteira (`exclusao.conta`); por produto (`maximo=1`) estado `exclusao.item` e Bloquear/Liberar (`exclusao.item`). Sem amarelo próprio.
- **`TirarDeTodas`**: `convite.remover_todas` pela janela; o aviso de oferta do dia/relâmpago vem do resumo do servidor (`RemoverDeTodas`), não é repetido aqui.
- **`AbaPromocoes`**: cinco seções na ordem pedida, abertas uma por vez e só montadas quando abertas (botão "Abrir"/"Fechar" secundário); "Produtos da conta" usa `selecionavel={false}` e `acaoDaLinha`.

## Verificação (números)

- `node --test` dos três arquivos de Alavancas: 161 testes, 0 falhas (gate de órfão e dos limites visuais por arquivo inclusos).
- `npm run test:js`: 871 testes, 869 passam, 2 falham, ambas do baseline (`Características secundárias nasce recolhido` e `FASES_TERMINAIS`). Nenhuma falha nova.
- `npm run build`: exit 0; `public/build/manifest.json` mtime 00:34:38 para 00:39:53 de 05/10; contém `Alavancas`.
- `tests/Feature/Publicador`: 550 testes, 3.157 asserções, exit 0.

## Deviations from Plan

1. **[Contrato real] `produtos/{item}/promocoes` devolve `{itens: [...]}`** (166-10), não `{promocoes: [...]}` como o plano supôs. A tela lê `itens` e, por tolerância, `promocoes`. As entradas trazem `tipo`, `status`, `preco`, `min_preco`, `max_preco`, `preco_sugerido`, `inicio`, `fim`.
2. **`somarDias` mora em `formato.js`**, não em `DescontoIndividual.jsx`: o teste com data fixa não consegue importar `.jsx` no `node --test`. Teste de `somarDias` sem relógio real (inclui virada de ano e bissexto).
3. **Alterar VOLUME sem pré-preenchimento das regras.** A lista `promocoes` do 166-04 não normaliza `sub_type`, `buy_quantity`, `pay_quantity`, `discount_percentage` nem `allow_combination`, então o formulário de alterar só pré-preenche nome e datas; a pessoa preenche apenas o que quer mudar e o servidor mescla com o que o ML tem (166-08). Se a tela precisar mostrar a regra atual, é acréscimo no normalizador do 166-04 (fica como sugestão para o 166-16).
4. **Seções abertas uma por vez**, inclusive "Convites do Mercado Livre", que abre por padrão (antes ficava sempre montada). Atende "no máximo um amarelo visível" e a leitura sob demanda.
5. **`AdicionarProdutos` aparece também para VOLUME** (`comPreco=false`): o ML define o preço, o corpo vai só com `promotion_id`/`promotion_type`.

## Known Stubs

Nenhum. Cupons e Atacado entram no 166-15.

## Threat Flags

Nenhum além do registro do plano: todo texto vem pelo React (gate proíbe `dangerouslySetInnerHTML`), busca com espera de 400 ms (T-166-67), bloqueio da conta inteira sempre pela janela com prévia assinada (T-166-65); o servidor revalida tudo (T-166-64).

## Self-Check: PASSED

Arquivos criados conferidos; 2 commits `feat(166-14)` no branch `feat/publicador-ml-261001`; STATE.md e ROADMAP.md intocados; sem push, deploy nem chamada ao ML.
