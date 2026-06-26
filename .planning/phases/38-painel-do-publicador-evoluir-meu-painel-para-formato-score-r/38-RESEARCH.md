# Fase 38: Painel do Publicador (score + radar) — Pesquisa

**Pesquisado em:** 2026-06-26
**Domínio:** Evolução de UI/UX + cálculo de score 0-100 para módulo MLB
**Confiança geral:** ALTA — todos os dados vêm do código-fonte real do projeto

---

<user_constraints>
## Restrições do Usuário (do CONTEXT.md)

### Decisões Travadas

| Decisão | Escolha |
|---|---|
| **Nome da seção** | "Painel do Publicador" |
| **Eixos do radar/score (5)** | Meta · Produtividade · Pontualidade · Conversão · Qualidade |
| **Escopo da tela** | Evoluir o "Meu Painel" existente (NÃO criar tela separada) |
| **Visão** | Só individual — cada publicador vê o seu; sem tela consolidada admin/líder |

### Alvo a Evoluir (não trocar rota/permissão)

- Rota mantida: `GET /mlb/meu-painel` → `MlbController@meuPainel` → `Inertia::render('Mlb/MeuPainel', ...)`
- Permissão: `mlb.meu_painel` — permanece idêntica
- Helpers a reaproveitar: `calcularKpis()`, `metaParaMes()`, `metaCadastrada()`, `mesesDisponiveis()`

### Fora de Escopo (NÃO fazer)

- Tela consolidada admin/líder (cards de todos os publicadores)
- Migrations / coleta de dados nova
- Mudar rota, nome de rota ou permissão

### Constraints Técnicas

- Stack fixa: Laravel 12 + Inertia + React. **Nenhuma migration nova.**
- Tailwind tokens `ecf-*`, dark theme, `cn()`, componentes `ui/` e `recharts` já no projeto.
- Comentários em **pt-BR**.
- **NÃO fazer deploy.** Rodar `npm run build` ao final de qualquer alteração de frontend.
</user_constraints>

---

## Resumo Executivo

A Fase 38 é uma **evolução de UI e lógica de cálculo** sobre código 100% existente — sem migration, sem nova rota, sem novo modelo. O trabalho central é:

1. Criar `app/Services/PublicadorScoreService.php` — análogo ao `PortfolioScoreService`, mas alimentado por dados de `mlb_publicacoes` e `mlb_empresas` ao invés de `adman_metrics`.
2. Evoluir `MlbController::meuPainel()` para chamar o novo serviço e passar props adicionais para a página.
3. Reescrever `resources/js/Pages/Mlb/MeuPainel.jsx` usando os componentes visuais de `Portfolio/Show.jsx` como molde (RadialBarChart, RadarChart, KpiCard, MiniMetric, ChartTooltip, LineChart).

Todo dado necessário já existe nas colunas atuais de `mlb_publicacoes` (`net_billing`, `vendido`, `vendas_qty`, `problema`, `comentario_resolvido`, `revisado`, `user_id`, `data`, `tipo`) e em `mlb_empresas` (`skus_estagio1/2/3`, `prazo_estagio1/2/3`, `responsavel_id`).

**Recomendação principal:** Criar `PublicadorScoreService` seguindo rigorosamente o padrão do `PortfolioScoreService` — mesma estrutura de `$pontos[]` com `['valor', 'peso']`, mesmo método `scoreFinal()` com redistribuição de pesos nulos, mesmo método `classificar()`. Reaproveitar todos os helpers do controller; a expansão de props no `meuPainel()` é incremental (adicionar ao array existente, não substituir).

---

## Mapa de Responsabilidades Arquiteturais

| Capacidade | Tier Primário | Tier Secundário | Racional |
|---|---|---|---|
| Cálculo de score 0-100 e 5 eixos | API/Backend (Service) | — | Lógica de negócio ponderada, requer dados de BD |
| KPIs de faturamento/anúncios/vendas | API/Backend (Controller) | — | Agrega SQL; controller já o faz via `calcularKpis()` |
| Pontualidade (SKUs atrasados) | API/Backend (Service) | — | Precisa iterar sobre arrays JSON de `mlb_empresas` |
| Evolução de faturamento (timeseries) | API/Backend (Controller) | — | Query por data agrupada por dia |
| RadialBarChart + RadarChart + LineChart | Frontend/React | — | Recharts já disponível no projeto |
| KpiCards, MiniMetric, Chip | Frontend/React | — | Componentes reutilizados de Show.jsx |

---

## Pilha Padrão

### Core (já no projeto — nenhuma instalação necessária)

| Biblioteca | Versão | Propósito | Observação |
|---|---|---|---|
| `recharts` | `^3.8.1` | RadialBarChart, RadarChart, LineChart | [VERIFIED: package.json do projeto] |
| Laravel 12 + Inertia | `^2.0` | SPA bridge | [VERIFIED: composer.json] |
| `lucide-react` | `^1.11.0` | Ícones | [VERIFIED: package.json] |
| Tailwind CSS | `^3.2.1` + tokens `ecf-*` | Estilização | [VERIFIED: tailwind.config.js] |
| `clsx` + `tailwind-merge` + `cn()` | Já em `@/lib/utils` | Classes condicionais | [VERIFIED: resources/js/lib/utils.js] |
| `formatCurrencyCompact`, `formatPercent` | Já em `@/lib/utils` | Formatação monetária | [VERIFIED: resources/js/lib/utils.js] |

**Instalação:** nenhuma — tudo já está no projeto.

---

## Padrões de Arquitetura

