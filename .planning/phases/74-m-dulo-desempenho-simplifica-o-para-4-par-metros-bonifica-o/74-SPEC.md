# Phase 74: Módulo Desempenho — simplificação para 4 parâmetros + bonificação — Specification

**Created:** 2026-07-09
**Ambiguity score:** 0.0625 (gate: ≤ 0.20)
**Requirements:** 14 locked

## Goal

Substituir o `PortfolioScoreService` atual (6 métricas ponderadas com pesos por categoria) por um `PortfolioScoreServiceV2` de **4 parâmetros** (NPS médio, % variação de faturamento vs mês anterior, % variação de margem de contribuição vs mês anterior, absenteísmo em standby), com cálculo por **média direta em escalas naturais**, consolidação **mensal fechada** (dia 1 do mês seguinte após sync Adman), faixas de bônus editáveis pelo admin via UI dedicada e artigo dinâmico no /manual sincronizado com a config.

## Background

**Estado atual (code-level):**
- `app/Services/PortfolioScoreService.php` implementa 6 métricas ponderadas: crescimento ajustado (30%), % em crescimento (20%), atingimento de meta (20%), recuperação (15%), execução (10%), qualidade NPS + reuniões (5%). Retorna `{score, classificacao, metricas, ...}`.
- Callsites: `PerformanceController::index/dashboardCarteira`, `PortfolioController::renderPortfolio`, `DashboardController::adminDashboard` (widget `performance_equipe`), `SnapshotDesempenhoScores` (cron 13:30 BRT diário).
- Persistência: tabela `desempenho_score_snapshots` armazena 1 linha por (`user_id`, `ref_date`) com colunas do shape v1.
- Frontend: `resources/js/Pages/Performance/{Dashboard,Index,Show}.jsx` renderiza cards por categoria + ranking + evolução histórica.
- Manual: `/manual` já existe com módulo de artigos (`resources/js/Pages/Manual/Artigos/`), populado apenas com `Cronograma.jsx` hoje.

**O que está errado / faltando:**
- A diretoria/gestão da ECF (2026-07-09) simplificou a lógica de bonificação. As 6 métricas ponderadas viraram overhead conceitual — o time não sabia explicar como o score sai. A nova regra é: **4 parâmetros, média direta, faixas explícitas**.
- Não há UI para admin ajustar faixas de bônus — hoje elas seriam hardcoded.
- Documento explicativo da régua não existe no /manual.
- Regra "bônus máximo por 2 meses consecutivos intermediário" não é suportada pela engine atual.

**Trigger:** decisão de negócio da diretoria/gestão em 2026-07-09 — impacto direto em bônus mensal real da equipe Performance.

## Requirements

1. **DESEMP-01 · Engine v2 de score**: Novo serviço de cálculo com 4 parâmetros substitui o v1.
   - Current: `PortfolioScoreService::compute(User)` retorna score de 0-100 baseado em 6 métricas ponderadas (crescimento, % crescimento, meta, recuperação, execução, qualidade).
   - Target: `PortfolioScoreServiceV2::compute(User, Carbon $mesReferencia)` (ou v1 substituído no mesmo namespace) retorna `{nps_medio: float|null, var_faturamento_pct: float|null, var_margem_pct: float|null, absenteismo_pct: float|null, nota_final: float, faixa_bonus: string, mes_referencia: string}`.
   - Acceptance: teste feature com fixture `Carlos` (NPS 4.25, var_fat 3%, var_margem 2.8%) retorna `nota_final = 3.35` e `faixa_bonus = 'sem_bonus'`.

2. **DESEMP-02 · Fórmula de nota final = média direta em escalas naturais**: A nota final é `média(nps_medio, var_faturamento_pct, var_margem_pct)`, **sem** normalizar pelas réguas 1-5.
   - Current: nota final aplicada via `scoreFinal()` que redistribui pesos categoricamente.
   - Target: `nota_final = round((nps_medio + var_faturamento_pct + var_margem_pct) / N, 2)` onde N = número de componentes não-null. Absenteísmo NÃO participa (standby — REQ 6).
   - Acceptance: método `computeNotaFinal($nps, $fat, $margem)` retorna `3.35` para os inputs (4.25, 3, 2.8); retorna `null` quando todos os componentes são null.

