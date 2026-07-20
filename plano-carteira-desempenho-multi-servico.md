# Plano: Carteira e Desempenho multi-servico

## Objetivo

Corrigir a arquitetura de Carteira e Desempenho para suportar empresas com Mercado Livre/Performance e Shopee ao mesmo tempo, sem duplicar empresa e sem misturar metricas financeiras de Mercado Livre em carteiras Shopee.

Importante: o score de desempenho continua sendo unico por profissional. Nao criar um score separado de Mercado Livre e outro de Shopee. A separacao deve existir no universo da carteira, nas fontes de dados e na elegibilidade das metricas, nao na nota final.

Tambem padronizar a regra de periodo em todas as telas que exibem empresas, carteira, ranking, dashboard ou resultado financeiro:

```text
Toda metrica deve declarar periodo atual, periodo comparativo, fonte, data de atualizacao e status do dado.
Toda listagem com resultado deve ter filtro de periodo.
Toda comparacao deve vir de uma janela resolvida por uma regra unica.
```

## Diagnostico

O sistema ja possui uma base boa para resolver o problema:

- `servicos` define o catalogo de servicos e possui `setor`, incluindo `performance` e `shopee`.
- `contratos_servico` define quais servicos uma empresa contratou.
- `company_users.servico_id` permite atribuir responsaveis por servico.
- A area Shopee ja usa responsaveis por servico.
- O NPS ja possui snapshots e atribuicoes por servico em `nps_score_assignments`.

O problema principal esta em Carteira e Desempenho financeiro:

- `User::companies()` retorna uma carteira consolidada por empresa.
- `PortfolioController` usa essa lista consolidada e soma `AdmanMetric` por `company_id`.
- `DesempenhoScoreService::computeUniverso()` tambem usa `$user->companies()`.
- Assim, se uma pessoa e responsavel Shopee de uma empresa que tambem tem Mercado Livre, essa empresa entra na carteira dela e recebe faturamento/margem de Mercado Livre.

Isso causa o bug atual:

```text
Responsavel Shopee recebe empresa na carteira.
Sistema soma metricas Adman/ML dessa empresa.
Carteira e Desempenho parecem Shopee, mas os numeros sao de Mercado Livre.
```

## Decisao arquitetural

Nao criar uma nova tabela `company_services` neste momento. O sistema ja tem o equivalente:

```text
companies
  Empresa unica / cliente

servicos
  Catalogo de servicos
  setor = performance | shopee | polos | publicacao | outros

contratos_servico
  Servicos contratados por empresa

company_users
  Responsaveis por empresa, papel e servico
  servico_id preenchido = responsavel daquele servico
  servico_id null = legado/consolidado
```

A nova regra conceitual:

```text
Empresa e o cliente.
Contrato de servico e a unidade operacional.
Responsavel pertence ao servico.
Metrica financeira pertence a uma fonte e a um contexto de servico.
Score permanece unico por profissional.
```

## Regra de periodo, fechamento e pagamento

Esta regra e transversal. Nao limitar apenas a Carteira.

Ela deve valer para:

```text
Carteira individual
Carteiras consolidadas
Desempenho/ranking
Dashboard do profissional
Dashboard admin/Performance, quando mostrar resultados por empresa/profissional
Detalhe de empresa, quando mostrar metricas historicas
Metas/bonus/fechamento
Relatorios que usam os mesmos indicadores
```

### Dois contextos de leitura

O sistema precisa separar leitura operacional de leitura oficial para bonus.

### 1. Operacional: mes em curso

Uso:

```text
Acompanhamento diario.
Carteira em andamento.
Dashboard em tempo quase real.
```

Regra padrao:

```text
Mes atual ate o ultimo dia completo/confiavel da fonte
vs
mes anterior no mesmo intervalo de dias
```

Exemplo em 20/07/2026:

```text
Se a Adman ja consolidou ate 19/07:
Atual:      01/07/2026 a 19/07/2026
Comparado: 01/06/2026 a 19/06/2026

Se a fonte estiver confiavel ate 20/07:
Atual:      01/07/2026 a 20/07/2026
Comparado: 01/06/2026 a 20/06/2026
```

Regra importante:

```text
Nao comparar 20 dias de julho com junho inteiro.
Nao usar dados do dia atual se a fonte ainda nao consolidou esse dia.
```

### 2. Oficial: mes fechado para bonus

Uso:

```text
Score oficial.
Ranking de bonus.
Pagamento do mes.
Fechamento gerencial.
```

Nova regra de negocio:

```text
Durante julho, o bonus pago em julho usa o resultado fechado de junho.
Junho e comparado com a janela fechada anterior.
```

Exemplo em qualquer dia de julho/2026:

```text
Mes de pagamento: julho/2026
Competencia avaliada: junho/2026
Periodo atual fechado: 01/06/2026 a 30/06/2026
Periodo comparativo fechado: 02/05/2026 a 31/05/2026
```

Motivo do comparativo 02/05 a 31/05:

```text
Junho tem 30 dias.
A comparacao deve usar os 30 dias imediatamente anteriores a junho.
Isso evita comparar uma janela de 30 dias contra outra de 31 dias.
```

Se a diretoria decidir que o correto e sempre usar mes calendario anterior, a regra fica:

```text
Junho/2026 vs Maio/2026 calendario
01/06/2026 a 30/06/2026 vs 01/05/2026 a 31/05/2026
```

Mas pela regra descrita como "os 30 dias anteriores a junho", o plano assume janela anterior fechada de mesmo tamanho.

### Filtros de periodo

Todas as telas com resultado devem ter filtro.

Filtros recomendados:

```text
Mes atual
Ultimo mes fechado
Mes especifico
Periodo personalizado fechado
```

Comportamento:

```text
Mes atual:
  01 do mes atual ate ultimo dia confiavel
  vs mesmo intervalo do mes anterior

Ultimo mes fechado:
  mes calendario anterior completo
  vs janela anterior fechada de mesmo tamanho

Mes especifico fechado:
  mes calendario completo selecionado
  vs janela anterior fechada de mesmo tamanho

Periodo personalizado fechado:
  data inicial e data final escolhidas
  vs janela imediatamente anterior com a mesma quantidade de dias
```

Exemplos:

```text
Filtro: Junho/2026
Atual:      01/06/2026 a 30/06/2026
Comparado: 02/05/2026 a 31/05/2026

Filtro: Maio/2026
Atual:      01/05/2026 a 31/05/2026
Comparado: 31/03/2026 a 30/04/2026

Filtro: 01/06/2026 a 15/06/2026
Atual:      01/06/2026 a 15/06/2026
Comparado: 17/05/2026 a 31/05/2026
```

Todas as datas sao inclusivas.

Timezone:

```text
America/Sao_Paulo
```

## Regra de variacao de margem via Adman

Hoje o sistema calcula manualmente variacao de margem somando `contribution_margin` atual e anterior.

Nova regra:

```text
Quando a Adman devolver a variacao pronta da metrica, usar a variacao da Adman.
Nao recalcular manualmente se a fonte oficial ja trouxe o diff.
```

O print da Adman mostra dois conceitos diferentes:

```text
Margem contribuicao R$
  Valor principal: profitMargin.value
  Badge vermelho/verde: profitMargin.diff

Margem %
  Valor principal: percentageMargin.value
  Badge vermelho/verde: percentageMargin.diff
```

O indicador visual de variacao de margem percentual deve usar o badge da Adman:

```text
percentageMargin.diff
```

Para evitar ambiguidade na UI, separar os labels:

```text
Margem R$
  valor: profitMargin.value
  variacao: profitMargin.diff

Margem %
  valor: percentageMargin.value
  variacao: percentageMargin.diff
```

Se o score usa "variacao de margem %" como componente, a fonte preferencial deve ser:

```text
percentageMargin.diff
```

Se o score usa "variacao de margem de contribuicao R$" como componente, a fonte preferencial deve ser:

```text
profitMargin.diff
```

Recomendacao de produto:

```text
Usar Margem % / percentageMargin.diff para o componente "var_margem_pct".
Mostrar Margem R$ como indicador financeiro complementar.
```

Fallback permitido:

```text
Se a Adman nao devolver diff para a janela consultada, calcular manualmente e marcar source = calculated_fallback.
Se a Adman devolver diff, source = adman_diff e esse valor vence.
```

