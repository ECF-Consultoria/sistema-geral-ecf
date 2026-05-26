---
slug: dashboard-sync-inconsistente
status: resolved
trigger: "Dashboard mostra totais (faturamento, investimento ads, TACOS médio) que oscilam aleatoriamente a cada execução do sync Adman, com variações de até ~R$ 20M para baixo e para cima — comportamento aparece após cadastro de todas as empresas (alto volume de dados)."
created: 2026-05-26
updated: 2026-05-26
resolved: 2026-05-26
goal: find_and_fix
---

# Debug Session: dashboard-sync-inconsistente

## Symptoms

**Expected behavior**
A cada execução do sync Adman, os agregados do dashboard (Faturamento total, Investimento em Ads, TACOS médio) devem ser determinísticos para a mesma janela de tempo: se nenhum dado novo entrou na Adman desde a última sync, os números devem se manter; se novos dias entraram, os números devem crescer monotonicamente (não cair).

**Actual behavior**
A cada execução do sync, os números oscilam de forma aparentemente aleatória — às vezes sobem, às vezes caem (~R$ 20M de variação). Isso sugere que cada sync produz um subconjunto diferente dos dados, não um superconjunto crescente.

**Error messages**
Não foi reportado erro visível na UI. Comportamento silencioso — sintoma é só a inconsistência dos números.

**Timeline**
Apareceu / se tornou perceptível após o cadastro de todas as empresas (aumento do volume de dados). Antes, com menos empresas, o problema não era notado (ou não existia).

**Reproduction**
1. Acessar dashboard → anotar Faturamento total, Investimento em Ads, TACOS médio
2. Disparar sync Adman (manual via `/dev/desenvolvimento` ou aguardar agendado)
3. Recarregar dashboard → comparar números
4. Repetir várias vezes → observar oscilação aleatória, não crescimento monotônico

## Known Context (do CLAUDE.md, STATE.md e memória)

- **Stack**: Laravel 12 + Inertia + React; queue driver `database`; cache driver `database`
- **Dashboard read path**: `Faturamento = SUM(adman_metrics.revenue) GROUP BY company_id` — premissa registrada estava DESATUALIZADA. O commit `f9056ea` (25/05) mudou o card "Faturamento" para usar **cache de chamadas diretas à Adman `/performance` (range 30d)** com **fallback para SUM(adman_metrics.revenue)** quando o cache está cold/erro.
- **Sync write path**: `app/Services/AdmanService.php` (`syncAll`, `syncCompany`); jobs em `app/Jobs/SyncAdmanCompanyJob.php`; comando `app/Console/Commands/SyncAdmanData.php`; rota manual em `/dev/desenvolvimento` chama `AdmanController::syncNow` → `syncAll`.
- **Cache write path**: `app/Jobs/RefreshGrossBillingCacheJob.php` agendado a cada 30min em `routes/console.php:58` chama `/performance` e `/accounts/metrics` por empresa, com throttle de 1.5s.

## Hypotheses to investigate (priorizadas)

1. ~~**H1 — Sync parcial silencioso (alta probabilidade)**~~: **PARCIALMENTE CORRETA**. Há sync parcial silencioso, mas a oscilação NÃO vem do write em `adman_metrics` (que usa `updateOrCreate` por `(company_id, reference_date)` — não-destrutivo, idempotente). Vem do **read no dashboard**, que mistura duas fontes diferentes por empresa dependendo de quem teve cache quente.
2. ~~**H2 — Replace/upsert mal aplicado**~~: **REFUTADA**. `syncCompany` faz `AdmanMetric::updateOrCreate(['company_id' => ..., 'reference_date' => ...], [...])` (AdmanService.php:94). Constraint UNIQUE `(company_id, reference_date)` existe na migration `2026_04_26_152220_create_adman_metrics_table.php:29`. Sem DELETE.
3. ~~**H3 — Janela de data variável**~~: **CONTRIBUI MAS NÃO É A CAUSA-RAIZ**. O range "30d" é `now()->subDays(30)..now()` recalculado a cada request — entre dias diferentes, a chave do cache muda (`adman:gross_billing:{custId}:{dateFrom}:{dateTo}`) e o cache vira completamente miss, forçando fallback DB para tudo.
4. ~~**H4 — Race entre múltiplos workers/jobs**~~: **REFUTADA**. `syncCompany` é idempotente por `(company_id, reference_date)`. `RefreshGrossBillingCacheJob` tem `ShouldBeUnique` (uniqueId fixo) — não roda em paralelo. `adman:sync` tem `withoutOverlapping()`. Sem corrida.
5. ~~**H5 — Cache do dashboard**~~: **CONFIRMADA — É A CAUSA**. Não é o controller que cacheia o response inteiro, mas o controller LÊ de um cache cuja composição varia entre requests.

