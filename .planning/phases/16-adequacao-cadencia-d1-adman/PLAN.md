# Phase 16: Adequação à cadência D-1 da API Adman — Plano

**Modo:** lean planning (sem discuss / sem research — CONTEXT.md detalhado)
**Mode:** mvp
**Depende de:** Phase 15 estável + confirmações Adman (D-1 + 10 req/min)
**Total estimado:** 3 plans, 3 waves sequenciais, ~8 tasks

---

## Resumo executivo

Reduzir o tráfego Adman de **~2k req/h** para **~168 req/dia**, alinhando schedule, cache TTLs, throttle interno e UX ao fato de que a API é D-1 (atualiza às 10h BRT, limite 10 req/min). Trabalho é puramente código — **sem migration de schema**.

Estrutura em 3 waves sequenciais para preservar dependências (botão "Reanalisar" depende do payload novo de `companies_summary` que vem do backend de Wave 2):

- **Wave 1 — Backend cadência:** rebalanço de schedules, constante `ADMAN_RATE_LIMIT_RPM`, throttle 7s, cache TTL 24h + chave com `YYYY-MM-DD`, **mitigação de timeout do Supervisor (1800s) + fan-out com delay incremental** (cobre SC-1 a SC-4 e fecha os riscos do critério 8).
- **Wave 2 — Remoção do botão "Sincronizar agora" + disclaimers D-1:** remove `AdmanController::syncNow`, rota `POST /adman/sync` e callers; substitui por badge "Atualizado em DD/MM HH:mm · D-1 da Adman" no Dashboard/Admin e adiciona disclaimer D-1 em Dashboard, Fechamento e cards Sugadores (cobre SC-5 e SC-7).
- **Wave 3 — Bloqueio do "Reanalisar" no card Sugadores + suíte de smoke:** `SugadorController::index` injeta `analisado_hoje` em `companies_summary`; `Sugadores/Index.jsx` desabilita botão "Reanalisar" + mensagem padronizada; smoke humano de 24h pós-deploy (cobre SC-6 e SC-8).

---

## Goal e success criteria (citação do ROADMAP)

> **Goal:** Reduzir chamadas à API Adman de ~2k/hora para ~168/dia alinhando schedule, caches e UX ao fato de que a Adman é D-1 (atualiza 1× ao dia, às 10h BRT, com limite de 10 req/min por API key). Eliminar o 429 crônico em produção sem perder funcionalidade.

1. `Schedule::command('adman:sync')` → `dailyAt('11:00')` (cron `0 11 * * *`).
2. Cascata reorganizada (11:00 → 11:30 → 11:45 → 11:55 → 12:00 → 12:30 → 12:45).
3. Constante `ADMAN_RATE_LIMIT_RPM = 10` declarada; throttle entre chamadas = **7 segundos** em `SyncAdmanData`/`syncAll` e `RefreshGrossBillingCacheJob`.
4. Cache TTL runtime → **24h** + chave inclui `YYYY-MM-DD`.
5. Botão "Sincronizar agora" + rota `POST /adman/sync` + `AdmanController::syncNow` removidos; UI substitui por badge "Atualizado em DD/MM HH:mm · D-1 da Adman".
6. Botão "Reanalisar" no card Sugadores bloqueado quando `AdmanSyncLog::whereDate('created_at', today())->where('company_id', $id)->exists()`; mensagem `"Análise diária já rodou hoje · próxima amanhã às 12h"`.
7. Disclaimer "Dados D-1 da Adman" visível em Dashboard, Fechamento e cards Sugadores.
8. Zero 429 em logs durante 24h pós-deploy.

---

## Mapeamento criterion → tasks

| SC | Wave | Task | Arquivos principais |
|----|------|------|---------------------|
| SC-1 | W1 | W1-T1 | `routes/console.php` |
| SC-2 | W1 | W1-T1 | `routes/console.php` |
| SC-3 | W1 | W1-T2, **W1-T4** | `app/Services/AdmanService.php`, `app/Jobs/RefreshGrossBillingCacheJob.php`, `app/Console/Commands/SyncAdmanData.php`, `/etc/supervisor/conf.d/ecf-worker.conf` (VPS) |
| SC-4 | W1 | W1-T3 | `app/Services/AdmanService.php` |
| SC-5 | W2 | W2-T1, W2-T2 | `routes/web.php`, `app/Http/Controllers/AdmanController.php`, `resources/js/Pages/Dashboard/Admin.jsx` |
| SC-7 | W2 | W2-T2 | `resources/js/Pages/Dashboard/Admin.jsx`, `resources/js/Pages/Admin/Financeiro.jsx`, `resources/js/Pages/Sugadores/Index.jsx` |
| SC-6 | W3 | W3-T1, W3-T2 | `app/Http/Controllers/SugadorController.php`, `resources/js/Pages/Sugadores/Index.jsx` |
| SC-8 | W3 + **W1-T4** | W3-T3 (smoke), **W1-T4 (Sub-task A + B)** | Smoke humano (deferido — 24h pós-deploy); supervisor.conf + fan-out garantem entrega real do "zero 429" |

---

## Estado verificado (2026-05-27)

**Confirmações via grep/read antes do plan:**

- `config/app.php` linha 68: `'timezone' => env('APP_TIMEZONE', 'America/Sao_Paulo')` → `dailyAt('11:00')` é 11h BRT real. **Schedule não precisa de ajuste UTC.**
- `routes/console.php` linha 13: schedule atual é `everyFiveMinutes()` (a substituir).
- `AdmanService::syncCompany` (linhas 123 e 141) **populates `AdmanSyncLog`** em sucesso E erro, sempre com `company_id` e `created_at = now()` → fonte do critério 6 está garantida.
- Único caller de `/adman/sync` no JSX: `resources/js/Pages/Dashboard/Admin.jsx` linha 97 (`window.axios.post(route('adman.sync'))`) + botão linhas 285-297.
- Outros botões "Sincronizar" no JSX são de outros serviços e **NÃO devem ser tocados**:
  - `Admin/Financeiro.jsx` linha 873 → `route('admin.financeiro.sync')` (faturamento mensal via `AdminController::syncFaturamento`, rota separada `/financeiro/sync-faturamento`).
  - `Grants/Index.jsx` → SFTP do ML.
  - `Meetings/Index.jsx` → Google Calendar.
  - `Mlb/Empresas.jsx`, `Mlb/Publicacoes.jsx`, `Mlb/Vendas.jsx`, `Mlb/Implementacao.jsx` → API Mercado Livre direta.
