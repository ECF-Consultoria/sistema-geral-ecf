<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Quem atende tickets e pode ser responsável por demanda
    |--------------------------------------------------------------------------
    |
    | IDs de usuário (separados por vírgula). O cargo Dev (`users.is_dev`) dá
    | acesso ao sistema inteiro, mas nem todo Dev atende — em 29/09/2026 o
    | usuário pediu só Maycon Gomes (#24) e Matheus Barreto (#2) nas listas
    | "Quem atende" / "Para quem enviar" e no responsável da demanda; a
    | Thalissa (#33) segue Dev, fora da lista. Quem está aqui também precisa
    | ser Dev ativo. Vazio = todo Dev ativo atende (é o que os testes usam).
    |
    */
    'atendimento_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('DEMANDAS_DEV_ATENDIMENTO_IDS', '2,24')),
    ))),

];
