---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 01
subsystem: publicador
tags: [alavancas, trava, migration, historico, mariadb]
requires: []
provides:
  - AlavancasLiberadas (trava própria, regra ALAV-LIB)
  - bloco 'alavancas' e 'ml_api_base' em config/publicador.php
  - tabela pub_alavanca_escritas e model PubAlavancaEscrita
affects: [166-02, 166-03, 166-11]
key-files:
  created:
    - .planning/phases/166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado/166-BASELINE-TESTES.md
    - app/Support/Publicador/AlavancasLiberadas.php
    - app/Models/PubAlavancaEscrita.php
    - database/migrations/2026_10_05_100000_create_pub_alavanca_escritas_table.php
    - tests/Unit/Publicador/Alavancas/AlavancasLiberadasTest.php
    - tests/Feature/Publicador/Alavancas/MigracaoHistoricoTest.php
  modified:
    - config/publicador.php
    - tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php
decisions:
  - Trava das Alavancas sem fallback para a trava de publicação; default 459 só em Company
  - Migration só CREATE; 3 FKs SET NULL em colunas anuláveis; sem enum/change
metrics:
  completed: 2026-10-04
  tasks: 3
---

# Fase 166 Plano 01: Fundação (baseline, trava e histórico) Summary

Baseline medido em commit próprio, trava `AlavancasLiberadas` independente da publicação (listas por âncora, fail-closed) e tabela `pub_alavanca_escritas` com desenho escrito na migration e FKs SET NULL.

## Commits

| Task | Commit | Descrição |
|---|---|---|
| 1 | `17bf0f30` | docs(166): baseline dos testes antes da fase (só o arquivo de baseline) |
| 2 | `896073ce` | feat(166-01): trava própria das Alavancas |
| 3 | `8b5f975b` | feat(166-01): histórico de escritas (pub_alavanca_escritas) |

## Baseline (antes de qualquer código, HEAD `2a279068`)

| Grupo | Testes | Asserções | Falhas | Exit |
|---|---|---|---|---|
| `tests/Feature/Publicador` | 238 | 1473 | 0 | 0 |
| `tests/Unit/Publicador` | 156 | 522 | 0 | 0 |
| `npm run test:js` | 710 | n/d | 2 (pré-existentes, planilha/fases de Polos) | 1 |

Depois deste plano: Feature 244 / 1520 / 0 / exit 0 (+6, os de `MigracaoHistoricoTest`); Unit 165 / 541 / 0 / exit 0 (+9, os de `AlavancasLiberadasTest`). JS não foi tocado e não foi rodado de novo. A coluna "Depois (166-16)" do baseline fica para o gate final.

## Prova escolhida para "sem fallback"

As DUAS ficaram: a prova pelo ambiente funcionou (putenv/$_ENV/$_SERVER + `require config_path('publicador.php')`): sem a variável das Alavancas e com `PUBLICADOR_EMPRESAS_PILOTO=7` e `PUBLICADOR_CONTAS_LIBERADAS_COMPANIES=8`, o resultado é `[459]`; com a variável vazia, `[]`. Além disso há a prova pela fonte (o bloco `'alavancas'` não contém os nomes das variáveis da publicação).

## Mutação (learnings §5)

Trocando `publicador.alavancas.contas_liberadas.companies` por `publicador.contas_liberadas.companies` em `libera()`, o `AlavancasLiberadasTest` falhou (4 falhas em 9 testes). Desfeita (arquivo restaurado, sem diff).

## Deviations from Plan

**1. [Rule 1 - Bug] Comentários citavam nomes proibidos pelos próprios testes de conteúdo**
- Comentário de `config/publicador.php` citava `PUBLICADOR_CONTAS_LIBERADAS_*`/`PUBLICADOR_EMPRESAS_PILOTO` dentro do bloco `alavancas`; e o docblock da migration citava `->change()` e `enum`. Reescritos sem esses tokens para a guarda por conteúdo valer (sem afrouxar o teste).

**2. [Rule 1 - Bug] Teste de preservação usa `forceDelete()` no usuário**
- `User` tem `SoftDeletes`; `delete()` não aciona o SET NULL. A prova do SET NULL é a exclusão física. Sem mudança de código de produção.

Fora isso, o plano foi executado como escrito.

## Notas

- A migration NÃO foi rodada no MariaDB local; a prova é SQLite dos testes + guarda por conteúdo + varredura de driver em `MigracoesDaFaseDetectamMariaDbTest`.
- Nenhuma chamada de rede ao Mercado Livre; STATE.md e ROADMAP.md intocados.

## Known Stubs

Nenhum.

## Self-Check: PASSED

Arquivos criados conferidos e os três commits existem em `git log` (17bf0f30, 896073ce, 8b5f975b).
