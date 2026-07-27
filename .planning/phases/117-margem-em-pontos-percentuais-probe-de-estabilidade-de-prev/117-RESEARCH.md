# Phase 117: Margem em pontos percentuais + probe de estabilidade de `prev` - Research

**Pesquisado em:** 2026-07-27
**Domínio:** Contrato de métricas Adman (`AdmanMetricDiffService`) + comando artisan de probe de estabilidade contra API real
**Confiança:** ALTA (shape/cache/consumidores — leitura direta de código e testes) / MÉDIA (janela de concorrência real na VPS — depende de comportamento observado, não determinístico)

## Summary

Esta fase tem duas entregas independentes e ambas são de baixo risco técnico porque reaproveitam infraestrutura já existente e battle-tested: (1) ampliar aditivamente o shape de `AdmanMetricDiffService::compute()` com `prev_value` (3 métricas) e `diff_pp` (só `contribution_margin_pct`), bump de cache `v5`→`v6`, e espelhar `diff_pp=null` no `ShopeeMetricDiffService`; (2) criar um comando artisan novo (`adman:probe-margem-prev`) que lê `percentageMargin` real da Adman repetidamente ao longo de 24-48h, persiste cada leitura, e agrega para detectar "flip de nota" na régua de margem reusada.

A investigação confirmou a premissa central do CONTEXT (D-05): a Adman **já entrega `.prev`** nas três métricas relevantes (`grossBilling`/`billing`, `profitMargin`/`liquidMargin`, `percentageMargin`) — comprovado pela fixture real capturada em `tests/Feature/V18/AdmanMetricDiffServiceTest.php` (`respostaAccountMetrics()`/`respostaPerformance()`) e pelo próprio `AdmanService::fetchAccountMetricsDetailedCached()`, que já preserva `{value, diff, prev}` por campo desde a Fase 101. Nenhum dos dois shapes precisa de código novo para *obter* `.prev` — só para *expor* nos returns de `AdmanMetricDiffService`.

O ponto mais delicado da fase é o probe (item 9 das perguntas): `AdmanMetricDiffService::compute()` tem **cache diário próprio** (`adman:diff:v5/v6`, TTL até 1440min) por cima do cache diário do `AdmanService::fetchAccountMetricsDetailedCached()` (`adman:account_metrics_detailed:...`). Se o probe chamar `compute()` diretamente, a 2ª–5ª leitura do mesmo dia devolvem o **mesmo objeto cacheado**, e o gate inteiro vira teatro — exatamente o erro do incidente de 23/07 que o CONTEXT quer evitar repetir. A investigação confirma que existe um caminho limpo para ler fresco sem tocar no cache do resto do sistema: chamar `AdmanService::fetchAccountMetricsDetailedCached($custId, $start, $end, 1440, forceRefresh: true, $marketplace)` diretamente — o parâmetro `forceRefresh=true` **ignora a leitura do cache mas ainda sobrescreve o cache com o valor fresco** (linha 809-870 de `AdmanService.php`), então não há necessidade de `Cache::flush()` (que seria destrutivo em produção — apagaria sessões e outros caches do app).

**Recomendação primária:** implementar o Plan 117-01 (shape aditivo) e o Plan 117-02 (comando de probe) exatamente como fatiados pelo usuário. No probe, NUNCA chamar `AdmanMetricDiffService::compute()` para a leitura em si (ele cacheia); chamar `AdmanService::fetchAccountMetricsDetailedCached(..., forceRefresh: true)` direto, e persistir cada leitura numa tabela dedicada (não em stdout/arquivo), seguindo o padrão já estabelecido de `mlb_sync_vendas_logs` — porque a agregação (detecção de flip por empresa entre leituras) precisa ser uma query SQL, não um parser de log.

## User Constraints

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Critério de aprovação do gate**
- **D-01 · A métrica de aprovação é "zero flip de nota", não tolerância em pp.** O probe reprova se **qualquer empresa da amostra mudar de faixa da régua** entre duas leituras. Justificativa: variação de 0,03 pp longe de uma fronteira é irrelevante; 0,03 pp em cima da fronteira `+1` muda a nota de 3 para 4 e muda quanto se paga. Fronteiras da régua (em pp): `−5`, `−2`, `+1`, `+4`.
- **D-02 · Mínimo de 5 leituras espalhadas em 24-48h, com pelo menos uma proposital durante sync concorrente.** O modo de falha conhecido é rate-limit 429 por concorrência com `[MLB SyncTodasVendas]` / `[MLB SyncPub]` na mesma API-key — **não** aparece em chamadas seguidas na mesma janela. Cronograma alvo: madrugada (API ociosa), manhã durante o sync Adman, ~11:20 BRT (`adman:sync-margem`), tarde em pico, e uma repetição +24h. **Este desenho existe especificamente para não repetir o erro de 23/07**, quando "3 chamadas deram valores idênticos" concluiu *"o dado não flutua"* e 4 dias depois virou revert.
- **D-03 · Cobertura mínima de `prev` não-nulo: 80%.** Reusa `AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA = 0.8` (linha 70) em vez de inventar patamar novo. Abaixo disso o gate reprova, mesmo que os valores presentes sejam estáveis.
- **D-04 · População medida: carteiras do Luiz (user 3) e Danilo (user 15), competência fechada.** São as duas carteiras do incidente de 23/07, com cobertura local de margem já verificada (99,8% e 100%), e existe leitura histórica delas (`+6,83` / `−3,25` / `+8,63`) para comparar. Amostra enviesada só nas 5 empresas que oscilaram (LUCCAUTO, LYAMDECOR, GARCIA, Hunter, OESTE) foi **rejeitada**. Varrer todas as empresas Adman também foi rejeitada.

**Shape do contrato de métricas**
- **D-05 · `prev_value` nas três métricas** (`revenue`, `contribution_margin_value`, `contribution_margin_pct`). A Adman já entrega `.prev` em todas — custo zero de chamadas.
- **D-06 · `diff_pp` SÓ em `contribution_margin_pct`.** Pontos percentuais só existem para métrica que já é percentual.
- **D-07 · `diff_pp` é calculado apenas quando `comparison_mode === 'previous_equal_length_window'` e `value` e `prev_value` são ambos numéricos. Fora disso, `null`.**
- **D-08 · `quality` ganha indicador de cobertura de `diff_pp`, mas `status` NÃO muda.** Motivo estrutural: `quality.status` governa a política de TTL do cache em `compute()` (`partial` → 10 min, `complete` → 1440 min). Rebaixar para `partial` quando falta `diff_pp` faria empresa sem `prev` cair em TTL curto **permanentemente**.

### Claude's Discretion
- **D-09 · Comando artisan novo e dedicado para o probe** (nome sugerido `adman:probe-margem-prev`), em vez de estender `mlb:inspecionar-adman` ou `adman:warm-diff`.
- **D-10 · O probe PERSISTE cada leitura com timestamp antes de agregar.** A agregação (detecção de flip de nota, cobertura) roda depois, sobre as leituras gravadas. Formato exato fica para o planner, mas "cada leitura é um fato durável e re-agregável" é obrigatório. ⚠️ O resultado do gate deve ser conferido **por reconsulta ao dado persistido**, nunca por leitura de stdout — mesmo padrão que o gate `FIXMARG-03` já exige.
- **D-11 · O probe roda na VPS, contra a Adman real.**
- **D-12 · Se o gate REPROVAR, a fase ainda entrega o shape.** `prev_value`/`diff_pp` são aditivos e não quebram ninguém, então ficam. O que fica **bloqueado** é a Fase 119 consumir `diff_pp` para nota.

