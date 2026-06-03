---
phase: 19-sugadores-foco-dia
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Http/Controllers/SugadorController.php
  - app/Console/Commands/LimparOrfaosSugadores.php
  - app/Models/SugadorAcao.php
  - app/Services/AdmanMcpService.php
  - resources/js/Pages/Sugadores/Index.jsx
  - tests/Feature/Phase19/SugadoresVistaDefaultTest.php
  - tests/Feature/Phase19/LimparOrfaosSugadoresTest.php
  - tests/Feature/Phase19/CopiarMlbsTest.php
autonomous: false  # W4 tem checkpoint humano antes do --apply em prod
requirements:
  - SUG-DEFAULT-01    # Vista default filtra hoje+pendente automaticamente
  - SUG-COPY-LINHA-01 # Botão Copiar MLBs inline na linha do sugador
  - SUG-COPY-EMPRESA-01 # Botão Copiar MLBs agregado no card de empresa
  - SUG-BANNER-D1-01  # Banner D-1 explícito + indicadores de estado por empresa
  - SUG-MCP-LOCK-01   # Cache::lock por custId em AdmanMcpService
  - SUG-ORFAOS-01     # Comando one-shot sugadores:limpar-orfaos

must_haves:
  truths:
    - "Vista default de /sugadores em modo lista mostra somente reference_date=hoje + status=pendente, sem o usuário precisar selecionar filtros"
    - "Banner pt-BR no topo de /sugadores informa que a análise diária roda às 12h BRT e exibe horário da última execução"
    - "Card de empresa (modo cards) mostra badge de estado: análise OK hoje, sem análise hoje ou sem sync hoje"
    - "Botão Copiar MLBs aparece inline em cada linha de sugador tipo=adgroup na lista e copia a lista para o clipboard sem abrir drilldown"
    - "Botão Copiar MLBs da empresa aparece em cada CompanyCard com count_hoje>0 e copia lista consolidada (sem duplicatas) de TODOS os sugadores adgroup pendentes de hoje da empresa"
    - "Cache::lock por custId em AdmanMcpService serializa chamadas concorrentes ao MCP e mitiga 429 reportado pelo usuário"
    - "Comando php artisan sugadores:limpar-orfaos sem --apply é dry-run; com --apply marca 1407 sugadores antigos pendentes como auto_resolvido com audit log e respeita STATUS_TRAVADOS"
  artifacts:
    - path: "app/Http/Controllers/SugadorController.php"
      provides: "index() com default_view=hoje + analise_diaria prop + mlbsByCompany() endpoint agregado"
      contains: "default_view, analise_diaria, mlbsByCompany"
    - path: "app/Console/Commands/LimparOrfaosSugadores.php"
      provides: "Comando one-shot sugadores:limpar-orfaos com dry-run default e --apply explícito"
      contains: "sugadores:limpar-orfaos"
    - path: "app/Models/SugadorAcao.php"
      provides: "Constante ACAO_LIMPEZA_ORFAOS"
      contains: "ACAO_LIMPEZA_ORFAOS"
    - path: "app/Services/AdmanMcpService.php"
      provides: "Cache::lock por custId em fetchAllProductAds para serializar concorrência"
      contains: "Cache::lock"
    - path: "resources/js/Pages/Sugadores/Index.jsx"
      provides: "Banner D-1 + filtros default + botão Copiar inline + Copiar agregado no card"
      contains: "Análise diária roda às 12h BRT, copyMlbsLinha, copyMlbsEmpresa"
    - path: "tests/Feature/Phase19/SugadoresVistaDefaultTest.php"
      provides: "4 testes de filtros default + analise_diaria prop"
      contains: "test_default_view_filtra_hoje_e_pendente"
    - path: "tests/Feature/Phase19/LimparOrfaosSugadoresTest.php"
      provides: "4 testes: dry-run, apply, STATUS_TRAVADOS intactos, HOJE intacto"
      contains: "test_apply_marca_antigos_como_auto_resolvido"
    - path: "tests/Feature/Phase19/CopiarMlbsTest.php"
      provides: "2 testes: lista consolidada única + Cache::lock serializa MCP"
      contains: "test_mlbs_by_company_retorna_lista_unica"
  key_links:
    - from: "SugadorController::index"
      to: "frontend (default_view + analise_diaria + companies_summary)"
      via: "props Inertia"
      pattern: "default_view.*hoje|analise_diaria"
    - from: "LimparOrfaosSugadores command"
      to: "tabela sugadores + sugador_acoes"
      via: "DB::transaction com UPDATE em massa + insert chunks de 500"
      pattern: "ACAO_LIMPEZA_ORFAOS|DB::transaction"
    - from: "AdmanMcpService::fetchAllProductAds"
      to: "Cache::lock(adman_mcp:custid:{custId}, 30)"
      via: "block() com TTL 30s"
      pattern: "adman_mcp:custid:|Cache::lock"
    - from: "Sugadores/Index.jsx CompanyCard"
      to: "rota mlbs-by-company OU loop /sugadores/{id}/mlbs"
      via: "fetch on-click"
      pattern: "mlbs-todos|mlbs-by-company|copyMlbsEmpresa"
---

<objective>
Reforçar **acertividade + praticidade** no módulo Sugadores eliminando o bug crônico de 429 do MCP e os 3 problemas de UX reportados pelo usuário em 2026-06-03. O foco operacional volta para **o sugador do dia atual** — 478 itens HOJE deixam de ficar escondidos pelos 1407 acumulados, e ações de 1 clique cortam o caminho de "ver sugador → abrir drilldown → copiar MLBs → agir fora".

Purpose:
- **Acertividade**: usuário enxerga o que importa hoje sem ruído de antigos acumulados; estado de cada empresa (análise OK / sem sync) deixa claro QUANDO os dados estão atualizados.
- **Praticidade**: 1 clique copia os MLBs direto da lista ou do card da empresa, sem entrar no drilldown; 429 do MCP fica raro.

Output:
- 1 PLAN.md (este arquivo)
- Backend: SugadorController.index() com vista default + endpoint mlbs-by-company + Cache::lock no MCP + comando limpar-orfaos
- Frontend: Sugadores/Index.jsx com banner D-1, header "478 HOJE", botões copiar inline e por empresa, badges de estado
- 10 testes Feature em tests/Feature/Phase19/
- Limpeza one-shot dos 1407 sugadores antigos via comando read-only por default
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/19-sugadores-foco-dia/CONTEXT.md

# Memory persistente relevante
@C:/Users/User/.claude/projects/c--xampp-htdocs-ecf-admin-ecf-admin/memory/project_adman_data_sources.md
@C:/Users/User/.claude/projects/c--xampp-htdocs-ecf-admin-ecf-admin/memory/project_sugadores_pagination_limit.md
@C:/Users/User/.claude/projects/c--xampp-htdocs-ecf-admin-ecf-admin/memory/feedback_project_priorities.md

# Código autoritativo (ler antes de editar)
@app/Http/Controllers/SugadorController.php
@app/Services/AdmanMcpService.php
@app/Models/Sugador.php
@app/Models/SugadorAcao.php
@resources/js/Pages/Sugadores/Index.jsx
@resources/js/Pages/Sugadores/Show.jsx
@routes/web.php

<interfaces>
<!-- Contratos relevantes extraídos do código atual. Executor usa diretamente, sem reexplorar. -->

## Sugador (app/Models/Sugador.php)
- TIPO_CAMPANHA = 'campanha', TIPO_ADGROUP = 'adgroup'
- STATUS_PENDENTE = 'pendente', STATUS_AUTO_RESOLVIDO = 'auto_resolvido'
- STATUS_TRAVADOS = ['em_acao', 'resolvido', 'ignorado', 'movido', 'auto_resolvido']
  → IMPORTANTE: 'auto_resolvido' já está em STATUS_TRAVADOS — o comando NÃO precisa adicioná-lo.
