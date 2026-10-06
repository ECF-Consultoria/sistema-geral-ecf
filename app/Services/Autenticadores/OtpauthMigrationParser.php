<?php

namespace App\Services\Autenticadores;

use InvalidArgumentException;
use ParagonIE\ConstantTime\Base32;

/**
 * Lê as URIs "otpauth-migration://offline?data=..." que o Google Authenticator
 * gera em "Transferir contas → Exportar contas". O parâmetro `data` é um
 * protobuf (MigrationPayload) em Base64. Uma exportação pode trazer VÁRIAS
 * contas; este parser devolve todas.
 *
 * Esquema do Google Authenticator:
 *   MigrationPayload { repeated OtpParameters otp_parameters = 1; ... }
 *   OtpParameters {
 *     bytes  secret    = 1;
 *     string name      = 2;   // conta (às vezes "Servico:conta")
 *     string issuer    = 3;
 *     enum   algorithm = 4;   // 0/1 SHA1 · 2 SHA256 · 3 SHA512
 *     enum   digits    = 5;   // 0/1 seis · 2 oito
 *     enum   type      = 6;   // 1 HOTP · 2 TOTP
 *   }
 * Campos extras (ex.: id no campo 8) são ignorados. O período é sempre 30 s.
 *
 * Porte do decoder validado no PoC Node (totp-poc/src/otpauth-migration.js).
 */
class OtpauthMigrationParser
{
    private const ALGORITHMS = [0 => 'SHA1', 1 => 'SHA1', 2 => 'SHA256', 3 => 'SHA512'];
    private const DIGITS     = [0 => 6, 1 => 6, 2 => 8];
    private const TYPE_HOTP  = 1;
    private const TYPE_TOTP  = 2;
    private const PERIOD     = 30;

    private const MALFORMED = 'Dados da URI de migração corrompidos ou incompletos. Copie a URI de novo, inteira.';

    /**
     * @return array<int, array{issuer:string, account:string, secret:string, algorithm:string, digits:int, period:int}>
     */
    public function parse(string $uri): array
    {
        if (! preg_match('#^otpauth-migration://offline\?(.*)$#is', trim($uri), $m)) {
            throw new InvalidArgumentException('URI de migração inválida: o esperado é otpauth-migration://offline?data=…');
        }

        $bytes = $this->readDataParam($m[1]);
        $entries = [];

        foreach ($this->fields($bytes) as [$field, , $value]) {
            if ($field === 1) {
                $entries[] = $this->decodeOtpParameters($value);
            }
        }

        if ($entries === []) {
            throw new InvalidArgumentException('A URI de migração não contém nenhuma conta.');
        }

        return array_map([$this, 'toAccount'], $entries);
    }

    private function toAccount(array $entry): array
    {
        $label = $entry['issuer'] !== '' ? $entry['issuer'] : ($entry['name'] !== '' ? $entry['name'] : '(sem nome)');

        if ($entry['type'] === self::TYPE_HOTP) {
            throw new InvalidArgumentException("A conta \"{$label}\" é HOTP (por contador). Este módulo só suporta TOTP.");
        }
        if ($entry['type'] !== self::TYPE_TOTP) {
            throw new InvalidArgumentException("Tipo de conta desconhecido na URI de migração (\"{$label}\").");
        }
        if (! array_key_exists($entry['algorithm'], self::ALGORITHMS)) {
            throw new InvalidArgumentException("Algoritmo não suportado na conta \"{$label}\".");
        }
        if (! array_key_exists($entry['digits'], self::DIGITS)) {
            throw new InvalidArgumentException("Quantidade de dígitos não suportada na conta \"{$label}\".");
        }
        if ($entry['secret'] === '') {
            throw new InvalidArgumentException("A conta \"{$label}\" não tem secret.");
        }

        return array_merge(
            $this->splitLabel($entry['name'], $entry['issuer']),
            [
                'secret'    => Base32::encodeUpperUnpadded($entry['secret']),
                'algorithm' => self::ALGORITHMS[$entry['algorithm']],
                'digits'    => self::DIGITS[$entry['digits']],
                'period'    => self::PERIOD,
            ],
        );
    }

    // ─── URI → bytes ───

