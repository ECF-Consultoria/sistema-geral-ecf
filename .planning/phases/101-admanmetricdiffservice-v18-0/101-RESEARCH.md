# Phase 101: AdmanMetricDiffService (v18.0) - Research

**Researched:** 2026-07-20
**Domain:** Integração com API Adman (diffs de período), persistência de métricas, arquitetura de serviços Laravel
**Confidence:** HIGH (achados-chave validados com chamadas reais à API Adman em produção, não suposição)

## Summary

O achado central desta pesquisa: **o payload real da Adman confirma exatamente o mapeamento do plano canônico, e uma verificação empírica prova que o `.diff`/`.prev` nativo da Adman usa "N dias imediatamente anteriores" como baseline — o que bate 1:1 com `MetricPeriodResolver::baselineJanelaMesmoTamanho()` (modos `official_bonus`/`closed_period`, `comparison_mode='previous_equal_length_window'`), mas NÃO bate com o modo operacional (`current_month`, `comparison_mode='same_interval_previous_month'`, que é alinhado por dia-do-mês-anterior, não por N-dias-antes)**. Isso muda a forma como `ADM-02` deve ser implementado: preferir `adman_diff` é seguro sempre que `comparison_mode==='previous_equal_length_window'`; no modo operacional, o diff nativo da Adman **não é semanticamente equivalente** ao baseline do resolver e não deve ser apresentado como `adman_diff` sem essa ressalva.

Segundo achado crítico: `percentageMargin` **não existe** no payload de `/performance/{custId}` (usado hoje por `AdmanService::syncCompany()` e gravado em `AdmanMetric.raw_data`) — ele só existe no payload de `/accounts/{custId}/metrics` (usado por `fetchAccountMetrics`/`fetchAccountMetricsCached`, que hoje descarta `.diff` e `.prev`, mantendo só `.value`). Ou seja, `AdmanMetricDiffService` precisa combinar **dois endpoints diferentes**: `profitMargin` (R$) e `revenue`/`grossBilling` vêm de `/performance`; `percentageMargin` (%) vem exclusivamente de `/accounts/metrics`. `raw_data` histórico nunca teve `percentageMargin` — o backfill (ADM-04) só pode recuperar diffs de `profitMargin`/`grossBilling`, e mesmo assim são diffs **diários** (dia vs dia anterior), não diffs de período — não confundir os dois.

Terceiro achado: `AdmanMetric` é fato **diário** (uma linha por `company_id`+`reference_date`); o diff de período não deve virar coluna nessa tabela (o próprio plano já alerta para isso em §987-993). A arquitetura correta é um serviço que chama a Adman **ao vivo** com o range do período resolvido (`current_start`/`current_end` do `MetricPeriodResolver`) e lê o `.diff` já pronto — replicando o padrão de cache já existente em `AdmanService` (`adman:gross_billing:...`, `adman:account_metrics:...`), nunca lendo do `AdmanMetric.raw_data` armazenado (que é sempre diff diário).

**Primary recommendation:** Criar `App\Services\Metrics\AdmanMetricDiffService` como serviço de leitura AO VIVO (não DB), reaproveitando `AdmanService::fetchPerformance()` (para `revenue`/`profitMargin`) e uma nova variante detalhada de `fetchAccountMetrics()` (para `percentageMargin`, preservando `.diff`/`.prev` sem quebrar os 5 consumidores atuais de `fetchAccountMetricsCached`). `diff_source='adman_diff'` só quando `comparison_mode==='previous_equal_length_window'` E a Adman devolveu `.diff`; caso contrário `diff_source='calculated_fallback'` reaproveitando a lógica de soma já existente em `DesempenhoScoreService::computeVarMargem`/`computeVarFaturamento` e `AdmanMetricsProvider`.

## User Constraints

Não há `CONTEXT.md` para esta fase (não passou por `/gsd:discuss-phase`). Sem decisões travadas de usuário — este research segue o plano canônico `plano-carteira-desempenho-multi-servico.md` e as REQs `ADM-01..05` de `REQUIREMENTS-v18.md` como fonte de verdade.

## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| ADM-01 | Ler `revenue`, `profitMargin.value/.diff`, `percentageMargin.value/.diff` da resposta/cache Adman | Confirmado via payload real: `revenue`/`profitMargin` em `/performance` (`summarizedData`); `percentageMargin` só em `/accounts/{custId}/metrics` (`metrics`). Ver "Payload real da Adman" abaixo. |
| ADM-02 | Preferir diff oficial (`diff_source='adman_diff'`); fallback só quando diff não existe | Achado crítico: gating adicional necessário por `comparison_mode` — ver "Risco arquitetural" abaixo. Sem esse gate, `adman_diff` seria semanticamente errado no modo operacional. |
| ADM-03 | Diff de período não vira fato diário; fato diário guarda valor do dia, snapshot de período guarda a comparação da janela | Confirmado: `AdmanMetric` é 1 linha/dia; arquitetura recomendada é serviço live-read parametrizado por período, não coluna nova na tabela diária. |
| ADM-04 | Backfill de `raw_data` antigo quando tiver `.diff` | Confirmado que `raw_data` (payload de `/performance` dia-único) tem `profitMargin.diff`/`grossBilling.diff` desde sempre (é gravado como veio da API) — mas são diffs **diários**, não de período. `percentageMargin` nunca esteve em `raw_data`. |
| ADM-05 | Labels sem ambiguidade: Margem R$ (`profitMargin`) distinta de Margem % (`percentageMargin`) | Confirmado nos dois payloads reais — são campos e endpoints fisicamente diferentes, valores de `.diff` também diferem ligeiramente (ver Pitfall 3). |

## Architectural Responsibility Map

| Capacidade | Tier primário | Tier secundário | Racional |
|------------|---------------|------------------|----------|
| Chamada HTTP à API Adman (`/performance`, `/accounts/metrics`) | API/Backend (`AdmanService`) | — | Já existe; `AdmanMetricDiffService` reusa, não duplica chamadas HTTP |
| Extração e normalização de diff/value/source | API/Backend (`AdmanMetricDiffService`, novo) | — | Camada fina de leitura, sem HTTP direto — delega a `AdmanService` |
| Cache de resposta por (custId, range, dia) | API/Backend (`Cache` facade, `database`/Redis conforme ambiente) | — | Reusa convenção de chave já usada por `fetchGrossBilling`/`fetchAccountMetricsCached` |
| Fato diário (histórico) | Database (`adman_metrics` table) | — | Não muda nesta fase; segue sendo grão diário |
| Consumo do diff por período (bônus, carteira) | API/Backend (Fases 102/103 — `DesempenhoScoreService`, `PortfolioController` equivalente) | — | Fora do escopo desta fase; só a interface precisa estar pronta |

## Payload real da Adman (verificado ao vivo em produção, 2026-07-20)

Testado com `Company::find(242)` (adman_account_id=1107394917, marketplace=meli), via script PHP standalone bootando o Laravel diretamente no VPS (`php artisan tinker` via stdin/`--execute` mostrou-se não-confiável neste ambiente — ver Pitfall 5).

### `/performance/{custId}` — `summarizedData` (usado por `AdmanService::fetchPerformance`, gravado em `AdmanMetric.raw_data`)

Range de 18 dias (`dateFrom=2026-07-01`, `dateTo=2026-07-18`):

```json
{
  "grossBilling":  { "value": 530797.73, "diff": 101.24, "prev": 263768.55 },
  "netBilling":    { "value": 514765.68, "diff": 117.34, "prev": 236847.86 },
  "canceledSales": { "value": 16032.05,  "diff": -40.45, "prev": 26920.69 },
  "salesFee":      { "value": 69138.37,  "diff": 104.93, "prev": 33738.31 },
  "investment":    { "value": 9990.82,   "diff": 81.96,  "prev": 5490.65 },
  "taxes":         { "value": 0,         "diff": 0,      "prev": 0 },
  "shippingCost":  { "value": 63687.12,  "diff": 166.28, "prev": 23916.93 },
  "productCost":   { "value": 173592.47, "diff": 149.12, "prev": 69681.79 },
  "returnCost":    { "value": 211.9,     "diff": -0.94,  "prev": 213.9 },
  "profitMargin":  { "value": 141428.81, "diff": 147.75, "prev": 57084.83 },
  "profitShare":   26.64
}
```

