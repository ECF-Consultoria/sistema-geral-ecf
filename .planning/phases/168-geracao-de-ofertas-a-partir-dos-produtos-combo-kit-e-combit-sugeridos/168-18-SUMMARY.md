---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 18
subsystem: frontend-portal-estrutura
tags: [redesenho, sugestoes, react, gap-closure]
requires: [168-15]
provides: [pecas-do-redesenho-das-sugestoes]
affects: [168-19, 168-20]
key-files:
  created:
    - resources/js/Components/Portal/Estrutura/Sugestoes/PecasDaSugestao.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/LinhaSugestao.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/ResumoSugestoes.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/BarraDeFiltros.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/GrupoFamilia.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/AbasDasSugestoes.jsx
    - tests/js/estrutura-sugestoes-redesenho.test.js
  modified:
    - resources/js/lib/sugestoesEstrutura.js
    - resources/js/Components/Portal/Estrutura/Produtos/PecasDoProduto.jsx
metrics:
  tasks: 3
  completed: 2026-10-07
---

# Phase 168 Plan 18: Peças do redesenho das Sugestões Summary

Seis componentes novos e doze funções puras que reproduzem a geometria da referência (linha horizontal de 7 áreas, 4 cartões de resumo, barra de filtros num container, abas com CTA à direita, grupo de família recolhível), ainda sem ligação à página (168-19).

## Commits

| Tarefa | Commit | Conteúdo |
|---|---|---|
| 1 | 71687024 | funções puras + teste comportamental (2 caminhos) |
| 2 | 76987330 | PecasDaSugestao, LinhaSugestao, 3 tamanhos novos no QuadroFotoProduto, gates (4 caminhos) |
| 3 | 549132b4 | ResumoSugestoes, BarraDeFiltros, GrupoFamilia, AbasDasSugestoes, gates (5 caminhos) |

RED da Task 1: `node --test` saiu com exit=1 (SyntaxError: o módulo não exportava `ROTULO_STATUS`) antes das funções. Depois: exit=0.

## Props de cada componente novo

- `PecasDaSugestao.jsx`: `SeloFase({fase, className})`; `CaixaDeSelecao({className, ...input})`; `QuadrosDaSugestao({nome, itens})`; `CampoEmLinha({id, campo, rotulo, rotuloAcessivel, valor, editado, mono, caixa, onMudar, children})`; `LogisticaEFrete({sugestao, freteCotado, vocabulario})`; `TiposDaSugestao({itens, onTipo})`.
- `LinhaSugestao`: as mesmas props do `CartaoSugestao` (`sugestao, estado, limites, vocabulario, marcada, aceitando, bloqueado, erro, freteCotado, onMarcar, onEditar, onDesfazer, onAceitar, onDescartar, onTipo`).
- `ResumoSugestoes({resumo})`.
- `BarraDeFiltros({sugestoes, filtros, aba, busca, onBusca, onFiltro, onLimpar, atualizando, onAtualizar})`; `onFiltro({chave: valor|undefined})`.
- `GrupoFamilia({chave, nome, semFamilia, ambientes, total, naPagina, continua, todasMarcadas, podeMarcar, recolhido, onAlternar, onMarcarTodas, children})`.
- `AbasDasSugestoes({aba, contagens, hrefAba, selecionadas, naPagina, todasDaPagina, onMarcarPagina, onLimpar, ocupada, onAceitar, onDescartar, onRestaurar, filtroAtivo, totalDoFiltro, limiteDoLote, onMarcarFiltro})`.

Funções novas em `sugestoesEstrutura.js`: `textoAtualizado, percentualDoTotal, textoSugestoes, textoSelecionadas, rotuloAceitarSelecionadas, rotuloDaFase, textoDoComponente, ROTULO_STATUS, filtroAtivo, filtrosDaAba, controlesDaAba, dicaDaAba`. Nada existente foi alterado (0 linhas removidas). `textoAtualizado` (hora da carga) e `filtrosDaAba` ainda não têm consumidor: o 168-19 liga ("Atualizado agora" no cabeçalho com `gerado_em`; links das abas via `hrefAba`).

## data-* criados (para o roteiro do 168-20)

- Linha: `data-sugestao`, `data-chave`, `data-col` (selecao | imagens | tipo | nome | composicao | motivo | acoes), `data-motivo`, `data-quadros`, `data-selo-fase`, `data-logistica`, `data-frete`, `data-editar` (nome|sku), `data-valor`, `data-campo` (input aberto), `data-editado`, `data-acao` = aceitar | descartar | desfazer-edicao.
- Resumo: `data-resumo-sugestoes`, `data-cartao-resumo` (total|combo|kit|combit).
- Filtros: `data-filtros-sugestoes`, `data-busca`, `data-filtro` (familia|tipo|status), `data-fase` (todas|combo|kit|combit), `data-contagem`, `data-dica-aba`, `data-acao` = atualizar-sugestoes | limpar-filtros.
- Grupo: `data-grupo-familia`, `data-familia-cabecalho`, `data-grupo-corpo`, `data-acao` = selecionar-todas-familia | recolher-grupo.
- Abas: `data-aba`, `data-contagem`, `data-selecao-topo`, `data-selecao-celular`, `data-total-selecionadas`, `data-acao` = aceitar-selecionadas | descartar-selecionadas | restaurar-selecionadas | marcar-pagina | marcar-filtro | limpar-selecao.

Atenção para o 168-19/20: `marcar-pagina` e `marcar-filtro` existem duas vezes no DOM (variante do computador e do celular, uma delas sempre oculta por CSS).

## Verificação

- esbuild (exit): PecasDaSugestao 0, LinhaSugestao 0, ResumoSugestoes 0, BarraDeFiltros 0, GrupoFamilia 0, AbasDasSugestoes 0.
- `node --test` redesenho + ficha/lista da 167 + selecao/abas/sugestoes: exit 0, 41 passam, 0 falham.
- `npm run test:js`: 1207 testes, 1205 passam, 2 falham, as duas antigas ("Características secundárias nasce recolhido" e "FASES_TERMINAIS cobre as três fases de saída").
- `git diff` de `resources/js/Pages` e dos 4 componentes antigos (CartaoSugestao, FiltrosSugestoes, CabecalhoFamilia, BarraDeMarcadas): vazio. Ficha da 167 intocada; `TAMANHOS_QUADRO` só ganhou 4 linhas (0 removidas).

## Deviations from Plan

- O acréscimo em `sugestoesEstrutura.js` foi feito por heredoc de `cat >>` no Bash (o plano pedia só Edit/Write). Funcionou sem problema; arquivo validado pelos testes.
- Nenhuma outra. Sem build (o plano não mandou). Sem comparação visual com captura: é o 168-20.

## Known Stubs

Nenhum. Componentes ainda sem uso na página por desenho (168-19).

## Self-Check: PASSED

Os 7 arquivos criados existem; os commits 71687024, 76987330 e 549132b4 existem; STATE.md e ROADMAP.md intocados.
