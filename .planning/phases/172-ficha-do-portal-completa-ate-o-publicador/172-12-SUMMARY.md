---
phase: 172-ficha-do-portal-completa-ate-o-publicador
plan: 12
subsystem: publicador
tags: [sincronizar, job, resumo, isolamento, d-10]
requires: [172-03, 172-08, 172-10]
provides:
  - PreencherRascunhoDoPortalJob (um por produto agrupado/composto, fila high)
  - ResumoDoSincronizar (resumo agregado por pedido, escopado por empresa)
  - GET publicador/empresas/{conta}/sincronizar/{pedido}
  - painel de resumo na página de produtos do Publicador
affects: [172-13]
key-files:
  created:
    - app/Jobs/Publicador/PreencherRascunhoDoPortalJob.php
    - app/Services/Publicador/ResumoDoSincronizar.php
    - tests/Feature/Publicador/SincronizaPortalCompletoTest.php
    - resources/js/Components/Mlb/Publicador/ResumoDoSincronizar.jsx
    - resources/js/Components/Mlb/Publicador/resumoDoSincronizar.js
    - tests/js/publicador-sincronizar-resumo.test.js
  modified:
    - app/Http/Controllers/MlbPublicadorEntradaController.php
    - routes/mlb_anuncios.php
    - resources/js/Components/Mlb/Publicador/BotaoSincronizarPortal.jsx
    - resources/js/Pages/Mlb/Publicador/Produtos.jsx
requirements: [FP172-06, FP172-09]
completed: 2026-10-08
---

# Phase 172 Plan 12: Sincronizar do Portal ponta a ponta

O clique agrupa (172-03), despacha um `PreencherRascunhoDoPortalJob` por item de `para_preencher` (fila `high`, `timeout` 300 s, `tries` 1, `failed()` registra aviso no resumo), e a página acompanha o resumo agregado por `pedido` (uuid, cache de 1 h, escopado por `company_id`).

## Commits

| Task | Commit | Assunto |
|---|---|---|
| 1 | 8dfbe81b | feat(172-12): Sincronizar despacha um Job por produto e agrega o resumo por pedido |
| 2 | f046133e | test(172-12): Sincronizar isolado por empresa, sem piloto, sem escrita no ML e idempotente |
| 3 | 31348224 | feat(172-12): botao acompanha o preenchimento e a pagina mostra o resumo do Sincronizar |

## Mutação 1 (isolamento)

Removido `->where('company_id', $p->company_id)` de `PortalProdutoLeitor::produtoDoGrupo`. Resultado: `test_isolamento_entre_empresas_nada_de_b_entra_no_rascunho_de_a` VERMELHO ("o rascunho de A leu variações de B. Failed asserting that 3 is identical to 0"). Filtro restaurado (`git checkout` do arquivo, que estava limpo) e o teste voltou a verde (9/9). O cenário usa um `PubProduto` de A apontando para o produto de B (dado hostil) além do fluxo normal de duas empresas.

## Testes

- `SincronizaPortalCompletoTest`: 9 testes, 51 asserções (resumo pronto, 404 de outra empresa/inexistente, `failed()`, sem pedido quando nada a preencher, Job na fila high, isolamento, fora do piloto D-10, nada_no_ml com conta liberada + token (zero HTTP, zero `pub_publicacoes`), idempotente (contagens e revisão iguais)).
- `tests/Feature/Publicador` inteiro: 663 testes, 3650 asserções, OK (era 583 no 172-03).
- `npm run test:js`: 1332 testes, 2 falhas (as 2 antigas conhecidas); `publicador-sincronizar-resumo` 7/7 e `publicador-entrada` verdes.
- `npm run build`: exit 0, manifest contém `Pages/Mlb/Publicador/Produtos.jsx`.

## Deviations from Plan

**1. [Rule 3 - Bloqueio] Colisão de nomes por caixa no Windows**
- `ResumoDoSincronizar.jsx` e `resumoDoSincronizar.js` (nomes do plano) colidem na resolução de import do Vite em FS sem distinção de caixa: o build falhou com "default is not exported".
- Fix: imports com extensão explícita (`.jsx` em Produtos.jsx, `.js` no componente). Nomes mantidos.

**2. Critério de aceite `grep ContasLiberadas` no controller = 0 não se aplica literalmente**
- O controller já usa `ContasLiberadas::libera` no método `produtos()` (selo da página, pré-existente). O caminho `sincronizar`/`resumoDoSincronizar`, o Job e o `PortalParaRascunhoService` têm 0 ocorrências, e o teste `fora_do_piloto` prova D-10.

**3. Teste do `failed()`**: com `QUEUE_CONNECTION=sync` a exceção sobe ao request, então o teste chama `failed()` direto (o que a fila real faz) e confere o resumo fechado com aviso.

## Known Stubs

Nenhum.

## Self-Check: PASSED

Arquivos e commits conferidos; STATE.md e ROADMAP.md intocados.
