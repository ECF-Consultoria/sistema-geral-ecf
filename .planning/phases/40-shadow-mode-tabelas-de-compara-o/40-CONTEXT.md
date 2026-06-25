# Phase 40: Shadow mode + tabelas de comparação — Context

**Gathered:** 2026-06-25
**Status:** Ready for planning (Phase 39 entregou os providers; Phase 40 adiciona run-recording + comparação)
**Source:** Import express path (`plano-migracao-sugadores-ml-direto.md` §4 Fase 2 Shadow mode) + Phase 39 deliverables

<domain>
## Phase Boundary

Phase 40 introduz **shadow mode**: rodar o `SugadorAnalysisService` com **ambos os providers** (Adman + ML) em paralelo numa janela de tempo, gravar os motivos detectados por cada um em tabelas auxiliares novas, e comparar. **Não altera `sugadores`** — a tabela canônica de produção continua sendo escrita apenas pelo path atual (Adman); shadow é só leitura + gravação em tabelas paralelas para análise.

Objetivo operacional: **medir paridade** entre Adman e ML antes de migrar empresas para `ml_primary` (Phase 42). Alvo: ≥95% de paridade de motivos por empresa+período. Sem isso, não há base para confiar que ML produz os mesmos sugadores.

**Esta phase entrega:**

1. Migration: 2 tabelas novas
   - `sugador_provider_runs` — uma linha por execução (`company_id`, `provider`, `reference_date`, `periodo_inicio`, `periodo_fim`, `status`, `started_at`, `finished_at`, `error`, `summary` JSON)
   - `sugador_provider_items` — uma linha por sugador detectado em cada run (`run_id`, `tipo`, `campaign_id`, `adgroup_id`, `mlb_id` nullable, `motivos` JSON, `metrics_json` JSON, `raw_hash` SHA256 do JSON normalizado, timestamps)
2. Service `ShadowRunService` (ou similar) — orquestra: roda `analyzeCompany` 2x (uma forçando `adman`, outra forçando `ml`), grava em `sugador_provider_runs`+`sugador_provider_items` em ambos os casos. NÃO grava em `sugadores`.
3. Service `ProviderComparisonService` — compara 2 runs (ou todos os runs de uma janela) e classifica divergências em 5 buckets: só-Adman, só-ML, métricas-divergentes, motivo-divergente, quarentena-divergente
4. Comando `php artisan sugadores:shadow-ml --company={id|all} [--days=7]` — dispara shadow run para 1 ou todas as empresas
5. Comando `php artisan sugadores:compare-providers --company={id} --from=YYYY-MM-DD --to=YYYY-MM-DD [--format=table|json]` — imprime/exporta relatório de paridade
6. Scheduler shadow separado em `routes/console.php` (diário, 13h BRT — depois da análise Adman padrão das 12h) — disparando shadow apenas para empresas em `SUGADORES_ML_SHADOW_COMPANIES` env (lista CSV de company_ids)
7. Migration de seed/index para performance (lookups por `company_id+reference_date+provider`)
8. Testes Feature cobrindo: shadow run end-to-end (Mockery dos providers), comparação retorna divergências esperadas, comando CLI exit codes corretos

**Esta phase NÃO entrega:**
- Gravação em `sugadores` via path ML (Phase 42)
- UI admin para visualizar shadow runs (Phase 41 ou follow-up)
- Tela de aprovação manual de "empresa pronta para primary" (Phase 41)
- Rate limiter `ml-api:{seller_id}` (Phase 41)
- Tabela `ml_advertisers` cache (Phase 41)
- Rollback automático em divergência crítica (Phase 42)
- Substituir o scheduler Adman atual (Phase 42 cut-over)

**Pré-requisitos:**
- Phase 39 ✓ — `SugadoresAdsProviderFactory`, `AdmanSugadoresProvider`, `MercadoLivreSugadoresProvider`, `SugadorAnalysisService::analyzeCompany($company, $refDate, $dryRun, $forceProvider)` operando
- Phase 38-01 ✓ — `MercadoLivreAdsService` HTTP layer disponível
- **Bloqueio conhecido pendente:** smoke real Phase 38-02 não rodou; payload ML é hipótese; tabela comparação vai capturar a divergência real quando o smoke destravar, mas até lá as comparações com empresa Bymobille vão ter ruído. Mitigação: Phase 40 entrega a infra sem depender de paridade real ≥95% (esse alvo é da Phase 41 onboarding).

