# Fase 38: Painel do Publicador — Mapa de Padrões

**Mapeado em:** 2026-06-26
**Arquivos analisados:** 4 (2 novos, 2 modificados)
**Análogos encontrados:** 4 / 4

---

## Classificação de Arquivos

| Arquivo Novo / Modificado | Papel | Fluxo de Dados | Análogo Mais Próximo | Qualidade do Match |
|---|---|---|---|---|
| `app/Services/PublicadorScoreService.php` | service | transform (aggregation) | `app/Services/PortfolioScoreService.php` | exato |
| `app/Http/Controllers/MlbController.php` (método `meuPainel`) | controller | request-response | `app/Http/Controllers/MlbController.php` (próprio, padrão atual) | exato |
| `resources/js/Pages/Mlb/MeuPainel.jsx` | component (page) | request-response | `resources/js/Pages/Portfolio/Show.jsx` | exato |
| `tests/Unit/PublicadorScoreServiceTest.php` | test (unit) | transform | `tests/Unit/CalcularFaixaTest.php` | role-match |
| `tests/Feature/Phase38Publicador/MeuPainelControllerTest.php` | test (feature) | request-response | `tests/Feature/Phase38/PolosControllerTest.php` | exato |

---

## Atribuições de Padrão por Arquivo

---

### `app/Services/PublicadorScoreService.php` (service, transform)

**Análogo:** `app/Services/PortfolioScoreService.php`

**Padrão de imports** (linhas 1–10):
```php
<?php

namespace App\Services;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use Carbon\Carbon;
```
> Observação: o `PublicadorScoreService` NÃO injeta `AdmanService` no construtor (diferente do `PortfolioScoreService` linha 43) — não há dependência de API externa.

**Padrão de docblock de classe** (linhas 12–40 do análogo — adaptar):
```php
/**
 * Calcula score 0-100, classificação e detalhes dos 5 eixos para um publicador.
 *
 * Eixos e pesos:
 *   35%  Meta: feito / meta-do-mês (via metaParaMes())
 *   25%  Produtividade: feito vs meta (escala dois-segmentos: 100%→80pts, 130%→100pts)
 *   20%  Pontualidade: % SKUs sem atraso em empresas com responsavel_id=$userId
 *   10%  Conversão: % anúncios vendidos no mês
 *   10%  Qualidade: sem_problema(60%) + feedbacks_resolvidos(40%)
 *
 * Quando um eixo não tem dado (ex: sem empresas responsáveis), o peso é
 * redistribuído automaticamente via scoreFinal() — não penaliza quem não tem dado.
 */
class PublicadorScoreService
```

**Padrão core: estrutura `$pontos[]` + `scoreFinal()` + `classificar()`** (análogo linhas 283–292):
```php
// Formato IDÊNTICO ao PortfolioScoreService — copiar verbatim a estrutura
$pontos = [
    'meta'          => ['valor' => $pontosMeta,          'peso' => 35],
    'produtividade' => ['valor' => $pontosProdutividade, 'peso' => 25],
    'pontualidade'  => ['valor' => $pontosPontualidade,  'peso' => 20],
    'conversao'     => ['valor' => $pontosConversao,     'peso' => 10],
    'qualidade'     => ['valor' => $pontosQualidade,     'peso' => 10],
];
$score   = $this->scoreFinal($pontos);
$classif = $this->classificar($score);
```

**`scoreFinal()` — copiar verbatim do análogo** (linhas 379–390):
```php
private function scoreFinal(array $pontos): float
{
    $totalPeso     = 0;
    $somaPonderada = 0.0;
    foreach ($pontos as $p) {
        if ($p['valor'] === null) continue;
        $totalPeso     += $p['peso'];
        $somaPonderada += $p['peso'] * (float) $p['valor'];
    }
    if ($totalPeso === 0) return 0.0;
    return round($somaPonderada / $totalPeso, 1);
}
```

