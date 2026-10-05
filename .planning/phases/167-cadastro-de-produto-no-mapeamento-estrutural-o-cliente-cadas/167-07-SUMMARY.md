---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 07
subsystem: portal-cliente / mapeamento-estrutural
tags: [produtos, oferta-ligada, lista-skus, exclusao, publicador-d27]
requires: [167-03, 167-06]
provides:
  - ProdutoCadastroService::gravarLinhas cria/sincroniza a oferta simples ligada (variacao_id)
  - ProdutoCadastroService::garantirOfertas (reconciliador idempotente)
  - ProdutoCadastroService::excluirVariacao (D-22)
  - ProdutoCadastroService::nomeDaOferta
affects: [167-08, 167-09, 167-10]
tech-stack:
  added: []
  patterns: [reaproveitar EstruturaOfertaService (viaProduto) em vez de segunda regra de exclusão]
key-files:
  created:
    - tests/Feature/PortalCliente/Estrutura/Produtos/OfertaLigadaAoProdutoTest.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/CicloDeVidaDoProdutoTest.php
  modified:
    - app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/CadastroDeProdutoTest.php
key-decisions:
  - "Variação criada -> EstruturaOfertaService::criar (SKU = código, nome 'Produto — Valor'); variação ou produto alterado -> sincronizarDaVariacao em todas as variações afetadas (renomear produto muda o nome de todas)"
  - "Sem casamento por SKU com ofertas antigas (D-09): teste compara as 9 ofertas do gabarito campo a campo antes/depois"
  - "excluirVariacao só delega a EstruturaOfertaService::excluir(viaProduto: true); nenhuma regra de anúncio duplicada no serviço de produtos"
  - "totais ganhou a chave absorvidos_da_espera (anúncios da espera que viraram da oferta nova)"
requirements-completed: [PR167-05, PR167-12, PR167-13]
duration: ~25min
completed: 2026-10-05
---

# Phase 167 Plan 07: Oferta ligada à variação e exclusão (D-08, D-22) Summary

Cada variação gravada nasce com a sua oferta simples na Lista SKUs, que acompanha código, valor e nome do produto; excluir a variação segue a regra que a Lista SKUs já tinha (componente bloqueia, anúncios voltam à espera, item do Publicador fica solto, última variação leva o produto).

## Commits

- `c59ae309` — oferta ligada (criar, sincronizar, reconciliar) + `excluirVariacao` no serviço + `OfertaLigadaAoProdutoTest` + ajuste de 2 asserções de `totais` em `CadastroDeProdutoTest`
- `048b8165` — `CicloDeVidaDoProdutoTest`

## Deviations from Plan

**1. [Organização] Os dois commits não separam serviço por tarefa.** `excluirVariacao` e a oferta ligada estão no mesmo arquivo e foram escritos juntos; o commit 1 leva o serviço completo e o commit 2 só o teste da Tarefa 2 (rodado verde antes de ambos).

**2. [Ajuste previsto] `CadastroDeProdutoTest`:** duas asserções `assertSame` de `totais` passaram a incluir `'absorvidos_da_espera' => 0` (o plano manda somar nessa chave). Regra não relaxada.

Nada mais: plano executado como escrito. Nenhum desvio de Regra 1-4.

## Verificação

- `tests/Feature/PortalCliente/Estrutura/Produtos` + `OfertaExcluidaNoPortalTest`: 82 testes, 380 asserções, OK.
- Regressão pedida (exit code real conferido): `tests/Feature/PortalCliente/Estrutura` (G1), `tests/Unit/PortalEstrutura`, G2 (DadosEfetivos, SincronizaPortal, MigracaoAnunciarAntigo, CustoDoAnuncio), G3 (DominioLiberaTodoModulo + PortalSemAnunciar), G5 (OfertaExcluidaNoPortal, ExclusaoDaEmpresaPreservaHistorico, MigracoesDaFaseDetectamMariaDb): todos EXIT=0, sem falha nova.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum além do modelo de ameaças do plano (T-167-26..30 mitigados: teste de ofertas antigas intactas, unique + reconciliador idempotente, `where company_id` + `findOrFail`, anúncios para a espera, activity `variacao_excluida`/`produto_excluido`).

## Self-Check: PASSED

Arquivos e commits `c59ae309`, `048b8165` conferidos.