</domain>

<decisions>
## Implementation Decisions

### Schema das 2 tabelas novas

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_create_sugador_provider_runs_and_items_tables.php`

```php
// Tabela 1: sugador_provider_runs
Schema::create('sugador_provider_runs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
    $table->string('provider', 32)->index();              // 'adman' | 'ml'
    $table->date('reference_date')->index();              // dia da análise
    $table->date('periodo_inicio');                       // início da janela analisada (geralmente refDate - dias_analise)
    $table->date('periodo_fim');                          // fim da janela
    $table->string('status', 32)->index();                // 'running' | 'completed' | 'failed'
    $table->timestampTz('started_at');
    $table->timestampTz('finished_at')->nullable();
    $table->text('error')->nullable();                    // mensagem se status=failed
    $table->json('summary')->nullable();                  // {campanhas_detectadas, adgroups_detectados, etc}
    $table->timestamps();
    
    $table->index(['company_id', 'reference_date', 'provider'], 'idx_company_ref_provider');
});

// Tabela 2: sugador_provider_items
Schema::create('sugador_provider_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('run_id')->constrained('sugador_provider_runs')->cascadeOnDelete();
    $table->string('tipo', 32);                           // 'campanha' | 'adgroup'
    $table->string('campaign_id', 64)->index();
    $table->string('adgroup_id', 64)->nullable()->index();// null quando tipo=campanha
    $table->string('mlb_id', 32)->nullable()->index();    // chave de match alternativa
    $table->json('motivos');                              // array de strings ['cpc_alto', 'sem_conversao', ...]
    $table->json('metrics_json');                         // contrato §2.3 do plano (investment, revenue, etc)
    $table->string('raw_hash', 64)->index();              // SHA256 do JSON normalizado pra detectar duplicatas exatas
    $table->timestamps();
    
    $table->index(['run_id', 'tipo'], 'idx_run_tipo');
});
```

### Service `ShadowRunService`

**Arquivo:** `app/Services/Sugadores/ShadowRunService.php`

Responsabilidades:
- Para cada empresa elegível (recebe `$company` + `$referenceDate`):
  - Cria 1 row em `sugador_provider_runs` com `provider='adman'`, status=`running`, `started_at=now()`
  - Chama `SugadorAnalysisService::analyzeCompany($company, $referenceDate, dryRun=true, forceProvider='adman')`
  - Persiste cada motivo detectado em `sugador_provider_items` (run_id da row Adman)
  - Atualiza row Adman com status=`completed`, `finished_at=now()`, summary contendo counts
  - Mesma coisa pra `provider='ml'`
  - Se um dos dois der erro, marca status=`failed` no row dele + `error=msg` e continua (não interrompe o outro)
- **NÃO grava em `sugadores`** — `dryRun=true` no service garante isso (Phase 39 já entregou esse comportamento)
- Retorna `['adman_run_id', 'ml_run_id', 'adman_status', 'ml_status']`

### Service `ProviderComparisonService`

**Arquivo:** `app/Services/Sugadores/ProviderComparisonService.php`

Métodos:
- `compareRuns(int $admanRunId, int $mlRunId): ComparisonReport`
- `compareWindow(int $companyId, Carbon $from, Carbon $to): ComparisonReport`  (compara todos os runs Adman vs todos runs ML no período)

ComparisonReport (POPO ou array):
```php
[
    'matched' => N,            // items presentes em ambos com mesma chave
    'metrics_diff' => N,       // mesma chave mas métricas divergem além de tolerância
    'motivo_diff' => N,        // mesma chave + métricas próximas mas conjunto de motivos diferente
    'apenas_adman' => N,       // chave só aparece em Adman
    'apenas_ml' => N,          // chave só aparece em ML
    'quarentena_diff' => N,    // mesma campanha tratada como quarentena por um e não pelo outro
    'paridade_motivos_pct' => float,  // alvo ≥95%
    'detalhes' => [...],       // array opcional com primeiros N exemplos pra debug
]
```

Tolerâncias para "métricas-divergentes" (plano §7 Paridade):
- Dinheiro (investment, revenue): diferença ≤ 1% OU ≤ R$ 0,10
- Percentuais (acos, ctr): ≤ 0,5 p.p.
- Inteiros (clicks, sold_quantity, etc): igual
- `safe_div` resultados ausentes em um dos lados: considerar null == null como match, número vs null como divergência

Match key prioridade:
1. `tipo|campaign_id|adgroup_id` (chave canônica)
2. Se adgroup_id for null/diferente, fallback `tipo|campaign_id|mlb_id` (alternativa para Product Ads onde adgroup_id não tem equivalente direto)

### Comando `sugadores:shadow-ml`

**Arquivo:** `app/Console/Commands/SugadoresShadowMl.php` (novo)

Signature: `sugadores:shadow-ml {--company= : ID ou "all" (default: usa env SUGADORES_ML_SHADOW_COMPANIES)} {--days=1 : quantos dias para trás rodar shadow}`

- Se `--company=all`: usa `SUGADORES_ML_SHADOW_COMPANIES` env (CSV de IDs); se env vazia, aborta com erro claro
- Para cada empresa, para cada day no range, chama `ShadowRunService->run($company, $date)`
- Output console: progresso por empresa, summary final (X runs Adman ok, Y runs ML ok, Z falhas)
- Exit 0 mesmo com falhas individuais (registra em `error` da row)

### Comando `sugadores:compare-providers`

**Arquivo:** `app/Console/Commands/SugadoresCompareProviders.php` (novo)

Signature: `sugadores:compare-providers {--company= : ID obrigatório} {--from= : YYYY-MM-DD obrigatório} {--to= : YYYY-MM-DD obrigatório} {--format=table : table|json}`

- Chama `ProviderComparisonService->compareWindow($companyId, $from, $to)`
- `--format=table`: imprime relatório formatado pt-BR com counts + paridade %
- `--format=json`: imprime JSON puro (machine-readable, útil para CI)
- Exit code 0 se paridade ≥95%, 1 se <95% (útil para CI/cron alerts)

### Scheduler

**Arquivo:** `routes/console.php` — adicionar:
```php
Schedule::command('sugadores:shadow-ml --company=all --days=1')
    ->dailyAt('13:00')
    ->timezone('America/Sao_Paulo')
    ->name('sugadores_shadow_ml_daily')
    ->onOneServer()
    ->withoutOverlapping();