**`classificar()` — copiar verbatim do análogo** (linhas 392–398):
```php
private function classificar(float $score): string
{
    if ($score >= 85) return 'excelente';
    if ($score >= 70) return 'bom';
    if ($score >= 55) return 'atencao';
    return 'critico';
}
```

**Padrão de qualidade combinada — dois componentes com pesos** (análogo `scoreQualidade()` linhas 363–374):
```php
// Mesmo padrão de combinar dois componentes (NPS×70%+presença×30% no análogo;
// sem_problema×60%+feedbacks_resolvidos×40% no PublicadorScoreService)
private function scoreQualidade(?float $avgNps, int $meetingsCount, ?float $absenteismoPct): ?float
{
    if ($avgNps === null && $meetingsCount === 0) return null;
    $pNps     = $avgNps !== null ? max(0.0, min(100.0, ($avgNps / 5.0) * 100.0)) : null;
    $pPresenca = $absenteismoPct !== null ? max(0.0, 100.0 - $absenteismoPct) : null;
    if ($pNps !== null && $pPresenca !== null) {
        return round($pNps * 0.7 + $pPresenca * 0.3, 2);
    }
    return round($pNps ?? $pPresenca ?? 0, 2);
}
```

**Padrão de retorno do método `compute()`** (análogo linhas 294–343):
```php
// O análogo retorna array com chaves: tem_base_comparativa, metricas, pontos_categoria, score, classificacao, periodo
// Para o PublicadorScoreService, o retorno deve ser:
return [
    'score'             => $score,          // float 0-100
    'classificacao'     => $classif,        // 'excelente'|'bom'|'atencao'|'critico'
    'pontos_categoria'  => $pontos,         // para o radar (5 dimensões com valor+peso)
    'metricas'          => [                // para os MiniMetrics cards
        'meta'          => ['feito' => $feito, 'alvo' => $meta, 'pct' => $pontosMeta],
        'produtividade' => ['feito' => $feito, 'meta' => $meta, 'pct' => $pontosProdutividade],
        'pontualidade'  => ['total_skus' => $totalSkus, 'atrasados' => $atrasadosCnt, 'pct_no_prazo' => $pontosPontualidade],
        'conversao'     => ['vendidos' => $vendas, 'feito' => $feito, 'pct' => $pontosConversao],
        'qualidade'     => ['pct_sem_problema' => $pctSemProblema, 'pct_feedbacks_resolvidos' => $pctFeedbacksResolvidos],
    ],
];
```

**Padrão de iteração sobre `skus_estagio*`** (análogo do `MlbController::dashboard()` linhas 461–481):
```php
// Fonte real: MlbController.php:461-481 (loop de atrasos no dashboard)
// PublicadorScoreService replica este padrão filtrando por responsavel_id=$userId
$empresas = MlbEmpresa::where('responsavel_id', $userId)
    ->get(['id','skus_estagio1','skus_estagio2','skus_estagio3',
           'prazo_estagio1','prazo_estagio2','prazo_estagio3']);

$hoje = now()->startOfDay();
foreach ($empresas as $e) {
    for ($stage = 1; $stage <= 3; $stage++) {
        $skus     = $e->{"skus_estagio{$stage}"} ?? [];
        $prazoRaw = $e->{"prazo_estagio{$stage}"};
        $prazoVenc = $prazoRaw && $hoje->gt(Carbon::parse($prazoRaw)->startOfDay());
        foreach ($skus as $sku) {
            if (trim($sku['sku'] ?? '') === '') continue;
            // ...contagem de atrasados
        }
    }
}
```

---

### `app/Http/Controllers/MlbController.php` — método `meuPainel()` (controller, request-response)

**Análogo:** próprio método `meuPainel()` (linhas 531–641) + `Inertia::render()` de `dashboard()` (linhas 488–528)

