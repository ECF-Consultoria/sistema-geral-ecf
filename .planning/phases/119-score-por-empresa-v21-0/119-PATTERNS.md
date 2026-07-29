# Phase 119: Score por empresa — Pattern Map

**Mapeado em:** 2026-07-28
**Arquivos analisados:** 2 (1 serviço novo + 1 suíte de testes nova, múltiplos arquivos)
**Análogos encontrados:** 2/2 (ambos com match estrutural forte — Fase 118 é o precedente direto)

## File Classification

| Arquivo novo/modificado | Papel | Fluxo de dados | Análogo mais próximo | Qualidade do match |
|---|---|---|---|---|
| `app/Services/Desempenho/CompanyScoreService.php` | service (agregador de leitura) | CRUD (leitura pura, composição de 3 fontes) | `app/Services/Desempenho/NpsPorEmpresaService.php` | **exato** — mesma pasta, mesmo tipo de retorno, mesmo consumidor (`CarteiraContextService`), mesma disciplina de metadados auditáveis |
| `tests/Feature/Phase119/CompanyScoreServiceReguasTest.php` (teste de equivalência das réguas) | test | transform (comparação Reflection de duas implementações) | `tests/Feature/Phase118/NpsJanelaResolverTest.php` — método `test_resolver_concorda_com_computeNpsWindow_nos_tres_casos` | **exato** — é o padrão explicitamente prescrito pela C-03 do CONTEXT |
| `tests/Feature/Phase119/CompanyScoreServiceMargemTest.php` (fixture financeira via Adman) | test | request-response (HTTP mockado) | `tests/Feature/V18/AdmanMetricDiffServiceTest.php` (fixtures `percentageMargin`) + `tests/Feature/DesempenhoShopeeScoreTest.php` (`Http::preventStrayRequests()`/`Http::fake()` de carteira completa) | **forte** — a fixture MPP-06 já existe pronta em `AdmanMetricDiffServiceTest`; o padrão de `Http::fake` em cenário de carteira multi-empresa vem do Shopee test |
| `tests/Feature/Phase119/CompanyScoreServiceContratoTest.php` / `FormulaTest.php` / `DispatcherTest.php` / `FonteTest.php` / `StatusTest.php` | test | CRUD/request-response | `tests/Feature/Phase118/NpsPorEmpresaContratoTest.php` + `NpsPorEmpresaRamosTest.php` | **forte** — mesmo trait de fixture (`CriaCenarioResponsaveis`), mesmo `RefreshDatabase`, mesmo idioma de asserção de shape |

Não há "sem análogo" nesta fase — a Fase 118 é um precedente estrutural quase 1:1 (mesmo tipo de fase: aditiva, mesma pasta, mesmo consumidor de `CarteiraContextService`, mesmo gate de hash).

---

## Pattern Assignments

### `app/Services/Desempenho/CompanyScoreService.php` (service, CRUD/leitura pura)

**Análogo:** `app/Services/Desempenho/NpsPorEmpresaService.php` (Fase 118)

**Imports pattern** (linhas 1-14 do análogo — mesma pasta, mesmo padrão de import):
```php
<?php

namespace App\Services\Desempenho;

use App\Models\User;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Portfolio\CarteiraContextService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
```
Para o `CompanyScoreService`, adicionar `use App\Services\Desempenho\NpsPorEmpresaService;` (mesma pasta — sem `use` cross-namespace estranho) e `use App\Models\Company;` se precisar tipar o parâmetro do dispatcher.

