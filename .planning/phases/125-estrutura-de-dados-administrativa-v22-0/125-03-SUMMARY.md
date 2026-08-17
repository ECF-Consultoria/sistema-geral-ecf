---
phase: 125-estrutura-de-dados-administrativa-v22-0
plan: 03
subsystem: database
tags: [laravel, migration, mariadb, phpunit, guard-test, vps]
status: completo

# Dependency graph
requires:
  - phase: 125-01
    provides: Tabela contrato_assinaturas + migration a ser provada
  - phase: 125-02
    provides: Tabela contrato_assinatura_signatarios + migration a ser provada
provides:
  - Guarda estática (tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php) contra as 3 cicatrizes de schema do projeto
  - Prova empírica, no MariaDB de produção, de que as duas migrations da fase rodam sem erro 1830 nem 1059 e nascem com todas as chaves/índices declarados
affects: [126-integracao-clicksign, 129-webhook-clicksign, 130-liberacao-manual, 131-tela-administrativa-contratos]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Guarda estática de convenção de migration: lê o TEXTO do arquivo (sem RefreshDatabase), remove comentários antes de casar padrão para não se auto-invalidar quando o próprio comentário documenta a armadilha que o teste proíbe"
    - "Prova cirúrgica em produção sem deploy: copiar só os 2 arquivos de migration via pscp e rodar migrate --force --path= individualmente, sem git push nem deploy.sh, quando o MariaDB local não está disponível e o usuário autoriza explicitamente"

key-files:
  created:
    - tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php
  modified: []

key-decisions:
  - "Task 2 rodou no VPS de produção, não no MariaDB local — mysqld local confirmado fora do ar (Task 2 original bloqueou corretamente em vez de escalar por conta própria). O checkpoint da Task 3 apresentou a opção ao usuário, que autorizou explicitamente o caminho VPS (T-125-24 respeitado: decisão humana, não iniciativa do executor)."
  - "A unicidade da D-01 NÃO foi testada por inserção real em produção nesta rodada — o orquestrador optou por não gravar linhas de teste no banco de produção, diferente do que a Task 2 original previa para o MariaDB local. A evidência da unicidade em produção é indireta (índice único confirmado existir + teste `indice_unico_barra_duplicata` verde no SQLite), não uma prova direta de INSERT duplicado recusado no engine real."

requirements-completed: [DADOS-01, DADOS-02]

# Metrics
duration: ~25min (Task 1 ~15min nesta sessão anterior + finalização desta sessão)
completed: 2026-08-10
---

# Phase 125 Plan 03: Prova em MariaDB + guarda estática — Summary

**Guarda estática contra as 3 cicatrizes de schema do MariaDB (enum, FK `nullOnDelete` sem `nullable`, índice > 64 chars) mais prova empírica, no MariaDB de produção real, de que as duas migrations da fase (`contrato_assinaturas` e `contrato_assinatura_signatarios`) rodam sem erro 1830 nem 1059 e nascem com todos os 7 índices/chaves declarados.**

## Performance

- **Duration:** ~25 min no total (Task 1 executada numa sessão anterior; Tasks 2/3 finalizadas nesta sessão de continuação)
- **Tasks:** 3/3 concluídas
- **Files modified:** 1 (criado, Task 1). Task 2 não alterou arquivos (execução + coleta de evidência). Task 3 é decisão humana.

## Accomplishments

- `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php` criado com os 6 testes especificados: proibição de `->enum(`, exigência de `->nullable()` em toda FK `nullOnDelete`, proibição de índice/chave anônimo, limite de 64 caracteres nos nomes, proibição de coluna de prazo (`expira_em`/`deadline`, guarda executável da D-03), e existência dos dois arquivos de migration.
- 30/30 testes verdes em `tests/Feature/Phase125/` (24 pré-existentes + 6 da guarda estática), reconfirmado nesta sessão antes de fechar o plano.
- **Conferência de não auto-invalidação feita e confirmada**: a remoção de comentários no helper `migrationSemComentarios()` foi desligada temporariamente e rodado só o teste 1 — ele **falhou de verdade**, apontando que a migration `2026_08_10_100000_create_contrato_assinaturas_table.php` contém a string `$table->enum(` dentro de um comentário explicativo. Prova que, sem a remoção de comentários, a guarda se acusaria a si mesma. Implementação real restaurada e os 30 testes reconfirmados verdes.
- **Prova em MariaDB real obtida — no VPS de produção**, por decisão explícita do usuário no gate da Task 3 (ver seção "Checkpoint resolvido" abaixo).

