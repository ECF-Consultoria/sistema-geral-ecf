<?php

namespace Tests\Unit\Publicador;

use App\Support\Publicador\PadroesDoEditor as P;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * N9 (22/10/2026): os padrões que o editor aplicava sozinho ao abrir, portados para o servidor. A regra tem de ser
 * a MESMA do JS — senão o servidor grava um tom e a tela, ao abrir, troca por outro (e grava, que é o defeito).
 *
 * A paridade é provada pela fixture `tests/fixtures-ml/padroes-do-editor-paridade.json`, gerada a partir do JS e
 * conferida também por `tests/js/publicador-padroes-do-editor.test.js`: os dois lados batem nos mesmos números.
 */
class PadroesDoEditorTest extends TestCase
{
    use CarregaSchemas;

    private static function paridade(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/fixtures-ml/padroes-do-editor-paridade.json'), true);
    }

    private static function sequencia(array $valores): \Closure
    {
        $i = 0;

        return function () use ($valores, &$i) {
            return $valores[$i++ % count($valores)];
        };
    }

    private static function cadeira(array $eixos = []): SchemaClassificado
    {
        return (new ClassificadorAtributos())->classificar(self::schema(self::CADEIRA), new ContextoClassificacao('new', $eixos));
    }

    // ─── Paridade com o JS ───────────────────────────────────────────────────

    public function test_o_digito_e_a_validacao_do_ean_batem_com_o_js(): void
    {
        $f = self::paridade();
        foreach ($f['digitos'] as $c) {
            $this->assertSame($c['digito'], P::digitoEan13($c['base']), $c['base']);
        }
        foreach ($f['codigos'] as $c) {
            $this->assertSame($c['valido'], P::eanValido($c['codigo']), $c['codigo']);
        }
    }

    public function test_o_mesmo_sorteio_gera_o_mesmo_ean_do_js_inclusive_quando_o_primeiro_ja_existe(): void
    {
        foreach (self::paridade()['sorteios'] as $c) {
            $ean = P::gerarEan13($c['existentes'], self::sequencia($c['sorteio']));
            $this->assertSame($c['ean'], $ean);
            $this->assertStringStartsWith('789', $ean);
            $this->assertTrue(P::eanValido($ean));
        }
    }

    public function test_o_tom_de_cada_nome_de_cor_bate_com_o_tomDaCor_do_js(): void
    {
        $f = self::paridade();
        foreach ($f['cores'] as $c) {
            $this->assertSame($c['tom'], P::tomDaCor($c['nome'], $f['tons'])['id'] ?? null, "cor \"{$c['nome']}\"");
        }
        foreach ($f['cores_sem_celeste'] as $c) {
            $this->assertSame($c['tom'], P::tomDaCor($c['nome'], $f['tons_sem_celeste'])['id'] ?? null, "cor \"{$c['nome']}\" sem o tom Azul celeste");
        }
    }

    public function test_o_gerador_sem_sorteio_dado_sempre_da_ean_valido_e_fora_dos_existentes(): void
    {
        $usados = [];
        for ($i = 0; $i < 200; $i++) {
            $ean = P::gerarEan13($usados);
            $this->assertTrue(P::eanValido($ean));
            $this->assertStringStartsWith('789', $ean);
            $this->assertArrayNotHasKey($ean, $usados);
            $usados[$ean] = true;
        }
    }

    // ─── As regras de quem ganha o quê (as mesmas condições da tela) ─────────

    public function test_ean_so_para_variacao_ativa_nao_publicada_sem_codigo_e_sem_o_motivo(): void
    {
        $schema = self::cadeira();
        $this->assertNotNull($schema->atributo('GTIN'), 'a cadeira pede GTIN');
        $variantes = [
            new Variante('a', [], dados: ['atributos' => []]),
            new Variante('b', [], dados: ['atributos' => ['GTIN' => ['value_name' => '7896553367645']]]),
            new Variante('c', [], dados: ['atributos' => ['EMPTY_GTIN_REASON' => ['value_id' => '17055161']]]),
            new Variante('d', [], ativa: false),
            new Variante('e', [], orfa: true),
            new Variante('f', [], publicada: true),
            new Variante('g', [], dados: ['atributos' => ['GTIN' => ['value_name' => '   ']]]),
        ];

        $this->assertSame(['a', 'g'], array_map(fn (Variante $v) => $v->chave, P::variantesSemGtin($variantes, $schema)));
        $this->assertSame([], P::variantesSemGtin($variantes, null), 'sem schema não se sabe se a categoria pede GTIN');
    }

