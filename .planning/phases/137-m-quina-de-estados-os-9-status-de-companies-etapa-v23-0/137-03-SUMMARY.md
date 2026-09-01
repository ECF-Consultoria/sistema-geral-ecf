---
phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
plan: 03
subsystem: database
tags: [laravel, eloquent, migration, mariadb, companies, state-machine, service-layer]

# Dependency graph
requires:
  - phase: 137-02
    provides: "Coluna companies.etapa (nullable, sem default) + 9 constantes Company::ETAPA_*/ETAPAS na ordem canônica do §10"
provides:
  - "Único serviço capaz de gravar companies.etapa: App\\Services\\FluxoEntrada\\EtapaTransicaoService"
  - "podeTransicionar() puro com tabela explícita de transições permitidas (incl. salto 2→4) e recusa sempre nomeada"
  - "transicionar() transacional: grava só etapa (Pitfall 6) + 1 linha de histórico por transição aprovada"
  - "carimbarBackfill() — escrita em massa exclusiva do backfill do plano 137-05, sem histórico"
  - "Tabela company_etapa_transicoes (append-only) + model CompanyEtapaTransicao"
  - "tests/Unit/Phase137/EtapaTransicaoServiceTest.php — 12 testes provando as 5 regras de podeTransicionar() e o único ponto de escrita"
affects: [137-04, 137-05, 137-06, 138, 139, 141, 142, 143]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Par puro/efeito espelhando GatilhoContratoAdministrativoService: podeTransicionar() sem efeito colateral × transicionar() com efeito, mesma régua para recusa programática e futura UI"
    - "Serviço escrito SEM chamador de produção de propósito (precedente EmpresaOperacionalRouter, Fase 124) — separa o risco de escrever o service do risco de trocar o caminho de produção"
    - "Tabela explícita de transições (const array), não corrente +1 rígida — permite saltos conhecidos do domínio"
    - "Histórico append-only em tabela dedicada (UPDATED_AT = null), molde company_manager_history (Fase 108)"

key-files:
  created:
    - database/migrations/2026_09_01_120000_create_company_etapa_transicoes_table.php
    - app/Models/CompanyEtapaTransicao.php
    - app/Services/FluxoEntrada/EtapaTransicaoService.php
    - tests/Unit/Phase137/EtapaTransicaoServiceTest.php
  modified: []

key-decisions:
  - "D-16 resolvido como tabela dedicada company_etapa_transicoes (Opção B), não spatie/laravel-activitylog — o pacote tem delete_records_older_than_days=365 (risco para o dado que a Fase 143 usa de insumo de SLA) e motivo/retrocesso são conceitos de primeira classe, não properties genérico"
  - "Chave null de origem no array TRANSICOES_PERMITIDAS é escrita explicitamente como '' (string vazia), documentada em comentário, para não depender do cast implícito do PHP (null vira '' como chave de array)"
  - "Checagem de retrocesso (D-15) é posicional — compara o índice de Company::ETAPAS do destino contra o da etapa atual — e roda ANTES da consulta à tabela explícita de avanços, então cobre qualquer retrocesso, não só os pares listados"

patterns-established:
  - "EtapaTransicaoService::podeTransicionar()/transicionar() é o contrato público que as Fases 138-143 devem consumir — nenhum chamador deve escrever companies.etapa por fora"

requirements-completed: [ETAPA-03, ETAPA-06]

# Metrics
duration: ~35min
completed: 2026-09-01
---

# Phase 137 Plan 03: Serviço único de transição de etapa + histórico Summary

**`EtapaTransicaoService` — par puro/efeito (`podeTransicionar()`/`transicionar()`) que é o único ponto de escrita de `companies.etapa`, com tabela explícita de transições (incl. salto 2→4), retrocesso auditado com motivo obrigatório, e histórico append-only transacional em `company_etapa_transicoes` — ainda sem chamador de produção, de propósito.**

## Performance

- **Duration:** ~35 min
- **Tasks:** 3/3 completos
- **Files modified:** 4 (2 arquivos novos de schema/model, 1 serviço novo, 1 teste novo)

## Accomplishments