**Docblock de classe — molde de auditabilidade e aditividade** (linhas 16-57 do análogo). Copiar a estrutura de 3 blocos: (1) o que o serviço é e de onde vem; (2) "Regras travadas" numeradas D-01..D-0N referenciando o CONTEXT; (3) bloco "Aditividade" citando que `DesempenhoScoreService` é espelhado, nunca modificado, com `@see` para o CONTEXT e para o arquivo espelhado com faixa de linhas:
```php
/**
 * Agregador de LEITURA que devolve a linha de fato por empresa
 * `(user_id, company_id)` — Fase 119 (EMPS-01..07), consome o componente de
 * NPS já pronto da Fase 118.
 *
 * ...
 *
 * ─── Aditividade ──────────────────────────────────────────────────────────
 * `DesempenhoScoreService` é ESPELHADO, não substituído — nenhum número de
 * produção muda nesta fase. `reguaFaturamento()`/`reguaMargem()` são
 * duplicadas byte a byte (C-03/119-CONTEXT.md) com teste de equivalência —
 * ver `NpsJanelaResolver` para o precedente do mesmo padrão na Fase 118.
 *
 * @see .planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md
 * @see app/Services/DesempenhoScoreService.php:1290,1311 (réguas duplicadas — NÃO reescritas)
 * @see app/Services/Desempenho/NpsPorEmpresaService.php (componente NPS consumido, não reimplementado)
 */
class CompanyScoreService
{
    public function __construct(
        private CarteiraContextService $carteiraContext,
        private MetricDiffDispatcher $diffDispatcher,
        private NpsPorEmpresaService $npsPorEmpresaService,
    ) {
    }
```

**@return docblock — molde exato para o shape de retorno auditável** (linhas 96-109 do análogo):
```php
/**
 * @return Collection<int, object{
 *   company_id: int,
 *   company_name: string,
 *   fonte_financeira: ?string,
 *   nps_pontos: ?float,
 *   faturamento_var_pct: ?float,
 *   faturamento_pontos: ?float,
 *   margem_var_pp: ?float,
 *   margem_pontos: ?float,
 *   componentes_presentes: int,
 *   nota_empresa: ?float,
 *   nota_empresa_parcial: ?float,
 *   status: string,
 *   quality: array{revenue_diff_source: ?string, margin_diff_source: ?string, margin_source: ?string, motivos: array<int,string>},
 * }> chaveada por `company_id`.
 */
public function computeEmpresasScore(User $user, Carbon $mes, array $periodo, bool $mesFechado, ?Collection $invalidadas = null): Collection
```
Note: o formato `Collection<int, object{...}>` é copiado literalmente do docblock do análogo (linhas 96-108) — mesmo idioma de tipos PHPDoc usado em todo o serviço vizinho.

**Como monta o universo — copiar passo a passo** (linhas 110-131 do análogo):
```php
$invalidadas = $invalidadas ?? collect();

$vinculos = $this->carteiraContext->forUser($user, ['active' => true])
    ->reject(fn (array $v) => $invalidadas->contains($v['company_id']));

// forUser() NÃO colapsa vínculos — deduplicar aqui (Pitfall documentado no
// docblock do CarteiraContextService::contadores()).
$companiesUniverso = $vinculos->pluck('company_id')->unique()->values();
```
Esta é EXATAMENTE a ordem que a Resposta 7 do RESEARCH prescreve: invalidação ANTES de qualquer resolução de fonte/chamada HTTP.

**Chamada única ao componente de NPS** (linha 335 do RESEARCH, confirmado no método `notasNpsPorEmpresa` do análogo):
```php
$notasNps = $this->npsPorEmpresaService->notasNpsPorEmpresa($user, $mes, $mesFechado, $invalidadas);
$npsPontos = $notasNps[$companyId]->nota ?? null;
```
UMA chamada cobrindo todas as empresas — nunca em loop (anti-pattern explícito do RESEARCH).

**Padrão "sempre existe uma linha, mesmo sem nota/fonte"** — molde em `linhaSemNota()` do análogo (linhas 501-516):
```php
private function linhaSemFonte(int $companyId, string $companyName, ?float $npsPontos): object
{
    return (object) [
        'company_id'            => $companyId,
        'company_name'          => $companyName,
        'fonte_financeira'      => null,
        'nps_pontos'            => $npsPontos,
        'faturamento_pontos'    => null,
        'margem_pontos'         => null,
        'componentes_presentes' => $npsPontos !== null ? 1 : 0,
        'nota_empresa'          => null,
        'nota_empresa_parcial'  => $npsPontos,
        'status'                => 'sem_fonte',
        'quality'               => ['revenue_diff_source' => null, 'margin_diff_source' => null, 'margin_source' => null, 'motivos' => ['sem_fonte_financeira']],
    ];
}
```
D-03 do CONTEXT + C-04 (nunca chamar o dispatcher quando `fonte_financeira === null` — o guard vem ANTES, não no catch).

