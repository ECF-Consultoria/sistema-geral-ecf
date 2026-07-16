# Phase 89: Carteira individual — renderCarteiraProfissional por contexto (v17.0) - Research

**Researched:** 2026-07-16
**Domain:** Refatoração de controller Laravel (leitura) sobre schema já existente + ajuste de presentational JSX (Inertia) — zero libs novas
**Confidence:** HIGH (100% código lido diretamente neste repositório; nenhuma dependência externa; único ponto MEDIUM é a decisão AND→OR da pendência `sem_responsavel`, que vem de um quick task descartado e não pôde ser lido — ver Assumptions Log)

<user_constraints>
## User Constraints (from REQUIREMENTS.md / plano canônico)

### Locked Decisions (REQUIREMENTS.md CART-01..05, CART-08 — Fase 89)

- **CART-01**: A carteira individual (`renderCarteiraProfissional`) usa `CarteiraContextService`; empresa com Performance + Shopee aparece UMA vez como empresa, podendo exibir dois vínculos de serviço
- **CART-02**: Vínculo Shopee aparece na carteira com estado explícito "sem fonte financeira", sem faturamento/margem de ML
- **CART-03**: Soma financeira (`SUM(revenue)`, `SUM(contribution_margin)`, `ad_spend`, `tacos`) considera apenas vínculos com `financial_metrics_eligible = true`
- **CART-04**: Profissional responsável APENAS por Shopee de uma empresa que também tem ML NÃO recebe faturamento/margem de ML como se fosse dele
- **CART-05**: Profissional responsável por ML E Shopee da mesma empresa NÃO duplica faturamento no filtro "Todos" (métrica ML contada uma vez)
- **CART-08**: A tela `/companies` (painel Performance) exibe o responsável do SERVIÇO DE PERFORMANCE na coluna Analista/Estrategista — nunca o responsável Shopee; a pendência "sem responsável" acusa falta do responsável de performance especificamente

### Claude's Discretion (não há CONTEXT.md desta fase — sem discuss-phase rodado; decisões abaixo vêm do ROADMAP.md + plano canônico + ADR já executado)

- Estrutura exata do payload `empresas[]` do `Portfolio/AdminCarteira.jsx` (agrupamento por empresa com sub-vínculos vs. lista achatada) — este research recomenda uma estrutura específica na seção UI abaixo, mas a decisão final de shape é do planner/executor.
- Se o filtro de contexto completo (Todos/Performance/Shopee) e os contadores de topo (badges, "empresas únicas: N") entram nesta fase ou ficam só para a Fase 90 — ver seção "Fronteira 89 vs 90" abaixo. Recomendação: MÍNIMO necessário para CART-01/02 nesta fase; filtro completo + contadores fica para a Fase 90 (tem `UI hint: yes` explícito; Fase 89 não tem essa marca no ROADMAP).

### Deferred Ideas (OUT OF SCOPE desta fase)

- `renderCarteirasConsolidadas()` (cards admin) — Fase 90 (CART-06, CART-07)
- Filtro de contexto Todos/Performance/Shopee completo na UI + contadores de topo — Fase 90 (CART-07)
- `DesempenhoScoreService::computeUniverso` — Fase 91 (DESEMP-01..07), NÃO tocar nesta fase
- Menu "Gestão ECF" — Fase 93 (MENU-01)
- Fonte financeira de Shopee (API/importação) — fora de escopo da milestone inteira; vínculos Shopee ficam `financial_metrics_eligible=false` até segunda ordem
- `renderPortfolio()` (a OUTRA função do `PortfolioController`, atrás da rota `/admin/users/{user}/portfolio` quando o próprio user visita a própria carteira) — plano canônico só menciona `renderCarteiraProfissional()` na Fase 2; `renderPortfolio()` não está no escopo de nenhuma REQ desta fase (ver Pitfall 6)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Research Support |
|----|-----------|-------------------|
| CART-01 | Empresa Performance+Shopee aparece 1x, com 2 vínculos exibidos | §Anatomia atual de `renderCarteiraProfissional` + §Desenho recomendado do payload |
| CART-02 | Vínculo Shopee com estado "sem fonte financeira" explícito | §Desenho recomendado do payload + §UI mínima da Fase 89 |
| CART-03 | SUM(revenue/margem)/ad_spend/tacos só de vínculos elegíveis | §Gap: ad_spend/tacos NÃO existem hoje em `renderCarteiraProfissional` (achado crítico) |
| CART-04 | Shopee-only não herda financeiro ML da mesma empresa | §Algoritmo de dedup financeiro (CART-04+CART-05) |
| CART-05 | ML+Shopee mesma empresa não duplica financeiro | §Algoritmo de dedup financeiro (CART-04+CART-05) |
| CART-08 | `/companies` mostra responsável de Performance, nunca Shopee | §CART-08 — relações novas no Company model + call-sites intocados |
</phase_requirements>
</user_constraints>

## Summary

`renderCarteiraProfissional()` (`app/Http/Controllers/PortfolioController.php:114-423`) hoje monta a carteira de UM profissional inteiramente a partir de `$user->companies()` — a relação consolidada por empresa que NÃO distingue setor de serviço. Toda a lógica de soma financeira (faturamento, margem, variação MoM com recorte de dias comuns) já é sofisticada e correta *dentro de uma empresa*; o problema é que ela roda sobre `companyIds` vindos de TODAS as empresas do profissional, sem checar se aquele vínculo específico é elegível financeiramente. `CarteiraContextService::forUser()` (Fase 88, já pronto e testado — 12/12 verdes) resolve exatamente essa lacuna: substitui `$user->companies()` por uma Collection de VÍNCULOS (não empresas), cada um com `financial_metrics_eligible` já calculado por `servicos.setor`.

