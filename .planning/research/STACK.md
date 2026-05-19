# Technology Stack — Módulo Fechamento (v2.0 Administrativo)

**Projeto:** ECF Admin — Módulo Administrativo / Fechamento Mensal
**Pesquisado:** 2026-05-19
**Confiança geral:** HIGH (baseado em análise direta do código existente)

---

## Contexto: O que já existe e não muda

O stack base está validado e bloqueado por constraint do projeto:

- Laravel 12 + Eloquent ORM
- Inertia.js v2 (sem API layer separado)
- React 18 + Tailwind (tokens `ecf-*`, dark theme)
- `AdmanService` com `fetchPerformance()` já retornando `grossBilling` (faturamento bruto)
- `adman_metrics` table com coluna `revenue` (`decimal(15,2)`) e chave única `(company_id, reference_date)`
- `Company` model sem nenhum campo de tipo de serviço, data de contrato, ou faturamento agregado

---

## Decisões de Stack Necessárias

### 1. Modelagem de dados contratuais em Company

**Decisão: Adicionar colunas diretamente na tabela `companies` via migration.**

Não criar tabela separada `company_contracts`.

**Por quê colunas diretas ganham:**

A tabela separada seria justificada se houvesse histórico temporal de contratos (empresa muda de tipo, guarda versões antigas) ou se múltiplos contratos coexistissem. Nenhum desses casos faz parte do escopo atual ou dos requisitos declarados no PROJECT.md. Para o módulo de fechamento, cada empresa tem exatamente um tipo de serviço e uma data de início de contrato. Junção extra sem benefício é custo puro.

**Campos a adicionar em `companies`:**

```php
// migration: add_billing_fields_to_companies_table
$table->string('tipo_servico', 50)->nullable();
// Valores: 'polo', 'assessoria', 'incubadora'
// null = empresa sem contrato ativo no fechamento

$table->date('contrato_inicio')->nullable();
// Data de início do contrato — usada para filtrar empresas elegíveis
// no fechamento e para exibir "tempo de cliente"

$table->decimal('servico_adicional', 10, 2)->nullable();
// Campo reservado conforme ADM-06 — visível, sem lógica de valor neste milestone
// Tipo decimal porque futuramente pode conter um valor monetário adicional
```

Adicionar ao `$fillable` e `$casts` do `Company` model:

```php
// Em $fillable, adicionar:
'tipo_servico', 'contrato_inicio', 'servico_adicional',

// Em $casts, adicionar:
'contrato_inicio'   => 'date',
'servico_adicional' => 'decimal:2',
```

**Constante no model:**

```php
// Em Company.php
public const TIPOS_SERVICO = ['polo', 'assessoria', 'incubadora'];

public const TIPO_LABELS = [
    'polo'        => 'POLO',
    'assessoria'  => 'Assessoria',
    'incubadora'  => 'Incubadora',
];
```

Espelhado no JSX da página como objeto literal (convenção do projeto — sem enum compartilhado entre PHP e JS).

---

### 2. Armazenamento de faturamento mensal por empresa

**Decisão: Agregar `adman_metrics.revenue` em runtime via query SQL; não criar tabela de cache dedicada neste milestone.**

**Por quê:**

A tabela `adman_metrics` já possui `(company_id, reference_date, revenue)` com índice único. O faturamento mensal é simplesmente `SUM(revenue) WHERE company_id = ? AND reference_date BETWEEN primeiro e último dia do mês`. Essa query com índice existente em produção com dezenas de empresas leva < 5ms. Criar uma tabela `fechamentos_mensais` seria prematura — adiciona complexidade de manutenção de cache sem ganho de performance perceptível no volume atual.

**Quando reconsiderar:** Se o módulo de fechamento precisar de histórico de fechamentos auditados (snapshot imutável do valor cobrado em cada mês após aprovação), aí sim uma tabela `fechamentos` faz sentido. Isso é candidato para um milestone futuro, não agora.

