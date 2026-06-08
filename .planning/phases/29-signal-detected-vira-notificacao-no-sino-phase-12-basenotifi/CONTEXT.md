# Phase 29: signal.detected vira notificação no sino

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-08
**Depende de:** Phase 8 (`BaseNotification` + tabela `notifications` + `databaseChannel`), Phase 10 (sino + polling + página `/notificacoes`), Phase 12 (dispatch pra usuários), Phase 26 (`HandleSignalDetectedJob` recebendo push), Phase 23 (lookup company por cust_id)
**Milestone:** v9.0 — Sistema de Notificações 2.0

## Goal

Quando o webhook `signal.detected` do ECF Drive chega no receiver da Phase 26 com severity=critical para empresa da NOSSA carteira, criar notificação real na tabela `notifications` da Phase 8 destinada a admin + consultor + mentor. O sino do header (Phase 10) automaticamente acende via polling do shared prop existente — **zero mudança no frontend**.

## Decisões já travadas (AskUserQuestion 2026-06-08)

1. **Severidades disparam:** apenas `critical` (manter sino limpo)
2. **Filtro de carteira:** apenas signals de empresas da NOSSA carteira (match cust_id contra `Company.adman_account_id` OR `ml_store_id`). Signals de alunos/lojistas externos não criam notificação — continuam acessíveis via `/alertas-estrategicos`.
3. **Destinatários:** admin + consultor + mentor (mesma audiência da Phase 23 `/alertas-estrategicos`). Coerente com o resto do sistema.
4. **Escopo MVP:** apenas `signal.detected`. Outros eventos (`grant.expirando`, `sync.failed`) ficam para Phase 30+.

## Decisões técnicas

### D-01: Categoria nova `ALERTA_ECF` na enum `Categoria`

Adicionar na enum `App\Enums\Categoria` (Phase 8). Discrimina visualmente alertas estratégicos das demais notificações (notif manual, metas atingidas, etc).

### D-02: Subclasse concreta `AlertaEcfNotification extends BaseNotification`

Para coerência com pattern existente (`MetaAtribuidaNotification`, `MetaAtingidaNotification`, `ManualNotification`, `EmpresaCadastradaNotification`). Aceita: `signal_id`, `event_type`, `cust_id`, `empresa_nome`, `payload_resumido` no construtor + chama `parent::__construct(titulo, mensagem, Categoria::ALERTA_ECF, meta=array)`.

### D-03: Título descritivo pt-BR por tipo de evento

Reusar as labels já traduzidas da Phase 23 (`TYPE_LABELS` em `AlertasController`). Padrão: `"{label_tipo} em {nome_empresa}"`. Ex:

- `seller.gmv_queda_mom` → **"Queda crítica de faturamento em RELOJOARIA WENUS"**
- `seller.queda_visitas` → **"Queda crítica de visitas em CAMILLO PARTS"**
- `seller.medalha_rebaixada` → **"Medalha rebaixada em LYAMDECOR"**
- `seller.score_critico` → **"Score crítico em IMPERIALECOMMERCEOFICIAL"**
- `seller.oportunidade_pads` → **"Oportunidade de Ads detectada em PREMIER INDÚSTRIA"** (esse seria info, NÃO entra nesta fase)

### D-04: Mensagem com payload resumido

Reusar a função `formatPayload` da Phase 23 (`AlertasController` PHP). Ex:
- `seller.gmv_queda_mom` → "GMV caiu 76,5% (R$ 47k → R$ 11k) em maio/2026"
- `seller.queda_visitas` → "Visitas caíram 65% (12k → 4k)"

### D-05: Link direto

`meta['link']` → `/alertas-estrategicos?company_id={X}` ou simplesmente `/alertas-estrategicos` na MVP (a página já permite filtrar). Decisão: `/alertas-estrategicos` simples + scroll/highlight do alerta específico fica para fase futura.

### D-06: Idempotência

Webhook `signal.detected` já tem idempotência por `event_id` no `WebhookDelivery` (Phase 26). Job só dispatcha 1×. Mas: o **mesmo signal** pode vir 2× se ECF Drive re-emitir (raro mas possível). Adicionar guard simples: `Notification::where('data->signal_id', $signalId)->exists()` antes de criar. Se existir, skip silencioso + log.

### D-07: Destinatários — query no momento do handler

`User::where(function($q) { $q->where('role', 'admin')->orWhere('role', 'consultor')->orWhere('role', 'mentor'); })->where('active', true)->get()`. Sem cache — handler roda raramente (~5/dia em prod).

### D-08: Filtro de carteira local

Antes de criar notification, lookup company por cust_id:
```php
$company = Company::where('active', true)
    ->where(fn($q) => $q->where('adman_account_id', $custId)
                       ->orWhere('ml_store_id', $custId))
    ->first();

if (!$company) {
    Log::channel('ecf-webhooks')->info('[Signal] Empresa fora da carteira — notificação não criada', [
        'cust_id' => $custId,
    ]);
    return;
}
```

### D-09: Payload do signal vindo do webhook

Vem dentro de `WebhookDelivery::payload` (JSON). Estrutura esperada (ver API-GUIDE.md §7):
```json
{
  "id": 91,
  "eventType": "seller.gmv_queda_mom",
  "custId": "1354156948",
  "severity": "critical",
  "periodKey": "202605",
  "payload": { "programa": "CPP", "gmv_atual": 11135.78, ... }
}
```