**Padrão de cabeçalho do método (linhas 531–540)**:
```php
public function meuPainel(Request $request): Response
{
    $this->checkPubAccess('meu_painel');

    $user   = $request->user();
    $mesRef = $request->get('mes', now()->format('Y-m'));
    $meta   = $this->metaParaMes($user->id, $mesRef);
    $ref    = Carbon::createFromFormat('Y-m', $mesRef)->startOfMonth();
    $meses  = $this->mesesDisponiveis($user->id);
    $kpis   = $this->calcularKpis($user->id, $ref, $meta);
```
> A adição da Fase 38 injeta a validação de `$mesRef` e a chamada ao `PublicadorScoreService` aqui, após `$kpis`, antes do bloco de queries de evolução.

**Padrão de import a adicionar no topo do controller (linha 16, bloco `use`):**
```php
use App\Services\PublicadorScoreService;
```

**Padrão de `Inertia::render()` — array expandido** (análogo linhas 626–640):
```php
// Padrão atual — MANTER todas as chaves existentes e ADICIONAR as novas abaixo
return Inertia::render('Mlb/MeuPainel', [
    // ── Props existentes (não alterar) ──
    'kpis'           => $kpis,
    'evolucaoDiaria' => $evolucaoDiaria,
    'topEmpresas'    => $topEmpresas,
    'feedbacks'      => $feedbacks,
    'meta'           => $meta,
    'mesRef'         => $mesRef,
    'meses'          => $meses,
    'problemas'      => ['empresas' => $empresasProblema, 'anuncios' => $anunciosProblema],
    'ticketEvolucao' => $ticketEvolucao,
    'ticketAtual'    => $ticketAtual,
    // ── Props novas (Fase 38) ──
    'score_publicador'        => $scoreData,           // array do PublicadorScoreService::compute()
    'faturamento_mes'         => $faturamentoMes,      // float — SUM(net_billing) do mês
    'net_billing_timeseries'  => $netBillingTimeseries, // [{ date: 'YYYY-MM-DD', realizado: float }]
]);
```

**Query de `net_billing_timeseries`** — padrão da query de evolução diária (linhas 545–552, adaptado):
```php
// Fonte: padrão de $evolucaoDiaria (linhas 545-552), mas soma net_billing e acumula
$netBillingRows = Publicacao::where('user_id', $user->id)
    ->whereBetween('data', [$primeiro, $ultimo])
    ->where('tipo', '!=', 'variacao')
    ->whereNotNull('net_billing')
    ->selectRaw('data, SUM(net_billing) as billing_dia')
    ->groupBy('data')
    ->orderBy('data')
    ->get();

$acumulado = 0;
$netBillingTimeseries = $netBillingRows->map(function ($r) use (&$acumulado) {
    $acumulado += (float) $r->billing_dia;
    return ['date' => $r->data->format('Y-m-d'), 'realizado' => round($acumulado, 2)];
})->values()->all();
```

**`calcularKpis()` já retorna `feito` e `vendas`** (linhas 188–203 do controller):
```php
// Campos reutilizáveis do $kpis existente (NÃO replicar query):
$kpis['feito']   // int — COUNT de publicações do mês (tipo != variacao)
$kpis['vendas']  // int — COUNT com vendido=true
// Passar esses valores para PublicadorScoreService::compute() ao invés de recalcular
```

---

### `resources/js/Pages/Mlb/MeuPainel.jsx` (component page, request-response)

**Análogo:** `resources/js/Pages/Portfolio/Show.jsx`

**Padrão de imports** (Show.jsx linhas 1–21 — adaptar para o escopo da página):
```jsx
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Award, Target, BarChart2, Clock, CheckCircle, AlertTriangle,
} from 'lucide-react';
import { cn, formatCurrencyCompact, formatPercent } from '@/lib/utils';
import {
    ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip,
    RadialBarChart, RadialBar, PolarAngleAxis,
    RadarChart, Radar, PolarGrid, PolarRadiusAxis,
} from 'recharts';
```
> Manter os imports atuais de `MeuPainel.jsx` que forem reutilizados (TicketIndividualChart, AlertTriangle) e ADICIONAR os de recharts/radar.