## Current Focus

```yaml
hypothesis: "Causa-raiz CONFIRMADA: o card 'Faturamento' (e 'Investimento em Ads 30d', 'TACOS médio') mistura DUAS fontes de dados por empresa dependendo do estado do cache: (a) valor exato da Adman /performance 30d quando o cache está quente, (b) SUM(adman_metrics.revenue) WHERE reference_date >= now-30d quando o cache está miss/erro. Os dois valores divergem MUITO por empresa (Adman aplica ajustes retroativos, devoluções, conciliação; o SUM daily perde dias com sync falho), então cada request mostra um total que é uma combinação diferente de empresas Adman + empresas DB-fallback."
test: "Confirmar leitura mista em DashboardController::adminDashboard linhas 96-189."
expecting: "Encontrar lógica if(\$entry['value'] !== null) usa cache, else fallback DB SUM."
next_action: "Aplicar fix: tornar a leitura determinística — escolher UMA fonte por card e por todo o conjunto de empresas."
```

## Evidence

- timestamp: 2026-05-26T15:00:00Z
  observation: "AdmanService::syncCompany usa AdmanMetric::updateOrCreate(['company_id' => ..., 'reference_date' => \$date], [...]) — não-destrutivo, idempotente, escopo de 1 dia (yesterday por padrão)."
  source: "app/Services/AdmanService.php:62-117 (especialmente linha 94)"
  refutes: "H2 (replace/upsert mal aplicado)"

- timestamp: 2026-05-26T15:00:30Z
  observation: "Migration de adman_metrics define UNIQUE(company_id, reference_date) — duplicação impossível. Sem coluna soft-deletes."
  source: "database/migrations/2026_04_26_152220_create_adman_metrics_table.php:29"
  refutes: "H2"

- timestamp: 2026-05-26T15:01:00Z
  observation: "AdmanService::syncAll usa Company::chunk(20, fn) com try/catch por empresa. Erro engolido (Log::error + continue). Sem rollback. Mas o write é updateOrCreate em SINGLE-row, não há linhas removidas em caso de falha — a empresa simplesmente fica sem o registro da data nova, mas conserva os dias anteriores."
  source: "app/Services/AdmanService.php:36-60"
  notes: "Confirma sync parcial silencioso, mas isso só causa 'falta de 1 dia em 30' para algumas empresas — não causa oscilação ±R$ 20M sozinho."

- timestamp: 2026-05-26T15:02:00Z
  observation: "DashboardController::adminDashboard computa total_revenue = array_sum(\$revenue30dByCompany) onde \$revenue30dByCompany[\$cid] = cache value se cache !== null, senão fallback SUM(adman_metrics.revenue) WHERE reference_date >= now-30d. As DUAS fontes são UNIDADES DIFERENTES."
  source: "app/Http/Controllers/DashboardController.php:96-130, 172"
  proves: "H5 e H1 combinadas"

- timestamp: 2026-05-26T15:02:30Z
  observation: "O cache de \$revenue30dByCompany é populado por RefreshGrossBillingCacheJob (cron 30min). Empresas onde Adman retornou erro (429/timeout/5xx) recebem ERROR_SENTINEL com TTL 10min — durante esses 10min, getCachedGrossBilling retorna null e o dashboard cai no fallback DB SUM para AQUELA empresa, enquanto outras empresas continuam exibindo o valor Adman."
  source: "app/Services/AdmanService.php:255-289, 393-437; app/Jobs/RefreshGrossBillingCacheJob.php:81-134"
  proves: "Mecanismo da oscilação"

- timestamp: 2026-05-26T15:03:00Z
  observation: "Commit f9056ea (25/05/2026) introduziu a mudança: 'card Faturamento + carteira de analistas: passa a usar array_sum(\$revenue30dByCompany) — soma dos grossBilling 30d EXATOS da Adman (via cache pre-aquecido) em vez de SUM(adman_metrics.revenue) daily do DB'. Antes dessa mudança, o card usava só o SUM DB (consistente entre runs, mesmo que 'errado' vs Adman). Depois passou a misturar."
  source: "git log f9056ea"
  proves: "Ponto de entrada da regressão; bate com 'apareceu após cadastro de todas as empresas' — antes havia poucas empresas, todas pequenas, com cache geralmente quente; com mais empresas + maiores, o rate limit da Adman cobra preço e ERROR_SENTINEL aparece com mais frequência."

