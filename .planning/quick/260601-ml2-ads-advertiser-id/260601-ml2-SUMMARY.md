---
quick_id: 260601-ml2
slug: ml-ads-advertiser-id
status: complete
completed: 2026-06-01
---

# Summary — Correção da integração Mercado Ads (advertiser_id + aggregation_type)

## Sintoma
No painel, ACOS (30d) e Invest. Ads (30d) sempre "—" e TACOS (30d) sempre 0%
para empresas ML — mesmo para contas que rodam anúncios.

## Causa-raiz (dois bugs, confirmados contra a API real)
A publicidade **nunca** foi sincronizada para nenhuma empresa ML:

1. **advertiser_id errado.** O código usava `ml_user_id` (Seller ID) como
   advertiser do Mercado Ads. O advertiser_id é um ID próprio do Mercado Ads,
   diferente do Seller ID:
   - Rei do Mix: seller `465723451` → advertiser **71098**
   - Emp. Teste Felipe: seller `436501796` → advertiser **620095**

   Resultado: `401 user_not_authorized` em toda chamada de ads → `ad_spend`/
   `ad_revenue` caíam no fallback zerado do `syncCompany`.

2. **`aggregation_type='total'` inválido.** Mesmo com o advertiser correto, a API
   responde `400` — o campo aceita apenas `CAMPAIGN` ou `DAILY`.

Validação ao vivo (advertiser 71098 + `CAMPAIGN`): HTTP 200 com dados reais —
campanha "Lançamento" cost=R$2,89, total_amount=R$35,90, acos=8,05%.

## Correção (`app/Services/MercadoLivreService.php`)
- Novo `resolveAdvertiserId(Company)`: busca o advertiser via
  `GET /advertising/advertisers?product_id=PADS` (header `Api-Version: 1`),
  cacheado 24h por empresa; cacheia `0` (sentinela) para contas sem Mercado Ads.
- `get()` aceita `array $headers` (para o `Api-Version: 1` exigido pela API de ads).
- `fetchAdsSummary()` e `fetchCampaigns()`: usam o advertiser_id resolvido,
  `aggregation_type='CAMPAIGN'` e o header; retornam zeros/lista vazia quando a
  conta não tem Mercado Ads (sem erro).
- `fetchAdsItems()` deixado intacto — sem callers (sugadores usa AdmanService);
  fica como follow-up se a detecção de sugadores migrar para ML.

## Impacto
- ACOS, TACOS (com ads) e Invest. Ads passam a refletir dados reais para empresas
  ML que rodam Mercado Ads. Contas sem ads continuam zeradas (correto).
- Nenhuma mudança de frontend — a grade de KPIs (task 260601-fm3) já consome
  `acos_30d`/`tacos_30d`/`ad_investment_30d`.

## Verificação
- `php -l` ok. Lógica validada contra a API ML em produção (scripts read-only).
- Pós-deploy: re-sincronizar histórico (30d) das empresas ML para popular o
  `ad_spend`/`ad_revenue` retroativo: `php artisan ml:sync --company=ID --from=… --to=…`.

## Edge case (commit fe83661)
Advertiser que existe mas não tem campanhas retorna `404
advertiser_campaigns_not_found` — estava quebrando o sync inteiro do dia
(MG Store L / advertiser 404656, 30 falhas). `fetchAdsSummary`/`fetchCampaigns`
agora tratam esse 404 (+ user_not_authorized) via `isNoAdsError()` → zeros/vazio.

## Resultado verificado em produção (30d, após deploy + backfill)
| Empresa | Faturamento | Invest.Ads | ACOS | TACOS |
|---------|-------------|-----------|------|-------|
| Empresa Teste Felipe (298) | R$ 2.307.414 | R$ 68.595,80 | 4,61% | 2,97% |
| Rei do Mix (296) | R$ 229,40 | R$ 2,89 | 8,05% | 1,26% |
| MG Store L (294) | — | — | — | — (0 pedidos/0 ads no período) |

## Follow-up
- `fetchAdsItems` (sugadores ML) usa advertiser_id errado — corrigir se/quando ativar.
- NPS/Absenteísmo dependem de reuniões e pesquisas NPS cadastradas (CRM), não do ML.
