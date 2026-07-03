# Estudo: métricas de MLBs dentro do Adgroup em Product Ads

## 1. Problema observado

No print, o adgroup tem:

- investimento total: `R$ 5,82`;
- cliques total: `2`;
- vendas total: `0`.

Mas a seção `MLBs neste adgroup` mostra 3 cards de MLB, cada um com:

- `2 cliques`;
- `R$ 5,82`;
- `0 vendas ads`.

Isso está errado porque a métrica agregada do adgroup foi copiada para cada MLB. Se somar os cards, o resultado vira:

- `6 cliques`;
- `R$ 17,46`;
- `0 vendas`;

O total dos MLBs filhos não bate com o total do adgroup.

Regra central:

> Nunca replicar métricas do adgroup em cada MLB. Se não houver métrica individual por MLB, mostrar "métrica indisponível", não copiar o total do pai.

## 2. Resultado esperado

Na seção `MLBs neste adgroup`, cada card de MLB deve mostrar somente métricas de Product Ads daquele MLB dentro da janela do sugador:

- cliques Ads do MLB;
- investimento Ads do MLB;
- vendas Ads do MLB;
- opcional: faturamento Ads do MLB;
- opcional: impressões Ads do MLB;
- opcional: participação no gasto do adgroup.

Exemplo correto para um adgroup com 2 cliques e R$ 5,82:

```text
MLB A: 2 cliques | R$ 5,82 | 0 vendas
MLB B: 0 cliques | R$ 0,00 | 0 vendas
MLB C: 0 cliques | R$ 0,00 | 0 vendas
```

ou:

```text
MLB A: 1 clique | R$ 2,91 | 0 vendas
MLB B: 1 clique | R$ 2,91 | 0 vendas
MLB C: 0 cliques | R$ 0,00 | 0 vendas
```

O que não pode acontecer:

```text
MLB A: 2 cliques | R$ 5,82
MLB B: 2 cliques | R$ 5,82
MLB C: 2 cliques | R$ 5,82
```

## 3. Ads somente, sem orgânico

O pedido é mostrar dados apenas de Product Ads.

Portanto:

- usar `clicks`, não visitas orgânicas do item;
- usar `cost` como investimento Ads;
- usar `units_quantity` como vendas atribuídas ao Ads;
- usar `total_amount` como faturamento atribuído ao Ads;
- não usar `organic_units_quantity`;
- não usar `organic_units_amount`;
- não buscar visitas do item em endpoints gerais de item, porque isso mistura tráfego orgânico.

Se a UI quiser manter a palavra "visitas", o mais correto é renomear para:

```text
Cliques Ads
```

Product Ads documenta `clicks` e `prints`. "Visitas" do item pode ter outro significado e misturar orgânico.

## 4. Endpoints relevantes da API Mercado Livre

Todos os endpoints abaixo devem usar:

```http
Authorization: Bearer {ACCESS_TOKEN}
api-version: 2
```

### 4.1 Caminho preferencial: anúncios dentro do ad_group

Quando o sugador tem `adgroup_id` compatível com Product Ads `ad_group_id`, usar:

```http
GET https://api.mercadolibre.com/advertising/{SITE_ID}/product_ads/ad_groups/{AD_GROUP_ID}/ads
```

Com query:

```text
date_from={periodo_inicio}
date_to={periodo_fim}
metrics=clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,acos,roas
```

Exemplo documentado:

```http
GET /advertising/MLM/product_ads/ad_groups/1142185192/ads?date_from=2025-09-20&date_to=2025-10-08&metrics=clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,organic_units_quantity,organic_units_amount,organic_items_quantity,acos,tacos,sov,cvr,roas
```

Para o nosso caso, remover os campos orgânicos da query.

Esse endpoint é o melhor para a seção `MLBs neste adgroup`, porque retorna os anúncios/itens pertencentes ao adgroup com métricas por item.

### 4.2 Fallback: métricas de ads por item_id dentro da campanha

Quando já temos a lista de MLBs do adgroup, usar:

```http
GET https://api.mercadolibre.com/advertising/{SITE_ID}/advertisers/{ADVERTISER_ID}/product_ads/campaigns/{CAMPAIGN_ID}/ads/metrics
```

Com query:

```text
date_from={periodo_inicio}
date_to={periodo_fim}
filters[item_ids]={MLB1,MLB2,MLB3}
metrics=clicks,prints,cost,cpc,acos,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount
```

Esse endpoint deve retornar métricas separadas por `item_id`.

### 4.3 Alternativa: ads/search com filtros

Para investigação e fallback:

```http
GET https://api.mercadolibre.com/advertising/{SITE_ID}/advertisers/{ADVERTISER_ID}/product_ads/ads/search
```

Com filtros possíveis:

```text
filters[item_id]={MLB}
filters[campaign_id]={CAMPAIGN_ID}
metrics=clicks,prints,ctr,cost,cpc,acos,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount
```

