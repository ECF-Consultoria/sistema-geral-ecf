# Sugadores — Bugs 3, 4 e Caso 2 diferidos do UAT Phase 53

**Capturado:** 2026-07-02
**Contexto:** UAT Phase 53 achou 5 bugs. Hotfix Wave 4 resolveu Bugs 1 e 2. Estes 3 ficaram diferidos.

---

## Bug 3 — Rate limit ML persistente derruba `tryFetchAdsMetrics`

**Sintoma:** log de 2026-07-02 12:36-12:44 mostra dezenas de empresas caindo com "Rate limit ML excedido para seller unknown (60/min)" ANTES de `listCampaigns` rodar. Cache 10min da Wave 1 não previne a 1ª chamada.

**Root cause hipotético:** análise diária dispara em paralelo (Job por empresa) e satura os 60 req/min do ML. Cache só ajuda em re-runs.

**Fix candidato:**
- Retry com backoff (60s + jitter) no `MercadoLivreAdsService::tryFetchAdsMetrics`
- OU throttling no `AnalyzeCompanySugadoresJob` (reduzir concorrência do worker)
- OU pipeline com sleep entre companies no `sugadores:analyze` (path Command)

**Prioridade:** média. Sem esse fix, empresas grandes vão continuar tendo `campaign_name=null` mesmo com o Bug 2 corrigido.

---

## Bug 4 — Adgroup multi-MLB (venda parcial não filtra)

**Sintoma:** operador disse "se um anúncio dentro do adgroup teve venda, o adgroup não é sugador". Wave 2 filtra pelo `sold_global` do 1 MLB "principal" que o provider retorna. Se um adgroup tem N MLBs e o principal não vende mas outro sim, filtro `sold_global >= 10` não pega.

**Investigação pendente:** provider ML `/product_ads/items` retorna 1 row por adgroup ou N rows (uma por MLB)? Verificar em prod: `foreach ($results)` no `MercadoLivreSugadoresProvider::fetchAdgroupsMetrics` — quantas rows por adgroup?

**Fix candidato (depois de confirmar hipótese):**
- Se provider retorna 1 row: consultar `AdmanAdgroupMlb` pelo adgroup → agregar `fetchItemStatus.sold_quantity` de todos os MLBs
- Se provider retorna N rows: agregar `sold_global` DEPOIS do loop, por adgroup, antes de gerar upsert

**Prioridade:** média. Só afeta adgroups com > 1 MLB e onde o MLB principal do ads não é o que mais vende globalmente.

---

## Caso 2 — Config stale + cliques errados

**Sintomas relatados pelo operador:**
1. Ao mudar config de sugador, o ConfigResumoCard em `/sugadores/empresa/` continua mostrando a config antiga
2. Config aplicada foi `cliques >= 20 sem venda` mas ao rodar análise, sugadores gerados tinham < 20 cliques ao verificar no ML

**Diagnóstico pendente:** operador NÃO informou o nome da empresa. Vai lembrar e mandar depois.

**Hipóteses para o item 1 (config stale):**
- Cache Inertia (se ConfigResumoCard é populado via SSR e a resposta tem cache HTTP)
- SugadorConfig salvo mas EmpresaListagem/Show carregou a versão antiga (relação eager sem refresh)

**Hipóteses para o item 2 (cliques errados):**
- Configuração pode ser `optional` (default) — outro critério (`gasto_sem_venda` ou `cpc_alto`) bateu e adicionou motivo. Motivos retornam APENAS os que bateram; se `cliques_sem_conversao` não bateu mas `gasto_sem_venda` sim, aparece só gasto. **Não é bug, é config.**
- Diferença de janela entre clicks contados pelo provider e clicks reportados no ML painel do vendedor

**Prioridade:** baixa até operador confirmar qual empresa foi.

---

## Contexto operacional

- Bugs 1 e 2 corrigidos em commit `<TBD>` da Wave 4 hotfix Phase 53
- Waves 1 + 2 da Phase 53 continuam válidas — os fixes de detector (B1 CAMILLO, B2 BARAOSHOP, B3 DINMAP) estão em prod
- Foto real de Bugs 3 e 4 só aparece pós-cron `sugadores:analyze` de 2026-07-03 12:00 BRT