**Pattern de query:**

```php
// Em FechamentoService ou método do controller
$mes = now()->startOfMonth();
$faturamento = AdmanMetric::query()
    ->where('company_id', $company->id)
    ->whereBetween('reference_date', [
        $mes->toDateString(),
        $mes->copy()->endOfMonth()->toDateString(),
    ])
    ->sum('revenue');
```

Executar isso em collection via `map()` para todas as empresas de uma vez:

```php
// Busca todos os registros do mês em uma query, agrupa em PHP
$metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
    ->select('company_id', DB::raw('SUM(revenue) as faturamento_mensal'))
    ->groupBy('company_id')
    ->pluck('faturamento_mensal', 'company_id');
// Resultado: Collection<company_id => faturamento_mensal>
```

Isso é **uma query**, não N queries. Requer `use Illuminate\Support\Facades\DB;` no controller/service.

**Cache de curto prazo (opcional mas recomendado):**

O painel de fechamento pode ser acessado várias vezes por dia pelo admin. A query de agregação é leve, mas pode-se adicionar cache de 15 minutos usando o driver `database` já configurado:

```php
$chave = 'fechamento_mensal_' . now()->format('Y_m');
$dados = Cache::remember($chave, now()->addMinutes(15), function () use ($inicio, $fim) {
    return AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
        ->select('company_id', DB::raw('SUM(revenue) as faturamento_mensal'))
        ->groupBy('company_id')
        ->pluck('faturamento_mensal', 'company_id');
});
```

Cache de 15 minutos é suficiente — o sync Adman roda diariamente e os dados do mês corrente não mudam a cada segundo.

---

### 3. Cálculo de faixa de investimento

**Decisão: PHP puro — método estático no model ou service dedicado. Sem biblioteca externa.**

A tabela de 7 faixas é uma lookup table simples. Nenhuma biblioteca PHP de billing/pricing faz sentido aqui — todas assumem planos recorrentes, webhooks de pagamento, assinaturas. O problema é uma função `faturamento → faixa` que cabe em 20 linhas.

**Implementação recomendada — constante + método estático em `Company` (ou service):**

```php
// Em Company.php (ou FechamentoService.php se preferir separar)
public const FAIXAS_INVESTIMENTO = [
    ['min' => 0,         'max' => 499_999.99,  'valor' => 3_000.00,  'label' => 'Até R$499.999'],
    ['min' => 500_000,   'max' => 999_999.99,  'valor' => 4_500.00,  'label' => 'R$500k–R$999k'],
    ['min' => 1_000_000, 'max' => 1_999_999.99,'valor' => 6_000.00,  'label' => 'R$1M–R$1,99M'],
    ['min' => 2_000_000, 'max' => 2_999_999.99,'valor' => 7_500.00,  'label' => 'R$2M–R$2,99M'],
    ['min' => 3_000_000, 'max' => 3_999_999.99,'valor' => 9_000.00,  'label' => 'R$3M–R$3,99M'],
    ['min' => 4_000_000, 'max' => 4_999_999.99,'valor' => 10_500.00, 'label' => 'R$4M–R$4,99M'],
    ['min' => 5_000_000, 'max' => PHP_INT_MAX,  'valor' => 12_000.00, 'label' => 'Acima R$5M'],
];

public static function calcularFaixa(float $faturamento): array
{
    foreach (self::FAIXAS_INVESTIMENTO as $i => $faixa) {
        if ($faturamento <= $faixa['max']) {
            $proximaFaixa = self::FAIXAS_INVESTIMENTO[$i + 1] ?? null;
            $progressoPct = $faixa['max'] === PHP_INT_MAX ? 100
                : round((($faturamento - $faixa['min']) / ($faixa['max'] - $faixa['min'])) * 100, 1);
            return [
                'faixa_atual'     => $faixa,
                'faixa_index'     => $i,
                'proxima_faixa'   => $proximaFaixa,
                'distancia_proxima' => $proximaFaixa ? max(0, $proximaFaixa['min'] - $faturamento) : null,
                'progresso_pct'   => $progressoPct,
            ];
        }
    }
    // Fallback: última faixa (não deve ocorrer com PHP_INT_MAX)
    return ['faixa_atual' => last(self::FAIXAS_INVESTIMENTO), 'faixa_index' => 6,
            'proxima_faixa' => null, 'distancia_proxima' => null, 'progresso_pct' => 100];
}
```