### Deferred Ideas (OUT OF SCOPE)
- Congelar `percentageMargin.prev` em snapshot diário próprio — fase própria, só se o probe reprovar.
- Reintroduzir cálculo local de margem a partir de `adman_metrics` — foi revertido em 24/07; só volta se o probe reprovar E o modo de falha indicar que a Adman é a fonte errada.
- Recalibrar a régua de margem para pp — travado em D2 da milestone v21.0.
- Expor `diff_pp`/`prev_value` na UI — Fase 123.
- Decidir o freeze de junho/2026 (prazo 31/07 14h BRT) — fora desta fase e desta milestone.
</user_constraints>

## Phase Requirements

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|---------------------|
| MPP-01 | `AdmanMetricDiffService` expõe `prev_value` e `diff_pp` no shape de cada métrica, preservando `value`, `diff_pct` e `diff_source` inalterados | Ver `## Standard Stack` / `## Architecture Patterns` — pontos exatos de `emptyMetrics()`, `resolveField()`, `resolveMargemPct()` mapeados com números de linha |
| MPP-02 | `contribution_margin_pct.diff_pp = value − prev_value`, só quando `comparison_mode === 'previous_equal_length_window'` e ambos numéricos; `null` fora disso | Ver `## Code Examples` — trecho exato a inserir em `resolveMargemPct()` |
| MPP-03 | Cache key `adman:diff:v5` → `v6` | Confirmado único ponto de definição da string (`AdmanMetricDiffService.php:122`) e ausência de teste que hardcode essa string — ver `## Common Pitfalls` |
| MPP-04 | Probe de estabilidade de `percentageMargin.prev` com relatório de variância apresentado ao usuário antes de qualquer fase amarrar pagamento | Ver `## Architecture Patterns` (comando de probe) e `## Validation Architecture` |
| MPP-05 | `ShopeeMetricDiffService` retorna `diff_pp = null`, sem quebrar placeholder 1.0 da Fase 109 | Ver `## Code Examples` — `margemNula()` já isolado, trivial de estender |
| MPP-06 | Fixture `value=27,47`/`prev=24,08` → `diff_pp=3,39`, `diff_pct` continua `14,09` | Fixture já existe em `AdmanMetricDiffServiceTest::respostaAccountMetrics()` — números confirmados |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Tier Primário | Tier Secundário | Racional |
|------------|---------------|------------------|----------|
| Shape de métricas com `prev_value`/`diff_pp` | API/Backend (`AdmanMetricDiffService`) | — | Serviço puro de domínio, sem UI nem persistência; consumidores (Carteira/Desempenho/Transparência) leem via `??`, então mudança é isolada nesta camada |
| `diff_pp = null` simétrico na Shopee | API/Backend (`ShopeeMetricDiffService`) | — | Mesmo contrato de retorno do Adman, mesma camada |
| Bump de cache `v5`→`v6` | API/Backend (chave de `Cache::` do Laravel, driver `database`) | — | Efeito é invisível para consumidores; só evita servir shape antigo |
| Comando de probe (`adman:probe-margem-prev`) | Console/Backend (Artisan Command) | Banco de Dados (persistência das leituras) | Roda via `php artisan` na VPS, chama `AdmanService` direto (bypassando o cache do domínio), grava fatos numa tabela nova para reconsulta |
| Agregação/relatório do probe (flip de nota, cobertura) | Console/Backend | Banco de Dados | Query SQL sobre a tabela de leituras — nunca parsing de stdout (D-10) |

## Standard Stack

Esta fase não introduz nenhuma biblioteca nova. Toda a infraestrutura necessária já existe no projeto:

| Componente | Já existe em | Uso nesta fase |
|------------|---------------|-----------------|
| `AdmanService::fetchAccountMetricsDetailedCached()` | `app/Services/AdmanService.php:805` | Já preserva `{value,diff,prev}` por campo — fonte de `prev_value` e leitura fresca do probe (`forceRefresh: true`) |
| `AdmanService::fetchPerformance()` | `app/Services/AdmanService.php:303` | Já preserva `{value,diff,prev}` em `summarizedData.grossBilling`/`profitMargin` — fonte de `prev_value` de `revenue` |
| `AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA` (0.8) | `app/Services/Metrics/AdmanMetricDiffService.php:70` | Reusado por D-03 (cobertura mínima do probe) |
| `Illuminate\Console\Command` | Laravel 12 | Base do comando `adman:probe-margem-prev` |
| `Illuminate\Support\Facades\Cache` | Laravel 12 | Já usado em `compute()`; probe NÃO deve usá-lo para a leitura (ver Pitfalls) |
| Migration + Eloquent Model | Laravel 12 | Persistência das leituras (D-10) — ver `## Architecture Patterns` |

**Nenhuma instalação de pacote é necessária nesta fase — a seção `Package Legitimacy Audit` não se aplica (nenhum pacote externo entra no `composer.json`/`package.json`).**

## Package Legitimacy Audit

Não aplicável — esta fase não instala pacotes externos. Toda a implementação usa classes já presentes no projeto (Laravel Console, Eloquent, Cache facade) e uma migration nova.

## Architecture Patterns

### Diagrama de fluxo — shape aditivo (Plan 117-01)

```text
Consumidor (DesempenhoScoreService / PortfolioController)
        │
        │  MetricDiffDispatcher::compute($company, $periodo, 'adman')
        ▼
AdmanMetricDiffService::compute()
        │
        ├─ cache adman:diff:v6:{marketplace}:{custId}:{start}:{end}:{dia}  ──► HIT: devolve shape já com prev_value/diff_pp
        │
        ├─ MISS → fetchPerformance()  ────────────► revenueAdman = {value,diff,prev}
        ├─ MISS → fetchAccountMetricsDetailedCached() ─► accountMetrics = {billing,liquidMargin,percentageMargin: {value,diff,prev}}
        │
        ├─ resolveField(revenueAdman, ...)                    → adiciona prev_value (sem diff_pp)
        ├─ resolveField(marginValueAdman, ...)                → adiciona prev_value (sem diff_pp)
        ├─ resolveMargemPct(marginPctAdman, isJanelaIgual)    → adiciona prev_value E diff_pp (gate D-07)
        │
        ▼
buildResult() → buildQuality() [+ indicador diff_pp_disponivel, D-08]
        │
        ▼
Cache::put(v6, ..., TTL por status) — shape novo nunca colide com v5 antigo
```

### Diagrama de fluxo — probe de estabilidade (Plan 117-02)

```text
php artisan adman:probe-margem-prev   (rodado 5×+ em 24-48h na VPS — D-11)
        │
        ├─ CarteiraContextService::forUser($luiz)   → vínculos → company_ids únicos
        ├─ CarteiraContextService::forUser($danilo) → vínculos → company_ids únicos
        │
        ├─ MetricPeriodResolver::resolve(['period_key' => '2026-06'])  (competência fechada FIXA — D-04)
        │
        ├─ para cada Company:
        │     AdmanService::fetchAccountMetricsDetailedCached(
        │         custId, current_start, current_end,
        │         1440, forceRefresh: TRUE, marketplace     ← NUNCA usar compute() aqui (cache mascara)
        │     )
        │     └─ percentageMargin = {value, diff, prev}
        │
        ├─ grava 1 linha em `adman_probe_margem_prev_leituras`
        │     (leitura_id, company_id, lida_em, value, prev, diff_nativo, nota_regua)
        │
        ▼
php artisan adman:probe-margem-prev --relatorio   (rodado 1× ao final)
        │
        ├─ SELECT * FROM adman_probe_margem_prev_leituras ORDER BY company_id, lida_em
        ├─ agrupa por company_id → detecta flip de nota_regua entre leituras consecutivas (D-01)
        ├─ calcula cobertura = leituras com prev não-nulo / total (D-03)
        ▼
Relatório console + linha persistida de veredito (aprovado/reprovado) — reconsultável (D-10)
```

### Recommended Project Structure

