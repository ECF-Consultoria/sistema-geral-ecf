# Briefing para redesign da tela de carteira de analistas/estrategistas

## Contexto

Existe uma tela de carteira para analistas e estrategistas. O objetivo dela é mostrar quais empresas estão sob gestão do profissional e como está o desempenho:

- De cada empresa individualmente.
- Da carteira como um todo, usando média e/ou soma das empresas.
- Do próprio profissional, como analista ou estrategista responsável.

A tela atual tem problemas de UI/UX: visual pesado, uso ruim de espaço, excesso de informação pouco acionável e ausência de dados mais estratégicos para a rotina do profissional.

O usuário também montou uma referência inicial no Figma com uma direção visual mais moderna: fundo escuro, topo minimalista, card principal com gradiente e faturamento em grande destaque.

## Objetivo

Redesenhar a tela para ser mais moderna, objetiva, eficiente e estratégica. A tela deve ajudar o analista/estrategista a entender rapidamente:

- Quanto a carteira está faturando.
- Quantas empresas estão sob gestão.
- Como está a meta da carteira.
- Quais empresas exigem ação imediata.
- Quais empresas puxam o resultado.
- Como o desempenho evolui no período vigente.

## Requisitos obrigatórios

A tela precisa conter:

- Quantidade de empresas na carteira.
- Nome e dados individuais de cada empresa.
- Meta da carteira.
- Faturamento total somado de todas as empresas da carteira.
- Filtro/busca para empresas.
- Período vigente da amostra dos dados.
- Gráficos de evolução/desempenho.
- Dados do profissional: nome, cargo/função e quantidade de empresas.

## Mudanças solicitadas

Remover:

- `TACOS médio` dos cards principais.

Manter TACOS apenas se for realmente útil em algum detalhe individual ou análise posterior, mas ele não deve ocupar espaço como KPI principal da carteira.

## Direção visual

Estilo desejado:

- UI moderna, escura, limpa e objetiva.
- Menos cards dispersos e mais hierarquia.
- Card principal com maior destaque visual.
- Primeiro card deve ser o mais importante: `Faturamento`.
- Esse card deve ter fundo com gradiente, inspirado no layout do Figma enviado.
- Layout deve parecer painel operacional, não landing page.
- Evitar excesso de textos explicativos.
- Usar dados compactos e fáceis de escanear.

Referência do card principal:

- Título/contexto: `Carteira - Ana Julia`
- Bloco de pessoa: `Pessoa`, `Analista - 24 empresas`
- Card destacado:
  - Label: `Faturamento`
  - Valor: `R$ 20.20M`

## Formatação de valores

Os valores monetários devem ser simplificados para leitura rápida:

- Exemplo real: `R$ 20.200.857,90`
- Exibição na UI: `R$ 20.20M`
- Milhares devem usar `K`.
- Milhões devem usar `M`.

Exemplos:

- `R$ 891.040,00` vira `R$ 891K`
- `R$ 3.469.401,00` vira `R$ 3.46M`
- `R$ 20.200.857,90` vira `R$ 20.20M`

Essa compactação é intencional para melhorar escaneabilidade, foco e precisão visual.

## Estrutura esperada da tela

### 1. Topo

Deve conter:

- Botão/ícone de voltar.
- Título: `Carteira - Ana Julia`.
- Filtro de período, exemplo: `Junho de 2026`.
- Período vigente da amostra, exemplo: `01/06 a 22/06`.
- Ação para criar nova meta, se fizer sentido no produto.

### 2. Card principal de faturamento

Esse é o card mais importante da tela.

Deve conter:

- Dados da pessoa/profissional:
  - Nome ou label `Pessoa`.
  - Cargo: `Analista`.
  - Quantidade de empresas: `24 empresas`.
- Faturamento total da carteira:
  - `R$ 20.20M`.
- Indicadores auxiliares, em chips pequenos:
  - Crescimento vs período anterior.
  - Percentual da meta atingido.
  - Valor restante para bater meta.

Exemplo:

- `+18.7% vs anterior`
- `Meta 82%`
- `R$ 4.40M restante`

### 3. KPIs secundários

Devem ser poucos e acionáveis. Sugestão:

- `Empresas na carteira`: 24.
- `Meta da carteira`: 82%, com realizado vs objetivo.
- `Prioridade do dia`: quantidade de empresas que exigem ação.
- `Investimento Ads`: valor investido e cobertura de empresas.

Evitar KPIs que não conduzem ação imediata.

### 4. Gráfico

Deve mostrar evolução e projeção da carteira no período.

Séries sugeridas:

- Realizado.
- Meta acumulada.
- Projeção.

Obrigatório:

- Interatividade ao passar o mouse.
- Tooltip com dados do ponto selecionado.

Tooltip deve trazer algo como:

- Data.
- Faturamento realizado.
- Meta acumulada.
- Diferença/saldo vs meta.

Exemplo:

```text
22/06
Realizado: R$ 20.20M
Meta: R$ 20.10M
Saldo: +R$ 100K
```

### 5. Lista/tabela de empresas

Deve ser objetiva e orientada a ação.

Colunas sugeridas:

- Empresa.
- Status.
- Faturamento.
- Meta.
- Margem.
- Ads.
- Ação recomendada.

Exemplo de linhas:

| Empresa | Status | Faturamento | Meta | Margem | Ads | Ação |
|---|---:|---:|---:|---:|---:|---|
| COMILOPARTSFILIAL | Saudável | R$ 8.45M | 96% | 44.2% | R$ 318K | Manter ritmo |
| RELOJOARIA WENUS | Crítico | R$ 3.46M | 84% | 37.5% | R$ 82K | Renovar grant |
| Dmov | Atenção | R$ 4.14K | 45% | 21.0% | R$ 0 | Ativar Ads |

### 6. Painel lateral estratégico

Pode conter:

- Ações estratégicas:
  - Ativar Ads nas empresas paradas.
  - Renovar grants vencidos.
  - Empresas abaixo de 50% da meta.
  - Empresas com queda vs período anterior.
- Top faturamento:
  - Ranking das empresas com maior faturamento.
- Metas:
  - Progresso da meta mensal.
  - Cobertura Ads.

## Responsividade

A tela deve funcionar bem em desktop e mobile.

No mobile:

- O card principal continua no topo.
- KPIs ficam empilhados.
- Tabela de empresas deve virar cards.
- Não pode haver overflow horizontal.
- Valores e botões não devem quebrar de forma feia.

## Resultado esperado

Uma tela mais moderna, intencional e operacional, que permita ao analista/estrategista responder rapidamente:

- Qual é o faturamento atual da carteira?
- Estamos perto ou longe da meta?
- Quais empresas merecem atenção hoje?
- Quais empresas sustentam o resultado?
- Qual ação prática devo tomar em cada empresa?

O design deve priorizar tomada de decisão, não apenas exibição de métricas.

## Arquivo de referência criado

Foi criado um mockup HTML estático como referência visual:

`carteira-analistas-ui-proposta.html`

Ele contém:

- Card principal com gradiente.
- Valor `R$ 20.20M`.
- KPIs simplificados.
- Gráfico com pontos interativos e tooltip.
- Tabela orientada a ação.
- Layout responsivo.

