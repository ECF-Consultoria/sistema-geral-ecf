---
phase: 150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
plan: 09
subsystem: database
tags: [migration, mariadb, foreign-key, audit-trail, phpunit, gap-closure]

# Dependency graph
requires:
  - phase: 150-03
    provides: "tabela company_etapa_transicoes (histórico append-only) e EtapaTransicaoService"
  - phase: 150-04
    provides: "precedente companies.pendencia_por com nullOnDelete(), mesma fase, mesmo conceito de ator"
provides:
  - "FK company_etapa_transicoes.user_id corrigida de cascadeOnDelete() para nullable()+nullOnDelete()"
  - "Prova comportamental (EtapaHistoricoAtorTest) de que forceDelete() de usuário preserva histórico de todas as empresas que ele movimentou"
  - "Docblock de CompanyEtapaTransicao::user() documentando que a relação pode devolver null"
affects: [143-historico-rastreabilidade-sla]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "FK de ator (quem agiu) usa nullOnDelete(), nunca cascadeOnDelete() — a linha é dona do fato, não do ator"

key-files:
  created:
    - tests/Feature/Phase150/EtapaHistoricoAtorTest.php
  modified:
    - database/migrations/2026_09_01_120000_create_company_etapa_transicoes_table.php
    - app/Models/CompanyEtapaTransicao.php

key-decisions:
  - "Corrigir a migration ORIGINAL in-place, não migration ALTER corretiva — branch nunca pusheada, tabela com 0 linhas em produção, cada migration sozinha no seu batch"
  - "nullOnDelete() em vez de restrictOnDelete() — preserva a linha inteira de histórico e alinha com o precedente companies.pendencia_por da mesma fase"
  - "Ciclo rollback --step=2 + migrate protegido por asserção SQL do nome exato das 2 migrations nos 2 maiores batches, antes E depois — banco ecf_admin é compartilhado por 20+ worktrees desta máquina"

requirements-completed: [ETAPA-03, ETAPA-06]

# Metrics
duration: ~15min
completed: 2026-09-02
---

# Phase 150 Plan 09: Histórico de etapa sobrevive à exclusão permanente do ator Summary

**FK `company_etapa_transicoes.user_id` corrigida de `cascadeOnDelete()` para `nullable()` + `nullOnDelete()` no MariaDB real, com prova comportamental de que `forceDelete()` de um usuário não apaga mais o histórico de transição das empresas que ele movimentou (G2/CR-02 do `150-REVIEW.md`).**

## Performance

- **Duration:** ~15 min
- **Completed:** 2026-09-02T12:47:15Z
- **Tasks:** 2/2 completos
- **Files modified:** 2 modificados, 1 criado

## Accomplishments

- Fechado o gap G2 (CRITICAL) do `150-REVIEW.md`: a FK irmã de `companies.pendencia_por` (mesma fase, mesmo conceito de ator) já usava `nullOnDelete()`; `company_etapa_transicoes.user_id` divergia com `cascadeOnDelete()`, o que fazia `UserController::forceDestroy()` (hard delete real) apagar em cascata o histórico de TODAS as empresas que o usuário removido já tinha movimentado — inclusive empresas sem qualquer relação com a exclusão.
- Corrigida a migration ORIGINAL (`2026_09_01_120000_...`), não uma ALTER corretiva — decisão de desenho já registrada no `<measured_facts>` do plano (branch nunca pusheada, tabela com 0 linhas, cada migration sozinha no seu batch).
- Ciclo `migrate:rollback --step=2` + `migrate` executado localmente no MariaDB compartilhado `ecf_admin`, protegido por asserção SQL do nome exato das 2 migrations nos 2 maiores batches — antes e depois — para não colidir com outra sessão usando o mesmo banco.
- Schema real reconferido por `SHOW CREATE TABLE` (nunca por stdout): `user_id` agora `DEFAULT NULL` com `CONSTRAINT ... ON DELETE SET NULL`.
- Backfill do 150-05 confirmado intacto pelo ciclo inteiro: `total=180`, `em_operacao=1`, `NULL=179`, idêntico a `150-BACKFILL-CONTAGENS.md`.
- Prova comportamental nova (`EtapaHistoricoAtorTest`, 4 testes) cobrindo os 4 comportamentos do `must_haves` do plano, com prova por regressão dirigida: revertida a migration para `cascadeOnDelete()` sem `nullable()`, 2 dos 4 testes falharam exatamente como esperado; reversão desfeita e conferida byte-idêntica ao commit.

## Task Commits

1. **Task 1: Corrigir a FK do ator na migration original e reconferir o schema real do MariaDB** - `c08d836d` (fix)
2. **Task 2: Prova comportamental — hard delete do ator preserva o histórico** - `a0001044` (test)

_Nenhuma task usou TDD formal RED/GREEN separado — a Task 1 (fix) já entregava o schema corrigido antes da Task 2 escrever o teste; a prova de regressão (reversão temporária + teste falhando) cumpre o mesmo papel de "visto falhando contra o comportamento antigo", documentada abaixo._

