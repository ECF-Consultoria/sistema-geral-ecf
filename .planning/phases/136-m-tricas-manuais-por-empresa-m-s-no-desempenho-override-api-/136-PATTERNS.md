# Phase 136: Métricas manuais por empresa/mês no Desempenho — Mapa de Padrões

**Mapeado em:** 2026-08-11
**Arquivos analisados:** 17 (9 novos + 8 modificados)
**Analogs encontrados:** 15 / 17 (2 sem analog exato — sinalizados abaixo com a convenção a seguir mesmo assim)

## File Classification

| Arquivo novo/modificado | Papel | Fluxo de dado | Analog mais próximo | Qualidade do match |
|---|---|---|---|---|
| `database/migrations/2026_08_11_HHMMSS_create_desempenho_metricas_manuais_table.php` | migration | CRUD (schema) | `database/migrations/2026_08_11_120100_create_onboardings_tables.php` + `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` | role-match (convenção idêntica, dois analogs complementares) |
| `app/Models/DesempenhoMetricaManual.php` (nome sugerido) | model | CRUD | `app/Models/BonusInvalidacao.php` | exact |
| `app/Services/Metrics/FinancialSourceResolver.php` (nome sugerido pelo RESEARCH Q2) | service | transform | `app/Services/Metrics/MetricDiffDispatcher.php` | role-match |
| `app/Services/Metrics/ManualMetricOverrideService.php` (nome sugerido pelo RESEARCH Q3) | service (decorator) | transform | `app/Services/Metrics/ShopeeMetricDiffService.php` / `app/Services/Metrics/AdmanMetricDiffService.php` (contrato de shape) | role-match |
| `app/Http/Requests/StoreMetricaManualRequest.php` (nome sugerido) | middleware (validação) | request-response | `app/Http/Requests/UpdateBonusFaixaRequest.php` | exact |
| `app/Http/Controllers/DesempenhoMetricasManuaisController.php` (nome sugerido) | controller | CRUD + request-response | `app/Http/Controllers/BonusAuditoriaController.php` **e** `app/Http/Controllers/DesempenhoConfigController.php` | role-match (dois padrões coexistem — ver seção dedicada) |
| `resources/js/Pages/Desempenho/MetricasManuais.jsx` (nome sugerido) | component (página Inertia) | CRUD (grade editável) | **SEM ANALOG EXATO** — composição de `Pages/Desempenho/Auditoria.jsx` + `Pages/Polos/components/CustIdCell.jsx` + `Pages/Desempenho/Configuracao.jsx` | partial-match |
| `app/Console/Commands/RelatorioImpactoDesempateDesempenho.php` (nome sugerido) | command | batch / read-only | `app/Console/Commands/VerificarConsolidacaoDesempenho.php` | exact |
| `tests/Feature/Phase136/*.php` (4 arquivos, ver Wave 0 Gaps do RESEARCH) | test | Feature | `tests/Feature/Phase119/CompanyScoreServiceFonteTest.php` + `tests/Feature/V16/CriaCenarioResponsaveis.php` | role-match |
| `app/Services/Desempenho/CompanyScoreService.php` (modificado, linha 168-175) | service | transform | — (é o próprio arquivo; ver "Modificações" abaixo) | — |
| `app/Services/DesempenhoScoreService.php` (modificado, linhas 449 e 911-916) | service | transform | — | — |
| `app/Http/Controllers/PortfolioController.php` (modificado, linhas 118-127) | controller | request-response | — | — |
| `app/Services/Desempenho/CompanyScoreSnapshotWriter.php` (modificado) | service | CRUD (persistência) | — | — |
| `resources/js/Pages/Performance/Show.jsx` + `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx` (modificados) | component | request-response (props Inertia) | — | — |
| `routes/web.php` (modificado — rota nova) | route | request-response | — | — |
| `tests/Feature/Phase119/CompanyScoreService{Dispatcher,Fonte,Margem,Reconciliacao,Status}Test.php` (modificados — gate de hash) | test | Feature | — | — |
| `tests/Feature/{DesempenhoShopeeScoreTest,Phase116/NpsFloorDesempenhoTest,Phase96/NpsInvalidacaoRespostaTest,V18/DesempenhoMetadadosCacheTest}.php` (modificados — bump v19→v20) | test | Feature | — | — |

---

## Pattern Assignments

### 1. Migration da tabela de lançamentos manuais

**Analogs:** `database/migrations/2026_08_11_120100_create_onboardings_tables.php` (mais recente, molde de `nullOnDelete()`+`nullable()`) e `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` (molde de nome de índice explícito + STRING em vez de enum).

**Padrão real de `nullOnDelete()` sempre acompanhado de `nullable()`** (`database/migrations/2026_08_11_120100_create_onboardings_tables.php:59-62`):
```php
$table->foreignId('responsavel_id')
    ->nullable()
    ->constrained('users')
    ->nullOnDelete();
```

**Padrão real de nome de índice único EXPLÍCITO e curto** (`database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php:84-94`, comentário incluso porque explica o PORQUÊ):
```php
// Chave do critério 2 do ROADMAP — 1 linha por (profissional,
// empresa, competência); base da idempotência do writer.
//
// O nome do índice é EXPLÍCITO e curto de propósito. O nome que o
// Laravel geraria sozinho
// (`desempenho_company_score_snapshots_user_id_company_id_mes_referencia_unique`)
// tem 75 caracteres e o MariaDB recusa identificadores acima de 64
// (erro 1059) — o SQLite dos testes aceita, então isso SÓ aparece
// em produção. O nome da tabela já ocupa 34 caracteres, então
// qualquer índice multi-coluna auto-nomeado aqui estoura o limite.
$table->unique(['user_id', 'company_id', 'mes_referencia'], 'dcss_user_company_mes_unique');
```

**Padrão real de coluna STRING em vez de enum** (mesma migration, linhas 46-50):
```php
// STRING sempre — nunca coluna de tipo restrito por lista fixa:
// o CHECK é enforçado no SQLite dos testes e quebra ao surgir
// valor novo (armadilha registrada na memória do projeto).
$table->string('fonte_financeira')->nullable();
$table->string('status')->nullable();
```

**Cálculo verificado pelo RESEARCH (Q6):** o nome auto-gerado do índice único de 3 colunas para `desempenho_metricas_manuais` teria **68 caracteres** — 4 acima do limite de 64 do MariaDB (erro 1059). Aplicar nome explícito, ex. `dmm_company_mes_metrica_unique` (RESEARCH Q6 já traz o schema proposto completo — o executor deve seguir a mesma estrutura: `company_id` FK cascade, `mes_referencia` date sempre `YYYY-MM-01`, `metrica` string(20), `valor` decimal(16,2), `valor_anterior` decimal(16,2) nullable, `ativo` boolean default true, `lancado_por` FK nullable+nullOnDelete, `lancado_em` timestamp, `timestamps()`, unique composto com nome explícito).

