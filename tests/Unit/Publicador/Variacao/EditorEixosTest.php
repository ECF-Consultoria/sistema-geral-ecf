<?php

namespace Tests\Unit\Publicador\Variacao;

use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Variacao\EditorEixos;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\ValorEixo;
use PHPUnit\Framework\TestCase;

/** `05` §2–3 e §10 — o que pode virar eixo e que valor um eixo aceita. */
class EditorEixosTest extends TestCase
{
    /** Atributos da categoria no formato que o classificador (F1.2) entrega. */
    private const CATALOGO = [
        'COLOR' => ['id' => 'COLOR', 'name' => 'Cor', 'allow_variations' => true, 'defines_picture' => true, 'aceita_texto_livre' => true,
            'values' => [['id' => '52049', 'name' => 'Preto'], ['id' => '52028', 'name' => 'Azul']]],
        'SIZE' => ['id' => 'SIZE', 'name' => 'Tamanho', 'allow_variations' => true, 'aceita_texto_livre' => true, 'values' => []],
        'VOLTAGE' => ['id' => 'VOLTAGE', 'name' => 'Voltagem', 'allow_variations' => true, 'aceita_texto_livre' => false,
            'values' => [['id' => '39205162', 'name' => '127V'], ['id' => '198813', 'name' => '220V']]],
        'FREQUENCY' => ['id' => 'FREQUENCY', 'name' => 'Frequência', 'allow_variations' => true, 'aceita_texto_livre' => true, 'values' => []],
        'BRAND' => ['id' => 'BRAND', 'name' => 'Marca', 'allow_variations' => false, 'values' => []],
    ];

    private function violacao(callable $acao): RegraViolada
    {
        try {
            $acao();
        } catch (RegraViolada $e) {
            return $e;
        }
        $this->fail('Esperava RegraViolada');
    }

    public function test_eixo_so_entre_atributos_que_aceitam_variacao(): void
    {
        $editor = new EditorEixos();

        $eixos = $editor->adicionarEixo([], 'COLOR', self::CATALOGO);
        $this->assertSame('COLOR', $eixos[0]->chave);
        $this->assertTrue($eixos[0]->definesPicture);
        $this->assertSame(0, $eixos[0]->posicao);

        $this->assertSame('V-VAR-01', $this->violacao(fn () => $editor->adicionarEixo([], 'BRAND', self::CATALOGO))->regra);
        $this->assertSame('V-VAR-01', $this->violacao(fn () => $editor->adicionarEixo([], 'INEXISTENTE', self::CATALOGO))->regra);
        $this->assertSame('V-VAR-01', $this->violacao(fn () => $editor->adicionarEixo($eixos, 'COLOR', self::CATALOGO))->regra);
    }

    public function test_limite_de_eixos_e_configuravel(): void
    {
        $editor = new EditorEixos(maxEixos: 2);
        $eixos = $editor->adicionarEixo($editor->adicionarEixo([], 'COLOR', self::CATALOGO), 'SIZE', self::CATALOGO);

        $this->assertSame('H-17', $this->violacao(fn () => $editor->adicionarEixo($eixos, 'VOLTAGE', self::CATALOGO))->regra);
    }

    public function test_tc11_um_eixo_customizado_no_maximo(): void
    {
        $editor = new EditorEixos();
        $eixos = $editor->adicionarEixoCustomizado([], 'Estampa', self::CATALOGO);

        $this->assertTrue($eixos[0]->ehCustomizado());
        $this->assertSame('V-VAR-02', $this->violacao(fn () => $editor->adicionarEixoCustomizado($eixos, 'Acabamento', self::CATALOGO))->regra);
    }

    public function test_tc12_customizado_com_nome_de_atributo_da_categoria_sugere_o_atributo(): void
    {
        $e = $this->violacao(fn () => (new EditorEixos())->adicionarEixoCustomizado([], ' cor ', self::CATALOGO));

        $this->assertSame('V-VAR-02', $e->regra);
        $this->assertSame('COLOR', $e->contexto['sugestao']);

        $this->assertSame('V-VAR-02', $this->violacao(fn () => (new EditorEixos())->adicionarEixoCustomizado([], 'size', self::CATALOGO))->regra);
    }

    public function test_tc06_valor_repetido_depois_de_normalizar(): void
    {
        $editor = new EditorEixos();
        $eixo = $editor->adicionarValor(new Eixo('SIZE', 'Tamanho', 0), null, 'M', self::CATALOGO['SIZE']);

        $this->assertSame('V-VAR-03', $this->violacao(fn () => $editor->adicionarValor($eixo, null, ' m ', self::CATALOGO['SIZE']))->regra);
        $this->assertCount(1, $eixo->valores);
    }

