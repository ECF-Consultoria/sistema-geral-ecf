# Shopee: conexão e faturamento

Leitura obrigatória antes de responder "empresa X desconectou?" ou "o faturamento
Shopee está certo?". Concentra o que foi medido em produção e **não é dedutível do
código**.

Medições de 2026-10-06 (investigação do quick `261006-dv3`), confirmando e
estendendo o quick `260917-jol` de 17/09.

---

## 1. "As empresas estão desconectando sozinhas" — nunca aconteceu, duas vezes

O setor Shopee relatou isso em setembro e de novo em outubro. Nas duas vezes a
medição disse o contrário.

Em 2026-10-06: **19 tokens `erp` + 17 `ads`, todos `active`**, todos com
`last_refreshed_at` às 03:00 do próprio dia (o `shopee:refresh-tokens` roda), zero
`revoked`, `last_error` NULL nos 36, e **nenhuma** linha de falha de renovação no
`laravel.log`.

### Como provar que nunca houve conexão (e não "conectou e caiu")

`ShopeeOAuthController::disconnect()` **apaga** as linhas de `shopee_tokens`, então
a ausência de token não distingue os dois casos. O que distingue:

| sinal | por que serve |
|---|---|
| `company_marketplaces` com `marketplace='shopee'` | o `callback` do ERP cria essa linha e o `disconnect()` **não** apaga — é o carimbo permanente de "já conectou uma vez" |
| qualquer linha em `shopee_metrics` | só nasce com token funcionando |
| `zgrep -c -i shopee /var/log/nginx/access.log*` | **zero** = o cliente nunca abriu nem a landing `/shopee/conectar` nem o callback |

Em 06/10 as 12 empresas sem token falhavam nos **três** sinais. Não é desconexão:
é convite que o cliente não abriu. (Mesma conclusão de 17/09, com outras datas de
link.)

### O que a tela mostra e o que o setor lê

`StatusBadge` em `ShopeeOAuth/Index.jsx` decide por precedência: token `active`
ganha de tudo. "Convite expirado" (vermelho) só aparece quando **não há token** e
`shopee_link_generated_at + 7 dias` já passou. É um convite vencido, não uma queda
— mas é vermelho, e vermelho é lido como "caiu". Quando a pergunta voltar, comece
pelos três sinais acima antes de abrir o código.

### O risco de revogação espúria que existia de verdade

`ShopeeService::refreshToken` serializava com `Cache::lock(..., 15)` e fazia, dentro
da trava, um POST com `Http::timeout(30)`. Resposta lenta da Shopee fazia a trava
expirar **com a request em voo**; um segundo processo entrava e usava o mesmo
`refresh_token`, que é rotativo e single-use. Um dos dois recebia refresh inválido e
o código **revoga** o token. Nunca disparou (zero `revoked` em 06/10), mas era
exatamente o sintoma relatado. TTL subiu para 60s no `261006-dv3`. **Regra: o TTL da
trava tem que ser maior que o timeout HTTP de dentro dela.**

---

## 2. O faturamento era um retrato tirado uma vez e nunca revisitado

`shopee:sync` grava D-1 às 11:15 e **nunca voltava àquele dia**. A Adman tem
`adman:reler-dias` às 19:00 desde o quick `260930-njd`; a Shopee não tinha
equivalente até o `261006-dv3`.

Medido relendo a API em 06/10 **sem gravar** (`fetchOrdersSummary` é leitura pura —
use isso para auditar sem efeito colateral):

| empresa | dia | guardado | Shopee em 06/10 | |
|---|---|---|---|---|
| GENUINEAUTOMOTIVE | 04/10 | 37.871,24 | 38.820,84 | **+2,5%** |
| MPozenato | 28/09 | 52.020,35 | 52.645,64 | +1,2% |
| MPozenato | 20/08 | 62.097,12 | 61.283,29 | **−1,3%** |
| GENUINEAUTOMOTIVE | 20/08 | 46.266,64 | 46.061,27 | −0,4% |

Vai para os **dois lados**: pedido pago depois do sync sobe, pedido cancelado
depois desce. E até o `261006-dv3` a correção só sabia subir, porque
`syncCompanyDay` devolvia `null` no dia sem pedido válido e deixava o valor velho.

Antes da releitura diária, cada mês ficava congelado no último backfill **manual**:
05 → 23/07 · 06 → 27/07 · 07 → 17/08 · 08 → 22/09 · 09 → 01/10. É por isso que o
re-sync de 01/10 às 10:42 mudou setembro inteiro de 19 empresas e a Gabs Folheados
saltou 6x — ver `FechamentoConferenciaFaturamentoService`.