- Throttle atual: `AdmanService::syncAll` linha 51 `usleep(700_000)` (0.7s); `RefreshGrossBillingCacheJob` linhas 118 e 133 `usleep(1_500_000)` (1.5s).
- Cache TTLs default: `fetchGrossBilling` 60min (linha 255), `fetchAccountMetricsCached` 60min (linha 393), `fetchGrossBillingsBatch` 30min (linha 513). Chaves atuais NÃO incluem data de "hoje" (são `dateFrom:dateTo`, que já mudam ao virar o dia em chamadas baseadas em "hoje" mas NÃO invalidam quando query inclui datas fixas + janela móvel).
- `AdmanMcpService::cachedFullScanIfReady` existe (linha 284) mas é fora do escopo — não-objetivo declarado em CONTEXT (Sugadores drilldown ainda precisa de freshness, não 24h).
- **Supervisor em produção (verificado via SSH 2026-05-27):** `/etc/supervisor/conf.d/ecf-worker.conf` declara `command=php artisan queue:work redis --sleep=3 --tries=3 --timeout=900 --max-time=3600` com `numprocs=2`. Dois riscos confirmados: (1) `--timeout=900` (15min) é menor que o loop de 20min do `RefreshGrossBillingCacheJob`; (2) `numprocs=2` permite que dois workers peguem `SyncAdmanCompanyJob` simultaneamente, violando o limite global mesmo com throttle local de 7s (pior caso: 2 × 60/7 ≈ 17 req/min global). **W1-T4 ataca esses dois riscos.**

---

## Plans (waves)

### Wave 1 — Backend: schedule + throttle + cache + mitigação de paralelismo (autonomous + 1 sub-task de infra SSH)

**Plan:** `16-01-PLAN.md` (a ser gerado pelo executor ao iniciar a fase). Foco backend puro, sem JSX, sem `npm run build`.

**Files modified:** `routes/console.php`, `app/Services/AdmanService.php`, `app/Jobs/RefreshGrossBillingCacheJob.php`, `app/Console/Commands/SyncAdmanData.php`, `/etc/supervisor/conf.d/ecf-worker.conf` (infra VPS, fora do commit Git), novo `tests/Feature/Phase16/AdmanCadenceTest.php`.

#### Task W1-T1 — Reorganizar `routes/console.php` para a cascata D-1

- **Arquivos:** `routes/console.php`
- **Mudanças:**
  - `Schedule::command('adman:sync')` → trocar `->everyFiveMinutes()` por `->dailyAt('11:00')->name('adman-sync-d1')`. Manter `->withoutOverlapping()`. Adicionar comentário pt-BR explicando: API é D-1 (publica 10h BRT, sync 1h depois com margem de processamento Adman).
  - `Schedule::command('adman:sync-faturamento')` → `dailyAt('11:30')` (era 07:30).
  - `Schedule::command('goals:calculate')` → `dailyAt('11:45')` (era 06:00). Atualizar nome `calculate-goal-results` mantido.
  - `Schedule::job(new \App\Jobs\CalculateSetorGoalResults)` → `dailyAt('11:55')` (era 07:00).
  - `Schedule::command('sugadores:analyze')` → `dailyAt('12:00')` (era 06:30).
  - `Schedule::command('sugadores:cleanup-quarentena')` → `dailyAt('12:30')` (era 06:45).
  - `Schedule::job(new \App\Jobs\RefreshGrossBillingCacheJob)` → trocar `->everyThirtyMinutes()` por `->dailyAt('12:45')->name('refresh-gross-billing-cache-d1')`. Manter `->withoutOverlapping()`. Atualizar comentário do bloco (linhas 54-61) — não é mais `30min, 50+ empresas × 1.5s = 75s`; agora é `1×/dia, ~168 empresas × 7s = ~20min`.
  - **NÃO tocar:** `prune-pending-nps-surveys`, `grants:sync-sftp`, `notifications:cleanup`, `checa-envio-relatorio-fechamento` (não consomem Adman).
- **Verification:**
  - `php artisan schedule:list` mostra `0 11 * * *` para `adman:sync` e `45 12 * * *` para `RefreshGrossBillingCacheJob`.
  - `grep -n "everyFiveMinutes\|everyThirtyMinutes" routes/console.php` retorna **zero** ocorrências.
  - `php artisan test --filter=AdmanCadenceTest::test_schedule_horarios` valida cron strings dos 7 jobs reorganizados.
- **Done:** `php artisan schedule:list` lista 7 jobs Adman-cascata nos horários da tabela do critério 2; teste verde.

#### Task W1-T2 — Constante `ADMAN_RATE_LIMIT_RPM` + throttle 7s em loops sequenciais

- **Arquivos:** `app/Services/AdmanService.php`, `app/Jobs/RefreshGrossBillingCacheJob.php`
- **Mudanças:**
  - `AdmanService`: adicionar `public const ADMAN_RATE_LIMIT_RPM = 10;` no topo da classe com docblock pt-BR explicando — "10 req/min documentado pela Adman (2026-05-27); throttle aplicado = 7s = 1000ms × 60 / 10 + 1s de folga para garantir nunca passar".
  - `AdmanService::syncAll` linha 51: trocar `usleep(700_000)` por `usleep(7_000_000)`. Comentário inline: `// Throttle conforme AdmanService::ADMAN_RATE_LIMIT_RPM (10 rpm → 7s/req com folga)`.
  - `RefreshGrossBillingCacheJob` linhas 118 e 133: trocar ambos `usleep(1_500_000)` por `usleep(7_000_000)`. Comentário inline igual.
  - **NÃO tocar:** `usleep(700_000)` na linha 51 do `fetchPerformance` (loop de retry interno, contexto diferente — já tem backoff exponencial 2s/4s/8s linhas 211-215), `usleep(400_000)` linha 187, demais `usleep`s menores no `fetchCampaigns`/`fetchAdgroups` (são paginação dentro de UMA chamada, não loop entre empresas).
  - `AdmanController::syncNow` linha 52: `delay(now()->addMilliseconds($dispatched * 1500))` — **será removido junto com o método no Wave 2**, ignorar aqui.