- Scopes: ->pendentes(), ->daCarteira($user), ->noPeriodo($from, $to)
- Casts: reference_date é 'date' (Carbon\Carbon::class — comparações com today() funcionam)

## SugadorAcao (app/Models/SugadorAcao.php)
- $timestamps = false; created_at deve ser preenchido manualmente.
- Constantes existentes: ACAO_MARCOU_*, ACAO_VOLTOU_PENDENTE, ACAO_MOVEU, ACAO_AUTO_RESOLVIDO.
- ADICIONAR: public const ACAO_LIMPEZA_ORFAOS = 'limpeza_orfaos';

## SugadorController (app/Http/Controllers/SugadorController.php)
- index() já calcula:
  - $hoje = now()->toDateString()
  - $companies (com cust_id_status)
  - $companiesSummary com count_hoje, total_pendentes, ultima_analise, analisado_hoje (via AdmanSyncLog), cust_id_status
  - $viewMode aceitando 'cards' | 'list' (default 'cards')
  - $request->only(['company_id', 'status', 'tipo', 'date_from', 'date_to', 'user_id', 'include_resolved'])
- show() carrega Sugador com loadMissing; usa Gate::authorize('view')
- mlbs(Sugador $sugador) já retorna JSON com mlbs[].listing_id; usa Cache + retry; throw nunca — sempre response()->json
- Constructor injeta SugadorAnalysisService, AdmanMcpService, AdmanService.

## AdmanMcpService (app/Services/AdmanMcpService.php)
- fetchAllProductAds(string $custId, string $dateFrom, string $dateTo, int $itemsPerPage = 50, int $maxPages = 16, ?string $progressCacheKey = null): array
  → Já tem Cache::remember de 30min. Loop interno itera páginas com usleep(1.5s).
  → ALVO do Cache::lock: envolver o conteúdo do Cache::remember (ou wrap externo da chamada).
- fetchMlbsByCampaign(string $custId, string $campaignId, string $dateFrom, string $dateTo): array
  → Chama listCampaigns + fetchAllProductAds. O lock deve estar dentro de fetchAllProductAds (ou em fetchMlbsByCampaign envolvendo as duas chamadas) para serializar por custId.
- listCampaigns(string $custId): array — também chama MCP; cache 1h.
- call(string $toolName, array $arguments): array — chamada baixa-level com retry; rate limit 50 req/min.

## Routes (routes/web.php linhas 161-181)
- Group atual com middleware ['auth', 'permission:sugadores.ver'] (verificar — usar mesmo padrão dos vizinhos).
- ADICIONAR (mesmo grupo de sugadores.mlbs):
  Route::get('/sugadores/companies/{company}/mlbs-todos', [SugadorController::class, 'mlbsByCompany'])
      ->name('sugadores.mlbs-by-company');

## Frontend (resources/js/Pages/Sugadores/Index.jsx)
- Já recebe: sugadores, companies, users, user_companies, filters, total_pendentes, can_manage, can_analyze, companies_summary, view_mode.
- CompanyCard já existe (linha 123); recebe { card, canAnalyze, enqueuedAt, onReanalisar, onVer }.
- copyToClipboard helper JÁ EXISTE em Show.jsx (linha 34) com fallback HTTP-safe — MOVER para resources/js/lib/utils.js OU duplicar inline em Index.jsx (executor decide; documentar).
- Disclaimer atual já existe na linha 705 ("Dados D-1 da Adman · próxima análise: amanhã 12h") — AMPLIAR com horário da última execução real.

## Show.jsx (referência — não editar)
- copyToClipboard com fallback execCommand já implementado linhas 32-54.
- handleCopy(tag, ids) usa ids.join(',') — mesmo formato a replicar.
</interfaces>
</context>

## Por que este plano entrega o goal

Narrativa SC → Wave (goal-backward):

- **SC-1 (Banner D-1 explícito + estado por empresa)** → W1-T1 amplia `index()` com prop `analise_diaria` (horario_cron='12:00 BRT', ultima_execucao_global) + `card.sincronizou_hoje` no `companies_summary`. W2-T1 renderiza banner + badges no CompanyCard.
- **SC-2 (Vista default só HOJE + pendente)** → W1-T1 aplica filtro automático em `index()` quando não há query params explícitos + adiciona prop `default_view='hoje'`. W2-T2 renderiza header "478 HOJE" + botão "Ver dias anteriores".
- **SC-3 (Copiar MLBs inline na linha)** → W2-T3 adiciona botão na linha do sugador (modo lista) que faz fetch em `/sugadores/{id}/mlbs` e usa o `copyToClipboard` existente. Não exige backend novo.
- **SC-4 (Copiar MLBs da empresa)** → W1-T4 cria endpoint backend `mlbs-by-company` que consolida MLBs com lock por custId (Opção A — ver Decisões). W2-T4 chama o endpoint no CompanyCard com loading state.
- **SC-5 (Mitigação 429 MCP)** → W1-T3 envolve a chamada paginada em `fetchAllProductAds` com `Cache::lock("adman_mcp:custid:{custId}", 30)` — concorrência de chamadas no mesmo custId fica serializada e a paginação inteira (que já tem throttle interno de 1.5s) conclui antes de qualquer outra começar.
- **SC-6 (Comando limpar-orfaos)** → W1-T2 cria o comando (`sugadores:limpar-orfaos {--apply}`). Sem `--apply` é dry-run; com `--apply` faz UPDATE em massa + insert em `sugador_acoes` em chunks de 500.
- **SC-7 (Testes)** → W3 entrega 10 testes Feature cobrindo todos os caminhos acima.

W4 entrega o ciclo operacional: deploy + dry-run + confirmação humana + apply + smoke.

## Decisões importantes (documentar no commit/SUMMARY)

### Decisão 1: W1-T4 — endpoint backend `mlbs-by-company` (Opção A escolhida)

**Opção A (escolhida)**: criar endpoint `GET /sugadores/companies/{company}/mlbs-todos` que, no servidor, itera os sugadores `adgroup pendente reference_date=hoje` da empresa, chama `mcp->fetchMlbsByCampaign(...)` para cada (já cacheado 30min + agora com `Cache::lock`), consolida lista única e retorna JSON.

**Opção B (rejeitada)**: loop no frontend chamando `/sugadores/{id}/mlbs` N vezes.

**Justificativa**:
1. Reusar `Cache::lock` do W1-T3 — o backend processa todos os adgroups da mesma conta serialmente DENTRO de um único lock por custId. No frontend N chamadas paralelas competiriam pelo lock e ficariam em fila independentes, mais frágil.
2. Resposta HTTP única — o frontend mostra um único loading state ("Processando N sugadores...") em vez de orquestrar progresso de N requests.
3. Lock de 30s × N sugadores pode demorar; mitigação: limitar a primeiros 20 adgroups na primeira versão, retornando flag `truncated=true` se houver mais. UI mostra "20 de 47 sugadores — copiar resto?".
4. Cache do `fetchAllProductAds` (30min, key inclui dateFrom/dateTo) é compartilhado: o 1º adgroup paga o custo MCP, os demais leem do cache instantaneamente. Custo amortizado.

**Risco**: se a empresa tiver muitos dateFrom/dateTo distintos (sugadores de dias diferentes), cada um vira cache miss. Mitigação: filtro `reference_date=hoje` no W1-T4 garante uniformidade do range.

### Decisão 2: como expor `analise_diaria` (prop nova vs ampliar companies_summary)

**Escolha**: criar prop nova `analise_diaria` no nível raiz da página (não dentro de `companies_summary`).

**Estrutura**:
```
analise_diaria: {
  horario_cron: '12:00 BRT',
  ultima_execucao_global: ISO-8601 ou null
}
```