## Files Created/Modified

- `database/migrations/2026_09_01_120000_create_company_etapa_transicoes_table.php` — `user_id` passa a `nullable()->constrained('users')->nullOnDelete()` (era `cascadeOnDelete()`); docblock estendido com o racional da FK do ator (T-150-26/T-150-27)
- `app/Models/CompanyEtapaTransicao.php` — docblock de `user()` documenta que a relação pode devolver `null` quando o ator foi removido permanentemente
- `tests/Feature/Phase150/EtapaHistoricoAtorTest.php` — 4 testes: `forceDelete` preserva histórico de 1 empresa zerando só `user_id`; preserva histórico de VÁRIAS empresas não relacionadas; `delete()` soft não altera nada; `forceDelete` de um ator não toca histórico de outro ator

## Decisões Tomadas

- **Corrigir a migration original in-place**, não criar uma ALTER corretiva — a branch `feat/fluxo-entrada-empresas` nunca foi pusheada (`git branch -r --contains HEAD` vazio), a tabela tinha 0 linhas em produção local, e cada migration envolvida estava sozinha no seu batch (104 e 105), tornando o ciclo rollback/migrate cirúrgico. Uma ALTER deixaria cruft permanente sem contrapartida.
- **`nullOnDelete()`, não `restrictOnDelete()`** — preserva a linha inteira de histórico (`etapa_anterior`, `etapa_nova`, `motivo`, `retrocesso`, `created_at`) e só perde a referência ao ator, exatamente o que a Fase 156 precisa. `restrictOnDelete()` bloquearia `forceDestroy()` de qualquer usuário que já tenha movimentado uma etapa — falha ruidosa em vez de perda silenciosa, mas transformaria uma ação administrativa legítima num impasse sem saída.
- **Asserção por SQL do nome exato das migrations nos 2 maiores batches, antes E depois do ciclo** — o banco `ecf_admin` é compartilhado por 20+ worktrees desta máquina; `migrate:status` sem pendente é necessário mas não suficiente contra uma migration aplicada por outra sessão entre a checagem e o `--step=2`.

## Evidência — reconsulta ao MariaDB real (nunca por stdout)

### ANTES do ciclo rollback/migrate

```
SHOW CREATE TABLE company_etapa_transicoes\G
*************************** 1. row ***************************
       Table: company_etapa_transicoes
Create Table: CREATE TABLE `company_etapa_transicoes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `etapa_anterior` varchar(40) DEFAULT NULL,
  `etapa_nova` varchar(40) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `motivo` text DEFAULT NULL,
  `retrocesso` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_etapa_transicoes_user_id_foreign` (`user_id`),
  KEY `company_etapa_transicoes_company_id_created_at_index` (`company_id`,`created_at`),
  CONSTRAINT `company_etapa_transicoes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_etapa_transicoes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

```
SELECT COUNT(*) total, SUM(etapa='em_operacao') em_op, SUM(etapa IS NULL) nulo FROM companies;
total  em_op  nulo
180    1      179

SELECT COUNT(*) FROM company_etapa_transicoes;
0

SELECT SUM(pendencia_aberta=1), SUM(pendencia_motivo IS NOT NULL), SUM(pendencia_por IS NOT NULL) FROM companies;
0  0  0
```

### Asserção pré-rollback (banco compartilhado — proteção T-150-30)

```
SELECT DISTINCT batch FROM migrations ORDER BY batch DESC LIMIT 2;
105
104

SELECT batch, migration FROM migrations WHERE batch IN (105,104) ORDER BY batch DESC, migration;
105  2026_09_01_130000_add_pendencia_to_companies_table
104  2026_09_01_120000_create_company_etapa_transicoes_table
```

Um por batch, nomes batendo exatamente com o esperado — seguro prosseguir. Repetida imediatamente antes do `--step=2` (sem mudança desde a primeira checagem).

### Ciclo executado

```
php artisan migrate:rollback --step=2 --force
  2026_09_01_130000_add_pendencia_to_companies_table ... DONE (43.17ms)
  2026_09_01_120000_create_company_etapa_transicoes_table ... DONE (6.54ms)

php artisan migrate --force
  2026_09_01_120000_create_company_etapa_transicoes_table ... DONE (102.77ms)
  2026_09_01_130000_add_pendencia_to_companies_table ... DONE (91.63ms)
```

### DEPOIS do ciclo — asserção pós-migrate

```
SELECT batch, migration FROM migrations WHERE batch IN (104,103) ORDER BY batch DESC, migration;
104  2026_09_01_120000_create_company_etapa_transicoes_table
104  2026_09_01_130000_add_pendencia_to_companies_table
103  2026_09_01_110000_add_etapa_to_companies_table
```

As duas migrations desta fase reaplicadas juntas no novo batch 104 (o rollback esvaziou 104/105, o `migrate` seguinte as reaplica no próximo batch livre). Batch 103 (`..._110000_add_etapa...`, contém o backfill) **intacto**, como previsto.