3. **DESEMP-03 · Componente NPS = média das notas do mês; sem notas = 0**: NPS do analista/estrategista é a média aritmética das notas 1-5 que recebeu no mês; ausência de notas força **0** (penaliza).
   - Current: `PortfolioScoreService` computa avg NPS na dimensão qualidade (peso 5%) via `NpsResponse->{scoreField}` legacy.
   - Target: `computeNpsMedio(User $u, Carbon $mes)`: soma respostas NPS do mês onde a survey pertence a empresa da carteira do user + resposta contém score na dimensão apropriada (`estrategista` ou `analista`); retorna `AVG(peso)` via `NpsScoreCalculator` (dual-path v15/legacy). Sem respostas → retorna `0.0` (penaliza, decisão da diretoria).
   - Acceptance: teste com user sem NpsResponse no mês → `nps_medio = 0.0` e `nota_final` reduz proporcionalmente; user com 3 respostas notas 5/4/3 → `nps_medio = 4.0`.

4. **DESEMP-04 · % variação de faturamento = média das % vs mês anterior por empresa**: Para cada empresa ATIVA na carteira do user com `revenue > 0` em ambos os meses, calcula `(rev_atual - rev_anterior) / rev_anterior * 100`; retorna média das %.
   - Current: `PortfolioScoreService::compute` compara rolling 30d vs revenue_prev_period via `SUM(adman_metrics.revenue_prev_period)`.
   - Target: `computeVarFaturamento(User, Carbon $mes)`: para cada empresa da carteira, obter `rev_atual = revenue(mes)` e `rev_anterior = revenue(mes-1)` via **ML OAuth first + Adman fallback** (padrão Phase 61 `MetricsProviderFactory`); descartar empresas sem baseline (`rev_anterior <= 0`); descartar empresas NOVAS (na carteira há menos de 2 meses); retornar `AVG(varPct)` das restantes. Retorna `null` se nenhuma empresa qualifica.
   - Acceptance: teste com carteira `[-2%, +7%, +4%]` retorna `3.00%` (média exata); teste com carteira contendo empresa nova (sem baseline) confirma que ela NÃO entra no cálculo; teste com carteira vazia elegível retorna `null`.

5. **DESEMP-05 · % variação de margem de contribuição = média das % vs mês anterior por empresa**: Análoga a REQ 4, mas usando `contribution_margin` da fonte Adman/ML.
   - Current: `PortfolioScoreService` usa `metrics->avg('contribution_margin_pct')` sem lookback.
   - Target: `computeVarMargem(User, Carbon $mes)`: para cada empresa, `margem_atual = contribution_margin(mes)` e `margem_anterior = contribution_margin(mes-1)`; calcula `(atual - anterior) / anterior * 100`; média das %. Fonte: **Adman preferencial** (a Adman é a fonte canônica de margem — spec explicita que ML não expõe custo). Retorna `null` se nenhuma empresa qualifica.
   - Acceptance: teste com fixture manual de 3 empresas confirma média correta; teste com empresa sem `cost_price` cadastrado no Adman (margem = 100% artificial) documenta que ela ENTRA no cálculo — a diretoria conhece o gap ("burlar") e o time paga o preço até integração ML OAuth cobrir o campo.

6. **DESEMP-06 · Absenteísmo em standby — placeholder no cálculo e UI**: Absenteísmo NÃO entra no cálculo agora; card na UI exibe placeholder com badge "Em breve".
   - Current: PortfolioScoreService não modela absenteísmo (usa "reunions absenteeism" dentro de qualidade, semântica diferente).
   - Target: `computeAbsenteismo(User, Carbon $mes)` retorna `null` sempre nesta phase. Card `Absenteísmo` no Dashboard e Show exibe valor `—` + badge amarelo `Em breve — fonte de dados em definição`. Não subtrai da `nota_final`.
   - Acceptance: teste confirma `absenteismo_pct = null` para qualquer user; snapshot com `null` OK; frontend renderiza badge "Em breve" no card.