- **Verification:**
  - `grep -n "ADMAN_RATE_LIMIT_RPM" app/Services/AdmanService.php` retorna ≥1 match (declaração).
  - `grep -nE "usleep\(7_000_000\)" app/Services/AdmanService.php app/Jobs/RefreshGrossBillingCacheJob.php` retorna 3 matches (1 syncAll + 2 RefreshGrossBilling).
  - `grep -nE "usleep\(1_500_000\)|usleep\(700_000\)" app/Services/AdmanService.php app/Jobs/RefreshGrossBillingCacheJob.php` — filtrar comentários (`grep -v '^\s*//'`) e validar que `1_500_000` retorna 0 e `700_000` retorna no máximo 1 (linha 51 do `fetchPerformance`, que **não é a do syncAll**).
  - Teste unitário `Phase16/AdmanCadenceTest::test_constante_rate_limit_documentada` reflete `ADMAN_RATE_LIMIT_RPM === 10`.
- **Done:** Constante declarada e referenciada em todos os 3 sites de throttle entre empresas; testes verdes.

#### Task W1-T3 — Cache TTL 24h + chave com `YYYY-MM-DD`

- **Arquivos:** `app/Services/AdmanService.php`, `tests/Feature/Phase16/AdmanCadenceTest.php`
- **Mudanças:**
  - `fetchGrossBilling` linha 255: trocar default `$cacheMinutes = 60` por `$cacheMinutes = 1440`.
  - `fetchAccountMetricsCached` linha 393: trocar default `$cacheMinutes = 60` por `$cacheMinutes = 1440`.
  - `fetchGrossBillingsBatch` linha 513: trocar default `$cacheMinutes = 30` por `$cacheMinutes = 1440`.
  - **Chave de cache com data:** alterar a definição da `$cacheKey` em `fetchGrossBilling`, `fetchAccountMetricsCached`, `getCachedGrossBilling`, `hasCachedEntry`, `getCachedAccountMetrics`, `fetchGrossBillingsBatch` para incluir o **dia local** (`now()->setTimezone(config('app.timezone'))->toDateString()`). Formato proposto:
    - antes: `"adman:gross_billing:{$custId}:{$dateFrom}:{$dateTo}"`
    - depois: `"adman:gross_billing:{$custId}:{$dateFrom}:{$dateTo}:" . now()->setTimezone(config('app.timezone'))->toDateString()`
  - Extrair helper privado `private function cacheDay(): string { return now()->setTimezone(config('app.timezone'))->toDateString(); }` para evitar duplicação em 6 sites.
  - **NÃO tocar:** `syncMonthRevenue` mantém `cacheMinutes: 0` (caminho de sync, não cache). `ERROR_CACHE_MINUTES` mantém seu valor atual (curto, intencional).
  - Comentário pt-BR no topo de cada método ajustado: "TTL = 24h (1440min) — API Adman é D-1; chave inclui data atual em BRT para auto-invalidar ao virar o dia."
- **Verification:**
  - `grep -nE "cacheMinutes = (1440|60|30)" app/Services/AdmanService.php` — filtrar comentários e confirmar que os 3 defaults agora são `1440`.
  - `grep -n "cacheDay()" app/Services/AdmanService.php` retorna pelo menos 6 matches (1 declaração + 5+ usos nas chaves).
  - Teste `Phase16/AdmanCadenceTest::test_cache_ttl_24h` — mock de `Cache::put`, chama `fetchGrossBilling` com `forceRefresh=true` e força a flag de cache hit; assert `now()->addMinutes(1440)` foi passado como TTL.
  - Teste `Phase16/AdmanCadenceTest::test_cache_key_inclui_dia_brt` — fixa `Carbon::setTestNow('2026-05-27 23:30 BRT')`, chama método; assert que a chave contém `:2026-05-27`. Em seguida `setTestNow('2026-05-28 00:30 BRT')` e confirma que a chave gera `:2026-05-28` (key diferente → cache miss).
  - **Caches já populados:** o plan documenta — **NÃO** limpar cache manualmente. TTL antigo (≤60min) expira em <1h; novas chaves coexistem temporariamente.
- **Done:** 3 TTLs default = 1440; helper `cacheDay()` em uso em ≥5 sites; testes verdes; comentário pt-BR explicando estratégia.

#### Task W1-T4 — Mitigar timeout do Supervisor + fan-out com delay incremental no `SyncAdmanData`

**Por que existe:** diagnóstico do supervisor em produção (2026-05-27) revelou dois riscos residuais que o throttle local de 7s sozinho **não** resolve:

1. `--timeout=900` (15min) do worker é menor que o loop interno de 20min do `RefreshGrossBillingCacheJob` (168 empresas × 7s). Sem ajuste, o job falha mid-loop.
2. `numprocs=2` permite dois workers paralelos pegando `SyncAdmanCompanyJob` simultaneamente. O throttle local de 7s só ordena chamadas **dentro** de cada worker — globalmente, dois workers poderiam emitir ~17 req/min e voltar a estourar 429.

Esta task fecha ambos os flancos. **Sub-task A é mudança de infra (não vai pro commit Git); Sub-task B é código PHP normal.**

##### Sub-task A — Aumentar `--timeout` do Supervisor para 1800s (VPS, não-commit)

