---
slug: audit-performance-lentidao
status: root_cause_found
trigger: Sistema muito lento — login demora MINUTOS pra autenticar; Dashboard MercadoLivre e /performance também travadas
created: 2026-07-10
updated: 2026-07-10
criticality: alta
---

# Auditoria: lentidão sistêmica no ECF Admin

## Symptoms

**Expected behavior:**
Login em segundos, páginas carregando em <2s, dashboard renderizando <5s.

**Actual behavior:**
- Login demora **minutos** pra autenticar e liberar acesso
- Dashboard Mercado Livre lenta
- Página /performance lenta
- Sensação de lentidão sistêmica em todo carregamento

**Error messages:**
Nenhum erro visível — só demora. Nos logs recentes há:
- `[MLB SyncPub] cURL error 28: Operation timed out after 120000 milliseconds` na Adman API
- `RefreshGrossBillingCacheJob has timed out`
- Rate limit 429 na Adman e Mercado Livre em massa hoje

**Timeline:**
Detectado 2026-07-10. Sem timestamp exato de quando começou a piorar.

**Reproduction:**
1. Acessar `admin.ecfconsultoria.com.br/login`
2. Fazer login com credenciais válidas
3. Cronometrar até dashboard aparecer — usuário reporta minutos

## Contexto técnico

- Laravel 12 + Inertia + React
- MySQL/MariaDB no VPS (não corrompido no VPS, só local)
- `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`
- HandleInertiaRequests roda em toda request e injeta `auth.user`, `sugadores_pendentes` (badge global)
- Adman API + Google Calendar + ML OAuth são fontes externas frequentemente chamadas
- Supervisor gerencia 2 queue workers (`ecf-worker:00`, `ecf-worker:01`)
- Timeouts na Adman API já vistos várias vezes hoje (rate limit 429 + timeouts 120s)

## Hipóteses iniciais (a serem testadas)

1. **HandleInertiaRequests com query pesada** — middleware injeta dados globais em toda request. `sugadores_pendentes` badge ou `buildUserPayload` pode ter N+1
2. **Chamada externa síncrona bloqueante** — algum middleware/service chamando Adman/ML/Google no bootstrap sem timeout curto; se remoto lento, todo request trava
3. **Queue worker travado** — RefreshGrossBillingCacheJob deu timeout (visto no log); worker pode estar segurando conexões DB/cache/lock
4. **Sessions table sem índice ou muito grande** — SELECT/UPDATE por sess_id lento
5. **Cache Adman em MISS massivo** — cache expirado + N empresas → N calls remotas síncronas
6. **DB overloaded** — MariaDB no VPS pode estar saturado (long queries, deadlocks, cache_key duplicado)
7. **Ziggy expondo 500+ rotas** em cada payload Inertia
8. **Logs.log gigante** — Laravel flush em arquivo enorme lento
9. **Snapshot job de desempenho rodando em foreground durante request** — SnapshotDesempenhoScores schedule pode bater com dashboard

## Current Focus

- **hypothesis:** CONFIRMADA — ver Resolution abaixo.
- **next_action:** nenhuma (goal=find_root_cause_only; fix não aplicado nesta rodada)
- **test:** benchmark direto via tinker no VPS reproduziu 70s cold vs 0.66s warm — ver Evidence
- **expecting:** concluído

## Escopo da auditoria

- Identificar o gargalo do LOGIN (crítico — user espera minutos)
- Perfilar Dashboard MercadoLivre
- Perfilar /performance
- Verificar se middleware/service está fazendo call externa sem timeout adequado
- Ver se supervisor workers estão OK ou travados
- Confirmar se sessions/cache table têm índice adequado
- **NÃO propor skeleton loading nesta rodada** — se causa é backend, skeleton é maquiagem

## Arquivos suspeitos

- `app/Http/Middleware/HandleInertiaRequests.php` (share global)
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/PerformanceController.php`
- `app/Services/AdmanService.php` (cache TTL + external HTTP)
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php` (middleware pipeline)
- `config/session.php`, `config/cache.php`, `config/queue.php`
- `routes/console.php` (schedule)

## Evidence

