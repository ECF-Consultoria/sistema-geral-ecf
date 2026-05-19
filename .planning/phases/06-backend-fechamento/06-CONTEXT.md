# Phase 6: Backend Fechamento - Context

**Gathered:** 2026-05-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Implementar a camada de backend que calcula o faturamento mensal por empresa a partir dos dados sincronizados em `adman_metrics`, determina a faixa de investimento e o valor mensal a cobrar via função `calcularFaixa()`, e entrega esses dados como props Inertia via `AdminController::fechamento()`.

Escopo estrito: FCH-04 (faturamento + período coberto) e FCH-05 (faixa + valor mensal). A exibição (barras de progresso, total consolidado, estado visual) fica para a Phase 7 — esta fase apenas garante que as props chegam corretas ao frontend.

</domain>

<decisions>
## Implementation Decisions

### Cálculo de faturamento

- **D-01:** Faturamento = `SUM(adman_metrics.revenue)` GROUP BY `company_id` para o mês corrente (Carbon::now()->startOfMonth() até Carbon::now()) — sem chamada HTTP à API Adman.
- **D-02:** "Mês corrente" = do primeiro dia do mês atual até hoje (não mês cheio) — permite exibir dados parciais do mês em curso.
- **D-03:** Período coberto calculado a partir de `MIN(reference_date)` e `MAX(reference_date)` dos registros da empresa no mês corrente — exibido como "01/05 a 18/05".
- **D-04:** Empresa sem nenhum registro em `adman_metrics` no mês corrente → estado `sem_dados`. Não entra no total consolidado da Phase 7.

### Estados de empresa

- **D-05:** 3 estados mutuamente exclusivos por empresa:
  - `sem_integracao`: `has_adman === false` (sem `adman_account_id`) — empresa não integrada
  - `sem_dados`: integrada mas sem registros em adman_metrics no mês corrente
  - `ok`: integrada com dados no mês corrente
- **D-06:** Apenas empresas com estado `ok` entram no total consolidado (Phase 7).

### Tabela de progressão (constante no backend)

- **D-07:** A tabela de faixas é implementada como array PHP constante na classe onde `calcularFaixa()` é definida — não editável via UI neste milestone (cf. `faturamento_adm.md`).
- **D-08:** Faixas (faturamento → investimento mensal):
  - Até R$ 499.999,99 → R$ 3.000,00
  - De R$ 500.000,00 até R$ 999.999,99 → R$ 4.500,00
  - De R$ 1.000.000,00 até R$ 1.999.999,99 → R$ 6.000,00
  - De R$ 2.000.000,00 até R$ 2.999.999,99 → R$ 7.500,00
  - De R$ 3.000.000,00 até R$ 3.999.999,99 → R$ 9.000,00
  - De R$ 4.000.000,00 até R$ 4.999.999,99 → R$ 10.500,00
  - Acima de R$ 5.000.000,00 → R$ 12.000,00 (faixa máxima)
- **D-09:** Faixa máxima: faturamento > R$ 4.999.999,99 → retorna `['faixa' => 'maxima', 'valor' => 12000.00]`. A Phase 7 trata esse caso exibindo "Faixa máxima" sem barra de progresso.

### Localização da lógica

- **D-10:** `calcularFaixa()` pode ser um método privado no `AdminController` (sem Service separado) — a lógica é simples o suficiente (lookup table + match expression). Se crescer na Phase 7, pode-se extrair para `AdminFinanceiroService`.
- **D-11:** A query de aggregation é executada no `AdminController::fechamento()` — não em um Job ou cache. O tempo de resposta é aceitável (query sobre registros já sincronizados no banco).

### Props entregues ao frontend

- **D-12:** O array `companies` entregue pelo controller ADICIONA os seguintes campos aos já existentes na Phase 5:
  - `faturamento`: float|null — SUM(revenue) do mês corrente, null se sem_dados
  - `periodo_inicio`: string|null — MIN(reference_date) formatado como 'd/m', null se sem_dados
  - `periodo_fim`: string|null — MAX(reference_date) formatado como 'd/m', null se sem_dados
  - `faixa`: string|null — label da faixa ('ate_499k', '500k_999k', ..., 'maxima'), null se sem_dados
  - `valor_mensal`: float|null — investimento mensal calculado, null se sem_dados
  - `estado`: 'sem_integracao' | 'sem_dados' | 'ok'

