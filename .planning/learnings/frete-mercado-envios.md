# Frete do Mercado Envios — tabela, cubado, modalidade e cotação por tipo

> Leia antes de mexer em `config/estrutura_produtos.php` (frete e limites), `FreteMe2Service`,
> `TabelaFreteEcf`, `LogisticaProduto`, `ModalidadeDeEnvio`, no frete sugerido da Precificação do
> Portal ou em qualquer conta de "quanto o vendedor paga de envio". Decisão do usuário de 09/10/2026:
> **"seguir o ML em tudo"**. Precificação: ADR `PORTAL-02` §"Revisão de 09/10/2026".

## 1. O ML trocou a tabela de custos DUAS vezes em 2026 — e ninguém percebeu por 6 semanas

- **02/03/2026**: a tabela que o sistema usava (cópia da aba "Frete ML Verde" da planilha
  3Planejamento), 29 linhas — sem a faixa "de 9 a 10 kg".
- **24/08/2026**: tabela nova, 30 linhas (ganhou "de 9 a 10 kg"), as mesmas 8 colunas de preço
  (0–18,99 · 19–48,99 · 49–78,99 · 79–99,99 · 100–119,99 · 120–149,99 · 150–199,99 · ≥ 200).
  Página oficial: https://www.mercadolivre.com.br/ajuda/custos-envio-reputacao-verde-sem-reputacao_48392
- **Como foi provado (09/10/2026):**
  1. as cotações reais da #459 de 01/10 (`tests/fixtures-ml/sondagem/conta/shipping_options_free_*.json`,
     15×15×20 com 500 g) deram **8,45 · 14,45 · 21,35** a R$ 50 · 79 · 150 — a linha "0,5 a 1 kg" da
     tabela NOVA (a de 02/03 dava 7,95 · 13,85 · 20,75);
  2. a página baixada com `curl -A "<user-agent de navegador>"` e conferida célula a célula contra o
     config: 240 valores, 0 divergências. O artigo NÃO está no HTML visível: vem no estado embutido,
     como `\"content\":\"…\"` dentro de uma string JS (duas camadas de escape — a aspa que fecha a
     string interna é a precedida por um número de barras múltiplo de 4).
- **Checagem periódica (sugestão, não feita):** um comando que baixa a página e compara com o config,
  avisando no Setor Dev — ou um item mensal no checklist do dev. Sintoma de tabela velha: cotação real
  de conta verde diferente da tabela na MESMA faixa. Desconfie da tabela antes de desconfiar da API.

## 2. Peso faturado = max(real, cubado), SEM o mínimo de 5 kg

A planilha da ECF só cobrava o cubado (C×L×A ÷ 6000) acima de 5 kg (`peso_cubado_minimo`, removido).
O ML cobra sempre: a #459 voltou `billable_weight: 750` para 15×15×20 com 500 g reais. Produto leve e
volumoso faturava errado (20×20×20 com 500 g: 0,5 kg na planilha, 1,33 kg no ML).

## 3. Os limites do ME2 dependem da MODALIDADE de envio da conta

- "Dimensões permitidas" (https://www.mercadolivre.com.br/ajuda/Dimensoes-permitidas_3163, lida em
  09/10): **Correios** (`drop_off`) 30 kg / soma 200 / lado 100; **Agências ML** (`xd_drop_off`) e
  **Coleta** (`cross_docking`) 50 / 300 / 200; **Full** (`fulfillment`) 25 kg na embalagem primária /
  soma 260 / lado 120. Todos pelo peso REAL. Os limites moram em `estrutura_produtos.modalidades`.
- A modalidade vem de `GET /users/{id}/shipping_preferences` → `logistics[mode=me2].types[]`
  (`type`, `default`, `status`): o tipo padrão ativo de DESPACHO; senão o primeiro ativo de despacho
  (Coleta > Agência > Correios); o Full só quando é o único ativo. Flex (`self_service`) não é
  modalidade. A #459 é `drop_off` padrão + Full ativo → Correios.
- Lida SÓ na cotação real (ação explícita, com o token) e guardada 7 dias
  (`frete.modalidade_cache_horas`). Produtos, Planejamento e Precificação leem do cache — **sem ela,
  valem os Correios** (os mais estreitos), então um produto de 40 kg aparece ME1 até a primeira
  cotação de uma conta na Coleta. Falha passageira (rede, 429, 5xx) não fica guardada e interrompe a
  cotação (as cotações cairiam do mesmo jeito, cada uma esperando o timeout); outro 4xx vale "sem
  modalidade" por 1 h.
- O Full do ML é mais largo que o da planilha (20 kg, lado 80): 93×55×6 com 9,5 kg e 89×57×52 com
  25 kg passaram a "ME2 · Full". Não é bug.

