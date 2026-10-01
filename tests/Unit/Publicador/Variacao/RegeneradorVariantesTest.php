<?php

namespace Tests\Unit\Publicador\Variacao;

use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\GeradorCombinacoes;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use PHPUnit\Framework\TestCase;

/**
 * `05` §4 — quando eixos e valores mudam, o que acontece com o que a pessoa já
 * digitou. A regra de ouro: nada se perde em silêncio, e o que já foi
 * publicado nunca some.
 */
class RegeneradorVariantesTest extends TestCase
{
    private static function voltagem(array $nomes = ['127V', '220V']): Eixo
    {
        $ids = ['127V' => '39205162', '220V' => '198813'];

        return new Eixo('VOLTAGE', 'Voltagem', 0, valores: array_map(fn ($n) => new ValorEixo($ids[$n], $n), $nomes));
    }

    private static function cor(array $nomes, int $posicao = 1): Eixo
    {
        $ids = ['Preto' => '52049', 'Azul' => '52028', 'Branco' => '52055'];

        return new Eixo('COLOR', 'Cor', $posicao, definesPicture: true, valores: array_map(fn ($n) => new ValorEixo($ids[$n], $n), $nomes));
    }

    /** As variantes de hoje, com dados digitados em cada uma. */
    private static function comDados(array $eixos, array $dadosPorRotulo): array
    {
        return array_map(
            fn ($c) => Variante::daCombinacao($c, dados: $dadosPorRotulo[$c->rotulo] ?? []),
            GeradorCombinacoes::gerar($eixos),
        );
    }

    private static function porRotulo(array $variantes, array $eixos): array
    {
        $saida = [];
        foreach ($variantes as $v) {
            $saida[$v->rotulo($eixos)] = $v;
        }

        return $saida;
    }

    public function test_adicionar_valor_cria_variantes_vazias_e_mantem_as_existentes(): void
    {
        $atuais = self::comDados([self::cor(['Preto'], 0)], ['Preto' => ['preco' => 150, 'estoque' => 3]]);
        $eixos = [self::cor(['Preto', 'Azul'], 0)];

        $r = RegeneradorVariantes::regenerar($atuais, $eixos);
        $v = self::porRotulo($r->variantes, $eixos);

        $this->assertSame(['preco' => 150, 'estoque' => 3], $v['Preto']->dados);
        $this->assertSame([], $v['Azul']->dados);
        $this->assertTrue($v['Azul']->ativa);
        $this->assertSame([], $r->descartadas);
    }

    public function test_remover_valor_deixa_orfa_desativada_com_os_dados_e_readicionar_devolve(): void
    {
        $eixos = [self::cor(['Preto', 'Azul'], 0)];
        $atuais = self::comDados($eixos, ['Preto' => ['preco' => 150], 'Azul' => ['preco' => 165]]);

        $semAzul = RegeneradorVariantes::regenerar($atuais, [self::cor(['Preto'], 0)]);
        $azul = collect($semAzul->variantes)->firstWhere('chave', 'COLOR=id:52028');

        $this->assertTrue($azul->orfa);
        $this->assertFalse($azul->ativa);
        $this->assertSame(['preco' => 165], $azul->dados);
        $this->assertSame([], $semAzul->descartadas);

        $deVolta = RegeneradorVariantes::regenerar($semAzul->variantes, $eixos);
        $azul = collect($deVolta->variantes)->firstWhere('chave', 'COLOR=id:52028');
        $this->assertFalse($azul->orfa);
        $this->assertTrue($azul->ativa, 'readicionar o valor é pedir aquela variante de novo');
        $this->assertSame(['preco' => 165], $azul->dados);
        $this->assertCount(2, $deVolta->variantes);
    }

    public function test_tc05_combinacao_desativada_continua_desativada_e_com_dados(): void
    {
        $eixos = [self::cor(['Preto', 'Azul'], 0)];
        $atuais = array_map(
            fn ($v) => $v->rotulo($eixos) === 'Azul' ? $v->comAtiva(false) : $v,
            self::comDados($eixos, ['Azul' => ['estoque' => 4]]),
        );

        $r = RegeneradorVariantes::regenerar($atuais, [self::cor(['Preto', 'Azul', 'Branco'], 0)]);
        $azul = collect($r->variantes)->firstWhere('chave', 'COLOR=id:52028');

        $this->assertFalse($azul->ativa);
        $this->assertFalse($azul->orfa);
        $this->assertSame(['estoque' => 4], $azul->dados);
    }

    public function test_tc09_adicionar_eixo_copiando_os_dados_para_as_novas_que_contem_a_antiga(): void
    {
        $atuais = self::comDados([self::voltagem()], ['127V' => ['preco' => 199.9, 'estoque' => 5], '220V' => ['preco' => 209.9, 'estoque' => 2]]);
        $eixos = [self::voltagem(), self::cor(['Preto'])];

        $r = RegeneradorVariantes::regenerar($atuais, $eixos, copiarDados: true);
        $v = self::porRotulo($r->variantes, $eixos);

        $this->assertSame(['127V / Preto', '220V / Preto'], array_keys($v));
        $this->assertSame(['preco' => 199.9, 'estoque' => 5], $v['127V / Preto']->dados);
        $this->assertSame(['preco' => 209.9, 'estoque' => 2], $v['220V / Preto']->dados);
        // A antiga foi absorvida (os dados passaram adiante): não fica órfã pendurada.
        $this->assertEqualsCanonicalizing(['VOLTAGE=id:39205162', 'VOLTAGE=id:198813'], $r->descartadas);
    }

