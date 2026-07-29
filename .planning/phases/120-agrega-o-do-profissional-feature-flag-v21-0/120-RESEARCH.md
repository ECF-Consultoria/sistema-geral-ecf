# Phase 120: Agregação do profissional + feature flag - Research

**Researched:** 2026-07-29
**Domain:** Motor de bonificação (`DesempenhoScoreService`) — bifurcação por feature flag, agregação profissional-a-partir-de-empresa, versionamento de cache
**Confidence:** HIGH (todo o conteúdo crítico foi lido diretamente do código-fonte atual; nenhuma parte depende de API externa ou de conhecimento de treinamento)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**D-01 · Denominador da média.** Só empresas `complete` entram na média, e a cobertura vira guarda de status. Empresa `partial`, `sem_fonte` ou `sem_dados` fica fora do denominador — não entra com `nota_empresa_parcial`. A guarda de cobertura é o que impede que um profissional com 2 de 10 empresas completas seja julgado só por 2 com aparência de nota oficial.

**D-02 · `score_status` deriva da cobertura de empresas completas:**
- `official` — cobertura ≥ patamar
- `partial` — tem nota, mas cobertura abaixo do patamar
- `blocked` — nenhuma empresa com nota alguma

A trava da Fase 109 é preservada sem exceção especial: empresa Shopee é `complete` (placeholder de margem 1.0 conta como componente presente), então profissional só-Shopee tem cobertura 100% e continua `official`.

**D-03 · Patamar de cobertura = 70%.** (Decisão do pesquisador/planner anterior — o usuário não fixou o número; fácil de sobrepor.) Reusa `ConsolidarMesDesempenho::MARGEM_COBERTURA_MINIMA_CONGELAMENTO = 0.7` para evitar dois conceitos concorrentes de "cobertura suficiente".

**D-04 · Shadow apenas em COMANDOS.** Com a flag desligada, `empresas_score` é calculado apenas em `desempenho:warm-cache`, `desempenho:consolidar-mes` e o comparador da Fase 121 — nunca em leitura interativa (`PerformanceController`, `PortfolioController`, `DashboardController`). Razão: dashboard de 70s por chamada HTTP síncrona à Adman (memória `project_desempenho_compute_cache`); rodar o dispatcher por empresa + NPS por empresa em toda requisição de tela dobraria esse custo com a flag ainda desligada.

**D-05 · `DesempenhoShopeeScoreTest` ganha cenários espelho para o modo flag-ligada, mantendo os 7 atuais intactos.** Enquanto a flag estiver desligada, o caminho antigo é o de produção — reescrever os invariantes validaria um caminho que não roda. A suíte passa a documentar as duas semânticas.

### Claude's Discretion

Nenhuma área foi explicitamente delegada como "discretion" no CONTEXT.md desta fase — D-03 (patamar de 70%) é a única decisão marcada como não travada pelo usuário e "fácil de sobrepor" caso o discuss-phase decida diferente.

### Deferred Ideas (OUT OF SCOPE)

- **Ligar a flag em produção** — depende do gate MPP-04 aprovado **e** do delta da Fase 121 aceito.
- **Aposentar `margemPontos()` e as réguas duplicadas** — só quando a flag virar permanente; unificação de `reguaFaturamento`/`reguaMargem` (débito da C-03 da Fase 119) entra junto.
- **Persistir `empresas_score`** — Fase 122.
- **Exibir a lista de empresas com nota** — Fase 123.

### Risco herdado (Fase 119 · `119-04-SUMMARY.md`)

`DesempenhoScoreService::margemPontos()` aplica a régua **uma vez** sobre a média agregada da carteira; o modelo novo aplica a régua **por empresa** e promedia depois. **Régua-da-média ≠ média-das-réguas.** O invariante documentado em `margemPontos()` ("só-performance devolve exatamente `reguaMargem($varMargemReal)` — regressão zero") **não vale** no caminho novo — é esperado, mas a Fase 121 precisa quantificar antes de qualquer ativação.

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| AGRE-01 | Com a flag ligada, `nota_final` do profissional é exatamente a média das `nota_empresa` das empresas consideradas | Ver "Q1 — Onde a bifurcação entra" e "Q4 — computeScoreStatus" abaixo; `CompanyScoreService::computeEmpresasScore()` já entrega `nota_empresa` por linha (D-01 da Fase 119) |
| AGRE-02 | Feature flag `config('metrics.performance_company_first_score')` controla a troca; `empresas_score` calculado em shadow nos dois modos | Ver "Q2 — padrão de `config/metrics.php`" e "Q7 — onde o shadow roda" |
| AGRE-03 | `cacheKey()` sobe de v12→v13; 4 suítes com string hardcoded atualizadas | Ver "Q6 — bump de cacheKey" — lista completa e verificada por grep |
| AGRE-04 | Payload expõe `empresas_score` e `componentes.var_margem_pp`, preservando chaves legadas | Ver "Q5 — payload completo de `compute()`" — lista letra-por-letra do array atual |
| AGRE-05 | Tratamento de empresa sem baseline segue D-01 (excluir do denominador), sem contradizer DESEMP-06 nem a trava da Fase 109 | D-01/D-02 já resolvem isto — ver seção de decisões acima e "Q4" |
| AGRE-06 | `score_status` permanece coerente: só-Shopee continua `official`, nunca `blocked` por ausência de margem | Ver "Q4" e "Q8" (por que o placeholder Shopee garante cobertura 100%) |

</phase_requirements>

## Summary

Esta é a primeira fase que **modifica** `app/Services/DesempenhoScoreService.php` (Fases 117-119 mantiveram-no byte-a-byte intocado com gate de hash). A boa notícia é que a superfície de mudança mínima é pequena e bem localizada: `compute()` (linhas 394-589) já tem um ponto de bifurcação natural logo após o cálculo dos 4 componentes legados (linha ~459, após `$margemPontos = $this->margemPontos(...)`) e antes de `computeNotaFinal()`/`computeScoreStatus()` (linhas 462/465). O `CompanyScoreService::computeEmpresasScore()` (Fase 119, `app/Services/Desempenho/CompanyScoreService.php`) já está pronto e testado (29/29 verdes) para ser consumido — ele exige exatamente os mesmos insumos que `compute()` já tem em mãos naquele ponto (`$user`, `$mes`, `$periodo`, `$invalidadas`).

**O risco real não é a lógica nova — é o "shadow apenas em comandos" (D-04).** `compute()` e `computeCached()` são chamados por ~23 arquivos, incluindo os 3 controllers de leitura interativa (`PerformanceController`, `PortfolioController`, `DashboardController`) que chamam `computeCached($user, $mes)` com apenas 2 argumentos. Para que o shadow NUNCA rode nessas chamadas, é preciso um sinal de contexto explícito (não a feature flag) — um parâmetro opcional adicional em `compute()`/`computeCached()`, default `false`, passado como `true` apenas por `desempenho:warm-cache` e `desempenho:consolidar-mes`. Há uma armadilha de cache aqui: `computeCached()` usa `Cache::remember()` com a MESMA chave independentemente do shadow — se um cache-miss interativo (shadow=false) popular a chave primeiro, o próximo ciclo do `desempenho:warm-cache` (shadow=true) vai encontrar a chave já preenchida e **pular** o closure, deixando o shadow silenciosamente sem rodar naquele ciclo. Isto é aceitável para `desempenho:warm-cache` (é só uma pré-aquecida, best-effort) mas não para `desempenho:consolidar-mes`, que chama `compute()` DIRETO (sem cache) — esse comando sempre roda o shadow com garantia.

