<?php

namespace Tests\Feature\Phase132;

use App\Support\Clicksign\ClicksignAmbiente;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 132 Plano 01 (D-01, Task 1) — cobre `ClicksignAmbiente::ehProducao()`
 * e `ClicksignAmbiente::painelUrl()`.
 *
 * O caso mais importante desta suíte não é o caminho feliz: é o de grafia
 * desconhecida caindo no painel de teste. É ele que documenta a escolha de
 * default seguro — a garantia de que um erro de digitação em `CLICKSIGN_ENV`
 * nunca leva ninguém, por acidente, ao painel de produção.
 */
class ClicksignAmbienteTest extends TestCase
{
    // ─── ehProducao() — as quatro grafias aceitas ───

    #[Test]
    public function ehproducao_aceita_producao_em_portugues(): void
    {
        $this->assertTrue(ClicksignAmbiente::ehProducao('producao'));
    }

    #[Test]
    public function ehproducao_aceita_producao_com_acento(): void
    {
        $this->assertTrue(ClicksignAmbiente::ehProducao('produção'));
    }

    #[Test]
    public function ehproducao_aceita_production_em_ingles(): void
    {
        $this->assertTrue(ClicksignAmbiente::ehProducao('production'));
    }

    #[Test]
    public function ehproducao_aceita_prod_abreviado(): void
    {
        $this->assertTrue(ClicksignAmbiente::ehProducao('prod'));
    }

    #[Test]
    public function ehproducao_aceita_grafia_com_espaco_em_volta_e_maiusculas(): void
    {
        $this->assertTrue(ClicksignAmbiente::ehProducao('  PRODUÇÃO  '));
    }

    // ─── ehProducao() — o default seguro ───

    #[Test]
    public function ehproducao_recusa_sandbox(): void
    {
        $this->assertFalse(ClicksignAmbiente::ehProducao('sandbox'));
    }

    #[Test]
    public function ehproducao_recusa_string_vazia(): void
    {
        $this->assertFalse(ClicksignAmbiente::ehProducao(''));
    }

    #[Test]
    public function ehproducao_recusa_null(): void
    {
        $this->assertFalse(ClicksignAmbiente::ehProducao(null));
    }

    #[Test]
    public function grafia_desconhecida_cai_no_painel_de_teste(): void
    {
        // Este é o teste que documenta a escolha de design: um erro de
        // digitação em CLICKSIGN_ENV (aqui, "producaozinho" — parecido mas
        // errado) NUNCA pode levar ninguém ao painel de produção por
        // acidente. O default seguro é sempre o sandbox.
        $this->assertFalse(ClicksignAmbiente::ehProducao('producaozinho'));
        $this->assertSame(
            ClicksignAmbiente::PAINEL_SANDBOX,
            ClicksignAmbiente::painelUrl(null, 'producaozinho'),
        );
    }

    // ─── painelUrl() ───

    #[Test]
    public function painelurl_devolve_o_painel_de_producao_quando_env_e_production(): void
    {
        $this->assertSame(
            ClicksignAmbiente::PAINEL_PRODUCAO,
            ClicksignAmbiente::painelUrl(null, 'production'),
        );
    }

    #[Test]
    public function painelurl_devolve_o_painel_de_teste_quando_env_e_sandbox(): void
    {
        $this->assertSame(
            ClicksignAmbiente::PAINEL_SANDBOX,
            ClicksignAmbiente::painelUrl(null, 'sandbox'),
        );
    }

    #[Test]
    public function painelurl_devolve_o_painel_de_teste_quando_env_nao_existe(): void
    {
        $this->assertSame(
            ClicksignAmbiente::PAINEL_SANDBOX,
            ClicksignAmbiente::painelUrl(null, 'ambiente-que-nao-existe'),
        );
    }

    #[Test]
    public function painelurl_explicita_vence_mesmo_em_producao(): void
    {
        $this->assertSame(
            'https://exemplo.invalido',
            ClicksignAmbiente::painelUrl('https://exemplo.invalido', 'production'),
        );
    }

    // ─── config('services.clicksign.painel_url') — sem regressão ───

    #[Test]
    public function config_painel_url_continua_resolvendo_para_o_teste_por_padrao(): void
    {
        $this->assertSame(
            ClicksignAmbiente::PAINEL_SANDBOX,
            config('services.clicksign.painel_url'),
        );
    }
}