- **Arquivos (no VPS, via SSH):** `/etc/supervisor/conf.d/ecf-worker.conf`
- **Mudanças:**
  - Trocar `--timeout=900` por `--timeout=1800` (30 min) — folga sobre o loop de ~20 min do `RefreshGrossBillingCacheJob`.
  - Manter `--max-time=3600` (worker reinicia após 1h independente).
  - **NÃO mudar:** `stopwaitsecs=3600`, `numprocs=2`, `--sleep=3`, `--tries=3`, ou qualquer outra diretiva.
- **Comandos (via `plink.exe`/`ssh` da máquina dev):**
  ```
  ssh root@177.7.53.164 "sed -i 's/--timeout=900/--timeout=1800/' /etc/supervisor/conf.d/ecf-worker.conf"
  ssh root@177.7.53.164 "supervisorctl reread && supervisorctl update ecf-worker"
  ssh root@177.7.53.164 "supervisorctl status ecf-worker:*"
  ```
- **Quando aplicar:** **ANTES** do deploy do código (assim que a Phase 16 estiver pronta para subir). Ordem correta: (1) SSH config update → (2) `git push` do código Wave 1+2+3 → (3) `deploy.sh`. Se a ordem inverter (deploy primeiro), o `RefreshGrossBillingCacheJob` da primeira execução pós-deploy falha em ~15min.
- **Verification:**
  - `supervisorctl status` mostra `ecf-worker:ecf-worker_00 RUNNING` e `ecf-worker:ecf-worker_01 RUNNING` com **PIDs novos** (config reload reiniciou processos).
  - `grep timeout /etc/supervisor/conf.d/ecf-worker.conf` mostra `--timeout=1800` exatamente uma vez.
  - **Rollback:** `ssh root@177.7.53.164 "sed -i 's/--timeout=1800/--timeout=900/' /etc/supervisor/conf.d/ecf-worker.conf && supervisorctl reread && supervisorctl update ecf-worker"`.
- **NÃO faz parte do commit Git** — é mudança de infra no servidor. **Documentar no STATE.md** ao concluir Phase 16 (entrada: "2026-MM-DD — supervisor `--timeout` elevado de 900s para 1800s para suportar loop D-1 do `RefreshGrossBillingCacheJob`").
- **Done:** `supervisorctl status` confirma workers reiniciados com novo timeout; `grep` confirma valor 1800 no arquivo; entrada registrada no STATE.md.

##### Sub-task B — Fan-out com delay incremental de 7s em `SyncAdmanData::handle`

- **Arquivos:** `app/Console/Commands/SyncAdmanData.php`
- **Mudanças:**
  - Refatorar o loop de empresas no `handle()`:
    - **Antes:** dispatch sequencial (ou com `usleep` síncrono) de `SyncAdmanCompanyJob` para cada empresa. O comando atual depende do throttle interno do worker para espaçar requisições.
    - **Depois:** loop enumerado:
      ```
      foreach ($companies as $i => $company) {
          \App\Jobs\SyncAdmanCompanyJob::dispatch($company)
              ->delay(now()->addSeconds($i * 7));
      }
      ```
      Resultado: os 168 jobs entram na fila com horários espaçados de 7 em 7 segundos. Workers paralelos (`numprocs=2`) só conseguem pegar **um job por vez** porque os demais ainda têm `available_at > now()`.
  - **Comentário pt-BR inline** explicando a estratégia, referenciando a constante:
    ```
    // Fan-out com delay incremental de 7s (AdmanService::ADMAN_RATE_LIMIT_RPM = 10 rpm
    // → 60s/10 = 6s teórico + 1s de folga). Respeita o limite global mesmo com
    // numprocs=2 no Supervisor: apenas 1 job fica "ready" a cada 7s, independente
    // do número de workers. Substitui o usleep síncrono usado anteriormente.
    ```
  - Se o código atual tiver `usleep` síncrono entre dispatches (ex: `usleep(1_500_000)`), remover — o delay agora é responsabilidade do scheduler de jobs, não do comando.
  - **NÃO tocar:** logging, contadores de progresso, tratamento de erro do `handle()`, demais filtros de empresa.
- **Verification:**
  - `grep -nE "delay\(.*addSeconds.*\* 7" app/Console/Commands/SyncAdmanData.php` retorna ≥1 match.
  - `grep -n "ADMAN_RATE_LIMIT_RPM" app/Console/Commands/SyncAdmanData.php` retorna ≥1 match (comentário referenciando).
  - `grep -nE "usleep" app/Console/Commands/SyncAdmanData.php` — filtrar comentários; retorna 0 matches (delay síncrono foi removido).
  - Teste unitário `Phase16/AdmanCadenceTest::test_sync_adman_data_fan_out_com_delay`:
    - `Bus::fake([\App\Jobs\SyncAdmanCompanyJob::class])`.
    - Criar 3 empresas com `adman_account_id`.
    - Rodar `Artisan::call('adman:sync')`.
    - Assert `Bus::assertDispatched(SyncAdmanCompanyJob::class, 3)`.
    - Inspecionar via `Bus::dispatched(...)` que os delays são 0s, 7s e 14s respectivamente (incremento monotônico de 7s).
  - **Manual (após deploy):** `php artisan adman:sync` + `php artisan queue:listen --verbose` → logs mostram jobs sendo processados com espaçamento de ~7s, mesmo com 2 workers ativos.
- **Done:** Loop substituído por `->delay($i * 7s)`; comentário referencia `ADMAN_RATE_LIMIT_RPM`; nenhum `usleep` sobrando; teste de Bus fake verde.

##### Sub-task C — `RefreshGrossBillingCacheJob` também vira fan-out?

**Decisão (planner):** **NÃO refatorar agora.** Justificativa:

