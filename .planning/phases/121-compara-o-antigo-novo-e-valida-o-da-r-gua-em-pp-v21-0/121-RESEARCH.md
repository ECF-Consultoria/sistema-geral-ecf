# Phase 121: Comparação antigo × novo e validação da régua em pp - Research

**Researched:** 2026-07-30
**Domain:** Comando Artisan de leitura/relatório sobre `DesempenhoScoreService`/`CompanyScoreService` (Laravel 12) — nenhuma mudança de cálculo
**Confidence:** HIGH (domínio 100% interno; toda evidência vem de leitura direta do código desta milestone, sem dependência de biblioteca nova)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01 · UMA chamada, os dois resultados.** `compute()` roda **uma única vez** com o shadow ligado e devolve no mesmo payload a `nota_final` legada e o `empresas_score`, do qual a nota nova é derivada. Razão: duas chamadas seriam duas leituras da Adman, e a mesma empresa já devolveu valores levemente diferentes entre leituras nesta milestone — o delta ficaria contaminado por ruído de API. Comparar contra o snapshot congelado também foi descartado (snapshot de junho congelado numa passada específica de 31/07).
- **D-02 · Decomposição por contribuição, isolando uma variável por vez.** O delta tem três fontes que se misturam: margem em pp × relativa, régua-por-empresa × régua-da-média, e empresas excluídas do denominador. O relatório calcula cada parcela isolando uma variável e nomeia a maior. ⚠️ As parcelas NÃO somam exatamente o delta total — os efeitos interagem. O relatório deve dizer isso explicitamente, não esconder o resíduo.
- **D-03 · Só empresas com `financial_metrics_eligible = true`, em três competências** — a fechada mais as duas anteriores. Razão: medir todas as empresas Adman mediria a Adman, não o bônus; uma competência só não distingue "a régua comprime" de "junho foi atípico". A pergunta que o histograma responde: 80%+ das empresas na faixa 3-4 nas três competências confirma a compressão e reabre a D2 da milestone.
- **D-04 · Sem limiar automático. O comando informa; o usuário decide.** Mudança de faixa de bônus é decisão de negócio. O comando precisa apresentar bem: delta por pessoa, quem muda de faixa e para qual, decomposição da causa por pessoa, e o histograma de pp. O SUMMARY registra a decisão do usuário e o número em que ela se baseou.

### Fronteira da fase (não é "decisão" travada, é escopo)

Esta fase **não muda cálculo nenhum**. Produz a evidência que decide se a flag da Fase 120 pode ser ligada. **NÃO está nesta fase:** ligar a flag, persistir por empresa (122), telas (123). **Não modifica `DesempenhoScoreService` nem `CompanyScoreService`.** Só lê.

### Claude's Discretion

- Formato exato de persistência do relatório (tabela(s) nova(s), shape das colunas, se existe modo `--relatorio` separado do modo de leitura).
- Como isolar programaticamente as 7 amostras de risco da ROLL-02.
- Se/como aplicar pacing entre chamadas à Adman.
- Nome exato de variáveis/classes internas do comando.

### Deferred Ideas (OUT OF SCOPE)

- **Ligar a flag** — depende do gate MPP-04 e da decisão desta fase.
- **Recalibrar a régua para pp** — se o histograma confirmar a compressão, vira pauta de diretoria; está no Out of Scope da milestone v21.0.
- **Persistir a comparação como série histórica** — Fase 122, se fizer sentido.

**Referência de sanidade já conhecida:** carteira do Luiz deu `~−0,59 pp`, que na régua reusada é nota 3 — contra régua 5 no snapshot congelado e régua 1 no cálculo local revertido.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| ROLL-01 | `php artisan desempenho:comparar-score-empresa --mes=YYYY-MM` produz, por profissional, `nota_antiga`, `nota_nova`, `delta`, contagem de empresas total/complete/partial e a maior causa do delta | Ver `## Standard Stack`, `## Code Examples` (assinatura de `compute()`, Reflection para os métodos novos privados) e `## Common Pitfalls` (custo/900 chamadas, TTL curto de `partial`) |
| ROLL-02 | Comparação roda sobre a última competência fechada; 7 amostras de risco conferidas manualmente | Ver `## Don't Hand-Roll` e a resposta à Pergunta 5 em `## Code Examples` — cada amostra tem um filtro determinístico sobre `empresas_score`, exceto "empresa invalidada", que é uma checagem de **ausência** |
| ROLL-03 | Distribuição real de `margem_var_pp` na carteira inteira medida e apresentada, confirmando/refutando a compressão da régua reusada (D2) | Ver `## Architecture Patterns` (onde vive `margem_var_pp`, dedupe por `company_id`) e `## Common Pitfalls` (as 2 competências extras não são pré-aquecidas pelo cron) |
</phase_requirements>

## Summary

O comando `desempenho:comparar-score-empresa` não precisa inventar nada novo no motor de cálculo — ele é um **consumidor de leitura** de três peças já prontas: `DesempenhoScoreService::compute($user, $mes, null, incluirEmpresasScore: true)` (uma única chamada, D-01), a coleção `empresas_score` que ela devolve (linhas de `CompanyScoreService::computeEmpresasScore()`, Fase 119/120), e dois métodos **privados** novos da Fase 120 (`computeNotaFinalPorEmpresa()`/`computeScoreStatusPorEmpresa()`) que already implementam a "nota nova"/"status novo" a partir dessa mesma coleção — sem exigir nenhuma segunda chamada à Adman.

A pergunta mais importante da pauta (Pergunta 2) tem resposta: **as três parcelas da decomposição D-02 são calculáveis com o payload de UMA ÚNICA chamada `compute()`**, mas uma delas — "margem em pp × relativa" — exige uma chamada **adicional, porém gratuita** ao `MetricDiffDispatcher::compute()` para o mesmo `(company, período)` já resolvido pelo shadow: `CompanyScoreService` extrai `diff_pp` do payload do dispatcher mas **descarta** `diff_pct` (a variação relativa nativa da Adman), que existe no MESMO payload cacheado. Reconsultar o dispatcher para o mesmo par `(empresa, período)` no MESMO dia bate em cache (TTL 24h para leitura `complete`), não gera segunda leitura ao vivo — **desde que a reconsulta aconteça logo depois da primeira**, porque leituras `partial` (rate-limit/instabilidade transitória) só ficam em cache por 10 minutos, não 24h. Este é o achado mais acionável da pesquisa: o comando deve extrair o `diff_pct` de margem **interleaved**, empresa por empresa, na MESMA passada em que o shadow roda — nunca numa segunda passada depois de processar todos os profissionais.