### Diagrama de Fluxo de Dados

```
GET /mlb/meu-painel?mes=YYYY-MM
        │
        ▼
MlbController::meuPainel()
  ├─ metaParaMes($userId, $mesRef)          ← mlb_meta_historico
  ├─ calcularKpis($userId, $ref, $meta)     ← mlb_publicacoes
  ├─ PublicadorScoreService::compute()      ← mlb_publicacoes + mlb_empresas
  │     ├─ Meta (feito/metaParaMes)
  │     ├─ Produtividade (feito/metaParaMes como volume)
  │     ├─ Pontualidade (SKUs s/ atraso em empresas responsavel_id=$userId)
  │     ├─ Conversão (vendido/feito)
  │     └─ Qualidade (sem_problema + feedbacks_resolvidos)
  ├─ Query net_billing diário (timeseries)  ← mlb_publicacoes
  └─ Props existentes (feedbacks, topEmpresas, problemas, ticketEvolucao…)
        │
        ▼
Inertia::render('Mlb/MeuPainel', [...props expandidas...])
        │
        ▼
MeuPainel.jsx
  ├─ Header (título "Painel do Publicador" + seletor de mês)
  ├─ Card principal (faturamento net_billing em destaque + chips score/meta)
  ├─ KpiCards topo: Faturamento · Anúncios Feitos · Vendas no Mês
  ├─ PerformanceSection (RadialBarChart score + RadarChart 5 eixos + MiniMetrics)
  ├─ LineChart evolução faturamento diário/acumulado
  ├─ ProblemasSection (mantida do layout atual)
  └─ FeedbacksSection (mantida)
```

### Estrutura de Arquivos Impactados

```
app/
  Services/
    PublicadorScoreService.php      ← NOVO (criar)
    PortfolioScoreService.php       ← molde (não alterar)
  Http/Controllers/
    MlbController.php               ← ALTERAR: meuPainel() + import do novo Service

resources/js/Pages/Mlb/
  MeuPainel.jsx                     ← REESCREVER layout
  (Portfolio/Show.jsx é molde — não alterar)
```

---

## Ponto Central: PortfolioScoreService como Molde

[VERIFIED: app/Services/PortfolioScoreService.php — código-fonte lido linha a linha]

### Padrão que DEVE ser replicado

**Estrutura do array `$pontos`:**
```php
// Formato idêntico ao PortfolioScoreService — o PublicadorScoreService DEVE usar isso
$pontos = [
    'meta'          => ['valor' => $pontosMeta,          'peso' => 35],
    'produtividade' => ['valor' => $pontosProdutividade, 'peso' => 25],
    'pontualidade'  => ['valor' => $pontosPontualidade,  'peso' => 20],
    'conversao'     => ['valor' => $pontosConversao,     'peso' => 10],
    'qualidade'     => ['valor' => $pontosQualidade,     'peso' => 10],
];
$score = $this->scoreFinal($pontos); // redistribui pesos nulos automaticamente
```

**Método `scoreFinal()` — copiar verbatim:**
```php
// Fonte: PortfolioScoreService.php:379-390 (VERIFIED)
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

**Método `classificar()` — copiar verbatim:**
```php
// Fonte: PortfolioScoreService.php:392-398 (VERIFIED)
private function classificar(float $score): string
{
    if ($score >= 85) return 'excelente';
    if ($score >= 70) return 'bom';
    if ($score >= 55) return 'atencao';
    return 'critico';
}
```

---

## Fórmula de Normalização 0-100 para os 5 Eixos do Publicador

### Eixo 1 — Meta (peso: 35%)

**O que mede:** atingimento da meta de anúncios do mês.
**Fonte:** `calcularKpis()` já calcula `$kpis['feito']` e `metaParaMes()` retorna a meta.

```php
// Normalização: feito/meta * 100, cap em 100
// null quando $meta == 0 (publicador sem meta cadastrada e sem fallback)
$pontosMeta = $meta > 0
    ? min(100.0, round(($feito / $meta) * 100, 1))
    : null;
