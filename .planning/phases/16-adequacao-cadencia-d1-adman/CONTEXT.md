# Phase 16: Adequação à cadência D-1 da API Adman

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-05-27
**Depende de:** Phase 15 (módulo Sugadores estável), confirmações Adman (D-1 + 10 req/min)

## Goal

Reduzir chamadas à API Adman de **~2k/hora para ~168/dia** alinhando schedule, caches e UX ao fato de que a Adman é D-1 (atualiza 1× ao dia, às 10h BRT, com limite de **10 req/min por API key**). Eliminar o 429 crônico em produção sem perder funcionalidade.

## Confirmações da Adman (2026-05-27)

1. **API D-1**: dados atualizam 1× por dia, **às 10h BRT** (margem segura: 11h).
2. **Rate limit documentado**: **10 requisições por minuto por chave de API**. Se exceder → 429 Too Many Requests.
3. Não existe endpoint batch nem webhook — só REST + MCP, ambos D-1.

## Decisões já tomadas no scoping

- **Botão "Sincronizar agora"** (`AdmanController::syncNow` + rota `POST /adman/sync`): **REMOVER completamente** (UI + backend). Substituir pelos badges "Atualizado em DD/MM HH:mm · D-1 da Adman" nos mesmos pontos visuais.
- **Botão "Reanalisar"** no card de Sugadores (Phase 15): **bloquear se já houve `AdmanSyncLog` no dia atual** para a empresa; mostrar `"Análise diária já rodou hoje · próxima amanhã às 12h"`.
- **Horário do sync**: **11h BRT** (1h depois da Adman publicar D-1).
- **Throttle entre chamadas**: **7 segundos** (folga sobre 6s teórico para nunca passar de 10 req/min).
- **Cache TTLs runtime**: **24h** com chave incluindo `YYYY-MM-DD` para invalidar ao virar o dia.
- **Sem migration** de schema — só código.

## Mapa do estado atual (verificado 2026-05-27)

### Schedules na cascata D-0 (precisam reorganizar)

| Job | Cron atual | Cron proposto | Razão |
|---|---|---|---|
| `adman:sync` | `everyFiveMinutes()` | `dailyAt('11:00')` | Adman é D-1 — não faz sentido 5min |
| `adman:sync-faturamento` | `dailyAt('07:30')` | `dailyAt('11:30')` | Precisa dados do `adman:sync` |
| `calculate-goal-results` | `dailyAt('06:00')` | `dailyAt('11:45')` | Idem |
| `calculate-setor-goal-results` | `dailyAt('07:00')` | `dailyAt('11:55')` | Idem |
| `sugadores:analyze` | `dailyAt('06:30')` | `dailyAt('12:00')` | Depende do adman:sync |
| `sugadores:cleanup-quarentena` | `dailyAt('06:45')` | `dailyAt('12:30')` | Depende do analyze |
| `RefreshGrossBillingCacheJob` | `everyThirtyMinutes()` | `dailyAt('12:45')` | API é D-1 |
| `notifications:cleanup` | `dailyAt('04:00')` | mantém | Não usa Adman |
| `grants:sync-sftp` | `dailyAt('03:00')` | mantém | SFTP do ML, não Adman |
| `prune-pending-nps-surveys` | `daily()` | mantém | Não usa Adman |
| `checa-envio-relatorio-fechamento` | `everyMinute()` | mantém | Não usa Adman |

### Botões "Sincronizar agora" e rotas

| Local | Status |
|---|---|
| `app/Http/Controllers/AdmanController.php::syncNow()` | **REMOVER** método inteiro |
| `routes/web.php:144` `POST /adman/sync` → `[AdmanController, 'syncNow']` | **REMOVER** rota |
| UI: 8 JSX referenciam "Sincronizar" — precisam grep + ajuste (Financeiro, Dashboard/Admin, Mlb/Empresas, Mlb/Implementacao, Mlb/Publicacoes, Mlb/Vendas, Grants/Index, Meetings/Index) | Algumas são SFTP/Grants — separar Adman do resto antes de remover |
| `App\Jobs\SyncAdmanCompanyJob` | Mantém (continua sendo invocado pelo schedule diário) |