**Padrão real de `Schema::hasTable()` idempotente** (`database/migrations/2026_08_11_120100_create_onboardings_tables.php:34`):
```php
if (! Schema::hasTable('onboardings')) {
    Schema::create('onboardings', function (Blueprint $table) { ... });
}
```

---

### 2. Model `DesempenhoMetricaManual`

**Analog:** `app/Models/BonusInvalidacao.php` (íntegro, 76 linhas — tabela pequena, `LogsActivity`, FK Company+User).

**Imports + declaração de classe** (`app/Models/BonusInvalidacao.php:1-25`):
```php
<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BonusInvalidacao extends Model
{
    use LogsActivity;

    protected $table = 'bonus_invalidacoes';

    protected $fillable = [
        'company_id',
        'competencia',
        'motivo',
        'invalidated_by',
    ];

    protected $casts = [
        'competencia' => 'date',
    ];
```

**Padrão de `getActivitylogOptions()` (D-12)** (`app/Models/BonusInvalidacao.php:38-49`):
```php
public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logOnly(['company_id', 'competencia', 'motivo', 'invalidated_by'])
        ->logOnlyDirty()
        ->dontSubmitEmptyLogs()
        ->setDescriptionForEvent(fn (string $event) => match ($event) {
            'created' => 'invalidou empresa para bônus',
            'deleted' => 'reativou empresa para bônus',
            default   => "bônus invalidação {$event}",
        });
}
```
Adaptar para `DesempenhoMetricaManual`: `logOnly(['company_id', 'mes_referencia', 'metrica', 'valor', 'valor_anterior', 'ativo', 'lancado_por'])`, com `'created' => 'lançou valor manual de desempenho'` e `'updated' => 'editou valor manual de desempenho'` — RESEARCH Q6 já traz este trecho pronto.

**Padrão de relação BelongsTo + método estático de consulta por competência** (`app/Models/BonusInvalidacao.php:51-74`):
```php
public function company(): BelongsTo
{
    return $this->belongsTo(Company::class);
}

public function invalidatedBy(): BelongsTo
{
    return $this->belongsTo(User::class, 'invalidated_by');
}

public static function companyIdsInvalidadas(Carbon $competencia): \Illuminate\Support\Collection
{
    return static::query()
        ->whereDate('competencia', $competencia->copy()->startOfMonth()->toDateString())
        ->pluck('company_id')
        ->map(fn ($id) => (int) $id);
}
```
Replicar o padrão `whereDate('mes_referencia', ...)` (não comparação direta de `date`) — é o mesmo cuidado usado em `CompanyScoreSnapshotWriter::sync()` (ver seção 13).

**Padrão de casts numéricos + array/json** (analog complementar, `app/Models/DesempenhoCompanyScoreSnapshot.php:64-75`):
```php
protected $casts = [
    'mes_referencia'        => 'date',
    'gerado_em'             => 'datetime',
    'quality'               => 'array',
    'nps_pontos'            => 'float',
    ...
];
```

---

### 3. Service `FinancialSourceResolver` (D-10 — resolvedor único)

**Analog:** `app/Services/Metrics/MetricDiffDispatcher.php` (íntegro, 45 linhas).

**Padrão real completo** (`app/Services/Metrics/MetricDiffDispatcher.php:1-44`):
```php
<?php

namespace App\Services\Metrics;

use App\Models\Company;
use InvalidArgumentException;

/**
 * Fase 109 Plan 01 (SHOP-CAR-01) — roteador por fonte financeira.
 * ...
 */
class MetricDiffDispatcher
{
    public function __construct(
        private AdmanMetricDiffService $admanMetricDiffService,
        private ShopeeMetricDiffService $shopeeMetricDiffService,
    ) {
    }

    public function compute(Company $company, array $periodo, string $source): array
    {
        return match ($source) {
            'adman'  => $this->admanMetricDiffService->compute($company, $periodo),
            'shopee' => $this->shopeeMetricDiffService->compute($company, $periodo),
            default  => throw new InvalidArgumentException(
                "Fonte financeira desconhecida: '{$source}'. Esperado 'adman' ou 'shopee'."
            ),
        };
    }
}
```
Copiar: namespace `App\Services\Metrics`, injeção via construtor (sem service próprio de banco — recebe dados já carregados), docblock de classe explicando o "porquê" antes do "o quê", whitelist explícita em vez de branch silencioso.

**Assinatura recomendada pelo RESEARCH (Q2), já com o algoritmo corrigido de D-10:**
```php
namespace App\Services\Metrics;

class FinancialSourceResolver
{
    /**
     * @param Collection<int, array> $vinculosElegiveis  já filtrados por financial_metrics_eligible=true
     * @param Collection<int, Company> $companiesById     keyBy('id') — já carregado pelo chamador
     * @return Collection<int, string> company_id => 'adman'|'shopee'
     */
    public function resolverPorEmpresa(Collection $vinculosElegiveis, Collection $companiesById): Collection
    {
        return $vinculosElegiveis
            ->groupBy('company_id')
            ->map(function (Collection $grupo, $companyId) use ($companiesById) {
                $sources = $grupo->pluck('financial_source');
                $company = $companiesById->get((int) $companyId);
                $admanValido = $company !== null && $company->cust_id !== null;

                return match (true) {
                    $sources->contains('adman') && $admanValido => 'adman',
                    $sources->contains('shopee')                 => 'shopee',
                    default                                       => $sources->first(),
                };
            });
    }
}
```
**Critério "tem Adman de fato" (D-10):** usar `Company::cust_id` (accessor `adman_account_id ?: ml_store_id`, `app/Models/Company.php:94-98`), nunca a coluna crua `adman_account_id` — é o mesmo sinal que `AdmanMetricDiffService::compute()`/`isCached()` já usam para decidir se vale tentar a chamada.

---

### 4. Service `ManualMetricOverrideService` (decorator — Q3)

**Analogs (contrato de shape a preservar byte a byte):** `app/Services/Metrics/ShopeeMetricDiffService.php` e `app/Services/Metrics/AdmanMetricDiffService.php`.

**Shape exato que o override precisa devolver** (docblock, `app/Services/Metrics/ShopeeMetricDiffService.php:62-72`):
```php
/**
 * @return array{
 *     company_id: int,
 *     period: array,
 *     metrics: array{
 *         revenue: array{value: ?float, prev_value: ?float, diff_pct: ?float, diff_source: ?string},
 *         contribution_margin_value: array{value: ?float, prev_value: ?float, diff_pct: ?float, diff_pp: ?float, diff_source: ?string},
 *         contribution_margin_pct: array{value: ?float, prev_value: ?float, diff_pct: ?float, diff_pp: ?float, diff_source: ?string},
 *     },
 *     investment: array{value: ?float, prev_value: ?float, diff_pct: ?float, diff_source: ?string},
 *     quality: array{status: string, source: string, computed_at: string},
 * }
 */
public function compute(Company $company, array $periodo): array
```

