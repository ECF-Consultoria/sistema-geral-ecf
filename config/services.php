<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'adman' => [
        'base_url' => env('ADMAN_BASE_URL', 'https://api.adman.com.br/v1'),
        'api_key'  => env('ADMAN_API_KEY', ''),
    ],

    'adman_mcp' => [
        'url'     => env('ADMAN_MCP_URL', 'https://mcp.ad-man.io/v1/mcp'),
        'api_key' => env('ADMAN_MCP_API_KEY', env('ADMAN_API_KEY', '')),
    ],

    'mercadolivre' => [
        'client_id'     => env('ML_CLIENT_ID', '5662190514685421'),
        'client_secret' => env('ML_CLIENT_SECRET'),
        'redirect'      => env('ML_REDIRECT_URI', 'https://desafio.ecfconsultoria.com.br/oauth/mercadolivre/callback'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    'ml_sftp' => [
        'grants_file' => env('ML_SFTP_GRANTS_FILE', 'grants.csv'),
    ],

    // Phase 20 — integração com API HTTP do ECF Drive (substitui sync SFTP do ML)
    'ecf' => [
        'base'           => env('ECF_API_BASE', 'https://files.ecfconsultoria.com.br/api/v1'),
        'key'            => env('ECF_API_KEY', ''),
        'webhook_secret' => env('ECF_WEBHOOK_SECRET', ''),
    ],

    // Phase 34 Plan 34-04 — Webhook HubSpot (HMAC v3) + API HubSpot CRM v3.
    // client_secret: validar X-HubSpot-Signature-v3 (App Settings → Auth no HubSpot).
    // access_token: Private App token (Bearer) para GET /crm/v3/objects/*.
    // stage_fechado_ganho_id: id(s) do dealstage "Fechado Ganho". Aceita CSV
    //   pra suportar multiplos pipelines (Polos / Infoprodutos / Sales default).
    //   Ex: "1352209026,1352209033,closedwon".
    // props.*: mapeamento configurável de propriedades HubSpot → colunas do ECF (D-05).
    'hubspot' => [
        'client_secret'          => env('HUBSPOT_CLIENT_SECRET'),
        'access_token'           => env('HUBSPOT_ACCESS_TOKEN'),
        'stage_fechado_ganho_id' => env('HUBSPOT_STAGE_FECHADO_GANHO_ID', 'closedwon'),
        'props' => [
            'deal' => [
                'nicho'              => env('HUBSPOT_PROP_DEAL_NICHO', 'nicho'),
                'dor'                => env('HUBSPOT_PROP_DEAL_DOR', 'dor'),
                'vende_ml'           => env('HUBSPOT_PROP_DEAL_VENDE_ML', 'vende_ml'),
                'faturamento_mensal' => env('HUBSPOT_PROP_DEAL_FATURAMENTO', 'faturamento_mensal'),
                'servico'            => env('HUBSPOT_PROP_DEAL_SERVICO', 'servico_ecf'),
            ],
            'company' => [
                'name'  => env('HUBSPOT_PROP_COMPANY_NAME', 'name'),
                'cnpj'  => env('HUBSPOT_PROP_COMPANY_CNPJ', 'cnpj'),
                'email' => env('HUBSPOT_PROP_COMPANY_EMAIL', 'email'),
                'phone' => env('HUBSPOT_PROP_COMPANY_PHONE', 'phone'),
            ],
            // Phase 35 Plan 35-02 — contato vinculado ao deal (D-04).
            // Usado pra preencher email_cliente/telefone da Company quando
            // a HubSpot Company nao tem esses campos (fallback). firstname +
            // lastname concatenados viram linha "Contato (HubSpot): ..." em
            // notes da Company.
            'contact' => [
                'firstname' => env('HUBSPOT_PROP_CONTACT_FIRSTNAME', 'firstname'),
                'lastname'  => env('HUBSPOT_PROP_CONTACT_LASTNAME', 'lastname'),
                'email'     => env('HUBSPOT_PROP_CONTACT_EMAIL', 'email'),
                'phone'     => env('HUBSPOT_PROP_CONTACT_PHONE', 'phone'),
            ],
        ],
    ],

];
