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

    // D9: a publicação roda em fatias que se redespacham (o Job não pode passar do
    // retry_after de 90 s da fila, senão é reentregue). A trava cobre a fatia inteira
    // mais um POST lento; UNKNOWN só é reenviado depois de reconciliar E de passado
    // este tempo desde o envio (a busca por SKU do ML demora a enxergar o item novo).
    'fatia_segundos' => 45,
    'trava_segundos' => 600,
    'reconciliar_apos_segundos' => 180,

    // Piloto (usuário, 01/10): só estas empresas publicam pelo Publicador novo; as
    // demais seguem no Anunciar antigo. Vazio = todas. A #459 é a conta de teste.
    'empresas_piloto' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_EMPRESAS_PILOTO', '459'))))),

    // D21: contas liberadas para publicar, uma a uma pelo usuário depois do teste real;
    // vazio = ninguém. Listas separadas por âncora (Company 5 não libera MlbEmpresa 5).
    'contas_liberadas' => [
        'companies' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_CONTAS_LIBERADAS_COMPANIES', env('PUBLICADOR_EMPRESAS_PILOTO', '459')))))),
        'mlb_empresas' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_CONTAS_LIBERADAS_MLB_EMPRESAS', ''))))),
    ],

    // D11 [HIP]: conta multidepósito cria pelo caminho próprio, com o estoque de cada
    // depósito. Formato lido na documentação por busca (acesso direto dá 403) e nunca
    // testado de verdade (a conta de teste não tem depósitos). Se o ML recusar com 4xx,
    // o Publicador cria pelo /items comum e avisa (plano B).
    'multideposito' => [
        'caminho' => '/items/multiwarehouse',
        'campo' => 'stock_locations',
    ],

];
