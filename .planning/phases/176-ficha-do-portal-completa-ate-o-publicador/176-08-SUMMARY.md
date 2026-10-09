---
phase: 176-ficha-do-portal-completa-ate-o-publicador
plan: 08
subsystem: publicador
tags: [portal, rascunho, so-vazio, variacoes, sku, estoque]
requires: [176-04, 176-07]
provides:
  - PortalParaRascunhoService::preencher(PubProduto) com resumo (rascunho_id, intocavel, variantes, campos_preenchidos, campos_mantidos, fotos_*, avisos)
  - RascunhoRepository::colunasDeValor grava values_multi
affects: [176-09, 176-10]
key-files:
  created:
    - app/Services/Publicador/PortalParaRascunhoService.php
    - tests/Feature/Publicador/PortalParaRascunhoTest.php
  modified:
    - app/Services/Publicador/RascunhoRepository.php
requirements: [FP176-05, FP176-06, FP176-09]
completed: 2026-10-08
---

# Phase 176 Plan 08: Portal para o rascunho (produto Simples agrupado)

Categoria, ficha técnica, pacote, eixo por cor e SKU/estoque de cada variante chegam ao rascunho só onde está vazio, sob a mesma trava da IA, sem escrever no ML.

## Commits

| Task | Commit | Assunto |
|---|---|---|
| 1 e 2 | 8699c445 | feat(176-08): PortalParaRascunhoService leva o produto agrupado do Portal ao rascunho |

As duas tarefas foram implementadas juntas e entraram num commit só (o serviço é um arquivo; o eixo depende do plano da ficha).

## Decisões de implementação

- Três `sobTrava` (categoria; ficha + pacote numa escrita; variações). Cada uma relê o rascunho com `lockForUpdate` e recusa `IaParaRascunhoService::intocavel`. Não há "sobrescrever": o Portal nunca substitui.
- Eixo dominante = o `eixo` mais frequente entre as variações com valor. Variação sem valor ou de outro eixo é pulada com aviso; valor repetido usa o primeiro. Menos de 2 cores utilizáveis = sem eixo. Mapa cor/tamanho/voltagem/material/sabor para COLOR/SIZE/VOLTAGE/MATERIAL/FLAVOR só se o schema diz `podeSerEixo`; senão `~custom` com o rótulo do Portal. O valor casa com a lista do ML por `ChaveCanonica::texto` (ganha o `id`). Nada grava MAIN_COLOR.
- Sem eixo no rascunho: dois `salvarEixos` (a cor âncora primeiro, depois todas), para a variante única passar os dados à âncora. Com um eixo igual (mesma chave ou mesmo nome): reenvia os valores existentes mais os que faltam (casados por texto ou id). Outro eixo, ou mais de um: aviso e nada acrescentado.
- Uma única chamada `salvarVariantes` por execução. Estoque só se nulo; SKU só se vazio, se repetido em outra variante (a 1ª que o tem fica) ou, com várias cores, se for a cópia do `skuExibido()` do grupo. Produto de uma variação: variante única recebe SKU (só se vazio) e estoque.
- Pacote: `LogisticaProduto::pacote` por variação, `pacoteDoGrupo`, `pacoteParaAtributos`; só ids que existem no schema e cuja unidade aceita cm/g; divergência liga `revisar` e avisa.
- `colunasDeValor` passou a gravar `values_multi` (null quando não há lista). Efeito colateral aceito: a tela, ao regravar um atributo pelo caminho `gravarAtributos`, zera `values_multi` se não o enviar (o snapshot não expõe essa coluna).

## Testes

- `PortalParaRascunhoTest`: 18 testes, 78 asserções, OK (schemas guardados, `Http::preventStrayRequests` + `assertNothingSent`).
- `tests/Feature/Publicador`: 621 testes, 3471 asserções, OK (baseline G1 567). `tests/Unit/Publicador`: 293 testes, 957 asserções, OK (baseline G2 256).

### Mutação do só-vazio (conferida e desfeita)

1. Ficha ignorando `preenchido()` (sobrescreve a marca da equipe): `test_so_preenche_o_vazio_e_conta_o_que_manteve` ficou VERMELHO ("two strings are identical"). Revertido.
2. Estoque sobrescrevendo o digitado (guarda `!== null` trocada por `false`): o mesmo teste ficou VERMELHO ("array contains 99"). Revertido; arquivo restaurado de cópia e suíte verde de novo.

## Deviations from Plan

Nenhuma de regra. Pontos de julgamento:
- A tarefa 1 e a 2 foram commitadas juntas.
- A "âncora" do passo 1 dos eixos: a variação cuja oferta é a do `pub_produto`; sem oferta no `pub_produto` (caso do grupo), a de menor ordem.
- MAIN_COLOR: o serviço não o escreve nem o filtra por nome; a ficha só grava ids do schema com papel PRODUCT. Se um dia a ficha do Portal trouxer MAIN_COLOR como atributo de produto, ele passaria; hoje a ficha do Portal não o produz.

## Known Stubs

`fotos_trazidas` e `fotos_nao_trazidas` ficam 0 e [] até o 176-10 (previsto no plano). Sem front-end alterado (sem `npm run build`).

## Self-Check: PASSED

Arquivos e commit 8699c445 conferidos; STATE.md e ROADMAP.md intocados.