**Primary recommendation:** Adicionar um parâmetro `bool $incluirEmpresasScore = false` a `compute()` (e propagá-lo, também com default `false`, em `computeCached()`), calcular `$empresasScore` logo após a resolução de `$invalidadas` (linha ~421) quando `$incluirEmpresasScore || config('metrics.performance_company_first_score')` for verdadeiro, bifurcar `$nota`/`$scoreStatus` só quando a flag (não o parâmetro de shadow) estiver ligada, e adicionar `empresas_score` + `componentes.var_margem_pp` ao array de retorno sem remover/renomear nenhuma chave existente. Com a flag `false` e o parâmetro de shadow `false` (o caso de todos os ~40 call-sites existentes, incluindo os 3 controllers), o fluxo de `$nota`/`$scoreStatus` é **idêntico byte a byte** ao de hoje — nenhuma linha de código que já roda hoje muda de comportamento.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Agregação `nota_final` do profissional | API/Backend (Service Layer) | — | Cálculo puro de negócio; já vive em `DesempenhoScoreService::compute()`, sem I/O de UI |
| Feature flag `performance_company_first_score` | API/Backend (Config) | — | `config/metrics.php` já hospeda o precedente `unified_metrics_enabled`; leitura só via `config()`, nunca `env()` direto em runtime |
| Shadow computation (`empresas_score` sem consumir a nota) | Console Commands (background) | — | D-04: nunca em leitura interativa — `PerformanceController`/`PortfolioController`/`DashboardController` chamam `computeCached()` sem o novo parâmetro |
| Cache do payload de desempenho | Database/Storage (cache Redis/array) | API/Backend | `Cache::remember()` chaveado por versão (`cacheKey()`); bump obrigatório quando o shape do array muda (AGRE-03) |
| Linha por empresa (`nota_empresa`, `status`, `quality`) | API/Backend (Service Layer) | — | `CompanyScoreService` (Fase 119), já implementado e testado; esta fase só consome |
| Persistência mensal do payload | Console Commands | Database/Storage | `ConsolidarMesDesempenho::updateOrCreate` grava `breakdown_json` = `$result` inteiro — `empresas_score` entra "de graça" nesta fase (persistência estruturada só na Fase 122) |

## Standard Stack

Não há introdução de bibliotecas externas nesta fase — é uma modificação puramente interna a um serviço PHP já existente, consumindo outro serviço PHP já existente (`CompanyScoreService`, Fase 119). Não se aplica gate de legitimidade de pacote (nenhum `composer require`/`npm install` previsto).

### Core (assets internos reutilizados, não pacotes)
| Classe/Config | Local | Papel nesta fase |
|---------|---------|--------------|
| `DesempenhoScoreService::compute()` | `app/Services/DesempenhoScoreService.php:394-589` | Onde a bifurcação entra |
| `CompanyScoreService::computeEmpresasScore()` | `app/Services/Desempenho/CompanyScoreService.php:120` | Fonte da linha por empresa — primeiro consumidor real |
| `config/metrics.php` | raiz `config/` | Precedente de flag (`unified_metrics_enabled`) — a flag nova segue o MESMO padrão |
| `ConsolidarMesDesempenho::MARGEM_COBERTURA_MINIMA_CONGELAMENTO` | `app/Console/Commands/ConsolidarMesDesempenho.php:76` | Origem do patamar 0.7 da D-03 |

**Installation:** nenhuma (`composer.json` inalterado).

## Package Legitimacy Audit

**N/A — esta fase não instala pacotes externos.** Toda a mudança é interna ao código PHP já versionado (`DesempenhoScoreService`, `CompanyScoreService`, `config/metrics.php`). Gate de legitimidade de pacote não se aplica.

## Architecture Patterns

### System Architecture Diagram

```
                         ┌─────────────────────────────────────────┐
                         │  Console Commands (shadow habilitado)    │
                         │  - desempenho:warm-cache                 │
                         │  - desempenho:consolidar-mes             │
                         │  - desempenho:comparar-score-empresa*    │
                         │    (*Fase 121, ainda não existe)         │
                         └──────────────┬────────────────────────────┘
                                        │ compute($user,$mes,$periodo,
                                        │         incluirEmpresasScore:true)
                                        ▼
┌──────────────────┐   computeCached()  ┌───────────────────────────────────┐
│ Controllers       │ ─────────────────▶│  DesempenhoScoreService::compute() │
│ (leitura          │  (SEM o novo       │                                    │
│  interativa)      │   parâmetro —      │  1. computeUniverso()              │
│ - Performance     │   shadow=false)    │  2. invalidadas = BonusInvalidacao  │
│ - Portfolio       │                    │     ::companyIdsInvalidadas($mes)  │
│ - Dashboard       │                    │  3. IF incluirEmpresasScore OR      │
└──────────────────┘                    │       config(flag):                │
                                         │       $empresasScore =              │
                                         │       CompanyScoreService           │
                                         │       ::computeEmpresasScore(       │
                                         │         $user,$mes,$periodo,        │
                                         │         $invalidadas)   ◄── SHADOW  │
                                         │  4. nps/varFat/varMargem/margemPts  │
                                         │     (4 componentes legados,         │
                                         │      SEMPRE calculados, INTOCADOS)  │
                                         │  5. IF config(flag)==true:          │
                                         │       nota = média(nota_empresa     │
                                         │         das 'complete')             │
                                         │       scoreStatus = por cobertura   │
                                         │     ELSE (flag=false, hoje SEMPRE):  │
                                         │       nota = computeNotaFinal(...)  │
                                         │         [IDÊNTICO a hoje]           │
                                         │       scoreStatus =                 │
                                         │         computeScoreStatus(...)     │
                                         │         [IDÊNTICO a hoje]           │
                                         │  6. return [...chaves legadas,      │
                                         │       'empresas_score' => ...,      │
                                         │       'componentes.var_margem_pp']  │
                                         └───────────────────────────────────┘
```

### Recommended Project Structure

Nenhum arquivo novo de produção é necessário — a fase modifica `DesempenhoScoreService.php` in-place e adiciona a chave nova em `config/metrics.php`. Testes novos vivem em `tests/Feature/Phase120/`.

```
app/Services/
├── DesempenhoScoreService.php        # MODIFICADO nesta fase (gate de hash cai)
└── Desempenho/
    └── CompanyScoreService.php        # Fase 119, sem mudanças — só consumido
config/
└── metrics.php                        # + chave 'performance_company_first_score'
tests/Feature/
├── DesempenhoShopeeScoreTest.php      # 7 testes existentes — cacheKey literal atualizado (v12→v13)
├── Phase96/NpsInvalidacaoRespostaTest.php   # idem
├── Phase116/NpsFloorDesempenhoTest.php      # idem
├── V18/DesempenhoMetadadosCacheTest.php     # idem
└── Phase120/                           # NOVO — AGRE-01..06 + cenários espelho flag-ligada (D-05)
```

### Padrão 1: Bifurcação por feature flag com shadow independente (a peça central desta fase)

**O quê:** dois sinais booleanos independentes controlam comportamentos diferentes — (a) o parâmetro de shadow controla SE `CompanyScoreService::computeEmpresasScore()` roda; (b) `config('metrics.performance_company_first_score')` controla QUAL resultado vira `nota_final`/`score_status`. Confundir os dois quebra D-04 (a flag desligada não pode implicar shadow desligado nos comandos, e o shadow ligado não pode implicar nota nova em produção).

**Quando usar:** sempre que uma fase precisa auditar um caminho novo (calcular, mas não consumir) antes de decidir ativá-lo — o padrão já existe em `config/metrics.php` para `unified_metrics_enabled`, mas aquele caso não tem a restrição extra de "shadow só em alguns call-sites". Esta fase é a primeira a precisar de DOIS sinais.

