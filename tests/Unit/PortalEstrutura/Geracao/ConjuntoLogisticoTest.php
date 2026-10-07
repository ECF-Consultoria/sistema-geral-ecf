<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\ConjuntoLogistico;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use Tests\TestCase;

class ConjuntoLogisticoTest extends TestCase
{
    private function vol(float $c, float $l, float $a, float $kg): array
    {
        return ['c' => $c, 'l' => $l, 'a' => $a, 'kg' => $kg];
    }

    private function item(int $id, string $nome, int $qtd, array $volumes, ?float $custo = null): array
    {
        return ['produto_id' => $id, 'produto_nome' => $nome, 'quantidade' => $qtd, 'volumes' => $volumes, 'custo' => $custo];
    }

    public function test_volumes_repetidos_pela_quantidade(): void
    {
        $v1 = $this->vol(10, 10, 10, 1);
        $v2 = $this->vol(20, 20, 20, 2);

        $volumes = ConjuntoLogistico::volumes([
            $this->item(1, 'A', 1, [$v1]),
            $this->item(2, 'B', 4, [$v2]),
        ]);

        $this->assertCount(5, $volumes);
        $this->assertCount(1, array_keys($volumes, $v1, true));
        $this->assertCount(4, array_keys($volumes, $v2, true));
    }

    public function test_item_de_duas_caixas_vezes_tres(): void
    {
        $volumes = ConjuntoLogistico::volumes([
            $this->item(1, 'A', 3, [$this->vol(10, 10, 10, 1), $this->vol(5, 5, 5, 1)]),
        ]);

        $this->assertCount(6, $volumes);
    }

    public function test_mesa_mais_quatro_cadeiras_igual_a_chamada_direta(): void
    {
        $mesa    = $this->vol(160, 90, 15, 40);
        $cadeira = $this->vol(50, 50, 20, 6);

        $r = ConjuntoLogistico::avaliar([
            $this->item(1, 'Mesa Polo', 1, [$mesa]),
            $this->item(2, 'Cadeira Polo', 4, [$cadeira]),
        ]);

        $direto = LogisticaProduto::daVolumes([$mesa, $cadeira, $cadeira, $cadeira, $cadeira]);

        $this->assertSame($direto + ['sem_medida' => []], $r);
        $this->assertSame(160.0, $r['pacote']['c']);
        $this->assertSame(90.0, $r['pacote']['l']);
        $this->assertSame(95.0, $r['pacote']['a']);
        $this->assertSame(64.0, $r['pacote']['peso_real']);
        $this->assertSame(LogisticaProduto::ME1, $r['logistica']);
    }

    public function test_conjunto_pequeno_igual_a_chamada_direta(): void
    {
        $cadeira = $this->vol(45, 45, 10, 3);

        $r = ConjuntoLogistico::avaliar([$this->item(2, 'Cadeira', 2, [$cadeira])]);

        $this->assertSame(LogisticaProduto::daVolumes([$cadeira, $cadeira]) + ['sem_medida' => []], $r);
        $this->assertContains($r['logistica'], [LogisticaProduto::ME2, LogisticaProduto::ME2_FULL]);
    }

    public function test_componente_sem_medida_deixa_pendente(): void
    {
        $r = ConjuntoLogistico::avaliar([
            $this->item(1, 'Mesa Polo', 1, [$this->vol(160, 90, 15, 40)]),
            $this->item(7, 'Cadeira Polo', 4, []),
        ]);

        $this->assertSame(LogisticaProduto::PENDENTE, $r['logistica']);
        $this->assertSame([['id' => 7, 'nome' => 'Cadeira Polo']], $r['sem_medida']);
    }

    public function test_custo_do_conjunto(): void
    {
        $this->assertSame(622.0, ConjuntoLogistico::custo([
            $this->item(1, 'A', 1, [], 300.0),
            $this->item(2, 'B', 4, [], 80.5),
        ]));

        $this->assertNull(ConjuntoLogistico::custo([
            $this->item(1, 'A', 1, [], 300.0),
            $this->item(2, 'B', 4, [], null),
        ]));
    }
}
