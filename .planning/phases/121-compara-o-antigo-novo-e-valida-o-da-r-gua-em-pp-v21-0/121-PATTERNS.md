# Phase 121: Comparação antigo × novo e validação da régua em pp - Mapa de Padrões

**Mapeado:** 2026-07-30
**Arquivos analisados:** 5 (1 comando novo, 1 migration nova, 2 models novos, 1 suíte de teste nova)
**Analogs encontrados:** 5 / 5

## Classificação dos Arquivos

| Arquivo novo/modificado | Papel | Fluxo de dados | Analog mais próximo | Qualidade do match |
|---|---|---|---|---|
| `app/Console/Commands/CompararScoreEmpresa.php` | command | batch / request-response (CLI) | `app/Console/Commands/ProbeMargemPrevStability.php` | exato (mesma disciplina: `--mes` fixo, persistir-antes-de-agregar, fail-open por item) |
| `database/migrations/YYYY_MM_DD_create_desempenho_comparador_tables.php` | migration | insert-only, batch | `database/migrations/2026_07_27_120000_create_adman_probe_margem_prev_tables.php` | exato (2 tabelas, mesmas armadilhas de tipo de coluna) |
| `app/Models/DesempenhoComparadorProfissional.php` | model | CRUD (insert-only) | `app/Models/AdmanProbeMargemPrevVeredito.php` (via mesma migration/mesmo padrão de model simples) | role-match |
| `app/Models/DesempenhoComparadorEmpresa.php` | model | CRUD (insert-only) | `app/Models/AdmanProbeMargemPrevLeitura.php` | role-match |
| `tests/Feature/Phase121/CompararScoreEmpresaCommandTest.php` | test | request-response (Artisan) | `tests/Feature/Phase120/AgregacaoProfissionalTest.php` + `tests/Feature/Phase119/CompanyScoreServiceFormulaTest.php` | exato (mock parcial de `CompanyScoreService::computeEmpresasScore()` + Reflection) |

Nenhum arquivo desta fase é role "controller"/"component" — é 100% backend CLI, coerente com a fronteira da fase (sem tela, sem rota HTTP).

---

## Pattern Assignments

### `app/Console/Commands/CompararScoreEmpresa.php` (command, batch)

**Analog:** `app/Console/Commands/ProbeMargemPrevStability.php` (molde estrutural) + `app/Console/Commands/ConsolidarMesDesempenho.php` (molde de iteração por profissional) + `app/Services/DesempenhoScoreService.php` / `app/Services/Desempenho/CompanyScoreService.php` (o que consumir, sem tocar)

**Imports pattern** (`ProbeMargemPrevStability.php:1-13`):
```php
namespace App\Console\Commands;

use App\Models\AdmanProbeMargemPrevLeitura;
use App\Models\AdmanProbeMargemPrevVeredito;
use App\Models\Company;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Portfolio\CarteiraContextService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
```
Para o comando desta fase, trocar por: `App\Models\BonusInvalidacao`, `App\Models\DesempenhoComparadorProfissional`, `App\Models\DesempenhoComparadorEmpresa`, `App\Models\User`, `App\Services\DesempenhoScoreService`, `App\Services\Metrics\MetricDiffDispatcher`, `App\Services\Metrics\MetricPeriodResolver`, `Illuminate\Support\Collection`, `Carbon\Carbon`, `Illuminate\Console\Command`, `Illuminate\Support\Facades\Log`.