**Padrão de lookup tables de classificação** (Show.jsx linhas 53–72 — copiar verbatim):
```jsx
const CLASSIF_LABEL = {
    excelente: 'Excelente',
    bom:       'Bom',
    atencao:   'Atenção',
    critico:   'Crítico',
};
const CLASSIF_CLS = {
    excelente: 'text-emerald-300',
    bom:       'text-sky-300',
    atencao:   'text-amber-300',
    critico:   'text-red-300',
};
const CLASSIF_BG = {
    excelente: 'bg-emerald-500/10 border-emerald-500/30',
    bom:       'bg-sky-500/10 border-sky-500/30',
    atencao:   'bg-amber-500/10 border-amber-500/30',
    critico:   'bg-red-500/10 border-red-500/30',
};
const SCORE_COLOR = {
    excelente: '#10b981',
    bom:       '#3b82f6',
    atencao:   '#f59e0b',
    critico:   '#ef4444',
};
```

**`KpiCard` — copiar verbatim do análogo** (Show.jsx linhas 91–114):
```jsx
function KpiCard({ label, value, sub, icon: Icon, accent = 'text-white/85', help, onClick, children }) {
    const clickable = !!onClick;
    return (
        <Card
            className={cn(
                'bg-ecf-card/60 border-white/[0.06]',
                clickable && 'cursor-pointer hover:border-white/20 transition-colors'
            )}
            onClick={onClick}
            title={help}
        >
            <CardContent className="p-4">
                <div className="flex items-center gap-2 text-white/50 text-[11px] uppercase tracking-wide">
                    {Icon && <Icon size={12} />}
                    {label}
                    {help && <span className="text-white/30 text-[9px] cursor-help">ⓘ</span>}
                </div>
                <div className={cn('mt-1.5 text-2xl font-bold tabular-nums', accent)}>{value}</div>
                {sub && <div className="text-white/40 text-[11px] mt-0.5">{sub}</div>}
                {children}
            </CardContent>
        </Card>
    );
}
```

**`Chip` — copiar verbatim do análogo** (Show.jsx linhas 118–130):
```jsx
function Chip({ children, tone = 'neutral' }) {
    const tones = {
        positive: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/25',
        negative: 'bg-red-500/15 text-red-300 border-red-500/25',
        neutral:  'bg-white/10 text-white/80 border-white/15',
        meta:     'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/30',
    };
    return (
        <span className={cn('inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-full border', tones[tone])}>
            {children}
        </span>
    );
}
```

**`MiniMetric` — copiar verbatim do análogo** (Show.jsx linhas 315–326):
```jsx
function MiniMetric({ label, value, sub, help }) {
    return (
        <div className="rounded-lg bg-white/[0.04] border border-white/[0.06] px-3 py-2 group relative" title={help}>
            <div className="text-white/45 text-[10px] uppercase tracking-wide flex items-center gap-1">
                {label}
                {help && <span className="text-white/30 text-[9px] cursor-help">ⓘ</span>}
            </div>
            <div className="text-white/90 text-base font-bold tabular-nums leading-tight mt-0.5">{value}</div>
            <div className="text-white/35 text-[10px] mt-0.5">{sub}</div>
        </div>
    );
}
```

**`ChartTooltip` — copiar do análogo e adaptar** (Show.jsx linhas 369–411):
```jsx
// Adaptar: remover meta_acumulada e projecao (v1 usa só linha "realizado")
function ChartTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    const ponto     = payload[0]?.payload ?? {};
    const realizado = ponto.realizado;
    const dateLabel = label ? label.slice(8, 10) + '/' + label.slice(5, 7) : '';
    return (
        <div className="rounded-lg border border-white/15 bg-ecf-bg/95 backdrop-blur px-3 py-2 text-[12px] shadow-xl">
            <div className="text-white/90 font-semibold mb-1">{dateLabel}</div>
            {realizado != null && (
                <div className="flex items-center justify-between gap-4">
                    <span className="text-emerald-300">Faturamento acumulado</span>
                    <span className="text-white/90 tabular-nums">{formatCurrencyCompact(realizado)}</span>
                </div>
            )}
        </div>
    );
}
```

