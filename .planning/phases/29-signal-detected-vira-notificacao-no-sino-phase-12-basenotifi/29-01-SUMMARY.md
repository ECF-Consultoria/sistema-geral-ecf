---
phase: 29-signal-detected-vira-notificacao-no-sino-phase-12-basenotifi
plan: 01
subsystem: notifications
tags: [webhook, signal, notifications, queue, jobs]
dependency_graph:
  requires:
    - 08-01  # BaseNotification + tabela notifications + Categoria enum
    - 10-01  # Sino do header + polling shared prop
    - 12-01  # Pattern subclasses concretas (ManualNotification etc)
    - 23-01  # AlertasController::TYPE_LABELS + lookup Company por cust_id
    - 26-01  # HandleSignalDetectedJob + WebhookDelivery
  provides:
    - Categoria::ALERTA_ECF — novo case na enum para discriminar alertas do ECF Drive
    - AlertaEcfNotification — subclasse concreta de BaseNotification para signals críticos
    - HandleSignalDetectedJob pipeline real — filtro severity → carteira → idempotência → Notification::send
  affects:
    - tabela notifications (cria linhas via Notification::send canal database)
    - webhook_deliveries (atualiza status para processed em todos os paths)
tech_stack:
  added: []
  patterns:
    - Subclasse concreta de BaseNotification (padrão Phase 11/12)
    - Guard de idempotência via DatabaseNotification::where('data->meta->signal_id')
    - Filtro de carteira local via Company::where(adman_account_id OR ml_store_id)
    - Notification::send com canal database (async via queue worker)
key_files:
  created:
    - app/Notifications/AlertaEcfNotification.php
    - tests/Feature/Phase29/HandleSignalDetectedJobTest.php
    - tests/Feature/Phase29/AlertaEcfNotificationTest.php
  modified:
    - app/Notifications/Categoria.php         # adiciona case ALERTA_ECF
    - app/Jobs/EcfWebhook/HandleSignalDetectedJob.php  # substitui stub Phase 26
decisions:
  - "TYPE_LABELS duplicado em constante privada AlertaEcfNotification (espelho AlertasController) — duplicação consciente documentada, evita acoplamento Job→Controller"
  - "Idempotência via data->meta->signal_id (não data->signal_id) — payload canônico BaseNotification aninha tudo em meta"
  - "Cast (string) em signalId e custId em todos os pontos — API ECF Drive envia int mas armazenamos string para compat MySQL/SQLite"
  - "Marca delivery processed em TODOS os return paths — severity não-critical, custId null, fora carteira e já notificado são sucesso operacional, não falha"
  - "Notification::send (não sendNow) — Job já está em worker, forçar síncrono desnecessário"
metrics:
  duration: "~25 minutos (W1+W2+W3)"
  completed: "2026-06-08"
  tasks_completed: 3
  tasks_total: 4  # W4 é checkpoint humano blocking
  files_created: 3
  files_modified: 2
  tests_passing: 21
  test_assertions: 51
---

# Phase 29 Plan 01: signal.detected vira notificação no sino — SUMMARY (W1+W2+W3)

**One-liner:** Pipeline webhook signal.detected com filtro de carteira, idempotência por signal_id e Notification::send para admin+consultor+mentor via AlertaEcfNotification extends BaseNotification.

## Status

W1 (backend core) + W2 (job integration) + W3 (testes) — **CONCLUÍDAS**.
W4 (smoke real em prod com curl + sino no browser) — **PENDENTE** (checkpoint humano blocking).

## O que foi construído

### W1-T1 — Categoria::ALERTA_ECF (`app/Notifications/Categoria.php`)

Adicionado case `ALERTA_ECF = 'alerta_ecf'` na enum Categoria. Discrimina alertas
estratégicos do ECF Drive das demais notificações (manual, meta atribuída, meta atingida).
Adição não-breaking: Phases 9+ usam `Categoria::from()` para hidratar do banco.

### W1-T2 — AlertaEcfNotification (`app/Notifications/AlertaEcfNotification.php`)

Subclasse concreta de `BaseNotification` seguindo o padrão Phase 11/12. Construtor com
6 parâmetros: `signalId`, `eventType`, `custId`, `empresaNome`, `payloadResumido`, `link`.

- Título: `"{label} em {empresa}"` via `TYPE_LABELS` privado (espelho do AlertasController Phase 23
  com prefixo "crítica" nos tipos gmv_queda_mom e queda_visitas)
- Mensagem: helper privado `formatPayload()` replica `AlertaCard.jsx` Phase 23 em PHP
- Meta canônico: `signal_id` (string), `event_type`, `cust_id`, `link`, `severity=critical`
- Helpers: `fmtBRL`, `fmtPct`, `fmtInt` formatam pt-BR (vírgula decimal, milhar com ponto)
- Não sobrescreve `via()` nem `toArray()` — herda 100% do BaseNotification

### W2-T1 — HandleSignalDetectedJob pipeline real (`app/Jobs/EcfWebhook/HandleSignalDetectedJob.php`)