E adicionar 2 flags por empresa em `companies_summary[i]`:
- `analisou_hoje: bool` (já existe — manter)
- `sincronizou_hoje: bool` (NOVO — via AdmanSyncLog::whereDate('created_at', today())->whereNull('error_message')->where('company_id', $c->id)->exists() — JÁ está calculado no controller via `$companiesAnalisadasHoje`, expor como `sincronizou_hoje` faz mais sentido semântico já que vem de AdmanSyncLog).

**Por que separado**: `analise_diaria` é metadado global (banner topo), não cabe duplicar dentro de cada card. Flags por empresa ficam em `companies_summary` porque já há um pipeline de aggregate ali.

**Renomeação**: `analisado_hoje` em `companies_summary` reflete sync Adman (vem de AdmanSyncLog). Para evitar quebrar consumers, manter o nome `analisado_hoje` mas ADICIONAR `sincronizou_hoje` (alias) e introduzir `tem_analise_sugadores_hoje` se precisarmos distinguir análise-sugadores de sync-Adman. Por ora: usar `analisado_hoje` para sync Adman (status atual) + `sincronizou_hoje` como alias semântico. Executor decide se mantém alias ou só renomeia (documentar).

## Risco residual

1. **Lock TTL de 30s pode ser curto para contas grandes**: a paginação de 16 páginas com 1.5s entre páginas leva ~24s — folga apertada. Se observarmos timeout em prod, calibrar para 45s ou usar `block(45, fn() => ...)` com retry. Documentado em comentário no W1-T3.
2. **Comando `--apply` pode marcar sugadores que o usuário queria revisitar**: dry-run obrigatório antes (W4-T1) + sumário por empresa permite abort. Se padrão suspeito aparecer (1407 candidatas todas com sync OK + empresa OK), o operador para e investiga em vez de aplicar (auto-resolução pode estar com bug).

<tasks>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- WAVE 1 — Backend                                                     -->
<!-- ═══════════════════════════════════════════════════════════════════ -->

<task type="auto" tdd="true">
  <name>W1-T1: Filtros default HOJE + prop analise_diaria + sincronizou_hoje no index()</name>
  <files>
    app/Http/Controllers/SugadorController.php
  </files>
  <behavior>
    - Sem query params explícitos (request sem `reference_date`, `date_from`, `date_to`, `status`, `include_resolved`, `include_old`): aplicar `where('status', 'pendente')` + `whereDate('reference_date', today())` automaticamente.
    - Com `?include_old=1`: pular o filtro automático de `reference_date` (mantém o resto da lógica existente). Não persistir `include_old` em filters retornado.
    - Com `?reference_date=YYYY-MM-DD` ou `?date_from=...`/`?date_to=...`: pular o filtro automático de data (usuário sabe o que quer).
    - Com `?status=...`: pular o filtro automático de status.
    - Prop nova `default_view`: 'hoje' quando os defaults foram aplicados, 'custom' caso contrário.
    - Prop nova `analise_diaria` { horario_cron: '12:00 BRT', ultima_execucao_global: ISO-8601 ou null }. Fonte de `ultima_execucao_global`: `Sugador::max('created_at')` (cria-se um sugador em cada rodada da análise).
    - Em `companies_summary`, ADICIONAR campo `sincronizou_hoje` (alias semântico do atual `analisado_hoje`, que reflete AdmanSyncLog sem error). Manter `analisado_hoje` para compat.
    - Não quebrar nenhum dos 3 testes Phase 15/16 que tocam SugadorController (verificar com `php artisan test --filter=Sugador` antes de commitar).
  </behavior>
  <action>
    Edita `SugadorController::index` (linhas 33-216):

    1. Adicionar bloco logo após autorização (antes da construção da query) que detecta se a request está em "modo default":
       - `$temFiltroData = $request->filled('reference_date') || $request->filled('date_from') || $request->filled('date_to') || $request->boolean('include_old');`
       - `$temFiltroStatus = $request->filled('status') || $request->boolean('include_resolved');`
       - `$defaultView = (!$temFiltroData && !$temFiltroStatus) ? 'hoje' : 'custom';`

    2. Após o bloco de filtros existente (linhas 54-83), se `$defaultView === 'hoje'`:
       - `$query->whereDate('reference_date', today());`
       - `$query->where('status', Sugador::STATUS_PENDENTE);`
       Coloque ANTES de `$sugadores = $query->paginate(...)`. Os filtros existentes têm precedência (já têm `if ($request->filled(...))` guards).

    3. Calcular `$analiseDiaria` ANTES do `return Inertia::render`:
       ```
       $analiseDiaria = [
           'horario_cron' => '12:00 BRT',
           'ultima_execucao_global' => optional(Sugador::query()->max('created_at'))
               ? \Carbon\Carbon::parse(Sugador::query()->max('created_at'))->toIso8601String()
               : null,
       ];
       ```
       (Não inline — uma única query `Sugador::max('created_at')` armazenada em variável local.)

    4. No map de `$companiesSummary` (linhas 165-184), adicionar:
       ```
       'sincronizou_hoje' => $companiesAnalisadasHoje->has($c->id),
       ```
       (mesmo valor de `analisado_hoje` — alias semântico para o frontend usar nomenclatura clara).

    5. Em `Inertia::render` props (linha 204-215), adicionar:
       - `'default_view' => $defaultView`
       - `'analise_diaria' => $analiseDiaria`

    6. Comentários pt-BR em cada bloco novo explicando a regra (foco no dia atual / D-1 Adman).
  </action>
  <verify>
    <automated>cd c:/xampp/htdocs/ecf_admin/ecf_admin && php artisan test --filter=Sugador 2>&1 | tail -20</automated>
  </verify>
  <done>
    - Visita `/sugadores?view=list` sem filtros → response Inertia tem `default_view=hoje`, `analise_diaria.horario_cron='12:00 BRT'`, e `sugadores.data` só com `reference_date=hoje` e `status=pendente`.
    - Visita `/sugadores?include_old=1` → `default_view=custom`, lista inclui anteriores.
    - Visita `/sugadores?reference_date=2026-06-01` → `default_view=custom`, filtro respeita data específica.
    - Suíte Sugador existente (Phase 15/16) continua verde.
  </done>
</task>

