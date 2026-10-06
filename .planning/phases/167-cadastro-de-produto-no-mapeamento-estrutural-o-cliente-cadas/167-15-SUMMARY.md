---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 15
subsystem: portal-cliente-frontend
tags: [react, importacao, planilha, listas, mapeamento-estrutural]
requires: ["167-09", "167-10", "167-13", "167-14"]
provides:
  - JanelaImportacao (escolher arquivo, prévia sem gravar, confirmar reenviando o arquivo)
  - Menu "Planilha" (baixar modelo por link, importar) e botões do estado vazio
  - JanelaListas (famílias e ambientes da empresa: criar, renomear, excluir só o que não está em uso)
affects: ["167-16", "167-17"]
tech-stack:
  patterns: ["prévia por axios + confirmação por router.post com forceFormData", "janela atualiza o mesmo estado `listas` da página"]
key-files:
  created:
    - resources/js/Components/Portal/Estrutura/Produtos/JanelaImportacao.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/JanelaListas.jsx
    - tests/js/estrutura-produtos-janelas.test.js
  modified:
    - resources/js/Pages/Portal/EstruturaProdutos.jsx
decisions:
  - "A confirmação da importação reenvia o arquivo; nada da prévia volta ao servidor como 'o que gravar' (T-167-60)"
  - "Renomear/excluir item de lista grava o que está pendente na grade e então recarrega produtos e listas (evita perder edição não salva)"
  - "O gatilho do menu Planilha é um <button> comum (Botao não repassa ref ao Radix asChild)"
metrics:
  tasks: 2
  completed: 2026-10-06
---

# Phase 167 Plan 15: Janelas de importação e de listas Summary

Importação da planilha de Produtos com prévia (novos, atualizados, sem mudança, erros abertos, listas a criar, avisos) e gestão das famílias e ambientes da empresa, ligadas ao cabeçalho e ao estado vazio da tela de Produtos.

## Tarefas

| Tarefa | Commit | O que entrou |
| --- | --- | --- |
| 1 | `e292b726` | JanelaImportacao, menu "Planilha" (baixar modelo como `<a download>`, importar), botões do estado vazio, gate JS |
| 2 | `4df257b9` | JanelaListas em duas abas, botão "Famílias e ambientes", gate JS ampliado |

## Verificação

- `node --test tests/js/estrutura-produtos-janelas.test.js` + produtos + pickers: verde (8 do gate novo).
- `npm run test:js`: 1020 testes, 1018 passam; 2 falhas, ambas do baseline (`Características secundárias nasce recolhido`, `FASES_TERMINAIS`). Nenhuma falha nova.
- `npm run build`: saída 0, mtime do `public/build/manifest.json` mudou, `Pages/Portal/EstruturaProdutos.jsx` segue no manifest.
- `tests/Feature/PortalCliente`: 363 testes, 2628 asserções, OK.
- `grep uppercase` em JanelaImportacao: 0.

## Deviations from Plan

**1. [Rule 3 - Bloqueio] Gatilho do menu sem `Botao`**
- `Botao` não é `forwardRef`, então não serve em `DropdownMenuTrigger asChild`. Usado `<button>` com as mesmas classes do secundário.

**2. [Rule 2 - Correção] Recarregar grava antes**
- Renomear/excluir item de lista recarrega `produtos`, o que reseta a grade e limpa as linhas sujas. A página passa `onRecarregar`, que dispara a gravação pendente antes do `router.reload`.

**3. Commit 1 amendado antes do segundo**
- O primeiro commit saiu com uma regex do gate escapada errado (falha minha de shell); corrigido com `--amend` no mesmo caminho, antes de qualquer outro commit. Nada pushado.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum novo. T-167-59/60/61 atendidos: validação real no servidor, confirmação reenvia o arquivo, JSX sem `dangerouslySetInnerHTML`.

## Pendências

- Prova visual em navegador (arrastar arquivo, prévia com erros, renomear item) não foi feita aqui; só os gates estruturais e o build.

## Self-Check: PASSED

Arquivos criados existem, commits `e292b726` e `4df257b9` presentes.
