---
phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
plan: 10
subsystem: database
tags: [eloquent, lockForUpdate, race-condition, laravel, artisan-command, traceability]

# Dependency graph
requires:
  - phase: 137-08
    provides: "Gate estático `EtapaPontoUnicoTest.php` (3 regras) que continua verde após as edições de `app/` deste plano"
  - phase: 137-09
    provides: "`company_etapa_transicoes.user_id` nullable + `nullOnDelete()` — schema estável para as novas linhas de histórico gravadas por `transicionar()`"
provides:
  - "`EtapaTransicaoService::transicionar()` releva e trava a linha (`lockForUpdate()`) DENTRO da transação, decidindo sobre o estado do banco, nunca sobre o objeto `Company` em memória (fecha WR-01/T-137-31/T-137-32)"
  - "`etapa:backfill` chaveia o balde 1 por `id` (único), imune a colisão de `companies.name` (fecha WR-02/T-137-33)"
  - "`137-01-PLAN.md` não reivindica mais `ETAPA-01`/`ETAPA-02` — traceability honesta (fecha WR sem código, T-137-35)"
affects: [138, 139, 141, 142, 143]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Releitura com `lockForUpdate()` DENTRO de `DB::transaction()`, decidindo sobre a instância travada — mesmo padrão de `CompanyScoreSnapshotWriter::sync()` (D-122-08) e `DesempenhoMetricasManuaisController::salvar()`"
    - "Coleção de ids chaveada só por `id` (nunca por coluna sem `unique()`) quando o resultado alimenta um `whereIn()` de escrita em massa"

key-files:
  created: []
  modified:
    - app/Services/FluxoEntrada/EtapaTransicaoService.php
    - tests/Unit/Phase137/EtapaTransicaoServiceTest.php
    - app/Console/Commands/EtapaBackfill.php
    - tests/Feature/Phase137/EtapaBackfillTest.php
    - .planning/phases/137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/137-01-PLAN.md

key-decisions:
  - "podeTransicionar() é avaliado sobre a linha relida e travada (`Company::whereKey($id)->lockForUpdate()->first()`), nunca sobre o `$company` recebido por parâmetro — a avaliação anterior (fora da transação) foi removida por completo, não duplicada"
  - "lockForUpdate() documentado como no-op no SQLite dos testes (mesma ressalva de D-122-08); a suíte prova re-leitura de estado divergente memória-vs-banco, não serialização de concorrência real — só o MariaDB de produção prova isso"
  - "Amostra do dry-run do backfill passou a ser uma consulta SEPARADA (whereIn nos 20 primeiros ids), desacoplando apresentação de decisão"
  - "137-01-PLAN.md ganhou requirements: [] + comentário YAML de uma linha, sem tocar em must_haves/tasks/corpo — registro histórico do que foi executado permanece intacto"

patterns-established:
  - "Releitura com lockForUpdate() dentro da MESMA closure de DB::transaction() que escreve — nunca reaproveitar objeto recebido por parâmetro para decisão em serviço de escrita única"

requirements-completed: [ETAPA-02, ETAPA-03, ETAPA-06]

# Metrics
duration: 30min
completed: 2026-09-02
---

# Phase 137 Plan 10: Gap closure — TOCTOU no lock, homônimo no backfill, frontmatter honesto Summary

**`EtapaTransicaoService::transicionar()` passou a decidir sobre a linha travada por `lockForUpdate()` dentro da transação (não mais sobre o `Company` em memória do chamador); `etapa:backfill` chaveia o balde 1 por `id` em vez de `name` (não único); `137-01-PLAN.md` deixou de reivindicar `ETAPA-01`/`ETAPA-02` que não entregou.**

## Performance

- **Duration:** ~30 min
- **Completed:** 2026-09-02
- **Tasks:** 3/3
- **Files modified:** 5

## Accomplishments

- **G4/WR-01 fechado:** `transicionar()` agora relê `Company::whereKey($company->id)->lockForUpdate()->first()` DENTRO de `DB::transaction()` e reavalia `podeTransicionar()` sobre essa instância travada — a avaliação antiga, feita sobre o objeto em memória antes da transação abrir, foi removida. Empresa removida entre a chamada e o lock devolve `status: 'erro'` sem exceção vazar. O objeto `Company` que o chamador passou é sincronizado (`setAttribute` + `syncOriginalAttribute`) após transição bem-sucedida — nenhum objeto obsoleto sobra na mão de quem chamou.
- **G5/WR-02 fechado:** o balde 1 de `etapa:backfill` passou de `pluck('id', 'name')` para `pluck('id')` — `companies.name` não tem `unique()` no schema, e a chave por nome colapsava empresas homônimas, descartando a primeira em silêncio. A amostra impressa no dry-run agora vem de uma consulta separada (`whereIn` nos 20 primeiros ids), desacoplada da decisão de quem é carimbado.
- **G6 fechado:** `137-01-PLAN.md` teve `requirements: [ETAPA-01, ETAPA-02]` trocado por `requirements: []` com um comentário de uma linha explicando o motivo — o plano só destravou o ambiente de teste, sem tocar `app/`/`database/`. `ETAPA-01` continua coberto por `137-02`, `ETAPA-02` por `137-05`/`137-10` — nenhum dos 6 IDs (`ETAPA-01`..`06`) ficou órfão (conferido por grep em todos os 11 planos).
- Prova dirigida para os dois fixes de código: revertida temporariamente cada correção, os testes novos (2 de divergência memória-vs-banco no serviço; 1 de nome duplicado no backfill) FALHARAM exatamente como previsto, e a reversão foi desfeita restaurando o arquivo corrigido (via cópia de backup, sem `git checkout`/`stash`).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Serializar transicionar() — decidir sobre a linha travada (G4)** - `0a120abf` (fix)
2. **Task 2: Chavear o balde 1 do backfill por id (G5)** - `f8558fb5` (fix)
3. **Task 3: Corrigir o frontmatter de 137-01-PLAN.md (G6)** - `9eaa4fac` (docs)