7. **DESEMP-07 · Faixas de bônus configuráveis via tabela dedicada `bonus_faixas`**: Admin edita faixas em UI própria; sem hardcode.
   - Current: Nenhuma tabela ou UI para faixas de bônus.
   - Target: Nova migration cria `bonus_faixas` `(id, nome, nota_min, nota_max, ordem, ativo, descricao, created_at, updated_at)`. Seed inicial com 4 faixas: `sem_bonus [0.00, 3.99]`, `basico [4.00, 4.49]`, `intermediario [4.50, 4.99]`, `maximo [5.00, 5.00]`. Model `BonusFaixa` com ActivityLog. Método `PortfolioScoreServiceV2::classificarFaixa(float $nota): string` retorna slug da faixa (ex. `intermediario`).
   - Acceptance: 4 rows seed criadas; `classificarFaixa(3.35)` retorna `sem_bonus`; `classificarFaixa(4.20)` retorna `basico`; admin altera limite via UI e re-cálculo reflete mudança sem deploy.

8. **DESEMP-08 · Regra "2 meses consecutivos intermediário → bônus máximo"**: Ao classificar, se `nota_final` do mês M é intermediário E `nota_final` do mês M-1 do mesmo user também é intermediário → promove para MÁXIMO.
   - Current: Regra não existe.
   - Target: Após `classificarFaixa`, se resultado = `intermediario`, consultar `desempenho_score_snapshots` (ou nova tabela mensal — ver REQ 12): se existe snapshot do user para (mes − 1 mês) com faixa `intermediario`, promover retorno para `maximo`. Se `nota_final` do mês corrente ≥ 5.00 exato, também promover para `maximo`.
   - Acceptance: teste com snapshot histórico `[junho: intermediario, julho: intermediario]` → cálculo de julho retorna `faixa_bonus = maximo`; teste com apenas 1 mês intermediário → retorna `intermediario` (não promove).

9. **DESEMP-09 · Frequência = mês calendário fechado; consolidação dia 1 mês seguinte após sync Adman**: Score é mensal, consolidado após sync Adman D-1 do mês anterior estar completo.
   - Current: `SnapshotDesempenhoScores` roda 13:30 diário; snapshot rolling 30d.
   - Target: `SnapshotDesempenhoScores` reescrita como **mensal**: roda dia 1 de cada mês às 14:00 BRT (após Adman sync 11:00 do D-1); calcula score do mês encerrado; grava 1 row por (user, mes_referencia). Cron diário atual pode ser mantido como "dry-run/health check" mas sem escrita nova. Command aceita `--mes=YYYY-MM` para reprocessar. Schedule::command atualizado em `routes/console.php`.
   - Acceptance: teste com Carbon::setTestNow('2026-08-01 14:05') + factory de user + carteira + fixtures NPS/AdmanMetric de julho → executa comando → grava snapshot com `mes_referencia = 2026-07-01`; teste em 2026-08-15 confirma que rodar de novo é idempotente (não duplica).

10. **DESEMP-10 · Sem carteira → excluir do ranking + badge "Sem carteira no período"**: User (analista/estrategista) sem empresas ativas atribuídas no mês NÃO entra no ranking; Show exibe placeholder.
    - Current: PortfolioScoreService::compute retorna score = 0 ou valores nulos que aparecem no ranking mesmo assim.
    - Target: `computeUniverso(User, Carbon)` verifica `$user->companies()->where('active', true)->exists()` no mês; se falso, retorna shape sem score com flag `sem_carteira: true` + `motivo: 'Sem carteira no período'`. `SnapshotDesempenhoScores` PULA usuários sem carteira. `Performance/Dashboard.jsx` filtra usuários com flag e não os ranquiza.
    - Acceptance: teste com user sem `company_users` no mês → `computeUniverso` retorna `sem_carteira=true`; dashboard não lista esse user no ranking; página Show exibe badge amarelo "Sem carteira em julho/2026".