<task type="auto" tdd="true">
  <name>W1-T2: Comando sugadores:limpar-orfaos + constante ACAO_LIMPEZA_ORFAOS</name>
  <files>
    app/Console/Commands/LimparOrfaosSugadores.php
    app/Models/SugadorAcao.php
  </files>
  <behavior>
    - `php artisan sugadores:limpar-orfaos` (sem flag): dry-run. Imprime total candidato + breakdown por empresa (top 10 + "outras N empresas com M sugadores"). Nenhum write.
    - `php artisan sugadores:limpar-orfaos --apply`: dentro de DB::transaction:
      - UPDATE em massa em sugadores: candidatos viram `status='auto_resolvido'`, `resolvido_em=now()`, `resolvido_por=null`.
      - INSERT em massa em sugador_acoes (chunks de 500): `acao='limpeza_orfaos'`, `status_anterior='pendente'`, `status_novo='auto_resolvido'`, `observacao='Limpeza one-shot Phase 19 — sugador antigo não-redetectado'`, `user_id=null`, `created_at=now()`.
      - Log info `[LimparOrfaos] N sugadores marcados como auto_resolvido`.
    - Critério de candidato: `Sugador::where('status', 'pendente')->where('reference_date', '<', today()->toDateString())`.
    - NÃO toca em sugadores de HOJE.
    - NÃO toca em STATUS_TRAVADOS (já garantido pelo filtro `status='pendente'`).
    - Mensagens, prompt e log em pt-BR.
  </behavior>
  <action>
    1. Em `app/Models/SugadorAcao.php` adicionar constante (após linha 33):
       `public const ACAO_LIMPEZA_ORFAOS = 'limpeza_orfaos';`

    2. Criar `app/Console/Commands/LimparOrfaosSugadores.php`:
       - Namespace `App\Console\Commands`.
       - Signature: `protected $signature = 'sugadores:limpar-orfaos {--apply : Aplica o UPDATE; sem essa flag é dry-run}';`
       - Description: `'Marca como auto_resolvido sugadores pendentes com reference_date anterior a hoje (limpeza one-shot Phase 19).'`
       - `handle()`:
         a. Carregar `$candidatos = Sugador::where('status', Sugador::STATUS_PENDENTE)->where('reference_date', '<', today()->toDateString())->select('id', 'company_id')->get();`
         b. Se `$candidatos->isEmpty()` → `info('Nenhum sugador órfão encontrado.')` e retornar 0.
         c. Sumário: `$total = $candidatos->count(); $porEmpresa = $candidatos->groupBy('company_id')->map->count()->sortDesc();`
         d. Carregar nomes das empresas em uma query: `$nomes = Company::whereIn('id', $porEmpresa->keys())->pluck('name', 'id');`
         e. Print tabela top 10: `$this->table(['Empresa', 'Sugadores'], $porEmpresa->take(10)->map(fn($n, $id) => [$nomes[$id] ?? "#{$id}", $n])->values()->all());`
         f. Se `> 10`: `$this->line(sprintf('+ %d empresas com %d sugadores no resto.', $porEmpresa->count() - 10, $porEmpresa->skip(10)->sum()));`
         g. Print total: `$this->info("Total candidato: {$total} sugadores.");`
         h. Se NÃO `--apply`: `$this->warn('Dry-run — nenhuma mudança aplicada. Rode com --apply para aplicar.');` e retornar 0.
         i. Se `--apply`: `DB::transaction(function () use ($candidatos, $total) { ... })`:
            - `$ids = $candidatos->pluck('id')->all();`
            - `Sugador::whereIn('id', $ids)->update(['status' => Sugador::STATUS_AUTO_RESOLVIDO, 'resolvido_em' => now(), 'resolvido_por' => null]);`
            - Inserts em chunks de 500: para cada chunk gerar array `[['sugador_id' => $id, 'user_id' => null, 'acao' => SugadorAcao::ACAO_LIMPEZA_ORFAOS, 'status_anterior' => Sugador::STATUS_PENDENTE, 'status_novo' => Sugador::STATUS_AUTO_RESOLVIDO, 'observacao' => 'Limpeza one-shot Phase 19 — sugador antigo não-redetectado', 'created_at' => $now], ...]` e chamar `SugadorAcao::insert($chunk);`
            - `Log::info("[LimparOrfaos] {$total} sugadores marcados como auto_resolvido");`
            - `$this->info("Aplicado: {$total} sugadores marcados como auto_resolvido.");`

    3. Comentário no topo do arquivo: "Comando one-shot Phase 19. Read-only por default; --apply explícito. Reduz acúmulo dos 1407 pendentes antigos (CONTEXT.md §Estado atual)."
  </action>
  <verify>
    <automated>cd c:/xampp/htdocs/ecf_admin/ecf_admin && php artisan test --filter=LimparOrfaos 2>&1 | tail -10</automated>
  </verify>
  <done>
    - `php artisan sugadores:limpar-orfaos --help` mostra a signature.
    - Em ambiente de teste (RefreshDatabase + factories): comando dry-run lista candidatos sem write; comando --apply atualiza tabela e insere audit log.
    - Sugadores com `reference_date=hoje` e sugadores em STATUS_TRAVADOS não são tocados (validado nos testes W3-T2).
  </done>
</task>

<task type="auto" tdd="true">
  <name>W1-T3: Cache::lock por custId em AdmanMcpService::fetchAllProductAds</name>
  <files>
    app/Services/AdmanMcpService.php
  </files>
  <behavior>
    - Concorrência: 2 requests HTTP simultâneas chamando `fetchMlbsByCampaign` para o mesmo `custId` serializam — a segunda espera a primeira terminar (ou cacheada) antes de prosseguir.
    - Concorrência entre custIds diferentes: continuam paralelas (lock é por custId, não global).
    - Cache de 30min do `fetchAllProductAds` continua funcionando — lock só é necessário quando há cache miss.
    - Comportamento de erro: se o lock não for adquirido em 30s, lança `RuntimeException('Adman MCP ocupado para a conta {custId} — tente novamente em alguns segundos.')`. Caller (SugadorController::mlbs) já trata throws como 502.
    - Constante de TTL/timeout em comentário: documentar que 30s cobre a paginação típica de 16 páginas × 1.5s = 24s + folga TLS.
  </behavior>
  <action>
    Em `app/Services/AdmanMcpService.php`:

    1. Em `fetchAllProductAds()` (linha 147), envolver o `Cache::remember(...)` interno com `Cache::lock`:
       ```
       $lockKey = "adman_mcp:custid:{$custId}";
       // MCP da Adman tem rate limit 50 req/min global por API key. Múltiplas
       // chamadas concorrentes para o mesmo custId (drilldown de N adgroups da
       // mesma empresa) estouram em getMarketplaceadsCustIdproductAdsmetrics.
       // Lock por custId serializa — a paginação interna já tem throttle 1.5s.
       // TTL 30s cobre 16 páginas × 1.5s = 24s + folga TLS.
       $lock = Cache::lock($lockKey, 30);
       return $lock->block(30, function () use ($custId, $dateFrom, $dateTo, $itemsPerPage, $maxPages, $progressCacheKey, $cacheKey) {
           return Cache::remember($cacheKey, now()->addMinutes(30), function () use (...) {
               // ... corpo original do Cache::remember inalterado ...
           });
       });
       ```
       Mover a definição de `$cacheKey` para ANTES do lock para passar via use.
       Se `block(30, ...)` levantar `LockTimeoutException`, capturar e relançar como `RuntimeException` com mensagem pt-BR.

    2. Comentário pt-BR explicando:
       - Por que lock por custId (não global): permite paralelismo entre contas distintas.
       - Por que 30s: cobre paginação típica + folga TLS.
       - Por que NÃO envolve `listCampaigns`: cache de 1h cobre case comum + chamada é leve (1 endpoint).
       - Driver compatível: database (Laravel 12 DatabaseLock — em uso em prod, CACHE_STORE=database).

    3. NÃO mexer em `fetchMlbsByCampaign` (linha 255) — o lock em `fetchAllProductAds` cobre o ponto crítico de paginação. `listCampaigns` (linha 210) tem cache 1h e não precisa de lock.
  </action>
  <verify>
    <automated>cd c:/xampp/htdocs/ecf_admin/ecf_admin && php artisan test --filter=CopiarMlbs 2>&1 | tail -10</automated>
  </verify>
  <done>
    - Teste W3-T3 valida: 2 chamadas concorrentes ao mesmo custId serializam; 1 timeout em 30s relança RuntimeException pt-BR.
    - Smoke manual (W4): abrir adgroup-sugador de conta grande, clicar "Carregar MLBs", NÃO ver 429 (cache + lock cobrem).
  </done>
</task>

