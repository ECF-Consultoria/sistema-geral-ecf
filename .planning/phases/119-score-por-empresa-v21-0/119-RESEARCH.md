# Phase 119: Score por empresa - Research

**Pesquisado em:** 2026-07-28
**Domínio:** Refatoração aditiva de motor de bônus (Laravel/PHP) — granularidade por empresa
**Confiança:** HIGH (código lido linha a linha; nenhuma lib externa nova; nenhuma chamada de rede real necessária)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01 · A linha reporta DOIS números, não um.** `nota_empresa` (estrita — `null` se faltar qualquer um dos 3 componentes) **e** `nota_empresa_parcial` (média dos componentes presentes), mais `componentes_presentes` (int), `status` e `quality.motivos`. Razão: a Fase 119 é aditiva e informativa; quem decide política de denominador é a Fase 120. Reportar só a estrita jogaria fora informação que a Fase 121 precisa; reportar só a parcial misturaria silenciosamente empresa completa com incompleta.
- **D-02 · Empresa Shopee conta como `complete`**, com `quality.margin_source = 'placeholder_shopee'`. Razão: a Fase 109 travou que profissional só-Shopee não pode cair em `blocked`/`partial` por ausência de margem — marcar a linha como parcial contradiria a trava.
- **D-03 · Empresa sem fonte financeira permanece listada** em `empresas_score`, com `nota_empresa = null`, `nota_empresa_parcial` = a própria nota de NPS, `componentes_presentes = 1`, `status = 'sem_fonte'` e `quality.motivos = ['sem_fonte_financeira']`. Razão: auditabilidade que a Fase 121 vai precisar.

### Claude's Discretion

- **D-04 · O blend `margemPontos()` da Fase 109 fica INTOCADO nesta fase.** `DesempenhoScoreService::margemPontos()` (linha ~1348) continua sendo o caminho vivo enquanto a flag da Fase 120 estiver desligada. O caminho novo simplesmente não o usa — no modelo por empresa a ponderação emerge naturalmente da média das notas.
- **D-05 · Assinatura e local do `CompanyScoreService`** — o planner decide, coerente com os vizinhos (`app/Services/Desempenho/` já hospeda o `NpsPorEmpresaService` da Fase 118).

### Bloqueio de execução (não é "deferred", é gate)

**GATE MPP-04** — o probe de estabilidade de `percentageMargin.prev` ainda não tem veredito aprovado (apenas 1 de ≥5 leituras registradas em 2026-07-28). **Planejar é permitido, executar não.** Se o veredito vier `reprovado`/`instrumentacao_suspeita`, esta fase muda de forma.

### Deferred Ideas (OUT OF SCOPE)

- Política de denominador (empresa incompleta entra ou sai da média do profissional) — Fase 120.
- Aposentar `margemPontos()` — Fase 120, quando a flag existir.
- Reescrever os invariantes de `DesempenhoShopeeScoreTest` para o modo flag-ligada — Fase 120.
- Persistir a linha por empresa — Fase 122.
- Exibir a lista de empresas com nota — Fase 123.

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| EMPS-01 | `CompanyScoreService` produz uma linha por empresa com o contrato do plano §3.1 | Ver `## Contrato da linha por empresa` e `## Código: shape de retorno` |
| EMPS-02 | Régua de faturamento aplicada **por empresa**, antes de qualquer média | Ver `## Resposta 8` (caso âncora régua-da-média × média-das-réguas) e `## Código` |
| EMPS-03 | Régua de margem sobre `margem_var_pp`, nunca sobre `diff_pct` | Ver `## Resposta 3` — caminho exato de `diff_pp`, fixture MPP-06 |
| EMPS-04 | `nota_empresa = round((nps+fat+margem)/3, 2)` | Ver `## Resposta 5` e caso âncora D-01/D-02 do CONTEXT |
| EMPS-05 | `MetricDiffDispatcher::compute()` chamado 1× por empresa | Ver `## Resposta 1` — contrato de extração única |
| EMPS-06 | Fonte financeira vencedora (Adman > Shopee) preservada; Shopee = placeholder | Ver `## Resposta 4` |
| EMPS-07 | `status` e `quality` auditáveis | Ver `## Resposta 6` — taxonomia final |

</phase_requirements>

## Summary

Esta fase cria um serviço PHP **novo e aditivo** (`app/Services/Desempenho/CompanyScoreService.php`, seguindo o precedente do `NpsPorEmpresaService` da Fase 118) que produz uma **linha de fato por `(user_id, company_id)`** com os três componentes de bônus já pontuados pela régua e a `nota_empresa` calculada. `DesempenhoScoreService` não é tocado — nenhum número de produção muda.

O trabalho de engenharia real está em **traduzir corretamente 3 coisas que hoje são agregadas para o nível de empresa**: (1) a chamada ao `MetricDiffDispatcher`, hoje duplicada estruturalmente entre `computeVarFaturamento()`/`computeVarMargem()`; (2) a leitura de margem, que passa de `diff_pct` (variação relativa) para `diff_pp` (pontos percentuais, campo novo da Fase 117, só existe quando `comparison_mode==='previous_equal_length_window'` e `value`/`prev` são ambos numéricos); e (3) a resolução da fonte financeira vencedora, hoje calculada sobre um universo **já filtrado** (`financial_metrics_eligible=true`), mas que a Fase 119 precisa aplicar sobre o universo **completo** (`CarteiraContextService::forUser()`, todos os setores) para poder marcar `sem_fonte` (D-03) em vez de simplesmente excluir a empresa.

A descoberta mais importante da pesquisa: **os guards de margem "cicatrizados" (dias-comuns, cobertura mínima, `diffPctGuardado`) já vivem dentro de `AdmanMetricDiffService`, que já opera por empresa.** `DesempenhoScoreService::computeVarMargem()` não contém guard nenhum — é um loop fino que só faz bookkeeping agregado (`n_com_margem_real`, `n_elegivel`, `$vars->avg()`). O único guard que é **genuinamente agregado** e que a Fase 119 **não deve tentar portar** é a média (`$vars->avg()`) e o blend ponderado por contagem de `margemPontos()` — isso é exatamente o que a milestone existe para substituir. Uma segunda descoberta relevante: a constante `AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA` (0.8) está **órfã** no caminho de produção — o hotfix `a413e823` de 24/07 removeu a chamada ao fallback de margem local, e hoje só é lida via Reflection pelo comando de diagnóstico `ProbeMargemPrevStability`. Não existe guard de "cobertura mínima" ativo gateando `diff_pct`/`diff_pp` de margem hoje.