## 4. Cotação por tipo, no PRÓPRIO preço — e a faixa decide o cache

- `GET /users/{seller}/shipping_options/free` com `listing_type_id` = `gold_special` no preço do
  Clássico e `gold_pro` no do Premium; `free_shipping` = preço ≥ `frete.gratis_obrigatorio_a_partir`
  (79); `logistic_type` = a modalidade (antes: `gold_special` e `drop_off` fixos, sem `free_shipping`).
- O frete muda a faixa do preço e o preço muda o frete: **ponto fixo**. A tabela é o palpite que diz
  qual faixa cotar; a cotação confirma. Quando a cotação leva o preço para outra faixa, a rodada
  seguinte cota a faixa nova (até `max_recotacoes`); oscilando, cota a faixa que ainda está na tabela.
- Cache por PACOTE (`estrutura:frete:v2:{empresa}:{dims}`), com uma entrada por modalidade · tipo ·
  **faixa de preço** (coluna). A chave antiga usava `round($preco)` e juntava 78,99 com 79 — faixas
  diferentes (8,45 com o ML bancando × 14,45 com frete grátis obrigatório).
- Lote: `max_por_requisicao` conta PEDIDOS ao ML; cada variação faz dois (Clássico e Premium), então
  24 = 12 variações por chamada. O que não coube volta em `pendentes`, e a tela pede de novo.
- Abaixo de R$ 19: "os produtos de menos de R$ 19 pagam no máximo metade do preço"
  (`TabelaFreteEcf::comTeto`, aplicado também ao valor da API — é idempotente).
- Quem diz "frete grátis obrigatório" numa cotação real continua sendo a RESPOSTA
  (`free_shipping_by_meli`, `publicador-ml.md` §10). O 79 do config vale para a estimativa pela tabela,
  para o `free_shipping` enviado, para o aviso do wizard antigo (`AnunciarML`, prop
  `frete_gratis_a_partir`) e para a IA do rascunho (`RascunhoAnuncioIaService`).

## 5. Frete sugerido na Precificação (D-19 revogada)

- Ordem: digitado → sugerido do próprio tipo → digitado do outro tipo → nada. **O "outro tipo" ficou
  de propósito**, só para quando não há sugestão (ME1, logística declarada fora do ME2, oferta sem
  medidas): tirá-lo devolve o PUFF-AZ (Premium mais barato que o Clássico) às ofertas antigas sem
  produto. Se alguém pedir "sugerido → nulo" ao pé da letra, mostre este caso antes.
- Composta usa o pacote SOMADO dos componentes (`ConjuntoLogistico`) — provisório.
- Carregar a página não chama o ML; o "Cotar agora" é a própria rota com `?cotar=1` (sem rota nova).
- O Publicador herda (`DadosEfetivosService` lê `EstruturaPrecificacaoService::pagina`): o preço
  publicado passou a ter o frete sugerido, e o V-SAL-08 ("preço calculado sem frete") deixa de pegar a
  oferta que tem sugestão.

## 6. O custo do envio é cobrado SEMPRE no ME2

"O custo dos Envios no Mercado Livre é um custo operacional [...] que se aplica a todos os casos, mesmo
quando o envio é pago pelo comprador." O `AnaliseAlavancasService` dava frete ZERO a anúncio sem frete
grátis — o "recebe" saía inflado. Agora cota sempre (com `free_shipping` do próprio anúncio); fora do
ME2 é zero; leitura falhou, a tabela pelas dimensões do anúncio, avisada.

## 7. Armadilhas pagas nesta mudança

- `MlbAnuncioController::cotarFrete` lia `shipping_options.0.list_cost` — campo de OUTRO endpoint; a
  resposta de `/users/{id}/shipping_options/free` traz `coverage.all_country.list_cost`. A estimativa
  do wizard saía sempre vazia, e o teste Phase78 passava porque o fake inventava o formato. **Fake de
  API: use a resposta capturada (`tests/fixtures-ml/sondagem`), nunca a imaginada.**
- O layout do Portal faz GET a `files.ecfconsultoria.com.br/api/v1/signals` em toda página:
  `Http::assertNothingSent()` em teste de página do Portal falha por isso. Restrinja a asserção à URL
  do ML.
- Fake de cotação com valor CONSTANTE (20 em toda faixa) faz o ponto fixo oscilar entre faixas e gastar
  mais cotações do que a vida real; teste de lote/cache deve responder a própria tabela.
- O `ClienteMlPublicador` refaz 5xx com `sleep` (1, 2, 4 s): para simular "leitura falhou" sem deixar o
  teste lento, responda 4xx.