- `company_etapa_transicoes` existe em MariaDB local: 8 colunas (`id`, `company_id`, `etapa_anterior` nullable, `etapa_nova` NOT NULL, `user_id`, `motivo`, `retrocesso`, `created_at`), sem `updated_at`, com índice composto `(company_id, created_at)` — confirmado por reconsulta direta ao banco (`artisan db:table --json`), nunca por stdout do `migrate`.
- `EtapaTransicaoService::podeTransicionar()` é puro (nenhum `save`/`update`/`create`/`dispatch` no corpo) e cobre as 5 regras do plano: destino desconhecido, destino igual ao atual, retrocesso (posicional, D-15), fora da tabela explícita de avanço, avanço normal. Toda recusa nomeia o requisito faltante — nenhuma mensagem genérica.
- O salto 2→4 (empresa `isento` no gate v22.0, ex. 100% Polos) está na tabela `TRANSICOES_PERMITIDAS` e é aceito; o salto 1→9 é recusado com a origem e a lista de destinos aceitos na mensagem.
- `EtapaTransicaoService::transicionar()` é a única escrita de `companies.etapa`: recusa se `podeTransicionar()` recusar (propagando a mensagem, sem inventar outra), recusa retrocesso sem motivo (D-15), e grava a coluna + a linha de histórico dentro de uma transação — não existe etapa mudada sem rastro.
- Restrição do Pitfall 6 (RESEARCH.md) aplicada literalmente: o `update()` que toca `Company` grava **só** a chave `'etapa'` — confirmado por grep, os dois únicos `update([` do arquivo têm exatamente essa chave. `CompanyGatilhoContratoObserver` não dispara de carona.
- `carimbarBackfill()` existe como escrita em massa exclusiva do plano 137-05, deliberadamente sem gerar histórico (evita duração fictícia no painel de gargalo da Fase 143).
- 12 testes unitários novos, todos verdes: 7 de `podeTransicionar()` (Task 2) + 5 de `transicionar()`/`carimbarBackfill()` (Task 3). Suíte `tests/Unit/Phase137` completa: 18/18 (6 do plano 137-02 + 12 deste plano). `Baseline command` (`Phase37CompaniesPerformanceFilterTest` + `CompanyControllerResponsavelPerformanceTest`): 24/24, número idêntico ao registrado antes da migration — a tela `/companies` não mudou, como esperado (este plano não pluga nenhum caminho de produção).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Tabela e model do histórico de transição (D-16, Opção B)** - `94889df7` (feat)
2. **Task 2: `podeTransicionar()` — a régua pura, com recusa nomeada (ETAPA-06)** - `fbc62ec8` (feat)
3. **Task 3: `transicionar()` e `carimbarBackfill()` — o único ponto de escrita** - `bfd89263` (feat)

_Este plano não teve tasks TDD — cada arquivo de teste nasceu junto com a implementação que prova (`tdd_mode` desligado nesta fase, conforme `137-VALIDATION.md`)._

## Files Created/Modified

- `database/migrations/2026_09_01_120000_create_company_etapa_transicoes_table.php` — cria `company_etapa_transicoes` (append-only, `etapa_anterior` nullable, `etapa_nova` NOT NULL, `retrocesso` boolean default false, sem `updated_at`) + índice composto `(company_id, created_at)`; `down()` derruba só a tabela.
- `app/Models/CompanyEtapaTransicao.php` (novo) — `UPDATED_AT = null`, `$fillable` com as 6 colunas graváveis, casts (`retrocesso` boolean, `created_at` datetime), relações `company()`/`user()`.
- `app/Services/FluxoEntrada/EtapaTransicaoService.php` (novo) — `TRANSICOES_PERMITIDAS` (tabela explícita, D-14), `podeTransicionar()` (puro), `transicionar()` (único ponto de escrita, transacional), `carimbarBackfill()` (escrita em massa exclusiva do backfill).
- `tests/Unit/Phase137/EtapaTransicaoServiceTest.php` (novo) — 12 testes: destino inexistente, destino=atual, salto 1→9 recusado com mensagem nomeando origem+destinos aceitos, salto 2→4 permitido, `null`→etapa1 permitido, `null`→etapa5 recusado, retrocesso 5→2 permitido e marcado, transição válida grava coluna+histórico com ator, transição recusada não grava nada, retrocesso sem motivo recusado, retrocesso com motivo grava `retrocesso=true`+`motivo`, `carimbarBackfill()` grava etapa 9 sem histórico.

