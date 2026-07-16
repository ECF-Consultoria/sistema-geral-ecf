<?php

/*
 * Configuração do módulo Anti-Burlamento NPS — Phase 94.
 *
 * IPs/CIDRs internos da ECF e a janela de "resposta rápida" ficam em .env
 * nesta fase — configuráveis pela UI é Fase 96 (fora de escopo aqui).
 */

return [

    'anti_burlamento' => [

        // Lista de IPs exatos da rede interna ECF, separados por vírgula.
        // Ex.: ECF_INTERNAL_IPS=201.10.20.30,189.40.50.60
        'internal_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ECF_INTERNAL_IPS', ''))
        ))),

        // Lista de redes CIDR internas, separadas por vírgula.
        // Ex.: ECF_INTERNAL_CIDRS=10.0.0.0/8,192.168.0.0/16
        'internal_cidrs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ECF_INTERNAL_CIDRS', ''))
        ))),

        // Janela (segundos) para considerar uma resposta "rápida demais" após
        // a geração do link. Default 60s (Regra 2 do NpsSuspicionService).
        'fast_response_window_seconds' => (int) env('NPS_SUSPICION_WINDOW_SECONDS', 60),

    ],

];
