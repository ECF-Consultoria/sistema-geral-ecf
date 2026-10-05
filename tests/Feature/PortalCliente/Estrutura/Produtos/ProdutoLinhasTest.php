<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaAmbiente;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Services\Portal\Estrutura\EstruturaAnuncioService;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Produtos\ProdutoLinhas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-06: a linha que a tela exibe.
 *
 * Modos de falha que estes testes impedem: a tela recalcular logística/frete
 * (PORTAL-02), produto de outra empresa aparecendo, e a página de 100 produtos
 * perder linhas.
 */
class ProdutoLinhasTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function linhas(): ProdutoLinhas
    {
        return app(ProdutoLinhas::class);
    }

    private function produto(Company $empresa, string $codigo, string $nome = 'Cristaleira', array $extra = []): EstruturaProduto
    {
        return EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => $codigo, 'nome' => $nome, ...$extra]);
    }

    private function variacao(EstruturaProduto $p, string $codigo, int $ordem, array $volumes = [], ?float $custo = null, array $extra = []): EstruturaProdutoVariacao
    {
        $v = EstruturaProdutoVariacao::create([
            'produto_id' => $p->id, 'company_id' => $p->company_id, 'ordem' => $ordem, 'codigo' => $codigo, 'custo' => $custo, ...$extra,
        ]);
        foreach ($volumes as $i => [$c, $l, $a, $kg]) {
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => $i + 1, 'comprimento' => $c, 'largura' => $l, 'altura' => $a, 'peso' => $kg]);
        }

        return $v;
    }

    public function test_duas_variacoes_saem_na_ordem_com_peso_total_e_texto_de_volumes(): void
    {
        $empresa = $this->empresaDoGabarito();
        $p = $this->produto($empresa, '1014');
        $this->variacao($p, '1014-2', 2, [[10, 10, 10, 2.5]]);
        $this->variacao($p, '1014-1', 1, [[186, 43, 12, 27.8], [97, 42, 12, 12.1]]);

        $r = $this->linhas()->paraProdutos($empresa, [$p->id]);

        $this->assertSame(['1014-1', '1014-2'], array_column($r, 'codigo'));
        $this->assertTrue($r[0]['primeira']);
        $this->assertFalse($r[1]['primeira']);
        $this->assertSame(39.9, $r[0]['peso_total']);
        $this->assertSame(2, $r[0]['n_volumes']);
        $this->assertSame('186×43×12 · 27,8 | 97×42×12 · 12,1', $r[0]['volumes_texto']);
        $this->assertSame('1014', $r[0]['grupo']);
    }

    public function test_logistica_me2_com_frete_da_tabela_e_me1_sem_frete(): void
    {
        $empresa = $this->empresaDoGabarito();
        $p = $this->produto($empresa, 'A');
        $this->variacao($p, 'A-1', 1, [[93, 55, 6, 9.5]]);
        $this->variacao($p, 'A-2', 2, [[186, 43, 12, 27.8], [97, 42, 12, 12.1]]);

        [$me2, $me1] = $this->linhas()->paraProdutos($empresa, [$p->id]);

        $this->assertSame('me2', $me2['logistica']);
        $this->assertEquals(['c' => 93.0, 'l' => 55.0, 'a' => 6.0, 'peso_real' => 9.5], $me2['pacote']);
        $this->assertSame('tabela_ecf', $me2['frete']['origem']);

        $this->assertSame('me1', $me1['logistica']);
        $this->assertSame(39.9, $me1['peso_total']);
        $this->assertNull($me1['frete']['valor']);
        $this->assertContains('frete_me1', $me1['pendencias']);
    }

    public function test_pendencias_na_ordem_fixa_e_completa_nao_tem_nenhuma(): void
    {
        $empresa = $this->empresaDoGabarito();
        $p = $this->produto($empresa, 'A');
        $this->variacao($p, 'A-1', 1, [[10, 10, 10, 1]]);

        $r = $this->linhas()->paraProdutos($empresa, [$p->id]);
        $this->assertSame(['custo', 'categoria', 'familia', 'ambiente'], $r[0]['pendencias']);

        $fam = EstruturaFamilia::create(['company_id' => $empresa->id, 'nome' => 'Farmhouse']);
        $amb = EstruturaAmbiente::create(['company_id' => $empresa->id, 'nome' => 'Sala']);
        $p->update(['familia_id' => $fam->id, 'categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'X']);
        $p->ambientes()->attach($amb->id);
        $p->variacoes()->first()->update(['custo' => 100]);

        $this->assertSame([], $this->linhas()->paraProdutos($empresa, [$p->id])[0]['pendencias']);
    }

    public function test_categoria_estado_familia_ambientes_e_rotulo_do_eixo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $fam = EstruturaFamilia::create(['company_id' => $empresa->id, 'nome' => 'Farmhouse']);
        $a1 = EstruturaAmbiente::create(['company_id' => $empresa->id, 'nome' => 'Sala']);
        $a2 = EstruturaAmbiente::create(['company_id' => $empresa->id, 'nome' => 'Hall']);
        $p = $this->produto($empresa, 'A', 'X', ['familia_id' => $fam->id, 'categoria_ml_nome' => 'Cristaleiras']);
        $p->ambientes()->attach([$a1->id, $a2->id]);
        $this->variacao($p, 'A-1', 1, [], null, ['eixo' => 'tamanho', 'valor' => 'G']);

        $l = $this->linhas()->paraProdutos($empresa, [$p->id])[0];

        $this->assertSame('a_confirmar', $l['categoria_estado']);
        $this->assertSame('Farmhouse', $l['familia']);
        $this->assertSame(['Hall', 'Sala'], $l['ambientes']);
        $this->assertSame('Tamanho', $l['eixo_rotulo']);
        $this->assertSame('tamanho', $l['eixo']);

        $p->update(['categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Cristaleiras']);
        $this->assertSame('confirmada', $this->linhas()->paraProdutos($empresa, [$p->id])[0]['categoria_estado']);
    }

    public function test_paginacao_de_100_produtos_e_busca_por_nome_e_por_codigo_de_variacao(): void
    {
        $empresa = $this->empresaDoGabarito();
        for ($i = 1; $i <= 101; $i++) {
            $p = $this->produto($empresa, "P{$i}", $i === 7 ? 'Poltrona Especial' : "Produto {$i}");
            $this->variacao($p, "V{$i}-REF", 1);
        }

        $r = $this->linhas()->pagina($empresa, '', 1);
        $this->assertCount(100, $r['linhas']);
        $this->assertSame(['pagina' => 1, 'paginas' => 2, 'total' => 101], $r['paginacao']);
        $this->assertTrue($r['tem_produtos']);

        // Página fora do intervalo vira a última válida.
        $r = $this->linhas()->pagina($empresa, '', 9);
        $this->assertSame(2, $r['paginacao']['pagina']);
        $this->assertCount(1, $r['linhas']);

        $r = $this->linhas()->pagina($empresa, 'poltrona', 1);
        $this->assertSame(['V7-REF'], array_column($r['linhas'], 'codigo'));

        $r = $this->linhas()->pagina($empresa, 'v55-ref', 1);
        $this->assertSame(['V55-REF'], array_column($r['linhas'], 'codigo'));
    }

    public function test_outra_empresa_nunca_aparece_e_empresa_vazia_nao_tem_produtos(): void
    {
        $a = $this->empresaDoGabarito();
        $b = $this->empresaDoGabarito();
        $p = $this->produto($b, 'B1');
        $this->variacao($p, 'B1-1', 1);

        $this->assertSame([], $this->linhas()->paraProdutos($a, [$p->id]));

        $r = $this->linhas()->pagina($a, '', 1);
        $this->assertFalse($r['tem_produtos']);
        $this->assertSame([], $r['linhas']);
        $this->assertSame(1, $r['paginacao']['paginas']);
    }

    public function test_oferta_nula_sem_oferta_e_com_contagem_de_anuncios_e_usos(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $p = $this->produto($empresa, 'A');
        $v1 = $this->variacao($p, 'A-1', 1);
        $this->variacao($p, 'A-2', 2);

        $ofertas = app(EstruturaOfertaService::class);
        [$simples] = $ofertas->criar($empresa, ['sku' => 'A-1', 'fase' => 'simples', 'nome' => 'A 1', 'variacao_id' => $v1->id], $ator);
        $ofertas->criar($empresa, [
            'sku' => 'A-1-CB2', 'fase' => 'combo', 'nome' => 'Combo 2',
            'componentes' => [['id' => $simples->id, 'quantidade' => 2]],
        ], $ator);
        app(EstruturaAnuncioService::class)->cadastrar($simples, [
            'tipo' => 'classico', 'codigo_mlb' => 'MLB0000000099', 'titulo' => 'Titulo', 'catalogo' => false, 'status' => 'ativo',
        ], $ator);

        [$com, $sem] = $this->linhas()->paraProdutos($empresa, [$p->id]);

        $this->assertNull($sem['oferta']);
        $this->assertSame($simples->id, $com['oferta']['id']);
        $this->assertSame('A-1', $com['oferta']['sku']);
        $this->assertSame(1, $com['oferta']['anuncios']);
        $this->assertSame(['A-1-CB2'], $com['oferta']['usada_em']);
    }
}