- timestamp: 2026-07-10T14:56 — **Estado de sistema VPS (via plink):** `supervisorctl status` → ambos workers `RUNNING`, uptime 26min (restart recente, provável do deploy do hotfix `19d0dc4` às 14:29 — `bootstrap/cache/*.php` timestamps confirmam `config:cache` rodado às 14:29). `top`: CPU 95% idle, load average 0.91/0.97/1.01 (ok pra 4+ cores). `free -h`: 15GB total, 12GB available. `df -h`: disco 30% usado. **Conclusão: recursos de sistema (CPU/RAM/disco) NÃO são o gargalo.**

- timestamp: 2026-07-10T14:58 — **`storage/logs/laravel.log` tem 550.210 linhas.** Grande mas não é a causa raiz (append em arquivo é O(1) no Linux); é sintoma colateral da falta de rotação de log — merece hotfix de higiene à parte, não é o gargalo de request.

- timestamp: 2026-07-10T15:00 — **Descoberta de config real de produção:** `.env` do VPS tem `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis` — DIVERGE da documentação do projeto (CLAUDE.md/arquitetura dizem `database` como driver de cache/session/queue). Sessions ficam em MySQL mesmo assim (tabela custom), mas cache/queue reais são Redis. Isso invalida a hipótese 4 (sessions table) — tabela MySQL `sessions` tem só 7 linhas com índices corretos (`PRIMARY`, `sessions_user_id_index`, `sessions_last_activity_index`) — não é gargalo. `cache` (MySQL) e `jobs` (MySQL) ficam vazios/0 permanentemente porque não são usados — driver real é Redis (`REDIS_CACHE_DB=2`, `REDIS_DB=1` fila).

- timestamp: 2026-07-10T15:02 — **Redis operacional:** `DBSIZE` db1(fila)=30 chaves, db2(cache)=8948 chaves. `keyspace_misses=10.216.162` vs `keyspace_hits=868.637` histórico (proporção de miss MUITO alta desde sempre) — consistente com padrão de cache-miss frequente em produção.

- timestamp: 2026-07-10T15:04 — **`failed_jobs` (MySQL, usado independente do driver de fila) = 549 registros.** Confirma histórico de falhas recorrentes em jobs (RefreshGrossBillingCacheJob e outros) batendo timeout/rate-limit, alinhado com o trigger reportado.

- timestamp: 2026-07-10T15:05 — **`app/Http/Controllers/DashboardController.php::adminDashboard()` (linhas 748-804) e `app/Http/Controllers/PerformanceController.php::index()` (linhas 98-112) chamam `DesempenhoScoreService::compute($user, $mes)` SEQUENCIALMENTE, em loop, para TODOS os users com cargo analista/estrategista (11 hoje), TODA VEZ que a página carrega** (sem cache do resultado agregado — só os componentes internos de `compute()` têm cache parcial). `compute()` roda em: `/dashboard` (admin/líder), `/dashboard/mercadolivre` (mesma branch admin/líder via `mercadolivre()`→`index()`), e `/performance` (ranking). Login de um admin redireciona direto pra `/dashboard` → cai nessa mesma rota cara.

- timestamp: 2026-07-10T15:06 — **`app/Services/DesempenhoScoreService.php::computeVarFaturamento()` (linhas 316-463)** — para cada empresa qualificada da carteira do user, se `MetricsProviderFactory::caseFor($company)` ∈ {`ambos`,`so-ml`}, chama `MlMetricsProvider::readForCompany()` **DUAS VEZES por empresa** (janela atual + janela anterior). `MlMetricsProvider::readForCompany()` (linhas 82-97) usa `Cache::remember` com **TTL de apenas 900s (15min)** sobre `fetchFromApi()`, que dispara **2 chamadas HTTP síncronas por janela** (`MercadoLivreService::fetchOrdersSummary` + `fetchAdsSummary`) — ou seja, até **4 HTTP calls síncronas por empresa** em cache miss. `fetchOrdersSummary` (linhas 411-467) ainda pagina em blocos de 50 pedidos (até 1000/dia) — empresas de alto volume multiplicam ainda mais as chamadas.

