# Phase 90: Carteiras consolidadas — renderCarteirasConsolidadas (v17.0) - Research

**Researched:** 2026-07-16
**Domain:** Refatoração de controller Laravel (leitura) sobre schema/service já existentes (`CarteiraContextService`, Fase 88) + evolução de 2 telas React (Inertia) — zero libs novas
**Confidence:** HIGH (100% código lido diretamente neste repositório; único ponto MEDIUM é o desenho exato do "contador no topo da tela" na visão consolidada, que o plano canônico não especifica claramente para telas multi-card — ver Assumptions Log)

<user_constraints>
## User Constraints (from REQUIREMENTS.md / plano canônico)

> Não há CONTEXT.md desta fase (sem `discuss-phase` rodado). As constraints abaixo vêm do `REQUIREMENTS.md`, do `ROADMAP.md` (Phase 90, `UI hint: yes`) e do `plano-carteira-desempenho-multi-servico.md` (plano canônico do usuário) — tratadas com a mesma autoridade de decisões travadas.

### Locked Decisions (REQUIREMENTS.md CART-06, CART-07 — Fase 90)

- **CART-06**: A carteira consolidada (`renderCarteirasConsolidadas`, visão admin) mostra cards por profissional com contagem correta, separando empresas únicas de vínculos de serviço, sem puxar faturamento ML pra quem só cuida em Shopee
- **CART-07**: A UI de carteira tem filtro de contexto (Todos / Performance-ML / Shopee), badges de serviço por linha e contadores (empresas únicas vs. vínculos de serviço)

### Success Criteria (ROADMAP.md Phase 90 — texto travado)

1. Cards por profissional na carteira consolidada mostram contagem correta, separando empresas únicas de vínculos de serviço
2. Profissional responsável apenas por Shopee de uma empresa que também tem ML NÃO aparece com faturamento/margem ML puxado dessa empresa
3. A UI de carteira (**individual e consolidada**) tem filtro de contexto funcional (Todos / Performance-ML / Shopee)
4. Badges de serviço aparecem por linha e contadores (empresas únicas vs. vínculos de serviço) ficam visíveis no topo da tela

Ponto crítico do SC3: o filtro de contexto **vale para as DUAS telas** (`Portfolio/AdminCarteira.jsx`, individual — Fase 89 entregou os badges mas não o filtro nem os contadores; `Portfolio/Carteiras.jsx`, consolidada — alvo principal desta fase). A Fase 90 não é só "CART-06 no backend + CART-07 no admin cards" — é também fechar o débito deixado pela Fase 89 (que deliberadamente adiou o filtro completo para cá, ver `89-RESEARCH.md` Assumption A2).

### Claude's Discretion

- Nome exato do query param do filtro de contexto (`?contexto=`, `?setor=`, `?visao=`) — este research recomenda `?contexto=` para não colidir com o `$setoresFiltro` organizacional já existente em `renderCarteirasConsolidadas` (ver seção "Duas noções de 'setor' — não confundir").
- Se os contadores agregados (`empresas_unicas`/`vinculos_servico`) aparecem só POR CARD (visão consolidada) ou também como banner de página — ver seção "UI de Carteira" e Assumptions Log (A1).
- Estrutura exata de `source_counts` (Phase 61, flag `unified_metrics_enabled`) após o refactor — se deve ficar restrita a `companyIdsElegiveis` ou continuar sobre todas as empresas do profissional.

### Deferred Ideas (OUT OF SCOPE desta fase)

- `DesempenhoScoreService::computeUniverso` e toda a Fase 91 (DESEMP-01..07) — NÃO tocar `DesempenhoScoreService.php` nesta fase
- `renderPortfolio()` (auto-visualização legada, `/admin/users/{user}/portfolio` quando `$atual->id === $user->id`) — mesmo bug de pivot/distinct já diagnosticado na Fase 89 (Pitfall 2), permanece fora do escopo — ver seção dedicada "renderPortfolio — known-gap" abaixo com a recomendação explícita
- Menu "Gestão ECF" — Fase 93 (MENU-01)
- Fonte financeira de Shopee (API/importação) — fora de escopo da milestone inteira
- NPS (quick tasks paralelas, ex. `260716-jps` conforme MEMORY.md) — zero overlap de arquivos esperado, mas não tocar nada em `app/Services/Nps/`, `NpsController`, `resources/js/Pages/Nps/*` nesta fase
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Research Support |
|----|-----------|-------------------|
| CART-06 | Cards por profissional com contagem correta (empresas únicas ≠ vínculos), sem puxar ML pra quem só cuida Shopee | §Anatomia atual de `renderCarteirasConsolidadas` + §Algoritmo de dedup entre profissionais (o "novo" bug, distinto do CART-04/05 da Fase 89) |
| CART-07 | Filtro de contexto Todos/Performance/Shopee + badges + contadores, nas DUAS telas | §UI de Carteira — o que falta em cada tela + §Duas noções de "setor" |
</phase_requirements>
</user_constraints>

## Summary

