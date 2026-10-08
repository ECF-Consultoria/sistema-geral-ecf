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

            // 08/10 — banheiro e núcleo do nome (a 1ª palavra-tipo do nome vence; empate, a mais longa).
            'gabinete com armário e nichos' => [null, 'Gabinete Armário Banheiro com Nichos', 'gabinete', 'nome'],
            'espelho com prateleira'        => [null, 'Espelho Redondo com Prateleira 70cm', 'espelho', 'nome'],
            'lixeira'                       => [null, 'Lixeira 8L Nuvem Minimal', 'lixeira', 'nome'],
            'armário de banheiro é gabinete' => [null, 'Armário de Banheiro Suspenso', 'gabinete', 'nome'],
            'armário solto segue armário'   => [null, 'Armário Multiuso 2 Portas', 'armario', 'nome'],
            'categoria gabinetes'           => ['Gabinetes', 'Qualquer', 'gabinete', 'categoria'],
            'categoria espelhos'            => ['Espelhos', 'Qualquer', 'espelho', 'categoria'],
            'categoria lixeiras'            => ['Lixeiras', 'Qualquer', 'lixeira', 'categoria'],
            'categoria sem tipo cai p/ nome' => ['Banheiro', 'Gabinete Ripado Nature', 'gabinete', 'nome'],
            'acessório pelo nome'           => [null, 'Porta Escova de Dentes Inox', 'acessorio-banheiro', 'nome'],
            'toalheiro plural'              => [null, 'Porta Toalhas de Parede', 'toalheiro', 'nome'],
            'penteadeira com espelho'       => [null, 'Penteadeira com Espelho', 'penteadeira', 'nome'],
            'cômoda com espelho'            => [null, 'Cômoda 4 Gavetas com Espelho', 'comoda', 'nome'],
            'trecho longo vence na posição' => [null, 'Mesa de Centro com Nicho', 'mesa-centro', 'nome'],
        ];
    }

    #[DataProvider('casos')]
    public function test_inferencia(?string $categoria, ?string $nome, ?string $slug, ?string $fonte): void
    {
        $r = TipoDoProduto::inferir($categoria, $nome, $this->tipos());

        $this->assertSame($slug, $r['slug']);
        $this->assertSame($fonte, $r['fonte']);
    }

    /** 08/10: categoria ambígua não decide; cai para o nome, que resolve pelo núcleo. */
    public function test_ambiguo_na_categoria_cai_para_o_nome(): void
    {
        $r = TipoDoProduto::inferir('Bancos e Banquetas', 'Cadeira Alta', $this->tipos());

        $this->assertSame('cadeira', $r['slug']);
        $this->assertSame(['cadeira'], $r['candidatos']);
        $this->assertSame('nome', $r['fonte']);
    }

    public function test_ambiguo_na_categoria_e_nome_sem_tipo_fica_sem_tipo_com_os_candidatos_da_categoria(): void
    {
        $r = TipoDoProduto::inferir('Bancos e Banquetas', 'Assento Alto Industrial', $this->tipos());

        $this->assertNull($r['slug']);
        $this->assertSame(['banqueta', 'banco'], $r['candidatos']);
        $this->assertSame('categoria', $r['fonte']);
    }

    public function test_nome_com_dois_tipos_guarda_os_dois_candidatos(): void
    {
        $r = TipoDoProduto::inferir(null, 'Gabinete Armário Banheiro com Nichos', $this->tipos());

        $this->assertSame('gabinete', $r['slug']);
        $this->assertSame(['nicho', 'gabinete'], $r['candidatos']);
    }

    /** Antes de 08/10 os três caíam em "Sem tipo" (teste do usuário). */
    public function test_o_banheiro_do_teste_do_usuario_tem_tipo(): void
    {
        $tipos = $this->tipos();

        $this->assertSame('gabinete', TipoDoProduto::inferir(null, 'Gabinete Banheiro 80cm Ripado Nature com Nichos', $tipos)['slug']);
        $this->assertSame('espelho', TipoDoProduto::inferir(null, 'Espelho Banheiro Ripado Nature com Prateleira', $tipos)['slug']);
        $this->assertSame('lixeira', TipoDoProduto::inferir(null, 'Lixeira Banheiro Ripado Nature', $tipos)['slug']);
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
