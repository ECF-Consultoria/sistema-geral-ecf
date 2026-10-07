---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 11
subsystem: frontend
tags: [portal, spreadsheet-grid, produtos, inertia, autosave]
requires: ["167-04", "167-10"]
provides:
  - "Página Inertia real Portal/EstruturaProdutos (tabela editável, gravação por linha)"
  - "resources/js/lib/produtosEstrutura.js: colunasDaGrade, linhaDaGrade, linhaParaServidor, lerBlocoComCabecalho, formatação"
  - "JanelaExcluirVariacao (D-22) e ComoFunciona com passos próprios"
affects: ["167-12", "167-13", "167-14", "167-15", "167-16"]
tech-stack:
  added: []
  patterns: ["gravação por linha com debounce e retrato do servidor (_base) para enviar só o que mudou", "lib sem JSX (createElement) para células da grade"]
key-files:
  created:
    - resources/js/lib/produtosEstrutura.js
    - resources/js/Pages/Portal/EstruturaProdutos.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/JanelaExcluirVariacao.jsx
    - tests/js/estrutura-produtos.test.js
  modified:
    - resources/js/Components/Portal/Estrutura/ComoFunciona.jsx
    - tests/Feature/PortalCliente/PortalSemAnunciarTest.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/AcessoAosProdutosTest.php
key-decisions:
  - "Cada linha guarda `_base` (retrato do servidor); o POST só leva os campos que a pessoa mudou. Célula em branco enviada apagaria dado, e categoria como texto rebaixaria uma categoria confirmada."
  - "A chave estável `_k` da linha é a da digitação (`n3`), não `v{id}`: ao gravar, a linha é mesclada pela `chave` sem remontar (não perde foco nem seleção)."
  - "+ variação marca `_base` com tudo copiado da 1ª, exceto Ref e Valor; o servidor copia eixo/volumes/custo (D-04). A linha só vira 'suja' quando a pessoa edita algo, para não gravar uma variação sem Valor sozinha."
  - "Custo é `text` com renderCell em R$ (aceita vírgula ou ponto); o servidor normaliza."
requirements-completed: [PR167-07, PR167-02, PR167-11]
duration: ~55min
completed: 2026-10-05
---

# Phase 167 Plan 11: Tela de Produtos com a grade Summary

Página `Portal/EstruturaProdutos`: tabela editável de 14 colunas (uma linha por variação, produtos agrupados), gravação automática por linha contra `POST linhas` e confirmação de exclusão com os anúncios que voltam para a espera, sem nenhuma conta de logística ou frete no JS.

## Tarefas

| Tarefa | Commit | Resultado |
| ------ | ------ | --------- |
| 1. Lib de colunas/formatação e página com gravação por linha | `fb018056` | lib + página real + `ComoFunciona` com `passos` |
| 2. Janela de exclusão D-22, gate JS e testes de backend na página real | `30c886a4` | `JanelaExcluirVariacao`, gate `estrutura-produtos.test.js`, 2 testes PHP sem o `false` |

## O que a tela faz

- 14 colunas na ordem do UI-SPEC; Ref e Produto fixas, ações da linha (Plus/Trash2) fixas à direita; rótulo "Família (linha de design)".
- Gravação: 800 ms parado, só linha com Ref e Produto; "Salvando…/Salvo" na barra; erro de uma linha abaixo dela ("Não salvamos esta linha: … Corrija e tente de novo."), só depois da tentativa; falha de rede mantém a tela e tenta de novo em 5 s; edição durante o envio não é sobrescrita pela resposta.
- Colar: evento DOM da grade cresce linhas; com cabeçalhos do modelo (Ref/Código/SKU, Grupo, Variação, Produto, Família, Ambiente, Categoria ML, Volumes, Custo) mapeia por nome; acima de 200 linhas avisa para usar a importação; aviso de lote lista famílias/ambientes criados.
- Estado vazio, busca com debounce 350 ms, paginação, nota de rodapé do frete sem conta ML.
- Exclusão: linha não gravada some sem confirmar; gravada abre a janela (sem anúncios / com N anúncios / última variação / bloqueada por componente só com "Entendi"); 422 aparece dentro da janela.

## Verificação

- `node --test tests/js/estrutura-produtos.test.js tests/js/estrutura-grid-produtos.test.js`: 21 de 21.
- `AcessoAosProdutosTest` + `PortalSemAnunciarTest`: 19 testes, 220 asserções, verdes (agora exigem a página existir).
- `npm run test:js`: 984 passam, 2 falham, ambas do baseline (`Características secundárias nasce recolhido`, `FASES_TERMINAIS`).
- `npm run build`: saída 0, manifest atualizado (17:41), `Pages/Portal/EstruturaProdutos.jsx` presente (3 ocorrências) e o chunk `EstruturaProdutos-*.js` contém o texto da janela de exclusão.
- `tests/Feature/PortalCliente` inteiro (G4): 363 testes, 2628 asserções, verdes.
- Acceptance greps: 0 ocorrências de `6000|cubag|79|me2.peso|soma_lados` e de `precificacaoProdutos` nos dois arquivos.

## Deviations from Plan

Nenhuma de regra 1-4. Ajustes de detalhe:
- O tooltip "Vale para todas as variações" nas células de produto das linhas seguintes não foi ligado: `conditionalFormat` só devolve classe e a grade não tem prop de `title` por célula. As células aparecem apagadas (`text-white/40`); o tooltip fica para quando a grade ganhar essa prop.
- O alerta de faixa do frete é um "!" âmbar com tooltip (a lib é `.js` sem JSX e o ícone `AlertTriangle` ficaria fora do padrão de `createElement`).

## Verificação visual pendente

Não houve conferência no navegador (colar real do Excel, Tab/Enter, "+ variação", popover). A página ainda não tem os editores de Família/Ambiente/Categoria/Volumes (167-13/14): essas células recebem texto digitado/colado. O aviso "Coladas N linhas" para colagem posicional conta as linhas do bloco, não só as gravadas.

## Known Stubs

Nenhum stub de dado. Pontos intencionalmente adiados para os planos seguintes na mesma página: editores de escolha (167-13), categoria e fretes (167-14), listas/planilha/importação (167-15), celular (167-16).

## Self-Check: PASSED

Arquivos criados conferidos; commits `fb018056` e `30c886a4` existem.