```

**Landmine:** `metaParaMes()` NUNCA retorna null — tem fallback para 220 via `cargos.meta_publicacoes`. Usar `metaCadastrada()` se quiser null quando não há meta explícita. Para o score, usar `metaParaMes()` (garante que todos têm meta), e reportar a origem na UI.

### Eixo 2 — Produtividade (peso: 25%)

**O que mede:** volume de publicações relativo ao par mais simples disponível (própria meta).
**Decisão de abordagem:** Usar a **meta como baseline** (mais simples, mais robusto). Comparar com média dos pares exigiria calcular todos os publicadores no mês — complexidade extra sem valor proporcional para uma tela individual.

```php
// Mesma lógica que Meta, mas com escala diferente:
//   0% da meta → 0 pts
//   100% da meta → 80 pts (indica "adequado")
//   >= 130% da meta → 100 pts (indica "excepcional")
// Linear: 0%→0, 100%→80, 130%→100 (dois segmentos)
$pctMeta = $meta > 0 ? ($feito / $meta) * 100 : 0;
if ($pctMeta >= 130) {
    $pontosProdutividade = 100.0;
} elseif ($pctMeta >= 100) {
    // 100%→80 e 130%→100: aumenta 20 pts nos 30% extras
    $pontosProdutividade = round(80.0 + (($pctMeta - 100) / 30) * 20, 1);
} else {
    // 0%→0 e 100%→80: linear
    $pontosProdutividade = round(($pctMeta / 100) * 80, 1);
}
// null quando $meta == 0 (sem meta cadastrada E sem cargo com meta)
$pontosProdutividade = $meta > 0 ? $pontosProdutividade : null;
```

**Alternativa considerada e descartada:** média dos pares. Exigiria buscar todos os publicadores ativos, calcular `feito` de cada um, e comparar — n+1 queries ou subquery complexa. Para v1, a meta é baseline suficiente e já está disponível.

### Eixo 3 — Pontualidade (peso: 20%)

**O que mede:** % de SKUs entregues sem atraso entre todas as empresas onde `responsavel_id = $userId`.
**Fonte:** `mlb_empresas.skus_estagio1/2/3` (array JSON com `{sku, ok, atrasado, concluido_em}`) + `prazo_estagio1/2/3`.

**Universo:** Empresas com `responsavel_id = $userId` (não empresas onde o publicador fez publicações — a responsabilidade de pontualidade é do responsável da empresa). [VERIFIED: MlbController.php:459 — loop de atrasos usa MlbEmpresa::get() filtrando por responsavel_id]

**Estrutura dos dados (verificada em MlbEmpresa.php:96-109):**
- `normalizaSkus()` garante `{sku, ok, atrasado, concluido_em}` em cada item.
- `$sku['ok'] === true` → concluído. `$sku['atrasado'] === true` → concluído mas atrasado.
- `$prazoRaw && $hoje->gt(Carbon::parse($prazoRaw)->startOfDay())` → prazo vencido.
- SKU pendente (ok=false) + prazo vencido = "atrasado pendente".

```php
// Lógica espelhada do MlbController::dashboard() linhas 461-481 (VERIFIED)
$empresas = MlbEmpresa::where('responsavel_id', $userId)
    ->get(['id','skus_estagio1','skus_estagio2','skus_estagio3',
           'prazo_estagio1','prazo_estagio2','prazo_estagio3']);

$totalSkus    = 0;
$atrasadosCnt = 0;
$hoje = now()->startOfDay();

foreach ($empresas as $e) {
    for ($stage = 1; $stage <= 3; $stage++) {
        $skus     = $e->{"skus_estagio{$stage}"} ?? [];
        $prazoRaw = $e->{"prazo_estagio{$stage}"};
        $prazoVenc = $prazoRaw && $hoje->gt(Carbon::parse($prazoRaw)->startOfDay());
        foreach ($skus as $sku) {
            if (trim($sku['sku'] ?? '') === '') continue;
            $totalSkus++;
            if (($sku['atrasado'] ?? false)) {
                // Concluído mas atrasado
                $atrasadosCnt++;
            } elseif (!($sku['ok'] ?? false) && $prazoVenc) {
                // Pendente com prazo vencido
                $atrasadosCnt++;
            }
        }
    }
}

// Normalização: % no prazo (inverso dos atrasados)
// null quando $totalSkus == 0 (publicador sem empresas responsáveis)
$pontosPontualidade = $totalSkus > 0
    ? round((($totalSkus - $atrasadosCnt) / $totalSkus) * 100, 1)
    : null;
```

**Estado vazio limpo:** Publicador sem empresas com `responsavel_id` apontando para ele → `null` → peso redistribuído para os outros 4 eixos.

### Eixo 4 — Conversão (peso: 10%)

**O que mede:** % de anúncios que geraram pelo menos uma venda no mês.
**Fonte:** `mlb_publicacoes.vendido` (boolean) e `feito` (COUNT sem variações).

```php
// Normalização direta: % conversão → 0-100
// null quando $feito == 0 (sem publicações no mês — estado vazio)
$pontosConversao = $feito > 0
    ? round(($vendas / $feito) * 100, 1)
    : null;
```

**Atenção:** `$vendas` = `COUNT WHERE vendido = true` — já calculado em `calcularKpis()` como `$kpis['vendas']`. Reaproveitar.

**Landmine divisão por zero:** protegido com `$feito > 0`. Quando o publicador não tem publicações no mês, o eixo retorna null e o peso é redistribuído.

### Eixo 5 — Qualidade (peso: 10%)

**O que mede:** ausência de problemas reportados + feedbacks resolvidos.
**Fonte:** `mlb_publicacoes.problema` (boolean) e `mlb_publicacoes.comentario_resolvido` (boolean) + `mlb_publicacoes.comentario` (not null/empty).

**Fórmula proposta (dois componentes):**

```php
// Componente A: % anúncios SEM problema (peso 60% dentro de Qualidade)
// Universo: todas as publicações do publicador no mês
$pubsMes = Publicacao::where('user_id', $userId)
    ->whereBetween('data', [$primeiro, $ultimo])
    ->where('tipo', '!=', 'variacao');

$totalPubs      = (clone $pubsMes)->count();
$comProblema    = (clone $pubsMes)->where('problema', true)->count();
$pctSemProblema = $totalPubs > 0
    ? (($totalPubs - $comProblema) / $totalPubs) * 100
    : null;

// Componente B: % feedbacks resolvidos (peso 40% dentro de Qualidade)
// Universo: publicações COM comentário (lider deu feedback)
$totalFeedbacks   = Publicacao::where('user_id', $userId)
    ->whereNotNull('comentario')
    ->where('comentario', '!=', '')
    ->count();
$feedbacksResolvidos = Publicacao::where('user_id', $userId)
    ->whereNotNull('comentario')
    ->where('comentario', '!=', '')
    ->where('comentario_resolvido', true)
    ->count();
$pctFeedbacksResolvidos = $totalFeedbacks > 0
    ? ($feedbacksResolvidos / $totalFeedbacks) * 100
    : null; // sem feedbacks = sem dado (não penaliza)