- Com Sub-task A (timeout do Supervisor = 1800s), o loop atual de ~20min cabe folgado dentro de uma única execução de job.
- O throttle interno de 7s (W1-T2) já garante respeito ao rate limit dentro do job, e como é **um** job que roda 1×/dia às 12:45 com `->withoutOverlapping()`, não há sobreposição entre workers para esse caso específico.
- Refatorar para fan-out (168 jobs × delay incremental) dobra a fila e aumenta a janela total de execução (~20min → ~20min × 1, mas com mais I/O na fila Redis) sem ganho mensurável em respeito ao rate limit.
- **Defesa em profundidade pendente:** se o smoke pós-deploy (W3-T3) mostrar 429 vindos do `RefreshGrossBillingCacheJob`, abrir Phase 16.1 para refatorar como fan-out. Por enquanto, o loop único protegido pelo throttle interno de 7s + `->withoutOverlapping()` + timeout 1800s é suficiente.

**Implicação no plan:** Sub-task C **não gera código**. Decisão documentada aqui e nos pitfalls.

---

### Wave 2 — Remoção do botão "Sincronizar agora" + badges/disclaimers D-1 (autonomous, mas com `npm run build`)

**Plan:** `16-02-PLAN.md`. Depende de Wave 1 estar mergeada (a remoção de `syncNow` reduz a janela de uso síncrono que viola os novos throttles).

**Files modified:** `routes/web.php`, `app/Http/Controllers/AdmanController.php`, `app/Http/Controllers/DashboardController.php` (read-only check + nova prop `adman_last_sync`), `resources/js/Pages/Dashboard/Admin.jsx`, `resources/js/Pages/Admin/Financeiro.jsx`, `resources/js/Pages/Sugadores/Index.jsx`, novo `tests/Feature/Phase16/AdmanRouteRemovalTest.php`.

#### Task W2-T1 — Remover backend: `AdmanController::syncNow` + rota `POST /adman/sync`

- **Arquivos:** `routes/web.php`, `app/Http/Controllers/AdmanController.php`, `tests/Feature/Phase16/AdmanRouteRemovalTest.php`
- **Mudanças:**
  - `routes/web.php` linhas 144-146: **deletar** `Route::post('/adman/sync', [AdmanController::class, 'syncNow'])->name('adman.sync');`. Manter linhas 147-149 (`/adman/last-sync` → `lastSync` continua útil — pode ser usado pelo badge no futuro, e remove-lo seria fora do escopo do critério 5).
  - `AdmanController.php` linhas 13-63: **deletar** método `syncNow` inteiro. Manter `lastSync` (linhas 65-71).
  - Limpar imports não usados em `AdmanController` (`use App\Jobs\SyncAdmanCompanyJob`, `use App\Models\Company` se não usados mais — verificar via uso).
  - **NÃO tocar:** `AdminController::syncFaturamento` (rota `admin.financeiro.sync` usada pelo `Admin/Financeiro.jsx`). É outro fluxo (sync de faturamento mensal por empresa via job individual).
- **Verification:**
  - `grep -n "syncNow\|adman/sync\b\|adman.sync\b" routes/web.php app/Http/Controllers/AdmanController.php` retorna 0 matches em código aplicativo (excluindo comentários: usar `grep -v '^\s*//'`).
  - Teste `Phase16/AdmanRouteRemovalTest::test_rota_post_adman_sync_nao_existe` — `$response = $this->actingAs($admin)->post('/adman/sync'); $response->assertStatus(404);`.
  - Teste `Phase16/AdmanRouteRemovalTest::test_rota_admin_financeiro_sync_continua_existindo` — sanidade reverse, garante que o sync de faturamento mensal NÃO foi removido por engano.
  - `php artisan route:list --path=adman` mostra apenas `/adman/last-sync` (sem `/adman/sync`).
- **Done:** Rota removida; método removido; rota irmã `/adman/last-sync` preservada; rota `admin.financeiro.sync` preservada; testes verdes.

#### Task W2-T2 — Remover botão "Sincronizar" do Dashboard/Admin + badge "Atualizado em DD/MM HH:mm · D-1 da Adman" + disclaimer D-1 em 3 telas

- **Arquivos:** `app/Http/Controllers/DashboardController.php`, `resources/js/Pages/Dashboard/Admin.jsx`, `resources/js/Pages/Admin/Financeiro.jsx`, `resources/js/Pages/Sugadores/Index.jsx`
- **Mudanças:**

  **Backend (`DashboardController`):**
  - Adicionar prop `adman_last_sync` em `Inertia::render('Dashboard/Admin', [...])`:
    - Query: `\App\Models\AdmanSyncLog::query()->whereNull('error_message')->latest('created_at')->value('created_at')` (último sync bem-sucedido global).
    - Formatar como `?array { iso: string, label: string }` onde `label` é `now('America/Sao_Paulo')->format('d/m H:i')`.
    - Se `null`, passar `null` (UI mostra "Nunca").

  **Frontend `Dashboard/Admin.jsx`:**
  - Receber prop `adman_last_sync` na desestruturação (linhas 80-89).
  - **Deletar:** `useState syncing` (linha 91), `useState lastSync` (linha 92), função `handleSync` inteira (linhas 94-105), bloco `<span lastSync>` + `<button onClick={handleSync}>` (linhas 285-297).
  - **Substituir** o bloco do botão (linhas 285-297) por um badge estático D-1:
    ```
    <div title="Dados D-1: a API Adman atualiza 1× ao dia, às 10h BRT. Sincronização automática diária às 11h." className="...inline-flex items-center gap-1.5 h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white/50 text-[12px]">
      <Clock size={12} />
      {adman_last_sync ? `Atualizado em ${adman_last_sync.label} · D-1 da Adman` : 'D-1 da Adman · sem sync ainda'}
    </div>
    ```
    (Importar `Clock` de `lucide-react`.)
  - Limpar import `RefreshCw` se não usado mais (verificar — `RefreshCw size={13}` linha 295 era o único uso em Dashboard/Admin).

  **Frontend `Admin/Financeiro.jsx` — disclaimer D-1 (critério 7):**
  - Adicionar tooltip/legenda inline próxima ao cabeçalho da tabela de fechamento: `<span className="text-white/40 text-xs" title="Dados D-1 da Adman — atualizam 1× ao dia, às 10h BRT">Dados D-1 da Adman</span>`. Posição exata: executor decide (sob título da página ou junto ao seletor de mês).
  - **NÃO tocar** o componente `SyncFaturamentoBtn` (linhas 867-893) — é sync de faturamento mensal por mês selecionado, fluxo independente (rota `admin.financeiro.sync`).

  **Frontend `Sugadores/Index.jsx` — disclaimer D-1 (critério 7):**
  - Adicionar tooltip/legenda inline no header da view "cards" (acima do grid `companies_summary`): `<span className="text-white/40 text-xs" title="Dados D-1 da Adman — análise diária roda às 12h BRT">Dados D-1 da Adman · próxima análise: amanhã 12h</span>`.

