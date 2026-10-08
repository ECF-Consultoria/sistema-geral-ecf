<?php

namespace Tests\Unit\Publicador\Portal;

use App\Support\Publicador\Portal\CoresDoGrupo;
use PHPUnit\Framework\TestCase;

/** Review 172 WR-09: uma regra só decide quem é cor do grupo e quem vira produto separado. */
class CoresDoGrupoTest extends TestCase
{
    private static function v(int $id, ?string $valor, ?string $eixo = 'cor'): array
    {
        return ['id' => $id, 'eixo' => $eixo, 'valor' => $valor, 'codigo' => 'SKU-'.$id];
    }

    public function test_sem_valor_outro_eixo_e_repetida_ficam_fora_com_o_motivo(): void
    {
        $r = CoresDoGrupo::separar([self::v(1, 'Azul'), self::v(2, ''), self::v(3, 'P', 'tamanho'), self::v(4, 'azul'), self::v(5, 'Preto')]);

        $this->assertSame([1, 5], $r['agrupaveis']);
        $this->assertSame([2, 3, 4], array_keys($r['fora']));
        $this->assertStringContainsString('SKU-2 está sem valor', $r['fora'][2]);
        $this->assertStringContainsString('"P" usa outro tipo', $r['fora'][3]);
        $this->assertStringContainsString('"azul" está repetida', $r['fora'][4]);
    }

    public function test_produto_de_uma_variacao_entra_mesmo_sem_valor(): void
    {
        $this->assertSame(['agrupaveis' => [7], 'fora' => []], CoresDoGrupo::separar([self::v(7, '')]));
    }

    public function test_varias_variacoes_sem_valor_viram_todas_produtos_separados(): void
    {
        $r = CoresDoGrupo::separar([self::v(1, null), self::v(2, '  ')]);

        $this->assertSame([], $r['agrupaveis']);
        $this->assertCount(2, $r['fora']);
    }
}