## Files Created/Modified

- `app/Services/FluxoEntrada/EtapaTransicaoService.php` - `transicionar()` releva e trava a `Company` dentro de `DB::transaction()`; decisão passa a ser sobre a instância travada
- `tests/Unit/Phase137/EtapaTransicaoServiceTest.php` - 4 testes novos: divergência memória-vs-banco (recusa e `etapa_anterior` correta), sincronização do objeto do chamador, erro sem exceção em empresa removida
- `app/Console/Commands/EtapaBackfill.php` - balde 1 chaveado por `id`; amostra do dry-run buscada à parte
- `tests/Feature/Phase137/EtapaBackfillTest.php` - 1 teste novo: duas empresas com `name` idêntico, ambas recebem `em_operacao`
- `.planning/phases/137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/137-01-PLAN.md` - `requirements: []` + comentário de justificativa

## Decisions Made

- A avaliação de `podeTransicionar()` que existia ANTES de `DB::transaction()` abrir foi **removida por completo**, não duplicada — só existe uma avaliação agora, dentro da closure, sobre a linha travada. Isso simplifica o método (menos um caminho de decisão para manter sincronizado) às custas de sempre abrir uma transação mesmo quando a recusa seria óbvia sem lock — troca aceitável porque o volume de chamadas de `transicionar()` é baixo (transição de empresa, não operação em massa).
- Prova dirigida (revert temporário) foi feita por cópia de arquivo via `cp`/backup em `/c/tmp`, nunca por `git checkout -- <arquivo>` ou `git stash` — evita qualquer risco de descartar trabalho não commitado ou colidir com stash de outra sessão (regra do projeto, `destructive_git_prohibition`).
- STATE.md corrigido à mão (ver seção seguinte) em vez de `state.advance-plan`, que corrompe `progress.percent`/`milestone_name` conforme já documentado por planos anteriores desta fase.

## Deviations from Plan

None - plan executado exatamente como escrito. A única diferença de forma foi a prova dirigida do G4/G5 ser feita por cópia de arquivo (`cp` para/de `/c/tmp`) em vez de `git stash`/`git checkout` pontual, para respeitar a proibição do projeto contra `git stash` (compartilhado entre worktrees) e evitar descartar o próprio fix ainda não commitado. Efeito idêntico ao pedido pelo plano: revert temporário → teste falha → revert desfeito → `git status --short` limpo.

## Issues Encountered

Nenhum. O comentário original planejado para `EtapaBackfill.php` citava literalmente `pluck('id', 'name')` — isso quebraria o acceptance criteria `grep -c "pluck('id', 'name')" ... = 0`, que não distingue código de comentário. Reescrito para descrever a chamada antiga sem reproduzir a assinatura exata (`grep -c` confirmado em 0 após o ajuste).

## User Setup Required

None - nenhuma configuração de serviço externo.

## Next Phase Readiness

Os 3 achados do `137-REVIEW.md` (G4/WR-01, G5/WR-02) e o defeito de frontmatter (G6) estão fechados. Falta apenas **137-11 (G3)** entre os planos de gap closure planejados para esta fase. `EtapaTransicaoService` segue sem chamador de produção, de propósito — a Fase 138 (webhook Clicksign → etapa 1) é quem vai exercitar o lock sob concorrência real pela primeira vez; o teste desta fase prova a re-leitura, não a serialização (SQLite não permite provar isso).

---
*Phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0*
*Completed: 2026-09-02*

## Self-Check: PASSED

- FOUND: commit `0a120abf` (Task 1)
- FOUND: commit `f8558fb5` (Task 2)
- FOUND: commit `9eaa4fac` (Task 3)
- FOUND: `app/Services/FluxoEntrada/EtapaTransicaoService.php`
- FOUND: `app/Console/Commands/EtapaBackfill.php`
- FOUND: `tests/Unit/Phase137/EtapaTransicaoServiceTest.php`
- FOUND: `tests/Feature/Phase137/EtapaBackfillTest.php`
- FOUND: `.planning/phases/137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/137-01-PLAN.md`