A refatoração central da Fase 2 do plano canônico é: (1) trocar a origem de empresas por `CarteiraContextService::forUser($user)`; (2) agrupar os vínculos por `company_id` para exibição (1 linha de empresa, N sub-linhas de vínculo); (3) filtrar a lista de `company_id` usada nas queries de `AdmanMetric` para conter APENAS empresas onde o profissional tem AO MENOS UM vínculo elegível (`financial_metrics_eligible=true`) — e mesmo assim, consultar `AdmanMetric` UMA VEZ por `company_id` (nunca por vínculo), porque a tabela é por empresa, não por serviço. Esse último ponto é o mecanismo real por trás de CART-04 e CART-05 e está detalhado na seção "Algoritmo de dedup financeiro" abaixo.

**Achado crítico não previsto no ROADMAP**: `renderCarteiraProfissional()` hoje NÃO calcula `ad_spend` nem `tacos` — só `faturamento` (revenue) e `margem_contribuicao`. Mas CART-03 exige que a soma de `ad_spend` e `tacos` também respeite `financial_metrics_eligible`. Isso significa que a Fase 89 precisa ADICIONAR esses dois campos à função (usando o mesmo padrão já usado em `renderPortfolio()` e `renderCarteirasConsolidadas()`, que já leem `getCachedAccountMetricsMany` para `investment`/`tacos`), não apenas filtrar campos que já existem. Ver seção dedicada.

**Segundo achado**: a tela `/companies` (CART-08) tem seu lado de ESCRITA já correto — `CompanyController::update()` e `bulkAssign()` já gravam `servico_id` escopado ao contrato Performance ativo via o helper privado `servicoPerformanceAtivoId()` (trabalho da Fase 76). O bug de CART-08 é 100% do lado de LEITURA: `Company::consultor()`/`estrategista()` (usadas em `index()` e `show()`) não filtram por `servico_id`/setor — misturam responsável ML e Shopee na mesma relação, então `$c->consultor->first()` pega qualquer um dos dois, não necessariamente o de Performance.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Resolução de vínculos por profissional | API/Backend (`CarteiraContextService`, já pronto) | Database | Fase 88 já entrega isso; Fase 89 é CONSUMIDOR, não produtor |
| Agrupamento vínculo→empresa + soma financeira gated | API/Backend (`PortfolioController::renderCarteiraProfissional`) | Database (`AdmanMetric`) | Lógica de negócio de agregação financeira; não pertence ao frontend |
| Resolução de responsável de Performance por empresa | API/Backend (`Company` model — novas relações `analistaPerformance()`/`estrategistaPerformance()`) | Database (`company_users`, `servicos`, `contratos_servico`) | Espelha `consultorDoServico()`/`estrategistaDoServico()` já existentes; é leitura, não deve virar lógica de controller |
| Exibição de "sem fonte financeira" por vínculo | Browser/Client (`Portfolio/AdminCarteira.jsx`) | — | Puramente apresentacional — o backend já entrega a flag `financial_metrics_eligible` pronta |

## Anatomia atual de `renderCarteiraProfissional`

Função privada em `PortfolioController.php:114-423`, renderiza `Portfolio/AdminCarteira.jsx` (confirmado por `Inertia::render('Portfolio/AdminCarteira', [...])` na linha 396). Chamada por dois fluxos (linha 92 e linha 445):
- `show()`: admin/líder abrindo carteira de OUTRO profissional (`/admin/users/{user}/portfolio`)
- `own()`: o PRÓPRIO profissional (não-admin, não-líder) abrindo `/portfolio`

Passos internos (todos precisam ser adaptados):

1. **Janela de datas** (linhas 125-153) — cálculo de mês em curso vs. mês fechado, com comparação dia-a-dia acumulada. **Não muda** — é lógica de calendário, independente de origem de empresas.
2. **Origem das empresas** (linha 156-161): `$user->companies()->with('mlToken')->where('active', true)->orderBy('name')->get()` → **ESTE é o ponto a trocar** por `CarteiraContextService::forUser($user, ['active' => true])`.
3. **Agregação `AdmanMetric`** (linhas 172-231): duas queries `SUM(revenue)`/`SUM(contribution_margin)` (janela atual + anterior) via `whereIn('company_id', $companyIds)`, mais 2 queries de "dias com margem" para o recorte de dias comuns (fix Tomelin/LOJASINVAL, `2026-07-13`). `$companyIds` hoje = `$rawCompanies->pluck('id')` (já distinct, pois vem de `$user->companies()`). **Precisa virar**: lista de `company_id` ÚNICA, mas filtrada para conter só empresas com `financial_metrics_eligible=true` — ver "Algoritmo de dedup financeiro" abaixo.
4. **Cache Adman gross** (linhas 233-240): `getCachedGrossBillingsMany($custIds, ...)` — usa `$rawCompanies->map(fn($c) => $c->cust_id)`. Mesma observação: `$custIds` deve vir só das empresas elegíveis.
5. **Map por empresa** (linhas 242-351): monta `faturamento`, `margem_contribuicao`, `margem_contribuicao_anterior`, `margem_variacao_pct`, `motivo_sem_margem`, `has_ml_oauth`. **NÃO tem `ad_spend` nem `tacos`** (achado crítico, ver seção própria).
6. **Totais consolidados** (linhas 353-375): soma simples de `$empresas` já filtrado — se o passo 5 já filtrar/marcar corretamente, os totais herdam a correção automaticamente.
7. **Payload Inertia** (linhas 396-422): `profissional`, `resumo`, `empresas`, `periodo`.