**Docblock "future-ready" que já prevê exatamente este caso** (`app/Services/Metrics/ShopeeMetricDiffService.php:22-28`):
```
### Margem sempre null (decisão travada 2026-07-23)
`contribution_margin_value`/`contribution_margin_pct` retornam
`value`/`prev_value`/`diff_pct`/`diff_source` sempre `null` ... —
a Shopee não fornece CMV. Arquitetura *future-ready*: quando a
Shopee passar a fornecer margem, basta trocar `margemValorNula()`/
`margemPctNula()` por um cálculo real, sem mudar o shape.
```

**Padrão de composição recomendado (RESEARCH Q3, não é código do projeto — é a assinatura a implementar):** classe nova chamada **depois** de `MetricDiffDispatcher::compute()`, recebendo `(Company $company, array $periodo, array $resultadoDispatcher)`, substituindo só o bloco (`metrics.revenue` ou `metrics.contribution_margin_*`) do eixo marcado `manual`, com `diff_source='manual_mes_calendario'` (D-05), preservando as mesmas chaves. **Nunca** um terceiro branch dentro do `match()` do `MetricDiffDispatcher` — ver justificativa completa no RESEARCH Q3 (misturaria dois eixos ortogonais e quebraria a whitelist `InvalidArgumentException` de T-109-02).

**Consistência com D-EXC-01:** o hotfix de 2026-07-24 trava `AdmanMetricDiffService::resolveMargemPct()` (linhas 308-325) — o override roda **fora** desse método, substituindo o bloco já retornado, não o cálculo interno.

---

### 5. FormRequest de validação do lançamento

**Analog:** `app/Http/Requests/UpdateBonusFaixaRequest.php` (íntegro, 148 linhas).

**Padrão de `authorize()` — defesa dupla, `false` explícito** (`app/Http/Requests/UpdateBonusFaixaRequest.php:42-45`):
```php
public function authorize(): bool
{
    return $this->user()?->isAdmin() === true;
}
```

**Padrão de `rules()`** (`app/Http/Requests/UpdateBonusFaixaRequest.php:52-61`):
```php
public function rules(): array
{
    return [
        'nome'      => ['required', 'string', 'max:100'],
        'descricao' => ['nullable', 'string', 'max:2000'],
        'nota_min'  => ['required', 'numeric', 'between:0,5'],
        'nota_max'  => ['required', 'numeric', 'between:0,5', 'gte:nota_min'],
        'ordem'     => ['required', 'integer', 'min:0'],
    ];
}
```
Adaptar para o lançamento manual: `valor` como `numeric` positivo com teto plausível (RESEARCH Q9 recomenda `max:99999999.99`, coerente com `decimal(16,2)`), `metrica` via `Rule::in(['faturamento','margem_cmv'])`, `mes_referencia` com regex `YYYY-MM`.

**Padrão de `withValidator()` para regra composta** (`app/Http/Requests/UpdateBonusFaixaRequest.php:70-119`, trecho representativo):
```php
public function withValidator(Validator $validator): void
{
    $validator->after(function (Validator $v) {
        $faixaAtual = $this->route('faixa');
        if (! $faixaAtual instanceof BonusFaixa) {
            return;
        }
        // ... regra composta com $v->errors()->add('campo', 'mensagem')
    });
}
```
Usar este hook para a regra de D-09 (mês não pode estar consolidado — reaproveitar a MESMA verificação de `CompanyScoreSnapshotWriter`, ver seção 13) **antes** do INSERT/UPDATE, não como checagem opcional depois (RESEARCH Q9).

**Padrão de `messages()` em pt-BR** (`app/Http/Requests/UpdateBonusFaixaRequest.php:127-146`) — seguir o mesmo mapeamento `campo.regra => mensagem`.

---

### 6. Controller da grade admin — dois padrões coexistem

**RESEARCH confirmou (Q8):** `/desempenho/configuracao/*` vive **dentro** de um grupo largo `Route::middleware(['auth','verified','role:admin'])->group(...)` (abre em `routes/web.php:157`, fecha em `377`). `/desempenho/auditoria-bonus` e `/desempenho/relatorio-bonificacao` estão **fora** desse grupo, com `->middleware('role:admin')` **por rota** (linhas 544-560). A rota nova desta fase deve seguir o padrão de **middleware por rota**, porque é literalmente vizinha desse segundo bloco (linhas 541-560), não do primeiro (que já fechou na linha 377).

**Padrão de registro de rota a copiar** (`routes/web.php:544-549`):
```php
// Auditoria de pagamento de bônus (item 3/4 · 2026-07-21) — admin-only.
// Invalida o resultado de uma empresa para bônus numa competência (empresa
// sem custo preenchido infla margem injustamente). Ver BonusAuditoriaController.
Route::get('/desempenho/auditoria-bonus', [BonusAuditoriaController::class, 'index'])
    ->middleware('role:admin')
    ->name('desempenho.auditoria-bonus');
Route::post('/desempenho/auditoria-bonus/toggle', [BonusAuditoriaController::class, 'toggle'])
    ->middleware('role:admin')
    ->name('desempenho.auditoria-bonus.toggle');
```

**Analog de controller (estrutura + injeção de dependência + Inertia::render):** `app/Http/Controllers/BonusAuditoriaController.php` (íntegro, 274 linhas).

**Padrão de construtor com DI de services do módulo** (`app/Http/Controllers/BonusAuditoriaController.php:30-35`):
```php
public function __construct(
    private DesempenhoScoreService $scoreService,
    private MetricPeriodResolver $periodResolver,
    private CarteiraContextService $carteiraContext,
    private CompanyScoreSnapshotReader $companyScoreReader,
) {}
```

**Padrão de action de escrita (`toggle`) — validação inline + `back()->with()`** (`app/Http/Controllers/BonusAuditoriaController.php:178-209`):
```php
public function toggle(Request $request)
{
    $dados = $request->validate([
        'company_id'  => ['required', 'integer', 'exists:companies,id'],
        'competencia' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        'motivo'      => ['nullable', 'string', 'max:255'],
    ]);
    // ...
    return back()->with('success', $flash);
}
```

**Padrão alternativo (grupo de middleware) a NÃO copiar aqui, mas usado por `DesempenhoConfigController`** (`app/Http/Controllers/DesempenhoConfigController.php:1-9`, `routes/web.php:368-376`) — citado só para o executor entender por que os dois padrões existem e escolher o correto (rota por-rota, não grupo):
```php
Route::get   ('/desempenho/configuracao',
    [\App\Http\Controllers\DesempenhoConfigController::class, 'index'])
    ->name('desempenho.configuracao.index');
```
(sem `->middleware('role:admin')` na linha — herda do grupo que abre em `routes/web.php:157`).