`renderCarteirasConsolidadas()` (`app/Http/Controllers/PortfolioController.php:561-734`) monta um card por analista/estrategista ativo usando `User::estrategistaCompanies()`/`consultorCompanies()` — as mesmas relações consolidadas por empresa que a Fase 88/89 já provaram serem a causa raiz do bug em `renderCarteiraProfissional()`. A boa notícia: essas duas relações **já têm** o dedup defensivo `->select('companies.*')->distinct('companies.id')` (correção da Phase 78, documentada em `User.php:206-245`), então não há risco de uma mesma empresa aparecer 2x na SOMA financeira de um card por causa de 2 linhas de pivot do mesmo papel — isso já está resolvido. O bug real de CART-06 é outro: essas relações filtram só por `role` da pivot (`consultor`/`estrategista`), **não por setor/serviço** — uma empresa onde o profissional só é responsável Shopee (`servico_id` apontando pra um serviço `setor='shopee'`) entra no mesmo `$companyIds` que as empresas de Performance dele, e a query `AdmanMetric::whereIn('company_id', $companyIds)` soma o faturamento ML dessa empresa (gerido por OUTRO profissional) como se fosse do dono do card. É o mesmo mecanismo do CART-04 da Fase 89, agora na função de cards em vez da função de carteira individual — a correção é a MESMA receita: trocar a origem por `CarteiraContextService::forUser($u, ['role' => ..., 'active' => true])` e filtrar a lista de `company_id` usada em `AdmanMetric` para conter só vínculos `financial_metrics_eligible=true`.

O segundo eixo (CART-06 "separando empresas únicas de vínculos de serviço") é puramente aditivo: `CarteiraContextService::contadores()` (Fase 88, já pronto) devolve exatamente o shape `{empresas_unicas, vinculos_servico, vinculos_financeiros, vinculos_sem_fonte_financeira}` que os cards precisam expor — não precisa reinventar a lógica de contagem, só chamar o método já testado.

O terceiro eixo (CART-07, filtro de contexto) é o único que introduz UI nova: hoje NENHUMA das duas telas (`AdminCarteira.jsx`, `Carteiras.jsx`) tem um seletor Todos/Performance/Shopee — a Fase 89 deliberadamente ficou só nos badges por vínculo (ver `89-RESEARCH.md` Assumption A2, "filtro completo fica para a Fase 90"). Como `CarteiraContextService::forUser()` já aceita `filters['setor']`, o trabalho de backend é passar um novo query param `?contexto=` adiante; o trabalho de frontend é o `<select>` + os contadores. Nenhuma mudança de schema, nenhuma lib nova.

**Achado que muda o escopo real da fase**: embora o ROADMAP nomeie a fase por `renderCarteirasConsolidadas`, o SC3 ("A UI de carteira — individual e consolidada — tem filtro de contexto funcional") força a fase a também tocar `renderCarteiraProfissional()` e `AdminCarteira.jsx` de novo (adicionar o filtro que a Fase 89 propositalmente não implementou). Isso não conflita com CART-01..05 (já implementados) — é aditivo (um `?contexto=` a mais nos `filters` do `forUser()` já existente).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Resolução de vínculos + contadores por profissional | API/Backend (`CarteiraContextService`, já pronto — Fase 88) | Database | Fase 90 é CONSUMIDOR, não produtor; `contadores()` já existe e está testado |
| Agrupamento por profissional + soma financeira gated por elegibilidade | API/Backend (`PortfolioController::renderCarteirasConsolidadas`) | Database (`AdmanMetric`, cache `AdmanService`) | Mesma lógica de negócio já usada em `renderCarteiraProfissional` (Fase 89) — replicar o padrão, não reinventar |
| Filtro de contexto (querystring → `CarteiraContextService` filters) | API/Backend (parse do `Request`) | — | `forUser()` já suporta `filters['setor']`; só falta o controller ler o query param e repassar |
| Seletor de contexto + badges + contadores na UI | Browser/Client (`Carteiras.jsx`, `AdminCarteira.jsx`) | — | Puramente apresentacional — backend já entrega os dados prontos |

## Anatomia atual de `renderCarteirasConsolidadas`

Função privada em `PortfolioController.php:561-734`, renderiza `Portfolio/Carteiras` (`resources/js/Pages/Portfolio/Carteiras.jsx`, confirmado por `Inertia::render('Portfolio/Carteiras', [...])` na linha 730). Chamada por `own()` (linha 538-549) em 2 ramos:
- Admin → `renderCarteirasConsolidadas($request)` sem filtro de setor organizacional
- Líder de setor → `renderCarteirasConsolidadas($request, $setoresIds)` com `$setoresIds` = setores organizacionais que o líder lidera (ver seção "Duas noções de 'setor'" — **não é o mesmo conceito** do filtro de contexto Performance/Shopee)

Passos internos:

1. **Período** (linhas 563-570): `?period=1|7|30|180` (dias) — vira `$dateFrom`/`$dateTo`. **Não muda** — independente da origem dos vínculos.
2. **Lista de profissionais** (linhas 582-605): `$analistas`/`$estrategistas` via `whereExists` em `user_setores` + `cargos.slug`, com filtro opcional por `$setoresFiltro` (organizacional). **Não muda.**
3. **Origem das empresas por card** (linha 619-621): `$u->estrategistaCompanies()->where('active', true)->get([...])` OU `consultorCompanies()` — **ESTE é o ponto a trocar** por `$this->carteiraContext->forUser($u, ['role' => $role, 'active' => true, 'setor' => $contextoFiltro])`.
4. **Agregação financeira por empresa** (linhas 628-679): `SUM(revenue)`/`SUM(ad_spend)`/`AVG(contribution_margin_pct)` via `AdmanMetric::whereIn('company_id', $companyIds)` + cache Adman gross/account por `custId` (mesmo padrão de `renderCarteiraProfissional`). Hoje `$companyIds` = **todas** as empresas do profissional naquele papel, sem filtro de elegibilidade financeira — é aqui que o CART-06 quebra.
5. **Payload do card** (linhas 691-701): `{ id, name, tipo, role, companies_count, avg_tacos, total_revenue, avg_margin, total_ad_spend }`. `companies_count` = `$companyIds->count()` — hoje conta empresas (já deduplicadas por `distinct('companies.id')` da relação), mas SEM separar "vínculos" de "empresas" porque a relação de origem não expõe essa granularidade.
6. **Enriquecimento condicional `source_counts`** (linhas 704-728, flag `unified_metrics_enabled`): recomputa `$companies` (de novo via `estrategistaCompanies()`/`consultorCompanies()`, SEM filtro de elegibilidade) e conta `{adman, ml, unified, none}` via `factoryToSource()`. Mesma origem antiga — precisa ser reapontada junto.

