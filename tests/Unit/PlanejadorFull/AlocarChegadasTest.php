<?php

namespace Tests\Unit\PlanejadorFull;

use App\Services\PlanejadorFull\FullColetaService;
use App\Services\PlanejadorFull\FullEnviosService;
use PHPUnit\Framework\TestCase;

/**
 * Distribuição das chegadas no FULL entre os envios registrados — é o que vira a
 * "Entrada pendente" que a API do ML não entrega. E a leitura das respostas da API
 * (estoque, operação de chegada, anúncio, pedido) com os formatos medidos em 15/10/2026.
 */
class AlocarChegadasTest extends TestCase
{
    private function destino(int $id, string $desde, int $quantidade, ?string $inbound = null): array
    {
        return ['id' => $id, 'desde' => $desde, 'inbound' => $inbound, 'quantidade' => $quantidade];
    }

    private function chegada(string $em, int $quantidade, ?string $inbound = null): array
    {
        return ['em' => $em, 'inbound' => $inbound, 'quantidade' => $quantidade];
    }

    public function test_chegada_com_numero_do_envio_vai_para_esse_envio_antes_do_mais_antigo(): void
    {
        $r = FullEnviosService::alocarChegadas(
            [$this->destino(1, '2026-10-01T03:00:00Z', 50), $this->destino(2, '2026-10-05T03:00:00Z', 30, '75714964')],
            [$this->chegada('2026-10-08T14:00:00Z', 20, '75714964')],
        );

        $this->assertSame([1 => 0, 2 => 20], $r);
    }

    public function test_sem_numero_o_envio_mais_antigo_recebe_primeiro_e_o_excedente_passa_ao_seguinte(): void
    {
        $r = FullEnviosService::alocarChegadas(
            [$this->destino(2, '2026-10-05T03:00:00Z', 30), $this->destino(1, '2026-10-01T03:00:00Z', 10)],
            [$this->chegada('2026-10-08T14:00:00Z', 25)],
        );

        $this->assertSame([1 => 10, 2 => 15], $r);
    }

    public function test_chegada_antes_do_inicio_da_contagem_nao_conta(): void
    {
        $r = FullEnviosService::alocarChegadas(
            [$this->destino(1, '2026-10-15T11:45:00Z', 100)],
            [$this->chegada('2026-10-15T10:00:00Z', 40), $this->chegada('2026-10-15T12:00:00Z', 5)],
        );

        $this->assertSame([1 => 5], $r);
    }

    public function test_recebido_nunca_passa_do_enviado_e_sobra_de_envio_nao_registrado_fica_de_fora(): void
    {
        $r = FullEnviosService::alocarChegadas(
            [$this->destino(1, '2026-10-01T03:00:00Z', 10)],
            [$this->chegada('2026-10-02T10:00:00Z', 8), $this->chegada('2026-10-03T10:00:00Z', 8)],
        );

        $this->assertSame([1 => 10], $r);
    }

    public function test_chegada_de_outro_envio_do_ml_nao_entra_no_envio_que_tem_numero(): void
    {
        $r = FullEnviosService::alocarChegadas(
            [$this->destino(1, '2026-10-01T03:00:00Z', 10, '111')],
            [$this->chegada('2026-10-02T10:00:00Z', 8, '222')],
        );

        $this->assertSame([1 => 0], $r);
    }

    public function test_le_operacao_de_chegada_somando_o_que_entrou_indisponivel(): void
    {
        $op = [
            'date_created' => '2026-10-08T14:10:38Z',
            'type' => 'INBOUND_RECEPTION',
            'detail' => ['available_quantity' => 5, 'not_available_detail' => [['status' => 'damaged', 'quantity' => 1]]],
            'external_references' => [['type' => 'inbound_id', 'value' => '75714964']],
        ];

        $this->assertSame(['em' => '2026-10-08T14:10:38Z', 'inbound' => '75714964', 'quantidade' => 6], FullEnviosService::lerChegada($op));
    }