## Task Commits

1. **Task 1: Guarda automatizada das três cicatrizes de schema** — `4bebb2b8` (test)
2. **Task 2: Prova em MariaDB** — sem commit de código; execução contra produção realizada pelo orquestrador (ver evidência abaixo), não reproduzível por este executor (classificador de permissões bloqueia escrita em produção via `plink`/`pscp`)
3. **Task 3: Checkpoint humano** — resolvido; decisão registrada nesta seção do SUMMARY

**Plan metadata:** (este commit)

## Files Created/Modified

- `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php` — guarda estática das 3 cicatrizes, análise de texto das duas migrations (sem `RefreshDatabase`)

## Decisions Made

- Ver `key-decisions` no frontmatter. Resumo: caminho VPS autorizado pelo usuário no gate; nenhuma decisão de schema/negócio nova nesta rodada.

## Checkpoint resolvido — Task 3

**Contexto:** o MariaDB local estava confirmado fora do ar (duplamente checado: `tasklist` e `artisan db:show`), então a Task 2 original parou no passo 1 conforme instruído — a decisão de usar o VPS não é do executor.

**Opções apresentadas ao usuário no gate:**
1. Adiar o gate até o MariaDB local voltar.
2. Provar a mesma conferência no VPS de produção, com autorização explícita.

**O usuário escolheu a opção 2**, com autorização explícita, em 2026-08-10, para o MariaDB de produção (`177.7.53.164`, banco `ecf_admin`).

**Caminho usado (cirúrgico, sem deploy):** apenas os 2 arquivos de migration foram copiados via `pscp`; cada migration rodou individualmente com `migrate --force --path=`. **Nenhum** `git push`, **nenhum** `deploy.sh`. Havia 0 migrations pendentes antes da operação.

### Evidência literal coletada

**1. `migrate:status` depois:**
```
2026_08_10_100000_create_contrato_assinaturas_table .............. [110] Ran
2026_08_10_100001_create_contrato_assinatura_signatarios_table ... [111] Ran
```

**2. `php artisan db:table contrato_assinaturas` (produção):**
```
Engine: InnoDB | Collation: utf8mb4_unicode_ci | Columns: 13
  status varchar, utf8mb4_unicode_ci .................... rascunho varchar(40)
  company_id_em_andamento bigint, nullable ................... bigint unsigned
  servicos_snapshot json, nullable ...................................... json
  enviado_em / assinado_em / liberado_em timestamp, nullable
  erro_mensagem text, nullable
  clicksign_envelope_id / clicksign_document_id varchar(64), nullable
Index:
  ca_clicksign_envelope_uniq clicksign_envelope_id ............. btree, unique
  ca_company_andamento_uniq company_id_em_andamento ............ btree, unique
  ca_company_status_idx company_id, status ................... btree, compound
  primary id .................................................. btree, primary
Foreign Key:
  contrato_assinaturas_company_id_foreign company_id references id on companies — no action / cascade
```

**3. `php artisan db:table contrato_assinatura_signatarios` (produção):**
```
Engine: InnoDB | Columns: 15
  contrato_assinatura_id bigint unsigned
  user_id bigint, nullable ................................... bigint unsigned
  papel varchar(20) | situacao varchar(20) default 'pendente'
  nome varchar(255) | email varchar(255) | cpf varchar(14), nullable
  assinado_em timestamp, nullable | ip_address varchar(45), nullable
  auths json, nullable | evidencia_signer json, nullable
  clicksign_signer_key varchar(64), nullable
Index:
  cas_clicksign_signer_idx clicksign_signer_key ........................ btree
  cas_contrato_situacao_idx contrato_assinatura_id, situacao . btree, compound
  cas_user_fk user_id .................................................. btree
  primary id .................................................. btree, primary
Foreign Key:
  cas_contrato_fk contrato_assinatura_id references id on contrato_assinaturas — no action / cascade
  cas_user_fk user_id references id on users — no action / set null
```

### O que a evidência prova

- **Erro 1059 evitado** — os 7 índices/FKs existem com os nomes curtos declarados à mão. O nome
  autogerado `contrato_assinatura_signatarios_contrato_assinatura_id_foreign` (62 caracteres)
  nunca foi criado; é `cas_contrato_fk` (15 caracteres). A migration não ficou `Pending`, e as
  tabelas não nasceram sem índice — que é exatamente como a 1059 falha, em silêncio.