`Carteiras.jsx` (arquivo completo lido) hoje espera `user_portfolios: [{ id, name, tipo, companies_count, avg_tacos, total_revenue, avg_margin, total_ad_spend, source_counts? }]` — um card por profissional, grid `md:grid-cols-2 lg:grid-cols-3`, sem noção de setor/vínculo nem filtro de contexto. É a tela mais simples das duas (nenhuma tabela de empresas — isso só existe no drill-down `AdminCarteira.jsx`).

## Algoritmo de dedup entre profissionais (CART-06) — a mesma receita da Fase 89, aplicada aqui

O mecanismo é **idêntico** ao "Algoritmo de dedup financeiro" documentado em `89-RESEARCH.md` (CART-04/05), só que agora dentro do `map()` que monta cada card, em vez de dentro da função de carteira individual:

```php
$portfolios = $todos->map(function ($item) use ($dateFrom, $dateTo, $contextoFiltro) {
    $u    = $item['user'];
    $tipo = $item['tipo'];
    $role = $tipo === 'estrategista' ? 'estrategista' : 'consultor';

    // Troca: de $u->estrategistaCompanies()/consultorCompanies() para o service.
    $vinculos = $this->carteiraContext->forUser($u, [
        'role'   => $role,
        'active' => true,
        'setor'  => $contextoFiltro, // null quando "Todos"
    ]);

    if ($vinculos->isEmpty()) return null; // preserva o ->filter() atual

    // CART-06 ponto 1 — contadores prontos, sem reinventar.
    $contadores = $this->carteiraContext->contadores($vinculos);

    // CART-06 ponto 2 — dedup financeiro: MESMO pulo do gato da Fase 89
    // (89-RESEARCH.md §Algoritmo de dedup financeiro). ->unique() é o que
    // impede a query de AdmanMetric rodar 2x pra uma empresa onde o
    // profissional é responsável ML E Shopee.
    $companyIdsElegiveis = $vinculos
        ->where('financial_metrics_eligible', true)
        ->pluck('company_id')
        ->unique()
        ->values();

    if ($companyIdsElegiveis->isEmpty()) {
        // Profissional só-Shopee (ou filtro "Shopee" ativo): card existe,
        // mostra contadores, mas financeiro é 0/null — não zera o card
        // inteiro (CART-06 pede "aparece com contagem correta", não "some").
        return [
            'id' => $u->id, 'name' => $u->name, 'tipo' => $tipo, 'role' => $u->role,
            ...$contadores,
            'avg_tacos' => null, 'total_revenue' => 0.0, 'avg_margin' => null, 'total_ad_spend' => 0.0,
        ];
    }

    // Precisa carregar os Company models — CarteiraContextService não expõe
    // cust_id/adman_account_id/ml_store_id (granularidade de vínculo, não
    // de empresa) — mesmo motivo documentado em renderCarteiraProfissional.
    $companies = Company::whereIn('id', $companyIdsElegiveis)
        ->get(['id', 'adman_account_id', 'ml_store_id']);

    // ... resto do bloco de soma (SUM DB + cache gross/account) É O MESMO
    // CÓDIGO JÁ EXISTENTE (linhas 628-679), só trocando a fonte de $companies.

    return [
        'id' => $u->id, 'name' => $u->name, 'tipo' => $tipo, 'role' => $u->role,
        ...$contadores, // empresas_unicas, vinculos_servico, vinculos_financeiros, vinculos_sem_fonte_financeira
        'avg_tacos' => $tacosCarteira, 'total_revenue' => round($totalRevenue, 2),
        'avg_margin' => $avgMargin, 'total_ad_spend' => round($totalAdSpend, 2),
    ];
})->filter()->sortBy('name')->values();
```

Isso resolve os dois pontos do CART-06/ROADMAP SC1-SC2 ao mesmo tempo:
- **SC2** (Shopee-only não puxa ML): se TODOS os vínculos do profissional (naquele papel) para uma empresa forem Shopee, ela nunca entra em `companyIdsElegiveis` → `AdmanMetric` nunca é consultado pra ela nesse card.
- **SC1** (contagem correta, empresas ≠ vínculos): `$contadores['empresas_unicas']` conta `company_id` distintos; `$contadores['vinculos_servico']` conta o total de vínculos (sem colapsar) — a MESMA empresa com ML+Shopee do mesmo profissional aparece como **1** em `empresas_unicas` e **2** em `vinculos_servico`, exatamente o que SC1 pede.

