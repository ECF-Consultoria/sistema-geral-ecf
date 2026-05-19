# Architecture: Módulo de Fechamento Administrativo

**Milestone:** v2.0 Administrativo — Fechamento
**Researched:** 2026-05-19
**Context:** Integração do módulo de fechamento mensal ao ECF Admin existente (Laravel 12 + Inertia.js + React)

---

## Decisão Central: Rota e Página

**Usar a rota `/administrativo/financeiro` existente, renomeando semanticamente para "Fechamento".**

A rota `GET /administrativo/financeiro` → `AdminController::financeiro()` → `Admin/Financeiro.jsx` já está registrada, protegida por `middleware(['auth', 'verified', 'role:admin'])` e aparece no sidebar do `AppLayout.jsx` com o label "Financeiro" (linha 52).

Criar `/administrativo/fechamento` seria redundante: significaria nova rota, nova entrada no sidebar, e orphanizar `Admin/Financeiro.jsx` (placeholder vazio). A abordagem correta é evoluir o que existe:

- **Manter** a rota `/administrativo/financeiro` e o named route `admin.financeiro`
- **Manter** o arquivo `Admin/Financeiro.jsx` — reescrever seu conteúdo de dentro
- **Manter** o label "Financeiro" no sidebar (ou renomear para "Fechamento" — mudança de 1 string em `AppLayout.jsx`)
- **Expandir** `AdminController::financeiro()` com queries reais no lugar do `Inertia::render('Admin/Financeiro')` vazio

Isso elimina qualquer trabalho duplicado. Nenhuma nova rota. Nenhuma nova entrada de sidebar.

---

## Componentes: Novo vs. Modificado

### Arquivos a MODIFICAR (existentes)

| Arquivo | O que muda |
|---------|-----------|
| `app/Http/Controllers/AdminController.php` | Método `financeiro()`: adicionar queries de empresas + faturamento mensal + cálculo de faixa |
| `resources/js/Pages/Admin/Financeiro.jsx` | Reescrever completamente — sair do placeholder, implementar tabela de fechamento |
| `resources/js/Layouts/AppLayout.jsx` | Opcional: renomear label "Financeiro" → "Fechamento" (1 string, linha 52) |

### Arquivos a CRIAR (novos)

| Arquivo | Por que criar |
|---------|---------------|
| `database/migrations/YYYY_MM_DD_add_contract_fields_to_companies_table.php` | Adicionar `service_type`, `contract_start`, `contract_end`, `additional_service` à tabela `companies` — ver detalhe abaixo |

**Não é necessário criar:**
- Novo controller (AdminController absorve o método)
- Nova rota (rota já existe)
- Nova model (Company já é a entidade central)
- Nenhum Job ou Service novo (faturamento vem de `AdmanMetric` já gravado no banco; sem chamada de API síncrona na página)

---

## Schema: Campos no Companies vs. Tabela Nova

**Adicionar campos diretamente em `companies`, não criar `company_contracts`.**

Razão: o milestone define uma relação 1-para-1 empresa↔contrato — "cada empresa tem um tipo de serviço e datas de contrato". Uma tabela separada agrega complexidade de JOIN sem benefício enquanto não houver histórico de contratos. Os campos são estáveis (tipo de serviço não muda com frequência) e se encaixam no padrão `$fillable` já existente do modelo `Company`.

### Migration a criar

```php
Schema::table('companies', function (Blueprint $table) {
    // Tipo de serviço: POLO / Assessoria / Incubadora
    $table->string('service_type')->nullable()->after('segment');
    // Período de contrato
    $table->date('contract_start')->nullable()->after('service_type');
    $table->date('contract_end')->nullable()->after('contract_start');
    // Campo de serviço adicional — reservado, sem lógica de valor neste milestone
    $table->string('additional_service')->nullable()->after('contract_end');
});
```

### Company model: adicionar ao `$fillable`

```php
'service_type', 'contract_start', 'contract_end', 'additional_service',
```

**`service_type`** aceita os valores `'POLO'`, `'Assessoria'`, `'Incubadora'` — enum leve gerenciado como string (padrão do projeto, ver `segment`).

---

## Como o AdmanService Fornece Faturamento Mensal

**Não há chamada direta ao AdmanService na página de fechamento.** O faturamento já está gravado em `adman_metrics` pelo sync diário (`SyncAdmanData` → `AdmanService::syncAll()`). A página lê o banco, não a API.

### Endpoint Adman relevante (referência para sync existente)

O `AdmanService::fetchPerformance()` chama:

```
GET https://api.ad-man.io/v1/meli/performance/{custId}?dateFrom=YYYY-MM-DD&dateTo=YYYY-MM-DD
```

O campo `summarizedData.grossBilling.value` é o faturamento bruto, gravado em `adman_metrics.revenue`. Este é o campo correto para calcular a faixa de investimento segundo `faturamento_adm.md`.