Nao fazer:

```text
Nao misturar percentageMargin.value com variacao manual de contribution_margin.
Nao mostrar o valor principal preto como se fosse variacao.
Nao recalcular por soma quando o badge/diff da Adman estiver disponivel.
```

## Regra central da carteira

Carteira nao deve ser calculada diretamente por `company_id`.

Carteira deve ser calculada por vinculos:

```text
user_id
company_id
servico_id
setor
role
```

Exemplo:

```text
Camillo Parts
  Performance / Mercado Livre
    Analista: Ana
    Estrategista: Bruno
    Fonte financeira: Adman/ML

  Shopee
    Analista: Gustavo
    Estrategista: Felipe
    Fonte financeira: indisponivel
```

Se Gustavo abre a carteira dele no contexto Shopee, Camillo Parts aparece, mas sem faturamento/margem de ML.

## Regra central do score

Manter um unico score por profissional.

Nao implementar:

```text
Score Mercado Livre
Score Shopee
Score Geral
```

Implementar:

```text
Score unico do profissional
com componentes calculados por elegibilidade.
```

O score unico pode continuar usando os componentes atuais:

```text
NPS medio
Variacao de faturamento
Variacao de margem de contribuicao
Absenteismo, quando existir fonte
```

Mas cada componente precisa respeitar fonte e elegibilidade:

### NPS

NPS ja deve considerar todas as atribuicoes congeladas do profissional:

```text
nps_score_assignments.user_id = profissional
```

Isso permite que NPS Shopee e NPS Performance entrem no mesmo NPS medio do profissional, sem misturar responsaveis.

### Faturamento

Variacao de faturamento deve considerar apenas vinculos de servico com fonte financeira disponivel.

Hoje:

```text
Performance/Mercado Livre -> tem Adman/ML
Shopee -> nao tem fonte financeira ainda
```

Logo:

```text
Empresas onde o profissional responde por Performance entram no componente financeiro.
Empresas onde ele responde apenas por Shopee nao entram no componente financeiro enquanto nao houver fonte Shopee.
```

Isso nao cria score separado. Apenas evita usar uma metrica que nao pertence ao servico.

### Margem de contribuicao

Mesma regra do faturamento:

```text
Margem atual vem de Adman/ML e deve contar apenas para vinculos Performance.
Shopee fica como sem fonte de margem ate existir API/importacao Shopee.
```

### Denominador da nota

O score unico deve ser transparente quando um componente nao tem elegibilidade.

Exemplo:

```text
Profissional com ML + Shopee:
NPS: 4.8
Var. faturamento: +10%
Var. margem: +12%
Nota = media dos componentes disponiveis
```

Exemplo:

```text
Profissional apenas Shopee, sem fonte financeira:
NPS: 4.7
Var. faturamento: sem fonte
Var. margem: sem fonte
Nota = calculada com componentes disponiveis ou marcada como parcial, conforme regra de negocio
```

Recomendacao:

```text
Se so NPS estiver disponivel, mostrar nota como "parcial" ate a diretoria aprovar a regra de bonus para Shopee sem financeiro.
Nao preencher faturamento/margem com dados ML.
```

## UI de Carteira

A tela de carteira deve ter filtro de contexto:

```text
Todos
Performance / Mercado Livre
Shopee
```

No topo:

```text
Empresas unicas: 26
Vinculos de servico: 31
Servicos com fonte financeira: 26
Servicos sem fonte financeira: 5
```

Na lista, preferir agrupamento por empresa com sublinhas por servico:

```text
Camillo Parts
  Mercado Livre | Analista | R$ 380K | margem R$ 121K | produtos sem custo 8%
  Shopee         | Analista | sem fonte financeira
```

Se o filtro for Shopee:

```text
Mostrar somente vinculos Shopee.
Nao mostrar faturamento/margem de Mercado Livre.
Mostrar status: sem fonte financeira configurada.
```

Se o filtro for Todos:

```text
Mostrar empresas agrupadas.
Somar financeiro apenas dos vinculos elegiveis.
Nao duplicar a mesma metrica ML caso a pessoa seja responsavel ML e Shopee da mesma empresa.
```

