# Phase 20: Integração ECF Drive (substitui sync SFTP por API HTTP)

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-05
**Depende de:** Phase 19 (sem dependência técnica — só sequencial no ROADMAP)
**Milestone:** v7.0

## Goal

Trocar a fonte de dados do módulo `/grants` (admin) do XLSX-via-SFTP para a API HTTP do **ECF Drive** — um sistema externo desenvolvido pelo usuário que abstrai o SFTP do Mercado Livre e expõe as informações como REST. A integração preserva o domínio existente (model `CompanyGrant`, página `/grants`, permissão `core.grants`) e substitui APENAS o pipeline de coleta.

## Origem da fase

Citação do usuário em 2026-06-05:

> "Vamos trabalhar agora na aba, consegui desenvolver o outro sistema que acessa SFTP do meli, pega as informações e transforma em API, vamos integrar ele."

O usuário forneceu briefing técnico completo com 8 passos cobrindo:
- Cadastro de API Key no painel do ECF Drive
- Variáveis de ambiente Laravel
- `EcfDriveService` (wrapper)
- Controller `/api/grants/*` (originalmente em Vue/cliente HTTP autônomo)
- Sync diário opcional
- Webhook receiver com HMAC SHA256

**Adaptado ao stack do ECF Admin (Laravel 12 + Inertia.js + React):**
- Substitui pipeline SFTP existente em vez de criar UI nova (página `/grants` já existe)
- Consume via `Inertia::render($props)` — não criar `/api/grants/*` paralelo
- Webhook real-time fica para fase futura (Phase 21)

## Pipeline atual (que será substituído)

```text
ML SFTP (arquivo XLSX)
   ↓ (cron 11h diário)
SyncGrantsFromSftp (PhpSpreadsheet)
   ↓
company_grants (tabela local)
   ↓
GrantController::index
   ↓
Grants/Index.jsx
```

