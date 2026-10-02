<?php

namespace Tests\Unit\Publicador\Variacao;

use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\GeradorCombinacoes;
use App\Support\Publicador\Variacao\ValorEixo;
use PHPUnit\Framework\TestCase;

/** `05` §2 — o usuário define eixos e valores; o sistema gera as combinações. */
class GeradorCombinacoesTest extends TestCase
{
    public static function cor(): Eixo
    {
        return new Eixo('COLOR', 'Cor', 0, definesPicture: true, valores: [
            new ValorEixo('52049', 'Preto'), new ValorEixo('52055', 'Branco'), new ValorEixo('52028', 'Azul'),
        ]);
    }

    public static function tamanho(): Eixo
    {
        return new Eixo('SIZE', 'Tamanho', 1, valores: [new ValorEixo(null, 'P'), new ValorEixo(null, 'M'), new ValorEixo(null, 'G')]);
    }

    public function test_sem_eixos_sai_uma_variante_unica(): void
    {
        $combos = GeradorCombinacoes::gerar([]);

        $this->assertCount(1, $combos);
        $this->assertSame(ChaveCanonica::UNICA, $combos[0]->chave);
        $this->assertSame('Único', $combos[0]->rotulo);
    }

    public function test_tc03_duas_dimensoes_na_ordem_com_o_primeiro_eixo_mais_lento(): void
    {
        $combos = GeradorCombinacoes::gerar([self::cor(), self::tamanho()]);

        $this->assertCount(9, $combos);
        $this->assertSame(
            ['Preto / P', 'Preto / M', 'Preto / G', 'Branco / P', 'Branco / M', 'Branco / G', 'Azul / P', 'Azul / M', 'Azul / G'],
            array_map(fn ($c) => $c->rotulo, $combos),
        );
        $this->assertSame('COLOR=id:52049|SIZE=txt:p', $combos[0]->chave);
        $this->assertCount(9, array_unique(array_map(fn ($c) => $c->chave, $combos)));
    }

    public function test_a_ordem_segue_a_posicao_dos_eixos_mas_a_chave_nao_muda(): void
    {
        $tamanhoPrimeiro = new Eixo('SIZE', 'Tamanho', 0, valores: self::tamanho()->valores);
        $corDepois = new Eixo('COLOR', 'Cor', 1, definesPicture: true, valores: self::cor()->valores);

        $combos = GeradorCombinacoes::gerar([$corDepois, $tamanhoPrimeiro]);

        $this->assertSame('P / Preto', $combos[0]->rotulo);
        $this->assertSame('COLOR=id:52049|SIZE=txt:p', $combos[0]->chave);
    }

    public function test_tc04_tres_dimensoes_com_eixo_customizado(): void
    {
        $cor = new Eixo('COLOR', 'Cor', 0, valores: [new ValorEixo('1', 'Preto'), new ValorEixo('2', 'Azul')]);
        $estampa = Eixo::customizado('Estampa', 2, [new ValorEixo(null, 'Lisa'), new ValorEixo(null, 'Listrada')]);

        $combos = GeradorCombinacoes::gerar([$cor, self::tamanho(), $estampa]);

        $this->assertCount(12, $combos);
        $this->assertSame('COLOR=id:1|SIZE=txt:p|~custom=txt:lisa', $combos[0]->chave);
        $this->assertSame('Preto / P / Lisa', $combos[0]->rotulo);
    }

    public function test_eixo_ainda_sem_valores_nao_zera_as_combinacoes(): void
    {
        $vazio = new Eixo('VOLTAGE', 'Voltagem', 2, valores: []);

        $this->assertCount(9, GeradorCombinacoes::gerar([self::cor(), self::tamanho(), $vazio]));
    }

    public function test_tc08_contar_antes_de_gerar(): void
    {
        $quinzeCores = new Eixo('COLOR', 'Cor', 0, valores: array_map(fn ($i) => new ValorEixo((string) $i, "Cor {$i}"), range(1, 15)));
        $oitoTamanhos = new Eixo('SIZE', 'Tamanho', 1, valores: array_map(fn ($i) => new ValorEixo(null, "T{$i}"), range(1, 8)));

        $this->assertSame(120, GeradorCombinacoes::contar([$quinzeCores, $oitoTamanhos]));
        $this->assertSame(1, GeradorCombinacoes::contar([]));
    }
}
