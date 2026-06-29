# Phase 49: Rankings de /desempenho por função + ranking separado de publicação — Context

**Gathered:** 2026-06-29
**Status:** Ready for research
**Source:** Síntese lean — briefing 2026-06-29 Item 1 + UAT da Phase 48 (operador relembrou esse item ao validar 48)

<domain>
## Phase Boundary

A página `/performance` hoje mostra ranking ÚNICO (Geral — todos os participantes do setor performance, filtrado por `user_setores → cargos.slug IN ('analista','estrategista')` após Phase 45). O operador quer:

1. **3 visualizações em `/performance`** (tabs/seletor):
   - **Geral** — comportamento atual mantido
   - **Analistas** — filtrado por `cargo_slug = 'analista'`
   - **Estrategistas** — filtrado por `cargo_slug = 'estrategista'`

2. **Ranking de Publicação em rota SEPARADA** — não dentro de `/performance` (públicos distintos: performance é analista/estrategista; publicação é gestor/publicador/líder pub). Nova rota dentro do dropdown **"Publicação"** do menu lateral. Sugestão: `/publicacao/desempenho` (nome final na execução).

</domain>

<decisions>
## Implementation Decisions

### Escopo LOCKED

- **DENTRO** da Phase 49:
  - Adicionar tabs/seletor em `/performance` para alternar entre Geral / Analistas / Estrategistas
  - Reusar `PortfolioScoreService` com parâmetro de filtro de função (cargo_slug)
  - Criar rota nova `/publicacao/desempenho` (ou nome equivalente) dentro do dropdown Publicação
  - Service/Controller para ranking de publicação (pode ser service dedicado se métricas divergirem muito, ou reuso do mesmo `PortfolioScoreService` com filtro por roles de publicação)
  - Sidebar: entry no dropdown Publicação (`AppLayout.jsx`)
  - Permissões: ranking de publicação só visível pra roles de publicação (`publication_role IS NOT NULL` OU roles `gestor/publicador/lider`); admin sempre vê tudo

- **FORA** da Phase 49:
  - Score diferenciado por função (sugador vs PPA weights) — Phase 47 (depends_on Phase 44)
  - Histórico longitudinal de scores — Phase 46
  - Gamificação OAuth ML — Phase 50

### Dependência reavaliada (decisão importante)

O roadmap original dizia "Phase 49 depende de Phase 47 (filtro de função no score)". **Reavaliação:** as 3 tabs em `/performance` precisam apenas filtrar os USERS exibidos por cargo — o cálculo de score atual já é por user, então filtrar a lista é trivial. **Phase 49 pode ser entregue ANTES da Phase 47.**

Quando Phase 47 destravar (depois da Phase 44 + Phase 46), o score dos analistas naturalmente passa a refletir Sugador metrics e dos estrategistas PPA metrics — sem mudança nas tabs.

**Conclusão:** Phase 49 fica independente. Sinergia com Phase 47 (rica), mas não bloqueante.

### Abordagem técnica

- **Tabs em `/performance`** — adicionar prop `cargo_filter` ao `PerformanceController::index()`. Frontend tem 3 tabs clicáveis (Geral / Analistas / Estrategistas) que navegam para `/performance?cargo=analista` etc. via Inertia link. Estado da tab vem da query string.
- **Service** — `PortfolioScoreService::compute()` ou método equivalente recebe opcionalmente `?string $cargoFilter` (igual ao pattern de Phase 45 que adicionou filtro canônico). Aplica `whereExists user_setores → cargos.slug = $cargoFilter` na query base de users.
- **Rota publicação separada** — `routes/web.php` ganha `GET /publicacao/desempenho` com middleware de permissão `permission:publication.dashboard` ou similar. Controller dedicado (`PublicacaoDesempenhoController` ou método novo em `PublicacaoController` se existir).
- **Service de publicação** — research vai mapear se é o mesmo `PortfolioScoreService` (com filtro `whereNotNull('publication_role')`) ou se precisa de service separado por divergência de métricas (NPS de publicação, taxa de approval, etc).
- **Sidebar** — `resources/js/Layouts/AppLayout.jsx` recebe nova entry no dropdown Publicação, gated por permission.

