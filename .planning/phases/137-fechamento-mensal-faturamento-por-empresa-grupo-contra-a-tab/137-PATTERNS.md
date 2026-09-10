# Phase 137: Fechamento mensal — faturamento por empresa/grupo contra a tabela progressiva - Pattern Map

**Mapeado em:** 2026-09-02
**Arquivos analisados:** 19 (11 novos, 8 modificados)
**Análogos encontrados:** 17 / 19

## File Classification

| Arquivo novo/modificado | Papel | Fluxo de dados | Análogo mais próximo | Qualidade do match |
|---|---|---|---|---|
| `database/migrations/..._create_servico_faixas_faturamento_table.php` | migration | CRUD | `database/migrations/2026_07_09_140002_create_bonus_faixas_table.php` | exato |
| `database/migrations/..._create_empresa_faixas_faturamento_table.php` | migration | CRUD | mesmo acima | exato |
| `app/Models/ServicoFaixaFaturamento.php` | model | CRUD | `app/Models/BonusFaixa.php` | exato |
| `app/Models/EmpresaFaixaFaturamento.php` | model | CRUD | `app/Models/BonusFaixa.php` | exato |
| `app/Http/Requests/UpdateFaixaFaturamentoRequest.php` (ou 2 requests) | middleware/validation | request-response | `app/Http/Requests/UpdateBonusFaixaRequest.php` | exato |
| `app/Http/Controllers/FechamentoFaixasController.php` (ou método em `AdminController`) | controller | CRUD | `app/Http/Controllers/DesempenhoConfigController.php` | exato |
| `database/migrations/..._create_fechamento_snapshots_table.php` | migration | CRUD | `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` | exato |
| `database/migrations/..._create_fechamento_grupo_snapshots_table.php` | migration | CRUD | mesmo acima | role-match (granularidade nova: grupo, não empresa) |
| `app/Models/FechamentoSnapshot.php` | model | CRUD | `app/Models/DesempenhoCompanyScoreSnapshot.php` | exato |
| `app/Models/FechamentoGrupoSnapshot.php` | model | CRUD | `app/Models/DesempenhoCompanyScoreSnapshot.php` | role-match |
| `app/Services/Fechamento/FechamentoSnapshotWriter.php` | service | event-driven/batch | `app/Services/Desempenho/CompanyScoreSnapshotWriter.php` | exato |
| `app/Services/Fechamento/FechamentoRollupService.php` (soma ML+Shopee por empresa/grupo) | service | batch/transform | `app/Services/Metrics/ShopeeMetricDiffService.php` (`naJanela()`) + `AdminController::fechamento()` (agregação `adman_metrics`) | role-match |
| `app/Console/Commands/ConsolidarMesFechamento.php` | command | batch | `app/Console/Commands/ConsolidarMesDesempenho.php` | exato |
| `app/Console/Commands/VerificarConsolidacaoFechamento.php` | command | batch (read-only) | `app/Console/Commands/VerificarConsolidacaoDesempenho.php` | exato |
| `app/Http/Controllers/AdminController.php` (`fechamento()`, `gerarRelatorio()`, `gerarRelatorioGeral()`) | controller | request-response | ele mesmo (evolução, não reescrita) | exato — código já lido, ver excertos abaixo |
| `app/Jobs/EnviarRelatorioFechamentoJob.php` | service/event-driven | event-driven | ele mesmo (evolução) | exato |
| `resources/js/Pages/Admin/Financeiro.jsx` | component | request-response | ele mesmo (evolução) | exato |
| `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` (se extraído) | component | CRUD (form) | Dialog de contrato já dentro de `Financeiro.jsx` (`ContratosSection` + modal correlato) | role-match |
| `app/Models/ContratoServicoObserver.php` (possível 3º gancho para D-03) | event-driven hook | event-driven | `app/Models/ContratoServico.php` `#[ObservedBy(...)]` | role-match |

## Pattern Assignments

### `database/migrations/..._create_servico_faixas_faturamento_table.php` e `..._create_empresa_faixas_faturamento_table.php` (migration, CRUD)