<task type="auto" tdd="true">
  <name>W1-T4: Endpoint mlbsByCompany + rota + lista consolidada com lock compartilhado</name>
  <files>
    app/Http/Controllers/SugadorController.php
    routes/web.php
  </files>
  <behavior>
    - GET `/sugadores/companies/{company}/mlbs-todos` retorna JSON:
      ```
      {
        mlbs: ['MLB123', 'MLB456', ...],        // únicos, ordenados
        total_mlbs: N,                            // count(mlbs)
        sugadores_processados: M,                 // adgroups que retornaram MLBs
        sugadores_solicitados: K,                 // adgroups da empresa pendentes hoje
        truncated: bool,                          // true se foram limitados a 20 adgroups
      }
      ```
    - Critério: sugadores da empresa com `tipo='adgroup' status='pendente' reference_date=today()`.
    - Limite por chamada: 20 adgroups (para evitar request gigante). Se `count > 20`, processa os 20 primeiros (ordem `created_at DESC` — mais recentes primeiro) e retorna `truncated=true`.
    - Reusa `mcp->fetchMlbsByCampaign(...)` que JÁ tem `Cache::lock` (W1-T3). 20 chamadas para o mesmo custId rodam serialmente DENTRO do lock — depois do 1º acabar e cachear, demais são cache hits (mesmo dateFrom/dateTo).
    - Autorização: mesmo que `mlbs()` — mas usa Company route binding. Apply scope da carteira como `sgiCampaigns()` faz (linhas 353-363): admin/gestor/lider vê todas; demais só carteira.
    - Empresa sem `adman_account_id` → 422 com `reason` pt-BR.
    - MCP não configurada → 503 com `reason`.
    - Erro genérico → 502 com `reason: 'Falha ao consultar MCP: ...'`.
  </behavior>
  <action>
    1. Adicionar método público em `SugadorController` (após `mlbs()`):
       ```
       public function mlbsByCompany(Request $request, Company $company)
       {
           // Autorização: mesma regra do drilldown — admin/gestor/lider veem tudo;
           // demais só carteira. Replica padrão de sgiCampaigns().
           $user = $request->user();
           $isGlobal = $user->isAdmin()
               || (method_exists($user, 'isGestor') && $user->isGestor())
               || (method_exists($user, 'isLiderPub') && $user->isLiderPub());
           if (!$isGlobal && !$user->companies()->where('companies.id', $company->id)->exists()) {
               abort(403, 'Sem acesso a esta empresa.');
           }

           if (!$company->adman_account_id) {
               return response()->json(['mlbs' => [], 'total_mlbs' => 0,
                   'sugadores_processados' => 0, 'sugadores_solicitados' => 0,
                   'truncated' => false,
                   'reason' => 'Empresa sem adman_account_id.'], 422);
           }
           if (!$this->mcp->isConfigured()) {
               return response()->json(['mlbs' => [], 'reason' => 'MCP da Adman não configurada.'], 503);
           }

           $hoje = now()->toDateString();
           $adgroups = Sugador::where('company_id', $company->id)
               ->where('tipo', Sugador::TIPO_ADGROUP)
               ->where('status', Sugador::STATUS_PENDENTE)
               ->whereDate('reference_date', $hoje)
               ->orderByDesc('created_at')
               ->get(['id', 'campaign_id', 'periodo_inicio', 'periodo_fim']);

           $solicitados = $adgroups->count();
           $LIMITE = 20;          // evita request gigante; UI mostra "20 de 47"
           $alvos = $adgroups->take($LIMITE);
           $truncated = $solicitados > $LIMITE;

           @set_time_limit(0);    // mesma justificativa do mlbs() — TLS lento

           $mlbsSet = [];
           $processados = 0;

           foreach ($alvos as $s) {
               $dateFrom = optional($s->periodo_inicio)->toDateString() ?? now()->subDays(7)->toDateString();
               $dateTo   = optional($s->periodo_fim)->toDateString()    ?? now()->subDay()->toDateString();
               try {
                   $res = $this->mcp->fetchMlbsByCampaign(
                       $company->adman_account_id,
                       (string) $s->campaign_id,
                       $dateFrom,
                       $dateTo,
                   );
                   foreach ($res['mlbs'] ?? [] as $m) {
                       $id = $m['listing_id'] ?? null;
                       if ($id) $mlbsSet[$id] = true;
                   }
                   $processados++;
               } catch (\Throwable $e) {
                   Log::warning("[Sugadores/MlbsByCompany] sugador {$s->id} falhou: " . $e->getMessage());
                   // Continua com os outros — falha de 1 não interrompe o lote.
               }
           }

           $mlbs = array_keys($mlbsSet);
           sort($mlbs);

           return response()->json([
               'mlbs' => $mlbs,
               'total_mlbs' => count($mlbs),
               'sugadores_processados' => $processados,
               'sugadores_solicitados' => $solicitados,
               'truncated' => $truncated,
           ]);
       }
       ```

    2. Em `routes/web.php`, no mesmo grupo dos `/sugadores/*` (verificar linha 165-181 — adicionar APÓS linha 173):
       ```
       Route::get('/sugadores/companies/{company}/mlbs-todos',
           [SugadorController::class, 'mlbsByCompany'])
           ->name('sugadores.mlbs-by-company');
       ```

    3. Comentário pt-BR no topo do método explicando reuso do Cache::lock + cache 30min compartilhado (1º adgroup paga, demais leem cache).
  </action>
  <verify>
    <automated>cd c:/xampp/htdocs/ecf_admin/ecf_admin && php artisan test --filter=CopiarMlbs::test_mlbs_by_company 2>&1 | tail -10</automated>
  </verify>
  <done>
    - Rota nomeada `sugadores.mlbs-by-company` existe (verificar com `php artisan route:list | grep mlbs-by-company`).
    - Endpoint retorna lista consolidada única para empresa com 2+ sugadores adgroup hoje.
    - Empresa sem adman_account_id → 422.
    - Empresa com >20 adgroups → response com `truncated=true` e 20 MLBs processados.
  </done>
</task>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- WAVE 2 — Frontend                                                    -->
<!-- ═══════════════════════════════════════════════════════════════════ -->

<task type="auto">
  <name>W2-T1: Banner D-1 expandido com última execução + badges de estado no CompanyCard</name>
  <files>
    resources/js/Pages/Sugadores/Index.jsx
  </files>
  <action>
    1. Importar prop `analise_diaria` no `export default function SugadoresIndex(...)` (linha 486).

    2. Substituir o disclaimer atual (linhas 703-710) por bloco compacto pt-BR:
       - Texto: `Análise diária roda às 12h BRT · Última execução: {fmtRelative(analise_diaria.ultima_execucao_global)}` (ou "—" se null).
       - Estilo: igual ao disclaimer existente (mesmo container) — apenas ampliar texto.
       - Visível em AMBOS modos (cards e lista) — está no header geral, fora dos blocos `view_mode === ...`.

    3. Em `CompanyCard` (linha 123), adicionar badge minúsculo no header (ao lado de CustIdInvalidoBadge):
       - Se `card.sincronizou_hoje === true` (alias de `analisado_hoje`): badge verde "Análise OK hoje".
       - Se `card.sincronizou_hoje === false` E `card.cust_id_status === 'invalido'`: badge vermelho "Sem sync hoje" (overlap com CustIdInvalidoBadge — escolher um; preferir CustIdInvalidoBadge porque é causa raiz).
       - Se `card.sincronizou_hoje === false` E `card.cust_id_status !== 'invalido'`: badge cinza "Sem análise hoje".
       - Tooltip pt-BR em cada caso.
       - Tailwind: reusar classes `text-[10px] font-semibold px-1.5 py-0.5 rounded` + variantes de cor (emerald/red/zinc).

    4. Comentário pt-BR sobre semântica: "sincronizou_hoje" reflete AdmanSyncLog (cron 11h Adman) — se sync falhou, sugadores também não rodam, então o operador entende por que count_hoje pode estar zerado.
  </action>
  <verify>
    <automated>cd c:/xampp/htdocs/ecf_admin/ecf_admin && npm run build 2>&1 | tail -10</automated>
  </verify>
  <done>
    - `npm run build` sem warnings (além dos pré-existentes).
    - Banner topo mostra "Análise diária roda às 12h BRT · Última execução: há X horas".
    - CompanyCard mostra 1 badge de estado (verde/cinza/vermelho) próximo ao nome.
  </done>
</task>