**`PerformanceSection` — RadialBarChart + RadarChart** (Show.jsx linhas 141–306):
```jsx
// Copiar a estrutura completa de PerformanceSection de Show.jsx:141-306
// Adaptar APENAS:
// 1. Título: "Performance do profissional" → "Desempenho do Publicador"
// 2. Remover prop `comparacao` (sem comparação com pares na v1)
// 3. Dimensões do radar: substituir as 5 dimensões da carteira pelas do publicador
const dimensoes = score_publicador ? [
    { dim: 'Meta',          valor: score_publicador.pontos_categoria.meta?.valor ?? 0,          bruto: score_publicador.metricas.meta?.pct,                  sufixo: '%' },
    { dim: 'Produtividade', valor: score_publicador.pontos_categoria.produtividade?.valor ?? 0, bruto: score_publicador.metricas.produtividade?.pct,          sufixo: '%' },
    { dim: 'Pontualidade',  valor: score_publicador.pontos_categoria.pontualidade?.valor ?? 0,  bruto: score_publicador.metricas.pontualidade?.pct_no_prazo,  sufixo: '%' },
    { dim: 'Conversão',     valor: score_publicador.pontos_categoria.conversao?.valor ?? 0,     bruto: score_publicador.metricas.conversao?.pct,              sufixo: '%' },
    { dim: 'Qualidade',     valor: score_publicador.pontos_categoria.qualidade?.valor ?? 0,     bruto: score_publicador.metricas.qualidade?.pct_sem_problema,  sufixo: '%' },
] : [];

// RadialBarChart — copiar verbatim de Show.jsx:182-211 (dados: [{ name:'Score', value: data.score, fill: cor }])
// RadarChart — copiar verbatim de Show.jsx:218-256 (dataKey="valor", payload.bruto para tooltip)
```

**LineChart de evolução do faturamento** (Show.jsx linhas 720–771 — simplificado para v1):
```jsx
// Copiar o bloco do LineChart de Show.jsx:719-771
// Adaptar: remover as linhas meta_acumulada e projecao (só "realizado" na v1)
// Shape de dados: net_billing_timeseries = [{ date: 'YYYY-MM-DD', realizado: float }]
<LineChart data={net_billing_timeseries} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
    <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.05)" />
    <XAxis
        dataKey="date"
        tick={{ fill: 'rgba(255,255,255,0.4)', fontSize: 10 }}
        tickFormatter={(d) => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : ''}
        stroke="rgba(255,255,255,0.1)"
    />
    <YAxis
        tick={{ fill: 'rgba(255,255,255,0.4)', fontSize: 10 }}
        tickFormatter={(v) => formatCurrencyCompact(v)}
        stroke="rgba(255,255,255,0.1)"
    />
    <Tooltip content={<ChartTooltip />} />
    <Line
        type="monotone"
        dataKey="realizado"
        name="Faturamento"
        stroke="#10b981"
        strokeWidth={2.5}
        dot={false}
        activeDot={{ r: 5 }}
        connectNulls={false}
    />
</LineChart>
```

**Assinatura do componente default — props expandidas**:
```jsx
export default function MeuPainel({
    // Props existentes (manter)
    kpis, evolucaoDiaria, topEmpresas, feedbacks, meta, mesRef, meses,
    problemas, ticketEvolucao, ticketAtual,
    // Props novas (Fase 38)
    score_publicador,         // { score, classificacao, pontos_categoria, metricas }
    faturamento_mes,          // float
    net_billing_timeseries,   // [{ date, realizado }]
}) { ... }
```