O `Portfolio/AdminCarteira.jsx` (arquivo completo lido) hoje espera `empresas: [{ id, name, faturamento, margem_contribuicao, margem_contribuicao_anterior, margem_variacao_pct, has_ml_oauth, motivo_sem_margem }]` — uma linha por EMPRESA (não por vínculo), sem noção de setor/serviço. É o ponto central a evoluir para CART-01/02.

## Algoritmo de dedup financeiro (CART-04 + CART-05) — o critério mais sutil da fase

`AdmanMetric` é uma tabela POR EMPRESA (`company_id`), não por serviço/vínculo — não existe (e não deve existir, per plano canônico) uma dimensão de setor nela. Isso significa que o dedup NÃO é "somar 2x e dividir por 2" nem "não somar a segunda vez" no sentido de deduplicar valores repetidos — é sobre **decidir, por empresa, se ela entra ou não na lista de `company_id` consultada**, e essa decisão depende do USUÁRIO (não da empresa isoladamente):

```
Para o usuário logado/alvo:
  vinculos = CarteiraContextService::forUser($user, ['active' => true])
  // Agrupar por company_id primeiro (fonte para exibição — CART-01)
  porEmpresa = vinculos->groupBy('company_id')

  // Fonte para os SOMATÓRIOS financeiros (CART-03/04/05):
  companyIdsElegiveis = vinculos
      ->where('financial_metrics_eligible', true)
      ->pluck('company_id')
      ->unique()     // <- o pulo do gato: unique() aqui é o que evita a
                     //    dupla contagem quando o profissional é responsável
                     //    ML E Shopee da MESMA empresa (2 vínculos, 1 só
                     //    financial_metrics_eligible=true) — a query de
                     //    AdmanMetric roda 1x por company_id, nunca 1x por vínculo.

  atualPorEmpresa = AdmanMetric::whereIn('company_id', companyIdsElegiveis)-> ... // como hoje
```

Isso resolve os dois critérios ao mesmo tempo:
- **CART-04** (Shopee-only não herda ML): se TODOS os vínculos do profissional para aquela empresa forem Shopee, a empresa nunca entra em `companyIdsElegiveis` → `AdmanMetric` nunca é consultado para ela → o profissional não recebe faturamento ML daquela empresa, mesmo que a empresa TENHA dados de ML (geridos por outro profissional).
- **CART-05** (ML+Shopee mesma empresa não duplica): o profissional tem 2 vínculos para a mesma empresa (1 ML elegível + 1 Shopee não-elegível). `->unique()` no `pluck('company_id')` garante que essa empresa entra 1x na lista de IDs consultados — a query `SUM(revenue)` roda 1x, não 2x. Não há "duplicação" possível porque a query nunca somou por vínculo, sempre por empresa.

**Pitfall a documentar para o executor**: é tentador (e ERRADO) implementar isso iterando sobre `vinculos` e somando `AdmanMetric` uma vez por vínculo elegível — isso duplicaria o financeiro de uma empresa com 2 vínculos elegíveis do mesmo profissional em setores diferentes (hipoteticamente, se um dia existir 2 setores financeiros). A unidade de consulta a `AdmanMetric` é SEMPRE `company_id` único, nunca vínculo.

## Desenho recomendado do payload (CART-01/CART-02)

Para satisfazer CART-01 ("aparece 1x como empresa, mostrando os 2 vínculos separadamente") sem redesenhar toda a UI (o filtro completo Todos/Performance/Shopee é Fase 90), a estrutura mínima recomendada é anexar um array `servicos` (ou `vinculos`) a cada linha de empresa, mantendo o restante do shape atual intacto:

```php
'empresas' => $porEmpresa->map(function ($vinculosDaEmpresa, $companyId) use (/* dados financeiros já calculados por companyId */) {
    $primeiro = $vinculosDaEmpresa->first();
    return [
        'id'   => $companyId,
        'name' => $primeiro['company_name'],
        // Campos financeiros só fazem sentido se ALGUM vínculo for elegível
        // — mesmo cálculo de hoje, indexado por $companyId (não por vínculo).
        'faturamento' => ...,
        'margem_contribuicao' => ...,
        // ... demais campos financeiros como hoje ...
        'has_ml_oauth' => ...,
        // NOVO — CART-01/02: 1 entrada por vínculo de serviço desta empresa.
        'servicos' => $vinculosDaEmpresa->map(fn ($v) => [
            'servico_id'   => $v['servico_id'],
            'servico_nome' => $v['servico_nome'],
            'setor'        => $v['setor'],
            'role'         => $v['role'],
            'role_label'   => $v['role_label'],
            'financial_metrics_eligible' => $v['financial_metrics_eligible'],
        ])->values(),
    ];
})->values(),
```

No `AdminCarteira.jsx`, a UI mínima da Fase 89 (sem o filtro completo, que é Fase 90) é: sob o nome da empresa, renderizar 1 badge por item de `c.servicos` (ex.: "Performance · Analista" em amarelo/verde, "Shopee · Analista — sem fonte financeira" em cinza/âmbar) — suficiente para CART-01 (visibilidade dos 2 vínculos) e CART-02 (estado explícito "sem fonte financeira" no vínculo Shopee). O `resumo` (KPIs de topo) já soma corretamente se o backend já filtra por `companyIdsElegiveis` — nenhuma mudança adicional necessária nos KPIs além dos novos campos `ad_spend`/`tacos` do achado crítico abaixo.