<task type="auto">
  <name>W2-T2: Vista default HOJE com header destacado + toggle "Ver dias anteriores"</name>
  <files>
    resources/js/Pages/Sugadores/Index.jsx
  </files>
  <action>
    1. Importar prop `default_view` (linha 486, junto com analise_diaria).

    2. Quando `view_mode === 'list'`:
       - Se `default_view === 'hoje'`: renderizar header destaque acima da tabela:
         ```
         <div className="mb-4 flex items-center justify-between gap-3 flex-wrap">
           <div>
             <span className="text-ecf-yellow font-display font-bold text-3xl tabular-nums">{fmtInt(meta.total)}</span>
             <span className="text-white/60 text-sm ml-2">sugadores HOJE</span>
           </div>
           <button onClick={() => router.get(route('sugadores.index'), { view: 'list', include_old: 1 })}
                   className="...">Ver dias anteriores</button>
         </div>
         ```
       - Se `default_view === 'custom'` E `include_old` veio nas filters: renderizar header alternativo:
         ```
         Inclui dias anteriores: {fmtInt(meta.total)} total
         [Voltar para HOJE] → router.get(route('sugadores.index'), { view: 'list' })
         ```

    3. Em `applyFilters` e `clearFilters` (linhas 543-566), garantir que `include_old` é preservado quando o usuário muda outros filtros (após escolher "Ver dias anteriores", filtros adicionais não devem voltar ao modo default).
       - Adicionar `include_old` ao estado `f` (default '').
       - No `clean` filter (linha 557), incluir `include_old` quando truthy.

    4. NÃO mexer em modo cards — vista default só afeta modo lista (cards já mostra HOJE em destaque por design).

    5. Comentário pt-BR explicando: "default_view='hoje' é a regra de praticidade — o operador vê 478 em vez de 1885 e foca no que importa hoje."
  </action>
  <verify>
    <automated>cd c:/xampp/htdocs/ecf_admin/ecf_admin && npm run build 2>&1 | tail -10</automated>
  </verify>
  <done>
    - `npm run build` OK.
    - Manual (W4): visita `/sugadores?view=list` mostra header "N sugadores HOJE" + botão "Ver dias anteriores".
    - Clicar em "Ver dias anteriores" navega para `?include_old=1` e header muda para "Inclui dias anteriores".
  </done>
</task>

<task type="auto">
  <name>W2-T3: Botão Copiar MLBs inline na linha do sugador (modo lista)</name>
  <files>
    resources/js/Pages/Sugadores/Index.jsx
  </files>
  <action>
    1. Copiar o helper `copyToClipboard` de `Show.jsx` (linhas 32-54) para escopo do módulo `Index.jsx` (após os `STATUS_LABELS` no topo). Comentário pt-BR explicando duplicação (futuro: extrair pra resources/js/lib/utils.js — não nesta fase).

    2. Adicionar estado local no componente principal (após `enqueuedAt`):
       ```
       const [copyingId, setCopyingId] = useState(null);  // sugador_id em loading
       const [copiedFeedback, setCopiedFeedback] = useState({});  // sugador_id → {tag, count}
       ```

    3. Função handler:
       ```
       async function copyMlbsLinha(sugadorId) {
           setCopyingId(sugadorId);
           try {
               const r = await fetch(route('sugadores.mlbs', sugadorId), { headers: { Accept: 'application/json' } });
               if (!r.ok) throw new Error(`HTTP ${r.status}`);
               const j = await r.json();
               const mlbs = (j.mlbs || []).map(m => m.listing_id).filter(Boolean);
               if (!mlbs.length) {
                   setCopiedFeedback(p => ({ ...p, [sugadorId]: { count: 0, error: 'Sem MLBs' } }));
               } else {
                   const ok = await copyToClipboard(mlbs.join(','));
                   setCopiedFeedback(p => ({ ...p, [sugadorId]: { count: mlbs.length, ok } }));
               }
           } catch (e) {
               setCopiedFeedback(p => ({ ...p, [sugadorId]: { error: 'Tente novamente' } }));
           } finally {
               setCopyingId(null);
               // Limpa feedback após 2s
               setTimeout(() => setCopiedFeedback(p => { const n = {...p}; delete n[sugadorId]; return n; }), 2000);
           }
       }
       ```

    4. No render da linha de sugador (encontrar bloco que renderiza ações por sugador no modo lista — provavelmente após o checkbox de bulk e antes de "Ver detalhes"). Inserir botão APENAS se `s.tipo === 'adgroup'`:
       ```
       {s.tipo === 'adgroup' && (
           <button
               type="button"
               onClick={() => copyMlbsLinha(s.id)}
               disabled={copyingId === s.id}
               title="Copia todos os MLBs deste sugador para o clipboard"
               className="inline-flex items-center gap-1 h-7 px-2 rounded border border-white/[0.08] bg-white/[0.03] text-white/60 hover:text-white hover:bg-white/[0.05] text-[11px]"
           >
               {copyingId === s.id ? <Loader2 size={11} className="animate-spin" />
                : copiedFeedback[s.id]?.ok ? <Check size={11} className="text-emerald-300" />
                : <Copy size={11} />}
               {copyingId === s.id ? '...'
                : copiedFeedback[s.id]?.ok ? `Copiado ${copiedFeedback[s.id].count}`
                : copiedFeedback[s.id]?.error ? copiedFeedback[s.id].error
                : 'Copiar MLBs'}
           </button>
       )}
       ```
       (Importar `Copy`, `Check`, `Loader2` de lucide-react no topo do arquivo se ainda não estão.)

    5. Comentário pt-BR: "1 clique do operador — sem entrar no drilldown, sem perder contexto da lista."
  </action>
  <verify>
    <automated>cd c:/xampp/htdocs/ecf_admin/ecf_admin && npm run build 2>&1 | tail -10</automated>
  </verify>
  <done>
    - `npm run build` OK.
    - Manual (W4): modo lista, clique no botão "Copiar MLBs" de uma linha adgroup mostra spinner + "Copiado N" 2s. Clipboard contém lista CSV.
    - Sugadores tipo `campanha` não mostram o botão.
  </done>
</task>

