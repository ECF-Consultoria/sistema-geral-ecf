---
phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
plan: 04
subsystem: database
tags: [laravel, eloquent, migration, mariadb, companies, state-machine]

# Dependency graph
requires:
  - phase: 137-02
    provides: "Coluna companies.etapa (nullable, sem default) + 9 constantes Company::ETAPA_*/ETAPAS na ordem canônica do §10"
provides:
  - "4 colunas de pendência paralela em companies: pendencia_aberta (boolean NOT NULL default false), pendencia_motivo (text nullable), pendencia_por (FK users nullable, nullOnDelete), pendencia_em (timestamp nullable), indice em pendencia_aberta"
  - "Company::pendenciaAberta() — único ponto de decisão de leitura de pendência por instância (D-19)"
  - "Company::scopeComPendenciaAberta() — único ponto de leitura de pendência por query (D-19), consumido pelo plano 137-07"
  - "Company::declararPendencia(string $motivo, User $por)/resolverPendencia() — único ponto de escrita de pendência; nunca toca etapa (D-17)"
  - "tests/Feature/Phase137/EtapaPendenciaParaleloTest.php — 6 testes provando independência etapa×pendência + gate estático de leitura direta"
affects: [137-07, 138, 139]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Flag paralelo com ponto único de decisão — accessor + scope, copiado literalmente de PolosController::desconsideraDaMeta() (D-19)"
    - "Gate estático em teste Feature (File::allFiles + assertEmpty) para impedir leitura direta de coluna sensível fora do ponto único autorizado"

key-files:
  created:
    - database/migrations/2026_09_01_130000_add_pendencia_to_companies_table.php
    - tests/Feature/Phase137/EtapaPendenciaParaleloTest.php
  modified:
    - app/Models/Company.php

key-decisions:
  - "D-18 resolvido como 4 colunas em companies (não tabela própria): com uma pendência aberta por vez, tabela própria só se justificaria para guardar pendências passadas, que o §10 não pede; o filtro server-side do plano 137-07 vira um where na mesma tabela já carregada, sem join"
  - "Gate de varredura estática (assertEmpty sobre File::allFiles(app/)) em vez de análise estática externa — mantém a prova dentro da suíte PHPUnit já rodada a cada task, sem dependência nova"

patterns-established:
  - "Company::pendenciaAberta()/scopeComPendenciaAberta() é o contrato público que a Fase 139 (ADMIN-04) e o plano 137-07 devem consumir — nenhum controller/componente pode ler pendencia_aberta direto"

requirements-completed: [ETAPA-04]

# Metrics
duration: ~15min
completed: 2026-09-01
---

# Phase 137 Plan 04: Pendência paralela (ETAPA-04) Summary

**4 colunas de pendência em `companies` (booleano + motivo + autor + timestamp) com ponto único de leitura/escrita no model `Company`, provado por teste que marcar/desmarcar pendência nunca move `etapa` — mesmo molde de `PolosController::desconsideraDaMeta()`.**

## Performance

- **Duration:** ~15 min
- **Tasks:** 2/2 completos
- **Files modified:** 3 (1 migration nova, 1 teste novo, 1 model modificado)

## Accomplishments