    private function readDataParam(string $query): string
    {
        $raw = null;
        foreach (explode('&', $query) as $pair) {
            // Não usa explode('='): o Base64 termina com "=" de padding.
            $eq = strpos($pair, '=');
            if ($eq > 0 && strtolower(substr($pair, 0, $eq)) === 'data') {
                $raw = substr($pair, $eq + 1);
            }
        }
        if ($raw === null) {
            throw new InvalidArgumentException('A URI de migração não tem o parâmetro data.');
        }

        $base64 = rawurldecode($raw);
        // Copiando de alguns lugares, o "+" do Base64 vira espaço.
        $base64 = str_replace(' ', '+', $base64);

        if (! preg_match('#^[A-Za-z0-9+/_-]+={0,2}$#', $base64)) {
            throw new InvalidArgumentException(self::MALFORMED);
        }

        $decoded = base64_decode(strtr($base64, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException(self::MALFORMED);
        }

        return $decoded;
    }

    // ─── Protobuf (formato de fio), só o necessário ───

    private function decodeOtpParameters(string $bytes): array
    {
        $entry = ['secret' => '', 'name' => '', 'issuer' => '', 'algorithm' => 0, 'digits' => 0, 'type' => 0];

        foreach ($this->fields($bytes) as [$field, $wire, $value]) {
            switch ($field) {
                case 1: $entry['secret']    = $this->expectBytes($wire, $value); break;
                case 2: $entry['name']      = trim($this->expectBytes($wire, $value)); break;
                case 3: $entry['issuer']    = trim($this->expectBytes($wire, $value)); break;
                case 4: $entry['algorithm'] = $this->expectInt($wire, $value); break;
                case 5: $entry['digits']    = $this->expectInt($wire, $value); break;
                case 6: $entry['type']      = $this->expectInt($wire, $value); break;
                default: break;
            }
        }

        return $entry;
    }

    /**
     * Lista os campos de uma mensagem: [field, wireType, value].
     * value é int (varint) ou string (bytes). Campos de 64/32 bits são pulados.
     *
     * @return array<int, array{0:int,1:int,2:int|string}>
     */
    private function fields(string $bytes): array
    {
        $out = [];
        $pos = 0;
        $len = strlen($bytes);

        while ($pos < $len) {
            $key   = $this->varint($bytes, $pos, $len);
            $field = $key >> 3;
            $wire  = $key & 7;
            if ($field === 0) {
                throw new InvalidArgumentException(self::MALFORMED);
            }

            switch ($wire) {
                case 0:
                    $out[] = [$field, 0, $this->varint($bytes, $pos, $len)];
                    break;
                case 2:
                    $l = $this->varint($bytes, $pos, $len);
                    if ($l < 0 || $pos + $l > $len) {
                        throw new InvalidArgumentException(self::MALFORMED);
                    }
                    $out[] = [$field, 2, substr($bytes, $pos, $l)];
                    $pos += $l;
                    break;
                case 1:
                    $pos += 8;
                    break;
                case 5:
                    $pos += 4;
                    break;
                default:
                    throw new InvalidArgumentException(self::MALFORMED);
            }
        }

        return $out;
    }

    private function varint(string $b, int &$pos, int $len): int
    {
        $result = 0;
        $shift = 0;
        while ($shift < 64) {
            if ($pos >= $len) {
                throw new InvalidArgumentException(self::MALFORMED);
            }
            $byte = ord($b[$pos++]);
            $result |= ($byte & 0x7f) << $shift;
            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }
        throw new InvalidArgumentException(self::MALFORMED);
    }

    private function expectBytes(int $wire, int|string $value): string
    {
        if ($wire !== 2 || ! is_string($value)) {
            throw new InvalidArgumentException(self::MALFORMED);
        }
        return $value;
    }

    private function expectInt(int $wire, int|string $value): int
    {
        if ($wire !== 0 || ! is_int($value)) {
            throw new InvalidArgumentException(self::MALFORMED);
        }
        return $value;
    }

    // O Google às vezes grava o nome como "Servico:conta". Separa para não repetir o serviço.
    private function splitLabel(string $name, string $issuer): array
    {
        $colon = strpos($name, ':');
        if ($colon > 0) {
            $prefix = trim(substr($name, 0, $colon));
            if ($issuer === '' || $prefix === $issuer) {
                return [
                    'issuer'  => $issuer !== '' ? $issuer : $prefix,
                    'account' => trim(substr($name, $colon + 1)),
                ];
            }
        }

        return ['issuer' => $issuer, 'account' => $name];
    }
}