**Recomendação principal:** criar `CompanyScoreService::computeEmpresasScore(User $user, array $periodo, bool $mesFechado, Collection $invalidadas): Collection` como método autocontido, injetando `CarteiraContextService`, `MetricDiffDispatcher`, `NpsPorEmpresaService`; **receber `$periodo` já resolvido** (não resolver internamente) para garantir que a janela seja byte-idêntica à que `DesempenhoScoreService::compute()` já resolveu — isso é o que vai permitir à Fase 121 comparar old×new sem ruído de janela divergente.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Cálculo de nota por empresa (`CompanyScoreService`) | API/Backend (Service layer) | — | Lógica de negócio pura, sem I/O de UI; injeta serviços de domínio existentes |
| Universo de empresas por profissional | API/Backend (`CarteiraContextService`) | — | Já existe, reusado sem modificação |
| Diff financeiro por empresa (Adman/Shopee) | API/Backend (`MetricDiffDispatcher` → `AdmanMetricDiffService`/`ShopeeMetricDiffService`) | Fonte externa (Adman HTTP) | Já existe; Fase 119 consome, não modifica |
| Nota de NPS por empresa | API/Backend (`NpsPorEmpresaService`, Fase 118) | Database (`nps_score_assignments`, `nps_surveys`) | Já existe; Fase 119 consome, não reimplementa |
| Persistência da linha por empresa | Database | — | **Fora de escopo** — Fase 122 (`desempenho_company_score_snapshots`) |
| Exibição da lista de empresas com nota | Frontend Server (Inertia/React) | — | **Fora de escopo** — Fase 123 |

Nenhuma capacidade desta fase toca Browser/Client ou CDN — é 100% backend, sem rota HTTP nova, sem página nova.

## Standard Stack

Nenhuma biblioteca externa nova é necessária. A fase usa exclusivamente:

| Componente | Já existe? | Uso nesta fase |
|---|---|---|
| Laravel 12 / Eloquent Collections | Sim | `Collection::groupBy`/`avg`/`reject` — mesmo idioma do `NpsPorEmpresaService` |
| `App\Services\Metrics\MetricDiffDispatcher` | Sim (Fase 109/117) | Consumido, não modificado |
| `App\Services\Portfolio\CarteiraContextService` | Sim (Fase 88/109) | Consumido, não modificado |
| `App\Services\Desempenho\NpsPorEmpresaService` | Sim (Fase 118) | Consumido, não modificado |
| PHPUnit 11.5 (`php artisan test`) | Sim | Testes novos em `tests/Feature/Phase119/` |

**Instalação:** nenhuma (`composer install`/`npm install` não são necessários para este trabalho).

## Package Legitimacy Audit

**N/A — esta fase não instala nenhum pacote externo (composer ou npm).** Todo o trabalho é composição de código PHP interno sobre serviços já existentes no repositório. A tabela de auditoria de pacotes é omitida por não haver instalação a auditar.

## Architecture Patterns

### Diagrama de fluxo — `CompanyScoreService::computeEmpresasScore()`

```text
User + $mes + $periodo (já resolvido por quem chama)
        │
        ▼
CarteiraContextService::forUser($user, ['active'=>true])
        │  (TODOS os setores: performance, shopee, polos, publicacao, outros —
        │   várias linhas por empresa quando há >1 serviço/vínculo, Pitfall conhecido)
        ▼
BonusInvalidacao::companyIdsInvalidadas($mes) ──▶ reject() ANTES de qualquer outra coisa
        │
        ▼
companiesUniverso = vinculos->pluck('company_id')->unique()   ◀── identidade da empresa
        │
        ├──▶ Resolver fonte financeira vencedora por empresa
        │     (groupBy company_id, filter financial_metrics_eligible=true,
        │      'adman' vence se presente, senão 'shopee', senão null=sem_fonte)
        │
        ├──▶ NpsPorEmpresaService::notasNpsPorEmpresa($user, $mes, $mesFechado, $invalidadas)
        │     (UMA chamada só, cobre TODAS as empresas do universo)
        │
        └──▶ para cada empresa com fonte_financeira != null:
              MetricDiffDispatcher::compute($company, $periodo, $fonte)  ◀── UMA chamada por empresa (EMPS-05)
                    │
                    ├─▶ metrics.revenue.diff_pct              → faturamento_var_pct → reguaFaturamento() → faturamento_pontos
                    └─▶ metrics.contribution_margin_pct.diff_pp → margem_var_pp      → reguaMargem()      → margem_pontos
        │
        ▼
Monta 1 linha por company_id (mesmo empresa sem fonte, D-03) com:
  nota_empresa (estrita, D-01) | nota_empresa_parcial | componentes_presentes | status | quality
```

### Contrato da linha por empresa (EMPS-01, §3.1 do plano canônico + D-01/D-02/D-03 do CONTEXT)

```php
[
    'company_id'            => 123,
    'company_name'          => 'Empresa Gol',
    'fonte_financeira'      => 'adman', // 'adman' | 'shopee' | null (D-03)

    'nps_pontos'            => 4.6,      // vem PRONTO de NpsPorEmpresaService::notasNpsPorEmpresa()->nota

    'faturamento_atual'     => 100000.00,
    'faturamento_anterior'  => 92592.59,
    'faturamento_var_pct'   => 8.0,      // metrics.revenue.diff_pct
    'faturamento_pontos'    => 5.0,      // reguaFaturamento(faturamento_var_pct)

    'margem_pct_atual'      => 18.2,     // metrics.contribution_margin_pct.value
    'margem_pct_anterior'   => 15.0,     // metrics.contribution_margin_pct.prev_value
    'margem_var_pp'         => 3.2,      // metrics.contribution_margin_pct.diff_pp — NUNCA diff_pct
    'margem_pontos'         => 4.0,      // reguaMargem(margem_var_pp) | 1.0 fixo se Shopee (D-02) | null se Adman sem diff_pp

    'componentes_presentes' => 3,        // count(não-null entre nps/faturamento/margem pontos)
    'nota_empresa'          => 4.53,     // D-01: null se componentes_presentes < 3
    'nota_empresa_parcial'  => 4.53,     // D-01: média dos presentes (sempre calculável se >=1 presente)

    'status'                => 'complete', // ver taxonomia — Resposta 6
    'quality'               => [
        'revenue_diff_source' => 'adman_diff',       // metrics.revenue.diff_source, repassado
        'margin_diff_source'  => 'adman_diff',       // metrics.contribution_margin_pct.diff_source
        'margin_source'       => null,               // 'placeholder_shopee' quando D-02 se aplica
        'motivos'             => [],                 // ex.: ['sem_fonte_financeira'], ['margem_pp_indisponivel']
    ],
]
```

### Pattern: uma linha SEMPRE existe por empresa do universo (mesmo sem fonte)

**O que:** diferente de `DesempenhoScoreService::computeUniverso()` (que só monta `companies_elegiveis` = empresas com `financial_metrics_eligible=true`), o universo do `CompanyScoreService` é o **universo completo** de `forUser()` — inclui empresas de setores sem fonte financeira nenhuma (`polos`/`publicacao`/`outros`).
**Quando usar:** sempre, é o contrato da D-03.
**Por quê:** sem isso, não dá pra distinguir "empresa sem fonte" de "empresa fora da carteira" — exatamente a auditabilidade que a Fase 121 precisa (ver `## Resposta 7`).

### Anti-Patterns a evitar