<task type="auto">
  <name>W2-T4: Botão Copiar MLBs da empresa no CompanyCard (modo cards)</name>
  <files>
    resources/js/Pages/Sugadores/Index.jsx
  </files>
  <action>
    1. Em `CompanyCard` (linha 123), adicionar prop `onCopyMlbs` + estado de loading externo controlado pelo pai.

    2. Renderizar botão entre "Ver sugadores" e "Reanalisar" — APENAS se `card.count_hoje > 0`:
       ```
       {card.count_hoje > 0 && (
           <button
               type="button"
               onClick={() => onCopyMlbs(card.company_id)}
               disabled={copyingEmpresaId === card.company_id}
               className="inline-flex items-center justify-center gap-1.5 h-8 px-2.5 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] text-[12px]"
               title={`Copia MLBs de todos os ${card.count_hoje} sugadores adgroup desta empresa (HOJE)`}
           >
               {copyingEmpresaId === card.company_id
                   ? <><Loader2 size={11} className="animate-spin" /> Processando...</>
                   : copiedEmpresaFeedback[card.company_id]
                       ? <><Check size={11} className="text-emerald-300" /> Copiado {copiedEmpresaFeedback[card.company_id].total}</>
                       : <><Copy size={11} /> Copiar MLBs</>}
           </button>
       )}
       ```
       Passar como novas props para CompanyCard: `copyingEmpresaId`, `copiedEmpresaFeedback`, `onCopyMlbs`.

    3. Handler no componente principal (perto de `reanalisarEmpresa`):
       ```
       const [copyingEmpresaId, setCopyingEmpresaId] = useState(null);
       const [copiedEmpresaFeedback, setCopiedEmpresaFeedback] = useState({});

       async function copyMlbsEmpresa(companyId) {
           setCopyingEmpresaId(companyId);
           try {
               const r = await fetch(route('sugadores.mlbs-by-company', companyId),
                                     { headers: { Accept: 'application/json' } });
               if (!r.ok) throw new Error(`HTTP ${r.status}`);
               const j = await r.json();
               const mlbs = j.mlbs || [];
               if (!mlbs.length) {
                   setCopiedEmpresaFeedback(p => ({ ...p, [companyId]: { total: 0, error: 'Sem MLBs' } }));
                   return;
               }
               const ok = await copyToClipboard(mlbs.join(','));
               setCopiedEmpresaFeedback(p => ({ ...p, [companyId]: { total: j.total_mlbs, processados: j.sugadores_processados, truncated: j.truncated, ok } }));
           } catch (e) {
               setCopiedEmpresaFeedback(p => ({ ...p, [companyId]: { error: 'Falhou — tente em alguns segundos.' } }));
           } finally {
               setCopyingEmpresaId(null);
               setTimeout(() => setCopiedEmpresaFeedback(p => { const n = {...p}; delete n[companyId]; return n; }), 4000);
           }
       }
       ```

    4. Passar handler ao `<CompanyCard ... onCopyMlbs={copyMlbsEmpresa} copyingEmpresaId={copyingEmpresaId} copiedEmpresaFeedback={copiedEmpresaFeedback} />` (linha 834).

    5. Pequeno texto auxiliar abaixo do botão quando `truncated`: "(20 de N — copie cada parte)" para deixar claro que pode haver mais.

    6. Comentário pt-BR: "Reusa endpoint mlbs-by-company que serializa MCP via Cache::lock por custId. 1º adgroup paga o custo (~15s TLS); demais leem do cache compartilhado."
  </action>
  <verify>
    <automated>cd c:/xampp/htdocs/ecf_admin/ecf_admin && npm run build 2>&1 | tail -10</automated>
  </verify>
  <done>
    - `npm run build` OK.
    - Manual (W4): modo cards, clicar "Copiar MLBs" em card com count_hoje>0 mostra "Processando..." e depois "Copiado N" + clipboard contém lista CSV.
    - Card com `count_hoje=0` NÃO mostra o botão.
  </done>
</task>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- WAVE 3 — Testes Feature                                              -->
<!-- ═══════════════════════════════════════════════════════════════════ -->

<task type="auto" tdd="true">
  <name>W3-T1: SugadoresVistaDefaultTest (4 testes)</name>
  <files>
    tests/Feature/Phase19/SugadoresVistaDefaultTest.php
  </files>
  <action>
    Criar arquivo com `RefreshDatabase`. Setup factory que cria:
    - 1 user admin
    - 1 company
    - 2 sugadores HOJE status=pendente
    - 3 sugadores anteriores (reference_date=yesterday) status=pendente
    - 1 sugador HOJE status=auto_resolvido (não deve aparecer no default)

    Testes:
    1. `test_default_view_filtra_hoje_e_pendente`:
       - GET `/sugadores?view=list` (sem outros params)
       - Assert: response Inertia tem `default_view === 'hoje'`
       - Assert: `sugadores.data` tem exatamente 2 itens (só os HOJE pendentes)
       - Assert: prop `analise_diaria.horario_cron === '12:00 BRT'`
       - Assert: prop `analise_diaria.ultima_execucao_global` é string ISO-8601 não-null

    2. `test_include_old_inclui_anteriores`:
       - GET `/sugadores?view=list&include_old=1`
       - Assert: `default_view === 'custom'`
       - Assert: `sugadores.data` tem 5 itens (2 hoje + 3 anteriores; auto_resolvido fica de fora pelo default de status)

    3. `test_reference_date_explicito_override_default`:
       - GET `/sugadores?view=list&reference_date=` (yesterday)
       - Assert: `default_view === 'custom'`
       - Assert: lista contém apenas sugadores do reference_date informado

    4. `test_companies_summary_inclui_sincronizou_hoje`:
       - Cria AdmanSyncLog de hoje sem error_message
       - GET `/sugadores?view=cards`
       - Assert: `companies_summary[0].sincronizou_hoje === true`
       - Assert: `companies_summary[0].analisado_hoje === true` (alias mantido)

    Helper de assert Inertia props: usar `$response->viewData('page')['props']`.
  </action>
  <verify>
    <automated>cd c:/xampp/htdocs/ecf_admin/ecf_admin && php artisan test --filter=SugadoresVistaDefault 2>&1 | tail -15</automated>
  </verify>
  <done>4 testes verdes.</done>
</task>

<task type="auto" tdd="true">
  <name>W3-T2: LimparOrfaosSugadoresTest (4 testes)</name>
  <files>
    tests/Feature/Phase19/LimparOrfaosSugadoresTest.php
  </files>
  <action>
    Setup: cria mix de sugadores via factory:
    - 2 pendentes ANTIGOS (reference_date < today)
    - 1 pendente HOJE
    - 1 em STATUS_TRAVADOS (em_acao) ANTIGO
    - 1 já auto_resolvido ANTIGO

    Testes:
    1. `test_dry_run_nao_aplica_mudancas`:
       - `Artisan::call('sugadores:limpar-orfaos')` (sem --apply)
       - Assert: nenhum sugador mudou status (snapshot antes/depois)
       - Assert: nenhum SugadorAcao foi criado
       - Assert: output contém "Dry-run" e contagem correta (2 candidatos)

    2. `test_apply_marca_antigos_como_auto_resolvido`:
       - `Artisan::call('sugadores:limpar-orfaos', ['--apply' => true])`
       - Assert: os 2 ANTIGOS pendentes viraram `auto_resolvido` com `resolvido_em` setado e `resolvido_por=null`
       - Assert: 2 SugadorAcao criados com `acao='limpeza_orfaos'`, `status_anterior='pendente'`, `status_novo='auto_resolvido'`, `user_id=null`

    3. `test_status_travados_nao_sao_tocados`:
       - O sugador em `em_acao` (mesmo antigo) NÃO muda de status (filtro de query bloqueia)
       - O sugador `auto_resolvido` antigo NÃO é re-marcado nem cria action duplicada

    4. `test_hoje_nao_e_tocado`:
       - O sugador pendente de HOJE permanece pendente
       - Nenhuma SugadorAcao criada para esse id
  </action>
  <verify>
    <automated>cd c:/xampp/htdocs/ecf_admin/ecf_admin && php artisan test --filter=LimparOrfaosSugadores 2>&1 | tail -15</automated>
  </verify>
  <done>4 testes verdes.</done>
</task>

<task type="auto" tdd="true">
  <name>W3-T3: CopiarMlbsTest (2 testes)</name>
  <files>
    tests/Feature/Phase19/CopiarMlbsTest.php
  </files>
  <action>
    Setup:
    - 1 user admin, 1 company com adman_account_id
    - 3 sugadores adgroup pendentes HOJE da mesma empresa, com `campaign_id` distintos

    Testes:
    1. `test_mlbs_by_company_retorna_lista_unica`:
       - Mockar `AdmanMcpService` no container: `$this->mock(AdmanMcpService::class, function ($m) { $m->shouldReceive('isConfigured')->andReturn(true); $m->shouldReceive('fetchMlbsByCampaign')->andReturn(['mlbs' => [['listing_id' => 'MLB1'], ['listing_id' => 'MLB2']], ...]); });`
       - Para o 2º sugador: retornar `['MLB2', 'MLB3']` (overlap proposital)
       - GET `/sugadores/companies/{id}/mlbs-todos`
       - Assert: status 200, json `mlbs === ['MLB1', 'MLB2', 'MLB3']` (únicos, ordenados)
       - Assert: `total_mlbs === 3`, `sugadores_processados === 3`, `truncated === false`

    2. `test_cache_lock_serializa_chamadas_concorrentes`:
       - Estratégia simples (sem fork de processo): usar `Cache::lock("adman_mcp:custid:{$custId}", 30)->get()` antes de chamar o endpoint, simulando outra request em andamento.
       - Iniciar lock manualmente em outro process via `Cache::lock(...)->get()`, depois chamar endpoint mock e validar:
         - Se o `block(30, ...)` é honrado: chamada espera lock liberar.
         - Como teste prático: validar que `Cache::lock` é chamada DENTRO de `fetchAllProductAds` via `Cache::shouldReceive('lock')->with('adman_mcp:custid:CUST123', 30)->andReturn($mockLock)` e que `$mockLock->shouldReceive('block')->once();`.
       - Alternativa pragmática: teste de integração direto chamando 2x o método em sequência e verificando que ambos completam sem erro (cache hit no segundo). Documentar limitação se for usar essa via.

    NOTE: o teste 2 é o mais frágil. Se complexidade ficar fora do orçamento de contexto, simplificar para apenas "Cache::lock foi invocada com chave correta" usando Mockery — suficiente para regression.
  </action>
  <verify>
    <automated>cd c:/xampp/htdocs/ecf_admin/ecf_admin && php artisan test --filter=CopiarMlbs 2>&1 | tail -15</automated>
  </verify>
  <done>2 testes verdes.</done>