**Análogo:** `database/migrations/2026_07_09_140002_create_bonus_faixas_table.php`

**Padrão a copiar** (arquivo inteiro é curto, 78 linhas — já lido por completo):
```php
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('servico_faixas_faturamento')) {
            return; // idempotente — guard igual ao de bonus_faixas
        }

        Schema::create('servico_faixas_faturamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('servico_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->default(0);
            // null = "sem teto" (última faixa) — NÃO usar 0 ou 999999999 como sentinela.
            $table->decimal('limite_superior', 16, 2)->nullable();
            $table->decimal('valor', 10, 2);
            $table->timestamps();

            // Nome de índice EXPLÍCITO e CURTO — ver Pitfall 1 abaixo.
            $table->index(['servico_id', 'ordem'], 'sff_servico_ordem_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servico_faixas_faturamento');
    }
};
```
Para `empresa_faixas_faturamento`, trocar `servico_id` por `company_id` (FK
para `companies`, `cascadeOnDelete()` — mesma decisão de design do Pitfall 2
abaixo: linha órfã sem valor de auditoria quando a empresa é apagada).

⚠️ **Pitfall 1 (medido pela pesquisa):** `fechamento_faturamento_snapshots`
combinado com `unique(['company_id', 'mes_referencia'])` gera nome default
de **65 caracteres** — MariaDB recusa (erro 1059), o índice fica faltando e
a migration marca "Ran" mesmo assim. Usar nomes curtos de tabela
(`fechamento_snapshots`, não `fechamento_faturamento_snapshots`) e **sempre**
nomear o índice a mão, como `desempenho_company_score_snapshots` já faz
(`dcss_user_company_mes_unique`,
`database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php:94`).

⚠️ **Pitfall 4:** se alguma coluna nova usar lista fixa de valores (`evolucao`
= subiu/manteve/caiu, `origem` do snapshot, `tabela_origem` =
herdada/própria), usar **`string()`, nunca `enum()`** — o CHECK do SQLite dos
testes quebra ao surgir valor novo. Comentário de referência já existe na
migration de `desempenho_company_score_snapshots` (linha ~44-47).

---

### `app/Models/ServicoFaixaFaturamento.php` / `EmpresaFaixaFaturamento.php` (model, CRUD)

**Análogo:** `app/Models/BonusFaixa.php`

**Padrão de model configurável com auditoria** (lido por completo, 116 linhas):
```php
class ServicoFaixaFaturamento extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['servico_id', 'ordem', 'limite_superior', 'valor'];

    protected $casts = [
        'ordem'            => 'int',
        'limite_superior'  => 'decimal:2',
        'valor'            => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('faixa_faturamento')
            ->setDescriptionForEvent(
                fn (string $event) => "Faixa de faturamento (ordem {$this->ordem}) foi {$event}"
            );
    }

    public function scopeOrdenadas($query)
    {
        return $query->orderBy('ordem');
    }
}
```
`BonusFaixa::classificar(float $nota): ?self` (linhas 96-107 do arquivo) é o
molde direto do método `calcularFaixa()` a construir aqui — troque a
comparação `nota_min <= x <= nota_max` por `limite_superior IS NULL OR x <=
limite_superior`, ordenado por `ordem ASC`, primeira que casar.

**Resolução de herança (D-01, Company → serviço com exceção):** modelar como
método em `Company`, no molde de `CobrancaCalculator::novo()` que já busca
`valor_padrao` do serviço com override do contrato — aqui a "herança" é entre
`empresa_faixas_faturamento` (se existir QUALQUER linha, D-13 all-or-nothing)
e `servico_faixas_faturamento` do serviço de Gestão/ML ativo da empresa.

---

### `app/Http/Requests/UpdateFaixaFaturamentoRequest.php` (validation)

**Análogo:** `app/Http/Requests/UpdateBonusFaixaRequest.php` (arquivo inteiro
lido, 130 linhas)

