---
quick_id: 261006-dv3
slug: shopee-precisao-do-faturamento-e-conexao
date: 2026-10-06
status: complete
commits:
  - 2dc488ed
---

# Shopee: ninguém desconectou, e o faturamento agora se corrige sozinho

Duas perguntas, uma investigação em produção e seis correções. **Nada foi para
produção** — sem deploy, sem backfill, nenhum comando de escrita rodado no VPS.

## Resposta 1 — nenhuma empresa desconectou sozinha

Medido em 2026-10-06 contra o banco de produção (somente leitura):

- **19 tokens `erp` + 17 `ads`, todos `active`**, todos renovados às 03:00 do dia
  pelo `shopee:refresh-tokens`. Zero `revoked`. `last_error` NULL nos 36.
- **Nenhuma** linha de falha de renovação no `laravel.log`. Os únicos erros
  `[Shopee]` em todo o log são 10 timeouts do sync manual da empresa 132.

As **12 empresas sem conexão nunca conectaram**. A prova é que o
`ShopeeOAuthController::callback` grava uma linha em `company_marketplaces`
(`marketplace='shopee'`) que o `disconnect()` **não apaga** — nenhuma das 12 tem
essa linha, nenhuma tem uma única linha em `shopee_metrics`, e
`zgrep -c -i shopee` em toda a janela retida do nginx devolve **0**: o cliente não
abriu nem a landing nem o callback.

Com convite pendente/vencido: CARAIBAALUMINIO (23/07), Decoral (23/09),
FACASERECHIM (03/08), Ita Prime (03/08), LYAMDECOR (17/09), Matron Kids (17/09),
RELOJOARIA WENUS (03/08), Visammer (17/09), Vitrine do Couro (03/08), WEHOUSE
(03/08). Sem link algum: Lenonn Milani, POZELAR.

O badge vermelho "Convite expirado" é convite vencido, não queda — mas é vermelho,
e foi lido como queda. Mesma conclusão do quick `260917-jol` em 17/09, agora com
dados de outubro.

**O risco real existia, mas em outro lugar:** `refreshToken` usava
`Cache::lock(..., 15)` com `Http::timeout(30)` dentro. Resposta lenta da Shopee
fazia a trava expirar com a request em voo, um segundo processo reusava o
`refresh_token` (rotativo, single-use) e o código **revoga** o token. Nunca
disparou, mas era exatamente o sintoma relatado. TTL agora é 60s.

## Resposta 2 — o faturamento estava errado por nunca ser relido

`shopee:sync` gravava D-1 às 11:15 e **nunca voltava àquele dia**. A Adman tem
`adman:reler-dias` desde o quick `260930-njd`; a Shopee não tinha equivalente.
Medido relendo a API sem gravar:

| empresa | dia | guardado | Shopee em 06/10 | |
|---|---|---|---|---|
| GENUINEAUTOMOTIVE | 04/10 | 37.871,24 | 38.820,84 | **+2,5%** |
| MPozenato | 28/09 | 52.020,35 | 52.645,64 | +1,2% |
| MPozenato | 20/08 | 62.097,12 | 61.283,29 | **−1,3%** |
| GENUINEAUTOMOTIVE | 20/08 | 46.266,64 | 46.061,27 | −0,4% |

Vai para os dois lados, e a correção só sabia subir. Cada mês estava congelado no
último backfill manual: 05 → 23/07 · 06 → 27/07 · 07 → 17/08 · 08 → 22/09 ·
09 → 01/10.