11. **DESEMP-11 · Fonte de dados: ML OAuth first + Adman fallback**: Faturamento por empresa prioriza fonte ML OAuth (via `MetricsProviderFactory` da Phase 61); cai em Adman quando ML não disponível.
    - Current: `PortfolioScoreService` usa `AdmanService::getCachedGrossBillingsMany` diretamente.
    - Target: novo service usa `MetricsProviderFactory::caseFor(Company)` para escolher provider; providers já existentes (`AdmanSugadoresProvider`, `MlSugadoresProvider`, `UnifiedProvider`). Empresas caso `so-ml` usam ML; `so-adman` usam Adman; `ambos` prefere ML; `none` excluída do cálculo. Margem SEMPRE via Adman (ML não expõe custo — spec conhece o gap).
    - Acceptance: teste com empresa `mlToken != null` → provider ML consumido para faturamento; empresa sem mlToken → Adman consumido; empresa marcada como `none` → excluída da carteira do user no cálculo.

12. **DESEMP-12 · Página admin `/desempenho/configuracao` para editar faixas**: Nova rota admin renderiza UI CRUD das faixas.
    - Current: Nenhuma rota.
    - Target: `Route::get('/desempenho/configuracao', [DesempenhoConfigController::class, 'index'])->middleware('role:admin')`. Página `Desempenho/Configuracao.jsx` lista faixas ativas com edit inline de `nota_min`, `nota_max`, `nome`, `descricao`. Endpoints REST: PATCH `/desempenho/configuracao/faixas/{faixa}`, PATCH `/desempenho/configuracao/faixas/{faixa}/toggle-active`. Validação: `nota_min < nota_max`; `nota_min >= 0`; `nota_max <= 5`; sem sobreposição entre faixas ativas.
    - Acceptance: user não-admin → 403; admin acessa e vê 4 faixas seed; admin edita `intermediario.nota_min` de 4.50 para 4.60 → salva com sucesso; validação de sobreposição rejeita `nota_max: 4.70` de basico quando intermediario começa em 4.60.

13. **DESEMP-13 · Artigo dinâmico `/manual/desempenho-bonificacao` sincronizado com config**: Artigo do manual renderiza tabela de faixas a partir do banco em tempo real.
    - Current: Não existe artigo sobre bonificação no /manual.
    - Target: `resources/js/Pages/Manual/Artigos/DesempenhoBonificacao.jsx` (ou rota dedicada) recebe prop `faixas` do `ManualController::show` quando slug = `desempenho-bonificacao`. Renderiza texto explicativo estático (metodologia dos 4 parâmetros) + tabela dinâmica com nome/faixa/descricao das rows de `bonus_faixas`. Admin edita config → doc reflete no próximo page load.
    - Acceptance: acessar `/manual/desempenho-bonificacao` retorna 200 e mostra as 4 faixas seed; admin edita `intermediario.nota_min = 4.60` → reload da página do manual mostra 4.60 na tabela sem exigir deploy.

14. **DESEMP-14 · Big bang deploy — v1 removido, snapshots antigos preservados sem UI**: Remove código v1 no mesmo commit da entrega v2; snapshots antigos ficam na tabela mas UI não os exibe mais.
    - Current: v1 rodando com callsites em 4 arquivos + cron diário.
    - Target: `PortfolioScoreService` DELETADO ou renomeado para `PortfolioScoreServiceLegacyDoNotUse` com `@deprecated`. Callsites (PerformanceController, PortfolioController, DashboardController) refatorados para `PortfolioScoreServiceV2`. Cron diário substituído pelo mensal. Rows antigas de `desempenho_score_snapshots` permanecem no banco mas `Performance/Dashboard.jsx` filtra `mes_referencia >= '2026-08-01'` (ou coluna versão nova).
    - Acceptance: `grep -r "PortfolioScoreService" app/` NÃO retorna referências ativas ao serviço antigo; suite Feature de rotas Performance passa com engine nova; dashboard não exibe scores anteriores a 2026-08-01.

## Boundaries

**In scope:**
- `PortfolioScoreServiceV2` (ou v1 substituído) com engine de 4 parâmetros
- Tabela `bonus_faixas` + Model + seed inicial de 4 faixas
- Command `SnapshotDesempenhoScores` reescrito como mensal
- Rota admin `/desempenho/configuracao` + Controller + página Inertia
- Rotas REST para editar faixas + toggle active
- Frontend: `Performance/{Dashboard,Index,Show}.jsx` reescritos para o novo shape de props
- Artigo dinâmico `/manual/desempenho-bonificacao` sincronizado com `bonus_faixas`
- Refactor de callsites do v1: `PerformanceController`, `PortfolioController`, `DashboardController::adminDashboard`
- Fonte ML OAuth first (via `MetricsProviderFactory`) + Adman fallback para faturamento; Adman canônico para margem
- Suite de testes Feature cobrindo REQ 1-14, incluindo fixture Carlos como âncora