**Padrão de validação com regra composta (`withValidator`) para sobreposição:**
```php
public function authorize(): bool
{
    return $this->user()?->isAdmin() === true;
}

public function rules(): array
{
    return [
        'ordem'            => ['required', 'integer', 'min:0'],
        'limite_superior'  => ['nullable', 'numeric', 'min:0'],
        'valor'            => ['required', 'numeric', 'min:0'],
    ];
}

public function withValidator(Validator $validator): void
{
    $validator->after(function (Validator $v) {
        // Mesmo algoritmo de sobreposição de intervalos fechados que
        // UpdateBonusFaixaRequest usa para nota_min/nota_max — aqui aplicado
        // a limite_superior (faixa i vai de limite[i-1] exclusivo até
        // limite[i] inclusivo). Copiar a MESMA disciplina: mensagem única,
        // não iterar o resto após achar 1 sobreposição.
    });
}
```
Copy exato do algoritmo de sobreposição está em
`app/Http/Requests/UpdateBonusFaixaRequest.php:95-115`
(`$novoMin <= $outraMax && $novoMax >= $outraMin`).

---

### `app/Http/Controllers/FechamentoFaixasController.php` (controller, CRUD)

**Análogo:** `app/Http/Controllers/DesempenhoConfigController.php` (lido por
completo, 132 linhas)

**Padrão CRUD simples de 1 tabela, sem service dedicado:**
```php
class FechamentoFaixasController extends Controller
{
    public function updateFaixa(UpdateFaixaFaturamentoRequest $request, ServicoFaixaFaturamento $faixa)
    {
        $faixa->update($request->validated());

        return back()->with('success', "Faixa atualizada com sucesso.");
    }
}
```
Route model binding implícito em `{faixa}`, mesmo padrão da rota
`/desempenho/configuracao/faixas/{faixa}`. Guard duplo: middleware
`role:admin` no grupo de rotas + `authorize()` no FormRequest — **já é a
convenção do grupo `administrativo` em `routes/web.php:1393`**
(`Route::middleware(['auth', 'verified', 'role:admin'])->prefix('administrativo')->name('admin.')`).
As novas rotas de faixas devem entrar **dentro** desse grupo existente, não
criar um grupo novo.

---

### `database/migrations/..._create_fechamento_snapshots_table.php` / `..._create_fechamento_grupo_snapshots_table.php` (migration, CRUD)

**Análogo:** `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php`
(arquivo inteiro lido, 107 linhas)

**Padrão a copiar (índices nomeados, FK sem nullOnDelete, coluna de mês
normalizada, JSON para sub-estrutura):**
```php
Schema::create('fechamento_snapshots', function (Blueprint $table) {
    $table->id();

    // Cascade + NOT NULL — mesma decisão de design de
    // desempenho_company_score_snapshots.company_id: snapshot de empresa
    // apagada não tem valor de auditoria, então some junto (evita o
    // Pitfall 2 de FK nullOnDelete sem coluna nullable).
    $table->foreignId('company_id')->constrained()->cascadeOnDelete();

    $table->date('mes_referencia'); // sempre YYYY-MM-01

    $table->string('company_name')->nullable(); // nome no momento do congelamento

    $table->decimal('faturamento_ml', 16, 2)->nullable();
    $table->decimal('faturamento_shopee', 16, 2)->nullable();
    $table->decimal('faturamento_total', 16, 2)->nullable();

    $table->foreignId('servico_id')->nullable()->constrained()->nullOnDelete();
    // ^ aqui SIM nullable() ANTES de nullOnDelete() — Pitfall 2 (erro 1830).

    $table->string('faixa_aplicada')->nullable();   // STRING, não enum (Pitfall 4)
    $table->decimal('valor_faixa', 10, 2)->nullable();
    $table->string('evolucao')->nullable();          // subiu | manteve | caiu — STRING
    $table->string('tabela_origem')->nullable();      // herdada | propria — STRING

    $table->string('origem', 32); // 'consolidar_mes' | outra origem futura
    $table->unsignedBigInteger('reconsolidado_por')->nullable(); // D-12: user_id de quem refez
    $table->text('motivo_reconsolidacao')->nullable();           // D-12: auditoria do "porquê"
    $table->timestamp('gerado_em');

    $table->timestamps();

    // Nome CURTO e EXPLÍCITO — ver Pitfall 1.
    $table->unique(['company_id', 'mes_referencia'], 'fecha_snap_empresa_mes_unique');
    $table->index(['mes_referencia'], 'fecha_snap_mes_idx');
});
```
Para `fechamento_grupo_snapshots`, trocar `company_id` por `company_group_id`
(FK para `company_groups`, também `cascadeOnDelete()` — mesmo raciocínio) e
`unique(['company_group_id', 'mes_referencia'], 'fecha_grupo_snap_mes_unique')`.

