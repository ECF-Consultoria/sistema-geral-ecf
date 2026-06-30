# Phase 46: Histórico longitudinal de scores na página de desempenho — Context

**Gathered:** 2026-06-30
**Status:** Ready for research
**Source:** Síntese lean — ROADMAP entry + briefing `metodologia-desempenho-carteira.md` + leitura direta de PortfolioScoreService

<domain>
## Phase Boundary

A página `/performance` calcula score on-the-fly a cada request (chama `PortfolioScoreService::compute()` por user). Resultado: classificação muda todo dia conforme variação da Adman/ML, e ninguém consegue decidir "quem é realmente o melhor/pior" — só vê o ranking do momento.

**Esta phase entrega persistência longitudinal:**

1. **Snapshot diário** dos scores via job no scheduler (executa depois da cascata D-1)
2. **UI delta** na `/performance` mostrando variação vs dia anterior + vs semana anterior
3. **Gráfico de evolução individual** mostrando a curva de cada profissional ao longo do tempo
4. **Comparação com mediana do grupo** ao longo do tempo (princípio do briefing: "acima/abaixo da mediana do time")

A metodologia de scoring justa do briefing `metodologia-desempenho-carteira.md` **JÁ ESTÁ implementada** em `PortfolioScoreService` (quick 260623). Esta phase não refatora score — só captura no tempo o que o serviço já produz.

</domain>

<decisions>
## Implementation Decisions

### Escopo LOCKED

- **DENTRO** da Phase 46:
  - Migration `desempenho_score_snapshots` (user_id, ref_date, score, classificacao, ranking_pos, tem_base_comparativa, empresas_carteira, empresas_eligiveis, breakdown_json) com unique key (user_id, ref_date) para idempotência
  - Model `DesempenhoScoreSnapshot` com cast `breakdown` → array
  - Comando Artisan `desempenho:snapshot-scores` que itera os mesmos users do PerformanceController e persiste 1 linha/user via `updateOrCreate`
  - Schedule diário às 13:30 BRT (depois do `sync-polos-faturamento-d1` 13:00) com `withoutOverlapping()` + `onOneServer()`
  - PerformanceController enriquece cada item do ranking com `delta_vs_ontem`, `delta_vs_semana_passada` lidos da snapshot table
  - UI: 2 indicadores compactos na linha do ranking (`↑ +2.3` verde / `↓ −1.1` vermelho / `=` neutro)
  - UI: novo endpoint+componente "Evolução individual" mostrando gráfico de linha do score do user nos últimos N dias (default 30) — pode ser drawer/modal ao clicar no nome do profissional, ou widget separado
  - PHPUnit Feature tests: snapshot idempotente, delta calculado corretamente, gráfico endpoint retorna dados ordenados

- **FORA** da Phase 46:
  - Refatoração da metodologia de scoring — JÁ implementada em `PortfolioScoreService` (quick 260623)
  - Ajuste de pesos do brief por cargo (analista vs estrategista) — Phase 47
  - Backfill histórico (snapshot só começa a popular a partir do dia do deploy; UI lida com período curto graciosamente)
  - Snapshots por cargo separados (Phase 49 entrega tabs e o snapshot é por user — o filtro de cargo aplica na leitura)
  - Snapshot do ranking de publicação (rota `/publicacao/desempenho` da Phase 49 — pode reusar a mesma tabela e job estendido em phase futura)

### Como rodar o backfill (opcional, fora da phase mas anotado)

Snapshot só começa a popular a partir do deploy. Para ter delta "vs ontem" no dia 2, basta esperar 1 dia. Para fazer backfill artificial dos últimos N dias (preencher curva inicial), o operador pode rodar o comando com `--date=YYYY-MM-DD` retroativo — mas isso reflete o score CALCULADO HOJE, não o que teria sido produzido naquele dia. Por isso fica fora do escopo: gera dados enganosos. Tratamento correto = esperar a curva crescer organicamente.

### Abordagem técnica

- **Migration**: tabela enxuta (1 linha/user/dia). Sem FK em `users.id` para sobreviver a `deleted users` (soft delete). Index em (`user_id`, `ref_date`) — leituras são sempre WHERE user_id + ORDER BY ref_date.
- **Job vs Command**: usar Artisan Command (sufixa o pattern de `adman:sync`, `goals:calculate`, etc — todos comandos no scheduler). Não usa Job de queue porque é trabalho síncrono curto (~5-10s pra ~30 users).
- **Comparações temporais**: `delta_vs_ontem` = score hoje − score do snapshot mais recente strictly anterior a hoje (não exige que seja exatamente D-1 — se feriado/cron falhou pega último disponível). `delta_vs_semana_passada` = score hoje − snapshot com `ref_date <= today − 7d ORDER BY ref_date DESC LIMIT 1`.
- **Endpoint do gráfico**: rota nova `GET /performance/{user}/evolucao?period=30` retorna JSON com lista de pontos (date, score, ranking_pos). Componente React consome via fetch sob demanda quando user expande drawer.
- **UI placement**: 2 indicadores delta na coluna existente do score (compactos, ao lado da posição); gráfico individual via drawer ou modal ao clicar no nome — não polui o ranking principal.

### Claude's Discretion