As outras duas parcelas ("régua-por-empresa × régua-da-média" e "empresas excluídas do denominador") são 100% calculáveis só com o que `empresas_score` já contém — sem chamada adicional nenhuma.

**Recomendação primária:** persistir o relatório em duas tabelas insert-only (uma por profissional×competência, uma por empresa×profissional×competência — mesmo padrão do `ProbeMargemPrevStability`), calcular a "nota nova" via `ReflectionMethod` sobre os métodos privados (padrão já estabelecido em `CompanyScoreServiceFormulaTest`/`CompanyScoreServiceReguasTest`), interleavar a chamada extra do dispatcher por empresa dentro do mesmo loop que gera `empresas_score`, e rodar fora da janela de contenção conhecida da Adman (evitar sobreposição com `adman:sync`/`adman:warm-diff`/crons de desempenho).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Chamada única a `compute()` com shadow ligado | Backend/API (Artisan Command → `DesempenhoScoreService`) | — | Comando CLI, sem rota HTTP nem tela; orquestra um service já existente |
| Derivação de nota_nova/status_novo a partir de `empresas_score` | Backend/API (Reflection sobre `DesempenhoScoreService`) | — | Lógica já existe, privada, no mesmo service — não é lógica nova |
| Extração de `diff_pct` de margem (parcela pp×relativa) | Backend/API (`MetricDiffDispatcher`/`AdmanMetricDiffService`, via cache) | Database/Storage (Cache Redis/DB) | Dado já computado e cacheado pelo shadow; a leitura extra é cache-only quando interleaved |
| Persistência do relatório (2 tabelas insert-only) | Database/Storage | Backend/API (Eloquent models/migrations) | Segue a disciplina "persistir antes de agregar, reconsultar, nunca por stdout" já estabelecida pelo probe da Fase 117 |
| Apresentação do relatório (tabela console + resumo) | Backend/API (Artisan `$this->table()`) | — | Sem UI (Fase 123); saída é CLI, lida a partir do banco já persistido |
| Amostras de risco / histograma pp | Backend/API (queries sobre as tabelas novas) | — | Filtros determinísticos sobre dado já persistido, não cálculo novo |

Não há camada Browser/CDN nesta fase — é 100% backend (comando Artisan), coerente com a fronteira "não está nesta fase: telas (123)".

## Standard Stack

### Core

Nenhuma biblioteca nova. O comando reusa exclusivamente infraestrutura já presente no `composer.json`:

| Peça | Já existe em | Papel no comando |
|------|--------------|-------------------|
| `DesempenhoScoreService::compute()` | `app/Services/DesempenhoScoreService.php` | Chamada única (D-01); shape documentado no docblock do método |
| `CompanyScoreService::computeEmpresasScore()` | `app/Services/Desempenho/CompanyScoreService.php` | Já é chamado DENTRO de `compute()` quando `incluirEmpresasScore: true`; o comando NUNCA chama isto diretamente — leria um universo potencialmente diferente do que `compute()` resolveu |
| `MetricDiffDispatcher::compute()` | `app/Services/Metrics/MetricDiffDispatcher.php` | Reconsulta cache-only do `diff_pct` de margem (parcela pp×relativa) |
| `BonusInvalidacao::companyIdsInvalidadas($mes)` | `app/Models/BonusInvalidacao.php` | Cross-check da amostra "empresa invalidada" (ausência, não presença) |
| `MetricPeriodResolver::resolve()` | `app/Services/Metrics/MetricPeriodResolver.php` | Resolve os 3 `$periodo` (competência fechada + 2 anteriores) |
| `ReflectionMethod` (PHP nativo) | `tests/Feature/Phase119/CompanyScoreServiceFormulaTest.php:150` (padrão já usado no projeto) | Chama `computeNotaFinalPorEmpresa()`/`computeScoreStatusPorEmpresa()` (privados) sem tocar `DesempenhoScoreService.php` |

### Supporting

Nenhuma. `Illuminate\Support\Collection` (já é dependência do framework) cobre agregação/filtro/agrupamento sem pacote extra.

### Alternatives Considered

| Instead of | Could use | Tradeoff |
|------------|-----------|----------|
| Reflection sobre os métodos privados | Duplicar a lógica de `computeNotaFinalPorEmpresa`/`computeScoreStatusPorEmpresa` no comando (mesmo padrão que `CompanyScoreService` já faz com `reguaFaturamento`/`reguaMargem`) | Duplicar funciona, mas cria um TERCEIRO lugar com a mesma lógica (já são 2: `DesempenhoScoreService` e `CompanyScoreService`) — risco de drift silencioso justo no comando que decide o go/no-go da milestone. Reflection é mais seguro aqui porque este comando é auditoria, não caminho de produção contínuo |
| Interleavar a chamada extra do dispatcher por empresa | Rodar uma segunda passada completa (todos os profissionais, todas as competências) só para coletar `diff_pct` | A segunda passada corre risco real de estourar o TTL curto (10 min) das leituras `partial`, reintroduzindo o problema de "duas leituras, ruído" que D-01 existe para evitar |
| Persistir em 2 tabelas insert-only | Relatório 100% efêmero (só `$this->table()` no console) | Efêmero é mais simples, mas contradiz a disciplina "persistir antes de agregar, conferir por reconsulta ao banco, nunca por stdout" que o `code_context` do CONTEXT.md explicitamente pede para replicar, e inviabiliza auditoria posterior das 7 amostras/histograma sem re-rodar (custo Adman) |

**Installation:** nenhuma (`composer install`/`npm install` não mudam nesta fase).

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhum pacote novo (`composer.json`/`package.json` inalterados) — todo o trabalho é reuso de código já presente no projeto. Nenhuma entrada de auditoria de slopcheck/registry é necessária.

## Architecture Patterns

