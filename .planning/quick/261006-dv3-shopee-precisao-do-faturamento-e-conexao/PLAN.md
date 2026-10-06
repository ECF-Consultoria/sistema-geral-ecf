---
quick_id: 261006-dv3
slug: shopee-precisao-do-faturamento-e-conexao
date: 2026-10-06
status: in-progress
---

# Precisão do faturamento Shopee + robustez da conexão

## Pergunta que originou o trabalho

O setor Shopee acha que as empresas estão **desconectando sozinhas**, e relatou que
o faturamento das empresas Shopee **não bate**. Duas perguntas, uma investigação.

## Investigação em produção — 2026-10-06

Rodada direto no VPS (somente leitura: `SELECT` + `grep` em log + releitura da API
Shopee **sem gravar**). Produção está em `96ddc1bf`, que contém `2664d9ae`
(keep-alive do quick 260917-jol) — ou seja, o fix de setembro está no ar.

### 1. Ninguém desconectou sozinho

| evidência | resultado |
|---|---|
| `shopee_tokens` por app/status | **19 `erp` active, 17 `ads` active** — zero `revoked` |
| `last_refreshed_at` de todos | **2026-10-06 03:00** (o `shopee:refresh-tokens` rodou hoje) |
| `last_error` / `last_error_at` | **NULL em todos os 36 tokens** |
| `[Shopee]` com falha de renovação no `laravel.log` | **nenhuma linha** |

As **10 empresas com "convite expirado"** nunca conectaram — não é desconexão:

CARAIBAALUMINIO (link 23/07), Decoral (23/09), FACASERECHIM (03/08), Ita Prime
(03/08), LYAMDECOR (17/09), Matron Kids (17/09), RELOJOARIA WENUS (03/08),
Visammer (17/09), Vitrine do Couro (03/08), WEHOUSE (03/08). Mais Lenonn Milani e
POZELAR, que nunca receberam link.

A prova de que **nunca** houve conexão (e não "conectou e caiu") é que
`ShopeeOAuthController::callback` grava uma linha em `company_marketplaces`
(`marketplace='shopee'`) que o `disconnect()` **não apaga**. Nenhuma das 12 tem
essa linha, nenhuma tem uma única linha em `shopee_metrics`, e
`zgrep -c -i shopee /var/log/nginx/access.log*` devolve **0** — em toda a janela
retida do nginx não houve um único acesso a `/shopee/conectar` nem ao callback.
O cliente não abriu o link.

### 2. O faturamento é um retrato tirado uma vez e nunca revisitado

`shopee:sync` grava D-1 às 11:15 e **nunca volta àquele dia**. A Adman tem
`adman:reler-dias` às 19:00 exatamente para isso (quick 260930-njd). A Shopee
nunca ganhou o equivalente. Medido relendo a API hoje, sem gravar:

| empresa | dia | gravado | API agora | diferença |
|---|---|---|---|---|
| GENUINEAUTOMOTIVE | 04/10 | 37.871,24 | 38.820,84 | **+949,60 (+2,5%)** |
| MPozenato | 20/08 | 62.097,12 | 61.283,29 | **−813,83 (−1,3%)** |
| MPozenato | 28/09 | 52.020,35 | 52.645,64 | +625,29 (+1,2%) |
| GENUINEAUTOMOTIVE | 20/08 | 46.266,64 | 46.061,27 | −205,37 (−0,4%) |

Vai para os **dois lados**: pedido pago depois do sync sobe, pedido cancelado
depois do sync desce. E `syncCompanyDay` devolve `null` quando o dia não tem
pedido válido, então **cancelamento nunca baixa o número já gravado**.

Cada mês está congelado no último backfill manual que alguém rodou:
05 → 23/07 · 06 → 27/07 · 07 → 17/08 · 08 → 22/09 · 09 → 01/10 · 10 → D+1.

### 3. Histórico que falta é lido como "venda zero real"

`ShopeeMetricDiffService` documenta a premissa: "um dia sem linha é venda zero
real, não gap de sync". **Gabs Folheados (#395)** desmente: tem 34 dias gravados
(01/09→04/10, R$ 44.963,43), conectou em 23/09, mas a API ainda devolve venda em
15/05 (R$ 1.461,66 / 45 ped.), 10/07 (R$ 1.432,80 / 46), 05/08 (R$ 1.282,64 / 42)
e 25/08 (R$ 1.764,60 / 52) — nenhum deles com linha. Isso entra no baseline da
carteira como zero.

