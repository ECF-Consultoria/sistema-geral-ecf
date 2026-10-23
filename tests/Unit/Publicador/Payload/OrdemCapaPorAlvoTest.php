<?php

namespace Tests\Unit\Publicador\Payload;

use App\Support\Publicador\Payload\OrdemCapaPorAlvo;
use PHPUnit\Framework\TestCase;

/**
 * A capa por alvo (D6, CAPA-01..04; refeita em 22/10/2026): a sequência é a MESMA no Clássico e no Premium; só a 1ª foto
 * do Premium muda, e só para uma ambientada — nunca fundo branco nem detalhe. O usuário viu, nos 6 produtos publicados na
 * #459 em 21/10, o Clássico em [ambientada, fundo branco, textura] e o Premium em [fundo branco, textura, ambientada]:
 * a rotação antiga girava a lista inteira.
 */
class OrdemCapaPorAlvoTest extends TestCase
{
    /** A galeria automática de móvel, com a 2ª ambientada (a capa do Premium) no fim. */
    private const MOVEL = ['L', 'H', 'T', 'C'];

    private const TIPOS = ['L' => 'lifestyle', 'H' => 'hero', 'T' => 'detail_textura', 'C' => 'composicao'];

    public function test_o_classico_fica_como_esta(): void
    {
        $this->assertSame(self::MOVEL, OrdemCapaPorAlvo::aplicar(self::MOVEL, 0, self::TIPOS));
    }

    public function test_o_premium_troca_so_a_capa_com_a_segunda_ambientada(): void
    {
        $premium = OrdemCapaPorAlvo::aplicar(self::MOVEL, 1, self::TIPOS);

        $this->assertSame(['C', 'H', 'T', 'L'], $premium);
        $this->assertSame(array_slice(self::MOVEL, 1, 2), array_slice($premium, 1, 2), 'o meio da sequência é o mesmo do Clássico');
    }

    public function test_sem_a_segunda_ambientada_o_premium_nunca_abre_com_fundo_branco_nem_textura(): void
    {
        // Móvel de antes da 4ª imagem: a única ambientada já é a capa — o Premium fica igual ao Clássico.
        $this->assertSame(['L', 'H', 'T'], OrdemCapaPorAlvo::aplicar(['L', 'H', 'T'], 1, self::TIPOS));
        // A cor com 2 fotos [ambientada, textura] (a POL-CON abria o Premium com a textura).
        $this->assertSame(['L', 'T'], OrdemCapaPorAlvo::aplicar(['L', 'T'], 1, self::TIPOS));
        // Fora de móvel a capa do Clássico é o fundo branco: o Premium abre com a ambientada, e só as duas trocam.
        $this->assertSame(['L', 'H', 'T'], OrdemCapaPorAlvo::aplicar(['H', 'L', 'T'], 1, self::TIPOS));
    }

    public function test_a_segunda_ambientada_e_a_preferida_mesmo_com_outra_ambientada_antes(): void
    {
        $this->assertSame(['C', 'L', 'T', 'H'], OrdemCapaPorAlvo::aplicar(['H', 'L', 'T', 'C'], 1, self::TIPOS));
    }

    public function test_foto_do_cliente_sem_tipo_nao_vira_capa_do_premium(): void
    {
        $this->assertSame(['a1', 'a2'], OrdemCapaPorAlvo::aplicar(['a1', 'a2'], 1));
        $this->assertSame(['a1', 'a2'], OrdemCapaPorAlvo::aplicar(['a1', 'a2'], 1, ['a2' => 'hero']));
    }

    public function test_uma_foto_ou_nenhuma_nao_muda(): void
    {
        $this->assertSame(['L'], OrdemCapaPorAlvo::aplicar(['L'], 1, self::TIPOS));
        $this->assertSame([], OrdemCapaPorAlvo::aplicar([], 1, self::TIPOS));
    }

    public function test_nunca_duplica_nem_descarta_foto(): void
    {
        foreach ([self::MOVEL, ['H', 'L', 'T'], ['L', 'T'], ['L'], []] as $fotos) {
            foreach ([0, 1, 2, 3] as $indice) {
                $this->assertEqualsCanonicalizing($fotos, OrdemCapaPorAlvo::aplicar($fotos, $indice, self::TIPOS));
            }
        }
    }

    public function test_e_deterministica(): void
    {
        $this->assertSame(OrdemCapaPorAlvo::aplicar(self::MOVEL, 1, self::TIPOS), OrdemCapaPorAlvo::aplicar(self::MOVEL, 1, self::TIPOS));
    }
}