// Score de Qualidade combinado
if ($pctSemProblema === null && $pctFeedbacksResolvidos === null) {
    $pontosQualidade = null;
} elseif ($pctFeedbacksResolvidos === null) {
    $pontosQualidade = round($pctSemProblema, 1); // só componente A
} elseif ($pctSemProblema === null) {
    $pontosQualidade = round($pctFeedbacksResolvidos, 1); // só B
} else {
    $pontosQualidade = round($pctSemProblema * 0.60 + $pctFeedbacksResolvidos * 0.40, 1);
}
```

**Espelho com `scoreQualidade()` do PortfolioScoreService (linhas 363-374):** a lógica de combinar dois componentes com pesos e tratar null é idêntica à da Carteira (NPS×70% + presença×30%).

---

## Sumário de Pesos Recomendados

| Eixo | Peso | Racional |
|---|---|---|
| Meta | 35% | Compromisso primário do publicador |
| Produtividade | 25% | Volume sustenta o resultado da equipe |
| Pontualidade | 20% | Impacta o cliente diretamente |
| Conversão | 10% | Indicador de qualidade do anúncio |
| Qualidade | 10% | Complementa Conversão; dados esparsos |

**Total: 100%** — redistribuído automaticamente para eixos com dado (`scoreFinal()`).

---

## PublicadorScoreService — Assinatura e Retorno

### Localização

`app/Services/PublicadorScoreService.php` — namespace `App\Services`.

Injetado no `MlbController` via DI no construtor ou via `app()` dentro do método.

### Assinatura do método principal

```php
/**
 * Calcula score 0-100, classificação e detalhes dos 5 eixos para um publicador.
 *
 * @return array{
 *   score: float,
 *   classificacao: string,
 *   pontos_categoria: array<string, array{valor: float|null, peso: int}>,
 *   metricas: array{
 *     meta: array{feito: int, alvo: int, pct: float|null},
 *     produtividade: array{feito: int, meta: int, pct: float|null},
 *     pontualidade: array{total_skus: int, atrasados: int, pct_no_prazo: float|null},
 *     conversao: array{vendidos: int, feito: int, pct: float|null},
 *     qualidade: array{pct_sem_problema: float|null, pct_feedbacks_resolvidos: float|null},
 *   },
 * }
 */
public function compute(int $userId, string $mesRef): array
```

### Array de retorno completo para o controller

```php
// O controller passa isso para a página via Inertia
[
    'score'             => 72.5,          // float 0-100
    'classificacao'     => 'bom',         // 'excelente'|'bom'|'atencao'|'critico'
    'pontos_categoria'  => [              // para o radar (5 dimensões)
        'meta'          => ['valor' => 85.0, 'peso' => 35],
        'produtividade' => ['valor' => 70.2, 'peso' => 25],
        'pontualidade'  => ['valor' => 90.0, 'peso' => 20],
        'conversao'     => ['valor' => 45.0, 'peso' => 10],
        'qualidade'     => ['valor' => null, 'peso' => 10], // sem dado → redistribuído
    ],
    'metricas'          => [              // para os MiniMetrics cards
        'meta'          => ['feito' => 187, 'alvo' => 220, 'pct' => 85.0],
        'produtividade' => ['feito' => 187, 'meta' => 220, 'pct' => 85.0],
        'pontualidade'  => ['total_skus' => 42, 'atrasados' => 4, 'pct_no_prazo' => 90.5],
        'conversao'     => ['vendidos' => 98, 'feito' => 187, 'pct' => 52.4],
        'qualidade'     => ['pct_sem_problema' => 96.3, 'pct_feedbacks_resolvidos' => null],
    ],
]
```

---

## Props Expandidas para meuPainel()

### Props atuais (mantidas integralmente)

```php
// MlbController::meuPainel() — props já existentes (não remover)
'kpis'           => $kpis,            // calcularKpis() já existente
'evolucaoDiaria' => $evolucaoDiaria,  // COUNT publicações/dia
'topEmpresas'    => $topEmpresas,     // top 5 empresas
'feedbacks'      => $feedbacks,       // feedbacks pendentes
'meta'           => $meta,            // int
'mesRef'         => $mesRef,
'meses'          => $meses,
'problemas'      => [...],
'ticketEvolucao' => $ticketEvolucao,
'ticketAtual'    => $ticketAtual,
```

### Props novas a adicionar

```php
// Adicionadas ao array do Inertia::render()
'score_publicador'   => $scoreData,   // array completo do PublicadorScoreService
'faturamento_mes'    => $faturamentoMes,  // SUM(net_billing) do mês — float
'anuncios_feitos'    => $kpis['feito'],   // já em $kpis — reutilizar
'vendas_mes'         => $kpis['vendas'],  // já em $kpis — reutilizar
'net_billing_timeseries' => $netBillingTimeseries, // ver abaixo
```

### net_billing_timeseries — query proposta

```php
// Evolução ACUMULADA do faturamento por dia (espelha revenue_timeseries de Show.jsx)
// Fonte: mlb_publicacoes.net_billing (nullable — usar COALESCE)
$netBillingRows = Publicacao::where('user_id', $userId)
    ->whereBetween('data', [$primeiro, $ultimo])
    ->where('tipo', '!=', 'variacao')
    ->whereNotNull('net_billing')
    ->selectRaw('data, SUM(net_billing) as billing_dia')
    ->groupBy('data')
    ->orderBy('data')
    ->get();

