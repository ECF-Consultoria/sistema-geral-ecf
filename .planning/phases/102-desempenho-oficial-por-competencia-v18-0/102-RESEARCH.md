# Fase 102: Desempenho oficial por competência (v18.0) — Research

**Pesquisado em:** 2026-07-20
**Domínio:** Motor de cálculo de bônus (`DesempenhoScoreService`) — integração com `MetricPeriodResolver` (Fase 100) e `AdmanMetricDiffService` (Fase 101)
**Confiança:** MÉDIA — a arquitetura de integração é clara (HIGH), mas há uma decisão de produto bloqueante (reprocessar ou não o snapshot de junho/2026) e um risco de regressão de teste não-óbvio (ALTO, ver Pitfall 1) que precisam de decisão humana antes do plano ser executado com segurança.

## Summary

Esta é a fase que muda o número do bônus pago. Hoje o `DesempenhoScoreService::computeVarFaturamento()`/`computeVarMargem()` calculam a janela atual e a baseline **inline**, usando `now()`, `startOfMonth()`/`subMonth()`, com uma régua **calendário-vs-calendário** (mês completo vs mês completo anterior) para meses fechados, e uma régua **dia-1-até-hoje vs mesmo-intervalo-mês-anterior** para o mês em curso. Nenhuma das duas é o `MetricPeriodResolver` da Fase 100 — e a régua de mês fechado usa uma baseline de tamanho **diferente** (mês anterior pode ter 28-31 dias), o que o `plano-carteira-desempenho-multi-servico.md` classifica explicitamente como bug a corrigir (§158-164).

A Fase 102 precisa: (1) trocar as janelas inline por `MetricPeriodResolver::resolve()`; (2) trocar `computeVarMargem()` por uma chamada a `AdmanMetricDiffService::compute($company, $periodo)` por empresa, usando `metrics.contribution_margin_pct.diff_pct`; (3) adicionar `periodo`/`bonus` ao shape de retorno; (4) decidir explicitamente como `compute()` recebe o "modo" (operacional vs oficial de bônus); (5) bumpar a chave de cache v4→v5 incluindo o `period_key`; (6) decidir com o usuário/diretoria se o snapshot de **junho/2026** — já consolidado por `desempenho:consolidar-mes` no dia 01/07 sob a régua ANTIGA — deve ser **reprocessado** com a régua nova antes de virar o número pago em julho.

Duas descobertas críticas não estavam no escopo do prompt e mudam o risco da fase:

1. **A baseline de "N dias imediatamente anteriores" quebra o guard de dias-comuns de fixtures esparsas** (mock com 1 linha no dia 15 do mês) porque o offset do dia 15 muda quando a janela baseline deixa de começar no dia 1 do mês anterior (ver Pitfall 1). Isso significa que a âncora Carlos (hoje `nota_final=4.08/basico`) **vai precisar de fixture nova e número novo**, não apenas de ajuste de expectativa — e o mesmo se aplica a QUALQUER teste que usa `mockAdmanRevenueMargem`/padrão equivalente de 1-linha-no-dia-15 em `tests/Feature/Phase74/`, `tests/Feature/V16/`.
2. **O snapshot de junho/2026 já existe em produção** (cron `desempenho:consolidar-mes` roda todo dia 1 às 14:00 e, por padrão, consolida "o mês anterior ao hoje" — em 01/07/2026 isso já gravou junho sob a régua ANTIGA, sem diff da Adman, com baseline calendário). Como hoje é 2026-07-20, esse snapshot já está gravado. `PerformanceController::show()`/`index()` **preferem o snapshot** para meses fechados — então, sem uma decisão explícita de reprocessar, a tela "Bônus atual" em julho pode continuar mostrando o número de junho calculado pela régua ANTIGA mesmo depois do deploy da Fase 102, contrariando o objetivo central da fase.

**Recomendação principal:** tratar "modo bônus" como uma chamada explícita que resolve `last_closed_month` via `MetricPeriodResolver` e ignora/reprocessa o snapshot pré-existente de junho (mediante decisão do usuário — ver `## Decisões de produto a confirmar`), em vez de tentar preservar o valor numérico da âncora Carlos.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Resolução de janelas (atual/baseline) | API/Backend (`MetricPeriodResolver`) | — | Service puro, já existe (Fase 100); único ponto de verdade de datas |
| Leitura do diff pronto da Adman (margem %) | API/Backend (`AdmanMetricDiffService`) | Database/Storage (`AdmanMetric`, fallback) | Já existe (Fase 101); encapsula gate por `comparison_mode` + guards |
| Orquestração do score (NPS + faturamento + margem + nota final) | API/Backend (`DesempenhoScoreService`) | — | Fase 102 é o consumidor que amarra resolver + diff service |
| Decisão operacional vs oficial de bônus | API/Backend (controller/commands que chamam o service) | — | O service não deve adivinhar; quem chama declara o `period_key` |
| Persistência do resultado mensal fechado | Database/Storage (`desempenho_score_snapshots`) | API/Backend (`ConsolidarMesDesempenho`) | Grava `breakdown_json` — precisa da régua nova para meses processados a partir do deploy |
| Cache do resultado (Redis via `Cache::remember`) | API/Backend (`computeCached`) | — | TTL adaptativo por `is_closed`; chave precisa de bump + `period_key` |
| Exibição do score/bônus | Frontend/SSR (`Performance/{Index,Show,Dashboard}.jsx`) | — | Fora de escopo desta fase (Fase 104 = UI de período); só recebe o payload novo |

## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| BON-01 | `DesempenhoScoreService` consome `MetricPeriodResolver` — var. faturamento/margem usa `period.current_*`/`period.baseline_*`, não `now()`/`startOfMonth` inline | Mapeado exatamente onde cada janela nasce hoje (computeVarFaturamento §651-720, computeVarMargem §811-838) — ver `## Anatomia das janelas hoje` |
| BON-02 | Ranking oficial de bônus em julho/2026 usa competência junho/2026 fechada (atual 01/06..30/06 vs 02/05..31/05); score de junho exibido/pago em julho | A cadência "paga o mês fechado anterior" JÁ EXISTE via cron `ConsolidarMesDesempenho` (mês anterior ao hoje) — o que muda é a FORMA da baseline (calendário → janela-de-mesmo-tamanho) e a necessidade de reprocessar o snapshot de junho já gravado (ver `## Decisões de produto`) |
| BON-03 | `var_margem_pct` usa `percentageMargin.diff` via `AdmanMetricDiffService`; fallback calculado só quando ausente, marcado | `AdmanMetricDiffService::compute()` já implementa isso (`resolveField`, gate `comparison_mode==='previous_equal_length_window'`); Fase 102 só precisa DELEGAR (ver `## Refatoração recomendada`) |
| BON-04 | Retorno adiciona `periodo` (janelas) e `bonus` (`payment_month`,`competence_month`) aos metadados; score único preservado | Shape alvo já documentado no plano canônico §839-864 — replicado abaixo em `## Shape de retorno alvo` |
| BON-05 | Leitura operacional segue disponível, marcada operacional/parcial; régua de elegibilidade v17.0 (`financial_metrics_eligible`, `score_status`) intacta | `computeScoreStatus`/`computeUniverso` (Fase 91) não precisam mudar — só o INSUMO de `varFat`/`varMargem` muda de fonte |

## Anatomia das janelas hoje (o que o resolver substitui)

Local exato de cada nascimento de janela em `app/Services/DesempenhoScoreService.php`:

### `computeVarFaturamento()` (linhas 651-798)
- **Filtro "empresa nova"** (linha 667): `$limiteNova = $mes->copy()->subMonth()->startOfMonth();` — usa `$mes` (Carbon passado a `compute()`), não o resolver. Este filtro é ortogonal ao período de comparação (é sobre `companies.created_at`) — **não precisa mudar** só por causa do resolver, mas convém revisitar se `$mes` deixar de ser o parâmetro canônico.
- **Ramo mês em curso** (linhas 705-714): `$hoje`, `$mesCorrente`, `$ehMesEmCurso = $hoje->between(...)`; se em curso, `$inicioMes=$mesCorrente`, `$fimMes=$hoje->endOfDay()`, `$inicioAnter=$mesCorrente->subMonth()`, `$fimAnter=$inicioAnter->setDay(min($diaAtual,$inicioAnter->daysInMonth))->endOfDay()`. **Isto é matematicamente IDÊNTICO** ao `MetricPeriodResolver::resolveCurrentMonth()` (mesmo alinhamento por dia, mesmo clamp) — a migração para o resolver neste ramo é uma troca 1:1, sem mudança de número esperada.
- **Ramo mês fechado** (linhas 715-720): `$inicioMes=$mes->startOfMonth()`, `$fimMes=$mes->endOfMonth()`, `$inicioAnter=$mes->subMonth()->startOfMonth()`, `$fimAnter=$mes->subMonth()->endOfMonth()`. **Isto é calendário-vs-calendário** — DIFERENTE do resolver (`baselineJanelaMesmoTamanho`, N-dias-imediatamente-anteriores). Migrar este ramo MUDA o número (ver Pitfall 1).
- Consulta Adman com 2 SELECTs agregados por `company_id` (linhas 724-738) — sem guard de dias-comuns (só o guard de `computeVarMargem` tem isso). Fonte primária ainda é ML via `MetricsProviderFactory` quando `caseFor in ['ambos','so-ml']` (linhas 759-774) — este comportamento (ML-first) **não está coberto pelo `AdmanMetricDiffService`**, que só lê Adman.

### `computeVarMargem()` (linhas 811-978)
- Mesmo padrão de dois ramos (em-curso vs fechado) nas linhas 823-838, idêntico em estrutura ao de `computeVarFaturamento`.
- Guard `margem_dias` (linhas 845-859) + guard **dias-comuns por dia-do-mês** (`Carbon::parse($d)->day`, linhas 874-902, 921-964) — **cópia fiel** do mesmo padrão que `AdmanMetricDiffService::somasComGuards()` generalizou para offset-desde-o-início-da-janela (documentado no próprio docblock do diff service: "Este service é BAIXO nível [...] A Fase 102 [...] REMOVE a lógica duplicada de lá").

**Conclusão:** o ramo "mês em curso" migra sem mudança de número; o ramo "mês fechado" muda de régua por decisão de negócio JÁ TOMADA (`PER-03`/`PER-04`, "Baseline por mês calendário — descartado" em `REQUIREMENTS-v18.md`). A duplicação de guards em `computeVarMargem` é exatamente o que a Fase 102 deve apagar ao delegar para `AdmanMetricDiffService`.

## Onde entra o modo bônus vs operacional (decisão central de desenho)

**Estado atual:** não existe uma decisão explícita de "modo". `PerformanceController::index()`/`show()` recebem `?mes=YYYY-MM` (ou usam o mês corrente por default) e o CONTROLLER decide: `$ehMesEmCurso = $mesReferencia->equalTo($mesCorrente)`. Se em curso → `computeCached()` ao vivo. Se passado → prefere `DesempenhoScoreSnapshot::mensal()` (o `breakdown_json` gravado por `ConsolidarMesDesempenho`); só cai para `computeCached()` se o snapshot não existir. **Não há hoje nenhum conceito de "a competência oficial de bônus é X" — é implícito na cadência do cron** (`ConsolidarMesDesempenho` roda dia 1, mês anterior ao hoje, que por coincidência de calendário sempre É a competência de bônus do mês corrente).