### Cache TTLs runtime atuais

| Método | TTL atual | TTL proposto |
|---|---|---|
| `AdmanService::fetchGrossBilling` (default `$cacheMinutes`) | 60 min | **24h** = 1440 min |
| `AdmanService::fetchAccountMetricsCached` (default) | 60 min | **24h** = 1440 min |
| `AdmanService::fetchGrossBillingsBatch` (default) | 30 min | **24h** = 1440 min |
| `AdmanService::syncMonthRevenue` (`cacheMinutes: 0`) | 0 (cold) | mantém 0 (caminho de sync — não cache) |
| Cache key | Não inclui data hoje | **incluir `YYYY-MM-DD`** para invalidar ao virar o dia |

### Throttle entre chamadas sequenciais

| Local atual | Throttle atual | Proposto |
|---|---|---|
| `App\Console\Commands\SyncAdmanData` (loop de empresas) | 1.5s (vi no commit `f0fdb44`) | **7s** |
| `RefreshGrossBillingCacheJob` (loop de empresas) | 1.5s | **7s** |
| `AnalyzeCompanySugadoresJob` | N/A (1 job por empresa) | N/A |
| `fetchPerformance` retry em 429 | exponencial 2s/4s/8s | mantém (já correto) |

### Arquivos referenciando "Sincronizar" (precisa grep prévio)

```
resources/js/Pages/Admin/Financeiro.jsx
resources/js/Pages/Dashboard/Admin.jsx
resources/js/Pages/Grants/Index.jsx           ← SFTP, NÃO Adman
resources/js/Pages/Meetings/Index.jsx          ← verificar (provável Google Calendar)
resources/js/Pages/Mlb/Empresas.jsx
resources/js/Pages/Mlb/Implementacao.jsx       ← provavelmente Mercado Livre, NÃO Adman
resources/js/Pages/Mlb/Publicacoes.jsx
resources/js/Pages/Mlb/Vendas.jsx
```

**Pitfall:** nem todo botão "Sincronizar" é Adman. Grants/SFTP, Meetings/Google e MLB/Mercado Livre têm seus próprios syncs. Plan deve filtrar APENAS callers de `AdmanController::syncNow` ou `/adman/sync` antes de remover.

## Success Criteria (citação do ROADMAP)

1. `Schedule::command('adman:sync')` → `dailyAt('11:00')` (cron `0 11 * * *`)
2. Cascata reorganizada: `adman:sync` (11:00) → `adman:sync-faturamento` (11:30) → `calculate-goal-results` (11:45) → `calculate-setor-goal-results` (11:55) → `sugadores:analyze` (12:00) → `sugadores:cleanup-quarentena` (12:30) → `RefreshGrossBillingCacheJob` (12:45, 1×/dia)
3. Constante `ADMAN_RATE_LIMIT_RPM = 10` documentada; throttle entre chamadas = **7s** em `SyncAdmanData` e `RefreshGrossBillingCacheJob`
4. Cache TTLs → **24h** + chave inclui `YYYY-MM-DD` (auto-invalida ao virar dia)
5. Botão "Sincronizar agora" + rota `POST /adman/sync` + método `AdmanController::syncNow` removidos; UI substitui por badge "Atualizado em DD/MM HH:mm · D-1 da Adman" lendo de `AdmanSyncLog`/`AdmanMetric`
6. Botão "Reanalisar" no card Sugadores bloqueado quando `AdmanSyncLog::whereDate('created_at', today())->where('company_id', $id)->exists()`; mensagem "Análise diária já rodou hoje · próxima amanhã às 12h"
7. Disclaimer "Dados D-1 da Adman" visível em Dashboard, Fechamento, cards Sugadores (tooltip explicativo)
8. Zero 429 em logs durante 24h pós-deploy

## Cross-cutting constraints

- **pt-BR** em comentários, mensagens flash e activity log
- `npm run build` obrigatório após cada edição JSX
- **Constante `ADMAN_RATE_LIMIT_RPM`** referenciada em todo throttle aplicado (rastreabilidade)
- **NÃO remover** `AdmanController::syncNow` antes de remover/migrar TODOS os callers no JSX (grep prévio obrigatório)
- Migration de schema **NÃO necessária** — só código
- Cache antigo expira naturalmente (TTL ≤ 60min) — novos TTLs aplicam-se a partir do próximo refresh
- Decisão de remover botão "Sincronizar agora" é **irreversível em UX**; ao executar Plan, confirmar que badge "última atualização" aparece nos mesmos pontos visuais