</task>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- WAVE 4 — Operacional (checkpoint humano)                             -->
<!-- ═══════════════════════════════════════════════════════════════════ -->

<task type="checkpoint:human-action" gate="blocking">
  <what-built>
    Backend (filtros default + comando limpar-orfaos + Cache::lock + endpoint mlbs-by-company), frontend (banner D-1 + vista HOJE + botões copiar), e 10 testes Feature todos verdes. Pronto para deploy.
  </what-built>
  <how-to-verify>
    Execute estes passos NA ORDEM (não pule):

    1. **Build local**:
       ```
       npm run build
       php artisan test --filter=Phase19
       ```
       Esperado: build sem erros + 10 testes verdes.

    2. **Push + deploy**: rode o script de deploy do projeto (`deploy.sh` ou `deploy_parcial.sh`). Aguarde término.

    3. **Dry-run em prod (read-only)** via SSH no VPS:
       ```
       cd /var/www/html/ecf_admin && php artisan sugadores:limpar-orfaos
       ```
       Esperado: tabela com top 10 empresas + "Total candidato: ~1407 sugadores" + warning "Dry-run".

    4. **CONFIRME COMIGO antes de aplicar** — cole aqui o output do dry-run.
       Se o total bater com expectativa (~1407) e nenhum padrão estranho aparecer (e.g., empresa com >300 sugadores que deveria estar sincronizando OK), prossiga.
       Se aparecer algo suspeito, NÃO rode --apply; investigue antes (pode indicar bug em auto-resolução).

    5. **Após meu OK**, rode `--apply` em prod:
       ```
       php artisan sugadores:limpar-orfaos --apply
       ```
       Esperado: "Aplicado: N sugadores marcados como auto_resolvido."

    6. **Validar UI em prod** (https://admin.ecfconsultoria.com.br/sugadores):
       - Banner "Análise diária roda às 12h BRT · Última execução: ..." visível.
       - Vista default (modo lista) mostra só HOJE; header "N sugadores HOJE" + botão "Ver dias anteriores".
       - Botão "Copiar MLBs" inline em uma linha adgroup funciona (cole no Bloco de Notas e confira CSV).
       - Botão "Copiar MLBs" no card de uma empresa com count_hoje>0 funciona.
       - Badges de estado (Análise OK / Sem análise / Sem sync) aparecem nos cards.

    7. **Smoke do bug 429**:
       - Abrir um adgroup-sugador de conta grande (Stand Brasil ou similar com 30+ adgroups hoje).
       - Clicar "Carregar MLBs".
       - Esperado: carrega normalmente (cache + lock). Se aparecer 429, registrar no SUMMARY.md como follow-up (não bloqueia merge).
  </how-to-verify>
  <resume-signal>
    Cole o output completo do dry-run para revisão. Após meu "OK aplicar", rode `--apply` e confirme o output do --apply + screenshots de UI. Type "deploy concluído" quando smoke passar.
  </resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser → /sugadores/* | Input do usuário (filtros, IDs de empresa/sugador) cruza para o backend |
| backend → Adman MCP | Chamadas HTTP para mcp.ad-man.io com api-key; rate limit 50/min |
| artisan CLI → DB | Comando `sugadores:limpar-orfaos` executa UPDATE/INSERT em massa |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-19-01 | Tampering | `mlbsByCompany` endpoint | mitigate | Authorization scope da carteira (W1-T4) — não-admin sem company nas suas relações recebe 403 |
| T-19-02 | DoS | MCP via mlbs-by-company com N adgroups | mitigate | Limite hardcoded de 20 adgroups por request + Cache::lock por custId (W1-T3) impede multi-request paralelo do mesmo cliente |
| T-19-03 | Tampering | `sugadores:limpar-orfaos` comando | mitigate | Dry-run default; `--apply` explícito; gate humano no W4 antes de prod |
| T-19-04 | Information Disclosure | MLBs de empresa cross-carteira | mitigate | Mesmo authorization scope de `sgiCampaigns()` aplicado em `mlbsByCompany` |
| T-19-05 | Repudiation | Limpeza em massa sem audit | mitigate | INSERT em `sugador_acoes` com `acao=limpeza_orfaos`, `user_id=null`, `observacao` explícita — rastreável por created_at |
| T-19-06 | Denial of Service | Lock TTL muito longo trava conta | accept | TTL 30s é curto; se travar, próxima chamada cai e usuário retenta. Risco baixo vs benefício de mitigar 429. |
</threat_model>

<verification>
- Build frontend: `npm run build` sem erros novos.
- Testes Phase 19: `php artisan test --filter=Phase19` → 10 verdes.
- Suíte completa Sugadores não regredir: `php artisan test --filter=Sugador` antes e depois — mesmo count verde.
- Rotas registradas: `php artisan route:list | grep mlbs` mostra `sugadores.mlbs-by-company`.
- Comando registrado: `php artisan list | grep sugadores` mostra `sugadores:limpar-orfaos`.
- Dry-run em prod produz output coerente com expectativa (~1407 candidatos).
- Smoke manual confirma todos os 7 success criteria do CONTEXT.md.
</verification>

<success_criteria>
Todos os 7 success criteria de CONTEXT.md atendidos:
1. ✅ Banner D-1 explícito no topo + badges por empresa.
2. ✅ Vista default só HOJE + pendente (modo lista).
3. ✅ Botão "Copiar MLBs" inline na linha do sugador adgroup.
4. ✅ Botão "Copiar MLBs da empresa" no CompanyCard (count_hoje>0).
5. ✅ `Cache::lock("adman_mcp:custid:{custId}", 30)` em `fetchAllProductAds`.
6. ✅ Comando `sugadores:limpar-orfaos` (dry-run default + --apply explícito + audit).
7. ✅ 10 testes Feature verdes em `tests/Feature/Phase19/`.

Critérios operacionais:
- 1407 sugadores antigos marcados como `auto_resolvido` em prod.
- Smoke do bug 429 confirma mitigação (ou registra follow-up se persistir).
</success_criteria>

<output>
Crie `.planning/phases/19-sugadores-foco-dia/19-01-SUMMARY.md` ao fim com:
- Resumo das 4 waves (o que mudou em cada arquivo).
- Output exato do dry-run + --apply em prod (linhas chave).
- Confirmação dos 7 SCs validados manualmente.
- Resultado do smoke 429 (mitigado vs ainda observado).
- Follow-ups (se houver): regenerar `analise_diaria` via `SugadorAnaliseRun` model se for criado em fase futura; extrair `copyToClipboard` para `resources/js/lib/utils.js`.
</output>
