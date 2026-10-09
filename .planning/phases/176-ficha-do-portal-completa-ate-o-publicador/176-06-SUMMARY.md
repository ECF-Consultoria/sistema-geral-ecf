---
phase: 176-ficha-do-portal-completa-ate-o-publicador
plan: 06
subsystem: publicador
tags: [preco, dadosefetivos, rascunhosnapshot, agrupamento]
requires: [176-03]
provides:
  - DadosEfetivosService::daProduto devolve precos_por_variante (SKU normalizado -> listing_type_id -> preco) para produto agrupado
  - RascunhoSnapshot::comEfetivos(titulos, precos, porVariante = [])
affects: [176-12]
key-files:
  modified:
    - app/Services/Publicador/DadosEfetivosService.php
    - app/Support/Publicador/RascunhoSnapshot.php
    - app/Services/Publicador/ConferenciaService.php
    - app/Services/Publicador/EditorRascunhoService.php
    - app/Services/Publicador/Criativos/ContextoCriativoDoPublicador.php
  created:
    - tests/Feature/Publicador/PrecoPorVarianteTest.php
requirements: [FP176-05]
completed: 2026-10-08
---

# Phase 176 Plan 06: preço efetivo por variante

Cada cor do rascunho agrupado passa a mostrar, conferir e publicar o preço da Precificação da SUA oferta. Produto não agrupado não recebe a chave nova e se comporta como antes.

## Commits

| Task | Commit | Assunto |
|---|---|---|
| 1 | 72160ecb | feat(176-06): preco efetivo por variante no produto agrupado |
| 2 | 8e114c83 | feat(176-06): editor, conferencia e criativos passam o preco por variante |

## Decisões de implementação

- Ofertas do mapa: `fase = simples`, mesma `company_id` do pub_produto, variação da mesma Company e do `estrutura_produto_id` (T-176-19). Uma única chamada `pagina()` com os ids de todas as cores.
- A leitura de `anunciado` por tipo virou o helper privado `precosAnunciados`, usado também por `daOferta`.
- `comEfetivos`: SKU da variante vem de `dados.atributos.SELLER_SKU`, com fallback ao `SELLER_SKU` do produto (mesmo fallback do `ValidadorRascunho`). Preço digitado vence; sem casamento cai em `$precos` (âncora).
- Os 5 call sites (Conferência 1, Editor 3, Criativos 1) passam `$e['precos_por_variante'] ?? []`; os mocks do `CenarioCadeira` sem a chave seguem válidos.

## Testes

- `tests/Feature/Publicador` inteiro: 589 testes, 3341 asserções, OK (baseline G1: 567; antes deste plano: 583; +6 novos, 0 falha).
- Novos (`PrecoPorVarianteTest`): preço por variante com digitado vencendo e sem casamento na âncora; regressão sem o 3º argumento; mapa por SKU com uma só chamada à Precificação; produto não agrupado sem a chave; variação/oferta de outra Company fora do mapa; `estado()` e conferência usando o preço da variante.

## Deviations from Plan

Nenhuma de regra. Os testes das duas tasks ficaram em um único arquivo, então o teste de `estado()`/conferência entrou no commit da task 1 (antes dos callers); ele só passa com o commit da task 2.

## Known Stubs

Nenhum.

## Self-Check: PASSED

Commits e arquivos conferidos; STATE.md e ROADMAP.md intocados. Sem alteração de front-end (sem `npm run build`).