    public function test_faltantes_preenche_o_vazio_e_nunca_troca_o_que_existe(): void
    {
        $schema = self::cadeira(['COLOR']);
        $this->assertSame(AtributoClassificado::SECAO_VARIANTE, $schema->atributo('MAIN_COLOR')?->secao);
        $cor = new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('52028', 'Azul-marinho'), new ValorEixo(null, 'Grafite'), new ValorEixo(null, 'Nogueira')]);
        $s = new RascunhoSnapshot(
            categoriaId: self::CADEIRA,
            eixos: [$cor],
            variantes: [
                new Variante('azul', ['COLOR' => $cor->valores[0]], dados: ['atributos' => ['SELLER_SKU' => ['value_name' => 'CAD-AZ']], 'estoque' => 3]),
                new Variante('grafite', ['COLOR' => $cor->valores[1]], dados: ['atributos' => ['GTIN' => ['value_name' => '7896553367645'], 'MAIN_COLOR' => ['value_id' => '2450295', 'value_name' => 'Preto']]]),
                new Variante('nogueira', ['COLOR' => $cor->valores[2]]),
            ],
        );

        $f = P::faltantes($s, $schema, null, self::sequencia([0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 0.0]));
        $porChave = collect($f['variantes'])->keyBy('chave');

        $azul = $porChave['azul']->dados;
        $this->assertTrue(P::eanValido($azul['atributos']['GTIN']['value_name']));
        $this->assertSame(['value_id' => '2450293', 'value_name' => 'Azul'], $azul['atributos']['MAIN_COLOR'], 'Azul-marinho → Azul');
        $this->assertSame('CAD-AZ', $azul['atributos']['SELLER_SKU']['value_name'], 'o resto fica');
        $this->assertSame(3, $azul['estoque']);

        $grafite = $porChave['grafite']->dados['atributos'];
        $this->assertSame('7896553367645', $grafite['GTIN']['value_name'], 'código que existe não muda');
        $this->assertSame('2450295', $grafite['MAIN_COLOR']['value_id'], 'Cor principal escolhida (Preto num Grafite) fica');

        $nogueira = $porChave['nogueira']->dados['atributos'];
        $this->assertArrayNotHasKey('MAIN_COLOR', $nogueira, 'nome sem tom: a pessoa escolhe');
        $this->assertNotSame($nogueira['GTIN']['value_name'], $azul['atributos']['GTIN']['value_name'], 'dois códigos diferentes');

        $this->assertNull($f['fotos_por_variante'], 'a Cor define a foto: não liga "fotos por variação"');
        $this->assertNull($f['envio']);
        $this->assertSame(['GTIN:azul', 'GTIN:nogueira', 'MAIN_COLOR:azul'], $f['aplicados']);
    }

    public function test_rascunho_completo_nao_tem_nada_a_aplicar(): void
    {
        $schema = self::cadeira(['COLOR']);
        $cor = new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('52028', 'Azul')]);
        $s = new RascunhoSnapshot(categoriaId: self::CADEIRA, eixos: [$cor], variantes: [
            new Variante('azul', ['COLOR' => $cor->valores[0]], dados: ['atributos' => ['GTIN' => ['value_name' => '7890123456784'], 'MAIN_COLOR' => ['value_id' => '2450293', 'value_name' => 'Azul']]]),
        ], envio: ['modo' => 'me2', 'frete_gratis' => true, 'retirada' => false]);

        $f = P::faltantes($s, $schema, ['me2', 'custom', 'not_specified']);

        $this->assertSame([], $f['aplicados']);
        $this->assertNull($f['variantes']);
    }

    public function test_fotos_por_variacao_so_quando_ha_variacao_e_nenhum_eixo_define_a_foto(): void
    {
        $tamanho = fn (bool $foto, array $valores) => new Eixo('SIZE', 'Tamanho', 0, $foto, array_map(fn ($n) => new ValorEixo(null, $n), $valores));

        $this->assertTrue(P::precisaFotosPorVariante([$tamanho(false, ['P', 'M'])]));
        $this->assertFalse(P::precisaFotosPorVariante([$tamanho(true, ['P', 'M'])]));
        $this->assertFalse(P::precisaFotosPorVariante([$tamanho(false, [])]), 'eixo sem valor não é variação');
        $this->assertFalse(P::precisaFotosPorVariante([]));
        $this->assertFalse(P::precisaFotosPorVariante([$tamanho(false, ['P']), new Eixo('COLOR', 'Cor', 1, true, [new ValorEixo(null, 'Azul')])]));

        $s = new RascunhoSnapshot(categoriaId: 'MLB1', eixos: [$tamanho(false, ['P', 'M'])], variantes: [], fotosPorVariante: true);
        $this->assertNull(P::faltantes($s, null, null)['fotos_por_variante'], 'já ligado: nada a fazer');
    }

    public function test_forma_de_envio_que_a_conta_nao_tem_volta_para_mercado_envios_ou_a_primeira_que_ela_tem(): void
    {
        $this->assertNull(P::modoDeEnvio('me2', null), 'sem saber o que a conta tem, nada muda');
        $this->assertNull(P::modoDeEnvio('custom', ['me2', 'custom']));
        $this->assertSame('me2', P::modoDeEnvio('custom', ['me2', 'not_specified']));
        $this->assertSame('custom', P::modoDeEnvio('me2', ['not_specified', 'custom']), 'a ordem é a da tela (me2, custom, a combinar)');
        $this->assertNull(P::modoDeEnvio('me2', ['me1']), 'nenhuma que a tela conheça: fica');
    }
}
