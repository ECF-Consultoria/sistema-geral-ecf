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

**E o `tinker` como `www-data` MORRE antes de rodar, com `ConfigPaths.php line 456:
Writing to directory /var/www/.config/psysh is not allowed`** (medido em 2026-10-09,
no deploy da Fase 175). O home do `www-data` é `/var/www` e não é gravável por ele,
então o psysh (o REPL por trás do tinker) aborta ao tentar criar o diretório de
configuração — **antes** de executar uma única linha do seu código. Não é erro do seu
script e não tem nada a ver com permissão de banco. Receita:

```
sudo -u www-data env XDG_CONFIG_HOME=/tmp HOME=/tmp php artisan tinker --execute='...' < /dev/null
```

Isso NÃO está no repositório (é propriedade do sistema de arquivos da VPS) e volta a
morder quem reconstruir o servidor. Alternativa sem tinker, quando serve: `php artisan
db:table <tabela>` mostra colunas, defaults, índices e FKs com a regra de exclusão —
foi como conferi o schema de `pub_produtos` em produção sem precisar do REPL.

---

## 6. O faturamento passou a ser o do painel da Shopee (2026-10-06, quick `261006-fac`)

**Isto SUPERA o parágrafo final da seção 2** ("a base é `total_amount`, mantido
com frete por decisão do usuário"). No mesmo dia, com os números na mesa, o
usuário decidiu o contrário, literal: *"Quero que o faturamento das empresas no
sistemas seja exatamente igual ao faturamento mostrado no painel da shopee, se é
com frete ou sem frete não importa."* A história anterior fica registrada porque
explica os valores gravados **antes** de 06/10.

A régua nova, em `ShopeeService::fetchOrdersSummary()`:

```
revenue = Σ (pedidos com `pay_time` não vazio)
            Σ (itens)  model_discounted_price × model_quantity_purchased
```

Sem frete, **sem filtro de status**, **sem descontar item cancelado/devolvido**.

### As duas coisas que estavam erradas (medidas contra a API, não dedutíveis)

**1. O painel conta no PAGAMENTO, não no status de hoje.** Pedido pago e
cancelado depois continua sendo faturamento. O filtro `UNPAID|CANCELLED|IN_CANCEL`
descartava isso: na CAMILLO MATRIZ (#1) em 30/09 eram R$ 1.434,94 em dois pedidos
pagos e cancelados pelo comprador em seguida (R$ 1.398,13 e R$ 36,81) — painel
R$ 8.953,89, nosso valor gravado R$ 6.953,31, régua nova R$ 9.133,89.

⚠️ **E o painel conta o pedido pago POR INTEIRO.** Descontar o item cancelado
(`cancelled_qty`/`returned_qty`) **passa do alvo em 16,6%**. Parece a correção
óbvia e está errada — não desconte.

**2. O valor sai do ITEM, não do pedido.** `total_amount` (itens + frete −
promoções) às vezes fica **acima** e às vezes **abaixo** do preço dos itens: na
ITUFARMA1 (#225) um pedido tinha total R$ 31,42 contra R$ 48,46 de item, 54% de
diferença. Não existe fator de conversão: a única base estável é o item.

**A janela do dia é BRT (−03:00).** Cinco fusos testados; só o BRT fecha — UTC
erra +12% e GMT+8 erra −16%. Não "normalize" para UTC.

### Evidência: setembro/2026 inteiro contra a planilha manual do time

| loja | planilha | regra antiga | regra nova |
|---|---|---|---|
| Edumac Parts #144 | 21.149,30 | −6,09% | **0,00% (ao centavo)** |
| Camillo Filial RS #358 | 20.330,13 | −6,75% | **0,00% (ao centavo)** |
| Camillo Matriz #1 | 147.312,67 | −13,71% | −0,44% |
| Tuki Pet #364 | 79.910,90 | −5,92% | +0,30% |
| Interior Magazine #370 | 132.835,11 | −1,24% | +0,49% |
| Camillo Filial SC #131 | 268.932,28 | −10,07% | +0,82% |
| Itadecor Magazine #369 | 55.891,19 | −1,77% | +1,32% |
| Gran Belo #212 | 311.945,63 | −7,33% | +5,53% (único fora) |

Dias isolados lidos no painel pelo usuário: Camillo Matriz 30/09 → painel
R$ 8.953,89 (nosso R$ 6.953,31, régua nova R$ 9.133,89); ITUFARMA1 #225 30/09 →
painel R$ 603,72 (nosso R$ 597,17, régua nova R$ 609,13).

**Pendência conhecida, não regressão:** o resíduo é pequeno e **sempre para
cima** — provavelmente desconto aplicado no pedido e não no item. Não vale
perseguir sem uma medição nova.

### Por que mexer num método só bastou

`fetchOrdersSummary()` é **ponto único**: os 11 consumidores leem
`shopee_metrics.revenue` (`FechamentoRollupService`, `ConsolidarMesFechamento`,
`VerificarConsolidacaoFechamento`, `FechamentoConferenciaFaturamentoService`,
`AdminController`, `DashboardController`, `PortfolioController`,
`ShopeeMetricDiffService`, `RelerDiasShopee`, `ShopeeMetric`) e **nenhum deles
replica a conta**. Corrigir a coleta propaga para fechamento, dashboard, carteira
e desempenho de uma vez. Se algum dia alguém duplicar essa soma fora do service,
esta propriedade morre.

⚠️ **A régua nova só vale para o que for coletado daqui pra frente.** Os valores
já gravados seguem na régua antiga — mês fechado ainda lê snapshot congelado
(ver `project_snapshot_congelado_mes_fechado`), então recoletar setembro das 19
empresas **e refazer o fechamento** é passo humano separado, com gate, porque
muda competência passada e, por tabela, carteira/desempenho/bônus.

### Nota sobre a suíte

`--filter="Shopee|Phase158|Phase60"` tinha **7 falhas antes** de qualquer edição
e as mesmas 7 depois: `DesempenhoShopeeScoreTest` (3, `var_margem_pct` vem null),
`Phase119\CompanyScoreServiceFonteTest` (3) e
`Phase158\CalculadoraNoPortalTest` (1, ícone no mapa do layout). **Não são
regressão de quem mexe na coleta** — nenhuma delas faz fake de
`get_order_detail`; todas semeiam `shopee_metrics` direto no banco. Rode o gate
antes de editar para ter essa referência.