**Assinatura + validação de `--mes` FIXO** (`ProbeMargemPrevStability.php:81-116`):
```php
protected $signature = 'adman:probe-margem-prev
    {--mes= : competência fechada FIXA no formato YYYY-MM (OBRIGATÓRIO — nunca last_closed_month, ver docblock da classe)}
    {--janela= : ...}
    {--relatorio : agrega as leituras já persistidas e emite o veredito, sem tocar a Adman}';

public function handle(): int
{
    if ($this->option('relatorio')) {
        return $this->emitirRelatorio();
    }

    $mes = $this->option('mes');

    if (! is_string($mes) || preg_match('/^\d{4}-\d{2}$/', $mes) !== 1) {
        $this->error('[...] --mes é obrigatório e precisa ser uma competência FIXA no formato YYYY-MM ...');
        return self::FAILURE;
    }

    $periodo = $this->resolver->resolve(['period_key' => $mes]);

    if ($periodo['comparison_mode'] !== 'previous_equal_length_window') {
        $this->error("[...] comparison_mode inesperado ('{$periodo['comparison_mode']}') para --mes={$mes}. Abortando ...");
        return self::FAILURE;
    }
    // ...
}
```
Para `desempenho:comparar-score-empresa`: mesma validação `preg_match('/^\d{4}-\d{2}$/', ...)`, mas resolver **3 competências** (fechada + 2 anteriores) — usar `Carbon::createFromFormat('Y-m-d', "{$mes}-01")` (nunca `'Y-m'`, ver Pitfall documentado em `ConsolidarMesDesempenho.php:97-104`) e então `$this->resolver->resolve(['period_key' => $mesLabel])` para cada uma das 3, iterando com `->subMonthNoOverflow()`.

**Loop com fail-open por item + log estruturado** (`ProbeMargemPrevStability.php:145-232`):
```php
foreach ($companies as $company) {
    $custId = $company->adman_account_id ?: $company->ml_store_id;
    if (empty($custId)) { $semCustId++; continue; }

    try {
        // ... trabalho ...
        AdmanProbeMargemPrevLeitura::create([...]);
        // contadores de sucesso
    } catch (\Throwable $e) {
        $fail++;
        Log::warning('[AdmanProbeMargemPrev] falhou', [
            'company_id' => $company->id,
            'periodo'    => "{$periodo['current_start']}..{$periodo['current_end']}",
            'error'      => $e->getMessage(),
        ]);
        AdmanProbeMargemPrevLeitura::create(['company_id' => $company->id, /* ... */ 'http_falhou' => true]);
    }
}
```
Espelho para o comando desta fase: o loop é **por profissional** (molde `ConsolidarMesDesempenho.php:137-234`, com `try { ... } catch (\Throwable $e) { Log::error(...); $fail++; continue; }`), e **dentro** dele, por empresa em `empresas_score` (molde do loop acima) para a extração interleaved de `diff_pct` (ver Pattern 2 abaixo). Nunca deixar uma exceção de item abortar o restante — mesma disciplina "fail-open por item" das duas fontes.

**Persistir antes de agregar / nunca só stdout** (`ProbeMargemPrevStability.php:234-245`, `543-573`, `744-748`):
```php
$msg = sprintf('[AdmanProbeMargemPrev] leitura concluída — mes=%s ... OK=%d FAIL=%d ...', $mes, $ok, $fail);
Log::info($msg);
$this->info($msg);
// ...
$this->warn('[AdmanProbeMargemPrev] AVISO: este relatorio impresso no console e um espelho. A conferencia OFICIAL do gate ... e por reconsulta ao banco ... — nunca por este stdout (mesma disciplina que o gate FIXMARG-03 ja exige neste projeto).');
```
**Diferença relevante (RESEARCH, Pergunta 4):** o probe separa `--relatorio` de leitura porque agrega MÚLTIPLAS rodadas no tempo — este comparador é uma comparação de **um único instante**, então persistir e reconsultar podem acontecer na MESMA execução (sem exigir modo `--relatorio` obrigatório); recomenda-se ainda assim reconsultar do banco (não do array em memória) para montar a tabela de console, provando reconsultabilidade sem herdar a complexidade de rodadas do probe.

**Validação em runtime do `comparison_mode`** (mesmo trecho `ProbeMargemPrevStability.php:120-129`) — reaproveitar tal qual para as 3 competências resolvidas.

---

### Migration `create_desempenho_comparador_tables.php` (migration, insert-only)

**Analog:** `database/migrations/2026_07_27_120000_create_adman_probe_margem_prev_tables.php`