```
app/Services/Metrics/
├── AdmanMetricDiffService.php        # editado — shape aditivo (Plan 117-01)
└── ShopeeMetricDiffService.php       # editado — diff_pp=null (Plan 117-01)

app/Console/Commands/
└── ProbeMargemPrevStability.php      # novo — comando adman:probe-margem-prev (Plan 117-02)

app/Models/
└── AdmanProbeMargemPrevLeitura.php   # novo — model da tabela de leituras (Plan 117-02)

database/migrations/
└── 2026_07_XX_create_adman_probe_margem_prev_leituras_table.php  # novo (Plan 117-02)

tests/Feature/V18/
└── AdmanMetricDiffServiceTest.php    # editado — novos testes MPP-02/06 + fix do cenário (e) (Plan 117-01)

tests/Feature/
└── ShopeeMetricDiffServiceTest.php   # se existir, editado; senão criar cobrindo diff_pp=null (Plan 117-01)

tests/Feature/Phase117/
└── ProbeMargemPrevStabilityCommandTest.php  # novo — testa persistência/agregação com Http::fake (Plan 117-02)
```

### Pattern 1: Shape aditivo sem quebrar consumidores existentes

**O quê:** adicionar chaves novas a um array associativo já lido por `??` em todos os consumidores.
**Quando usar:** sempre que o `array_keys()` estrito não for verificado em produção (só em 1 teste, ver Pitfalls).
**Exemplo (baseado no código real de `resolveMargemPct()`, linha 272):**

```php
// Fonte: app/Services/Metrics/AdmanMetricDiffService.php:272-290 (estado atual)
private function resolveMargemPct(?array $marginPctAdman, bool $isJanelaIgual): array
{
    $value     = isset($marginPctAdman['value']) ? (float) $marginPctAdman['value'] : null;
    $prevValue = isset($marginPctAdman['prev'])  ? (float) $marginPctAdman['prev']  : null; // NOVO
    $adminDiff = $marginPctAdman['diff'] ?? null;

    // diff_pp (MPP-02/D-07): só em janela-igual, com value E prev numéricos.
    $diffPp = ($isJanelaIgual && $value !== null && $prevValue !== null)
        ? round($value - $prevValue, 2)
        : null;

    if ($isJanelaIgual && $adminDiff !== null) {
        return [
            'value'       => $value,
            'prev_value'  => $prevValue,  // NOVO
            'diff_pct'    => (float) $adminDiff,
            'diff_pp'     => $diffPp,     // NOVO
            'diff_source' => 'adman_diff',
        ];
    }

    return [
        'value'       => $value,
        'prev_value'  => $prevValue,  // NOVO — mesmo fora do gate de diff_pp, prev_value é exposto sempre que a Adman mandou (D-05)
        'diff_pct'    => null,
        'diff_pp'     => $diffPp,     // já null pelo gate acima
        'diff_source' => 'adman_indisponivel',
    ];
}
```

**Nota de design importante:** `prev_value` deve ser exposto **sempre que a Adman devolveu o campo**, independente do gate de `diff_pp` — D-05 não amarra `prev_value` ao `comparison_mode`, só `diff_pp` (D-07). Isso significa que em modo operacional (`same_interval_previous_month`), `resolveMargemPct()` ainda deve devolver `prev_value` não-nulo (quando a Adman mandou), mesmo que `diff_pp` seja `null`. O mesmo vale para `resolveField()` (revenue e margem R$): `prev_value` vem do parâmetro `$adman['prev'] ?? null` incondicionalmente.

### Pattern 2: `resolveField()` — mesma lógica para revenue e margem R$

```php
// Fonte: app/Services/Metrics/AdmanMetricDiffService.php:231-249 (estado atual)
private function resolveField(?array $adman, bool $isJanelaIgual, callable $fallback): array
{
    $value     = isset($adman['value']) ? (float) $adman['value'] : null;
    $prevValue = isset($adman['prev'])  ? (float) $adman['prev']  : null; // NOVO — sempre exposto
    $adminDiff = $adman['diff'] ?? null;

    if ($isJanelaIgual && $adminDiff !== null) {
        return [
            'value'       => $value,
            'prev_value'  => $prevValue, // NOVO
            'diff_pct'    => (float) $adminDiff,
            'diff_source' => 'adman_diff',
        ];
    }

    return [
        'value'       => $value,
        'prev_value'  => $prevValue, // NOVO
        'diff_pct'    => $fallback(),
        'diff_source' => 'calculated_fallback',
    ];
}
```

`revenue` e `contribution_margin_value` NÃO recebem `diff_pp` (D-06) — nem no shape vazio nem nos retornos calculados.

### Pattern 3: `emptyMetrics()` — shape vazio precisa das chaves novas

```php
// Fonte: app/Services/Metrics/AdmanMetricDiffService.php:550-555 (estado atual)
private function emptyMetrics(): array
{
    $vazioComDiffPp = ['value' => null, 'prev_value' => null, 'diff_pct' => null, 'diff_pp' => null, 'diff_source' => null];
    $vazioSemDiffPp = ['value' => null, 'prev_value' => null, 'diff_pct' => null, 'diff_source' => null];

    return [
        'revenue'                   => $vazioSemDiffPp,
        'contribution_margin_value' => $vazioSemDiffPp,
        'contribution_margin_pct'   => $vazioComDiffPp,
    ];
}
```

**Atenção:** isso muda `emptyMetrics()` de `array_fill_keys(self::METRIC_KEYS, $vazio)` (shape uniforme) para shape por-métrica — porque só `contribution_margin_pct` tem `diff_pp` (D-06). Confirmar que nada itera `METRIC_KEYS` esperando shapes idênticos entre métricas (não encontrado nenhum consumidor que faça isso — `buildQuality()` só lê `value`/`diff_pct`, presentes em ambos).

### Pattern 4: `buildQuality()` — indicador de cobertura de `diff_pp` (D-08)

```php
// Fonte: app/Services/Metrics/AdmanMetricDiffService.php:567-591 (estado atual, com adendo)
private function buildQuality(array $metrics): array
{
    $comValue = collect($metrics)->filter(fn ($m) => $m['value'] !== null)->count();
    $comDiff  = collect($metrics)->filter(fn ($m) => $m['diff_pct'] !== null)->count();

    $status = match (true) {
        $comValue === count(self::METRIC_KEYS) => 'complete',
        $comValue === 0 && $comDiff === 0      => 'missing',
        default                                => 'partial',
    };

    return [
        'status'            => $status,   // INALTERADO — D-08 exige que status não mude
        'source'            => 'adman',
        'computed_at'       => now()->toIso8601String(),
        'diff_pp_disponivel' => $metrics['contribution_margin_pct']['diff_pp'] !== null, // NOVO — indicador informativo, Fase 121 agrega isto
    ];
}
```

**Nomenclatura recomendada:** `diff_pp_disponivel` (boolean simples) em vez de uma fração de "cobertura", porque só existe UMA métrica que pode ter `diff_pp` por chamada — a "cobertura" real é uma métrica de portfólio (quantas empresas de N têm `diff_pp_disponivel=true`), que a Fase 121 calcula agregando este campo, não recalculando.

### Pattern 5: `ShopeeMetricDiffService` — `diff_pp=null` simétrico (MPP-05)

```php
// Fonte: app/Services/Metrics/ShopeeMetricDiffService.php:186-190 (estado atual)
private function margemNula(): array
{
    return ['value' => null, 'prev_value' => null, 'diff_pct' => null, 'diff_pp' => null, 'diff_source' => null];
}
```

`calcularRevenue()` (linha 102) e `calcularInvestimento()` (linha 124) já computam `$anterior` localmente — **recomendação de discrição do planner**: adicionar `'prev_value' => $anterior` nesses dois métodos por simetria de shape (custo zero, já calculado), mesmo que não seja estritamente exigido por MPP-05 (que só cobre `diff_pp`). Isso evita que consumidores futuros (Fase 121) precisem tratar Adman e Shopee como shapes diferentes. Se o planner preferir escopo mínimo, deixar `prev_value=null` no revenue/investment da Shopee também é aceitável — nenhum requirement exige o contrário.

