# Fase 103: Carteira por período (v18.0) — Pesquisa

**Pesquisado:** 2026-07-20
**Domínio:** Refatoração de controller Laravel (`PortfolioController`) para consumir `MetricPeriodResolver` (Fase 100) e `AdmanMetricDiffService` (Fase 101) — mesmo padrão já aplicado em `DesempenhoScoreService` (Fase 102)
**Confiança:** HIGH (código-fonte lido diretamente nos 3 services da fundação + no controller atual; nenhuma lib externa nova; nenhuma chamada de rede necessária pra esta pesquisa)

## Summary

A Fase 103 troca a montagem manual de período (`now()`/`subMonth()`/`startOfMonth()`) dentro de `renderCarteiraProfissional()` e `renderCarteirasConsolidadas()` pelo `MetricPeriodResolver` (Fase 100), e troca o cálculo manual de variação de margem pelo `AdmanMetricDiffService::compute()` (Fase 101) — o MESMO par de serviços que a Fase 102 já cravou dentro do `DesempenhoScoreService`. O código de referência (`resolvePeriodo()`/`computeOficial()`/`computeVarMargem()` em `DesempenhoScoreService.php:259-309,942-964`) é o precedente direto a espelhar; a Fase 103 não inventa arquitetura nova, aplica a receita já provada.

Duas descobertas mudam o formato da fase e precisam estar no plano:

1. **`renderCarteiraProfissional()` hoje já é "quase resolver"** — usa mês-fechado com o mês calendário anterior completo (`subMonth()->endOfMonth()`), enquanto o resolver, em `closed_period`/`last_closed_month`, usa janela-de-mesmo-tamanho (N dias imediatamente anteriores). Migrar MUDA o número de baseline exibido pra qualquer mês fechado selecionado — mesma mudança de comportamento que já aconteceu na Fase 102 (`DesempenhoScoreService` fez bump de cache v4→v5 por causa disso). A carteira não tem cache, então não precisa de bump, mas os testes que fixam datas relativas a "mês fechado" vão quebrar em valor, não em estrutura.

2. **`renderCarteirasConsolidadas()` NÃO é "quase resolver" — é um modelo de período completamente diferente.** Usa `?period=1/7/30/180` (janela rolante em DIAS, sem baseline, sem variação nenhuma calculada) em vez de mês calendário. Hoje o card mostra `avg_margin` (média simples do período, não uma variação) — não existe `margem_variacao_pct` nessa tela. Migrar esta função pro resolver não é troca de fonte, é ADICIONAR o conceito de baseline/variação que ela nunca teve. Isso precisa ser reconhecido explicitamente no plano — não é refactor 1:1 como a individual.

Além disso, encontrei uma armadilha de nomenclatura que a Fase 102 já documentou para si mesma (nota ADM-05) e que a Fase 103 herda: o campo existente `margem_variacao_pct` da carteira (calculado hoje como crescimento % do valor R$ da margem) NÃO é a mesma métrica que `DesempenhoScoreService::computeVarMargem()` passou a usar na Fase 102 (`percentageMargin.diff`, a variação da margem-como-%-da-receita). O sucessor correto do campo existente da carteira é `AdmanMetricDiffService::compute()['metrics']['contribution_margin_value']['diff_pct']` (mapeado de `profitMargin.diff`), não `contribution_margin_pct` — ver Pitfall 1 abaixo. Confundir os dois muda silenciosamente o que a tela de carteira reporta como "variação de margem", em uma tela que já foi auditada 3× por divergência de número (Tomelin, Gabriela, LOJASINVAL/AVF2K — ver `.planning/debug/resolved/`).

**Recomendação primária:** Espelhar literalmente o padrão de `DesempenhoScoreService` (Fase 102) nas duas funções do `PortfolioController`, usando `contribution_margin_value.diff_pct` (não `contribution_margin_pct`) para preservar a semântica do campo `margem_variacao_pct` já existente; tratar `renderCarteirasConsolidadas()` como escopo maior (adicionar baseline, não só trocar); e não construir nenhum seletor visual novo — 103 é backend/payload, 104 é UI (ver Pitfall 3).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Resolução de período (janela atual/baseline) | API/Backend | — | `MetricPeriodResolver` é service puro, sem I/O; chamado dentro do controller |
| Variação de margem/faturamento | API/Backend | — | `AdmanMetricDiffService` encapsula leitura Adman + fallback calculado; controller só agrega |
| Elegibilidade financeira por vínculo | API/Backend | — | `CarteiraContextService` (Fase 88/89/90) — INTOCADO nesta fase |
| Exibição de período/variação | Frontend (Inertia/React) | — | `AdminCarteira.jsx`/`Carteiras.jsx` consomem o payload; Fase 103 só garante que `periodo`/variação cheguem corretos — nenhuma mudança visual nesta fase (ver Pitfall 3) |

## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|---------------------|
| CAR-01 | `renderCarteiraProfissional`/`renderCarteirasConsolidadas` resolvem período via `MetricPeriodResolver`; mês fechado não usa `now()` | Anatomia mapeada linha-a-linha nas duas funções (ver Architecture Patterns); padrão de `resolvePeriodo()` da Fase 102 pronto pra copiar |
| CAR-02 | Soma financeira usa janelas do resolver; variação de margem vem do diff Adman quando disponível; elegibilidade v17.0 preservada | `AdmanMetricDiffService::compute(Company, periodo)` já existe e é fail-open; `CarteiraContextService` já filtra por `financial_metrics_eligible` nas duas funções — não mexer nisso. Achado crítico: usar `contribution_margin_value`, não `contribution_margin_pct` (Pitfall 1) |
| CAR-03 | Todos os cards/tabelas/séries leem `period.current_start/end`/`baseline_start/end` — coerência entre blocos | Hoje as duas funções JÁ leem as mesmas variáveis locais (`$dateFrom`/`$dateTo`/etc.) pra todos os blocos dentro de si — a coerência interna já existe; o trabalho é trocar a FONTE dessas variáveis pelo resolver, não introduzir coerência nova |

## Standard Stack

Nenhum pacote novo. A fase reusa 100% de infraestrutura já construída nas Fases 88/100/101/102:

| Serviço | Já existe em | Papel na Fase 103 |
|---------|--------------|--------------------|
| `MetricPeriodResolver` | `app/Services/Metrics/MetricPeriodResolver.php` (Fase 100) | Substitui os blocos `now()`/`startOfMonth()`/`subMonth()` inline |
| `AdmanMetricDiffService` | `app/Services/Metrics/AdmanMetricDiffService.php` (Fase 101) | Substitui o cálculo manual de `margemVarPct` (linhas 413-416 e a lógica de dias-comuns 355-407 de `renderCarteiraProfissional`) |
| `CarteiraContextService` | `app/Services/Portfolio/CarteiraContextService.php` (Fase 88) | INTOCADO — já injetado no controller (`$this->carteiraContext`), já usado nas duas funções desde a Fase 89/90 |

**Instalação:** nenhuma — `composer install`/`npm install` não mudam nesta fase.

## Package Legitimacy Audit

Não aplicável — esta fase não instala pacotes externos novos (PHP ou JS).

## Architecture Patterns

### 1. Anatomia do período HOJE em `renderCarteiraProfissional()` (pós-Fase 89/90)

`app/Http/Controllers/PortfolioController.php:137-176`:

```php
// Mês em curso (comportamento IDÊNTICO ao resolver current_month):
if ($ehMesEmCurso) {
    $inicioMes   = $mesSelecionado->copy();
    $fimMes      = $hoje->copy()->endOfDay();
    $inicioAnter = $mesSelecionado->copy()->subMonth();
    $fimAnter    = $inicioAnter->copy()->setDay(min($hoje->day, $inicioAnter->daysInMonth))->endOfDay();
} else {
    // Mês fechado — DIFERENTE do resolver:
    $inicioMes   = $mesSelecionado->copy();
    $fimMes      = $mesSelecionado->copy()->endOfMonth();
    $inicioAnter = $mesSelecionado->copy()->subMonth();          // mês calendário anterior INTEIRO
    $fimAnter    = $inicioAnter->copy()->endOfMonth();           // não é janela-de-mesmo-tamanho
}
```

Comparação com `MetricPeriodResolver`:

| Modo | Código atual da carteira | `MetricPeriodResolver` equivalente | Batem? |
|------|---------------------------|--------------------------------------|--------|
| Mês em curso (`?mes=` ausente ou = mês corrente) | dia 1..hoje vs mesmo intervalo do mês anterior, alinhado por dia | `current_month` (`resolveCurrentMonth`) | **SIM** — mesma regra, mesmo clamp por `min(dia, daysInMonth)` |
| Mês fechado (`?mes=YYYY-MM` no passado) | mês calendário completo vs **mês calendário anterior completo** | `YYYY-MM` (`resolveSpecificMonth`) → baseline = **janela-de-mesmo-tamanho** (N dias imediatamente anteriores) | **NÃO** — baseline diferente; migrar muda o número de comparação exibido |