- **Portar `n_com_margem_real`/`n_elegivel`/`nShopeePlaceholder`/o blend ponderado de `margemPontos()` para o código novo.** Esses existem só para resolver um problema de matemática agregada (ponderar uma média por contagem) que **deixa de existir** quando cada empresa já chega com seu próprio ponto régua'd. Portar essa lógica reintroduziria a "régua-da-média" que a milestone existe para aposentar.
- **Resolver `$periodo` dentro do `CompanyScoreService`** (chamando `MetricPeriodResolver` de novo) em vez de recebê-lo já resolvido de quem chama. Isso arrisca uma janela ligeiramente diferente da que `DesempenhoScoreService::compute()` já resolveu, o que quebraria silenciosamente a comparação da Fase 121 (duas notas diferentes por causa da janela, não da fórmula).
- **Chamar `NpsPorEmpresaService` por empresa** (`foreach ($companies as $c) { ...notasNpsPorEmpresa(...) }`). O método já retorna a Collection inteira por `company_id` numa única chamada — chamá-lo em loop refaria a query custosa de NPS N vezes.
- **Aplicar `reguaMargem()` sobre `diff_pct`.** É exatamente o hotfix que a Fase 117/119 reabre deliberadamente na direção oposta (D1/D2 da milestone) — usar `diff_pct` por engano aqui reintroduziria a métrica errada silenciosamente, sem quebrar nenhum teste que só olhe o shape.

## Don't Hand-Roll

| Problema | Não construir | Usar em vez disso | Por quê |
|---|---|---|---|
| Universo de empresas do profissional | Query direta em `company_users` | `CarteiraContextService::forUser()` | Contrato explícito no docblock: join direto ignora o ramo legado `servico_id NULL` |
| Resolução de nota de NPS por empresa | Reimplementar os 3 ramos (atribuição/legado/imputada) | `NpsPorEmpresaService::notasNpsPorEmpresa()` | Fase 118 já entrega isso pronto, com D-01..D-04 resolvidos e testado (24/24 verdes) |
| Diff financeiro por empresa | Chamar `AdmanService`/`AdmanMetricDiffService` diretamente | `MetricDiffDispatcher::compute($company, $periodo, $source)` | Roteia Adman×Shopee com a MESMA regra de desempate da carteira; chamar o service de baixo nível direto perderia o roteamento |
| Cálculo de `diff_pp` | Recalcular `value - prev` manualmente a partir de `metrics.contribution_margin_pct.value`/`.prev_value` | Ler `metrics.contribution_margin_pct.diff_pp` já pronto | O gate `comparison_mode==='previous_equal_length_window'` já está aplicado dentro de `resolveMargemPct()` — recalcular por fora ignoraria esse gate |

**Insight chave:** esta fase é, estruturalmente, um trabalho de **composição**, não de implementação de regra de negócio nova. As 3 fontes de dado (universo, financeiro, NPS) já existem, já são testadas, e já resolvem exatamente os problemas difíceis (dedupe, janela M+1, fonte vencedora, guards de dias-comuns). O único código genuinamente novo é a régua **aplicada por empresa** e a montagem do contrato/status/quality.

## Respostas às Perguntas de Pesquisa

### Resposta 1 — EMPS-05, a chamada única do dispatcher

Hoje (`app/Services/DesempenhoScoreService.php`):

```php
// computeVarFaturamento() — linha 1130
$diffPct = $this->diffDispatcher->compute($company, $periodo, $source)['metrics']['revenue']['diff_pct'] ?? null;

// computeVarMargem() — linha 1205
$resultado = $this->diffDispatcher->compute($company, $periodo, $source);
$diffPct   = $resultado['metrics']['contribution_margin_pct']['diff_pct'] ?? null;
```

Cada método extrai **um único campo** de um shape que já contém tudo. O contrato de uma chamada única, por empresa:

```php
$resultado = $this->diffDispatcher->compute($company, $periodo, $fonte);

$faturamentoVarPct = $resultado['metrics']['revenue']['diff_pct'] ?? null;
$margemPctAtual    = $resultado['metrics']['contribution_margin_pct']['value'] ?? null;
$margemPctAnterior = $resultado['metrics']['contribution_margin_pct']['prev_value'] ?? null;
$margemVarPp       = $resultado['metrics']['contribution_margin_pct']['diff_pp'] ?? null;  // EMPS-03 — nunca diff_pct
$revenueDiffSource = $resultado['metrics']['revenue']['diff_source'] ?? null;
$margemDiffSource  = $resultado['metrics']['contribution_margin_pct']['diff_source'] ?? null;
```

**Efeitos colaterais de chamar 1× em vez de 2×: nenhum observável.** `AdmanMetricDiffService::compute()` (linhas 108-227) já cacheia por `$cacheKey` (`Cache::get`/`Cache::put`) e mantém um **memo em memória de instância** (`private array $memo`) cujo docblock (linhas 72-83) diz explicitamente: esse memo existe **porque** `DesempenhoScoreService` chama `compute()` duas vezes por empresa na mesma passada, e sem ele a 2ª chamada poderia ler um `ERROR_SENTINEL` gravado pela 1ª (quando o HTTP falhou 100%) e descartar um `diff_pct` bom vindo do `calculated_fallback`. **Chamando 1× por empresa, essa proteção deixa de ser necessária** para o `CompanyScoreService` (não há 2ª leitura pra proteger) — mas não é preciso mexer no memo existente, porque ele só ativa quando há de fato uma releitura da mesma cacheKey na mesma instância.

**Nuance para a Fase 121 (não bloqueia a 119):** `AdmanMetricDiffService` não é bindado como singleton no container — `DesempenhoScoreService` e um futuro `CompanyScoreService` resolvido separadamente terão **instâncias diferentes**, cada uma com seu próprio `$memo`. Isso é inofensivo: o `Cache::get()/put()` (Redis/database, compartilhado entre instâncias) já garante que a 2ª instância nunca refaz o HTTP — na pior hipótese lê um `ERROR_SENTINEL` de curta duração (10 min) gravado pela outra instância e trata como "sem dado" (fail-open), o que é o comportamento correto, não um bug. Vale documentar isso no plano da Fase 121/122 para não reabrir a investigação.

### Resposta 2 — Guards de `computeVarMargem()` (a pergunta mais importante)

**Descoberta central: `DesempenhoScoreService::computeVarMargem()` (linhas 1186-1222) NÃO contém nenhum guard de dias-comuns/cobertura/`diffPctGuardado`.** É um loop fino:

```php
private function computeVarMargem(User $user, Carbon $mes, EloquentCollection $companies, array $periodo, Collection $fontes): array
{
    $nElegivel = $companies->filter(fn ($c) => ($fontes[$c->id] ?? 'adman') !== 'shopee')->count();
    // ...
    foreach ($companies as $company) {
        $source    = $fontes[$company->id] ?? 'adman';
        $resultado = $this->diffDispatcher->compute($company, $periodo, $source);
        $diffPct   = $resultado['metrics']['contribution_margin_pct']['diff_pct'] ?? null;
        if ($diffPct !== null) { $vars->push($diffPct); $nComMargemReal++; }
    }
    // ...
    return ['pct' => round($vars->avg(), 2), 'n_com_margem_real' => $nComMargemReal, 'n_elegivel' => $nElegivel];
}
```