- **Verification:**
  - `npm run build` completa sem erro.
  - `grep -nE "route\('adman.sync'\)|handleSync|window.axios.post.*adman" resources/js/Pages/Dashboard/Admin.jsx` retorna 0 matches.
  - `grep -n "adman_last_sync" resources/js/Pages/Dashboard/Admin.jsx` retorna ≥1 match.
  - `grep -n "D-1 da Adman" resources/js/Pages/Dashboard/Admin.jsx resources/js/Pages/Admin/Financeiro.jsx resources/js/Pages/Sugadores/Index.jsx` retorna ≥3 matches (1 por arquivo).
  - Smoke browser deferido: usuário abre `/dashboard` (logado como admin) — confirmar badge "Atualizado em DD/MM HH:mm · D-1 da Adman" no canto direito; nenhum botão "Sincronizar".
  - Smoke browser: `/administrativo/financeiro` mostra disclaimer; `/sugadores` mostra disclaimer.
- **Done:** Botão removido, badge visível, 3 disclaimers D-1 inseridos, `npm run build` OK, sem erros de import/uso.

---

### Wave 3 — Bloqueio do "Reanalisar" no card Sugadores + smoke (autonomous + checkpoint humano)

**Plan:** `16-03-PLAN.md`. Depende de Wave 1 (cadência) — sem ela, `analisado_hoje` flag não corresponde ao novo schedule.

**Files modified:** `app/Http/Controllers/SugadorController.php`, `resources/js/Pages/Sugadores/Index.jsx`, novo `tests/Feature/Phase16/SugadoresBloqueioReanalisarTest.php`.

#### Task W3-T1 — `SugadorController::index` injeta `analisado_hoje` em `companies_summary`

- **Arquivos:** `app/Http/Controllers/SugadorController.php`, `tests/Feature/Phase16/SugadoresBloqueioReanalisarTest.php`
- **Mudanças:**
  - Em `SugadorController::index` (linhas 127-174):
    - **Antes** do bloco `selectRaw` (linha 131), adicionar query auxiliar:
      ```
      $companiesAnalisadasHoje = \App\Models\AdmanSyncLog::query()
          ->whereIn('company_id', $visibleIds)
          ->whereDate('created_at', today())
          ->whereNull('error_message')
          ->distinct()
          ->pluck('company_id')
          ->flip();
      ```
      Comentário pt-BR explicando: "Bloqueio do Reanalisar (Phase 16 SC-6) — empresa que já teve sync sucesso hoje não pode rodar `AnalyzeCompanySugadoresJob` de novo (rate limit)."
    - No `->map(function ($c) { ... })` (linha 147), adicionar campo no array de retorno:
      ```
      'analisado_hoje' => $companiesAnalisadasHoje->has($c->id),
      ```
  - **NÃO tocar:** demais campos de `companies_summary`, demais props de `Inertia::render`, sorting, view_mode logic, `can_analyze` flag global.
  - Considerar timezone: `whereDate('created_at', today())` usa o timezone do banco; o app é BRT (`America/Sao_Paulo`). `today()` retorna meia-noite BRT (porque `config('app.timezone')` está como `America/Sao_Paulo`). Se houver discrepância (servidor UTC + DB UTC), o limite "hoje" pode "abrir" depois das 21h BRT — registrar nota mas não bloquear: o critério 6 (mensagem "próxima amanhã às 12h") já é eventual no caso de borda.
- **Verification:**
  - `grep -n "analisado_hoje" app/Http/Controllers/SugadorController.php` retorna ≥2 matches (query + map).
  - Teste `SugadoresBloqueioReanalisarTest::test_analisado_hoje_true_quando_log_existe`:
    - Cria empresa visível, cria `AdmanSyncLog` com `created_at = now()` e `error_message = null`.
    - Chama `$this->actingAs($admin)->get('/sugadores'); $response->assertOk();`.
    - Inspect via `$response->inertiaProps()['companies_summary']` (helper Inertia testing); assert primeiro card com a `company_id` certa tem `'analisado_hoje' => true`.
  - Teste `SugadoresBloqueioReanalisarTest::test_analisado_hoje_false_sem_log_hoje`:
    - Cria `AdmanSyncLog` com `created_at = now()->subDay()` (ontem).
    - Assert `'analisado_hoje' => false`.
  - Teste `SugadoresBloqueioReanalisarTest::test_analisado_hoje_false_quando_log_hoje_com_erro`:
    - Cria `AdmanSyncLog` com `created_at = now()` mas `error_message = 'falha qualquer'`.
    - Assert `'analisado_hoje' => false` (sync com falha não conta como "rodado").
- **Done:** Campo presente em todos os elementos de `companies_summary`; 3 testes verdes; sem regressão nos demais asserts de `Phase15SugadoresTest` (rodar suíte completa).

#### Task W3-T2 — `Sugadores/Index.jsx` bloqueia botão "Reanalisar" + mensagem "Análise diária já rodou hoje · próxima amanhã às 12h"