⚠️ **D-12 (revisado) exige campos de auditoria da reconsolidação** que o
molde do Desempenho não tem (o Desempenho permite rerun mas não registra
quem/quando/por quê no schema — só via `Log`). A Fase 137 precisa desses
campos **na própria tabela** (`reconsolidado_por`, `motivo_reconsolidacao`,
ou uma tabela de histórico separada), porque D-11 diz explicitamente "por
que" precisa ser auditável, e o dialog `RefazerFechamentoDialog` do UI-SPEC
já captura um campo de motivo obrigatório.

---

### `app/Models/FechamentoSnapshot.php` / `FechamentoGrupoSnapshot.php` (model, CRUD)

**Análogo:** `app/Models/DesempenhoCompanyScoreSnapshot.php` — não lido nesta
sessão (fora do orçamento de leitura), mas sua migration irmã já foi lida por
completo acima; o model segue o padrão trivial de casts + `$fillable` que
`CompanyScoreSnapshotWriter.php` documenta no array `$dados` (linhas 84-102
do writer, já citado abaixo) — sem lógica própria de cálculo, só persistência.

---

### `app/Services/Fechamento/FechamentoSnapshotWriter.php` (service, event-driven/batch)

**Análogo:** `app/Services/Desempenho/CompanyScoreSnapshotWriter.php` (arquivo
inteiro lido, 193 linhas)

**Padrão de writer idempotente com trava de congelamento (upsert + prune em
transação):**
```php
class FechamentoSnapshotWriter
{
    public const ORIGEM_CONSOLIDAR_MES = 'consolidar_mes';

    /**
     * @return array{upserted: int, pruned: int, congelado: bool}
     */
    public function sync(Carbon $mes, iterable $linhasEmpresa, string $origem, ?int $reconsolidadoPor = null, ?string $motivo = null): array
    {
        $mesStr = $mes->copy()->startOfMonth()->toDateString();

        return DB::transaction(function () use ($mesStr, $linhasEmpresa, $origem, $reconsolidadoPor, $motivo) {
            // Trava de congelamento — diferente do Desempenho, aqui D-12
            // pede que reconsolidar EXIJA motivo explícito (não é ignorada
            // silenciosamente como no molde original).
            $jaCongelado = FechamentoSnapshot::query()
                ->whereDate('mes_referencia', $mesStr)
                ->where('origem', self::ORIGEM_CONSOLIDAR_MES)
                ->lockForUpdate()
                ->exists();

            if ($jaCongelado && $motivo === null) {
                throw new \RuntimeException('Competência já fechada — motivo de reconsolidação é obrigatório.');
            }

            // upsert com whereDate() — NUNCA updateOrCreate(['mes_referencia' => $mesStr])
            // direto: o cast `date` grava datetime completo e a comparação
            // string crua nunca bate (mesma armadilha documentada no writer
            // original, linhas ~112-119).
            foreach ($linhasEmpresa as $linha) {
                $existente = FechamentoSnapshot::query()
                    ->where('company_id', $linha['company_id'])
                    ->whereDate('mes_referencia', $mesStr)
                    ->first();
                // ...fill+save ou create...
            }

            // prune: convergir para o conjunto atual, nunca insert-only.
        });
    }
}
```
**Trava de congelamento (linhas 63-72 do original):**
```php
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
```
⚠️ **Divergência deliberada de D-12 em relação ao molde:** o writer do
Desempenho deixa `consolidar_mes` **ignorar** a trava sem exigir motivo. A
Fase 137, por D-12 revisado, precisa **recusar reconsolidar sem motivo
explícito** — não copiar esse trecho literalmente, adaptar a condição.