## UI de Desempenho

Desempenho deve sair visualmente de dentro do grupo Mercado Livre.

Sugestao de menu:

```text
Gestao ECF
  Carteiras
  Desempenho
  Metas

Mercado Livre
  Dashboard
  Empresas
  Sugadores
  PPA
  Grants

Shopee
  Empresas
  Dashboard
```

A pagina de Desempenho continua sendo ranking unico, mas deve exibir metadados:

```text
Empresas unicas
Vinculos de servico
Vinculos com financeiro
Vinculos sem financeiro
NPS medio
Var. faturamento
Var. margem
Status da nota: oficial | parcial | bloqueada
```

Filtros uteis:

```text
Cargo: Geral | Analistas | Estrategistas
Setor de atuacao: Todos | Performance | Shopee
```

Esse filtro nao cria outro score oficial. Ele apenas muda a visualizacao/auditoria do universo.

## Camada tecnica proposta

### `MetricPeriodResolver`

Criar um resolvedor unico de periodos.

Nome sugerido:

```text
App\Services\Metrics\MetricPeriodResolver
```

Responsabilidade:

```text
Receber filtro de periodo.
Resolver janela atual.
Resolver janela comparativa.
Resolver se o periodo e operacional ou oficial.
Resolver competencia de bonus.
Garantir datas inclusivas.
Garantir timezone America/Sao_Paulo.
Expor label para UI.
```

Shape sugerido:

```php
[
    'mode' => 'operational|official_bonus|closed_period',
    'period_key' => 'current_month|last_closed_month|2026-06|custom',
    'current_start' => '2026-06-01',
    'current_end' => '2026-06-30',
    'baseline_start' => '2026-05-02',
    'baseline_end' => '2026-05-31',
    'days_count' => 30,
    'comparison_mode' => 'previous_equal_length_window',
    'timezone' => 'America/Sao_Paulo',
    'data_fresh_until' => '2026-06-30',
    'bonus_payment_month' => '2026-07',
    'bonus_competence_month' => '2026-06',
    'is_current_month' => false,
    'is_closed' => true,
]
```

Regra:

```text
Nenhum controller deve montar periodo manualmente.
Nenhuma tela deve comparar datas com regra propria.
```

Criar um service de leitura de carteira contextual.

Nome sugerido:

```text
App\Services\Portfolio\CarteiraContextService
```

Responsabilidade:

```text
Retornar os vinculos de carteira do profissional por servico.
Resolver setor do servico.
Resolver papel do profissional no servico.
Marcar se o vinculo tem fonte financeira.
Marcar se o vinculo entra em faturamento/margem.
Evitar duplicidade quando o mesmo profissional cuida da mesma empresa em mais de um servico.
```

Shape sugerido:

```php
[
    'user_id' => 10,
    'company_id' => 123,
    'company_name' => 'Camillo Parts',
    'servico_id' => 7,
    'servico_nome' => 'Gestao de ADS Shopee',
    'setor' => 'shopee',
    'role' => 'consultor',
    'role_label' => 'Analista',
    'has_financial_source' => false,
    'financial_source' => null,
    'financial_metrics_eligible' => false,
]
```

Para Performance/Mercado Livre:

```php
[
    'setor' => 'performance',
    'has_financial_source' => true,
    'financial_source' => 'adman',
    'financial_metrics_eligible' => true,
]
```

### `AdmanMetricDiffService`

Criar uma camada pequena para ler os valores e diffs da Adman.

Nome sugerido:

```text
App\Services\Metrics\AdmanMetricDiffService
```

Responsabilidade:

```text
Ler revenue, margem R$, margem %, investimento e seus diffs quando a Adman devolver.
Preferir diff oficial da Adman.
Usar fallback calculado apenas quando o diff nao existir.
Retornar metadados de fonte por campo.
```

Shape sugerido:

```php
[
    'company_id' => 123,
    'period' => [...],
    'metrics' => [
        'revenue' => [
            'value' => 293561.81,
            'diff_pct' => 6.06,
            'diff_source' => 'adman_diff',
        ],
        'contribution_margin_value' => [
            'value' => 86597.73,
            'diff_pct' => -19.63,
            'diff_source' => 'adman_diff',
        ],
        'contribution_margin_pct' => [
            'value' => 25.20,
            'diff_pct' => 17.88,
            'diff_source' => 'adman_diff',
        ],
    ],
    'quality' => [
        'status' => 'complete|partial|missing',
        'source' => 'adman',
        'computed_at' => '...',
    ],
]
```

