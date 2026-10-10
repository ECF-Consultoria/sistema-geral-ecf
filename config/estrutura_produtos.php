<?php

/*
|--------------------------------------------------------------------------
| Produtos do Mapeamento Estrutural — regras do Mercado Livre
|--------------------------------------------------------------------------
|
| Regras do Mercado Livre, iguais para todos os clientes (configuração global,
| Claude's Discretion do 167-CONTEXT). Toda hipótese vira opção aqui, nunca
| literal em código: quando o ML mudar, muda-se o valor, não a lógica.
| Fonte: Central de Vendedores do ML (páginas citadas em cada bloco). A aba
| "Parâmetros" da planilha de Planejamento Estrutural foi a fonte até 09/10/2026;
| desde então a regra é "seguir o ML em tudo" (.planning/learnings/frete-mercado-envios.md).
|
*/

return [

    // Peso cubado = C × L × A (cm) ÷ fator.
    'fator_cubagem' => 6000,

    // Peso faturado = o MAIOR entre o real e o cubado, SEM mínimo (09/10/2026). A planilha da
    // ECF só cobrava o cubado acima de 5 kg; o ML cobra sempre: 15×15×20 com 500 g volta
    // `billable_weight: 750` na conta #459 (sondagem de 01/10, tests/fixtures-ml/sondagem/conta).

    // Limites do Mercado Envios por MODALIDADE de envio da conta (o `logistic_type` do ME2).
    // Fonte: "Dimensões permitidas" (https://www.mercadolivre.com.br/ajuda/Dimensoes-permitidas_3163),
    // lida em 09/10/2026. Usam o peso REAL (soma dos pesos), não o faturado.
    'modalidades' => [
        'drop_off'      => ['peso' => 30, 'soma' => 200, 'maior' => 100], // Envios tradicionais (Correios)
        'xd_drop_off'   => ['peso' => 50, 'soma' => 300, 'maior' => 200], // Agências Mercado Livre
        'cross_docking' => ['peso' => 50, 'soma' => 300, 'maior' => 200], // Coleta
        'fulfillment'   => ['peso' => 25, 'soma' => 260, 'maior' => 120], // Centro de distribuição (Full)
    ],

    // Sem conta conectada ou sem a preferência de envio lida: os limites dos Correios (os mais estreitos).
    'modalidade_padrao' => 'drop_off',

    // Elegibilidade ao Full, dentro do ME2: os limites do centro de distribuição.
    'modalidade_full' => 'fulfillment',

    'frete' => [
        // A planilha supõe a faixa "a partir de R$ 200"; usado quando falta custo.
        'preco_referencia' => 200,

        // Vigência da tabela reserva abaixo (mostrada na tela como "estimativa").
        'vigente_desde' => '2026-08-24',
        'reputacao'     => 'verde',

        // Cotação pela API: cache, lote e re-cotações por requisição. O lote conta pedidos ao ML:
        // cada variação cota Clássico e Premium (dois pedidos), então 24 = 12 variações por chamada.
        'cache_horas'        => 6,
        'max_por_requisicao' => 24,
        'max_recotacoes'     => 2,

        // A preferência de envio da conta (a modalidade) muda pouco: cache longo, em horas.
        'modalidade_cache_horas' => 168,

        // Frete grátis obrigatório a partir deste preço (R$): "Em anúncios a partir de R$ 79,
        // você oferece frete grátis e rápido"; de R$ 19 a R$ 78,99 o ML oferece o grátis padrão.
        // Vai no `free_shipping` da cotação, no aviso do wizard antigo e na IA do rascunho. Na
        // cotação real, quem diz se o frete grátis ficou obrigatório continua sendo a RESPOSTA da
        // API (`free_shipping_by_meli`, learnings publicador-ml.md §10); este valor só vale para a
        // estimativa pela tabela.
        'gratis_obrigatorio_a_partir' => 79,

        // "*Os produtos de menos de R$ 19 pagam no máximo metade do preço do produto."
        'teto_abaixo_de' => 19,
        'teto_fracao'    => 0.5,

        // Limites inferiores de cada LINHA da tabela (kg).
        'faixas_peso' => [0, 0.3, 0.5, 1, 1.5, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 15, 17, 20, 25, 30, 40, 50, 60, 70, 80, 90, 100, 125, 150],

        // Limites de COLUNA da tabela de custos de envio do ML (R$). A regra do frete grátis
        // obrigatório é a `gratis_obrigatorio_a_partir`, não estas colunas.
        'faixas_preco' => [0, 19, 49, 79, 100, 120, 150, 200],

        // 30 linhas (faixas_peso) × 8 colunas (faixas_preco). Custos para MercadoLíderes, com
        // reputação verde ou sem reputação, vigentes desde 24/08/2026
        // (https://www.mercadolivre.com.br/ajuda/custos-envio-reputacao-verde-sem-reputacao_48392),
        // conferidos célula a célula contra a página em 09/10/2026. A anterior (02/03/2026, 29
        // linhas, cópia da aba "Frete ML Verde" da planilha) não tinha a faixa de 9 a 10 kg.
        'tabela' => [
            [5.65, 6.85, 8.15, 12.95, 14.95, 16.95, 19.05, 21.65],       // até 0,3 kg
            [5.95, 6.95, 8.25, 13.85, 16.15, 18.15, 20.45, 23.25],       // 0,3 a 0,5 kg
            [6.05, 7.15, 8.45, 14.45, 16.85, 19.05, 21.35, 24.45],       // 0,5 a 1 kg
            [6.15, 7.35, 8.65, 14.75, 17.15, 19.45, 21.75, 25.45],       // 1 a 1,5 kg
            [6.25, 7.45, 8.75, 15.05, 17.65, 19.85, 22.25, 25.55],       // 1,5 a 2 kg
            [6.35, 8.65, 9.15, 16.45, 19.15, 21.65, 24.35, 27.05],       // 2 a 3 kg
            [6.45, 8.75, 9.75, 17.85, 20.75, 23.35, 26.35, 29.25],       // 3 a 4 kg
            [6.55, 8.85, 10.25, 19.75, 22.85, 26.05, 29.25, 32.45],      // 4 a 5 kg
            [6.65, 8.95, 10.35, 25.95, 29.15, 33.35, 36.45, 40.85],      // 5 a 6 kg
            [6.75, 9.05, 10.45, 27.55, 31.65, 36.75, 40.85, 45.25],      // 6 a 7 kg
            [6.85, 9.25, 10.55, 29.45, 34.35, 39.25, 44.15, 49.35],      // 7 a 8 kg
            [6.95, 9.35, 10.65, 30.25, 35.25, 40.35, 45.35, 50.75],      // 8 a 9 kg
            [7.05, 9.45, 10.85, 38.25, 45.05, 51.95, 58.75, 65.85],      // 9 a 10 kg
            [7.05, 9.65, 11.05, 41.65, 48.55, 55.45, 62.35, 69.35],      // 10 a 11 kg
            [7.15, 10.05, 11.45, 42.55, 49.75, 56.85, 63.85, 70.95],     // 11 a 13 kg
            [7.25, 10.25, 11.65, 45.55, 52.95, 60.55, 68.15, 75.65],     // 13 a 15 kg
            [7.35, 10.45, 11.85, 48.95, 56.55, 64.05, 71.35, 79.35],     // 15 a 17 kg
            [7.45, 10.65, 12.05, 55.15, 64.35, 73.55, 82.75, 91.95],     // 17 a 20 kg
            [7.65, 11.05, 12.25, 64.55, 75.75, 85.45, 96.25, 106.85],    // 20 a 25 kg
            [7.75, 11.25, 12.45, 66.45, 76.05, 86.25, 97.15, 107.85],    // 25 a 30 kg
            [7.85, 11.45, 12.65, 68.35, 79.65, 89.75, 100.05, 107.95],   // 30 a 40 kg
            [7.95, 11.65, 12.85, 70.95, 81.85, 92.85, 103.45, 111.65],   // 40 a 50 kg
            [8.05, 11.85, 13.05, 75.55, 87.25, 99.05, 110.25, 119.05],   // 50 a 60 kg
            [8.15, 12.05, 13.25, 80.95, 93.75, 105.95, 118.05, 127.45],  // 60 a 70 kg
            [8.25, 12.25, 13.45, 84.65, 97.95, 110.75, 123.35, 133.15],  // 70 a 80 kg
            [8.35, 12.45, 13.65, 94.05, 108.35, 122.95, 136.95, 147.85], // 80 a 90 kg
            [8.45, 12.65, 13.85, 107.45, 124.85, 140.45, 156.45, 168.85], // 90 a 100 kg
            [8.55, 12.85, 14.05, 120.15, 138.95, 156.95, 174.85, 188.85], // 100 a 125 kg
            [8.65, 12.85, 14.25, 127.45, 147.05, 166.55, 185.55, 200.35], // 125 a 150 kg
            [8.75, 12.85, 14.45, 167.05, 193.35, 218.45, 243.45, 262.85], // mais de 150 kg
        ],
    ],

    // Imagens por variação (galeria da cor). O arquivo vai CRU para o disco privado; o
    // servidor só confere formato e tamanho. Mudar o teto de tamanho exige conferir também
    // `upload_max_filesize` e `post_max_size` do PHP-FPM: acima deles o pedido chega vazio.
    'imagens' => [
        'max_por_variacao' => 12,
        'max_kb'           => 10240, // 10 MB por imagem
        'extensoes'        => ['jpg', 'jpeg', 'png', 'webp'],
    ],
];