// Acumular dia a dia (igual ao padrão de revenue_timeseries em Show.jsx)
$acumulado = 0;
$netBillingTimeseries = $netBillingRows->map(function ($r) use (&$acumulado) {
    $acumulado += (float) $r->billing_dia;
    return [
        'date'      => $r->data->format('Y-m-d'),  // YYYY-MM-DD para XAxis
        'realizado' => round($acumulado, 2),
    ];
})->values()->all();
```

**Landmine:** `net_billing` é nullable na tabela. `whereNotNull('net_billing')` exclui publicações sem sync de vendas — isso é correto; publicações sem `net_billing` não contribuem para faturamento. [VERIFIED: migration `2026_05_08_133410_add_preco_to_mlb_publicacoes.php` — coluna `decimal(10,2)->nullable()`]

---

## Componentes de Show.jsx a Reutilizar em MeuPainel.jsx

[VERIFIED: resources/js/Pages/Portfolio/Show.jsx — código-fonte lido integralmente]

### Componentes a copiar/adaptar

| Componente em Show.jsx | Uso em MeuPainel.jsx | Adaptação necessária |
|---|---|---|
| `KpiCard` (linhas 91-114) | KPIs: Faturamento · Anúncios Feitos · Vendas no Mês | Nenhuma — copiar igual |
| `PerformanceSection` (linhas 141-306) | Bloco principal do score | Renomear dimensões para os 5 eixos do publicador |
| `MiniMetric` (linhas 315-326) | Cards de detalhe abaixo do radar | Nenhuma — copiar igual |
| `ChartTooltip` (linhas 369-411) | Tooltip do gráfico de evolução | Adaptar para billing (sem `meta_acumulada` por ora) |
| `Chip` (linhas 118-130) | Chips no card principal | Nenhuma — copiar igual |
| `CLASSIF_LABEL`, `CLASSIF_CLS`, `CLASSIF_BG`, `SCORE_COLOR` | Labels e cores de classificação | Nenhuma — copiar igual |

### Dimensões do Radar em Show.jsx (linhas 148-155) — estrutura esperada

```jsx
// Show.jsx usa este formato para alimentar o RadarChart
const dimensoes = [
    { dim: 'Meta',          valor: pontosMeta,          bruto: metaPct,         sufixo: '%' },
    { dim: 'Produtividade', valor: pontosProdutividade, bruto: prodPct,         sufixo: '%' },
    { dim: 'Pontualidade',  valor: pontosPontualidade,  bruto: pontualPct,      sufixo: '%' },
    { dim: 'Conversão',     valor: pontosConversao,     bruto: conversaoPct,    sufixo: '%' },
    { dim: 'Qualidade',     valor: pontosQualidade,     bruto: qualidadePct,    sufixo: '%' },
];
// `valor` é 0-100 (para o eixo visual do radar)
// `bruto` é o valor real (para o tooltip — "X% (Y pts)")
// `sufixo` é o sufixo do bruto no tooltip
```

### RadialBarChart — dados esperados (Show.jsx:158-159)

```jsx
const radialData = [{ name: 'Score', value: data.score, fill: cor }];
// RadialBarChart com domain [0,100], startAngle=90, endAngle=-270
// Texto centralizado via absolute positioning
```

### LineChart de evolução (Show.jsx:720-771)

```jsx
// Espera revenue_timeseries com shape:
// [{ date: 'YYYY-MM-DD', realizado: 1234.56 }]
// (para o publicador, sem meta_acumulada na v1 — linha única verde)
```

---

## Não Reinventar

| Problema | Não Criar | Usar | Por quê |
|---|---|---|---|
| Redistribuição de pesos nulos | Lógica própria | `scoreFinal()` do PortfolioScoreService | Já testado, padrão do projeto |
| Classificação de score | Código de string próprio | `classificar()` do PortfolioScoreService | Mesmo mapeamento; consistência |
| Formatação de moeda | Função própria | `formatCurrencyCompact()` de `@/lib/utils` | Já implementada e testada |
| Classes condicionais | Template string manual | `cn()` de `@/lib/utils` | Padrão do projeto |
| RadialBar + Radar | D3 custom / SVG raw | `recharts` (já no projeto) | Mesmos componentes de Show.jsx |
| SKU atrasado — contagem | Query customizada na tela | Lógica de MlbController::dashboard() ~462-481 | Extrair para o Service |

---

## Armadilhas Comuns

### Armadilha 1: net_billing é nullable — não é SEMPRE preenchido

**O que dá errado:** `SUM(net_billing)` sem `COALESCE` ou `whereNotNull` pode retornar null quando NENHUM registro tem valor.
**Causa:** A coluna é optional (sync de vendas pode não ter rodado para o publicador no mês).
**Como evitar:** Usar `(float)($resultado?->billing ?? 0)` no PHP; no JS, verificar `if (faturamentoMes > 0)` antes de exibir.
**Sinal de alerta:** Faturamento mostrando "R$ 0,00" mesmo quando o publicador tem vendas → checar se `net_billing` foi sincronizado.

### Armadilha 2: responsavel_id ≠ user_id das publicações

**O que dá errado:** Para Pontualidade, buscar `mlb_empresas WHERE responsavel_id = $userId` pode retornar zero empresas se o publicador não for responsável de nenhuma. Para Produtividade/Conversão/Qualidade, a fonte é `mlb_publicacoes WHERE user_id = $userId`.
**Causa:** Um publicador pode fazer publicações em empresas das quais não é o responsável principal.
**Como evitar:** Manter a separação clara: Pontualidade usa `responsavel_id` em `mlb_empresas`; os outros 4 eixos usam `user_id` em `mlb_publicacoes`. Se Pontualidade retornar null (sem empresas responsáveis), o peso é redistribuído — comportamento correto.

### Armadilha 3: calcularKpis() já tem $feito e $vendas — não recalcular

**O que dá errado:** Fazer uma segunda query para `COUNT mlb_publicacoes` dentro do PublicadorScoreService quando o controller já tem o dado.
**Causa:** O controller chama `$kpis = $this->calcularKpis(...)` antes de instanciar o Service.
**Como evitar:** Passar `$feito`, `$vendas` e `$meta` como parâmetros do `compute()`, ou fazer o Service receber o array `$kpis` completo. Melhor: passar os valores brutos necessários.

### Armadilha 4: divisão por zero em `feito == 0`

**O que dá errado:** `$vendas / $feito` quando o publicador não publicou nada no mês.
**Causa:** Mês selecionado sem publicações.
**Como evitar:** Todos os eixos que dependem de `$feito` devem checar `$feito > 0` e retornar `null` — que será redistribuído pelo `scoreFinal()`.

### Armadilha 5: `data` no `net_billing_timeseries` — timezone

**O que dá errado:** `$r->data->format('Y-m-d')` pode retornar data diferente da esperada se o Eloquent usar UTC internamente.
**Causa:** A coluna `data` em `mlb_publicacoes` é `date` (não `datetime`) — o cast `'date'` do Eloquent usa Carbon. Para colunas `date` (sem hora), não há problema de timezone.
**Como evitar:** Usar o cast `'date'` do model (`Publicacao.$casts` já o define — VERIFIED linha 57) e chamar `->format('Y-m-d')`. No JS, o XAxis usa `tickFormatter={(d) => d.slice(8,10)+'/'+d.slice(5,7)}` (copiado de Show.jsx linha 725-727).

### Armadilha 6: `calcularKpis()` é um método privado do controller

**O que dá errado:** O `PublicadorScoreService` não pode chamar `calcularKpis()` diretamente.
**Causa:** O método é `private` no `MlbController`.
**Como evitar:** O controller chama `$kpis = $this->calcularKpis(...)` e passa os valores necessários (`feito`, `vendas`, `meta`) para o Service. NÃO duplicar a query.

### Armadilha 7: skus_estagio* podem ser null no banco

**O que dá errado:** `foreach ($e->skus_estagio1 ?? [] as $sku)` — se o cast retornar null, o `?? []` protege. Mas se o JSON for malformado, o cast do Eloquent pode lançar exception.
**Causa:** `MlbEmpresa.$casts` define `'skus_estagio1' => 'array'` — o Eloquent serializa/deserializa automaticamente. Valores null no banco ficam null em PHP.
**Como evitar:** Sempre usar `$e->{"skus_estagio{$stage}"} ?? []` (VERIFIED: padrão já usado em MlbController::dashboard() linha 462).

---

## Exemplos de Código

### Estrutura de MeuPainel.jsx com componentes reutilizados

```jsx
// Fonte: Show.jsx (linhas 91-326) — componentes copiados, não importados
// MeuPainel.jsx importa apenas do projeto — sem dependências novas

