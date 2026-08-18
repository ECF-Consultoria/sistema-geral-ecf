---
phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com
plan: 02
subsystem: database
tags: [eloquent, migrations, sqlite, mariadb, versionamento, laravel]

# Dependency graph
requires:
  - phase: 135-01
    provides: "ContratoServicoFactory + baseline de regressão de Polos (10 falhas pré-existentes) como denominador de não-regressão"
provides:
  - "5 tabelas novas (onboarding_templates, template_passos, onboardings, onboarding_passos, onboarding_links) e seus 5 models Eloquent"
  - "Catálogos fechados como constantes PHP: TemplatePasso::DONOS/AUTO_FONTES/CONDICOES, Onboarding::STATUSES, OnboardingPasso::STATUSES"
  - "Índice único parcial composto multi-driver (SQLite + MariaDB) garantindo 1 versão ativa por serviço"
  - "disponivel_em e coleta_iniciada_em no schema — pré-requisito estrutural de SC-11 e do watchdog de coleta"
affects: [135-03, 135-04, 135-05, 135-06, 135-07, 135-08, 135-09, 135-10, 135-11, 135-12, 135-13]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Índice único parcial COMPOSTO (servico_id) WHERE ativo=1, bifurcado por DB::connection()->getDriverName() — SQLite via CREATE UNIQUE INDEX ... WHERE, MariaDB via coluna virtual gerada (CASE WHEN ativo=1 THEN servico_id END) + UNIQUE"
    - "Versionamento imutável por linha nova: nenhuma migration ou model desta fase faz UPDATE em template_passos publicado — só INSERT de versão N+1"
    - "depende_de guarda array de CHAVE (string), não de id — sobrevive à criação de ids novos a cada versão"

key-files:
  created:
    - database/migrations/2026_08_11_120000_create_onboarding_templates_tables.php
    - database/migrations/2026_08_11_120100_create_onboardings_tables.php
    - app/Models/OnboardingTemplate.php
    - app/Models/TemplatePasso.php
    - app/Models/Onboarding.php
    - app/Models/OnboardingPasso.php
    - app/Models/OnboardingLink.php
    - tests/Feature/Phase135/OnboardingSchemaTest.php
  modified: []

key-decisions:
  - "down() das duas migrations remove explicitamente índice/coluna gerada do ramo MariaDB antes do dropIfExists, por simetria com o up() — mesmo dropIfExists já removendo tudo sozinho"
  - "OnboardingLink não referencia 'mlb_implementacoes' nem 'MlbImplementacao' nem em comentário — o gate D-02 do plano é literal (grep em app/Models/Onboarding*.php + TemplatePasso.php) e pegou até docblock comparativo"
  - "Onboarding é o único dos 5 models com LogsActivity (status + responsavel_id) — os demais não têm activitylog, conforme especificado no plano"

patterns-established:
  - "Molde de índice único parcial composto (não só '1 default global' como em nps_templates, mas '1 ativo por servico_id') fica disponível para qualquer versionamento futuro por-dono no projeto"

requirements-completed: [SC-01, SC-05, SC-09, SC-10, SC-11, D-01, D-06, D-07, D-09, D-10, D-11, D-12, D-14, D-19]

# Metrics
duration: ~23min
completed: 2026-08-11
---

# Fase 135 Plano 02: Schema do motor de Onboarding (5 tabelas + 5 models) Summary

**5 tabelas e 5 models Eloquent do motor de onboarding — versionamento imutável de template com índice único parcial composto (SQLite+MariaDB), 6 estados de passo (nunca booleano) e `disponivel_em`/`coleta_iniciada_em` no schema — tudo ao lado do onboarding de Polos, intocado.**

## Performance

- **Duration:** ~23 min
- **Started:** 2026-08-11T17:00:00Z (aprox., após leitura de contexto)
- **Completed:** 2026-08-11T17:17:10Z
- **Tasks:** 3
- **Files modified:** 8 (2 migrations criadas, 5 models criados, 1 teste criado)

