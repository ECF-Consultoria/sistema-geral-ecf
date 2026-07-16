# Plano: Carteira e Desempenho multi-servico

## Objetivo

Corrigir a arquitetura de Carteira e Desempenho para suportar empresas com Mercado Livre/Performance e Shopee ao mesmo tempo, sem duplicar empresa e sem misturar metricas financeiras de Mercado Livre em carteiras Shopee.

Importante: o score de desempenho continua sendo unico por profissional. Nao criar um score separado de Mercado Livre e outro de Shopee. A separacao deve existir no universo da carteira, nas fontes de dados e na elegibilidade das metricas, nao na nota final.

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

## Mudancas no backend

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
]
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
```

Nao criar rota separada para score Shopee.

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
- Mostrar Shopee sem fonte financeira.
- Somar faturamento/margem apenas de vinculos elegiveis.
- Exibir empresas unicas e vinculos de servico.
- Evitar duplicar financeiro da mesma empresa no filtro Todos.

### Fase 3 - Carteiras consolidadas

Refatorar `renderCarteirasConsolidadas()`.

Entregas:

- Cards por profissional com contagem correta.
- Separar empresas unicas de vinculos de servico.
- Nao puxar faturamento ML para usuario que so cuida da empresa em Shopee.

### Fase 4 - Desempenho unico com elegibilidade

Refatorar `DesempenhoScoreService`.

Entregas:

- `computeUniverso()` baseado em vinculos de servico.
- NPS continua vindo de `nps_score_assignments`.
- Faturamento/margem usam apenas vinculos com fonte financeira.
- Score continua unico.
- Score mostra status `official`, `partial` ou `blocked`.

### Fase 5 - UI de Desempenho

Refatorar payload e tela de ranking.

Entregas:

- Mostrar empresas unicas.
- Mostrar vinculos de servico.
- Mostrar vinculos sem fonte financeira.
- Mostrar quando a nota esta parcial.
- Manter ranking unico.

### Fase 6 - Menu

Mover Carteira e Desempenho para fora de Mercado Livre.

Entregas:

- Criar grupo `Gestao ECF`.
- Manter Mercado Livre apenas com telas realmente ML.
- Manter Shopee com telas Shopee.

## Criterios de aceite

### Carteira

- Empresa com Performance + Shopee aparece uma unica vez como empresa, mas pode mostrar dois vinculos de servico.
- Usuario responsavel apenas Shopee ve a empresa na carteira Shopee.
- Usuario responsavel apenas Shopee nao ve faturamento/margem ML como se fosse dele.
- Usuario responsavel por ML e Shopee da mesma empresa nao duplica faturamento no filtro Todos.
- Tela mostra diferenca entre empresas unicas e vinculos de servico.

### Desempenho

- Ranking continua unico.
- Score continua unico por profissional.
- NPS Shopee entra no NPS medio do responsavel Shopee via `nps_score_assignments`.
- Faturamento e margem nao usam empresas onde o profissional atua somente em Shopee.
- Profissional apenas Shopee nao recebe variacao financeira baseada em ML.
- Profissional com ML + Shopee usa financeiro do ML onde ele e responsavel ML e NPS de todos os servicos atribuidos.
- UI indica quando a nota esta parcial por falta de fonte financeira.

### Dados

- Nenhuma empresa e duplicada.
- Nenhuma atribuicao Shopee altera responsavel Performance.
- Nenhuma atribuicao Performance altera responsavel Shopee.
- `company_users.servico_id` e respeitado em todos os fluxos novos.
- `servico_id null` continua funcionando como legado ate limpeza futura.

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