**`percentageMargin` NÃO existe neste endpoint** (confirmado em 2 chamadas distintas, dia-único e range de 18 dias). `profitShare` é escalar direto (não é `{value,diff,prev}`).

### `/accounts/{custId}/metrics` — `metrics` (usado por `AdmanService::fetchAccountMetrics`/`fetchAccountMetricsCached`)

Mesma empresa, mesmo range:

```json
{
  "billing":           { "value": 530797.73, "diff": 101.24, "prev": 263768.55 },
  "liquidMargin":       { "value": 141428.81, "diff": 147.96, "prev": 57036.05 },
  "percentageMargin":   { "value": 27.47,     "diff": 14.09,  "prev": 24.08 },
  "investment":         { "value": 9990.82,   "diff": 80.36,  "prev": 5539.43 },
  "acos": {...}, "tacos": {...}, "totalCosts": {...}, "soldQuantity": {...}
  // + averageTicket, income, costPerClick, clicks, unitSales, viewsContrib,
  //   salesContrib, billingContrib, conversion, returned — todos {value,diff,prev}
}
```

`fetchAccountMetricsCached()` hoje extrai **só** `.value` de `acos/tacos/investment/liquidMargin/percentageMargin/billing` via um closure `$val()` — descarta `.diff` e `.prev` totalmente (`app/Services/AdmanService.php:757-769`).

### Fórmula do `.diff` (confirmada empiricamente em 6+ campos de ambos endpoints)

```text
diff = (value - prev) / prev * 100
```

Sempre percentual de variação relativa — **inclusive para `percentageMargin.diff`**, que é a variação relativa do PONTO PERCENTUAL, não a diferença em pontos. Exemplo real: `percentageMargin.value=27.47`, `prev=24.08` → diferença em pontos = 3.39, mas `diff=14.09` = `(27.47-24.08)/24.08*100`. **Isso é uma pegadinha de UI (ADM-05): "margem % subiu 14,09%" não significa "subiu 14,09 pontos percentuais"** — é a taxa de crescimento da própria margem %.

### Achado crítico — janela do baseline (`prev`) é EXATAMENTE "N dias imediatamente anteriores"

Verificado com uma segunda chamada dedicada: `fetchPerformance(custId, '2026-06-13', '2026-06-30', ...)` (18 dias, terminando no dia anterior a `2026-07-01`) devolveu `grossBilling.value = 263768.55` — **valor idêntico, byte a byte**, ao `grossBilling.prev` da chamada original (`dateFrom=2026-07-01, dateTo=2026-07-18`). Isso prova que a Adman calcula `prev`/`diff` comparando com a janela de **mesmo tamanho imediatamente anterior a `dateFrom`** — exatamente a fórmula de `MetricPeriodResolver::baselineJanelaMesmoTamanho()` (`baseline_end = current_start - 1 dia`, `baseline_start = baseline_end - (days_count-1)`).

Também confirmado no caso de 1 dia: `raw_data` de `reference_date=2026-07-18` (sync diário, `dateFrom=dateTo='2026-07-18'`) tem `grossBilling.prev=39119.06` — que é o faturamento do dia `2026-07-17` (dia único imediatamente anterior), consistente com N=1.

## Risco arquitetural (o achado mais importante para o design de ADM-02)

`MetricPeriodResolver` tem **dois `comparison_mode` com semânticas de baseline diferentes**:

| `comparison_mode` | Modos que usam | Baseline | Bate com o `.diff` nativo da Adman? |
|---|---|---|---|
| `previous_equal_length_window` | `official_bonus` (bônus), `closed_period` (mês específico/custom) | N dias imediatamente antes de `current_start` | **SIM** — verificado empiricamente acima |
| `same_interval_previous_month` | `operational` (mês em curso) | Mesmo intervalo de dias no mês calendário anterior (alinhado por dia, com clamp) | **NÃO** — a Adman sempre usa "N dias antes", nunca "mesmo intervalo do mês anterior" |