## Accomplishments
- `onboarding_templates` + `template_passos` com versionamento imutável por linha nova (D-07/SC-09) e índice único parcial **composto** `(servico_id) WHERE ativo=1`, bifurcado por driver — comprovado em SQLite via inserts reais que lançam `QueryException` na segunda linha `ativo=true` do mesmo serviço, e convivem sem erro quando a segunda é `ativo=false`.
- `onboardings` + `onboarding_passos` + `onboarding_links` com `disponivel_em` e `coleta_iniciada_em` documentados no schema (comentário explícito de por que existem, exigido pelo plano), `unique(contrato_servico_id)` (SC-01) e `unique(company_id)` em `onboarding_links` (D-06) — as 4 constraints de unicidade do plano provadas por `QueryException` real, não por leitura de código.
- 5 models com os catálogos fechados exatos do `<interfaces>`: `TemplatePasso::DONOS` (3), `TemplatePasso::AUTO_FONTES` (5), `TemplatePasso::CONDICOES`, `Onboarding::STATUSES` (3), `OnboardingPasso::STATUSES` (6, incluindo `aguardando_coleta` e `indeterminado` — D-11). D-19 (dono ≠ auto_fonte) documentada em pt-BR no docblock de `TemplatePasso`.
- `OnboardingSchemaTest` (12 testes) cobre round-trip de casts (`array`/`Carbon`), contagem exata dos catálogos e as 4 constraints de unicidade — mais um teste literal do gate D-02 (grep programático de `mlb_implementacoes`/`MlbImplementacao` nos 5 arquivos de model).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Migration das tabelas de template (versionamento imutável + 1 ativa por serviço)** - `0d02ff1c` (feat)
2. **Task 2: Migration das tabelas de onboarding (com disponivel_em) e do link por empresa** - `78d9e380` (feat)
3. **Task 3: Os 5 models com casts, relações e catálogos fechados** - `fe41d426` (feat)

**Plan metadata:** (a ser adicionado no commit final de documentação)

## Files Created/Modified
- `database/migrations/2026_08_11_120000_create_onboarding_templates_tables.php` - `onboarding_templates` + `template_passos`, índice único parcial composto multi-driver
- `database/migrations/2026_08_11_120100_create_onboardings_tables.php` - `onboardings` + `onboarding_passos` + `onboarding_links`, com `disponivel_em`/`coleta_iniciada_em` comentados
- `app/Models/OnboardingTemplate.php` - relação `passos()` ordenada, scope `ativo()`
- `app/Models/TemplatePasso.php` - catálogos fechados `DONOS`/`AUTO_FONTES`/`CONDICOES`, docblock D-19
- `app/Models/Onboarding.php` - `LogsActivity` (status + responsavel_id), scopes `emAndamento()`/`naoConcluido()`
- `app/Models/OnboardingPasso.php` - `STATUSES` com os 6 estados (D-11), scope `pendentesDeReavaliacao()`
- `app/Models/OnboardingLink.php` - 1 token por empresa, `belongsTo(Company::class)`
- `tests/Feature/Phase135/OnboardingSchemaTest.php` - 12 testes (catálogos, casts, unicidade, gate D-02)

## Decisions Made
- Índice único parcial composto adaptado do molde `nps_templates_default_uniq` (1 default global) para "1 ativo por `servico_id`" — no MariaDB a coluna virtual gerada devolve `servico_id` (não `1`) quando `ativo=1`, para que a unicidade seja por combinação e não global.
- `down()` das duas migrations remove explicitamente o índice/coluna gerada do ramo MariaDB antes do `dropIfExists` — redundante com o que `dropIfExists` já faz sozinho, mas mantém a simetria explícita pedida pelo plano.
- `depende_de` fica como `json` (array de `chave`, nunca de `id`) desde já, mesmo sem consumidor nesta fase — é D-10, decisão já travada no CONTEXT.

## Deviations from Plan

None - plano executado exatamente como escrito nas 3 tasks.