Esse endpoint ajuda a localizar o item e confirmar `campaign_id`, `status`, `catalog_listing` e métricas, mas o caminho 4.1 é preferível quando temos `ad_group_id`.

## 5. Modelo de dados recomendado

Não guardar essas métricas apenas como array solto dentro do card.

Criar uma tabela dedicada:

```text
sugador_adgroup_mlb_metrics
```

Campos sugeridos:

```text
id
company_id
sugador_id nullable
reference_date
periodo_inicio
periodo_fim
campaign_id
adgroup_id
mlb_id
mlb_titulo nullable
thumbnail nullable
status nullable
catalog_listing boolean
adgroup_type nullable
impressions unsigned int nullable
clicks unsigned int nullable
investment decimal(10,2) nullable
direct_units unsigned int nullable
indirect_units unsigned int nullable
sales unsigned int nullable
direct_revenue decimal(10,2) nullable
indirect_revenue decimal(10,2) nullable
revenue decimal(10,2) nullable
cpc decimal(10,4) nullable
ctr decimal(10,6) nullable
acos decimal(10,4) nullable
roas decimal(10,4) nullable
metrics_source varchar
metrics_status varchar
raw_data json nullable
synced_at timestamp nullable
created_at
updated_at
```

Índice único:

```text
company_id + reference_date + campaign_id + adgroup_id + mlb_id
```

Valores de `metrics_source`:

```text
ml_adgroup_ads
ml_campaign_ads_metrics
ml_ads_search
```

Valores de `metrics_status`:

```text
exact
partial
unavailable
```

## 6. Normalização correta

Para cada item retornado pela API:

```php
[
    'mlb_id' => $row['item_id'],
    'clicks' => (int) data_get($row, 'metrics.clicks', 0),
    'impressions' => (int) data_get($row, 'metrics.prints', 0),
    'investment' => (float) data_get($row, 'metrics.cost', 0),
    'direct_units' => (int) data_get($row, 'metrics.direct_units_quantity', 0),
    'indirect_units' => (int) data_get($row, 'metrics.indirect_units_quantity', 0),
    'sales' => (int) data_get($row, 'metrics.units_quantity', 0),
    'direct_revenue' => (float) data_get($row, 'metrics.direct_amount', 0),
    'indirect_revenue' => (float) data_get($row, 'metrics.indirect_amount', 0),
    'revenue' => (float) data_get($row, 'metrics.total_amount', 0),
    'cpc' => (float) data_get($row, 'metrics.cpc', safe_div($cost, $clicks)),
    'acos' => (float) data_get($row, 'metrics.acos', safe_div($cost * 100, $revenue)),
    'roas' => (float) data_get($row, 'metrics.roas', safe_div($revenue, $cost)),
]
```

Observação:

- `sales` deve usar `units_quantity`, pois representa unidades atribuídas ao Ads.
- Não usar `organic_units_quantity` para o card do MLB.
- Não usar `organic_units_amount` para faturamento Ads.

## 7. Deduplicação obrigatória

O print mostra o mesmo MLB repetido 3 vezes:

```text
MLB6818019790
MLB6818019790
MLB6818019790
```

Isso também precisa ser corrigido.

Regra:

- agrupar por `mlb_id`;
- se o mesmo MLB vier em mais de uma linha da API, somar métricas;
- exibir apenas um card por MLB;
- manter `raw_data` com as linhas originais para debug.

Pseudocódigo:

```php
$grouped = collect($rows)
    ->groupBy('item_id')
    ->map(function ($items, $mlbId) {
        return [
            'mlb_id' => $mlbId,
            'clicks' => $items->sum('clicks'),
            'impressions' => $items->sum('impressions'),
            'investment' => $items->sum('investment'),
            'sales' => $items->sum('sales'),
            'revenue' => $items->sum('revenue'),
            'raw_data' => $items->pluck('raw_data')->values(),
        ];
    })
    ->values();
```

## 8. Validação contra o total do adgroup

Depois de buscar as métricas por MLB, comparar com o sugador/adgroup pai:

```php
$sumClicks = $mlbs->sum('clicks');
$sumInvestment = $mlbs->sum('investment');
$sumSales = $mlbs->sum('sales');

$clicksOk = $sumClicks === (int) $sugador->cliques;
$investmentOk = abs($sumInvestment - (float) $sugador->investimento_periodo) <= 0.10;
$salesOk = $sumSales === (int) $sugador->vendas_periodo;
```

Se houver diferença:

- não sobrescrever os valores individuais;
- mostrar aviso discreto na UI, por exemplo `Soma dos MLBs difere do total do adgroup`;
- gravar log com `metrics_status = partial`.

Motivos possíveis:

- item oculto/deletado;
- item delegado/revogado;
- arredondamento;
- janela de datas diferente;
- endpoint de adgroup e endpoint de campaign usam agregações ligeiramente diferentes.

## 9. Regra de fallback

Se a API não retornar métrica por MLB:

