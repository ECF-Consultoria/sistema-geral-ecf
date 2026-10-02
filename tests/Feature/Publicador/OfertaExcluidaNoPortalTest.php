<?php

namespace Tests\Feature\Publicador;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubVariantePreco;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * D27 pela Lista SKUs: apagar a oferta solta o produto do Publicador, que fica
 * com o título e o preço que herdava; rascunho e publicações ficam.
 */
class OfertaExcluidaNoPortalTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private AtorDoPortal $ator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->fakeMl();
        $this->ator = AtorDoPortal::daEquipe(User::factory()->create(['name' => 'Dev ECF']));
        // O planejado e a Precificação do Portal, como o mock os devolve.
        $this->efetivos = [
            'titulos' => ['gold_special' => 'Cadeira Planejada Clássico', 'gold_pro' => 'Cadeira Planejada Premium'],
            'precos' => ['gold_special' => 199.9, 'gold_pro' => 249.9],
            'mlbs' => [],
        ];
        // Um 2º alvo sem título digitado, para ter vazio dos dois lados.
        $this->r->alvos()->create(['listing_type_id' => 'gold_pro', 'titulo' => null, 'ativo' => true, 'posicao' => 1]);
    }

    private function excluir(): void
    {
        app(EstruturaOfertaService::class)->excluir($this->produto->oferta->fresh(), $this->ator);
    }

    private function alvo(string $tipo)
    {
        return $this->r->alvos()->where('listing_type_id', $tipo)->first();
    }

    public function test_produto_fica_solto_com_sku_e_nome_e_rascunho_e_publicacao_intactos(): void
    {
        $pub = PubPublicacao::create(['rascunho_id' => $this->r->id, 'revisao' => $this->r->revisao, 'modelo_publicacao' => 'items', 'status' => 'COMPLETED',
            'chave_idempotencia' => (string) Str::uuid()]);
        $item = PubPublicacaoItem::create(['publicacao_id' => $pub->id, 'indice' => 0, 'listing_type_id' => 'gold_special', 'variante_chave' => ChaveCanonica::UNICA,
            'status' => PubPublicacaoItem::CREATED, 'payload' => ['family_name' => 'Cadeira'], 'resposta' => ['id' => 'MLB9000000001']]);
        $this->produto->oferta->update(['sku' => 'CAD-01-NOVO', 'nome' => 'Cadeira Final']);

        $this->excluir();

        $produto = $this->produto->fresh();
        $this->assertNull($produto->oferta_id);
        $this->assertSame(PubProduto::ORIGEM_PORTAL, $produto->origem);
        $this->assertSame('CAD-01-NOVO', $produto->sku);
        $this->assertSame('Cadeira Final', $produto->nome);
        $this->assertSame('CAD-01-NOVO', $produto->skuExibido());
        $this->assertSame('Cadeira Final', $produto->nomeExibido());
        $this->assertSame(0, EstruturaOferta::count());
        $this->assertSame(1, PubRascunho::count());
        $this->assertSame(['id' => 'MLB9000000001'], $item->fresh()->resposta);
        $this->assertSame(['family_name' => 'Cadeira'], $item->fresh()->payload);
        $this->assertSame(1, PubPublicacao::count());
    }

    public function test_titulo_vazio_recebe_o_planejado_e_o_digitado_fica(): void
    {
        $digitado = $this->alvo('gold_special')->titulo;

        $this->excluir();

        $this->assertSame($digitado, $this->alvo('gold_special')->titulo, 'o digitado não é sobrescrito');
        $this->assertSame('Cadeira Planejada Premium', $this->alvo('gold_pro')->titulo);
    }

    public function test_preco_vazio_recebe_o_efetivo_e_o_digitado_fica(): void
    {
        // A variante única tem 150 digitado no Clássico (CenarioCadeira); Premium vazio.
        $this->assertSame(150.0, (float) PubVariantePreco::where('alvo_id', $this->alvo('gold_special')->id)->value('preco'));
        $this->assertNull(PubVariantePreco::where('alvo_id', $this->alvo('gold_pro')->id)->value('preco'));

        $this->excluir();

        $this->assertSame(150.0, (float) PubVariantePreco::where('alvo_id', $this->alvo('gold_special')->id)->value('preco'));
        $this->assertSame(249.9, (float) PubVariantePreco::where('alvo_id', $this->alvo('gold_pro')->id)->value('preco'));
    }

    public function test_tipo_sem_preco_efetivo_continua_vazio(): void
    {
        $this->efetivos['precos']['gold_pro'] = null;

        $this->excluir();

        $this->assertNull(PubVariantePreco::where('alvo_id', $this->alvo('gold_pro')->id)->value('preco'));
    }

    public function test_revisao_updated_at_e_status_do_rascunho_nao_mudam(): void
    {
        $this->r->update(['status' => PubRascunho::VALIDATED]);
        DB::table('pub_rascunhos')->where('id', $this->r->id)->update(['updated_at' => '2026-01-01 10:00:00']);
        $antes = DB::table('pub_rascunhos')->where('id', $this->r->id)->first();

        $this->excluir();

        $depois = DB::table('pub_rascunhos')->where('id', $this->r->id)->first();
        $this->assertSame($antes->revisao, $depois->revisao);
        $this->assertSame($antes->updated_at, $depois->updated_at);
        $this->assertSame(PubRascunho::VALIDATED, $depois->status);
    }

    public function test_estado_depois_traz_produto_solto_efetivos_nulos_e_valores_congelados(): void
    {
        $this->excluir();

        $e = app(EditorRascunhoService::class)->estado($this->r->fresh());

        $this->assertNull($e['produto']['oferta_id']);
        $this->assertSame(['gold_special' => null, 'gold_pro' => null], $e['efetivos']['titulos']);
        $this->assertSame(['gold_special' => null, 'gold_pro' => null], $e['efetivos']['precos']);
        $titulos = array_column($e['alvos'], 'titulo', 'listing_type_id');
        $this->assertSame('Cadeira Planejada Premium', $titulos['gold_pro']);
        $this->assertSame(150.0, (float) $e['variantes'][0]['precos']['gold_special']);
        $this->assertSame(249.9, (float) $e['variantes'][0]['precos']['gold_pro']);
    }

    public function test_oferta_sem_produto_no_publicador_exclui_como_antes_e_nada_nasce(): void
    {
        $outra = EstruturaOferta::create(['company_id' => $this->empresa->id, 'sku' => 'OUTRA', 'fase' => 'simples', 'nome' => 'Outra']);
        EstruturaAnuncio::create(['oferta_id' => $outra->id, 'tipo' => 'classico', 'titulo' => 'Outra Planejada']);
        $produtos = PubProduto::count();

        app(EstruturaOfertaService::class)->excluir($outra, $this->ator);

        $this->assertNull(EstruturaOferta::find($outra->id));
        $this->assertSame($produtos, PubProduto::count());
        $this->assertSame(1, PubRascunho::count());
    }

    public function test_produto_sem_rascunho_so_congela_sku_e_nome(): void
    {
        $oferta = EstruturaOferta::create(['company_id' => $this->empresa->id, 'sku' => 'SEM-R', 'fase' => 'simples', 'nome' => 'Sem Rascunho']);
        $produto = PubProduto::daOferta($oferta);
        $oferta->update(['nome' => 'Sem Rascunho 2']);

        app(EstruturaOfertaService::class)->excluir($oferta->fresh(), $this->ator);

        $produto = $produto->fresh();
        $this->assertNull($produto->oferta_id);
        $this->assertSame('Sem Rascunho 2', $produto->nome);
    }

    public function test_oferta_componente_de_variacao_nao_toca_no_produto_nem_no_rascunho(): void
    {
        $combo = EstruturaOferta::create(['company_id' => $this->empresa->id, 'sku' => 'CAD-01-CB2', 'fase' => 'combo', 'nome' => 'Combo']);
        $combo->componentes()->create(['componente_id' => $this->produto->oferta_id, 'quantidade' => 2]);

        try {
            $this->excluir();
            $this->fail('devia recusar: a oferta é componente de outra');
        } catch (ValidationException) {
            // esperado
        }

        $this->assertSame($this->produto->oferta_id, $this->produto->fresh()->oferta_id);
        $this->assertNull($this->alvo('gold_pro')->fresh()->titulo);
    }
}