## Achado crítico: `ad_spend`/`tacos` não existem hoje em `renderCarteiraProfissional`

CART-03 exige que `ad_spend` e `tacos` também respeitem `financial_metrics_eligible=true`, mas a função-alvo desta fase NUNCA computou esses campos — só `revenue` e `contribution_margin`. Os dois campos existem em OUTRAS funções do mesmo controller:

- `renderPortfolio()` (linha 682-868, função de auto-visualização legada): usa `AdmanMetric::selectRaw('... SUM(ad_spend) as ads, AVG(tacos) as tacos ...')` (linha 686) + fallback ao cache `getCachedAccountMetricsMany()` (`investment`/`tacos`, linhas 725-728, 778-787).
- `renderCarteirasConsolidadas()` (linha 458-631): mesmo padrão, `SUM(ad_spend) as ads` (linha 528) + `getCachedAccountMetricsMany` (linha 534, 553-571) para TACOS por empresa.

**Recomendação**: replicar o MESMO padrão (SUM DB + fallback cache Adman `investment`/`tacos`) dentro de `renderCarteiraProfissional`, usando a MESMA lista `companyIdsElegiveis`/`custIdsElegiveis` já filtrada — isso garante que `ad_spend`/`tacos` nasçam corretos desde o dia 1 (nunca vazam de empresa Shopee-only) em vez de precisar de um filtro post-hoc. Adicionar os campos ao `selectRaw` das 2 queries de `AdmanMetric` já existentes (linha 172-183) é mais barato que criar queries novas — é literalmente adicionar `SUM(ad_spend) as ads` na mesma query que já roda.

Se o `AdminCarteira.jsx` não expuser esses campos na tabela visível nesta fase (a UI completa de contadores/filtro é Fase 90), ainda assim o TESTE dedicado que CART-03 exige (SC3: "validado por teste dedicado do analista Shopee de empresa que também tem ML") precisa de valores no payload OU no retorno de um método testável isoladamente — recomenda-se expor os campos no payload de qualquer forma (mesmo que a UI ainda não os desenhe), é o caminho mais barato e evita 2ª rodada de mudança de shape na Fase 90.

## CART-08 — `/companies` (CompanyController) responsável de Performance

### Lado de escrita — JÁ CORRETO, não tocar

`CompanyController::update()` (linhas ~610-650) e `bulkAssign()` (linhas ~725-750) já gravam `servico_id` escopado via o helper privado `servicoPerformanceAtivoId(Company $company): ?int` (linha 709-719) — resolve o contrato Performance ativo da empresa (`MIN(ct.servico_id)` entre contratos ativos de setor performance) e faz `detach` ESCOPADO por esse `servico_id` (nunca detach total — preserva linha Shopee). Este é trabalho da Fase 76 (v16.0), já correto e testado. **A Fase 89 não precisa (e não deve) tocar nestes dois métodos.**

### Lado de leitura — o bug real

`Company::consultor()`/`estrategista()` (`app/Models/Company.php:164-189`) são relações CONSOLIDADAS: `belongsToMany(User::class, 'company_users')->wherePivot('role', 'consultor')->distinct('users.id')` — sem filtro de `servico_id`. Se uma empresa tem analista ML (servico_id=6) E analista Shopee (servico_id=X, usuário diferente), AMBOS aparecem na mesma Collection `$company->consultor` — `->first()` (usado em `index()`) pega o que a query devolver primeiro (ordem não garantida), podendo mostrar o responsável Shopee na coluna "Analista" de um painel que é 100% Performance.

Usos a reapontar (confirmados por leitura direta):

| Local | Uso atual | Shape retornado hoje |
|---|---|---|
| `CompanyController::index()` linhas 99-100 (`with()`) + 158-159 | `$c->consultor->first()?->only(['id','name'])` | objeto único ou `null` |
| `CompanyController::index()` linha 190 | `($c->consultor->isEmpty() && $c->estrategista->isEmpty()) ? 'sem_responsavel' : null` | pendência AND |
| `CompanyController::show()` linhas 278-279 (`load()`) + 453-454 | `$company->consultor->map->only(['id','name'])->values()` | array (mesmo com 1 elemento) |

### Recomendação: 2 novas relações no Company model, espelhando `consultorDoServico()`

Padrão já existente em `Company.php:197-209` (`consultorDoServico(int $servicoId)`/`estrategistaDoServico(int $servicoId)`) filtra por UM `servico_id` fixo — não serve diretamente porque Performance tem 2 serviços possíveis (Gestão id 6, Mentoria id 7) e precisa também cobrir o ramo legado `servico_id NULL` (CTX-05). A relação nova precisa espelhar as DUAS fontes que `CarteiraContextService` já resolve, mas em granularidade de EMPRESA (não de usuário):