`WarmDesempenhoCache` só aquece `Carbon::now()->startOfMonth()` — **nunca** aquece o mês de competência do bônus. `ConsolidarMesDesempenho` chama `compute()` DIRETO (não `computeCached()`) com `--mes` (default = mês anterior). `SnapshotDesempenhoScores` (diário) sempre usa o mês corrente, também via `compute()` direto.

### Opções de assinatura avaliadas

**Opção A — trocar `Carbon $mesReferencia` por `array $periodo` (shape do resolver) em `compute()`/`computeCached()`.**
Arquiteturalmente correta (o service passa a receber o período já resolvido, nunca decide sozinho), mas tem raio de explosão grande: `grep` encontrou **~40+ arquivos** referenciando `compute(`/`computeCached(` — controller (3 call sites), 3 commands, e pelo menos 10 suítes de teste (`Phase74`, `V16/BonusDualPathRegressaoTest`, `V16/DesempenhoElegibilidadeTest`, `V16/ComparacaoContextualBlockedTest`, `DesempenhoScoreSnapshotTest`, `DesempenhoEvolucaoTest`, `ConsolidarMesDesempenhoCommandTest`, etc.) que chamam `compute($user, Carbon::parse(...))` diretamente. Todos precisariam reescrever a chamada.

**Opção B (recomendada) — manter `Carbon $mesReferencia` como parâmetro primário (compat com todos os call sites/testes existentes), adicionar um parâmetro opcional `?array $periodoOverride = null`, e resolver internamente quando ausente:**
```php
public function compute(User $user, Carbon $mesReferencia, ?array $periodoOverride = null): array
{
    $mes = $mesReferencia->copy()->startOfMonth();
    $periodo = $periodoOverride ?? $this->resolvePeriodo($mes);
    // ...
}

private function resolvePeriodo(Carbon $mes): array
{
    $mesCorrente = now()->startOfMonth();
    return $mes->equalTo($mesCorrente)
        ? $this->periodResolver->resolve(['period_key' => 'current_month'])
        : $this->periodResolver->resolve(['period_key' => $mes->format('Y-m')]);
}
```
E um método de conveniência explícito para o modo oficial:
```php
public function computeOficial(User $user): array
{
    $periodo = $this->periodResolver->resolve(['period_key' => 'last_closed_month']);
    $mes = Carbon::parse($periodo['current_start'])->startOfMonth();
    return $this->compute($user, $mes, $periodo);
}
```
Isso preserva 100% dos call sites existentes (`compute($user, $mes)` continua funcionando, resolvendo o período sozinho pela regra "é o mês corrente? current_month : YYYY-MM"), e dá ao controller/aos commands um caminho EXPLÍCITO para pedir "o número oficial de bônus" sem ambiguidade — sem depender de `$mesReferencia` coincidir com o mês fechado mais recente. **Trade-off assumido:** `resolveSpecificMonth('YYYY-MM')` e `resolveLastClosedMonth()` produzem os MESMOS `current_start/end`/`baseline_start/end` quando o mês pedido é de fato o último fechado — a única diferença é que `resolveSpecificMonth` retorna `bonus_payment_month=null`/`bonus_competence_month=null` (por design do resolver — ver `MetricPeriodResolver.php:216-223`). Ou seja: chamar `compute($user, $mesJunho)` (via Opção B, sem override) dá o MESMO NÚMERO que `computeOficial($user)`, mas SEM os metadados `bonus.*` — a UI (Fase 104) não saberia rotular como "oficial de bônus". Recomenda-se que `PerformanceController` chame `computeOficial()` explicitamente sempre que renderizar o contexto "Bônus atual", e reserve `compute($user, $mes)` para navegação de auditoria de meses específicos.

**Decisão que o planner precisa travar:** qual das duas opções (A ou B) — ou uma variante — recomendamos a Opção B pelo menor raio de explosão e por preservar `computeCached()`, mas é uma decisão arquitetural que afeta todos os consumidores futuros (Fase 103/104) e deveria ser confirmada com o usuário antes do plano.

## A chave de cache (crítico)

Hoje: `sprintf('desempenho.compute.v4.%d.%s', $user->id, $mes->format('Y-m'))` (linha 191), TTL 7 dias se `$mes < $mesCorrente`, senão 10 min.

**Dois problemas que a Fase 102 precisa resolver simultaneamente:**

1. **Bump obrigatório v4→v5** — a régua de baseline muda (calendário → janela-de-mesmo-tamanho) e a fonte de `var_margem_pct` muda (cálculo manual → `AdmanMetricDiffService`). Sem o bump, o Redis serviria o número ANTIGO por até 7 dias após o deploy para qualquer mês fechado já cacheado sob a chave v4 — reincidência do mesmo padrão documentado nos bumps v1→v2→v3→v4 (comentários linhas 172-190 do arquivo atual).
2. **Colisão operacional × oficial no MESMO rótulo `Y-m`** — se `computeOficial()` (Opção B) e um `compute($user, $mesJunho)` "genérico" (auditoria) resolvem para o MESMO `current_start`/`current_end` mas com metadados `bonus.*` diferentes (populados vs `null`), cachear os dois sob a MESMA chave (`v5.{user_id}.2026-06`) faz o segundo sobrescrever o primeiro silenciosamente — quem pediu o "oficial" pode receber uma resposta cacheada sem `bonus.*`, ou vice-versa. **A chave precisa incluir o `period_key` do resolver, não só o `Y-m`:**

```php
$periodo = $periodoOverride ?? $this->resolvePeriodo($mes);
$cacheKey = sprintf('desempenho.compute.v5.%d.%s', $user->id, $periodo['period_key']);
// period_key ∈ {'current_month', '2026-06', 'last_closed_month', ...}
```

