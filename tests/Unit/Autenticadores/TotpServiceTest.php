<?php

namespace Tests\Unit\Autenticadores;

use App\Services\Autenticadores\OtpauthMigrationParser;
use App\Services\Autenticadores\TotpService;
use InvalidArgumentException;
use ParagonIE\ConstantTime\Base32;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890", vetor público da RFC 6238

    private function totp(): TotpService
    {
        return new TotpService(new OtpauthMigrationParser());
    }

    /** Vetores oficiais da RFC 6238, Apêndice B (SHA-1, 8 dígitos). */
    public function test_gera_os_vetores_oficiais_da_rfc_6238(): void
    {
        $vetores = [
            [59, '94287082'], [1111111109, '07081804'], [1111111111, '14050471'],
            [1234567890, '89005924'], [2000000000, '69279037'], [20000000000, '65353130'],
        ];
        foreach ($vetores as [$t, $esperado]) {
            $this->assertSame($esperado, $this->totp()->gerarCodigo(self::RFC_SECRET, 'SHA1', 8, 30, $t)['code'], "t={$t}");
            // 6 dígitos (padrão do Google) = últimos 6.
            $this->assertSame(substr($esperado, -6), $this->totp()->gerarCodigo(self::RFC_SECRET, 'SHA1', 6, 30, $t)['code']);
        }
    }

    public function test_confere_com_implementacao_independente_para_secret_aleatorio(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $key = random_bytes(20);
            $secret = Base32::encodeUpperUnpadded($key);
            $t = random_int(0, 2 ** 40);

            $counter = pack('J', intdiv($t, 30));
            $h = hash_hmac('sha1', $counter, $key, true);
            $o = ord($h[19]) & 0x0f;
            $esperado = str_pad((string) (((unpack('N', substr($h, $o, 4))[1]) & 0x7fffffff) % 1_000_000), 6, '0', STR_PAD_LEFT);

            $this->assertSame($esperado, $this->totp()->gerarCodigo($secret, 'SHA1', 6, 30, $t)['code'], "i={$i} t={$t}");
        }
    }

    public function test_informa_janela_e_tempo_restante(): void
    {
        $r = $this->totp()->gerarCodigo(self::RFC_SECRET, 'SHA1', 6, 30, 59);
        $this->assertSame(1, $r['step']);
        $this->assertSame(1000, $r['remaining_ms']);
    }

    public function test_parse_otpauth_simples_devolve_uma_conta(): void
    {
        $contas = $this->totp()->parseUri('otpauth://totp/Loja:fin%40loja.com?secret=' . strtolower(self::RFC_SECRET) . '&issuer=Loja');
        $this->assertCount(1, $contas);
        $this->assertSame('Loja', $contas[0]['issuer']);
        $this->assertSame('fin@loja.com', $contas[0]['account']);
        $this->assertSame(self::RFC_SECRET, $contas[0]['secret']);
    }

    public function test_parse_migracao_devolve_varias_contas(): void
    {
        $contas = [
            ['key' => random_bytes(20), 'name' => 'a@ex.com', 'issuer' => 'Amazon'],
            ['key' => random_bytes(20), 'name' => 'b@ex.com', 'issuer' => 'AWS'],
            ['key' => random_bytes(20), 'name' => 'c@ex.com', 'issuer' => 'GitHub'],
        ];
        $uri = $this->montarMigracao($contas);

        $parsed = $this->totp()->parseUri($uri);
        $this->assertCount(3, $parsed);
        $this->assertSame(['Amazon', 'AWS', 'GitHub'], array_column($parsed, 'issuer'));

        foreach ($contas as $i => $c) {
            $t = 1_000_000_000 + $i;
            $counter = pack('J', intdiv($t, 30));
            $h = hash_hmac('sha1', $counter, $c['key'], true);
            $o = ord($h[19]) & 0x0f;
            $esp = str_pad((string) (((unpack('N', substr($h, $o, 4))[1]) & 0x7fffffff) % 1_000_000), 6, '0', STR_PAD_LEFT);
            $this->assertSame($esp, $this->totp()->gerarCodigo($parsed[$i]['secret'], 'SHA1', 6, 30, $t)['code']);
        }
    }

    public function test_rejeita_hotp_e_uri_sem_secret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->totp()->parseUri('otpauth://hotp/X:y?secret=' . self::RFC_SECRET . '&counter=0');
    }

    public function test_conta_hotp_no_lote_derruba_citando_nome_nao_secret(): void
    {
        $boa = random_bytes(20);
        $uri = $this->montarMigracao([
            ['key' => $boa, 'name' => 'ok@ex.com', 'issuer' => 'Boa'],
            ['key' => random_bytes(20), 'name' => 'ruim', 'issuer' => 'Ruim', 'type' => 1],
        ]);
        try {
            $this->totp()->parseUri($uri);
            $this->fail('deveria lançar');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsStringIgnoringCase('Ruim', $e->getMessage());
            $this->assertStringNotContainsString(substr(Base32::encodeUpperUnpadded($boa), 0, 8), $e->getMessage());
        }
    }

    // ─── Montagem do protobuf de migração ───

    private function montarMigracao(array $contas): string
    {
        $f = fn (int $field, string $buf) => chr(($field << 3) | 2) . chr(strlen($buf)) . $buf;
        $v = fn (int $field, int $val) => chr($field << 3) . chr($val);

        $otps = '';
        foreach ($contas as $c) {
            $otps .= $f(1, $f(1, $c['key']) . $f(2, $c['name']) . $f(3, $c['issuer']) . $v(4, 1) . $v(5, 1) . $v(6, $c['type'] ?? 2));
        }
        $payload = $otps . $v(2, 2) . $v(3, 1) . $v(4, 0);

        return 'otpauth-migration://offline?data=' . rawurlencode(base64_encode($payload));
    }
}