## Issues Encountered

- **Sequenciamento da verificação das Tasks 1 e 2:** o comando de verify de ambas (`artisan test --filter=OnboardingSchemaTest`) referencia um arquivo de teste que só é criado na Task 3. Adaptei rodando os critérios de aceitação de cada task manualmente via `artisan tinker` + Query Builder (inserts reais provocando `QueryException` nas 4 constraints, mais os greps especificados) contra um banco SQLite temporário, e só rodei o `--filter=OnboardingSchemaTest` de fato depois que o arquivo existiu, na Task 3 — onde passou 12/12 de primeira depois de um ajuste (abaixo).
- **`artisan migrate --env=testing --database=sqlite` do próprio plano não funciona neste ambiente:** sem `.env.testing`, o comando cai no `.env` de desenvolvimento (MySQL) e tenta abrir um arquivo sqlite chamado literalmente `ecf_admin` (o `DB_DATABASE` do `.env`). Usei `DB_CONNECTION=sqlite DB_DATABASE=<arquivo temporário>` como override de variável de ambiente para validar as migrations de forma equivalente (mesmo efeito: SQL roda limpo, sem erro). Os testes reais (Task 3) usam o `:memory:` de `phpunit.xml`, que é o caminho canônico do projeto.
- **Auto-correção antes do commit (não chegou a ser um deviation formal):** o docblock inicial de `OnboardingLink.php` comparava o token novo com `mlb_implementacoes.token` para dar contexto — isso derrubou o próprio teste de gate D-02 (`grep "mlb_implementacoes\|MlbImplementacao" app/Models/Onboarding*.php`), porque o glob `Onboarding*.php` inclui `OnboardingLink.php`. Reescrevi o comentário sem citar a tabela/classe de Polos antes de qualquer commit — nenhum código com a referência chegou a ser versionado.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Schema e models prontos para o Observer do Plano 04 (`ContratoServicoObserver`) criar `Onboarding`/`OnboardingPasso` em rascunho nos 4 call-sites de `ContratoServico::create()`.
- Catálogos fechados (`TemplatePasso::AUTO_FONTES`/`DONOS`/`CONDICOES`) prontos para o FormRequest do Plano 07 validar contra eles via `Rule::in()`.
- `disponivel_em`/`coleta_iniciada_em` no schema — o Plano 05 (avaliador de dependências) e o Plano 12 (comando de reavaliação) já têm onde escrever/ler.
- Nenhum arquivo de Polos tocado (`git status --porcelain` sem `mlb_implementacoes`/`MlbImplementacao`/`Pages/MlbImplementacao` nesta sessão) — D-02 intacto.
- Gate de regressão de Polos (SC-02) conferido nesta sessão: `PolosControllerTest` (6 falhas) + `PolosFaturamentoSnapshotTest` (4 falhas) = 10 falhas, batendo exatamente com a baseline do Plano 01. Zero falha nova.
- Os 4 arquivos de risco do Observer (`Phase112HubspotHandoffWebhookTest`, `Phase113HubspotDedupTest`, `Phase37ComercialListagemTest`, `Phase37CompaniesPerformanceFilterTest`) seguem 100% verdes.

---
*Phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com*
*Completed: 2026-08-11*

## Self-Check: PASSED

- FOUND: `database/migrations/2026_08_11_120000_create_onboarding_templates_tables.php`
- FOUND: `database/migrations/2026_08_11_120100_create_onboardings_tables.php`
- FOUND: `app/Models/OnboardingTemplate.php`
- FOUND: `app/Models/TemplatePasso.php`
- FOUND: `app/Models/Onboarding.php`
- FOUND: `app/Models/OnboardingPasso.php`
- FOUND: `app/Models/OnboardingLink.php`
- FOUND: `tests/Feature/Phase135/OnboardingSchemaTest.php`
- FOUND: commit `0d02ff1c`
- FOUND: commit `78d9e380`
- FOUND: commit `fe41d426`
