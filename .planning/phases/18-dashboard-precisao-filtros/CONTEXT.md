# Phase 18: Dashboard precisa e com filtros empilháveis

**Status:** Planning
**Mode:** mvp (slice por bug)
**Iniciada:** 2026-06-02
**Depende de:** Phase 16 (cache D-1 + `RefreshGrossBillingCacheJob` em produção)

## Goal

Aplicar diretamente as duas regras-mestras do projeto (**acertividade** + **praticidade**) na Dashboard, eliminando 3 bugs reportados pelo usuário em 2026-06-02:

1. Trocar o filtro de tempo não muda os dados dos cards principais (range 30d hardcoded)
2. Selecionar empresa + período perde a empresa (inconsistência camelCase/snake_case)
3. Soma de faturamento da Dashboard não bate com a Adman para o mesmo período

## Origem da fase

Citação literal do usuário em 2026-06-02:

> "Se mudo o filtro de tempo os dados continuam os mesmos, deveria ser diferente de acordo com o período selecionado.
> No filtro da dash seleciono um empresa especifica e depois seleciono um filtro de tempo a empresa sai, deveria ser possível colocar múltiplos filtros.
> A soma total de faturamento de todas as empresa não está batendo com adman, o período que vem selecionado por padrão é últimos 30 dias, na adman se eu coloco esse mesmo filtro de últimos 30 dias e vejo o faturamento total somado de todas as empresas, da bem mais.
> Ideia para resolver essa parte de soma do faturamento: ao rodar o cron diário salvar o valor do faturamento e ao invés na hora do mostrar para usuário o valor trazido pela API somar o valor já salvo cron diário. É só uma ideia, não sei se é viável, ou se é eficiente, se tem jeito melhor por isso quero que gere um plano gsd para isso."

Regras-mestras estabelecidas na mesma conversa (memória `feedback_project_priorities.md`):
- **Acertividade / Precisão nos dados** — números mostrados precisam bater com a fonte autoritativa
- **Praticidade — eficiência, agilidade, facilidade** — operadores executam rápido, filtros combinam de verdade

## Diagnóstico técnico (verificado em 2026-06-02 contra HEAD `ff6182e`)

### Bug 1 — Trocar período não muda dados (RAIZ ENCONTRADA)

**Arquivo:** [app/Http/Controllers/DashboardController.php](app/Http/Controllers/DashboardController.php)

`$period` é lido do request na linha 45 e o `$since` derivado em `getSince()` (linhas 55-64). Mas o range que alimenta os cards principais é **hardcoded em 30 dias**:

```php
// Linhas 106-107
$dateFrom30d = now()->subDays(30)->toDateString();   // ← FIXO
$dateTo30d   = now()->toDateString();
```

E é esse range fixo que vai pros cards via cache batch (linhas 111-112) e fallback DB (linhas 156-157, 253):

```php
$grossBatch30d   = $this->adman->getCachedGrossBillingsMany($custIds30d, $dateFrom30d, $dateTo30d);
$accountBatch30d = $this->adman->getCachedAccountMetricsMany($custIds30d, $dateFrom30d, $dateTo30d);
...
$sumDb30d = AdmanMetric::query()->whereIn(...)->whereBetween('reference_date', [$dateFrom30d, $dateTo30d])->...;
```

Apenas o chart de série temporal (linhas 80-83, 190-204) e algumas queries menores (NPS linha 211, reuniões linha 217, sugadores) usam `$since`. **Os cards grandes (Faturamento, Invest. Ads, TACOS médio, Margem) ignoram o filtro do usuário.**

### Bug 2 — Combinar filtros perde a empresa (RAIZ ENCONTRADA)

**Inconsistência camelCase ↔ snake_case** entre o que o controller devolve e o que ele lê:

Controller devolve ([linha 386](app/Http/Controllers/DashboardController.php#L386)):
```php
'filters' => compact('companyFilter', 'consultorFilter', 'estrategistaFilter')
// → { companyFilter: '5', consultorFilter: null, estrategistaFilter: null }
```

Mas controller lê apenas snake_case ([linhas 68-70](app/Http/Controllers/DashboardController.php#L68)):
```php
$companyFilter = $request->get('company_id');       // ← espera snake_case
$consultorFilter = $request->get('consultor_id');
$estrategistaFilter = $request->get('estrategista_id') ?? $request->get('mentor_id');
```

Frontend espalha o `filters` (camelCase) ao fazer requests novos ([Admin.jsx:93-99](resources/js/Pages/Dashboard/Admin.jsx#L93)):

```jsx
const applyFilter = (key, value) => {
    router.get(route('dashboard'), {
        ...filters,           // ← { companyFilter: '5' } ← chave INVÁLIDA
        period,
        [key]: value || undefined,
    }, ...);
};
```

**Sequência do bug:**
1. User seleciona empresa "X" → `applyFilter('company_id', '5')` → URL: `?company_id=5&period=30` (OK, snake_case)
2. Controller responde com `filters: { companyFilter: '5' }`
3. User troca período → `applyFilter('period', '7')` → URL: `?companyFilter=5&period=7`
4. Controller NÃO lê `companyFilter`, só `company_id` → filtro de empresa se perde

**Note:** o ECFSelect referencia `filters.companyFilter` (linha 252) e `filters.consultorFilter` (linha 258) — fix precisa atualizar ambos os lados de forma consistente.

### Bug 3 — Soma de faturamento divergente (precisa AUDITORIA)

**Hipóteses (não testadas):**

a. **Bug 1 cascateando**: o card "Faturamento" sempre soma 30 dias, então comparar com "Últimos 30 dias" da Adman deveria casar. Se não casa, tem item b ou c.

b. **Empresas sem `cust_id` mapeado**: `Company::cust_id` é `ml_store_id ?: adman_account_id`. Empresas com integração ativa na Adman mas sem nenhum dos dois preenchidos no nosso DB contribuem **R$ 0** silenciosamente.

c. **Gaps em `adman_metrics`**: o fallback DB `SUM(adman_metrics.revenue)` ignora dias em que o sync diário falhou. Se uma empresa teve 5 dias falhados no mês, esses dias somam 0 em nós, mas a Adman tem o número certo.

d. **Política "tudo-ou-nada" do cache** ([linhas 117-133](app/Http/Controllers/DashboardController.php#L117)): se QUALQUER empresa está em cache miss, descarta cache do conjunto inteiro e cai pro DB. O DB pode estar incompleto → vira fonte de divergência.

**Solução proposta pelo usuário** (snapshot diário + somar valor salvo): **já existe parcialmente**:
- `adman_metrics` (sync diário pela Phase 1) — granularidade diária por empresa
- Cache Redis 24h (Phase 16) — pré-aquecido pelo `RefreshGrossBillingCacheJob`
- `company_monthly_revenue` (model existe via `syncMonthRevenue` no `AdmanService`) — granularidade mensal

O problema **não é falta de snapshot**, é integridade do snapshot. Antes de criar uma 4ª tabela, precisamos **medir** a divergência por empresa pra decidir a estratégia certa de fix.

## Decisões já tomadas no scoping (2026-06-02)

- **Multi-select de empresas: NÃO** — 1 empresa por vez basta. Fix do Bug 2 só empilha filtros.
- **Range custom de datas: NÃO** — 1d/7d/30d/180d bastam por agora.
- **Estratégia do Bug 3 NÃO pré-decidida** — após auditoria (W3) o planner/executor decide se é (a) preencher `cust_id` faltante, (b) backfill de dias falhados, (c) revisar política tudo-ou-nada, (d) nova tabela `dashboard_daily_totals`.
- **Comando de auditoria é READ-ONLY** — nunca UPDATE/INSERT, só leitura e tabela formatada no terminal.

## Success Criteria (do ROADMAP)

1. **Filtros empilháveis** — frontend e backend usam snake_case (`company_id`, `consultor_id`, `estrategista_id`, `period`); nenhum filtro se perde ao alterar outro.
2. **Período afeta TODOS os cards** — helper `getPeriodRange(string $period): array{from: string, to: string}` derivado de `$period`; aplicado em todas as queries do controller.
3. **Auditoria executada** — `dashboard:audit-billing-divergence [--period=N]` compara `fetchPerformance` vs `SUM(adman_metrics.revenue)` por empresa; identifica empresas sem `cust_id`, dias faltantes, magnitude.
4. **Fix do Bug 3 baseado nos achados** — escopo de W4 definido por deviation explícito após W3.
5. **UI sinaliza incerteza** — quando fallback DB ativo, cards mostram "≈ valor aproximado" sutil ou tooltip equivalente.
6. **Testes** — period preserva `company_id` e vice-versa; range derivado; auditoria detecta gap propositado.

## Mapa de arquivos relevantes

### Backend
- [app/Http/Controllers/DashboardController.php](app/Http/Controllers/DashboardController.php) (570 linhas) — refator principal
- [app/Services/AdmanService.php](app/Services/AdmanService.php) (942 linhas) — `fetchPerformance`, `fetchGrossBilling`, `getCachedGrossBillingsMany`, `getCachedAccountMetricsMany`. **Pode receber** novo método público de auditoria mas idealmente o comando faz inline.
- [app/Models/AdmanMetric.php](app/Models/AdmanMetric.php) — fonte do SUM fallback
- [app/Models/AdmanSyncLog.php](app/Models/AdmanSyncLog.php) — última sync per company
- [app/Models/Company.php](app/Models/Company.php) — accessor `cust_id`
- [app/Jobs/RefreshGrossBillingCacheJob.php](app/Jobs/RefreshGrossBillingCacheJob.php) — **NÃO MEXER** salvo se auditoria identificar gap originado lá

### Frontend
- [resources/js/Pages/Dashboard/Admin.jsx](resources/js/Pages/Dashboard/Admin.jsx) (609 linhas) — `applyFilter`, `ECFSelect`, cards principais

### Comando novo
- `app/Console/Commands/AuditBillingDivergence.php` (novo) — registrar em `routes/console.php` se necessário (provavelmente não, é manual)

### Testes
- `tests/Feature/DashboardFiltersTest.php` (novo) — combina filtros + period dinâmico
- `tests/Feature/AuditBillingDivergenceTest.php` (novo) — auditoria detecta gap propositado

## Pitfalls antecipados

1. **`compact()` PHP usa nome literal da variável** — `compact('companyFilter')` vira chave `companyFilter`. Fix: ou renomear as variáveis pra `$company_id`/`$consultor_id`, ou usar array literal `['company_id' => $companyFilter, ...]`.

2. **`ECFSelect` no Admin.jsx referencia `filters.companyFilter`** (camelCase) em pelo menos 3 lugares — todos precisam migrar pra `filters.company_id`.

3. **`$dateFrom30d` é usado em múltiplos lugares** (linhas 111, 112, 157, 253, 502). Refactor para `[$dateFromN, $dateToN] = $this->getPeriodRange($period)` precisa de cuidado para não quebrar:
   - Cache keys: o `RefreshGrossBillingCacheJob` (Phase 16) cacheia com range 30d. Se trocar pra 7d, cache miss garantido → fallback DB. **Cuidado**: pode degradar quando user filtra 7d.
   - Decisão: ou (i) o cache passa a ter múltiplos ranges (cache key `gross:{custId}:{period}:{date}`), ou (ii) só os ranges 1d/7d/30d/180d são "primeira classe" no cache.

4. **`getCachedGrossBillingsMany` esperando range fixo**: o cache batch só lê chaves pré-existentes. Se range diferente, miss → fallback DB. Plan precisa decidir se inclui pre-warm do cache pra ranges não-30d ou se 7d/180d sempre cai em fallback.

5. **`AdmanMetric::whereBetween('reference_date', [...])`** — coluna `reference_date` é cast `date` que pode armazenar como datetime em SQLite. Pitfall já aplicado em Phase 15: usar `DATE(reference_date)` ou comparar strings, não Carbon.

6. **Auditoria em produção**: comando `dashboard:audit-billing-divergence` vai chamar `fetchPerformance` por empresa (168 empresas) — 168 × 7s = ~20min com throttle Phase 16. Roda 1× via SSH manual, não em cron.

7. **Race condition no fallback DB**: a política "tudo-ou-nada" descarta cache do conjunto se 1 empresa tem cache miss. Como `RefreshGrossBillingCacheJob` está em queue, durante 5-20min do dia o cache fica parcial e oscila. Fix possível: aceitar parcial e sinalizar UI (SC-5).

8. **SC-3 + SC-4 são sequenciais e SC-4 é "TBD"** — planner precisa modelar W3 e W4 com deviation contract explícito ("W4 escopo depende de W3"), não estimar tarefas concretas pra W4 antes de auditar.

## Não-objetivos (out of scope)

- Multi-select de empresas
- Range custom de datas (de/até livre)
- Refactor da Dashboard de não-admin (userDashboard)
- Mudar `RefreshGrossBillingCacheJob` salvo se auditoria identificar gap lá
- Reescrever `AdmanService::fetchPerformance` (já testado/estável)
- Criar novo schema/migration ANTES de saber o tipo de gap (W3 decide)
- Otimizar performance da Dashboard além do necessário pros bugs

## Cross-cutting constraints

- pt-BR em comentários, mensagens flash, commits
- `npm run build` obrigatório após cada edição JSX
- Naming: usar **snake_case** consistente (`company_id`, `consultor_id`, `estrategista_id`, `period`) em backend e frontend
- Aspas simples, 4 espaços, trailing commas em JSX (CONVENTIONS.md)
- Tests adicionados em `tests/Feature/` seguindo padrão Phase 15/16
- Comando de auditoria é **read-only** — nunca UPDATE/INSERT
- Sem deploy automático no fim — checkpoint humano antes do push/deploy

## Referências adicionais

- [.planning/codebase/ARCHITECTURE.md](.planning/codebase/ARCHITECTURE.md)
- Memory: [feedback_project_priorities.md](MEMORY.md) — regras acertividade + praticidade
- Memory: [project_adman_data_sources.md](MEMORY.md) — 2 fontes de dado (sync + MCP)
- Phase 16 Decisions (STATE.md) — cache D-1, `ADMAN_RATE_LIMIT_RPM = 10`, throttle 7s