**Pitfall a documentar para o executor** (mesmo do research da Fase 89, reaplicado aqui): a unidade de consulta a `AdmanMetric` é sempre `company_id` único (após `->unique()`), nunca por vínculo — iterar `$vinculos` e somar `AdmanMetric` por vínculo duplicaria o financeiro de uma empresa com 2 vínculos elegíveis do mesmo profissional.

### `companies_count` — chave legada, decisão de compatibilidade

O card atual expõe `companies_count` (consumido em `Carteiras.jsx:82`: `{u.companies_count} empresa{...}`). Duas opções pro planner:
1. **Substituir** por `empresas_unicas` (mesmo valor, nome novo) e atualizar o `.jsx` no mesmo plano — mais limpo, sem chave redundante.
2. **Manter `companies_count` como alias** de `empresas_unicas` (soma zero-risco pra quem já lê o payload) e adicionar as novas chaves ao lado.

Recomendação: opção 1 (substituir) — o `.jsx` já vai ser tocado nesta fase pra CART-07 (filtro/contadores), então não há custo extra em renomear a chave no mesmo commit; evita 2 chaves com o mesmo valor confundindo o próximo dev.

## `source_counts` (Phase 61 flag) — precisa da mesma correção

O bloco condicional (linhas 704-728) recomputa `$companies` via `estrategistaCompanies()`/`consultorCompanies()` de novo — SEM filtro de elegibilidade, mesmo bug do bloco principal. Se não for corrigido junto, o card mostraria `total_revenue`/`avg_tacos` corretos (gated) mas `source_counts` (badges ML/Adman/Unified/Sem integração) contando a empresa Shopee-only como se fosse uma "fonte" do profissional — inconsistência visível na mesma UI. Recomendação: reaproveitar `$companyIdsElegiveis` (ou os `$vinculos` já resolvidos) do bloco principal em vez de recomputar as relações — elimina a query duplicada E a divergência.

## UI de Carteira — o que falta em cada tela (CART-07)

### `Portfolio/Carteiras.jsx` (consolidada — alvo principal desta fase)

Hoje: grid de cards, cada um com `TACOS Médio`/`Faturamento`/`Margem Méd.`/`Gasto Ads` + mini-legenda `source_counts` opcional. Falta:
- **Seletor de contexto** (Todos/Performance-ML/Shopee) ao lado do seletor de período já existente (`PERIOD_OPTIONS`), disparando `router.get(route('portfolio.own'), { period, contexto })`.
- **Contadores por card**: trocar a linha `{u.companies_count} empresa{...}` por algo como `{u.empresas_unicas} empresa{s} · {u.vinculos_servico} vínculo{s}` (usa `SETOR_LABELS`/badge visual consistente com `AdminCarteira.jsx`, reaproveitar o mesmo padrão de cor amarelo/âmbar já usado lá para elegível/sem-fonte).
- **Badge "sem fonte financeira" no card**: quando `vinculos_sem_fonte_financeira > 0`, um chip pequeno âmbar (mesmo tom de `AdminCarteira.jsx:406`) avisando quantos vínculos do profissional não têm fonte.

### `Portfolio/AdminCarteira.jsx` (individual — já tem os badges por vínculo da Fase 89, falta o filtro + contadores de topo)

Já tem (Fase 89): badges `servicos[]` por linha de empresa (`SETOR_LABELS`, chip amarelo elegível / âmbar sem-fonte). Falta:
- **Seletor de contexto** no header (mesmo padrão do seletor de mês já existente, linhas 159-188) — `router.visit(currentPath + '?contexto=' + valor + '&mes=' + mesAtual, ...)` (preservar o `?mes=` já ativo).
- **Contadores no topo**: `resumo.total_empresas` já existe; adicionar `resumo.vinculos_servico`/`resumo.vinculos_sem_fonte_financeira` ao objeto `resumo` (backend) e um card/linha pequena exibindo "X empresas únicas · Y vínculos de serviço" próximo ao cabeçalho.

### Comportamento esperado do filtro (ambas as telas, conforme plano canônico)

```text
Filtro = Todos:      mostra tudo; financeiro soma só vínculos elegíveis (comportamento já correto pós-fix).
Filtro = Performance: equivalente a filters['setor'] = Servico::SETOR_PERFORMANCE — só vínculos de Performance aparecem.
Filtro = Shopee:      equivalente a filters['setor'] = Servico::SETOR_SHOPEE — só vínculos Shopee aparecem,
                      financeiro sempre "sem fonte financeira" (nunca mostra R$ de ML).
```

Como `CarteiraContextService::forUser()` já resolve o filtro de setor internamente (linha 119-121 do service: `if (! empty($filters['setor'])) { $vinculos = $vinculos->where('setor', $filters['setor']); }`), o controller só precisa mapear o valor da query string pro slug do setor (`Servico::SETOR_PERFORMANCE`/`Servico::SETOR_SHOPEE`) e passar adiante — zero lógica de filtro nova no controller.

## Duas noções de "setor" — não confundir (pitfall de nomenclatura)