### Pattern 6: Comando de probe — leitura fresca sem cache (o ponto crítico)

```php
// Padrão a seguir no novo ProbeMargemPrevStability::handle()
// NUNCA fazer isto (mede o cache, não a Adman):
//   app(AdmanMetricDiffService::class)->compute($company, $periodo);
//
// Fazer isto (bypassa os DOIS caches — o do diff service nem é chamado,
// e o forceRefresh=true bypassa o cache interno do AdmanService):
$admanService = app(AdmanService::class);
$detalhado = $admanService->fetchAccountMetricsDetailedCached(
    custId: $custId,
    dateFrom: $periodo['current_start'],
    dateTo: $periodo['current_end'],
    cacheMinutes: 1440,
    forceRefresh: true,       // ← bypassa a leitura do cache; ainda GRAVA o valor fresco no cache pros outros consumidores
    marketplace: $marketplace,
);
$percentageMargin = $detalhado['percentageMargin'] ?? null; // {value, diff, prev} ou null
```

**Por que isso não quebra nada:** `forceRefresh=true` só pula o `Cache::get()` (linha 809-814 de `AdmanService.php`); o `Cache::put()` no final (linha 870) roda incondicionalmente — então o probe efetivamente RE-AQUECE o cache compartilhado com o valor mais fresco, em vez de invalidá-lo (`Cache::flush()` seria destrutivo e desnecessário). Isso é o MESMO padrão que o teste `test_h_recompute_repetido_reflete_ao_vivo_oscilante_sem_mascarar` (cenário h) já valida — mas lá o teste usa `Cache::flush()` porque é ambiente de teste isolado; em produção, `forceRefresh=true` é o equivalente cirúrgico.

### Anti-Patterns to Avoid

- **Chamar `AdmanMetricDiffService::compute()` no loop de leitura do probe:** o cache de 1440min faz a 2ª-5ª chamada do mesmo dia devolver o mesmo objeto — o probe "provaria" estabilidade que é só efeito de cache, reproduzindo o erro de 23/07 que o CONTEXT explicitamente quer evitar.
- **Usar `Cache::flush()` na VPS para forçar leitura fresca:** apaga sessões de usuários logados e todos os outros caches do app (Adman, Desempenho, etc.) — desproporcional para o objetivo. Use `forceRefresh: true` no método específico.
- **Persistir o resultado do probe só em stdout/log:** viola D-10 e o padrão já estabelecido pelo gate `FIXMARG-03` (memória do projeto: "conferido por reconsulta ao snapshot, nunca por stdout"). Uma execução de 24-48h com 5+ leituras espalhadas não pode depender de copiar/colar saída de terminal.
- **Duplicar a régua de margem sem documentar a duplicação:** se o planner optar por duplicar `reguaMargem()` no comando do probe (recomendado, ver seção seguinte), documentar explicitamente como duplicação intencional temporária — igual ao padrão já usado em `AdmanMetricDiffService` (docblock da classe: "calculated_fallback — duplicação TEMPORÁRIA e INTENCIONAL").
- **Rodar o probe contra TODAS as empresas Adman:** rejeitado explicitamente em D-04 — o próprio volume do probe se tornaria fonte de rate-limit e contaminaria a medição.

## Régua de margem no probe (pergunta 6)

`DesempenhoScoreService::reguaMargem(?float $pct): ?float` (linha 1311) é **privada**, mas é uma função pura de 5 linhas (if/elseif em cascata, sem dependência de `$this`):

```php
// Fonte: app/Services/DesempenhoScoreService.php:1311-1319 (estado atual)
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

**Opções avaliadas:**

1. **Duplicar no comando do probe** (função privada idêntica, com docblock apontando a origem e a data). Prós: zero risco de regressão em `DesempenhoScoreService` (fora de escopo desta fase — D-12 não toca cálculo de nota); consistente com o padrão de duplicação documentada já usado no próprio `AdmanMetricDiffService` (guards de `computeVarMargem()`/`computeVarFaturamento()` foram copiados lá com o mesmo raciocínio). Contras: se a régua mudar um dia, há 2 (e em breve 3, com a Fase 119) lugares para atualizar.
2. **Tornar `reguaMargem()` `public static`** em `DesempenhoScoreService` e chamar `DesempenhoScoreService::reguaMargem($pp)` do comando. Prós: zero duplicação. Contras: expõe um método de domínio de negócio como API pública de uma classe que D-12 explicitamente diz que esta fase NÃO deve tocar ("Deliberadamente não embutir aqui... são fases próprias"); mudar a visibilidade de um método sem necessidade told é um efeito colateral fora do escopo declarado da fase.
3. **Extrair para um helper compartilhado** (ex.: `App\Services\Desempenho\ReguaMargem::pontos(?float $pp): ?float`) usado por `DesempenhoScoreService::reguaMargem()` (que passaria a delegar) e pelo probe. Prós: solução definitiva, e a Fase 119 (que "também vai precisar aplicar essa régua" per CONTEXT) já usaria o helper certo desde o início. Contras: toca `DesempenhoScoreService` nesta fase — fora do boundary declarado ("Esta fase entrega duas coisas, e nada além disso").

**Recomendação:** opção 1 (duplicar, documentado) para esta fase — respeita o boundary estrito do CONTEXT (`<domain>`: "NÃO está nesta fase... mudar a régua"). Adicionar uma nota explícita no todo/CONTEXT da Fase 119 (ou um TODO no código) para que a extração do helper compartilhado (opção 3) aconteça QUANDO a Fase 119 for planejada — ela já vai precisar tocar `DesempenhoScoreService` de qualquer forma para criar `CompanyScoreService`, então esse é o momento certo de eliminar a duplicação, não agora.

## Amostra do probe — resolução de carteiras (pergunta 7)

`CarteiraContextService::forUser(User $user, array $filters = []): Collection` (`app/Services/Portfolio/CarteiraContextService.php:106`) é o único caminho correto (contrato de uso documentado no docblock da classe: "consumidores devem SEMPRE usar `forUser()`, nunca fazer join direto"). Retorna uma `Collection` de arrays associativos:

```php
[
  'user_id' => int, 'company_id' => int, 'company_name' => string,
  'servico_id' => ?int, 'servico_nome' => ?string, 'setor' => string,
  'role' => string, 'role_label' => string,
  'has_financial_source' => bool, 'financial_source' => ?string,
  'financial_metrics_eligible' => bool,
]
```

Uso no probe:

```php
$luiz   = User::findOrFail(3);
$danilo = User::findOrFail(15);

$vinculosLuiz   = app(CarteiraContextService::class)->forUser($luiz)
    ->where('financial_metrics_eligible', true)
    ->where('financial_source', 'adman'); // só empresas Adman entram no probe de percentageMargin
$vinculosDanilo = app(CarteiraContextService::class)->forUser($danilo)
    ->where('financial_metrics_eligible', true)
    ->where('financial_source', 'adman');

$companyIds = $vinculosLuiz->pluck('company_id')
    ->concat($vinculosDanilo->pluck('company_id'))
    ->unique()
    ->values();

