<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-07: excluir variação pela regra da Lista SKUs (D-22).
 *
 * Modos de falha que estes testes impedem: apagar variação que é componente de
 * combo (deixa o combo órfão); anúncio sumindo em vez de voltar para a espera;
 * produto sem variação sobrando; excluir variação de outra empresa por id.
 */
class CicloDeVidaDoProdutoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function svc(): ProdutoCadastroService
    {
        return app(ProdutoCadastroService::class);
    }

    private function linha(string $codigo, string $valor): array
    {
        return ['grupo' => '1014', 'codigo' => $codigo, 'nome' => 'Cristaleira', 'valor' => $valor, 'eixo' => 'cor', 'volumes' => [['c' => 93, 'l' => 55, 'a' => 6, 'kg' => 9.5]]];
    }

    /** @return array<string, int> codigo => id da variação */
    private function duasVariacoes(Company $empresa): array
    {
        $r = $this->svc()->gravarLinhas($empresa, [$this->linha('1014-1', 'Natural'), $this->linha('1014-2', 'Preto')], $this->atorCliente($empresa));

        return array_column($r['linhas'], 'id', 'codigo');
    }

    public function test_excluir_variacao_sem_anuncios_mantem_o_produto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ids = $this->duasVariacoes($empresa);

        $r = $this->svc()->excluirVariacao($empresa, $ids['1014-1'], $this->atorCliente($empresa));

        $this->assertSame(['produto_excluido' => false, 'anuncios_para_espera' => 0, 'sku' => '1014-1'], $r);
        $this->assertNull(EstruturaOferta::where('sku', '1014-1')->first());
        $this->assertNull(EstruturaProdutoVariacao::find($ids['1014-1']));
        $this->assertSame(0, EstruturaProdutoVolume::where('variacao_id', $ids['1014-1'])->count());
        $this->assertSame(1, EstruturaProduto::count());
        $this->assertNotNull(EstruturaOferta::where('sku', '1014-2')->first());
    }

    public function test_excluir_a_ultima_variacao_leva_o_produto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ids = $this->duasVariacoes($empresa);

        $this->svc()->excluirVariacao($empresa, $ids['1014-1'], $ator);
        $r = $this->svc()->excluirVariacao($empresa, $ids['1014-2'], $ator);

        $this->assertTrue($r['produto_excluido']);
        $this->assertSame(0, EstruturaProduto::count());
        $this->assertSame(0, EstruturaOferta::count());
        $this->assertSame(0, \DB::table('estrutura_produto_ambiente')->count());
    }

    public function test_anuncios_da_variacao_voltam_para_a_espera_com_motivo_sem_oferta(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ids = $this->duasVariacoes($empresa);
        $oferta = EstruturaOferta::where('variacao_id', $ids['1014-1'])->first();
        foreach (['MLB1', 'MLB2'] as $mlb) {
            EstruturaAnuncio::create(['oferta_id' => $oferta->id, 'tipo' => 'classico', 'status' => 'ativo', 'codigo_mlb' => $mlb, 'titulo' => 'x']);
        }

        $r = $this->svc()->excluirVariacao($empresa, $ids['1014-1'], $this->atorCliente($empresa));

        $this->assertSame(2, $r['anuncios_para_espera']);
        $espera = EstruturaAnuncioEspera::where('company_id', $empresa->id)->get();
        $this->assertCount(2, $espera);
        $this->assertSame([EstruturaAnuncioEspera::MOTIVO_SEM_OFERTA], $espera->pluck('motivo')->unique()->values()->all());
        $this->assertSame(0, EstruturaAnuncio::count());
    }

    public function test_variacao_que_e_componente_de_combo_bloqueia_e_nada_e_apagado(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ids = $this->duasVariacoes($empresa);
        $oferta = EstruturaOferta::where('variacao_id', $ids['1014-1'])->first();
        app(EstruturaOfertaService::class)->criar($empresa, [
            'sku' => '1014-1-CB2', 'fase' => 'combo', 'componentes' => [['id' => $oferta->id, 'quantidade' => 2]],
        ], $ator);

        try {
            $this->svc()->excluirVariacao($empresa, $ids['1014-1'], $ator);
            $this->fail('Deveria bloquear');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('1014-1-CB2', $e->errors()['oferta'][0]);
        }

        $this->assertNotNull(EstruturaProdutoVariacao::find($ids['1014-1']));
        $this->assertNotNull(EstruturaOferta::find($oferta->id));
        $this->assertNotNull(EstruturaOferta::where('sku', '1014-1-CB2')->first());
    }

    public function test_item_do_publicador_fica_solto_da_oferta(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ids = $this->duasVariacoes($empresa);
        $oferta = EstruturaOferta::where('variacao_id', $ids['1014-1'])->first();
        $pub = PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $oferta->id, 'sku' => '1014-1', 'nome' => 'Cristaleira — Natural', 'origem' => PubProduto::ORIGEM_PORTAL]);

        $this->svc()->excluirVariacao($empresa, $ids['1014-1'], $this->atorCliente($empresa));

        $pub = $pub->fresh();
        $this->assertNotNull($pub);
        $this->assertNull($pub->oferta_id);
        $this->assertSame('1014-1', $pub->sku);
    }

    public function test_variacao_de_outra_empresa_e_404_e_nada_muda(): void
    {
        $empresa = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $ids = $this->duasVariacoes($outra);

        try {
            $this->svc()->excluirVariacao($empresa, $ids['1014-1'], $this->atorCliente($empresa));
            $this->fail('Deveria dar 404');
        } catch (ModelNotFoundException) {
            // esperado
        }

        $this->assertSame(2, EstruturaProdutoVariacao::count());
        $this->assertSame(2, EstruturaOferta::count());
    }

    public function test_activity_registra_variacao_excluida_e_produto_excluido_com_origem(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ids = $this->duasVariacoes($empresa);

        $this->svc()->excluirVariacao($empresa, $ids['1014-1'], $ator);
        $this->svc()->excluirVariacao($empresa, $ids['1014-2'], $ator);

        $eventos = Activity::where('log_name', 'portal')->get()->map(fn ($a) => $a->getExtraProperty('evento'));
        $this->assertSame(2, $eventos->filter(fn ($e) => $e === 'variacao_excluida')->count());
        $this->assertSame(1, $eventos->filter(fn ($e) => $e === 'produto_excluido')->count());
        $log = Activity::where('log_name', 'portal')->latest('id')->first();
        $this->assertSame('cliente', $log->getExtraProperty('origem'));
    }
}
