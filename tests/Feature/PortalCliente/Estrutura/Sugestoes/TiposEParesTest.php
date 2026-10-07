<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaSugestaoDescartada;
use App\Models\EstruturaTipoPar;
use App\Models\EstruturaTipoProduto;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-06: schema e semente de tipos, pares, ajuste por produto e descarte.
 *
 * Modos de falha que estes testes impedem: a semente duplicar tipos/pares ao rodar de novo,
 * o par (a, b) e o (b, a) virarem duas linhas, a direção do Combit apontar para o lado
 * errado depois da ordenação, excluir tipo/produto deixando órfão, e a migration alterar
 * tabela da 167.
 */
class TiposEParesTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private const SEMENTE = '2026_10_07_100100_semear_estrutura_tipos_e_pares.php';

    private function tipo(string $slug): EstruturaTipoProduto
    {
        return EstruturaTipoProduto::where('slug', $slug)->firstOrFail();
    }

    private function produto($empresa): EstruturaProduto
    {
        return EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => null, 'nome' => 'Produto '.uniqid()]);
    }

    public function test_a_semente_grava_os_tipos_e_pares_do_config(): void
    {
        $this->assertSame(count(config('estrutura_geracao.tipos')), EstruturaTipoProduto::count());
        $this->assertSame(count(config('estrutura_geracao.pares')), EstruturaTipoPar::count());
        $this->assertSame(18, EstruturaTipoPar::count());
    }

    public function test_rodar_a_semente_de_novo_nao_muda_as_contagens(): void
    {
        $tipos = EstruturaTipoProduto::count();
        $pares = EstruturaTipoPar::count();

        $migration = require database_path('migrations/'.self::SEMENTE);
        $migration->up();

        $this->assertSame($tipos, EstruturaTipoProduto::count());
        $this->assertSame($pares, EstruturaTipoPar::count());
    }

    public function test_o_par_fica_ordenado_e_a_direcao_do_combit_e_remapeada(): void
    {
        foreach (EstruturaTipoPar::all() as $par) {
            $this->assertLessThanOrEqual($par->tipo_b_id, $par->tipo_a_id);
        }

        // [cadeira, mesa] com repete cadeira, em qualquer ordem de id.
        $cadeira = $this->tipo('cadeira');
        $mesa = $this->tipo('mesa');
        $par = EstruturaTipoPar::where('tipo_a_id', min($cadeira->id, $mesa->id))
            ->where('tipo_b_id', max($cadeira->id, $mesa->id))->firstOrFail();

        $this->assertSame($cadeira->id < $mesa->id ? 'a' : 'b', $par->combit_repete);

        // Par de config com os tipos em ordem invertida ([banqueta, mesa]) grava uma linha só.
        $banqueta = $this->tipo('banqueta');
        $this->assertSame(1, EstruturaTipoPar::where('tipo_a_id', min($banqueta->id, $mesa->id))
            ->where('tipo_b_id', max($banqueta->id, $mesa->id))->count());

        // Par só Kit fica sem direção; par do mesmo tipo (cama + cama) também.
        $aparador = $this->tipo('aparador');
        $this->assertNull(EstruturaTipoPar::where('tipo_a_id', min($aparador->id, $mesa->id))
            ->where('tipo_b_id', max($aparador->id, $mesa->id))->value('combit_repete'));
        $cama = $this->tipo('cama');
        $this->assertTrue(EstruturaTipoPar::where('tipo_a_id', $cama->id)->where('tipo_b_id', $cama->id)->exists());
    }

    public function test_o_par_e_unico_independente_da_ordem(): void
    {
        $par = EstruturaTipoPar::firstOrFail();

        $this->expectException(QueryException::class);
        EstruturaTipoPar::create(['tipo_a_id' => $par->tipo_a_id, 'tipo_b_id' => $par->tipo_b_id, 'combit_repete' => null]);
    }

    public function test_o_slug_do_tipo_e_unico(): void
    {
        $this->expectException(QueryException::class);
        EstruturaTipoProduto::create(['slug' => 'mesa', 'nome' => 'X', 'plural' => 'Xs', 'palavras' => 'x']);
    }

    public function test_excluir_o_tipo_leva_os_pares_e_devolve_o_produto_a_inferencia(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $cadeira = $this->tipo('cadeira');

        EstruturaProdutoGeracao::create(['produto_id' => $produto->id, 'company_id' => $empresa->id, 'tipo_id' => $cadeira->id]);
        $this->assertGreaterThan(0, EstruturaTipoPar::where('tipo_a_id', $cadeira->id)->orWhere('tipo_b_id', $cadeira->id)->count());

        $cadeira->delete();

        $this->assertSame(0, EstruturaTipoPar::where('tipo_a_id', $cadeira->id)->orWhere('tipo_b_id', $cadeira->id)->count());
        $ajuste = EstruturaProdutoGeracao::findOrFail($produto->id);
        $this->assertNull($ajuste->tipo_id);
    }

    public function test_excluir_o_produto_leva_o_ajuste_dele(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);

        EstruturaProdutoGeracao::create([
            'produto_id' => $produto->id, 'company_id' => $empresa->id, 'tipo_id' => $this->tipo('mesa')->id, 'qtd_combo' => '2',
        ]);
        $this->assertSame(1, EstruturaProdutoGeracao::count());

        $produto->delete();

        $this->assertSame(0, EstruturaProdutoGeracao::count());
    }

    public function test_o_descarte_e_unico_por_empresa_e_chave(): void
    {
        $a = $this->empresaDoGabarito();
        $b = $this->empresaDoGabarito();

        EstruturaSugestaoDescartada::create(['company_id' => $a->id, 'chave' => 'kit:1+2', 'fase' => 'kit']);
        EstruturaSugestaoDescartada::create(['company_id' => $b->id, 'chave' => 'kit:1+2', 'fase' => 'kit']);
        $this->assertSame(2, EstruturaSugestaoDescartada::count());

        $this->expectException(QueryException::class);
        EstruturaSugestaoDescartada::create(['company_id' => $a->id, 'chave' => 'kit:1+2', 'fase' => 'kit']);
    }

    public function test_apagar_a_empresa_leva_ajuste_e_descarte(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        EstruturaProdutoGeracao::create(['produto_id' => $produto->id, 'company_id' => $empresa->id]);
        EstruturaSugestaoDescartada::create(['company_id' => $empresa->id, 'chave' => 'k', 'fase' => 'kit']);

        $empresa->delete();

        $this->assertSame(0, EstruturaProdutoGeracao::count());
        $this->assertSame(0, EstruturaSugestaoDescartada::count());
    }

    public function test_as_migrations_da_fase_nao_alteram_tabela_existente_e_os_nomes_cabem(): void
    {
        foreach (['2026_10_07_100000_create_estrutura_geracao_tables.php', self::SEMENTE] as $arquivo) {
            $codigo = file_get_contents(database_path("migrations/{$arquivo}"));

            $this->assertStringNotContainsString('->enum(', $codigo, $arquivo);
            $this->assertStringNotContainsString('->json(', $codigo, $arquivo);
            $this->assertStringNotContainsString("Schema::table('estrutura_ofertas'", $codigo, $arquivo);
            $this->assertStringNotContainsString("Schema::table('estrutura_produto", $codigo, $arquivo);
            $this->assertStringNotContainsString("Schema::create('estrutura_produtos'", $codigo, $arquivo);

            preg_match_all("/'((?:[a-z]+_)+(?:uq|idx|fk|pk))'/", $codigo, $m);
            foreach ($m[1] as $nome) {
                $this->assertLessThanOrEqual(64, strlen($nome), "{$arquivo}: {$nome}");
            }
        }
    }

    public function test_a_criacao_so_toca_as_quatro_tabelas_novas(): void
    {
        $codigo = file_get_contents(database_path('migrations/2026_10_07_100000_create_estrutura_geracao_tables.php'));

        preg_match_all("/Schema::(?:create|table|dropIfExists)\('([a-z_]+)'/", $codigo, $m);
        $permitidas = ['estrutura_tipos_produto', 'estrutura_tipo_pares', 'estrutura_produto_geracao', 'estrutura_sugestoes_descartadas'];

        foreach (array_unique($m[1]) as $tabela) {
            $this->assertContains($tabela, $permitidas, "tabela fora das 4 novas: {$tabela}");
        }
        $this->assertNotEmpty(DB::select('select 1'));
    }
}