```php
/**
 * Analista de PERFORMANCE da empresa — nunca retorna o responsável Shopee.
 * Espelha a resolução de 2 fontes do CarteiraContextService (Fase 88, CTX-05),
 * mas em granularidade de empresa: servico_id preenchido apontando pra um
 * serviço de setor=performance (Gestão OU Mentoria, sem hardcode de id) OU
 * servico_id NULL com contrato performance ativo (legado).
 */
public function analistaPerformance()
{
    return $this->belongsToMany(User::class, 'company_users')
        ->wherePivot('role', 'consultor')
        ->where(function ($q) {
            $q->whereIn('company_users.servico_id', function ($sub) {
                $sub->select('id')->from('servicos')->where('setor', Servico::SETOR_PERFORMANCE);
            })->orWhere(function ($q2) {
                $q2->whereNull('company_users.servico_id')
                   ->whereExists(function ($sub2) {
                       $sub2->select(DB::raw(1))
                            ->from('contratos_servico as ct')
                            ->join('servicos as s2', 's2.id', '=', 'ct.servico_id')
                            ->whereColumn('ct.company_id', 'company_users.company_id')
                            ->where('ct.ativo', true)
                            ->where('s2.setor', Servico::SETOR_PERFORMANCE);
                   });
            });
        })
        ->distinct('users.id');
}

public function estrategistaPerformance()
{
    // idêntico, wherePivot('role', 'estrategista')
}
```

`->distinct('users.id')` preservado (mesmo motivo do `consultor()` atual: `COUNT(DISTINCT users.id)`, não `COUNT(*)`). `Servico::SETOR_PERFORMANCE` já é constante existente (`app/Models/Servico.php:52`) — sem hardcode de id, consistente com CTX-03.

### Reapontamento (preservando chaves do payload)

Trocar SÓ:
- `index()`: `with(['consultor', 'estrategista', ...])` → `with(['analistaPerformance', 'estrategistaPerformance', ...])`; `$c->consultor->first()` → `$c->analistaPerformance->first()` (linha 158); idem estrategista (linha 159). **Chave do array de saída continua `'consultor'`/`'estrategista'`** — só a FONTE muda, não o nome da chave (o `.jsx` não muda).
- `show()`: `load(['consultor', 'estrategista', ...])` → `load(['analistaPerformance', 'estrategistaPerformance', ...])`; linha 453-454 idem.

### Pendência `sem_responsavel` — AND → OR (mudança de comportamento, confirmar)

O foco da pesquisa afirma que a decisão travada (de um quick task descartado, `260716-fo0`, cujo arquivo não foi localizado no repo — provavelmente nunca commitado) é trocar:

```php
// Hoje (AND — só flag se NENHUM dos dois estiver atribuído):
($c->consultor->isEmpty() && $c->estrategista->isEmpty()) ? 'sem_responsavel' : null,

// Proposto (OR — flag se QUALQUER um dos dois de Performance estiver faltando):
($c->analistaPerformance->isEmpty() || $c->estrategistaPerformance->isEmpty()) ? 'sem_responsavel' : null,
```

Isso é uma mudança de COMPORTAMENTO visível, não só de fonte de dados: hoje uma empresa com analista mas sem estrategista NÃO aparece como pendente; com OR, passaria a aparecer. Faz sentido de negócio (a pendência passa a significar "falta pelo menos 1 responsável de Performance", alinhado com a redação da ROADMAP SC5 — "a pendência acusa falta do responsável de performance especificamente"), e é consistente com o padrão já usado em `META-04`/outros pontos do sistema que tratam analista e estrategista como papéis distintos e obrigatórios. **Confiança MEDIUM**: não há como confirmar contra o texto original do quick task (arquivo não encontrado — `find` não retornou nada para `260716-fo0` em `.planning/`). Recomenda-se o planner tratar isso como decisão a CONFIRMAR com o usuário antes de travar (pode inflar a contagem de pendências exibidas para o time de Comercial/Admin — efeito colateral visível, vale um checkpoint ou pelo menos uma nota no SUMMARY).

### Call-sites que NÃO devem ser tocados (confirmados por leitura direta)

Todos usam `Company::consultor()`/`estrategista()` (a relação consolidada, ANTIGA) e ficam fora do escopo desta fase — são consumidores de "responsável consolidado" (bônus, notificação, dashboard geral), não do painel `/companies`:

| Arquivo | Linha(s) | Uso |
|---|---|---|
| `DashboardController.php` | 262 | `with(['latestMetrics', 'consultor', 'estrategista', 'mlToken'])` — já filtrado a empresas Performance no nível da query externa, mas a relação em si continua consolidada |
| `DashboardController.php` | 273-274 | `whereHas('consultor', ...)`/`whereHas('estrategista', ...)` — filtros de dashboard |
| `DashboardController.php` | 908-909 | `$c->consultor->first()?->name` / `$c->estrategista->first()?->name` — ranking |
| `GoalController.php` | 24-25, 38-39 | listagem de metas por empresa |
| `Goal.php` (model, `AUTO-02`) | 28, 33 | `$company->consultor->merge($company->estrategista)->unique('id')` — destinatários de notificação |
| `CalculateGoalResults.php` | 91, 98-99 | idem, destinatários de cálculo de meta |
| `MeetingController.php` | 21, 39-40 | listagem de reuniões |
| `Api/HubspotWebhookController.php` | 580, 584 | `calcularPendencias()` interno — pendência de notificação Comercial, ESPELHA a lógica antiga (AND) deliberadamente, não deve divergir do texto atual sem decisão própria |
| `NpsDispararMensal.php` | 183, 191 | `$empresa->estrategista()->first()` / `$empresa->consultor()->first()` — fallback legado do disparo NPS (dual-path já documentado na Fase 79/81) |
| `ComercialController.php` | 205-206, 322-323 | painel Comercial (`/comercial/empresas/listagem`) — tela DIFERENTE de `/companies`, fora do escopo Performance desta fase |

Todos confirmados via leitura direta de código nesta sessão (`[VERIFIED: grep + leitura]`) — a lista do prompt de pesquisa bate 1:1 com o código real.