O mesmo objeto espelhado em JS no arquivo da página para renderização do frontend (sem roundtrip extra).

---

### 4. Exposição de dados via Inertia props

**Decisão: Controller monta array completo com faturamento + faixa calculada, passa via `Inertia::render()`.**

Padrão já consolidado no projeto (ver `DevController::index()`). Sem endpoint JSON separado, sem SWR, sem React Query.

**Shape do prop `empresas` para a página:**

```php
// AdministrativoController::index()
Inertia::render('Administrativo/Fechamento', [
    'mes_referencia' => now()->format('Y-m'),
    'empresas' => $empresas->map(fn(Company $c) => [
        'id'                => $c->id,
        'name'              => $c->name,
        'tipo_servico'      => $c->tipo_servico,
        'contrato_inicio'   => $c->contrato_inicio?->toDateString(),
        'servico_adicional' => $c->servico_adicional,
        'faturamento'       => (float) ($metricas[$c->id] ?? 0),
        'faixa'             => Company::calcularFaixa((float) ($metricas[$c->id] ?? 0)),
        'tem_adman'         => (bool) $c->adman_account_id,
    ]),
    'total_a_cobrar' => $empresas->sum(fn($c) =>
        Company::calcularFaixa((float) ($metricas[$c->id] ?? 0))['faixa_atual']['valor']
    ),
]);
```

---

### 5. Progress bar no frontend

**Decisão: Tailwind CSS puro — `<div>` com `width` dinâmico em style prop. Sem biblioteca de charting.**

O projeto já tem `recharts` instalado, mas é overkill para uma barra de progresso simples. Uma barra de progresso com posição percentual é CSS trivial:

```jsx
// Barra de progresso — zero dependências extras
const FaixaProgressBar = ({ progressoPct, faixaLabel, proximaFaixa }) => (
    <div className="space-y-1">
        <div className="flex justify-between text-xs text-white/50">
            <span>{faixaLabel}</span>
            {proximaFaixa && <span>Próxima: {proximaFaixa.label}</span>}
        </div>
        <div className="h-2 rounded-full bg-white/[0.08] overflow-hidden">
            <div
                className="h-full rounded-full bg-ecf-yellow transition-all"
                style={{ width: `${Math.min(progressoPct, 100)}%` }}
            />
        </div>
        <div className="text-xs text-white/40">
            {progressoPct}% da faixa atual
        </div>
    </div>
);
```

O token `bg-ecf-yellow` (`#ffe600`) já está definido no `tailwind.config.js` — usa o mesmo padrão visual do resto do painel.

---

### 6. AdmanService: método para faturamento mensal

**Decisão: Reutilizar `fetchPerformance()` existente com range do mês completo. Sem novo método na API.**

`fetchPerformance()` já aceita `dateFrom` e `dateTo` e retorna `summarizedData.grossBilling.value`. Para o fechamento mensal, basta passar o primeiro e último dia do mês corrente.

**Alternativa considerada e rejeitada:** Criar método `fetchMonthlyRevenue()` no `AdmanService`. Rejeitado porque o dado já está na tabela `adman_metrics` via sync diário existente. Fazer nova chamada HTTP para cada empresa no carregamento da página seria lento (N × 120s timeout) e desnecessário. O sync já popula os dados — o fechamento apenas agrega o que está no banco.

