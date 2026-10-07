<?php

/*
|--------------------------------------------------------------------------
| Geração de ofertas (Combo, Kit e Combit) — vocabulário e limites da ECF
|--------------------------------------------------------------------------
|
| Toda hipótese vira opção aqui, nunca literal em código. `tipos` e `pares`
| são a SEMENTE lida pela migration do 168-06; depois dela a ECF mantém os dois
| pela tela admin, sem deploy.
|
| - D-06: pares de TIPO da ECF (global, vale para todas as empresas).
| - D-22 (substitui o D-13): quantidades COMO A PLANILHA USA, aprovadas pelo usuário
|   (cadeira, banqueta, banco, prateleira, cabeceira, criado-mudo, mesa-lateral, cama;
|   mesa e os demais: nenhuma). A ECF amplia pelo admin.
| - D-20/D-21/D-23: só a lista aprovada pelo usuário entra (18 pares; bicama em cama; tipo beliche).
|
| Vocabulário GENÉRICO de móveis: nunca nome de produto, marca ou família de
| cliente.
|
*/

return [

    // Tela de sugestões.
    'por_pagina'     => 20,
    'lote_aceite'    => 100,
    'teto_sugestoes' => 5000,

    // Limites de campo: título do ML (RascunhoAnuncioIaService) e SKU (EstruturaOfertaService::campos()).
    'max_titulo'      => 60,
    'max_sku'         => 120,
    'max_quantidades' => 8,

    // slug => nome, plural, palavras (normalizadas: sem acento, minúsculas), quantidades e ordem.
    // `qtd_*` em texto "2, 4, 6"; '0' ou null = nenhuma no nível do tipo.
    'tipos' => [
        'mesa' => [
            'nome' => 'Mesa', 'plural' => 'Mesas', 'palavras' => ['mesa'],
            'qtd_combo' => '0', 'qtd_combit' => '0', 'ordem' => 10,
        ],
        'mesa-centro' => [
            'nome' => 'Mesa de centro', 'plural' => 'Mesas de centro', 'palavras' => ['mesa de centro', 'mesa centro'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 12,
        ],
        'mesa-lateral' => [
            'nome' => 'Mesa lateral', 'plural' => 'Mesas laterais', 'palavras' => ['mesa lateral', 'mesa de apoio'],
            'qtd_combo' => '2', 'qtd_combit' => '2', 'ordem' => 14,
        ],
        'cama' => [
            'nome' => 'Cama', 'plural' => 'Camas', 'palavras' => ['cama', 'bicama'],
            'qtd_combo' => '2', 'qtd_combit' => null, 'ordem' => 20,
        ],
        'beliche' => [
            'nome' => 'Beliche', 'plural' => 'Beliches', 'palavras' => ['beliche', 'treliche'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 21,
        ],
        'cabeceira' => [
            'nome' => 'Cabeceira', 'plural' => 'Cabeceiras', 'palavras' => ['cabeceira'],
            'qtd_combo' => '2', 'qtd_combit' => '2', 'ordem' => 22,
        ],
        'sofa' => [
            'nome' => 'Sofá', 'plural' => 'Sofás', 'palavras' => ['sofa'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 24,
        ],
        'buffet' => [
            'nome' => 'Buffet', 'plural' => 'Buffets', 'palavras' => ['buffet', 'bufe'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 30,
        ],
        'aparador' => [
            'nome' => 'Aparador', 'plural' => 'Aparadores', 'palavras' => ['aparador'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 32,
        ],
        'rack' => [
            'nome' => 'Rack', 'plural' => 'Racks', 'palavras' => ['rack'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 34,
        ],
        'painel' => [
            'nome' => 'Painel', 'plural' => 'Painéis', 'palavras' => ['painel'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 36,
        ],
        'estante' => [
            'nome' => 'Estante', 'plural' => 'Estantes', 'palavras' => ['estante'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 38,
        ],
        'cristaleira' => [
            'nome' => 'Cristaleira', 'plural' => 'Cristaleiras', 'palavras' => ['cristaleira'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 40,
        ],
        'comoda' => [
            'nome' => 'Cômoda', 'plural' => 'Cômodas', 'palavras' => ['comoda'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 42,
        ],
        'guarda-roupa' => [
            'nome' => 'Guarda-roupa', 'plural' => 'Guarda-roupas', 'palavras' => ['guarda roupa', 'roupeiro'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 44,
        ],
        'escrivaninha' => [
            'nome' => 'Escrivaninha', 'plural' => 'Escrivaninhas', 'palavras' => ['escrivaninha'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 46,
        ],
        'penteadeira' => [
            'nome' => 'Penteadeira', 'plural' => 'Penteadeiras', 'palavras' => ['penteadeira'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 48,
        ],
        'sapateira' => [
            'nome' => 'Sapateira', 'plural' => 'Sapateiras', 'palavras' => ['sapateira'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 50,
        ],
        'armario' => [
            'nome' => 'Armário', 'plural' => 'Armários', 'palavras' => ['armario'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 52,
        ],
        'nicho' => [
            'nome' => 'Nicho', 'plural' => 'Nichos', 'palavras' => ['nicho'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 54,
        ],
        'prateleira' => [
            'nome' => 'Prateleira', 'plural' => 'Prateleiras', 'palavras' => ['prateleira'],
            'qtd_combo' => '2, 3', 'qtd_combit' => '2', 'ordem' => 56,
        ],
        'criado-mudo' => [
            'nome' => 'Criado-mudo', 'plural' => 'Criados-mudos',
            'palavras' => ['criado mudo', 'mesa de cabeceira', 'mesinha de cabeceira'],
            'qtd_combo' => '2', 'qtd_combit' => '2', 'ordem' => 60,
        ],
        'cadeira' => [
            'nome' => 'Cadeira', 'plural' => 'Cadeiras', 'palavras' => ['cadeira'],
            'qtd_combo' => '2, 4, 6, 8', 'qtd_combit' => '2, 4, 6', 'ordem' => 70,
        ],
        'poltrona' => [
            'nome' => 'Poltrona', 'plural' => 'Poltronas', 'palavras' => ['poltrona'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 72,
        ],
        'banqueta' => [
            'nome' => 'Banqueta', 'plural' => 'Banquetas', 'palavras' => ['banqueta'],
            'qtd_combo' => '2, 3, 4', 'qtd_combit' => '2', 'ordem' => 74,
        ],
        'banco' => [
            'nome' => 'Banco', 'plural' => 'Bancos', 'palavras' => ['banco'],
            'qtd_combo' => '2', 'qtd_combit' => '2', 'ordem' => 76,
        ],
        'puff' => [
            'nome' => 'Puff', 'plural' => 'Puffs', 'palavras' => ['puff', 'puf'],
            'qtd_combo' => null, 'qtd_combit' => null, 'ordem' => 78,
        ],
    ],

    // Pares de tipo: `repete` = null (só Kit), o slug do lado que se repete no Combit, ou 'ambos'.
    // Lista-semente aprovada pelo usuário em 2026-10-07 (168-02, D-21); a ECF amplia pela tela admin.
    'pares' => [
        ['tipos' => ['aparador', 'mesa'], 'repete' => null],
        ['tipos' => ['aparador', 'mesa-centro'], 'repete' => null],
        ['tipos' => ['aparador', 'mesa-lateral'], 'repete' => 'mesa-lateral'],
        ['tipos' => ['aparador', 'rack'], 'repete' => null],
        ['tipos' => ['armario', 'prateleira'], 'repete' => null],
        ['tipos' => ['banco', 'mesa'], 'repete' => 'banco'],
        ['tipos' => ['banqueta', 'mesa'], 'repete' => 'banqueta'],
        ['tipos' => ['buffet', 'cristaleira'], 'repete' => null],
        ['tipos' => ['buffet', 'mesa'], 'repete' => null],
        ['tipos' => ['cabeceira', 'cama'], 'repete' => 'cabeceira'],
        ['tipos' => ['cabeceira', 'criado-mudo'], 'repete' => 'criado-mudo'],
        ['tipos' => ['cadeira', 'mesa'], 'repete' => 'cadeira'],
        ['tipos' => ['cama', 'cama'], 'repete' => null],
        ['tipos' => ['cama', 'criado-mudo'], 'repete' => 'criado-mudo'],
        ['tipos' => ['comoda', 'criado-mudo'], 'repete' => null],
        ['tipos' => ['comoda', 'guarda-roupa'], 'repete' => null],
        ['tipos' => ['comoda', 'prateleira'], 'repete' => 'prateleira'],
        ['tipos' => ['mesa-centro', 'mesa-lateral'], 'repete' => 'mesa-lateral'],
    ],

];