**Padrão de busting de cache após escrita** (`app/Http/Controllers/BonusAuditoriaController.php:241-264`, `bustarCacheDaEmpresa()`) — usar como referência para invalidar `Cache::forget($this->scoreService->cacheKey(...))` e limpar `DesempenhoCompanyScoreSnapshot` quando o lançamento manual afetar competência já com snapshot diário/warm gravado.

---

### 7. Página React da grade empresa × mês

**RESEARCH confirma: nenhum analog exato existe.** Composição recomendada de 3 fontes reais do projeto:

**(a) Estrutura de página admin-only com seletor de competência** — `resources/js/Pages/Desempenho/Auditoria.jsx` (íntegro, 308 linhas). Padrão de ação via `router.post` com `preserveScroll` (linhas 117-127):
```jsx
const toggle = () => {
    setEnviando(true);
    router.post(route('desempenho.auditoria-bonus.toggle'), {
        company_id: empresa.company_id,
        competencia,
        motivo: empresa.invalidada ? null : (motivo || null),
    }, {
        preserveScroll: true,
        onFinish: () => { setEnviando(false); setMotivo(''); },
    });
};
```

**(b) Célula editável inline + salvamento + estado local — o padrão mais próximo de "grade editável" no projeto inteiro:** `resources/js/Pages/Polos/components/CustIdCell.jsx` (íntegro, 137 linhas). Ciclo completo: exibição → clique vira input → Enter/blur salva → Escape cancela:
```jsx
export function CustIdCell({ e, onSalvar }) {
    const [editando, setEditando] = useState(false);
    const [valor, setValor] = useState('');
    // ... exibição com botão de editar (Pencil) quando já tem valor
    // ... input quando editando, com salvar() no Enter e cancelar no Escape
    const salvar = (ev) => {
        ev?.stopPropagation();
        setEditando(false);
        if (v === anterior) return;              // nada mudou → não gasta request
        if (v === '' && anterior === '') return; // cadastro abandonado em branco
        onSalvar(e, v);
    };
    return (
        <span className="inline-flex items-center gap-1 shrink-0" onClick={(ev) => ev.stopPropagation()}>
            <input
                autoFocus
                type="text"
                value={valor}
                onChange={(ev) => setValor(ev.target.value)}
                onKeyDown={(ev) => { if (ev.key === 'Enter') salvar(ev); if (ev.key === 'Escape') setEditando(false); }}
                className="h-6 w-24 rounded-md border border-white/15 bg-white/[0.05] px-1.5 font-mono text-[11px] text-white outline-none focus:border-ecf-yellow/40"
            />
            {/* botões salvar/cancelar */}
        </span>
    );
}
```
Site de chamada real (`resources/js/Pages/Polos/Painel.jsx:884-885,1492`):
```jsx
const salvarCustId = (e, valor) =>
    router.patch(route('mlb.empresas.cust-id', e.id), { cust_id: String(valor ?? '').trim() }, reloadOpts);
// ...
<CustIdCell e={e} onSalvar={on.salvarCustId} />
```

**(c) Formulário com `useForm()` + estado de erro do backend** — quando o lançamento precisar de mais de um campo por submit (ex. modal com faturamento + margem + confirmação), usar `resources/js/Pages/Desempenho/Configuracao.jsx:113-120` como molde (`FaixaCard`):
```jsx
const { data, setData, patch, processing, errors, isDirty, reset } = useForm({
    nome: faixa.nome,
    descricao: faixa.descricao ?? '',
    nota_min: faixa.nota_min,
    nota_max: faixa.nota_max,
    ordem: faixa.ordem,
});
```
(o restante do arquivo, não citado aqui para não estourar o orçamento de contexto, mostra `errors.campo` renderizado ao lado do input e `processing` desabilitando o botão de salvar — mesmo padrão a seguir).

**Risco do manifest do Vite (conhecimento do projeto, learnings):** criar a página nova como arquivo `.jsx` completo e autocontido, exatamente como as 3 páginas existentes de `Pages/Desempenho/` — **nunca** como wrapper de re-export puro (`export { default } from ...`), que já é causa conhecida de página sumir do manifest do Vite (ver `.planning/learnings/painel-polos-status-e-meta.md`).

---

### 8. Comando Artisan de relatório de impacto (D-11, read-only)

**Analog:** `app/Console/Commands/VerificarConsolidacaoDesempenho.php` (íntegro, 361 linhas — comprovadamente read-only, veredito por exit code).

**Padrão de assinatura + contrato de saída** (`app/Console/Commands/VerificarConsolidacaoDesempenho.php:83-87`):
```php
class VerificarConsolidacaoDesempenho extends Command
{
    protected $signature = 'desempenho:verificar-consolidacao
        {--mes= : YYYY-MM (default = mês anterior ao hoje)}
        {--json : saída em JSON, parseável, sem nenhum outro texto}';

    protected $description = 'Confere uma competencia do modulo Desempenho por RECONSULTA (read-only) as duas tabelas do fechamento. Exit code e o veredito, nunca o texto impresso.';
```

**Padrão de `handle()` — exit code é o contrato, `--json` é o formato oficial** (`app/Console/Commands/VerificarConsolidacaoDesempenho.php:89-122`):
```php
public function handle(): int
{
    // ... parse de --mes com createFromFormat('Y-m-d', $mesOption.'-01') — NUNCA 'Y-m' sozinho
    $relatorio = $this->montarRelatorio($mes, $mesStr, $mesLabel, $mesFechado);

    if ($this->option('json')) {
        $this->line(json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } else {
        $this->imprimirRelatorioHumano($relatorio);
    }

    return $relatorio['resumo']['total_inconsistencias'] === 0 ? self::SUCCESS : self::FAILURE;
}
```

**Padrão de docblock declarando READ-ONLY explicitamente** (`app/Console/Commands/VerificarConsolidacaoDesempenho.php:28-34`):
```
D-122-10 (READ-ONLY): este comando não grava nada — nenhuma escrita em
nenhuma tabela, nenhum cache aquecido, nenhuma chamada ao serviço que
monta o payload de nota (isso dispararia HTTP à Adman). Um verificador
que corrige o que encontra esconderia a inconsistência em vez de expô-la
```
Aplicar o mesmo espírito ao relatório de D-11: **nenhum** `->update()`/`->create()`/chamada a `MetricDiffDispatcher::compute()` que dispararia HTTP síncrono à Adman — o relatório de "quem mudaria de número" deve ler dados já persistidos (snapshots) ou, se precisar recomputar, fazer isso claramente fora de transação de escrita, nunca persistindo o resultado.