Substitui o stub Phase 26 (BaseNotification anônima apenas para admin, sem filtro de carteira,
sem idempotência). Pipeline completo:

1. Extrai `severity`, `eventType`, `custId`, `signalId`, `inner` do `payload['data']`
2. Invalida cache `ecf.signals.*` (independente da severity — Pattern Phase 23)
3. Filtro severity: apenas `critical` prossegue → warning/info: log + processed + return
4. Filtro custId nulo: log + processed + return
5. Lookup Company: `where('active', true)->where(adman_account_id OR ml_store_id, custId)` com cast `(string)`
6. Guard idempotência: `DatabaseNotification::where('data->meta->signal_id', signalId)->exists()`
7. Query destinatários: `User::whereIn('role', ['admin','consultor','mentor'])->where('active', true)`
8. `Notification::send($usuarios, new AlertaEcfNotification(...))`
9. delivery processed + log estruturado com contagem de destinatários

`tries=3`, `timeout=60` e `failed()` hook preservados da Phase 26.

### W3-T1 — Testes Feature Phase29

**21 testes verdes, 51 assertivas** em 2 suites:

`HandleSignalDetectedJobTest` (8 testes):
- critical + empresa na carteira → notification para admin/consultor/mentor
- critical + empresa fora da carteira → 0 notifications + delivery processed
- severity warning → 0 notifications
- severity info → 0 notifications
- idempotência: signal_id já existe → 0 dispatch novo
- roles fora da whitelist não recebem
- custId nulo → 0 notifications
- match por ml_store_id funciona

`AlertaEcfNotificationTest` (13 testes):
- 6 chaves canônicas do toArray()
- categoria = 'alerta_ecf'
- título via TYPE_LABELS + nome da empresa
- mensagem com valores formatados pt-BR
- meta.signal_id é string (não int)
- url = '/alertas-estrategicos'
- via() retorna ['database']
- 5 event_types com formatação específica (gmv_queda_mom, queda_visitas, medalha_rebaixada, score_critico, oportunidade_pads)
- fallback para event_type desconhecido
- meta com todas as chaves canônicas (signal_id, event_type, cust_id, link, severity)

## Commits desta phase

| Hash      | Tipo       | Descrição                                              |
|-----------|------------|--------------------------------------------------------|
| d060c09   | feat(29-01) | adiciona case ALERTA_ECF na enum Categoria             |
| 5da6e4e   | feat(29-01) | cria AlertaEcfNotification extends BaseNotification   |
| b798fec   | feat(29-01) | reescreve HandleSignalDetectedJob com pipeline real   |
| 3553eb3   | test(29-01) | suite Feature Phase29 — 21 testes verdes              |

## Deviations from Plan

None — plano executado exatamente como escrito. Os 4 tipos de event_type documentados no
PLAN mais o fallback genérico foram todos cobertos nos testes.

## W4 Pendente — Checkpoint humano blocking

**O que falta:** Smoke real em prod (curl simulando webhook ECF Drive + sino no browser).

**Passos do W4 para o orquestrador:**

1. Push dos commits desta branch para o remote
2. **PERGUNTAR autorização explícita do usuário antes de qualquer deploy** (memory `feedback_perguntar_antes_deploy_v9` ativo — outro dev pode estar em paralelo)
3. Após OK explícito: executar `deploy.sh` ou `deploy_parcial.sh`
4. Em prod via SSH/tinker: descobrir cust_id real de empresa da carteira
   ```
   Company::where('active', true)->whereNotNull('adman_account_id')->first(['name','adman_account_id'])
   ```
5. Disparar curl simulando webhook ECF Drive (payload + HMAC conforme API-GUIDE §7)
6. Validar via tinker em prod:
   ```
   DatabaseNotification::where('data->meta->signal_id', '<signal_id>')->count()
   // deve retornar 3 (admin + consultor + mentor)
   ```
7. Verificar sino do header no browser: badge incrementado, clique mostra título pt-BR,
   link leva para `/alertas-estrategicos`
8. Bonus: disparar 2 curls com mesmo signal_id → confirmar idempotência em prod

## Known Stubs

Nenhum. Toda a lógica está implementada. A W4 (smoke em prod) é gate humano de validação,
não um stub — os dados reais do webhook validarão o comportamento em produção.

## Self-Check: PASSED

Arquivos verificados:
- app/Notifications/Categoria.php — case ALERTA_ECF presente
- app/Notifications/AlertaEcfNotification.php — criado (163 linhas)
- app/Jobs/EcfWebhook/HandleSignalDetectedJob.php — pipeline real implementado
- tests/Feature/Phase29/HandleSignalDetectedJobTest.php — 8 testes
- tests/Feature/Phase29/AlertaEcfNotificationTest.php — 13 testes
- 21 testes verdes confirmados via `php artisan test --filter=Phase29 --testdox`

Commits verificados:
- d060c09, 5da6e4e, b798fec, 3553eb3 — todos presentes no log do git