### D-10: Nenhuma mudança no frontend

Sino do header (Phase 10) já lê shared prop `notifications_count` + lista do polling endpoint. Categoria `ALERTA_ECF` aparece automaticamente. Cor/ícone diferenciado fica para fase futura se virar necessário.

## Success Criteria

1. **Enum `Categoria` ganha `ALERTA_ECF`** (case nova).

2. **`AlertaEcfNotification` criada** estendendo `BaseNotification`:
   - Construtor recebe: `signalId`, `eventType`, `custId`, `empresaNome`, `payloadResumido`, `link`
   - Chama parent com `titulo` + `mensagem` formatados + `categoria=Categoria::ALERTA_ECF` + `meta` com signal_id e link

3. **`HandleSignalDetectedJob` substituído**:
   - Substitui a parte "se severity=critical, só loga" por dispatch real
   - Lookup company por cust_id; se fora da carteira → log + return (sem notification)
   - Se já existe notification com mesmo `signal_id` → log + return (idempotência D-06)
   - Cria `AlertaEcfNotification` + `Notification::send($usuarios, $notif)` (databaseChannel da Phase 8)
   - Mantém log estruturado canal `ecf-webhooks`

4. **Testes Feature**:
   - Critical + empresa da carteira → notification criada para N usuários
   - Critical + empresa fora da carteira → 0 notifications + log
   - Warning/Info → 0 notifications (filtro severity)
   - Idempotência: 2× mesmo signal_id → 1 notification
   - Sino mostra notificação após Job rodar (props passa pra UI)
   - Mínimo 6 testes

5. **Smoke real em prod** (Phase 26 W4 humano já configurou o webhook):
   - Forçar 1 signal via curl simulando webhook ECF Drive
   - Ver notification aparecer no banco
   - Sino do header aceso quando admin/consultor/mentor logado

## Mapa de arquivos

### Backend novos
- `app/Notifications/AlertaEcfNotification.php`

### Backend modificados
- `app/Enums/Categoria.php` — adiciona case `ALERTA_ECF`
- `app/Jobs/EcfWebhook/HandleSignalDetectedJob.php` — substitui lógica (hoje só log) por dispatch real

### Testes novos
- `tests/Feature/Phase29/HandleSignalDetectedJobTest.php`
- `tests/Feature/Phase29/AlertaEcfNotificationTest.php` (smoke da subclass)

### Não tocar
- `BaseNotification` (Phase 8)
- Sino frontend (Phase 10)
- `NotificacaoController` (Phase 9/10)
- Outros 5 Jobs do `EcfWebhook/`
- `AlertasController` (Phase 23 não muda)

## Pitfalls antecipados

1. **`data->signal_id` no `where`** — sintaxe `whereJsonContains('data->signal_id', $id)` ou `where('data', 'like', ...)`. Mais simples: `where(DB::raw("JSON_EXTRACT(data, '\$.signal_id')"), $id)` no MySQL; ou usar `Eloquent::where('data->signal_id', $id)` que Laravel suporta direto.

2. **`signal_id` pode ser int** vindo da API (ex: 91). Cast para string ao gravar pra evitar inconsistência.

3. **Cust_id missing** em algum payload do ECF Drive → defensiva: se `$payload['custId']` for null, log e skip.

4. **Webhook chegando quando worker está down** → Phase 26 marca `failed` após `tries=3`. Notification não cria. Admin investiga via `failed_jobs`. Aceitável.

5. **Race condition**: 2 requests do mesmo webhook em paralelo — Phase 26 `WebhookDelivery::firstOrCreate` resolve no receiver. Job roda 1×.

## Não-objetivos

- Outros eventos (`grant.expirando`, `sync.failed`) — Phase 30 ou 31
- Severity warning/info — fase futura se houver demanda
- Filtro por carteira do consultor específico (notificar só consultor que cuida da empresa) — fase futura, exige resolver pivot `company_users`
- Cor/ícone diferenciado no sino — fase futura
- Som ou push browser real-time (WebSocket) — fora de escopo
- Configuração via UI (ativar/desativar tipos de signal) — fase futura

## Cross-cutting constraints

- pt-BR em tudo (título, mensagem, log)
- `npm run build` NÃO necessário (frontend não muda)
- Sem migration nova (tabela `notifications` da Phase 8 já cobre)
- Smoke em prod é gate humano blocking (deploy precisa de OK explícito do usuário — outro dev ativo)
- Reusar `BaseNotification`, `Categoria`, `databaseChannel` existentes
- Mantém log canal `ecf-webhooks` (não polui `laravel.log`)

## Referências

- Phase 8 — `BaseNotification` + tabela `notifications` + `databaseChannel`
- Phase 10 — sino do header + polling + `/notificacoes`
- Phase 12 — dispatch para usuários + pattern de subclasses
- Phase 23 — `AlertasController` com lookup company por cust_id + `formatPayload` (replicar lógica em PHP)
- Phase 26 — `HandleSignalDetectedJob` + `WebhookDelivery` idempotência
- API-GUIDE.md §7 — payload do signal

## Memory persistente relevante

- Lean planning
- pt-BR
- **PERGUNTAR antes do deploy** (v9.0 — outro dev ativo)
- Autorização permanente para push/comandos read-only
- Acertividade — notification só para nossa carteira (sem ruído)
- Praticidade — 1 click no sino → link direto pra ação