**Padrão de saída humana vs. `--json`, com aviso explícito de qual é o contrato** (`app/Console/Commands/VerificarConsolidacaoDesempenho.php:314-320,358`):
```php
/**
 * Saída padrão (sem `--json`) ... Esta saída é
 * CONVENIÊNCIA HUMANA — nenhum teste desta suíte pode depender dela
 * (122-CONTEXT.md item 5); o `--json` é o contrato.
 */
// ...
$this->warn('[VerificarConsolidacao] AVISO: esta tabela é CONVENIÊNCIA HUMANA. A conferência OFICIAL é o EXIT CODE (0 = sem inconsistências) ou a saída --json — nunca este texto.');
```

---

### 9. Testes novos em `tests/Feature/Phase136/`

**Analogs obrigatórios:** `tests/Feature/Phase119/CompanyScoreServiceFonteTest.php` e `tests/Feature/V16/CriaCenarioResponsaveis.php`.

**O projeto NÃO usa factory para métricas** — confirmado por `database/factories/` (existem `CompanyFactory`, `UserFactory`, `ContratoServicoFactory`, `CompanyMarketplaceFactory`, `BonusFaixaFactory`; **não existem** `AdmanMetricFactory`/`ShopeeMetricFactory`/`DesempenhoCompanyScoreSnapshotFactory` nem factory para `company_users`/`Servico`). Seguir o padrão real: `Model::create()` direto + helpers da trait.

**Padrão real de `montarCenarioMisto()` — empresa com dois vínculos (performance + shopee)** (`tests/Feature/Phase119/CompanyScoreServiceFonteTest.php:123-136`):
```php
private function montarCenarioMisto(): array
{
    $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
    $empresa = Company::factory()->create(['active' => true, 'adman_account_id' => 'CUST-FONTE-MISTA']);

    $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
    $this->criarContrato($empresa->id, $servicoPerf, true);
    $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);

    $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
    $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);

    return compact('user', 'empresa');
}
```
**Para o teste NOVO de D-10 (caso sem `cust_id`)** — variar esta fixture removendo `'adman_account_id' => 'CUST-FONTE-MISTA'` (deixar `null`) e garantir `ml_store_id` também `null` — é exatamente o gap que o RESEARCH identificou (Q10): a fixture existente sempre seta `adman_account_id` válido.

**Padrão real de `montarCenarioSoShopee()`** (`tests/Feature/Phase119/CompanyScoreServiceFonteTest.php:146-159`):
```php
private function montarCenarioSoShopee(): array
{
    $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
    $empresa = Company::factory()->create(['active' => true]);

    $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
    $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);

    // Janela current (2026-06) e baseline (janela-de-mesmo-tamanho, mai/2026).
    ShopeeMetric::create(['company_id' => $empresa->id, 'reference_date' => '2026-06-15', 'revenue' => 102000]);
    ShopeeMetric::create(['company_id' => $empresa->id, 'reference_date' => '2026-05-15', 'revenue' => 100000]);

    return compact('user', 'empresa');
}
```

