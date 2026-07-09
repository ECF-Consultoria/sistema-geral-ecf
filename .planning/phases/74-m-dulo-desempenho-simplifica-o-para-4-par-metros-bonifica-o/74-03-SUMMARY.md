---
phase: 74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o
plan: 03
subsystem: desempenho
tags: [service, engine, score, bonificacao, nps, faturamento, margem]
requires:
  - Plan 74-01 (DesempenhoScoreSnapshot com mes_referencia + scopes mensal/diario)
  - Plan 74-02 (BonusFaixa::classificar + seed 4 faixas)
provides:
  - `App\Services\DesempenhoScoreService::compute(User, Carbon): array` (engine v2 completa)
  - Componentes independentes: NPS médio, % variação faturamento, % variação margem, absenteísmo (standby)
  - Classificação via `BonusFaixa` + promoção 2 meses consecutivos (DESEMP-08)
affects:
  - Plan 74-04 (comandos `desempenho:consolidar-mes` + `snapshot-scores` reescritos)
  - Plan 74-06 (`Performance/Dashboard.jsx` / `Show.jsx`)
  - Plans 74-09 / 74-10 (testes Feature com fixture Carlos)
tech-stack:
  added: []
  patterns:
    - Constructor DI de `MetricsProviderFactory` + `NpsScoreCalculator`
    - Dual-path v15 (NpsScoreCalculator) + legacy (score_estrategista/analista) — mesma convenção Phase 72/73
    - SQL agregado `selectRaw + groupBy` para revenue/margem (não itera rows PHP)
    - `whereDate` em vez de `where` para robustez SQLite (padrão SnapshotDesempenhoScores)
    - Cache in-memory da régua `BonusFaixa` por instância do service
key-files:
  created:
    - app/Services/DesempenhoScoreService.php (596 linhas)
  modified: []
decisions:
  - D-05 · Namespace `App\Services\DesempenhoScoreService` (alinhado ao módulo `/desempenho`)
  - D-06 · v1 permanece até Plan 74-04 (big bang no mesmo commit da entrega frontend)
  - D-07 · Assinatura `compute(User, Carbon $mesReferencia): array` com shape locked
  - D-11 · `MetricsProviderFactory::caseFor` decide a fonte por empresa (ML-first + Adman fallback)
  - D-17 · `classificarFaixa` delega para `BonusFaixa::classificar` + promoção em método separado
metrics:
  duration: 25min
  completed: 2026-07-09
  tasks: 1
  files: 1
  commits: [ca5b24f]
---

# Phase 74 Plan 03: Engine v2 do módulo Desempenho — 4 parâmetros + faixa configurável

Entrega o `App\Services\DesempenhoScoreService` que substitui a lógica de score do time Performance conforme a decisão da diretoria/gestão da ECF em 2026-07-09. A engine trabalha com 4 parâmetros em escalas naturais (NPS 1-5 + % variações + absenteísmo standby), aplica média direta e classifica na régua editável `bonus_faixas` (Plan 74-02).

## O que foi feito

### Task 1 — DesempenhoScoreService (commit `ca5b24f`)

Criado `app/Services/DesempenhoScoreService.php` com:

- **Constructor DI**: `MetricsProviderFactory $metricsFactory` + `NpsScoreCalculator $npsCalculator` (promoted properties). Propriedade privada `?Collection $faixasCache` para cache in-memory das faixas ativas.
- **`compute(User $user, Carbon $mesReferencia): array`** — API pública que retorna o shape locked em `74-03-PLAN.md` `<interfaces>`:
  - `user_id`, `user_name`, `mes_referencia`
  - `sem_carteira`, `motivo`, `empresas_carteira`, `empresas_com_baseline`
  - `componentes.nps_medio`, `var_faturamento_pct`, `var_margem_pct`, `absenteismo_pct`
  - `nota_final`, `faixa_bonus`, `faixa_promovida`
- **`computeUniverso`** — verifica `$user->companies()->where('active', true)` no mês; se vazio, retorna `sem_carteira=true` + motivo "Sem carteira em julho/2026" (DESEMP-10).
- **`computeNpsMedio`** — dual-path v15/legacy: primeiro tenta `NpsScoreCalculator::compute($response, $dim)`; se null, cai para `score_estrategista/score_analista` legacy. Média aritmética; sem respostas força `0.0` (DESEMP-03).
- **`computeVarFaturamento`** — filtro cascata:
  1. Empresas NOVAS (pivot `company_users.created_at` >= mês-1) excluídas.
  2. Empresas com `caseFor === 'none'` excluídas (DESEMP-11).
  3. Revenue atual: prefere ML (`MetricsProviderFactory::forCompany[0]->readForCompany`) quando `caseFor in ['ambos','so-ml']`; fallback ao SQL agregado `AdmanMetric SUM(revenue)`.
  4. Revenue anterior: sempre Adman local (baseline consolidado).
  5. Empresas com `rev_anterior <= 0` excluídas (sem baseline — DESEMP-04).
  6. Retorna média das variações + `empresas_com_baseline` para a UI.
