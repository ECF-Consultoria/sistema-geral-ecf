<?php

/*
|--------------------------------------------------------------------------
| Parâmetros da Fase 134 — "Meus Anúncios" (acervo publicado no ML)
|--------------------------------------------------------------------------
|
| Único lugar da fase onde vivem N da rotação da camada cara (D-23), o
| tamanho do lote de detalhe, os limites reais confirmados contra a API do
| Mercado Livre, a retenção da série diária (D-07) e o limiar de defasagem
| (D-08). Nenhum consumidor deve hardcodar esses números — sempre ler daqui.
|
| Fonte dos números: `.planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/134-RESEARCH.md`
| §3/§4 (custo/volume) e `134-CONTEXT.md` D-07/D-08/D-21/D-23 (decisões).
|
*/

return [

    // D-23 — cobertura completa da camada cara (visitas + price_to_win) em N
    // dias. Conta do 134-RESEARCH.md §A-03/§3: 406.932 itens ativos medidos
    // em produção + ~150-180 mil chamadas de price_to_win (itens
    // catalog_listing=true) somam ~587 mil chamadas/dia se fosse cobertura
    // total diária (~6,5h a 25 req/s). Com N=7 cai para ~84 mil/dia
    // (~56 min), na mesma ordem de grandeza da janela do ml:sync.
    'rotacao_n' => (int) env('MLB_ACERVO_ROTACAO_N', 7),

    // Tamanho do lote de itens por execução do job de detalhe da camada cara.
    // Mantém cada job em dezenas de segundos, muito abaixo do retry_after da
    // fila — ver Pitfall 1 do 134-RESEARCH.md (job monolítico por empresa
    // grande estourando o retry_after e duplicando execução).
    'chunk_detalhe' => (int) env('MLB_ACERVO_CHUNK_DETALHE', 500),

    // Multiget de itens (`GET /items?ids=`): 20 ids é o teto REAL da API,
    // confirmado por erro real da própria API ("only allows 20 elements") —
    // não é suposição de documentação.
    'lote_multiget' => 20,

    // Página do scroll de enumeração (`search_type=scan`). 50 mantém o
    // round-trip curto — o scroll_id tem TTL de 5 minutos e a enumeração
    // precisa rodar sem delay artificial entre páginas (134-RESEARCH.md §2).
    'pagina_scroll' => 50,

    // D-07 — retenção da série diária enxuta (visitas, vendas, saúde).
    'retencao_dias' => (int) env('MLB_ACERVO_RETENCAO_DIAS', 90),

    // D-08 — a partir de quantas horas sem coleta a tela mostra o banner de
    // defasagem ("coletado há N dias").
    'defasagem_horas' => (int) env('MLB_ACERVO_DEFASAGEM_HORAS', 24),

    // D-21 — veredicto da sondagem deste plano (134-01, Task 2).
    //
    // SONDAGEM CONCLUÍDA em 2026-08-10 contra produção (leitura, D-11
    // respeitado). VEREDICTO: **DISPONÍVEL** → Variante A do 134-UI-SPEC.md
    // (as duas medidas de saúde lado a lado, ML + Nota ECF).
    //
    // `GET /item/{id}/performance` RESPONDE. Medido no item MLB5318502460
    // (empresa 298): score=98, level="good", mais `buckets[]` agrupados
    // ("Dados do produto", "Condições de venda") e, dentro deles,
    // `variables[]` com `key` (UP_PICTURES, UP_STOCK_AVAILABILITY_TIME,
    // UP_GTIN, UP_TITLE, UP_FREE_SHIPPING…), `status` (PENDING/COMPLETED) e
    // `title` já redigido em pt-BR pelo próprio ML. É exatamente o "health
    // /quality + ações sugeridas de qualidade" que o 134-CONTEXT.md listou
    // como candidato. Fixture real em tests/fixtures/phase134/.
    //
    // O 134-RESEARCH.md havia registrado este endpoint como falho — a
    // pesquisa testou um item que devolveu "Product items are not
    // supported". A sondagem provou que ele responde; o erro anterior era
    // do item de amostra, não do endpoint. Ver 134-01-SUMMARY.md.
    //
    // Custo: 1 chamada por item, sem lote → camada CARA (rotação do D-23),
    // ao lado de visits e price_to_win.
    'saude_ml_disponivel' => (bool) env('MLB_ACERVO_SAUDE_ML', true),

    // ACHADO ADICIONAL da mesma sondagem — o campo `health` do multiget
    // (camada BARATA, custo zero) NÃO é sempre null como a pesquisa
    // registrou. Medido em 20 itens da empresa 298: 11 trazem valor real
    // (0.7 / 0.8, escala 0-1). O padrão é determinístico:
    //   • `catalog_listing = true`  → health null (4/4) — anúncio de
    //     catálogo não tem ficha própria, a ficha é do catálogo
    //   • `status` closed/inactive  → health null (5/5)
    //   • ativo/pausado fora de catálogo → health preenchido (11/11)
    // Ou seja: existe um sinal de saúde do ML DE GRAÇA para a maior parte
    // do acervo ativo. `performance` (caro) fica para o detalhe acionável.
    'health_multiget_confiavel' => true,

];