**Estrutura de 2 tabelas + armadilhas evitadas** (arquivo completo, linhas 24-136):
```php
Schema::create('adman_probe_margem_prev_leituras', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->cascadeOnDelete(); // NÃO usar nullOnDelete sem ->nullable() (erro 1830 MariaDB)
    $table->string('periodo_key');
    $table->timestamp('lida_em');
    $table->string('janela_esperada')->nullable(); // STRING, nunca enum()/lista fixa — CHECK enforçado no SQLite dos testes
    $table->decimal('value', 14, 6)->nullable();        // precisão 14,6 — NUNCA (10,2): destruiria variação sub-0,01
    $table->decimal('prev', 14, 6)->nullable();
    $table->decimal('margem_var_pp', 14, 6)->nullable();
    $table->unsignedTinyInteger('nota_regua')->nullable();
    $table->string('leitura_hash', 32)->nullable();
    $table->boolean('http_falhou')->default(false);
    $table->timestamps();
    $table->index(['company_id', 'lida_em']);
    $table->index(['periodo_key', 'lida_em']);
});

Schema::create('adman_probe_margem_prev_vereditos', function (Blueprint $table) {
    $table->id();
    $table->string('periodo_key');
    $table->timestamp('gerado_em');
    $table->unsignedInteger('total_leituras');
    $table->decimal('cobertura_prev', 5, 4)->nullable();
    $table->string('veredito'); // STRING, nunca enum
    $table->json('motivos')->nullable();
    $table->timestamps();
    $table->index(['periodo_key', 'gerado_em']);
});
```
**Molde para as 2 tabelas novas desta fase:**
- `desempenho_comparador_profissionais` (1 linha por `user_id` × `mes_referencia`): `foreignId('user_id')->constrained()->cascadeOnDelete()`, `date('mes_referencia')` ou `string('periodo_key')` (seguir convenção `periodo_key` do probe), `decimal('nota_antiga', 5, 2)->nullable()`, `decimal('nota_nova', 5, 2)->nullable()`, `decimal('delta', 6, 2)->nullable()`, `string('status_antigo')->nullable()`, `string('status_novo')->nullable()` (STRING, nunca enum — mesma armadilha), contadores `unsignedInteger` (total/complete/partial), `json('decomposicao')->nullable()` (as 3 parcelas + resíduo), `json('amostras_risco')->nullable()` ou tabela separada, `timestamps()`.
- `desempenho_comparador_empresas` (1 linha por `user_id` × `company_id` × `mes_referencia`): `foreignId('company_id')->constrained()->cascadeOnDelete()`, `foreignId('user_id')->constrained()->cascadeOnDelete()`, `string('periodo_key')`, `decimal('margem_var_pp', 14, 6)->nullable()` (mesma precisão do probe — não arredondar para 2 casas), `decimal('margem_diff_pct', 14, 6)->nullable()` (a parcela nova desta fase, extraída via Pattern 2 abaixo), `decimal('nota_empresa', 5, 2)->nullable()`, `string('status')->nullable()`, `boolean('financial_metrics_eligible')->default(false)`, `timestamps()`, índices `['company_id', 'periodo_key']` e `['user_id', 'periodo_key']`.

**Armadilhas replicadas do molde (aplicar às tabelas novas):**
1. Nunca `enum()`/coluna de lista fixa para `status_antigo`/`status_novo`/`fonte_financeira` — usar `string()` (CHECK do SQLite quebra os testes, memória do projeto `project_enum_setor_sqlite_check`).
2. `decimal(14,6)` para `margem_var_pp`/`margem_diff_pct` (não `(10,2)`) — precisão insuficiente mascara variação real e fabrica falso-positivo de "estabilidade".
3. FK com `cascadeOnDelete()` (nunca `nullOnDelete()` sem `->nullable()` — erro 1830 MariaDB, memória do projeto `project_mysql_nullondelete_nullable`).
4. `down()` dropa na ordem inversa de criação.

---

### `app/Models/DesempenhoComparadorProfissional.php` / `DesempenhoComparadorEmpresa.php` (model, CRUD insert-only)

**Analog:** `app/Models/BonusInvalidacao.php` (model simples, `$fillable` explícito, sem lógica pesada) — modelo de referência para shape de model Eloquent do projeto.