$companies = Company::whereIn('id', $companyIds)->get(['id', 'name', 'adman_account_id', 'ml_store_id', 'marketplace']);
```

Filtrar por `financial_source === 'adman'` é importante: `percentageMargin` só existe no caminho Adman (`ShopeeMetricDiffService` sempre devolve margem null), então incluir vínculos Shopee no probe adicionaria ruído sem sinal.

## Período — competência fechada (pergunta 8)

`MetricPeriodResolver::resolve(array $filtros): array` (`app/Services/Metrics/MetricPeriodResolver.php:87`) aceita `period_key` como `'last_closed_month'` OU uma string `'YYYY-MM'` explícita — ambos os modos produzem `mode='closed_period'`-equivalente com `comparison_mode='previous_equal_length_window'` (contrastando com `'current_month'`, que produz `same_interval_previous_month`).

**Recomendação: usar `'YYYY-MM'` explícito (ex.: `'2026-06'`), NÃO `'last_closed_month'`.** Motivo: o probe roda em 5+ leituras espalhadas por 24-48h — se a execução cruzar a virada de mês (ou se `last_closed_month` for reavaliado em momentos diferentes por qualquer motivo), `resolve(['period_key' => 'last_closed_month'])` pode apontar para competências DIFERENTES em leituras diferentes, invalidando a comparação "mesma empresa, mesma janela, valores diferentes" que é o objeto de estudo do probe. Fixar `period_key` explicitamente na primeira leitura (ex.: via `--mes=2026-06` do comando) e reusar o MESMO valor em todas as leituras da rodada.

```php
$periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => $this->option('mes') ?? '2026-06']);
// $periodo['comparison_mode'] === 'previous_equal_length_window'
// $periodo['current_start'] / $periodo['current_end'] fixos e reprodutíveis entre leituras
```

## Persistência das leituras do probe (pergunta 5 / D-10)

**Precedentes no código:**

1. **Arquivo em `storage/`** — `SugadoresMlReadDiagnostic.php` grava `storage/app/sugadores/ml-read-diagnostic/{id}-{ts}.json` via `Storage::disk('local')->put(...)`. Usado para diagnóstico ONE-SHOT (rodar 1× e ler o JSON manualmente).
2. **Tabela dedicada** — migration `2026_05_22_100001_create_mlb_sync_vendas_logs_table.php` cria `mlb_sync_vendas_logs`: uma linha por execução de job, com campos estruturados (`status`, contadores, `started_at`/`finished_at`, JSON de erros por item) e índice em `started_at` para "ordenação eficiente do histórico".

**Recomendação: tabela dedicada (opção 2), não arquivo JSON.** Razões específicas para este probe:
- A agregação exigida por D-01 ("detectar flip de nota entre QUALQUER par de leituras, por empresa") é uma operação de `GROUP BY company_id ORDER BY lida_em` — trivial em SQL, workaround manual em JSON solto por arquivo.
- D-10 exige reconsulta, não stdout — uma tabela é consultável via `php artisan tinker` na VPS a qualquer momento durante a janela de 24-48h, sem precisar re-parsear arquivos.
- O padrão `mlb_sync_vendas_logs` já é o precedente direto do projeto para "log de execuções estruturado, uma linha por evento, consultável depois" — reusar a mesma filosofia evita inventar uma terceira convenção de persistência de diagnóstico.

**Esboço de schema recomendado:**

```php
Schema::create('adman_probe_margem_prev_leituras', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->cascadeOnDelete();
    $table->string('periodo_key');           // ex.: '2026-06' — âncora de reprodutibilidade
    $table->timestamp('lida_em');            // momento real da leitura (não created_at)
    $table->string('janela_esperada')->nullable(); // 'madrugada'|'sync_adman'|'sync_margem'|'pico'|'repeticao_24h' (rótulo humano da D-02)
    $table->decimal('value', 10, 2)->nullable();      // percentageMargin.value
    $table->decimal('prev', 10, 2)->nullable();       // percentageMargin.prev
    $table->decimal('diff_nativo', 10, 2)->nullable();// percentageMargin.diff (contexto, não usado no cálculo pp)
    $table->decimal('margem_var_pp', 10, 2)->nullable(); // value - prev, calculado no momento da leitura
    $table->unsignedTinyInteger('nota_regua')->nullable(); // reguaMargem(margem_var_pp) aplicada no momento
    $table->boolean('http_falhou')->default(false);   // leitura não obteve percentageMargin (rate-limit/erro)
    $table->timestamps();

    $table->index(['company_id', 'lida_em']);
});
```

## Janela de sync concorrente (pergunta 4)

**Achado importante que muda a leitura de D-02:** `[MLB SyncTodasVendas]` (`SyncTodasVendasAdmanJob`, disparado em `MlbController.php:2268`) e `[MLB SyncPub]` (`MlbController.php:1087`) **NÃO são jobs agendados via cron** — são disparados manualmente por um analista clicando um botão na tela MLB. Isso foi confirmado por leitura de `routes/console.php` inteiro (nenhuma referência a esses comandos/jobs no `Schedule::`) e pelo debug resolvido `.planning/debug/margem-adman-diff-instavel.md`, que identifica esses dois processos como a origem real dos 30k+ ocorrências de rate-limit 429 no log — mas de forma NÃO determinística (dependem de quando um humano aciona o sync MLB).

**Consequência para o planner:** não é possível agendar uma leitura do probe "durante o sync MLB" de forma determinística via cron, porque o sync MLB não tem horário fixo. As janelas REALMENTE previsíveis e agendadas via `Schedule::` em `routes/console.php` são:

| Horário BRT | Comando agendado | Bate na mesma API-key Adman? |
|---|---|---|
| 03:00 | `sugadores:sync-adgroup-mlbs --all` | Sim (Adman MCP, descrito como "descongestionada" a essa hora) |
| 06:00 | `adman:warm-diff` | Sim |
| 08:00 | `ml:refresh-tokens` | Não (API ML, não Adman) |
| 11:00 | `adman:sync` | Sim — pico da cascata D-1 |
| 11:05 | `ml:sync` | Não (API ML) |
| 11:15 | `shopee:sync` | Não (API Shopee) |
| 11:20 | `adman:sync-margem` | Sim |
| 11:30 | `sync-faturamento-mensal` (`adman:sync-faturamento`) + `shopee:sync-ads` | Sim (a Adman) |
| 11:35 | `shopee:warm-diff` | Não |
| 11:40 | `adman:warm-diff` | Sim |
| 11:45 | `goals:calculate` | Não |
| 12:00 | `sugadores:analyze` | Sim (Adman MCP) |
| 12:45 | `RefreshGrossBillingCacheJob` | Sim |
| 13:00 | `SyncPolosFaturamentoJob` | Sim |
| a cada 8min, 7h-22h | `desempenho:warm-cache` | Sim (via `AdmanMetricDiffService::compute()`) |

**Recomendação para o cronograma de D-02:** usar a janela **11:00-12:00 BRT** como a "janela de pico de concorrência agendada" (sobreposição de `adman:sync`, `adman:sync-margem`, `sync-faturamento-mensal`, `sugadores:analyze` e o próprio `desempenho:warm-cache` rodando a cada 8min) em vez de tentar sincronizar com o sync MLB manual (imprevisível). Se o planner quiser garantir contenção REAL do MLB também, a opção mais determinística é pedir para um humano disparar manualmente `SyncTodasVendasAdmanJob`/`MlbController::syncPub` pouco antes de uma das leituras do probe — mas isso é uma ação humana fora do próprio comando, não algo que o comando de probe possa agendar sozinho. Documentar essa limitação explicitamente no relatório do probe (rótulo de janela `sync_mlb_manual_pendente` vs `sync_adman_agendado`).

## Testes que já cobrem o comportamento atual (não podem regredir)

`tests/Feature/V18/AdmanMetricDiffServiceTest.php` — **um teste específico VAI QUEBRAR e precisa ser atualizado, não é regressão inesperada**:

```php
// Linha 369 — cenário (e), test_e_shape_e_quality_completos()
foreach ($resultado['metrics'] as $metric) {
    $this->assertSame(['value', 'diff_pct', 'diff_source'], array_keys($metric));
}
```

Este assert `array_keys()` estrito vai falhar assim que `prev_value` (e `diff_pp` em `contribution_margin_pct`) forem adicionados — é o ÚNICO lugar em todo o código-fonte (produção + testes) que faz esse tipo de asserção estrita sobre o shape (confirmado por grep — nenhum controller/service faz `array_keys()` estrito sobre `metrics.*`). Este teste PRECISA ser atualizado como parte do Plan 117-01 para refletir o novo shape (chaves diferentes por métrica: `contribution_margin_pct` ganha `diff_pp`, as outras duas não).

Todos os outros 15 cenários (a, b, c, d, f, g, h, i, j, k, l, m, n, o) usam asserts pontuais via `assertSame`/`assertNull` em chaves específicas (`['diff_source']`, `['diff_pct']`, `['value']`) — nenhum deles quebra com chaves adicionadas.

`tests/Feature/V18/AdmanMetricDiffBackfillTest.php` — não usa `array_keys()` estrito (confirmado por grep); deve permanecer verde sem alteração.

## Common Pitfalls

### Pitfall 1: Probe medindo o cache em vez da Adman
**O que dá errado:** chamar `AdmanMetricDiffService::compute()` no loop do probe faz a 2ª-5ª leitura do dia devolver o mesmo objeto cacheado (TTL até 1440min quando `status='complete'`).
**Por que acontece:** `compute()` tem cache por dia BRT — decisão arquitetural correta para os consumidores normais (Carteira/Desempenho), mas incompatível com o objetivo do probe (medir variação intra-dia).
**Como evitar:** chamar `AdmanService::fetchAccountMetricsDetailedCached(..., forceRefresh: true)` diretamente — ver Pattern 6.
**Sinais de alerta:** se todas as 5 leituras do dia derem valores IDÊNTICOS bit-a-bit, isso é MUITO mais provável de ser cache do que estabilidade real — desconfiar e verificar se o `forceRefresh` foi mesmo propagado.

### Pitfall 2: `ERROR_SENTINEL` interferindo em leituras do probe
**O que dá errado:** se `fetchAccountMetricsDetailedCached()` falhar (429 sustentado por 4 tentativas), ele grava `ERROR_SENTINEL` no cache por 10 minutos — uma leitura do probe que aconteça dentro dessa janela de 10min (mesmo com `forceRefresh: true` na LEITURA) ainda vai *falhar* a chamada (o método sempre tenta a Adman quando `forceRefresh=true`, mas se a Adman estiver realmente indisponível, o retorno é `null` de qualquer forma).
**Por que acontece:** `forceRefresh` só ignora o cache na LEITURA — não muda o comportamento de falha/retry do método.
**Como evitar:** o probe deve tratar `null`/falha como uma leitura válida e persistir `http_falhou=true` (não descartar silenciosamente) — o próprio padrão de falha é dado relevante para o relatório (D-02 já antecipa que rate-limit é o modo de falha esperado).

### Pitfall 3: Memo por request não afeta o probe (mas documentar por quê)
**O que dá errado (hipótese a descartar):** poderia se pensar que o `private array $memo` de `AdmanMetricDiffService` mascararia leituras repetidas.
**Por que NÃO é um problema aqui:** o memo é uma property de INSTÂNCIA (não estático, não persistido), e cada leitura do probe roda como uma invocação separada de `php artisan` (processo novo do PHP-CLI) — a instância do serviço é recriada do zero a cada execução. Além disso, o probe recomendado nem instancia `AdmanMetricDiffService` (usa `AdmanService` direto). Documentar esse raciocínio no PLAN para não gerar dúvida futura.

### Pitfall 4: Cache key bump `v5`→`v6` esquecendo o comentário versionado
**O que dá errado:** o arquivo já tem um histórico de comentários numerados (`v2`, `v3`, `v4`, `v5`) documentando POR QUE cada bump aconteceu (linhas 114-121) — pular esse padrão ao subir para `v6` quebra a convenção de rastreabilidade do próprio arquivo.
**Como evitar:** adicionar o comentário `// v6 (2026-07-27): shape ganha prev_value/diff_pp (Fase 117, MPP-01/02/03) — bump invalida entradas com shape antigo.` seguindo o padrão exato das linhas anteriores.