Os guards **cicatrizados de verdade** vivem dentro de `AdmanMetricDiffService`, e **já operam por empresa** (recebem `Company $company` como parâmetro, uma de cada vez):

| Guard | Onde vive | Já é por empresa? | Ainda se aplica a margem/`diff_pp`? |
|---|---|---|---|
| `margem_dias` (um lado sem NENHUM dia com dado ⇒ não computa) | `AdmanMetricDiffService::offsetsComunsComLinha()` | **Sim, já é per-company** — recebe `Company $company` | **Não.** Só é usado pelo `calculated_fallback` de `revenue` (`fallbackSomaSimples`). `resolveMargemPct()` nunca chama fallback — ver linha abaixo |
| Interseção de dias-comuns | `AdmanMetricDiffService::somasComGuards()` | **Sim, já é per-company** | **Não**, mesma razão acima |
| `diffPctGuardado` (baseline ≤ 0 ⇒ `null`, nunca -100% artificial) | `AdmanMetricDiffService::diffPctGuardado()` | **Sim, já é per-company** | **Não para diff_pp.** `resolveMargemPct()` calcula `diff_pp = round(value - prev, 2)` — subtração direta de percentuais, sem divisão, **sem risco de baseline zero/negativo por construção**. Não precisa (nem deve) reusar `diffPctGuardado` para `diff_pp` |
| `MARGEM_COBERTURA_MINIMA` (0.8) — "preferir fallback local se cobertura < 80%" | `AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA` + `coberturaMargem()` | Per-company | **NÃO ESTÁ ATIVO.** O hotfix `a413e823` (2026-07-24) removeu a chamada a `fallbackMargemPct()` de dentro de `resolveMargemPct()` — confirmado por grep: `fallbackMargemPct()` **não tem nenhum call-site em produção**, só existe como método morto. A constante só é lida hoje via Reflection por `ProbeMargemPrevStability` (comando de diagnóstico da Fase 117), não pelo caminho de cálculo |

**O que É genuinamente agregado e NÃO deve ser portado para o nível de empresa:**

1. **`$vars->avg()`** — a própria média da % bruta ANTES da régua. É literalmente a "régua-da-média" que a milestone existe para substituir por "média-das-réguas" (D3). No modelo novo, cada empresa aplica `reguaMargem($margemVarPp)` **primeiro**; a média (se houver, na Fase 120) vem depois, sobre pontos já régua'd.
2. **`n_com_margem_real`/`n_elegivel`** — bookkeeping que hoje alimenta exclusivamente `margem_amostra.cobertura` (gate `FIXMARG-03` de `ConsolidarMesDesempenho`) e o denominador do blend Shopee em `margemPontos()`. No modelo por empresa, essa informação sai "de graça" contando `status`/`margem_pontos !== null` em `empresas_score` — a Fase 122 (SNAP-05) fica responsável por recalcular `margem_amostra` a partir disso, não a Fase 119.
3. **O blend ponderado por contagem de `margemPontos()`** (linha 1348, D-04 do CONTEXT: INTOCADO). O efeito equivalente ao "placeholder Shopee puxa a média pra baixo proporcionalmente" acontece **automaticamente** no modelo novo: a empresa Shopee entra com `margem_pontos=1.0` na própria linha, e quando a Fase 120 fizer a média das `nota_empresa`, o efeito de "puxar pra baixo" aparece — só que via um mecanismo matemático diferente (média de médias, não blend ponderado por contagem). **Isto é exatamente o risco documentado no `<risks>` do CONTEXT — o teste `DesempenhoShopeeScoreTest` continua verde porque o caminho antigo não muda, mas o invariante dele não vale mais no caminho novo (problema da Fase 120, não da 119).**

**Conclusão prática para o plano:** a Fase 119 não precisa (e não deve) recriar NENHUM guard de dias-comuns/cobertura/baseline — eles já rodam dentro de `AdmanMetricDiffService::compute()`, que já é chamado por empresa. O trabalho da Fase 119 é só **ler o campo certo** (`diff_pp` em vez de `diff_pct`) e **não reimplementar a matemática de agregação** que existia só para compensar o fato de a régua rodar depois da média.

### Resposta 3 — De onde sai `margem_var_pp` por empresa

Caminho completo:

```
AdmanService::fetchAccountMetricsDetailedCached($custId, ...)
  → retorna array com chave 'percentageMargin' = ['value' => ?, 'diff' => ?, 'prev' => ?]
       ↓
AdmanMetricDiffService::compute(Company $company, array $periodo)
  → $marginPctAdman = $accountMetrics['percentageMargin'] ?? null;
  → $metrics['contribution_margin_pct'] = $this->resolveMargemPct($marginPctAdman, $isJanelaIgual);
       ↓
AdmanMetricDiffService::resolveMargemPct()  (linhas 294-325)
  → $diffPp = ($isJanelaIgual && $value !== null && $prevValue !== null)
        ? round($value - $prevValue, 2)
        : null;
  → retorna ['value'=>.., 'prev_value'=>.., 'diff_pct'=>.., 'diff_pp'=>$diffPp, 'diff_source'=>..]
       ↓
MetricDiffDispatcher::compute($company, $periodo, 'adman')  → repassa o array inteiro
       ↓
CompanyScoreService  → lê $resultado['metrics']['contribution_margin_pct']['diff_pp']
```

`diff_pp` é `null` em qualquer um destes casos (independentes — qualquer um basta):

1. `$periodo['comparison_mode'] !== 'previous_equal_length_window'` (ex.: mês em curso, `same_interval_previous_month`).
2. `percentageMargin.value` ausente (rate-limit, empresa sem métrica, endpoint falhou).
3. `percentageMargin.prev` ausente — **este é exatamente o campo que o probe MPP-04 está validando quanto a estabilidade**; se a Adman não devolver `.prev` de forma consistente, `diff_pp` será intermitentemente `null` mesmo para a mesma empresa/competência.

Para empresa Shopee, `ShopeeMetricDiffService::margemPctNula()` sempre retorna `diff_pp = null` **por definição** (Shopee não tem CMV — MPP-05).

**O que a linha por empresa deve fazer quando `margem_var_pp` é `null` (D-01):**

- Se `fonte_financeira === 'adman'` e `diff_pp === null` → `margem_pontos = null`; componente **ausente** de `componentes_presentes`; `quality.motivos[] = 'margem_pp_indisponivel'`.
- Se `fonte_financeira === 'shopee'` → `margem_pontos = 1.0` **sempre** (D-02/D-05 da milestone), independente de `diff_pp` (que é sempre `null` aqui de qualquer forma) — **nunca** tratar como componente ausente; `quality.margin_source = 'placeholder_shopee'`.