Isso separa naturalmente `current_month` (TTL curto) de `2026-06`/`last_closed_month` (TTL longo) — e evita a colisão de metadados porque `last_closed_month` e `2026-06` (mesmo mês, chamado por caminhos diferentes) teriam chaves DIFERENTES (o que é aceitável: são pedidos com propósitos diferentes, o pequeno custo de computar 2× é preferível a servir metadado errado). **Se o planner preferir uma única chave por mês independente do path**, a alternativa é usar sempre `$mes->format('Y-m')` na chave MAS garantir que `bonus.*` seja computado a partir de `$mes` diretamente (sem depender de qual método foi chamado) — isso é possível porque "junho é competência de bônus" é uma verdade sobre `$mes`, não sobre COMO ele foi resolvido (ver observação no shape de retorno abaixo). Recomenda-se essa segunda abordagem — mais simples, uma única chave por mês, e os metadados `bonus.*` sempre presentes e coerentes.

## Snapshots de mês fechado — decisão de produto bloqueante (DEC-80-E revisitada)

`ConsolidarMesDesempenho` roda `monthlyOn(1, '14:00')`, sem `--mes` explícito consolida **"mês anterior ao hoje"** (`Carbon::today()->subMonthNoOverflow()->startOfMonth()`). Isso significa: **em 01/07/2026 às 14:00, o comando JÁ RODOU e já gravou o snapshot de junho/2026** sob a régua ANTIGA (calendário-vs-calendário, sem diff da Adman, sem metadados `periodo`/`bonus`). Como hoje é 2026-07-20, esse snapshot está em produção.

`PerformanceController::show()`/`index()` **preferem o snapshot mensal já gravado** (`breakdown_json`) para qualquer mês que não seja o corrente — só caem para `computeCached()` se o snapshot NÃO existir para aquele user/mês. Isso é o padrão de otimização "mês fechado é estável, não recomputa" que já existe hoje.

**A tensão:** o objetivo declarado da Fase 102 é "julho mostra junho fechado, com a régua nova". Mas se ninguém reprocessar junho, a tela de "Bônus atual" em julho vai continuar mostrando o `breakdown_json` de junho **calculado pela régua ANTIGA** — porque o controller prefere o snapshot existente, e o snapshot não vai mudar sozinho só porque o código do service mudou. **A fase entrega o motor novo, mas o número exibido para junho só muda se alguém rodar:**

```bash
php artisan desempenho:consolidar-mes --mes=2026-06
```

Esse comando é idempotente (`updateOrCreate` por `user_id`+`mes_referencia`) — rodar de novo NÃO duplica, apenas sobrescreve o `breakdown_json` de junho com o resultado da régua nova.

**Isto é uma decisão de negócio, não técnica — precedente direto em `91-02-SUMMARY.md`** ("Meses fechados NÃO são reprocessados... decisão de negócio da diretoria, fora do escopo técnico"). Mas o precedente da Fase 91 mudava só o UNIVERSO de empresas (matemática dos componentes intacta); aqui a MATEMÁTICA em si muda (baseline shape + fonte da margem) — o impacto de reprocessar (ou não) é maior:

- **Se reprocessar junho:** o bônus de junho pago/comunicado em julho pode MUDAR de valor (para melhor ou pior) depois de já ter sido potencialmente comunicado às pessoas — risco de reabrir uma conversa de bônus já fechada.
- **Se NÃO reprocessar:** a Fase 102 entrega código correto, mas o número exibido continua errado (calculado com a régua antiga) até o PRÓXIMO fechamento natural (01/08, consolidando julho) — o que atrasa o benefício da fase em um mês inteiro, e pode ser confuso porque o código "novo" (com testes provando a régua nova) coexiste com um dado "antigo" na tela.

**Recomendação para o planner:** não decidir isso silenciosamente dentro do plano. Expor como pergunta explícita ao usuário/diretoria ANTES da execução: *"O bônus de junho/2026, já consolidado sob a régua antiga, deve ser recalculado com a régua nova (Adman diff + janela-de-mesmo-tamanho) antes do pagamento de julho, ou o ajuste vale só a partir do fechamento de julho (01/08)?"* — e documentar a resposta como decisão travada, com o comando exato a rodar (ou não) como parte do checklist de deploy da fase.

## Refatoração recomendada — `var_margem_pct` via `AdmanMetricDiffService` (BON-03)

Trocar o corpo de `computeVarMargem()` (linhas 811-978, ~167 linhas incluindo os guards duplicados) por uma delegação por empresa:

```php
private function computeVarMargem(User $user, Carbon $mes, EloquentCollection $companies, array $periodo): ?float
{
    if ($companies->isEmpty()) {
        return null;
    }

    $vars = collect();
    foreach ($companies as $company) {
        $resultado = $this->admanDiffService->compute($company, $periodo);
        $diffPct = $resultado['metrics']['contribution_margin_pct']['diff_pct'] ?? null;
        if ($diffPct !== null) {
            $vars->push($diffPct);
        }
    }

    return $vars->isEmpty() ? null : round($vars->avg(), 2);
}
```

**Linhas a REMOVER integralmente** (guards duplicados, conforme o próprio docblock de `AdmanMetricDiffService` já anuncia): 833-859 (janela em-curso/fechado inline — substituída por `$periodo['current_start']` etc.), 845-902 (query `margemAtual`/`margemAnterior` + guard `margem_dias`), 904-971 (loop com guard dias-comuns + reagregação por interseção). Isso remove ~140 linhas de `DesempenhoScoreService.php` e a fonte única dos guards passa a ser `AdmanMetricDiffService::somasComGuards()`.

