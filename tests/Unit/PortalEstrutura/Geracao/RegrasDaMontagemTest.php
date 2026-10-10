<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\NomesSugeridos;
use App\Services\Portal\Estrutura\Geracao\RegrasDaMontagem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * As regras puras do "Montar kit" (09/10/2026): fase pela composição (a regra da Lista SKUs),
 * nome e SKU pelo padrão do Planejamento, ordem dos itens e "Terá estoque?".
 */
class RegrasDaMontagemTest extends TestCase
{
    public static function fases(): array
    {
        return [
            'nada'                    => [[], null],
            'um item, uma unidade'    => [[1], null],
            'um item, várias'         => [[4], 'combo'],
            'dois itens ×1'           => [[1, 1], 'kit'],
            'dois, um repetido'       => [[1, 4], 'combit'],
            'dois repetidos'          => [[2, 2], 'combit'],
            'seis itens ×1'           => [[1, 1, 1, 1, 1, 1], 'kit'],
            'três, um repetido'       => [[3, 1, 1], 'combit'],
        ];
    }

    #[DataProvider('fases')]
    public function test_a_fase_sai_da_composicao(array $quantidades, ?string $fase): void
    {
        $this->assertSame($fase, RegrasDaMontagem::fase($quantidades));
        $this->assertSame($fase === null, RegrasDaMontagem::mensagem($quantidades) !== null);
    }

    public function test_a_ordem_e_a_do_gerador_e_os_sem_tipo_vao_no_fim_na_ordem_da_escolha(): void
    {
        $itens = [
            ['sku' => 'SEM-1', 'tipo_ordem' => null, 'produto_id' => null],
            ['sku' => 'CAD', 'tipo_ordem' => 70, 'produto_id' => 2],
            ['sku' => 'SEM-2', 'tipo_ordem' => null, 'produto_id' => null],
            ['sku' => 'MESA', 'tipo_ordem' => 10, 'produto_id' => 9],
            ['sku' => 'CAD-B', 'tipo_ordem' => 70, 'produto_id' => 1],
        ];

        $this->assertSame(['MESA', 'CAD-B', 'CAD', 'SEM-1', 'SEM-2'], array_column(RegrasDaMontagem::ordenar($itens), 'sku'));
    }

    private static function item(string $nome, string $sku, int $q, ?string $valor = null, ?string $tipo = null, ?string $plural = null): array
    {
        return ['produto_nome' => $nome, 'sku' => $sku, 'valor' => $valor, 'quantidade' => $q, 'tipo_nome' => $tipo, 'tipo_plural' => $plural];
    }