`renderCarteirasConsolidadas()` já usa a palavra "setor" para outra coisa: `$setoresFiltro` (parâmetro posicional vindo de `own()`) filtra **organizacionalmente** quais analistas/estrategistas aparecem no grid, baseado em `setores`/`user_setores` (o líder do setor Shopee só vê profissionais do setor Shopee, por exemplo). Isso é ORTOGONAL ao filtro de contexto do CART-07 (que filtra, DENTRO do card de um profissional já visível, quais VÍNCULOS DE EMPRESA aparecem, baseado em `servicos.setor`). Os dois conceitos usam a tabela/coluna `setor`, mas em domínios diferentes (setor ORGANIZACIONAL do profissional vs. setor do SERVIÇO/vínculo com a empresa).

**Recomendação**: nomear o novo query param `?contexto=` (não `?setor=`) e a variável local `$contextoFiltro` no controller, para eliminar qualquer ambiguidade com `$setoresFiltro` já existente no mesmo método. Mapear os 3 valores aceitos (`todos`/`performance`/`shopee`, default `todos`) para o `filters['setor']` do service só na hora de chamar `forUser()`.

## `renderPortfolio()` — known-gap (recomendação: NÃO fechar nesta fase)

O prompt de pesquisa pergunta se a Fase 90 é o lugar de resolver o `pivot->role` quebrado de `renderPortfolio()` (auto-visualização legada). Reconfirmado por leitura direta nesta sessão:

- `renderPortfolio()` (linhas 736-1655, ~920 linhas) usa `$user->companies()->with([...])->where('active', true)->withPivot('role')->orderBy('name')->get()` (linha 773-778) e depois `'role' => $c->pivot->role` (linha 952). A base `companies()` (`User.php:206-221`) já tem `->select('companies.*')->distinct('companies.id')`, mas o `->withPivot('role')` encadeado DEPOIS adiciona a coluna `company_users.role` ao SELECT — se a mesma empresa tiver 2 linhas de pivot com `role` diferente para o mesmo usuário (ex.: consultor em Performance E estrategista em Shopee, hipoteticamente, ou 2 linhas de servico_id distintos com roles diferentes), o `DISTINCT` deixa de colapsar porque agora a linha inteira (incluindo `role`) difere entre as duas — a empresa pode aparecer 2x na listagem, com roles diferentes por linha. **Confirma o pitfall já documentado em `89-RESEARCH.md` Pitfall 2/6** (mesma leitura de código, linhas atualizadas pelo crescimento da Fase 89: 773→952 hoje vs. 670→849 na sessão da Fase 89).

**Recomendação — NÃO fechar nesta fase**, por 3 motivos:
1. **Fora do escopo das REQs**: nem CART-06 nem CART-07 mencionam `renderPortfolio()`; o plano canônico (`Fase 3 - Carteiras consolidadas`) só cita `renderCarteirasConsolidadas()`.
2. **Não é barato**: ao contrário do que o prompt de pesquisa sugere ("se fechar for barato, incluir"), `renderPortfolio()` é uma função de ~920 linhas com lógica de negócio pesada e não relacionada a setor/serviço (metas, grants, séries temporais de faturamento, alertas, comparação com pares, `DesempenhoScoreService::computeCached`) — o `$c->pivot->role` é usado só para exibir 1 badge de papel na tabela (linha 952), não para nenhum cálculo financeiro. Trocar a origem para `CarteiraContextService` exigiria replicar TODA a lógica já refeita em `renderCarteiraProfissional` (Fase 89) dentro desta outra função, ou fundir as duas funções — trabalho de escopo comparável a uma fase inteira, não um ajuste pontual.
3. **Exposição real é baixa**: a rota só executa este código quando `$atual->id === $user->id` DENTRO de `/admin/users/{user}/portfolio` (prefixo admin) — o fluxo principal de auto-visualização (`/portfolio` via `own()`) já usa `renderCarteiraProfissional()` corrigida desde o Ajuste 2026-07-09 v5. Acessar a própria carteira pela rota admin é tecnicamente possível mas incomum.

Ação recomendada: manter como **débito técnico documentado** (já está, desde a Fase 89) — se o planner quiser, adicionar 1 linha no SUMMARY desta fase reafirmando que o gap persiste, sem abrir tarefa nova. Se o usuário priorizar consolidar `renderPortfolio()`/`renderCarteiraProfissional()` numa função só no futuro, isso merece fase própria (fora do escopo v17.0, que é sobre elegibilidade financeira multi-serviço, não sobre eliminar duplicação de código entre as duas funções de carteira).

## Cache / performance

- `CarteiraContextService::forUser()` **sem cache** (decisão documentada no próprio service — só queries locais sobre ~268 linhas de pivot em prod, zero HTTP). Chamar 1x por profissional (N chamadas pro grid de cards) é trivial — mesmo padrão já usado em `renderCarteiraProfissional`.
- `RefreshGrossBillingCacheJob` (`app/Jobs/RefreshGrossBillingCacheJob.php`) pré-aquece o cache `AdmanService` (`getCachedGrossBillingsMany`/`getCachedAccountMetricsMany`) a cada 30min, TTL 24h por chave-dia — **não relacionado ao `CarteiraContextService`**, roda sobre TODAS as empresas ativas independente de quem é responsável. Nenhuma mudança necessária aqui; `renderCarteirasConsolidadas()` já só LÊ esse cache (nunca chama a API Adman diretamente), então a troca de origem de empresas não aumenta tráfego HTTP.
- **Atenção N+1 nova**: o refactor introduz `Company::whereIn('id', $companyIdsElegiveis)->get([...])` **dentro do `map()` por profissional** (1 query por card, ~10-30 profissionais em prod) — mesmo padrão já aceito em `renderCarteiraProfissional` (Fase 89), não é regressão de performance relevante dado o volume. Se o número de profissionais crescer muito, uma otimização futura seria pré-carregar todas as `Company` referenciadas por QUALQUER `$vinculos` de QUALQUER profissional ANTES do loop (1 query em vez de N) — não necessário para o volume atual, mas vale nota no PLAN como possível ajuste se `php artisan test` acusar timeout.
- Nenhuma mudança de TTL/chave de cache necessária — diferente do pitfall conhecido de `DesempenhoScoreService::computeCached()` (que É versionado por chave e exigiria bump em mudança de shape); esta fase não mexe nesse service.