**Exceção:** Se a empresa não tiver `adman_account_id` ou se não houver métricas no mês (sync não rodou), o faturamento retorna `0` e a UI exibe badge de alerta "sem dados Adman".

---

## Adições de Stack — Resumo

### Novas migrations necessárias

| Migration | Propósito |
|-----------|-----------|
| `add_billing_fields_to_companies_table` | Adiciona `tipo_servico`, `contrato_inicio`, `servico_adicional` |

### Novos arquivos PHP

| Arquivo | Propósito |
|---------|-----------|
| `app/Http/Controllers/AdministrativoController.php` | Rota `/administrativo/fechamento`, monta props |
| (opcional) `app/Services/FechamentoService.php` | Extrai lógica de agregação e cálculo de faixa se o controller ficar grande |

### Novos arquivos JSX

| Arquivo | Propósito |
|---------|-----------|
| `resources/js/Pages/Administrativo/Fechamento.jsx` | Página principal do módulo |

### Sem novas dependências PHP ou JS

Nenhum pacote adicional é necessário. A tabela de faixas é PHP puro. A barra de progresso é CSS/Tailwind. A agregação é Eloquent + DB::raw. O cache é o driver `database` já configurado.

---

## O que NÃO usar e por quê

| Candidato | Por que não |
|-----------|-------------|
| Tabela `company_contracts` separada | Não há histórico temporal de contratos no escopo. Custo de join sem benefício. Pode ser adicionada em milestone futuro se surgir necessidade de versionar contratos. |
| Nova chamada HTTP ao Adman para faturamento mensal | Dado já está em `adman_metrics` via sync diário. N chamadas HTTP no page load = até 120s × N timeout. Inaceitável. |
| `recharts` para progress bar | Já instalado, mas é para gráficos de série temporal. Uma barra de progresso é `<div>` com `width` inline. |
| Biblioteca de billing/pricing PHP | Todas assumem pagamentos recorrentes, webhooks, assinaturas. O problema é uma função de lookup de 20 linhas. |
| WebSockets / Pusher para total em tempo real | Faturamento mensal é calculado uma vez por carregamento de página. Não há evento em tempo real relevante aqui. |
| Redis para cache | O driver `database` já configurado é suficiente para cache de 15 minutos com baixo volume de admin. Redis seria adicionado apenas em cenário de alta concorrência. |

---

## Fluxo de dados completo

```
[Scheduler] SyncAdmanData
    → AdmanService::syncAll()
    → AdmanService::syncCompany() por empresa
    → adman_metrics (revenue por dia, por empresa)

[Admin acessa /administrativo/fechamento]
    → AdministrativoController::index()
    → Cache::remember('fechamento_mensal_Y_m', 15min, fn)
        → AdmanMetric::groupBy('company_id')->sum('revenue') — UMA query
    → Company::with([]) — empresas com tipo_servico, contrato_inicio
    → Company::calcularFaixa($faturamento) — PHP puro, sem DB
    → Inertia::render('Administrativo/Fechamento', $props)

[Browser]
    → Fechamento.jsx recebe props montados
    → Renderiza lista + FaixaProgressBar (CSS puro)
    → Exibe total_a_cobrar consolidado
```

---

## Fontes

- Análise direta: `app/Services/AdmanService.php` — `fetchPerformance()` retorna `grossBilling.value`
- Análise direta: `database/migrations/2026_04_26_152220_create_adman_metrics_table.php` — coluna `revenue decimal(15,2)`, unique `(company_id, reference_date)`
- Análise direta: `app/Models/Company.php` — sem campos contratuais, sem agregação de faturamento
- Análise direta: `app/Http/Controllers/DevController.php` — padrão de props Inertia seguido no projeto
- Análise direta: `app/Models/AdmanMetric.php` — casts e fillable documentados
- Constraint do projeto: `CLAUDE.md` e `PROJECT.md` — stack bloqueado, sem novas dependências exceto se essenciais