Isso é exatamente a mesma mudança que a Fase 102 already fez para o bônus oficial (ver `DesempenhoScoreService.php:218-230`, comentário do bump de cache v4→v5). A carteira não tem cache pra bumpar, mas os TESTES que fixam mês fechado vão precisar de novos valores esperados.

### 2. `renderCarteirasConsolidadas()` — modelo de período TOTALMENTE diferente

`app/Http/Controllers/PortfolioController.php:595-604`:

```php
$period = $request->get('period', '30');
$days   = match ($period) { '1' => 1, '7' => 7, '180' => 180, default => 30 };
$since  = now()->subDays($days);
$dateFrom = $since->toDateString();
$dateTo   = now()->toDateString();
```

Não há `$dateFromPrev`/`$dateToPrev` nesta função — **nenhuma baseline é calculada**. O card final (linha 807-822) expõe `avg_margin` (média simples de `contribution_margin_pct` no período, via `AVG()` SQL) e `total_revenue`/`total_ad_spend`/`avg_tacos` — todos SEM comparação. Confirmado também pelo front (`resources/js/Pages/Portfolio/Carteiras.jsx:177`): só renderiza `avg_margin`, nunca um chip de variação.

Isso não é um bug a corrigir — é a spec de hoje ("carteira consolidada, últimos N dias"). Migrar para o resolver, que só entende `current_month`/`last_closed_month`/`YYYY-MM`/`custom`, significa: (a) abandonar o seletor `1/7/30/180` em favor de um `period_key` do resolver, e (b) **introduzir** baseline/variação de margem que a tela nunca teve. O plano canônico confirma a intenção (§1074-1084 "Fase 3 - Carteiras consolidadas": *"Usar o mesmo filtro de período da carteira individual"* + *"Usar competência correta quando a visualização for de bônus"*) — ou seja, a extensão é intencional, não um efeito colateral a evitar.

### 3. `renderPortfolio()` — terceiro ponto de período inline, FORA do texto do REQUIREMENTS-v18

`app/Http/Controllers/PortfolioController.php:843-875` (usada pela auto-visualização `/portfolio`, rota `own()`) tem o MESMO padrão inline de `renderCarteiraProfissional()` (mês em curso = janela alinhada por dia; mês fechado = mês calendário completo vs anterior completo). O plano canônico (`plano-carteira-desempenho-multi-servico.md:757-763`, seção "2. Ajustar `PortfolioController`") LISTA as três funções (`renderCarteiraProfissional`, `renderCarteirasConsolidadas`, `renderPortfolio`) como afetadas — mas `REQUIREMENTS-v18.md` (CAR-01) e o `ROADMAP.md` da Fase 103 citam só as DUAS primeiras. Ver Open Questions.

### 4. Padrão a espelhar — `DesempenhoScoreService` (Fase 102)

```php
// app/Services/DesempenhoScoreService.php:280-287
private function resolvePeriodo(Carbon $mes): array
{
    $mesCorrente = now()->startOfMonth();
    return $mes->copy()->startOfMonth()->equalTo($mesCorrente)
        ? $this->periodResolver->resolve(['period_key' => 'current_month'])
        : $this->periodResolver->resolve(['period_key' => $mes->format('Y-m')]);
}

// app/Services/DesempenhoScoreService.php:942-964 (uso do diff service)
private function computeVarMargem(User $user, Carbon $mes, EloquentCollection $companies, array $periodo): ?float
{
    $vars = collect();
    foreach ($companies as $company) {
        $resultado = $this->admanDiffService->compute($company, $periodo);
        $diffPct   = $resultado['metrics']['contribution_margin_pct']['diff_pct'] ?? null; // ⚠ ver Pitfall 1
        if ($diffPct !== null) { $vars->push($diffPct); }
    }
    return $vars->isEmpty() ? null : round($vars->avg(), 2);
}
```

Este é o esqueleto a replicar em `PortfolioController` (adaptado — o controller já tem `$periodo` resolvido uma vez no topo da função e reusado por TODOS os blocos, então não precisa de um `resolvePeriodo()` próprio: basta trocar as 30 linhas de `if($ehMesEmCurso){...}else{...}` por UMA chamada a `$this->periodResolver->resolve([...])`).

## Don't Hand-Roll