**Exemplo (recomendação de implementação, não código final — decisão de sintaxe exata é do planner/executor):**
```php
// DesempenhoScoreService.php — assinatura NOVA, 100% compatível pra trás
// (parâmetro tem default, os ~40 call-sites existentes não mudam uma linha)
public function compute(
    User $user,
    Carbon $mesReferencia,
    ?array $periodoOverride = null,
    bool $incluirEmpresasScore = false,   // NOVO — shadow, NÃO é a feature flag
): array
{
    // ... trecho intocado até a linha 421 (resolução de $invalidadas) ...

    // ── NOVO: shadow, roda quando pedido explicitamente OU quando a flag
    //    já estiver ligada (aí virou consumo real, não mais shadow) ──────
    $empresasScore = null;
    if ($incluirEmpresasScore || config('metrics.performance_company_first_score')) {
        $empresasScore = $this->companyScoreService->computeEmpresasScore(
            $user, $mes, $periodo, $invalidadas
        );
    }

    // ... 4 componentes legados, INTOCADOS (linhas 436-459 de hoje) ─────

    // ── Bifurcação real (linhas 462/465 de hoje) ────────────────────────
    if (config('metrics.performance_company_first_score') && $empresasScore !== null) {
        [$nota, $scoreStatus] = $this->computeNotaFinalPorEmpresa($empresasScore); // NOVO (D-01/D-02/D-03)
    } else {
        $nota        = $this->computeNotaFinal($nps, $varFat, $margemPontos);      // INTOCADO
        $scoreStatus = $this->computeScoreStatus($contadores, $varFat, $margemPontos); // INTOCADO
    }

    // ... resto do método INTOCADO ...

    return [
        // ... todas as chaves legadas, SEM MUDANÇA ...
        'empresas_score' => $empresasScore?->values()->all() ?? [],   // NOVO (AGRE-04)
        'componentes' => [
            'nps_medio'           => $nps,
            'var_faturamento_pct' => $varFat,
            'var_margem_pct'      => $varMargem,
            'var_margem_pp'       => $empresasScore?->avg('margem_var_pp'),  // NOVO — ver Open Question
            'absenteismo_pct'     => $absent,
        ],
        // ... resto INTOCADO ...
    ];
}
```

`computeCached()` precisa do MESMO tratamento — propagar `$incluirEmpresasScore` como parâmetro opcional (default `false`), incluído na closure passada a `Cache::remember()`. Ver Common Pitfalls abaixo para a armadilha de cache que isso introduz.

### Anti-Patterns to Avoid

- **Ler `env('...')` direto no lugar de `config('metrics.performance_company_first_score')`:** o `config/metrics.php` já documenta por quê — o Laravel invalida `env()` fora de config files quando `php artisan config:cache` está ativo em produção.
- **Rodar `CompanyScoreService::computeEmpresasScore()` incondicionalmente em todo `compute()`:** viola D-04 diretamente — dobra o custo HTTP síncrono em toda leitura interativa mesmo com a flag desligada.
- **Fazer `computeNotaFinal()`/`computeScoreStatus()` legados dependerem de `$empresasScore` de alguma forma (mesmo que sutil):** quebra a garantia de byte-equivalência — o caminho legado deve permanecer uma árvore de chamadas totalmente separada da nova.
- **Reescrever os 7 testes de `DesempenhoShopeeScoreTest`** em vez de adicionar cenários espelho (D-05) — eles documentam o comportamento de PRODUÇÃO enquanto a flag estiver desligada.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Linha por empresa (NPS+faturamento+margem já pontuados) | Recalcular tudo dentro de `compute()` | `CompanyScoreService::computeEmpresasScore()` (Fase 119) | Já testado (29/29), já resolve fonte vencedora Adman×Shopee, guard C-04, dispatcher 1×/empresa |
| Régua de faturamento/margem por empresa | Nova implementação | `CompanyScoreService::reguaFaturamento()`/`reguaMargem()` (duplicação intencional da Fase 119, C-03) | Já provado byte-idêntico ao original via teste de equivalência por Reflection |
| Nota de NPS por empresa | Nova consulta a `nps_score_assignments`/`nps_surveys` | `NpsPorEmpresaService::notasNpsPorEmpresa()` (Fase 118), já consumido por `CompanyScoreService` | Preserva os 3 ramos (atribuição/legado/imputado) e a janela M+1 |
| Patamar de cobertura mínima | Inventar um novo número/constante | Reusar `0.7` de `ConsolidarMesDesempenho::MARGEM_COBERTURA_MINIMA_CONGELAMENTO` (D-03) | Evita 2 conceitos concorrentes de "cobertura suficiente" no mesmo domínio |

**Key insight:** as Fases 118/119 já resolveram toda a complexidade de dados (fonte financeira vencedora, NPS multi-ramo, réguas, placeholders). Esta fase é **estritamente aritmética de agregação** — soma/média de linhas já prontas + um `if` de feature flag. Se o plano começar a reimplementar qualquer coisa que já existe em `CompanyScoreService`/`NpsPorEmpresaService`, é sinal de que o escopo vazou.

## Common Pitfalls

### Pitfall 1: A armadilha do `Cache::remember()` com chave única em `computeCached()`

**O que dá errado:** `WarmDesempenhoCache::handle()` chama `$this->scoreService->computeCached($user, $mesReferencia)` (2 argumentos, sem shadow). Se este comando também passar `incluirEmpresasScore: true`, o `Cache::remember($cacheKey, $ttl, fn() => ...)` só invoca o closure quando a chave NÃO existe. Se um controller interativo (`PerformanceController`/`PortfolioController`/`DashboardController` — chamam `computeCached()` SEM o novo parâmetro, shadow implícito `false`) causar um cache-miss e popular a MESMA chave ANTES do próximo ciclo de `desempenho:warm-cache` (a cada 8min, TTL 10min), o warm-cache vai encontrar a chave já preenchida (sem `empresas_score`) e **pular** o cálculo — o shadow silenciosamente não roda naquele ciclo.

**Por que acontece:** `cacheKey()` (linha 302-336) não inclui nenhum sinal de "shadow incluído ou não" — é a MESMA chave para os dois casos, por design (o payload cacheado deve ser o mesmo para todos os consumidores dentro do TTL).

**Como evitar:** aceitar que `desempenho:warm-cache` é *best-effort* para o shadow (é só pré-aquecimento, não é o registro canônico) — o registro **canônico e garantido** do shadow é `desempenho:consolidar-mes`, que chama `compute()` **direto** (sem `Cache::remember`, linha 139 de `ConsolidarMesDesempenho.php`) e portanto SEMPRE roda o shadow quando solicitado. Se o plano precisar de garantia forte também no warm-cache, a alternativa é `Cache::forget($cacheKey)` antes de `computeCached()` nesse comando — mas isso reintroduz o custo de recompute a cada ciclo de 8min, o que é exatamente o que `computeCached()` existe para evitar. **Decisão de trade-off que o plano precisa registrar explicitamente**, não um bug a corrigir.

### Pitfall 2: Quebrar byte-equivalência por acidente via `$empresasScore` afetando estado compartilhado

**O que dá errado:** `CompanyScoreService::computeEmpresasScore()` chama internamente `MetricDiffDispatcher::compute()` e `NpsPorEmpresaService::notasNpsPorEmpresa()` — SE esses serviços tiverem qualquer cache in-memory ou efeito colateral compartilhado com os métodos que `compute()` legado também chama (`computeVarFaturamento`/`computeVarMargem`/`computeNpsWindow`), rodar o shadow ANTES dos componentes legados poderia, em teoria, alterar o resultado dos componentes legados (ex.: um cache de request memoizado incorretamente).

**Como evitar:** verificado nos dois serviços (Fase 118/119) que ambos são *stateless* por chamada (sem propriedades de instância mutáveis entre chamadas, exceto o `$faixasCache` de `DesempenhoScoreService` que não é tocado pelo shadow). Ainda assim, o plano deve incluir um teste explícito que rode `compute()` com `incluirEmpresasScore: true` e compare `componentes.var_faturamento_pct`/`var_margem_pct`/`nps_medio` byte a byte contra uma chamada com `incluirEmpresasScore: false` na MESMA fixture — prova direta de que ligar o shadow não muda o caminho legado.

### Pitfall 3: `computeScoreStatus()` legado precisa continuar recebendo os MESMOS argumentos

