---
phase: 176-ficha-do-portal-completa-ate-o-publicador
plan: 04
subsystem: publicador
tags: [puro, atributos, composicao, combo-kit-combit]
requires: [176-01]
provides:
  - PortalValorDeAtributo::resolver / pacoteParaAtributos
  - ComposicaoDoPortal::estoque / principal / pacoteDoGrupo / pacoteDoConjunto / descricoes
affects: [176-08, 176-10]
key-files:
  created:
    - app/Support/Publicador/Portal/PortalValorDeAtributo.php
    - app/Support/Publicador/Portal/ComposicaoDoPortal.php
    - tests/Unit/Publicador/PortalValorDeAtributoTest.php
    - tests/Unit/Publicador/ComposicaoDoPortalTest.php
requirements: [FP176-06, FP176-07]
completed: 2026-10-08
---

# Phase 176 Plan 04: tradução de atributo e composição do portal

Duas classes estáticas e puras (sem banco/HTTP) que o preenchimento do rascunho (172-08/10) consome.

## Commits

| Task | Assunto |
|---|---|
| 1 | feat(176-04): PortalValorDeAtributo traduz campo do portal em valor do rascunho |
| 2 | feat(176-04): ComposicaoDoPortal com estoque, principal, pacotes e descricoes |

## Testes

`tests/Unit/Publicador` inteiro: 289 testes, 942 asserções, OK (17 + 18 novos nos dois arquivos).

## Decisões de implementação

- `pacoteParaAtributos` devolve `array<id, string>` ("50 cm", "12345 g"), o mesmo formato de `IaParaRascunhoService::pacote`; quem grava embrulha em `['value_name' => ..., 'origem' => 'portal']`.
- `resolver`: `revisar` só liga no caminho multivalor. Campo `multivalor` com um único nome resolve também com `values_multi` e `revisar = true`.
- `principal`: candidato = item que aparece em par com `repete` a/b e não é o lado repetido; um candidato só vence; senão maior custo × quantidade (custo null = 0), empate = menor índice.
- Boolean sem opções no schema vira aviso (nada gravado).

## Deviations from Plan

Nenhuma de regra. Os testes foram escritos após as classes (sem ciclo RED observado); passaram de primeira.

## Known Stubs

Nenhum.

## Self-Check: PASSED

STATE.md e ROADMAP.md intocados.