## Cache — não é necessário bump

`CarteiraContextService::forUser()` NÃO usa cache (decisão documentada no próprio service — só queries locais, zero HTTP). `renderCarteiraProfissional()` também não implementa cache próprio — usa apenas o cache já existente do `AdmanService::getCachedGrossBillingsMany()`/`getCachedAccountMetricsMany()` (TTL interno do `AdmanService`, não relacionado à mudança desta fase). **Diferente do pitfall conhecido do projeto sobre `DesempenhoScoreService::computeCached()`** (que É cacheado com TTL e cuja mudança de shape EXIGE bump de versão de chave) — isso é sobre a Fase 91, não a Fase 89. Nenhuma ação de cache necessária aqui. `[VERIFIED: leitura direta dos dois arquivos]`.

## Common Pitfalls

### Pitfall 1: `AdmanMetric` é por-empresa, não por-vínculo — nunca somar por vínculo
Ver seção "Algoritmo de dedup financeiro" acima. Consultar `AdmanMetric` sempre por `company_id` ÚNICO (após `->unique()` na lista de empresas elegíveis), nunca iterando vínculos.

### Pitfall 2: `$c->pivot->role` / distinct quebrado — está em `renderPortfolio()`, NÃO na função-alvo
O comentário do prompt de pesquisa aponta `PortfolioController.php:673`/`:849` como pitfall de `pivot->role` com distinct quebrado. Confirmado por leitura: esse código está dentro de `renderPortfolio()` (linha 670-868, a função de AUTO-visualização legada, usada quando o próprio profissional acessa `/admin/users/{seu_próprio_id}/portfolio`), **não** em `renderCarteiraProfissional()` (a função-alvo desta fase, linhas 114-423). `renderPortfolio()` usa `$user->companies()->withPivot('role')` (linha 670-674) e depois `'role' => $c->pivot->role` (linha 849) — se a mesma empresa tiver 2 linhas de pivot (ML consultor-role + Shopee consultor-role, mesmo usuário), o Eloquent pode colapsar/desduplicar de forma não-determinística dependendo de `distinct()` ausente na query. **Este código não está no escopo de nenhuma REQ da Fase 89** (plano canônico só pede refatorar `renderCarteiraProfissional()`) — mas é uma rota tecnicamente alcançável (`/admin/users/{user}/portfolio` quando `$atual->id === $user->id`) que continuará com o bug antigo após esta fase. Recomenda-se documentar como divergência conhecida (não corrigir agora) — ver Pitfall 6/Open Questions.

### Pitfall 3: `renderCarteirasConsolidadas()` também usa `$user->companies()` (via `estrategistaCompanies()`/`consultorCompanies()`) — não tocar
Linhas 516-518, 614-616: usa `$u->estrategistaCompanies()`/`consultorCompanies()` (relações inversas em `User.php`, não vistas neste research mas referenciadas pelo padrão análogo a `Company::consultor()`). Esta função é explicitamente Fase 90 (CART-06/07) — **fora do escopo desta fase**, mesmo estando no mesmo arquivo/controller.

### Pitfall 4: `npm run build` obrigatório se `AdminCarteira.jsx` mudar
Convenção do projeto (`CLAUDE.md`): qualquer mudança em `.jsx` exige `npm run build` antes de considerar a task completa — não há hot-reload em produção/verificação.

### Pitfall 5: dev paralelo no módulo MLB/Anúncios (Fases 82-87)
Fases 82/83 (planilha `glide-data-grid`) estão em andamento por outro dev em paralelo em `resources/js/Pages/Mlb/AnunciarMassa.jsx` e arquivos relacionados. A Fase 89 não toca nenhum arquivo desse módulo — zero overlap esperado. Evitar rodar `npm run build` de forma que sobrescreva trabalho não commitado desse módulo sem necessidade (mesma nota já documentada na Fase 88).

### Pitfall 6: duas rotas diferentes renderizam "a carteira de alguém" com funções DIFERENTES
`show()` (linha 76-96) bifurca: se `$atual->id !== $user->id` → `renderCarteiraProfissional()` (a função-alvo); se `$atual->id === $user->id` → `renderPortfolio()` (função legada, NÃO tocada nesta fase). Isso significa que, após a Fase 89, um profissional acessando SUA PRÓPRIA carteira via `/admin/users/{seu_id}/portfolio` (rota tecnicamente exposta, embora não seja o fluxo principal — o fluxo principal de auto-visualização é `/portfolio` via `own()`, que JÁ vai para `renderCarteiraProfissional()` desde o Ajuste 2026-07-09 v5) veria dados DIFERENTES (ainda não corrigidos) da mesma carteira vista por um admin. Baixo risco de exposição real (rota admin-prefixada, uso incomum), mas vale nota no SUMMARY da fase.

## Testes obrigatórios (do plano canônico, seção "Testes obrigatorios")

- Analista Shopee de empresa que também tem ML NÃO recebe revenue/margem ML na carteira (CART-04)
- Estrategista Shopee de empresa que também tem ML NÃO recebe revenue/margem ML (CART-04, papel estrategista)
- Analista ML da empresa CONTINUA recebendo revenue/margem ML (regressão — não quebrar o caso comum)
- Mesmo usuário responsável ML E Shopee da MESMA empresa NÃO duplica revenue (CART-05) — teste mais importante da fase, cenário "mesmo profissional nos dois serviços da mesma empresa" já coberto pelo trait `CriaCenarioResponsaveis` (Fase 88, `inserirLinhaShopee()`)
- Carteira mostra 1 empresa (não 2) quando há Performance+Shopee do mesmo profissional (CART-01)
- `/companies` (`index()`) mostra analista/estrategista de Performance, nunca Shopee, mesmo quando a empresa tem responsável Shopee diferente (CART-08)
- `/companies/{id}` (`show()`) idem, shape array preservado
- Pendência `sem_responsavel` reflete a nova regra (AND→OR, se confirmada) — teste que prova a mudança de comportamento explicitamente, não só implicitamente

