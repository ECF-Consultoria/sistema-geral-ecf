<?php

namespace Tests\Unit\Portal\Estrutura\MercadoLivre;

use App\Services\Portal\Estrutura\Produtos\MercadoLivre\DiagnosticoDoAnuncio;
use PHPUnit\Framework\TestCase;

/** O diagnóstico que diz por que um anúncio vale ser trazido para Produtos (28/10/2026). */
class DiagnosticoDoAnuncioTest extends TestCase
{
    private function campos(): array
    {
        return [
            'BRAND'    => ['id' => 'BRAND', 'nome' => 'Marca', 'obrigatorio' => true],
            'MATERIAL' => ['id' => 'MATERIAL', 'nome' => 'Material', 'obrigatorio' => true],
            'COLOR'    => ['id' => 'COLOR', 'nome' => 'Cor', 'obrigatorio' => false],
            'STYLE'    => ['id' => 'STYLE', 'nome' => 'Estilo', 'obrigatorio' => false],
            'HEIGHT'   => ['id' => 'HEIGHT', 'nome' => 'Altura', 'obrigatorio' => false],
        ];
    }

    private function caixa(): array
    {
        return array_map(fn ($id) => ['id' => $id, 'value_name' => '10 cm'], ['SELLER_PACKAGE_LENGTH', 'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WEIGHT']);
    }

    public function test_anuncio_fraco_no_cadastro_e_nos_numeros_e_prioridade_alta(): void
    {
        $item = [
            'title' => 'Mesa Lateral',
            'attributes' => [['id' => 'BRAND', 'value_name' => 'Casa'], ...$this->caixa()],
            'pictures' => [['id' => 'a'], ['id' => 'b']],
        ];

        $d = DiagnosticoDoAnuncio::avaliar($item, $this->campos(), ['vendas_30d' => 0, 'visitas_30d' => 40], 60);

        $this->assertSame(['texto' => 'Mesa Lateral', 'chars' => 12, 'max' => 60, 'curto' => true], $d['titulo']);
        $this->assertTrue($d['modelo']['vazio']);
        $this->assertSame(['preenchidos' => 1, 'total' => 5, 'obrigatorios_faltando' => ['Material']], $d['campos']);
        $this->assertSame(['produto' => false, 'caixa' => true], $d['medidas']);
        $this->assertSame(['titulo_curto', 'sem_modelo', 'obrigatorio_faltando', 'ficha_incompleta', 'sem_medidas_produto', 'pouca_visita', 'sem_venda'],
            array_column($d['problemas'], 'chave'));
        $this->assertSame('alta', $d['prioridade']);
        $this->assertSame(2, $d['fotos']);
    }

    public function test_modelo_no_padrao_ecf_e_ficha_cheia_sem_problema_de_cadastro(): void
    {
        $item = [
            'title' => 'Mesa Lateral Redonda Madeira Maciça Pés Palito Sala Quarto',
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'Casa'], ['id' => 'MATERIAL', 'value_name' => 'Madeira'],
                ['id' => 'COLOR', 'value_name' => 'Natural'], ['id' => 'STYLE', 'value_name' => 'Moderno'],
                ['id' => 'HEIGHT', 'value_name' => '55 cm'],
                ['id' => 'MODEL', 'value_name' => 'mesa de apoio, mesinha lateral, mesa canto sofa, mesa cabeceira'],
                ...$this->caixa(),
            ],
        ];

        $d = DiagnosticoDoAnuncio::avaliar($item, $this->campos(), ['vendas_30d' => 12, 'visitas_30d' => 600], 60);

        $this->assertSame([], $d['problemas']);
        $this->assertSame('baixa', $d['prioridade']);
        $this->assertSame(2.0, $d['metricas']['conversao']);
    }

    public function test_modelo_curto_sem_virgula_e_fora_do_padrao_e_muita_visita_pouca_venda(): void
    {
        $item = [
            'title' => 'Mesa Lateral Redonda Madeira Maciça Pés Palito Sala Quarto',
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'Casa'], ['id' => 'MATERIAL', 'value_name' => 'Madeira'],
                ['id' => 'COLOR', 'value_name' => 'Natural'], ['id' => 'HEIGHT', 'value_name' => '55 cm'],
                ['id' => 'MODEL', 'value_name' => 'ML-55'], ...$this->caixa(),
            ],
        ];

        $d = DiagnosticoDoAnuncio::avaliar($item, $this->campos(), ['vendas_30d' => 2, 'visitas_30d' => 900], 60);

        $this->assertTrue($d['modelo']['fora_do_padrao']);
        $this->assertSame(['modelo_fora_do_padrao', 'visita_sem_venda'], array_column($d['problemas'], 'chave'));
        $this->assertSame('alta', $d['prioridade'], 'um de cadastro com um de desempenho');
    }

    public function test_anuncio_pausado_ou_encerrado_nao_tem_problema_de_desempenho(): void
    {
        // Pausado não recebe visita nem venda: isso não diz nada do cadastro. Os números ficam, o "problema" não.
        $item = ['status' => 'paused', 'title' => 'Mesa Lateral Redonda Madeira Maciça Pés Palito Sala Quarto',
            'attributes' => [['id' => 'MODEL', 'value_name' => 'a, b'], ...$this->caixa()]];

        $d = DiagnosticoDoAnuncio::avaliar($item, [], ['vendas_30d' => 0, 'visitas_30d' => 3]);

        $this->assertSame([], $d['problemas']);
        $this->assertSame(['vendas_30d' => 0, 'visitas_30d' => 3, 'conversao' => 0.0], $d['metricas']);
    }

    public function test_metricas_ainda_nao_lidas_nao_viram_problema(): void
    {
        $item = ['title' => 'Mesa Lateral Redonda Madeira Maciça Pés Palito Sala Quarto', 'attributes' => [['id' => 'MODEL', 'value_name' => 'a, b']]];

        $d = DiagnosticoDoAnuncio::avaliar($item, [], ['vendas_30d' => null, 'visitas_30d' => null]);

        $this->assertSame(['vendas_30d' => null, 'visitas_30d' => null, 'conversao' => null], $d['metricas']);
        $this->assertSame(['sem_caixa'], array_column($d['problemas'], 'chave'));
        $this->assertSame(60, $d['titulo']['max'], 'sem o limite da categoria, 60');
    }
}
