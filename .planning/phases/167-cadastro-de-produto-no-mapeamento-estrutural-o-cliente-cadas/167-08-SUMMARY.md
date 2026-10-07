---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 08
subsystem: portal-cliente / mapeamento-estrutural / precificacao
tags: [produtos, custo, precificacao, publicador-herda, d-10, d-19]
requires: [167-07]
provides:
  - ProdutoCustos::daEmpresa (oferta_id => custo da variação)
  - EstruturaPrecificacaoService::pagina/calcular com custo do produto (origem 'produto', chave do_produto)
  - salvarOferta recusa custo em oferta ligada
affects: [167-12]
tech-stack:
  added: []
  patterns: [sobrescrever o mapa de custos antes de PrecificacaoEstrutura::custo, sem tocar a função pura]
key-files:
  created:
    - app/Services/Portal/Estrutura/Produtos/ProdutoCustos.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/CustoDoProdutoNaPrecificacaoTest.php
  modified:
    - app/Services/Portal/Estrutura/EstruturaPrecificacaoService.php
key-decisions:
  - "Ligada => o custo é o da variação, inclusive null; o custo antigo em estrutura_precificacoes é ignorado na leitura e não é sobrescrito na gravação"
  - "O mesmo mapa alimenta combos/kits, então PrecificacaoEstrutura não mudou"
  - "Frete calculado no Produtos não entra na Precificação (D-19)"
requirements-completed: [PR167-06]
duration: ~20min
completed: 2026-10-05
---

# Phase 167 Plan 08: Custo do produto na Precificação (D-10) Summary

A oferta ligada a produto passa a precificar com o custo da variação (origem `produto`, `do_produto: true`), vencendo qualquer custo digitado antigo; variação sem custo deixa a oferta "sem custo"; combos e kits somam o custo do produto; ofertas sem produto seguem idênticas. O Publicador herda sem mudança de código.

## Commits

- `7b70f50d` — `ProdutoCustos`, ajuste de `EstruturaPrecificacaoService` (pagina, calcular, salvarOferta) e `CustoDoProdutoNaPrecificacaoTest` (6 comportamentos + herança pelo `DadosEfetivosService::daOferta`).

## Deviations from Plan

- A Task 2 não gerou commit próprio: o caso de herança do Publicador (`test_publicador_herda_o_custo_da_variacao`) foi escrito no mesmo arquivo de teste já na Task 1, então entrou no commit `7b70f50d`. Task 2 ficou só como regressão (abaixo). Nenhum arquivo de produção do Publicador nem teste de consumidor foi editado.
- No teste, atualizar o custo de uma variação existente exige `MODO_IMPORTACAO` (em `MODO_GRADE` o mesmo código é tratado de outra forma) — ajuste só no teste.

## Regressão (depois do último commit)

| Grupo | Resultado |
|---|---|
| `tests/Feature/PortalCliente/Estrutura` + `tests/Unit/PortalEstrutura` | 215 testes, 1276 asserções, OK |
| G2 (DadosEfetivos, SincronizaPortal, MigracaoAnunciarAntigo, Alavancas/CustoDoAnuncio) | 22 / 118, OK (= baseline) |
| `Publicador/DadosEfetivosTest` + `SincronizaPortalTest` + `MigracaoAnunciarAntigoTest` + `Alavancas` inteiro | 337 / 1854, OK |
| G3 (DominioLibera + PortalSemAnunciar) | 7 / 155, OK (= baseline) |
| G5 | 16 / 89, OK (baseline 15 / 84; ≥) |
| `PrecificacaoEstruturaTest` | verde (inclui gabarito cadeira+mesa) |

`git status tests/Feature/Publicador` vazio; `PrecificacaoEstrutura.php` não mudou.

## Pendência para o 167-12 (front)

`EstruturaPrecificacao.jsx` ainda mostra o input de custo para ofertas ligadas. Hoje não gera 422 porque, com origem `produto`, o estado do custo nasce vazio e o payload manda custo nulo; mas se o usuário digitar, recebe a recusa. O 167-12 já prevê a célula somente leitura via `do_produto`.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum. T-167-31/32/33 mitigados (recusa 422, herança com regressão, consulta filtrada por `o.company_id`).

## Self-Check: PASSED

Arquivos criados existem; commit `7b70f50d` presente.
