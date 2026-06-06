# Phase 26: Webhooks completos ECF Drive (receiver HMAC, 6 eventos)

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-05
**Depende de:** Phase 20 (secret já no `.env`) + Phase 22 (wrapper para invalidar cache) + Phases 23/24/25 (consumidores que recebem push real-time)
**Milestone:** v8.0 — Integração Estratégica ECF Drive

## Goal

Substituir polling pelo **push em tempo real** dos 6 eventos do ECF Drive. Receiver HTTP POST em `/api/webhooks/ecf` valida HMAC SHA256, despacha Jobs assíncronos por tipo de evento, retorna 2xx em <100ms (requisito do parceiro — senão retenta).

**Por que esta fase importa:** as phases 23/24/25 entregaram visualização sob demanda (polling). Phase 26 transforma o sistema em **fluxo proativo** — sino acende quando ECF Drive detecta algo, tarefa comercial é criada automaticamente quando grant vai expirar, relatório mensal chega no email da liderança sem ninguém precisar checar.

## Origem da fase

API-GUIDE.md §11 documenta 6 eventos disponíveis:

| Event | Quando dispara | Ação esperada no ECF Admin |
|---|---|---|
| `sync.completed` | Sync SFTP terminou OK | Log + invalidar cache de files |
| `sync.failed` | Sync SFTP falhou | Notificação admin + log crítico |
| `etl.completed` | Arquivo processado | Invalidar cache de carteira/sellers |
| `grant.expirando` | 30/15/7d antes do vencimento | Criar notificação para time comercial + (futuro) tarefa |
| `signal.detected` | Cada signal novo (~07:30 UTC) | Invalidar cache + notificação no sino |
| `relatorio.gerado` | Mensal dia 5 às 09:00 UTC | Dispatch job que baixa relatório + (Phase 28) gera PDF |

Decisão do usuário em 2026-06-05 (via AskUserQuestion): **implementar tudo de uma vez** + usuário configura webhook no painel ECF Drive e me passa apenas o secret.

## Decisões já travadas

### D-01: Rota pública sem CSRF

`POST /api/webhooks/ecf` no grupo SEM `web` middleware (não passa CSRF). Equivalente ao padrão de `/implementacao/*` da Phase 13.

### D-02: Validação HMAC SHA256 obrigatória

Header `X-ECF-Signature` com formato `sha256=<hex>`. Cálculo: `HMAC_SHA256(rawBody, ECF_WEBHOOK_SECRET)`. Comparação **timing-safe** via `hash_equals` (não `==`, evita timing attacks).

### D-03: Idempotência via `webhook_deliveries` table

Migration nova com colunas: `id, event_id (UNIQUE), event_type, payload (json), received_at, processed_at, status (received|processed|failed), error_message`. Antes de despachar job, verifica `WHERE event_id = ?`. Janela: indefinida (mantém histórico completo para auditoria).

### D-04: Dispatch async via Jobs

Receiver NÃO faz trabalho — apenas valida HMAC, registra em `webhook_deliveries`, dispatcha Job apropriado e retorna `200 OK`. Cada evento tem seu Job:

- `HandleSyncCompletedJob`
- `HandleSyncFailedJob`
- `HandleEtlCompletedJob`
- `HandleGrantExpirandoJob`
- `HandleSignalDetectedJob`
- `HandleRelatorioGeradoJob`

### D-05: Eventos desconhecidos = log + 200 OK

Quando ECF Drive enviar evento novo que ainda não temos handler (futuro), responder 200 mas logar como `[ECF Webhook] Evento desconhecido: {tipo}` para visibilidade.

### D-06: Logging estruturado

Toda recepção registrada em `storage/logs/ecf-webhooks.log` (canal dedicado) com IP origem, evento, signature válida/inválida, tempo de processamento.

### D-07: Tratamento de cada evento (W2 — handlers)