**`var_faturamento_pct` NÃO deve ser migrado por completo para `AdmanMetricDiffService`** — a régua v17.0 (DESEMP-11) exige ML-first com fallback Adman (`MetricsProviderFactory::caseFor()` + `readForCompany()`), e `AdmanMetricDiffService` só lê Adman. Recomenda-se: manter o ramo ML tal como está (mas usando `$periodo['current_start']`/`current_end`/`baseline_start`/`baseline_end']` no lugar das variáveis inline), e usar `AdmanMetricDiffService::compute()` (campo `revenue.diff_pct`) SÓ no fallback (quando `$fonteConsistente === null`), no lugar da query manual + guard de dias-comuns hoje duplicada nas linhas 722-738. Isso não está explicitamente exigido por nenhum REQ desta fase (BON-03 fala só de margem), mas evita deixar DOIS conjuntos de guards de dias-comuns divergentes no código (um em `AdmanMetricDiffService`, outro remanescente em `computeVarFaturamento`) — sinalizar ao usuário como melhoria recomendada, não obrigatória.

**Nota importante de nomenclatura (ADM-05):** `AdmanMetricDiffService::METRIC_KEYS` usa `contribution_margin_pct` como chave interna, mas por baixo dos panos isso é `percentageMargin` da Adman (não confundir com `profitMargin`/margem R$). BON-03 pede especificamente `percentageMargin.diff` — confirmado: `contribution_margin_pct` em `AdmanMetricDiffService::compute()` (linha 131-134) lê `$accountMetrics['percentageMargin']`. Correto, sem ambiguidade — mas o nome da chave (`contribution_margin_pct`, similar ao campo interno de `DesempenhoScoreService`/`AdmanMetric.contribution_margin`) é uma armadilha de leitura — vale um comentário explícito no código de integração deixando claro que `contribution_margin_pct` (chave do diff service) = `percentageMargin` (Adman) ≠ `AdmanMetric.contribution_margin` (campo R$, usado no fallback).

## Shape de retorno alvo (BON-04)

Acrescentar ao shape hoje documentado em `compute()` (linhas 100-135):

```php
'periodo' => [
    'current_start'  => $periodo['current_start'],
    'current_end'    => $periodo['current_end'],
    'baseline_start' => $periodo['baseline_start'],
    'baseline_end'   => $periodo['baseline_end'],
    'mode'           => $periodo['mode'],           // 'operational'|'official_bonus'|'closed_period'
    'comparison_mode'=> $periodo['comparison_mode'],
],
'bonus' => [
    'payment_month'    => $periodo['bonus_payment_month'],    // string 'Y-m' ou null
    'competence_month' => $periodo['bonus_competence_month'], // string 'Y-m' ou null
],
```

Recomendação (ver seção de cache acima): calcular `bonus.competence_month`/`bonus.payment_month` a partir de `$mes` diretamente (não só do `$periodo['bonus_*']`, que só vem populado quando `period_key==='last_closed_month'`) — ex.: `$mes->format('Y-m')` como competência e `$mes->copy()->addMonthNoOverflow()->format('Y-m')` como pagamento, SEMPRE que o mês for um mês fechado (`!$ehMesEmCurso`). Isso torna os metadados `bonus.*` coerentes independentemente de qual caminho (`compute($user,$mes)` genérico vs `computeOficial()`) gerou o resultado — ver discussão na seção "A chave de cache".

O `periodo_meta` já existente hoje (linhas 302-306, `em_curso`/`dias_decorridos`/`dias_no_mes`) pode ser mantido como está OU substituído pelo `periodo` novo — são redundantes; sugerir ao planner decidir se remove o antigo (mudança de shape, quebra consumidores do payload atual) ou mantém os dois lado a lado por uma fase de transição (mais seguro para não quebrar `Performance/*.jsx` antes da Fase 104).

## Pitfall 1 (CRÍTICO) — fixtures de 1-linha-no-dia-15 quebram sob janela-de-mesmo-tamanho

**O que acontece:** os testes atuais (`DesempenhoScoreServiceTest::mockAdmanRevenueMargem`, e o padrão equivalente em `tests/Feature/V16/*`) criam **uma única linha** de `AdmanMetric` no dia 15 de cada mês (`Carbon::parse($mesYm.'-15')`). Sob a régua ANTIGA (calendário-vs-calendário, ambas janelas começando no dia 1), comparar "dia 15 de julho" com "dia 15 de junho" funciona porque o guard de dias-comuns usa **dia-do-mês** como chave — e ambos os meses começam no dia 1, então dia-do-mês é um eixo comum válido.

Sob a régua NOVA (`baselineJanelaMesmoTamanho`), a baseline de um mês de 31 dias (julho) NÃO começa no dia 1 do mês anterior — começa **31 dias antes do início de julho**, ou seja, **31/05**, não 01/06. Refazendo a conta para o fixture Carlos (`compute($carlos, Carbon::parse('2026-07-01'))`, com `now()` congelado em 2026-08-01 nos testes):

```text
current_start = 2026-07-01, current_end = 2026-07-31 (31 dias)
baseline_end   = 2026-06-30
baseline_start = 2026-06-30 - 30 dias = 2026-05-31

Linha mock de julho: 2026-07-15 → offset desde current_start = 14 dias
Linha mock de junho: 2026-06-15 → offset desde baseline_start (2026-05-31) = 15 dias

offset 14 ≠ offset 15 → interseção de "dias-comuns" VAZIA → AdmanMetricDiffService
retorna diff_pct = null para essa empresa.
```

**Isso não é um bug em `AdmanMetricDiffService`** — o guard por offset-desde-o-início-da-janela é a generalização CORRETA quando a janela não começa no dia 1 (documentado no próprio service). O problema é que o padrão de fixture "1 linha fixa no dia 15" foi calibrado para o mundo antigo (janelas sempre começando no dia 1) e não sobrevive à mudança de régua.

**Impacto:** a âncora Carlos (`test_fixture_carlos_retorna_nota_4_08_basico`) e provavelmente TODOS os testes que usam esse padrão de fixture em `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` e `tests/Feature/V16/*` (BonusDualPathRegressaoTest, DesempenhoElegibilidadeTest, ComparacaoContextualBlockedTest, ConsolidarMesDesempenhoCommandTest) vão **quebrar silenciosamente para `var_margem_pct=null`** assim que a delegação para `AdmanMetricDiffService` entrar — não por erro de lógica, mas por desalinhamento de fixture.