- timestamp: 2026-05-26T15:03:30Z
  observation: "Adman /performance 30d e SUM(adman_metrics.revenue) 30d podem divergir SIGNIFICATIVAMENTE por empresa: (a) sync falha em alguns dias → SUM perde esses dias; (b) Adman aplica ajustes retroativos (devoluções, conciliação) que NÃO voltam ao adman_metrics local — o sync diário escreve só o dia novo, sem mexer no histórico. Para uma empresa grande (R$ 5M-15M/mês), a diferença entre 'Adman exato 30d' e 'SUM daily DB 30d' pode ser R$ 500k-2M facilmente. Com 10-15 empresas grandes flipando entre cache hit e DB fallback entre runs → oscilação cumulativa de R$ 20M é totalmente plausível."
  source: "Comentário no próprio código em AdmanService::fetchGrossBilling docblock (linhas 225-238)"
  proves: "Magnitude da oscilação"

- timestamp: 2026-05-26T15:04:00Z
  observation: "TACOS médio (avg_tacos) e total_ad_investment_30d são calculados APENAS a partir do cache /accounts/metrics, SEM fallback DB. Isso significa que empresas com cache cold/erro são IGNORADAS na média — mudando a composição do denominador entre requests. Adicionalmente: investimento total apenas SOMA empresas com cache quente — empresas em ERROR_SENTINEL contribuem ZERO. Próxima rodada (cache se recupera para 3 empresas) → investimento sobe; outra rodada (3 outras dão erro) → investimento cai."
  source: "app/Http/Controllers/DashboardController.php:176-189"
  proves: "Mecanismo da oscilação para TACOS médio e Investimento em Ads"

- timestamp: 2026-05-26T15:04:30Z
  observation: "Confirmado: scheduler roda 'adman:sync' a cada 5 minutos e RefreshGrossBillingCacheJob a cada 30 minutos. Os dois são INDEPENDENTES — 'adman:sync' não invalida nem aquece o cache do RefreshGrossBilling. Clicar 'sincronizar agora' em /dev/desenvolvimento NÃO atualiza o cache do dashboard (apenas escreve adman_metrics). A percepção 'oscila a cada sync' é coincidência com o cron de 30min do cache rodando em paralelo."
  source: "routes/console.php:13, 58-61"
  notes: "Importante para o user entender — o botão de sync atual NÃO afeta os cards 30d do dashboard."

## Eliminated

- H2 (replace/upsert mal aplicado): `updateOrCreate` por chave única; sem DELETE/TRUNCATE em adman_metrics anywhere no código.
- H4 (race entre workers): jobs únicos com `ShouldBeUnique`, schedule com `withoutOverlapping()`, write idempotente.
- Sync parcial sozinho explicar ±R$ 20M: refutado — sync parcial perde no máximo 1 dia por empresa por execução; impacto isolado pequeno. Combinado com hybrid read (H5), passa a explicar a magnitude.

## Resolution

### Root Cause

**O dashboard misturava duas fontes de dados estruturalmente diferentes na MESMA agregação.**

Para o card "Faturamento" (`total_revenue`), o código anterior decidia por empresa:

```php
foreach ($companies as $c) {
    $entry = $grossBatch30d[$c->adman_account_id] ?? ['value' => null, 'hasEntry' => false];
    if ($entry['value'] !== null) {
        $revenue30dByCompany[$c->id] = $entry['value'];            // FONTE A: Adman /performance 30d
    } else {
        $revenue30dByCompany[$c->id] = (float) ($sumDb30d[$c->id] ?? 0); // FONTE B: SUM(adman_metrics.revenue) 30d
    }
}
$totalRevenue = array_sum($revenue30dByCompany);
```

Cada empresa podia estar em A ou B dependendo de cache hit/miss, sucesso/erro da última chamada Adman e janela de data atual. Os valores A e B divergem em R$ 500k–R$ 2M por empresa grande (ajustes retroativos da Adman vs. sync diário daily). Com 10-15 empresas grandes flipando entre A e B → oscilação cumulativa de ±R$ 20M.

Para `total_ad_investment_30d` e `avg_tacos`: pior ainda — não havia fallback DB; empresas com cache cold/erro eram silenciosamente excluídas, fazendo o denominador variar entre requests.

### Fix Applied — Opção Híbrida tudo-ou-nada

**Política**: em uma única passagem, detecta se TODAS as empresas com `adman_account_id` têm valor real no cache `/performance` (e separadamente no `/accounts/metrics`). Se sim, usa valores EXATOS da Adman. Se QUALQUER empresa está em miss/erro, descarta o cache do conjunto inteiro e usa SUM agregado do `adman_metrics` local para TODAS as empresas — uma única query com `whereIn('company_id', ...)` no MESMO range do cache (`now->subDays(30)..now`).

