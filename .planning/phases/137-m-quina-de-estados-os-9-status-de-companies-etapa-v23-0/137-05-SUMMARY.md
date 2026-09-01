---
phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
plan: 05
subsystem: database
tags: [laravel, eloquent, artisan-command, mariadb, companies, state-machine, backfill]

# Dependency graph
requires:
  - phase: 137-02
    provides: "companies.etapa (aditiva) + 9 constantes Company::ETAPA_*/ETAPAS"
  - phase: 137-03
    provides: "EtapaTransicaoService::carimbarBackfill() — escrita em massa exclusiva do backfill"
provides:
  - "Comando etapa:backfill (dry-run por padrão, sem --limite/--servico) que migra o legado em exatamente dois baldes (D-04)"
  - "Prova automatizada (9 testes) de que balde 1 (analista OU estrategista) recebe em_operacao, balde 2 fica NULL, nenhuma etapa intermediária aparece (D-05), a colisão distribuir=operação é aceita para o legado (D-06), e o payload de GET /companies é idêntico antes/depois (Success Criteria nº 1)"
  - "137-BACKFILL-CONTAGENS.md — contagens antes/depois do --apply real contra MariaDB local, obtidas por reconsulta SQL direta (D-08), com o roteiro de execução em produção escrito"
affects: [137-06, 137-07, 138, 139, 141, 142, 143]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Molde de comando dry-run-por-padrão (OnboardingBackfillContratos): --apply explícito, contagem antes/depois, try/catch com Log::error taggeado, aviso explícito de que stdout não é prova"
    - "Backfill em massa delega a escrita a um método de serviço dedicado (carimbarBackfill), nunca escreve companies.etapa diretamente no comando — preserva o ponto único de escrita (Success Criteria nº 2)"

key-files:
  created:
    - app/Console/Commands/EtapaBackfill.php
    - tests/Feature/Phase137/EtapaBackfillTest.php
    - .planning/phases/137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/137-BACKFILL-CONTAGENS.md
  modified: []

key-decisions:
  - "D-04/D-05 confirmados em execução: balde 1 = whereHas('analistaPerformance') OR whereHas('estrategistaPerformance') (reuso literal das relações do model, nunca query reescrita); balde 2 é implícito — não existe UPDATE para ele, nasce NULL"
  - "D-06 provado por teste: empresa já distribuída (tem analista/estrategista) com Onboarding em rascunho ou andamento recebe em_operacao no backfill, não uma etapa intermediária"
  - "D-08 seguido à risca: as contagens registradas em 137-BACKFILL-CONTAGENS.md vieram de DB::table(...) num tinker separado da execução do comando, nunca do stdout do próprio etapa:backfill"
  - "Backfill LOCAL executado de verdade contra MariaDB (ecf_admin, XAMPP) nesta sessão — não é dry-run documentado apenas; 1 empresa (id 55) recebeu etapa=em_operacao, 179 permanecem NULL"

patterns-established:
  - "Todo backfill em massa futuro que precise migrar dado legado para companies.etapa deve seguir o mesmo shape: dry-run por padrão, sem filtros parciais que permitam carimbar só metade da base, escrita delegada a um método de serviço dedicado, contagem antes/depois impressa mas explicitamente marcada como não-prova"

requirements-completed: [ETAPA-02]

# Metrics
duration: ~25min
completed: 2026-09-01
---

# Phase 137 Plan 05: Backfill em dois baldes das ~500 empresas legadas Summary

**Comando `etapa:backfill` (dry-run por padrão) migra o legado para `companies.etapa` em exatamente dois baldes — `em_operacao` para quem já satisfaz o cálculo atual de "em operação" (analista OU estrategista), `NULL` para todo o resto — provado por 9 testes automatizados e executado de verdade contra o MariaDB local, com as contagens antes/depois conferidas por reconsulta SQL direta ao banco (D-08).**

## Performance

- **Duration:** ~25 min
- **Tasks:** 3/3 completos
- **Files modified:** 3 (1 comando novo, 1 suíte de teste nova, 1 documento de contagens novo)

## Accomplishments