| Problema | Não construir | Usar em vez disso | Por quê |
|----------|----------------|---------------------|---------|
| Cálculo de janela atual/baseline por modo | `if ($ehMesEmCurso) {...} else {...}` inline (30 linhas atuais) | `MetricPeriodResolver::resolve(['period_key' => ...])` | Único ponto de resolução do núcleo (contrato PER-06); já testado nos 4 modos em `tests/Unit/MetricPeriodResolverTest.php` |
| Variação de margem (dias-comuns, margem_dias, fallback) | Recalcular guards manualmente (o código atual já faz isso em 355-407 — ~50 linhas) | `AdmanMetricDiffService::compute($company, $periodo)` | Os MESMOS guards já estão dentro do service (cópia fiel documentada no próprio docblock do `AdmanMetricDiffService`, "duplicação TEMPORÁRIA e INTENCIONAL" — a Fase 102 já removeu a cópia de dentro do `DesempenhoScoreService`; a Fase 103 remove a cópia de dentro do `PortfolioController`) |
| Elegibilidade financeira / vínculos por serviço | Reimplementar join em `company_users` | `CarteiraContextService::forUser()`/`contadores()` | Já injetado e usado nas duas funções desde Fase 89/90 — **não mexer**, é fundação de outra fase e está fora do escopo CAR-01..03 |

**Insight-chave:** as três dependências desta fase (resolver, diff service, contexto) já existem, já são testadas isoladamente, e já têm um consumidor de referência funcionando em produção (`DesempenhoScoreService`, Fase 102). O trabalho de 103 é essencialmente "aplicar o mesmo transplante pela segunda vez", com dois desvios que o plano PRECISA prever: baseline muda de valor no mês fechado (Pitfall 2) e a consolidada ganha um conceito que não tinha (Pitfall 4).

## Common Pitfalls

### Pitfall 1: `contribution_margin_value` vs `contribution_margin_pct` — NÃO copiar cegamente a escolha da Fase 102
**O que dá errado:** Copiar literalmente `computeVarMargem()` do `DesempenhoScoreService` (que lê `contribution_margin_pct` → `percentageMargin.diff`) faria a carteira passar a exibir uma métrica DIFERENTE da que exibe hoje, sem nenhum erro visível — só um número diferente.
**Por que acontece:** `AdmanMetricDiffService` expõe DOIS campos de margem com nomes parecidos: `contribution_margin_value` (mapeado de `profitMargin.diff` — variação % do valor R$ da margem, calculado como `(atual-anterior)/anterior*100`) e `contribution_margin_pct` (mapeado de `percentageMargin.diff` — variação da margem-como-%-da-receita, métrica diferente). A fórmula atual da carteira (`PortfolioController.php:414-416`, `($margemAtual - $margemAnterior) / $margemAnterior * 100` sobre SOMA em R$) é matematicamente idêntica ao que `contribution_margin_value` calcula como fallback — ela é a sucessora correta.
**Como evitar:** usar `$resultado['metrics']['contribution_margin_value']['diff_pct']` para popular o campo `margem_variacao_pct` existente. Se o plano decidir também expor a variação de `percentageMargin` como campo NOVO (o `plano-carteira-desempenho-multi-servico.md:1158-1159` sugere que os dois têm lugar: "*Variação de margem percentual usa `percentageMargin.diff`... Variação de margem em R$ usa `profitMargin.diff`*"), isso é uma ADIÇÃO de campo, não uma substituição — precisa virar decisão explícita no discuss/plan, não uma escolha silenciosa de qual chave ler.
**Sinal de alerta:** testes de regressão da Fase 89/90 (`CarteiraFinanceiroElegibilidadeTest`, etc.) que fixam `margem_variacao_pct` esperado vão falhar com um número que "parece plausível" mas está errado — a métrica mudou de sentido, não só de fonte.

### Pitfall 2: baseline muda de valor no modo mês-fechado (mudança de comportamento esperada, mas quebra números fixados em teste)
**O que dá errado:** qualquer teste que grava `AdmanMetric` num mês fechado específico e verifica `margem_variacao_pct`/`total_margem_anterior` vai ter valor diferente pós-migração, porque a baseline deixa de ser "mês calendário anterior completo" e passa a ser "N dias imediatamente anteriores ao início do mês selecionado" (`baselineJanelaMesmoTamanho()`).
**Como evitar:** tratar como mudança de comportamento intencional (documentada em CAR-01/REQUIREMENTS-v18 e no `plano-carteira-desempenho-multi-servico.md:1156-1157`), recalcular os valores esperados dos testes afetados, e — se possível — adicionar um teste-âncora explícito comparando os dois modos (ex.: "maio/2026 fechado → baseline 31/03..30/04", espelhando o caso do `MetricPeriodResolverTest`).
**Não afeta:** o modo mês-em-curso (já bate 100% com `current_month` do resolver — ver Architecture Patterns §1).