### System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│  php artisan desempenho:comparar-score-empresa --mes=YYYY-MM            │
└───────────────────────────────┬───────────────────────────────────────┘
                                 │
                                 ▼
                 resolve 3 competências (MetricPeriodResolver)
                 [mes fechado, mes-1, mes-2] — cada uma com seu $periodo
                                 │
                                 ▼
        para cada competência × cada profissional elegível (User::analista|estrategista)
                                 │
                                 ▼
   DesempenhoScoreService::compute($user, $mes, $periodo, incluirEmpresasScore: true)
   [UMA chamada — D-01. Devolve nota_final legada + empresas_score no MESMO payload]
                                 │
              ┌──────────────────┼───────────────────────┐
              ▼                                          ▼
   nota_antiga = payload['nota_final']       empresas_score (Collection de linhas por empresa)
   status_antigo = payload['score_status']                │
                                                           ▼
                                     Reflection: computeNotaFinalPorEmpresa(empresasScore)
                                     Reflection: computeScoreStatusPorEmpresa(empresasScore)
                                     [métodos PRIVADOS da Fase 120 — nenhuma chamada nova à Adman]
                                                           │
                                                           ▼
                                     nota_nova / status_novo
                                                           │
                                                           ▼
        POR EMPRESA em empresas_score (interleaved, na MESMA iteração):
        MetricDiffDispatcher::compute($company, $periodo, $fonteFinanceira) — CACHE HIT
        (mesma chave que o shadow acabou de aquecer) → extrai diff_pct de margem
                                                           │
                                                           ▼
                    decomposição D-02: 3 parcelas + resíduo explícito
                    (pp×relativa via diff_pct extra; régua-por-empresa×régua-da-média
                     via nota_antiga vs nota_nova; denominador via nota_empresa
                     estrita vs nota_empresa_parcial, ambas já em empresas_score)
                                                           │
                                                           ▼
              PERSISTE (insert-only) — nunca só agrega em memória:
              desempenho_comparador_profissionais (1 linha/user×mes)
              desempenho_comparador_empresas       (1 linha/user×company×mes)
                                                           │
                                                           ▼
              RECONSULTA do banco (não do array em memória) para:
              - tabela console por profissional (ROLL-01)
              - amostras de risco (ROLL-02, 7 filtros + 1 checagem de ausência)
              - histograma de margem_var_pp deduplicado por company_id (ROLL-03)
```

### Recommended Project Structure

```
app/Console/Commands/
└── CompararScoreEmpresa.php        # comando único (ROLL-01/02/03), molde: ConsolidarMesDesempenho

app/Models/
├── DesempenhoComparadorProfissional.php   # 1 linha por (user_id, mes_referencia)
└── DesempenhoComparadorEmpresa.php        # 1 linha por (user_id, company_id, mes_referencia)

database/migrations/
└── YYYY_MM_DD_create_desempenho_comparador_tables.php  # molde: 2026_07_27_120000_create_adman_probe_margem_prev_tables.php

tests/Feature/Phase121/
└── CompararScoreEmpresaCommandTest.php     # ROLL-01/02/03, mock de CompanyScoreService::computeEmpresasScore (padrão AgregacaoProfissionalTest)
```

### Pattern 1: Reflection para consumir métodos privados sem tocar o arquivo-fonte

**What:** `computeNotaFinalPorEmpresa()` e `computeScoreStatusPorEmpresa()` (`app/Services/DesempenhoScoreService.php:1555,1593`) são `private`. O gate de aditividade desta fase proíbe tornar isso público. `ReflectionMethod::setAccessible(true)` já é o padrão consagrado no projeto para este exato problema.

**When to use:** sempre que o comando precisar do valor exato que a lógica de produção (ainda atrás da flag) produziria, sem duplicar a fórmula.

**Example:**
```php
// Source: tests/Feature/Phase119/CompanyScoreServiceFormulaTest.php:147-154 (padrão já em produção nos testes)
private function notaNovaViaReflection(DesempenhoScoreService $service, Collection $empresasScore): ?float
{
    $ref = new \ReflectionMethod($service, 'computeNotaFinalPorEmpresa');
    $ref->setAccessible(true);

    return $ref->invoke($service, $empresasScore);
}