- `app/Console/Commands/EtapaBackfill.php` existe, é dry-run por padrão (`--apply` explícito), não aceita `--limite` nem `--servico` (os dois baldes de D-04 não admitem execução parcial), reusa literalmente `analistaPerformance()`/`estrategistaPerformance()` do model (nenhuma reescrita de query com o papel errado `'analista'` — Pitfall 5 do `137-RESEARCH.md`), e delega toda escrita a `EtapaTransicaoService::carimbarBackfill()` — o comando em si não contém nenhum `update([...'etapa'...])`.
- 9 testes novos em `tests/Feature/Phase137/EtapaBackfillTest.php`, todos verdes: balde 1 pela ponta do analista, balde 1 pela ponta do estrategista, balde 2 (contrato Performance ativo sem responsáveis, o caso dominante), D-05 (`DISTINCT etapa` só devolve `em_operacao` mesmo com múltiplas empresas em cenários diferentes), D-06 em duas variações (onboarding `rascunho` e `andamento`), dry-run não altera nenhuma linha, `carimbarBackfill()` não gera histórico em `company_etapa_transicoes`, e o teste mais crítico — o payload de `GET /companies` é **idêntico** (mesmo conjunto de ids) antes e depois do `--apply`, incluindo os dois casos de exclusão do Pitfall 3 (empresa com `MlbEmpresa` associada e empresa sem contrato Performance ativo, que devem continuar fora da tela nos dois momentos).
- Backfill executado **de verdade** contra o MariaDB local (`ecf_admin`, XAMPP): 180 empresas total, 1 caiu no balde 1 (id 55, recebeu `etapa = em_operacao`), 179 permaneceram `NULL`. Números batem com a medição prévia de `137-RESEARCH.md` § "Contagem real". Todas as contagens em `137-BACKFILL-CONTAGENS.md` vieram de `DB::table(...)` num `tinker` separado da execução do `etapa:backfill`, nunca do stdout do próprio comando (D-08).
- `137-BACKFILL-CONTAGENS.md` registra os seis números (antes/depois × total/etapa9/etapaNull), confirma `company_etapa_transicoes` em `0` antes e depois (sem histórico, como projetado), confirma `DISTINCT etapa` só devolvendo `em_operacao`, e escreve o roteiro completo de execução em produção (que exige autorização explícita, nenhum deploy saiu desta fase) mais o comando de reversão local.
- `Baseline command` (`Phase37CompaniesPerformanceFilterTest` + `CompanyControllerResponsavelPerformanceTest`) permanece **24/24 verde**, idêntico ao registrado em `137-BASELINE-TESTES.md` antes de toda a fase — rodado inclusive **depois** do `--apply` real contra o MariaDB local, confirmando que nada na tela `/companies` mudou.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Comando `etapa:backfill`, dry-run por padrão** - `5acda646` (feat)
2. **Task 2: Prova automatizada dos dois baldes e da tela preservada** - `af2fc7ed` (test)
3. **Task 3: Contagens antes/depois por reconsulta ao banco (D-08)** - `76800cc0` (docs)

_Este plano não teve tasks TDD — cada arquivo de teste nasceu junto com a implementação que prova (`tdd_mode` desligado nesta fase, conforme `137-VALIDATION.md`)._

## Files Created/Modified

- `app/Console/Commands/EtapaBackfill.php` (novo) — comando `etapa:backfill`, dry-run por padrão, balde 1 via `whereHas('analistaPerformance')->orWhereHas('estrategistaPerformance')`, escrita delegada a `EtapaTransicaoService::carimbarBackfill()`, contagem antes/depois impressa com aviso explícito de que o stdout não é prova.
- `tests/Feature/Phase137/EtapaBackfillTest.php` (novo) — 9 testes cobrindo os dois baldes, D-05 (nenhuma etapa intermediária), D-06 (colisão aceita), dry-run, ausência de histórico, e o Success Criteria nº 1 (payload de `/companies` idêntico antes/depois, incluindo os casos de exclusão do Pitfall 3).
- `.planning/phases/137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/137-BACKFILL-CONTAGENS.md` (novo) — contagens do backfill real local, fonte `reconsulta SQL direta`, aviso de que a base local não é medida de produção, roteiro de execução em produção, comando de reversão local.

## Decisions Made

- **Balde 1 reusa literalmente as relações do model** — nenhuma query reescrita à mão. Confirmado por grep: o arquivo não contém a string `'analista'` (papel errado na pivot) nem nenhum `update([...'etapa'...])`.
- **Amostra do dry-run limitada a 20 linhas** na tabela impressa (`$this->table()`), com aviso quando o balde 1 for maior — decisão de implementação não coberta pelo `137-CONTEXT.md`, para não estourar o terminal quando produção tiver ~500 empresas e um balde 1 potencialmente maior que o local (que tem só 1).
- **Backfill local rodado de verdade** (não só documentado como "seria assim") — o plano pediu explicitamente a execução real contra MariaDB local na Task 3, e a contagem final confirma que a regra roda corretamente contra o banco real, não só contra o SQLite dos testes.

## Deviations from Plan

None - plano executado exatamente como escrito. Nenhuma das 3 tasks exigiu fix de Regra 1/2/3, nem decisão de Regra 4.

## Issues Encountered

None. O comando funcionou de primeira contra MariaDB local; os 9 testes passaram na primeira execução completa (depois de um ajuste preventivo nos comentários do comando, feito ANTES do commit, para não deixar a substring literal `'analista'` em nenhum comentário — o acceptance criteria da Task 1 exige `grep -c "'analista'"` = 0, e o texto explicativo original citava o papel errado entre aspas simples).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

Fundação pronta para o plano 137-06 (fecha a superfície de escrita — varredura estática que prova que nenhum lugar de `app/` fora de `EtapaTransicaoService` grava `companies.etapa`, incluindo este comando) e para o plano 137-07 (filtro por etapa na listagem, incluindo o sentinela `sem_etapa` para as 179 empresas que ficaram `NULL` no backfill local).

Para as Fases 138-143: a base legada já está migrada localmente nos dois baldes esperados. Quando a execução em produção acontecer (fora desta fase, com autorização explícita), o roteiro está escrito em `137-BACKFILL-CONTAGENS.md` — três consultas SQL diretas antes/depois mais a comparação da lista da aba "Empresas" (Manual-Only Verification do `137-VALIDATION.md`).

Nenhum bloqueio.

---
*Phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0*
*Completed: 2026-09-01*

## Self-Check: PASSED

Todos os arquivos criados confirmados no disco (`app/Console/Commands/EtapaBackfill.php`, `tests/Feature/Phase137/EtapaBackfillTest.php`, `.planning/phases/137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/137-BACKFILL-CONTAGENS.md`) e os 3 commits de task confirmados em `git log` (`5acda646`, `af2fc7ed`, `76800cc0`).
