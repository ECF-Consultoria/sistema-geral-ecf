---
phase: 172-ficha-do-portal-completa-ate-o-publicador
plan: 09
subsystem: publicador
tags: [ia, mag-t8, descricao, portal, job, cache]
requires: [172-07]
provides:
  - DescricaoIaService (pedir, estado, executar, falhou, specs)
  - GerarDescricaoIaJob (fila high, tries 1, timeout 300)
  - POST/GET mlb.anuncios.publicador.descricao-ia (+ .status)
  - estado().portal.descricao_cliente
affects: [172-10]
key-files:
  created:
    - app/Services/Publicador/DescricaoIaService.php
    - app/Jobs/Publicador/GerarDescricaoIaJob.php
    - app/Http/Controllers/MlbPublicadorDescricaoController.php
    - tests/Feature/Publicador/DescricaoIaTest.php
  modified:
    - routes/mlb_anuncios.php
    - app/Services/Publicador/EditorRascunhoService.php
requirements: [FP172-08]
completed: 2026-10-08
---

# Phase 172 Plan 09: descrição do cliente como insumo do MAG T8

O servidor entrega ao editor a descrição que o cliente escreveu no Portal e gera, sob demanda ou uma vez automaticamente, a descrição MAG T8 no cache, sem nunca gravar no rascunho.

## Commits

| Task | Commit | Assunto |
|---|---|---|
| 1 | 02ea7b80 | DescricaoIaService e GerarDescricaoIaJob geram a descricao MAG T8 no cache |
| 2 | 42ccc308 | rotas descricao-ia e portal.descricao_cliente no estado do editor |

## Decisões de implementação

- `specs()` = atributos preenchidos do rascunho (nome vindo do schema JÁ guardado em `MlCategoriaSchema`, sem HTTP), medidas `SELLER_PACKAGE_*` em linhas rotuladas, e o bloco fixo "Descrição fornecida pelo cliente:"; corte em 8000 caracteres.
- Automático: `Cache::add` só depois de checar descrição vazia e texto do cliente existente, para não gastar a única chance do rascunho sem material.
- `POST` automático que devolve null responde 200 `ja_pedido` (descrição vazia e chave automática já gravada) ou `nao_se_aplica`.
- Limpeza de HTML copiada da regra de `IaParaRascunhoService::limparDescricao` (helper privado); prompts do `AnaliseAnuncioService` e os arquivos de palavras-chave não foram tocados.
- `EditorRascunhoService` ganhou `PortalProdutoLeitor` no construtor (resolvido pelo container).

## Testes

- `DescricaoIaTest`: 15 testes, 56 asserções, OK (nenhuma chamada real à IA ou ao ML).
- `tests/Feature/Publicador` + `tests/Unit/Publicador`: 929 testes, 4484 asserções, OK (era 896 no fim do 172-07).

## Deviations from Plan

Nenhuma. O construtor do serviço não recebe `RascunhoRepository` (não é necessário: só leitura via relação `atributos`).

## Known Stubs

Nenhum. Sem alteração de front-end (sem `npm run build`); a tela que aplica o resultado fica para o plano seguinte.

## Self-Check: PASSED

Arquivos e commits conferidos; STATE.md e ROADMAP.md intocados.