**O que dá errado:** é tentador "limpar" `computeScoreStatus(array $contadores, ?float $varFat, ?float $margemPontos): string` para aceitar `$empresasScore` também, unificando as duas versões numa função só com um parâmetro extra opcional. Isso parece inofensivo, mas qualquer mudança de assinatura ou de corpo do método legado quebra a garantia "arquivo byte-a-byte intocado no caminho legado" que sustenta a prova de regressão zero.

**Como evitar:** criar um método NOVO e SEPARADO (ex.: `computeScoreStatusPorEmpresa(Collection $empresasScore): string`) em vez de estender o existente. O `if` de bifurcação escolhe qual chamar — nunca uma função híbrida.

### Pitfall 4: `shapeSemCarteira()` não tem as chaves que `compute()` normal tem

**O que dá errado:** `shapeSemCarteira()` (linha 1448-1486) retorna um array MENOR que o de `compute()` normal — falta `margem_amostra`, `periodo_meta`, `periodo`, `bonus` (essas chaves já são ausentes hoje quando `sem_carteira=true`, comportamento pré-existente). Se o plano adicionar `empresas_score`/`componentes.var_margem_pp` só no shape normal e esquecer o shape `sem_carteira`, qualquer consumidor que espere a chave presente em AMBOS os caminhos (ex.: Fase 123 futura) vai encontrar `undefined`.

**Como evitar:** adicionar `'empresas_score' => []` e `'componentes' => [..., 'var_margem_pp' => null]` também em `shapeSemCarteira()`, mantendo simetria com o shape normal.

## Code Examples

### Q1 — Onde exatamente a bifurcação entra em `compute()`

Fluxo atual completo (linhas 394-589), com os pontos de inserção marcados:

```php
public function compute(User $user, Carbon $mesReferencia, ?array $periodoOverride = null): array
{
    $mes     = $mesReferencia->copy()->startOfMonth();          // 396
    $periodo = $periodoOverride ?? $this->resolvePeriodo($mes); // 397

    $universo = $this->computeUniverso($user, $mes);            // 400
    if ($universo['sem_carteira']) {
        return $this->shapeSemCarteira($user, $mes, $universo['motivo']); // 403 — Pitfall 4 aqui
    }

    $companies  = $universo['companies_elegiveis'];             // 407
    $contadores = $universo['contadores'];                      // 408
    $fontes     = $universo['fontes'];                          // 409

    $invalidadas = BonusInvalidacao::companyIdsInvalidadas($mes); // 416
    if ($invalidadas->isNotEmpty()) {                             // 417
        $companies = $companies->reject(fn ($c) => $invalidadas->contains($c->id))->values();
    }
    // ────────────────────────────────────────────────────────────────
    // ★ PONTO DE INSERÇÃO 1 (shadow) — logo aqui, linha ~421.
    //   $invalidadas, $periodo e $mes já estão prontos — exatamente os
    //   insumos que CompanyScoreService::computeEmpresasScore() exige.
    // ────────────────────────────────────────────────────────────────

    $nps           = $this->computeNpsWindow($user, $mes, $periodo['is_closed'], $invalidadas); // 436
    $varFatData    = $this->computeVarFaturamento($user, $mes, $companies, $periodo, $fontes);   // 437
    $varMargemData = $this->computeVarMargem($user, $mes, $companies, $periodo, $fontes);        // 438
    $absent        = $this->computeAbsenteismo($user, $mes);                                    // 439

    $varFat = $varFatData['pct']; $empresasBaseline = $varFatData['empresas_com_baseline'];
    $varMargem = $varMargemData['pct']; $nComMargemReal = $varMargemData['n_com_margem_real'];
    $nElegivelAdman = $varMargemData['n_elegivel'];

    $nShopeePlaceholder = $companies->filter(fn ($c) => ($fontes[$c->id] ?? null) === 'shopee')->count(); // 456-458
    $margemPontos = $this->margemPontos($varMargem, $nComMargemReal, $nShopeePlaceholder);                // 459

    // ────────────────────────────────────────────────────────────────
    // ★ PONTO DE INSERÇÃO 2 (bifurcação real) — linhas 462/465 de hoje:
    // ────────────────────────────────────────────────────────────────
    $nota        = $this->computeNotaFinal($nps, $varFat, $margemPontos);         // 462 — INTOCADO no ramo flag=false
    $scoreStatus = $this->computeScoreStatus($contadores, $varFat, $margemPontos); // 465 — INTOCADO no ramo flag=false

    if ($scoreStatus === 'blocked') { $nota = null; }   // 472 — D-91-01, preservado

    // ... classificação de faixa, promoção 2 meses, metadados de período ... (476-492)

    return [ /* ★ PONTO DE INSERÇÃO 3 — array de retorno, ver Q5 */ ];
}
```

### Q2 — Padrão de `config/metrics.php` (`unified_metrics_enabled`)

```php
// config/metrics.php — arquivo INTEIRO já lido; chave existente:
'unified_metrics_enabled' => filter_var(
    env('UNIFIED_METRICS_ENABLED', false),
    FILTER_VALIDATE_BOOLEAN
),
```

Regras do padrão (a flag nova DEVE seguir exatamente):
1. Chave em `snake_case`, valor booleano, default `false` (via `env(..., false)`).
2. Casting via `filter_var(..., FILTER_VALIDATE_BOOLEAN)` — aceita `'true'`/`'1'`/`'on'` (case-insensitive), rejeita `'yes'`/`'sim'` (defesa contra tampering, comentário do arquivo cita "T-61-01-01 do threat model").
3. Docblock no topo do arquivo explicando o rollout (referência a fase/ADR, comportamento default vs ativado).
4. **Consumidores leem via `config('metrics.NOME_DA_FLAG')` — NUNCA `env()` direto em runtime** (comentário explícito no arquivo: `config:cache` invalida `env()` fora de config files).

Recomendação direta: adicionar em `config/metrics.php`:
```php
'performance_company_first_score' => filter_var(
    env('PERFORMANCE_COMPANY_FIRST_SCORE', false),
    FILTER_VALIDATE_BOOLEAN
),
```
com docblock citando a Fase 120, o gate MPP-04 e a Fase 121 como pré-requisitos para ativação em produção.

### Q3 — Contrato de `CompanyScoreService::computeEmpresasScore()`

```php
// app/Services/Desempenho/CompanyScoreService.php:120
public function computeEmpresasScore(
    User $user,
    Carbon $mes,
    array $periodo,                    // shape do MetricPeriodResolver — MESMO $periodo já resolvido em compute()
    ?Collection $invalidadas = null,   // resolve via BonusInvalidacao::companyIdsInvalidadas($mes) se null
): Collection
```

Devolve `Collection<int, object>` chaveada por `company_id`, com cada linha contendo (docblock linhas 108-118):
```
company_id, company_name, fonte_financeira,
nps_pontos,
faturamento_atual, faturamento_anterior, faturamento_var_pct, faturamento_pontos,
margem_pct_atual, margem_pct_anterior, margem_var_pp, margem_pontos,
componentes_presentes: int,
nota_empresa: ?float,          // ESTRITA — null se faltar qualquer 1 dos 3 componentes
nota_empresa_parcial: ?float,  // média dos componentes PRESENTES
status: string,                // 'complete'|'partial'|'sem_fonte'|'sem_dados'
quality: { revenue_diff_source, margin_diff_source, margin_source, motivos: array<string> }
```

**`$mesFechado` NÃO é parâmetro** — deriva de `(bool) ($periodo['is_closed'] ?? false)` (linha 128), o MESMO sinal que `compute()` já usa para `computeNpsWindow()`. `$invalidadas` resolve para `BonusInvalidacao::companyIdsInvalidadas($mes)` se `null` — **nunca** `collect()` vazio (silenciaria a invalidação).

### Q4 — `computeScoreStatus()` hoje vs. versão por cobertura

```php
// DesempenhoScoreService.php:691 — assinatura e regras ATUAIS
private function computeScoreStatus(array $contadores, ?float $varFat, ?float $margemPontos): string
{
    if ($contadores['vinculos_financeiros'] === 0) return 'blocked'; // zero vínculos financeiros elegíveis
    if ($varFat === null || $margemPontos === null) return 'partial'; // algum componente financeiro indisponível
    return 'official';
}
```