    public function test_nome_e_sku_pelo_padrao_do_planejamento(): void
    {
        $mesa = self::item('Mesa Polo', 'MESA', 1, 'Natural', 'Mesa', 'Mesas');
        $cadeira = fn (int $q) => self::item('Cadeira Polo', 'CAD', $q, 'Natural', 'Cadeira', 'Cadeiras');
        $banco = fn (int $q) => self::item('Banco Polo', 'BANCO', $q, null, 'Banco', 'Bancos');

        // Combo, Kit e Combit de 2: as MESMAS funções das sugestões.
        $this->assertSame(NomesSugeridos::combo('Cadeira Polo', 'CAD', 'Natural', 4, ['nome' => 'Cadeira', 'plural' => 'Cadeiras']), RegrasDaMontagem::nomeado([$cadeira(4)]));
        $this->assertSame(['nome' => 'Combo 4 Cadeiras Polo — Natural', 'sku' => 'CAD-CB4'], RegrasDaMontagem::nomeado([$cadeira(4)]));
        $this->assertSame(['nome' => 'Mesa Polo + Cadeira Polo — Natural', 'sku' => 'KT-MESA-CAD'], RegrasDaMontagem::nomeado([$mesa, $cadeira(1)]));
        $this->assertSame(['nome' => 'Mesa Polo + 4 Cadeiras — Natural', 'sku' => 'CT4-MESA-CAD'], RegrasDaMontagem::nomeado([$mesa, $cadeira(4)]));
        $this->assertSame(['nome' => 'Mesa Polo + 4 Cadeiras — Natural', 'sku' => 'CT4-MESA-CAD'], RegrasDaMontagem::nomeado([$cadeira(4), $mesa]), 'o fixo vem antes no nome');

        // Kit de 3+ e Combit de 3+ (ou com dois repetidos).
        $this->assertSame(['nome' => 'Mesa Polo + Cadeira Polo + Banco Polo — Natural', 'sku' => 'KT-MESA-CAD-BANCO'], RegrasDaMontagem::nomeado([$mesa, $cadeira(1), $banco(1)]));
        $this->assertSame(['nome' => 'Mesa Polo + 4 Cadeiras + 2 Bancos — Natural', 'sku' => 'CT-MESA-CADx4-BANCOx2'], RegrasDaMontagem::nomeado([$mesa, $cadeira(4), $banco(2)]));
        $this->assertSame(['nome' => '2 Cadeiras + 2 Bancos — Natural', 'sku' => 'CT-CADx2-BANCOx2'], RegrasDaMontagem::nomeado([$cadeira(2), $banco(2)]));

        // Sem tipo (oferta importada): o nome do produto no lugar do plural.
        $semTipo = self::item('Banco Antigo', 'AV-B', 2);
        $this->assertSame(['nome' => 'Mesa Polo + 2 Banco Antigo — Natural', 'sku' => 'CT2-MESA-AV-B'], RegrasDaMontagem::nomeado([$mesa, $semTipo]));
        $this->assertSame(['nome' => 'Combo 3 Banco Antigo', 'sku' => 'AV-B-CB3'], RegrasDaMontagem::nomeado([self::item('Banco Antigo', 'AV-B', 3)]));

        $this->assertNull(RegrasDaMontagem::nomeado([$mesa]));
        $this->assertNull(RegrasDaMontagem::nomeado([]));
    }

    public static function estoques(): array
    {
        $i = fn (string $nome, int $q, ?int $e) => ['nome' => $nome, 'quantidade' => $q, 'estoque' => $e];

        return [
            'o menor manda'                  => [[$i('Mesa', 1, 3), $i('Cadeira', 4, 10)], 2, 'Cadeira', []],
            'estoque que não dá uma unidade' => [[$i('Mesa', 1, 3), $i('Cadeira', 4, 3)], 0, 'Cadeira', []],
            'não informado deixa em aberto'  => [[$i('Mesa', 1, 3), $i('Cadeira', 4, null)], null, null, ['Cadeira']],
            'zero decide mesmo sem o outro'  => [[$i('Mesa', 1, 0), $i('Cadeira', 4, null)], 0, 'Mesa', ['Cadeira']],
            'combo'                          => [[$i('Cadeira', 6, 25)], 4, 'Cadeira', []],
            'nada escolhido'                 => [[], null, null, []],
        ];
    }

    #[DataProvider('estoques')]
    public function test_tera_estoque(array $itens, ?int $unidades, ?string $limitante, array $semInformacao): void
    {
        $r = RegrasDaMontagem::estoque($itens);

        $this->assertSame($unidades, $r['unidades']);
        $this->assertSame($limitante, $r['limitante']['nome'] ?? null);
        $this->assertSame($semInformacao, $r['sem_informacao']);
    }

    public function test_combit_de_varios_do_nomes_sugeridos(): void
    {
        $this->assertSame(
            ['nome' => 'Mesa + 6 Cadeiras + 2 Bancos — Freijó / Preto', 'sku' => 'CT-M1-C1x6-B1x2'],
            NomesSugeridos::combitDeVarios([
                ['produto_nome' => 'Mesa', 'sku' => 'M1', 'valor' => 'Freijó', 'quantidade' => 1],
                ['produto_nome' => 'Cadeira', 'sku' => 'C1', 'valor' => 'Preto', 'quantidade' => 6, 'plural' => 'Cadeiras'],
                ['produto_nome' => 'Banco', 'sku' => 'B1', 'valor' => 'Preto', 'quantidade' => 2, 'plural' => 'Bancos'],
            ])
        );
    }
}
