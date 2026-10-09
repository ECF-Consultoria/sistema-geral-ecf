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

    // ═══ Alavancas (Fase 166) ═══
    'alavancas' => [

        // D-03 (166): separada da trava de publicação de propósito — sem fallback para
        // as variáveis daquela trava (nem a antiga de piloto); vazio = ninguém;
        // Company 5 ≠ MlbEmpresa 5. Liberar uma não libera a outra.
        'contas_liberadas' => [
            'companies' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES', '459'))))),
            'mlb_empresas' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_ALAVANCAS_LIBERADAS_MLB_EMPRESAS', ''))))),
        ],

        // D-12 (166): alertas simples; null/false desliga o alerta; nenhum alerta bloqueia ação.
        // `4_light_green` é suposição A4 do RESEARCH (reputação que ainda permite desconto/cupom).
        'alertas' => [
            'convite_vence_em_dias' => 3,
            'recebido_queda_percentual' => 10,
            'estoque_minimo' => true,
            'reputacao_ok' => ['5_green', '4_light_green'],
        ],

        // Segundos de cache das leituras; escrita bem-sucedida invalida a conta.
        'cache' => [
            'conta' => 300,
            'panorama' => 120,
            'itens_promocao' => 60,
            'produtos' => 300,
            'tarifa' => 3600,
            'frete' => 3600,
            'anunciante' => 86400,
        ],

        // itens_por_lote = Questão 4 do RESEARCH, decidido pelo orquestrador: 50 por confirmação.
        // paginas_preload: páginas de itens de uma promoção lidas de uma vez na prévia/confirmação
        // de vários produtos. itens_analise_previa: a prévia calcula "quanto recebe" só dos 20 primeiros.
        'limites' => [
            'itens_por_lote' => 50,
            'itens_por_analise' => 10,
            'chamadas_analise_por_minuto' => 120,
            'offset_maximo_produtos' => 1000,
            'paginas_convites' => 4,
            'cupons_detalhados' => 20,
            'tentativas_423' => 3,
            'espera_423_segundos' => 2,
            'janela_publicidade_dias' => 90,
            'paginas_preload' => 20,
            'itens_analise_previa' => 20,
        ],

        // D-04 (166): a assinatura da prévia de uma escrita vale este tempo.
        'previa_validade_minutos' => 10,
    ],

    // ═══ IA prepara o rascunho ao salvar no Portal (09/10/2026) ═══
    // Cada save do produto no Portal agenda, com espera, a sincronização SÓ daquele produto e, com a
    // ficha completa, a geração de título, Modelo e descrição pela IA — gravados no rascunho sem tela
    // aberta, nunca por cima do que a equipe editou (learnings publicador-ml §16).
    'preparo_ia' => [
        // Chave de segurança: false desliga TUDO (nem sincroniza nem gera).
        'ativo' => (bool) env('PUBLICADOR_PREPARO_IA_ATIVO', true),
        // Espera depois do último save do produto; um save novo dentro dela adia (debounce).
        'atraso_min' => (int) env('PUBLICADOR_PREPARO_IA_ATRASO_MIN', 10),
        // Preparações com IA por empresa por dia (cada uma = título + Modelo + descrição de UM produto).
        // Passou disso, o produto só é sincronizado e o log diz por quê.
        'limite_diario_por_empresa' => (int) env('PUBLICADOR_PREPARO_IA_LIMITE_DIARIO', 60),
        // O editor do produto conta como "em uso" por este tempo depois do último sinal da tela.
        'editor_em_uso_min' => 3,
        // Editor em uso (ou "Anunciar por IA" rodando): a escrita espera este tempo e tenta de novo,
        // no máximo `max_adiamentos` vezes; depois desiste e o próximo save no Portal recomeça.
        'adiar_min' => 5,
        'max_adiamentos' => 24,
    ],

    // Só a conferência visual local (plano 166-16) aponta para um servidor de mentira;
    // em produção o cliente IGNORA este valor e usa o host oficial (plano 166-02).
    'ml_api_base' => env('PUBLICADOR_ML_API_BASE', 'https://api.mercadolibre.com'),

];
