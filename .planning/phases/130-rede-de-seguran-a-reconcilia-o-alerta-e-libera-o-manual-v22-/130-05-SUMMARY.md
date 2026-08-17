---
phase: 130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-
plan: 05
subsystem: notifications
tags: [laravel, notification, console-command, sino, cooldown]

# Dependency graph
requires:
  - phase: 130-01
    provides: "contrato_assinaturas.ultimo_alerta_em (carimbo de cooldown do alerta)"
  - phase: 130-02
    provides: "AudienciaRedeSeguranca::adminsEComercial(), ContratosPresosService::listar()/causa()/diasParado()"
  - phase: 130-04
    provides: "rota nomeada contratos.liberacao-manual.index"
provides:
  - "ContratoPresoNotification — notificação database (sino) com causa legível e link para a liberação manual"
  - "comando clicksign:alertar-presos — o 'alguém sabe' da rede de segurança (REDE-02)"
affects: [130-06-agendamento, 130-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Subclasse de BaseNotification sem sobrescrever via()/toArray() — payload canônico de 6 chaves herdado (mesmo molde de EmpresaHubspotPendenteNotification)"
    - "Cooldown de repetição de alerta filtrado em memória sobre coleção pequena, gravado SOMENTE após envio bem-sucedido (nunca antes)"
    - "Log defensivo de audiência vazia (Log::warning) + log de sucesso com contagens (Log::info), nunca payload/PII, sempre no canal ecf-webhooks"

key-files:
  created:
    - app/Notifications/ContratoPresoNotification.php
    - app/Console/Commands/ClicksignAlertarPresos.php
    - tests/Feature/Phase130/AlertaContratoPresoTest.php
    - tests/Feature/Phase130/AlertaCooldownTest.php
  modified: []

key-decisions:
  - "Nenhuma decisão nova de domínio — D-01/D-02/D-03/D-04/D-05 já estavam travadas no PLAN.md, no 130-CONTEXT.md e no 130-PATTERNS.md. A única escolha livre foi de forma: o texto exato dos 7 rótulos de LABELS_CAUSA seguiu literalmente os exemplos dados no PLAN.md."

patterns-established:
  - "ContratosPresosService::listar() é o ÚNICO recorte de 'empresa presa' — o comando de alerta não reimplementa nem estreita a consulta, apenas aplica o cooldown por cima"

requirements-completed: [REDE-02]

# Metrics
duration: ~25min
completed: 2026-08-13
---

# Phase 130 Plano 05: Rede de segurança — alerta de contrato preso Summary

**`ContratoPresoNotification` (sino in-app, causa legível, link clicável para a liberação manual) + comando `clicksign:alertar-presos` (audiência admin+comercial, cooldown configurável de repetição, log defensivo de audiência vazia) — o "alguém sabe" da rede de segurança, sem nenhuma chamada HTTP externa**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-08-13T19:05:00Z (aprox.)
- **Completed:** 2026-08-13T19:30:00Z (aprox.)
- **Tasks:** 2 completadas
- **Files modified:** 4 (todos criados)

## Accomplishments

- `ContratoPresoNotification` — subclasse concreta de `BaseNotification`, sem sobrescrever `via()`/`toArray()` (canal único `database`, payload canônico de 6 chaves). Mapa privado `LABELS_CAUSA` cobre as 7 constantes `CAUSA_*` de `ContratosPresosService` com frase em linguagem simples que já contém o que fazer, separando explicitamente "o cliente decidiu" (`recusado_pelo_cliente`) de "a integração falhou" (`erro_tecnico`) — a exigência central da D-05. Nenhum rótulo usa jargão (`webhook`/`envelope`/`payload`). `meta` restrita a ids, status, causa e dias parados — nunca e-mail/CPF/nome de signatário. `url` aponta para `route('contratos.liberacao-manual.index')` (plano 130-04).
- `clicksign:alertar-presos` — varre o banco local via `ContratosPresosService::listar()` (o mesmo recorte largo dos 7 estados usado pela tela de liberação manual, sem reimplementar nem estreitar), aplica o cooldown da D-04 em memória (`ultimo_alerta_em === null` OU passou de `rede_alerta_repeticao_dias`, default 3, configurável via `Configuracao::set()` sem deploy), notifica `AudienciaRedeSeguranca::adminsEComercial()` (D-02) e só grava o carimbo `ultimo_alerta_em` DEPOIS do `Notification::send()` bem-sucedido — para o alerta nunca ser "consumido" sem ninguém ter recebido. Audiência vazia gera `Log::channel('ecf-webhooks')->warning` e não marca o carimbo. Falha por contrato individual loga e segue para o próximo, sem derrubar o alerta dos demais.
- 14 testes novos provando: payload da notificação (5), comportamento do comando — audiência certa/errada, dentro do prazo, liberado não alerta, os 3 estados "ruins" (recusado/expirado/erro) geram alerta mesmo velhos, audiência vazia não grava carimbo (5) — e o cooldown ponta a ponta: primeira execução envia e grava, segunda imediata não repete, após o intervalo repete e atualiza, intervalo é configurável sem deploy (4).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: ContratoPresoNotification — a mensagem que diz o que fazer (D-01, D-05)** - `3258079a` (feat)
2. **Task 2: Comando clicksign:alertar-presos — gatilho, audiência e cooldown (D-02, D-03, D-04)** - `43d81d3b` (feat)

## Files Created/Modified

- `app/Notifications/ContratoPresoNotification.php` - `LABELS_CAUSA` (7 rótulos), construtor que monta `parent::__construct()` com título/mensagem/categoria MANUAL/url/meta
- `app/Console/Commands/ClicksignAlertarPresos.php` - `$signature = 'clicksign:alertar-presos'`, `CHAVE_REPETICAO_DIAS`/`DEFAULT_REPETICAO_DIAS`, `handle(ContratosPresosService $presos)` com cooldown, audiência e log defensivo
- `tests/Feature/Phase130/AlertaContratoPresoTest.php` - 10 testes: 5 de payload (Task 1) + 5 de comportamento do comando (Task 2)
- `tests/Feature/Phase130/AlertaCooldownTest.php` - 4 testes: envia e grava, não repete imediato, repete após intervalo, intervalo configurável

## Decisions Made

Nenhuma decisão nova de domínio — todas as regras (D-01/D-02/D-03/D-04/D-05) já estavam travadas no `130-05-PLAN.md`. A única escolha livre foi de forma: os textos dos 7 rótulos de `LABELS_CAUSA` seguiram literalmente os exemplos dados no `<action>` da Task 1 (o plano já entregou o tom exato esperado).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Assertion instável em `AlertaCooldownTest` por precisão de segundo no cast `datetime`**
- **Found during:** primeira execução da suíte após escrever a Task 2
- **Issue:** `test_apos_intervalo_configurado_repete_o_alerta_e_atualiza_o_carimbo` comparava `$segundoCarimbo->gt($primeiroCarimbo)` — como as duas chamadas ao comando aconteceram no mesmo segundo de relógio dentro do teste, o cast `datetime` (precisão de segundo) fazia as duas comparações falharem por igualdade, não por ordem incorreta.
- **Fix:** trocada a base de comparação para `$carimboRecuado` (o valor `now()->subDays(4)` gravado manualmente antes da segunda execução) em vez de `$primeiroCarimbo` — prova a mesma coisa (o carimbo foi atualizado pelo comando) sem depender de precisão de sub-segundo.
- **Files modified:** `tests/Feature/Phase130/AlertaCooldownTest.php`
- **Commit:** `43d81d3b` (mesmo commit da Task 2 — corrigido antes do commit final)

## Issues Encountered

`C:\xampp\php\php.exe artisan clicksign:alertar-presos` isolado (fora da suíte de testes) falhou com `PDOException: Nenhuma conexão pôde ser feita` contra o MariaDB local — mesma instabilidade de ambiente já documentada em `.planning/learnings/` e registrada nos SUMMARY.md dos planos 130-01 e 130-04. Não é regressão desta task: o comando roda corretamente até o `SUCCESS` (exit 0) dentro do ambiente de teste (`RefreshDatabase`, SQLite em memória), como provam os 5 testes de comportamento em `AlertaContratoPresoTest` e os 4 de `AlertaCooldownTest`, todos passando. `mysqld` não estava em execução no momento (`tasklist` confirmou ausência do processo) — fora do escopo deste plano religar o MariaDB local.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. O agendamento em `routes/console.php` fica explicitamente fora deste plano (é o plano 130-06).

## Next Phase Readiness

- `ContratoPresoNotification` e `clicksign:alertar-presos` prontos para o plano 130-06 registrar no `routes/console.php` (horário sugerido: diferente de `clicksign:reconciliar`, com folga, conforme padrão já documentado no `130-PATTERNS.md`).
- Suíte `Phase130` completa: 71/71 testes verdes (57 herdados dos planos 01-04 + 14 novos deste plano).
- Suíte `Phase129` reconfirmada: 80/80 testes verdes — nenhuma regressão.
- Nenhum bloqueio conhecido para os planos seguintes desta fase.

## Self-Check: PASSED

Os 4 arquivos criados (`ContratoPresoNotification.php`, `ClicksignAlertarPresos.php`, `AlertaContratoPresoTest.php`, `AlertaCooldownTest.php`) foram confirmados no disco e os 2 commits de task (`3258079a`, `43d81d3b`) foram confirmados em `git log`. `C:\xampp\php\php.exe artisan test --filter=Phase130` roda verde (71 testes, 280 assertions). `C:\xampp\php\php.exe artisan test --filter=Phase129` roda verde (80 testes, 235 assertions).

---
*Phase: 130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-*
*Completed: 2026-08-13*