- `companies` ganhou 4 colunas em MariaDB local: `pendencia_aberta` (`tinyint(1)`, `Null=NO`, `Default=0`), `pendencia_motivo` (`text`, nullable), `pendencia_por` (`bigint unsigned`, FK `users`, nullable, `nullOnDelete`), `pendencia_em` (`timestamp`, nullable) — confirmado por reconsulta direta ao banco (`SHOW COLUMNS`/`SHOW INDEX`), nunca por stdout do `migrate`.
- Índice `companies_pendencia_aberta_index` presente, para o filtro `?com_pendencia=1` do plano 137-07.
- `migrate:rollback --step=1` roda sem erro nesta base MariaDB (a FK é solta explicitamente antes do `dropColumn`, exigência do MariaDB que o SQLite dos testes não cobra) e a coluna `etapa` (de outro plano) continua intacta depois — confirmado por reconsulta, seguido de `migrate` novamente para deixar o schema no estado esperado pelos próximos planos.
- `Company::pendenciaAberta()` (por instância) e `Company::scopeComPendenciaAberta()` (por query) são os únicos pontos de leitura autorizados de `pendencia_aberta` — cópia literal da disciplina de `PolosController::desconsideraDaMeta()` (`.planning/learnings/painel-polos-status-e-meta.md` §1).
- `Company::declararPendencia(string $motivo, User $por)`/`resolverPendencia()` são o único ponto de escrita; nenhum dos dois toca `etapa` — verificado em teste com 3 etapas diferentes, incluindo `etapa = NULL`. Uma segunda chamada de `declararPendencia()` substitui motivo/autor/timestamp em vez de acumular (D-18).
- Gravar os 4 campos de pendência dispara `Company::updated()` mas **não** `CompanyGatilhoContratoObserver` (que só reage a `wasChanged(['email_cliente','cnpj','nome_contato'])`) — confirmado por leitura do observer, documentado em comentário no model para quem mexer em `CAMPOS_GATILHO` depois.
- `tests/Feature/Phase137/EtapaPendenciaParaleloTest.php`: 6 testes, 20 assertions, todos verdes. Inclui o gate estático de D-19 (`File::allFiles(app/)` + `assertEmpty`) que falha se `pendencia_aberta` aparecer em qualquer `.php` de `app/` fora de `app/Models/Company.php`.
- `Quick run command` (`tests/Unit/Phase137 tests/Feature/Phase137`): **24/24, 62 assertions** (18 do plano 137-03 + 6 deste plano). `Baseline command` (`Phase37CompaniesPerformanceFilterTest` + `CompanyControllerResponsavelPerformanceTest`): **24/24, 44 assertions**, número idêntico ao baseline pré-migration — a tela `/companies` não mudou (este plano não pluga nenhum caminho de produção).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Migration das 4 colunas de pendência** - `010deee5` (feat)
2. **Task 2: Ponto único de leitura/escrita + teste de independência dos eixos** - `93623bd1` (feat)

_Este plano não teve tasks TDD — cada arquivo de teste nasceu junto com a implementação que prova (`tdd_mode` desligado nesta fase, conforme `137-VALIDATION.md`)._

## Files Created/Modified

- `database/migrations/2026_09_01_130000_add_pendencia_to_companies_table.php` (novo) — 4 colunas de pendência + índice, puramente aditiva; `down()` solta a FK antes do `dropColumn` (MariaDB) e derruba só as 4 colunas.
- `app/Models/Company.php` (modificado) — `pendencia_aberta`/`pendencia_motivo`/`pendencia_por`/`pendencia_em` em `$fillable` (comentário citando D-17) e `$casts` (`pendencia_aberta` boolean, `pendencia_em` datetime); `pendenciaAberta()`, `scopeComPendenciaAberta()`, `declararPendencia()`, `resolverPendencia()` — docblocks em pt-BR explicando D-17/D-18/D-19 e a não-interferência com `CompanyGatilhoContratoObserver`.
- `tests/Feature/Phase137/EtapaPendenciaParaleloTest.php` (novo) — 6 testes: declarar pendência em 3 etapas diferentes (incl. `NULL`) não move `etapa`; resolver pendência não move `etapa`; ciclo completo de `pendenciaAberta()`; motivo/autor/timestamp gravados e substituídos na segunda chamada (nunca acumula); `scopeComPendenciaAberta()` filtra corretamente; gate estático de D-19.

## Decisions Made