Versão nova (recomendação de assinatura, baseada em D-01/D-02/D-03 — NÃO reescrever a função acima, criar uma nova):
```php
private function computeScoreStatusPorEmpresa(Collection $empresasScore): string
{
    // D-01: 'blocked' — NENHUMA empresa com nota alguma (nem parcial).
    $comAlgumaNota = $empresasScore->filter(fn ($e) => $e->nota_empresa_parcial !== null);
    if ($comAlgumaNota->isEmpty()) {
        return 'blocked';
    }

    // D-02/D-03: cobertura = fração de empresas 'complete' sobre o total do universo.
    $totalEmpresas = $empresasScore->count();
    $completas     = $empresasScore->where('status', 'complete')->count();
    $cobertura     = $totalEmpresas > 0 ? $completas / $totalEmpresas : 0.0;

    return $cobertura >= self::COBERTURA_MINIMA_SCORE_STATUS ? 'official' : 'partial'; // 0.7, D-03
}
```

Diferença essencial: a versão atual olha 2 sinais agregados da carteira inteira (`$varFat`/`$margemPontos`, cada um só/nulo ou presente). A versão nova olha a DISTRIBUIÇÃO de status por empresa — é uma métrica de cobertura, não de presença/ausência binária. Isso é o que resolve a "DECISÃO EM ABERTO" do `REQUIREMENTS-v21.md` (linha 39-43): em vez de "qualquer empresa sem baseline derruba todo mundo pra `partial`", agora é "cobertura abaixo de 70% derruba pra `partial`" — o placeholder Shopee (que sempre conta como `complete`, Fase 109/119 D-02) garante que profissional só-Shopee tenha cobertura 100% e permaneça `official` (AGRE-06), satisfazendo `DESEMP-06`.

### Q5 — Payload COMPLETO de `compute()` hoje (linhas 494-588 + `shapeSemCarteira()` 1450-1486)

```php
return [
    'user_id'               => $user->id,                          // LEGADO
    'user_name'             => $user->name,                        // LEGADO
    'mes_referencia'        => $mes->toDateString(),                // LEGADO
    'sem_carteira'          => false,                               // LEGADO
    'motivo'                => null,                                // LEGADO
    'empresas_carteira'     => $contadores['empresas_unicas'],      // LEGADO
    'empresas_com_baseline' => $empresasBaseline,                   // LEGADO
    'margem_amostra' => [                                           // LEGADO (alimenta gate FIXMARG-03)
        'n_real'     => $nComMargemReal,
        'n_elegivel' => $nElegivelAdman,
        'cobertura'  => $nElegivelAdman > 0 ? round($nComMargemReal / $nElegivelAdman, 4) : 1.0,
    ],
    'componentes' => [                                              // LEGADO — TODOS os 4 sub-campos
        'nps_medio'           => $nps,
        'var_faturamento_pct' => $varFat,
        'var_margem_pct'      => $varMargem,
        'absenteismo_pct'     => $absent,
        // + 'var_margem_pp'  => NOVO (AGRE-04) — ver Open Question abaixo
    ],
    'pontos_componentes' => [                                       // LEGADO
        'nps'         => $nps !== null ? max(1.0, min(5.0, $nps)) : null,
        'faturamento' => $this->reguaFaturamento($varFat),
        'margem'      => $margemPontos,
    ],
    'nota_final'      => $nota,                                     // LEGADO (bifurca por flag, tipo/formato ?float preservado)
    'faixa_bonus'     => $faixaFinal,                                // LEGADO
    'faixa_promovida' => $faixaPromovida,                            // LEGADO
    'periodo_meta' => [                                              // LEGADO
        'em_curso' => $ehMesEmCurso, 'dias_decorridos' => $diasDecorridos, 'dias_no_mes' => $diasNoMes,
    ],
    'periodo' => [                                                   // LEGADO
        'current_start' => ..., 'current_end' => ..., 'baseline_start' => ...,
        'baseline_end' => ..., 'mode' => ..., 'comparison_mode' => ...,
    ],
    'bonus' => [                                                     // LEGADO
        'competence_month' => ..., 'payment_month' => ...,
    ],
    'empresas_unicas'               => $contadores['empresas_unicas'],           // LEGADO
    'vinculos_servico'              => $contadores['vinculos_servico'],          // LEGADO
    'vinculos_financeiros'          => $contadores['vinculos_financeiros'],     // LEGADO
    'vinculos_sem_fonte_financeira' => $contadores['vinculos_sem_fonte_financeira'], // LEGADO
    'score_status'                  => $scoreStatus,                            // LEGADO (bifurca por flag, tipo string preservado)
    'componentes_disponiveis' => [                                              // LEGADO
        'nps_medio' => $nps !== null, 'var_faturamento_pct' => $varFat !== null, 'var_margem_pct' => $varMargem !== null,
    ],
    // ★ NOVO (AGRE-04):
    'empresas_score' => $empresasScore?->values()->all() ?? [],
];
```

**Nenhuma chave legada pode sumir ou mudar de TIPO** — AGRE-04 é explícito: `empresas_carteira`, `empresas_com_baseline`, `margem_amostra`, `componentes_disponiveis`, `score_status`, `faixa_bonus`, `faixa_promovida`, `componentes.var_margem_pct` continuam existindo com o MESMO shape. Apenas `empresas_score` (novo, no nível raiz) e `componentes.var_margem_pp` (novo, dentro de `componentes`) são adições.

`shapeSemCarteira()` (linha 1450) precisa da MESMA adição simétrica (Pitfall 4) — hoje esse shape já é menor (falta `margem_amostra`/`periodo_meta`/`periodo`/`bonus`), então `empresas_score => []` e `componentes.var_margem_pp => null` bastam ali.

### Q6 — TODAS as ocorrências da string de cache com versão hardcoded

Confirmado por grep em todo o repositório (`app/` + `tests/`) pelo padrão literal `desempenho.compute.v12`:

| Arquivo | Linha | Contexto |
|---------|-------|----------|
| `app/Services/DesempenhoScoreService.php` | 335 | **Definição canônica** — `sprintf('desempenho.compute.v12.%d.%s', ...)` dentro de `cacheKey()` |
| `tests/Feature/DesempenhoShopeeScoreTest.php` | 363 | `assertSame('desempenho.compute.v12.' . $user->id . '.current_month', $chave)` |
| `tests/Feature/Phase116/NpsFloorDesempenhoTest.php` | 388 | `assertStringStartsWith('desempenho.compute.v12.', $chave, ...)` |
| `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` | 246, 348 | `sprintf('desempenho.compute.v12.%d.%s', ...)` (2 ocorrências no mesmo arquivo) |
| `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` | 232, 258, 260, 277 | 4 ocorrências (`assertSame`/`sprintf` de comparação) |

**Confirma exatamente as 4 suítes citadas no CONTEXT** (`DesempenhoShopeeScoreTest`, `Phase116/NpsFloorDesempenhoTest`, `Phase96/NpsInvalidacaoRespostaTest`, `V18/DesempenhoMetadadosCacheTest`) — lista **completa**, nenhuma ocorrência adicional encontrada. Outros arquivos que usam `cacheKey()` (`Phase106/WarmDesempenhoCacheTest.php`, `Phase106/PerformanceControllerWarmDegradationTest.php`, `V16/DesempenhoElegibilidadeTest.php`, `V16/BonusDualPathRegressaoTest.php`, `tests/Unit/DesempenhoScoreServiceCacheTest.php`, `NpsMaterializarNaoRespondidosCommandTest.php`) chamam o **helper público** `$service->cacheKey(...)` em vez de hardcodar a string — esses NÃO precisam de alteração (o helper já devolve a versão correta automaticamente após o bump).

### Q7 — Onde o shadow deve/não deve rodar

