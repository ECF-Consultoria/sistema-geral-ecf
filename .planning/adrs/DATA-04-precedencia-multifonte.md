---
id: DATA-04
title: Precedência multi-fonte de métricas (ML vs Adman)
status: accepted
date: 2026-07-07
related_phases: [57, 60]
related_requirements: [DATA-04, DATA-06]
---

# ADR DATA-04 — Precedência multi-fonte de métricas (ML vs Adman)

## Contexto

Este ADR resolve o Success Criterion #2 da Phase 60 do ROADMAP ("regra de
precedência documentada em ADR versionado") e formaliza o comportamento esperado
quando o sistema precisa calcular métricas agregadas de uma empresa que possui
integração Adman, integração ML ou ambas. A camada de leitura unificada
(Phase 60 W2/W3/W4) só pode ser construída sobre uma decisão explícita —
sem esta decisão, cada consumidor tenderia a improvisar precedência
localmente, gerando divergência silenciosa entre dashboards.

Fatos que a decisão precisa reconhecer:

1. **`adman_metrics` é a única tabela local de métricas de venda/ads.**
   Model `App\Models\AdmanMetric` (colunas: `company_id`, `reference_date`,
   `revenue`, `net_billing`, `sales_fee`, `taxes`, `shipping_cost`,
   `product_cost`, `return_cost`, `profit_share`, `sold_quantity`, `ad_spend`,
   `contribution_margin`, `contribution_margin_pct`, `synced_at` etc.).
   Populada pelo scheduler diário via `AdmanService::syncCompany()`. Toda a
   leitura Adman hoje é OFFLINE e cacheada em nível de banco.

2. **`MercadoLivreService` é a fonte ML canônica LIVE (sem tabela local).**
   Métodos relevantes:
   - `fetchOrdersSummary(Company $c, string $from, string $to): array` →
     `revenue`, `sold_quantity`, `orders_count`, `sales_fee`, `raw_orders`.
   - `fetchAdsSummary(Company $c, string $from, string $to): array` →
     `ad_spend`, `ad_revenue`, `clicks`, `impressions`, `acos`, `roas`, `cpc`,
     `sold_quantity`.
   Precondição: `$company->mlToken?->status === 'active'` (accessor
   `Company::is_ml_driven`). Rate limit é a restrição operacional dominante —
   toda chamada custa quota ML.
   **Não existe** tabela `ml_orders`/`ml_sales`/`ml_metrics_daily` local
   (verificado via glob em `database/migrations/`) e a criação dessa tabela
   NÃO faz parte do escopo v14.0.

3. **6 controllers já leem `adman_metrics` direto**, sem intermediação por
   serviço: `DashboardController`, `CompanyController`, `PerformanceController`,
   `AdminController`, `PortfolioController`, `AdmanController`. Qualquer regra
   nova de precedência precisa coexistir com estes call-sites durante a
   Phase 60 (rollout gradual: Phase 61 migra os consumidores).

4. **Pivot `company_marketplaces` (Phase 57 v13.0)** informa em quais
   marketplaces uma empresa está (`meli`, `shopee`, `amazon`, `magalu` com
   `is_primary`, `active`, `integracao_status`). Ela é a fonte de verdade
   sobre "presença em marketplace", mas NÃO diz onde estão as métricas dessa
   empresa (Adman? ML API? Ambos?). A pivot alimenta a decisão de "quais
   providers ativar", não a decisão de "qual fonte tem precedência para
   `revenue`".

5. **Discrepância conhecida de TACOS entre as fontes.** Memory
   `project_adman_data_sources` registra que dashboards leem `adman_metrics`
   local enquanto o Sugadores drilldown lê via MCP — usuário já relatou
   divergência de TACOS entre os dois caminhos. A precedência precisa
   documentar como reconciliar sem falhar o cálculo.

6. **Empresas ML-only retornam 422 em endpoints Adman MCP.** Memory
   `project_ml_only_companies_adman_endpoints` registra que empresas que só
   têm `mlToken` active (sem `adman_account_id`) recebem HTTP 422 dos
   endpoints Adman durante v11.0 — a UI passou a tratar 422 como "caso
   válido" em vez de erro. A camada unificada precisa formalizar isso como
   caso "só-ML" e nunca tentar leitura Adman para essas empresas.

