<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\TipoDoProduto;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TipoDoProdutoTest extends TestCase
{
    private function tipos(): array
    {
        $saida = [];
        foreach (config('estrutura_geracao.tipos') as $slug => $t) {
            $saida[$slug] = ['palavras' => $t['palavras'], 'ordem' => $t['ordem']];
        }

        return $saida;
    }

    public static function casos(): array
    {
        return [
            'categoria vence o nome'        => ['Cadeiras', 'Qualquer', 'cadeira', 'categoria'],
            'mesa de jantar'                => ['Mesas de Jantar', null, 'mesa', 'categoria'],
            'frase mais longa: mesa centro' => ['Mesas de Centro', null, 'mesa-centro', 'categoria'],
            'mesa de cabeceira'             => ['Mesa de Cabeceira', null, 'criado-mudo', 'categoria'],
            'cai para o nome'               => [null, 'Cadeira Farmhouse', 'cadeira', 'nome'],
            'categoria vazia'               => ['', 'Banqueta Alta', 'banqueta', 'nome'],
            'plural em is'                  => ['Painéis', null, 'painel', 'categoria'],
            'mesas laterais'                => ['Mesas Laterais', null, 'mesa-lateral', 'categoria'],
            'aparadores'                    => ['Aparadores', null, 'aparador', 'categoria'],
            'sem tipo'                      => ['Decoração', 'Peça Decorativa', null, null],
            'mesada não é mesa'             => ['Mesada Infantil', null, null, null],
            'camada não é cama'             => ['Camada Dupla', null, null, null],
            'bancada não é banco'           => ['Bancada', null, null, null],
            // Limite conhecido: diminutivo fica sem tipo; a pessoa escolhe no painel "Sem tipo".
            'diminutivo sem tipo'           => ['Cadeirinha', null, null, null],
        ];
    }

    #[DataProvider('casos')]
    public function test_inferencia(?string $categoria, ?string $nome, ?string $slug, ?string $fonte): void
    {
        $r = TipoDoProduto::inferir($categoria, $nome, $this->tipos());

        $this->assertSame($slug, $r['slug']);
        $this->assertSame($fonte, $r['fonte']);
    }

    public function test_ambiguo_na_categoria_nao_cai_para_o_nome(): void
    {
        $r = TipoDoProduto::inferir('Bancos e Banquetas', 'Cadeira Alta', $this->tipos());

        $this->assertNull($r['slug']);
        $this->assertSame(['banqueta', 'banco'], $r['candidatos']);
        $this->assertSame('categoria', $r['fonte']);
    }

    public function test_efetivo(): void
    {
        $tipos = $this->tipos();
        $mesa  = ['slug' => 'mesa', 'candidatos' => ['mesa'], 'fonte' => 'categoria'];
        $nulo  = ['slug' => null, 'candidatos' => ['banqueta', 'banco'], 'fonte' => 'categoria'];

        $this->assertSame(
            ['slug' => 'cadeira', 'origem' => 'escolhido', 'candidatos' => ['mesa']],
            TipoDoProduto::efetivo('cadeira', $mesa, $tipos)
        );
        $this->assertSame(
            ['slug' => 'mesa', 'origem' => 'inferido', 'candidatos' => ['mesa']],
            TipoDoProduto::efetivo('inexistente', $mesa, $tipos)
        );
        $this->assertSame(
            ['slug' => null, 'origem' => null, 'candidatos' => ['banqueta', 'banco']],
            TipoDoProduto::efetivo(null, $nulo, $tipos)
        );
    }

    public function test_palavras(): void
    {
        $this->assertSame(
            ['criado mudo', 'mesa de cabeceira'],
            TipoDoProduto::palavras('Criado-mudo, mesa de cabeceira, , criado mudo')
        );
    }

    public function test_normalizar(): void
    {
        $this->assertSame('comoda de madeira', TipoDoProduto::normalizar('  Cômoda,  de-Madeira '));
        $this->assertSame('', TipoDoProduto::normalizar(null));
    }
}
