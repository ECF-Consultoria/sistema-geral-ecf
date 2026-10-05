---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 03
subsystem: portal-estrutura
tags: [estrutura, produtos, listas, oferta-ligada]
requires: [167-01]
provides:
  - ListasDaEmpresaService (família e ambiente por empresa)
  - EstruturaOfertaService com oferta ligada à variação (criar, sincronizarDaVariacao, proteção)
  - variacao_id no conjunto e na visão da Lista SKUs
affects: [167-06, 167-07, Publicador via Sincronizar do Portal]
key-files:
  created:
    - app/Services/Portal/Estrutura/Produtos/ListasDaEmpresaService.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/ListasDaEmpresaTest.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/OfertaLigadaNaListaSkusTest.php
  modified:
    - app/Services/Portal/Estrutura/EstruturaOfertaService.php
    - app/Services/Portal/Estrutura/EstruturaConjunto.php
    - app/Services/Portal/Estrutura/EstruturaVisaoService.php
decisions:
  - "Normalização de nome (caixa, acento, espaços) no PHP; o unique do banco é só rede, com SQLSTATE 23000 tratado"
  - "excluir() ganhou viaProduto (default false): a Lista SKUs recusa; o Produtos passa true e reaproveita D27 e a espera"
  - "sincronizarDaVariacao não chama atualizar(): só sku e nome, sem tocar fase nem componentes"
metrics:
  tasks: 2
  files: 6
  completed: 2026-10-05
---

# Phase 167 Plan 03: Listas da empresa e oferta ligada Summary

Família e ambiente viram listas da empresa (criadas uma vez, normalizadas no PHP) e a oferta simples ligada a uma variação passa a ser protegida na Lista SKUs, com sincronização de sku/nome que não reescreve fase nem composição.

## Tarefas

| Task | Nome | Commit |
|------|------|--------|
| 1 | ListasDaEmpresaService (família e ambiente) | `f6e19e88` |
| 2 | Oferta ligada no EstruturaOfertaService + variacao_id na visão | `e92b528c` |

## O que foi feito

- `ListasDaEmpresaService`: `chave()`, `limpar()`, `lista()`, `criar()`, `renomear()`, `excluir()`, `resolverNomes()` (ator nulo só simula). Recusa nome com `/ , |`, vazio e > 80; item em uso não sai; id de outra empresa dá `ModelNotFoundException`; toda escrita registra `lista_criada/renomeada/excluida` com origem cliente/interno.
- `EstruturaOfertaService`: `criar()` aceita `variacao_id` (mesma empresa, sempre simples); `atualizar()` mantém sku/nome/fase/componentes da oferta ligada (logística e observações editáveis); `excluir(..., bool $viaProduto = false)` recusa a ligada; `sincronizarDaVariacao()` novo.
- `EstruturaConjunto` e `EstruturaVisaoService` expõem `variacao_id` (selo "do Produtos").

## Testes

- `ListasDaEmpresaTest`: 8 testes / 38 asserções. `OfertaLigadaNaListaSkusTest`: 8 testes, incluindo HTTP PUT/DELETE no portal.
- `tests/Feature/PortalCliente/Estrutura` inteira: 109 testes, 847 asserções, exit 0 (baseline G1: 83/724).
- Publicador (baseline): G2 22/118 exit 0; G3 7/155 exit 0; G5 16/89 exit 0 (baseline 15/84; o glob pegou um arquivo a mais). Nenhuma falha.

## Deviations from Plan

- TDD estrito (RED antes do GREEN) não foi seguido: serviço e teste foram escritos em sequência e o teste rodado em seguida. Cobertura do `<behavior>` completa.
- Um teste meu falhou na primeira rodada por artefato do próprio teste (o ator era criado depois de contar Activity); corrigido no teste, sem mudança de código de produção.

## Known Stubs

Nenhum.

## Self-Check: PASSED

Arquivos e commits conferidos (`ListasDaEmpresaService.php`, 2 testes, `e92b528c`).