## Decisão

### Regra de precedência

**ML é fonte PRIMÁRIA para métricas que ambas as fontes expõem.** Justificativa:
o cutover Adman → ML da milestone v11.0 (Phase 42) promoveu ML como
canonical — ML é fresh (chamada live no período consultado), enquanto
`adman_metrics` reflete o último sync do scheduler (delay de até 24h).

**Adman ENRIQUECE campos que ML NÃO expõe hoje.** Enumerados explicitamente:

- `net_billing` (faturamento líquido pós-desconto)
- `taxes` (impostos apurados)
- `product_cost` (custo do produto vindo de cadastro)
- `contribution_margin` e `contribution_margin_pct` (margem de contribuição)
- `return_cost` (custo de devoluções)
- `profit_share` (participação nos lucros)
- `products_total` e `products_without_cost` (cobertura de custeio)

Estes campos não existem no retorno de `fetchOrdersSummary`/`fetchAdsSummary` —
são exclusivos do domínio Adman (cadastro contábil/fiscal). Quando ambos os
providers respondem para o mesmo (empresa, período), o `UnifiedMetricsService`
compõe um único DTO: numéricos operacionais vêm de ML, complementos
contábeis/fiscais vêm de Adman.

### 3 casos formalizados

| Caso        | Precondição de detecção                                                | Comportamento                                                                                                          |
| ----------- | ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| **só-Adman** | `mlToken` inexistente OU `status !== 'active'` E `adman_account_id` presente | Lê 100% de `adman_metrics`. DTO retornado com `source: 'adman'`. Nenhuma chamada a `MercadoLivreService`.               |
| **só-ML**    | `mlToken.status === 'active'` E `adman_account_id` NULL ou vazio        | Lê 100% via `MercadoLivreService` (orders + ads). DTO com `source: 'ml'`. Campos exclusivos-Adman retornam `null`. |
| **ambos**   | `mlToken.status === 'active'` E `adman_account_id` presente             | ML dita `revenue`/`sold_quantity`/`ad_spend`/`sales_fee`/`orders_count`; Adman enriquece com `net_billing`/`taxes`/`product_cost`/`contribution_margin` etc. DTO com `source: 'unified'`. |

**Caso ausente formalizado**: quando `mlToken` inativo E `adman_account_id`
NULL — empresa sem NENHUMA integração. O `UnifiedMetricsService` retorna DTO
sentinela com `source: 'none'` e todos os campos numéricos `null`. Isso
permite que dashboards distingam "empresa sem integração" (rendering
neutro/CTA) de "erro na leitura" (rendering de erro).

### Detecção do caso

A detecção do caso (só-Adman / só-ML / ambos / nenhum) usa os **campos
denormalizados de `companies`** (`mlToken?->status === 'active'` — via
accessor `Company::is_ml_driven` — e `adman_account_id`) e **NÃO consulta o
pivot `company_marketplaces`** para essa decisão específica.

Justificativa:

1. **Accessors legacy da Phase 57 mantêm contrato**: DATA-01 promoveu a
   pivot como source-of-truth para presença de marketplace, mas manteve as
   colunas flat (`adman_account_id`, `ml_store_id`, `marketplace`) como
   cache/backup. Os 8 consumidores atuais continuam usando
   `$company->adman_account_id` e `$company->cust_id`. Fazer a detecção via
   accessors denormalizados evita divergência de comportamento entre a nova
   camada e o legado.

2. **Evita JOIN adicional em hotpath de dashboards**: dashboards leem métricas
   por empresa em loop (`foreach ($companies as $c) { $service->for($c); }`).
   Detectar o caso via `->with('mlToken')` (1 eager load) é significativamente
   mais barato que exigir `->with('marketplaces')` + resolução de row primary
   para cada linha do dashboard.

3. **Escopo controlado**: migrar a detecção para o pivot é decisão futura
   (`DATA-FUTURE-02`, candidata a v14.x ou v15.x) e depende de todos os 8
   consumidores atuais estarem migrados. Está OUT-OF-SCOPE para v14.0.