**A base é `total_amount`** (itens + frete pago pelo cliente − promoções Shopee).
O "Vendas" do Seller Center exclui o frete, então nosso número é estruturalmente
**maior** que o do painel da Shopee. Isso foi perguntado ao usuário em 06/10 com o
caso na mesa e ele escolheu **manter com frete**. Não "corrija" isso sem perguntar
de novo — e lembre que trocar a base mexe retroativamente em carteira, desempenho
e bônus.

---

## 3. "Dia sem linha = venda zero real" é premissa do código e às vezes é falsa

`ShopeeMetricDiffService` documenta: "um dia sem linha é venda zero real, não gap de
sync". Na maioria dos casos é verdade — os 51 dias sem linha da CAMILLOPARTS FILIAL
RS foram conferidos na API e são **0 pedidos de fato**. Mas:

**Gabs Folheados (#395)** tinha 34 dias gravados (01/09→04/10) e conectou em 23/09,
enquanto a API ainda devolvia venda em 15/05 (R$ 1.461,66 / 45 ped.), 10/07
(R$ 1.432,80 / 46), 05/08 (R$ 1.282,64 / 42) e 25/08 (R$ 1.764,60 / 52). Todo esse
histórico entra no baseline da carteira como **zero**. O backfill de uma empresa que
começou a ser coletada depois de já vender é item separado, com gate humano, porque
muda competência passada.

**Antes de concluir "a empresa não vendeu nesse dia", releia a API.** O custo é uma
chamada e a resposta é definitiva.

### Buraco de sync mascarado de dia zerado

`syncAdsDay` faz merge na mesma linha diária. Como `shopee_metrics.revenue` tem
`default(0)` e não é nullable, um dia em que o sync de **Ads** passou mas o de
**faturamento** não nasce com `revenue = 0` e `synced_at` **NULL** —
indistinguível de dia sem venda. Eram 15 linhas em 4 empresas em 06/10 (GRAN BELO 7,
CAMILLOPARTS FILIAL RS 4, EDUMAC PARTS 3, MPozenato 1).

**`synced_at IS NULL` é o detector.** Query de auditoria:

```sql
SELECT company_id, COUNT(*), MIN(reference_date), MAX(reference_date)
FROM shopee_metrics WHERE synced_at IS NULL GROUP BY company_id;
```

O `shopee:reler-dias` só conserta as que caírem na janela dele; as antigas pedem
`shopee:sync --company= --from= --to=`.

---

## 4. Armadilhas de implementação que custaram tempo

**`updateOrCreate(['company_id','reference_date'])` em `shopee_metrics` só funciona
no MySQL.** A coluna é `date` e o cast do model é `date`: no MySQL a comparação é
tolerante, no SQLite dos testes o valor gravado é `Y-m-d 00:00:00` e o `where` por
`Y-m-d` **não casa** — o `updateOrCreate` parte para INSERT e estoura a unique
`(company_id, reference_date)`. Passou anos invisível porque nenhum teste regravava
um dia que já existia. Use `whereDate` para localizar a linha (é o que
`ShopeeMetricDiffService::naJanela()` já fazia e o que `ShopeeService::upsertDia()`
faz agora). Note que aqui o SQLite é mais **estrito** que o MariaDB — o inverso da
armadilha usual.

**`fetchOrdersSummary` parava calado ao atingir o teto de pedidos da janela.** Era
2000, sem um log. A GENUINEAUTOMOTIVE faz ~980 pedidos num dia **normal**; um dia de
promoção passaria e o faturamento sairia truncado sem ninguém saber. Teto em 10000 e
`Log::error` quando corta.

**`SyncShopeeCompanyJob` (o botão "Sincronizar") estourava.** `timeout` de 1200s
contra ~2 meses de loja movimentada: `[Shopee] Sync manual (job) FALHOU empresa 132:
has timed out` **10 vezes** entre 24/07 e 22/09. Escrita parcial do mês. Subiu para
3600s, mas **o botão continua sendo a forma mais fácil de deixar um mês meio
gravado** — depois de usá-lo em loja grande, confira.

**Produção roda `LOG_LEVEL=error`.** Qualquer `Log::warning` no caminho do sync é
invisível lá. A falha por dia do `shopee:sync` era warning; virou error. Ao criar
diagnóstico novo no módulo, já nasça em `Log::error` ou aceite que ninguém vai ver.

---

## 5. Receita de auditoria (somente leitura)

Os scripts PHP longos quebram no `plink` por quoting. O que funciona: escrever o
script local, `base64 -w0`, `pscp` para `/tmp`, decodificar e rodar.

**`php artisan tinker <arquivo>` PENDURA no plink sem `< /dev/null`** — ele fica
esperando stdin e o comando nunca volta. Sempre
`php artisan tinker /tmp/x.php > /tmp/x.out 2>&1 < /dev/null` e depois leia o
arquivo. Perdi duas rodadas nisso.
