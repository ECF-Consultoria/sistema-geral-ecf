<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Cast que cifra/decifra o secret TOTP em repouso usando uma chave DEDICADA
 * (config autenticadores.enc_key / env AUTENTICADORES_ENC_KEY), separada da
 * APP_KEY do Laravel.
 *
 * Por que não usar o cast 'encrypted' padrão: o 'encrypted' usa a APP_KEY, que
 * já destrava sessões e outros dados. Aqui a decisão foi isolar os seeds dos
 * clientes numa chave própria — quem tiver só o banco (ou só a APP_KEY) não abre
 * os secrets.
 *
 * Se a chave faltar ou for inválida, lança RuntimeException: o módulo falha de
 * forma clara em vez de gravar texto ilegível no lugar do secret.
 */
class TotpSecret implements CastsAttributes
{
    private static ?Encrypter $encrypter = null;

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->encrypter()->decryptString($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->encrypter()->encryptString($value);
    }

    private function encrypter(): Encrypter
    {
        if (self::$encrypter !== null) {
            return self::$encrypter;
        }

        $raw = config('autenticadores.enc_key');

        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException(
                'AUTENTICADORES_ENC_KEY ausente no .env. Gere com "php artisan autenticadores:gerar-chave" e cole no .env.'
            );
        }

        $key = str_starts_with($raw, 'base64:')
            ? base64_decode(substr($raw, 7), true)
            : $raw;

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'AUTENTICADORES_ENC_KEY inválida: esperado 32 bytes (use "php artisan autenticadores:gerar-chave").'
            );
        }

        return self::$encrypter = new Encrypter($key, config('autenticadores.cipher', 'aes-256-cbc'));
    }
}