| Evento | Job | Ação concreta |
|---|---|---|
| `sync.completed` | `HandleSyncCompletedJob` | Log apenas — informativo (W2-T1) |
| `sync.failed` | `HandleSyncFailedJob` | Notificação admin via tabela `notifications` (Phase 12), severity=critical (W2-T1) |
| `etl.completed` | `HandleEtlCompletedJob` | `Cache::forget` das chaves `ecf.signals.*`, `ecf.carteira.*` que ficaram stale após ETL (W2-T2) |
| `grant.expirando` | `HandleGrantExpirandoJob` | Notificação para usuários com permissão `core.grants` via Phase 12; payload contém `cust_id + cnpj + grant_fim + threshold` (W2-T3) |
| `signal.detected` | `HandleSignalDetectedJob` | Invalida cache `ecf.signals.*` + (opcional) cria notificação se severity=critical (W2-T4) |
| `relatorio.gerado` | `HandleRelatorioGeradoJob` | Log apenas nesta fase — Phase 28 implementará o PDF + email (W2-T5) |

### D-08: Rate limiting e proteção

Laravel `RateLimiter::for('ecf-webhook')` com 600 req/min por IP. Excedeu → 429. Defesa em profundidade contra spam/DDoS.

### D-09: ECF_WEBHOOK_SECRET

Já está em `.env.example` (vazio) desde Phase 20. Phase 26 W4 humano: usuário gera secret no painel ECF Drive + adiciona no `.env` do VPS.

### D-10: Secret NUNCA logado

`Log::info` jamais loga `$secret` nem `$rawBody` completo. Logs apenas: `event_type`, `event_id`, `signature_valid`, `processing_time_ms`.

## Schema da migration

```sql
CREATE TABLE webhook_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(255) NOT NULL UNIQUE,    -- vem do payload do ECF Drive
    event_type VARCHAR(64) NOT NULL,
    payload JSON NOT NULL,
    signature_valid BOOLEAN NOT NULL DEFAULT 0,
    received_at TIMESTAMP NOT NULL,
    processed_at TIMESTAMP NULL,
    status ENUM('received','processed','failed') NOT NULL DEFAULT 'received',
    error_message TEXT NULL,
    ip_address VARCHAR(45) NULL,
    INDEX idx_event_type (event_type, received_at),
    INDEX idx_status (status, received_at)
);
```

## Success Criteria

1. **Rota** `POST /api/webhooks/ecf` aceita sem CSRF, valida HMAC, retorna 200 em <100ms (sem trabalho síncrono).

2. **HMAC inválido** → 401 + log + NÃO grava `webhook_deliveries`.

3. **Idempotência:** mesmo `event_id` 2× → 200 OK no 2º mas job NÃO é despachado novamente.

4. **6 Jobs** em `app/Jobs/EcfWebhook/` despachados corretamente por tipo de evento.

5. **`webhook_deliveries`** persiste cada recepção válida com timestamp + status.

6. **`signal.detected`** invalida cache da Phase 23 + cria notificação se severity=critical → sino acende em tempo real.

7. **`grant.expirando`** cria notificações para usuários com permissão `core.grants` (Phase 12 dispatch). Payload `threshold` (30/15/7) destacado no título.

8. **`etl.completed`** invalida cache `ecf.carteira.*` (relevante para Phase 24 Painel Executivo).

9. **`sync.failed`** cria notificação crítica para admins.

10. **Testes Feature** mínimo 10:
   - HMAC válido + payload válido → 200 + job despachado
   - HMAC inválido → 401
   - HMAC ausente → 401
   - Idempotência (mesmo event_id 2×)
   - Evento desconhecido → 200 com log
   - 1 teste por handler (6 handlers)

11. **Smoke W4 humano:** usuário gera secret + configura webhook no painel ECF Drive. Eu testo localmente com `curl` simulando webhook real. Depois confirmo que o painel ECF Drive consegue disparar pra nós.

## Mapa de arquivos