Consequência prática: se `AdmanMetricDiffService` simplesmente chamar `fetchPerformance(custId, period.current_start, period.current_end)` e usar `.diff` como `adman_diff` **sem checar `period.comparison_mode`**, o resultado estará **correto no modo bônus/mês-fechado (Fase 102, `BON-02`/`BON-03` — o consumidor principal desta fase)**, mas **incorreto no modo operacional/mês-em-curso** (usado potencialmente pela Fase 103, carteira, em filtro "mês atual"). A recomendação é o serviço aceitar o objeto `periodo` inteiro (não só `current_start`/`current_end`) e só marcar `diff_source='adman_diff'` quando `periodo['comparison_mode'] === 'previous_equal_length_window'`; no modo operacional, sempre cair para `calculated_fallback` (2 chamadas de `.value` — atual e baseline — e diferença manual), já que o `.diff` nativo da Adman não é substituível ali.

## Standard Stack

Nenhuma biblioteca nova é necessária — a fase reusa 100% do stack existente (`Illuminate\Support\Facades\Http`, `Cache`, `Carbon`). **Package Legitimacy Audit: não aplicável — nenhum pacote externo novo é instalado nesta fase.**

## Architecture Patterns

### Onde persistir/retornar o diff (ADM-03)

Padrão recomendado, seguindo o próprio plano canônico (§980-985, "alternativa preferida"): **service de leitura ao vivo, não coluna nova em `adman_metrics`**.

```text
App\Services\Metrics\AdmanMetricDiffService::compute(Company $company, array $periodo): array
```

- Recebe o array `periodo` retornado por `MetricPeriodResolver::resolve()` inteiro (não só as datas) — precisa de `comparison_mode` para o gate acima.
- Chama `AdmanService::fetchPerformance($custId, $periodo['current_start'], $periodo['current_end'], ...)` para `revenue`/`profitMargin`.
- Chama uma nova variante detalhada de account-metrics (ver abaixo) para `percentageMargin`.
- Cacheia por `(custId, current_start, current_end, marketplace)` — reusando a convenção de `AdmanService` (`adman:diff:{marketplace}:{custId}:{from}:{to}:{dia}`, TTL 24h, mesma lógica de `ERROR_SENTINEL` fail-open).
- Retorna o shape sugerido pelo plano (§647-676):

```php
[
    'company_id' => $company->id,
    'period' => $periodo, // objeto inteiro do resolver, não recortado
    'metrics' => [
        'revenue' => ['value' => ..., 'diff_pct' => ..., 'diff_source' => 'adman_diff'|'calculated_fallback'],
        'contribution_margin_value' => [...], // profitMargin
        'contribution_margin_pct'   => [...], // percentageMargin
    ],
    'quality' => ['status' => 'complete|partial|missing', 'source' => 'adman', 'computed_at' => now()->toIso8601String()],
]
```

### Não quebrar consumidores existentes de `fetchAccountMetricsCached`

5 call-sites hoje dependem do shape simplificado `{acos, tacos, investment, liquid_margin, percentage_margin, billing}` (só floats): `CompanyController`, `PortfolioController`, `DashboardController`, `RefreshGrossBillingCacheJob`, e o próprio `AdmanService`. **Não alterar o retorno existente** — adicionar um método NOVO (`fetchAccountMetricsDetailedCached()` ou similar, nome exato é decisão do planner) que preserva `{value, diff, prev}` por campo, reusando a mesma chave de cache raiz (ou uma nova, para não colidir com o formato simplificado já cacheado).

### Reuso do "calculated_fallback" já existente

`DesempenhoScoreService::computeVarMargem()` (linhas 811-963) e `computeVarFaturamento()` já implementam a lógica de soma diária + recorte de dias comuns (fixes históricos: "fix Luiz" 2026-07-09, "audit LOJASINVAL+AVF2K" 2026-07-13 — ver `.planning/debug/resolved/audit-margem-*.md`). Essa lógica **é** o `calculated_fallback` — não precisa ser reescrita, só precisa ser reaproveitada/extraída para o novo service (ou chamada por ele) quando `diff_source` cair para `calculated_fallback`.

## Don't Hand-Roll