---

### `app/Services/Fechamento/FechamentoRollupService.php` (service, batch/transform)

**Análogos combinados:**
1. `app/Services/Metrics/ShopeeMetricDiffService.php:159-167` (`naJanela()`) — rollup Shopee
2. `app/Http/Controllers/AdminController.php:179-191` — rollup ML já existente

**Padrão ML (SUM direto em `adman_metrics`, mês-calendário — já é o que
`fechamento()` faz para "mês passado", trecho lido):**
```php
$metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
    ->whereNotNull('revenue')
    ->selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as periodo_inicio, MAX(reference_date) as periodo_fim')
    ->groupBy('company_id')
    ->get()
    ->keyBy('company_id');
```

**Padrão Shopee (`naJanela()`, mesma disciplina de `whereDate` em vez de
`whereBetween` — comentário explícito no arquivo original, linhas 168-179):**
```php
ShopeeMetric::where('company_id', $company->id)
    ->whereDate('reference_date', '>=', $inicioMes)
    ->whereDate('reference_date', '<=', $fimMes)
    ->sum('revenue');
```
Comentário-chave do arquivo original a preservar: `reference_date` é
persistido como datetime (`Y-m-d 00:00:00`); `whereBetween` puro compararia
como string e excluiria o último dia da janela — `whereDate` evita isso.

**Janela de mês-calendário fechado (D-06) — usar, não reinventar Carbon:**
```php
$periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => $mesFechado]); // 'YYYY-MM'
$inicio  = $periodo['current_start'];
$fim     = $periodo['current_end'];
```
Fonte: `app/Services/Metrics/MetricPeriodResolver.php:225` (`resolveSpecificMonth`),
trecho lido: `$monthStart = Carbon::parse($periodKey . '-01', self::TIMEZONE)->startOfMonth()->startOfDay();`
— NÃO usar `Carbon::createFromFormat('Y-m', ...)` sem o dia (mesmo pitfall
documentado em `ConsolidarMesDesempenho.php:118-127`, ver Shared Patterns).

⚠️ **Não usar `AdmanMetricDiffService`** para o fechamento — é HTTP-first,
orientado a variação percentual para bônus, faria chamada de rede
desnecessária todo dia 1 (Pitfall 7 do RESEARCH). Usar `SUM(adman_metrics.revenue)`
direto, como acima.

⚠️ **Não usar `company_monthly_revenues`/`CompanyMonthlyRevenue`** como fonte
do congelamento — fica com valor rolling-30-dias obsoleto quando o mês vira
(achado da pesquisa, `AdmanService::syncMonthRevenue()` linhas 1190-1219).

---

### `app/Console/Commands/ConsolidarMesFechamento.php` (command, batch)

**Análogo:** `app/Console/Commands/ConsolidarMesDesempenho.php` (378 linhas —
cabeçalho/derivação de mês lido nas linhas 1-140; padrão suficiente para
replicar)

**Padrão de derivação segura do mês-alvo (linhas 118-127 do original, texto
exato a preservar):**
```php
// Rule 1 (bug pré-existente exposto pela v18.0 D2): NÃO usar
// createFromFormat('Y-m', ...) — sem o dia explícito, o PHP preenche o dia
// com o de "hoje" e ESTOURA para o mês seguinte quando o mês alvo tem menos
// dias (ex.: hoje=31, --mes=2026-06 vira 2026-07-01, não 2026-06-01).
// Ancorar no dia 1 explícito elimina o overflow.
$mes = Carbon::createFromFormat('Y-m-d', $mesOption . '-01')->startOfMonth();
```

**Signature e default:**
```php
protected $signature = 'fechamento:consolidar-mes
    {--mes= : YYYY-MM (default = mês anterior ao hoje)}
    {--motivo= : obrigatório se a competência já estiver congelada (D-12)}';
```