private function statusNovoViaReflection(DesempenhoScoreService $service, Collection $empresasScore): string
{
    $ref = new \ReflectionMethod($service, 'computeScoreStatusPorEmpresa');
    $ref->setAccessible(true);

    return $ref->invoke($service, $empresasScore);
}
```

### Pattern 2: extrair a parcela "margem pp × relativa" sem 2ª leitura ao vivo

**What:** `CompanyScoreService::computeEmpresasScore()` (linha 235-239) lê `contribution_margin_pct.value`, `.prev_value`, `.diff_pp` e `.diff_source` do payload do `MetricDiffDispatcher`, mas **nunca lê `.diff_pct`** (a variação relativa nativa da Adman) — que existe no MESMO payload (`AdmanMetricDiffService.php:103,312`). Para a parcela "régua-por-empresa com margem RELATIVA" da D-02, o comando precisa desse `diff_pct`, que só existe reconsultando o dispatcher.

**When to use:** decomposição D-02, parcela 1. Fazer a reconsulta **imediatamente** após processar cada empresa no mesmo loop do shadow — nunca numa passada posterior.

**Example:**
```php
// Source: app/Services/Metrics/AdmanMetricDiffService.php:128-142 (mecanismo de cache)
foreach ($empresasScore as $linha) {
    if ($linha->fonte_financeira === null) {
        continue; // D-03 da Fase 119 — sem fonte, sem margem, nenhuma das duas leituras
    }

    // Mesma empresa, mesmo $periodo, mesmo dia — bate no Cache::get() interno
    // do AdmanMetricDiffService::compute() (chave inclui cacheDay()), NÃO uma
    // 2ª leitura ao vivo — DESDE QUE feito logo após o shadow ter aquecido
    // essa MESMA chave (TTL 'partial' é só 10 min, ver Common Pitfalls).
    $resultado = $dispatcher->compute($linha->company, $periodo, $linha->fonte_financeira);

    $margemDiffPctRelativo = $resultado['metrics']['contribution_margin_pct']['diff_pct'] ?? null;
    // aplicar a MESMA régua (reguaMargem, via Reflection ou cópia local) sobre
    // $margemDiffPctRelativo para obter a "nota margem relativa" e montar a
    // nota_empresa alternativa que isola a parcela pp×relativa.
}
```

### Anti-Patterns to Avoid

- **Chamar `compute()` duas vezes (flag off, depois flag on)** para comparar legado×novo: viola D-01 diretamente — mesmo que o custo HTTP real seja cache-hit (mesma empresa/período/dia), a decisão do usuário foi explícita em não fazer isso, e replicar duas chamadas completas ao orquestrador reintroduz superfície para inconsistência de universo/invalidação entre as duas passadas.
- **Chamar `CompanyScoreService::computeEmpresasScore()` diretamente no comando**, fora do `compute()`: resolveria `$periodo`/`$invalidadas` separadamente, arriscando uma janela ligeiramente diferente da que `compute()` já resolveu — o docblock de `CompanyScoreService` é explícito: "`$periodo` chega SEMPRE já resolvido por quem chama... garante janela byte-idêntica".
- **Rodar a extração de `diff_pct` numa segunda passada** (depois de processar todos os profissionais): risco real de estourar o TTL de 10 min das leituras `partial`, forçando uma 2ª leitura ao vivo sem querer.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Calcular "nota nova" / "status novo" a partir de `empresas_score` | Reimplementar a média/cobertura no comando | `ReflectionMethod` sobre `computeNotaFinalPorEmpresa`/`computeScoreStatusPorEmpresa` | A lógica já existe, testada (18/18 em `--filter=Phase120`); duplicar cria um 3º lugar para o mesmo cálculo divergir |
| Obter a variação relativa de margem por empresa | Recalcular `(atual-anterior)/anterior*100` a partir de `margem_pct_atual`/`margem_pct_anterior` | Reconsultar `MetricDiffDispatcher::compute()` (cache-hit) e ler `diff_pct` | O `diff_pct` "oficial" é o `.diff` NATIVO da Adman (hotfix `a413e823`), não um cálculo local — recalcular a partir de value/prev produziria um número PARECIDO mas não idêntico ao que a Adman de fato reporta |
| Identificar as 7 amostras de risco | Hardcode de nomes/IDs de empresa | Filtros determinísticos sobre `empresas_score` persistido (ver Pergunta 5 abaixo) + 1 checagem de ausência para "invalidada" | Hardcode quebra na próxima competência; filtros sobre o dado persistido são reexecutáveis |
| Resolver as 3 competências (fechada + 2 anteriores) | `now()->subMonths(n)` inline | `MetricPeriodResolver::resolve(['period_key' => 'YYYY-MM'])` por competência, com `Carbon::createFromFormat('Y-m-d', "{$mes}-01")` (NUNCA `createFromFormat('Y-m', ...)`, ver Pitfall do `ConsolidarMesDesempenho`) | Já existe fonte única de verdade para "o que é uma competência fechada" (`comparison_mode`, `is_closed`) |

**Key insight:** este comando não tem NENHUM cálculo genuinamente novo — é 100% composição de peças que a Fase 119/120 já construíram e testaram. O risco não é "lógica errada", é "reimplementar por engano algo que já existe e divergir".

## Common Pitfalls

### Pitfall 1: duplicar a lógica de agregação em vez de usar Reflection
**What goes wrong:** o comando reimplementa "média das `nota_empresa` complete" com uma pequena diferença de arredondamento ou de filtro de status.
**Why it happens:** parece mais simples que descobrir o padrão de Reflection já usado nos testes.
**How to avoid:** usar `ReflectionMethod` sobre `computeNotaFinalPorEmpresa`/`computeScoreStatusPorEmpresa`, exatamente como `CompanyScoreServiceFormulaTest`/`CompanyScoreServiceReguasTest` já fazem para `reguaFaturamento`.
**Warning signs:** teste do comando bate um valor "quase igual" ao que `AgregacaoProfissionalTest` produziu para o mesmo fixture — sinal de fórmula duplicada e divergente.

### Pitfall 2: extrair `diff_pct` numa segunda passada tardia
**What goes wrong:** o comando processa TODOS os profissionais/competências primeiro (só com o shadow), e só DEPOIS volta para reconsultar `diff_pct` por empresa — nesse intervalo (minutos, com 15-20 profissionais × 3 competências), o cache de leituras `partial` (TTL 10 min) já expirou, e a reconsulta vira uma 2ª leitura ao vivo, exatamente o ruído que D-01 queria evitar.
**Why it happens:** parece mais organizado separar "coleta" de "decomposição" em duas fases do código.
**How to avoid:** interleavear — extrair `diff_pct` empresa por empresa, IMEDIATAMENTE depois que o shadow processou aquela empresa, dentro do MESMO loop.
**Warning signs:** no relatório, empresas com `quality.margin_diff_source` diferente entre a leitura do shadow e a leitura extra do comando para o mesmo `(company, período)`.

### Pitfall 3: empresa contada 2× no histograma de ROLL-03 (dedupe por company_id)
**What goes wrong:** o mesmo `company_id` pode aparecer na carteira de MAIS DE UM profissional (ex.: Performance com um analista + Shopee com outro, `company_users` tem várias linhas por empresa desde a Fase 76 — ver memória `project_company_users_multi_linha_servico`). Somar `empresas_score` de todos os profissionais sem deduplicar por `company_id` infla o histograma e distorce a "distribuição real de `margem_var_pp` na carteira inteira" (D-03).
**Why it happens:** iterar por profissional é o padrão natural (mesmo padrão do `ConsolidarMesDesempenho`), mas o histograma pede uma métrica por EMPRESA, não por vínculo.
**How to avoid:** ao montar o histograma, `groupBy('company_id')->first()` (ou `unique('company_id')`) antes de contar — o `margem_var_pp` de uma empresa não depende de QUEM a está avaliando, é o mesmo dado cacheado por `(empresa, período)`.
**Warning signs:** soma de contagens do histograma maior que o número de empresas distintas com `financial_metrics_eligible=true` na competência.

### Pitfall 4: as 2 competências extras da D-03 não estão pré-aquecidas
**What goes wrong:** o cron `adman:warm-diff` (rodando hoje em produção) só aquece `current_month` + `last_closed_month` (ver `app/Console/Commands/WarmAdmanDiffCache.php:52-53`). A D-03 pede a competência fechada **mais as duas anteriores** — as 2 competências extras NUNCA são pré-aquecidas por nenhum cron existente. A primeira execução do comparador para essas 2 competências vai gerar leituras 100% ao vivo (custo cheio), não cache-hit.
**Why it happens:** nenhum consumidor existente antes desta fase precisava de mais de 1 competência fechada por vez.
**How to avoid:** dimensionar o custo (Pergunta 6) assumindo cold cache para as 2 competências mais antigas; considerar rodar `adman:warm-diff --period=YYYY-MM` manualmente para essas 2 competências antes do comparador, OU aceitar o custo e rodar fora de horário de contenção.
**Warning signs:** tempo de execução muito maior que o esperado nas competências N-1/N-2 comparado à competência fechada (N).

### Pitfall 5: "empresa invalidada" é ausência, não presença
**What goes wrong:** o comando tenta achar a "amostra de risco: empresa invalidada" filtrando `empresas_score` por algum campo `status='invalidada'` — esse status **não existe** (D-03 do `119-CONTEXT.md`/`CompanyScoreService` docblock linha 102-103: "`blocked`/`invalidada`/`sem_baseline` são deliberadamente inexistentes"). Empresas invalidadas na competência são **rejeitadas ANTES** de `computeEmpresasScore()` rodar (`compute()` linha ~489-494) — elas simplesmente não aparecem em `empresas_score`.
**Why it happens:** a intuição natural é procurar um status "invalidada" no dado, não a AUSÊNCIA de um company_id esperado.
**How to avoid:** cruzar `BonusInvalidacao::companyIdsInvalidadas($mes)` (lista quem foi invalidado) contra `empresas_score` (confirmar que NENHUM desses IDs aparece) — a "amostra de risco" aqui é uma prova de exclusão, não uma linha de dado.
**Warning signs:** a amostra "empresa invalidada" no relatório fica vazia ou ausente porque ninguém pensou em consultar `BonusInvalidacao` separadamente.

### Pitfall 6: as parcelas da decomposição parecem, mas não somam, o delta total
**What goes wrong:** o relatório apresenta 3 parcelas que, somadas, não batem com `nota_nova - nota_antiga` — e alguém (executor ou usuário lendo o relatório) acha que há um bug.
**Why it happens:** os efeitos interagem (ex.: uma empresa pode contribuir simultaneamente para "denominador" E "régua-por-empresa"); isso é matematicamente esperado, não um erro de cálculo.
**How to avoid:** o CONTEXT já exige isso explicitamente (D-02, ⚠️) — o relatório DEVE expor o resíduo (`delta_total - soma_das_parcelas`) como um número visível, nunca escondê-lo.
**Warning signs:** plano ou execução que tratam "parcelas somam 100% do delta" como critério de aceite — isso contradiz a decisão travada.

### Pitfall 7: `nota_final` pode bater igual enquanto `score_status` diverge
**What goes wrong:** o relatório só compara `nota_final` e conclui "sem mudança" para um profissional cujo `score_status` na verdade mudou de `official` para `partial` (ou vice-versa) — caso JÁ CONCRETO nos 4 espelhos da Fase 120 (cenário "Misto": `2.33`/`2.33` idênticos, mas `official`→`partial`).
**Why it happens:** o foco natural é no número (nota), não no status.
**How to avoid:** ROLL-01 já exige reportar ambos — reforçar no plano que "sem delta de nota" não implica "sem mudança de comportamento".
**Warning signs:** profissional listado como "delta zero, sem risco" que na verdade muda de `official` para `partial` (ou de faixa promovida para não-promovida via DESEMP-08, que depende de `score_status`/`faixa_bonus`, não só de `nota_final`).

## Code Examples

### Pergunta 1 — assinatura de `compute()` e onde está cada nota

```php
// Source: app/Services/DesempenhoScoreService.php:467
public function compute(
    User $user,
    Carbon $mesReferencia,
    ?array $periodoOverride = null,
    bool $incluirEmpresasScore = false
): array
```

Com `incluirEmpresasScore: true` e a flag `metrics.performance_company_first_score` em `false` (default de produção hoje):

```php
// Source: app/Services/DesempenhoScoreService.php:571-577, 708 (payload real)
$payload = $service->compute($user, $mes, null, incluirEmpresasScore: true);