**Chamadas de `compute()`/`computeCached()` em `app/`:** `ConsolidarMesDesempenho.php:139` (`compute()` DIRETO — sem cache), `WarmDesempenhoCache.php:122` (`computeCached()`), `PerformanceController.php:194,200,422,1255` (`computeCached()`), `PortfolioController.php:2106,2132` (`computeCached()`), `DashboardController.php:1103` (`computeCached()`), mais `NpsController.php`/`NpsMaterializarNaoRespondidos.php`/`NpsBackfillAssignmentsConsolidado.php`/`BonusAuditoriaController.php` (só `cacheKey()` para `Cache::forget()`, não chamam `compute()`).

**Nos comandos (shadow DEVE rodar):**
- `desempenho:consolidar-mes` → `ConsolidarMesDesempenho::handle()` linha 139 chama `$this->scoreService->compute($user, $mes)` **direto**, sem `Cache::remember` — o comando persiste `breakdown_json => $result` inteiro (linha 218). Aqui basta passar `incluirEmpresasScore: true` para o shadow rodar **garantidamente** toda vez.
- `desempenho:warm-cache` → `WarmDesempenhoCache::handle()` linha 122 chama `$this->scoreService->computeCached($user, $mesReferencia)` — precisa que `computeCached()` também aceite (e propague) o parâmetro novo. Ver Pitfall 1 para a ressalva sobre `Cache::remember` já ter a chave preenchida.
- `desempenho:comparar-score-empresa` (Fase 121, ainda não existe) — deve chamar com `incluirEmpresasScore: true` também.

**Na leitura interativa (shadow NUNCA deve rodar):**
- `PerformanceController` (linhas 194, 200, 422, 1255) e `PortfolioController` (2106, 2132) e `DashboardController` (1103) chamam `computeCached($user, $mesReferencia)` com **2 argumentos apenas** — com o novo parâmetro tendo default `false`, essas chamadas continuam exatamente como estão hoje (zero edição necessária nesses controllers) e o shadow permanece desligado ali.

### Q8 — Os 7 invariantes de `DesempenhoShopeeScoreTest`

| # | Teste | Assunto | Depende de `margemPontos()`? |
|---|-------|---------|------------------------------|
| 1 | `test_faturamento_inclui_revenue_shopee_via_dispatcher` | Faturamento Shopee entra via `MetricDiffDispatcher`; assert em `var_faturamento_pct` e `empresas_com_baseline` | **Não** — não toca `pontos_componentes.margem` nem `margemPontos()` |
| 2 | `test_empresa_com_performance_e_shopee_resolve_fonte_adman_desempate` | Regra de desempate Adman-vence-Shopee; assert em `var_faturamento_pct`, `empresas_unicas`, `vinculos_servico`, `vinculos_financeiros` | **Não** |
| 3 | `test_so_performance_regressao_zero_margem_pontos_e_nota_identicos_ao_baseline` | Assert **direto** em `pontos_componentes.margem == reguaMargem(2.80%) == 4.0` e `nota_final == 3.00` | **Sim** — este é LITERALMENTE o invariante documentado no docblock de `margemPontos()` linha 1335-1337 que "não vale no caminho novo" |
| 4 | `test_so_shopee_official_nota_final_nao_null_margem_placeholder_1` | Assert em `pontos_componentes.margem == 1.0` (placeholder puro) | **Sim** — testa o ramo `$nComMargemReal=0` do blend |
| 5 | `test_misto_ml_shopee_margem_pontos_blend_ponderado` | Assert em `pontos_componentes.margem == 2.50` (blend ponderado por contagem) | **Sim** — testa o ramo "misto" do blend explicitamente |
| 6 | `test_invalidacao_empresa_shopee_nao_infla_denominador_do_blend` | Assert em `pontos_componentes.margem == 2.50` pós-invalidação | **Sim** — testa que `$nShopeePlaceholder` pós-filtro alimenta o blend corretamente |
| 7 | `test_cache_key_bumpado_para_v12` | Assert em `cacheKey()` == string literal | **Não** — mas QUEBRA de qualquer forma com o bump v12→v13 (AGRE-03), independente de `margemPontos()` |

**Conclusão para D-05:** os testes 3, 4, 5 e 6 exercitam diretamente o comportamento de `margemPontos()` (a fórmula de blend por contagem) via `pontos_componentes.margem`. Como o caminho novo (flag ligada) não chama `margemPontos()` — ele deriva `margem_pontos` por empresa dentro de `CompanyScoreService` e agrega por MÉDIA das notas de empresa, não por blend ponderado por contagem — **esses 4 testes continuam válidos apenas para o caminho LEGADO (flag desligada)**. Eles devem permanecer intactos (documentando a semântica antiga) e ganhar cenários espelho equivalentes que chamem `compute()` com a flag ligada (via `config(['metrics.performance_company_first_score' => true])` no teste) e assertem o resultado ESPERADO do caminho novo para os MESMOS cenários — inclusive onde a divergência é numérica e intencional (ex.: cenário "misto" pode dar valor diferente de 2.50 dependendo de como `nota_empresa`/`status` por empresa entram na média nova, já que o modelo novo tem denominador diferente do blend por contagem). Os testes 1, 2 e 7 não dependem de `margemPontos()` — 1 e 2 continuam válidos tal como estão (a resolução de fonte financeira é a MESMA lógica reusada por `CompanyScoreService`); 7 precisa só do literal atualizado para v13.

### Q9 — Riscos e armadilhas (consolidado)

1. **Perda do gate de hash como rede de proteção.** Fases 117-119 pegaram "vários erros reais" (citação do CONTEXT) só por comparar `sha256sum` do arquivo antes/depois de cada task. A partir desta fase isso não é mais possível — a compensação recomendada é: (a) rodar a suíte completa `DesempenhoShopeeScoreTest` + `Phase96/NpsInvalidacaoRespostaTest` + `Phase116/NpsFloorDesempenhoTest` + `V18/DesempenhoMetadadosCacheTest` + `--filter=Desempenho` (regressão ampla, baseline conhecida de 14 falhas pré-existentes) a CADA task, não só ao final; (b) um teste NOVO e explícito de "diff estrutural" que roda `compute()` com a flag desligada em 2-3 fixtures ricas (múltiplas empresas, Shopee+Adman misto, empresa invalidada) e compara CHAVE POR CHAVE contra um snapshot JSON capturado ANTES de qualquer edição (Wave 0) — ver Validation Architecture abaixo.
2. **Risco da flag `false` não ser byte-equivalente ao comportamento atual.** O ponto de maior risco é o parâmetro de shadow contaminar (por acidente) os componentes legados — ver Pitfall 2. Mitigação: nenhum dos métodos legados (`computeNotaFinal`, `computeScoreStatus`, `computeVarFaturamento`, `computeVarMargem`, `computeNpsWindow`, `margemPontos`) deve receber `$empresasScore` como argumento, nem ler `config('metrics.performance_company_first_score')` internamente — só o método `compute()` (orquestrador) lê a flag, uma única vez, para decidir qual par de funções chamar.
3. **Custo de performance se o shadow vazar para leitura de tela.** D-04 já documenta o problema (dashboard de 70s vira potencialmente 140s). A mitigação arquitetural é o parâmetro `incluirEmpresasScore` com default `false` — o risco residual é um desenvolvedor futuro (ou a própria Fase 121) esquecer de manter o default e acidentalmente ativar em um controller. Recomenda-se um teste de regressão que garanta que `PerformanceController`/`PortfolioController`/`DashboardController` continuam chamando `computeCached()` com EXATAMENTE 2 argumentos posicionais (ou nomeados sem o novo parâmetro) — um grep-based ou reflection-based assertion é aceitável.
4. **Interação do bump de cache com o warm agendado.** O bump v12→v13 invalida TODAS as chaves antigas (ficam órfãs, expiram por TTL — padrão já documentado nos comentários de `cacheKey()` para os bumps v7-v12). Como `desempenho:warm-cache` roda a cada 8 minutos, o primeiro ciclo após o deploy vai recomputar tudo do zero (cold miss geral) — o mesmo comportamento já observado nos bumps anteriores, sem necessidade de `cache:clear` manual. **Atenção:** o bump precisa acontecer ATOMICAMENTE com a adição das chaves novas no payload — se o código for deployado com o payload novo mas SEM o bump de versão, consumidores podem receber (do Redis, por até 7 dias em mês fechado) uma mistura de payload ANTIGO (sem `empresas_score`) servido sob a MESMA chave que o código novo estaria escrevendo, criando inconsistência de shape entre requests.
5. **`shapeSemCarteira()` fora de simetria (Pitfall 4).** Fácil de esquecer porque é um caminho de retorno antecipado (linha 403), fisicamente distante do resto do método.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|---------------|--------|
| Régua aplicada UMA VEZ sobre a média agregada da carteira (`margemPontos()`, blend por contagem) | Régua aplicada POR EMPRESA, média das notas por empresa (`CompanyScoreService`, atrás de flag) | Fase 120 (esta fase), consumo ainda não ativado em produção | Muda a nota de quem tem múltiplas empresas com margem real divergente — "régua-da-média ≠ média-das-réguas" (risco herdado da Fase 119) |
| `score_status` binário por presença/ausência de componente agregado | `score_status` por cobertura de empresas `complete` (patamar 70%) | Fase 120 (flag), D-02/D-03 | Resolve a "decisão em aberto" do `REQUIREMENTS-v21.md` — evita que 1 empresa sem baseline derrube todo o profissional para `partial` |

