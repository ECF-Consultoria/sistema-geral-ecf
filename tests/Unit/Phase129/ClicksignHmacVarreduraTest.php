<?php

namespace Tests\Unit\Phase129;

use App\Support\Clicksign\ClicksignHmacVarredura;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova unitária da varredura pura das 4 candidatas de HMAC (gate A1,
 * D-08/D-09). Nenhum destes testes toca banco, config() ou rede — o body e
 * o secret são literais do próprio teste (lembrete do ROADMAP SC1: o
 * fixture de HMAC precisa ser calculado FORA do código de produção).
 */
class ClicksignHmacVarreduraTest extends TestCase
{
    private const BODY   = '{"event":{"name":"sign"},"data":{"id":"abc123"}}';
    private const SECRET = 'segredo-de-teste-129-nao-e-o-secret-real';

    #[Test]
    public function as_4_candidatas_produzem_valores_diferentes_entre_si(): void
    {
        $calculadas = ClicksignHmacVarredura::calcularTodas(self::BODY, self::SECRET);

        $this->assertCount(4, $calculadas);
        $this->assertCount(4, array_unique($calculadas), 'Duas candidatas colidiram — a varredura perderia poder de decisão.');
    }

    #[Test]
    public function cada_candidata_tem_64_caracteres_hex_depois_do_prefixo(): void
    {
        $calculadas = ClicksignHmacVarredura::calcularTodas(self::BODY, self::SECRET);

        foreach ($calculadas as $chave => $valor) {
            $this->assertStringStartsWith(ClicksignHmacVarredura::PREFIXO, $valor, "Candidata \"{$chave}\" sem o prefixo sha256=.");

            $hex = substr($valor, strlen(ClicksignHmacVarredura::PREFIXO));
            $this->assertSame(64, strlen($hex), "Candidata \"{$chave}\" não tem 64 caracteres hex.");
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hex, "Candidata \"{$chave}\" não é hex válido.");
        }
    }

    #[Test]
    public function identificar_todas_marca_exatamente_uma_candidata_verdadeira(): void
    {
        $headerRecebido = ClicksignHmacVarredura::calcular(self::BODY, self::SECRET, 'hmac_body_chave_secret');

        $veredito = ClicksignHmacVarredura::identificarTodas(self::BODY, self::SECRET, $headerRecebido);

        $this->assertSame(
            ['hmac_body_chave_secret'],
            array_keys(array_filter($veredito)),
        );

        $this->assertSame('hmac_body_chave_secret', ClicksignHmacVarredura::vencedora(self::BODY, self::SECRET, $headerRecebido));
    }

    #[Test]
    public function vencedora_devolve_nulo_quando_nenhuma_candidata_bate(): void
    {
        $headerInvalido = 'sha256=' . str_repeat('0', 64);

        $this->assertNull(ClicksignHmacVarredura::vencedora(self::BODY, self::SECRET, $headerInvalido));
    }

    #[Test]
    public function calcular_lanca_para_formula_desconhecida(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClicksignHmacVarredura::calcular(self::BODY, self::SECRET, 'formula_inexistente');
    }

    #[Test]
    public function formula_confirmada_e_a_medida_no_gate_a1(): void
    {
        // Gate A1 fechado em 2026-08-13 — 5/5 eventos reais do sandbox.
        // Ver .planning/phases/129-webhook-clicksign-v22-0/129-GATE.md.
        $this->assertSame('hmac_body_chave_secret', ClicksignHmacVarredura::FORMULA_CONFIRMADA);
    }

    #[Test]
    public function confere_aceita_header_calculado_com_a_formula_confirmada(): void
    {
        $header = ClicksignHmacVarredura::calcular(self::BODY, self::SECRET, ClicksignHmacVarredura::FORMULA_CONFIRMADA);

        $this->assertTrue(ClicksignHmacVarredura::confere(self::BODY, self::SECRET, $header));
    }

    #[Test]
    public function confere_recusa_header_que_nao_bate(): void
    {
        $headerErrado = 'sha256=' . str_repeat('0', 64);

        $this->assertFalse(ClicksignHmacVarredura::confere(self::BODY, self::SECRET, $headerErrado));
    }
}