### Pitfall 5: `emptyMetrics()` deixar de ser uniforme quebra suposição implícita
**O que dá errado:** hoje `emptyMetrics()` usa `array_fill_keys(self::METRIC_KEYS, $vazio)` — um único `$vazio` para as 3 métricas. Adicionar `diff_pp` só em `contribution_margin_pct` (D-06) força esse método a deixar de ser uniforme.
**Como evitar:** ver Pattern 3 — construir o array explicitamente por chave, sem `array_fill_keys`. Testar explicitamente que `emptyMetrics()`/`buildResult()` com `$custId` vazio devolve `contribution_margin_pct` com `diff_pp: null` E `revenue`/`contribution_margin_value` SEM a chave `diff_pp` (ou com ela ausente — decidir e testar explicitamente qual das duas: chave ausente vs chave presente com `null`. Recomendação: chave AUSENTE nas duas métricas sem `diff_pp`, para não sugerir que `diff_pp` é um conceito válido ali).

### Pitfall 6: Rate-limit do próprio probe contaminando a medição
**O que dá errado:** o probe faz N chamadas HTTP síncronas por leitura (uma por empresa das 2 carteiras) — se N for grande (Luiz tem ~25-26 empresas elegíveis por carteira), 5 execuções ao longo de 24-48h significam ~130 chamadas extras à Adman, no MESMO horário que outros jobs agendados (se o planner seguir a recomendação de rodar durante 11:00-12:00 BRT).
**Como evitar:** aceitar que ALGUMA fração de `http_falhou=true` é esperada e faz parte do que está sendo medido (D-02 already antecipa rate-limit como o modo de falha); não é preciso adicionar `sleep()`/throttle artificial entre empresas dentro de UMA leitura (o volume de ~25 chamadas é o mesmo que `WarmAdmanDiffCache`/`desempenho:warm-cache` já fazem rotineiramente sem throttle dedicado), mas o comando deve capturar e persistir falhas por empresa sem abortar a leitura inteira (fail-open por empresa, como o resto do sistema já faz).

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|--------|-------------------|
| A1 | `prev_value` deve ser exposto mesmo fora do gate de `comparison_mode` (só `diff_pp` é gateado) | Pattern 1 | Se o planner decidir que `prev_value` também deveria ser `null` fora de `previous_equal_length_window`, o shape muda; risco baixo porque D-05 do CONTEXT já afirma "a Adman já entrega .prev em todas — custo zero de chamadas" sem mencionar gate, e D-07 amarra o gate explicitamente só a `diff_pp` |
| A2 | Nome do campo de indicador de cobertura de D-08 (`diff_pp_disponivel`) | Pattern 4 | Puramente nominal — qualquer nome funciona desde que documentado; sem risco funcional, só de consistência textual com a Fase 121 |
| A3 | Nome/schema da tabela de persistência do probe (`adman_probe_margem_prev_leituras`) | Persistência das leituras | Nome específico é sugestão; estrutura (1 linha por leitura×empresa, indexada por company_id+lida_em) é o que importa para D-10 |
| A4 | Recomendação de janela 11:00-12:00 BRT como proxy de concorrência (já que sync MLB não é agendado) | Janela de sync concorrente | Se o usuário quiser garantir contenção real do MLB, precisa disparar manualmente o sync MLB durante uma das leituras — isso é ação humana, não algo o research pode forçar |

**Nenhuma claim de shape do payload Adman (`.prev` presente e populado) é `[ASSUMED]`** — todas foram `[VERIFIED: leitura direta do código-fonte + fixture de teste real]` (ver Sources).

## Open Questions

1. **`prev_value` deve ser arredondado com quantas casas decimais?**
   - O que sabemos: `value`/`diff_pct` não são arredondados no `resolveField()`/`resolveMargemPct()` atuais (só no `fallbackSomaSimples`/`diffPctGuardado`, que arredondam para 2 casas). `diff_pp` (novo) deveria seguir o mesmo padrão de `diffPctGuardado` (2 casas) para bater com o exemplo âncora `27.47 - 24.08 = 3.39` (exatamente 2 casas).
   - O que é incerto: se `prev_value` em si deve ser arredondado ou passado cru (a Adman já manda com 2 casas na prática, mas isso não é uma garantia de contrato).
   - Recomendação: `prev_value` cru (igual a `value`, sem round adicional); `diff_pp` com `round(..., 2)` explícito para bater com o caso âncora MPP-06.