**Out of scope:**
- `PublicadorScoreService` (setor MLB) — regras próprias, Phase separada se a diretoria quiser mudar lá também
- Integração real de absenteísmo (biometria facial da porta OR login-based) — placeholder "Em breve" nesta phase; fonte de dados em definição
- Migração de snapshots históricos v1 para v2 — histórico v1 fica preservado na tabela mas não é comparável (fórmula diferente); dashboard filtra por `mes_referencia >= '2026-08-01'`
- Comparativo v1 vs v2 no dashboard — big bang, sem período de convivência
- Bônus por cargo diferenciado (ex. estrategista tem faixas diferentes de analista) — faixas globais nesta phase
- Notificação push/email ao analista/estrategista quando fecha o mês — futuro
- Backfill de snapshots mensais para meses anteriores a 2026-08-01 — snapshot começa em agosto/2026

## Constraints

- **PHP memory**: cálculo do batch mensal (para ~15-20 usuários Performance × ~30 empresas × queries de metrics/NPS/margem) precisa caber em 256MB PHP-FPM. Se estourar, ajustar `ini_set('memory_limit', '512M')` no command (mesmo padrão do hotfix `DashboardController::adminDashboard`).
- **Idempotência**: rerun de `SnapshotDesempenhoScores --mes=YYYY-MM` NÃO deve duplicar rows — usar `updateOrCreate(['user_id', 'mes_referencia'])`.
- **Rollout window**: v2 entra em produção em deploy único. Time avisado antes via aviso interno + atualização do `/manual/desempenho-bonificacao`. Diretoria já assinou a mudança — não cabe A/B.
- **NpsScoreCalculator dual-path**: componente NPS precisa suportar surveys v15 (via `NpsScoreCalculator`) E surveys legacy (via `score_analista`/`score_estrategista` direto) — mesmo padrão preservado nas Phases 72/73.
- **PT-BR**: todos os textos de UI, mensagens de erro, badges e labels em pt-BR consistente com o resto do painel.
- **Zero regressão nas suites existentes**: `DesempenhoScoreSnapshotTest`, `DesempenhoEvolucaoTest`, `PerformanceCargoFilterTest`, `Portfolio/RenderPortfolioTest` precisam adaptar ao shape novo mas continuar verdes.

## Acceptance Criteria

- [ ] Fixture Carlos (NPS 4.25 + var_fat 3% + var_margem 2.8%) retorna `nota_final = 3.35` e `faixa_bonus = 'sem_bonus'` em teste feature
- [ ] Componente NPS retorna `0.0` quando user não recebeu nenhuma nota no mês (não `null`, não excluído da média)
- [ ] Componente var_faturamento retorna `null` quando nenhuma empresa da carteira qualifica (sem baseline OU empresa nova)
- [ ] Componente var_margem usa Adman como fonte canônica (documenta o gap ML OAuth × custo)
- [ ] Card Absenteísmo no Dashboard e Show exibe placeholder "—" com badge "Em breve"
- [ ] Tabela `bonus_faixas` criada com 4 rows seed (`sem_bonus`, `basico`, `intermediario`, `maximo`)
- [ ] Cálculo com nota = 4.35 classifica como `basico`; nota = 5.00 exato classifica como `maximo`
- [ ] Regra "2 meses consecutivos intermediário → máximo" testada com snapshot [junho: intermediario, julho: intermediario] → julho retorna `maximo`
- [ ] `SnapshotDesempenhoScores` executado em 2026-08-01 grava snapshot com `mes_referencia = 2026-07-01`; segunda execução no mesmo dia é idempotente
- [ ] User sem carteira ativa no mês → snapshot NÃO é gravado + dashboard não lista o user no ranking
- [ ] Rota `/desempenho/configuracao` retorna 403 para não-admin; 200 para admin com CRUD funcional das faixas
- [ ] Validação impede sobreposição entre faixas ativas (ex: `intermediario.nota_min < basico.nota_max`)
- [ ] Rota `/manual/desempenho-bonificacao` renderiza tabela de faixas em sync com o banco em tempo real (sem deploy)
- [ ] `grep -r "PortfolioScoreService[^V]" app/ resources/` retorna 0 matches ativos (v1 legado documentado como deprecated ou removido)
- [ ] Dashboard `Performance/Dashboard.jsx` NÃO exibe scores anteriores a 2026-08-01 (filtra por `mes_referencia`)
- [ ] Suite existente `DesempenhoScoreSnapshotTest` + `PerformanceCargoFilterTest` continua verde após adaptação ao shape novo