| Problema | Não construir | Usar em vez disso | Por quê |
|---|---|---|---|
| Cálculo de variação % de período | Fórmula manual nova | `AdmanMetricDiffService` lendo `.diff` nativo quando `comparison_mode` permitir | A Adman já faz o cálculo certo — reinventar introduz risco de divergência (é exatamente o bug que a Fase 101 corrige) |
| Fallback de variação quando Adman não tem diff | Nova lógica de soma | Reusar padrão de `DesempenhoScoreService::computeVarMargem`/`computeVarFaturamento` (recorte por dias comuns, guard de `margem_dias`) | Já existe, já tem 3 rounds de fixes de produção documentados |
| Leitura agregada de métricas Adman por período (sem diff) | Query solta em controller | `AdmanMetricsProvider::readForCompany()` (Phase 60, DB-only, soma diária) | Já é o padrão estabelecido para "valor" sem diff |

**Key insight:** Esta fase é fundamentalmente sobre **parar de recalcular manualmente algo que a fonte oficial já entrega pronto** — qualquer solução que reintroduza cálculo manual como caminho principal (em vez de fallback explícito) contraria o objetivo da fase.

## Common Pitfalls

### Pitfall 1: Confundir diff diário (raw_data histórico) com diff de período
**O que dá errado:** Backfill (ADM-04) tenta usar `raw_data.profitMargin.diff` de linhas antigas como se fosse a variação do período do bônus.
**Por que acontece:** `raw_data` é sempre resultado de `fetchPerformance($custId, $date, $date, ...)` — dia único. O `.diff` ali é sempre "esse dia vs o dia anterior", nunca "esse mês vs o anterior".
**Como evitar:** Backfill só preenche campos que representem explicitamente diff diário (se vierem a existir) ou é usado apenas para popular metadados de auditoria — nunca como substituto do diff de período computado ao vivo pelo `AdmanMetricDiffService`.
**Sinal de alerta:** Testes que comparam backfill de `raw_data` antigo contra o valor "oficial" de variação mensal do bônus vão divergir sistematicamente — se baterem por coincidência, é sinal de teste mal desenhado.

### Pitfall 2: Usar `adman_diff` no modo operacional sem checar `comparison_mode`
**O que dá errado:** Carteira (Fase 103) em filtro "mês atual" mostra uma variação de margem que não bate com o que qualquer humano calcularia manualmente (01/mês..hoje vs mesmo intervalo mês anterior).
**Por que acontece:** A Adman SEMPRE devolve `.diff` quando você pede um range — ele nunca "está ausente" tecnicamente, só está calculado com uma baseline diferente da que o resolver define para esse modo. `ADM-02` como está escrito ("só usa fallback quando diff não existe") não cobre esse caso textualmente.
**Como evitar:** Gate explícito por `periodo['comparison_mode'] === 'previous_equal_length_window'` antes de aceitar `adman_diff` — documentado na seção "Risco arquitetural" acima.
**Sinal de alerta:** Teste que verifica igualdade entre `adman_diff` e o cálculo manual de `same_interval_previous_month` vai falhar por desenho (baselines diferentes), a menos que o service já implemente o gate.

### Pitfall 3: Pequena divergência entre `profitMargin` (`/performance`) e `liquidMargin` (`/accounts/metrics`)
**O que dá errado:** `profitMargin.diff=147.75` vs `liquidMargin.diff=147.96` para a MESMA empresa/período (`value` bate exato: `141428.81` em ambos, mas `prev` diverge: `57084.83` vs `57036.05`).
**Por que acontece:** São dois endpoints/cálculos internos distintos da Adman — não há garantia de reconciliação perfeita entre eles.
**Como evitar:** Seguir o mapeamento do plano à risca (ADM-05): Margem R$ SEMPRE de `profitMargin` (`/performance`), Margem % SEMPRE de `percentageMargin` (`/accounts/metrics`) — nunca misturar `liquidMargin` como substituto de `profitMargin` ou vice-versa, mesmo que os valores "quase" batam.
**Sinal de alerta:** Se um teste fixture usa `liquidMargin.diff` para validar `contribution_margin_value.diff_pct`, ele está testando o endpoint errado.

