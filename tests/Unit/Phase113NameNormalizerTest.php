<?php

namespace Tests\Unit;

use App\Services\Hubspot\HubspotNameNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Fase 113, Plano 01 — cobre a normalização de nome de empresa usada no
 * match fraco de dedup (HUB-DEDUP-02). Unidade pura, sem DB/HTTP — extends
 * TestCase puro (não RefreshDatabase) de propósito.
 *
 * Foco especial: proteção contra FALSO POSITIVO — nomes de empresas
 * distintas NUNCA podem colapsar na mesma string normalizada.
 */
class Phase113NameNormalizerTest extends TestCase
{
    public function test_variacoes_de_caixa_acento_e_pontuacao_do_mesmo_nome_normalizam_igual(): void
    {
        $a = HubspotNameNormalizer::normalizar('AÇAÍ E CIA');
        $b = HubspotNameNormalizer::normalizar('acai e cia');

        $this->assertSame($a, $b);
        $this->assertSame('acai e cia', $a);
    }

    public function test_falso_positivo_bloqueado_padaria_do_ze_e_padaria_da_ana_sao_diferentes(): void
    {
        $a = HubspotNameNormalizer::normalizar('Padaria do Zé');
        $b = HubspotNameNormalizer::normalizar('Padaria da Ana');

        $this->assertNotSame($a, $b);
    }

    public function test_falso_positivo_bloqueado_silva_ltda_e_silva_ltda_me_sao_diferentes(): void
    {
        $a = HubspotNameNormalizer::normalizar('Silva Ltda');
        $b = HubspotNameNormalizer::normalizar('Silva Ltda ME');

        $this->assertNotSame($a, $b);
    }

    public function test_caixa_acento_e_espacos_multiplos_colapsam_e_fazem_trim(): void
    {
        $this->assertSame('sao joao', HubspotNameNormalizer::normalizar('  São   João  '));
    }

    public function test_entrada_null_retorna_string_vazia(): void
    {
        $this->assertSame('', HubspotNameNormalizer::normalizar(null));
    }

    public function test_entrada_vazia_retorna_string_vazia(): void
    {
        $this->assertSame('', HubspotNameNormalizer::normalizar(''));
    }

    public function test_pontuacao_e_e_comercial_viram_espaco_e_colapsam(): void
    {
        // "&" e "." não são letras/dígitos, viram espaço e colapsam —
        // o "&" NÃO é traduzido para a palavra "e" (símbolo é descartado
        // como separador, não como token semântico).
        $this->assertSame('acai cia ltda', HubspotNameNormalizer::normalizar('Açaí & Cia. Ltda'));
    }
}