**Seletor de mês existente em MeuPainel.jsx — manter exatamente como está** (MeuPainel.jsx — `<select>` nativo, não Radix):
```jsx
// O seletor de mês atual usa <select> nativo (não componente Radix)
// Manter o padrão atual; NÃO trocar para Radix Select (risco Radix Select com value="" → tela preta)
```

---

### `tests/Unit/PublicadorScoreServiceTest.php` (test, unit)

**Análogo:** `tests/Unit/CalcularFaixaTest.php`

**Padrão de namespace e estrutura** (CalcularFaixaTest.php linhas 1–16):
```php
<?php

namespace Tests\Unit;

use App\Services\PublicadorScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicadorScoreServiceTest extends TestCase
{
    use RefreshDatabase; // necessário pois o Service faz queries no banco
```

**Padrão de método de teste simples** (CalcularFaixaTest.php linhas 17–21):
```php
public function test_score_completo_retorna_float_entre_0_e_100(): void
{
    // ...criar dados com factory/insert direto, instanciar Service, assertar
    $this->assertGreaterThanOrEqual(0.0, $resultado['score']);
    $this->assertLessThanOrEqual(100.0, $resultado['score']);
}
```

**Padrão de instanciar service sem DI complexa** (CalcularFaixaTest.php linhas 9–15 — reflexão para métodos privados, mas Service público via `new`):
```php
// PublicadorScoreService não tem dependências externas — instanciar diretamente
$service = new PublicadorScoreService();
$resultado = $service->compute($userId, $mesRef);
```

**Padrão de dados sintéticos por insert direto** (PolosControllerTest.php linhas 113–124):
```php
// Preferir MlbEmpresa::create([...]) e Publicacao::create([...]) a factories
// (não há factories para esses models — padrão do projeto é insert direto nos testes)
Publicacao::create([
    'user_id'    => $user->id,
    'data'       => now()->startOfMonth()->toDateString(),
    'empresa'    => 'Empresa Teste',
    'tipo'       => 'publicacao',
    'vendido'    => true,
    'vendas_qty' => 2,
    'net_billing' => 1500.00,
    'problema'   => false,
]);
```

---

### `tests/Feature/Phase38Publicador/MeuPainelControllerTest.php` (test, feature)

**Análogo:** `tests/Feature/Phase38/PolosControllerTest.php`

**Padrão de namespace e imports** (PolosControllerTest.php linhas 22–33):
```php
<?php

namespace Tests\Feature\Phase38Publicador;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeuPainelControllerTest extends TestCase
{
    use RefreshDatabase;
```

**Helper para criar usuário com permissão `mlb.meu_painel`** (adaptar de PolosControllerTest.php linha 56):
```php
// checkPubAccess('meu_painel') aceita admin sem verificação adicional
// Para Feature tests, usar admin simplifica (não exige setup de setor/cargo)
private function publicador(): User
{
    return User::factory()->create([
        'role'              => 'admin', // admin bypassa checkPubAccess
        'email_verified_at' => now(),
    ]);
}
```

**Padrão de `assertInertia` com `has` e `where`** (PolosControllerTest.php linhas 137–149):
```php
$this->actingAs($this->publicador())
    ->get(route('mlb.meu-painel'))
    ->assertStatus(200)
    ->assertInertia(fn (Assert $p) => $p
        ->component('Mlb/MeuPainel')
        ->has('score_publicador')
        ->has('faturamento_mes')
        ->has('net_billing_timeseries')
        // props existentes também devem continuar presentes:
        ->has('kpis')
        ->has('meta')
        ->has('mesRef')
    );
```

**Padrão de teste de prop vazia válida** (PolosControllerTest.php linhas 517–531):
```php
// Padrão: ECF Drive offline → degradação graciosa; aqui: sem publicações → score válido
public function test_sem_publicacoes_retorna_props_validas(): void
{
    // Sem Publicacao no banco → score deve ser 0.0 sem exception
    $this->actingAs($this->publicador())
        ->get(route('mlb.meu-painel'))
        ->assertStatus(200)
        ->assertInertia(fn (Assert $p) => $p
            ->where('faturamento_mes', 0.0)
            ->where('score_publicador.score', 0.0)
        );
}
```