### Pitfall 3: fronteira 103 vs 104 — não construir o seletor visual, só o payload/parâmetro
**Evidência:** o Goal da Fase 103 no ROADMAP diz "...+ filtro de período..." mas a Fase 104 é literalmente "UI de período" com Success Criteria "toggle/segmento... Em curso / Bônus atual / Mês fechado". A carteira individual JÁ TEM um seletor de mês funcionando hoje (`?mes=YYYY-MM`, dropdown `meses_disponiveis` em `AdminCarteira.jsx:199-220`) — construído antes da v18.0, fora do escopo desta pesquisa canônica.
**Recomendação:** Fase 103 deve (a) fazer o controller aceitar o parâmetro de período existente (`?mes=`) e traduzi-lo para `MetricPeriodResolver::resolve(['period_key' => ...])` — o mapeamento é direto, porque `?mes=YYYY-MM` já é literalmente o formato `period_key` do modo `closed_period`/`YYYY-MM`; (b) expor `periodo` (shape do resolver) no payload Inertia das duas telas; (c) **não** adicionar o toggle "Em curso/Bônus atual/Mês fechado" nem trocar o dropdown por um segmented control — isso é Fase 104. O modo `last_closed_month` (bônus oficial) provavelmente só ganha um ponto de entrada na UI na Fase 104; a Fase 103 só precisa garantir que o CONTROLLER sabe resolvê-lo quando chamado (ex.: um novo valor de `?mes=bonus` ou similar) — decisão de contrato de parâmetro é do plano, não desta pesquisa.
**Consolidada:** hoje não tem NENHUM seletor de mês (só dias rolantes) — introduzir período mensal aqui é uma mudança de UI cabível em 103 (payload) + 104 (widget), mas o CAMPO `period` (`1/7/30/180`) que o front já lê (`Carteiras.jsx:33`) provavelmente precisa de uma ponte de compatibilidade ou substituição — sinalizar para o plano decidir se `period` morre nesta fase ou só ganha um vizinho `periodo` novo.

### Pitfall 4: `renderCarteirasConsolidadas()` ganha um conceito que nunca teve — não é refactor 1:1
Ver Architecture Patterns §2. O plano precisa alocar esforço explícito para "adicionar cálculo de baseline/variação de margem aos cards consolidados", não apenas "trocar fonte de período" — são conjuntos de trabalho diferentes. Se isso for maior que o wave orçado, considerar quebrar em plans separados (payload básico do resolver primeiro, variação por card depois).

### Pitfall 5: `renderPortfolio()` citado no plano canônico, ausente do REQUIREMENTS — decisão pendente
Ver Architecture Patterns §3 e Open Questions.

### Pitfall 6: custo de HTTP N+1 ao trocar cálculo de margem por `AdmanMetricDiffService::compute()` — sem cache no controller
`AdmanMetricDiffService::compute()` é POR EMPRESA (até 2 chamadas HTTP à Adman por empresa, cache 24h com chave `company+janela+dia`). O código atual de `renderCarteiraProfissional`/`renderCarteirasConsolidadas` usa `getCachedGrossBillingsMany()`/`getCachedAccountMetricsMany()` — chamadas EM LOTE (1 request pra N empresas). Trocar a variação de margem por `compute()` em loop (como `DesempenhoScoreService::computeVarMargem()` faz) reintroduz um padrão N+1 que o `DesempenhoScoreService` mitiga com `computeCached()` (`Cache::remember` de 10min a 7 dias) — mas `PortfolioController` **não tem cache nenhum** (confirmado — nenhuma ocorrência de `Cache::` no arquivo). Em cold-cache, uma carteira consolidada com dezenas de profissionais × múltiplas empresas cada pode disparar dezenas de chamadas HTTP síncronas na mesma request.
**Como mitigar:** não é escopo de CAR-01..03 resolver caching (não mencionado nos critérios), mas o plano deve pelo menos reconhecer o risco e considerar (a) aceitar por ora — o cache de 24h da Adman dentro do `AdmanMetricDiffService` amortiza requests subsequentes, só a PRIMEIRA carga do dia é lenta; ou (b) marcar como débito técnico pra fase futura.