### Rota existente

- **D-13:** A rota GET `/administrativo/financeiro` → `AdminController@fechamento` já existe da Phase 5 — nenhuma mudança de rota nesta fase. Apenas o método `fechamento()` é expandido.

### Claude's Discretion

- Estrutura interna da query Eloquent (subquery vs. join vs. eager load + collection groupBy)
- Formato exato do label de faixa (campo `faixa` nas props)
- Tratamento de revenue null nos registros (somar ou ignorar)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Backend existente
- `app/Http/Controllers/AdminController.php` — controller alvo; método `fechamento()` existente
- `app/Models/AdmanMetric.php` — model com campo `revenue` (decimal 15,2) e `reference_date` (date), FK `company_id`
- `app/Models/Company.php` — model Company com os 4 novos campos da Phase 5
- `routes/web.php` — grupo admin (linhas ~235-240); rota GET já registrada

### Dados de referência
- `faturamento_adm.md` (raiz do projeto) — tabela de faixas de investimento com valores exatos

### Testes existentes
- `tests/Feature/AdminFechamentoControllerTest.php` — 8 testes GREEN da Phase 5; NOVO plano deve adicionar testes sem quebrar os existentes
- `tests/Unit/CompanyServiceTypeTest.php` — referência de unit test

### Requisitos
- `.planning/REQUIREMENTS.md` — FCH-04, FCH-05 (escopo desta fase)
- `.planning/ROADMAP.md` — Phase 6 Success Criteria (5 critérios verificáveis)

</canonical_refs>

<code_context>
## Existing Code Insights

### AdminController::fechamento() — estado pós-Phase 5
```php
public function fechamento()
{
    $companies = Company::where('active', true)
        ->orderBy('name')
        ->get()
        ->map(fn (Company $c) => [
            'id'                 => $c->id,
            'name'               => $c->name,
            'service_type'       => $c->service_type,
            'contract_start'     => $c->contract_start?->toDateString(),
            'contract_end'       => $c->contract_end?->toDateString(),
            'additional_service' => $c->additional_service,
            'has_adman'          => (bool) $c->adman_account_id,
        ]);

    return Inertia::render('Admin/Financeiro', compact('companies'));
}
```
Esta implementação precisa ser expandida para incluir os dados de faturamento e faixa.

### AdmanMetric schema relevante
- `company_id` (FK) — chave de agrupamento
- `reference_date` (date) — para filtrar pelo mês corrente e calcular período coberto
- `revenue` (decimal 15,2) — campo somado para faturamento mensal

### Padrão de aggregation com Carbon (padrão usado no DashboardController)
```php
$inicio = Carbon::now()->startOfMonth();
$fim    = Carbon::now();
AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
    ->selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as inicio, MAX(reference_date) as fim')
    ->groupBy('company_id')
    ->get()
    ->keyBy('company_id');
```

### Formato de datas no período coberto
- "01/05 a 18/05" — usando `format('d/m')` do Carbon
- Exibido apenas para empresas com estado `ok`

</code_context>

<specifics>
## Specific Ideas

- A query de aggregation pode ser feita em uma única query SELECT com SUM, MIN, MAX agrupado por company_id — eficiente e sem N+1.
- `calcularFaixa()` como método privado no AdminController — retorna array `['faixa' => string, 'valor' => float]` ou null.
- Usar `keyBy('company_id')` na collection de métricas para fazer lookup O(1) no map() das companies.
- Revenue null em `adman_metrics`: somar apenas registros onde `revenue IS NOT NULL` para evitar distorção.

</specifics>

<deferred>
## Deferred to Phase 7

- Exibição de barras de progresso
- Total consolidado visível na UI
- Estado visual das empresas (cores, badges de faixa)
- Campo de serviço adicional na UI

</deferred>

---

*Phase: 6-Backend Fechamento*
*Context gathered: 2026-05-19*