- **Erro 1830 evitado** — `cas_user_fk ... set null` foi aceito, com `user_id` nullable. Sem o
  `->nullable()` o `migrate` teria abortado.
- **Nenhum enum** — `status varchar(40)`, `papel varchar(20)`, `situacao varchar(20)`.
- **Campo do Gate #9 no lugar** — `evidencia_signer json`, `auths json`, `ip_address varchar(45)`.

### Ressalva honesta — unicidade da D-01

A unicidade da D-01 **não** foi testada por inserção real em produção — o orquestrador optou
por não gravar linhas de teste no banco de produção (diferente do passo 6 original da Task 2,
pensado para o MariaDB local). O que sustenta a decisão, mesmo sem o INSERT direto:

(a) o índice único `ca_company_andamento_uniq` comprovadamente existe no InnoDB de produção,
via `SHOW`-equivalente do `db:table`;
(b) o teste `indice_unico_barra_duplicata` já está verde na suíte SQLite;
(c) múltiplos `NULL` em índice único é comportamento padrão e documentado do InnoDB (não
específico deste schema).

É evidência forte, mas **indireta** no engine real — não o mesmo grau de prova que um INSERT
duplicado recusado ao vivo teria dado. Registrado aqui para quem ler este SUMMARY depois não
assumir mais do que foi de fato provado.

### Dívida registrada

As duas tabelas passam a existir em **produção** antes da fase estar formalmente verificada
por `/gsd:verify-work`. Estão vazias e nenhum código as lê ainda — o primeiro consumidor nasce
na Fase 126/131 — então o risco operacional é baixo. Mas se a verificação apontar problema de
schema, **editar a migration não re-roda em produção** — exigiria `migrate:rollback --path=`
antes de qualquer correção.

## Deviations from Plan

Nenhum desvio de Rule 1/2/3 na Task 1 — implementada exatamente conforme especificado.

Na Task 2, o **caminho de execução divergiu do plano original** (MariaDB local → VPS de
produção), mas essa divergência foi tratada exatamente como o `<threat_model>` da fase previa
(T-125-24): a Task 2 parou sozinha ao encontrar o MariaDB local fora do ar, e o uso do VPS só
aconteceu depois de decisão explícita do usuário no checkpoint da Task 3. Não é uma dedução
Rule 1-3 — é o comportamento correto do próprio plano diante do gate humano.

## Issues Encountered

MariaDB local confirmado fora do ar duas vezes de forma independente (`tasklist` sem processo
`mysqld`; `artisan db:show --json` retornando `SQLSTATE[HY000] [2002]`). Resolvido pela decisão
humana de usar o VPS, não por tentativa de subir o serviço local (que teria sido iniciativa fora
de escopo, dado o incidente de corrupção do MariaDB local registrado em 2026-06-25).

## User Setup Required

None — a prova em produção já foi executada pelo orquestrador com autorização do usuário. Nenhuma
ação adicional pendente.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. T-125-24 (Elevation of Privilege —
rodar no VPS sem autorização) foi mitigado como desenhado: a Task 2 parou e escalou para o
checkpoint humano em vez de decidir sozinha usar o VPS.

## Next Phase Readiness

- Guarda estática (Task 1) protege contra regressão nas duas migrations desta fase.
- As duas tabelas existem em produção real, com todos os índices/chaves confirmados — DADOS-01 e
  DADOS-02 fecham nesta fase.
- Fase 125 está pronta para as fases seguintes que consomem estas tabelas (126, 129, 130, 131).
  A ressalva de unicidade indireta e a dívida de rollback ficam registradas acima para quem
  planejar essas fases.

---
*Phase: 125-estrutura-de-dados-administrativa-v22-0*
*Completed: 2026-08-10*

## Self-Check: PASSED

Arquivo criado na Task 1 encontrado no disco (`tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php`);
commit `4bebb2b8` confirmado em `git log`. 30/30 testes de `tests/Feature/Phase125/` reconfirmados
verdes nesta sessão de continuação. Evidência do MariaDB de produção transcrita literalmente do
despacho do orquestrador, sem reexecução (bloqueada pelo classificador de permissões, conforme
esperado e documentado nas constraints).
