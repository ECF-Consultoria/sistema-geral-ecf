# Phase 45: Compatibilidade ML em métricas — foco em /desempenho + widget unify — Context

**Gathered:** 2026-06-29
**Status:** Ready for research
**Source:** Síntese lean — derivado de briefing 2026-06-27 + esclarecimento de escopo do usuário 2026-06-29 (sem discuss-phase interativo, per memory `feedback_lean_planning`)

<domain>
## Phase Boundary

Phase 45 entrega:

1. **Unificação widget vs página `/performance`** — hoje classificações divergem. O widget "Desempenho da equipe" na `Dashboard/Admin.jsx` e a página `/performance` (`PerformanceController`) devem mostrar a mesma classificação/ranking para a mesma equipe no mesmo período. Widget vira preview resumida da página, ambos consumindo o mesmo service.

2. **Compatibilidade ML no scoring de `/performance`** — empresas com fonte de dados ML (hoje Bymobille #298; futuramente maioria) aparecem CORRETAMENTE nos scores de quem as gerencia. Hoje provavelmente aparecem zeradas porque o cálculo lê `adman_metrics` direto e não considera `ml_metrics`/`adman_metrics_ml` (depende da arquitetura — research vai mapear).

3. **Abstração via `CompanyMetricsProvider`** — factory por empresa (Adman vs ML) reusando pattern v11.0 Sugadores. 2 implementações concretas + factory que escolhe baseado em `mlToken` ativo da empresa.

</domain>

<decisions>
## Implementation Decisions

### Escopo crítico vs out-of-scope (LOCKED)

- **DENTRO** da Phase 45:
  - Bug widget ≠ página `/performance` (Item 3b do briefing)
  - Compat ML em scores de `/performance` (Item 1c do briefing)
  - Service unificado pra widget + página
  - `CompanyMetricsProvider` factory + 2 implementações
- **FORA** da Phase 45 (postpone explícito):
  - Compat ML em dashboard admin métricas geral — faturamento total, TACOS médio, investimento ads, gráfico evolução (Item 1a — phase futura)
  - Compat ML em carteira individual/geral admin/líder (Item 1b — phase futura)
  - Redesign visual da carteira segundo `briefing-carteira-analistas-ui.md` (root, untracked — vira phase separada quando usuário decidir)
  - Histórico longitudinal de scores (Item 4 — Phase 46)
  - Novos parâmetros de score: sugador-resolvido + PPA (Item 5 — Phase 47, depende de Phase 44)

### Abordagem técnica

- **Pattern factory v11.0** — copiar a estrutura de `SugadoresAdsProviderFactory` (Phase 39) para `CompanyMetricsProviderFactory`. Cada provider implementa contrato comum (`fetchScoreMetrics($company, $from, $to): array`).
- **Trigger de escolha do provider** — `mlToken` ativo da empresa via `MlToken::where('company_id', $id)->active()` (mesmo critério já usado pelos Sugadores). Se ML ativo → MlMetricsProvider; senão → AdmanMetricsProvider.
- **Service compartilhado widget+página** — extrair lógica atual da página `/performance` para um `PerformanceScoreService` (ou nome equivalente). Widget passa a consumir o mesmo service com `limit` reduzido. Reuso garante consistência.
- **Sem migration nova** — Phase 45 é refactor de leitura, não muda schema. Migrations vêm em Phase 46 (snapshot history).

### Comportamento esperado de fallback

- Empresa Adman-only (sem `mlToken`) → AdmanMetricsProvider (comportamento atual mantido)
- Empresa ML-only (sem `adman_account_id`) → MlMetricsProvider (resolve bug Bymobille zerado)
- Empresa híbrida (tem ambos) → ML preferido (consistente com Phase 42 cut-over decision em Sugadores)
- Empresa sem nem um nem outro (raro) → score zerado é OK; loga warning estruturado

### Claude's Discretion

- Nome exato do service compartilhado (`PerformanceScoreService`? `EquipeRankingService`? — decidir no planner com base no código atual)
- Cache strategy do service: ler from cache existente do Adman/ML ou re-computar? — decidir com base no que o `PerformanceController` faz hoje
- Testes: PHPUnit Feature com `RefreshDatabase` + SQLite in-memory (MariaDB local corrompido — memory `project_mariadb_local_corrompido`)

</decisions>

<specifics>
## Specific Ideas

### O bug do widget concretamente

Dashboard admin (`resources/js/Pages/Dashboard/Admin.jsx` linhas ~392-468) mostra widget "Desempenho da equipe" com BarChart horizontal por membro + score numérico + ranking. O usuário relata que se ele abre `/performance` o ranking é OUTRO. Causas possíveis (research vai confirmar):

- Widget e página fazem queries diferentes em `DashboardController` vs `PerformanceController`
- Janelas temporais diferentes (widget = mês corrente? página = últimos 30d?)
- Critérios de inclusão diferentes (widget filtra users com X+ empresas? página inclui todos com `role` específico?)
- Fórmula de score diferente (widget = média simples; página = média ponderada?)

### O bug ML concretamente

Bymobille (#298) é a primeira (e por enquanto única) empresa em prod usando ML como fonte principal. Em `/performance`, quem gerencia a Bymobille (estrategista/analista atribuído via `company_users` pivot) deveria ter as métricas dela contabilizadas. Hoje provavelmente:

- O cálculo de score lê `adman_metrics` da empresa → Bymobille não tem (ou tem zerado)
- Resultado: o profissional aparece com score abaixo do real
- Quando mais empresas migrarem (Phase 38-44 v11.0), o problema cresce

### Pattern v11.0 a reusar

Sugadores v11.0 (Phase 39-42) já entregou:
- `App\Services\Sugadores\SugadoresAdsProvider` (contract/interface)
- `App\Services\Sugadores\AdmanSugadoresProvider` + `MercadoLivreSugadoresProvider`
- `App\Services\Sugadores\SugadoresAdsProviderFactory`

Phase 45 espelha esse padrão para métricas de performance:
- `App\Services\Performance\CompanyMetricsProvider` (interface)
- `App\Services\Performance\AdmanMetricsProvider` + `MlMetricsProvider`
- `App\Services\Performance\CompanyMetricsProviderFactory`

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Briefing e captura do escopo

- `.planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md` — briefing umbrella original (Items 1c e 3b são os relevantes pra esta phase)

### Patterns a copiar (v11.0 Sugadores)

- `app/Services/Sugadores/SugadoresAdsProvider.php` — interface/contract
- `app/Services/Sugadores/AdmanSugadoresProvider.php` + `app/Services/Sugadores/MercadoLivreSugadoresProvider.php` — implementações
- `app/Services/Sugadores/SugadoresAdsProviderFactory.php` — factory com lógica de escolha
- `.planning/phases/39-provider-pattern-mercadolivresugadoresprovider-sem-gravar/` — RESEARCH.md + plans que originaram o pattern
- `.planning/phases/42-sugadores-via-api-ml-troca-de-motor-esconder-ui-dev-paralela/42-04-PLAN.md` — cut-over decision pattern (ML preferido)

### Arquivos a investigar (research vai listar tudo)

- `app/Http/Controllers/PerformanceController.php` — assumido nome; research confirma
- `app/Http/Controllers/DashboardController.php` — query do widget desempenho (a unificar)
- `resources/js/Pages/Dashboard/Admin.jsx` — widget "Desempenho da equipe" (linhas 392-468 confirmado em quick task 260627-09j)
- `resources/js/Pages/Performance/` — pages da rota /performance
- `app/Models/User.php` — relations `companies()` via pivot `company_users`
- `app/Models/AdmanMetric.php` — fonte atual de métricas Adman
- `app/Models/MlMetric.php` (se existir) — fonte de métricas ML
- Tabela `adman_metrics_ml` — Phase 41 entregou shadow comparison; pode ser fonte canônica ML
- `app/Services/Performance/*` (se já existir) — research lista

### Memory cross-refs

- `project_adman_data_sources.md` — dashboards hoje leem `adman_metrics` local; MCP só pra drilldown
- `project_ml_only_companies_adman_endpoints.md` — empresas ML-only retornam 422 em endpoints Adman; UI já trata
- `project_v11_sugadores_ml_migration.md` — milestone v11.0 Sugadores migration
- `project_mariadb_local_corrompido.md` — testes via SQLite in-memory

</canonical_refs>

<deferred>
## Deferred Ideas

- Compat ML em dashboard admin geral (faturamento total, TACOS médio, ads, gráfico evolução) — Phase futura (Item 1a). Pode ser puxado pra cá se o `CompanyMetricsProvider` cobrir naturalmente, mas o ESCOPO desta phase NÃO obriga essa entrega.
- Compat ML em carteira individual/geral (admin, líder) — Phase futura (Item 1b).
- Redesign visual da carteira segundo `briefing-carteira-analistas-ui.md` (hero card gradiente, valores R$ X.XXM, KPIs simplificados, gráfico com tooltip, tabela orientada a ação, painel lateral) — phase separada quando o usuário decidir prioridade.
- Histórico longitudinal de scores (Item 4) — Phase 46.
- Novos parâmetros de score: sugador-resolvido + PPA (Item 5) — Phase 47, depende de Phase 44 destravar.

</deferred>

---

*Phase: 45-compatibilidade-ml-em-m-tricas-unifica-o-widget-desempenho*
*Context gerado: 2026-06-29 (síntese lean a partir do briefing + esclarecimento do usuário, sem discuss-phase interativo)*