**Nada foi deprecado nesta fase** — `margemPontos()`, `reguaFaturamento()`, `reguaMargem()`, `computeVarFaturamento()`, `computeVarMargem()` permanecem 100% vivos e intocados (caminho da flag desligada). A aposentadoria é explicitamente diferida (`<deferred>` do CONTEXT).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `componentes.var_margem_pp` (agregado, nível-profissional) deve vir da MÉDIA dos `margem_var_pp` por empresa em `$empresasScore` (só disponível quando o shadow rodou) — o requirement AGRE-04 não especifica a fonte exata desse campo agregado | "Código Q5" / Open Questions | Se a fonte pretendida for outra (ex.: um novo cálculo agregado direto do `AdmanMetricDiffService`, independente do shadow por empresa), o campo pode ficar `null` em cenários onde o requirement esperava um valor — só afeta o campo NOVO, não quebra byte-equivalência das chaves legadas |
| A2 | O parâmetro de shadow (`incluirEmpresasScore` ou nome equivalente) deve ser adicionado tanto a `compute()` quanto a `computeCached()`, propagado por posição/nome — não existe uma constante de contexto de "sou um comando" mais elegante já disponível no serviço | Padrão 1 / Q1 | Nomenclatura exata é decisão do planner/executor; o risco é baixo (é só naming), mas se o planner escolher inferir o contexto de outra forma (ex.: verificar `app()->runningInConsole()`), isso teria efeito colateral em QUALQUER comando/tinker que rode `compute()`, não só os 2-3 comandos pretendidos — recomendo o parâmetro explícito em vez de inferência de ambiente |

**Nenhuma outra claim deste research depende de conhecimento não verificado no código** — todas as demais afirmações citam linha exata do arquivo lido nesta sessão.

## Open Questions (RESOLVED)

> Ambas resolvidas no `120-CONTEXT.md`: **Q1** (`var_margem_pp` quando o shadow não roda) pela **C-03** — devolve `null`, sem inventar agregado nem reaproveitar `var_margem_pct`. **Q2** (forçar `Cache::forget()` no warm) pela **C-02** — guard condicional que só recomputa quando falta `empresas_score` no payload cacheado, mais forte que o "best-effort" que esta pesquisa recomendara e sem o custo de ~70s a cada 8 min.

1. **De onde vem exatamente `componentes.var_margem_pp` (agregado) quando o shadow NÃO rodou (ex.: leitura interativa com flag desligada)?**
   - O que sabemos: `CompanyScoreService` já expõe `margem_var_pp` POR EMPRESA. Não existe hoje nenhum cálculo agregado de `diff_pp` a nível de carteira (o equivalente de `computeVarMargem()` mas para pp em vez de pct).
   - O que é incerto: se AGRE-04 espera que esse campo sempre exista (mesmo com a flag desligada e o shadow não rodando na leitura interativa) ou se é aceitável que fique `null` fora dos comandos.
   - Recomendação: tratar como `null` quando `$empresasScore === null` (shadow não rodou) — coerente com o padrão já usado para `var_margem_pct` (`null` quando indisponível). Confirmar com o usuário/discuss-phase se isso satisfaz a intenção de AGRE-04, já que o campo existirá "de graça" nos comandos mas não na tela.

2. **`desempenho:warm-cache` deve forçar `Cache::forget()` antes de recompute quando `incluirEmpresasScore=true`, ou aceitar o gap documentado no Pitfall 1?**
   - O que sabemos: `desempenho:consolidar-mes` (que persiste `breakdown_json`, o registro canônico) SEMPRE roda o shadow com garantia, pois chama `compute()` direto sem cache.
   - O que é incerto: se a Fase 121 (comparador antigo×novo) depende de `desempenho:warm-cache` também ter cobertura garantida do shadow, ou se ela vai rodar seu próprio `compute(..., incluirEmpresasScore: true)` independente do cache.
   - Recomendação: deixar como best-effort no warm-cache nesta fase (documentar a limitação) e revisar quando a Fase 121 especificar exatamente de onde ela lê os dados de comparação.

## Environment Availability

