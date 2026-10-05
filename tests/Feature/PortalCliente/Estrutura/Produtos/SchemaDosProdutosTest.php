<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaAmbiente;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-01: o schema do catálogo de produtos do Mapeamento.
 *
 * Modos de falha que estes testes impedem: o mesmo código repetido virando dois
 * produtos/variações na mesma empresa (e, no outro sentido, o código de uma empresa
 * barrando o de outra); excluir uma Company deixando família, produto, variação ou
 * volume órfão; apagar a oferta junto com a variação.
 */
class SchemaDosProdutosTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function produto(Company $empresa, ?string $codigo, array $extra = []): EstruturaProduto
    {
        return EstruturaProduto::create(array_merge(['company_id' => $empresa->id, 'codigo' => $codigo, 'nome' => 'Produto '.uniqid()], $extra));
    }

    private function variacao(EstruturaProduto $produto, string $codigo): EstruturaProdutoVariacao
    {
        return EstruturaProdutoVariacao::create([
            'produto_id' => $produto->id, 'company_id' => $produto->company_id, 'ordem' => 1, 'codigo' => $codigo,
        ]);
    }

    public function test_as_seis_tabelas_existem_com_as_colunas_do_desenho(): void
    {
        $this->assertTrue(Schema::hasColumns('estrutura_familias', ['id', 'company_id', 'nome', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('estrutura_ambientes', ['id', 'company_id', 'nome', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('estrutura_produtos', [
            'id', 'company_id', 'codigo', 'nome', 'familia_id', 'categoria_ml_id', 'categoria_ml_nome', 'categoria_ml_caminho', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('estrutura_produto_ambiente', ['produto_id', 'ambiente_id']));
        $this->assertTrue(Schema::hasColumns('estrutura_produto_variacoes', [
            'id', 'produto_id', 'company_id', 'ordem', 'codigo', 'eixo', 'valor', 'custo', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('estrutura_produto_volumes', ['id', 'variacao_id', 'ordem', 'comprimento', 'largura', 'altura', 'peso']));

        $this->assertFalse(Schema::hasColumn('estrutura_produto_volumes', 'created_at'));
        $this->assertFalse(Schema::hasColumn('estrutura_produto_ambiente', 'created_at'));
    }

    public function test_codigo_de_variacao_repetido_na_mesma_empresa_barra_e_em_outra_empresa_grava(): void
    {
        $a = $this->empresaDoGabarito();
        $b = $this->empresaDoGabarito();
        $p1 = $this->produto($a, 'P1');
        $p2 = $this->produto($a, 'P2');
        $pb = $this->produto($b, 'P1');

        $this->variacao($p1, 'CAD-01');
        $this->variacao($pb, 'CAD-01'); // outra empresa: grava

        $this->expectException(QueryException::class);
        $this->variacao($p2, 'CAD-01');
    }

    public function test_codigo_nulo_repete_e_codigo_igual_nao(): void
    {
        $a = $this->empresaDoGabarito();

        $this->produto($a, null);
        $this->produto($a, null);
        $this->assertSame(2, EstruturaProduto::where('company_id', $a->id)->whereNull('codigo')->count());

        $this->produto($a, 'GRP-1');
        $this->expectException(QueryException::class);
        $this->produto($a, 'GRP-1');
    }

    public function test_apagar_a_familia_zera_o_produto_e_apagar_o_produto_leva_variacoes_volumes_e_pivot(): void
    {
        $a = $this->empresaDoGabarito();
        $familia = EstruturaFamilia::create(['company_id' => $a->id, 'nome' => 'Farmhouse']);
        $ambiente = EstruturaAmbiente::create(['company_id' => $a->id, 'nome' => 'Cozinha']);
        $produto = $this->produto($a, 'P1', ['familia_id' => $familia->id]);
        $produto->ambientes()->sync([$ambiente->id]);
        $variacao = $this->variacao($produto, 'V1');
        EstruturaProdutoVolume::create(['variacao_id' => $variacao->id, 'ordem' => 1, 'comprimento' => 10, 'largura' => 20, 'altura' => 30, 'peso' => 1.5]);

        $familia->delete();
        $this->assertNull($produto->fresh()->familia_id);

        $produto->delete();
        $this->assertSame(0, EstruturaProdutoVariacao::count());
        $this->assertSame(0, EstruturaProdutoVolume::count());
        $this->assertSame(0, DB::table('estrutura_produto_ambiente')->count());
        $this->assertSame(1, EstruturaAmbiente::count(), 'o ambiente é da empresa e fica');
    }

    public function test_apagar_a_company_apaga_o_catalogo_dela_e_nao_toca_o_da_outra(): void
    {
        $a = $this->empresaDoGabarito();
        $b = $this->empresaDoGabarito();
        foreach ([$a, $b] as $empresa) {
            $familia = EstruturaFamilia::create(['company_id' => $empresa->id, 'nome' => 'Farmhouse']);
            $ambiente = EstruturaAmbiente::create(['company_id' => $empresa->id, 'nome' => 'Sala']);
            $produto = $this->produto($empresa, 'P1', ['familia_id' => $familia->id]);
            $produto->ambientes()->sync([$ambiente->id]);
            $variacao = $this->variacao($produto, 'V1');
            EstruturaProdutoVolume::create(['variacao_id' => $variacao->id, 'ordem' => 1, 'comprimento' => 1, 'largura' => 1, 'altura' => 1, 'peso' => 1]);
        }

        Company::find($a->id)->delete();

        foreach ([EstruturaFamilia::class, EstruturaAmbiente::class, EstruturaProduto::class, EstruturaProdutoVariacao::class] as $modelo) {
            $this->assertSame(0, $modelo::where('company_id', $a->id)->count(), $modelo.' da empresa apagada');
            $this->assertSame(1, $modelo::where('company_id', $b->id)->count(), $modelo.' da outra empresa');
        }
        $this->assertSame(1, EstruturaProdutoVolume::count());
        $this->assertSame(1, DB::table('estrutura_produto_ambiente')->count());
    }

    public function test_ambientes_sincronizam_varios_e_os_eixos_sao_a_lista_fechada(): void
    {
        $a = $this->empresaDoGabarito();
        $amb1 = EstruturaAmbiente::create(['company_id' => $a->id, 'nome' => 'Cozinha']);
        $amb2 = EstruturaAmbiente::create(['company_id' => $a->id, 'nome' => 'Sala']);
        $produto = $this->produto($a, 'P1');

        $produto->ambientes()->sync([$amb1->id, $amb2->id]);
        $this->assertSame(2, $produto->ambientes()->count());

        $this->assertSame(['cor', 'tamanho', 'voltagem', 'material', 'sabor', 'outro'], array_keys(EstruturaProdutoVariacao::EIXOS));
    }

    public function test_a_oferta_ganha_variacao_id_unico(): void
    {
        $a = $this->empresaDoGabarito();
        $this->assertTrue(Schema::hasColumn('estrutura_ofertas', 'variacao_id'));
        $variacao = $this->variacao($this->produto($a, 'P1'), 'V1');

        EstruturaOferta::create(['company_id' => $a->id, 'variacao_id' => $variacao->id, 'sku' => 'V1', 'fase' => 'simples']);

        $this->expectException(QueryException::class);
        EstruturaOferta::create(['company_id' => $a->id, 'variacao_id' => $variacao->id, 'sku' => 'V1-b', 'fase' => 'simples']);
    }

    public function test_down_e_up_da_migration_do_vinculo_mantem_as_ofertas_e_up_e_idempotente(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->listaDoGabarito($empresa, $this->atorCliente($empresa));
        $this->assertSame(9, EstruturaOferta::count());

        $migration = require database_path('migrations/2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php');
        // O SQLite reconstrói a tabela para dropar coluna/FK; com FK ligada (adiada ate o fim da transacao) e filhas apontando
        // para ela (componentes), o drop falha. Só no teste: no MariaDB o DDL é in-place.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('estrutura_ofertas', 'variacao_id'));
        $this->assertSame(9, EstruturaOferta::count(), 'down só tira o vínculo, nunca a oferta');

        $migration->up();
        $migration->up(); // idempotente
        $this->assertTrue(Schema::hasColumn('estrutura_ofertas', 'variacao_id'));
        $this->assertSame(9, EstruturaOferta::count());
        $this->assertSame(9, EstruturaOferta::whereNull('variacao_id')->count(), 'sem backfill');
    }

    public function test_apagar_a_variacao_deixa_a_oferta_viva_sem_vinculo(): void
    {
        $a = $this->empresaDoGabarito();
        $variacao = $this->variacao($this->produto($a, 'P1'), 'V1');
        $oferta = EstruturaOferta::create(['company_id' => $a->id, 'variacao_id' => $variacao->id, 'sku' => 'V1', 'fase' => 'simples']);
        $this->assertTrue($oferta->ligadaAProduto());

        $variacao->delete();

        $oferta = $oferta->fresh();
        $this->assertNotNull($oferta);
        $this->assertNull($oferta->variacao_id);
        $this->assertFalse($oferta->ligadaAProduto());
    }

    public function test_estado_da_categoria(): void
    {
        $this->assertSame('confirmada', (new EstruturaProduto(['categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'X']))->estadoCategoria());
        $this->assertSame('nao_validada', (new EstruturaProduto(['categoria_ml_id' => 'MLB1']))->estadoCategoria());
        $this->assertSame('a_confirmar', (new EstruturaProduto(['categoria_ml_nome' => 'Cadeiras']))->estadoCategoria());
        $this->assertSame('vazia', (new EstruturaProduto())->estadoCategoria());
    }
}