**Padrão de model enxuto** (`BonusInvalidacao.php:21-59`):
```php
class BonusInvalidacao extends Model
{
    use LogsActivity;

    protected $table = 'bonus_invalidacoes';

    protected $fillable = ['company_id', 'competencia', 'motivo', 'invalidated_by'];

    protected $casts = ['competencia' => 'date'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
```
Para os 2 models novos: `$fillable` com todas as colunas de dado (nunca `timestamps`), `$casts` para `json`→`array` nos campos de decomposição/amostras, relação `belongsTo(User::class)`/`belongsTo(Company::class)` conforme aplicável. **Não usar `LogsActivity`** aqui — são tabelas de auditoria insert-only geradas por comando, não dado editado por humano (mesmo raciocínio das tabelas `adman_probe_margem_prev_*`, que não têm activity log).

**Método estático de leitura agregada** (`BonusInvalidacao.php:68-74`, usado como cross-check da amostra "empresa invalidada"):
```php
public static function companyIdsInvalidadas(Carbon $competencia): \Illuminate\Support\Collection
{
    return static::query()
        ->whereDate('competencia', $competencia->copy()->startOfMonth()->toDateString())
        ->pluck('company_id')
        ->map(fn ($id) => (int) $id);
}
```
Usar exatamente esta chamada (sem reimplementar) para a amostra de risco "empresa invalidada" — que é checagem de **ausência**: cruzar esses IDs contra quem aparece em `empresas_score` (nenhum deve aparecer).

---

### `tests/Feature/Phase121/CompararScoreEmpresaCommandTest.php` (test)

**Analog:** `tests/Feature/Phase120/AgregacaoProfissionalTest.php` (mock parcial de `CompanyScoreService`) + `tests/Feature/Phase119/CompanyScoreServiceFormulaTest.php` (Reflection sobre método privado)

**Mock parcial de `CompanyScoreService::computeEmpresasScore()`** (`AgregacaoProfissionalTest.php:177-195`):
```php
private function linhaEmpresa(
    int $companyId,
    string $status,
    ?float $notaEmpresa = null,
    ?float $notaEmpresaParcial = null,
    ?float $margemVarPp = null
): object {
    return (object) [
        'company_id'           => $companyId,
        'company_name'         => "Empresa {$companyId}",
        'status'               => $status,
        'nota_empresa'         => $notaEmpresa,
        'nota_empresa_parcial' => $notaEmpresaParcial,
        'margem_var_pp'        => $margemVarPp,
    ];
}

private function mockCompanyScoreService(Collection $linhas): void
{
    $this->mock(CompanyScoreService::class, function ($mock) use ($linhas) {
        $mock->shouldReceive('computeEmpresasScore')->andReturn($linhas);
    });
}
```
Reusar este padrão para fixture das 7 amostras de risco (ROLL-02) e do histograma (ROLL-03) — evita HTTP real à Adman no teste. `config(['metrics.performance_company_first_score' => true])` **dentro do teste**, nunca no config de produção (mesma disciplina de `AgregacaoProfissionalTest.php:30-32`).

**Reflection sobre método privado** (`CompanyScoreServiceFormulaTest.php:147-154`):
```php
private function invocarReguaFaturamentoOriginal(float $pct): ?float
{
    $original = app(DesempenhoScoreService::class);
    $ref      = new ReflectionMethod($original, 'reguaFaturamento');
    $ref->setAccessible(true);

    return $ref->invoke($original, $pct);
}
```
No teste do comando: usar o MESMO padrão para invocar `computeNotaFinalPorEmpresa()`/`computeScoreStatusPorEmpresa()` sobre uma `Collection` de linhas fabricadas — cobre ROLL-01 sem depender do comando já ter essa lógica embutida corretamente (teste independente da implementação do comando).

**Convenção de suíte confirmada:** `tests/Feature/Phase121/` (mesmo padrão `Phase119`/`Phase120`), arquivo único `CompararScoreEmpresaCommandTest.php` cobrindo ROLL-01/02/03 em métodos de teste separados (`#[Test]` do PHPUnit 11, atributo já usado em ambos os analogs).

---

## Shared Patterns

### Reflection para consumir método privado — avaliação para uso em CÓDIGO DE PRODUÇÃO (não teste)

**Fonte:** `tests/Feature/Phase119/CompanyScoreServiceFormulaTest.php:147-154` (único precedente no projeto, mas em contexto de TESTE).

