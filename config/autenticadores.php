<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chave de criptografia dos secrets TOTP
    |--------------------------------------------------------------------------
    |
    | Os secrets TOTP (seeds dos autenticadores dos clientes) são cifrados em
    | repouso com esta chave DEDICADA, separada da APP_KEY de propósito: se um
    | dump do banco vazar, os seeds não abrem sem esta chave, que vive só no
    | .env (local e na VPS), não no banco.
    |
    | Gere com:  php artisan autenticadores:gerar-chave
    | e cole o valor (formato "base64:...") em AUTENTICADORES_ENC_KEY no .env.
    |
    | Sem esta chave o módulo não decifra nem grava secrets — e falha de forma
    | clara, em vez de gravar lixo (ver App\Casts\TotpSecret).
    |
    */
    'enc_key' => env('AUTENTICADORES_ENC_KEY'),

    // Cifra usada pelo Encrypter dedicado. AES-256-CBC com HMAC (padrão Laravel).
    'cipher' => 'aes-256-cbc',

];