2. **O relatório final do probe (D-10/D-01) deve ser outro comando (`--relatorio`) ou uma flag do mesmo comando?**
   - O que sabemos: D-09 já decidiu que é UM comando novo dedicado; não há decisão sobre se leitura e agregação são a mesma invocação ou duas.
   - O que é incerto: se rodar `adman:probe-margem-prev` sem argumentos faz uma leitura nova (idempotente, 5×+ ao longo do tempo) ou sempre agrega tudo que já existe.
   - Recomendação: uma flag (`--relatorio` ou `--agregar`) no MESMO comando, para não multiplicar arquivos de comando por uma responsabilidade estreitamente relacionada — mas isso é decisão de granularidade do planner, sem impacto nos requirements.

## Environment Availability

| Dependência | Necessária para | Disponível | Versão | Fallback |
|---|---|---|---|---|
| Conexão com a API Adman real (produção) | Probe (D-11) | Depende do ambiente — só é acessível a partir da VPS de produção com `ADMAN_API_KEY` configurada | — | Nenhum — o probe É INÚTIL rodando localmente/fixture (D-11 é explícito: "medir estabilidade contra fixture ou ambiente local não significa nada") |
| `php artisan` na VPS (Hostinger) | Rodar o comando ao longo de 24-48h | Presumivelmente sim (deploy já documentado em CLAUDE.md) | PHP 8.2+ | — |
| Cron/scheduler para rodar leituras automaticamente | Espalhar as 5+ leituras sem intervenção manual | Não confirmado nesta pesquisa se o planner vai querer um `Schedule::command()` temporário ou execução manual repetida | — | Execução manual via `php artisan adman:probe-margem-prev` disparada por humano em cada janela-alvo (mais simples de reverter depois que o probe terminar) |

**Dependências faltantes sem fallback:** nenhuma — o único requisito real (acesso à Adman de produção) já existe no ambiente de produção via `ADMAN_API_KEY`.

**Recomendação:** NÃO adicionar uma entrada permanente em `routes/console.php` para o probe — ele é um experimento de 24-48h com prazo definido (D-02), não um comando operacional contínuo. Rodar manualmente (ou com um `Schedule::command(...)->between(...)` TEMPORÁRIO removido depois) evita poluir o scheduler de produção com um comando de vida útil curta.

## Validation Architecture