```
SHOW CREATE TABLE company_etapa_transicoes\G
*************************** 1. row ***************************
       Table: company_etapa_transicoes
Create Table: CREATE TABLE `company_etapa_transicoes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `etapa_anterior` varchar(40) DEFAULT NULL,
  `etapa_nova` varchar(40) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `motivo` text DEFAULT NULL,
  `retrocesso` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_etapa_transicoes_user_id_foreign` (`user_id`),
  KEY `company_etapa_transicoes_company_id_created_at_index` (`company_id`,`created_at`),
  CONSTRAINT `company_etapa_transicoes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_etapa_transicoes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

`user_id` virou `DEFAULT NULL` e a constraint virou `ON DELETE SET NULL`. `company_id` **preservado** em `ON DELETE CASCADE` (correto — não foi tocado).

```
SELECT COUNT(*) total, SUM(etapa='em_operacao') em_op, SUM(etapa IS NULL) nulo FROM companies;
total  em_op  nulo
180    1      179

SELECT COUNT(*) FROM company_etapa_transicoes;
0

SELECT SUM(pendencia_aberta=1) pend_aberta, SUM(pendencia_motivo IS NOT NULL) pend_motivo, SUM(pendencia_por IS NOT NULL) pend_por FROM companies;
pend_aberta  pend_motivo  pend_por
0            0            0
```

Contagens idênticas ao ANTES e a `150-BACKFILL-CONTAGENS.md`. As 4 colunas de pendência voltaram a existir (recriadas pelo `migrate` do batch 105 original, agora dentro do novo batch 104) e seguem vazias.

## Evidência — prova por regressão dirigida (Task 2)

Migration temporariamente revertida para `->constrained('users')->cascadeOnDelete()` (sem `nullable()`), tests rodados de novo:

```
phpunit tests/Feature/Phase150/EtapaHistoricoAtorTest.php

EF..                                                                4 / 4 (100%)

1) test_force_delete_do_ator_preserva_historico_da_empresa_e_zera_so_o_autor
Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\CompanyEtapaTransicao].

1) test_force_delete_do_ator_preserva_historico_de_varias_empresas_nao_relacionadas
o histórico de transição da empresa X foi apagado ao remover permanentemente o usuário ator,
mesmo empresa X não sendo excluída — CR-02
Failed asserting that null is not null.

Tests: 4, Assertions: 9, Errors: 1, Failures: 1.
```

Os 2 testes que dependem da FK do ator falharam exatamente como esperado contra o schema antigo (a linha some do CASCADE); os outros 2 (soft delete, e "ator diferente não é tocado") passaram porque não dependem dessa correção especificamente. Migration revertida de volta ao estado correto e conferida byte-idêntica ao arquivo do commit `c08d836d` (`diff` sem saída); `git status --short` mostrou só o arquivo de teste ainda não commitado, nenhum resquício da reversão.

## Evidência — suíte de testes final

```
phpunit tests/Unit/Phase150 tests/Feature/Phase150 --colors=never
..................................................                50 / 50 (100%)
OK (50 tests, 128 assertions)
```

```
phpunit tests/Feature/Phase37CompaniesPerformanceFilterTest.php tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php --colors=never
........................                                          24 / 24 (100%)
OK (24 tests, 44 assertions)
```

## Deviations from Plan

None - plan executado exatamente como escrito. Único ajuste sem impacto de comportamento: a primeira redação do docblock da migration citava `cascadeOnDelete()` em prosa, o que fazia o `grep -c "cascadeOnDelete"` do acceptance criteria (esperado = 1, só a FK de `company_id`) bater 2. Reescrito para "FK em CASCATA" em texto livre, sem mudar o sentido nem o código — não é deviation de comportamento, é ajuste de fraseado para não colidir com o próprio grep de verificação do plano.

## Issues Encountered

Nenhum. `migrate:rollback --step=2` e `migrate` rodaram limpos na primeira tentativa; nenhuma outra sessão tocou o banco compartilhado `ecf_admin` durante a janela de execução (confirmado pelas duas asserções de batch, antes e depois).

## User Setup Required

None - nenhuma configuração de serviço externo.

## Next Phase Readiness

- G2 (CRITICAL) fechado. Restam G3 (150-11, filtros de `/companies` se apagando entre si) e G4+G5+G6 (150-10) para fechar o gap closure completo da Fase 150.
- Fase 156 (Histórico e rastreabilidade para SLA) agora consome uma FK que não perde histórico por exclusão administrativa de colaborador — pré-requisito que essa fase dependia implicitamente sem ter sido nomeado nela.

---
*Phase: 150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0*
*Completed: 2026-09-02*

## Self-Check: PASSED

- FOUND: `.planning/phases/150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/150-09-SUMMARY.md`
- FOUND: `tests/Feature/Phase150/EtapaHistoricoAtorTest.php`
- FOUND: commit `c08d836d` (fix — Task 1)
- FOUND: commit `a0001044` (test — Task 2)