Não se aplica — esta fase não introduz nenhuma dependência externa nova (nenhum serviço, CLI, runtime, banco de dados). Toda a infraestrutura necessária (PHP 8.2+, MySQL/SQLite, Redis/array cache, PHPUnit) já está em uso pelas Fases 117-119 anteriores desta mesma milestone.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (raiz do projeto) — `CACHE_STORE=array`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` em testes |
| Quick run command | `C:\xampp\php\php.exe artisan test --filter=Phase120` |
| Full suite command (regressão do domínio) | `C:\xampp\php\php.exe artisan test --filter=Desempenho` (baseline conhecida: **14 falhas pré-existentes**, ver `.planning/debug/resolved/audit-margem-baseline-negativo.md` e correlatos — zero falhas NOVAS é o critério de aceite) |

**Nunca rodar `artisan test` sem `--filter`** (regra do ambiente desta fase).

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AGRE-01 | Flag ligada → `nota_final` = média das `nota_empresa` das empresas `complete` | Feature | `php artisan test --filter=Phase120AgregacaoTest` | ❌ Wave 0 |
| AGRE-02 | Flag controla a troca; shadow calcula nos dois modos, mas nunca na leitura interativa | Feature | `php artisan test --filter=Phase120ShadowTest` | ❌ Wave 0 |
| AGRE-03 | `cacheKey()` v12→v13; 4 suítes atualizadas | Feature (regressão) | `php artisan test --filter="DesempenhoShopeeScoreTest\|NpsFloorDesempenhoTest\|NpsInvalidacaoRespostaTest\|DesempenhoMetadadosCacheTest"` | ✅ (4 arquivos existentes, precisam de 1 edição literal cada) |
| AGRE-04 | Payload expõe `empresas_score`/`componentes.var_margem_pp`, preserva chaves legadas | Feature | `php artisan test --filter=Phase120PayloadContratoTest` | ❌ Wave 0 |
| AGRE-05 | Empresa sem baseline exclui do denominador (D-01), sem contradizer DESEMP-06/Fase 109 | Feature | `php artisan test --filter=Phase120DenominadorTest` | ❌ Wave 0 |
| AGRE-06 | `score_status` coerente — só-Shopee continua `official` | Feature | `php artisan test --filter=Phase120ScoreStatusTest` | ❌ Wave 0 (cenário espelho de `test_so_shopee_official_nota_final_nao_null_margem_placeholder_1`) |
| **Byte-equivalência flag-off** | `compute()` com flag desligada produz saída IDÊNTICA à de hoje (exceto pelas 2 chaves novas aditivas) | Feature (regressão dura) | `php artisan test --filter=DesempenhoShopeeScoreTest` (7 testes, só cacheKey literal muda) + `php artisan test --filter=Desempenho` (regressão ampla) | ✅ (arquivo existente — a PROVA é que ele continua passando sem alteração de asserções, só o literal de cache) |

### Sampling Rate
- **Per task commit:** `C:\xampp\php\php.exe artisan test --filter=Phase120` + o(s) arquivo(s) específico(s) tocados na task (ex.: `--filter=DesempenhoShopeeScoreTest` após editar `DesempenhoScoreService.php`)
- **Per wave merge:** `C:\xampp\php\php.exe artisan test --filter=Desempenho` (regressão ampla — comparar contagem de falhas contra a baseline de 14)
- **Phase gate:** suíte completa de `--filter=Desempenho` + `--filter=Phase120` verde (14 falhas baseline, zero novas) antes de `/gsd:verify-work`

### Como provar a byte-equivalência do caminho flag-desligada (requisito do nyquist para esta fase)

A prova concreta, em ordem de força:

1. **Prova por teste literal existente (mais forte, já disponível):** os 4 testes de `DesempenhoShopeeScoreTest` que assertam valores NUMÉRICOS EXATOS vindos de `margemPontos()`/`computeNotaFinal()`/`computeScoreStatus()` (testes 3, 4, 5, 6 da tabela do Q8) — `nota_final=3.00`, `pontos_componentes.margem=1.0`/`2.50`, `score_status='official'` — DEVEM continuar passando **sem qualquer alteração de asserção**, só o literal de `cacheKey()` (v12→v13) muda. Se qualquer um desses 4 testes precisar de um valor esperado diferente após a mudança, a byte-equivalência do caminho flag-desligada foi quebrada — isto é o gate de aceite mais direto que existe.
2. **Prova por golden snapshot (Wave 0, antes de tocar `DesempenhoScoreService.php`):** capturar a saída JSON completa de `compute()` para 2-3 fixtures ricas (múltiplas empresas Adman+Shopee, 1 empresa invalidada, 1 sem baseline) ANTES de qualquer edição, salvar como fixture de teste. Depois da mudança (com a flag desligada), rodar `compute()` nas MESMAS fixtures e comparar chave por chave — as chaves legadas devem bater 100%; só `empresas_score` (novo) e `componentes.var_margem_pp` (novo) podem divergir de "ausente" para "presente".
3. **Prova de isolamento do shadow (Pitfall 2):** um teste que roda `compute($user, $mes, null, incluirEmpresasScore: true)` e `compute($user, $mes, null, incluirEmpresasScore: false)` na MESMA fixture e assert que `componentes.nps_medio`, `componentes.var_faturamento_pct`, `componentes.var_margem_pct`, `nota_final`, `score_status` são **idênticos** nos dois casos (só `empresas_score` difere entre eles — presente vs `[]`/`null`).
4. **Regressão ampla:** `--filter=Desempenho` mantendo exatamente 14 falhas pré-existentes (nem mais, nem menos) — qualquer NOVA falha nesse filtro após a mudança é sinal de regressão introduzida por esta fase.

### Wave 0 Gaps
- [ ] `tests/Feature/Phase120/` (diretório novo) — suítes para AGRE-01, AGRE-02, AGRE-04, AGRE-05, AGRE-06 (cenários novos, flag ligada)
- [ ] Golden snapshot fixture (JSON) de `compute()` ANTES da edição — insumo da prova de byte-equivalência (item 2 acima)
- [ ] Cenários espelho em `DesempenhoShopeeScoreTest.php` (D-05) para os 4 testes que dependem de `margemPontos()` (testes 3, 4, 5, 6 do Q8), cobrindo o MESMO cenário com a flag ligada
- [ ] Edição literal (v12→v13) nos 4 arquivos listados no Q6 — sem isso a suíte quebra assim que `cacheKey()` for editado

## Security Domain

`security_enforcement` ausente em `.planning/config.json` → tratado como habilitado. Esta fase, no entanto, não introduz nenhuma superfície nova de input de usuário, endpoint HTTP, ou fluxo de autenticação/sessão — é uma mudança interna de cálculo de negócio consumida pelos mesmos controllers/commands já existentes e protegidos.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | Não | Nenhuma mudança de autenticação |
| V3 Session Management | Não | Nenhuma mudança de sessão |
| V4 Access Control | Não | `PerformanceController`/`PortfolioController`/`DashboardController` já aplicam os controles de acesso existentes; esta fase não adiciona rota/endpoint novo |
| V5 Input Validation | Sim (parcial) | O ÚNICO input novo é a leitura de `env('PERFORMANCE_COMPANY_FIRST_SCORE')` — mitigado pelo mesmo padrão `filter_var(..., FILTER_VALIDATE_BOOLEAN)` já usado por `unified_metrics_enabled` (rejeita valores ambíguos) |
| V6 Cryptography | Não | Nenhuma criptografia envolvida |

### Known Threat Patterns for este domínio

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Tampering de env var da flag (ex.: valor ambíguo tentando forçar um estado inesperado) | Tampering | `filter_var(..., FILTER_VALIDATE_BOOLEAN)` — mesmo padrão já em produção para `unified_metrics_enabled`, rejeita `'yes'`/`'sim'`/valores não reconhecidos como `false` |
| Payload de cache servindo shape inconsistente entre versões (mistura de chaves antigas/novas sob a mesma chave) | Tampering/Information Disclosure (baixo risco, é dado interno de bônus) | Bump atômico de `cacheKey()` (v12→v13) simultâneo com a mudança de shape — ver Pitfall/Risco 4 |

## Sources

### Primary (HIGH confidence — lido diretamente do código-fonte nesta sessão)
- `app/Services/DesempenhoScoreService.php` (arquivo inteiro relevante: linhas 1-140, 220-395, 628-760, 1258-1387, 1448-1487)
- `app/Services/Desempenho/CompanyScoreService.php` (arquivo inteiro)
- `app/Services/Desempenho/NpsPorEmpresaService.php` (docblock de `notasNpsPorEmpresa()`, linhas 90-114)
- `config/metrics.php` (arquivo inteiro)
- `app/Console/Commands/ConsolidarMesDesempenho.php` (arquivo inteiro)
- `app/Console/Commands/WarmDesempenhoCache.php` (arquivo inteiro)
- `tests/Feature/DesempenhoShopeeScoreTest.php` (arquivo inteiro)
- `.planning/phases/120-.../120-CONTEXT.md`, `.planning/phases/119-.../119-CONTEXT.md`, `.planning/phases/119-.../119-04-SUMMARY.md`
- `.planning/REQUIREMENTS-v21.md`
- `phpunit.xml`, `.planning/config.json`
- Grep exaustivo por `desempenho.compute.v12` e por chamadas a `compute()`/`computeCached()` em `app/` e `tests/`

### Secondary (MEDIUM confidence)
- Nenhuma — toda a informação crítica foi verificada diretamente no repositório, sem depender de busca externa (fase 100% interna, sem bibliotecas novas).

### Tertiary (LOW confidence)
- Nenhuma.

## Metadata

**Confidence breakdown:**
- Standard stack: N/A — nenhuma biblioteca externa nesta fase
- Architecture: HIGH — todo o fluxo de `compute()`, `CompanyScoreService`, `config/metrics.php` e os 2 commands foi lido linha a linha
- Pitfalls: HIGH — a armadilha do `Cache::remember()` (Pitfall 1) foi derivada por leitura direta do código de `WarmDesempenhoCache`/`computeCached()`, não por suposição

**Research date:** 2026-07-29
**Valid until:** 2026-08-12 (30 dias — código interno estável, mas a Fase 121 pode revisar decisões desta fase logo em seguida)
