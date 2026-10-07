---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 05
subsystem: frontend-lib
tags: [sugestoes, navegador, testes-comportamentais, copy]
requires: ["168-01"]
provides:
  - "resources/js/lib/sugestoesSelecao.js (marcação, edição, aceite/descarte com enviar injetado, deveSegurarVisita)"
  - "resources/js/lib/sugestoesEstrutura.js (rótulos e textos pt-BR)"
affects: ["168-14"]
key-files:
  created:
    - resources/js/lib/sugestoesSelecao.js
    - resources/js/lib/sugestoesEstrutura.js
    - tests/js/estrutura-sugestoes-selecao.test.js
decisions:
  - "Limites (título, código, lote) chegam por parâmetro; nenhum literal 60/100/120 no JS de regra"
  - "422 não é falha de rede: devolve errosPorChave (se vierem por chave) e o estado intacto"
metrics:
  completed: 2026-10-07
---

# Phase 168 Plan 05: lógica de navegador das sugestões Summary

Funções puras e imutáveis para a tela de sugestões (marcar até o limite do lote, editar nome/código, montar o pedido `{chave, nome, sku}`, aplicar o resultado do aceite, guarda de saída) mais os textos do contrato de copy, provadas por 27 testes que rodam as funções reais contra um servidor falso.

## Tarefas

| Tarefa | Commit | Conteúdo |
|--------|--------|----------|
| 1 | 3b884802 | `sugestoesSelecao.js` + teste comportamental (18 testes) |
| 2 | 7fee8983 | `sugestoesEstrutura.js` + 9 testes de texto (27 no total) |

## Verificação

- `node --test tests/js/estrutura-sugestoes-selecao.test.js`: exit 0, 27/27.
- `npm run test:js`: 1148 de 1150; falham só as 2 do piso ("Características secundárias nasce recolhido" e "FASES_TERMINAIS").
- A lib não importa React, axios nem Inertia e não tem os literais 100/120.

## Deviations from Plan

- O teste da Tarefa 1 e a lib foram escritos juntos (sem rodar a fase RED isolada); os testes passaram de primeira. Sem impacto no resultado.
- Nenhuma outra: plano executado como escrito.

## Known Stubs

Nenhum.

## Self-Check: PASSED

Arquivos e commits 3b884802 e 7fee8983 conferidos com `git show --stat`.