---

## Padrões Compartilhados

### Tratamento de Erros — `abort(403)` (aplicar ao Service)
**Fonte:** `app/Http/Controllers/MlbController.php` linhas 38–56  
**Aplicar a:** MlbController (o padrão `checkPubAccess` já existente — não alterar)
```php
// Padrão já existente — não replicar no Service
if (!$temAcessoMlb) {
    abort(403, 'Acesso restrito ao módulo de publicações MLB.');
}
```

### Validação de `$mesRef` (novo em `meuPainel()`)
**Fonte:** RESEARCH.md §Segurança + padrão `$request->get()` já usado na linha 536
```php
// Adicionar após linha 536 do controller:
$mesRef = $request->get('mes', now()->format('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) {
    $mesRef = now()->format('Y-m');
}
```

### Classes condicionais com `cn()` (aplicar em MeuPainel.jsx)
**Fonte:** `resources/js/lib/utils.js` + Show.jsx (uso em cada componente)
```jsx
import { cn, formatCurrencyCompact, formatPercent } from '@/lib/utils';
// cn() em vez de template strings; formatCurrencyCompact() em vez de função local
```

### Logs com prefixo de módulo (aplicar ao Service se necessário)
**Fonte:** Convenção do projeto (CLAUDE.md §Logging)
```php
// Não há log necessário no PublicadorScoreService (sem chamada de API externa)
// Se houver exception inesperada, capturar no controller com:
Log::error('[MLB MeuPainel] Erro ao calcular score publicador', ['user_id' => $user->id, 'erro' => $e->getMessage()]);
```

---

## Sem Análogo Encontrado

Nenhum arquivo desta fase ficou sem análogo. Todos os 4 artefatos têm correspondente direto no código existente.

---

## Metadados

**Escopo de busca de análogos:**
- `app/Services/` — todos os services
- `app/Http/Controllers/MlbController.php` — método-alvo e métodos vizinhos
- `resources/js/Pages/Portfolio/Show.jsx` — componentes de score/radar
- `resources/js/Pages/Mlb/MeuPainel.jsx` — estado atual da página
- `tests/Unit/` e `tests/Feature/Phase38/` — padrões de teste

**Arquivos lidos:** 9 arquivos de código-fonte  
**Data do mapeamento:** 2026-06-26

---

## PATTERN MAPPING COMPLETE

**Fase:** 38 — Painel do Publicador (score + radar)
**Arquivos classificados:** 5 (incluindo os 2 de teste)
**Análogos encontrados:** 5 / 5

### Cobertura
- Arquivos com análogo exato: 4
- Arquivos com análogo por role-match: 1 (`PublicadorScoreServiceTest` → `CalcularFaixaTest`)
- Arquivos sem análogo: 0

### Padrões-chave Identificados
- `scoreFinal()` e `classificar()` do `PortfolioScoreService` são **copiados verbatim** — não reimplementar
- `PerformanceSection` de `Show.jsx` (RadialBarChart + RadarChart + MiniMetric) é o **molde estrutural** para o bloco de score do publicador
- `Inertia::render()` em `meuPainel()` recebe as novas props **adicionalmente** (nunca substituindo) — backward compatible
- Testes Feature usam `actingAs(admin)` + `assertInertia(Assert)` com `has()` e `where()` — padrão da `Phase38/PolosControllerTest`
- Testes Unit instanciam o Service diretamente com `new` (sem DI) e usam insert direto (sem factories inexistentes)

### Arquivo Criado
`C:\xampp\htdocs\ecf_admin\.planning\phases\38-painel-do-publicador-evoluir-meu-painel-para-formato-score-r\38-PATTERNS.md`

### Pronto para Planejamento
O mapeamento de padrões está completo. O planejador pode referenciar os trechos de análogo diretamente nas ações de cada PLAN.md.