```text
Mostrar o MLB, mas com métricas "--" ou "indisponível".
```

Não fazer:

```text
copiar clicks/cost/sales do adgroup para cada MLB.
```

Não fazer:

```text
dividir investimento igualmente entre os MLBs.
```

Não fazer:

```text
estimar cliques/vendas por proporção.
```

Métrica estimada para decisão operacional pode induzir o analista ao erro.

## 10. Fluxo backend recomendado

No detalhe do sugador:

1. Ler `company_id`, `campaign_id`, `adgroup_id`, `periodo_inicio`, `periodo_fim`.
2. Procurar cache em `sugador_adgroup_mlb_metrics`.
3. Se cache existe e é da mesma janela, retornar para a UI.
4. Se não existe:
   - chamar `GET /advertising/{SITE_ID}/product_ads/ad_groups/{AD_GROUP_ID}/ads`;
   - normalizar;
   - deduplicar por MLB;
   - salvar snapshot;
   - retornar.
5. Se endpoint de adgroup falhar:
   - usar fallback `campaigns/{campaign_id}/ads/metrics` com `filters[item_ids]`;
   - se ainda falhar, retornar MLBs sem métrica individual.

Para performance:

- manter botão `Atualizar métricas MLBs`;
- cache por `company_id + reference_date + adgroup_id`;
- TTL mínimo de 24h para a mesma janela fechada;
- opcional: job off-peak para pré-carregar métricas dos sugadores pendentes de hoje.

## 11. Ajustes na UI

Na seção `MLBs neste adgroup`, cada card deve mostrar:

```text
MLB123...
Título
Cliques Ads: X
Investimento Ads: R$ Y
Vendas Ads: Z
Faturamento Ads: R$ W
% do gasto do adgroup: N%
```

No topo da seção:

```text
Soma dos MLBs: R$ 5,82 | 2 cliques Ads | 0 vendas Ads
```

Se soma não bater:

```text
Métricas parciais: soma dos MLBs difere do total do adgroup.
```

Importante:

- usar o período do sugador, não "últimos 30 dias a partir de agora";
- exibir `M1 - 30d` somente se realmente corresponde ao `periodo_inicio`/`periodo_fim`;
- se a métrica veio da API Product Ads, mostrar chip discreto `Ads`.

## 12. Testes obrigatórios

### 12.1 Não repetir métrica do pai

Fixture:

```text
adgroup: 2 cliques, R$ 5,82, 0 vendas
MLBs:
  A: 2 cliques, R$ 5,82, 0 vendas
  B: 0 cliques, R$ 0,00, 0 vendas
  C: 0 cliques, R$ 0,00, 0 vendas
```

O teste deve falhar se todos os filhos receberem `2 cliques` e `R$ 5,82`.

### 12.2 Deduplicar MLB repetido

Fixture com três linhas do mesmo `MLB6818019790`.

Resultado esperado:

```text
um único card MLB6818019790
metricas somadas das linhas reais
```

### 12.3 Sem métrica individual

Se endpoint falhar:

Resultado esperado:

```text
MLB listado com métricas indisponíveis
```

Não:

```text
MLB listado com métrica do adgroup pai
```

### 12.4 Ads somente

Fixture contendo:

```text
units_quantity = 1
organic_units_quantity = 10
```

Resultado esperado no card:

```text
Vendas Ads = 1
```

Não:

```text
Vendas = 11
```

## 13. Prompt para Claude Code

Corrigir a seção `MLBs neste adgroup`.

O bug atual é que as métricas agregadas do adgroup estão sendo copiadas para cada MLB. Isso é proibido.

Implementar métrica individual por MLB usando endpoints Product Ads:

1. Preferir `GET /advertising/{SITE_ID}/product_ads/ad_groups/{AD_GROUP_ID}/ads` com `api-version: 2`.
2. Usar o período do sugador: `periodo_inicio` e `periodo_fim`.
3. Solicitar apenas métricas Ads: `clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,acos,roas`.
4. Não usar campos orgânicos.
5. Deduplicar por `item_id`/MLB.
6. Somar métricas quando o mesmo MLB vier em múltiplas linhas.
7. Criar cache/tabela para snapshot por `company_id + reference_date + campaign_id + adgroup_id + mlb_id`.
8. Se a métrica individual não existir, mostrar indisponível. Não estimar, não dividir e não copiar do pai.
9. Adicionar teste garantindo que a soma dos cards não excede o total do adgroup quando a API retorna dados completos.

Resultado esperado:

```text
Os cards de MLBs distribuem corretamente clicks/cost/sales conforme dados reais da API Product Ads.
```

## 14. Fontes oficiais consultadas

- Product Ads leitura: `https://developers.mercadolivre.com.br/pt_br/product-ads-leitura`
- Product Ads para Catálogo e User Products: `https://developers.mercadolivre.com.br/pt_br/product-ads-para-catalogo-e-user-products-leitura`
