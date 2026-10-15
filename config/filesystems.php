<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    | Disco das imagens definitivas do Publicador (v28.0, R2-01/R2-02).
    | Padrão `local` = comportamento de antes. Em produção vira `r2` só depois
    | que as credenciais estiverem no .env e `imagens:testar-conexao` passar.
    | Foto de referência do Gemini e geração não aprovada NUNCA usam este disco.
    */
    'imagens' => env('IMAGENS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'ml_sftp' => [
            'driver'     => 'sftp',
            'host'       => env('ML_SFTP_HOST', 'sftp.mercadolibre.io'),
            'port'       => (int) env('ML_SFTP_PORT', 22),
            'username'   => env('ML_SFTP_USER', 'dev01'),
            // Lê o conteúdo da chave PPK/PEM no momento do boot; phpseclib3 detecta o formato automaticamente
            'privateKey' => (function () {
                $path = env('ML_SFTP_PRIVATE_KEY_PATH');
                return $path && file_exists($path) ? file_get_contents($path) : null;
            })(),
            'passphrase' => env('ML_SFTP_PASSPHRASE') ?: null,
            'root'       => env('ML_SFTP_ROOT', '/'),
            'timeout'    => 30,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Cloudflare R2 (S3-compatível) — bucket PRIVADO, sem URL pública (v28.0 D4/D5):
        // o Mercado Livre recebe bytes e o navegador recebe por streaming do Laravel.
        // `root` é o prefixo do ambiente (production/ ou dev/) dentro do mesmo bucket.
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_BUCKET'),
            'endpoint' => env('R2_ENDPOINT') ?: (env('R2_ACCOUNT_ID')
                ? 'https://'.env('R2_ACCOUNT_ID').'.r2.cloudflarestorage.com'
                : null),
            'use_path_style_endpoint' => true,
            'root' => env('R2_PREFIX', 'dev'),
            'visibility' => 'private',
            // Checksum só quando a operação exige: o R2 não aceita todos os
            // algoritmos que o SDK manda por padrão desde a 3.337.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'http' => [
                'connect_timeout' => (float) env('R2_CONNECT_TIMEOUT', 5),
                'timeout' => (float) env('R2_TIMEOUT', 30),
            ],
            // Falha do R2 vira exceção (e não `false` silencioso) para quem grava
            // poder desfazer a linha do banco (ARM-04).
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