Fixture verificada (MPP-06, já testada na Fase 117): `value=27.47`, `prev=24.08` ⇒ `diff_pp=3.39` (⇒ `reguaMargem(3.39)=4`), enquanto `diff_pct` continua `14.09` (⇒ `reguaMargem(14.09)=5` — **valores DIFERENTES da mesma métrica**, prova viva de por que EMPS-03 exige ler o campo certo).

### Resposta 4 — Resolução da fonte financeira vencedora (EMPS-06)

A regra "Adman vence Shopee" vive hoje em `DesempenhoScoreService::computeUniverso()` (linhas 647-654):

```php
$fontes = $elegiveis
    ->groupBy('company_id')
    ->map(function (Collection $vs) {
        $sources = $vs->pluck('financial_source');
        return $sources->contains('adman') ? 'adman' : $sources->first();
    });
```

`$elegiveis` ali é **pré-filtrado** (`$vinculos->where('financial_metrics_eligible', true)`) — ou seja, essa expressão nunca vê uma empresa sem NENHUMA fonte elegível, porque essas já foram descartadas antes.

Para a Fase 119, a mesma expressão de desempate deve ser reaplicada, mas sobre o universo **completo** de `CarteiraContextService::forUser()` (não pré-filtrado):

```php
$vinculos = $carteiraContext->forUser($user, ['active' => true])
    ->reject(fn ($v) => $invalidadas->contains($v['company_id']));

$elegiveisPorEmpresa = $vinculos->where('financial_metrics_eligible', true)->groupBy('company_id');

// Para cada company_id do universo completo:
$fonteFinanceira = $elegiveisPorEmpresa->has($companyId)
    ? ($elegiveisPorEmpresa[$companyId]->pluck('financial_source')->contains('adman') ? 'adman' : $elegiveisPorEmpresa[$companyId]->pluck('financial_source')->first())
    : null; // D-03 — nenhum vínculo elegível ⇒ sem_fonte
```

`financial_source`/`financial_metrics_eligible` já vêm resolvidos POR VÍNCULO dentro de `CarteiraContextService::flagsFinanceirasPorSetor()` (linhas 247-276): `performance` → `adman`; `shopee` → `shopee`; `polos`/`publicacao`/`outros` → `null`/`false`. Não há necessidade de reimplementar essa tabela — só reaplicar o `groupBy`+desempate sobre o conjunto certo.

**Empresa sem fonte nenhuma (D-03):** `$fonteFinanceira === null` ⇒ a empresa **permanece** na Collection de retorno com `fonte_financeira = null`, `faturamento_pontos = null`, `margem_pontos = null`, `nps_pontos` = o que `NpsPorEmpresaService` devolver, `componentes_presentes = (nps_pontos !== null ? 1 : 0)`, `status = 'sem_fonte'`.

### Resposta 5 — Consumindo `NpsPorEmpresaService` (Fase 118)

Assinatura exata: `NpsPorEmpresaService::notasNpsPorEmpresa(User $user, Carbon $mes, bool $mesFechado, ?Collection $invalidadas = null): Collection`, chaveada por `company_id`.

Shape de cada entrada (ver `118-01-SUMMARY.md` e o docblock do método): `company_id, nota, origem, total_notas, por_ramo{atribuicao,legado,imputada}, por_papel, papeis, servico_ids, consolidado, notas_brutas, houve_survey`.

**O campo relevante para a Fase 119 é só `.nota`** — já é o `nps_pontos` pronto, já com o piso D-04 aplicado (1.0 quando não há NPS e a janela M+1 fechou, `null` só quando a janela M+1 ainda está em coleta — caso `janela_aberta`). **Não reimplementar** clamp/piso/janela — isso já está resolvido lá.

**Chamada:** UMA vez por `(user, mes, mesFechado, invalidadas)` — cobre TODAS as empresas do universo de uma vez, não chamar por empresa.

```php
$notasNps = $this->npsPorEmpresaService->notasNpsPorEmpresa($user, $mes, $periodo['is_closed'], $invalidadas);
$npsPontos = $notasNps[$companyId]->nota ?? null; // ver nota abaixo sobre "empresa ausente"
```

**Quando a empresa não aparece no retorno:** pelo próprio contrato do serviço (passos 2, 3 e 9 do método — ver código lido), `NpsPorEmpresaService` constrói seu `companiesUniverso` a partir do MESMO `CarteiraContextService::forUser($user, ['active'=>true])`, rejeita as MESMAS `$invalidadas`, e **garante uma entrada para TODA empresa desse universo** (via `linhaSemNota()` nos casos `mes_em_curso`/`janela_aberta`/`sem_nps`). **Portanto, se o `CompanyScoreService` usar exatamente o mesmo `forUser()` + a mesma `$invalidadas`, nunca deveria haver um `company_id` ausente.** Se acontecer na prática, é sinal de que os dois serviços divergiram na resolução do universo (bug a investigar, não comportamento esperado) — tratar defensivamente como `nps_pontos = null` + log de warning, nunca lançar exceção nem assumir silenciosamente outro valor.

### Resposta 6 — Taxonomia final de `status`/`quality`

O plano canônico (§3.1) sugere `complete|partial|blocked|invalidada|sem_baseline|sem_dados`; o CONTEXT acrescenta `sem_fonte`. Proposta final, **sem redundância**, coerente com D-01/D-02/D-03:

| Status | Condição | Coberto por decisão |
|---|---|---|
| `complete` | `componentes_presentes === 3` | D-02 (Shopee sempre entra aqui, `margem_pontos=1.0` conta como presente) |
| `partial` | `fonte_financeira !== null` e `1 <= componentes_presentes <= 2` | Faltou faturamento (sem baseline) OU margem (Adman sem `diff_pp`) — a causa específica vai em `quality.motivos`, não em outro valor de `status` |
| `sem_fonte` | `fonte_financeira === null` (D-03) | Sempre `componentes_presentes <= 1` (só NPS pode estar presente); avaliar `sem_fonte` **antes** de `partial`/`sem_dados` |
| `sem_dados` | `fonte_financeira !== null` e `componentes_presentes === 0` | Caso degenerado: nem faturamento, nem margem, nem NPS (só possível quando `nps_pontos` é `null` por `janela_aberta` — a única forma de NPS faltar) |

**Valores descartados por redundância, com justificativa:**

- **`blocked`** — é um conceito de nível **profissional** (`score_status` de `DesempenhoScoreService::computeScoreStatus()`, mantido pela Fase 120/AGRE-06), não de empresa. Uma empresa individual nunca "bloqueia" — ela é `sem_fonte`/`sem_dados`. Usar `blocked` aqui colidiria semanticamente com o campo já existente no payload do profissional.
- **`invalidada`** — empresas invalidadas na competência são **rejeitadas do universo antes de qualquer coisa** (ver Resposta 7) — nunca chegam a virar uma linha em `empresas_score`. Não existe linha para carregar esse status; a ausência da empresa na Collection já é o sinal (mesmo padrão hoje aplicado por `NpsPorEmpresaService` e por `computeUniverso()`).
- **`sem_baseline`** — seria redundante com `partial`: "sem baseline de faturamento" é só UMA das causas possíveis de `partial`. Fica documentado em `quality.motivos` (ex.: `'sem_baseline_faturamento'`), não como valor de `status` à parte — segue o próprio idioma que `AdmanMetricDiffService::buildQuality()` já usa (`status` grosso + detalhe em outro campo).