- timestamp: 2026-07-10T15:08 — **BENCHMARK DIRETO no VPS (via tinker, reproduzindo exatamente o loop de `adminDashboard`/`PerformanceController::index`) — COLD CACHE (TTL de 15min do `unified_metrics` expirado):**
  ```
  Total users (analista+estrategista): 11
  user=3  (Luiz Henrique):   5.764ms   (24 empresas)
  user=10 (Gabriela Aguiar): 15.789ms  (18 empresas)
  user=11 (Nathalia Martins):35.754ms  (36 empresas)  ← PIOR CASO
  user=12 (Débora Lima):        33ms   (5 empresas)
  user=15 (Danilo):           5.781ms  (25 empresas)
  user=16 (Gustavo):          4.674ms  (13 empresas)
  user=17 (Ana Julia):           85ms  (23 empresas)
  user=18 (Stefani):             859ms (23 empresas)
  user=19 (Douglas):           1.437ms (17 empresas)
  user=20 (Rubens):               58ms (25 empresas)
  user=21 (Felipe):               19ms (3 empresas)
  TOTAL: 70.253ms (70,25 SEGUNDOS) — 1.219 queries DB no total
  real  1m10.424s / user 0m11.989s / sys 0m0.816s
  ```
  `user+sys` (CPU) = ~12,8s vs `real` = 70,4s → **~58s foi I/O WAIT em chamadas de rede externas (ML API)**, não CPU nem DB local.

- timestamp: 2026-07-10T15:10 — **MESMO BENCHMARK repetido imediatamente depois (WARM CACHE, TTL 15min ainda válido):**
  ```
  TOTAL: 660,4ms (0,66 SEGUNDOS) — mesmas 1.219 queries DB
  real  0m0.811s / user 0m0.368s / sys 0m0.142s
  ```
  **Fator de diferença: 106x (70.253ms → 660ms)** com o MESMO número de queries DB nos dois casos → confirma que o custo de DB (1.219 queries) é desprezível (~0,5-0,8s) e que **100% da diferença de 69,6s é causado pelas chamadas HTTP síncronas à API do Mercado Livre feitas dentro de `computeVarFaturamento()`**.

- timestamp: 2026-07-10T15:12 — **Log de produção corrobora rate-limit em massa:** 170 ocorrências de `"Rate limit ML excedido para seller unknown (60/min)"` nas últimas 5.000 linhas do `laravel.log` (módulo `[Sugadores]`, mas confirma que o limite de 60 req/min da API ML está sendo estourado repetidamente hoje por múltiplas fontes concorrentes no sistema) + 8 ocorrências de `"timed out"`.

## Eliminated

- **Hipótese 3 (queue worker travado)** — REFUTADA. Workers `RUNNING`, uptime normal, `jobs` (fila real em Redis) com só 30 chaves — sem acúmulo. `failed_jobs`=549 é histórico acumulado, não é fila travada agora.
- **Hipótese 4 (sessions table sem índice/gigante)** — REFUTADA. 7 linhas, 3 índices corretos (PRIMARY, user_id, last_activity).
- **Hipótese 6 (DB/MariaDB overloaded)** — REFUTADA. `Threads_connected=4`, `Threads_running=2`, sem queries longas travadas no `SHOW PROCESSLIST` (só `Sleep` e a própria introspecção). CPU do host 95% idle.
- **Hipótese 8 (log gigante causando lentidão de request)** — REFUTADA como causa raiz (é sintoma/débito técnico à parte — merece rotação, mas não explica os segundos/minutos de espera).
- **Hipótese 1 (HandleInertiaRequests com N+1 pesado)** — PARCIALMENTE REFUTADA como causa PRINCIPAL. O middleware roda `countSugadoresPendentes()`, `unreadNotifications()->count()` e `countAlertasCriticos()` (cache 5min) em TODA request — são queries/cache leves, contribuem poucos ms, não segundos. Não é a fonte da lentidão de minutos.
- **Hipóteses 2/5/7/9** — não confirmadas isoladamente; a causa real (abaixo) é uma variante mais específica e mensurável da hipótese 5 (cache miss em massa), mas localizada precisamente no `MlMetricsProvider` dentro do `DesempenhoScoreService`, não no `AdmanService` genérico (que já tem TTL de 24h e batch-read só-cache, portanto não bloqueia request).