$notaAntiga    = $payload['nota_final'];       // computeNotaFinal() — legado, régua-da-média
$statusAntigo  = $payload['score_status'];     // computeScoreStatus() — legado
$empresasScore = $payload['empresas_score'];   // array de objects de CompanyScoreService — SEMPRE presente quando incluirEmpresasScore=true, mesmo com a flag off
```

`computeNotaFinalPorEmpresa()`/`computeScoreStatusPorEmpresa()` são **`private`** (confirmado via grep — `app/Services/DesempenhoScoreService.php:1555,1593`). Como a fase não pode tornar isso público (fronteira "não modifica"), a única opção compatível é **Reflection** (Padrão 1 acima) — não "o comando replica a média" (risco de drift) nem "o payload já traz a nota nova" (não traz, quando a flag está off).

### Pergunta 2 — as três parcelas da D-02

| Parcela | Calculável com o payload de UMA chamada? | Como |
|---|---|---|
| Régua-por-empresa × régua-da-média | **Sim, direto** | `nota_antiga` (payload) vs `nota_nova` (Reflection sobre `empresasScore`) |
| Empresas excluídas do denominador | **Sim, direto** | Dentro de `empresasScore`: `nota_empresa` (estrita, só conta se os 3 componentes presentes) vs `nota_empresa_parcial` (média dos presentes) — comparar `avg(nota_empresa filtrando complete)` com `avg(nota_empresa_parcial filtrando status != sem_dados)` isola o efeito do "tudo ou nada" |
| Margem em pp × relativa | **Sim, mas precisa de 1 chamada adicional cache-hit** | `CompanyScoreService` só extrai `diff_pp` do payload do dispatcher (`app/Services/Desempenho/CompanyScoreService.php:238`), nunca `diff_pct` — que EXISTE no mesmo payload (`app/Services/Metrics/AdmanMetricDiffService.php:103`). Reconsultar `MetricDiffDispatcher::compute($company, $periodo, $fonteFinanceira)` pelo MESMO `(empresa, período, dia)` bate cache (ver Padrão 2) — SEM modificar `CompanyScoreService` |

Cada empresa em `empresas_score` carrega `margem_var_pp` (Fase 117/119, `EMPS-03`) mas **não** carrega `diff_pct` — confirmado por leitura direta de `CompanyScoreService.php:235-239` (só lê `value`, `prev_value`, `diff_pp`, `diff_source`). O `diff_pct` existe no payload do `MetricDiffDispatcher`/`AdmanMetricDiffService`, um nível abaixo de `CompanyScoreService`.

### Pergunta 3 — onde vive `margem_var_pp` e como filtrar `financial_metrics_eligible`

```php
// Source: app/Services/Desempenho/CompanyScoreService.php:120-301 (computeEmpresasScore)
// Cada objeto em empresas_score tem margem_var_pp (linha 287) e fonte_financeira
// (linha 279 — null quando NÃO elegível, D-03 do 119-CONTEXT.md). Elegibilidade
// financeira é resolvida em fontesPorEmpresa (linha 155-162), filtrando
// vinculos->where('financial_metrics_eligible', true) — CarteiraContextService.