**`quality.motivos` (exemplos testáveis):** `sem_fonte_financeira` | `faturamento_sem_baseline` | `margem_pp_indisponivel` (Adman sem `diff_pp`) | `nps_janela_aberta` (NPS ausente por M+1 ainda em coleta).

### Resposta 7 — Montando o universo de empresas

```php
// 1. TODOS os vínculos, todos os setores — nunca filtrar por financial_metrics_eligible aqui.
$vinculos = $carteiraContext->forUser($user, ['active' => true]);

// 2. Invalidação por competência — MESMA competência M (nunca deslocada), ANTES de tudo.
$invalidadas = BonusInvalidacao::companyIdsInvalidadas($mes);
$vinculos    = $vinculos->reject(fn (array $v) => $invalidadas->contains($v['company_id']));

// 3. Identidade da empresa — dedupe SÓ para saber "quais empresas existem",
//    NUNCA para descartar as linhas (elas ainda são necessárias pra resolver
//    fonte financeira quando há >1 vínculo por empresa — Performance E Shopee).
$companiesUniverso = $vinculos->pluck('company_id')->unique()->values();
```

**Pitfall documentado (memória `project_company_users_multi_linha_servico`):** `company_users` tem VÁRIAS linhas por `(empresa, role)` — uma por serviço. `forUser()` **preserva** essa multiplicidade de propósito (CTX-04, "deliberadamente NÃO colapsa vínculos" — docblock de `contadores()`). Errar aqui significa: (a) contar a mesma empresa 2× se usar `->count()` em vez de `->unique()->count()`; ou (b) perder a informação de que uma empresa tem Performance E Shopee simultaneamente se colapsar cedo demais (isso quebraria a resolução de fonte vencedora da Resposta 4, que PRECISA ver as duas linhas pra aplicar o desempate).

**Ordem importa:** invalidação (passo 2) precisa vir **antes** de qualquer resolução de fonte/chamada ao dispatcher — mesmo padrão que `computeUniverso()`/`NpsPorEmpresaService` já seguem, e que evita chamar HTTP à Adman para uma empresa que nem vai entrar no cálculo.

### Resposta 8 — Riscos e armadilhas específicos

**1. Régua-da-média × média-das-réguas (o risco central da milestone, já documentado no `<risks>` do CONTEXT).** Hoje: `margemPontos()` aplica a régua UMA VEZ sobre a média agregada. No modelo novo: a régua roda por empresa, a média vem depois (Fase 120). Caso de prova (do CONTEXT, §5.4 do plano): Empresa A faturamento −20% (pontos 1), Empresa B +2% (pontos 4) ⇒ nova regra `(1+4)/2 = 2.5`; regra antiga `reguaFaturamento(−9%) = 1`. **Isto é esperado e correto — não é bug.** A Fase 119 deve incluir pelo menos um teste que documenta esse caso explicitamente (evita que a Fase 120/121 "descubra" a diferença acidentalmente e trate como regressão).

**2. Custo de N chamadas HTTP sequenciais à Adman num loop de carteira.** `AdmanMetricDiffService::compute()` faz até 2 chamadas HTTP síncronas por empresa quando o cache está frio (`fetchPerformance` + `fetchAccountMetricsDetailedCached`). Isso **já existe hoje** em `DesempenhoScoreService::compute()` — a Fase 119 não aumenta o volume de chamadas (ao contrário, remove a necessidade estrutural de uma futura 3ª chamada). Mas os testes desta fase **não podem** deixar isso acontecer de verdade: usar `Http::preventStrayRequests()` + `Http::fake(['*/performance/*' => ..., '*/accounts/*/metrics*' => ...])`, exatamente o padrão já estabelecido em `tests/Feature/DesempenhoShopeeScoreTest.php` (linhas 61-65). Isto é ainda mais crítico aqui porque o **GATE MPP-04 não está aprovado** — nenhum teste desta fase pode depender de uma chamada real à Adman para obter `percentageMargin.prev`; todo `diff_pp` testado deve vir de fixtures JSON controladas via `Http::fake()`.

**3. Risco de divergência silenciosa entre a linha por empresa e o agregado atual.** Como a Fase 119 é aditiva e ninguém consome `CompanyScoreService` ainda, não há sinal automático se ele divergir do `DesempenhoScoreService::compute()` de forma **não intencional** (bug, não a diferença matemática esperada do item 1). Recomendação: escrever pelo menos 1 teste de "reconciliação" (mesmo padrão de `NpsPorEmpresaRamosTest`, Fase 118) que roda os DOIS caminhos sobre a MESMA fixture e assere explicitamente **onde** eles devem bater (universo de empresas, fonte financeira resolvida, presença/ausência de NPS) e **onde** devem divergir por design (a nota final em si). Isso antecipa o trabalho de ROLL-01/02 (Fase 121) e evita que a primeira comparação real do `desempenho:comparar-score-empresa` seja a primeira vez que alguém olha os dois lado a lado.

**4. Gate de aditividade.** Toda task deve verificar `sha256sum app/Services/DesempenhoScoreService.php` antes/depois — mesmo padrão das Fases 117/118. Esta fase não deve gerar NENHUM diff nesse arquivo.

## Code Examples

### Régua aplicada por empresa, ANTES da média (EMPS-02)

```php
// Reusa reguaFaturamento()/reguaMargem() de DesempenhoScoreService SEM COPIAR —
// se elas permanecerem `private`, tornar `protected` (ou extrair um Trait/Value
// Object compartilhado) é decisão do planner, mas NÃO duplicar os cortes.
$faturamentoPontos = $this->reguaFaturamento($faturamentoVarPct); // null-safe, já existe
$margemPontos = match (true) {
    $fonteFinanceira === 'shopee' => 1.0,                 // D-02 — nunca aplica régua
    $margemVarPp === null         => null,                // Adman sem diff_pp — D-01
    default                       => $this->reguaMargem($margemVarPp), // D2 da milestone — cortes REUSADOS
};
```

### `nota_empresa` (D-01, EMPS-04) — dois números, nunca um só

```php
$pontos = collect([$npsPontos, $faturamentoPontos, $margemPontos]);
$presentes = $pontos->reject(fn ($v) => $v === null);

$componentesPresentes = $presentes->count();
$notaEmpresaParcial   = $presentes->isEmpty() ? null : round($presentes->avg(), 2);
$notaEmpresa          = $componentesPresentes === 3 ? $notaEmpresaParcial : null; // D-01
```

## State of the Art

