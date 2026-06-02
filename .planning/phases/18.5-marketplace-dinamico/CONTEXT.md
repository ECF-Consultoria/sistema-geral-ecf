# Phase 18.5: Marketplace dinâmico no AdmanService + import CSV oficial

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-02
**Depende de:** Phase 18 W1-W5 já implementadas localmente (não deployadas ainda — esperando esta fase)

## Goal

Eliminar o bug raiz das 32 falhas de sync identificadas pela auditoria da Phase 18:

**`AdmanService::$marketplace` está hardcoded em `'meli'`** ([linha 35](app/Services/AdmanService.php#L35)) e é aplicado em **todos os 8 endpoints** que chamam a Adman. Para contas Shopee/Amazon, o path `/meli/performance/{custId}` retorna HTTP 500.

A planilha oficial da Adman (`.planning/phases/18.5-marketplace-dinamico/accounts-adman.csv`) confirma:
- 135 contas **MercadoLibre** → sincronizam OK
- **33 contas Shopee** + **1 conta Amazon** → quebram com 500 porque chamamos com path `/meli/...` em vez de `/shopee/...` ou `/amazon/...`

## Origem da fase

Diagnóstico durante Phase 18 W5-T7 (rodar `dashboard:diagnose-cust-id` em prod) classificou as 32 empresas como INVALIDO_CONFIRMADO. O usuário enviou a planilha Adman; cruzamento mostrou que **TODOS os cust_ids estão corretos** — o problema é a dimensão de marketplace que nunca foi modelada no nosso lado.

Citação do usuário (2026-06-02):
> "Vou enviar o arquivo xmls contendo todas as empresas pra ver se é possível corrigir o cust id dessas 32 empresas."

Resultado: cust_id não estava errado; **marketplace** estava errado (constante implícita).

## Decisões já tomadas no scoping

- **Coluna `companies.marketplace`** ENUM com valores `'meli'`, `'shopee'`, `'amazon'`, default `'meli'` (preserva comportamento atual para empresas existentes não importadas)
- **Comando de import lê do CSV** (não scraping nem API): operador roda manualmente quando recebe novo export da Adman
- **`AdmanService` aceita marketplace dinâmico** — métodos novos que recebem `Company` ou `$marketplace` como parâmetro
- **Refator dos callers** — todos os 8 endpoints + comandos/jobs que usam `AdmanService` passam marketplace
- **`AdmanService` NÃO instancia `'meli'` por default** ao final do refator — recebe explicitamente. Backward compat opcional: deixar default `'meli'` para chamadas antigas no escopo do refator.
- **Esta fase é pré-requisito para deploy da W5 da Phase 18** — sem ela, as badges "Cust ID Inválido" aparecem em empresas que estão na verdade certas (cust_id ok, marketplace errado)

## Estado atual verificado (2026-06-02 contra HEAD `812a848`)

### Hardcoded marketplace
```php
// app/Services/AdmanService.php:35
$this->marketplace = 'meli';
```

### Endpoints afetados (8 sites em AdmanService)
| Linha | Endpoint | Método |
|---|---|---|
| 212 | `/{marketplace}/performance/{custId}` | `fetchPerformance` |
| 590 | `/{marketplace}/ads/{custId}/campaigns` | `fetchCampaigns` |
| 604 | `/{marketplace}/ads/{custId}/{campaignId}/metrics` | `fetchCampaignMetrics` |
| 642 | `/{marketplace}/accounts/{custId}/metrics` | `fetchAccountMetrics` |
| 658 | `/{marketplace}/accounts` | `listAccounts` |
| 711 | `/{marketplace}/ads/{custId}/campaigns` (filtered) | `fetchSugadorCampaigns` |
| 876 | `/{marketplace}/ads/{custId}/adgroups/metrics` | `fetchAdsMetrics` |

### Distribuição na planilha Adman
- 135 MercadoLibre (meli)
- 33 Shopee
- 1 Amazon
- 169 total

## Success Criteria

1. **Coluna `companies.marketplace`** existe com ENUM `'meli'|'shopee'|'amazon'` e default `'meli'`; `Company::$fillable`/`$casts` atualizados; migration roda em prod sem afetar dados existentes.

2. **Comando `dashboard:import-marketplace-from-csv {arquivo}`**:
   - Lê CSV com formato da planilha Adman (cabeçalho `Nome,CustId,Tipo,...,Marketplace`)
   - Para cada linha, busca empresa no DB onde `adman_account_id = CustId`
   - Mapeia marketplace da planilha:
     - `MercadoLibre` → `meli`
     - `Shopee` → `shopee`
     - `Amazon` → `amazon`
   - UPDATE `companies.marketplace` (e só essa coluna)
   - `--dry-run` mostra preview sem aplicar
   - Sumário: total CSV, matched no DB, marketplace alterado vs preservado
   - Activity log por update

3. **`AdmanService` aceita marketplace dinâmico** em todos os métodos públicos que fazem chamadas HTTP. Estratégia escolhida pelo planner:
   - Opção A: cada método recebe `string $marketplace = 'meli'` como último parâmetro
   - Opção B: método `forCompany(Company $c)` retorna instância configurada
   - Opção C: parâmetro `Company` ao invés de `string $custId` (mais grande, mais limpo)
   - Decisão final no PLAN.md

4. **Callers atualizados** — todos os call-sites no `app/` passam marketplace baseado na empresa. Sites principais:
   - `AdmanService::syncCompany` (interno)
   - `RefreshGrossBillingCacheJob`
   - `AnalyzeCompanySugadoresJob` (via `SugadorAnalysisService`)
   - `DashboardController`
   - `SugadorController::mlbs` (via `AdmanMcpService` — pode estar fora do escopo se MCP é separado)
   - Comandos Artisan que disparam sync

5. **Import executado em prod**: `dashboard:import-marketplace-from-csv` rodado via SSH; 33 Shopee + 1 Amazon recebem marketplace correto no DB; 135 meli permanecem default.

6. **Sync ressuscitado para as 34 contas**: após import + deploy, próxima execução do `adman:sync` (11:00 BRT) inclui contas Shopee/Amazon com sucesso. Re-rodar `dashboard:audit-billing-divergence --period=30` mostra divergência caindo dramaticamente (esperado: de 71,79% para < 10%).

7. **Re-rodar `mark-custid-status` em prod** — as 34 empresas que estavam INVALIDO_CONFIRMADO viram `'ok'` automaticamente. **Pré-condição para deploy seguro da W5 da Phase 18** (badges UI ficam corretas).

8. **Testes** cobrem: import CSV com 1 linha de cada marketplace + cust_id não encontrado + sintaxe inválida; refator de `AdmanService` com marketplace dinâmico.

## Mapa de arquivos relevantes

### Backend
- [app/Services/AdmanService.php](app/Services/AdmanService.php) (942 linhas) — refator principal
- [app/Models/Company.php](app/Models/Company.php) — `$fillable`, `$casts`, possível accessor `marketplace`
- `app/Console/Commands/ImportMarketplaceFromCsv.php` (novo)
- Callers a atualizar (lista pelo grep durante o plan):
  - `app/Jobs/RefreshGrossBillingCacheJob.php`
  - `app/Jobs/AnalyzeCompanySugadoresJob.php`
  - `app/Services/SugadorAnalysisService.php`
  - `app/Http/Controllers/DashboardController.php`
  - `app/Console/Commands/SyncAdmanData.php`
  - Outros que o `grep -rn "AdmanService\|->fetchPerformance"` revelar

### Migration
- `database/migrations/2026_06_02_190000_add_marketplace_to_companies.php` (novo)

### Testes
- `tests/Feature/Phase18_5/ImportMarketplaceFromCsvTest.php` (novo)
- `tests/Feature/Phase18_5/AdmanServiceMarketplaceTest.php` (novo)

### CSV oficial
- [.planning/phases/18.5-marketplace-dinamico/accounts-adman.csv](.planning/phases/18.5-marketplace-dinamico/accounts-adman.csv) (170 linhas)

## Pitfalls antecipados

1. **Mudança de assinatura do `AdmanService`** afeta TODOS os callers. Refator grande. Decisão sobre estratégia (A/B/C acima) crítica para minimizar surface.

2. **`AdmanMcpService` é separado** — pode ou não precisar do mesmo refator. Investigar se MCP é só para drilldown Sugadores (uma origem só) ou se também varia por marketplace.

3. **Default `'meli'` em empresas existentes** preserva comportamento — empresas não importadas continuam funcionando como antes. Empresas Shopee/Amazon não importadas continuam falhando até serem importadas.

4. **Cache da Phase 16** — cache keys atuais não incluem marketplace. Se 2 empresas tivessem mesmo cust_id em marketplaces diferentes (improvável mas possível), colidiriam. Verificar se cache key precisa incluir marketplace.

5. **Testes existentes** podem mockar `AdmanService` com assinatura antiga. Mockery aceita assinaturas variáveis mas precisa ajustar.

6. **Sincronização da W5 da Phase 18** — re-execução de `mark-custid-status` PRECISA acontecer DEPOIS do sync com marketplace correto rodar (pelo menos 1×) para reclassificar as 34. Sequência:
   a. Deploy Phase 18.5 (com Phase 18 W5)
   b. Rodar import CSV em prod
   c. Aguardar próximo ciclo `adman:sync` 11:00 (ou rodar manual)
   d. Rodar `mark-custid-status` em prod
   e. UI badges aparecem corretas

7. **Comando `dashboard:diagnose-cust-id`** (Phase 18 W4-T1) também usa `AdmanService` hardcoded — precisa receber refator para usar marketplace dinâmico. Senão continua dando falso positivo em contas Shopee.

## Não-objetivos (out of scope)

- Reescrever o `AdmanMcpService` (drilldown Sugadores) — só se for descoberto que ele também tem bug similar
- Importar OUTROS campos do CSV (faturamento, ACOS, etc) — só o `marketplace` por enquanto
- Criar UI para editar o marketplace manualmente — import via CSV resolve
- Backfill histórico do `adman_metrics` para empresas Shopee/Amazon que ficaram zeradas (já decidimos não fazer backfill na Phase 18)
- Suportar marketplace adicional (e.g. Magalu, Amazon US) — só `meli`, `shopee`, `amazon` (o que a planilha tem)

## Cross-cutting constraints

- pt-BR em comentários, mensagens, commits
- `npm run build` se mexer JSX (provavelmente não nesta fase)
- Migration roda local antes do commit
- snake_case
- `AdmanService` refator NÃO deve quebrar testes existentes (suíte completa antes/depois)
- Import é **read-only para outros campos** — só UPDATE `companies.marketplace`
- Sem deploy automático — deploy é decisão explícita do usuário ao fim

## Referências adicionais

- [.planning/phases/18-dashboard-precisao-filtros/AUDIT-OUTPUT-30d.txt](.planning/phases/18-dashboard-precisao-filtros/AUDIT-OUTPUT-30d.txt) — auditoria que mostrou 71,79% divergência
- [.planning/phases/18-dashboard-precisao-filtros/DIAGNOSE-CUSTID-OUTPUT.txt](.planning/phases/18-dashboard-precisao-filtros/DIAGNOSE-CUSTID-OUTPUT.txt) — diagnose que apontou as 32 INVALIDO_CONFIRMADO
- Memory: [feedback_project_priorities.md](MEMORY.md) — regra de acertividade aplicada aqui
- Phase 16 — cache D-1 (não quebrar)

## Memory persistente relevante

- **AdmanService usa marketplace hardcoded 'meli'** — confirmado e a ser refatorado nesta fase
- **Planilha Adman cobre 169 contas (135 meli, 33 shopee, 1 amazon)** — fonte autoritativa
- **Lean planning** — pular discuss/research/plan-check
- **GSD output em pt-BR**