### Backend novos
- `app/Http/Controllers/EcfWebhookController.php` — receiver
- `app/Models/WebhookDelivery.php`
- `database/migrations/2026_06_05_HHMMSS_create_webhook_deliveries_table.php`
- `app/Jobs/EcfWebhook/HandleSyncCompletedJob.php`
- `app/Jobs/EcfWebhook/HandleSyncFailedJob.php`
- `app/Jobs/EcfWebhook/HandleEtlCompletedJob.php`
- `app/Jobs/EcfWebhook/HandleGrantExpirandoJob.php`
- `app/Jobs/EcfWebhook/HandleSignalDetectedJob.php`
- `app/Jobs/EcfWebhook/HandleRelatorioGeradoJob.php`

### Backend modificados
- `routes/web.php` — adiciona rota fora do grupo `web` (sem CSRF)
- `config/logging.php` — adiciona canal `ecf-webhooks`
- `bootstrap/app.php` — `withRouting(then: ...)` configura prefix `/api/webhooks/*` exempt CSRF se necessário (Laravel 12 pattern)

### Testes novos
- `tests/Feature/Phase26/EcfWebhookControllerTest.php` (HMAC, idempotência, dispatch)
- `tests/Feature/Phase26/EcfWebhookHandlersTest.php` (1 teste por handler)

### Não tocar
- `EcfDriveService` (Phase 22)
- Polling existente na Phase 23 (`alertas_criticos_count` continua) — webhook complementa, não substitui

## Pitfalls antecipados

1. **HMAC com encoding errado**: ECF Drive pode mandar payload com BOM/whitespace diferente do que ele usou no cálculo. Sempre usar `$request->getContent()` (raw, sem parse JSON).

2. **`X-ECF-Signature` em case-sensitive**: Laravel normaliza headers, mas o formato `sha256=<hex>` precisa ser parseado corretamente.

3. **Job pesado bloqueando 200 OK**: NUNCA fazer trabalho no controller. Tudo via `dispatch()` async. Mesmo logging detalhado pode tirar de baixo de 100ms — mover para after-response middleware se necessário.

4. **Timezone do `received_at`**: usar `now()` UTC; comparações em handlers convertem para America/Sao_Paulo se relevante.

5. **Webhook spammed antes do app subir**: ECF Drive vai retentar 5× (2,4,8,16,32 min). Aceitar.

6. **Secret commitado por engano**: revisar antes de commit; `.env.example` mantém vazio.

7. **IP allowlist**: ECF Drive não documenta IP fixo. Sem allowlist por IP — HMAC é suficiente para autenticação.

## Não-objetivos

- Painel UI para gerenciar webhooks (lista, retry manual, edit) — fase futura se necessário
- Replay de webhooks via UI — admin pode disparar via tinker se precisar
- Phase 28 (PDF mensal a partir de `relatorio.gerado`)
- Substituir polling da Phase 23 (deixar conviver — polling é fallback se webhook cair)
- Webhooks de OUTROS sistemas (Adman, ML direto) — só ECF Drive

## Cross-cutting constraints

- pt-BR em comentários, mensagens, commits, log
- Sem `npm run build` (sem JSX nesta fase)
- Sem deploy automático (deploy direto via autorização permanente)
- Secret NUNCA no código nem em log
- HMAC timing-safe (`hash_equals`)
- Idempotência obrigatória
- 200 OK em <100ms

## Referências

- API-GUIDE.md §11 — Webhooks (cobertura completa)
- API-GUIDE.md §10.3 — Admin webhooks (criar/listar)
- Phase 12 — Notifications (dispatch para usuários por permissão)
- Phase 20 — `ECF_WEBHOOK_SECRET` já em `.env.example` vazio
- Phase 22 — Wrapper com chaves de cache invalidáveis

## Memory persistente relevante

- Lean planning
- Autorização permanente para deploy
- pt-BR
- Acertividade (push real-time bate com regra-mestra)
- Praticidade (sino acende → você age na hora, sem precisar abrir tela)