**Logging não-PII — mesmo padrão do análogo** (linhas 264-271 do análogo):
```php
Log::warning('[Score por Empresa] <mensagem curta>', [
    'user_id'     => $user->id,
    'company_id'  => $companyId,
    'competencia' => $mes->format('Y-m'),
]);
```
Nunca logar nome de empresa/cliente, e-mail ou texto — só IDs/competência (mesma disciplina ASVS V-Information-Disclosure do RESEARCH).

---

### Chamada única ao dispatcher — o que EMPS-05 unifica

**Análogo:** `app/Services/DesempenhoScoreService.php` — duas extrações hoje separadas (linhas 1130 e 1204-1205), que o `CompanyScoreService` funde em uma:

```php
// computeVarFaturamento() — app/Services/DesempenhoScoreService.php:1130
$diffPct = $this->diffDispatcher->compute($company, $periodo, $source)['metrics']['revenue']['diff_pct'] ?? null;

// computeVarMargem() — app/Services/DesempenhoScoreService.php:1204-1205
$resultado = $this->diffDispatcher->compute($company, $periodo, $source);
$diffPct   = $resultado['metrics']['contribution_margin_pct']['diff_pct'] ?? null;
```

Contrato da chamada única por empresa (a compor no `CompanyScoreService`):
```php
$resultado = $this->diffDispatcher->compute($company, $periodo, $fonteFinanceira);

$faturamentoVarPct = $resultado['metrics']['revenue']['diff_pct'] ?? null;
$margemPctAtual    = $resultado['metrics']['contribution_margin_pct']['value'] ?? null;
$margemPctAnterior = $resultado['metrics']['contribution_margin_pct']['prev_value'] ?? null;
$margemVarPp       = $resultado['metrics']['contribution_margin_pct']['diff_pp'] ?? null;  // EMPS-03 — NUNCA diff_pct
$revenueDiffSource = $resultado['metrics']['revenue']['diff_source'] ?? null;
$margemDiffSource  = $resultado['metrics']['contribution_margin_pct']['diff_source'] ?? null;
```

**Guard C-04, antes de qualquer chamada:**
```php
// app/Services/Metrics/MetricDiffDispatcher.php:36-42
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
```
`null` está fora da whitelist → lança `InvalidArgumentException`. O `CompanyScoreService` deve envolver a chamada num `if ($fonteFinanceira !== null) { ... }` — a empresa `sem_fonte` (D-03) nunca chega ao `if`.

---

### Corpo literal das duas réguas — duplicação byte-a-byte (C-03)

Copiar **exatamente** de `app/Services/DesempenhoScoreService.php:1290-1298` e `:1311-1319`:

```php
/**
 * Régua de FATURAMENTO — aplica pontuação 1-5 pts à % de variação de faturamento
 * vs mês anterior por empresa (média da carteira).
 *
 * Ancorada no SPEC-04 "Régua de Faturamento" da diretoria, adaptada à
 * interpretação vs-mês-anterior escolhida em spec-phase Q1:
 *   ≤ -6%  → 1 pt (queda severa)
 *   ≤ -1%  → 2 pts (queda leve)
 *   <  1%  → 3 pts (estável / meta)
 *   ≤  5%  → 4 pts (crescimento saudável)
 *   >  5%  → 5 pts (crescimento excelente)
 */
private function reguaFaturamento(?float $pct): ?float
{
    if ($pct === null) return null;
    if ($pct <= -6)    return 1.0;
    if ($pct <= -1)    return 2.0;
    if ($pct <   1)    return 3.0;
    if ($pct <=  5)    return 4.0;
    return 5.0;
}
```

