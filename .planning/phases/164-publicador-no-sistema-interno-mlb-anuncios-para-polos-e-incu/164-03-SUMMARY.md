---
phase: 164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 03
subsystem: publicador
tags: [publicador, polos, incubadora, contas-liberadas, inertia]
requires: [164-01]
provides:
  - MlbEmpresa::scopePrograma / programaPublicador (D13)
  - ContasLiberadas::libera / exigir (D21)
  - ProgramasPublicadorService (empresas, contagens, indicadores, resolver, produtosQuery)
  - MlbPublicadorEntradaController@index (tela A, /mlb/anuncios)
affects: [164-06, 164-07, 164-10]
key-files:
  created:
    - app/Support/Publicador/ContasLiberadas.php
    - app/Services/Publicador/ProgramasPublicadorService.php
    - app/Http/Controllers/MlbPublicadorEntradaController.php
    - tests/Feature/Publicador/ProgramaPublicadorTest.php
    - tests/Feature/Publicador/MlbPublicadorEntradaTest.php
  modified:
    - app/Models/MlbEmpresa.php
    - config/publicador.php
    - routes/mlb_anuncios.php
    - app/Http/Controllers/MlbAnuncioController.php
    - tests/Feature/AnunciosPolosNaListagemTest.php
    - tests/Feature/Phase75/AnunciosEscopoResponsavelTest.php
decisions:
  - Programa derivado da MlbEmpresa, nunca gravado (D13)
  - Contas liberadas por âncora, vazio = ninguém; começa só com a Company 459 (D21)
metrics:
  tasks: 2
  completed: 2026-10-02
---

# Phase 164 Plan 03: Entrada do Publicador por programa Summary

`/mlb/anuncios` agora abre a tela A do Publicador, com abas Polos, Incubadora e Gestão (`?programa=`), lista de empresas com conta do ML, situação do Portal, contagens e a trava D21 de contas liberadas, só para admins.

## Tarefas

| # | Tarefa | Commit |
|---|--------|--------|
| 1 | Programa da empresa (D13) e contas liberadas (D21) | b68cc2e4 |
| 2 | Entrada do Publicador por programa (tela A) | a2bbb545 |

## O que foi feito

- `MlbEmpresa::scopePrograma` e `programaPublicador()` com a mesma regra (matriz testada: nenhuma empresa cai nos dois programas).
- `ContasLiberadas` com listas separadas por âncora e fail-closed; `exigir` lança `RegraViolada('CONTA-LIB')`. `config('publicador.contas_liberadas')` com default `companies=[459]`; `empresas_piloto` mantida (sai em 164-07).
- `ProgramasPublicadorService`: linhas no contrato da tela A, agregados em consultas agrupadas (nº de consultas igual com 3 e 30 empresas), `resolver()` e `produtosQuery()` para 164-06.
- Controller enxuto com busca, filtros (`todos|prontos|atencao|nunca`) e paginação de 50. Rota `/` trocada; `index()`, `empresas()`, `empresasDeConsultoria()` e `empresasDePolos()` removidos do `MlbAnuncioController` (sem outros chamadores).
- Testes antigos adaptados sem apagar casos (Polos em `?programa=polos`, Company conectada em `?programa=gestao`).

## Verificação

- ProgramaPublicadorTest 8 testes / 39 asserções; MlbPublicadorEntradaTest 11 / 92: verdes.
- `tests/Feature/Publicador` 128/691 e `tests/Unit/Publicador` 152/507: verdes (280 testes, baseline 261 + 19 novos).
- `AnunciosPolosNaListagemTest` 6/21, `Phase75` 43/151, `MlTokenAncoraPolosTest` 7/14, `Phase134` 24/107, `Phase76` 23/103, `AnuncioIaAnaliseTest` 28/71: todos exit 0.
- `route:list`: `mlb.anuncios.index` aponta para `MlbPublicadorEntradaController@index`.

## Deviations from Plan

- A linha da tela A carrega um campo extra `publicados_mes` (usado só para somar o indicador), além do contrato do plano.
- No teste de N+1 há uma requisição de aquecimento antes de medir (a primeira requisição faz consultas de boot que distorciam a comparação).
- Nos testes de `MlbEmpresa` o helper usa `->fresh()`: o modelo recém-criado não tem os atributos default e o acesso a `projeto` falha.
- Tela React `Mlb/AnunciosEmpresas.jsx` NÃO alterada aqui (plano de front, 164-10); ela segue recebendo props novas até lá.

## Known Stubs

Nenhum.

## Self-Check: PASSED

Arquivos criados existem e os commits b68cc2e4 e a2bbb545 constam no log. STATE.md e ROADMAP.md não foram tocados.