import { Award, Target, BarChart2, Clock, CheckCircle, AlertTriangle } from 'lucide-react';
import { cn, formatCurrencyCompact, formatPercent } from '@/lib/utils';
import {
    ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip,
    RadialBarChart, RadialBar, PolarAngleAxis,
    RadarChart, Radar, PolarGrid, PolarRadiusAxis,
} from 'recharts';

// Mesmos mapas de Show.jsx (VERIFIED: Show.jsx:53-72)
const CLASSIF_LABEL = { excelente: 'Excelente', bom: 'Bom', atencao: 'Atenção', critico: 'Crítico' };
const CLASSIF_CLS   = { excelente: 'text-emerald-300', bom: 'text-sky-300', atencao: 'text-amber-300', critico: 'text-red-300' };
const CLASSIF_BG    = { excelente: 'bg-emerald-500/10 border-emerald-500/30', ... };
const SCORE_COLOR   = { excelente: '#10b981', bom: '#3b82f6', atencao: '#f59e0b', critico: '#ef4444' };

export default function MeuPainel({
    kpis, evolucaoDiaria, topEmpresas, feedbacks, meta, mesRef, meses,
    problemas, ticketEvolucao, ticketAtual,
    // Props novas
    score_publicador,          // { score, classificacao, pontos_categoria, metricas }
    faturamento_mes,           // float
    net_billing_timeseries,    // [{ date, realizado }]
}) { ... }
```

### Montagem das dimensões do radar no JS

```jsx
// Extraindo pontos_categoria do score_publicador e montando o array de dimensões
// para o RadarChart (equivalente a Show.jsx:148-155)
const dimensoes = score_publicador
    ? [
        { dim: 'Meta',          valor: score_publicador.pontos_categoria.meta?.valor ?? 0,          bruto: score_publicador.metricas.meta?.pct,         sufixo: '%' },
        { dim: 'Produtividade', valor: score_publicador.pontos_categoria.produtividade?.valor ?? 0, bruto: score_publicador.metricas.produtividade?.pct, sufixo: '%' },
        { dim: 'Pontualidade',  valor: score_publicador.pontos_categoria.pontualidade?.valor ?? 0,  bruto: score_publicador.metricas.pontualidade?.pct_no_prazo, sufixo: '%' },
        { dim: 'Conversão',     valor: score_publicador.pontos_categoria.conversao?.valor ?? 0,     bruto: score_publicador.metricas.conversao?.pct,    sufixo: '%' },
        { dim: 'Qualidade',     valor: score_publicador.pontos_categoria.qualidade?.valor ?? 0,     bruto: score_publicador.metricas.qualidade?.pct_sem_problema, sufixo: '%' },
    ]
    : [];
