---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 10
subsystem: portal-estrutura
tags: [sugestoes, lista, paginacao-servidor, frete, logistica-conjunto]
requires: ["168-04", "168-08"]
provides:
  - "ListaDeSugestoes::listar(Company, array $filtros, int $pagina): array (prop `sugestoes` da página)"
  - "ListaDeSugestoes::cotarPagina(Company, array $chaves): array{fretes, conectado, falhou, pendentes}"
affects: ["168-13", "168-14", "168-15"]
key-files:
  created:
    - app/Services/Portal/Estrutura/Geracao/ListaDeSugestoes.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/ListaDeSugestoesTest.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/LogisticaDoConjuntoTest.php
key-decisions:
  - "paraPagina() é o único ponto de tradução do gerador para o item da página; oferta_id, tipos, par e descartada não saem do servidor"
  - "Facetas: famílias contadas com fase/tipo/busca (sem família); fases contadas com família/tipo/busca (sem fase); contagens das abas sem filtro nenhum"
  - "Valor de família é string: id como texto ou 'sem'; filtro desconhecido vira sem filtro"
  - "Teto corta a lista filtrada antes da paginação; as contagens das abas não são cortadas"
requirements-completed: [PR168-10, PR168-11]
duration: ~30min
completed: 2026-10-07
---

# Phase 168 Plan 10: Lista das sugestões — Summary

`ListaDeSugestoes::listar` monta abas, painel (contagens, fases, famílias), filtros e página de 20 no servidor, e só a página ganha logística, custo e frete estimado do conjunto (uma chamada a `FreteMe2Service::estimar`, nenhuma requisição ao ML); `cotarPagina` faz a cotação real sob demanda, só por GET.

## Commits

- `029baf0d` feat(168): lista de sugestões com painel no servidor e logística/frete do conjunto (3 caminhos do plano; as duas tarefas num commit, como o plano pede)

## Verificação

- `ListaDeSugestoesTest` (15) + `LogisticaDoConjuntoTest` (8) = 24 testes, 176 asserções, exit 0 (SQLite em memória).
- Cobertos: contagens iguais em qualquer página/filtro; Polo 23 (9/6/8), Solo A 3, Sem família 3 por último; 20 + 9 sem repetir e página 99 = última; `familia_continua`; filtros de fase, tipo, SKU e nome sem caixa/acento; filtro desconhecido; lote e teto; aba sem tipo com candidatos; descartadas; `sku_repetido`; limites do config; isolamento por empresa; os 2 casos de CONTRATO de chaves; combit 1 mesa + 4 cadeiras = 620,0; kit com banco sem volumes = pendente com o produto citado; combo ×2 de cadeira ME2 com frete `tabela_ecf`; `Http::assertNothingSent` e contagens de ofertas/precificações iguais; `estimar` `->once()`; `cotarPagina` sem conta, com conta (só GET) e com corte em `por_pagina`/chave descartada/inexistente.
- A rodada do diretório `Sugestoes` inteiro ficou para o 168-13, como o plano manda.

## Deviations from Plan

None - plano executado como escrito.

## Observações para os próximos planos

- `familias[].valor` e `familia_totais` usam string (`'12'` ou `'sem'`); `familia_continua` idem.
- `produtos[id]` e `produtos_sem_tipo[]` trazem `familia` como NOME (texto); o id da família está só em `itens[].familia.id` e nas opções de `familias`.
- Na aba `sem_tipo`, `por_fase` é zerado e só valem os filtros de família e busca.
- `frete` do item pendente (sem medida) vem no formato "vazio" do `FreteMe2Service` (`valor` nulo).
- `cotarPagina` devolve `fretes` indexado pela chave da sugestão; sem chave vigente válida devolve `fretes` vazio sem tocar o ML.

## Known Stubs

None.

## Threat Flags

None. T-168-27 a T-168-30 mitigados e testados (filtros contra lista fechada, uma geração por chamada, só GET na cotação, nada gravado).

## Self-Check: PASSED

- Os 3 arquivos existem; commit `029baf0d` presente com só os 3 caminhos; STATE.md e ROADMAP.md não tocados.
