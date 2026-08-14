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

    // Shopee Open Platform (API v2). Diferente do ML: NÃO usa PKCE nem scope na
    // URL — toda request é assinada com HMAC-SHA256 (partner_key é a chave HMAC,
    // nunca vai na request). partner_id/partner_key/host são 1 por app (não por
    // empresa) e vêm do console de parceiro (open.shopee.com).
    //
    // Hosts (SHOPEE_HOST):
    //   - Produção global (default): 'https://partner.shopeemobile.com'
    //     ⚠️ host BR pode ser o global OU regional 'openplatform.shopee.com.br';
    //        confirmar no console antes do go-live.
    //   - Sandbox (VALIDADO): 'https://openplatform.sandbox.test-stable.shopee.sg'
    //   - a redirect URL precisa estar cadastrada no app da Shopee.
    //
    // verify_ssl: NUNCA desligar em produção. Só existe para dev local atrás de
    // TLS interceptado (AV/proxy) ou PHP sem cacert.pem — setar SHOPEE_VERIFY_SSL=false
    // apenas no .env local.
    'shopee' => [
        'host'        => env('SHOPEE_HOST', 'https://partner.shopeemobile.com'),
        'verify_ssl'  => env('SHOPEE_VERIFY_SSL', true),

        // DOIS apps distintos no Shopee Open Platform (categorias não coabitam):
        //  - erp: "ERP System"  → Order/Payment/escrow (faturamento).
        //  - ads: "Ads Service" → performance de anúncios (TACoS) — exige ISV oficial.
        // Cada app tem partner_id/partner_key/redirect PRÓPRIOS (1 por app). O
        // cliente autoriza os dois via consent encadeado; ambos apontam pro mesmo
        // shop_id. Host/verify_ssl são compartilhados (mesmo ambiente sandbox/live).
        'apps' => [
            'erp' => [
                // Fallback pros envs antigos (SHOPEE_PARTNER_ID/KEY/REDIRECT) — retrocompat.
                'partner_id'  => env('SHOPEE_ERP_PARTNER_ID', env('SHOPEE_PARTNER_ID')),
                'partner_key' => env('SHOPEE_ERP_PARTNER_KEY', env('SHOPEE_PARTNER_KEY')),
                'redirect'    => env('SHOPEE_ERP_REDIRECT_URI', env('SHOPEE_REDIRECT_URI', 'https://desafio.ecfconsultoria.com.br/oauth/shopee/callback')),
            ],
            'ads' => [
                'partner_id'  => env('SHOPEE_ADS_PARTNER_ID'),
                'partner_key' => env('SHOPEE_ADS_PARTNER_KEY'),
                'redirect'    => env('SHOPEE_ADS_REDIRECT_URI', 'https://desafio.ecfconsultoria.com.br/oauth/shopee/ads/callback'),
            ],
        ],
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
            // Phase 111 Plan 111-01 (HUB-API-01) — deal/company/contact ganham
            // props ampliadas do handoff Comercial v20.0 (só ADICIONA chaves;
            // as antigas permanecem intactas para o consumidor legado do
            // webhook, que acessa por chave nomeada). Regra: propriedade
            // ausente na conta HubSpot = null no payload, nunca quebra o fluxo.
            // Quick task 260805-eqk — nicho/dor/vende_ml/faturamento_mensal
            // saíram: essas properties NÃO existem na conta HubSpot da ECF (o
            // webhook pedia e o HubSpot ignorava) e as colunas correspondentes
            // em `companies` foram removidas.
            'deal' => [
                'servico'            => env('HUBSPOT_PROP_DEAL_SERVICO', 'servico_ecf'),
                'observacao'         => env('HUBSPOT_PROP_DEAL_OBSERVACAO', 'observacao'),
                'description'        => env('HUBSPOT_PROP_DEAL_DESCRIPTION', 'description'),
                'closed_won_reason'  => env('HUBSPOT_PROP_DEAL_CLOSED_WON_REASON', 'closed_won_reason'),
                'closedate'          => env('HUBSPOT_PROP_DEAL_CLOSEDATE', 'closedate'),
                'pipeline'           => env('HUBSPOT_PROP_DEAL_PIPELINE', 'pipeline'),
                'hs_mrr'             => env('HUBSPOT_PROP_DEAL_MRR', 'hs_mrr'),
                'hs_arr'             => env('HUBSPOT_PROP_DEAL_ARR', 'hs_arr'),
                'hs_tcv'             => env('HUBSPOT_PROP_DEAL_TCV', 'hs_tcv'),
                'hs_acv'             => env('HUBSPOT_PROP_DEAL_ACV', 'hs_acv'),
                'hs_currency'        => env('HUBSPOT_PROP_DEAL_CURRENCY', 'hs_currency'),
                // Campos SPIN (Situação/Problema/Implicação/Necessidade) — props
                // textarea do deal. Adicionados ao fetch → caem no hubspot_snapshot.deal
                // e são exibidos no modal Detalhes HubSpot + página da empresa.
                'spin_situacao'      => env('HUBSPOT_PROP_DEAL_SPIN_SITUACAO', 'situacao_atual_do_cliente'),
                'spin_problema'      => env('HUBSPOT_PROP_DEAL_SPIN_PROBLEMA', 'problema_principal_identificado'),
                'spin_implicacao'    => env('HUBSPOT_PROP_DEAL_SPIN_IMPLICACAO', 'implicacao_do_problema'),
                'spin_necessidade'   => env('HUBSPOT_PROP_DEAL_SPIN_NECESSIDADE', 'necessidade_de_solucao'),
            ],
            'company' => [
                'name'          => env('HUBSPOT_PROP_COMPANY_NAME', 'name'),
                'cnpj'          => env('HUBSPOT_PROP_COMPANY_CNPJ', 'cnpj'),
                'email'         => env('HUBSPOT_PROP_COMPANY_EMAIL', 'email'),
                'phone'         => env('HUBSPOT_PROP_COMPANY_PHONE', 'phone'),
                'domain'        => env('HUBSPOT_PROP_COMPANY_DOMAIN', 'domain'),
                'industry'      => env('HUBSPOT_PROP_COMPANY_INDUSTRY', 'industry'),
                'annualrevenue' => env('HUBSPOT_PROP_COMPANY_ANNUAL_REVENUE', 'annualrevenue'),
                'city'          => env('HUBSPOT_PROP_COMPANY_CITY', 'city'),
                'state'         => env('HUBSPOT_PROP_COMPANY_STATE', 'state'),
                'country'       => env('HUBSPOT_PROP_COMPANY_COUNTRY', 'country'),
            ],
            // Quick task 260805-eqk — Notes (engagements) associadas ao DEAL.
            // A property `observacao` do deal NAO existe na conta da ECF; as
            // observacoes reais sao Notes. `hs_note_body` vem em HTML e e
            // sanitizado no HubspotApiClient::fetchNotes.
            'note' => [
                'body'      => env('HUBSPOT_PROP_NOTE_BODY', 'hs_note_body'),
                'timestamp' => env('HUBSPOT_PROP_NOTE_TIMESTAMP', 'hs_timestamp'),
            ],
            // Phase 35 Plan 35-02 — contato vinculado ao deal (D-04).
            // Usado pra preencher email_cliente/telefone da Company quando
            // a HubSpot Company nao tem esses campos (fallback). firstname +
            // lastname concatenados alimentam a coluna estruturada
            // `companies.nome_contato` (Fase 113). Quick task 260805-ohs: a
            // linha "Contato (HubSpot): ..." em `companies.notes` foi removida.
            //
            // Quick task 260805-eqk — `origem_do_lead` (e as irmas
            // `campanha_origem` / `criativo_origem`) vivem no CONTATO, nao no
            // deal nem na company. Validado contra a conta real da ECF em
            // 2026-08-05 (contato 235433492313 => "Parceiro de Polos").
            'contact' => [
                'firstname'         => env('HUBSPOT_PROP_CONTACT_FIRSTNAME', 'firstname'),
                'lastname'          => env('HUBSPOT_PROP_CONTACT_LASTNAME', 'lastname'),
                'email'             => env('HUBSPOT_PROP_CONTACT_EMAIL', 'email'),
                'phone'             => env('HUBSPOT_PROP_CONTACT_PHONE', 'phone'),
                'mobilephone'       => env('HUBSPOT_PROP_CONTACT_MOBILEPHONE', 'mobilephone'),
                'jobtitle'          => env('HUBSPOT_PROP_CONTACT_JOBTITLE', 'jobtitle'),
                'additional_emails' => env('HUBSPOT_PROP_CONTACT_ADDITIONAL_EMAILS', 'hs_additional_emails'),
                'origem_do_lead'    => env('HUBSPOT_PROP_CONTACT_ORIGEM_LEAD', 'origem_do_lead'),
                'campanha_origem'   => env('HUBSPOT_PROP_CONTACT_CAMPANHA_ORIGEM', 'campanha_origem'),
                'criativo_origem'   => env('HUBSPOT_PROP_CONTACT_CRIATIVO_ORIGEM', 'criativo_origem'),
            ],
        ],
    ],

    // Fase 126 Plan 126-01 (CLICK-01) — client HTTP para a API Clicksign v3
    // (envelope/documento/signatário/requisito). ⚠️ O token vai PURO no
    // header Authorization — gate #2 medido em CLICKSIGN-SANDBOX-EMPIRICO.md
    // §1. NUNCA usar `Http::withToken()` no client: o helper do Laravel
    // prefixa "Bearer" e a API devolve 401. `max_upload_bytes` é o gate #5,
    // **NÃO MEDIDO** (o PDF de teste do sandbox tinha 1,5 KB) — fechado pelo
    // checkpoint humano do plano 126-06; o default abaixo é conservador.
    'clicksign' => [
        'env'              => env('CLICKSIGN_ENV', 'sandbox'),
        'base_url'         => env('CLICKSIGN_BASE_URL', 'https://sandbox.clicksign.com/api/v3'),
        'access_token'     => env('CLICKSIGN_ACCESS_TOKEN'),
        'webhook_secret'   => env('CLICKSIGN_WEBHOOK_SECRET'),
        'api_user_email'   => env('CLICKSIGN_API_USER_EMAIL'),
        'max_upload_bytes' => env('CLICKSIGN_MAX_UPLOAD_BYTES', 20971520),

        // Fase 131 Plano 131-01 (UI-05 do UI-SPEC, CLICK-10/D-13) — URL do
        // PAINEL da Clicksign (onde a pessoa CONCLUI o cancelamento à mão,
        // porque a API v3 não cancela envelope em andamento — medido em
        // CLICKSIGN-SANDBOX-EMPIRICO.md §15.2). NÃO é a `base_url` da API.
        // O CTA de confirmação do cancelamento (plano 131-05) promete
        // "Registrar e ir para a Clicksign" — um rótulo que promete levar a
        // um lugar precisa de fato levar, mesma régua que recusou "Cancelar
        // contrato" para um botão que não cancela. Derivada do MESMO
        // CLICKSIGN_ENV que decide a `base_url` acima; default aponta para o
        // sandbox (nunca produção) — apontar errado por omissão não pode
        // levar ninguém a mexer num contrato real por engano.
        'painel_url' => env('CLICKSIGN_PAINEL_URL') ?: (
            env('CLICKSIGN_ENV', 'sandbox') === 'producao'
                ? 'https://app.clicksign.com'
                : 'https://sandbox.clicksign.com'
        ),

        // Fase 126 Plan 126-10 — UUID do modelo (.docx) de contrato cadastrado
        // na conta Clicksign, lido pelo comando `clicksign:sondar-modelo` e,
        // depois, pela Fase 127. Sandbox e produção têm UUIDs DIFERENTES —
        // valor real só no `.env` local ou nas variáveis do servidor, nunca
        // aqui nem no `.env.example` (mesmo não sendo segredo, um exemplo
        // preenchido vira produção por engano).
        'template_id'      => env('CLICKSIGN_TEMPLATE_ID'),

        // Plano 127-04 (D-03 + CLICK-08 + DADOS-06) — 30 e 3 são os valores
        // MEDIDOS como padrão da própria Clicksign (`deadline_at` = +30
        // dias, `remind_interval` = 3 — §"Envelope" do
        // CLICKSIGN-SANDBOX-EMPIRICO.md), não são palpite. D-03: prazo e
        // lembrete vão na CRIAÇÃO do envelope, não na ativação, porque a
        // ativação acontece FORA do sistema (D-02, interface da Clicksign)
        // e o valor se perderia se só fosse aplicado lá. CLICK-08: o
        // lembrete é nativo da Clicksign — não criar scheduler próprio,
        // duplicaria notificação.
        'prazo_dias_padrao'    => (int) env('CLICKSIGN_PRAZO_DIAS', 30),
        'lembrete_dias_padrao' => (int) env('CLICKSIGN_LEMBRETE_DIAS', 3),

        // Fase 126 Plan 126-02 (D-08) — os 3 signatários FIXOS da ECF que
        // entram em todo contrato (dois sócios como "contratada" + Comercial
        // como "testemunha"), lidos de `env()`. Nome e e-mail reais NUNCA
        // entram aqui hardcoded nem no `.env.example` — só no `.env` local
        // (gitignored) ou nas variáveis de ambiente do servidor.
        'signatarios_ecf' => [
            [
                'nome'  => env('CLICKSIGN_SIG1_NOME', ''),
                'email' => env('CLICKSIGN_SIG1_EMAIL', ''),
                'papel' => 'contratada',
            ],
            [
                'nome'  => env('CLICKSIGN_SIG2_NOME', ''),
                'email' => env('CLICKSIGN_SIG2_EMAIL', ''),
                'papel' => 'contratada',
            ],
            [
                'nome'  => env('CLICKSIGN_SIG3_NOME', ''),
                'email' => env('CLICKSIGN_SIG3_EMAIL', ''),
                'papel' => 'testemunha',
            ],
        ],
    ],

];