**Padrão real do gate de aditividade — REPETIR em todo teste novo desta fase, adaptado (ver seção "Modificações" para a rotação da constante):**
```php
private function assertHashDesempenhoScoreServiceIntocado(): void
{
    $hash = hash_file('sha256', app_path('Services/DesempenhoScoreService.php'));
    $this->assertSame(self::HASH_DESEMPENHO_SCORE_SERVICE, $hash, 'DesempenhoScoreService.php foi alterado — fase é ADITIVA.');
}
```
Como esta fase **modifica de propósito** `DesempenhoScoreService.php` (D-10 na linha 915), os testes novos de Phase136 **não precisam** deste gate — ele é specific da Fase 119 para garantir que fases *aditivas* não tocassem o arquivo. Citado aqui só para o executor entender o mecanismo antes de rotacionar a constante (seção "Modificações" #16).

**Trait `CriaCenarioResponsaveis` — helpers reais via `DB::table`, não Eloquent** (`tests/Feature/V16/CriaCenarioResponsaveis.php:30-75`):
```php
protected function criarServico(string $setor, bool $ativo = true): int
{
    return (int) DB::table('servicos')->insertGetId([
        'nome' => 'Serviço ' . ucfirst($setor), 'valor_padrao' => 0,
        'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => $ativo,
        'setor' => $setor, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

protected function criarContrato(int $companyId, int $servicoId, bool $ativo = true): int
{
    return (int) DB::table('contratos_servico')->insertGetId([
        'company_id' => $companyId, 'servico_id' => $servicoId,
        'valor_contratado' => 0, 'data_contratacao' => now()->toDateString(),
        'ativo' => $ativo, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

protected function inserirPivot(int $companyId, int $userId, string $role, ?int $servicoId): int
{
    return (int) DB::table('company_users')->insertGetId([
        'company_id' => $companyId, 'user_id' => $userId, 'role' => $role,
        'servico_id' => $servicoId, 'assigned_at' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
```

**Padrão de `Http::preventStrayRequests()` + fake explícito por teste** (`tests/Feature/Phase119/CompanyScoreServiceFonteTest.php:65,101-107`) — nenhum teste desta fase pode disparar HTTP real à Adman:
```php
Http::preventStrayRequests();
// ...
private function fakeAdmanEndpoints(): void
{
    Http::fake([
        '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
        '*/accounts/*/metrics*' => Http::response($this->respostaAccountMetrics(), 200),
    ]);
}
```

**Comando de execução (ambiente Windows/XAMPP deste projeto):**
```
C:\xampp\php\php.exe artisan test --filter="Phase136"
```

---

## Modificações em arquivos existentes

### 10. `app/Services/Desempenho/CompanyScoreService.php` — desempate (D-10)

**Trecho atual** (linhas 163-175, confirmado nesta sessão):
```php
        // 5. Fonte financeira vencedora — SÓ entre os vínculos elegíveis
        //    (financial_metrics_eligible=true). 'adman' vence sobre 'shopee'
        //    quando a MESMA empresa tem os dois vínculos elegíveis. Empresa
        //    sem NENHUM vínculo elegível fica FORA do mapa (D-03) — o
        //    universo permanece o COMPLETO, nunca pré-filtrado.
        $fontesPorEmpresa = $vinculos
            ->where('financial_metrics_eligible', true)
            ->groupBy('company_id')
            ->map(function (Collection $grupo) {
                $sources = $grupo->pluck('financial_source');

                return $sources->contains('adman') ? 'adman' : $sources->first();
            });
```
**Custo de reordenar (RESEARCH Q2):** hoje `Company::whereIn($companyIdsComFonte)->get()->keyBy('id')` (linha 185, logo abaixo) roda **depois** do desempate, sobre o subconjunto já resolvido. Para checar `cust_id` durante o desempate (D-10), mover essa mesma query (ou uma versão enxuta com `select('id','adman_account_id','ml_store_id')`) para **antes**, sobre o universo elegível completo — mesma quantidade de linhas, só reordenada, **não é query nova**. Substituir o corpo do `map()` pela chamada a `FinancialSourceResolver::resolverPorEmpresa()`.

**Local onde a marcação de D-03/D-04 deve entrar** — o array `quality` já é montado por linha (linhas 335-359, ver seção 13) — adicionar `quality.faturamento_fonte`/`quality.margem_fonte` (`'auto'|'manual'`) ali, sem tocar em `CompanyScoreSnapshotWriter::sync()` nem `CompanyScoreSnapshotReader::mapear()` (ambos tratam `quality` como blob opaco).

### 11. `app/Services/DesempenhoScoreService.php` — `computeUniverso()` (linha ~915) e `cacheKey()` (linha 449)

**Trecho atual do desempate em `computeUniverso()`** (linhas 890-927, confirmado):
```php
    private function computeUniverso(User $user, Carbon $mes): array
    {
        $vinculos = $this->carteiraContext->forUser($user, ['active' => true]);
        // ...
        $elegiveis = $vinculos->where('financial_metrics_eligible', true);
        $companyIdsElegiveis = $elegiveis->pluck('company_id')->unique();
        $companiesElegiveis = Company::whereIn('id', $companyIdsElegiveis)->get();

        // Mapa company_id → fonte financeira vencedora ('adman'|'shopee').
        // 'adman' vence quando a MESMA empresa tem os dois vínculos elegíveis.
        $fontes = $elegiveis
            ->groupBy('company_id')
            ->map(function (Collection $vs) {
                $sources = $vs->pluck('financial_source');
                return $sources->contains('adman') ? 'adman' : $sources->first();
            });
```
**Este é o call-site MAIS BARATO de corrigir** (RESEARCH Q2): `$companiesElegiveis` já é carregado **antes** do desempate (linha 907, antes de 911-916) — passar direto para `FinancialSourceResolver::resolverPorEmpresa($elegiveis, $companiesElegiveis->keyBy('id'))` sem nenhuma query nova.

**Trecho atual de `cacheKey()`** (linha 449, confirmado):
```php
        return sprintf('desempenho.compute.v19.%d.%s', $userId, $periodKey);
```
Bump obrigatório para `v20` **no mesmo commit** que tocar `computeUniverso()` — ver histórico de comentário acima da linha (padrão de changelog inline por versão de cache, seguir o mesmo estilo de comentário ao adicionar a entrada v20).

### 12. `app/Http/Controllers/PortfolioController.php` — `fontesFinanceirasPorEmpresa()` (linha 118-127)

**Trecho atual** (confirmado):
```php
    /**
     * Fase 109 (SHOP-CAR-01/02) — resolve a fonte financeira VENCEDORA de
     * cada empresa a partir dos vínculos ELEGÍVEIS do profissional (não do
     * vínculo bruto). REGRA DE DESEMPATE TRAVADA (decisão do usuário
     * 2026-07-23, texto idêntico ao Plano 03/Desempenho): quando a MESMA
     * empresa tem vínculo performance elegível E vínculo shopee elegível do
     * mesmo profissional, a fonte é SEMPRE 'adman' (performance vence) —
     * nunca soma as duas, nunca deixa a Shopee vencer.
     *
     * @param  Collection  $vinculos  Vínculos já resolvidos por CarteiraContextService::forUser().
     * @return Collection<int, string>  company_id => 'adman'|'shopee'
     */
    private function fontesFinanceirasPorEmpresa(Collection $vinculos): Collection
    {
        return $vinculos
            ->where('financial_metrics_eligible', true)
            ->groupBy('company_id')
            ->map(function (Collection $vs) {
                $fontes = $vs->pluck('financial_source');
                return $fontes->contains('adman') ? 'adman' : $fontes->first();
            });
    }
```
**Único call-site que exige query NOVA** (RESEARCH Q2): este método não carrega nenhum `Company` hoje — só itera a `Collection` de vínculos. É chamado por 4 métodos diferentes do controller (`transparencia()` linha 274, `renderCarteiraProfissional()` linha 581, `renderCarteirasConsolidadas()` linha 1339, `renderPortfolio()` linha 1604) — mas cada requisição HTTP só passa por um deles. Adicionar `Company::whereIn($companyIds)->get(['id','adman_account_id','ml_store_id'])->keyBy('id')` e repassar ao `FinancialSourceResolver`. **Docblock de regra travada acima também precisa de atualização de texto** — a regra deixa de ser "adman sempre vence", passa a ser "adman vence só se a empresa tiver `cust_id`".

### 13. `app/Services/Desempenho/CompanyScoreSnapshotWriter.php` — rastro de D-03

**Constantes de origem** (linhas 42-44, confirmado):
```php
public const ORIGEM_CONSOLIDAR_MES  = 'consolidar_mes';
public const ORIGEM_SNAPSHOT_DIARIO = 'snapshot_diario';
public const ORIGEM_WARM_CACHE      = 'warm_cache';
```

**Trava de congelamento com `lockForUpdate()` dentro de transação** (linhas 59-74, confirmado) — molde para D-09 (mês consolidado fica read-only) e para o padrão de concorrência do RESEARCH Q9 (race entre lançamento manual e `desempenho:consolidar-mes`):
```php
return DB::transaction(function () use ($user, $mesStr, $empresasScore, $origem) {
    if ($origem !== self::ORIGEM_CONSOLIDAR_MES) {
        $congelado = DesempenhoCompanyScoreSnapshot::query()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', $mesStr)
            ->where('origem', self::ORIGEM_CONSOLIDAR_MES)
            ->lockForUpdate()
            ->exists();

        if ($congelado) {
            return ['upserted' => 0, 'pruned' => 0, 'congelado' => true];
        }
    }
    // ...
```

**Canal recomendado para D-03/D-04 — `quality` já gravado inteiro, sem filtro** (linhas 90-111, confirmado):
```php
$dados = [
    // ...
    'nota_empresa_parcial'  => $linhaArray['nota_empresa_parcial'] ?? null,
    // quality grava o sub-array inteiro — o cast
    // `array` do model serializa.
    'quality'               => $linhaArray['quality'] ?? null,
    'origem'                => $origem,
    'gerado_em'             => now(),
];
```
**Recomendação do RESEARCH (Q5):** adicionar `quality.faturamento_fonte`/`quality.margem_fonte` dentro do MESMO array já montado em `CompanyScoreService.php` (seção 10) — propaga automaticamente por todo o pipeline sem tocar em `sync()` nem em `CompanyScoreSnapshotReader::mapear()`, porque ambos tratam `quality` como blob opaco. Se D-11 precisar de `GROUP BY`/`WHERE` eficiente sobre "é manual", considerar colunas nullable adicionais na migration da tabela de snapshot (opção B, trade-off documentado no RESEARCH Q5 — decisão do planner).

### 14. `resources/js/Pages/Performance/Show.jsx` + `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx` — selo D-04

**Ponto de consumo em `Show.jsx`** (linha 671, confirmado):
```jsx
<EmpresasScoreTabela linhas={empresas_score} resumo={empresas_score_resumo} />
```

**Componentes de célula já existentes, prontos para receber o selo** (`resources/js/Components/Desempenho/EmpresasScoreTabela.jsx:59-72,83-90,97-133`):
```jsx
function CelulaEmpresa({ linha }) {
    const marketplace = marketplaceLabel(linha.fonte_financeira);
    return (
        <td className="px-3 py-3">
            <div className="text-white/80 truncate max-w-[220px]">{linha.company_name}</div>
            {marketplace !== null && (
                <div className="text-[10px] tracking-wide text-white/30" title={MARKETPLACE_TOOLTIP}>
                    {marketplace}
                </div>
            )}
        </td>
    );
}

function CelulaFaturamento({ linha }) {
    return (
        <td className="px-3 py-3 tabular-nums">
            <div className={corFaturamento(linha.faturamento_var_pct)}>{fmtVarPct(linha.faturamento_var_pct)}</div>
            <div className="text-[10px] text-white/30">{fmtNotaEmpresa(linha.faturamento_pontos)} pontos</div>
        </td>
    );
}
```

**Padrão real de selo com `title=` para tooltip (mesmo espírito do "valor lançado manualmente" de D-04)** — `SELO_SHOPEE_TEXTO`/`SELO_SHOPEE_TITULO`, definidos em `resources/js/lib/desempenhoLabels.js:171,173` e usados em `EmpresasScoreTabela.jsx:107-113`:
```js
// resources/js/lib/desempenhoLabels.js:171,173
export const SELO_SHOPEE_TEXTO = 'Shopee: sem dado de margem';
export const SELO_SHOPEE_TITULO = 'A Shopee não fornece margem. A empresa entra na conta com 1,00 ponto fixo — é limitação da fonte, não desempenho ruim.';
```
```jsx
// resources/js/Components/Desempenho/EmpresasScoreTabela.jsx:107-113
<span
    title={SELO_SHOPEE_TITULO}
    className="inline-flex items-center gap-1 rounded-full border border-amber-500/25 bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-200"
>
    {SELO_SHOPEE_TEXTO}
</span>
```
Adicionar `SELO_MANUAL_TEXTO`/`SELO_MANUAL_TITULO` no mesmo arquivo de constantes, e renderizar condicionalmente com base em `linha.quality?.faturamento_fonte === 'manual'` / `linha.quality?.margem_fonte === 'manual'`.

**Tensão de granularidade a resolver no planning (RESEARCH Open Question 1):** D-04 diz "a linha... recebe marcador" (singular), mas D-07 torna faturamento e margem independentes. Como `quality.faturamento_fonte`/`margem_fonte` já suportam os dois formatos sem mudança de schema, decidir: selo único em `CelulaEmpresa` (perde granularidade de qual eixo) ou dois selos (em `CelulaFaturamento` e/ou `CelulaMargem`, mais preciso). Não decidido pelo RESEARCH — decisão do planner.

### 15. `routes/web.php` — rota nova admin-only

Ver seção 6 acima (padrão de registro) — inserir dentro do bloco de linhas 541-560 (vizinho de `/desempenho/auditoria-bonus`), com `->middleware('role:admin')` **por rota**, nunca dentro do grupo largo que fecha em 377.

### 16. Os 5 arquivos do gate de hash da Fase 119

**Confirmado nesta sessão (grep) — os 5 arquivos e linhas exatas da constante:**
```
tests/Feature/Phase119/CompanyScoreServiceDispatcherTest.php:46
tests/Feature/Phase119/CompanyScoreServiceFonteTest.php:52
tests/Feature/Phase119/CompanyScoreServiceMargemTest.php:44
tests/Feature/Phase119/CompanyScoreServiceReconciliacaoTest.php:46
tests/Feature/Phase119/CompanyScoreServiceStatusTest.php:40
```
Todos com o mesmo valor literal:
```php
private const HASH_DESEMPENHO_SCORE_SERVICE = '5b6cb40da43773c19c24c1bbf8b6dffe20672cc6b223e8cc8f27676473064f24';
```
E o helper que compara (idêntico nos 5, linhas 162-186 conforme arquivo — ex. `CompanyScoreServiceFonteTest.php:180-184`):
```php
private function assertHashDesempenhoScoreServiceIntocado(): void
{
    $hash = hash_file('sha256', app_path('Services/DesempenhoScoreService.php'));
    $this->assertSame(self::HASH_DESEMPENHO_SCORE_SERVICE, $hash, 'DesempenhoScoreService.php foi alterado — fase é ADITIVA.');
}
```
**Como rotacionar (padrão já usado 4 vezes no histórico do próprio arquivo, ver comentários acima da constante em `CompanyScoreServiceFonteTest.php:48-51`):** após tocar `DesempenhoScoreService.php:915` (D-10), rodar `hash_file('sha256', ...)` via tinker (`C:\xampp\php\php.exe artisan tinker --execute="echo hash_file('sha256', app_path('Services/DesempenhoScoreService.php'));"`) e substituir a constante nos 5 arquivos **no mesmo commit**, adicionando uma linha de changelog acima seguindo o padrão:
```php
/** Rotacionado pela Fase 136 (D-10) — desempate de fonte financeira passa a checar cust_id. */
private const HASH_DESEMPENHO_SCORE_SERVICE = '<novo hash>';
```

### 17. Arquivos de teste com a chave de cache hardcoded — DIVERGÊNCIA do CONTEXT.md confirmada

**O CONTEXT.md lista 6 arquivos; o grep desta sessão confirma que só 4 têm a string literal `desempenho.compute.v19`:**
```
tests/Feature/V18/DesempenhoMetadadosCacheTest.php:232,258,260,277
tests/Feature/DesempenhoShopeeScoreTest.php:407
tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php:246,348
tests/Feature/Phase116/NpsFloorDesempenhoTest.php:427
```
**Estes 4 precisam de edição textual** `v19` → `v20` no mesmo commit do bump de `cacheKey()` (seção 11).

**Os outros 2 do CONTEXT.md — `tests/Feature/V16/BonusDualPathRegressaoTest.php` e `tests/Feature/V16/DesempenhoElegibilidadeTest.php` — NÃO contêm `v19`** (confirmado por grep, zero ocorrências): usam o helper dinâmico `$service->cacheKey()` para a chave atual esperada, e só hardcodam `v5` como valor de teste histórico órfão da Fase 105. Não precisam de edição — rodar como parte da suíte de regressão, sem tocar:
```php
// tests/Feature/V16/BonusDualPathRegressaoTest.php:528-542 (não editar)
$chaveV5 = sprintf('desempenho.compute.v5.%d.%s', $analista->id, $mes->format('Y-m'));
$chaveV6 = $service->cacheKey($analista->id, $mes); // dinâmico — acompanha qualquer bump futuro
```

---

## Shared Patterns

### Autenticação/autorização admin-only (defesa dupla)
**Fonte:** `app/Http/Middleware/EnsureUserHasRole.php` (íntegro) + `app/Http/Requests/UpdateBonusFaixaRequest.php:42-45`
**Aplicar a:** controller da grade admin, FormRequest de lançamento, comando de relatório (checagem de contexto não se aplica a CLI, mas o endpoint HTTP do relatório se houver).
```php
// app/Http/Middleware/EnsureUserHasRole.php:16-21
public function handle(Request $request, Closure $next, string ...$roles): Response
{
    if (!$request->user() || !in_array($request->user()->role, $roles)) {
        abort(403, 'Acesso não autorizado.');
    }
    return $next($request);
}
```
```php
// app/Http/Requests/UpdateBonusFaixaRequest.php:42-45 — segunda camada, nunca `?? false` implícito
public function authorize(): bool
{
    return $this->user()?->isAdmin() === true;
}
```

### Trilha de auditoria via `spatie/laravel-activitylog`
**Fonte:** `app/Models/BonusInvalidacao.php:38-49`
**Aplicar a:** `DesempenhoMetricaManual` (D-12) — nenhuma infraestrutura nova, `config/activitylog.php` e a tabela `activity_log` já existem.

### Trava de congelamento por `origem` + `lockForUpdate()` em transação
**Fonte:** `app/Services/Desempenho/CompanyScoreSnapshotWriter.php:42-44,59-74`
**Aplicar a:** validação de D-09 no FormRequest/Controller do lançamento manual (reaproveitar a MESMA query de trava, não inventar flag paralela) e à escrita da tabela nova sob race com `desempenho:consolidar-mes`.

### `quality` como canal de sinal opaco, propagado sem filtro
**Fonte:** `CompanyScoreService.php:353-358` → `CompanyScoreSnapshotWriter.php:106-108` → `CompanyScoreSnapshotReader.php:156` → `EmpresasScoreTabela.jsx` (`linha?.quality?.motivos`)
**Aplicar a:** sinal de D-03/D-04 (`quality.faturamento_fonte`/`quality.margem_fonte`) — menor superfície de mudança possível no pipeline.

### `MetricPeriodResolver::resolve(['period_key' => 'YYYY-MM'])` — único ponto de resolução de período
**Fonte:** `app/Services/Metrics/MetricPeriodResolver.php:225-250` (não citado em detalhe aqui — íntegro no RESEARCH Q4)
**Aplicar a:** D-05 (janela mês-cheio da célula manual) e D-06 (cascata do lado base) — nunca calcular `now()->startOfMonth()` nem dias manualmente.

### Selo com `title=` para tooltip discreto
**Fonte:** `resources/js/lib/desempenhoLabels.js:171-173` + `EmpresasScoreTabela.jsx:107-113`
**Aplicar a:** selo "valor lançado manualmente" de D-04.

### Célula editável inline (exibição → input → salvar/cancelar)
**Fonte:** `resources/js/Pages/Polos/components/CustIdCell.jsx` (íntegro)
**Aplicar a:** grade empresa × mês da tela nova — é o único componente do projeto inteiro com este ciclo completo já pronto.

---

## No Analog Found

| Arquivo | Papel | Fluxo de dado | Razão |
|---|---|---|---|
| `resources/js/Pages/Desempenho/MetricasManuais.jsx` (nome sugerido) | component | CRUD (grade editável empresa × mês) | Nenhuma página do projeto é uma grade editável em lote empresa×mês. **Convenção a seguir mesmo assim:** compor `Auditoria.jsx` (estrutura de página admin-only + seletor de competência) + `CustIdCell.jsx` (ciclo de edição inline) + `Configuracao.jsx` (`useForm`/`errors`/`processing` quando o submit precisar de mais de um campo). Nunca criar como wrapper de re-export (risco de sumir do manifest do Vite). |
| `app/Services/Metrics/ManualMetricOverrideService.php` (nome sugerido) | service (decorator) | transform | Nenhum decorator sobre `MetricDiffDispatcher::compute()` existe hoje — é o primeiro. **Convenção a seguir:** preservar EXATAMENTE o shape documentado em `ShopeeMetricDiffService.php:62-72`/`AdmanMetricDiffService.php:97-106`, `diff_source='manual_mes_calendario'` (D-05), chamado depois do dispatcher, nunca dentro do `match()` dele (ver seção 4). |

---

## Metadata

**Escopo de busca de analogs:** `app/Models/`, `app/Services/Metrics/`, `app/Services/Desempenho/`, `app/Http/Controllers/`, `app/Http/Requests/`, `app/Console/Commands/`, `database/migrations/`, `resources/js/Pages/Desempenho/`, `resources/js/Pages/Polos/components/`, `resources/js/Components/Desempenho/`, `resources/js/lib/`, `tests/Feature/Phase119/`, `tests/Feature/V16/`, `tests/Feature/V18/`, `tests/Feature/Phase96/`, `tests/Feature/Phase116/`.
**Arquivos lidos nesta sessão de mapeamento (além do RESEARCH.md, já lido integralmente pela pesquisa anterior):** `database/migrations/2026_08_11_120100_create_onboardings_tables.php`, `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php`, `app/Models/BonusInvalidacao.php`, `app/Models/DesempenhoCompanyScoreSnapshot.php`, `app/Services/Metrics/MetricDiffDispatcher.php`, `app/Services/Metrics/ShopeeMetricDiffService.php`, `app/Http/Requests/UpdateBonusFaixaRequest.php`, `app/Http/Controllers/BonusAuditoriaController.php`, `app/Http/Controllers/DesempenhoConfigController.php`, `app/Http/Middleware/EnsureUserHasRole.php`, `app/Console/Commands/VerificarConsolidacaoDesempenho.php`, `app/Services/Desempenho/CompanyScoreService.php` (trechos), `app/Services/DesempenhoScoreService.php` (trechos), `app/Services/Desempenho/CompanyScoreSnapshotWriter.php` (trechos), `app/Http/Controllers/PortfolioController.php` (trecho), `resources/js/Pages/Desempenho/Auditoria.jsx`, `resources/js/Pages/Desempenho/Configuracao.jsx` (trecho), `resources/js/Pages/Polos/components/CustIdCell.jsx`, `resources/js/Pages/Polos/Painel.jsx` (trecho), `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx` (trechos), `resources/js/lib/desempenhoLabels.js` (trecho), `resources/js/Pages/Performance/Show.jsx` (trecho), `routes/web.php` (trechos), `tests/Feature/Phase119/CompanyScoreServiceFonteTest.php` (linhas 1-210), `tests/Feature/V16/CriaCenarioResponsaveis.php` (íntegro).
**Data do mapeamento:** 2026-08-11