$elegiveis = $empresasScore->filter(fn ($e) => $e->fonte_financeira !== null);
$comMargem = $elegiveis->pluck('margem_var_pp')->filter(fn ($v) => $v !== null);
```

Para ROLL-03 (carteira INTEIRA, 3 competências), o comando precisa iterar TODOS os profissionais elegíveis, coletar `empresas_score` de cada um, e **deduplicar por `company_id`** antes de montar o histograma (Pitfall 3).

### Pergunta 4 — molde de comando com persistência

`ProbeMargemPrevStability` estabelece exatamente a disciplina pedida no CONTEXT: tabelas insert-only (`adman_probe_margem_prev_leituras`/`_vereditos`), modo `--relatorio` que só lê do banco (nunca recomputa), aviso explícito no console ("a conferência OFICIAL é por reconsulta ao banco... nunca por este stdout").

**Diferença relevante para esta fase:** o probe existe para medir **instabilidade ao longo do TEMPO** (múltiplas leituras da MESMA empresa espalhadas em 24-48h) — por isso separa "leitura" de "`--relatorio`" em dois comandos/modos. O comparador da Fase 121 é uma comparação de **UM ÚNICO instante** (legado × novo, na MESMA passada, D-01) — não há necessidade de separar "coleta" de "agregação" em execuções diferentes. **Recomendação:** persistir e recalcular tudo na MESMA execução (sem modo `--relatorio` obrigatório), mas ainda assim ler de volta do banco para montar a tabela de console — isso prova reconsultabilidade sem herdar a complexidade de rodadas do probe (que não se aplica aqui).

### Pergunta 5 — as 7 amostras de risco, filtro por filtro

Todas calculáveis sobre `empresas_score` já persistido (ou, no caso da invalidada, sobre `BonusInvalidacao`):

| Amostra | Filtro |
|---|---|
| Profissional com poucas empresas | `empresasScore->count()` mínimo entre os profissionais processados na competência |
| Profissional com muitas empresas | `empresasScore->count()` máximo |
| Empresa com queda grande de faturamento | `faturamento_var_pct` mais negativo (ou `<= -6`, corte da régua "queda severa") |
| Empresa com pp positivo | `margem_var_pp > 0` |
| Empresa sem baseline | `faturamento_pontos === null` (motivo `faturamento_sem_baseline` em `quality.motivos`) |
| Empresa invalidada | **NÃO existe em `empresas_score`.** Cross-check: `BonusInvalidacao::companyIdsInvalidadas($mes)` deve conter o `company_id`, e nenhuma linha correspondente deve aparecer em `empresas_score` de nenhum profissional daquela competência (ver Pitfall 5) |
| Profissional com Shopee | qualquer linha em `empresasScore` com `fonte_financeira === 'shopee'` |

### Pergunta 6 — custo de execução

Sem acesso ao MySQL local para contar linhas reais (`Connection refused` ao tentar `php artisan tinker` nesta sessão — MariaDB local não está rodando; ver memória `project_mariadb_local_corrompido`), a estimativa usa números já documentados no código desta milestone:

- `CarteiraContextService` (docblock, medido em prod 2026-07-16): **268 linhas de pivot** no total.
- `ConsolidarMesDesempenho` (docblock): "batch mensal itera ~15-20 users × ~30 empresas".
- Cada empresa com fonte `adman` custa até **2 chamadas HTTP síncronas** por `(empresa, período)` (`fetchPerformance` + `fetchAccountMetricsDetailedCached`, dentro de `AdmanMetricDiffService::compute()`), cacheadas por `(marketplace, custId, período, DIA)` com TTL 24h (`complete`) ou 10 min (`partial`/erro).
- O cron `adman:warm-diff` só pré-aquece `current_month` + `last_closed_month` — **as 2 competências extras da D-03 (N-1, N-2) nunca são pré-aquecidas por nenhum job existente** (Pitfall 4). A primeira execução do comparador para essas 2 competências é, na prática, uma passada a frio equivalente ao próprio `adman:warm-diff`.
- Estimativa (assumindo ~15-20 profissionais, universos parcialmente sobrepostos, ~30-50 empresas distintas com fonte Adman no total — número não confirmado neste ambiente): **até ~150 pares (empresa, período) por competência × 2 competências frias = ~300 pares, ~600 chamadas HTTP**, cada uma sujeita ao retry/backoff exponencial já embutido em `AdmanService` (até 6 tentativas, teto 30s, em caso de 429). Em condição folgada (sem contenção), da ordem de minutos a baixa dezena de minutos; sob contenção real (rate-limit ativo), pode multiplicar várias vezes — o mesmo padrão que motivou o probe da Fase 117 e o incidente de 12-13/06.
- **O comando PRECISA de consciência de pacing**, mas não necessariamente um `usleep` artificial novo: o `AdmanService` já tem backoff exponencial com jitter por chamada (quick fix `20260729-adman-retry-resiliente`). O risco real não é "chamada isolada sem proteção", é "volume agregado alto o suficiente para SER a causa do rate-limit", como o próprio probe já documentou. **Recomendação:** rodar fora de janelas de contenção conhecidas (evitar sobreposição com `adman:sync`/cron diário) e considerar pré-aquecer as 2 competências extras via `adman:warm-diff --period=YYYY-MM` antes de rodar o comparador, transformando custo ao vivo em custo de cache-hit.

### Pergunta 7 — riscos e armadilhas (ver `## Common Pitfalls` para detalhe)

1. Timeout/lentidão por volume de chamadas HTTP não pré-aquecidas (Pitfall 4) — mitigação: pré-aquecer ou aceitar o custo fora de horário de pico.
2. Contaminar a própria medição por rate-limit (Pitfall 2) — mitigação: interleavear a extração de `diff_pct`, nunca numa 2ª passada tardia.
3. Decomposição sugerindo precisão inexistente (Pitfall 6) — mitigação: expor o resíduo explicitamente, nunca escondê-lo (já é decisão travada, D-02).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|---------------|--------|
| Régua aplicada UMA VEZ sobre a média agregada da carteira (`margemPontos`/`reguaMargem` sobre `var_margem_pct` agregado) | Régua aplicada POR EMPRESA (`CompanyScoreService::reguaMargem` sobre `margem_var_pp` por empresa), média DEPOIS | Fase 119/120 (v21.0) — ainda atrás de feature flag, `false` em produção | É EXATAMENTE o que esta fase mede antes de decidir ligar |
| Margem lida como variação RELATIVA (`diff_pct`, `.diff` nativo da Adman) | Margem lida como pontos percentuais (`diff_pp = value - prev_value`) | Fase 117 (D1 da milestone), reabrindo deliberadamente o hotfix `a413e823` | `diff_pct` continua existindo e intocado para consumidores legados — mas fica "escondido" dentro de `CompanyScoreService`, que não o expõe na linha de saída (achado central desta pesquisa) |