## Resolution

### ROOT CAUSE FOUND (CONFIRMADO com medição direta, não plausibilidade)

**O quê:** `DashboardController::adminDashboard()` e `PerformanceController::index()` chamam `DesempenhoScoreService::compute()` **sincronamente, em loop, para todos os 11 usuários analista/estrategista, em TODA carga de página** (`/dashboard`, `/dashboard/mercadolivre` para admin/líder, `/performance`). Dentro de `compute()`, o método `computeVarFaturamento()` consulta `MlMetricsProvider::readForCompany()` para cada empresa ML da carteira de cada user — 2x por empresa (janela atual + anterior). Esse provider cacheia via Redis com **TTL curto de apenas 15 minutos**; em cache-miss (que acontece a cada 15min, ou seja, RECORRENTE, não um evento isolado), dispara HTTP síncrono real à API do Mercado Livre (`fetchOrdersSummary` + `fetchAdsSummary`, com paginação adicional em contas de alto volume).

**Números medidos (não estimados):**
- Cold cache (TTL expirado): **70,25 segundos** para processar os 11 usuários (pior caso individual: Nathalia Martins, 36 empresas, 35,75s sozinha).
- Warm cache (TTL válido): **0,66 segundos** — mesmas 1.219 queries DB nos dois casos.
- **Diferença = 69,6s = 100% atribuível a chamadas HTTP síncronas à API ML**, não a DB, não a CPU, não a recursos de sistema.
- Log de produção mostra 170 erros de rate-limit ML (60/min) nas últimas 5.000 linhas — evidência de que múltiplas fontes (Sugadores + este loop de Dashboard/Performance) competem pelo mesmo limite de 60 req/min, o que pode fazer o cache-miss custar ainda mais (retries/backoff) do que os 70s medidos em condição isolada.

**Por que explica os 3 sintomas:**
1. **Login demora minutos** — login não é lento em si; o REDIRECT pós-login de um admin/líder cai direto em `/dashboard` → `adminDashboard()` → dispara o loop de 11 users. Se o TTL de 15min tiver expirado (o que ocorre ~4x por hora, de forma recorrente), o usuário espera até ~70s+ (mais se houver contenção de rate-limit).
2. **Dashboard Mercado Livre lenta** — mesma rota (`mercadolivre()` delega pra `index()` → `adminDashboard()` pro papel admin/líder).
3. **/performance lenta** — `PerformanceController::index()` roda o MESMO loop (linha 98-112) pro ranking da equipe.

**Gargalo top 3 concreto (por camada, com números):**
1. **External (Mercado Livre API, síncrono, sem pool/batch)** — ~69,6s de 70,25s totais (99%) em cache-miss; TTL de cache de apenas 15min garante que isso se repete continuamente ao longo do dia úteis (~4x/hora).
2. **Application logic (N+1 lógico, não é N+1 de query mas N×M de chamada externa)** — `compute()` é chamado 1x por user (11x) × até 36 empresas por user × 2 janelas × até 2 endpoints ML = até ~150+ chamadas HTTP sequenciais numa única request, sem paralelização (`Http::pool`) e sem cache compartilhado entre requests de curta duração.
3. **Query DB (não é gargalo, mas alto volume)** — 1.219 queries numa única request do Dashboard/Performance; desprezível em tempo (~0,5-0,8s) mas indica ausência de eager-loading/batch em `computeUniverso`/`computeNpsMedio`/`computeVarFaturamento`/`computeVarMargem` (cada chamados 1x por user × componente).

**Fix (NÃO aplicado nesta rodada — goal=find_root_cause_only):**
Fica para próximo ciclo/planning. Direções candidatas identificadas (não implementadas): (a) aumentar TTL do cache `unified_metrics` do MlMetricsProvider e/ou pré-aquecer via job assíncrono (padrão já usado pelo `RefreshGrossBillingCacheJob` do AdmanService); (b) cachear o RESULTADO agregado de `DesempenhoScoreService::compute()` por user+mês (não só os componentes internos) com invalidação por job/cron; (c) paralelizar chamadas ML via `Http::pool()` quando múltiplas empresas precisam ser buscadas na mesma request.