- **`computeVarMargem`** — SEMPRE `AdmanMetric.contribution_margin` (spec conhece o gap ML sem custo — DESEMP-05). SQL agregado idêntico ao faturamento; descarta `margem_anterior <= 0`.
- **`computeAbsenteismo`** — retorna `null` sempre. Comentário cita "DESEMP-06 standby — fonte em definição".
- **`computeNotaFinal`** — filtra componentes não-null (`[nps, varFat, varMargem]`) e retorna média direta com 2 decimais. Absenteísmo NÃO entra (DESEMP-02). Retorna `null` se todos são null.
- **`classificarFaixa`** — usa cache in-memory `BonusFaixa::ativas()->ordenadas()->get()` e itera buscando primeira faixa com `nota_min <= nota <= nota_max` (comparação numérica após cast decimal:2). Retorna slug ou null. Não aplica DESEMP-08 (fica no método dedicado).
- **`promoverPor2MesesConsecutivos`** — se `$faixaAtual === 'intermediario'`:
  - Regra suplementar: nota corrente >= 5.00 promove direto para `maximo` (DESEMP-08 target).
  - Consulta snapshot mensal fechado do mês M-1 via `DesempenhoScoreSnapshot::mensal()->where('user_id')->whereDate('mes_referencia', ...)`.
  - Se `classificacao === 'intermediario'` no snapshot anterior → promove para `maximo`.
  - Senão retorna `intermediario` sem promoção.
- **`mesExtenso`** — helper pt-BR via `translatedFormat('F/Y')` (ex: "julho/2026").
- **`shapeSemCarteira`** — helper que padroniza o retorno quando `sem_carteira=true` (todos os componentes null, `nota_final=null`, `faixa_bonus=null`, `motivo` preenchido).

## Decisões implementadas

- **D-05** · Namespace `App\Services\DesempenhoScoreService` (alinhado ao módulo `/desempenho`).
- **D-06** · v1 preservado nesta phase — big bang de deleção fica no Plan 74-04.
- **D-07** · `compute(User, Carbon $mesReferencia): array` com shape locked (mesma superfície documentada em `74-03-PLAN.md` `<interfaces>`).
- **D-11** · `MetricsProviderFactory::caseFor` + `forCompany` decidem fonte por empresa; ML-first respeitado no faturamento.
- **D-17** · `classificarFaixa` delega para `BonusFaixa::classificar` (que retorna a Model completa) mas usa cache local para o path do loop de ranking. Regra DESEMP-08 fica no método separado, mantendo o Model puro.

## Deviations from Plan

Nenhuma — plan executado exatamente como escrito.

Ajuste de assinatura (não é desvio, é interpretação do spec): `computeVarFaturamento` retorna `array{pct: ?float, empresas_com_baseline: int}` em vez de `?float` puro, para expor `empresas_com_baseline` no shape final (chave `empresas_com_baseline` documentada no `<interfaces>` do plan). Os testes do Plan 74-09 vão consumir esse campo.

Regra suplementar `nota >= 5.00 => maximo` — o plano documenta em `<interfaces>` ponto 11 mas o SPEC DESEMP-08 é ambíguo se essa regra vale só em `intermediario` ou em qualquer faixa. Implementada da forma mais defensiva: só ativa quando `$faixaAtual === 'intermediario'`. Se a diretoria quiser aplicar em `basico` também, altera-se o guard no futuro sem quebrar o shape.

## Success Criteria

- [x] `php -l app/Services/DesempenhoScoreService.php` retorna "No syntax errors".
- [x] Constructor recebe `MetricsProviderFactory` E `NpsScoreCalculator` como propriedades promovidas.
- [x] `computeAbsenteismo` retorna literalmente `null` (não recebe absenteísmo em `computeNotaFinal`).
- [x] `computeNotaFinal` NÃO recebe absenteísmo como parâmetro (assinatura `?float $nps, ?float $varFat, ?float $varMargem`).
- [x] `classificarFaixa` invoca `BonusFaixa::ativas()->ordenadas()->get()` (não hardcode de faixas).
- [x] `promoverPor2MesesConsecutivos` consulta `DesempenhoScoreSnapshot` filtrando por `mes_referencia` (não `ref_date`).
- [x] Docblock/cabeçalho cita SPEC DESEMP-01 a DESEMP-11 + Phase 74 D-05/D-06/D-07/D-17.
- [x] Nenhum uso hardcoded de `score_analista`/`score_estrategista` sem passar por `NpsScoreCalculator` primeiro (fallback dual-path).
- [x] Arquivo tem 596 linhas (>= 250 mínimo do plano).
- [x] Service resolvível via container Laravel — smoke test `app(App\Services\DesempenhoScoreService::class)` retorna instância.

## Smoke test executado

```
php artisan tinker --execute="echo get_class(app(App\Services\DesempenhoScoreService::class));"
# → App\Services\DesempenhoScoreService
```

Container resolve com sucesso (DI de `MetricsProviderFactory` + `NpsScoreCalculator` funcionando).

## Threat Flags

Nenhum — service consome dados que já são internos ao painel (AdmanMetric local, NpsResponse local, snapshots locais). ML provider vem via factory já existente (Phase 60/61) e trata seu próprio rate limit + cache. Nenhuma nova superfície de rede ou autenticação exposta.

## Links

- SPEC: `.planning/phases/74-.../74-SPEC.md` DESEMP-01, 02, 03, 04, 05, 06, 07, 08, 10, 11
- CONTEXT decisões: `.planning/phases/74-.../74-CONTEXT.md` §D-05, D-06, D-07, D-11, D-17
- Consumidor imediato: `.planning/phases/74-.../74-04-PLAN.md` (comandos consolidação + snapshot)

## Self-Check: PASSED

- FOUND: `app/Services/DesempenhoScoreService.php`
- FOUND commit `ca5b24f` (Task 1 — engine v2 completa)
