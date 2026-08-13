---
phase: 130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-
plan: 06
subsystem: console-commands
tags: [laravel, scheduler, notification, auto-monitoramento, clicksign]

# Dependency graph
requires:
  - phase: 130-03
    provides: "Configuracao::get('clicksign_reconciliacao_status') — carimbo D-09 gravado por clicksign:reconciliar"
  - phase: 130-05
    provides: "ContratoPresoNotification (molde de subclasse concreta), comando clicksign:alertar-presos"
  - phase: 130-02
    provides: "AudienciaRedeSeguranca::adminsEComercial()"
provides:
  - "VarreduraParadaNotification — sino in-app avisando que a checagem automática de contratos não rodou/rodou com erro/está atrasada"
  - "Comando clicksign:verificar-varredura — lê o carimbo de Configuracao, trata JSON corrompido como 'sem carimbo', cooldown próprio"
  - "3 entradas em routes/console.php (07:00/07:30/08:00) — os três comandos da Fase 130 rodam sozinhos, todo dia"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Notification separada (não reaproveitada) quando o construtor da existente exige uma entidade que pode não existir no cenário novo — VarreduraParadaNotification vs ContratoPresoNotification (esta exige ContratoAssinatura, aquela fala sobre o comando, não sobre uma empresa)"
    - "Cooldown de auto-monitoramento como config global (rede_varredura_ultimo_alerta_em), não por-registro — não há 'um registro por empresa' neste caso, só um estado binário (varredura em dia / atrasada)"
    - "Limitação estrutural de qualquer verificação agendada (D-09) documentada por escrito em DOIS lugares: docblock do comando E comentário do bloco em routes/console.php — nunca escondida"

key-files:
  created:
    - app/Notifications/VarreduraParadaNotification.php
    - app/Console/Commands/ClicksignVerificarVarredura.php
    - tests/Feature/Phase130/AutoMonitoramentoCarimboTest.php
  modified:
    - routes/console.php

key-decisions:
  - "Nenhuma decisão nova de domínio — D-06/D-09 já estavam travadas no PLAN.md e no 130-PATTERNS.md. Único ponto sem análogo direto no projeto (registrado no próprio 130-PATTERNS.md): 'comando que verifica ausência de outro comando' é composição nova de dois padrões existentes (leitura de Configuracao + disparo de notificação), não cópia de um único arquivo."

patterns-established:
  - "clicksign:verificar-varredura nunca reimporta a lógica do carimbo — lê exatamente a mesma chave (clicksign_reconciliacao_status) que clicksign:reconciliar grava, sem duplicar formato"

requirements-completed: [REDE-02, REDE-04]

# Metrics
duration: ~20min
completed: 2026-08-13
---

# Phase 130 Plano 06: Rede de segurança — auto-monitoramento e agendamento Summary

**`VarreduraParadaNotification` + comando `clicksign:verificar-varredura` (a rede de segurança vigiando a si mesma, D-09) e as 3 entradas de agendamento diário em `routes/console.php` (07:00/07:30/08:00, D-06) — fecha o círculo: agora os três comandos da fase rodam sozinhos, e se algum deles parar, alguém é avisado**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-08-13 (aprox.)
- **Completed:** 2026-08-13
- **Tasks:** 2 completadas
- **Files modified:** 4 (3 criados, 1 editado)

## Accomplishments

- `VarreduraParadaNotification` — subclasse concreta de `BaseNotification`, SEPARADA de `ContratoPresoNotification` (o construtor daquela exige um `ContratoAssinatura`, que não existe no cenário "a varredura não rodou"). Sem sobrescrever `via()`/`toArray()` — canal único `database`, payload canônico de 6 chaves. Mensagem em linguagem simples cobrindo os 3 casos (nunca rodou / rodou há X e está atrasada / rodou mas terminou com erro), sempre dizendo o que fazer ("avise o time técnico"), sem jargão não explicado.
- Comando `clicksign:verificar-varredura` — lê `Configuracao::get('clicksign_reconciliacao_status')` (a MESMA chave gravada pelo `clicksign:reconciliar` do plano 130-03), trata JSON inválido como "sem carimbo" (T-130-06-01, nunca deixa `json_decode` derrubar o comando), dispara quando não há carimbo OU o carimbo está mais velho que 26h (`CHAVE_LIMIAR_HORAS`/`DEFAULT_LIMIAR_HORAS`, 26h = 24h do ciclo diário + 2h de folga) OU o carimbo tem `erro` preenchido. Cooldown próprio (`rede_varredura_ultimo_alerta_em`) para não repetir a cada execução manual (T-130-06-03), audiência `AudienciaRedeSeguranca::adminsEComercial()` com `Log::warning` no canal `ecf-webhooks` quando vazia.
- **Bloco de comentário obrigatório** no topo do comando e no bloco de `routes/console.php`, em pt-BR, declarando por escrito a limitação estrutural da D-09: esta checagem detecta "o comando `clicksign:reconciliar` parou", NÃO detecta "o agendador do sistema operacional (`cron`/`schedule:run`) parou por inteiro", porque nesse cenário o próprio comando de verificação também para de rodar — monitorar o cron do SO é responsabilidade de infraestrutura, fora do escopo desta fase.
- Três entradas novas em `routes/console.php`, num bloco próprio ("Fase 130 — Rede de segurança Clicksign"): `clicksign:reconciliar` às 07:00, `clicksign:alertar-presos` às 07:30, `clicksign:verificar-varredura` às 08:00 — todos `withoutOverlapping()`, todos antes da cascata D-1 (`adman:sync` 11:00) para não disputar o bucket global de 3/min da Clicksign. Comentário do bloco justifica cada horário, repete a limitação estrutural da D-09 e lembra que a reconciliação é rede de segurança, nunca o mecanismo principal (conforme `REQUIREMENTS-v22.md` §"Out of Scope"). Nenhuma entrada pré-existente do arquivo foi alterada — `git diff` mostra só adição de linhas.
- 8 testes novos em `AutoMonitoramentoCarimboTest`: carimbo gravado por `clicksign:reconciliar` tem as chaves esperadas; sem carimbo alerta; carimbo de 2h não alerta; carimbo de 30h alerta; carimbo recente com erro alerta; JSON corrompido alerta e não explode; duas execuções seguidas do comando de verificação enviam só UM alerta (cooldown); audiência vazia não envia e não lança exceção.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Checagem de ausência da varredura (D-09)** - `eccb7b2b` (feat)
2. **Task 2: Agendamento diário dos três comandos (D-06)** - `6893d527` (feat)