## Ambiguity Report

| Dimension          | Score  | Min  | Status | Notes                                                    |
|--------------------|--------|------|--------|----------------------------------------------------------|
| Goal Clarity       | 0.97   | 0.75 | ✓      | Fórmula, frequência, fontes, edge cases todos travados   |
| Boundary Clarity   | 0.92   | 0.70 | ✓      | Publicador OUT, absenteísmo standby, sem A/B             |
| Constraint Clarity | 0.92   | 0.65 | ✓      | Memory bump, idempotência, dual-path NPS                 |
| Acceptance Criteria| 0.92   | 0.70 | ✓      | 16 checkboxes pass/fail + fixture Carlos como âncora     |
| **Ambiguity**      | **0.0625** | **≤0.20** | **✓✓** | Bem abaixo do gate                                       |

## Interview Log

| Round | Perspective       | Question summary                                        | Decision locked                                                              |
|-------|-------------------|--------------------------------------------------------|------------------------------------------------------------------------------|
| 1     | Researcher        | Faturamento — vs mês anterior OU vs meta?              | vs mês anterior (fiel ao exemplo Carlos)                                     |
| 1     | Researcher        | Cálculo final — soma direta ou réguas 1-5?             | Soma direta em escalas naturais (fiel ao exemplo Carlos)                     |
| 1     | Researcher        | Frequência — rolling 30d ou mês fechado?               | Mês calendário fechado                                                       |
| 2     | Simplifier        | PortfolioScoreService antigo — como tratar?            | Substituir completamente + apagar histórico visualmente                      |
| 2     | Boundary          | PublicadorScoreService dentro ou fora?                 | Fora de escopo — Phase 74 só Performance                                     |
| 2     | Boundary          | Absenteísmo standby — como aparece na UI?              | Placeholder visível com badge "Em breve"                                     |
| 3     | Seed Closer       | Estrutura da config de faixas na UI                    | Tabela dedicada `bonus_faixas` + página admin dedicada                       |
| 3     | Seed Closer       | Doc no /manual sincronizada — como?                    | Artigo dinâmico gerado a partir das faixas ativas                            |
| 4     | Failure Analyst   | Analista sem carteira no mês — como aparece?           | Não entra no ranking + badge "Sem carteira no período"                       |
| 4     | Failure Analyst   | NPS = 0 respostas no mês — como tratar?                | NPS = 0 na média (penaliza) — decisão da diretoria                           |
| 4     | Failure Analyst   | Empresa nova sem baseline — entra no cálculo?          | Pular do cálculo de variação (excluir da média)                              |
| 5     | Seed Closer       | Quando fecha o mês oficialmente?                       | Dia 1 do mês seguinte, após sync Adman                                       |
| 5     | Seed Closer       | Fonte de dados para faturamento por empresa?           | ML OAuth first + Adman fallback (Phase 61 flow)                              |
| 5     | Seed Closer       | Como validar regra "2 meses seguidos intermediário"?  | Query no snapshot mensal fechado                                             |
| 6     | Seed Closer       | Rollout — big bang, feature flag ou coexistência?      | Big bang no deploy — v2 substitui v1 no mesmo commit                         |
| 6     | Seed Closer       | Teste canonical bloqueante?                            | Fixture "Carlos" da spec — nota final = 3.35, sem bônus                      |

---

*Phase: 74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o*
*Spec created: 2026-07-09*
*Next step: /gsd:discuss-phase 74 — decisões de implementação (schema exato, shape de props Inertia, service constructor DI, layout dos cards, etc.)*