### Fixtures reutilizáveis
`tests/Feature/V16/CriaCenarioResponsaveis.php` (trait, namespace `Tests\Feature\V16`, importável) já tem `criarCenarioMlComResponsaveis()`, `inserirLinhaShopee()`, `criarServico()`, `criarContrato()`, `inserirPivot()` — cobre diretamente os cenários CART-01/04/05. `tests/Feature/V16/CarteiraContextServiceTest.php` é a referência de shape de retorno do service já testado (12/12 verdes). Seguir a mesma convenção de diretório usada pela Fase 88 (`tests/Feature/V16/`, apesar do research da Fase 88 ter recomendado `tests/Feature/V17/` — a implementação real ficou em V16; **recomendação: manter em V16 por consistência com o que já foi commitado**, ou usar V17 e reexportar o trait via `use Tests\Feature\V16\CriaCenarioResponsaveis;` — decisão do planner, baixo risco em ambos os casos).

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
| CART-01 | Empresa Performance+Shopee aparece 1x com 2 vínculos | Feature (controller) | `php artisan test --filter=CarteiraIndividualContextoTest` | ❌ Wave 0 |
| CART-02 | Vínculo Shopee com "sem fonte financeira" no payload | Feature (controller) | `php artisan test --filter=CarteiraIndividualContextoTest` | ❌ Wave 0 |
| CART-03 | SUM ad_spend/tacos gated por `financial_metrics_eligible` | Feature (controller) | `php artisan test --filter=CarteiraFinanceiroEleg` | ❌ Wave 0 |
| CART-04 | Shopee-only não herda financeiro ML | Feature (controller) | `php artisan test --filter=CarteiraFinanceiroEleg` | ❌ Wave 0 |
| CART-05 | ML+Shopee mesma empresa não duplica financeiro | Feature (controller) | `php artisan test --filter=CarteiraFinanceiroEleg` | ❌ Wave 0 |
| CART-08 | `/companies` mostra responsável de Performance | Feature (controller) | `php artisan test --filter=CompanyControllerResponsavelPerformanceTest` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter={TestClassDaTask}`
- **Per wave merge:** `php artisan test --filter=V16` (suíte V16 completa, hoje 117/117 verdes/519 assertions — baseline de regressão) + `php artisan test --filter=Nps` (207/207, protege dual-path do bônus)
- **Phase gate:** `php artisan test` completo verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/V16/CarteiraIndividualContextoTest.php` (ou V17, ver nota acima) — cobre CART-01/02
- [ ] `tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php` — cobre CART-03/04/05 (o teste mais crítico da fase)
- [ ] `tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php` — cobre CART-08 (index + show + pendência)
- [ ] Nenhum framework novo a instalar — PHPUnit 11.x já configurado, trait de fixtures já existe

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V4 Access Control | yes | `abort_unless($autorizado, 403)` em `show()` (linha 88) — admin, líder do setor do usuário-alvo, ou o próprio usuário; `userCanViewCompany()`/`userIsCompanyEstrategista()` em `CompanyController` — **inalterados por esta fase**, nenhuma mudança de autorização |
| V5 Input Validation | yes | `?mes=YYYY-MM` validado via `preg_match('/^\d{4}-\d{2}$/', ...)` (linha 129) — já existente, não muda |
| V2/V3/V6 | no | Sem autenticação/sessão/criptografia nova nesta fase — refatoração de leitura sobre schema existente |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Vazamento de dados financeiros entre setores (Shopee vendo ML de outro responsável) | Information Disclosure | É exatamente o bug que esta fase corrige — `financial_metrics_eligible` gating na query de `AdmanMetric` |
| IDOR na rota `/admin/users/{user}/portfolio` | Broken Access Control | Já mitigado pelo `abort_unless` existente (linha 79-88) — não há mudança de autorização nesta fase, só de fonte de dados |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|----------------|
| A1 | A troca AND→OR na pendência `sem_responsavel` (`index()`) é a decisão correta/travada — baseada no texto do prompt de pesquisa, não no arquivo original do quick task `260716-fo0` (não encontrado no repo, provavelmente nunca commitado) | §CART-08 — Pendência sem_responsavel | Se a intenção real era manter AND (só mudar a FONTE, não a lógica booleana), a fase geraria pendências novas não solicitadas para empresas que hoje têm 1 dos 2 papéis preenchido — visível para o time de Comercial/Admin. Recomenda-se confirmar com o usuário antes de travar em plan-check ou discuss-phase |
| A2 | O payload mínimo de `AdminCarteira.jsx` para CART-01/02 é "badge por vínculo sob o nome da empresa" (sem filtro completo Todos/Performance/Shopee, que fica pra Fase 90) | §Desenho recomendado do payload | Se o usuário esperar o filtro completo já na Fase 89, a Fase 90 ficaria com escopo vazio para UI (CART-07 já cobre isso) — risco baixo, o ROADMAP já separa explicitamente as duas fases e só a Fase 90 tem `UI hint: yes` |
| A3 | `ad_spend`/`tacos` devem ser ADICIONADOS a `renderCarteiraProfissional` nesta fase (não existiam antes) — não é uma leitura errada de código, é uma lacuna real confirmada por grep | §Achado crítico ad_spend/tacos | Risco baixo — é uma leitura de código, não uma suposição; mas o ESCOPO de adicionar um campo novo (vs. só filtrar um campo existente) é maior que o ROADMAP sugere à primeira leitura, vale destacar no plan-check |