### Pitfall 4: Migrations cross-driver (SQLite testes vs MariaDB produção)
**O que dá errado:** Se o planner decidir criar colunas novas em `adman_metrics` (a alternativa não-preferida do plano) e usar `enum`/índice/FK sem branch por driver, os testes locais (SQLite) passam mas o deploy quebra no VPS (MariaDB).
**Por que acontece:** Padrão recorrente já documentado no projeto (memória: `project_mysql_nullondelete_nullable`, `project_mysql_drop_index_fk`, `project_enum_setor_sqlite_check`).
**Como evitar:** Preferir a arquitetura de service (sem migration) descrita acima. Se o planner ainda assim decidir por colunas novas, usar `decimal` nullable simples (sem CHECK/enum) — não há necessidade de enum aqui.
**Sinal de alerta:** Qualquer migration desta fase que toque `adman_metrics` deve ser validada explicitamente contra MariaDB no VPS antes do deploy (não confiar só em SQLite local).

### Pitfall 5: `php artisan tinker` via stdin/`--execute` não é confiável neste ambiente para investigação
**O que dá errado:** Scripts multi-statement enviados via stdin (`plink ... "php artisan tinker" < script.php`) ou via `--execute`/`include` só produzem o dump da primeira statement (ou ecoam o código-fonte de volta sem executar) — comportamento do PsySH não-interativo sobre pty do plink neste setup.
**Como evitar (para futuras fases de research/debug):** Fazer upload do script via `pscp` e rodar como PHP standalone bootando o Laravel diretamente (`require vendor/autoload.php` + `bootstrap/app.php` + `$kernel->bootstrap()`), depois `php script.php` — funciona de forma confiável e foi o método usado para todos os achados desta pesquisa.

### Pitfall 6 (contexto do projeto): não tocar módulo NPS em paralelo
Há sessão paralela ativa no módulo NPS (ver git status: `78-RESEARCH.md`, `79-VERIFICATION.md` soltos). Esta fase não tem overlap de arquivos com NPS (`app/Services/Metrics/*`, `app/Services/AdmanService.php`, `app/Models/AdmanMetric.php` vs `app/Models/Nps*`) — risco baixo, mas evitar tocar qualquer arquivo `Nps*`/`nps_*` fora do escopo.

## Code Examples

### Cache-key convention a reusar (fonte: `AdmanService.php`)
```php
// Fonte: app/Services/AdmanService.php:379-384 (fetchGrossBilling) e :742-752 (fetchAccountMetricsCached)
$cacheKey = "adman:gross_billing:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:" . $this->cacheDay();
// cacheDay() = data atual em BRT — chave se auto-invalida ao virar o dia (API Adman é D-1)
```

### Extração de `.diff` preservando `prev` (padrão a seguir, hoje só existe para `.value`)
```php
// Fonte: app/Services/AdmanService.php:757-760 (fetchAccountMetricsCached) — hoje só extrai value
$val = fn(string $key): ?float => isset($data['metrics'][$key]['value'])
    ? (float) $data['metrics'][$key]['value']
    : null;

// Variante detalhada necessária (ADM-01), preservando diff/prev:
$detalhado = fn(string $key): ?array => isset($data['metrics'][$key])
    ? [
        'value' => $data['metrics'][$key]['value'] ?? null,
        'diff'  => $data['metrics'][$key]['diff']  ?? null,
        'prev'  => $data['metrics'][$key]['prev']  ?? null,
      ]
    : null;
```

### Teste com `Http::fake` (padrão já estabelecido — fonte: `tests/Feature/Phase18_5/AdmanServiceMarketplaceTest.php`)
```php
Http::fake([
    '*' => Http::response([
        'summarizedData' => [
            'grossBilling' => ['value' => 100.0, 'diff' => 10.0, 'prev' => 90.0],
            'profitMargin' => ['value' => 25.0,  'diff' => -5.0, 'prev' => 26.3],
        ],
    ], 200),
]);
```

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|---|---|---|
| A1 | O nome exato do novo método detalhado (`fetchAccountMetricsDetailedCached` ou outro) é decisão do planner, não travada por este research | Architecture Patterns | Baixo — é só nomenclatura, não afeta comportamento |
| A2 | O baseline de "N dias imediatamente anteriores" foi confirmado para 1 empresa (id 242) e 2 janelas (1 dia e 18 dias); não foi testado para todas as combinações de marketplace (ex.: Shopee) nem para janelas muito longas (ex.: 30+ dias de mês fechado) | Achado crítico / Risco arquitetural | Médio — se a Adman tiver comportamento diferente por marketplace ou por tamanho de janela, o gate por `comparison_mode` continua sendo a proteção correta, mas a suposição de "sempre bate" merece 1 teste adicional em produção antes do BON-02 (mês fechado real, 30 dias) na Fase 102 |
| A3 | `profitShare` (escalar, não `{value,diff,prev}`) não é usado por nenhuma REQ desta fase — só documentado por completude do payload | Payload real | Nenhum — campo fora de escopo |