⚠️ **Open Question 1 do RESEARCH, decisão que o planner deve travar:** ao
contrário de `desempenho:consolidar-mes` (que permite rerun de propósito sem
exigir motivo), o comando da Fase 137 **precisa recusar reprocessar sem
`--motivo=`** — D-12 exige registro de quem/quando/por quê, e o dialog
`RefazerFechamentoDialog` do UI-SPEC já modela esse campo como obrigatório
no front. Não copiar a trava do Desempenho literalmente.

**Gate de qualidade antes de persistir** — o Desempenho recusa gravar amostra
degradada e preserva o snapshot anterior (FIXMARG-03,
`ConsolidarMesDesempenho.php` comentário de classe, linhas 55-73, já lido).
Adaptação para a Fase 137: se `faturamento_total` vier nulo/zero para uma
empresa com integração ativa (ML ou Shopee configurados mas soma zero por
falha de sync), não gravar `R$ 0` silencioso — usar o placeholder `A DEFINIR`
(ver Shared Patterns) e logar, nunca sobrescrever um snapshot bom anterior
com um degradado.

---

### `app/Console/Commands/VerificarConsolidacaoFechamento.php` (command, batch read-only)

**Análogo:** `app/Console/Commands/VerificarConsolidacaoDesempenho.php`
(cabeçalho + signature lidos, linhas 1-90 — padrão suficiente)

**Padrão de contrato (`--json` + exit code, nunca o texto impresso):**
```php
protected $signature = 'fechamento:verificar-consolidacao
    {--mes= : YYYY-MM (default = mês anterior ao hoje)}
    {--json : saída em JSON, parseável, sem nenhum outro texto}';

protected $description = 'Confere uma competência do fechamento por RECONSULTA (read-only) às tabelas de snapshot. Exit code é o veredito, nunca o texto impresso.';
```
Docblock a espelhar (linhas 15-25 do original): "NENHUMA linha do texto que
ele mesmo imprime é critério de verificação — o contrato real é a saída
`--json` e o EXIT CODE. SUCCESS só acontece com ZERO inconsistências."

**Classes de inconsistência a adaptar** (5 no original — mapa direto para a
Fase 137):
| Original (Desempenho) | Equivalente na Fase 137 |
|---|---|
| `SEM_SNAPSHOT` | Empresa ativa sem `fechamento_snapshots` na competência |
| `SEM_LINHAS` | (não se aplica — não há resumo separado do detalhe, avaliar se necessário) |
| `LINHAS_ORFAS` | `fechamento_grupo_snapshots` sem nenhuma empresa-membro em `fechamento_snapshots` |
| `DIVERGENCIA_*` | `faturamento_total` do grupo ≠ SOMA das linhas de empresa do grupo (D-10 exige que sejam a mesma fonte, nunca recalculada em paralelo) |
| `ORIGEM_NAO_CONGELADA` | Competência fechada (mês < corrente) com linha cuja `origem` ≠ `consolidar_mes` |

---

## Shared Patterns

### "Nunca confiar em stdout para conferir consolidação"
**Fonte:** `.planning/learnings/desempenho-bonificacao.md` §4 e §10.1 +
`app/Console/Commands/VerificarConsolidacaoDesempenho.php` (docblock, linhas
15-25)
**Aplica-se a:** `ConsolidarMesFechamento`, `VerificarConsolidacaoFechamento`,
qualquer teste de integração da Fase 137.
O gate de qualidade do `desempenho:consolidar-mes` reportou sucesso (exit
code 0) por semanas enquanto rows falhavam silenciosamente. A Fase 137 deve
tratar "o comando de verificação read-only com `--json`+exit code" como
critério de "fase funciona", não nice-to-have — e qualquer conferência
manual durante a implementação deve ser por **reconsulta ao banco**
(`SELECT` na tabela de snapshot), nunca pelo texto que o comando imprime.

### Nome de índice curto e explícito (MariaDB 64 chars, erro 1059)
**Fonte:** `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php:83-94`
**Aplica-se a:** `fechamento_snapshots`, `fechamento_grupo_snapshots`,
`servico_faixas_faturamento`, `empresa_faixas_faturamento` — qualquer
migration nova da fase com índice composto/unique.
Nomear a tabela curta e **sempre** passar o segundo argumento nomeado em
`$table->unique([...], 'nome_curto')`/`$table->index([...], 'nome_curto')`.