**Consequência (risco consciente)**: se a denormalização
(`companies.adman_account_id` etc.) divergir do pivot `company_marketplaces`
em algum momento (por bug de sync ou intervenção manual), a detecção pode
cair no caso errado. Mitigação registrada como candidato de teste de
consistência em futura milestone — **não bloqueia** esta phase. O
`AdminController` já expõe comandos de diagnóstico (`DiagnoseCustId`,
`MarkCustIdStatus`) que podem servir de base para essa checagem no futuro.

### Vocabulário do campo `source` no `UnifiedMetricsDto`

O DTO produzido pelos providers e pelo `UnifiedMetricsService` carrega um
campo `source` com **exatamente 4 valores válidos** — nenhum outro valor deve
ser produzido ao longo desta phase e das próximas. Trava a decisão antes da
implementação:

- **`'adman'`** — DTO produzido por `AdmanMetricsProvider` (fonte única =
  leitura direta de `adman_metrics`). Emitido nos casos "só-Adman" e também
  quando um consumidor pede explicitamente o provider Adman.
- **`'ml'`** — DTO produzido por `MlMetricsProvider` (fonte única = API ML
  via `MercadoLivreService::fetchOrdersSummary` + `fetchAdsSummary`).
  Emitido nos casos "só-ML" e também quando um consumidor pede explicitamente
  o provider ML.
- **`'unified'`** — DTO resultado da fusão campo-a-campo dentro do
  `UnifiedMetricsService`. Emitido APENAS no caso "ambos", após aplicar a
  tabela de precedência abaixo.
- **`'none'`** — DTO sentinela emitido quando a empresa não tem NENHUMA
  integração ativa (sem `mlToken` active e sem `adman_account_id`). Todos os
  campos numéricos ficam `null`. Permite ao consumidor distinguir
  "empresa sem integração" de "erro na leitura" — este último deve emergir
  como Exception, não como DTO.

Consumidores (dashboards Phase 61) devem tratar esses 4 valores
exaustivamente (`match($dto->source)`). Adicionar um quinto valor requer
update deste ADR.

### Definição operacional de "fonte ML"

Para os fins da Phase 60, "fonte ML" significa: **API ML via
`MercadoLivreService::fetchOrdersSummary` + `fetchAdsSummary`, com cache TTL
declarado abaixo**. NÃO existe tabela local `ml_metrics_daily` — a criação
dessa tabela (com sync noturno análogo ao Adman) está **fora do escopo** da
Phase 60 e da milestone v14.0. É candidato de futura milestone
(estimativa: v15.x, após validação em produção do custo real de rate limit).

### Tabela de precedência campo-a-campo

Header canônico: `campo | fonte_primaria | fonte_fallback | caso_ambos_diferem`.

| campo                    | fonte_primaria | fonte_fallback | caso_ambos_diferem                                                          |
| ------------------------ | -------------- | -------------- | --------------------------------------------------------------------------- |
| `revenue`                | ml             | adman          | ML vence; se |ML.revenue − Adman.revenue| / ML.revenue > 5% → `Log::warning` estruturado |
| `ad_spend`               | ml             | adman          | ML vence; log de divergência quando >5%                                     |
| `sold_quantity`          | ml             | adman          | ML vence; log de divergência quando >5%                                     |
| `tacos`                  | ml (derivado ad_spend/revenue) | adman | ML derivado tem precedência; se ML.tacos vs Adman.tacos difere >5% → `Log::warning('[UnifiedMetrics] TACOS divergente', [...])` sem falhar |
| `net_billing`            | adman          | (nenhuma)      | Não aplicável — ML não expõe; se Adman ausente, campo fica `null`           |
| `sales_fee`              | ml             | adman          | ML vence; log de divergência quando >5%                                     |
| `taxes`                  | adman          | (nenhuma)      | Não aplicável — ML não expõe; se Adman ausente, campo fica `null`           |
| `shipping_cost`          | adman          | (nenhuma)      | Não aplicável — ML não expõe hoje; se Adman ausente, campo fica `null`      |
| `product_cost`           | adman          | (nenhuma)      | Não aplicável — ML não expõe (vem de cadastro contábil); `null` se ausente  |
| `contribution_margin`    | adman          | (nenhuma)      | Não aplicável — derivado de campos exclusivos Adman; `null` se ausente      |
| `acos`                   | ml             | (nenhuma)      | ML canonical (calculado por ML sobre attributed sales); Adman não expõe direto |
| `roas`                   | ml             | (nenhuma)      | ML canonical; Adman não expõe direto                                        |
| `clicks`                 | ml             | (nenhuma)      | ML canonical (Ads API); Adman não expõe                                     |
| `impressions`            | ml             | (nenhuma)      | ML canonical (Ads API); Adman não expõe                                     |
| `orders_count`           | ml             | adman (derivado) | ML vence; se Adman conseguir derivar de `raw_data`, log de divergência quando >5% |