## Pitfalls

### Pitfall 1: `AdmanMetric` é por-empresa, não por-vínculo (mesmo da Fase 89)
Ver "Algoritmo de dedup entre profissionais" acima. Consultar sempre por `company_id` ÚNICO após `->unique()`, nunca iterando vínculos — vale tanto para o bloco principal quanto para `source_counts`.

### Pitfall 2: `source_counts` recomputa a relação antiga (Phase 61) — precisa do MESMO fix
Ver seção dedicada acima. Se só o bloco financeiro principal for corrigido e o bloco `source_counts` (linhas 704-728) ficar intocado, a mini-legenda ML/Adman/Unified/Sem-integração do card volta a contar empresas Shopee-only como se fossem do profissional — regressão visível na mesma tela que acabou de ser corrigida.

### Pitfall 3: duas noções de "setor" no mesmo método
Ver seção "Duas noções de 'setor'". Usar `?contexto=` (não `?setor=`) para o novo filtro evita colidir semanticamente com `$setoresFiltro` (organizacional) já existente no mesmo método.

### Pitfall 4: `companies_count` é chave consumida pelo frontend — não remover sem atualizar o `.jsx` no mesmo commit
Ver seção "companies_count — chave legada". Se o backend parar de emitir `companies_count` sem o `.jsx` ser atualizado no mesmo plano, o card quebra silenciosamente (`undefined empresas`).

### Pitfall 5: `renderPortfolio()` fica com o bug antigo — não é regressão desta fase, é debt pré-existente
Ver seção dedicada. Não confundir "não corrigido" com "quebrado por esta fase" — o código não é tocado, o comportamento não muda (nem piora nem melhora).

### Pitfall 6: `npm run build` obrigatório se `Carteiras.jsx`/`AdminCarteira.jsx` mudarem
Convenção do projeto (`CLAUDE.md`) — sem hot-reload em produção/verificação, qualquer mudança `.jsx` exige rebuild antes de considerar a task completa.

### Pitfall 7: dev paralelo — módulo MLB/Anúncios (Fases 82-87) e possíveis quick tasks de NPS
Fases 82/83 (planilha `glide-data-grid`) seguem em andamento por outro dev em `resources/js/Pages/Mlb/AnunciarMassa.jsx` e arquivos relacionados — zero overlap de arquivos com esta fase (`Portfolio/*`, `PortfolioController.php`). Segundo o `MEMORY.md` do projeto, pode haver quick tasks de NPS em paralelo (ex. referência `260716-jps` mencionada no prompt de pesquisa, não encontrada no repo nesta sessão — `[ASSUMED]`, tratar como aviso preventivo). Esta fase não toca `app/Services/Nps/`, `NpsController.php` nem `resources/js/Pages/Nps/*` — zero overlap esperado, mas vale rodar `git status` antes de commitar pra confirmar que nenhum arquivo de fora do escopo foi tocado por engano.

### Pitfall 8: empresas de demonstração/teste ativas em prod (`active=true`) entram nos cards normalmente
Nenhuma empresa com nome contendo "Teste"/"Demo" foi encontrada nos arquivos lidos nesta sessão (`[ASSUMED]` — não confirmado por grep no banco de prod, só ausência de menção nos arquivos `.planning/` locais). Se existirem, elas SEGUEM aparecendo nos cards normalmente (mesmo comportamento de hoje) — o filtro `active=true`/`ativo=true` já existente em `CarteiraContextService`/`consultorCompanies()` não distingue empresa real de empresa de teste. Fora de escopo desta fase corrigir isso; mencionar apenas como possível ruído visual ao validar em prod/staging.

## Testes obrigatórios (do plano canônico + REQUIREMENTS)

- Card do profissional Shopee-only (numa empresa que também tem ML gerido por outro) NÃO mostra `total_revenue`/`avg_margin`/`avg_tacos` de ML (CART-06 ponto 2 — espelha `test_shopee_only_analista_nao_herda_financeiro_ml` da Fase 89, mas no contexto de `renderCarteirasConsolidadas`)
- Card do profissional com ML+Shopee na MESMA empresa não duplica `total_revenue` (espelha `test_ml_e_shopee_mesma_empresa_nao_duplica_financeiro` da Fase 89)
- Card mostra `empresas_unicas` ≠ `vinculos_servico` quando o profissional tem 2 vínculos (ML+Shopee) na mesma empresa — o teste mais direto do CART-06 ponto 1
- Regressão: card de analista ML comum (sem Shopee) continua com `total_revenue`/`avg_tacos`/`avg_margin` corretos (não pode quebrar o caso majoritário)
- Filtro `?contexto=performance` na consolidada mostra só profissionais/cards com vínculo Performance (ou zera o financeiro de quem só tem Shopee, dependendo do desenho — decisão do planner: esconder card vs. zerar financeiro, ver Open Questions)
- Filtro `?contexto=shopee` na consolidada NUNCA mostra R$ de ML em nenhum card
- Filtro de contexto na individual (`renderCarteiraProfissional`) funciona idêntico ao da consolidada (mesmo `filters['setor']` do service)
- `source_counts` (flag `unified_metrics_enabled`) não conta mais empresa Shopee-only como fonte do profissional