- Nome do comando: `desempenho:snapshot-scores` vs `scores:snapshot` vs `performance:snapshot` — escolher consistente com nomes existentes
- Horário do schedule: `13:30` (logo após 13:00 polos) ou `14:00` (margem maior) — Plan decide com base em duração observada da cascata
- Forma da UI do gráfico: drawer Radix vs modal `Dialog` — escolher o que outras pages do projeto usam (revisar `Sugadores/Show.jsx` ou `Mlb/*` em pesquisa)
- Granularidade do snapshot: 1 linha/user/dia (locked) — não snapshotear por hora, dia é suficiente

</decisions>

<specifics>
## Specific Ideas

### Comportamento esperado

**Snapshot diário:**
- Roda às 13:30 BRT (depois da cascata D-1 que termina em 13:00)
- Itera os mesmos users do PerformanceController (whereExists user_setores → cargos analista/estrategista, active=true)
- Para cada user: chama `PortfolioScoreService::compute()`, persiste 1 linha em `desempenho_score_snapshots` via `updateOrCreate(['user_id', 'ref_date'])`
- Idempotente: re-run no mesmo dia atualiza o snapshot (não duplica)

**UI `/performance`:**
- Coluna score ganha 2 micro-indicadores: `↑ +2.3 hoje · ↑ +5.1 semana` (ou variação visual)
- Click no nome do profissional → abre drawer/modal "Evolução do score" com gráfico Recharts (LineChart) dos últimos 30 dias
- Gráfico mostra: linha individual do user + linha pontilhada da mediana do grupo no mesmo período (princípio "acima/abaixo da mediana do time")

### Métricas justas — herdadas do PortfolioScoreService (não mudam)

O score persistido carrega o `breakdown_json` com:
- `crescimento_ajustado_pct` (cap ±20%)
- `empresas_em_crescimento.pct`
- `atingimento_meta.pct`
- `recuperacao.pct`
- `execucao_ads.pct`
- `qualidade.avg_nps`, `meetings`, `absenteismo_pct`
- `faturamento.atual`, `faturamento.anterior`
- `tem_base_comparativa`, `empresas_eligiveis`, `empresas_carteira`

Tudo isso é serializado pra `breakdown_json` (TEXT/JSON). Score numérico, classificação string e ranking_pos viram colunas próprias pra queries rápidas.

### Cascata D-1 vigente (referência)

```
04:00  notifications:cleanup
08:00  ml:refresh-tokens
09:00  nps:disparar-mensal
11:00  adman:sync                ← fonte primária
11:05  ml:sync                   ← fonte secundária (empresas ML)
11:30  adman:sync-faturamento
11:45  goals:calculate
11:55  CalculateSetorGoalResults (job)
12:00  sugadores:analyze
12:30  sugadores:cleanup-quarentena
12:45  RefreshGrossBillingCacheJob
13:00  SyncPolosFaturamentoJob   ← fim da cascata
─────────────────────────────────
13:30  desempenho:snapshot-scores ← NOVA (Phase 46)
```

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning ou implementing.**

### Briefings e captura

- `metodologia-desempenho-carteira.md` (root, untracked) — metodologia de scoring justa; já implementada em `PortfolioScoreService` (quick 260623); usar como CONTEXTO de design da UI longitudinal
- `.planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md` — Item 4 captura inicial da Phase 46

### Patterns existentes (a investigar/reusar)

- `app/Services/PortfolioScoreService.php` (linhas 1-120+) — serviço já entrega `compute(User $user)` com payload completo; Phase 46 só consome
- `app/Http/Controllers/PerformanceController.php` (linhas 20-120) — pattern de iterar users, montar ranking, enriquecer com cargo_slug; mesma lista de users vira input do snapshot
- `routes/console.php` (linhas 15-165) — pattern de Schedule::command/job com `dailyAt()` + `withoutOverlapping()` + timezone BRT; cascata D-1 termina às 13:00
- `app/Console/Commands/*.php` — pattern de comando: signature, description, handle() com logging `[Tag]`, retorno self::SUCCESS
- `resources/js/Pages/Performance/Index.jsx` — page React do ranking atual; ganha indicadores delta e ação para abrir drawer/modal de evolução
- Recharts já está no projeto (usado em Carteira) — reusar `LineChart` para gráfico individual

### Memory cross-refs

- `feedback_project_priorities` — acertividade + praticidade
- `feedback_gsd_language_pt_br` — pt-BR
- `feedback_lean_planning` — pular discuss/research/plan-check overhead — APLICADO nesta phase

</canonical_refs>

<deferred>
## Deferred Ideas

- Pesos diferenciados por cargo (analista vs estrategista) com scoring negativo por sugador não-resolvido — Phase 47
- Snapshot do ranking de publicação (rota `/publicacao/desempenho` da Phase 49) — phase futura, reusa mesma tabela com discriminator opcional
- Backfill artificial de snapshots passados — não fazer (gera dados enganosos)
- Snapshot por hora ou granularidade < 1 dia — não necessário pra decisão de bonificação
- Comparação cross-period customizável pelo operador (drag-and-drop período) — futuro

</deferred>

---

*Phase: 46-historico-longitudinal-scores-desempenho*
*Context gerado: 2026-06-30 (síntese lean — sem discuss-phase interativo)*