### Pitfall 7: testes sem `Http::fake` continuam passando — mas silenciosamente caem no fallback
Os 3 testes de regressão existentes (`CarteiraFinanceiroElegibilidadeTest`, `CarteiraIndividualContextoTest`, `CarteirasConsolidadasContextoTest`) chamam as rotas sem `Http::fake` nem `setTestNow`. Como `AdmanService`/`AdmanMetricDiffService` são fail-open (try/catch, nunca lançam), os testes continuam passando mesmo sem mock — mas SEMPRE caem no `calculated_fallback` (nunca exercitam `diff_source='adman_diff'`). A Fase 102 já resolveu isso adicionando `tests/Feature/V18/DesempenhoPeriodoOficialTest.php` com `Http::fake([...'percentageMargin' => ['value'=>..., 'diff'=>...]...])` — o mesmo padrão deve ser replicado para a Fase 103 caso o plano queira um teste que prove que `diff_source='adman_diff'` é usado quando disponível.

### Pitfall 8: sessão paralela — não tocar em NPS/Shopee-dashboard/Polos
Confirmado pelo `git status`: há trabalho solto de NPS (Fase 78/79 research/verification), auditorias de margem/performance em `.planning/debug/resolved/`, e arquivos soltos de Shopee/dashboard fora do escopo desta fase. `PortfolioController.php` é compartilhado (tem `storeGoal`/`updateGoal`/`destroyGoal` no fim do arquivo, fora do escopo de CAR-01..03) — editar só as duas funções-alvo, não tocar no resto do arquivo.

## Code Examples

### Tradução do `?mes=` existente para `period_key` do resolver

```php
// Hoje (PortfolioController.php:149-157) — sobrevive, só troca o QUE FAZ com $mesSelecionado:
$mesQuery = $request->query('mes');
if ($mesQuery && preg_match('/^\d{4}-\d{2}$/', $mesQuery)) {
    $mesSelecionado = Carbon::createFromFormat('Y-m-d', $mesQuery . '-01')->startOfMonth();
} else {
    $mesSelecionado = now()->startOfMonth();
}
$ehMesEmCurso = $mesSelecionado->equalTo(now()->startOfMonth());

// Fase 103 — substitui os blocos if/else de datas (linhas 159-176) por:
$periodo = $ehMesEmCurso
    ? $this->periodResolver->resolve(['period_key' => 'current_month'])
    : $this->periodResolver->resolve(['period_key' => $mesSelecionado->format('Y-m')]);

$dateFrom     = $periodo['current_start'];
$dateTo       = $periodo['current_end'];
$dateFromPrev = $periodo['baseline_start'];
$dateToPrev   = $periodo['baseline_end'];
```
Fonte: padrão espelhado de `DesempenhoScoreService::resolvePeriodo()` (`app/Services/DesempenhoScoreService.php:280-287`).

### Variação de margem via diff service (preservando a semântica do campo existente)

```php
// Substitui o bloco de dias-comuns + fallback manual (PortfolioController.php:355-416)
$resultado = $this->admanDiffService->compute($company, $periodo);
$margemVarPct = $resultado['metrics']['contribution_margin_value']['diff_pct'] ?? null; // NÃO contribution_margin_pct — ver Pitfall 1
```
Fonte: `app/Services/Metrics/AdmanMetricDiffService.php:82-148` (shape de retorno) + `app/Services/DesempenhoScoreService.php:942-964` (padrão de consumo, com a chave TROCADA conforme Pitfall 1).

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|-------|-------------------|
| A1 | `contribution_margin_value.diff_pct` é o sucessor semanticamente correto do `margem_variacao_pct` atual (em vez de `contribution_margin_pct`) | Pitfall 1 / Code Examples | Análise feita comparando a fórmula matemática atual (`(atual-anterior)/anterior*100` sobre R$) com o `fallbackSomaSimples()` do diff service — mesma fórmula, mesmo campo-fonte (`contribution_margin`/`profitMargin`). Confiança ALTA (derivação matemática direta do código-fonte), mas não é uma decisão travada em CONTEXT.md — o plano deve confirmar |
| A2 | `renderPortfolio()` está FORA do escopo de CAR-01..03 (REQUIREMENTS-v18 só cita as outras duas funções, apesar do plano canônico listar as três) | Pitfall 5 / Open Questions | Se o usuário/planner decidir que `renderPortfolio()` também deveria migrar nesta fase, o escopo de 103 cresce (mais um consumidor com padrão inline igual, mesmo transplante) |
| A3 | O `?mes=YYYY-MM` existente na carteira individual pode ser traduzido 1:1 para `period_key` do resolver sem quebrar a UI atual (dropdown `meses_disponiveis`) | Code Examples / Pitfall 3 | Se o formato interno mudar, o dropdown existente (Fase pré-v18.0) para de funcionar até a Fase 104 ajustar o front — checar se isso é aceitável como estado intermediário entre 103 e 104 |