### Como calcular o faturamento mensal do mês corrente

`adman_metrics` tem granularidade diária (`reference_date`). Para o mês corrente, somar todos os registros do mês:

```php
$mesInicio = now()->startOfMonth()->toDateString();
$mesFim    = now()->endOfMonth()->toDateString();

AdmanMetric::where('company_id', $company->id)
    ->whereBetween('reference_date', [$mesInicio, $mesFim])
    ->sum('revenue');
```

O `AdminController::financeiro()` faz essa soma para cada empresa no `map()`.

---

## Lógica da Faixa de Investimento

A tabela de faixas vem de `faturamento_adm.md`. Implementada como array de constantes no controller (sem tabela no banco — as faixas são regras de negócio estáticas):

```php
// Faixas em ordem crescente: [limite_superior, investimento_mensal]
// null = sem limite (última faixa)
private const FAIXAS = [
    [499_999.99,   3_000.00],
    [999_999.99,   4_500.00],
    [1_999_999.99, 6_000.00],
    [2_999_999.99, 7_500.00],
    [3_999_999.99, 9_000.00],
    [4_999_999.99, 10_500.00],
    [null,         12_000.00], // acima de R$ 5M — valor mínimo
];
```

O método auxiliar privado `calcularFaixa(float $faturamento): array` retorna:

```php
[
    'investimento'       => float,   // valor a cobrar da faixa atual
    'faixa_min'          => float,   // início da faixa atual
    'faixa_max'          => ?float,  // fim da faixa atual (null = sem teto)
    'progresso_pct'      => float,   // 0-100: posição dentro da faixa
    'distancia_proxima'  => ?float,  // quanto falta para a próxima faixa (null se já na última)
    'proxima_faixa_min'  => ?float,  // valor de entrada da próxima faixa
]
```

---

## Fluxo de Dados: da API ao React

```
[Scheduler diário]
    └─ SyncAdmanData::handle()
        └─ AdmanService::syncAll()
            └─ fetchPerformance(custId, date, date)
                └─ adman_metrics.revenue ← grossBilling gravado por empresa/dia

[Requisição do admin abre /administrativo/financeiro]
    └─ AdminController::financeiro()
        ├─ Company::where('active', true)->get()            ← lista todas as empresas
        ├─ AdmanMetric::whereBetween('reference_date', [...])  ← soma do mês por empresa
        ├─ calcularFaixa($faturamentoMes)                   ← faixa + progresso
        └─ Inertia::render('Admin/Financeiro', [
               'empresas'        => [...],   // array com faturamento + faixa + progresso
               'totalACobrar'    => float,   // soma de todos os investimentos do mês
               'mesReferencia'   => string,  // 'Maio 2026'
           ])

[Browser recebe props via Inertia]
    └─ Admin/Financeiro.jsx
        ├─ Tabela de empresas
        │   ├─ Nome, service_type, contract_start/end
        │   ├─ Faturamento mensal (formatado BRL)
        │   ├─ Investimento da faixa (formatado BRL)
        │   ├─ Barra de progresso (pure CSS/Tailwind)
        │   └─ additional_service (campo reservado — exibido, sem valor)
        └─ Card total consolidado: R$ X a cobrar no mês
```

---

## Barra de Progresso: Sem Bibliotecas Extras

O Recharts já está instalado mas é desnecessário aqui — um `<div>` aninhado com `width` calculado é suficiente e segue o padrão de design do projeto.

### Componente local no Financeiro.jsx

```jsx
// Barra de progressão de faixa — componente local (usado só nesta página)
function BarraFaixa({ progressoPct, distanciaProxima, faixaMax }) {
    const pct = Math.min(Math.max(progressoPct, 0), 100);
    return (
        <div className="space-y-1">
            <div className="h-1.5 w-full rounded-full bg-white/[0.06]">
                <div
                    className="h-full rounded-full bg-ecf-yellow transition-all"
                    style={{ width: `${pct}%` }}
                />
            </div>
            {distanciaProxima != null && (
                <p className="text-[11px] text-white/40">
                    falta {fmtBRL(distanciaProxima)} p/ próxima faixa
                </p>
            )}
        </div>
    );
}
```

- `progressoPct` vem do controller: `(faturamento - faixa_min) / (faixa_max - faixa_min) * 100`
- Para a última faixa (sem teto), o progresso é sempre 100% ou pode mostrar barra cheia
- Nenhuma dependência nova — Tailwind + `style={{ width }}` inline

---

## Estrutura do AdminController

`AdminController` absorve o método `financeiro()` com lógica real. Não criar `FechamentoController` separado — o controller tem apenas 4 métodos (empresas, relatorio, financeiro, inventario) e nenhum deles tem lógica ainda. Adicionar método privado `calcularFaixa()` é suficiente.