- **Arquivos:** `resources/js/Pages/Sugadores/Index.jsx`
- **Mudanças:**
  - Componente `CompanyCard` (linhas 109-160):
    - Receber `card.analisado_hoje` (já vem do prop atualizado em W3-T1).
    - Condição atual de visibilidade do botão (linha 146): `canAnalyze && card.can_analyze`. **Atualizar** para também desabilitar (não esconder) quando `card.analisado_hoje === true`:
      ```
      {canAnalyze && card.can_analyze && (
        <button
          onClick={() => !card.analisado_hoje && onReanalisar(card.company_id)}
          disabled={card.analisado_hoje}
          title={card.analisado_hoje ? 'Análise diária já rodou hoje · próxima amanhã às 12h' : 'Reanalisar esta empresa agora (job na fila)'}
          className={cn('...classes existentes...', card.analisado_hoje && 'opacity-40 cursor-not-allowed')}
        >
          {card.analisado_hoje ? 'Análise diária OK' : 'Reanalisar'}
        </button>
      )}
      ```
    - Quando `card.analisado_hoje`, renderizar texto pequeno auxiliar abaixo do botão ou no tooltip — copy exata: `"Análise diária já rodou hoje · próxima amanhã às 12h"`.
  - **NÃO tocar:** lógica de `enqueuedAt` (feedback visual "Enfileirado às HH:mm" da Phase 15), Policy `manage`, restante do drilldown.
- **Verification:**
  - `npm run build` OK.
  - `grep -n "analisado_hoje\|Análise diária já rodou hoje · próxima amanhã às 12h" resources/js/Pages/Sugadores/Index.jsx` retorna ≥3 matches (uso da flag + texto da mensagem ≥2).
  - Smoke browser (deferido — parte do W3-T3): logado como admin, abre `/sugadores`; identifica uma empresa com sync hoje (via `php artisan tinker` populando `AdmanSyncLog`); confirma que o botão dela mostra "Análise diária OK" / cinza / cursor-not-allowed e o tooltip tem a mensagem exata.
- **Done:** Botão desabilitado quando `analisado_hoje=true`, copy literal aparece em hover/tooltip, `npm run build` OK.

#### Task W3-T3 — Suíte completa + smoke humano 24h pós-deploy (checkpoint)

- **Tipo:** `checkpoint:human-verify` (deferido para pós-deploy)
- **O que automatizar antes do checkpoint:**
  - `php artisan test` — toda a suíte deve passar.
  - `php artisan schedule:list` — print da lista no log da release.
  - `npm run build` — produção JS gerado sem warnings.
- **O que o humano valida (após deploy):**
  1. Crontab/Supervisor confirma `* * * * * php artisan schedule:run` ativo.
  2. Às 11:05 BRT do dia seguinte ao deploy: `tail -200 storage/logs/laravel.log | grep -i "adman:sync"` mostra início e fim da execução.
  3. Às 12:50 BRT: `tail` mostra `RefreshGrossBillingCacheJob` rodando e completando em ~20min.
  4. 24h depois: `grep -ic "429\|rate limit" storage/logs/laravel.log` retorna **0** (ou explica os hits residuais).
  5. Abre `/dashboard` — badge "Atualizado em DD/MM HH:mm · D-1 da Adman" visível, sem botão "Sincronizar".
  6. Abre `/sugadores` — pelo menos uma empresa mostra botão "Reanalisar" desabilitado após o sync diário.
  7. Abre `/administrativo/financeiro` — disclaimer "Dados D-1 da Adman" presente; botão `SyncFaturamentoBtn` mensal ainda funciona (sanidade — não regredimos).
- **Resume signal:** Operador digita "approved" ou "issues: <descrição>".
- **Done:** Suíte verde, schedule executando nos horários novos, zero 429 em 24h, UX confirmada visualmente.

---

## Pitfalls e mitigações

1. **Timezone divergente:** Server PHP roda em UTC (comentário em `routes/console.php` linha 88 diz isso), mas `config('app.timezone') = America/Sao_Paulo`. **Laravel Scheduler usa `config('app.timezone')`** — `dailyAt('11:00')` é 11h BRT. ✅ Confirmado. Mitigação: teste `AdmanCadenceTest::test_schedule_horarios` cobre as cron strings; smoke humano (W3-T3) confirma execução real.
2. **Caches antigos com TTL antigo:** Caches populados antes do deploy continuam com TTL ≤60min. Novos TTLs aplicam-se ao próximo refresh. **NÃO** clear manual — esperar TTL expirar naturalmente em <1h.
3. **`SyncAdmanCompanyJob` em curso ao deploy:** Sem ação especial — supervisor reinicia workers pós-deploy, próxima execução pega código novo.
4. **Throttle 7s × 168 empresas = ~20 min vs. `--timeout=900` do Supervisor (15min):** ✅ **Resolvido em W1-T4 Sub-task A** — `--timeout` elevado para 1800s (30min), folga sobre o loop de ~20min. Verificação via `supervisorctl status` + `grep timeout /etc/supervisor/conf.d/ecf-worker.conf` no smoke W3-T3.
5. **`numprocs=2` permite paralelismo violando o rate limit global:** Throttle local de 7s só ordena chamadas **dentro** de cada worker. Com 2 workers paralelos pegando `SyncAdmanCompanyJob` ao mesmo tempo, pior caso é ~17 req/min global → 429. ✅ **Resolvido em W1-T4 Sub-task B** — fan-out com `->delay($i * 7s)` faz com que apenas 1 job fique "ready" a cada 7s na fila Redis, independente do número de workers. Verificação via teste `Bus::fake` + smoke `queue:listen --verbose`.
6. **Frontend cache do browser:** Bundle JS pode permanecer 24h no CDN/browser do usuário. Botão "Sincronizar" pode aparecer ainda. Após primeiro deploy, fazer `Ctrl+F5` ou esperar TTL. Sem ação especial.
7. **`AdmanMcpService::cachedFullScanIfReady`:** Out of scope. CONTEXT diz que é "verificar se também precisa subir TTL" — decisão lean: **NÃO subir agora**. Drilldown Sugadores precisa de freshness média; revisitar em fase futura se sair 429 lá.
8. **Job `SyncFaturamentoMensal` sem throttle entre dispatchs:** `SyncFaturamentoMensal::handle` enfileira N jobs sem delay. Workers processam serial → throttle efetivo controlado pelo throughput do worker. Provavelmente OK mas digno de observar no smoke. Não corrigir agora — fora do escopo do critério 3.
9. **Critério 6 fonte (`AdmanSyncLog`):** Verificado — populado em `AdmanService::syncCompany` linhas 123 (sucesso) e 141 (erro). W3-T1 filtra `whereNull('error_message')` para não bloquear quando o sync falhou.
10. **`RefreshGrossBillingCacheJob` mantido como loop único (não-fan-out):** decisão consciente em W1-T4 Sub-task C. Com `--timeout=1800` + throttle interno de 7s + `->withoutOverlapping()` + 1×/dia, não há risco de colisão paralela. Se smoke W3-T3 mostrar 429 originados deste job especificamente, abrir Phase 16.1 para refatorar como fan-out (defesa em profundidade).