Regra geral para células "caso_ambos_diferem" marcadas com log: divergência
>5% grava `Log::warning('[UnifiedMetrics] {campo} divergente', ['company_id' => ..., 'periodo' => ..., 'ml' => ..., 'adman' => ...])` mas
**nunca falha o cálculo** — ML vence e a linha de log fica para diagnóstico
posterior (referência para plan 60-04 implementar).

## Consequências positivas

1. **Plans 60-02/03/04 têm âncora clara de decisão**: cada provider sabe
   qual é seu escopo (só lê sua fonte) e o `UnifiedMetricsService` sabe
   exatamente como reconciliar sem improvisar precedência.
2. **Dashboards Phase 61 sabem qual badge mostrar**: o campo `source` do
   DTO (`'adman'`/`'ml'`/`'unified'`/`'none'`) mapeia 1:1 para o
   indicador visual (DASH-06, DATA-05).
3. **Rollback simples se ML API cair**: o fallback é documentado e
   testável. Um circuit breaker no `MlMetricsProvider` pode fazer o
   `UnifiedMetricsService` cair para `source: 'adman'` transparente
   (padrão para plan 60-03 detalhar).
4. **Empresas ML-only param de gerar 422**: a detecção no
   `MetricsProviderFactory` nunca chama Adman para empresas em caso
   "só-ML", eliminando o 422 registrado em memory
   `project_ml_only_companies_adman_endpoints`.
5. **Divergência de TACOS deixa de ser silenciosa**: log estruturado
   quando ML e Adman diferem >5% dá evidência auditável do problema
   registrado em memory `project_adman_data_sources`.

## Consequências negativas

1. **Custo de rate limit ML**: cada leitura no caso "só-ML" ou "ambos"
   custa 2 chamadas ML API (orders + ads). Mitigar com cache
   TTL 15 min (ver Estratégia de cache ML abaixo). Se o custo real em
   produção passar do orçamento de rate limit, o débito de criar
   `ml_metrics_daily` local vira prioridade.
2. **Débito consciente de não ter tabela local ML**: leituras ML são
   fresh, porém instáveis (dependem de disponibilidade e latência da
   API ML) e cacheadas por apenas 15 min. Dashboards abertos em pico
   podem gerar bursts contra a API ML.
3. **Tabela de precedência é lista curada**: se ML expuser um campo
   novo (ex: futuro `net_billing` no orders endpoint), este ADR precisa
   ser atualizado explicitamente. Não há auto-descoberta.

## Alternativas consideradas

**A. Adman primário, ML enriquece.**
Rejeitada. O cutover Adman → ML da v11.0 (Phase 42) já promoveu ML como
canonical para métricas operacionais — voltar Adman ao topo seria regressão
de decisão já validada em produção. Além disso, `adman_metrics` reflete o
último sync do scheduler (delay de até 24h), enquanto ML é fresh no
período consultado.

**B. Criar tabela `ml_metrics_daily` local com sync noturno.**
Rejeitada nesta phase por escopo/prazo. Requer: (i) nova migration; (ii)
novo scheduler analogo ao Adman; (iii) tratamento de rate limit em batch;
(iv) reconciliação de idempotência. Estimativa mínima: 1 phase inteira só
para o sync. Candidato registrado como futura milestone (v14.x ou v15.x)
após validação em produção do custo de rate limit da abordagem live.

**C. Retornar união de todas as métricas sem precedência (deixar o
consumidor decidir).**
Rejeitada. Quebra Success Criterion #2 da Phase 60 ("sem duplicação") e
espalha a decisão de precedência por todos os consumidores — exatamente o
problema que este ADR resolve. Além disso, força cada dashboard a
reimplementar reconciliação, criando divergência silenciosa entre
telas (o cenário atual antes desta phase).