## Files Created/Modified

- `app/Notifications/VarreduraParadaNotification.php` - construtor `(?string $executadoEm, ?string $erro)`, `montarMensagem()` privado cobrindo os 3 casos, `categoria: Categoria::MANUAL`, `url: null` (ação de infraestrutura, não de tela), `meta` com `executado_em`/`erro`/`fonte`
- `app/Console/Commands/ClicksignVerificarVarredura.php` - `$signature = 'clicksign:verificar-varredura'`, constantes `CHAVE_LIMIAR_HORAS`/`DEFAULT_LIMIAR_HORAS` (26h)/`CHAVE_ULTIMO_ALERTA`, docblock de topo com a limitação D-09 escrita literalmente
- `tests/Feature/Phase130/AutoMonitoramentoCarimboTest.php` - 8 testes cobrindo os 4 gatilhos, o cooldown e a audiência vazia, sempre reconsultando `Configuracao`/`Notification::fake()`
- `routes/console.php` - bloco "Fase 130 — Rede de segurança Clicksign" com as 3 entradas novas (07:00/07:30/08:00), nenhuma linha pré-existente alterada

## Decisions Made

Nenhuma decisão nova de domínio — D-06/D-09 já estavam travadas no `130-06-PLAN.md`. A única composição sem análogo direto no projeto (já sinalizada no `130-PATTERNS.md`) foi montar `ClicksignVerificarVarredura` combinando o padrão de leitura de `Configuracao` com o padrão de disparo de notificação de `ClicksignAlertarPresos` — não havia precedente exato de "comando que verifica ausência de outro comando" para copiar.

## Deviations from Plan

None - plano executado exatamente como escrito.

## Issues Encountered

`C:\xampp\php\php.exe artisan clicksign:verificar-varredura` isolado (fora da suíte de testes) falhou com `PDOException: Nenhuma conexão pôde ser feita` contra o MariaDB local — mesma instabilidade de ambiente já documentada em `.planning/learnings/` e registrada nos SUMMARY.md dos planos 130-01/130-04/130-05 (`mysqld` fora do ar). Não é regressão desta task: os 8 testes de `AutoMonitoramentoCarimboTest` (via SQLite, `RefreshDatabase`) provam o comando saindo com código 0 em todos os cenários, incluindo banco vazio (audiência vazia). `php artisan schedule:list` rodou normalmente e confirmou os três comandos nos horários corretos.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. Nenhum pacote novo instalado.

## Next Phase Readiness

- A rede de segurança da Fase 130 está fechada: reconciliação (130-03), alerta de contrato preso (130-05) e auto-monitoramento (130-06) rodam sozinhos, todo dia, sem intervenção manual.
- Suíte `Phase130` completa: 79/79 testes verdes (71 herdados dos planos 01-05 + 8 novos deste plano).
- Suíte `Phase129` reconfirmada: 80/80 testes verdes — nenhuma regressão.
- Pronto para o plano 130-07 (gate humano em sandbox — rodada real de assinatura), que não depende de nenhum artefato deste plano além do que os planos 130-01/03/05 já deixaram pronto.

## Self-Check: PASSED

Os 3 arquivos criados (`VarreduraParadaNotification.php`, `ClicksignVerificarVarredura.php`, `AutoMonitoramentoCarimboTest.php`) foram confirmados no disco, o arquivo modificado (`routes/console.php`) foi confirmado com `git diff` mostrando só adições, e os 2 commits de task (`eccb7b2b`, `6893d527`) foram confirmados em `git log`. `C:\xampp\php\php.exe artisan test --filter=Phase130` roda verde (79 testes, 302 assertions). `C:\xampp\php\php.exe artisan test --filter=Phase129` roda verde (80 testes, 235 assertions). `C:\xampp\php\php.exe artisan schedule:list` confirma os três comandos em horários distintos e crescentes (07:00 → 07:30 → 08:00).

---
*Phase: 130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-*
*Completed: 2026-08-13*