### Test Framework
| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` |
| Comando rápido | `php artisan test --filter=AdmanMetricDiffServiceTest` |
| Suíte completa relevante | `php artisan test --filter=AdmanMetricDiffServiceTest \|\| php artisan test --filter=AdmanMetricDiffBackfillTest \|\| php artisan test --filter=ShopeeMetricDiffServiceTest \|\| php artisan test --filter=ProbeMargemPrevStability` |

### Phase Requirements → Test Map
| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|---|---|---|---|---|
| MPP-01 | `prev_value` presente nas 3 métricas, `value`/`diff_pct`/`diff_source` inalterados | unit/feature | `php artisan test --filter=test_e_shape_e_quality_completos` (atualizado) | ✅ (editar) |
| MPP-02 | `diff_pp` calculado só em janela-igual com ambos numéricos | feature | `php artisan test --filter=AdmanMetricDiffServiceTest` (novo cenário) | ❌ Wave 0 — adicionar `test_p_diff_pp_calculado_apenas_em_janela_igual_com_ambos_numericos` |
| MPP-03 | Cache `v5`→`v6`, shape velho não servido | feature | `php artisan test --filter=AdmanMetricDiffServiceTest` (novo cenário com cache pré-populado na chave v5) | ❌ Wave 0 — adicionar `test_q_cache_v6_nao_reaproveita_shape_v5` |
| MPP-04 | Probe persiste leituras e agrega flip de nota | feature | `php artisan test --filter=ProbeMargemPrevStabilityCommandTest` | ❌ Wave 0 — comando+model+migration+teste inteiros novos |
| MPP-05 | `ShopeeMetricDiffService::margemNula()` devolve `diff_pp: null` | unit/feature | `php artisan test --filter=ShopeeMetricDiffServiceTest` | ❌ Wave 0 — confirmar se arquivo já existe (não encontrado nesta pesquisa; criar se ausente) |
| MPP-06 | `value=27.47`/`prev=24.08` → `diff_pp=3.39`; `diff_pct` continua `14.09` | feature | `php artisan test --filter=test_r_fixture_ancora_diff_pp_3_39` | ❌ Wave 0 — adicionar cenário usando a MESMA fixture já existente (`respostaAccountMetrics()`) |

### Sampling Rate
- **Por commit de task:** `php artisan test --filter=AdmanMetricDiffServiceTest` (rápido, sem HTTP real — usa `Http::fake()`)
- **Por merge de wave:** suíte completa do domínio (`AdmanMetricDiffServiceTest` + `AdmanMetricDiffBackfillTest` + `ShopeeMetricDiffServiceTest` + `ProbeMargemPrevStabilityCommandTest`)
- **Gate de fase:** suíte completa verde ANTES de `/gsd:verify-work` — mas o **gate humano de MPP-04 é distinto e não-automatizável**: só é "passado" quando o relatório do probe (rodando 24-48h contra a Adman real na VPS) for apresentado ao usuário e ele decidir aprovar/reprovar. Testes automatizados de MPP-04 cobrem a MECÂNICA do comando (persistência, agregação, detecção de flip com dados fake via `Http::fake()`), NUNCA o veredito de estabilidade real — isso é um julgamento humano sobre dados de produção, fora do escopo de CI.

### Sinal de aprovação/reprovação do gate do probe (MPP-04)
- **Passa (observável):** query na tabela de leituras mostra, para TODAS as empresas da amostra (Luiz+Danilo, `financial_source=adman`), (a) cobertura de `prev` não-nulo ≥ 80% das leituras (D-03) E (b) `nota_regua` idêntica em TODAS as leituras da mesma empresa (D-01, "zero flip") — incluindo pelo menos uma leitura na janela 11:00-12:00 BRT (proxy de concorrência).
- **Falha (observável):** qualquer empresa com `nota_regua` diferente entre 2 leituras quaisquer (mesmo se as duas notas forem "razoáveis" isoladamente) OU cobertura de `prev` não-nulo < 80%.
- **Sinal ambíguo a evitar:** se TODAS as leituras derem valores idênticos bit-a-bit (não só a mesma nota da régua, mas o mesmo float), isso é suspeito de estar medindo cache (Pitfall 1) — o planner deve incluir uma verificação de sanidade no relatório final que sinaliza esse padrão como "possível problema de instrumentação", não como "estabilidade perfeita".

## Security Domain

Esta fase não expõe nenhum endpoint HTTP novo, não recebe input de usuário via formulário/API pública, e não lida com dados de autenticação/sessão. O único "input" é o próprio comando artisan, executado por um admin autenticado na VPS via SSH (D-11).

### Applicable ASVS Categories

| Categoria ASVS | Aplica | Controle padrão |
|---|---|---|
| V2 Autenticação | Não | Comando roda via CLI/SSH, fora do fluxo HTTP autenticado da aplicação |
| V3 Gestão de sessão | Não | N/A — sem sessão HTTP envolvida |
| V4 Controle de acesso | Parcial | Acesso ao comando é controlado por quem tem SSH na VPS (fora do escopo de código desta fase) — nenhum controle novo de código necessário |
| V5 Validação de entrada | Sim | Validar `--mes=YYYY-MM` do comando com regex antes de repassar ao `MetricPeriodResolver` (que já lança `InvalidArgumentException` para `period_key` malformado — reaproveitar, não reimplementar) |
| V6 Criptografia | Não | Nenhum dado sensível novo persistido — `percentageMargin`/`prev`/notas são dados financeiros agregados já visíveis em telas internas, não segredos |

### Known Threat Patterns

| Padrão | STRIDE | Mitigação padrão |
|---|---|---|
| Rate-limit/DoS acidental contra a API Adman (o próprio probe martelando) | Denial of Service (não intencional) | Fail-open por empresa (não abortar a leitura inteira), amostra pequena e fixa (D-04 já rejeita varrer todas as empresas) |
| Log/persistência de dados financeiros sensíveis (margem por empresa) em tabela nova | Information Disclosure | Tabela de leituras do probe é acessível só via DB/tinker (mesmo nível de proteção do resto do banco); nenhum dado é exposto via rota HTTP nesta fase |

## Code Examples

### Caso âncora obrigatório (MPP-06) — usando a fixture JÁ EXISTENTE

```php
// A adicionar em tests/Feature/V18/AdmanMetricDiffServiceTest.php
public function test_r_fixture_ancora_diff_pp_3_39_nao_deriva_de_diff_pct(): void
{
    $this->fakeAdmanEndpoints(); // percentageMargin: value=27.47, diff=14.09, prev=24.08 (fixture já existente)
    $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

    $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

    $margem = $resultado['metrics']['contribution_margin_pct'];
    $this->assertSame(27.47, $margem['value']);
    $this->assertSame(24.08, $margem['prev_value']);
    $this->assertSame(3.39, $margem['diff_pp']);       // 27.47 - 24.08, NÃO deriva de diff_pct
    $this->assertSame(14.09, $margem['diff_pct']);     // inalterado — continua sendo a variação relativa nativa
}
```

### Caso de gate negativo (D-07) — `diff_pp` null fora de janela-igual

```php
public function test_s_diff_pp_null_em_modo_operacional_mesmo_com_prev_presente(): void
{
    $this->fakeAdmanEndpoints(); // prev=24.08 presente no payload
    $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

    $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoOperacional());

    // diff_pp é null (gate D-07), mas prev_value AINDA é exposto (D-05 não amarra prev_value ao gate).
    $this->assertNull($resultado['metrics']['contribution_margin_pct']['diff_pp']);
    $this->assertSame(24.08, $resultado['metrics']['contribution_margin_pct']['prev_value']);
}
```

## State of the Art

| Abordagem antiga | Abordagem atual (esta fase) | Quando mudou | Impacto |
|---|---|---|---|
| Margem do bônus usa `percentageMargin.diff` (variação relativa) lida pela régua `reguaMargem()` | Margem do bônus (Fase 119, fora do escopo desta fase) vai usar `value - prev` (pontos percentuais) | Hotfix `a413e823` (2026-07-24) fixou "SEMPRE `.diff` nativo"; esta fase (117) REABRE essa decisão deliberadamente para expor `diff_pp`, mas NÃO consome ainda (D-12) | `diff_pct` continua existindo intocado para consumidores legados; `diff_pp` fica disponível mas inerte até a Fase 119 |
| Cache do diff Adman só protegia consumidores normais de recomputar toda leitura | Cache do diff Adman agora também é um obstáculo conhecido para instrumentação de estabilidade (o probe precisa bypassá-lo deliberadamente) | Esta fase (117) | Documentar isso é importante para qualquer fase futura de diagnóstico similar — não é specific só a esta fase |

**Nada fica obsoleto/deprecado nesta fase** — é uma extensão aditiva pura.

## Sources

### Primary (ALTA confiança — leitura direta de código-fonte do projeto)
- `app/Services/Metrics/AdmanMetricDiffService.php` (601 linhas, lido integralmente) — shape atual, cache, gates, guards
- `app/Services/Metrics/ShopeeMetricDiffService.php` (233 linhas, lido integralmente) — shape espelhado, `margemNula()`
- `app/Services/AdmanService.php` (trechos relevantes: `fetchPerformance` linha 303, `fetchAccountMetricsDetailedCached` linha 805, `fetchGrossBilling` linha 379) — confirmação de `.prev` já preservado
- `tests/Feature/V18/AdmanMetricDiffServiceTest.php` (696 linhas, lido integralmente) — fixture real com `.prev` populado nas 3 métricas, 15 cenários existentes mapeados
- `app/Services/Metrics/MetricPeriodResolver.php` (trecho lido) — contrato de `resolve()`, `comparison_mode` por `period_key`
- `app/Services/Portfolio/CarteiraContextService.php` (180 linhas lidas) — contrato de `forUser()`
- `app/Services/DesempenhoScoreService.php` (trechos: `reguaMargem` linha 1311, `margemPontos` linha 1348, `computeNotaFinal` linha 1258) — régua de margem atual
- `app/Console/Commands/WarmAdmanDiffCache.php` (íntegro) — modelo estrutural de comando que itera empresas × período
- `app/Console/Commands/SugadoresMlReadDiagnostic.php` (íntegro) — precedente de comando de diagnóstico com persistência
- `database/migrations/2026_05_22_100001_create_mlb_sync_vendas_logs_table.php` — precedente de tabela dedicada para log de execução
- `routes/console.php` (íntegro, 283 linhas) — todos os horários de cron confirmados
- `.planning/debug/margem-adman-diff-instavel.md` (root cause já investigado e RESOLVIDO) — origem real do rate-limit (jobs manuais MLB, não cron)
- `plano-implementacao-desempenho-por-empresa.md` §2.5, §4 "Fase 1" (lido integralmente) — plano canônico da milestone
- `.planning/REQUIREMENTS-v21.md` (lido integralmente) — MPP-01..06, decisões D1-D5
- `.planning/phases/117-.../117-CONTEXT.md` (lido integralmente) — 12 decisões travadas

### Secondary (MÉDIA confiança)
- Nenhuma fonte externa (web/Context7) foi necessária — esta fase é 100% interna ao codebase existente, sem biblioteca nova.

### Tertiary (BAIXA confiança)
- Nenhuma.

## Project Constraints (from CLAUDE.md)

- Comentários de código em pt-BR, incluindo docblocks explicando decisões e origem (padrão forte já usado em `AdmanMetricDiffService`).
- Nomenclatura: Services `PascalCase`+`Service`, Commands `PascalCase` verbo-substantivo (ex.: `ProbeMargemPrevStability` para `adman:probe-margem-prev`), migrations `YYYY_MM_DD_HHMMSS_verbo_substantivo`.
- Error handling: capturar `\Throwable` (não `\Exception`) em loops batch; jobs/commands logam com tag entre colchetes (`[AdmanProbe]` ou similar) — seguir o padrão `[Adman]`/`[AdmanMetricDiff]`/`[AdmanDiffWarm]` já usado no domínio.
- Nenhuma mudança de stack — Laravel 12 + Eloquent puro, sem pacote novo.
- Deploy não deve ser executado sem autorização explícita do usuário (irrelevante para o research, relevante para quando o planner/executor chegar em produção).
- GSD: todos os artefatos desta fase (PLAN.md, commits) em pt-BR.

## Metadata

**Confidence breakdown:**
- Standard stack: ALTA — nenhuma lib nova, tudo já existe e foi lido linha a linha
- Architecture (shape aditivo): ALTA — leitura direta do arquivo-alvo completo + teste que já prova `.prev` presente na fixture real
- Architecture (comando de probe): ALTA para a mecânica (cache bypass, persistência); MÉDIA para o resultado do gate em si (depende de comportamento real da Adman sob concorrência, que é justamente o que o probe vai medir — não é algo que a pesquisa possa prever)
- Pitfalls: ALTA — pitfall 1 (cache mascarando o probe) é derivado diretamente do código-fonte de `AdmanService`/`AdmanMetricDiffService`, não de especulação

**Data da pesquisa:** 2026-07-27
**Válida até:** 2026-08-26 (30 dias — domínio estável, mas monitorar se `AdmanService`/`AdmanMetricDiffService` sofrerem novo hotfix antes do planner consumir esta pesquisa, dado o histórico recente de mudanças frequentes nesses arquivos)