**Como evitar:**
1. Trocar o padrão de fixture de "1 linha no dia 15" para dados diários DENSOS (uma linha por dia, cobrindo toda a janela relevante) — mais realista e imune a esse tipo de desalinhamento de offset, ao custo de mais linhas de setup por teste.
2. OU calcular o dia exato calibrado para o offset correto por cenário (frágil — quebra de novo se a duração dos meses do teste mudar).
3. A âncora Carlos especificamente **vai precisar de um NOVO valor golden** — não adianta tentar preservar `4.08/basico`, porque a baseline em si mudou de forma (calendário → janela-de-mesmo-tamanho) por decisão de negócio já travada em `REQUIREMENTS-v18.md` (PER-03/PER-04). O planner deve tratar isso como "recalibrar a âncora", não como "preservar a âncora".

**Este achado deve ser comunicado ao usuário/diretoria** junto com a decisão de reprocessar-ou-não junho: a fase vai, necessariamente, produzir um número de bônus diferente do que a régua antiga produziria para o mesmo mês — mesmo family de decisão que "julho paga junho" já implicava, mas agora com uma superfície de teste concreta que precisa ser recalibrada.

## Regressões que não podem quebrar (mapeadas)

| Item | Situação hoje | Risco na Fase 102 |
|------|----------------|--------------------|
| Âncora Carlos (`Phase74/DesempenhoScoreServiceTest`) | `nota_final=4.08/basico`, `var_margem_pct=+2.80%` exato | **VAI MUDAR** — ver Pitfall 1. Precisa de fixture + golden number recalibrados, não de expectativa preservada |
| `BonusDualPathRegressaoTest` (V16) | Testa bump de cache v3→v4 e fórmula do NPS via reflection sobre `notasPorAtribuicao`/`notasLegado` | `computeNpsMedio` é INTOCADO nesta fase — teste deve continuar verde SEM alteração, desde que o bump de cache (v4→v5) não quebre a lógica de reflection (ela não depende da chave de cache) |
| `DesempenhoElegibilidadeTest` (V16, Fase 91) | Testa `score_status`/6 metadados/dedup/cache v4 | `score_status`/`computeUniverso` não mudam nesta fase — só o teste de "cache v4" precisa virar "cache v5" (ou ser reescrito para checar `period_key` na chave) |
| `ComparacaoContextualBlockedTest` (V16, Fase 92) | Testa `PortfolioController::show()` blocked não vira 0.0 | Fora do arquivo tocado por esta fase (`PortfolioController` é escopo da Fase 103) — mas usa `DesempenhoScoreService::compute()` como oráculo para calcular a mediana esperada; se o valor de `var_margem_pct` mudar (Pitfall 1), o oráculo do teste recalcula automaticamente (não hardcoded) — **provavelmente seguro**, mas precisa rodar para confirmar |
| `ConsolidarMesDesempenhoCommandTest` (Phase74) | Testa idempotência do `--mes`, sem_carteira, ranking_pos | Usa fixture própria com o MESMO padrão "1-linha" (já corrigido uma vez na Fase 91 — ver `91-01-SUMMARY.md` "Auto-fixed Issues") — sujeito ao mesmo Pitfall 1 |
| `computeNpsMedio` / `notasPorAtribuicao` / `notasLegado` | Dual-path Fase 79/80 | **INTOCADO** por esta fase — confirmar por grep que nenhum diff toca essas 3 funções |
| Sessão paralela NPS | Trabalho concorrente em `resources/js/Pages/Nps/*` (fora do domínio Desempenho) | Não tocar nenhum arquivo NPS-front; `DesempenhoScoreService.php` é o único arquivo de produção nesta fase de alto risco — manter fronteira estrita, igual ao padrão das Fases 91/92 |
| `PublicacaoDesempenhoRouteTest` | Falha PRÉ-EXISTENTE documentada (403≠200, permissão `mlb.dashboard`) | Ortogonal — classificar como pré-existente se reaparecer, não investigar |

## Pitfall 2 — `AdmanMetricDiffService::compute()` é leitura AO VIVO (HTTP), não query local

Diferente da query SQL agregada de 2 SELECTs que `computeVarMargem()` faz hoje para TODAS as empresas de uma vez, `AdmanMetricDiffService::compute()` é **por empresa** e faz até 2 chamadas HTTP à Adman quando o cache (`adman:diff:*`, TTL 24h por dia) está frio: `fetchPerformance()` (profitMargin) + `fetchAccountMetricsDetailedCached()` (percentageMargin — este último já é cacheado internamente pelo `AdmanService`).

**Risco:** `ConsolidarMesDesempenho` (batch mensal, ~15-20 users × até ~30 empresas cada) chama `compute()` DIRETO (sem `computeCached()` no nível de user) — se o cache do `AdmanMetricDiffService` estiver frio para o período (primeira vez que a competência é consultada), o batch pode reintroduzir o mesmo padrão de lentidão que o incidente `audit-performance-lentidao` (2026-07-10) já corrigiu para o dashboard (70s para 11 users, 99% em HTTP síncrono). Como o cache do diff service é por `(marketplace, custId, current_start, current_end, dia)`, ele é **compartilhado entre TODOS os users que compartilham a mesma empresa** — mas cada empresa normalmente pertence à carteira de 1 analista + 1 estrategista, então o reaproveitamento entre o snapshot diário (`SnapshotDesempenhoScores`, roda 13:30 todo dia) e o mensal (`ConsolidarMesDesempenho`, dia 1 14:00) É real (mesmo período, mesmo dia de cache) — mas a PRIMEIRA chamada do dia/período ainda paga o custo total em HTTP.