(Já os 51 dias sem linha da CAMILLOPARTS FILIAL RS **são** venda zero real —
conferido na API: 0 pedidos. Nem todo buraco é bug.)

### 4. Buraco de sync mascarado de dia zerado

`syncAdsDay` faz `updateOrCreate` por `(company_id, reference_date)` gravando só
as colunas `ad_*`. Como `shopee_metrics.revenue` tem `default(0)` e não é
nullable, um dia em que o sync de Ads passou mas o de faturamento **não** nasce
com `revenue = 0` e `synced_at = NULL`, indistinguível de dia sem venda.
São **15 linhas em 4 empresas**: GRAN BELO 7 (06/06→07/09), CAMILLOPARTS FILIAL
RS 4, EDUMAC PARTS 3, MPozenato 1.

### 5. O sync manual estoura e deixa o mês pela metade

`[Shopee] Sync manual (job) FALHOU empresa 132: SyncShopeeCompanyJob has timed
out` — **10 vezes** entre 24/07 e 22/09 (GENUINEAUTOMOTIVE, ~980 pedidos/dia).
O job roda `shopee:sync` + `shopee:sync-ads` sobre ~2 meses com `timeout = 1200`.
Escrita parcial do mês é exatamente como nasceu o incidente do fechamento de
01/10 documentado em `FechamentoConferenciaFaturamentoService`.

### 6. Risco latente de revogação espúria (a "desconexão sozinha" de verdade)

`ShopeeService::refreshToken` serializa com `Cache::lock(..., 15)` — TTL de **15s**
— e dentro da trava faz um POST com `Http::timeout(30)`. Se a Shopee demorar mais
de 15s, a trava expira **com a request em voo**, um segundo processo entra e usa o
mesmo `refresh_token` (que é rotativo/single-use); um dos dois recebe refresh
inválido e o código **revoga o token**. Não disparou ainda (zero `revoked` hoje),
mas é precisamente o sintoma que o setor relata.

## Decisões travadas

- **Faturamento continua em `total_amount`** (itens + frete pago pelo cliente −
  promoções Shopee), por decisão do usuário em 06/10. Não trocar para GMV sem
  frete, mesmo sabendo que o "Vendas" do Seller Center exclui o frete.
- **Sem backfill e sem deploy nesta tarefa.** O backfill da Gabs Folheados muda
  faturamento de competência passada, que alimenta carteira, desempenho e bônus —
  decisão do usuário, item separado.

## Tarefas

### T1 — `shopee:reler-dias` (o que estava faltando)
Comando novo espelhando `adman:reler-dias`: relê os últimos N dias (`--dias=5`
default, `--company=` opcional) de toda empresa com token ERP ativo, via
`ShopeeService::syncCompanyDay` (idempotente por `updateOrCreate`). Agendado às
19:30 com `withoutOverlapping()` — depois do `adman:reler-dias` (19:00) e longe da
janela das 11h. Resumo em `Log::error` quando houver falha, `Log::info` quando não.

### T2 — Cancelamento passa a baixar o número
`ShopeeService::syncCompanyDay`: quando o dia não tem pedido válido **e já existe
linha**, atualiza para zero com `synced_at` — hoje devolve `null` e deixa o valor
velho. Dia sem pedido **e sem linha** continua sem gravar (preserva a tabela
enxuta e o teste existente). Isso também preenche as 15 linhas órfãs do item 4.

### T3 — Corte de pedidos deixa de ser silencioso
`fetchOrdersSummary` para de parar calado em 2000 `order_sn`: limite sobe para
10000 e, se truncar, grava `Log::error` com empresa e janela.

### T4 — Falha por dia fica visível em produção
`ShopeeSync`: a falha por dia sai de `Log::warning` para `Log::error` (produção
roda `LOG_LEVEL=error`, então hoje o buraco é invisível).

### T5 — Sync manual para de estourar
`SyncShopeeCompanyJob`: `timeout` de 1200s para 3600s.

### T6 — Trava do refresh maior que o timeout HTTP
`ShopeeService::refreshToken`: TTL do `Cache::lock` de 15s para 60s (> os 30s do
`Http::timeout`).

### T7 — Testes
Cobrir: dia zerado com linha existente baixa para zero; dia zerado sem linha
continua sem gravar; `shopee:reler-dias` relê a janela e respeita `--company`;
truncamento loga erro. Mais `npm run build` (convenção do projeto) — nada de
frontend mudou, mas a convenção é rodar.

## Fora de escopo

- Backfill de histórico (Gabs Folheados e quem mais começou depois da venda real).
- Trocar a base do faturamento para GMV sem frete.
- Deploy.