| Abordagem antiga | Abordagem nova | Quando mudou | Impacto |
|---|---|---|---|
| Régua aplicada sobre a MÉDIA agregada da carteira (`reguaFaturamento($vars->avg())`, `margemPontos()` com blend por contagem) | Régua aplicada POR EMPRESA, média (se houver) roda depois | Fase 119 (aditivo) → vira o caminho vivo só na Fase 120 (flag) | Distribuição de notas muda — não é regressão, é o objetivo da milestone (D3) |
| Margem lida via `diff_pct` (variação relativa) | Margem lida via `diff_pp` (pontos percentuais, campo novo Fase 117) | Fase 117 (campo existe, gate MPP-04 pendente) → consumido pela primeira vez na Fase 119 | Distribuição comprime na faixa 3-4 (D2 da milestone, usuário ciente) |
| `AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA` preferia `calculated_fallback` sob baixa cobertura | Hotfix `a413e823` (24/07) reverteu — `resolveMargemPct()` nunca chama fallback, retorna `null` quando `.diff` nativo indisponível | 2026-07-24 | A constante ficou órfã (só lida por `ProbeMargemPrevStability` via Reflection) — não confundir com guard ativo |

**Descontinuado/obsoleto:**
- `AdmanMetricDiffService::fallbackMargemPct()` e `coberturaMargem()`: métodos sem nenhum call-site em produção (confirmado por grep). Não removê-los é decisão de outra fase (não tocar `AdmanMetricDiffService` nesta fase, que já está fechada desde a 117); só não construir cima deles supondo que estão ativos.

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|---|---|---|
| A1 | `AdmanMetricDiffService` não é bindado como singleton no container (baseado em não haver binding explícito em `app/Providers/`) | Resposta 1 | Se houver binding singleton em algum ServiceProvider não localizado pela busca, o comportamento de memo cross-instância seria diferente (mais compartilhamento, não menos) — não muda a conclusão de que 1x vs 2x é seguro, só o motivo exato |
| A2 | `CompanyScoreService` deve receber `$periodo` já resolvido em vez de resolver internamente | Summary / Anti-Patterns | Decisão de design (D-05 é discricionária do planner) — se o planner escolher resolver internamente, deve garantir explicitamente que usa a MESMA lógica de `resolvePeriodo()` (hoje `private`) para não divergir da janela do `DesempenhoScoreService` |
| A3 | O status `sem_dados` (componentes_presentes=0 com fonte financeira presente) é um caso raro mas real, limitado ao cenário `nps='janela_aberta'` + faturamento/margem ambos indisponíveis | Resposta 6 | Se a taxonomia proposta não cobrir algum caso de borda real da carteira, o planner precisa validar contra dados de produção antes de travar a fórmula de `status` |

**Nenhum destes é uma claim de negócio/compliance** — são inferências de engenharia sobre código lido diretamente; risco é de retrabalho de design, não de dado incorreto indo pra produção (a fase é aditiva e não persiste nada).

## Open Questions

1. **Onde vivem `reguaFaturamento()`/`reguaMargem()` para o `CompanyScoreService` reusar sem duplicar os cortes?**
   - O que sabemos: hoje são métodos `private` de `DesempenhoScoreService` (linhas 1290/1311).
   - O que é incerto: se o planner deve (a) tornar `protected`/extrair para uma classe/trait compartilhada, ou (b) duplicar os cortes numéricos em `CompanyScoreService` com um teste de paridade que compara as duas implementações (mesmo padrão de `NpsJanelaResolver` vs `computeNpsWindow` na Fase 118 — teste de equivalência, não extração completa).
   - Recomendação: extrair para um `ReguaBonusService`/`ReguaCalculator` compartilhado é o caminho mais limpo, mas exige tocar (ainda que minimamente, via `protected`) `DesempenhoScoreService.php` — o que colide com o gate de aditividade "byte-a-byte intocado" das Fases 117/118. Se o gate de hash for interpretado de forma estrita (nem mudar de `private` pra `protected`), a opção (b) — duplicar + teste de paridade — é a única compatível. **Decisão do planner, mas registrar explicitamente qual caminho foi escolhido e por quê no PLAN.md.**

2. **A empresa com `fonte_financeira=null` (D-03) deve ainda tentar chamar `MetricDiffDispatcher`?**
   - O que sabemos: `MetricDiffDispatcher::compute()` lança `InvalidArgumentException` para qualquer `$source` fora de `'adman'|'shopee'` (linha 39-41 do dispatcher).
   - O que é incerto: nada, na verdade — a resposta é clara: **nunca chamar o dispatcher quando `fonte_financeira === null`**. Documentado aqui só para deixar explícito no plano que o `if ($fonteFinanceira !== null)` precisa envolver a chamada ao dispatcher, senão o `CompanyScoreService` quebra com exceção na primeira empresa `sem_fonte` (ex.: setor `polos`).

## Environment Availability

| Dependência | Exigida por | Disponível | Versão | Fallback |
|---|---|---|---|---|
| PHP CLI (`C:\xampp\php\php.exe`) | Rodar testes/artisan | ✓ | 8.2.12 (não está no PATH — usar caminho absoluto) | — |
| SQLite in-memory (`phpunit.xml`) | `RefreshDatabase` nos testes Feature | ✓ | via `DB_CONNECTION=sqlite`/`:memory:` | — |
| Adman API real (HTTP) | Cálculo AO VIVO de `diff_pp`/`diff_pct` em produção | ✗ (não deve ser usada nesta fase) | — | `Http::fake()` obrigatório em todos os testes — GATE MPP-04 ainda não aprovado, nenhuma chamada real deve acontecer nesta fase |

**Dependências ausentes sem fallback:** nenhuma — o único "ausente" (Adman real) tem fallback obrigatório (mock) e é, na verdade, uma restrição de design, não uma lacuna de ambiente.

## Validation Architecture

### Test Framework
| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.5.50 (via `laravel/framework ^12.0`) |
| Config file | `phpunit.xml` (SQLite `:memory:`) |
| Quick run command | `"/c/xampp/php/php.exe" artisan test --filter=Phase119` (Windows: `C:\xampp\php\php.exe artisan test --filter=Phase119`) |
| Full suite / regressão ampla | `C:\xampp\php\php.exe artisan test --filter=Desempenho` — **NUNCA** `php artisan test` sem `--filter` (trava por HTTP real não mockada, documentado em `116-08`). Baseline conhecida: 14 falhas pré-existentes não relacionadas (debug de margem já aberto) — qualquer número acima disso é regressão a investigar |

### Phase Requirements → Test Map

| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|---|---|---|---|---|
| EMPS-01 | Linha por empresa tem todas as chaves do contrato §3.1 | unit/feature | `php artisan test --filter=CompanyScoreServiceContratoTest` | ❌ Wave 0 |
| EMPS-02 | Régua de faturamento aplicada por empresa, ANTES da média — caso âncora do CONTEXT (A −20%→1, B +2%→4, média=2.5 ≠ reguaFaturamento(−9%)=1) | feature | `php artisan test --filter=CompanyScoreServiceFormulaTest::test_regua_por_empresa_diverge_da_regua_agregada` | ❌ Wave 0 |
| EMPS-03 | Margem usa `diff_pp`, nunca `diff_pct` — fixture MPP-06 (`value=27.47,prev=24.08` ⇒ `diff_pp=3.39`⇒pontos 4, enquanto `diff_pct=14.09`⇒pontos 5) | feature (`Http::fake`) | `php artisan test --filter=CompanyScoreServiceMargemTest::test_margem_usa_diff_pp_nao_diff_pct` | ❌ Wave 0 |
| EMPS-04 | `nota_empresa = round((nps+fat+margem)/3,2)` — caso âncora do CONTEXT (4.6+5+4)/3=4.53 | unit/feature | `php artisan test --filter=CompanyScoreServiceFormulaTest::test_nota_empresa_caso_ancora` | ❌ Wave 0 |
| EMPS-05 | Dispatcher chamado exatamente 1× por empresa | feature (spy/mock ou `Http::fake` + `assertSentCount`) | `php artisan test --filter=CompanyScoreServiceDispatcherTest::test_dispatcher_chamado_uma_vez_por_empresa` | ❌ Wave 0 |
| EMPS-06 | Adman vence Shopee; empresa Shopee usa `margem_pontos=1.0` com `quality.margin_source=placeholder_shopee` — caso âncora D-02 (NPS 4.2+fat 4+margem 1.0)/3=3.07 | feature | `php artisan test --filter=CompanyScoreServiceFonteTest` | ❌ Wave 0 |
| EMPS-07 | `status`/`quality` cobrem `complete/partial/sem_fonte/sem_dados` de forma testável (casos âncora D-01/D-03) | feature | `php artisan test --filter=CompanyScoreServiceStatusTest` | ❌ Wave 0 |

### Sampling Rate
- **Por commit de task:** `php artisan test --filter=Phase119`
- **Por merge de wave:** `php artisan test --filter=Desempenho` (baseline: 14 falhas pré-existentes; qualquer excesso é regressão)
- **Gate de fase:** suíte de regressão ampla verde (dentro da baseline conhecida) + `sha256sum app/Services/DesempenhoScoreService.php` idêntico ao commit anterior antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase119/CompanyScoreServiceContratoTest.php` — cobre EMPS-01
- [ ] `tests/Feature/Phase119/CompanyScoreServiceFormulaTest.php` — cobre EMPS-02, EMPS-04
- [ ] `tests/Feature/Phase119/CompanyScoreServiceMargemTest.php` — cobre EMPS-03 (usa `Http::fake` com fixtures `percentageMargin.value`/`.prev`/`.diff` — nunca chamada real, gate MPP-04 pendente)
- [ ] `tests/Feature/Phase119/CompanyScoreServiceDispatcherTest.php` — cobre EMPS-05
- [ ] `tests/Feature/Phase119/CompanyScoreServiceFonteTest.php` — cobre EMPS-06
- [ ] `tests/Feature/Phase119/CompanyScoreServiceStatusTest.php` — cobre EMPS-07
- [ ] Nenhum framework/config novo necessário — `RefreshDatabase` + `CriaCenarioResponsaveis` (trait já usada em `DesempenhoShopeeScoreTest`/`NpsPorEmpresaContratoTest`) cobrem a necessidade de fixtures

## Security Domain

`security_enforcement` está ausente do `.planning/config.json` (tratado como habilitado por padrão), mas esta fase **não introduz superfície de ataque nova**: é um serviço de leitura interno, sem rota HTTP, sem input de usuário, sem escrita em banco (aditivo/leitura pura, consumido só por testes).

### Categorias ASVS aplicáveis

| Categoria ASVS | Aplica | Controle padrão |
|---|---|---|
| V2 Autenticação | Não | Nenhuma rota/endpoint novo |
| V3 Sessão | Não | — |
| V4 Controle de acesso | Não | O serviço opera sobre `User $user` já resolvido pelo caller; nenhuma decisão de autorização nova |
| V5 Validação de entrada | N/A | Nenhum input externo — parâmetros são objetos de domínio (`User`, `Carbon`, `Collection`) já validados a montante |
| V6 Criptografia | Não | — |

### Padrões de ameaça conhecidos no domínio

| Padrão | STRIDE | Mitigação padrão |
|---|---|---|
| Vazamento de PII em log (nome de cliente/empresa, e-mail, resposta de NPS) | Information Disclosure | `NpsPorEmpresaService` já estabelece o padrão (Log::warning só com IDs/competência, nunca nome/e-mail/texto — ver linhas 264-271). `CompanyScoreService` deve seguir a MESMA disciplina se logar qualquer coisa (ex.: warning de "company_id ausente no retorno do NPS", ver Open Question 2/Resposta 5) |

## Sources

### Primary (HIGH confidence — leitura direta do código)
- `app/Services/DesempenhoScoreService.php` — linhas 380-1400 lidas integralmente (compute, computeUniverso, computeVarFaturamento, computeVarMargem, reguaFaturamento, reguaMargem, margemPontos, computeScoreStatus, computeNpsWindow)
- `app/Services/Metrics/AdmanMetricDiffService.php` — arquivo completo (661 linhas)
- `app/Services/Metrics/MetricDiffDispatcher.php` — arquivo completo
- `app/Services/Metrics/ShopeeMetricDiffService.php` — arquivo completo
- `app/Services/Portfolio/CarteiraContextService.php` — arquivo completo (278 linhas)
- `app/Services/Desempenho/NpsPorEmpresaService.php` — arquivo completo (517 linhas)
- `.planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md` — decisões D-01..D-05, risco, gate MPP-04
- `.planning/REQUIREMENTS-v21.md` — EMPS-01..07, D1-D6 da milestone
- `.planning/phases/118-nps-por-empresa-v21-0/118-01-SUMMARY.md`
- `plano-implementacao-desempenho-por-empresa.md` §3.1-3.5, §4 Fase 3, §5

### Secondary (MEDIUM confidence)
- `tests/Feature/DesempenhoShopeeScoreTest.php` (padrão de `Http::fake`/`Http::preventStrayRequests` para testes de diff financeiro)
- `tests/Feature/Phase118/NpsPorEmpresaContratoTest.php` (padrão de fixtures NPS)
- Memória `project_company_users_multi_linha_servico` (multiplicidade de vínculos por empresa)

### Tertiary (LOW confidence)
- Nenhuma — toda a pesquisa foi verificada por leitura direta de código ou documento canônico do projeto.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhuma lib externa, 100% código interno já lido
- Architecture: HIGH — fluxo de dados rastreado ponta a ponta (Adman → AdmanMetricDiffService → MetricDiffDispatcher → consumidor) com leitura direta de cada camada
- Pitfalls: HIGH — guards, memo de cache e dead code (`fallbackMargemPct`) confirmados por grep, não por suposição

**Research date:** 2026-07-28
**Valid until:** enquanto o GATE MPP-04 não for aprovado (a validade real desta pesquisa está condicionada ao veredito do probe — se `reprovado`, esta pesquisa precisa ser revisitada antes de qualquer execução)