## Open Questions (RESOLVED)

> Ambas resolvidas no planejamento da Fase 101 — os plans (101-01) seguem as recomendações: `compute(Company $company, ...)` e TTL 24h com chave por dia.

1. **(RESOLVED — aceita `Company`)** **O `AdmanMetricDiffService` deve aceitar `Company` ou `custId`+`marketplace` diretamente?**
   - O que sabemos: `AdmanService` internamente sempre resolve `custId = $company->adman_account_id ?: $company->ml_store_id` e `marketplace = $company->marketplace ?? 'meli'`.
   - O que é incerto: se o novo service deve replicar essa resolução ou recebê-la já resolvida (mais testável, mas mais boilerplate no caller).
   - Recomendação: aceitar `Company` (consistente com `AdmanMetricsProvider::readForCompany(Company $company, ...)`, o padrão já estabelecido na Fase 60).

2. **(RESOLVED — TTL 24h, chave por dia)** **Cache do diff de período deve ter TTL de 24h (como o resto) ou mais curto?**
   - O que sabemos: a Adman é D-1 (publica 1x/dia às 10h BRT) — TTL 24h com chave por dia é o padrão de todo o resto do `AdmanService`.
   - O que é incerto: se o consumo pela Fase 102 (`desempenho:consolidar-mes`, comando mensal) precisa de TTL diferente.
   - Recomendação: seguir o padrão de 24h; a Fase 102 pode decidir bypass/`forceRefresh` se precisar de dado fresco no momento da consolidação mensal.

## Validation Architecture

### Test Framework
| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (testsuites `Unit` e `Feature`, diretórios `tests/Unit`/`tests/Feature`) |
| Quick run command | `C:\xampp\php\php.exe artisan test --filter=AdmanMetricDiffServiceTest` |
| Full suite command | `C:\xampp\php\php.exe artisan test` |

### Phase Requirements → Test Map
| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|--------|----------------|----------------|------------------------|------------------|
| ADM-01 | Lê `revenue`/`profitMargin.value/.diff` de `/performance` e `percentageMargin.value/.diff` de `/accounts/metrics` | Feature (`Http::fake`) | `php artisan test --filter=test_le_revenue_profitmargin_e_percentagemargin` | ❌ Wave 0 |
| ADM-02 | `diff_source='adman_diff'` só quando `comparison_mode='previous_equal_length_window'` E Adman devolveu diff; fallback caso contrário | Feature (`Http::fake` + `MetricPeriodResolver` real) | `php artisan test --filter=test_prefere_adman_diff_janela_igual` / `test_fallback_calculado_modo_operacional` | ❌ Wave 0 |
| ADM-03 | Diff de período não persiste como coluna nova em `adman_metrics`; service é live-read | Feature/estrutural (grep de migration + assert de shape de retorno) | `php artisan test --filter=AdmanMetricDiffServiceTest` | ❌ Wave 0 |
| ADM-04 | Backfill de `raw_data` antigo preenche campos quando `.diff` existir, deixa null quando não | Unit/Feature (fixture com `raw_data` real capturado nesta pesquisa) | `php artisan test --filter=test_backfill_raw_data_antigo` | ❌ Wave 0 |
| ADM-05 | Labels de Margem R$ e Margem % nunca se misturam | Feature (assert de chaves distintas no array de retorno) | `php artisan test --filter=test_labels_margem_rs_e_pct_distintos` | ❌ Wave 0 |

### Sampling Rate
- **Por commit de task:** `php artisan test --filter=AdmanMetricDiffServiceTest`
- **Por merge de wave:** `php artisan test --testsuite=Feature`
- **Gate de fase:** suite completa verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase101/AdmanMetricDiffServiceTest.php` — cobre ADM-01, ADM-02, ADM-03, ADM-05 (usar os payloads reais capturados nesta pesquisa como fixtures `Http::fake`)
- [ ] `tests/Unit/Phase101/AdmanMetricDiffBackfillTest.php` (ou Feature, conforme decisão do planner) — cobre ADM-04, usando o `raw_data` real de `AdmanMetric#6937` (company_id=242, reference_date=2026-07-18) capturado nesta pesquisa como fixture
- [ ] Nenhuma dependência de framework nova — PHPUnit já instalado e configurado