Campos Adman a mapear:

```text
profitMargin.value       -> margem de contribuicao R$
profitMargin.diff        -> variacao da margem de contribuicao R$
percentageMargin.value   -> margem %
percentageMargin.diff    -> variacao da margem %
grossBilling/billing     -> faturamento, conforme endpoint usado
grossBilling.diff/billing.diff -> variacao de faturamento, quando disponivel
```

Se o endpoint atual `fetchAccountMetricsCached()` descarta `diff`, criar versao detalhada:

```text
fetchAccountMetricsDetailedCached()
```

ou alterar o retorno cacheado para preservar:

```text
{ value, diff, prev }
```

sem quebrar os consumidores antigos.

## Mudancas no backend

### 0. Criar `MetricPeriodResolver`

Arquivos novos sugeridos:

```text
app/Services/Metrics/MetricPeriodResolver.php
tests/Unit/MetricPeriodResolverTest.php
```

Casos obrigatorios:

```text
20/07/2026, mes atual:
  01/07/2026..20/07/2026
  vs 01/06/2026..20/06/2026

20/07/2026, ultimo mes fechado:
  01/06/2026..30/06/2026
  vs 02/05/2026..31/05/2026
  bonus_payment_month = 2026-07
  bonus_competence_month = 2026-06

Filtro Junho/2026:
  01/06/2026..30/06/2026
  vs 02/05/2026..31/05/2026

Filtro Maio/2026:
  01/05/2026..31/05/2026
  vs 31/03/2026..30/04/2026
```

### 1. Preservar `User::companies()`

Nao remover agora.

Ele ainda pode ser usado por telas legadas e fallback.

Mas documentar:

```text
User::companies() = carteira consolidada por empresa.
Nao usar para calculo oficial de Carteira/Desempenho financeiro multi-servico.
```

### 2. Ajustar `PortfolioController`

Arquivos afetados:

```text
app/Http/Controllers/PortfolioController.php
```

Hoje o problema ocorre em:

```text
renderCarteiraProfissional()
renderCarteirasConsolidadas()
renderPortfolio()
```

Trocar a origem:

```php
$user->companies()
```

por:

```php
$carteiraContextService->forUser($user, $filters)
```

As consultas a `AdmanMetric` devem receber apenas `company_id` dos vinculos elegiveis financeiramente.

Regra:

```text
Se vinculo e Shopee sem fonte, aparece na lista, mas nao entra em SUM(revenue), SUM(contribution_margin), ad_spend, tacos ou variacao financeira.
```

Trocar tambem a resolucao de periodo manual por:

```php
$period = $metricPeriodResolver->resolve($request->all());
```

Todos os cards, tabelas e series temporais devem usar:

```text
period.current_start
period.current_end
period.baseline_start
period.baseline_end
```

Quando o filtro for mes fechado, nao usar "mes em curso" nem `now()` dentro do calculo.

### 3. Ajustar `DesempenhoScoreService`

Arquivo afetado:

```text
app/Services/DesempenhoScoreService.php
```

Hoje:

```php
$companies = $user->companies()->where('active', true)->get();
```

Novo fluxo:

```text
computeUniverso()
  retorna vinculos de servico ativos do profissional
  retorna empresas unicas
  retorna empresas elegiveis para financeiro
```

Componentes:

```text
computeNpsMedio()
  manter caminho por nps_score_assignments

computeVarFaturamento()
  usar apenas empresas/vinculos com financial_metrics_eligible = true

computeVarMargem()
  usar apenas empresas/vinculos com financial_metrics_eligible = true
  preferir percentageMargin.diff da Adman para var_margem_pct
```

Retorno do service deve adicionar metadados:

```php
[
    'empresas_unicas' => 26,
    'vinculos_servico' => 31,
    'vinculos_financeiros' => 26,
    'vinculos_sem_fonte_financeira' => 5,
    'score_status' => 'official|partial|blocked',
    'componentes_disponiveis' => [
        'nps_medio' => true,
        'var_faturamento_pct' => true,
        'var_margem_pct' => true,
    ],
    'periodo' => [
        'current_start' => '2026-06-01',
        'current_end' => '2026-06-30',
        'baseline_start' => '2026-05-02',
        'baseline_end' => '2026-05-31',
    ],
    'bonus' => [
        'payment_month' => '2026-07',
        'competence_month' => '2026-06',
    ],
]
```

Regra:

```text
Ranking oficial do mes em julho deve usar competencia junho fechada.
Ranking operacional pode continuar mostrando julho em curso, mas marcado como operacional/parcial.
```

### 4. Ajustar `PerformanceController`

Arquivo afetado:

```text
app/Http/Controllers/PerformanceController.php
```

Manter ranking unico.

Adicionar no payload de cada profissional:

```text
empresas_unicas
vinculos_servico
vinculos_financeiros
vinculos_sem_fonte_financeira
score_status
periodo
bonus.payment_month
bonus.competence_month
```

Nao criar rota separada para score Shopee.

Filtro padrao:

```text
Para tela operacional: mes atual.
Para tela de bonus/fechamento: ultimo mes fechado.
```

Se a mesma pagina atender os dois usos, exibir toggle/segmento:

```text
Em curso
Bonus atual
Mes fechado
```

### 5. Ajustar menu

Arquivo afetado:

```text
resources/js/Layouts/AppLayout.jsx
```

Mover:

```text
Desempenho
Carteira
Metas, se fizer sentido
```

para um grupo transversal, fora de Mercado Livre.

Sugestao:

```text
Gestao ECF
  Carteiras
  Desempenho
  Metas
```

### 6. Ajustar UI de carteira

Arquivos afetados:

```text
resources/js/Pages/Portfolio/AdminCarteira.jsx
resources/js/Pages/Portfolio/Carteiras.jsx
resources/js/Pages/Portfolio/Show.jsx
```

Adicionar:

```text
Filtro de setor/servico.
Badges de servico por linha.
Estado "sem fonte financeira" para Shopee.
Contadores de empresas unicas vs vinculos de servico.
Filtro de periodo em todas as telas que mostram resultado.
```

### 7. Persistir e usar diffs da Adman

Arquivos afetados:

```text
app/Services/AdmanService.php
app/Models/AdmanMetric.php
database/migrations/*
app/Services/Metrics/*
```

Adicionar campos ou snapshot de periodo para os diffs oficiais:

```text
revenue_diff_pct
contribution_margin_diff_pct
percentage_margin_diff_pct
diff_source
```

Alternativa preferida se a metrica e sempre por periodo:

```text
Criar snapshots de periodo por empresa, guardando value/diff/prev da Adman.
Nao tentar transformar todo diff de periodo em fato diario.
```

Regra:

```text
Diff de periodo pertence ao periodo consultado.
Fato diario guarda valor do dia.
Snapshot de periodo guarda comparacao da janela.
```

Backfill:

```text
Quando `raw_data` antigo tiver `profitMargin.diff` ou `percentageMargin.diff`, preencher os novos campos.
Quando nao tiver, deixar null e permitir fallback calculado marcado.
```

## Regras de compatibilidade

Durante a transicao:

```text
servico_id preenchido tem prioridade.
servico_id null continua como legado/consolidado.
```

Para dados antigos:

```text
Se a empresa tem contrato performance ativo e company_users.servico_id null, considerar como Performance legado.
Se a empresa tem contrato Shopee ativo e company_users.servico_id null, nao assumir responsavel Shopee automaticamente.
```

Nao fazer:

```text
Nao duplicar empresas.
Nao apagar User::companies().
Nao usar AdmanMetric de uma empresa no vinculo Shopee.
Nao criar score separado por marketplace.
Nao deixar Shopee com faturamento de ML apenas porque a empresa e a mesma.
```

## Fases de implementacao

### Fase 0 - Periodos e fonte de variacao

Criar `MetricPeriodResolver` e definir a leitura de diff da Adman.

Entregas:

- Resolver mes atual vs mesmo periodo do mes anterior.
- Resolver ultimo mes fechado para bonus.
- Resolver mes fechado selecionado vs janela anterior de mesmo tamanho.
- Criar ou ajustar camada para ler `percentageMargin.diff` e `profitMargin.diff`.
- Garantir que todo payload critico retorne periodo atual e comparativo.

### Fase 1 - Camada de contexto

Criar `CarteiraContextService`.

Entregas:

- Listar vinculos de servico por usuario.
- Identificar `setor`.
- Identificar `role`.
- Identificar fonte financeira.
- Deduplicar corretamente empresas unicas.
- Testar profissional com:
  - apenas Performance
  - apenas Shopee
  - Performance + Shopee na mesma empresa
  - mesmo profissional nos dois servicos da mesma empresa

### Fase 2 - Carteira individual

Refatorar `renderCarteiraProfissional()`.

Entregas:

- Usar contexto por servico.
- Usar `MetricPeriodResolver`.
- Mostrar Shopee sem fonte financeira.
- Somar faturamento/margem apenas de vinculos elegiveis.
- Usar diff da Adman para variacao de margem quando disponivel.
- Exibir empresas unicas e vinculos de servico.
- Evitar duplicar financeiro da mesma empresa no filtro Todos.
- Adicionar filtro de periodo.

### Fase 3 - Carteiras consolidadas

Refatorar `renderCarteirasConsolidadas()`.

Entregas:

- Cards por profissional com contagem correta.
- Separar empresas unicas de vinculos de servico.
- Nao puxar faturamento ML para usuario que so cuida da empresa em Shopee.
- Usar o mesmo filtro de periodo da carteira individual.
- Usar competencia correta quando a visualizacao for de bonus.

### Fase 4 - Desempenho unico com elegibilidade

Refatorar `DesempenhoScoreService`.

Entregas:

- `computeUniverso()` baseado em vinculos de servico.
- NPS continua vindo de `nps_score_assignments`.
- Faturamento/margem usam apenas vinculos com fonte financeira.
- Periodo oficial de bonus usa ultimo mes fechado.
- Variacao de margem usa diff da Adman quando disponivel.
- Score continua unico.
- Score mostra status `official`, `partial` ou `blocked`.

### Fase 5 - UI de Desempenho

Refatorar payload e tela de ranking.

Entregas:

- Mostrar empresas unicas.
- Mostrar vinculos de servico.
- Mostrar vinculos sem fonte financeira.
- Mostrar quando a nota esta parcial.
- Mostrar competencia avaliada e mes de pagamento do bonus.
- Mostrar se a pagina esta em modo operacional ou bonus.
- Manter ranking unico.

### Fase 6 - Menu

Mover Carteira e Desempenho para fora de Mercado Livre.

Entregas:

- Criar grupo `Gestao ECF`.
- Manter Mercado Livre apenas com telas realmente ML.
- Manter Shopee com telas Shopee.

### Fase 7 - Propagar filtro de periodo nas outras areas

Mapear todas as telas que mostram empresas com resultado e trocar calculos manuais de periodo pelo resolver unico.

Areas candidatas:

```text
Dashboard admin/Performance
Dashboard do profissional
Detalhe de empresa
Metas e resultados de meta
Relatorios de fechamento
Relatorios mensais
Qualquer widget que mostre revenue, margem, margem %, ads, tacos ou variacao
```

Entregas:

- Toda area com resultado recebe filtro de periodo ou herda um periodo global claro.
- Toda resposta da API/Inertia carrega `periodo`.
- Nenhum lugar calcula "mes passado" manualmente fora do resolver.
- Todo indicador critico mostra fonte e status do dado.

## Criterios de aceite

### Carteira

- Empresa com Performance + Shopee aparece uma unica vez como empresa, mas pode mostrar dois vinculos de servico.
- Usuario responsavel apenas Shopee ve a empresa na carteira Shopee.
- Usuario responsavel apenas Shopee nao ve faturamento/margem ML como se fosse dele.
- Usuario responsavel por ML e Shopee da mesma empresa nao duplica faturamento no filtro Todos.
- Tela mostra diferenca entre empresas unicas e vinculos de servico.
- Filtro padrao de mes atual compara o mesmo intervalo do mes anterior.
- Filtro Junho/2026 compara 01/06/2026..30/06/2026 com 02/05/2026..31/05/2026.
- Variacao de margem percentual usa `percentageMargin.diff` da Adman quando disponivel.
- Variacao de margem em R$ usa `profitMargin.diff` da Adman quando disponivel.

