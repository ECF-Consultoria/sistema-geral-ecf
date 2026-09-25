<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\MlAcervoItem;
use App\Models\MlToken;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\EstruturaConjunto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Os anúncios que a empresa já tem no ML, trazidos pelo OAuth.
 *
 * Os corpos de anúncio são REAIS — `tests/fixtures/phase134/multiget-lote.json`,
 * capturado pela sondagem da Fase 134 (`Http::fake()` nunca sobre shape
 * inventado). Nesse lote, os pares Clássico + Premium do mesmo produto têm o
 * MESMO `SELLER_SKU` (`1808`, `1301-UN-NA`) e `user_product_id` diferentes:
 * é por isso que o casamento é pelo SKU.
 */
class AnunciosMercadoLivreTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function fixture(string $nome): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures/phase134/{$nome}")), true);
    }

    private function conectar($empresa): void
    {
        MlToken::create([
            'company_id' => $empresa->id, 'ml_user_id' => '436501796',
            'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6), 'last_refreshed_at' => now(),
            'status' => 'active', 'connected_at' => now(),
        ]);
    }

    /**
     * A conta devolve os 20 anúncios do lote real numa página de scroll.
     *
     * Um fake SÓ, com uma volta de scroll por leitura: `Http::fake()` acumula
     * e o primeiro stub que casa vence — registrar de novo para a segunda
     * leitura cairia na sequência já esgotada.
     */
    private function fingirConta(int $leituras = 1): void
    {
        $lote = $this->fixture('multiget-lote.json');
        $ids = array_map(fn ($w) => $w['body']['id'], $lote);

        $scroll = Http::sequence();
        foreach (range(1, $leituras) as $_) {
            $scroll->push(['scroll_id' => 'x', 'results' => $ids, 'paging' => ['total' => 20]])
                ->push(['scroll_id' => 'x', 'results' => [], 'paging' => ['total' => 20]]);
        }

        Http::fake([
            '*/items/search*' => $scroll,
            '*/items?*' => Http::response($lote),
        ]);
    }

    public function test_cada_anuncio_real_vira_uma_linha_com_sku_tipo_e_status(): void
    {
        $linhas = array_map(fn ($w) => AnunciosMercadoLivreService::linhaDoAnuncio($w['body']), $this->fixture('multiget-lote.json'));
        $porMlb = collect($linhas)->keyBy('mlb');

        $this->assertSame(['sku' => '1808', 'mlb' => 'MLB5318502460', 'tipo' => 'Premium', 'status' => 'Ativo'],
            array_intersect_key($porMlb['MLB5318502460'], array_flip(['sku', 'mlb', 'tipo', 'status'])));
        $this->assertSame('Clássico', $porMlb['MLB5317224904']['tipo']);
        $this->assertSame('Pausado', $porMlb['MLB5316608806']['status']);
        $this->assertSame('Inativo', $porMlb['MLB7046783144']['status']);   // closed
        $this->assertTrue($porMlb['MLB4009839421']['catalogo']);
        $this->assertNull($porMlb['MLB7046783144']['sku']);                  // veio sem SELLER_SKU
        $this->assertSame(15, collect($linhas)->whereNotNull('sku')->count());
    }

    /** Anúncio com variações: o SKU vem delas só quando TODAS concordam. */
    public function test_sku_das_variacoes_so_quando_todas_concordam(): void
    {
        $item = ['id' => 'MLB1', 'listing_type_id' => 'gold_pro', 'variations' => [
            ['attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'X-1']]],
            ['attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'X-1 ']]],
        ]];
        $this->assertSame('X-1', AnunciosMercadoLivreService::skuDoAnuncio($item));

        $item['variations'][1]['attributes'][0]['value_name'] = 'X-2';
        $this->assertNull(AnunciosMercadoLivreService::skuDoAnuncio($item));

        // Anúncio grátis ou outro tipo não entra: o método só trabalha com Clássico e Premium.
        $this->assertNull(AnunciosMercadoLivreService::linhaDoAnuncio(['id' => 'MLB2', 'listing_type_id' => 'free']));
    }

    /**
     * Importar pelo portal: lê a conta, mostra a prévia (a MESMA da colagem) e
     * só grava ao confirmar. Casa pelo SKU; o resto vai para a espera.
     */
    public function test_importar_casa_pelo_sku_e_o_resto_vai_para_a_espera(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->conectar($empresa);
        $svc = app(EstruturaOfertaService::class);
        [$armario] = $svc->criar($empresa, ['sku' => '1808', 'fase' => 'simples', 'nome' => 'Armário Aéreo'], $ator);
        [$balcao] = $svc->criar($empresa, ['sku' => '1301-UN-NA', 'fase' => 'simples', 'nome' => 'Balcão Cooktop'], $ator);
        [$turim] = $svc->criar($empresa, ['sku' => '1304', 'fase' => 'simples', 'nome' => 'Balcão Turim'], $ator);
        $this->fingirConta(leituras: 2);

        $sessao = $this->entrarNoPortal($empresa);

        // Sob a fila `sync` dos testes, o Job roda dentro do POST.
        $sessao->postJson(route('portal.auth.estrutura.importacao.iniciar'))->assertOk();

        $estado = $sessao->getJson(route('portal.auth.estrutura.importacao.estado'))->assertOk()->json();
        $this->assertSame('pronto', $estado['estado']);
        $this->assertSame(20, $estado['total']);
        $this->assertSame(['novos' => 7, 'atualizados' => 0, 'espera' => 13, 'erros' => 0, 'removidos' => 0], $estado['previa']['totais']);
        $this->assertSame(0, EstruturaAnuncio::count(), 'a prévia não grava');

        $sessao->post(route('portal.auth.estrutura.importacao.aplicar'))->assertSessionHasNoErrors();

        $conjunto = EstruturaConjunto::daEmpresa($empresa);
        $this->assertSame([2, 1, 'ok'], [$conjunto->oferta($armario->id)['classicos'], $conjunto->oferta($armario->id)['premiums'], $conjunto->oferta($armario->id)['situacao']]);
        $this->assertSame('ok', $conjunto->oferta($balcao->id)['situacao']);
        $this->assertSame('falta_premium', $conjunto->oferta($turim->id)['situacao']);
        $this->assertSame(13, EstruturaAnuncioEspera::count());
        $this->assertSame(5, EstruturaAnuncioEspera::where('motivo', 'sem_sku')->count());

        // Importar de novo não duplica: atualiza pelo MLB.
        $sessao->postJson(route('portal.auth.estrutura.importacao.iniciar'))->assertOk();
        $this->assertSame(7, $sessao->getJson(route('portal.auth.estrutura.importacao.estado'))->json('previa.totais.atualizados'));
    }

    public function test_sem_conta_conectada_nao_importa(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->entrarNoPortal($empresa)
            ->postJson(route('portal.auth.estrutura.importacao.iniciar'))
            ->assertStatus(422);
    }

    // ═══ A exceção: buscar e ligar ══════════════════════════════════════════

    private function acervo($empresa, string $mlb, string $titulo, string $tipo = 'gold_special', string $status = 'active'): MlAcervoItem
    {
        return MlAcervoItem::create([
            'company_id' => $empresa->id, 'ml_item_id' => $mlb, 'title' => $titulo,
            'listing_type_id' => $tipo, 'status' => $status, 'catalog_listing' => false,
        ]);
    }

    public function test_busca_so_mostra_anuncios_da_empresa_e_diz_onde_ja_estao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);          // MLB0000000001 está na CAD-01
        $this->acervo($empresa, 'MLB0000000001', 'Cadeira de Jantar Estofada');
        $this->acervo($empresa, 'MLB0000000099', 'Cadeira Jantar Premium Veludo', 'gold_pro');
        $this->acervo($empresa, 'MLB0000000098', 'Cadeira grátis', 'free');
        $outra = $this->empresaDoGabarito();
        $this->acervo($outra, 'MLB0000000097', 'Cadeira de outra empresa');

        $r = $this->entrarNoPortal($empresa)
            ->getJson(route('portal.auth.estrutura.anuncios_ml.buscar', ['q' => 'cadeira']))
            ->assertOk()->json();

        $this->assertEqualsCanonicalizing(['MLB0000000001', 'MLB0000000099'], array_column($r['itens'], 'mlb'));
        $porMlb = collect($r['itens'])->keyBy('mlb');
        $this->assertSame('CAD-01', $porMlb['MLB0000000001']['ligado_a']);
        $this->assertNull($porMlb['MLB0000000099']['ligado_a']);

        // Filtrada pelo tipo — no "Concluir Premium" da agenda.
        $soPremium = $this->entrarNoPortal($empresa)
            ->getJson(route('portal.auth.estrutura.anuncios_ml.buscar', ['q' => 'cadeira', 'tipo' => 'premium']))->json('itens');
        $this->assertSame(['MLB0000000099'], array_column($soPremium, 'mlb'));
    }

    public function test_ligar_usa_o_registro_do_acervo_e_recusa_o_que_ja_esta_em_outra_oferta(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        $this->acervo($empresa, 'MLB0000000099', 'Kit 2 Cadeiras Premium', 'gold_pro', 'paused');
        $this->acervo($empresa, 'MLB0000000001', 'Cadeira de Jantar Estofada');
        $sessao = $this->entrarNoPortal($empresa);

        $sessao->post(route('portal.auth.estrutura.anuncios_ml.ligar', $ofertas['CAD-01-CB2']->id), ['ml_item_id' => 'MLB0000000099'])
            ->assertSessionHasNoErrors();

        $ligado = EstruturaAnuncio::where('codigo_mlb', 'MLB0000000099')->sole();
        $this->assertSame([$ofertas['CAD-01-CB2']->id, 'premium', 'pausado', 'Kit 2 Cadeiras Premium'],
            [$ligado->oferta_id, $ligado->tipo, $ligado->status, $ligado->titulo]);

        // Já está na CAD-01: ligar à CB3 é recusado.
        $sessao->post(route('portal.auth.estrutura.anuncios_ml.ligar', $ofertas['CAD-01-CB3']->id), ['ml_item_id' => 'MLB0000000001'])
            ->assertSessionHasErrors('ml_item_id');
    }

    public function test_ligar_tira_da_espera_e_nao_enxerga_outra_empresa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->acervo($empresa, 'MLB0000000050', 'Mesa Marfim Clássico');
        EstruturaAnuncioEspera::create(['company_id' => $empresa->id, 'sku_colado' => 'MESA-ANTIGA', 'motivo' => 'sem_oferta',
            'tipo' => 'classico', 'status' => 'ativo', 'codigo_mlb' => 'MLB0000000050']);
        $outra = $this->empresaDoGabarito();
        $this->acervo($outra, 'MLB0000000060', 'Alheio');
        $sessao = $this->entrarNoPortal($empresa);

        $sessao->post(route('portal.auth.estrutura.anuncios_ml.ligar', $ofertas['MSA-MR']->id), ['ml_item_id' => 'MLB0000000050'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, EstruturaAnuncioEspera::count());
        // Ganhou o Clássico que estava na espera; o Premium ainda falta.
        $this->assertSame('falta_premium', EstruturaConjunto::daEmpresa($empresa)->oferta($ofertas['MSA-MR']->id)['situacao']);

        $sessao->post(route('portal.auth.estrutura.anuncios_ml.ligar', $ofertas['MSA-MR']->id), ['ml_item_id' => 'MLB0000000060'])
            ->assertNotFound();
    }
}
