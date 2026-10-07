---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 09
subsystem: admin-estrutura-geracao
tags: [admin, tipos, pares, role-admin]
requires: ["168-02", "168-03", "168-06"]
provides:
  - "CatalogoDaEcfService (regras de tipo e par)"
  - "DevEstruturaGeracaoController + 7 rotas dev.estrutura_geracao.*"
affects: ["168-12 (página Dev/EstruturaGeracao)"]
key-files:
  created:
    - app/Services/Portal/Estrutura/Geracao/CatalogoDaEcfService.php
    - app/Http/Controllers/DevEstruturaGeracaoController.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/AdminTiposEParesTest.php
  modified:
    - routes/web.php
requirements: [PR168-14]
completed: 2026-10-07
---

# Phase 168 Plan 09: Admin da ECF para tipos e pares Summary

Backend de `/dev/estrutura-geracao` no grupo `role:admin`: a ECF cria, edita e exclui tipos (nome, plural, palavras, quantidades de Combo/Combit) e pares (com a direção do Combit) sem deploy.

## Commits

- `0923e02c` serviço `CatalogoDaEcfService` + `AdminTiposEParesTest`
- `58c41d61` controller + 7 rotas (`routes/web.php` só com linhas `+`: 17 de rota + 1 `use`)

## Decisões e comportamento

- `ordenarPar` guarda o par com `tipo_a_id <= tipo_b_id` e remapeia `primeiro/segundo/ambos/nao` para `a/b/ambos/null`.
- Slug por `Str::slug` com sufixo `-2`, `-3`; nunca vem do request nem muda na edição.
- Exclusão de tipo apaga pares e zera `tipo_id` dos ajustes explicitamente (não depende só da FK).
- Par repetido (qualquer ordem) e corrida 23000 dão `tipo_b_id => 'Este par já existe. Edite o que está na lista.'`.
- Toda escrita grava `activity_log` com `log_name = 'estrutura_geracao'` e o admin como causer.
- Rota fora de `portal/`; `RestringeDominioDoPortal::liberado('dev/estrutura-geracao')` é falso (testado).

## Verificação

- `AdminTiposEParesTest` + `DominioLiberaTodoModuloTest`: OK (20 testes, 151 asserções). `AdminTiposEParesTest` tem 17 testes.
- `route:list --name=dev.estrutura_geracao`: 7 rotas.

## Deviations from Plan

**1. [Rule 3 - Bloqueio] `withoutVite()` no teste do index.** A página React só nasce no 168-12; `component('…', false)` não evita o `ViteException` do manifest ao renderizar o HTML. O teste usa `withoutVite()`.

## Problemas pré-existentes (fora de escopo)

`tests/Feature/DevControllerTest.php` tem 5 falhas (props `empresas`, rota de dispatch-sync 404) que não têm relação com este plano: o `DevController` foi alterado em commit anterior (`8f7a1c48`) e o teste ficou defasado. Não corrigidas.

## Known Stubs

Nenhum. A página `Dev/EstruturaGeracao` é entrega do 168-12.

## Self-Check: PASSED

Arquivos criados e commits `0923e02c`, `58c41d61` conferidos com `git show --stat`.