    public function test_tc07_texto_livre_igual_a_um_valor_da_lista_vira_o_id_e_forcar_duplicado_bloqueia(): void
    {
        $this->assertEquals(new ValorEixo('52049', 'Preto'), EditorEixos::sugerirValor('  preto ', self::CATALOGO['COLOR']['values']));
        $this->assertNull(EditorEixos::sugerirValor('grafite', self::CATALOGO['COLOR']['values']));

        $editor = new EditorEixos();
        $cor = $editor->adicionarValor(new Eixo('COLOR', 'Cor', 0), null, 'preto', self::CATALOGO['COLOR']);
        $this->assertSame('52049', $cor->valores[0]->valueId, 'o texto que bate com a lista adota o id');

        $this->assertSame('V-VAR-03', $this->violacao(fn () => $editor->adicionarValor($cor, null, 'PRETO', self::CATALOGO['COLOR']))->regra);
        $this->assertSame('V-VAR-03', $this->violacao(fn () => $editor->adicionarValor($cor, '52049', 'Preto', self::CATALOGO['COLOR']))->regra);

        $livre = $editor->adicionarValor($cor, null, 'Grafite', self::CATALOGO['COLOR']);
        $this->assertNull($livre->valores[1]->valueId);
        $this->assertSame([0, 1], array_map(fn ($v) => $v->posicao, $livre->valores));
    }

    public function test_tc40_eixo_nao_aceita_nao_se_aplica_nem_valor_vazio(): void
    {
        $editor = new EditorEixos();

        $this->assertSame('V-VAR-06', $this->violacao(fn () => $editor->adicionarValor(new Eixo('COLOR', 'Cor', 0), '-1', '', self::CATALOGO['COLOR']))->regra);
        $this->assertSame('V-VAR-05', $this->violacao(fn () => $editor->adicionarValor(new Eixo('SIZE', 'Tamanho', 0), null, '   ', self::CATALOGO['SIZE']))->regra);
    }

    public function test_lista_fechada_so_aceita_valor_da_lista(): void
    {
        $editor = new EditorEixos();
        $voltagem = new Eixo('VOLTAGE', 'Voltagem', 0);

        $this->assertSame('39205162', $editor->adicionarValor($voltagem, null, '127v', self::CATALOGO['VOLTAGE'])->valores[0]->valueId);
        $this->assertSame('V-VAR-07', $this->violacao(fn () => $editor->adicionarValor($voltagem, null, '110V', self::CATALOGO['VOLTAGE']))->regra);
        $this->assertSame('V-VAR-07', $this->violacao(fn () => $editor->adicionarValor($voltagem, '999', '110V', self::CATALOGO['VOLTAGE']))->regra);
    }

    public function test_tc13_atributo_do_produto_que_vira_eixo_leva_o_valor_e_sai_do_produto(): void
    {
        $produto = ['BRAND' => ['value_id' => null, 'value_name' => 'ECF'], 'COLOR' => ['value_id' => '52028', 'value_name' => 'Azul']];

        [$eixos, $restante] = (new EditorEixos())->promoverAtributo($produto, 'COLOR', [], self::CATALOGO);

        $this->assertSame('COLOR', $eixos[0]->chave);
        $this->assertEquals([new ValorEixo('52028', 'Azul')], $eixos[0]->valores);
        $this->assertArrayNotHasKey('COLOR', $restante);
        $this->assertArrayHasKey('BRAND', $restante);
    }

    public function test_promover_atributo_com_nao_se_aplica_cria_o_eixo_vazio(): void
    {
        $produto = ['COLOR' => ['value_id' => '-1', 'value_name' => null]];

        [$eixos, $restante] = (new EditorEixos())->promoverAtributo($produto, 'COLOR', [], self::CATALOGO);

        $this->assertSame([], $eixos[0]->valores);
        $this->assertSame([], $restante);
    }

    public function test_remover_eixo_reposiciona_os_que_sobram(): void
    {
        $editor = new EditorEixos();
        $eixos = $editor->adicionarEixo($editor->adicionarEixo($editor->adicionarEixo([], 'COLOR', self::CATALOGO), 'SIZE', self::CATALOGO), 'VOLTAGE', self::CATALOGO);

        $sobra = $editor->removerEixo($eixos, 'SIZE');

        $this->assertSame(['COLOR', 'VOLTAGE'], array_map(fn ($e) => $e->chave, $sobra));
        $this->assertSame([0, 1], array_map(fn ($e) => $e->posicao, $sobra));
    }
}