**Aplicar a:** `CompararScoreEmpresa.php` (comando), que precisa chamar `computeNotaFinalPorEmpresa()`/`computeScoreStatusPorEmpresa()` — ambos `private` em `app/Services/DesempenhoScoreService.php:1555,1593` — a partir de código de produção, não de um teste.

```php
private function notaNovaViaReflection(DesempenhoScoreService $service, Collection $empresasScore): ?float
{
    $ref = new \ReflectionMethod($service, 'computeNotaFinalPorEmpresa');
    $ref->setAccessible(true);

    return $ref->invoke($service, $empresasScore);
}
```

**Avaliação (pedida no `specific_mapping_needs` #2):** é o único caminho compatível com a fronteira da fase ("não modifica `DesempenhoScoreService`"), mas usar Reflection em produção — e não só em teste — é uma esticada real do padrão existente: acopla o comando à assinatura interna exata de dois métodos privados, que podem mudar de nome/assinatura na Fase 122 sem que nenhum contrato público avise. Alternativas descartadas pelo próprio RESEARCH: (a) duplicar a fórmula no comando cria um TERCEIRO lugar para a mesma lógica divergir (pior — já são 2: `DesempenhoScoreService` e `CompanyScoreService`); (b) tornar os métodos `protected`/expor via método público de conveniência exigiria tocar `DesempenhoScoreService.php`, proibido pela fronteira da fase. **Recomendação:** usar Reflection tal como o RESEARCH recomenda, mas (1) envolver a chamada num método privado nomeado e documentado (como acima) para isolar o ponto de acoplamento num único lugar do comando; (2) adicionar teste de regressão que falha ruidosamente (`ReflectionException`) se o nome do método mudar, servindo de sentinela; (3) deixar explícito no docblock do comando que este é código de AUDITORIA temporária (não caminho de produção contínuo), reduzindo o risco a "quebra visível no teste", nunca "drift silencioso".

### Chamada ao `compute()` com shadow ligado (payload com as duas notas)

**Fonte:** `app/Services/DesempenhoScoreService.php:467` (assinatura) e `:505-577` (uso do payload)

```php
public function compute(User $user, Carbon $mesReferencia, ?array $periodoOverride = null, bool $incluirEmpresasScore = false): array
```
```php
$payload = $service->compute($user, $mes, null, incluirEmpresasScore: true);

$notaAntiga    = $payload['nota_final'];       // legado, régua-da-média
$statusAntigo  = $payload['score_status'];     // legado
$empresasScore = $payload['empresas_score'];   // sempre presente quando incluirEmpresasScore=true, mesmo com a flag off
```
**Aplicar a:** o loop principal do comando, uma vez por `(profissional, competência)` — nunca chamar `compute()` duas vezes (viola D-01) nem chamar `CompanyScoreService::computeEmpresasScore()` diretamente fora de `compute()` (resolveria `$periodo`/`$invalidadas` de novo, arriscando janela diferente).

### Releitura interleaved do `MetricDiffDispatcher` para `diff_pct` de margem

**Fonte:** `app/Services/Desempenho/CompanyScoreService.php:225-239` (ponto exato onde o dispatcher é chamado dentro do shadow):
```php
// EMPS-05 — UMA única chamada ao dispatcher por empresa, alimentando faturamento E margem.
$company   = $companies->get($companyId);
$resultado = $this->diffDispatcher->compute($company, $periodo, $fonteFinanceira);
// ...
$margemVarPp = $resultado['metrics']['contribution_margin_pct']['diff_pp'] ?? null; // EMPS-03 — nunca diff_pct
```
E a assinatura do dispatcher (`app/Services/Metrics/MetricDiffDispatcher.php:34-43`):
```php
public function compute(Company $company, array $periodo, string $source): array
{
    return match ($source) {
        'adman'  => $this->admanMetricDiffService->compute($company, $periodo),
        'shopee' => $this->shopeeMetricDiffService->compute($company, $periodo),
        default  => throw new InvalidArgumentException("Fonte financeira desconhecida: '{$source}'."),
    };
}
```
**Shape do payload devolvido**, com `diff_pct` que `CompanyScoreService` descarta (`app/Services/Metrics/AdmanMetricDiffService.php:100-106`):
```php
* @return array{
*     metrics: array{
*         contribution_margin_pct: array{value: ?float, prev_value: ?float, diff_pct: ?float, diff_pp: ?float, diff_source: ?string},
*     },
* }
```
**Aplicar a:** dentro do MESMO loop do comando que consome `empresas_score` (nunca numa segunda passada — TTL de `partial` é 10 min, ver `AdmanMetricDiffService.php:205-219`: `complete` cacheia 1440 min, `partial`/erro cacheia `ERROR_CACHE_MINUTES`, curto). Reconsultar `$dispatcher->compute($linha->company, $periodo, $linha->fonte_financeira)` **imediatamente após** o shadow processar aquela empresa — mesma chave de cache (`adman:diff:v6:...:{cacheDay()}`), portanto cache-hit se interleaved.

### `financial_metrics_eligible` e dedupe por `company_id` (ROLL-03)

**Fonte:** `app/Services/Portfolio/CarteiraContextService.php` (docblock, `:99-104`) e uso em `CompanyScoreService.php:132-162`:
```php
$vinculos = $this->carteiraContext->forUser($user, ['active' => true])
    ->reject(fn (array $v) => $invalidadas->contains($v['company_id']));

$fontesPorEmpresa = $vinculos
    ->where('financial_metrics_eligible', true)
    ->groupBy('company_id')
    ->map(fn (Collection $grupo) => $grupo->pluck('financial_source')->contains('adman') ? 'adman' : $grupo->pluck('financial_source')->first());
```
Para o comando: filtrar `empresas_score` por `fonte_financeira !== null` (proxy de `financial_metrics_eligible=true`, já resolvido dentro do `compute()`), depois **deduplicar por `company_id`** antes de montar o histograma de `margem_var_pp` (D-03/ROLL-03) — `groupBy('company_id')->first()` ou `unique('company_id')`, agregando as coleções de TODOS os profissionais elegíveis processados na competência. Empresa pode aparecer em mais de uma carteira (`company_users` tem várias linhas por empresa desde a Fase 76, memória `project_company_users_multi_linha_servico`) — sem dedupe o histograma infla.

### `BonusInvalidacao::companyIdsInvalidadas($mes)` — amostra "empresa invalidada" (ausência)

**Fonte:** `app/Models/BonusInvalidacao.php:68-74`
```php
public static function companyIdsInvalidadas(Carbon $competencia): \Illuminate\Support\Collection
```
**Aplicar a:** a amostra de risco "empresa invalidada" NÃO é um filtro sobre `empresas_score` (não existe status `invalidada` lá — deliberadamente inexistente, `CompanyScoreService.php:102-103`). É uma checagem de exclusão: chamar `BonusInvalidacao::companyIdsInvalidadas($mes)` e confirmar que nenhum desses IDs aparece em `empresas_score` de nenhum profissional processado na competência.

---

## No Analog Found

Nenhum arquivo desta fase ficou sem analog — o domínio é 100% leitura/composição de código já existente nesta milestone (RESEARCH confirma: "não há NENHUM cálculo genuinamente novo").

## Metadata

**Escopo de busca de analogs:** `app/Console/Commands/`, `app/Models/`, `app/Services/DesempenhoScoreService.php`, `app/Services/Desempenho/`, `app/Services/Metrics/`, `tests/Feature/Phase119/`, `tests/Feature/Phase120/`, `database/migrations/`.
**Arquivos lidos:** `ProbeMargemPrevStability.php` (completo), migration `2026_07_27_120000_create_adman_probe_margem_prev_tables.php` (completa), `ConsolidarMesDesempenho.php` (completo), `BonusInvalidacao.php` (completo), `CompanyScoreServiceFormulaTest.php` (trecho 120-189), `AgregacaoProfissionalTest.php` (trecho 1-110, 177-207), `DesempenhoScoreService.php` (trechos 460-590, 1540-1630), `CompanyScoreService.php` (trechos 1-110, 110-310), `MetricDiffDispatcher.php` (completo), `AdmanMetricDiffService.php` (trechos 95-145, 205-219), `CarteiraContextService.php` (trechos com `financial_metrics_eligible`), `WarmAdmanDiffCache.php` (trecho de `current_month`/`last_closed_month`).
**Data de extração:** 2026-07-30
