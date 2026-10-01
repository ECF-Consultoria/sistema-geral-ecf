<?php

/*
|--------------------------------------------------------------------------
| Publicador do Anunciar (portal)
|--------------------------------------------------------------------------
|
| Especificação: `.planning/publicador-ml-spec/`. Toda hipótese [HIP] da spec
| vira opção aqui em vez de regra fixa no código (`12`): quando a API real
| contradisser, muda-se o valor, não o código.
|
*/

return [

    // RN-22: o schema da categoria vale 24h; antes de publicar, vencido é rebuscado.
    'schema_ttl_horas' => (int) env('PUBLICADOR_SCHEMA_TTL_HORAS', 24),

    // `09` §6: renovar o token quando faltar menos que isto (o refresh do ML é de uso único).
    'renovar_token_minutos' => 10,

    // `09` §2: novas tentativas em 429 (espera 1, 2, 4, 8, 16 s ou o Retry-After) e em 5xx de leitura.
    'tentativas_429' => 5,
    'tentativas_5xx' => 3,
    'espera_maxima_segundos' => 16,
    'timeout_segundos' => 30,

    // H-17: o ML não documenta limite de eixos.
    'max_eixos' => 3,

    // N-14: o validate limitou o family_name ao max_title_length da categoria; o teto de 120
    // do [ML·S8] não apareceu. Nulo = só o da categoria.
    'limite_family_name' => null,

    // `08` V-TIT-02 (aviso). Telefone, e-mail e link são detectados à parte.
    'termos_proibidos_titulo' => ['frete gratis', 'parcelado', 'sem juros', 'novo', 'usado', 'promocao', 'oferta'],

    // `08` V-ATT-11 (aviso), por domínio: medida "maior" não pode ficar abaixo da "menor".
    'plausibilidade' => [
        'MLB-OFFICE_CHAIRS' => [['maior' => 'MAX_CHAIR_HEIGHT', 'menor' => 'BACKREST_HEIGHT']],
    ],

    // `08` §1: no UP cada variante é um validate; avisar a demora acima disto.
    'avisar_acima_de_itens' => 20,
    'concorrencia_ml' => 2,

];