```

Roda 1h depois da análise Adman padrão (12h) — janela tranquila, dados Adman já em `sugadores`, ML não interfere.

### Env config

**`.env.example`:** adicionar
```
# CSV de company_ids elegíveis para shadow mode ML (Phase 40)
SUGADORES_ML_SHADOW_COMPANIES=
```

`.env` real: deixar vazio até que o smoke real da Phase 38 destrave (e usuário decida quem entra).

**Config:** `config/sugadores.php` (criar se não existir) — lê env:
```php
return [
    'ml_shadow_companies' => array_filter(explode(',', env('SUGADORES_ML_SHADOW_COMPANIES', ''))),
];
```

### Não-tocar

- `sugadores`, `sugador_configs`, `sugador_acoes` (tabelas canônicas — intactas)
- `app/Services/SugadorAnalysisService.php` (Phase 39 fechou — só consumir via factory)
- `app/Services/Sugadores/*Provider*.php`, `*Factory*.php` (Phase 39 — só consumir)
- `app/Services/AdmanService.php`, `MercadoLivreService.php`, `MercadoLivreAdsService.php` (intactos)
- `app/Repositories/AdgroupMlbMapRepository.php` (Phase 39 — só consumir se precisar)
- `app/Console/Commands/AnalyzeSugadores.php` (Phase 39 fechou — não alterar)
- `app/Jobs/AnalyzeCompanySugadoresJob.php` (NÃO alterar; shadow é comando standalone)
- Scheduler Adman atual em `routes/console.php` (adicionar entrada nova, não modificar existente)

### Testes

- `tests/Feature/Phase40/CreateSugadorProviderTablesTest.php` — verifica schema (colunas, índices, FKs)
- `tests/Unit/Phase40/ShadowRunServiceTest.php` — Mockery do `SugadorAnalysisService`; testa: cria 2 runs (Adman+ML), grava items, marca completed/failed, NÃO chama `Sugador::create` (assertDatabaseCount('sugadores') igual antes/depois)
- `tests/Unit/Phase40/ProviderComparisonServiceTest.php` — testa: matched=full, apenas_adman, apenas_ml, metrics_diff (com tolerâncias), motivo_diff, quarentena_diff, paridade_motivos_pct correto
- `tests/Feature/Phase40/SugadoresShadowMlCommandTest.php` — comando dispara N runs; respeita env CSV; exit 0
- `tests/Feature/Phase40/SugadoresCompareProvidersCommandTest.php` — comando imprime relatório; exit 0 se ≥95%, 1 se <95%; `--format=json` produz JSON parseable

### Claude's Discretion

- Como dispatchar shadow: inline (comando síncrono) vs Job (`AnalyzeShadowJob` queueable). Sugiro **inline no comando** para simplicidade — Phase 40 não precisa de queue (smoke pequeno). Job pode entrar em Phase 41 se virar gargalo.
- Estrutura `ComparisonReport`: array simples vs DTO. Array OK (consistente com retorno do `SugadorAnalysisService`).
- Onde colocar `safe_div` agora que vai ser usado em 3 lugares (AdmanProvider, MlProvider, ComparisonService): criar `app/Support/SafeMath.php` com método estático `divide($num, $den)`. Pequeno refactor opcional.
- Cache de `raw_hash` para detectar runs duplicados (mesma config gera mesmo hash): nice-to-have, deferir se complicar.

</decisions>

<canonical_refs>
## Canonical References

### Plano de migração
- `plano-migracao-sugadores-ml-direto.md` §4 Fase 2 Shadow mode (texto canônico do escopo + critérios de comparação)
- `plano-migracao-sugadores-ml-direto.md` §7 Paridade (tolerâncias de divergência)

### Phase 39 deliverables (consumidos)
- `app/Contracts/SugadoresAdsProvider.php`
- `app/Services/Sugadores/SugadoresAdsProviderFactory.php` — método `for($company, $forceName)`
- `app/Services/Sugadores/AdmanSugadoresProvider.php`
- `app/Services/Sugadores/MercadoLivreSugadoresProvider.php`
- `app/Services/SugadorAnalysisService.php` — assinatura `analyzeCompany(Company, ?Carbon, bool $dryRun, ?string $forceProvider)`; usar com `dryRun=true` no shadow
- `app/Console/Commands/AnalyzeSugadores.php` — referência de pattern de comando (não consumir, só estilo)

### Schema atual
- `database/migrations/` — referência para encontrar como `sugadores`, `sugador_configs`, `sugador_acoes` foram criadas; sua migration nova segue mesmo padrão (foreignId, índices, timestamps tz)
- `app/Models/Sugador.php` — campos e constantes para alinhar nomes nas novas tabelas (`STATUS_TRAVADOS`, `MOTIVO_*`)

### Externos
- `https://laravel.com/docs/12.x/migrations` — sintaxe migration
- `https://laravel.com/docs/12.x/scheduling` — schedule API

</canonical_refs>

<requirements_to_register>
## Requirements desta Phase

Registrar como REQ-40-XX:

- **REQ-40-01** — Migration cria `sugador_provider_runs` (10 colunas conforme schema §decisions) + `sugador_provider_items` (8 colunas) + índices compostos; idempotente
- **REQ-40-02** — `App\Services\Sugadores\ShadowRunService` orquestra 2 runs por empresa (Adman+ML) gravando em runs+items; NÃO grava em `sugadores` (assertion no test); falha de um provider não interrompe o outro
- **REQ-40-03** — `App\Services\Sugadores\ProviderComparisonService` retorna 5 buckets de divergência + `paridade_motivos_pct`; tolerâncias §7 do plano (dinheiro ≤1% ou ≤R$0,10; percentuais ≤0,5pp; inteiros igual)
- **REQ-40-04** — Comando `php artisan sugadores:shadow-ml --company={id|all} [--days=N]` dispara shadow inline; respeita env `SUGADORES_ML_SHADOW_COMPANIES` quando `--company=all`
- **REQ-40-05** — Comando `php artisan sugadores:compare-providers --company={id} --from=YYYY-MM-DD --to=YYYY-MM-DD [--format=table|json]` imprime relatório; exit 0 se paridade ≥95%, 1 caso contrário
- **REQ-40-06** — Scheduler diário em `routes/console.php` rodando `sugadores:shadow-ml --company=all --days=1` às 13h BRT; sem overlap; `onOneServer`
- **REQ-40-07** — Env `SUGADORES_ML_SHADOW_COMPANIES` documentada em `.env.example`; config `config/sugadores.php` expõe `ml_shadow_companies` lendo CSV; comando aborta com mensagem clara se env vazia E `--company=all`
- **REQ-40-08** — Suite de testes cobrindo: schema migration, ShadowRunService Mockery (zero gravação em sugadores), ProviderComparisonService com fixtures de 5 cenários de divergência, ambos comandos CLI (exit codes + format json/table); zero regressão Sugador/Phase 39

</requirements_to_register>

<plan_slicing_suggestion>
## Slicing sugerido (4 plans em 3 waves)

**Wave 1:**
- **Plan 40-01** — Migration `sugador_provider_runs` + `sugador_provider_items` + Models Eloquent + 1 test schema. (REQ-40-01)

**Wave 2** (paralelo possível, sem overlap de arquivos):
- **Plan 40-02** — `ShadowRunService` + tests Unit com Mockery (REQ-40-02). Depende: 40-01 (precisa das tabelas).
- **Plan 40-03** — `ProviderComparisonService` + tests Unit com fixtures sintéticas (REQ-40-03). Depende: 40-01 (precisa do schema dos items pra ler).

**Wave 3:**
- **Plan 40-04** — 2 comandos (`sugadores:shadow-ml` + `sugadores:compare-providers`) + scheduler + env + config + tests Feature (REQ-40-04, REQ-40-05, REQ-40-06, REQ-40-07, REQ-40-08 parte). Depende: 40-02 + 40-03.

**Bloqueio MariaDB:** Plan 40-01 (migration) precisa de DB para rodar. SQLite em-memory funciona pros tests mas pra executar migration em dev real... bem, dev real está com MariaDB caído. Recomendação:
- Plans 40-01..04 podem ser DESENVOLVIDOS e ter tests verdes via SQLite em-memory sem MariaDB local
- O **smoke manual de rodar migration no MariaDB local** + `php artisan sugadores:shadow-ml --company=<bymobille>` continua deferido até MariaDB voltar (segue a quick task `260625-mrd`)
- Não bloqueia execução dos 4 plans

</plan_slicing_suggestion>

<specifics>
## Specific Ideas

- `safe_div` já está duplicado em AdmanProvider e MlProvider (Phase 39). Plan 40-03 vai precisar pra calcular diferença de métricas. Considerar mover para `app/Support/SafeMath.php` em Plan 40-03 (refactor opt-in) OU copiar local de novo (lean, mas dívida técnica). **Recomendação:** copiar local nesta phase, extrair em phase futura quando virar problema real.
- Migration timestamp: usar prefixo > último migration commitado (`grep -r "Schema::create" database/migrations/ | tail -1` para encontrar último)
- Models: `App\Models\SugadorProviderRun` + `App\Models\SugadorProviderItem` com relação `hasMany`/`belongsTo`. Casts: `summary` json, `motivos` array, `metrics_json` array, `started_at`/`finished_at` immutable datetime
- Eloquent observer para auto-popular `raw_hash` no save do `SugadorProviderItem` (SHA256 do `metrics_json + motivos` ordenado) — opcional, pode ser método explícito no service que cria o item

</specifics>

<deferred>
## Deferred Ideas

- UI admin de visualização de shadow runs e paridade — Phase 41 (faz parte do onboarding visual)
- Gravação em `sugadores` via path ML (`ml_primary` mode) — Phase 42
- Cut-over por empresa baseado em paridade observada — Phase 42
- Notificação automática quando paridade cai abaixo de 95% — opcional (BaseNotification disponível, mas escopo Phase 41+)
- Tabela `ml_advertisers` (cache de `advertiser_id` por empresa) — Phase 41
- Rate limiter `ml-api:{seller_id}` — Phase 41
- Job queueable `AnalyzeShadowJob` (substituir comando síncrono) — futuro, se virar gargalo
- Extração de `safe_div` para helper centralizado — futuro refactor
- Auto-rollback de empresa em ml_primary quando divergência crítica — Phase 42
- Comparação cross-empresa (rolling 7-day window dashboard) — Phase 41 UI

</deferred>

---

*Phase: 40-shadow-mode-tabelas-de-compara-o*
*Context gathered: 2026-06-25 via import express path + Phase 39 deliverables consumed*
