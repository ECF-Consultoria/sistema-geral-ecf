---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 18
subsystem: portal-cliente / mapeamento estrutural / produtos
tags: [gap-closure, d-23, d-24, lista-de-cartoes, ficha-do-produto, sem-planilha]
requires: [167-16]
provides:
  - tela de Produtos como lista de cartões + ficha (lateral no computador, de baixo no celular)
  - gates JS no contrato D-23 (a grade não volta sem querer)
affects: [167-17 (conferência visual volta a ser a Task 3 dele)]
tech-stack:
  patterns: ["lista de cartões responsiva 1/2/3 colunas", "Sheet side por matchMedia 767 px", "proteção de alteração não salva via onInteractOutside/onEscapeKeyDown"]
key-files:
  created:
    - resources/js/Components/Portal/Estrutura/Produtos/ListaProdutos.jsx
    - tests/js/estrutura-produtos-lista.test.js
  modified:
    - resources/js/Components/Portal/Estrutura/Produtos/SheetProduto.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/JanelaSugestoesCategoria.jsx
    - resources/js/Pages/Portal/EstruturaProdutos.jsx
    - resources/js/lib/produtosEstrutura.js
    - tests/js/estrutura-produtos.test.js
    - tests/js/estrutura-produtos-pickers.test.js
    - tests/js/estrutura-produtos-ml.test.js
    - tests/js/estrutura-produtos-janelas.test.js
    - tests/js/estrutura-grid-produtos.test.js
    - .planning/phases/167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas/167-UI-SPEC.md
    - .planning/learnings/portal-do-cliente.md
  deleted:
    - resources/js/Components/Portal/Estrutura/Produtos/CartoesProdutosMobile.jsx (renomeado para ListaProdutos)
    - resources/js/Components/Portal/Estrutura/Produtos/EditorVolumes.jsx
    - tests/js/estrutura-produtos-mobile.test.js (renomeado para estrutura-produtos-lista)
decisions:
  - "D-23: nada de planilha dentro do sistema; no computador vale o desenho do celular (lista + ficha)"
  - "D-24: a planilha só como arquivo (Baixar modelo (.xlsx) e Importar planilha, botões com nome próprio)"
metrics:
  completed: 2026-10-06
  tasks: 3
---

# Phase 167 Plan 18: Produtos sem planilha — lista + ficha Summary

Fecha a lacuna do checkpoint reprovado do 167-17 ("eu disse que não queria uma planilha dentro do sistema"): a grade de
células saiu da tela de Produtos; no computador e no celular a tela é uma lista de cartões por produto e uma ficha do
produto (painel à direita a partir de 768 px, folha de baixo abaixo disso) com um único "Salvar produto".

## Commits

| Task | Commit | O que fez |
| ---- | ------ | --------- |
| 1 | `7f2d7ed9` | `ListaProdutos` (renomeado de `CartoesProdutosMobile`: 1/2/3 colunas, medidas, peso cubado, custo, "Falta:"), `SheetProduto` com `lado` e proteção de alteração não salva, textos "variação" no lugar de "linha", "escolha no produto" |
| 2 | `e421051d` | Página reescrita sem grade; lib enxuta; `EditorVolumes.jsx` apagado; gates no contrato novo |
| 3 | `9bde2ba9` | Seção "Revisão D-23" no UI-SPEC, o porquê no learnings (item do §31) e nota no gate da grade compartilhada |

## O que foi removido

- A grade (`SpreadsheetGrid`) da página: 0 ocorrências em `EstruturaProdutos.jsx`. O componente compartilhado e o
  `gradeTeclado.js` ficaram SEM diff.
- Gravação por linha com debounce de 800 ms, colar do Excel, Tab entre células, "+10 linhas" e o status "Salvando…/Salvo".
- Menu "Planilha" (virou dois botões: "Importar planilha" e o link "Baixar modelo (.xlsx)").
- `EditorVolumes.jsx` (a ficha tem os cartões de volume próprios), `colunasDaGrade`, `lerBlocoComCabecalho`,
  `campoDoCabecalho`, `mudou`, `celula`.
- A exclusão pela linha: agora só pela ficha, com a mesma confirmação do D-22.

## O que continua

Busca, paginação, "Famílias e ambientes", "Importar planilha" e "Baixar modelo (.xlsx)", "Sugerir categorias" em lote (D-06,
agora gravando a 1ª variação de cada produto marcado num único POST `linhas`), "Consultar fretes no Mercado Livre" só com
conta conectada, estado vazio, "Como funciona". Backend intacto.

## Números do gate

- `node --test` dos 8 arquivos de Produtos/grade/teclado: 77 de 77.
- `npm run test:js`: 1035 testes, 1033 passam; as 2 falhas são as do baseline (`Características secundárias nasce
  recolhido` e `FASES_TERMINAIS`).
- `tests/Feature/PortalCliente`: OK, 363 testes, 2628 assertions.
- `git diff --stat` de `app routes database config tests/Feature SpreadsheetGrid.jsx gradeTeclado.js` desde o plano: vazio.
- `npm run build` real nas Tasks 1 e 2 (mtime do `manifest.json` mudou; `Pages/Portal/EstruturaProdutos.jsx` presente).

## Capturas (opcional, feitas)

Puppeteer rodou contra `127.0.0.1:8167` (que segue de pé, servindo o build novo). Salvas em
`C:/Users/User/AppData/Local/Temp/claude/c--xampp-htdocs-ecf-admin/406cc17a-5256-4c79-8089-77e456d45feb/scratchpad/produtos-visual/`:
`lista-desktop.png`, `ficha-desktop.png`, `lista-celular.png`. Sem erros de console nem de página. Conferido a olho: a lista é de
cartões em 3 colunas, a ficha abre à direita com o formulário e o botão amarelo no rodapé, nada tem cara de planilha.
Ressalva visual menor: em cartões estreitos o frete ("R$ 78,65 estimativa") quebra para a linha de baixo da variação.

## Deviations from Plan

Nenhuma de regra. Observação operacional: `SheetProduto.jsx`, `EstruturaProdutos.jsx` e `produtosEstrutura.js` estão em CRLF
no working copy; as edições foram feitas preservando CRLF (o Git normaliza para LF).

## Pendente

Conferência visual do 167-17 (Task 3 dele), que volta ao usuário com os passos novos listados no `<output>` do plano.

## Self-Check: PASSED