## Security Domain

### Categorias ASVS aplicáveis

| Categoria ASVS | Aplica | Controle padrão |
|---|---|---|
| V5 Input Validation | Sim | Payload da Adman é externo — todo acesso a campos deve usar `??`/`isset()` (já é o padrão em `AdmanService`), nunca assumir chave presente. `AdmanMetricDiffService` deve seguir o mesmo padrão defensivo. |
| V12 API and Web Service | Sim | Chamada outbound autenticada via header `integrator-api-key` (`AdmanService::headers()`) — já existe, nenhuma mudança de credencial nesta fase. Rate limit (10 req/min) já respeitado via throttle existente; novo service deve reusar as chamadas de `AdmanService`, não abrir conexões HTTP paralelas. |
| V6 Cryptography | Não | Nenhuma criptografia nova nesta fase |
| V2/V3/V4 (Auth/Session/Access Control) | Não | Service backend puro, sem superfície de auth nova |

### Padrões de ameaça conhecidos para este stack

| Padrão | STRIDE | Mitigação padrão |
|---|---|---|
| Resposta malformada/parcial da Adman (campo ausente, tipo inesperado) causando erro 500 | Denial of Service (parcial) | `??`/`isset()` em toda leitura de campo aninhado, fail-open retornando `null`/`diff_source=null` em vez de exceção (padrão já usado em `fetchGrossBilling`/`fetchAccountMetricsCached` com `ERROR_SENTINEL`) |
| Vazamento de `integrator-api-key` em logs | Information Disclosure | Já mitigado — `AdmanService::headers()` não loga o header; manter esse padrão em qualquer log novo do `AdmanMetricDiffService` |

## Sources

### Primary (HIGH confidence — verificado ao vivo nesta sessão)
- Chamadas reais a `/performance/{custId}` e `/accounts/{custId}/metrics` via `AdmanService` em produção (VPS `177.7.53.164`, empresa `CARAIBAALUMINIO alumen`, id 242) — 3 chamadas distintas, 2026-07-20
- `app/Services/AdmanService.php` (leitura integral)
- `app/Models/AdmanMetric.php` (leitura integral)
- `app/Services/Metrics/MetricPeriodResolver.php` (leitura integral — Fase 100, já implementada)
- `app/Services/Metrics/AdmanMetricsProvider.php` e `UnifiedMetricsDto.php` (Fase 60)
- `app/Services/DesempenhoScoreService.php` (`computeVarMargem`/`computeVarFaturamento`, linhas 651-963)
- `tests/Feature/Phase18_5/AdmanServiceMarketplaceTest.php` (padrão de teste `Http::fake`)
- `plano-carteira-desempenho-multi-servico.md` §232-305, §628-701, §960-1000, §1195-1208
- `.planning/REQUIREMENTS-v18.md`, `.planning/ROADMAP.md` (Fase 101)

### Secondary (MEDIUM confidence)
- Nenhuma — toda a informação técnica desta fase foi verificável diretamente no código/produção, sem necessidade de fontes externas

### Tertiary (LOW confidence)
- Nenhuma

## Metadata

**Confidence breakdown:**
- Formato do payload Adman: HIGH — verificado com 3 chamadas reais à API em produção
- Semântica do baseline (`prev`=N-dias-antes): HIGH — provado com chamada dedicada confirmando valor idêntico
- Onde persistir o diff: HIGH — decisão já indicada pelo próprio plano canônico, confirmada pela investigação da estrutura de `AdmanMetric` (fato diário)
- Risco de gate por `comparison_mode`: HIGH (lógica) / MEDIUM (empírico — testado só para 1 empresa/2 janelas, ver Assumption A2)

**Research date:** 2026-07-20
**Valid until:** A Adman pode mudar o payload sem aviso (é API de terceiro) — revalidar formato se `ADM-01` começar a falhar em produção após deploy. Estimativa: 30 dias (API estável historicamente, sem changelog público conhecido).