## Pitfalls antecipados

1. **Botões "Sincronizar" não-Adman**: Grants (SFTP do ML), Meetings (Google Calendar), Mlb/Implementacao (provável ML). Plan precisa de grep ANTES de remover, isolando apenas os que chamam `/adman/sync`.
2. **Caches já populados com TTL antigo**: ao subir o novo código, os caches antigos continuam com TTL de 60min/30min. Aplicação dos novos TTLs só acontece no próximo refresh. NÃO precisa limpar cache manualmente — basta esperar.
3. **`AdmanSyncLog` precisa estar populado**: o critério 6 (bloquear Reanalisar) depende de `AdmanSyncLog::whereDate('created_at', today())`. Confirmar que o `SyncAdmanCompanyJob` cria entrada em `adman_sync_logs` após cada execução (provavelmente sim — verificar em planning fase 1).
4. **Job em curso ao deploy**: se um `SyncAdmanCompanyJob` antigo está rodando no momento do deploy, ele continua com o código antigo. Sem ação especial — workers reiniciam pós-deploy e pegam código novo.
5. **Timezone**: Adman publica em horário "10h" — confirmar se é BRT ou UTC. Se for UTC, 11h BRT = 14h UTC. **Verificar com Adman**. Defensivo: o servidor roda em UTC e os crons usam `dailyAt('11:00')` que respeita o timezone do `app.timezone` — confirmar config.
6. **Throttle 7s × 168 empresas = ~19.6 min para sync completo** (sequential). Job worker precisa estar disponível durante esse tempo (sem timeout do supervisor).
7. **Adman MCP** (`AdmanMcpService` usado no drilldown de MLBs) tem `cachedFullScanIfReady` — verificar se também precisa subir TTL.
8. **Frontend cache do browser**: assets podem ficar 24h no CDN/browser. Botão "Sincronizar" pode aparecer pra usuário com cache antigo. Sem ação especial — eventualmente atualiza.

## Não-objetivos (out of scope)

- Não criar webhook nem polling externo — Adman não oferece
- Não migrar para outra fonte de dados (ML Direct API, etc.)
- Não tocar em syncs não-Adman (SFTP Grants, Google Meetings, ML Publicações)
- Não tocar em retry logic do `AdmanService::fetchPerformance` (já tem retry exponencial correto)
- Não criar nova UI de "Status do sync" (badge inline já resolve)
- Não tocar em `AnalyzeCompanySugadoresJob` lógica interna — só o trigger UI

## Referências adicionais

- [routes/console.php](routes/console.php) — schedules
- [app/Services/AdmanService.php](app/Services/AdmanService.php) — cache TTLs (linhas 255, 393, 513)
- [app/Http/Controllers/AdmanController.php](app/Http/Controllers/AdmanController.php) — `syncNow`
- [app/Jobs/SyncAdmanCompanyJob.php](app/Jobs/SyncAdmanCompanyJob.php) — fluxo de sync por empresa
- [app/Console/Commands/SyncAdmanData.php](app/Console/Commands/SyncAdmanData.php) — comando que roda o schedule
- [app/Models/AdmanSyncLog.php](app/Models/AdmanSyncLog.php) — fonte de "última sincronização"
- [.planning/codebase/INTEGRATIONS.md](.planning/codebase/INTEGRATIONS.md) — visão geral do Adman
- [.planning/STATE.md](.planning/STATE.md) — registro do reportar 429 ao provedor

## Memory persistente relevante

- **Adman tem 2 fontes** (sync agendado + MCP) — ambas D-1, ambas afetadas
- **Adman tem rate limit 10 req/min por chave** — confirmado pelo provedor em 2026-05-27
- **Lean planning** — pular discuss/research/plan-check, ir direto pro planner com CONTEXT.md detalhado
- **GSD output em pt-BR** — todos artefatos da fase em português
