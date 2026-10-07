---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 19
subsystem: frontend-portal-estrutura
tags: [redesenho, sugestoes, react, gap-closure]
requires: [168-17, 168-18]
provides: [tela-sugestoes-no-desenho-da-referencia]
affects: [168-20, 168-21]
key-files:
  modified:
    - resources/js/Pages/Portal/EstruturaSugestoes.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/PainelSemTipo.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/ListaDescartadas.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/BarraDeMarcadas.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/LinhaSugestao.jsx
    - resources/js/lib/sugestoesEstrutura.js
  deleted:
    - resources/js/Components/Portal/Estrutura/Sugestoes/CartaoSugestao.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/CabecalhoFamilia.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/FiltrosSugestoes.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/ExplicacaoDasOfertas.jsx
metrics:
  tasks: 2
  completed: 2026-10-07
---

# Phase 168 Plan 19: Tela de sugestões no desenho da referência Summary

A página `EstruturaSugestoes.jsx` agora segue a referência de cima para baixo (trilha, título com "Atualizado agora", 4 cartões de resumo, barra de filtros, abas com a seleção à direita, famílias em container com uma LINHA por sugestão), com toda a lógica e a guarda de saída de antes; a barra fixa virou só do celular e os 4 componentes do desenho antigo saíram.

## Commits

| Tarefa | Commit | Conteúdo |
|---|---|---|
| 1 | 01a42c1f | página ligada às peças do 168-18 + gate "monta o desenho da referência" (2 caminhos) |
| 2 | a52963b4 | Sem tipo e Descartadas em container/linhas, barra fixa `lg:hidden`, 4 componentes removidos, gates (12 caminhos) |

## Verificação

- `node --test` dos 5 arquivos de sugestões: exit 0.
- `npm run test:js`: 1206 testes, 1204 passam, 2 falham, as duas antigas ("Características secundárias nasce recolhido" e "FASES_TERMINAIS cobre as três fases de saída").
- `grep` por CartaoSugestao, CabecalhoFamilia, FiltrosSugestoes, ExplicacaoDasOfertas, textoMarcadas e rotuloAceitarMarcadas em `resources/js`: vazio.
- esbuild da página e dos 3 componentes alterados: exit 0.
- Build: `npm run build` exit 0. mtime de `public/build/manifest.json` ANTES 1791385250 (12:00:50) e DEPOIS 1791388849 (13:00:49). A chave `resources/js/Pages/Portal/EstruturaSugestoes.jsx` aparece no manifest (3 ocorrências). `public/build` não commitado.
- Ficha da 167 (EstruturaProdutoFicha, FichaDadosGerais, CartaoVariacao): diff vazio.

## Função de hoje -> onde ficou na tela nova (roteiro do 168-20)

| Função | Onde ficou |
|---|---|
| Carregar nas três fases e agrupar por família | `agruparPorFamilia` + `GrupoFamilia` (`data-grupo-familia`), linhas `LinhaSugestao` (`data-sugestao`) |
| Filtros (família, tipo de produto, tipo de sugestão), busca, status | `BarraDeFiltros` (`data-filtros-sugestoes`, `data-busca`, `data-filtro`, `data-fase`); `visitar` com `status` em `filtrosRef`; vão ao servidor |
| Filtros seguem na troca de aba | `hrefAba` = `filtrosDaAba(filtros, aba)`; controle que não vale na aba fica desabilitado com `data-dica-aba` |
| As três abas e contadores | `AbasDasSugestoes` (`data-aba`, `data-contagem`) |
| Selecionar uma / várias / todas do grupo | caixa da linha (`marcar`), `selecionar-todas-familia` (`alternarVarias`), `marcar-pagina`, `marcar-filtro` (`marcarDoFiltro`) |
| Seleção persistente entre páginas e teto de 100 | `estado` (`estadoInicial`) sobrevive a `preserveState`; `limites.lote`, avisos `msgLimiteDoLote`/`msgMarcamosPrimeiras`; faixa `data-faixa-teto` |
| Aceitar e aceitar selecionadas | `aceitar-selecionadas` na linha das abas (computador) e `aceitar-marcadas` na barra fixa (celular); botão Aceitar da linha; ambos por `aceitarMarcadas` com `{ sugestoes: pedidos }` |
| Descartar, Desfazer, confirmação para várias | botão da linha, `descartar-selecionadas` / `descartar-marcadas`, `Janela` de confirmação (`confirmar-descarte`), "Desfazer" no aviso |
| Editar nome e SKU (avisos 60 e 120) | `LinhaSugestao` (`data-editar` nome/sku, `data-campo`), `editarCampo`, `desfazer-edicao` |
| Composição, motivo, logística, frete | `LinhaSugestao` (`data-motivo`, `data-logistica`, `data-frete`) |
| Consultar fretes no ML | rodapé `data-rodape-frete` (`consultar-fretes` ou `data-nota-frete`), abaixo da lista e acima da paginação |
| Janela de tipo | `onTipo` da linha e "Ajustar quantidades" do Sem tipo abrem `JanelaTipo` |
| Sem tipo | `PainelSemTipo`: linhas num container, `definir-tipo`, `ajustar-quantidades` |
| Descartadas, marcação própria, Restaurar | `ListaDescartadas` (`data-sugestao-descartada`, `restaurar`); `estadoDesc`; `restaurar-selecionadas` (abas) e `restaurar-marcadas` (celular) |
| Atualizar e "Atualizado agora" | `atualizar-sugestoes` (`router.reload` só de `sugestoes`, mantém seleção); `data-atualizado` a cada 30 s via `textoAtualizado(sugestoes.gerado_em)` |
| Guarda de saída (3 portas), `finish`, `pageshow`, `sairSemAceitar` | inalterados na página; `definirGuardaDoVoltar` + `chegouPeloHistorico` + `router.reload`; sem `popstate` próprio |
| Aceitas somem, descartadas vão para a aba | `recarregar()` depois de cada escrita |
| Trilha / voltar para Produtos | `data-trilha`, `data-acao="voltar-produtos"` |
| Como funciona (explicação dos 3 tipos) | `ComoFunciona` (os 4 passos, sem mudança) |
| Recolher família | `recolher-grupo`, estado `recolhidos` em memória |

## Deviations from Plan

- [Rule 3] O arquivo da página estava em CRLF; as edições foram feitas por scripts Node que preservam CRLF (Edit direto não casaria). Sem efeito no resultado.
- Constante `ABAS` da página removida (sem uso depois que as abas passaram ao `AbasDasSugestoes`).
- Comentário de `LinhaSugestao.jsx` ajustado para não citar o nome do componente apagado (o grep de limpeza do plano exige saída vazia); por isso esse arquivo entra no commit 2.
- Nenhuma outra.

## Known Stubs

Nenhum.

## Pendências para o 168-20

Prova no navegador (guarda T4/T5/T12, seleção entre páginas, teto de 100, Atualizar mantendo edições, Sem tipo e Descartadas em largura xl e abaixo, barra fixa só no celular) e passe de fidelidade com capturas. Nada disso foi exercitado aqui (sem navegador).

## Self-Check: PASSED

Commits 01a42c1f e a52963b4 existem; os 4 componentes antigos não existem mais; manifest do build com a página; STATE.md e ROADMAP.md intocados.