Também achei **histórico lido como venda zero**: Gabs Folheados (#395) tem 34 dias
gravados (01/09→04/10) e conectou em 23/09, mas a API ainda devolve R$ 1.282–1.764
por dia em 15/05, 10/07, 05/08 e 25/08. E **15 linhas em 4 empresas** (GRAN BELO 7,
CAMILLOPARTS FILIAL RS 4, EDUMAC PARTS 3, MPozenato 1) nasceram só do sync de Ads,
com `revenue = 0` e `synced_at` NULL — buraco de sync disfarçado de dia zerado.

(Já os 51 dias sem linha da CAMILLOPARTS FILIAL RS são venda zero de verdade —
conferido na API. Nem todo buraco é bug.)

## O que mudou — commit `2dc488ed`

| # | mudança |
|---|---|
| T1 | **`shopee:reler-dias`** (novo, agendado 19:30): relê os últimos N dias (default 5) de toda empresa com token ERP ativo. 19:30 fica depois do `adman:reler-dias` e longe da janela das 11h. |
| T2 | `syncCompanyDay` grava o dia **zerado** quando a linha já existe — antes devolvia `null` e deixava o valor velho, então cancelamento nunca baixava o número. |
| — | **`upsertDia()`** substitui o `updateOrCreate(['company_id','reference_date'])`. Bug achado durante os testes: o match por igualdade crua de `reference_date` só funciona no MySQL; no SQLite o valor é `Y-m-d 00:00:00`, o `where` não casa e o `updateOrCreate` partia para INSERT, estourando a unique. Invisível até agora porque nenhum teste regravava um dia existente — e a releitura diária faz exatamente isso. |
| T3 | `fetchOrdersSummary` para de truncar calado: teto de 2000 → 10000 e `Log::error` quando corta (a GENUINEAUTOMOTIVE faz ~980 pedidos num dia normal). |
| T4 | `ShopeeSync`: falha por dia de `Log::warning` → `Log::error` (produção roda `LOG_LEVEL=error`). |
| T5 | `SyncShopeeCompanyJob`: `timeout` 1200s → 3600s. Estourou 10× na empresa 132 entre 24/07 e 22/09, deixando o mês pela metade — origem do incidente do fechamento de 01/10. |
| T6 | `refreshToken`: TTL do `Cache::lock` 15s → 60s, agora maior que o `Http::timeout(30)`. |
| T7 | `ShopeeRelerDiasTest` — 8 testes novos. |

Decisão travada pelo usuário em 06/10: **o faturamento continua em `total_amount`**
(inclui o frete pago pelo cliente), ciente de que o "Vendas" do Seller Center
exclui o frete e que por isso nosso número é estruturalmente maior.

## Verificação

- `ShopeeRelerDiasTest`: **8/8**.
- Suíte `--filter Shopee`: 232 testes, **6 falhas pré-existentes**, nenhuma minha:
  - `Phase119\CompanyScoreServiceFonteTest` (3) — guard de hash do
    `DesempenhoScoreService.php` não rotacionado. O arquivo está **byte-idêntico ao
    HEAD** (`96e4ad12…`) e o teste cobra `a78b7d28…`; o service mudou em 24/08
    (`1db55a7d`), o teste em 12/08 (`96c523fc`).
  - `DesempenhoShopeeScoreTest` (3) — já documentadas em
    `.planning/learnings/desempenho-bonificacao.md` §0.02.
- `npm run build`: ok (nada de frontend mudou; convenção do projeto).

## Pendente — decisão do usuário

1. **Deploy.** Precisa de `queue:restart` depois, porque o `SyncShopeeCompanyJob`
   mudou de timeout. Não há migration.
2. **Primeira releitura retroativa.** O `shopee:reler-dias` só alcança os últimos 5
   dias. Para corrigir setembro e outubro de uma vez:
   `shopee:reler-dias --dias=40`.
3. **Backfill do histórico da Gabs Folheados** (e de quem mais começou a ser
   coletado depois de já vender). Muda faturamento de competência passada, que
   alimenta carteira, desempenho e bônus — gate humano, não entra de carona.
4. **As 15 linhas órfãs** com `synced_at` NULL fora da janela de 5 dias pedem
   `shopee:sync --company= --from= --to=`.
5. **Avisar o setor Shopee** de que o badge "Convite expirado" significa convite não
   aberto pelo cliente, e que em 06/10 nenhuma loja havia caído.

## Conhecimento registrado

`.planning/learnings/shopee-conexao-e-faturamento.md` — os três sinais que provam
"nunca conectou", a base `total_amount` e por que não mexer nela sozinho, o
detector `synced_at IS NULL`, a armadilha do `updateOrCreate` com coluna `date`, e
a receita de auditoria por `plink` (incluindo o `< /dev/null` sem o qual o
`artisan tinker` pendura).