Se a lógica crescer (múltiplos meses, filtros, export), extrair para `FechamentoService` — mas não para este milestone.

```php
class AdminController extends Controller
{
    // Faixas de investimento definidas em faturamento_adm.md
    private const FAIXAS = [...];

    public function financeiro(): \Inertia\Response
    {
        $mesInicio = now()->startOfMonth()->toDateString();
        $mesFim    = now()->endOfMonth()->toDateString();

        $empresas = Company::where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Company $c) use ($mesInicio, $mesFim) {
                $faturamento = AdmanMetric::where('company_id', $c->id)
                    ->whereBetween('reference_date', [$mesInicio, $mesFim])
                    ->sum('revenue');

                $faixa = $this->calcularFaixa((float) $faturamento);

                return [
                    'id'                 => $c->id,
                    'name'               => $c->name,
                    'service_type'       => $c->service_type,
                    'contract_start'     => $c->contract_start?->toDateString(),
                    'contract_end'       => $c->contract_end?->toDateString(),
                    'additional_service' => $c->additional_service,
                    'faturamento'        => (float) $faturamento,
                    'investimento'       => $faixa['investimento'],
                    'progresso_pct'      => $faixa['progresso_pct'],
                    'distancia_proxima'  => $faixa['distancia_proxima'],
                    'faixa_min'          => $faixa['faixa_min'],
                    'faixa_max'          => $faixa['faixa_max'],
                ];
            });

        return Inertia::render('Admin/Financeiro', [
            'empresas'       => $empresas,
            'totalACobrar'   => $empresas->sum('investimento'),
            'mesReferencia'  => now()->translatedFormat('F Y'),
        ]);
    }

    private function calcularFaixa(float $faturamento): array { ... }
}
```

---

## Ordem de Build (Dependências entre Componentes)

```
1. Migration: add_contract_fields_to_companies
   └─ php artisan migrate
      └─ Company.$fillable atualizado

2. AdminController::financeiro() + calcularFaixa()
   └─ testa com php artisan tinker ou teste PHPUnit
      └─ retorna array de empresas com faturamento + faixa calculados

3. Admin/Financeiro.jsx (reescrita)
   └─ consome props: empresas[], totalACobrar, mesReferencia
      └─ componente local BarraFaixa (sem dependência externa)
         └─ npm run build
```

**Dependências explícitas:**
- A migration deve rodar antes de qualquer query nos campos novos
- O controller deve estar estável antes de desenvolver a UI (props bem definidas = contrato seguro)
- A UI não depende de nenhum novo componente em `resources/js/Components/` — tudo local no `.jsx`

---

## Limites de Componente

| Componente | Responsabilidade | Comunicação |
|------------|-----------------|-------------|
| `AdminController::financeiro()` | Agrega faturamento do mês por empresa, calcula faixa, serializa props | Inertia::render → browser |
| `AdminController::calcularFaixa()` | Pura: recebe float, retorna array de faixa — sem acesso a DB ou API | Chamado por `financeiro()` |
| `AdmanMetric` (model existente) | Fonte dos dados de faturamento diário — sem modificação | Consultado via `sum('revenue')` |
| `Company` (model existente) | Entidade central — recebe 4 campos novos via migration | Consultado com `get()` |
| `Admin/Financeiro.jsx` | Renderiza tabela + cards + barras de progresso com as props recebidas | Recebe props de Inertia, sem chamadas HTTP próprias |
| `BarraFaixa` (sub-componente local) | Renderiza a barra de progresso da faixa com `progresso_pct` | Props locais do `.jsx` |

---

## Restrições Arquiteturais Relevantes

- **Sem WebSockets / polling:** A página carrega os dados no page load (Inertia SSR). Para atualizar, o admin recarrega a página. Isso é suficiente — fechamento mensal não é real-time.
- **Faturamento do mês corrente pode estar incompleto:** O sync Adman processa o dia anterior (ver `AdmanService::syncCompany()` — `$date = now()->subDay()`). Os últimos 2 dias do mês terão dados faltando. Exibir a data do último sync ou uma nota na UI é recomendado.
- **N+1 potencial:** A query de `sum('revenue')` dentro do `map()` gera N queries (uma por empresa). Para o volume atual (< 100 empresas) é aceitável. Se crescer, substituir por `AdmanMetric::selectRaw('company_id, SUM(revenue) as total')->whereBetween(...)->groupBy('company_id')->get()->keyBy('company_id')` e fazer o join em memória.
- **Sem nova dependência JS:** A barra de progresso usa CSS Tailwind puro — `recharts` e `radix-ui` não são necessários para este módulo.
- **Acesso:** Toda a rota `/administrativo/*` já está protegida por `middleware('role:admin')` em `routes/web.php` (linha 233). Nenhuma camada de auth adicional necessária.

---

*Pesquisa realizada em: 2026-05-19*
