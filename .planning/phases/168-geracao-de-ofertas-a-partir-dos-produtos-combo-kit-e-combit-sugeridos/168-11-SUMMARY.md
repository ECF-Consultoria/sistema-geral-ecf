---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 11
subsystem: portal-estrutura
tags: [sugestoes, aceite, descarte, laravel]
requires: [168-08]
provides:
  - DecisoesDasSugestoes (aceitar, descartar, restaurar, definirGeracao)
affects: [168-13]
key-files:
  created:
    - app/Services/Portal/Estrutura/Geracao/DecisoesDasSugestoes.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/AceitarSugestaoTest.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/NadaDuplicadoTest.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/DescarteDeSugestaoTest.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/GeracaoDoProdutoTest.php
  modified: []
decisions:
  - "Aceite regera a sugestão dentro de lockForUpdate na empresa; composição nunca vem do request"
  - "Chave inválida, ausente, descartada ou já criada no lote conta em ja_existiam (sem distinguir, para não vazar existência de outra empresa)"
  - "Descartar só aceita chave que a geração conhece (vigente ou já descartada); insertOrIgnore torna idempotente"
metrics:
  tasks: 2
  tests: 31
  completed: 2026-10-07
---

# Phase 168 Plan 11: Decisões das sugestões Summary

Serviço `DecisoesDasSugestoes` com aceite seguro (regera, trava a empresa, cria por `EstruturaOfertaService::criar(varrerEspera: false)`, erro isolado por item, varredura única), descarte/restauração persistentes por empresa e definição de tipo/quantidades do produto na tabela 1:1, tudo com `RegistroEstrutura`.

## O que foi feito

- **aceitar**: limite `lote_aceite` (100) com ValidationException; transação + `lockForUpdate` na empresa; geração dentro do lock; mesma chave duas vezes no lote cria uma só; `ValidationException` de uma sugestão vira entrada em `erros`; `varrerEspera` uma vez no fim; eventos `oferta_criada` (por oferta) e `sugestoes_aceitas` (por lote). Não grava logística nem preço.
- **descartar / restaurar**: `insertOrIgnore` por empresa+chave+fase; restaurar apaga só as linhas da empresa e conta `ja_existem` pelo retrato.
- **definirGeracao**: produto por `where('company_id')->findOrFail` (404), tipo validado, quantidades por `Quantidades`; tudo nulo apaga a linha 1:1; ficha da 167 intocada.

## Verificação

4 arquivos de feature, 31 testes, 95 asserções, exit 0 (`AceitarSugestaoTest` 10, `NadaDuplicadoTest` 6, `DescarteDeSugestaoTest` 7, `GeracaoDoProdutoTest` 8). Rodada do diretório `Sugestoes` inteiro fica para a abertura do 168-13, conforme o plano.

## Deviations from Plan

- Os dois commits de tarefa foram unificados em um só (`d489b748`), como o próprio plano prevê ("Commit junto com a Task 2").
- Acrescentado um teste de lote vazio para atingir o mínimo de 16 testes em Aceitar+NadaDuplicado.

None além disso: plano executado como escrito.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum além do modelo de ameaças do plano (T-168-31 a T-168-35 mitigados e cobertos por teste).

## Self-Check: PASSED

- Arquivos criados existem; commit `d489b748` lista só os 5 caminhos do plano.