```php
/**
 * Régua de MARGEM DE CONTRIBUIÇÃO — aplica pontuação 1-5 pts à % de variação
 * de margem vs mês anterior por empresa (média da carteira).
 *
 * Ancorada no SPEC-05 "Régua de Margem" da diretoria:
 *   ≤ -5%  → 1 pt
 *   ≤ -2%  → 2 pts
 *   ≤  1%  → 3 pts
 *   ≤  4%  → 4 pts
 *   >  4%  → 5 pts
 */
private function reguaMargem(?float $pct): ?float
{
    if ($pct === null) return null;
    if ($pct <= -5)    return 1.0;
    if ($pct <= -2)    return 2.0;
    if ($pct <=  1)    return 3.0;
    if ($pct <=  4)    return 4.0;
    return 5.0;
}
```

**Diferença de contrato ao chamar `reguaMargem()` no serviço novo (D-02):** no `DesempenhoScoreService` original, `reguaMargem()` é chamada sobre uma % de variação relativa (`diff_pct`, agregada). No `CompanyScoreService`, a MESMA função (cortes idênticos) deve receber `margem_var_pp` (pontos percentuais, por empresa) — nunca `diff_pct`. O docblock da cópia deve dizer explicitamente isto, para não confundir o próximo dev:
```php
// NOTA (Fase 119, D2 da milestone v21.0): os cortes numéricos são IDÊNTICOS
// à `DesempenhoScoreService::reguaMargem()`, mas aqui a função recebe
// `margem_var_pp` (diff_pp — pontos percentuais), NUNCA `diff_pct`. A
// duplicação é intencional e temporária — ver 119-CONTEXT.md C-03.
```

**Aplicação por empresa, incluindo o placeholder Shopee (D-02):**
```php
$faturamentoPontos = $this->reguaFaturamento($faturamentoVarPct);
$margemPontos = match (true) {
    $fonteFinanceira === 'shopee' => 1.0,                              // D-02 — nunca aplica régua
    $margemVarPp === null         => null,                             // Adman sem diff_pp — D-01/quality.motivos[]='margem_pp_indisponivel'
    default                       => $this->reguaMargem($margemVarPp), // cortes REUSADOS (duplicados)
};
```

**Composição da nota (D-01, EMPS-04):**
```php
$pontos    = collect([$npsPontos, $faturamentoPontos, $margemPontos]);
$presentes = $pontos->reject(fn ($v) => $v === null);

$componentesPresentes = $presentes->count();
$notaEmpresaParcial   = $presentes->isEmpty() ? null : round($presentes->avg(), 2);
$notaEmpresa          = $componentesPresentes === 3 ? $notaEmpresaParcial : null; // D-01
```

**O que NÃO portar** (anti-pattern confirmado por C-02/Resposta 2 do RESEARCH — não recriar em `CompanyScoreService`):
- `$vars->avg()` de `computeVarMargem()` (linha 1199-1218) — é a "régua-da-média" que a milestone substitui.
- `n_com_margem_real`/`n_elegivel` (bookkeeping agregado, linhas 1191-1220) — sai "de graça" contando `status`/`margem_pontos !== null` na Collection nova.
- `margemPontos()` (linha 1348, blend ponderado por contagem) — D-04, INTOCADO, não usado pelo caminho novo.

---

### Resolução da fonte financeira vencedora (EMPS-06 / D-03)

**Análogo:** `app/Services/Portfolio/CarteiraContextService.php` — `flagsFinanceirasPorSetor()` (linhas 247-276):
```php
private function flagsFinanceirasPorSetor(string $setor): array
{
    return match ($setor) {
        Servico::SETOR_PERFORMANCE => [
            'has_financial_source'       => true,
            'financial_source'           => 'adman',
            'financial_metrics_eligible' => true,
        ],
        Servico::SETOR_SHOPEE => [
            'has_financial_source'       => true,
            'financial_source'           => 'shopee',
            'financial_metrics_eligible' => true,
        ],
        default => [
            'has_financial_source'       => false,
            'financial_source'           => null,
            'financial_metrics_eligible' => false,
        ],
    };
}
```
Isso já vem resolvido POR VÍNCULO dentro do shape devolvido por `forUser()` — não reimplementar esta tabela.

