<?php

namespace App\Services\Autenticadores;

use InvalidArgumentException;
use ParagonIE\ConstantTime\Base32;

/**
 * Geração de códigos TOTP (RFC 6238) e leitura de URIs otpauth:// /
 * otpauth-migration://. Usa o HMAC do próprio PHP (hash_hmac — primitiva da
 * stdlib, não cifra feita à mão) e o Base32 do paragonie (já instalado).
 *
 * Nada aqui toca o banco: recebe o secret em Base32 e devolve o código. Porte
 * da lógica validada no PoC Node (totp-poc/src/totp.js) contra os vetores
 * oficiais da RFC 6238 — ver tests/Unit/TotpServiceTest.php.
 */
class TotpService
{
    private const ALGORITHMS = ['SHA1' => 'sha1', 'SHA256' => 'sha256', 'SHA512' => 'sha512'];
    private const DIGITS = [6, 7, 8];
    private const MIN_PERIOD = 10;
    private const MAX_PERIOD = 120;
    private const MIN_SECRET_LEN = 16;   // 80 bits — mínimo usado por serviços reais
    private const MAX_SECRET_LEN = 128;

    public function __construct(private OtpauthMigrationParser $migrationParser) {}

    /**
     * Gera o código TOTP de um instante (padrão: agora).
     *
     * @return array{code:string, digits:int, period:int, step:int, remaining_ms:int}
     */
    public function gerarCodigo(
        string $secretBase32,
        string $algoritmo = 'SHA1',
        int $digitos = 6,
        int $periodo = 30,
        ?int $timestamp = null,
    ): array {
        $algoritmo = strtoupper($algoritmo);
        if (! isset(self::ALGORITHMS[$algoritmo])) {
            throw new InvalidArgumentException('Algoritmo TOTP não suportado.');
        }
        if (! in_array($digitos, self::DIGITS, true)) {
            throw new InvalidArgumentException('Quantidade de dígitos não suportada.');
        }
        if ($periodo < self::MIN_PERIOD || $periodo > self::MAX_PERIOD) {
            throw new InvalidArgumentException('Período TOTP inválido.');
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, $periodo);
        $key = $this->decodeBase32($secretBase32);

        // Contador em 64 bits big-endian (RFC 4226).
        $binCounter = pack('J', $counter);
        $hash = hash_hmac(self::ALGORITHMS[$algoritmo], $binCounter, $key, true);

        // Truncagem dinâmica (RFC 4226 §5.3).
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = (unpack('N', substr($hash, $offset, 4))[1]) & 0x7fffffff;
        $code = str_pad((string) ($binary % (10 ** $digitos)), $digitos, '0', STR_PAD_LEFT);

        return [
            'code'         => $code,
            'digits'       => $digitos,
            'period'       => $periodo,
            'step'         => $counter,
            'remaining_ms' => ($periodo - ($timestamp % $periodo)) * 1000,
        ];
    }

    /**
     * Interpreta uma URI e devolve SEMPRE uma lista de contas:
     *   otpauth://totp/...            → 1 conta
     *   otpauth-migration://offline?… → N contas
     *
     * @return array<int, array{issuer:string, account:string, secret:string, algorithm:string, digits:int, period:int}>
     */
    public function parseUri(string $uri): array
    {
        $uri = trim($uri);
        if ($uri === '') {
            throw new InvalidArgumentException('Informe a URI otpauth:// ou otpauth-migration://.');
        }

        if (stripos($uri, 'otpauth-migration:') === 0) {
            return array_map([$this, 'normalizeAccount'], $this->migrationParser->parse($uri));
        }
        if (preg_match('#^otpauth://hotp/#i', $uri)) {
            throw new InvalidArgumentException('A URI é de HOTP (por contador). Este módulo só suporta TOTP.');
        }
        if (! preg_match('#^otpauth://totp/#i', $uri)) {
            throw new InvalidArgumentException('A URI deve começar com otpauth://totp/ ou otpauth-migration://.');
        }

        return [$this->normalizeAccount($this->parseSingle($uri))];
    }

    /** Normaliza o secret para o Base32 canônico (maiúsculo, sem espaços/hífens/padding). */
    public function normalizeSecret(string $raw): string
    {
        $secret = rtrim(strtoupper(preg_replace('/[\s-]/', '', $raw)), '=');

        if (! preg_match('/^[A-Z2-7]+$/', $secret)) {
            throw new InvalidArgumentException('O secret deve estar em Base32: letras A–Z e dígitos 2–7.');
        }
        if (strlen($secret) < self::MIN_SECRET_LEN) {
            throw new InvalidArgumentException('Secret curto demais para um autenticador real.');
        }
        if (strlen($secret) > self::MAX_SECRET_LEN) {
            throw new InvalidArgumentException('Secret longo demais.');
        }

        return $secret;
    }

    private function parseSingle(string $uri): array
    {
        $parts = parse_url($uri);
        $label = isset($parts['path']) ? rawurldecode(ltrim($parts['path'], '/')) : '';
        parse_str($parts['query'] ?? '', $q);

        if (empty($q['secret'])) {
            throw new InvalidArgumentException('URI otpauth:// inválida: sem o parâmetro secret.');
        }

        $issuer = isset($q['issuer']) ? trim($q['issuer']) : '';
        $account = $label;
        if (str_contains($label, ':')) {
            [$prefix, $acc] = explode(':', $label, 2);
            $account = trim($acc);
            if ($issuer === '') {
                $issuer = trim($prefix);
            }
        }

        return [
            'issuer'    => $issuer,
            'account'   => trim($account),
            'secret'    => $q['secret'],
            'algorithm' => strtoupper($q['algorithm'] ?? 'SHA1'),
            'digits'    => (int) ($q['digits'] ?? 6),
            'period'    => (int) ($q['period'] ?? 30),
        ];
    }

    private function normalizeAccount(array $a): array
    {
        $algoritmo = strtoupper($a['algorithm'] ?? 'SHA1');
        $digitos = (int) ($a['digits'] ?? 6);
        $periodo = (int) ($a['period'] ?? 30);

        if (! isset(self::ALGORITHMS[$algoritmo])) {
            throw new InvalidArgumentException('Algoritmo não suportado (use SHA1, SHA256 ou SHA512).');
        }
        if (! in_array($digitos, self::DIGITS, true)) {
            throw new InvalidArgumentException('Quantidade de dígitos não suportada (use 6, 7 ou 8).');
        }
        if ($periodo < self::MIN_PERIOD || $periodo > self::MAX_PERIOD) {
            throw new InvalidArgumentException('Período inválido (entre 10 e 120 segundos).');
        }

        return [
            'issuer'    => trim($a['issuer'] ?? ''),
            'account'   => trim($a['account'] ?? ''),
            'secret'    => $this->normalizeSecret($a['secret']),
            'algorithm' => $algoritmo,
            'digits'    => $digitos,
            'period'    => $periodo,
        ];
    }

    private function decodeBase32(string $secret): string
    {
        $secret = rtrim(strtoupper(preg_replace('/[\s-]/', '', $secret)), '=');
        if ($secret === '' || ! preg_match('/^[A-Z2-7]+$/', $secret)) {
            throw new InvalidArgumentException('Secret TOTP inválido (Base32).');
        }

        return Base32::decodeUpper($secret);
    }
}