Aplicada para `total_revenue`, `total_ad_investment_30d` e `avg_tacos`:

- `total_revenue`: `array_sum(revenue30dByCompany)` onde o array é populado 100% pelo cache OU 100% pelo SUM DB (sem mistura por empresa).
- `total_ad_investment_30d`: cache → soma de `metrics.investment` por empresa; fallback → `SUM(ad_spend)` em uma query agregada.
- `avg_tacos`: cache → média dos tacos por empresa; fallback → `(total_ad_investment_30d / total_revenue) * 100`.

A `userDashboard()` recebeu a mesma lógica para `revenue30dByCompany` e `tacos30dByCompany` (a tabela `companies` no User dashboard tinha o mesmo bug de mistura por empresa).

Decisão determinística por request, sem "tela vazia": quando o cache está incompleto, o usuário vê o melhor valor possível do DB local até o `RefreshGrossBillingCacheJob` (disparado em background quando detecta miss) aquecer o cache na próxima janela.

### Files Changed

- `app/Http/Controllers/DashboardController.php`
  - `adminDashboard()`: reescrita do bloco de cards 30d (linhas ~84-280). Detecta `$grossCacheCompleto` e `$accountCacheCompleto` em uma passagem; aplica tudo-ou-nada por card; mantém `companies_performance` e `userPortfolios` consumindo o `$revenue30dByCompany` final (sem mistura).
  - `userDashboard()`: mesma política aplicada ao `revenue30dByCompany` e `tacos30dByCompany` que alimentam a tabela `companies`.
  - Range das queries DB alterado de `where('reference_date', '>=', $dateFrom30d)` para `whereBetween('reference_date', [$dateFrom30d, $dateTo30d])` — bate exatamente com a chave do cache (`adman:gross_billing:{custId}:{dateFrom}:{dateTo}`).
  - Adicionado `use Illuminate\Support\Facades\Log` e `Log::info('[Dashboard] ... em fallback DB')` quando cair no fallback (visibilidade pra diagnóstico futuro).

### Files NOT Touched (escopo do orchestrator)

- `app/Jobs/RefreshGrossBillingCacheJob.php` — cron de cache permanece igual; outros consumidores podem precisar dele.
- `app/Services/AdmanService.php` — write path saudável conforme refutação de H2; cache read methods inalterados.
- `app/Http/Controllers/AdminController.php` e `app/Http/Controllers/CompanyController.php` — também usam padrão `$missingCache` por empresa, mas estão fora do escopo desta sessão; podem ter o mesmo bug em outros cards.
- `resources/js/Pages/Dashboard/Admin.jsx` e `User.jsx` — UI badge "fonte: Adman/DB" descartado conforme guidance "manter mudança mínima é o default".

### Verification

1. `php -l app/Http/Controllers/DashboardController.php` → No syntax errors detected.
2. `npm run build` → 0 erros JS; 16.47s para 80+ assets; `Dashboard-*.js` e `Admin-*.js` rebuildados.
3. Inspeção textual confirmou que `$revenue30dByCompany[$c->id]` é populado APENAS dentro de `if ($grossCacheCompleto) { ... } else { ... }` — sem caminho onde mistura por empresa.
4. Sem `DashboardTest.php` no `tests/Feature/` — comando `php artisan test --filter=Dashboard` skipado (sem testes a rodar).

### Verification Plan (runtime — pós-deploy)

1. Abrir dashboard, anotar `total_revenue`, `total_ad_investment_30d`, `avg_tacos`. Recarregar 3-5 vezes em sequência (sem aguardar cache job). Valores devem manter-se ESTÁVEIS dentro do mesmo modo (cache ou DB).
2. Forçar cache cold: `php artisan cache:clear`. Recarregar. Valores devem mostrar números do DB SUM (consistentes), não vazios.
3. Aguardar `RefreshGrossBillingCacheJob` (~2-3min para ~50 empresas). Recarregar. Valores podem "saltar" para os números exatos da Adman — esperado, e em bloco (todos os cards juntos), nunca fracionado.
4. Inspecionar `Log::info('[Dashboard] ... em fallback DB')` para identificar quantas requests caem no fallback ao longo do dia.

### Risks / Side-Effects

- Quando cache Adman está incompleto, totais usam DB local que pode estar atrás dos números exibidos na dashboard Adman (devido a ajustes retroativos não persistidos). Esse é o trade-off da Opção Híbrida — preferimos número estável ligeiramente "antigo" a número oscilante.
- Em deploy fresco com cache zerado, primeira janela mostra DB SUM; ao completar o warm-up (~2-3min), os valores podem dar um "salto" em bloco para os números Adman. Comportamento esperado e documentado.