### FK `nullOnDelete()` exige `->nullable()` (MariaDB erro 1830)
**Fonte:** `.planning/learnings/desempenho-bonificacao.md` §6 +
`database/migrations/2026_06_11_120000_create_company_groups_table.php:23-27`
(`nullable()` vem antes de `constrained()->nullOnDelete()`)
**Aplica-se a:** qualquer FK opcional nova (ex.: `servico_id` nullable em
`fechamento_snapshots`). SQLite dos testes não pega — só falha no deploy
MariaDB.

### Coluna de lista fixa é `string()`, nunca `enum()`
**Fonte:** `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php`
comentário linhas ~44-47
**Aplica-se a:** `evolucao` (subiu/manteve/caiu), `origem`, `tabela_origem`
(herdada/própria), `faixa_aplicada`. O CHECK do SQLite dos testes quebra ao
surgir valor novo — usar `string()` sempre.

### Ausência visível — placeholder `A DEFINIR`
**Fonte:** `app/Services/ContratoPdfService.php:51,`
`resolverOuPendente()` (linhas 512-524, lido por completo)
```php
private function resolverOuPendente(?string $valor, string $chave, array &$camposPendentes): string
{
    if (is_string($valor) && $valor !== '') {
        return $valor;
    }
    $camposPendentes[] = $chave;
    return self::PLACEHOLDER; // 'A DEFINIR'
}
```
**Aplica-se a:** empresa sem tabela de faixas (própria nem do serviço) e
empresa sem faturamento no mês — o UI-SPEC já reserva exatamente esse texto
(`Tabela de faixas: A DEFINIR`). Reusar o **literal** `'A DEFINIR'`, não
reinventar string equivalente — é convenção de copy do projeto.

### Sobreposição de intervalos — regra composta em FormRequest
**Fonte:** `app/Http/Requests/UpdateBonusFaixaRequest.php:79-115` (lido por
completo)
```php
if ($novoMin <= $outraMax && $novoMax >= $outraMin) {
    $v->errors()->add('nota_min', "Sobreposição com a faixa \"{$outra->nome}\" [...]");
    return; // 1 sobreposição basta — não iterar o resto
}
```
**Aplica-se a:** `UpdateFaixaFaturamentoRequest` — adaptar de
`nota_min`/`nota_max` para `limite_superior` (faixas com teto, ordenadas por
`ordem`).

