<?php

namespace Tests\Unit\PortalEstrutura;

use App\Services\Portal\Estrutura\Produtos\PendenciasDoProduto;
use PHPUnit\Framework\TestCase;

class PendenciasDoProdutoTest extends TestCase
{
    private function completa(array $sobrescrever = []): array
    {
        return array_merge([
            'volumes'         => [['c' => 10, 'l' => 10, 'a' => 10, 'kg' => 1]],
            'custo'           => 50.0,
            'categoria_ml_id' => 'MLB1574',
            'familia'         => 'Sofá',
            'ambientes'       => ['Sala'],
            'logistica'       => 'me2',
        ], $sobrescrever);
    }

    public function test_linha_completa_nao_tem_pendencia(): void
    {
        $this->assertSame([], PendenciasDoProduto::daLinha($this->completa()));
    }

    public function test_sem_volumes_segue_a_ordem_fixa(): void
    {
        $r = PendenciasDoProduto::daLinha([
            'volumes' => [], 'custo' => null, 'categoria_ml_id' => null,
            'familia' => null, 'ambientes' => [], 'logistica' => 'pendente',
        ]);

        $this->assertSame(['medidas', 'custo', 'categoria', 'familia', 'ambiente'], $r);
    }

    public function test_peso_zero_e_peso_e_nao_medidas(): void
    {
        $r = PendenciasDoProduto::daLinha($this->completa([
            'volumes' => [['c' => 10, 'l' => 10, 'a' => 10, 'kg' => 0]],
        ]));

        $this->assertSame(['peso'], $r);
    }

    public function test_me1_acrescenta_frete_me1(): void
    {
        $this->assertSame(['frete_me1'], PendenciasDoProduto::daLinha($this->completa(['logistica' => 'me1'])));
    }

    public function test_categoria_a_confirmar_sem_id_conta_como_pendencia(): void
    {
        $this->assertSame(['categoria'], PendenciasDoProduto::daLinha($this->completa(['categoria_ml_id' => ''])));
    }

    public function test_rotulos_cobrem_toda_a_ordem(): void
    {
        foreach (PendenciasDoProduto::ORDEM as $codigo) {
            $this->assertArrayHasKey($codigo, PendenciasDoProduto::ROTULOS);
        }
        $this->assertSame('família', PendenciasDoProduto::ROTULOS['familia']);
    }
}