**Deprecated/outdated:** nada nesta fase — é auditoria pura sobre um mecanismo ainda em shadow.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Estimativa de "~30-50 empresas distintas com fonte Adman" e "~15-20 profissionais elegíveis" | `## Code Examples` (Pergunta 6) | Se o número real for muito maior, o custo/tempo de execução estimado fica subestimado; MySQL local estava indisponível nesta sessão (`Connection refused`), então os números vêm só de docblocks/comentários do código, não de uma contagem ao vivo — **confirmar contagem real antes de rodar em produção/VPS** |
| A2 | Reconsultar `MetricDiffDispatcher::compute()` para o mesmo `(empresa, período, dia)` é sempre cache-hit quando interleaved | `## Common Pitfalls` (Pitfall 2), `## Code Examples` (Pergunta 2) | Verificado por leitura direta do código-fonte do cache (`AdmanMetricDiffService.php:128-142`), não por execução ao vivo nesta sessão — o comportamento pode divergir se o backend de cache (Redis/DB) estiver degradado/indisponível no momento da execução real |
| A3 | Persistir em 2 tabelas insert-only (profissional×mês + empresa×profissional×mês) é a estrutura recomendada | `## Standard Stack` (Alternatives Considered), `## Architecture Patterns` | Fica a critério do planner/discretion do usuário — o CONTEXT.md não trava o shape exato de persistência, só a disciplina "reconsultável, não só stdout" |

**Se esta tabela parecer pequena:** é porque o domínio desta fase é 100% leitura de código já escrito nesta mesma milestone — a maior parte das afirmações é `[VERIFIED: leitura direta do código]`, não `[ASSUMED]`.

## Open Questions

1. **Quantas empresas/profissionais reais existem hoje para dimensionar o custo de execução com precisão?**
   - What we know: docblocks documentam ~268 vínculos totais (medido 2026-07-16) e "~15-20 users × ~30 empresas" como ordem de grandeza do batch mensal.
   - What's unclear: número exato de empresas ELEGÍVEIS financeiramente (`financial_metrics_eligible=true`) hoje, e quantas têm `adman_account_id` (vs Shopee, que não custa HTTP de margem).
   - Recommendation: rodar uma contagem rápida (`CarteiraContextService::contadores()` agregado sobre todos os users elegíveis) antes de planejar o comando definitivo — o MySQL local não estava acessível nesta sessão de pesquisa para confirmar ao vivo.

2. **O comando deve suportar `--relatorio` (reimprimir do banco sem recomputar), ou isso é escopo desnecessário para uma comparação de instante único?**
   - What we know: o probe da Fase 117 tem esse modo porque agrega MÚLTIPLAS rodadas ao longo do tempo.
   - What's unclear: se o usuário vai querer reconsultar o relatório dias depois sem re-rodar (custo Adman) — plausível, dado o padrão de "conferir por reconsulta ao banco".
   - Recommendation: incluir um modo simples de leitura (`--mostrar` ou reaproveitar `--relatorio`) que só faz `SELECT` nas 2 tabelas novas, sem custo adicional de implementação relevante.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI (`C:\xampp\php\php.exe`) | Rodar o comando Artisan e os testes | ✓ | PHP 8.2+ (per `composer.json`) | — |
| MySQL/MariaDB local | Ler `company_users`/`bonus_invalidacoes`/etc. em dev | ✗ (nesta sessão — `Connection refused` em `127.0.0.1:3306`) | — | Rodar testes com SQLite (padrão de `phpunit.xml`); confirmar contagens reais no VPS ou reiniciar o MariaDB local antes de executar o comando manualmente em dev |
| API Adman (externa) | `MetricDiffDispatcher`/`AdmanMetricDiffService` para margem/faturamento por empresa | Não testado nesta sessão (fora de escopo de pesquisa de código) | — | Nenhum — é a fonte de dado financeiro; sem ela o comando produz `sem_fonte`/`partial` honestamente, não quebra |
| Cache (Redis/DB, conforme `.env`) | TTL de 24h/10min do `AdmanMetricDiffService`, base da mitigação do Pitfall 2 | Assumido disponível (mesmo backend que já sustenta produção) | — | — |

**Missing dependencies with no fallback:** nenhuma que bloqueie o PLANEJAMENTO desta fase — a indisponibilidade do MySQL local é uma limitação da SESSÃO DE PESQUISA, não do ambiente de execução real (dev local completo ou VPS).

**Missing dependencies with fallback:** contagens reais de empresas/profissionais (Open Question 1) — mitigado reiniciando o MariaDB local ou confirmando no VPS antes da execução real do comando.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x via `php artisan test` (Laravel test runner) |
| Config file | `phpunit.xml` (raiz do projeto) |
| Quick run command | `C:\xampp\php\php.exe artisan test --filter=CompararScoreEmpresa` |
| Full suite command | `C:\xampp\php\php.exe artisan test --filter=Desempenho` (baseline conhecida: **14 falhas pré-existentes**, documentadas na Fase 120 — não regressão desta fase) |