**Desempate "Adman vence Shopee"** hoje vive em `DesempenhoScoreService::computeUniverso()` (linhas 647-654, citado no RESEARCH):
```php
$fontes = $elegiveis
    ->groupBy('company_id')
    ->map(function (Collection $vs) {
        $sources = $vs->pluck('financial_source');
        return $sources->contains('adman') ? 'adman' : $sources->first();
    });
```

**Adaptação para a Fase 119** (universo COMPLETO, não pré-filtrado — a mudança que o EMPS-06/D-03 exige):
```php
$elegiveisPorEmpresa = $vinculos->where('financial_metrics_eligible', true)->groupBy('company_id');

$fonteFinanceira = $elegiveisPorEmpresa->has($companyId)
    ? ($elegiveisPorEmpresa[$companyId]->pluck('financial_source')->contains('adman')
        ? 'adman'
        : $elegiveisPorEmpresa[$companyId]->pluck('financial_source')->first())
    : null; // D-03 — nenhum vínculo elegível ⇒ sem_fonte
```

---

### Padrão de gate de aditividade (obrigatório em toda task)

**Fonte:** `.planning/phases/118-nps-por-empresa-v21-0/118-01-PLAN.md` (linhas 217, 295, 354) e `118-01-SUMMARY.md` (linha 39).

Hash de referência atual (confirmado no `118-01-SUMMARY.md` e repassado pela orientação da tarefa):
```
cfc16da2a8404fba0d4a9a2bc62cd1a6f668bd17fe390fe6405cebd4e71a9edd
```

Comando de verificação, a repetir em cada task/commit do plano:
```bash
sha256sum app/Services/DesempenhoScoreService.php | grep -q '^cfc16da2a8404fba0d4a9a2bc62cd1a6f668bd17fe390fe6405cebd4e71a9edd'
```

Sinal de aprovação em `VALIDATION.md` (molde de `118-VALIDATION.md:87`):
> `git diff --name-only` **não** inclui `app/Services/DesempenhoScoreService.php` — a fase é aditiva.

---

### Teste de equivalência das réguas (C-03) — molde exato

**Análogo:** `tests/Feature/Phase118/NpsJanelaResolverTest.php`, método `test_resolver_concorda_com_computeNpsWindow_nos_tres_casos` (linhas 211-236) e o helper de invocação por Reflection (linhas 117-124):

```php
private function invocarComputeNpsWindow(User $user, Carbon $mes, bool $mesFechado, ?Collection $invalidadas = null): ?float
{
    $service = app(DesempenhoScoreService::class);
    $metodo  = new ReflectionMethod($service, 'computeNpsWindow');
    $metodo->setAccessible(true);

    return $metodo->invoke($service, $user, $mes, $mesFechado, $invalidadas);
}
```

```php
#[Test]
public function test_resolver_concorda_com_computeNpsWindow_nos_tres_casos(): void
{
    $cenario  = $this->montarCenarioVazio();
    $resolver = new NpsJanelaResolver();

    $r1 = $this->invocarComputeNpsWindow($cenario['analista'], Carbon::parse('2026-06-01'), false);
    $this->assertSame(1.0, $r1);
    // ... casos 2 e 3, ver arquivo completo
}
```

**Molde exato a replicar para `CompanyScoreServiceReguasTest.php`** — comparar por Reflection `reguaFaturamento()`/`reguaMargem()` de `DesempenhoScoreService` (private) contra as cópias `private` do `CompanyScoreService`, nos boundaries que a tarefa exige (`-6, -5, -2, -1, 0, 1, 4, 5`, mais `null`):