## Open Questions

1. **`renderPortfolio()` entra no escopo da Fase 103?**
   - O que se sabe: o plano canônico (`plano-carteira-desempenho-multi-servico.md:757-763`) lista as 3 funções afetadas; `REQUIREMENTS-v18.md` (CAR-01) e o `ROADMAP.md` da Fase 103 citam só `renderCarteiraProfissional`/`renderCarteirasConsolidadas`.
   - O que não está claro: se a omissão no ROADMAP/REQUIREMENTS foi intencional (talvez porque `renderPortfolio` é rota legada de auto-visualização, com menos uso) ou um esquecimento ao especializar o plano canônico em requirements formais.
   - Recomendação: o planner deve tratar isso como decisão explícita — ou confirma que `renderPortfolio()` fica de fora (e documenta o porquê, já que segue com período manual inconsistente com o resto do núcleo pós-103), ou adiciona como 4º alvo do plano.

2. **A consolidada ganha variação de margem NESTA fase, ou só o payload de período (sem variação) por ora?**
   - O que se sabe: CAR-02/CAR-03 falam em "variação de margem" e "coerência de janela entre todos os blocos" — os dois SC's implicam baseline em todas as telas, inclusive consolidada. O plano canônico confirma a intenção (§1074-1084).
   - O que não está claro: se o escopo/orçamento de waves da Fase 103 comporta adicionar uma capacidade nova (variação) na consolidada no mesmo fôlego que migra o período individual, ou se vale quebrar em plans/waves separados.
   - Recomendação: planejar como 2 fatias — (1) período via resolver nas duas funções + variação na individual (troca 1:1, menor risco), (2) variação nova na consolidada (adição de capacidade, maior risco/esforço).

3. **O campo `percentageMargin.diff` (Margem %) precisa de um campo NOVO na carteira, ou fica só no Desempenho?**
   - O que se sabe: plano canônico §1158 menciona ambos (`percentageMargin.diff` E `profitMargin.diff`) na seção "Carteira" dos critérios de aceite.
   - O que não está claro: se isso significa "a carteira deveria mostrar as DUAS variações" ou é uma frase guarda-chuva que descreve a arquitetura geral do diff service (compartilhada com Desempenho), sem implicar UI nova na carteira.
   - Recomendação: por padrão, NÃO adicionar campo novo (menor escopo, preserva CAR-01..03 como troca de fonte) — só adicionar se o discuss-phase confirmar que é pedido explícito.

## Environment Availability

Não aplicável — fase não introduz dependência externa nova. `AdmanService` (chamado indiretamente via `AdmanMetricDiffService`) já está em uso em produção pelas mesmas funções hoje.

## Validation Architecture

### Test Framework
| Propriedade | Valor |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), config em `phpunit.xml` |
| Config file | `phpunit.xml` (raiz do projeto) |
| Comando rápido | `C:\xampp\php\php.exe artisan test --filter=CarteiraFinanceiroElegibilidadeTest` (ou o nome do teste alvo) |
| Suite completa | `C:\xampp\php\php.exe artisan test` |

### Requisitos da Fase → Testes
| REQ ID | Comportamento | Tipo | Comando automatizado | Arquivo existe? |
|--------|----------------|------|------------------------|------------------|
| CAR-01 | Mês fechado não usa `now()`/mês em curso | Feature | `php artisan test --filter=CarteiraIndividualContextoTest` (adaptar valores esperados) | ✅ existe, precisa de ajuste de valores (Pitfall 2) |
| CAR-02 | Variação de margem vem do diff Adman quando disponível; elegibilidade preservada | Feature | Novo teste com `Http::fake` no padrão de `tests/Feature/V18/DesempenhoPeriodoOficialTest.php` | ❌ Wave 0 — criar `tests/Feature/V18/CarteiraDiffAdmanTest.php` (ou nome equivalente) |
| CAR-03 | Coerência de janela entre todos os blocos da mesma tela | Feature | Assert nos campos `periodo.current_start/end`/`baseline_start/end` do payload Inertia | ❌ Wave 0 — pode ser coberto no mesmo teste de CAR-02 |

