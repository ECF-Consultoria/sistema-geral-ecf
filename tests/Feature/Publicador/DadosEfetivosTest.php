<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\PrecificacaoEstrutura;
use App\Services\Publicador\DadosEfetivosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * O título planejado (aba Anúncios) e o preço da Precificação, lidos do
 * módulo de verdade — o mesmo gabarito do Anunciar antigo. O que a conferência
 * e a publicação recebem por `comEfetivos()`.
 */
class DadosEfetivosTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    public function test_titulo_planejado_e_preco_anunciado_por_tipo_e_o_preco_acompanha_a_precificacao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);
        $cb3 = $ofertas['CAD-01-CB3'];

        $sessao->post(route('portal.auth.estrutura.anuncios.criar', $cb3->id), ['tipo' => 'classico', 'titulo' => 'Kit 3 Cadeiras 01 Madeira Maciça'])->assertSessionHasNoErrors();
        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $cb3->id), ['custo' => '300', 'frete_classico' => '20', 'frete_premium' => '25'])->assertSessionHasNoErrors();

        $e = app(DadosEfetivosService::class)->daOferta($cb3->fresh());

        $this->assertSame(['gold_special' => 'Kit 3 Cadeiras 01 Madeira Maciça', 'gold_pro' => null], $e['titulos'], 'Premium sem título planejado fica vazio');
        $this->assertSame((float) PrecificacaoEstrutura::preco(300, 20, 11.5, 19, 0, 0, 20)['anunciado'], $e['precos']['gold_special']);
        $this->assertSame((float) PrecificacaoEstrutura::preco(300, 25, 16.5, 19, 0, 0, 20)['anunciado'], $e['precos']['gold_pro']);
        $this->assertSame([], $e['mlbs']);

        // O custo mudou na Precificação: o efetivo muda junto (não congela).
        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $cb3->id), ['custo' => '350', 'frete_classico' => '20', 'frete_premium' => '25'])->assertSessionHasNoErrors();
        $this->assertSame((float) PrecificacaoEstrutura::preco(350, 20, 11.5, 19, 0, 0, 20)['anunciado'], app(DadosEfetivosService::class)->daOferta($cb3->fresh())['precos']['gold_special']);

        // O MLB colado na aba Anúncios entra na lista dos que já são desta oferta.
        EstruturaAnuncio::where('oferta_id', $cb3->id)->update(['codigo_mlb' => 'MLB4000000001']);
        $this->assertSame(['MLB4000000001'], app(DadosEfetivosService::class)->daOferta($cb3->fresh())['mlbs']);
    }

    public function test_produto_sem_oferta_nao_tem_efetivos(): void
    {
        $empresa = Company::factory()->create();
        $produto = PubProduto::create(['company_id' => $empresa->id, 'sku' => 'SOLTO-1', 'nome' => 'Solto', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        $e = app(DadosEfetivosService::class)->daProduto($produto);

        $this->assertSame(['gold_special' => null, 'gold_pro' => null], $e['titulos']);
        $this->assertSame(['gold_special' => null, 'gold_pro' => null], $e['precos']);
        $this->assertSame([], $e['mlbs']);
    }

    public function test_produto_do_portal_usa_os_efetivos_da_oferta(): void
    {
        $empresa = Company::factory()->create();
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira']);
        EstruturaAnuncio::create(['oferta_id' => $oferta->id, 'tipo' => 'classico', 'titulo' => 'Cadeira Planejada', 'codigo_mlb' => 'MLB4000000009', 'status' => 'ativo']);
        $produto = PubProduto::daOferta($oferta);
        $svc = app(DadosEfetivosService::class);

        $this->assertSame($svc->daOferta($oferta), $svc->daProduto($produto));
        $this->assertSame('Cadeira Planejada', $svc->daProduto($produto)['titulos']['gold_special']);
    }
}