### Desempenho

- Ranking continua unico.
- Score continua unico por profissional.
- NPS Shopee entra no NPS medio do responsavel Shopee via `nps_score_assignments`.
- Faturamento e margem nao usam empresas onde o profissional atua somente em Shopee.
- Profissional apenas Shopee nao recebe variacao financeira baseada em ML.
- Profissional com ML + Shopee usa financeiro do ML onde ele e responsavel ML e NPS de todos os servicos atribuidos.
- UI indica quando a nota esta parcial por falta de fonte financeira.
- Em julho/2026, ranking oficial de bonus usa competencia junho/2026.
- Competencia junho/2026 compara 01/06/2026..30/06/2026 com 02/05/2026..31/05/2026.
- O score de bonus de junho e pago/exibido em julho.
- A tela operacional ainda pode mostrar julho em curso, mas marcada como operacional/parcial.

### Dados

- Nenhuma empresa e duplicada.
- Nenhuma atribuicao Shopee altera responsavel Performance.
- Nenhuma atribuicao Performance altera responsavel Shopee.
- `company_users.servico_id` e respeitado em todos os fluxos novos.
- `servico_id null` continua funcionando como legado ate limpeza futura.
- Fatos diarios nao guardam diff de periodo sem contexto.
- Diffs de periodo ficam em snapshot/retorno de periodo com fonte declarada.
- Fallback calculado de variacao so e usado quando a Adman nao trouxer diff.

## Testes obrigatorios

Criar ou ajustar testes para:

- Usuario analista Shopee de empresa que tambem tem ML nao recebe revenue/margem ML na carteira.
- Usuario estrategista Shopee de empresa que tambem tem ML nao recebe revenue/margem ML no desempenho.
- Usuario analista ML da empresa continua recebendo revenue/margem ML.
- Mesmo usuario responsavel ML e Shopee da mesma empresa nao duplica revenue no filtro Todos.
- NPS Shopee entra para responsavel Shopee.
- NPS ML nao entra para responsavel Shopee quando o modelo cobre apenas Performance.
- Carteira mostra empresas unicas e vinculos de servico.
- Desempenho remove `sem_carteira` somente quando o usuario nao tem nenhum vinculo ativo.
- Desempenho financeiro usa apenas vinculos com `financial_metrics_eligible = true`.
- Menu exibe Carteira e Desempenho fora do grupo Mercado Livre.
- `MetricPeriodResolver` em 20/07/2026 resolve mes atual como 01/07..20/07 vs 01/06..20/06, ou ate ultimo dia confiavel se configurado.
- `MetricPeriodResolver` em 20/07/2026 resolve bonus atual como competencia junho/2026, pagamento julho/2026.
- `MetricPeriodResolver` para Junho/2026 resolve 01/06..30/06 vs 02/05..31/05.
- `MetricPeriodResolver` para periodo customizado 01/06..15/06 resolve baseline 17/05..31/05.
- Carteira, Desempenho e Dashboard usam o mesmo objeto `periodo`.
- Variacao de margem percentual usa `percentageMargin.diff` quando presente no retorno/cache Adman.
- Variacao de margem R$ usa `profitMargin.diff` quando presente no retorno/cache Adman.
- Quando o diff Adman esta ausente, fallback calculado aparece com `diff_source = calculated_fallback`.
- Nenhum teste deve aceitar variacao de margem calculada manualmente quando `adman_diff` esta disponivel.

## Observacao final

O ponto mais importante e nao confundir:

```text
Empresa compartilhada entre marketplaces
```

com:

```text
Metrica compartilhada entre servicos
```

A empresa pode ser a mesma. O responsavel pode ate ser a mesma pessoa. Mas o servico, a fonte de dados e a elegibilidade da metrica precisam ser explicitos.

Essa mudanca resolve o bug sem quebrar o NPS ja implementado e sem criar um score separado por marketplace.
