<?php

/*
 * Configuração da integração Digisac (WhatsApp) — v15.5.
 *
 * Valores default padrões vêm daqui e são sobrescritos por Configuracao::get
 * quando o admin persiste alterações em `/nps/envio-automatico`. Ou seja:
 *   ambiente/env → config('digisac.default_service_id')
 *   admin editou → Configuracao::get('nps_digisac_service_id')
 *
 * O dispatcher (NpsDigisacDispatchService) prefere Configuracao quando existe,
 * cai em config() quando vazio.
 */

return [

    // URL base da API Digisac — ex: https://ecf.digisac.chat
    'base_url' => rtrim((string) env('DIGISAC_BASE_URL', ''), '/'),

    // Bearer token da conta Digisac (env: DIGISAC_TOKEN).
    'token' => env('DIGISAC_TOKEN'),

    // Conexão WhatsApp padrão — usada como fallback global quando a empresa
    // não define digisac_service_id proprio.
    'default_service_id' => env('DIGISAC_DEFAULT_SERVICE_ID'),

    // Usuário Digisac que aparece como origem do envio (bot user).
    'default_user_id' => env('DIGISAC_DEFAULT_USER_ID'),

    // Timeout em segundos para chamadas Digisac. O envio mensal roda no comando
    // (síncrono) — timeout curto evita segurar o batch por muito tempo.
    'timeout' => (int) env('DIGISAC_TIMEOUT', 15),

    // Origem enviada no payload de POST /messages. "bot" é o valor típico
    // quando a mensagem parte de automação e não do atendente humano.
    'origin' => env('DIGISAC_ORIGIN', 'bot'),

];
