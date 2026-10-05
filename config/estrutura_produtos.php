<?php

/*
|--------------------------------------------------------------------------
| Produtos do Mapeamento Estrutural — regras do Mercado Livre
|--------------------------------------------------------------------------
|
| Regras do Mercado Livre, iguais para todos os clientes (configuração global,
| Claude's Discretion do 167-CONTEXT). Toda hipótese vira opção aqui, nunca
| literal em código: quando o ML mudar, muda-se o valor, não a lógica.
| Fonte: aba "Parâmetros" da planilha de Planejamento Estrutural e Central de
| Vendedores do ML.
|
*/

return [

    // Peso cubado = C × L × A (cm) ÷ fator.
    'fator_cubagem' => 6000,

    // O peso cubado só passa a valer acima deste mínimo (kg).
    'peso_cubado_minimo' => 5,

    // Limites do ME2 — usam o peso REAL (soma dos pesos), não o faturado.
    'me2' => [
        'peso'  => 30,   // kg
        'soma'  => 200,  // C + L + A, em cm
        'maior' => 100,  // maior lado, em cm
    ],

    // Elegibilidade ao Full, dentro do ME2.
    'full' => [
        'peso'  => 20,  // kg
        'maior' => 80,  // maior lado, em cm
    ],

    'frete' => [
        // A planilha supõe a faixa "a partir de R$ 200"; usado quando falta custo.
        'preco_referencia' => 200,

        // Vigência da tabela reserva abaixo (mostrada na tela como "estimativa").
        'vigente_desde' => '2026-03-02',
        'reputacao'     => 'verde',

        // Cotação pela API: cache, lote e re-cotações por requisição.
        'cache_horas'        => 6,
        'max_por_requisicao' => 12,
        'max_recotacoes'     => 2,

        // Limites inferiores de cada LINHA da tabela (kg).
        'faixas_peso' => [0, 0.3, 0.5, 1, 1.5, 2, 3, 4, 5, 6, 7, 8, 9, 11, 13, 15, 17, 20, 25, 30, 40, 50, 60, 70, 80, 90, 100, 125, 150],

        // limites de COLUNA da tabela de custos de envio do ML — NÃO são a regra do
        // frete grátis obrigatório, que nunca fica no código (learnings
        // publicador-ml.md §10); quem decide é a resposta da API.
        'faixas_preco' => [0, 19, 49, 79, 100, 120, 150, 200],

        // 29 linhas (faixas_peso) × 8 colunas (faixas_preco).
        'tabela' => [],
    ],
];
