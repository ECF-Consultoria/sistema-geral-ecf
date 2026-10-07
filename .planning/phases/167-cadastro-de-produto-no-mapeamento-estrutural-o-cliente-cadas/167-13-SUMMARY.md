---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 13
subsystem: portal-cliente-frontend
tags: [react, spreadsheet-grid, picker, volumes, mapeamento-estrutural]
requires: ["167-04", "167-10", "167-11"]
provides:
  - PickerLista (família única, ambiente múltiplo) como editor de célula
  - EditorVolumes (caixas C×L×A + kg) como editor de célula
affects: ["167-14"]
tech-stack:
  patterns: ["editor de célula via renderEditor + registrarFechar (fechar grava)"]
key-files:
  created:
    - resources/js/Components/Portal/Estrutura/Produtos/PickerLista.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/EditorVolumes.jsx
    - tests/js/estrutura-produtos-pickers.test.js
  modified:
    - resources/js/Pages/Portal/EstruturaProdutos.jsx
    - resources/js/lib/produtosEstrutura.js
decisions:
  - "Ambientes gravam ambientes_texto separado por ', ' (o split de linhaParaServidor já cobre); sem marca _ambientesEditados"
  - "Caixas digitadas vão em volumes_digitados e linhaParaServidor as manda como `volumes` (lista); o texto canônico vai em volumes_texto só para marcar a linha como alterada e a célula mostrar algo até o servidor responder"
metrics:
  tasks: 2
  completed: 2026-10-05
---

# Phase 167 Plan 13: Editores de família, ambiente e volumes Summary

Família e ambiente passam a ser escolhidos num popover com busca na própria célula (criar uma vez, "Usar" para grafia quase igual), e os volumes são editados caixa a caixa dentro da grade, com o pacote para o frete exibido como o servidor devolveu.

## Commits

- `994372dc` feat(167): família e ambiente escolhidos na célula, criados uma vez
- `7d09702f` feat(167): medidas das caixas editadas dentro da tabela

## O que foi feito

- **PickerLista**: família grava no clique; ambiente usa caixas e grava ao fechar (`registrarFechar`). Busca ignora caixa/acento/espaços; "Criar “x”" chama as rotas `familias.criar`/`ambientes.criar` e atualiza as listas da página; "Usar “Sala Estar”" evita duplicata; `/ , |` mostra a orientação em âmbar; 422 mostra `errors.nome[0]`. Dica fixa de família (D-07).
- **EditorVolumes**: uma linha por caixa, Enter no peso cria a próxima, Remover por caixa, "Fechar" grava (só se houve mudança), modo texto quando aberto digitando, "Pacote para o frete" só quando `row.pacote` existe. Nenhuma conta no editor (gate barra).
- **Página**: estado `listas` (props + respostas do POST linhas e das rotas de criar), `editores` {familia, ambientes, volumes} passados a `colunasDaGrade`.

## Deviations from Plan

**1. [Rule 3 - ajuste de contrato] Chaves do patch**
- O plano previa `_ambientesEditados` e `{volumes, _volumesEditados}`. Como `mudou()` só olha os campos editáveis e `linhaParaServidor` já divide `ambientes_texto`, ambientes não precisaram de marca. Para volumes usei `volumes_digitados` (lista de strings) + `volumes_texto` canônico (marca a linha como alterada); `linhaParaServidor` manda `volumes` quando há `volumes_digitados`. `volumes: []` no patch faz a célula mostrar o texto até o servidor responder. Arquivo extra tocado: `resources/js/lib/produtosEstrutura.js`.
- Ambientes unidos por `', '` em vez de `' / '` (o plano), porque `/` é caractere proibido em nome e a célula/`linhaDaGrade` já usam vírgula.

## Verificação

- `node --test` dos pickers + página: 17 passam.
- `npm run test:js`: 999 passam, 2 falham = as 2 do baseline (Características secundárias; FASES_TERMINAIS). Nenhuma falha nova.
- `npm run build`: saiu 0, manifest com mtime novo, `EstruturaProdutos.jsx` presente.
- `tests/Feature/PortalCliente`: OK (363 testes, 2628 asserções).
- Não foi feita prova visual no navegador (fica para o UAT).

## Known Stubs

Nenhum. Editores de Categoria continuam por conta do 167-14.

## Self-Check: PASSED

Arquivos criados existem; commits `994372dc` e `7d09702f` existem.