### Fixtures reutilizáveis
`tests/Feature/V16/CriaCenarioResponsaveis.php` (trait, `criarCenarioMlComResponsaveis()`, `inserirLinhaShopee()`, `criarServico()`, `criarContrato()`, `inserirPivot()`) — mesma trait usada pela Fase 89, cobre 100% dos cenários acima sem fixtures novas. `tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php` é o precedente direto de shape de teste (equivalente exato, trocando a rota `portfolio.show` por `portfolio.own` como admin).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (DB_CONNECTION=sqlite, DB_DATABASE=:memory:) |
| Quick run command | `php artisan test --filter=NomeDoTeste` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CART-06 | Cards separam empresas únicas de vínculos; Shopee-only não herda ML | Feature (controller) | `php artisan test --filter=CarteirasConsolidadasContextoTest` | ❌ Wave 0 |
| CART-07 | Filtro de contexto funcional nas 2 telas + contadores | Feature (controller) | `php artisan test --filter=CarteirasConsolidadasContextoTest` (backend) — UI só via checkpoint visual | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter={TestClassDaTask}`
- **Per wave merge:** `php artisan test --filter=V16` (suíte V16 completa — baseline de regressão herdada da Fase 88/89) + `php artisan test --filter=Portfolio`
- **Phase gate:** `php artisan test` completo verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/V16/CarteirasConsolidadasContextoTest.php` — cobre CART-06 (dedup entre profissionais) + CART-07 backend (filtro `?contexto=`)
- [ ] Nenhum framework novo a instalar — PHPUnit 11.x já configurado, trait de fixtures já existe (`CriaCenarioResponsaveis`)

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V4 Access Control | yes | `own()` já gate por `isAdmin()`/`isLider()` (linha 541-548) — inalterado por esta fase; `$setoresFiltro` organizacional já restringe líderes aos próprios setores |
| V5 Input Validation | yes | Novo `?contexto=` deve ser validado contra whitelist (`todos`/`performance`/`shopee`) antes de repassar a `CarteiraContextService` — nunca aceitar valor arbitrário como `setor` (mesmo que o service não faça SQL injection, um valor inválido silenciosamente devolveria lista vazia sem erro, confundindo o usuário) |
| V2/V3/V6 | no | Sem autenticação/sessão/criptografia nova nesta fase |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Vazamento de dados financeiros entre setores nos cards consolidados (mesmo bug do CART-04, agora multi-profissional) | Information Disclosure | É exatamente o que CART-06 corrige — gate por `financial_metrics_eligible` na query de `AdmanMetric`, por card |
| Filtro `?contexto=` com valor arbitrário causando comportamento indefinido | Improper Input Handling | Whitelist explícita (`todos`/`performance`/`shopee`) no controller antes de repassar ao service |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|----------------|
| A1 | Os "contadores no topo da tela" (SC4) na visão CONSOLIDADA significam contadores POR CARD (não um banner agregado de página) — o plano canônico só desenha esse banner explicitamente para a visão INDIVIDUAL (`plano-carteira-desempenho-multi-servico.md` §UI de Carteira) | §UI de Carteira — o que falta em cada tela | Se o usuário esperar um banner agregado no topo de `Carteiras.jsx` somando `vinculos_servico` de todos os profissionais visíveis, a leitura "por card" cobre menos que o esperado — risco médio, recomenda-se checkpoint visual ou pergunta explícita no plan-check |
| A2 | `renderPortfolio()` permanece fora do escopo (não corrigido) — decisão explícita desta pesquisa, não do usuário diretamente | §renderPortfolio — known-gap | Se o usuário considerar isso bloqueante para v17.0 fechar, precisa virar fase própria — baixo risco dado que os 2 fluxos principais (`/portfolio` e cards admin) já usam as funções corrigidas |
| A3 | Referência a quick task `260716-jps` (NPS, dev paralelo) não encontrada no repo nesta sessão — tratada como aviso preventivo do prompt de pesquisa, não verificada | §Pitfall 7 | Risco baixo — mesmo que a referência esteja desatualizada/incorreta, a recomendação (não tocar `app/Services/Nps/`) é segura por padrão e não custa nada seguir |

**Se esta tabela estivesse vazia**: não estaria — 3 pontos precisam de confirmação/checkpoint antes ou durante o plan-check.

## Open Questions

1. **Quando o filtro `?contexto=shopee` está ativo e um profissional não tem NENHUM vínculo Shopee, o card dele desaparece da grid ou aparece zerado ("0 vínculos")?**
   - O que sabemos: `CarteiraContextService::forUser()` com `filters['setor']='shopee'` devolve Collection vazia para esse profissional.
   - O que é incerto: o `->filter()` atual (linha 702: `})->filter()->sortBy('name')->values()`) já remove profissionais com `$vinculos->isEmpty()` — aplicado ao filtro de contexto, isso faria profissionais 100%-Performance sumirem da grid quando o filtro é "Shopee". Comportamento razoável (não faz sentido mostrar card vazio), mas vale confirmar que é o esperado antes de travar.
   - Recomendação: manter o comportamento de "desaparecer" (consistente com o `->filter()` já existente) — documentar explicitamente no PLAN para não virar bug reportado ("cadê o card da Ana quando filtro Shopee?").

