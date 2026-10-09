---
phase: 176-ficha-do-portal-completa-ate-o-publicador
plan: 07
subsystem: publicador
tags: [portal, leitor, imagem, webp, rascunho]
requires: [176-03, 176-06]
provides:
  - PortalProdutoLeitor (doGrupo, daComposta, lerImagem, descricaoDoCliente) escopado por company_id
  - ConversorParaJpg::converter (D-15)
  - ImagemAssetService::receber(..., bool $enviar = true)
  - EditorRascunhoService::rascunhoDoProduto(PubProduto, bool $comSku = true)
affects: [176-08, 176-09, 176-10]
key-files:
  created:
    - app/Services/Publicador/PortalProdutoLeitor.php
    - app/Support/Publicador/Imagem/ConversorParaJpg.php
    - tests/Feature/Publicador/PortalProdutoLeitorTest.php
    - tests/Unit/Publicador/Imagem/ConversorParaJpgTest.php
  modified:
    - app/Services/Publicador/ImagemAssetService.php
    - app/Services/Publicador/EditorRascunhoService.php
    - tests/Feature/Publicador/ImagensTest.php
requirements: [FP176-06, FP176-08, FP176-09]
completed: 2026-10-08
---

# Phase 176 Plan 07: base de leitura do Portal para o Publicador

Leitor do Portal isolado por empresa, conversão WebP para JPG, foto guardada sem envio ao ML e rascunho criável sem HTTP de conta.

## Commits

| Task | Commit | Assunto |
|---|---|---|
| 2 | f13cec9f | feat(176-07): ConversorParaJpg (WebP vira JPG) e receber() sem enviar ao ML |
| 1 | af7dbcae | feat(176-07): PortalProdutoLeitor le o produto do Portal escopado por empresa |
| 3 | ef5e467b | feat(176-07): rascunhoDoProduto cria o rascunho sem ler a conta no ML |

## Decisões de implementação

- Todas as consultas do leitor filtram pela `company_id` do `PubProduto`; vínculo cruzado (produto, variação, componente) é descartado, e componente inválido da composta vira aviso em `avisos`.
- `lerImagem` exige o prefixo `estrutura/{empresa}/` e rejeita `..`; só então toca o disco.
- Número de consultas de `doGrupo` é fixo (eager loading); teste compara 2 e 6 cores.
- `daComposta` devolve também `avisos` (além de fase, sku, itens, pares). `tipo` de cada item usa tipo escolhido (`EstruturaProdutoGeracao`) ou inferido.
- `abrir()` = `rascunhoDoProduto` + `lerContaSeVencida`; resultado idêntico para quem chama.

## Testes

- `tests/Feature/Publicador` + `tests/Unit/Publicador`: 896 testes, 4350 asserções, OK.
- Novos: 4 (ConversorParaJpg), 1 (ImagensTest, `enviar: false` com `Http::assertNothingSent`), 13 (PortalProdutoLeitorTest).

## Deviations from Plan

Nenhuma. Testes escritos junto do código (sem ciclo RED observado separadamente).

## Known Stubs

Nenhum. Sem alteração de front-end (sem `npm run build`).

## Self-Check: PASSED

Commits e arquivos conferidos; STATE.md e ROADMAP.md intocados.