⚠️ Nunca rodar `artisan test` sem `--filter` (convenção do projeto, `additional_context` desta pesquisa) — a suíte completa é grande e o filtro isola o escopo desta fase.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ROLL-01 | Comando produz, por profissional, `nota_antiga`/`nota_nova`/`delta`/contadores/`maior_causa_delta`, persistidos e reconsultáveis | Feature | `php artisan test --filter=CompararScoreEmpresaCommandTest` | ❌ Wave 0 |
| ROLL-02 | As 7 amostras de risco são identificáveis programaticamente (6 filtros + 1 checagem de ausência) sobre um fixture cobrindo os 7 casos | Feature | `php artisan test --filter=CompararScoreEmpresaCommandTest::test_amostras_de_risco` | ❌ Wave 0 |
| ROLL-03 | Histograma de `margem_var_pp` deduplicado por `company_id`, só empresas `financial_metrics_eligible=true`, 3 competências | Feature | `php artisan test --filter=CompararScoreEmpresaCommandTest::test_histograma_margem_pp` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=CompararScoreEmpresa`
- **Per wave merge:** `php artisan test --filter=Desempenho` (confirmar baseline continua em 14 falhas — nenhuma nova)
- **Phase gate:** suíte completa do filtro `Desempenho` verde (exceto as 14 pré-existentes) antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase121/CompararScoreEmpresaCommandTest.php` — cobre ROLL-01/02/03, seguindo o padrão de mock parcial de `CompanyScoreService::computeEmpresasScore()` já usado em `tests/Feature/Phase120/AgregacaoProfissionalTest.php` (evita chamada HTTP real à Adman nos testes)
- [ ] Migration `database/migrations/YYYY_MM_DD_create_desempenho_comparador_tables.php` — molde: `2026_07_27_120000_create_adman_probe_margem_prev_tables.php` (tabelas insert-only)
- [ ] Models `DesempenhoComparadorProfissional`/`DesempenhoComparadorEmpresa` — se a rota de persistência em tabela for a escolhida pelo plano
- [ ] Nenhum framework de teste a instalar — PHPUnit 11.x já configurado

## Security Domain

> `security_enforcement` não está explicitamente `false` em `.planning/config.json` — seção incluída, mas proporcional ao risco real (comando CLI interno, sem input de usuário externo, sem rota HTTP nova).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-------------------|
| V2 Authentication | Não | Comando Artisan roda via CLI/cron, sem sessão HTTP |
| V3 Session Management | Não | idem |
| V4 Access Control | Não diretamente | Nenhuma rota/controller nova; execução restrita a quem tem acesso ao servidor/CLI (mesmo modelo de todos os outros comandos `desempenho:*`) |
| V5 Input Validation | Sim | `--mes=YYYY-MM` deve validar formato explicitamente com mensagem clara (mesmo padrão de `ConsolidarMesDesempenho`/`ProbeMargemPrevStability` — `preg_match('/^\d{4}-\d{2}$/', ...)`), nunca confiar em `last_closed_month` implícito para esta fase (evita ambiguidade de competência) |
| V6 Cryptography | Não | Nenhum dado sensível novo — só notas numéricas e metadados de qualidade, já expostos hoje em `empresas_score`/relatórios existentes |

### Known Threat Patterns for este domínio

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|----------------------|
| Log/console vazando valores de margem por empresa em texto livre | Information Disclosure | Seguir a disciplina já estabelecida no probe (`T-117-06`): `Log::info` só com contadores agregados; valores por empresa só na tabela persistida/console, nunca em log estruturado enviado a serviço externo |
| Comando rodado repetidamente gerando custo/volume de chamadas à Adman como efeito colateral não intencional (uso indevido como vetor de carga) | Denial of Service (contra a própria Adman/rate-limit compartilhado) | `--mes` obrigatório (nunca "roda para todas as competências por default"); considerar guard de "já rodou hoje para esta competência" antes de reprocessar |

## Sources

### Primary (HIGH confidence — leitura direta do código desta milestone)

- `app/Services/DesempenhoScoreService.php` — assinatura de `compute()`, `computeNotaFinalPorEmpresa()`/`computeScoreStatusPorEmpresa()` (privados, linhas 1555/1593), `margemPontos()`/`reguaMargem()`, cache key v14
- `app/Services/Desempenho/CompanyScoreService.php` — shape de `empresas_score` (`nota_empresa`, `nota_empresa_parcial`, `margem_var_pp`, `quality.motivos`), ausência de `diff_pct` na linha de saída
- `app/Services/Metrics/AdmanMetricDiffService.php` — shape completo do payload do dispatcher (inclui `diff_pct` E `diff_pp`), mecanismo de cache por `(marketplace, custId, período, dia)`, TTL 24h/10min
- `app/Services/Metrics/MetricDiffDispatcher.php` — roteamento adman/shopee
- `app/Services/Portfolio/CarteiraContextService.php` — `financial_metrics_eligible`, contadores, escala documentada (268 vínculos)
- `app/Console/Commands/ConsolidarMesDesempenho.php` — molde de iteração por profissional, `memory_limit`, validação de `--mes`
- `app/Console/Commands/ProbeMargemPrevStability.php` + migration `2026_07_27_120000_create_adman_probe_margem_prev_tables.php` — molde de persistência insert-only + disciplina "reconsulta ao banco, nunca stdout"
- `app/Console/Commands/WarmAdmanDiffCache.php` — confirmação de que o cron só aquece `current_month`+`last_closed_month`
- `app/Services/AdmanService.php` — retry/backoff exponencial já embutido para 429 (quick `20260729-adman-retry-resiliente`)
- `tests/Feature/Phase119/CompanyScoreServiceFormulaTest.php` — padrão de Reflection já em uso no projeto
- `.planning/phases/120-.../120-03-SUMMARY.md` — os 4 cenários espelho (primeiro dado numérico da divergência)
- `.planning/REQUIREMENTS-v21.md` — ROLL-01/02/03, D1/D2 da milestone
- `.planning/phases/121-.../121-CONTEXT.md` — D-01..D-04 desta fase
- `plano-implementacao-desempenho-por-empresa.md` §6 — desenho canônico do comando e das amostras de risco

### Secondary (MEDIUM confidence)

- Nenhuma — esta pesquisa não precisou de WebSearch/Context7 (domínio 100% interno, sem biblioteca nova).

### Tertiary (LOW confidence)

- Estimativas de custo/volume (Pergunta 6) — baseadas em docblocks do código, não em contagem ao vivo (MySQL local indisponível nesta sessão). Ver Assumptions Log A1.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhuma peça nova, tudo confirmado por leitura direta do código já mergeado nesta milestone
- Architecture: HIGH — o fluxo de dados foi traçado linha a linha através de `DesempenhoScoreService`/`CompanyScoreService`/`MetricDiffDispatcher`/`AdmanMetricDiffService`
- Pitfalls: HIGH para os pitfalls 1-3, 5-7 (verificados por leitura de código/testes existentes); MEDIUM para o pitfall 4 e a estimativa de custo (Pergunta 6), por depender de volume real não confirmado nesta sessão (MySQL local indisponível)

**Research date:** 2026-07-30
**Valid until:** 30 dias (domínio interno estável; revalidar se a Fase 122 mudar o shape de `empresas_score` antes desta fase rodar)