**Recomendação:** medir o tempo de execução do `ConsolidarMesDesempenho` em staging/local antes do deploy, e considerar pré-aquecer o cache do `AdmanMetricDiffService` para o período oficial (`last_closed_month`) ANTES do horário do cron mensal — análogo ao papel que `WarmDesempenhoCache` já cumpre para o cache do `compute()` inteiro. Isso não é um requisito formal desta fase, mas é um risco operacional direto de reusar `AdmanMetricDiffService` num contexto de batch que a Fase 101 não teve motivo para testar sob carga.

## Pitfall 3 — `WarmDesempenhoCache` não conhece o novo período oficial

Hoje `WarmDesempenhoCache` só aquece `Carbon::now()->startOfMonth()` (o mês corrente, operacional). Se `PerformanceController` passar a chamar `computeOficial()` (ou equivalente) para o contexto "Bônus atual" (Fase 104, mas o backend desta fase precisa sustentar isso), essa chamada NUNCA é pré-aquecida pelo warm command — o primeiro usuário a abrir a tela "Bônus atual" depois do TTL expirar (7 dias, já que é mês fechado) sofre o cold-miss completo (incluindo o Pitfall 2 acima, potencialmente). Recomenda-se estender `WarmDesempenhoCache` para também chamar `computeCached()` com o período oficial (`last_closed_month`) — baixo custo (muda 1x por mês, TTL de 7 dias já cobre o mês inteiro), mas precisa ser adicionado explicitamente nesta fase ou na 103/104 para não ficar esquecido.

## Pitfall 4 — Carbon::setTestNow e o congelamento de "hoje" nos testes

Os testes existentes (`DesempenhoScoreServiceTest::setUp()`) já congelam `Carbon::setTestNow('2026-08-01 14:05:00')`. O `MetricPeriodResolver` usa `Carbon::now(self::TIMEZONE)` internamente (`now()` privado, linha 321-324) — **compatível** com `Carbon::setTestNow()` (Carbon respeita o mock global independente de timezone explícito). Não é necessário injetar Carbon customizado no resolver; os testes atuais continuam funcionando com o padrão `setTestNow`. Atenção apenas para consistência: `MetricPeriodResolver::TIMEZONE = 'America/Sao_Paulo'` — se algum teste futuro usar `Carbon::setTestNow()` com um Carbon UTC puro sem timezone explícito, o `Carbon::now(self::TIMEZONE)` interno pode devolver um dia diferente perto da virada de mês (23h-1h de diferença UTC↔BRT). Recomenda-se sempre congelar com horário "seguro" (ex.: 14:00, como já é o padrão) para evitar flakiness de fronteira de dia.

## Ambiente

- Binário PHP local para rodar testes: `C:\xampp\php\php.exe vendor/bin/phpunit` (confirmado em comentários de summaries anteriores — `php` do PATH pode resolver para outra versão no Windows).
- `MetricPeriodResolver` e `AdmanMetricDiffService` já existem em `app/Services/Metrics/` — nenhuma migration nova necessária para esta fase (ambos são services puros/live-read, sem coluna nova, conforme decisão travada na Fase 101).

## Don't Hand-Roll

| Problema | Não construir | Usar em vez disso | Por quê |
|----------|----------------|--------------------|---------|
| Cálculo de janela atual/baseline (qualquer modo) | Lógica inline com `now()`/`subMonth()`/`startOfMonth()` | `MetricPeriodResolver::resolve()` | Única fonte de verdade determinística, já testada (PER-01..06 completos, Fase 100) |
| Variação de margem % pronta da Adman | Somar `contribution_margin` manualmente com guards de dias-comuns | `AdmanMetricDiffService::compute()->metrics['contribution_margin_pct']` | Guards já cicatrizados (fix Luiz + audit Tomelin/LOJASINVAL/AVF2K) generalizados corretamente para janelas arbitrárias; duplicar é reintroduzir bug já resolvido |
| Metadados de competência/pagamento de bônus | Calcular `$mes->addMonth()` ad-hoc em cada consumidor | Ler `bonus_payment_month`/`bonus_competence_month` do resolver (ou replicar a MESMA fórmula simples, `addMonthNoOverflow`, num único lugar) | Evita drift entre Carteira (Fase 103), Desempenho (102) e UI (104) |

**Key insight:** esta fase é essencialmente uma fase de DELEGAÇÃO — o trabalho pesado (janelas, guards, gate por `comparison_mode`) já foi construído nas Fases 100/101. O risco real não é "construir errado", é "esquecer de apagar a versão duplicada antiga" (cache stale, snapshot stale, guard duplicado) — os 3 pitfalls documentados acima são exatamente essas 3 formas de esquecimento.

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|--------|--------------------|
| A1 | A Opção B de assinatura (`Carbon` + `?array $periodoOverride`) é a que "muda menos" e deve ser recomendada ao invés da Opção A (trocar tudo para `array $periodo`) | Onde entra o modo bônus vs operacional | Se o usuário preferir a Opção A por consistência arquitetural de longo prazo (Fases 103/104 vão precisar do mesmo padrão de qualquer forma), o plano da Fase 102 deveria adotar A desde já para não fazer 2 migrações de assinatura em fases seguidas |
| A2 | `bonus.competence_month`/`bonus.payment_month` devem ser calculados a partir de `$mes` diretamente (sempre presentes para qualquer mês fechado), não só quando `period_key==='last_closed_month'` | Shape de retorno alvo / chave de cache | Se a diretoria/produto quiser que `bonus.*` só apareça quando for LITERALMENTE o mês de competência atual (não qualquer mês fechado auditado), essa recomendação precisa ser revertida — decisão de produto, não técnica |
| A3 | Reprocessar (ou não) o snapshot de junho/2026 é decisão do usuário/diretoria, fora do escopo técnico do plano | Snapshots de mês fechado | Se o plano assumir silenciosamente "não reprocessa" (seguindo o precedente da Fase 91) sem perguntar, o objetivo declarado da milestone ("julho mostra junho com a régua nova") pode não se concretizar visualmente até 01/08 |
| A4 | Testes com fixture "1 linha no dia 15" vão quebrar para `null` sob a nova baseline (Pitfall 1) — comportamento generalizável a todos os testes do domínio Desempenho que seguem esse padrão | Pitfall 1 | Verificado matematicamente para o caso Carlos (offset 14 vs 15); não foi executado `phpunit` de fato nesta sessão de pesquisa para confirmar empiricamente — o planner deve rodar a suíte ANTES de assumir a extensão exata do dano |