## Estratégia de cache ML

- **TTL**: 15 minutos por par (empresa, período, tipo). Escolhido para
  balancear frescor (mesma janela dos dashboards ML atuais) com custo de
  rate limit em bursts.
- **Chave de cache canônica**:
  `unified_metrics:{company_id}:{from}:{to}:{source}` onde `source` é o
  valor do enum (`ml`, `adman`, `unified`). O sufixo `source` evita
  colisão entre a leitura pura de um provider e a fusão do `UnifiedMetricsService`.
- **Store**: driver `cache` padrão do Laravel (hoje `database`; em
  produção `redis` — coexiste com convenção do projeto).
- **Invalidação por sync Adman**: ao final de `AdmanService::syncCompany($c)`,
  chamar `Cache::forget("unified_metrics:{$c->id}:*")` (ou tag equivalente
  se store suportar) para evitar servir métricas Adman stale após um
  sync recém-concluído.
- **Invalidação por token ML revogado**: quando `mlToken.status` transita
  de `active` para outro estado, invalidar todas as chaves da empresa
  (a detecção do caso mudará e chaves antigas viram inválidas).
- **Sem cache no caso "none"**: DTO sentinela é barato de construir e
  cachear induziria a servir `null` mesmo após integração ser configurada.

## Rollout e feature flag

- **Coexistência sem migração de consumidor nesta phase.** Phase 60
  entrega apenas a infra (providers + service + testes). Os 6 controllers
  citados no Contexto (`DashboardController`, `CompanyController`,
  `PerformanceController`, `AdminController`, `PortfolioController`,
  `AdmanController`) NÃO são migrados nesta phase — eles seguem lendo
  `adman_metrics` direto até Phase 61.
- **Feature flag `UNIFIED_METRICS_ENABLED`** (env variable, default
  `false`). Semântica: quando `true`, `UnifiedMetricsService` está
  disponível para injeção nos consumidores que optarem em migrar. Quando
  `false` (default v14.0 inicial), o service continua bootável em testes
  mas não é usado em runtime de produção. A flag será virada para `true`
  no início da Phase 61, após validação dos testes automatizados dos 3
  casos (Success Criterion #3 da Phase 60).
- **Baseline zero-regressão**: plan 60-04 executa a suite baseline antes
  e depois; delta deve ser 0 (Success Criterion #4).
- **Sem deploy dentro da Phase 60**: entregas são infra + testes. Deploy
  fica para Phase 61 quando um consumidor real for migrado — nesse
  momento, o dashboard migrado dependerá da flag habilitada.

## Referências

- `.planning/adrs/DATA-01-multi-marketplace-model.md` — modelo N:N híbrido
  que fornece o pivot `company_marketplaces` (base multi-fonte).
- `.planning/ROADMAP.md` linhas 29-38 — Phase 60 goal + 4 Success
  Criteria (este ADR fecha o SC #2).
- `.planning/REQUIREMENTS.md` linhas 15-17 — DATA-04, DATA-05 e DATA-06.
- Memory `project_adman_data_sources` — 2 fontes Adman (sync agendado +
  MCP) e discrepância de TACOS relatada pelo usuário.
- Memory `project_ml_only_companies_adman_endpoints` — empresas ML-only
  retornam 422 nos endpoints Adman MCP durante v11.0.
- Memory `project_v11_sugadores_ml_migration` — cutover Adman → ML da
  milestone v11.0 (Phase 42), fundamento da decisão "ML primário".
- `app/Services/AdmanService.php` — fonte de leitura Adman (o que o
  provider Adman precisará envelopar).
- `app/Services/MercadoLivreService.php` — fonte de leitura ML
  (`fetchOrdersSummary` + `fetchAdsSummary` — os métodos que o provider ML
  vai chamar).
- `app/Models/AdmanMetric.php` — schema completo dos campos exclusivos
  Adman (`net_billing`, `taxes`, `product_cost`, `contribution_margin`
  etc.).
- `app/Models/Company.php` linhas 74-93 — accessors `cust_id` e
  `is_ml_driven` que a detecção do caso vai consumir.
