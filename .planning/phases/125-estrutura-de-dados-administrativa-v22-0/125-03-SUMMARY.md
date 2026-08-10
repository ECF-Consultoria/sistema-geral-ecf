---
phase: 125-estrutura-de-dados-administrativa-v22-0
plan: 03
subsystem: database
tags: [laravel, migration, mariadb, phpunit, guard-test]
status: parcial — bloqueado em checkpoint humano (Task 3)

# Dependency graph
requires:
  - phase: 125-01
    provides: Tabela contrato_assinaturas + migration a ser provada
  - phase: 125-02
    provides: Tabela contrato_assinatura_signatarios + migration a ser provada
provides:
  - Guarda estática (tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php) contra as 3 cicatrizes de schema do projeto
affects: [126-integracao-clicksign, 129-webhook-clicksign, 130-liberacao-manual, 131-tela-administrativa-contratos]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Guarda estática de convenção de migration: lê o TEXTO do arquivo (sem RefreshDatabase), remove comentários antes de casar padrão para não se auto-invalidar quando o próprio comentário documenta a armadilha que o teste proíbe"

key-files:
  created:
    - tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php
  modified: []

key-decisions:
  - "Task 2 (rodar as migrations no MariaDB local) NÃO executada — mysqld local confirmado fora do ar duas vezes (tasklist e artisan db:show). Não é iniciativa do executor decidir usar o VPS (T-125-24)."

requirements-completed: []

# Metrics
duration: ~15min (só Task 1; Task 2/3 bloqueadas)
completed: 2026-08-10
---

# Phase 125 Plan 03: Prova em MariaDB + guarda estática — Summary (PARCIAL)

**Guarda estática das 3 cicatrizes de schema (enum, FK nullOnDelete sem nullable, índice > 64 chars) criada e comprovadamente não auto-invalidante; a prova em MariaDB real (Task 2) e o gate humano (Task 3) ficam pendentes porque o MariaDB local não está no ar.**

## Performance

- **Duration:** ~15 min (Task 1 apenas)
- **Tasks:** 1/3 concluída automaticamente; 1/3 bloqueada por ambiente; 1/3 é o checkpoint humano em aberto
- **Files modified:** 1 (criado)

## Accomplishments

- `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php` criado com os 6 testes especificados: proibição de `->enum(`, exigência de `->nullable()` em toda FK `nullOnDelete`, proibição de índice/chave anônimo, limite de 64 caracteres nos nomes, proibição de coluna de prazo (`expira_em`/`deadline`, guarda executável da D-03), e existência dos dois arquivos de migration.
- 30/30 testes verdes em `tests/Feature/Phase125/` (24 pré-existentes + 6 novos).
- **Conferência de não auto-invalidação feita e confirmada**: desliguei temporariamente a remoção de comentários no helper `migrationSemComentarios()` e rodei só o teste 1 — ele **falhou de verdade**, apontando que a migration `2026_08_10_100000_create_contrato_assinaturas_table.php` contém a string `$table->enum(` dentro de um comentário explicativo (linha do bloco que documenta as 3 armadilhas). Isso prova que, sem a remoção de comentários, a guarda se acusaria a si mesma. Restaurei a implementação real em seguida e reconfirmei os 30 testes verdes.

## Task Commits

1. **Task 1: Guarda automatizada das três cicatrizes de schema** — `4bebb2b8` (test)

Tasks 2 e 3 **não** foram executadas nesta rodada (ver "Issues Encountered" abaixo).

## Files Created/Modified

- `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php` — guarda estática das 3 cicatrizes, análise de texto das duas migrations (sem `RefreshDatabase`)

## Decisions Made

Nenhuma decisão nova de schema/negócio. Único ponto de discrição do executor: forma exata do parsing por regex (remoção de comentários bloco/linha, extração de nome de índice/FK neutralizando arrays antes do split por vírgula) — detalhe de implementação da guarda, sem impacto em nenhuma decisão travada (D-01 a D-10).

## Deviations from Plan

Nenhum desvio de Rule 1/2/3 na Task 1 — implementada exatamente conforme especificado no plano, incluindo a conferência manual de não auto-invalidação pedida no acceptance criteria.

## Issues Encountered

**Task 2 bloqueada por ambiente — MariaDB local fora do ar.**

Antes de despachar este executor, o orquestrador já havia confirmado via `tasklist | grep mysqld` que o serviço não estava rodando. Reconfirmei de forma independente, como o passo 1 da Task 2 exige:

1. `tasklist | grep -i mysqld` — nenhuma saída, nenhum processo `mysqld` no ar.
2. `php artisan db:show --json` — `PDOException: SQLSTATE[HY000] [2002] Nenhuma conexão pôde ser feita porque a máquina de destino as recusou ativamente` (host `127.0.0.1:3306`, banco `ecf_admin`).

Conforme o passo 1 da Task 2 do plano ("Se não estiver no ar ou não conectar, **parar aqui**... a decisão de usar o VPS é do checkpoint, não do executor"), parei e não executei nenhum dos passos 2–7 (nenhum `migrate`, nenhuma leitura de `SHOW INDEX`/`SHOW CREATE TABLE`, nenhum teste de unicidade da D-01 no banco real).

**Não tentei subir o serviço do XAMPP.** O `<estado_do_ambiente_ja_verificado_pelo_orquestrador>` deste despacho proíbe explicitamente essa iniciativa — e a memória do projeto registra que o MariaDB local já apareceu corrompido uma vez (incidente 2026-06-25, quick task `260625-mrd`), então subir o serviço sem o dono presente não é uma decisão do executor.

**Task 3 (checkpoint humano) não pode ser resolvida por mim.** Não há evidência de MariaDB real para apresentar. O checkpoint abaixo apresenta as três opções ao usuário, conforme `<how-to-verify>` do plano original.

## User Setup Required

Ver seção CHECKPOINT abaixo (resposta narrativa desta execução) — decisão do usuário sobre como fechar a prova em MariaDB.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. T-125-24 (Elevation of Privilege — rodar no VPS sem autorização) é exatamente o que este bloqueio evita: a Task 2 parou no passo 1 em vez de escalar para o VPS por conta própria.

## Next Phase Readiness

- A guarda estática (Task 1) já protege contra regressão nas duas migrations desta fase, independente do resultado da Task 2/3.
- A fase **não pode ser dada por concluída** até a Task 2 rodar contra um MariaDB real (local ou VPS, por decisão explícita do usuário) e a Task 3 (gate humano) ser respondida.
- Se o usuário rodar `php artisan migrate --force` manualmente contra o MariaDB local depois de subir o XAMPP, uma nova sessão pode retomar a partir do passo 2 da Task 2 deste plano.

---
*Phase: 125-estrutura-de-dados-administrativa-v22-0*
*Status: parcial — aguardando decisão humana (checkpoint Task 3)*

## Self-Check: PASSED

Arquivo criado encontrado no disco (`tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php`); commit `4bebb2b8` confirmado em `git log`.