    public function test_adicionar_eixo_comecando_vazio(): void
    {
        $atuais = self::comDados([self::voltagem()], ['127V' => ['preco' => 199.9]]);
        $eixos = [self::voltagem(), self::cor(['Preto'])];

        $r = RegeneradorVariantes::regenerar($atuais, $eixos, copiarDados: false);

        $this->assertCount(2, $r->variantes);
        $this->assertSame([[], []], array_map(fn ($v) => $v->dados, $r->variantes));
        $this->assertCount(2, $r->descartadas);
    }

    public function test_produto_simples_que_ganha_o_primeiro_eixo_passa_os_dados_a_todas(): void
    {
        $atuais = [new Variante(ChaveCanonica::UNICA, [], dados: ['preco' => 150, 'sku' => 'CAD'])];
        $eixos = [self::cor(['Preto', 'Azul'], 0)];

        $r = RegeneradorVariantes::regenerar($atuais, $eixos);

        $this->assertSame([['preco' => 150, 'sku' => 'CAD'], ['preco' => 150, 'sku' => 'CAD']], array_map(fn ($v) => $v->dados, $r->variantes));
        $this->assertSame([ChaveCanonica::UNICA], $r->descartadas);
    }

    public function test_remover_eixo_sem_conflito_junta_as_variantes(): void
    {
        $eixos = [self::voltagem(), self::cor(['Preto'])];
        $atuais = self::comDados($eixos, ['127V / Preto' => ['preco' => 150], '220V / Preto' => ['preco' => 150]]);

        $r = RegeneradorVariantes::regenerar($atuais, [self::cor(['Preto'], 0)]);

        $this->assertCount(1, $r->variantes);
        $this->assertSame(['preco' => 150], $r->variantes[0]->dados);
        $this->assertSame([], $r->conflitos);
        $this->assertCount(2, $r->descartadas);
    }

    public function test_remover_eixo_com_dados_diferentes_vira_conflito_e_nada_e_somado(): void
    {
        $eixos = [self::voltagem(), self::cor(['Preto'])];
        $atuais = self::comDados($eixos, ['127V / Preto' => ['estoque' => 5], '220V / Preto' => ['estoque' => 2]]);

        $r = RegeneradorVariantes::regenerar($atuais, [self::cor(['Preto'], 0)]);
        $preto = collect($r->variantes)->firstWhere('chave', 'COLOR=id:52049');

        $this->assertSame([], $preto->dados, 'estoque não é somado automaticamente');
        $this->assertEqualsCanonicalizing(['COLOR=id:52049|VOLTAGE=id:39205162', 'COLOR=id:52049|VOLTAGE=id:198813'], $r->conflitos['COLOR=id:52049']);
        // As de origem ficam órfãs até a pessoa escolher — os dados não se perdem.
        $this->assertCount(2, array_filter($r->variantes, fn ($v) => $v->orfa));
        $this->assertSame([], $r->descartadas);
    }

    public function test_tc10_variante_publicada_nunca_e_descartada(): void
    {
        $eixos = [self::cor(['Preto', 'Azul'], 0)];
        $atuais = array_map(
            fn ($v) => $v->rotulo($eixos) === 'Azul' ? $v->comPublicada(true) : $v,
            self::comDados($eixos, ['Azul' => ['estoque' => 4]]),
        );

        $semAzul = RegeneradorVariantes::regenerar($atuais, [self::cor(['Preto'], 0)]);
        $azul = collect($semAzul->variantes)->firstWhere('chave', 'COLOR=id:52028');
        $this->assertTrue($azul->orfa);
        $this->assertTrue($azul->publicada);

        // Nem quando um eixo novo absorve as antigas.
        $comEixo = RegeneradorVariantes::regenerar($atuais, [self::cor(['Preto', 'Azul'], 0), new Eixo('SIZE', 'Tamanho', 1, valores: [new ValorEixo(null, 'P')])]);
        $this->assertNotContains('COLOR=id:52028', $comEixo->descartadas);
        $this->assertTrue(collect($comEixo->variantes)->firstWhere('chave', 'COLOR=id:52028')->orfa);
    }

    public function test_orfas_vao_para_o_fim_e_a_ordem_das_novas_e_a_do_gerador(): void
    {
        $eixos = [self::cor(['Preto', 'Azul', 'Branco'], 0)];
        $atuais = self::comDados($eixos, []);

        $r = RegeneradorVariantes::regenerar($atuais, [self::cor(['Branco', 'Preto'], 0)]);

        $this->assertSame(['Branco', 'Preto', 'Azul'], array_map(fn ($v) => $v->valores['COLOR']->valueName, $r->variantes));
        $this->assertTrue($r->variantes[2]->orfa);
    }
}