Arquivos:
- [app/Console/Commands/SyncGrantsFromSftp.php](app/Console/Commands/SyncGrantsFromSftp.php) — comando `grants:sync-sftp`
- [routes/console.php:34](routes/console.php#L34) — schedule
- [config/filesystems.php](config/filesystems.php) — disk `ml_sftp`
- [config/services.php](config/services.php) — `ml_sftp.grants_file`
- composer dep `league/flysystem-sftp-v3` + `phpoffice/phpspreadsheet`

## Pipeline novo

```text
ECF Drive API (HTTPS)
   ↓ (cron diário + botão "Sincronizar agora")
EcfDriveService::listGrants() → ecf:sync-grants command
   ↓
company_grants (tabela local — fonte preservada)
   ↓
GrantController::index (inalterado em estrutura)
   ↓
Grants/Index.jsx (adiciona coluna segmento + status sync ECF)
```

## Decisões já travadas (via AskUserQuestion 2026-06-05)

1. **Escopo MVP:** apenas wrapper síncrono + sync diário local + UI. Webhook real-time fica como Phase 21.
2. **Posicionamento UI:** integrar à página `/grants` já existente — não criar página nova.
3. **Phase no ROADMAP:** Phase 20 da milestone v7.0 (Phase 19 segue em aberto pendente smoke W4 visual).
4. **SFTP antigo:** substituir direto (schedule aponta para o novo comando; código `SyncGrantsFromSftp` mantém no repo por +1 fase como rollback safety, mas não roda mais).
5. **Coluna `segmento`:** adicionar via migration (`segmento` nullable string) — útil para time comercial segmentar por nicho.
6. **Match `company_id`:** 1º cust_id (`companies.adman_account_id == ecf.custId`); 2º fallback CNPJ (normalizado, só dígitos); 3º se nenhum bater, registra em `storage/logs/grants-orfaos.log` para revisão manual.
7. **Webhook:** deferido para Phase 21. Mas configurar `ECF_WEBHOOK_SECRET` no `.env` desde já (vazio) para facilitar Phase 21.

## Mapeamento de campos API ECF Drive → company_grants

| API ECF Drive | DB local | Observação |
|---|---|---|
| `custId` | `ml_cust_id` (e lookup `company_id`) | Chave primária de match |
| `cnpj` | (lookup `company_id` via Company.cnpj, fallback) | Não persiste em company_grants — Company já tem |
| `razaoSocial` | (já em `Company.name`) | Não persiste em company_grants |
| `email` | `ml_email` | |
| `telefone` | `ml_phone` | |
| `grantInicio` | `granted_at` | API formato ISO-8601 |
| `grantFim` | `expires_at` | API formato ISO-8601 |
| `expirado` (bool) | `status` | true → `expired`; false → `active` (ou `pending` se `granted_at` futuro) |
| `segmento` | `segmento` (NOVO) | Migration W1-T1 |
| `diasParaExpirar` | (calculado via accessor `days_remaining`) | Não persiste — derivado de `expires_at` |

Campos da API ignorados: nenhum no MVP.

## Success Criteria

1. **Wrapper `EcfDriveService` operacional**: `ping()` retorna true em prod com `ECF_API_KEY` válida em `.env`; `listGrants()` paginated; `cliente(custId)` retorna detalhe único; cache 5min para `grantsExpirandoEm(dias)`.

2. **Comando `grants:sync-ecf {--dry-run}`**: substitui SFTP. Sem `--dry-run` é apply: paginate até esgotar, upsert em `company_grants` por `ml_cust_id`, match `company_id` (cust_id → CNPJ → log). Com `--dry-run`: imprime resumo (`N grants encontrados, M matched, K órfãos`) sem write.

3. **Schedule atualizado**: `routes/console.php` deixa de invocar `grants:sync-sftp` e passa a invocar `grants:sync-ecf` no mesmo horário (ou novo horário a definir no PLAN). `grants:sync-sftp` continua existindo no código mas não no schedule.

4. **Migration `segmento`**: coluna nullable string em `company_grants`; UI mostra como nova coluna na lista de grants.

5. **Match com fallback CNPJ**: 1º cust_id, 2º CNPJ normalizado (só dígitos), 3º log `storage/logs/grants-orfaos.log` com timestamp + payload do grant órfão para revisão manual.

6. **UI mostra origem da última sync**: header da página `/grants` informa "Última sincronização: HH:MM via ECF Drive" (substitui "via SFTP ML").

7. **Variáveis de ambiente**: `ECF_API_BASE`, `ECF_API_KEY`, `ECF_WEBHOOK_SECRET` (vazio) em `.env.example` e `config/services.php` mapeados.

8. **Testes Feature**: cobrir wrapper (Http::fake), comando sync (dry-run + apply + match + órfãos), migration segmento. Mínimo 6 testes.

## Mapa de arquivos relevantes

### Backend novos
- `app/Services/EcfDriveService.php` — wrapper HTTP (NOVO)
- `app/Console/Commands/SyncGrantsFromEcfDrive.php` — comando `grants:sync-ecf` (NOVO)
- `database/migrations/YYYY_MM_DD_HHMMSS_add_segmento_to_company_grants.php` (NOVO)
- `config/services.php` — adiciona `ecf.base`, `ecf.key`, `ecf.webhook_secret`
- `.env.example` — adiciona as 3 vars
- `app/Providers/AppServiceProvider.php` — bind singleton de `EcfDriveService`

### Backend modificados
- `app/Http/Controllers/GrantController.php` — `index()` passa `segmento` no map de grants; `syncRun`/`syncNow` chamam `grants:sync-ecf` em vez de `grants:sync-sftp`
- `app/Models/CompanyGrant.php` — `$fillable` inclui `segmento`
- `routes/console.php` — schedule passa a apontar para `grants:sync-ecf` (remover ou comentar entry antiga)

### Frontend modificado
- `resources/js/Pages/Grants/Index.jsx` — adiciona coluna `segmento` + label "via ECF Drive" no banner de sync

### Testes novos (em `tests/Feature/Phase20/`)
- `EcfDriveServiceTest.php` — `Http::fake` para `listGrants`, `cliente`, `ping`
- `SyncGrantsFromEcfDriveTest.php` — dry-run, apply, match cust_id, fallback CNPJ, órfãos
- `CompanyGrantSegmentoTest.php` — migration aplica, `$fillable` aceita `segmento`

### Não tocar (escopo bloqueado)
- `app/Console/Commands/SyncGrantsFromSftp.php` — mantém intacto (rollback safety)
- `config/filesystems.php` (disk `ml_sftp`) — mantém intacto
- composer deps `league/flysystem-sftp-v3` e `phpoffice/phpspreadsheet` — não remover
- Webhook (Phase 21)

## Pitfalls antecipados

1. **API ECF Drive offline durante sync**: o comando deve falhar gracefully (log + status JSON de erro) e não corromper a tabela. Wrapper já tem `retry(2, 500)` + timeout 15s.

2. **Match cust_id ambíguo**: empresas que mudaram de cust_id no Adman histórico. Mitigação: match por CNPJ normalizado (só dígitos), e log de divergência se 2+ empresas casarem por CNPJ.

3. **API key vazada no chat**: usuário colou `ecf_c7b9...` no histórico. Recomendação no SUMMARY: revogar essa key + gerar nova somente no `.env` do VPS, nunca commitar.

4. **Pagination edge case**: API retorna `data: []` na última página; loop precisa parar quando `count($r['data']) < $limit` (não `=== 0` exclusivamente).

5. **Coluna `segmento` na UI quebra layout responsivo**: a tabela `/grants` tem ~10 colunas. Adicionar 1 mais pode estourar largura em laptop. Mitigação: usar truncate + tooltip.

6. **Status `pending` desaparece**: o pipeline SFTP populava `status='pending'` para grants sem `expires_at`. API ECF Drive parece não ter esse conceito — todo grant tem `expirado: true|false`. Decisão no PLAN: como mapear grants "limbo".

7. **Cache stale**: cache 5min em `grantsExpirandoEm` pode dar diferença com tabela local recém-sincronizada. UI deve usar tabela local (autoritativa pós-sync), não chamadas API live.

8. **Rate limit ECF Drive?**: briefing não menciona. Confirmar com usuário no W4 antes de habilitar schedule mais agressivo que 1x/dia.

## Não-objetivos (out of scope)

- Webhook real-time `grant.expirando` (Phase 21)
- UI de gestão de webhooks (Phase 21+)
- Remoção do código `SyncGrantsFromSftp` + composer deps SFTP (Phase 21 ou 22)
- Refator da UI `Grants/Index.jsx` além de coluna `segmento` + label sync
- Mudança de permissões (`core.grants` continua)
- Sync da API ECF para outros recursos além de grants (ex: tokens, integrações)
- Reuso do `EcfDriveService` em outros módulos do ECF Admin nesta fase

## Cross-cutting constraints

- pt-BR em comentários, mensagens, commits
- `npm run build` obrigatório após cada JSX
- snake_case consistente nas props (Inertia)
- API key NUNCA no código — só `.env` do VPS
- Sem deploy automático
- Mantém `EnsureUserHasRole` + permissão `core.grants` no grupo de rotas
- Activity log via Spatie em `CompanyGrant` continua funcionando (já incluído)

## Referências

- Phase 19 PLAN.md — padrão de testes Feature + commit per task
- Phase 18.5 — padrão de comando one-shot com `--dry-run` (`dashboard:import-marketplace-from-csv`)
- Phase 16 — padrão de cache D-1 + scheduler em `routes/console.php`
- Briefing técnico fornecido pelo usuário 2026-06-05 (8 passos cobrindo API key, .env, service, controller, frontend, sync diário, webhook, smoke)
- Memory: [feedback_project_priorities](MEMORY.md) — regras acertividade + praticidade
- Memory: [feedback_lean_planning](MEMORY.md) — pular discuss/research; ir direto ao plan

## Memory persistente relevante

- **Lean planning** — pular discuss/research/plan-check
- **GSD output em pt-BR**
- **Substituir SFTP direto** (decidido) — não tentar manter compatibilidade dual durante sync