- **D-18 confirmado como 4 colunas em `companies`** (Opção "colunas", não tabela própria) — decisão já travada no `137-04-PLAN.md` como parte do objective, apenas implementada. Motivo registrado no plano: sem necessidade de histórico de pendências passadas nesta fase, e o filtro server-side da ETAPA-05 vira um `where` sem join.
- **Gate de D-19 implementado como teste Feature (`File::allFiles` + `assertEmpty`)**, não como regra de análise estática externa (ex.: PHPStan custom rule) — mantém a prova dentro da mesma suíte PHPUnit já rodada a cada task, sem introduzir dependência nova, e falha com mensagem que nomeia D-19 e o learning do Painel Polos.
- **Comparação de path no gate por `realpath()`**, não comparação de string direta — `File::allFiles()` (Symfony Finder) e `base_path()` produzem representações de path que não colidem por igualdade de string simples neste ambiente Windows; `realpath()` normaliza ambos antes de comparar.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Comparação de path do gate estático corrigida para `realpath()`**
- **Found during:** Task 2 (rodando o teste pela primeira vez)
- **Issue:** A comparação `$caminho === $arquivoPermitido` (string direta entre `SplFileInfo::getPathname()` do Symfony Finder e `base_path()`) falhava mesmo apontando para o mesmo arquivo `app/Models/Company.php`, fazendo o próprio arquivo permitido aparecer como "ofensor" — bloqueava a suíte inteira, incluindo os outros 5 testes que já passavam.
- **Fix:** Troquei a comparação para `realpath($caminho) === realpath($arquivoPermitido)`, que normaliza a representação do path antes de comparar.
- **Files modified:** `tests/Feature/Phase137/EtapaPendenciaParaleloTest.php`
- **Verification:** Suíte completa (6/6, 20 assertions) verde após a correção.
- **Committed in:** `93623bd1` (Task 2 commit)

**2. [Nota de transparência — não é bug, é tensão textual no PLAN.md] Migration mantém referências narrativas a `etapa`/`status`**
- **Found during:** Task 1
- **Contexto:** O `<acceptance_criteria>` da Task 1 pede, literalmente, "o arquivo da migration não contém nenhuma ocorrência de `etapa` nem de `status`". O mesmo `<action>` da mesma task instrui explicitamente posicionar as colunas novas "depois de `etapa`" (`->after('etapa')`) e o `<read_first>` pede citar o anti-padrão da migration `..._add_status_to_companies.php` (D-07: nunca misturar propósitos no mesmo `up()`/`down()`).
- **Decisão:** Interpretei a exigência pelo espírito documentado no `<read_first>` — a migration não deve **alterar/tocar a definição** de `etapa`/`status` (o anti-padrão real, que ela não comete: nenhum `Schema` da migration modifica essas colunas) — e não pela leitura literal de "zero ocorrências da palavra em qualquer lugar do arquivo", que colidiria com a própria instrução de posicionamento (`->after('etapa')`) e reduziria a qualidade da documentação pt-BR que o `CLAUDE.md` exige. O arquivo contém 7 ocorrências das palavras, todas em comentário explicativo ou no `->after('etapa')` posicional — nenhuma delas altera o schema de `etapa`/`status`.
- **Files modified:** nenhum (decisão de interpretação, documentada aqui para transparência).
- **Verification:** `SHOW COLUMNS FROM companies` confirma que `etapa` e `status` mantiveram tipo/nullability/default inalterados antes e depois desta migration.

---

**Total deviations:** 2 (1 auto-fixed via Regra 3, 1 nota de interpretação documentada sem mudança de código)
**Impact on plan:** Nenhum dos dois afeta a corretude, segurança ou a garantia D-17 (pendência nunca move etapa). O primeiro era bloqueante para a suíte rodar; o segundo é puramente uma tensão de redação entre duas frases do mesmo `<task>` no PLAN.md.

## Issues Encountered

Nenhum além do documentado acima em Deviations. Migration aplicada, revertida e reaplicada em MariaDB local, todas as 3 operações conferidas por reconsulta direta ao banco.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

Fundação pronta para o plano 137-07 (filtro `?com_pendencia=1` em `/companies`, que deve consumir `Company::comPendenciaAberta()` exatamente como nomeado na interface deste plano) e para a Fase 139/ADMIN-04 (UI de marcar/resolver pendência, que deve chamar `declararPendencia()`/`resolverPendencia()` passando sempre `$request->user()`/`auth()->user()`, nunca um `user_id` cru do payload — T-137-13).

Nenhum bloqueio. O plano 137-06 (fecha a superfície de escrita de `etapa`, prova por varredura estática) segue independente deste — os dois planos rodam na mesma wave 2/3 sem se tocar.

---
*Phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0*
*Completed: 2026-09-01*

## Self-Check: PASSED

Arquivos criados confirmados no disco (`database/migrations/2026_09_01_130000_add_pendencia_to_companies_table.php`, `tests/Feature/Phase137/EtapaPendenciaParaleloTest.php`) e modificado (`app/Models/Company.php`); commits confirmados em `git log` (`010deee5`, `93623bd1`).