```php
private function invocarRegua(object $service, string $metodo, ?float $pct): ?float
{
    $ref = new ReflectionMethod($service, $metodo);
    $ref->setAccessible(true);

    return $ref->invoke($service, $pct);
}

#[Test]
public function test_regua_faturamento_concorda_com_desempenho_score_service_nos_boundaries(): void
{
    $original = app(DesempenhoScoreService::class);
    $novo     = app(CompanyScoreService::class);

    foreach ([null, -6.0, -5.0, -2.0, -1.0, 0.0, 1.0, 4.0, 5.0, 5.01, -6.01] as $pct) {
        $this->assertSame(
            $this->invocarRegua($original, 'reguaFaturamento', $pct),
            $this->invocarRegua($novo, 'reguaFaturamento', $pct),
            "reguaFaturamento diverge no boundary pct={$pct}"
        );
    }
}
// repetir análogo para reguaMargem
```

Nota do débito registrado em `118-01-SUMMARY.md` — "a proteção contra divergência é um teste de equivalência, não a extração completa" — o mesmo enquadramento se aplica aqui: registrar no SUMMARY da 119 que a unificação real (extrair para classe compartilhada) fica para quando o gate de aditividade sair (Fase 120).

---

### Convenção de suíte e fixture financeira

**Local:** `tests/Feature/Phase119/` (confirmado pelo Wave 0 Gaps do RESEARCH — 6 arquivos esperados: `CompanyScoreServiceContratoTest`, `FormulaTest`, `MargemTest`, `DispatcherTest`, `FonteTest`, `StatusTest`).

**Trait de cenário — reusar sem reimplementar:**
```php
use Tests\Feature\V16\CriaCenarioResponsaveis;
```
(mesmo trait usado em `NpsJanelaResolverTest`, `NpsPorEmpresaContratoTest`, `DesempenhoShopeeScoreTest` — helpers `criarServico()`, `criarContrato()`, `inserirPivot()`.)

**Fixture financeira Adman com `Http::fake()` — molde de `tests/Feature/V18/AdmanMetricDiffServiceTest.php`:**

Fixture MPP-06 já validada e pronta para reuso (linhas 52-62 e 115-128):
```php
private function respostaAccountMetrics(): array
{
    return [
        'metrics' => [
            'billing'           => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
            'liquidMargin'      => ['value' => 141428.81, 'diff' => 147.96, 'prev' => 57036.05],
            'percentageMargin'  => ['value' => 27.47,     'diff' => 14.09,  'prev' => 24.08],
            'investment'        => ['value' => 9990.82,   'diff' => 80.36,  'prev' => 5539.43],
        ],
    ];
}

private function fakeAdmanEndpoints(?float $percentageMarginDiff = 14.09): void
{
    $accountMetrics = $this->respostaAccountMetrics();
    if ($percentageMarginDiff === null) {
        unset($accountMetrics['metrics']['percentageMargin']['diff']);
    } else {
        $accountMetrics['metrics']['percentageMargin']['diff'] = $percentageMarginDiff;
    }

    Http::fake([
        '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
        '*/accounts/*/metrics*' => Http::response($accountMetrics, 200),
    ]);
}
```
Com esta fixture, `diff_pp = round(27.47 - 24.08, 2) = 3.39` ⇒ `reguaMargem(3.39) = 4`, enquanto `diff_pct = 14.09` ⇒ `reguaMargem(14.09) = 5` — a prova viva de EMPS-03 (usar o RESEARCH `test_r_fixture_ancora_diff_pp_3_39_nao_deriva_de_diff_pct` como referência de asserção).

**Molde de carteira multi-empresa com `Http::preventStrayRequests()` — de `tests/Feature/DesempenhoShopeeScoreTest.php` (linhas 46-65):**
```php
protected function setUp(): void
{
    parent::setUp();

    DB::statement('PRAGMA foreign_keys = ON');
    Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

    Http::preventStrayRequests();
    Http::fake([
        '*/performance/*'       => Http::response([], 404),
        '*/accounts/*/metrics*' => Http::response([], 404),
    ]);
    // sobrescrever por teste com fakeAdmanEndpoints() quando precisar de dado real
}
```
**Crítico para esta fase:** `Http::preventStrayRequests()` é obrigatório em TODO teste que toque `MetricDiffDispatcher`/`AdmanMetricDiffService` — o GATE MPP-04 não está aprovado, então nenhum teste pode arriscar uma chamada real à Adman. Qualquer `diff_pp` testado deve vir de `Http::fake()`, nunca de rede real (RESEARCH, "Risco 2").