### Sampling Rate
- **Por commit de task:** `php artisan test --filter=Carteira` (roda os 3 arquivos V16 + novos)
- **Por merge de wave:** `php artisan test` completo
- **Gate de fase:** suite completa verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/V18/CarteiraDiffAdmanTest.php` (ou nome equivalente) — cobre CAR-02/CAR-03 com `Http::fake` provando `diff_source='adman_diff'` quando a Adman responde, e fallback quando não
- [ ] Recalcular valores esperados de baseline em `CarteiraIndividualContextoTest`/`CarteiraFinanceiroElegibilidadeTest` para o novo modo mês-fechado (janela-de-mesmo-tamanho)
- Framework: nenhum install novo — PHPUnit já configurado

## Security Domain

### Categorias ASVS aplicáveis

| Categoria ASVS | Aplica | Controle padrão |
|---------------|---------|-----------------|
| V4 Access Control | Sim | Middleware `role:admin`/checagem de líder já existente em `show()` (`PortfolioController.php:99-119`) — INTOCADO nesta fase |
| V5 Input Validation | Sim | Parâmetro `?mes=` já validado por whitelist regex (`/^\d{4}-\d{2}$/`); parâmetro `?contexto=` já validado por `match` explícito (`contextoFiltro()`, linhas 78-85) — QUALQUER parâmetro novo de período (ex.: modo bônus) deve seguir o MESMO padrão de whitelist, nunca repassar string crua ao `MetricPeriodResolver` (que já lança `InvalidArgumentException` para `period_key` desconhecido — mas isso não deve virar 500 pro usuário; o controller deve validar antes) |

### Padrões de ameaça conhecidos no stack

| Padrão | STRIDE | Mitigação padrão |
|--------|--------|---------------------|
| `period_key` malformado/injeção via query string | Tampering | `MetricPeriodResolver` já valida e lança `InvalidArgumentException` — controller deve capturar/whitelist ANTES de repassar, mesmo padrão de `contextoFiltro()` |
| Enumeração de carteira de outro profissional via `?mes=`/período em rota já protegida | Information Disclosure | Sem mudança — a autorização em `show()`/`own()` já é resolvida ANTES de chamar `renderCarteiraProfissional`/`renderCarteirasConsolidadas`; nenhum novo vetor introduzido por esta fase |

## Sources

### Primária (confiança ALTA — leitura direta de código)
- `app/Services/Metrics/MetricPeriodResolver.php` (Fase 100, completo)
- `app/Services/Metrics/AdmanMetricDiffService.php` (Fase 101, completo)
- `app/Services/DesempenhoScoreService.php` (Fase 102, seções 190-450 e 920-965)
- `app/Services/Portfolio/CarteiraContextService.php` (Fase 88, completo)
- `app/Http/Controllers/PortfolioController.php` (linhas 1-260, 562-841 — as duas funções-alvo completas)
- `resources/js/Pages/Portfolio/AdminCarteira.jsx` (docblock de contrato de props, linhas 1-55)
- `resources/js/Pages/Portfolio/Carteiras.jsx` (grep de campos consumidos)
- `tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php`, `CarteiraIndividualContextoTest.php`, `CarteirasConsolidadasContextoTest.php` (grep de padrão de request, ausência de `Http::fake`/`setTestNow`)
- `tests/Feature/V18/DesempenhoPeriodoOficialTest.php` (padrão de `Http::fake` a replicar)
- `.planning/ROADMAP.md` (Fases 100-104, seção v18.0 completa)
- `.planning/REQUIREMENTS-v18.md` (completo)
- `plano-carteira-desempenho-multi-servico.md` (linhas 438-479, 749-800, 1055-1100, 1150-1179)

### Secundária
- Nenhuma — pesquisa 100% baseada em código-fonte do próprio repositório; nenhuma consulta externa (Context7/WebSearch) foi necessária, já que a fundação (Fases 100-102) já documenta e implementa tudo que 103 precisa consumir.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhum pacote novo, reuso de serviços já testados
- Architecture: HIGH — anatomia das duas funções mapeada linha-a-linha do código atual
- Pitfalls: HIGH — Pitfall 1 (contribution_margin_value vs _pct) derivado de comparação matemática direta entre a fórmula atual e o fallback do diff service; Pitfall 2/4 confirmados por leitura completa das duas funções

**Data da pesquisa:** 2026-07-20
**Válida até:** 2026-08-19 (30 dias — domínio estável, sem dependência de API externa mutável)