**Se esta tabela estivesse vazia:** não está — A1/A2 são recomendações técnicas que dependem de preferência de longo prazo do usuário; A3 é uma decisão de negócio explícita; A4 é uma dedução matemática não verificada empiricamente nesta sessão (nenhum teste foi executado).

## Open Questions (RESOLVED — decisões travadas nos plans 102-01/102-02)

1. **Reprocessar junho/2026?** (ver `## Decisões de produto`) — pergunta direta ao usuário/diretoria antes de executar o plano. Recomenda-se incluir como pergunta de discuss-phase ou checkpoint explícito no plano.
2. **Opção A vs B de assinatura de `compute()`** — decisão arquitetural com impacto nas Fases 103/104 (que também vão precisar declarar operacional-vs-fechado). Recomenda-se decidir agora para não migrar 2x.
3. **`periodo_meta` antigo (em_curso/dias_decorridos/dias_no_mes) fica ou sai?** — redundante com o `periodo` novo; manter os dois evita quebrar consumidores do payload atual (`Performance/*.jsx`) antes da Fase 104, mas deixa o shape poluído por 2 fases. Recomenda-se manter nesta fase e remover na 104 (quando o frontend migrar).
4. **`var_faturamento_pct` delega também para `AdmanMetricDiffService` no fallback Adman, ou mantém a query manual?** Nenhum REQ exige isso explicitamente (só BON-03 fala de margem) — mas deixar os dois guards de dias-comuns vivendo em paralelo (um em cada service) é uma dívida técnica. Recomenda-se ao planner decidir se cabe nesta fase ou fica para depois.

## Sources

### Primary (HIGH confidence — leitura direta de código nesta sessão)
- `app/Services/DesempenhoScoreService.php` (1199 linhas, lido inteiro)
- `app/Services/Metrics/MetricPeriodResolver.php` (363 linhas, lido inteiro)
- `app/Services/Metrics/AdmanMetricDiffService.php` (396 linhas, lido inteiro)
- `app/Http/Controllers/PerformanceController.php` (1080 linhas, lido inteiro)
- `app/Console/Commands/{WarmDesempenhoCache,ConsolidarMesDesempenho,SnapshotDesempenhoScores}.php` (lidos inteiros)
- `plano-carteira-desempenho-multi-servico.md` §1-260 (regra de período/margem) e §780-1226 (ajustes por arquivo, fases, critérios de aceite, testes obrigatórios)
- `.planning/REQUIREMENTS-v18.md` (BON-01..05, PER-01..06, ADM-01..05, critérios de aceite globais)
- `.planning/ROADMAP.md` (Fase 74 e histórico de decisões locked de bônus)
- `.planning/phases/91-.../91-01-SUMMARY.md` e `91-02-SUMMARY.md` (precedente DEC-91, auditoria dos 9 consumidores, pendência comparacaoContextual)
- `.planning/phases/92-.../92-01-SUMMARY.md` (confirmação de que comparacaoContextual já foi corrigido — não é pendência desta fase)
- `.planning/phases/80-.../80-CONTEXT.md` (definição de DEC-80-E)
- `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` (fixture Carlos, `mockAdmanRevenueMargem`, `Carbon::setTestNow`)
- `.planning/config.json` (confirmação `nyquist_validation: true`, `security_enforcement` ausente → default enabled, mas fase não introduz superfície de segurança nova — ver nota abaixo)

### Nota sobre seções omitidas
- **Security Domain**: omitida — esta fase não introduz input de usuário novo, endpoint novo, nem mudança de autenticação/autorização; é refatoração interna de cálculo consumido por rotas já protegidas por `permission:core.performance` (inalterado). Nenhuma superfície ASVS nova.
- **Validation Architecture**: como a fase é dominada por lógica de cálculo pura + integração de services já testados, o Wave 0 deve focar em: (a) atualizar/recriar a suíte `DesempenhoScoreServiceTest` com fixtures densas (Pitfall 1) e golden numbers recalibrados; (b) testes de cache-key incluindo `period_key`; (c) teste explícito comparando `compute($user, $mesJunho)` vs `computeOficial($user)` para o mesmo mês, provando que os números batem e só os metadados `bonus.*` diferem (se a Opção B/A2 for adotada).

## Metadata

**Confidence breakdown:**
- Mapeamento das janelas hoje / integração com resolver+diff service: HIGH — leitura direta e completa dos 3 services envolvidos, sem ambiguidade de código.
- Decisão de modo bônus vs operacional (assinatura): MEDIUM — a recomendação (Opção B) é sólida tecnicamente, mas é uma escolha de design que o usuário pode preferir diferente.
- Pitfall 1 (fixtures quebram): MEDIUM-HIGH — matemática verificada manualmente com precisão (offsets calculados explicitamente), mas não confirmada rodando `phpunit` nesta sessão.
- Decisão de reprocessar snapshot de junho: LOW quanto ao "certo a fazer" (é puramente uma decisão de negócio, não técnica) — HIGH quanto ao fato de que a tensão existe e é real (confirmado via leitura do cron + do controller).

**Research date:** 2026-07-20
**Valid until:** ~7 dias — domínio de alto risco (motor de bônus), qualquer mudança em `AdmanMetricDiffService`/`MetricPeriodResolver` ou nova consolidação mensal invalida partes desta pesquisa.