### Claude's Discretion

- Nome da rota final: `/publicacao/desempenho` vs `/publicacao/ranking` vs `/publicacao/performance` — decidir na execução com base no que faz sentido com nomes existentes
- Service de publicação: reuso vs dedicado — research decide com base nas métricas atuais de scoring
- Tabs UI: tabs nativas vs ECFSelect (componente já no projeto) — escolher consistente com outras páginas
- Testes: PHPUnit Feature com filtro por cargo (cobertura mínima das 3 visualizações + permission test para publicação)

</decisions>

<specifics>
## Specific Ideas

### Comportamento esperado

**`/performance`:**
- Default: tab "Geral" (comportamento atual)
- Click em "Analistas": URL vira `/performance?cargo=analista`, ranking mostra só analistas
- Click em "Estrategistas": URL vira `/performance?cargo=estrategista`, ranking mostra só estrategistas
- Widget "Desempenho da equipe" do Dashboard admin continua usando filtro Geral (não muda)

**`/publicacao/desempenho`:**
- Visível APENAS para users com role de publicação (gestor/publicador/líder pub) + admin
- Mostra ranking dos profissionais de publicação
- Layout pode reusar componente existente do ranking (`/performance` UI) com props diferentes
- Sidebar Publicação ganha entry "Desempenho" — gated por mesma permission da rota

### Pattern do filtro canônico (já entregue em Phase 45)

`DashboardController::adminDashboard()` linhas 589+ aplica filtro `whereExists → user_setores → cargos.slug IN (...)`. `PerformanceController` ganhou esse pattern na Phase 45 fix. Phase 49 ESTENDE: aceitar `$cargo_slug` opcional (single value em vez de IN) e propagar pro `PortfolioScoreService`.

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning ou implementing.**

### Briefings e captura

- `.planning/todos/pending/270629-melhorias-carteira-desempenho-gamificacao-ml.md` — briefing umbrella (Item 1)

### Patterns existentes (a investigar/reusar)

- `app/Http/Controllers/PerformanceController.php` (linha 39+) — index() recebe filtro de users; Phase 45 alinhou ao DashboardController via user_setores → cargos
- `app/Http/Controllers/DashboardController.php` (linhas 589+) — pattern do filtro canônico por cargo
- `app/Services/PortfolioScoreService.php` — `compute()` aceita lista de users; precisa ganhar opção de filtro `?string $cargoFilter`
- `resources/js/Pages/Performance/Index.jsx` (ou similar — research confirma) — page React do ranking atual
- `resources/js/Layouts/AppLayout.jsx` — sidebar; tem dropdown Publicação (research confirma)
- `routes/web.php` — performance.index existe; precisa adicionar `publicacao.desempenho.index` ou similar
- Roles de publicação: `gestor / publicador / lider` — research mapeia onde estão definidas (permission keys, helpers `User::hasPubPermission()`)

### Memory cross-refs

- `feedback_project_priorities` — acertividade + praticidade
- `feedback_gsd_language_pt_br` — pt-BR
- `project_legacy_columns_rename` — Phase 7 renomeou colunas legacy; NÃO usar publication_role legado

</canonical_refs>

<deferred>
## Deferred Ideas

- Score diferenciado por função (peso sugador vs PPA) — Phase 47 (depends Phase 44 + 46)
- Histórico longitudinal de scores — Phase 46
- Gamificação OAuth ML — Phase 50
- Tabs/visualizações adicionais (ex: por mentor específico) — futuro

</deferred>

---

*Phase: 49-rankings-de-desempenho-por-fun-o-ranking-separado-de-publica*
*Context gerado: 2026-06-29 (síntese lean — sem discuss-phase interativo)*
