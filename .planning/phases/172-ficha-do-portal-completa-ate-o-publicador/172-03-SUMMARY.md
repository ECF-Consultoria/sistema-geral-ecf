---
phase: 172-ficha-do-portal-completa-ate-o-publicador
plan: 03
subsystem: publicador
tags: [sincronizar, agrupamento, pub_produtos, portal-estrutura]
requires: [172-01]
provides:
  - Sincronizar cria/adota UM pub_produtos por produto do Portal (estrutura_produto_id), ancorado na 1ª variação
  - retorno do serviço com adotados, duplicados, avisos e para_preencher
  - situacaoPortal conta o grupo como cobertura
affects: [172-12]
key-files:
  modified:
    - app/Models/PubProduto.php
    - app/Services/Publicador/PublicadorSincronizaPortalService.php
    - app/Services/Publicador/ProgramasPublicadorService.php
    - tests/Feature/Publicador/SincronizaPortalTest.php
  created:
    - tests/Feature/Publicador/SincronizaPortalAgrupamentoTest.php
requirements: [FP172-05, FP172-09]
completed: 2026-10-08
---

# Phase 172 Plan 03: agrupamento do D-06 no Sincronizar

As ofertas Simples de um produto do Portal viram um único `pub_produtos` com `estrutura_produto_id`. Legado com rascunho ainda sem publicação é adotado (só `estrutura_produto_id` muda); legado publicado (por fato, via `IaParaRascunhoService::intocavel`) nunca é adotado. Nada é apagado.

## Commits

| Task | Commit | Assunto |
|---|---|---|
| 1 | bfcf1758 | feat(172-03): Sincronizar agrupa as cores de um produto do Portal em um unico produto |
| 2 | bc418bf8 | feat(172-03): situacaoPortal conta o grupo do produto como cobertura |

## Decisões de implementação

- Ordem de adoção: legado com rascunho não intocável (mais antigo), depois legado sem rascunho da oferta âncora, depois o sem rascunho mais antigo.
- Sem grupo e sem adoção: cria na 1ª oferta (por ordem, depois id) que ainda não tem pub_produto; se todas já têm (todas publicadas), aviso e nada é criado.
- `duplicados` lista os legados das outras cores a cada execução (não é gravado); aviso extra quando algum é intocável.
- `skuExibido`/`nomeExibido` do grupo usam o produto do Portal só se `company_id` coincide; senão cai no comportamento anterior.
- Corrida: captura 23000 e relê o grupo por `estrutura_produto_id`; `criados` não conta o que outro processo criou.
- Ofertas, variações e produtos consultados sempre com `company_id` da Company recebida (T-172-08).

## Testes

- `tests/Feature/Publicador` inteiro: 583 testes, 3326 asserções, OK (baseline G1: 567; +16 novos, 0 falha).
- Novos: 9 em `SincronizaPortalAgrupamentoTest` (cobre cada linha do behavior) e 3 no fim de `SincronizaPortalTest`; os 8 antigos intactos.

## Deviations from Plan

Nenhuma de regra. Observação: `MlbPublicadorEntradaController::sincronizar` não foi tocado (dono: 172-12), então `adotados`, `duplicados`, `avisos` e `para_preencher` ainda não chegam à tela.

## Known Stubs

Nenhum.

## Self-Check: PASSED

Commits e arquivos conferidos; STATE.md e ROADMAP.md intocados.