---

## Não-objetivos (out of scope)

- Webhook ou polling externo (Adman não oferece).
- Migrar para outra fonte (ML Direct API).
- Mexer em syncs não-Adman (SFTP Grants, Google Meetings, ML Publicações, sync de faturamento mensal `admin.financeiro.sync`).
- Mexer no retry exponencial de `fetchPerformance` (já correto).
- Criar nova página de "Status do sync" (badge inline já resolve).
- Mexer em `AnalyzeCompanySugadoresJob` (só o trigger UI).
- Subir TTL do `AdmanMcpService` (drilldown precisa de freshness).
- Migration de schema.
- Refatorar `RefreshGrossBillingCacheJob` para fan-out (decisão W1-T4 Sub-task C — protegido por timeout 1800s + `withoutOverlapping` + throttle interno).

---

## Deviation contract — parar e perguntar se:

1. `config('app.timezone')` em produção for diferente de `America/Sao_Paulo` (verificar via `php artisan tinker` → `config('app.timezone')` antes de mergear Wave 1). Implicação: o schedule rodaria em outro fuso.
2. Grep prévio em `routes:list` ou `resources/js/**` revelar **outro caller** de `/adman/sync` ou `route('adman.sync')` que **NÃO seja** `Dashboard/Admin.jsx` (ex: shell script, comando Artisan, blade, código externo, Inertia visit).
3. `AdmanSyncLog` deixar de ser populado pelo `AdmanService::syncCompany` (regressão em Phase 16 ou anterior) — bloqueia critério 6.
4. Reorganizar schedule quebrar dependência de outro job não documentado em CONTEXT (ex: descobrir que `calculate-goal-results` depende de algo que só roda em outro horário).
5. Suíte regredir testes não relacionados — registrar em `.planning/deferred-items.md` e perguntar antes de seguir.
6. Workers Supervisor tiverem timeout < 25 min descoberto após W1-T4 Sub-task A — significaria que o `sed -i` não pegou ou o arquivo de config está em outro path. **Não editar manualmente o `ecf-worker.conf`** se o `sed -i` retornar erro de permissão, path diferente ou nenhuma substituição — parar e investigar (pode haver override em `/etc/supervisor/supervisord.conf` ou em outro include).
7. `app/Console/Commands/SyncAdmanData.php` não tiver um loop `foreach` claro sobre empresas no `handle()` (estrutura diferente da assumida em W1-T4 Sub-task B) — parar e mapear o fluxo real antes de aplicar `->delay()`.
8. Após Sub-task A, `supervisorctl status` retornar `FATAL` ou `BACKOFF` em algum worker — reverter timeout para 900s imediatamente (rollback documentado) e investigar.

---

## Por que este plano entrega o goal?

O goal é "reduzir ~2k → ~168 req/dia, zero 429" e tem 8 critérios. Cada wave fecha um eixo do problema:

- **Wave 1 (SC-1, SC-2, SC-3, SC-4, e fechamento real de SC-8):** Ataca a **frequência** (5min → diário), o **espaçamento** (1.5s → 7s entre chamadas) e a **cobertura de cache** (60min → 24h com chave por dia). Esses três sozinhos derrubam a maior parte das requisições e do 429 — passam de ~120 chamadas/empresa/dia para ~1.x chamada/empresa/dia (sync diário + cache misses esporádicos). **W1-T4 fecha os riscos residuais identificados via inspeção do supervisor.conf em produção:** o timeout do worker (`--timeout=900`) é elevado para 1800s para suportar o loop de ~20min do `RefreshGrossBillingCacheJob` (Sub-task A), e o `SyncAdmanData` passa a usar fan-out com `->delay($i * 7s)` para garantir que o critério 3 (throttle 7s) seja respeitado **globalmente** mesmo com `numprocs=2` workers em paralelo (Sub-task B). Sem W1-T4, o critério 8 (zero 429) não seria entregue de fato — apenas no papel.
- **Wave 2 (SC-5, SC-7):** Fecha o **vetor humano** — botão "Sincronizar agora" no Dashboard era a única forma de UI síncrona disparar bursts de ~168 chamadas em paralelo. Badge "Atualizado em DD/MM HH:mm · D-1 da Adman" + disclaimers D-1 alinham a expectativa do usuário (que parou de existir um "sync sob demanda").
- **Wave 3 (SC-6, SC-8):** Fecha o **vetor humano residual** — botão "Reanalisar" no card Sugadores (Phase 15) ainda dispara `AnalyzeCompanySugadoresJob` que chama a Adman. Bloqueá-lo quando o sync diário já rodou impede que analistas façam reanálise repetida na mesma empresa. Smoke pós-deploy (SC-8) é a única verificação possível para "zero 429" porque depende de produção real por 24h.

A ordem das waves é **goal-backward**: Wave 1 cria a infra que Wave 2 e 3 pressupõem (cadência diária faz sentido para o badge; throttle 7s + fan-out + timeout 1800s evitam que o ainda-existente botão "Reanalisar" estoure a quota antes do bloqueio de W3 estar em produção). Cada wave gera valor independente — se algo travar em W2 ou W3, W1 sozinha já reduz ~95% das chamadas e elimina a maior parte dos 429.
