---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 16
subsystem: portal-cliente-frontend
tags: [react, mobile, sheet, mapeamento-estrutural, produtos]
requires: ["167-11", "167-13", "167-14", "167-15"]
provides:
  - CartoesProdutosMobile (lista de cartões por produto abaixo de 768 px)
  - SheetProduto (formulário de baixo que grava pelo mesmo POST linhas da tabela)
  - ui/sheet com prop side ('right' padrão, 'bottom')
affects: ["167-17"]
tech-stack:
  patterns: ["matchMedia('(max-width: 767px)') com listener troca grade por cartões", "formulário em rascunho local e UM POST linhas por produto", "picker reaproveitado dentro de Sheet de baixo (contrato onCommit/registrarFechar)"]
key-files:
  created:
    - resources/js/Components/Portal/Estrutura/Produtos/CartoesProdutosMobile.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/SheetProduto.jsx
    - tests/js/estrutura-produtos-mobile.test.js
  modified:
    - resources/js/Pages/Portal/EstruturaProdutos.jsx
    - resources/js/Components/ui/sheet.jsx
decisions:
  - "Família, ambiente e categoria são escolhidos uma vez e valem para todas as variações do formulário (o servidor só olha a 1ª linha de cada produto)"
  - "Produto novo com várias variações: o código da 1ª vira o `grupo` de todas, para o servidor juntá-las num produto só"
  - "Volumes no celular são cartões próprios (grade 2x2); o EditorVolumes continua só da tabela por ser um popover de 4 colunas"
  - "Gravação parcial: o que gravou volta com os ids do servidor, o que falhou fica no formulário com o motivo sob a variação"
metrics:
  tasks: 2
  completed: 2026-10-06
---

# Phase 167 Plan 16: Cadastro pelo celular Summary

Abaixo de 768 px a tabela vira cartões por produto, e tocar abre uma folha de baixo que cadastra e edita o produto inteiro pelo mesmo POST `linhas` da tabela.

## O que foi feito

- **Cartões** (`CartoesProdutosMobile`): agrupa as linhas já gravadas por `produto_id`; nome 15px/600, "Família · Ambiente(s)", categoria e, por variação, `Ref · Valor · pílula · frete` (reusa `ESTILO_LOGISTICA` e `renderFrete`). Aviso dispensável "Para cadastrar muitos produtos de uma vez, use o computador ou a planilha." Alvos de toque de 44 px.
- **Formulário** (`SheetProduto`): Sheet de baixo (`max-h-[90vh] overflow-y-auto rounded-t-2xl`), rótulo 12px/600 acima de campos `h-11`, volumes em cartões 2x2, "Adicionar volume", "Nova variação" (copia a 1ª, valor vazio, D-04), "Excluir variação" pela `JanelaExcluirVariacao` (D-22) e um único botão amarelo "Salvar produto" no rodapé. Família, ambiente e categoria abrem `PickerLista`/`PickerCategoria` em outro Sheet de baixo.
- **Página**: hook `useTelaEstreita` (matchMedia + listener); "Adicionar produto" e "Cadastrar o primeiro produto" abrem o formulário em branco no celular; "Planilha" segue no cabeçalho. `mesclarDoSheet` troca as linhas devolvidas no lugar e insere variação nova junto do produto.
- **`ui/sheet.jsx`**: prop `side` aditiva ('right' continua o padrão; nenhum uso existente muda).

## Commits

- `1a4c97ad` feat(167): Produtos em cartões e formulário de produto no celular

## Verificação

- `node --test tests/js/estrutura-produtos-mobile.test.js tests/js/estrutura-produtos.test.js`: 19 de 19.
- `npm run build`: saída 0, "built in 23.45s", `public/build/manifest.json` com data nova (09:21 para 09:28) e `Pages/Portal/EstruturaProdutos.jsx` presente.
- `npm run test:js`: 1030 passam, 2 falham, ambas do baseline (`Características secundárias nasce recolhido` e `FASES_TERMINAIS`). Nenhuma falha nova.
- `tests/Feature/PortalCliente`: OK (363 testes, 2628 asserções).
- Não houve verificação visual em navegador de celular; o comportamento de toque, o empilhamento dos dois Sheets e o teclado virtual ficam para o UAT.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Bloqueio] `ui/sheet.jsx` só tinha o lado direito**
- **Found during:** Task 2
- **Issue:** o plano pede `side="bottom"`, mas `SheetContent` era fixo à direita; sobrescrever por `className` deixaria as classes de animação lateral em conflito.
- **Fix:** prop `side` ('right' padrão, 'bottom'), sem alterar os usos atuais.
- **Files modified:** resources/js/Components/ui/sheet.jsx
- **Commit:** 1a4c97ad

**2. [Organização] Duas tarefas num commit só**
- A página importa o `SheetProduto`, então a Task 1 não compilaria sozinha; as duas tarefas saíram num único commit com os cinco caminhos.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum. O celular grava pelo mesmo endpoint e validação da tabela (T-167-62); nomes saem pelo JSX, sem `dangerouslySetInnerHTML` (T-167-63, coberto pelo gate).

## Self-Check: PASSED

- FOUND: CartoesProdutosMobile.jsx, SheetProduto.jsx, tests/js/estrutura-produtos-mobile.test.js
- FOUND: commit 1a4c97ad
