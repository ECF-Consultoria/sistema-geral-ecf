<?php

namespace Tests\Feature\Publicador;

use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\EditorRascunhoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * O estado do editor por PRODUTO (160-02): chave `produto` ao vivo, produto sem
 * oferta sem efetivos, e o resumo de bloqueios gravado sem mexer em revisão.
 */
class EstadoDoProdutoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->fakeMl();
    }

    private function editor(): EditorRascunhoService
    {
        return app(EditorRascunhoService::class);
    }

    public function test_estado_traz_o_produto_com_sku_e_nome_ao_vivo_da_oferta(): void
    {
        $e = $this->editor()->estado($this->r);

        $this->assertSame($this->produto->id, $e['produto']['id']);
        $this->assertSame('CAD-01', $e['produto']['sku']);
        $this->assertSame('Cadeira', $e['produto']['nome']);
        $this->assertSame($this->produto->oferta_id, $e['produto']['oferta_id']);
        $this->assertSame(PubProduto::ORIGEM_PORTAL, $e['produto']['origem']);
        $this->assertSame($this->produto->oferta_id, $e['oferta']['id']);

        $this->produto->oferta->update(['nome' => 'Cadeira Renomeada no Portal']);

        $this->assertSame('Cadeira Renomeada no Portal', $this->editor()->estado($this->r)['produto']['nome']);
    }

    public function test_produto_sem_oferta_abre_sem_efetivos_e_com_dois_alvos_ativos(): void
    {
        $solto = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'SOLTO-1', 'nome' => 'Produto Solto', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        $r = $this->editor()->abrir($solto);
        $e = $this->editor()->estado($r);

        $this->assertNull($e['oferta']);
        $this->assertSame('SOLTO-1', $e['produto']['sku']);
        $this->assertNull($e['produto']['oferta_id']);
        $this->assertSame(['gold_special' => null, 'gold_pro' => null], $e['efetivos']['titulos']);
        $this->assertSame(['gold_special' => null, 'gold_pro' => null], $e['efetivos']['precos']);
        $this->assertSame([true, true], array_column($e['alvos'], 'ativo'));
        $this->assertSame([null, null], array_column($e['alvos'], 'mlb_na_regua'));
    }

    public function test_resumo_de_bloqueios_e_gravado_sem_subir_revisao_nem_updated_at(): void
    {
        // Sem categoria não há schema nem problemas: o resumo (0) ainda é gravado na 1ª leitura.
        $this->r->update(['categoria_id' => null]);
        DB::table('pub_rascunhos')->where('id', $this->r->id)->update(['updated_at' => '2026-01-01 10:00:00']);
        $antes = DB::table('pub_rascunhos')->where('id', $this->r->id)->first();

        $e = $this->editor()->estado($this->r);

        $depois = DB::table('pub_rascunhos')->where('id', $this->r->id)->first();
        $resumo = json_decode($depois->step_state, true)['resumo'];
        $bloqueios = count(array_filter($e['problemas'], fn ($p) => $p['severidade'] === 'BLOCKER'));

        $this->assertSame($bloqueios, $resumo['bloqueios']);
        $this->assertSame((int) $antes->revisao, (int) $depois->revisao);
        $this->assertSame($antes->updated_at, $depois->updated_at);
    }

    public function test_resumo_preserva_o_resto_do_step_state(): void
    {
        $this->r->update(['step_state' => ['conta' => ['modelo' => 'UP'], 'resumo' => ['bloqueios' => 99, 'revisao' => 1]]]);

        $this->editor()->estado($this->r);

        $step = PubRascunho::find($this->r->id)->step_state;
        $this->assertSame(['modelo' => 'UP'], $step['conta']);
        $this->assertNotSame(99, $step['resumo']['bloqueios']);
    }

    public function test_prontidao_traz_faltam_do_resumo_so_em_rascunho(): void
    {
        $this->r->update(['step_state' => ['resumo' => ['bloqueios' => 3, 'revisao' => 1]]]);

        $this->assertSame(3, EditorRascunhoService::prontidao($this->r->fresh(), null)['faltam']);
        $this->assertSame(0, EditorRascunhoService::prontidao(null, null)['faltam']);

        $this->r->update(['status' => PubRascunho::PUBLISHED]);
        $this->assertSame(0, EditorRascunhoService::prontidao($this->r->fresh(), null)['faltam']);
    }
}