    public function test_le_estoque_do_full_separando_transferencia_do_resto(): void
    {
        $lido = FullColetaService::lerEstoque([
            'inventory_id' => 'HHCQ98890', 'total' => 886, 'available_quantity' => 837, 'not_available_quantity' => 49,
            'not_available_detail' => [['status' => 'transfer', 'quantity' => 45], ['status' => 'lost', 'quantity' => 4]],
        ]);

        $this->assertSame(837, $lido['aptas']);
        $this->assertSame(45, $lido['transferencia']);
        $this->assertSame(4, $lido['indisponivel']);
    }

    public function test_anuncio_com_variacao_vira_um_codigo_ml_por_variacao_e_varios_anuncios_somam_no_mesmo_codigo(): void
    {
        $produtos = [];
        $mapa = [];

        FullColetaService::absorverAnuncio([
            'id' => 'MLB1', 'title' => 'Relógio Orient', 'status' => 'active', 'inventory_id' => null,
            'attributes' => [['id' => 'BRAND', 'value_name' => 'Orient']],
            'variations' => [
                ['id' => 11, 'inventory_id' => 'HHCQ98890', 'seller_custom_field' => 'SKU-AZ', 'available_quantity' => 5, 'attribute_combinations' => [['value_name' => 'Azul']]],
                ['id' => 12, 'inventory_id' => 'HHCQ98891', 'seller_custom_field' => null, 'available_quantity' => 0, 'attribute_combinations' => []],
            ],
        ], $produtos, $mapa);

        FullColetaService::absorverAnuncio([
            'id' => 'MLB2', 'title' => 'Relógio Orient (catálogo)', 'status' => 'closed', 'inventory_id' => 'HHCQ98890',
            'seller_custom_field' => 'SKU-AZ', 'available_quantity' => 5, 'variations' => [], 'attributes' => [],
        ], $produtos, $mapa);

        $this->assertSame(['HHCQ98890', 'HHCQ98891'], array_keys($produtos));
        $this->assertSame('Relógio Orient · Azul', $produtos['HHCQ98890']['titulo']);
        $this->assertSame(['MLB1', 'MLB2'], $produtos['HHCQ98890']['anuncios']);
        $this->assertSame('active', $produtos['HHCQ98890']['status']);
        $this->assertSame('Orient', $produtos['HHCQ98890']['marca']);
        $this->assertSame(['MLB1|11' => 'HHCQ98890', 'MLB1|12' => 'HHCQ98891', 'MLB2|' => 'HHCQ98890'], $mapa);
    }

    public function test_pedido_soma_por_codigo_ml_e_dia_e_ignora_anuncio_fora_do_full(): void
    {
        $mapa = ['MLB1|11' => 'HHCQ98890', 'MLB2|' => 'OKFW19104'];
        $soma = [];
        $skus = [];

        FullColetaService::somarPedido(['order_items' => [
            ['item' => ['id' => 'MLB1', 'variation_id' => 11, 'seller_sku' => 'SKU-AZ'], 'quantity' => 2, 'unit_price' => 100],
            ['item' => ['id' => 'MLB2', 'variation_id' => null, 'seller_sku' => 'W59'], 'quantity' => 1, 'unit_price' => 50.5],
            ['item' => ['id' => 'MLB9', 'variation_id' => null], 'quantity' => 7, 'unit_price' => 10],
        ]], $mapa, '2026-10-14', $soma, $skus);

        $this->assertSame(['unidades' => 2, 'faturamento' => 200.0], $soma['HHCQ98890#2026-10-14']);
        $this->assertSame(['unidades' => 1, 'faturamento' => 50.5], $soma['OKFW19104#2026-10-14']);
        $this->assertCount(2, $soma);
        $this->assertSame(['HHCQ98890' => 'SKU-AZ', 'OKFW19104' => 'W59'], $skus);
    }
}