## Open Questions

1. **A troca AND→OR da pendência `sem_responsavel` em `CompanyController::index()` é realmente desejada?**
   - O que sabemos: o prompt de pesquisa afirma que sim, citando um quick task descartado (`260716-fo0`) como fonte da decisão.
   - O que é incerto: o arquivo desse quick task não foi encontrado em `.planning/` — não há como verificar o texto original, só o resumo passado no prompt.
   - Recomendação: tratar como decisão A CONFIRMAR (não travada) — se houver `discuss-phase` para esta fase, incluir explicitamente; se pular direto pro plan, o planner deve marcar como `checkpoint:human-verify` ou pelo menos destacar em negrito no PLAN.md antes de implementar.

2. **`renderPortfolio()` (a função legada, atrás de `/admin/users/{user}/portfolio` quando o próprio user visita a própria carteira) fica com o bug antigo — é aceitável?**
   - O que sabemos: nenhuma REQ desta fase (nem o plano canônico) menciona `renderPortfolio()`.
   - O que é incerto: se um profissional Shopee-only acessar essa rota específica (incomum, mas tecnicamente possível), ainda veria dados misturados de ML.
   - Recomendação: documentar como divergência conhecida no SUMMARY da fase (não bloqueante) — o fluxo principal de auto-visualização (`/portfolio` via `own()`) já usa a função corrigida.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHPUnit | Testes Feature | ✓ | 11.5.50 | — |
| SQLite (`:memory:`) | Testes Feature (phpunit.xml) | ✓ (embutido no PHP) | — | — |
| MySQL/MariaDB local (XAMPP) | Verificação manual/dev local (NÃO necessário para testes) | ✗ (mesmo incidente `project_mariadb_local_corrompido.md`, não resolvido desde 2026-06-25) | — | Testes rodam 100% em SQLite; verificação de comportamento real-world (se necessária) só via VPS |
| Node.js/npm (`npm run build`) | Só se `AdminCarteira.jsx` for editado | ✓ | v24.15.0 | — |

**Missing dependencies with no fallback:** nenhuma — MySQL local não bloqueia a fase (testes não dependem dele).

## Sources

### Primary (HIGH confidence — leitura direta de código neste repositório)
- `app/Http/Controllers/PortfolioController.php` (functions `show`, `renderCarteiraProfissional`, `own`, `renderCarteirasConsolidadas`, `renderPortfolio` — lido por completo)
- `app/Http/Controllers/CompanyController.php` (`index`, `show`, `update`, `bulkAssign`, `servicoPerformanceAtivoId` — lido por completo)
- `app/Models/Company.php` (`consultor`, `estrategista`, `consultorDoServico`, `estrategistaDoServico`)
- `app/Models/Servico.php` (constantes `SETOR_*`)
- `app/Models/AdmanMetric.php`
- `app/Services/Portfolio/CarteiraContextService.php` (Fase 88, pronto)
- `resources/js/Pages/Portfolio/AdminCarteira.jsx` (lido por completo)
- `resources/js/Pages/Companies/Index.jsx`, `resources/js/Pages/Companies/Show.jsx` (shape de consumo `consultor`/`estrategista`)
- `.planning/phases/88-.../88-RESEARCH.md`, `88-01-SUMMARY.md` (precedente direto + contagem real de prod)
- `plano-carteira-desempenho-multi-servico.md` (plano canônico do usuário)
- `.planning/ROADMAP.md` (Phase 88-93, success criteria)
- `.planning/REQUIREMENTS.md` (CART-01..08)
- `tests/Feature/V16/ResponsaveisConsolidadoInvarianteTest.php`, `CriaCenarioResponsaveis.php` (precedente de teste)
- `grep` confirmando TODOS os call-sites de `->consultor`/`->estrategista` em `app/` (DashboardController, GoalController, Goal.php, CalculateGoalResults, MeetingController, Api/HubspotWebhookController, NpsDispararMensal, ComercialController)

### Tertiary (LOW confidence — não verificado)
- Conteúdo original do quick task `260716-fo0` (arquivo não encontrado no repo) — a decisão AND→OR é reportada de segunda mão pelo prompt de pesquisa, não lida na fonte primária

## Metadata

**Confidence breakdown:**
- Anatomia de `renderCarteiraProfissional` e algoritmo de dedup: HIGH — código lido linha a linha, algoritmo derivado diretamente da estrutura real da tabela `AdmanMetric`
- CART-08 (relações novas + call-sites intocados): HIGH — todos os 9 call-sites confirmados por grep + leitura, padrão de relação já tem precedente direto (`consultorDoServico`)
- Achado ad_spend/tacos: HIGH — confirmado por ausência literal desses campos no `selectRaw`/payload atual, comparado com os 2 outros métodos do mesmo controller que já fazem isso
- Decisão AND→OR da pendência: MEDIUM — fonte não verificável (arquivo do quick task não encontrado)

**Research date:** 2026-07-16
**Valid until:** 30 dias (schema estável desde a Fase 76; nenhuma dependência externa versionada; único risco de staleness é se a Fase 90 for planejada antes e mudar o shape do payload que este research assume)