**Assert de "dispatcher chamado 1x por empresa" (EMPS-05)** — usar `Http::assertSentCount()` sobre os endpoints Adman fakeados, contando por empresa na carteira (mesmo idioma de asserção de `Http::fake` já usado nos testes V18).

---

## Shared Patterns

### Universo de carteira (nunca pré-filtrado por elegibilidade)
**Fonte:** `app/Services/Portfolio/CarteiraContextService.php::forUser()`
**Aplicar a:** `CompanyScoreService::computeEmpresasScore()`
```php
$vinculos = $this->carteiraContext->forUser($user, ['active' => true])
    ->reject(fn (array $v) => $invalidadas->contains($v['company_id']));
$companiesUniverso = $vinculos->pluck('company_id')->unique()->values();
```
Nunca usar `$user->companies()` (carteira consolidada legada) nem join direto em `company_users.servico_id` — perde o ramo legado (`servico_id NULL`) e pode contar/descartar vínculos errados (memória `project_company_users_multi_linha_servico`).

### Guard de fonte nula antes do dispatcher (C-04)
**Fonte:** `app/Services/Metrics/MetricDiffDispatcher.php:36-42`
**Aplicar a:** qualquer ponto do `CompanyScoreService` que chame `compute()`
```php
if ($fonteFinanceira !== null) {
    $resultado = $this->diffDispatcher->compute($company, $periodo, $fonteFinanceira);
    // ...
}
```

### Gate de aditividade em toda task
**Fonte:** Fases 117/118 (`sha256sum` + `git diff --name-only`)
**Aplicar a:** todas as tasks do plano da Fase 119
```bash
sha256sum app/Services/DesempenhoScoreService.php | grep -q '^cfc16da2a8404fba0d4a9a2bc62cd1a6f668bd17fe390fe6405cebd4e71a9edd'
```

### `quality` como veículo de auditoria
**Fonte:** `app/Services/Metrics/AdmanMetricDiffService.php` (`buildQuality()`, `status`/`source`/`computed_at`), replicado em `NpsPorEmpresaService` (`origem`, `por_ramo`)
**Aplicar a:** shape de retorno do `CompanyScoreService` — campo `quality.motivos` (array de strings), `quality.margin_source`, `quality.revenue_diff_source`/`margin_diff_source`.

### `Http::preventStrayRequests()` + `Http::fake()` obrigatório em testes financeiros
**Fonte:** `tests/Feature/DesempenhoShopeeScoreTest.php:61-65`, `tests/Feature/V18/AdmanMetricDiffServiceTest.php`
**Aplicar a:** todos os testes de `tests/Feature/Phase119/` que envolvam `MetricDiffDispatcher`/Adman — nenhuma chamada real à Adman é aceitável enquanto o GATE MPP-04 não sair aprovado.

---

## No Analog Found

Nenhum arquivo desta fase ficou sem análogo — a Fase 118 cobre o precedente estrutural completo (serviço agregador + suíte de testes + gate de hash), e a Fase 101/V18 cobre o precedente de fixture financeira via `Http::fake()`.

---

## Metadata

**Escopo de busca de análogos:** `app/Services/Desempenho/`, `app/Services/Metrics/`, `app/Services/Portfolio/`, `app/Services/DesempenhoScoreService.php`, `tests/Feature/Phase118/`, `tests/Feature/V18/`, `tests/Feature/DesempenhoShopeeScoreTest.php`
**Arquivos lidos:** `DesempenhoScoreService.php` (linhas 1080-1409), `NpsPorEmpresaService.php` (completo, 517 linhas), `MetricDiffDispatcher.php` (completo), `CarteiraContextService.php` (completo), `NpsJanelaResolverTest.php` (completo), `AdmanMetricDiffServiceTest.php` (linhas 1-135), `DesempenhoShopeeScoreTest.php` (linhas 1-120)
**Data de extração:** 2026-07-28