### Rota admin — grupo já existente, não criar grupo novo
**Fonte:** `routes/web.php:1393`
```php
Route::middleware(['auth', 'verified', 'role:admin'])->prefix('administrativo')->name('admin.')->group(function () {
    // ... rotas de /financeiro já aqui ...
});
```
**Aplica-se a:** todas as rotas novas de faixas + fechar/refazer competência
— entram dentro deste grupo (guard `role:admin` + prefixo `admin.` já
resolvidos), seguindo a ordem já documentada no comentário do arquivo
("rotas específicas devem vir ANTES de `/financeiro/{company}` para evitar
colisão com o parâmetro dinâmico").

### Gancho de observer para nascer exceção junto do contrato (D-03)
**Fonte:** `app/Models/ContratoServico.php:33`
```php
#[ObservedBy([ContratoServicoObserver::class, ContratoServicoGatilhoObserver::class])]
class ContratoServico extends Model
```
**Aplica-se a:** se o planner decidir (D-03, discretion) que a exceção de
`empresa_faixas_faturamento` nasce automaticamente quando um `ContratoServico`
é criado com valor fora do padrão do serviço — adicionar um 3º observer ao
array (padrão já em uso, não abstração nova).

### Cabeçalho da tela — badge de status + botão primário condicional
**Fonte:** `resources/js/Pages/Admin/Financeiro.jsx` (`EvolucaoBadge`,
linhas 200-210, e `RecebidoToggle`, linhas 212-244, ambos lidos por
completo)
```jsx
function EvolucaoBadge({ evolucao }) {
    if (!evolucao) return null;
    const config = {
        subiu:   { Icon: TrendingUp,  cls: 'text-emerald-400', title: 'Subiu de faixa' },
        desceu:  { Icon: TrendingDown, cls: 'text-red-400',    title: 'Desceu de faixa' },
        manteve: { Icon: Minus,        cls: 'text-white/25',   title: 'Manteve a faixa' },
    }[evolucao];
    if (!config) return null;
    const { Icon, cls, title } = config;
    return <Icon size={14} className={cn('shrink-0', cls)} title={title} />;
}
```
**Aplica-se a:** `StatusCompetenciaBadge` (Em aberto/Fechado) e
`FecharCompetenciaButton`/`RefazerFechamentoDialog` — mesmo padrão de
componente local com objeto de config por estado + `lucide-react` icon,
mesmas classes `cn()`/tokens `ecf-*`.

### Dialog com form (imports já disponíveis, nada novo em `Components/ui/`)
**Fonte:** `resources/js/Pages/Admin/Financeiro.jsx:1-12` (imports, lido)
```jsx
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { cn, formatDate, formatCurrency } from '@/lib/utils';
import axios from 'axios';
```
**Aplica-se a:** `RefazerFechamentoDialog` (usa `Textarea` para o campo
motivo), `FaixaFormDialog` (usa `Input`/`Label`). Confirma o UI-SPEC: nenhum
primitivo novo de `Components/ui/` é necessário.

---

## Sem Análogo

| Arquivo | Papel | Fluxo de dados | Motivo |
|---|---|---|---|
| `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` (se extraído) | component | CRUD (form) | Não existe hoje uma UI de CRUD de linhas tipadas *dentro* de um accordion expandido da tela — o precedente mais próximo (`Desempenho/Configuracao.jsx`) é uma página inteira dedicada, não um bloco dentro de outra tela. Usar como referência estrutural de formulário (campos + validação de sobreposição no front), mas o encaixe visual (accordion) não tem precedente direto — seguir o UI-SPEC seção 4. |
| `app/Services/Fechamento/FechamentoRollupService.php` (agregação por GRUPO, soma de snapshots já gravados) | service | batch/transform | D-10 (soma simples de empresas-irmãs, sem mediana/média) é uma regra de agregação nova no projeto — não confundir com `computeVarFaturamento()`/`median()` do `DesempenhoScoreService` (Pitfall 5 do RESEARCH, resolvem problemas diferentes). Implementar como SUM direto sobre `fechamento_snapshots` já gravado, não recalcular do zero. |

## Metadata

**Escopo da busca de análogos:** `app/Models/`, `app/Http/Controllers/`,
`app/Http/Requests/`, `app/Services/`, `app/Console/Commands/`,
`database/migrations/`, `routes/`, `resources/js/Pages/Admin/`
**Arquivos lidos por completo ou em trechos direcionados:** `BonusFaixa.php`,
`2026_07_09_140002_create_bonus_faixas_table.php`,
`UpdateBonusFaixaRequest.php`, `DesempenhoConfigController.php`,
`2026_08_03_120000_create_desempenho_company_score_snapshots_table.php`,
`CompanyScoreSnapshotWriter.php`, `ConsolidarMesDesempenho.php` (parcial),
`VerificarConsolidacaoDesempenho.php` (parcial), `AdminController.php`
(parcial: cabeçalho, `fechamento()`, `calcularFaixa()`),
`CobrancaCalculator.php`, `CompanyGroup.php`, `ShopeeMetricDiffService.php`
(parcial), `MetricPeriodResolver.php` (parcial), `FechamentoRecebido.php`,
`EnviarRelatorioFechamentoJob.php` (parcial), `Financeiro.jsx` (parcial:
imports, `EvolucaoBadge`, `RecebidoToggle`, `TotalConsolidado`,
`FechamentoRow`), `ContratoServico.php` (parcial), `ContratoPdfService.php`
(parcial), `routes/web.php`/`routes/console.php` (grep + trechos).
**Data da extração de padrões:** 2026-09-02