```

---

## Estado Atual do MeuPainel.jsx

[VERIFIED: resources/js/Pages/Mlb/MeuPainel.jsx — lido integralmente]

A página atual exporta `MeuPainel({ kpis, evolucaoDiaria, topEmpresas, feedbacks, meta, mesRef, meses, problemas, ticketEvolucao, ticketAtual })` e renderiza:

- Seletor de mês (nativo `<select>`, não Radix)
- Grid de 6 KpiCards (Meta do Mês, Feito, Faltam, Anúncios Vendidos, Unidades Vendidas, Ritmo Necessário)
- Barra de progresso (% da meta)
- `ProblemasSection` (empresas + anúncios com problema)
- AreaChart de publicações diárias + Top 5 Empresas
- `TicketIndividualChart` (componente externo de `@/Components/TicketMedioChart`)
- Lista de feedbacks do líder

**Elementos a MANTER:** `ProblemasSection`, feedbacks, seletor de mês, `TicketIndividualChart`.
**Elementos a REFORMULAR:** header (renomear), KpiCards (trocar para Faturamento/Anúncios/Vendas), remover barra de progresso simples (substituída pelo RadialBarChart), adicionar `PerformanceSection`, substituir AreaChart por LineChart de net_billing.

---

## Auditoria de Pacotes

**Esta fase não instala nenhum pacote externo novo.** Todos os componentes (`recharts`, `lucide-react`, `cn`, Radix UI) já estão no projeto e verificados via package.json.

| Pacote | Registro | Disponível | slopcheck | Disposição |
|---|---|---|---|---|
| recharts | npm | já instalado (`^3.8.1`) | N/A | Aprovado — já em uso |
| lucide-react | npm | já instalado (`^1.11.0`) | N/A | Aprovado — já em uso |

**Pacotes removidos por [SLOP]:** nenhum.
**Pacotes suspeitos [SUS]:** nenhum.

---

## Inventário de Estado em Tempo de Execução

> Esta fase não é de renomeação/refatoração de strings — seção omitida conforme instrução.

---

## Disponibilidade do Ambiente

| Dependência | Necessária por | Disponível | Versão | Fallback |
|---|---|---|---|---|
| PHP 8.2+ | Service PHP | Sim | XAMPP local | — |
| Node.js | `npm run build` | Sim | v24.15.0 | — |
| MySQL/SQLite | mlb_publicacoes, mlb_empresas | Sim | XAMPP MySQL | — |
| `mlb_publicacoes.net_billing` | KPI Faturamento e timeseries | Sim (nullable) | migration `2026_05_08_133410` | Exibir "—" quando null |

**Dependências bloqueantes ausentes:** nenhuma.
**Dependências com fallback:** `net_billing` nullable — UI exibe "—" quando null (publicador sem sync de vendas).

---

## Validação Architecture

> Nyquist habilitado (`nyquist_validation: true` em `.planning/config.json`).

### Framework de Testes

| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Arquivo de config | `phpunit.xml` |
| Comando rápido | `php artisan test --filter=Phase38Publicador` |
| Suite completa | `php artisan test` |

### Mapeamento de Requisitos para Testes

| Req ID | Comportamento | Tipo | Comando automatizado | Arquivo existe? |
|---|---|---|---|---|
| PUB-01 | Score 0-100 calculado corretamente para publicador com dados completos | Unitário | `php artisan test --filter=PublicadorScoreServiceTest` | ❌ Wave 0 |
| PUB-02 | Eixo com `$feito=0` retorna `valor=null` e peso redistribuído | Unitário | `php artisan test --filter=PublicadorScoreServiceTest::test_eixo_null_redistribui_peso` | ❌ Wave 0 |
| PUB-03 | Pontualidade retorna `null` quando publicador sem empresas responsáveis | Unitário | `php artisan test --filter=PublicadorScoreServiceTest::test_pontualidade_sem_empresas` | ❌ Wave 0 |
| PUB-04 | `meuPainel()` passa `score_publicador`, `faturamento_mes`, `net_billing_timeseries` | Feature | `php artisan test --filter=MeuPainelControllerTest` | ❌ Wave 0 |
| PUB-05 | `meuPainel()` com publicador sem publicações no mês retorna props vazias válidas (sem exceção) | Feature | `php artisan test --filter=MeuPainelControllerTest::test_sem_publicacoes` | ❌ Wave 0 |
| PUB-06 | net_billing null em todas as publicações → faturamento_mes = 0 (sem divisão por zero) | Feature | `php artisan test --filter=MeuPainelControllerTest::test_net_billing_null` | ❌ Wave 0 |

### Taxa de Amostragem

- **Por commit de tarefa:** `php artisan test --filter=PublicadorScoreServiceTest`
- **Por merge de wave:** `php artisan test --filter=Phase38`
- **Gate da fase:** Suite completa verde antes de `/gsd:verify-work`

### Lacunas de Wave 0 (criar antes de implementar)

- [ ] `tests/Unit/PublicadorScoreServiceTest.php` — cobre PUB-01, PUB-02, PUB-03
- [ ] `tests/Feature/Phase38Publicador/MeuPainelControllerTest.php` — cobre PUB-04, PUB-05, PUB-06
- [ ] Factories sintéticas no teste: `Publicacao::factory()` com `vendido`, `problema`, `net_billing` e `mlb_empresa` com `skus_estagio*` e `responsavel_id`

**Padrão para testes sintéticos no projeto (VERIFIED: tests/Unit/CalcularFaixaTest.php — padrão existente):** usar factories Eloquent ou dados mockados em arrays simples. O `PublicadorScoreService` não injeta dependências externas (sem Adman, sem HTTP) — pode ser testado com factories de banco em memória (SQLite em memória via phpunit.xml).

---

## Segurança

> Fase puramente de UI/cálculo interno. Nenhuma rota nova, nenhum dado sensível, nenhum upload. A rota `mlb.meu_painel` já tem `checkPubAccess('meu_painel')` garantido pelo controller existente.

| Categoria ASVS | Aplica | Controle |
|---|---|---|
| V4 Controle de Acesso | Sim | `checkPubAccess('meu_painel')` já existente — não alterar |
| V5 Validação de Entrada | Sim | `$mesRef = $request->get('mes', now()->format('Y-m'))` — validar formato YYYY-MM |
| V2 Autenticação | N/A | Inertia + sessão Laravel — sem mudança |

**Ponto de atenção:** `$mesRef` vem do query string sem sanitização além do `now()->format('Y-m')` como fallback. Ao fazer `Carbon::createFromFormat('Y-m', $mesRef)`, um valor inválido lança exceção. Adicionar validação:

```php
// Antes de usar $mesRef no controller
if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) {
    $mesRef = now()->format('Y-m');
}
```

---

## Log de Hipóteses

| # | Afirmação | Seção | Risco se Errado |
|---|---|---|---|
| A1 | `publicadores()` do controller retorna apenas quem tem cargo `publicador` ou `lider-de-publicacao` — um publicador SEMPRE terá `responsavel_id` preenchido em pelo menos uma `mlb_empresa` | Pontualidade | Se publicador recém-cadastrado não for responsável de nenhuma empresa, Pontualidade = null (peso redistribuído — comportamento aceitável) |
| A2 | `net_billing` em `mlb_publicacoes` é preenchido pelo sync de vendas (VendasSyncService) — publicadores com sync configurado terão valores; outros terão null | Faturamento | Faturamento exibido como R$ 0 para publicadores sem sync — informar usuário na UI |

---

## Perguntas em Aberto

1. **Pesos dos eixos estão corretos?**
   - O que sabemos: Meta 35%, Produtividade 25%, Pontualidade 20%, Conversão 10%, Qualidade 10% foram derivados por analogia com o PortfolioScoreService e a relevância operacional de cada eixo.
   - O que está incerto: o usuário pode querer pesos diferentes (ex: Meta=40%, Produtividade=20%).
   - Recomendação: implementar com os pesos acima e disponibilizar como constantes no Service para fácil ajuste.

2. **Produtividade vs meta: o publicador quer ver seu volume comparado com a equipe?**
   - O que sabemos: a tela é individual; não há acesso à média dos pares nesta fase.
   - O que está incerto: se o usuário quiser ver "você fez X, a média da equipe foi Y" — isso exigiria uma query adicional.
   - Recomendação: v1 usa a meta como baseline; comparação com pares fica para v2.

3. **Manter `TicketIndividualChart` ou remover?**
   - O que sabemos: o componente existe em `@/Components/TicketMedioChart.jsx` e é passado via `ticketEvolucao` e `ticketAtual`.
   - O que está incerto: o CONTEXT.md não menciona explicitamente manter ou remover.
   - Recomendação: manter como seção colapsável abaixo do gráfico de faturamento, para não perder dados que o publicador já usa.

---

## Fontes

### Primárias (ALTA confiança — código-fonte verificado)

- `app/Services/PortfolioScoreService.php` — lógica de score, scoreFinal(), classificar(), normalizarCrescimento(), scoreQualidade()
- `resources/js/Pages/Portfolio/Show.jsx` — PerformanceSection, KpiCard, MiniMetric, Chip, ChartTooltip, RadialBarChart, RadarChart, LineChart, dimensoes[]
- `app/Http/Controllers/MlbController.php` — meuPainel(), calcularKpis(), metaParaMes(), metaCadastrada(), publicadores(), mesesDisponiveis()
- `app/Models/Publicacao.php` — fillable, casts, relações
- `app/Models/MlbEmpresa.php` — fillable, casts, normalizaSkus(), skus_estagio*, prazo_estagio*
- `resources/js/Pages/Mlb/MeuPainel.jsx` — layout atual, props recebidas, componentes existentes
- `resources/js/lib/utils.js` — cn(), formatCurrencyCompact(), formatPercent()
- `database/migrations/2026_05_08_133410_add_preco_to_mlb_publicacoes.php` — net_billing nullable
- `.planning/config.json` — nyquist_validation: true

### Secundárias (MÉDIA confiança)

- `tests/` — inventário de testes existentes para identificar padrões e namespace correto para novos testes
- `.planning/phases/38-*/CONTEXT.md` — decisões do usuário travadas

---

## Metadados

**Quebra de confiança:**
- Pilha padrão: ALTA — código-fonte verificado
- Arquitetura: ALTA — todos os arquivos-molde lidos
- Armadilhas: ALTA — identificadas diretamente no código
- Fórmulas de normalização: MÉDIA — propostas analíticas baseadas no padrão do PortfolioScoreService; pesos específicos são sugestão (A1)

**Data da pesquisa:** 2026-06-26
**Válido até:** 2026-07-26 (fase estável — código interno, sem dependências externas em mudança)
