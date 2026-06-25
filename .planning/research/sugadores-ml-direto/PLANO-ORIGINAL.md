# Plano tecnico: migrar Sugadores da Adman para API oficial Mercado Livre

## 0. Premissas criticas

Este plano parte do modulo Sugadores descrito no briefing e deve preservar estes contratos:

- A UI, FSM de status e schema existente de `sugadores`, `sugador_configs` e `sugador_acoes` continuam iguais.
- `evaluateMetrics()` e `buildRow()` continuam recebendo o mesmo contrato normalizado de metricas.
- O modulo continua apenas lendo dados do Mercado Livre; qualquer acao no painel ML permanece manual pelo analista.
- A Adman nao deve ser removida no primeiro corte. Ela fica como fallback ate todas as empresas relevantes terem `mlToken` valido.
- Hoje, no sistema atual, somente `ByMobille - Teste` tem conexao direta ML. Ela deve ser o piloto funcional, mas nao basta para provar paridade contra Adman se ela nao tiver `adman_account_id`.

## 1. Arquitetura-alvo

### 1.1 Separar coleta de analise

Criar uma camada de provider para que o nucleo de deteccao nao saiba se os dados vieram da Adman ou do Mercado Livre.

Arquivos sugeridos:

- `app/Contracts/SugadoresAdsProvider.php`
- `app/Data/Sugadores/NormalizedAdgroupMetric.php` ou array normalizado, se o projeto nao usar DTOs.
- `app/Data/Sugadores/NormalizedCampaignMetric.php`
- `app/Services/Sugadores/AdmanSugadoresProvider.php`
- `app/Services/Sugadores/MercadoLivreSugadoresProvider.php`
- `app/Services/MercadoLivreAdsService.php`

Contrato minimo do provider:

```php
interface SugadoresAdsProvider
{
    public function supports(Company $company): bool;

    /** @return array<int, array> contrato normalizado de adgroup/anuncio */
    public function fetchAdgroupsMetrics(Company $company, Carbon $from, Carbon $to): array;

    /** @return array<int, array> campanhas atuais para quarentena */
    public function fetchCampaigns(Company $company): array;

    /** @return array<int, array> metricas de campanhas no periodo */
    public function fetchCampaignsMetrics(Company $company, Carbon $from, Carbon $to): array;

    /** @return array<string, array<int, string>> adgroup_id => MLB IDs */
    public function fetchAdgroupMlbs(Company $company, Carbon $from, Carbon $to): array;
}
```

`SugadorAnalysisService` deve receber um provider por injecao/resolucao e continuar trabalhando sobre o mesmo array normalizado. A implementacao Adman pode apenas encapsular o `AdmanService` atual no inicio.

### 1.2 Modos de execucao

Usar tres modos ate o cut-over:

- `adman`: comportamento atual de producao.
- `ml_shadow`: busca dados ML e compara, mas nao grava em `sugadores`.
- `ml_primary`: grava em `sugadores` usando ML; Adman vira fallback manual/diagnostico.

Config sugerida:

```php
// config/sugadores.php
'provider_mode' => env('SUGADORES_PROVIDER_MODE', 'adman'),
'shadow_companies' => env('SUGADORES_ML_SHADOW_COMPANIES', ''),
'primary_companies' => env('SUGADORES_ML_PRIMARY_COMPANIES', ''),
```

Evitar alterar `sugador_configs` para isso no primeiro momento. Se depois o time quiser UI por empresa, criar migracao propria sem misturar com o contrato funcional de criterios.

## 2. Mapeamento Adman -> Mercado Livre

> Importante: os endpoints abaixo devem ser tratados como candidatos tecnicos a validar contra a documentacao atual do Mercado Livre e contra chamadas reais com o token da ByMobille. O Claude Code deve criar primeiro um comando de smoke que imprime status HTTP, shape de payload e campos disponiveis antes de implementar a migracao inteira.

### 2.1 Descobrir advertiser

Fluxo esperado:

- Entrada local: `Company -> mlToken -> seller_id/access_token`.
- Chamada candidata: `GET /advertising/advertisers`.
- Saida necessaria: `advertiser_id`, `site_id`, `seller_id`.

Persistencia recomendada:

- Reusar `ml_tokens` se ja houver campos suficientes.
- Se nao houver, criar cache/tabela pequena `ml_advertisers` com `company_id`, `seller_id`, `advertiser_id`, `site_id`, `raw_data`, timestamps.

### 2.2 Campanhas

Equivalente Adman:

- `GET /{marketplace}/ads/{custId}/campaigns`
- `GET /{marketplace}/ads/{custId}/campaigns/{campaignId}/metrics`

Mercado Livre candidato:

- Listagem: `GET /advertising/product_ads/campaigns?advertiser_id={advertiser_id}`
- Metricas: endpoint de metricas de campanhas Product Ads, idealmente em lote por periodo; se a API so permitir por campanha, implementar com rate limit por seller e cache.

Normalizacao:

```php
[
  'campaign_id' => (string) $campaignId,
  'campaign_name' => $name,
  'campaign_status' => $status,
  'investment' => $cost,
  'revenue' => $totalRevenue,
  'sold_quantity' => $units,
  'clicks' => $clicks,
  'impressions' => $prints,
  'cpc' => $cpc ?? safe_div($cost, $clicks),
  'acos' => $acos ?? safe_div($cost * 100, $totalRevenue),
  'roas' => $roas ?? safe_div($totalRevenue, $cost),
  'raw' => $payload,
]
```

### 2.3 Adgroups/anuncios

Equivalente Adman:

- `GET /{marketplace}/ads/{custId}/adgroups/metrics`

Risco de conceito:

- A Adman expõe `adgroup`.
- O Mercado Livre pode expor Product Ads por campanha/anuncio/item sem o mesmo conceito de adgroup. O modulo deve preservar `tipo='adgroup'` na tabela, mas o `adgroup_id` normalizado pode ser o identificador estavel do Product Ad ou do agrupamento ML equivalente.

Mercado Livre candidato:

- Listagem de Product Ads/anuncios: `GET /advertising/product_ads/ads...`
- Metricas por anuncio/item/campaign-item: endpoint de metricas Product Ads no periodo.

Normalizacao obrigatoria:

```php
[
  'adgroup_id' => (string) $mlAdIdOrItemId,
  'adgroup_name' => $titleOrAdName,
  'campaign_id' => (string) $campaignId,
  'thumbnail' => $thumbnail,
  'adgroup_type' => $type,              // PRODUCT, CATALOG, MANUAL, ou equivalente
  'catalog_listing' => (bool) $isCatalog,
  'mlb_id' => $itemId,                  // MLB...
  'mlb_titulo' => $title,
  'investment' => $cost,
  'revenue' => $totalRevenue,
  'sold_quantity' => $units,
  'clicks' => $clicks,
  'impressions' => $prints,
  'cpc' => $cpc ?? safe_div($cost, $clicks),
  'ctr' => $ctr ?? safe_div($clicks, $prints),
  'acos' => $acos ?? safe_div($cost * 100, $totalRevenue),
  'roas' => $roas ?? safe_div($totalRevenue, $cost),
  'organic_amount' => $organicRevenue ?? null,
  'organic_units' => $organicUnits ?? null,
  'raw' => $payload,
]
```

Se `organic_amount` e `organic_units` nao existirem na API ML, manter `null`. Eles nao fazem parte dos criterios core descritos.

## 3. Estrategia de rate limit ML

Nao portar o bucket global `adman-api`. Criar limiter por seller:

- Bucket Laravel: `ml-api:{seller_id}`.
- Job unico continua por empresa: `ShouldBeUnique` em `company_id`.
- Backoff HTTP:
  - `429`: respeitar `Retry-After` quando presente, com teto inicial de 60s e jitter.
  - `5xx`: backoff exponencial com jitter.
  - `401`: tentar refresh token uma vez; se falhar, marcar empresa como erro de onboarding/token.
  - `403`: nao retry infinito; registrar permissao/scope ausente.
- Metricas operacionais:
  - total de chamadas por empresa,
  - paginas lidas,
  - 429 por empresa,
  - refresh token executado,
  - duracao total.

Comecar conservador, por exemplo 60 req/min por seller, e ajustar apos smoke real. O ganho principal vem de sair do limite global da Adman e particionar por token/seller.

## 4. Fases de migracao

### Fase 0: smoke tecnico com ByMobille - Teste

Objetivo: descobrir shape real da API ML sem tocar no fluxo de producao.

Entregas:

- Comando `php artisan sugadores:ml-smoke --company={id} --days=30`.
- Resolve `mlToken`, faz refresh se necessario, descobre `advertiser_id`.
- Lista campanhas.
- Lista ads/anuncios e tenta obter metricas no periodo.
- Imprime e grava JSON de amostra em `storage/app/sugadores/ml-smoke/{company_id}-{date}.json`.

Criterio de aceite:

- ByMobille retorna campanhas/ads ou erro claro de permissao/scope.
- O comando mostra quais campos existem para custo, receita, unidades, clicks, impressoes, CPC, CTR, ACOS, ROAS, item/MLB, thumbnail e status.

### Fase 1: provider ML sem gravar em `sugadores`

Objetivo: implementar normalizacao completa.

Entregas:

- `MercadoLivreAdsService` com retries, paginacao e refresh token.
- `MercadoLivreSugadoresProvider`.
- Testes unitarios de normalizacao com fixtures reais anonimizadas da ByMobille.
- `sugadores:analyze --provider=ml --company={id} --dry-run` retornando os motivos calculados sem upsert.

Criterio de aceite:

- O provider ML entrega exatamente o contrato do item §2.3.
- `evaluateMetrics()` nao precisa saber que a origem e ML.

### Fase 2: shadow mode e comparacao

Objetivo: comparar Adman vs ML sem mudar a fila do analista.

Criar tabelas auxiliares, sem alterar `sugadores`:

- `sugador_provider_runs`: `id`, `company_id`, `provider`, `reference_date`, `periodo_inicio`, `periodo_fim`, `status`, `started_at`, `finished_at`, `error`, `summary`.
- `sugador_provider_items`: `run_id`, `tipo`, `campaign_id`, `adgroup_id`, `motivos`, `metrics_json`, `raw_hash`, timestamps.

Comandos:

- `sugadores:shadow-ml --company={id|all}`
- `sugadores:compare-providers --company={id} --from=YYYY-MM-DD --to=YYYY-MM-DD`

Criterios de comparacao:

- match por chave normalizada: `tipo|campaign_id|adgroup_id`;
- match alternativo por `mlb_id` quando o conceito de adgroup divergir;
- paridade de motivos: alvo >= 95%;
- divergencias classificadas:
  - item existe so na Adman,
  - item existe so no ML,
  - metricas divergentes,
  - motivo divergente por arredondamento/calculo,
  - campanha/adgroup em quarentena tratado diferente.

Observacao importante:

- Como hoje apenas ByMobille tem ML direto, ela serve para validar funcionalidade. Para validar paridade Adman vs ML, e necessario conectar pelo menos 1 empresa que ja rode bem via Adman tambem via OAuth ML.

### Fase 3: onboarding das empresas

Objetivo: reduzir dependencia da Adman sem quebrar empresas sem token.

Backlog:

- Tela/admin report: empresas ativas com `mlToken` valido, expirado, ausente, erro de refresh.
- Checklist de onboarding por empresa:
  - conectar OAuth ML,
  - confirmar seller_id,
  - confirmar advertiser_id,
  - validar scopes Ads,
  - rodar smoke,
  - habilitar shadow.
- Politica temporaria:
  - empresas sem `mlToken`: continuam Adman;
  - empresas com token mas smoke falha: Adman + alerta operacional;
  - empresas com shadow aprovado 7 dias: candidatas a `ml_primary`.

### Fase 4: cut-over por empresa

Objetivo: trocar a origem sem alterar UX.

Processo:

1. Selecionar empresas com 7 dias de shadow aprovado.
2. Colocar em `SUGADORES_ML_PRIMARY_COMPANIES`.
3. Rodar analise diaria ML gravando em `sugadores`.
4. Manter shadow Adman por mais 7 dias apenas para comparacao.
5. Se divergencia critica ocorrer, remover empresa do primary e voltar para Adman no proximo run.

Criterio de aceite:

- mesmos 17 testes Feature continuam passando;
- ByMobille funciona em ML primary;
- primeira empresa com Adman+ML atinge >= 95% de paridade de motivos por 7 dias;
- nenhum status travado e sobrescrito;
- `bulkMove`, quarentena, auto-resolve e drilldown continuam inalterados.

### Fase 5: remocao gradual da Adman

So iniciar quando:

- 100% das empresas ativas MLB tiverem `mlToken` valido;
- scheduler ML estiver estavel;
- 429 ML < 1% das chamadas por 7 dias;
- contas grandes concluirem dentro de 900s;
- suporte/operacao aceitar que Adman nao e mais fallback.

Depois:

- remover env obrigatorio `ADMAN_API_KEY`;
- trocar nomes de tabelas/jobs apenas se valer a pena;
- manter migracao historica e compatibilidade de leitura.

## 5. Decisao sobre `adman_adgroup_mlbs`

Nao eliminar agora.

Recomendacao:

- Manter a tabela/cache durante toda a migracao para preservar SLA da UI de drilldown < 3s.
- No provider ML, popular a mesma tabela com `adgroup_id -> [MLB IDs]` usando dados que vierem inline da API ML.
- Criar uma abstracao de repositorio, por exemplo `AdgroupMlbMapRepository`, para esconder o nome legado.
- Renomear a tabela so depois do cut-over completo, com migracao simples para `ads_adgroup_mlbs` ou `sugador_adgroup_mlbs`.

Motivo: mesmo se ML devolver o vinculo inline, a UI nao deve depender de chamada live para abrir drilldown.

## 6. Pontos de implementacao no codigo atual

Prioridade de edicao:

1. Criar provider contract e encapsular Adman atual sem mudar comportamento.
2. Implementar smoke ML.
3. Implementar `MercadoLivreAdsService` e provider ML.
4. Refatorar `SugadorAnalysisService` para usar provider resolvido.
5. Ajustar `AnalyzeCompanySugadoresJob` para `RateLimited('ml-api:{seller_id}')` quando provider for ML.
6. Adicionar shadow tables e comandos de comparacao.
7. Adicionar scheduler shadow separado, sem mexer no scheduler Adman ate o primary estar aprovado.

Evitar no primeiro PR:

- Reescrever UI.
- Remover Adman.
- Renomear tabelas legadas.
- Mudar criterios de deteccao.
- Mudar FSM.

## 7. Testes

### Unitarios

- Normalizacao ML para todos os campos do contrato.
- Calculo fallback de `cpc`, `ctr`, `acos`, `roas`.
- Tratamento de metricas ausentes como `null`.
- Mapeamento de status ML para `active|paused|closed|ended`.
- `shouldSkipCampaign()` continua ignorando SGI/Sugador/Sugadores e paused/closed/ended.
- Retry/backoff para `429`, `5xx`, `401`, `403`.

### Feature

- Rodar todos os existentes em `tests/Feature/Sugador*` e `tests/Feature/Phase30/`.
- Adicionar teste de `analyzeCompany` com provider ML fake.
- Adicionar teste que status travado nao volta para `pendente` quando reanalisado via ML.
- Adicionar teste de auto-resolve com chave nao redetectada via ML.
- Adicionar teste de bulk move inalterado.

### Paridade

Para empresas com Adman+ML:

- Executar 7 dias seguidos.
- Exportar:
  - total Adman,
  - total ML,
  - intersecao por chave,
  - divergencias por motivo,
  - divergencias por metrica maior que tolerancia.
- Tolerancias sugeridas:
  - dinheiro: diferenca <= 1% ou <= R$ 0,10;
  - percentuais: <= 0,5 p.p.;
  - inteiros: igual ou diferenca explicada por janela/atribuição.

## 8. Riscos e mitigacoes

- ML nao possui conceito identico de adgroup: preservar `tipo='adgroup'`, mas documentar `adgroup_id` como ID normalizado do Product Ad/item.
- ML nao retorna revenue total igual Adman: calcular com campo mais proximo e registrar divergencia; nao esconder esse gap.
- Token expira ou scope Ads ausente: falhar por empresa, nao quebrar o run global.
- Empresas sem OAuth: Adman continua ate onboarding.
- Conta grande estoura timeout: preferir endpoints em lote; se nao houver, particionar por campanha e usar cache incremental diario.
- Quarentena divergente por status/nome: normalizar campanhas antes da avaliacao e manter fail-open se listagem falhar.

## 9. Prompt operacional para Claude Code

Use este plano como backlog. Comece pela Fase 0 e nao implemente o cut-over ainda.

Primeira entrega esperada:

1. Localizar `MercadoLivreService`, `ml_tokens`, `SugadorAnalysisServiceMl` e comandos existentes.
2. Criar `sugadores:ml-smoke --company={id} --days=30`.
3. Usar a empresa `ByMobille - Teste` como piloto.
4. Validar endpoints Product Ads reais, headers exigidos, paginacao e shape de metricas.
5. Gravar fixture JSON anonimizavel em `storage/app/sugadores/ml-smoke`.
6. Gerar um relatorio curto com:
   - endpoints que funcionaram,
   - campos disponiveis,
   - campos ausentes,
   - equivalencia com o contrato normalizado,
   - blockers de permissao/token.

Nao avance para substituir a Adman antes desse smoke estar verde.

## 10. Referencias para validar durante implementacao

- Documentacao oficial Mercado Livre Developers: https://developers.mercadolivre.com.br/
- API base Mercado Livre: https://api.mercadolibre.com/
- Secao Mercado Ads/Product Ads da documentacao oficial deve ser a fonte final para nomes exatos de endpoints, parametros, headers e rate limits.
