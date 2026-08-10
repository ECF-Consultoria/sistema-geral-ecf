---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
plan: 03
subsystem: database
tags: [laravel, migrations, eloquent, factories, phpunit, clicksign, pdf]

# Dependency graph
requires:
  - phase: 125-estrutura-de-dados-administrativa-v22-0
    provides: "Tabela contrato_assinaturas (batches 110/111), model ContratoAssinatura, ContratoAssinaturaFactory"
provides:
  - "Colunas pdf_path e pdf_assinado_path em contrato_assinaturas, aditivas e idempotentes (D-03)"
  - "$fillable de ContratoAssinatura com as duas colunas novas (mass assignment não falha em silêncio)"
  - "Guarda estática MigrationFase126ConvencoesTest cobrindo as 3 armadilhas de schema do projeto"
  - "State comSnapshot() na factory — servicos_snapshot com 2-3 serviços, valores com centavos, datas Y-m-d"
  - "State comEmpresaDeNomeExtremo() — empresa com nome 80+ caracteres acentuado, insumo do teste PDF-03"
affects: [126-04, 126-05, 127-orquestracao-contrato, 129-webhook-clicksign]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Migration ADITIVA (Schema::table, não Schema::create) guardada por Schema::hasColumn no up() e no down()"
    - "Guarda estática de convenções de migration lendo o texto do arquivo (sem RefreshDatabase), molde replicado da Fase 125"

key-files:
  created:
    - database/migrations/2026_08_10_120000_add_pdf_paths_to_contrato_assinaturas_table.php
    - tests/Feature/Phase126/MigrationFase126ConvencoesTest.php
    - tests/Feature/Phase126/ContratoAssinaturaPdfPathsTest.php
  modified:
    - app/Models/ContratoAssinatura.php
    - database/factories/ContratoAssinaturaFactory.php

key-decisions:
  - "Nenhum índice nas duas colunas novas: são carga (caminho de arquivo), não critério de busca — a guarda estática foi adaptada para aceitar zero ocorrências de índice sem falhar (diferente do molde da Fase 125, que sempre tinha índice)"
  - "comSnapshot() com 3 serviços fixos (não fake() aleatório) para o teste ser determinístico e a régua do Success Criteria 3 (forma e volume de dado real) ser auditável"
  - "comEmpresaDeNomeExtremo() como state dedicado na factory, não no teste do plano 126-05 — evita que cada teste invente seu próprio nome extremo"

requirements-completed: [PDF-01]

# Metrics
duration: 25min
completed: 2026-08-10
---

# Phase 126 Plan 03: Migration pdf_path/pdf_assinado_path + factory realista Summary

**Migration aditiva e idempotente abre `pdf_path`/`pdf_assinado_path` em `contrato_assinaturas` (D-03), com `$fillable` atualizado na mesma entrega e uma factory com `servicos_snapshot` realista e empresa de nome extremo para os planos do PDF (126-04/05).**

## Performance

- **Duration:** ~25 min
- **Tasks:** 2/2 completos
- **Files modified:** 5 (2 criados, 1 migration nova, 2 testes novos)

## Accomplishments
- Colunas `pdf_path` e `pdf_assinado_path` existem em `contrato_assinaturas`, aditivas sobre a tabela de produção (batches 110/111), com `up()`/`down()` guardados por `Schema::hasColumn` — reexecução segura.
- `$fillable` de `ContratoAssinatura` acompanhou a migration na mesma task — mass assignment das duas colunas não falha em silêncio (Pitfall 4 do RESEARCH).
- Guarda estática `MigrationFase126ConvencoesTest` cobre as 3 armadilhas do projeto (enum + SQLite, FK `nullOnDelete` sem `nullable`, índice > 64 chars) lendo o TEXTO da migration, sem tocar banco.
- `ContratoAssinaturaFactory` ganhou `comSnapshot()` (2-3 serviços com valores com centavos e datas `Y-m-d`, mesma forma de `ContratoServico::$casts`) e `comEmpresaDeNomeExtremo()` (nome 109 caracteres, acentuado, com hífen) — insumo pronto para os testes de PDF dos planos 126-04/05.

## Task Commits

Each task was committed atomically:

1. **Task 1: Migration aditiva (D-03) + $fillable na mesma entrega + guarda estática** - `c0fc392e` (feat)
2. **Task 2: State comSnapshot() na factory + teste de persistência das colunas** - `1dd85838` (feat)

_Sem tasks TDD com múltiplos commits — ambas escreveram implementação + teste na mesma entrega, conforme molde da Fase 125._

## Files Created/Modified
- `database/migrations/2026_08_10_120000_add_pdf_paths_to_contrato_assinaturas_table.php` - migration aditiva/idempotente das duas colunas novas
- `app/Models/ContratoAssinatura.php` - `$fillable` ganha `pdf_path` e `pdf_assinado_path`
- `tests/Feature/Phase126/MigrationFase126ConvencoesTest.php` - guarda estática das 3 armadilhas de schema, adaptada para migration sem índice
- `database/factories/ContratoAssinaturaFactory.php` - states `comSnapshot()` e `comEmpresaDeNomeExtremo()`
- `tests/Feature/Phase126/ContratoAssinaturaPdfPathsTest.php` - prova ponta a ponta das colunas e dos dois states novos

## Decisions Made
- **Guarda de índice adaptada, não copiada:** o molde da Fase 125 usa `assertNotEmpty` para exigir pelo menos um índice nomeado. Esta migration não cria índice nenhum (colunas são carga, não critério de busca) — a adaptação aceita zero ocorrências sem falhar, evitando falso negativo.
- **`comSnapshot()` com dados fixos, não `fake()` aleatório:** garante determinismo no teste e torna a "forma e volume de dado real" (régua da 126-VALIDATION.md) auditável a olho, sem depender de seed.
- **Nome extremo como state da factory, não inline no teste do plano 126-05:** centraliza a disciplina de "nome inventado, nunca copiado de empresa real" (D-15) num único lugar.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- As colunas e o `$fillable` estão prontos para os planos 126-04/05 (`ContratoPdfService`) gravarem `pdf_path` ao gerar o PDF.
- `comSnapshot()` e `comEmpresaDeNomeExtremo()` estão prontos para os testes `ContratoPdfServiceTest` (PDF-01/02/03) do próximo plano consumirem sem inventar dado.
- **Verificação manual pendente, não bloqueia este plano:** rodar a migration contra o MariaDB real com `migrate --path=` e autorização explícita — o SQLite dos testes não reproduz os erros 1830/1059. Registrada como checkpoint no plano 126-06.

---
*Phase: 126-client-clicksign-pdf-do-contrato-v22-0*
*Completed: 2026-08-10*

## Self-Check: PASSED

Todos os arquivos criados e os 3 commits de task (c0fc392e, 1dd85838, f0f04d17) foram confirmados no disco/histórico do git.