## Decisions Made

- **D-16 (Opção B) confirmada em execução:** tabela dedicada `company_etapa_transicoes`, não `activitylog` — decisão já travada no `137-03-PLAN.md`, apenas implementada.
- **Chave `null` no array `TRANSICOES_PERMITIDAS`:** PHP funde toda chave `null` de array em `''` (string vazia) automaticamente. Em vez de depender do cast implícito, a chave já nasce escrita como `''` no código-fonte, com comentário explicando o motivo — decisão de implementação não coberta pelo `137-CONTEXT.md`, tomada para manter a tabela legível e auditável sem "mágica" de runtime.
- **Ordem das checagens em `podeTransicionar()`:** a checagem de retrocesso (regra 3) é posicional (compara índices em `Company::ETAPAS`) e roda **antes** da consulta à tabela explícita de avanços (regra 4). Isso garante que qualquer retrocesso — não só os pares explicitamente listados — seja reconhecido como retrocesso e não caia, por engano, na regra "fora da tabela de avanço" com uma mensagem que faria parecer que retrocesso não existe daquela origem.

## Deviations from Plan

None - plano executado exatamente como escrito. Nenhuma das 3 tasks exigiu fix de Regra 1/2/3, nem decisão de Regra 4.

## Issues Encountered

None. Migration aplicada e conferida por reconsulta direta ao MariaDB local (`artisan db:table --json`), não por stdout do `migrate`, conforme exigido pelo `137-03-PLAN.md` e pela disciplina do `.planning/learnings/desempenho-bonificacao.md` §6/§8.

## Verificação de regressão (fim de wave)

- `Quick run command` (`tests/Unit/Phase137`): **OK (18 tests, 42 assertions)**. `tests/Feature/Phase137` ainda não existe (nasce no plano 137-04) — comportamento esperado, documentado também no `137-02-SUMMARY.md`.
- `Baseline command` (`Phase37CompaniesPerformanceFilterTest` + `CompanyControllerResponsavelPerformanceTest`): **OK (24 tests, 44 assertions)** — idêntico à baseline registrada em `137-BASELINE-TESTES.md`. Nenhum caminho de produção foi alterado por este plano (o serviço nasce sem chamador, de propósito), então a tela `/companies` não podia ter mudado, e não mudou.
- Reconsulta final ao banco: `company_etapa_transicoes` com as 8 colunas esperadas, sem `updated_at`, índice composto `(company_id, created_at)` presente, FKs `company_id`→`companies.cascadeOnDelete()` e `user_id`→`users.cascadeOnDelete()` confirmadas.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

Fundação pronta para os planos 137-04 (pendência paralela — não depende deste, mas roda na mesma wave), 137-05 (backfill — vai chamar `carimbarBackfill()` diretamente) e 137-06 (fecha a superfície de escrita, prova por varredura estática que nenhum outro lugar de `app/` grava `companies.etapa` — sanity check manual já confirmou que as únicas ocorrências de `'etapa'` fora deste serviço pertencem a `OnboardingPasso`, tabela diferente).

Para as Fases 138 (webhook → etapa 1), 139 (FINALIZAR → etapa 5), 141 (distribuição → etapa 6) e 142 (onboarding → 7/8/9): o contrato público está pronto e testado — `transicionar(Company $company, string $etapaDestino, User $por, ?string $motivo = null): array`. As Fases 138-142 devem sempre passar `$request->user()`/`auth()->user()` como `$por`, nunca um `user_id` cru vindo de payload (T-137-02, mitigado pela tipagem `User`).

Nenhum bloqueio. Timestamp `130000` segue livre para a migration do plano 137-04, conforme reservado pelo plano 137-02.

---
*Phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0*
*Completed: 2026-09-01*

## Self-Check: PASSED

Todos os arquivos criados confirmados no disco (`database/migrations/2026_09_01_120000_create_company_etapa_transicoes_table.php`, `app/Models/CompanyEtapaTransicao.php`, `app/Services/FluxoEntrada/EtapaTransicaoService.php`, `tests/Unit/Phase137/EtapaTransicaoServiceTest.php`) e todos os commits confirmados em `git log` (`94889df7`, `fbc62ec8`, `bfd89263`).