2. **O banner agregado de contadores no topo de `Carteiras.jsx` (se decidido incluir, ver A1) deve somar `empresas_unicas` entre profissionais, mesmo sabendo que isso NÃO é "empresas únicas da organização" (uma empresa com 2 profissionais responsáveis contaria 2x)?**
   - O que sabemos: somar `vinculos_servico` entre cards é aditivamente correto (cada vínculo pertence a exatamente 1 profissional).
   - O que é incerto: somar `empresas_unicas` entre cards infla a contagem real de empresas distintas da organização.
   - Recomendação: se o banner agregado for incluído, rotular como "Vínculos exibidos: N" (soma correta) e evitar rotular como "Empresas únicas da organização: N" (soma incorreta) — ou computar um `distinct()` verdadeiro sobre TODOS os `company_id` de TODOS os vínculos combinados, separado dos contadores por-profissional.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHPUnit | Testes Feature | ✓ | 11.5.50 | — |
| SQLite (`:memory:`) | Testes Feature (phpunit.xml) | ✓ (embutido no PHP) | — | — |
| MySQL/MariaDB local (XAMPP) | Verificação manual/dev local (NÃO necessário para testes) | ✗ (incidente `project_mariadb_local_corrompido.md`, não resolvido desde 2026-06-25) | — | Testes rodam 100% em SQLite; verificação real-world só via VPS |
| Node.js/npm (`npm run build`) | Só se `Carteiras.jsx`/`AdminCarteira.jsx` forem editados | ✓ | v24.15.0 | — |

**Missing dependencies with no fallback:** nenhuma.

## Package Legitimacy Audit

Não aplicável — esta fase não instala pacotes externos novos (refatoração de controller PHP + evolução de componentes React já existentes, reaproveitando dependências já presentes no `composer.json`/`package.json`).

## Sources

### Primary (HIGH confidence — leitura direta de código neste repositório)
- `app/Http/Controllers/PortfolioController.php` (`show`, `own`, `renderCarteiraProfissional`, `renderCarteirasConsolidadas` linhas 561-734, `renderPortfolio` linhas 736-1655 — lido por completo)
- `app/Services/Portfolio/CarteiraContextService.php` (Fase 88, `forUser`/`contadores` — lido por completo, incluindo docblocks de decisão)
- `app/Models/User.php` (`companies()`, `consultorCompanies()`, `estrategistaCompanies()`, `setoresLiderados()`, `isLider()`)
- `app/Models/Company.php` (`getCustIdAttribute`, `analistaPerformance()`, `estrategistaPerformance()`, `mlToken()`)
- `app/Models/Servico.php` (constantes `SETOR_*`)
- `app/Jobs/RefreshGrossBillingCacheJob.php` (lido por completo — mecanismo de cache warm-up)
- `resources/js/Pages/Portfolio/Carteiras.jsx` (lido por completo)
- `resources/js/Pages/Portfolio/AdminCarteira.jsx` (lido por completo)
- `tests/Feature/Portfolio/RenderPortfolioTest.php`, `tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php`, `tests/Feature/V16/CriaCenarioResponsaveis.php` (lidos por completo — padrões de teste/fixture)
- `.planning/phases/89-carteira-individual-.../89-RESEARCH.md` (lido por completo — precedente direto, Pitfalls 2/3/6 e Assumptions A1-A3 diretamente relevantes para esta fase)
- `.planning/phases/89-carteira-individual-.../deferred-items.md`
- `plano-carteira-desempenho-multi-servico.md` (plano canônico do usuário — seções "Fase 3", "UI de Carteira")
- `.planning/ROADMAP.md` (Phase 88-90, texto completo)
- `.planning/REQUIREMENTS.md` (CART-06, CART-07)
- `.planning/config.json` (`nyquist_validation: true`, `security_enforcement` ausente = habilitado)

### Tertiary (LOW confidence — não verificado)
- Quick task `260716-jps` (NPS, dev paralelo) mencionado no prompt de pesquisa — não localizado em `.planning/quick/` nesta sessão

## Metadata

**Confidence breakdown:**
- Anatomia de `renderCarteirasConsolidadas` e algoritmo de dedup entre profissionais: HIGH — código lido linha a linha, mesmo padrão já provado na Fase 89 (mesmo service, mesmo algoritmo, função diferente)
- `renderPortfolio` known-gap (recomendação de não fechar): HIGH — leitura direta confirma o bug e o tamanho/complexidade da função que tornaria o fix caro
- UI de Carteira (CART-07, o que falta em cada tela): HIGH quanto ao que falta hoje (leitura direta dos 2 `.jsx`); MEDIUM quanto ao desenho exato do "contador no topo" da visão consolidada (ambiguidade do plano canônico — ver Assumption A1)
- `source_counts`/cache/performance: HIGH — leitura direta do bloco condicional e do job de warm-up

**Research date:** 2026-07-16
**Valid until:** 30 dias (schema estável desde a Fase 76; `CarteiraContextService` estável desde a Fase 88; único risco de staleness é se a Fase 91 (Desempenho) for planejada/executada antes e mudar algo em `DesempenhoScoreService` que esta fase referencia só como "fora de escopo")
