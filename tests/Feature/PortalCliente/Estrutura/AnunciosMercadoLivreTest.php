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
     * A conta do lote real, respondendo à busca por SKU (`seller_sku`) com os
     * anúncios que têm aquele SKU. O acervo local recebe todos, MENOS
     * `$foraDoAcervo` — o anúncio publicado depois do sync da madrugada, que
     * tem de vir pelo multiget.
     */
    private function fingirConta($empresa, array $foraDoAcervo = []): void
    {
        $lote = $this->fixture('multiget-lote.json');
        $idsPorSku = [];
        foreach ($lote as $w) {
            $sku = AnunciosMercadoLivreService::skuDoAnuncio($w['body']);
            if ($sku) {
                $idsPorSku[$sku][] = $w['body']['id'];
            }
            if (! in_array($w['body']['id'], $foraDoAcervo, true)) {
                MlAcervoItem::create([
                    'company_id' => $empresa->id, 'ml_item_id' => $w['body']['id'], 'title' => $w['body']['title'],
                    'listing_type_id' => $w['body']['listing_type_id'], 'status' => $w['body']['status'],
                    'catalog_listing' => (bool) ($w['body']['catalog_listing'] ?? false),
                ]);
            }
        }

        Http::fake([
            '*/items/search*' => function ($request) use ($idsPorSku) {
                $ids = $idsPorSku[$request->data()['seller_sku'] ?? ''] ?? [];

                return Http::response(['results' => $ids, 'paging' => ['total' => count($ids), 'offset' => 0, 'limit' => 50]]);
            },
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
     * Importar pelo portal: para cada SKU de oferta, pergunta ao ML quais
     * anúncios têm aquele SKU; mostra a prévia (a MESMA da colagem) e só grava
     * ao confirmar. Anúncio de SKU que nenhuma oferta tem NÃO vem — numa conta
     * de 100 mil anúncios, isso lotaria "aguardando oferta"; ele se acha pela
     * busca manual.
     */
    public function test_importar_busca_pelo_sku_de_cada_oferta(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->conectar($empresa);
        $svc = app(EstruturaOfertaService::class);
        [$armario] = $svc->criar($empresa, ['sku' => '1808', 'fase' => 'simples', 'nome' => 'Armário Aéreo'], $ator);
        [$balcao] = $svc->criar($empresa, ['sku' => '1301-UN-NA', 'fase' => 'simples', 'nome' => 'Balcão Cooktop'], $ator);
        [$turim] = $svc->criar($empresa, ['sku' => '1304', 'fase' => 'simples', 'nome' => 'Balcão Turim'], $ator);
        $svc->criar($empresa, ['sku' => 'SEM-NADA-NO-ML', 'fase' => 'simples'], $ator);
        // O Premium do balcão foi publicado hoje: ainda não está no acervo.
        $this->fingirConta($empresa, foraDoAcervo: ['MLB5317120924']);

        $sessao = $this->entrarNoPortal($empresa);

        // Sob a fila `sync` dos testes, o Job roda dentro do POST.
        $sessao->postJson(route('portal.auth.estrutura.importacao.iniciar'))->assertOk();

        $estado = $sessao->getJson(route('portal.auth.estrutura.importacao.estado'))->assertOk()->json();
        $this->assertSame('pronto', $estado['estado']);
        $this->assertSame([7, 4, 1], [$estado['total'], $estado['skus'], $estado['sem_anuncio']]);
        $this->assertSame(['novos' => 7, 'atualizados' => 0, 'espera' => 0, 'erros' => 0, 'removidos' => 0], $estado['previa']['totais']);
        $this->assertSame(0, EstruturaAnuncio::count(), 'a prévia não grava');

        // Uma busca por SKU, e o multiget só para o que faltava no acervo.
        Http::assertSentCount(4 + 1);

        $sessao->post(route('portal.auth.estrutura.importacao.aplicar'))->assertSessionHasNoErrors();

        $conjunto = EstruturaConjunto::daEmpresa($empresa);
        $this->assertSame([2, 1, 'ok'], [$conjunto->oferta($armario->id)['classicos'], $conjunto->oferta($armario->id)['premiums'], $conjunto->oferta($armario->id)['situacao']]);
        $this->assertSame('ok', $conjunto->oferta($balcao->id)['situacao']);
        $this->assertSame('falta_premium', $conjunto->oferta($turim->id)['situacao']);
        $this->assertSame(0, EstruturaAnuncioEspera::count());

        // Importar de novo não duplica: atualiza pelo MLB.
        $sessao->postJson(route('portal.auth.estrutura.importacao.iniciar'))->assertOk();
        $this->assertSame(7, $sessao->getJson(route('portal.auth.estrutura.importacao.estado'))->json('previa.totais.atualizados'));
        $this->assertSame(7, EstruturaAnuncio::count());
    }

    public function test_sem_ofertas_nao_importa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);

        $this->entrarNoPortal($empresa)
            ->postJson(route('portal.auth.estrutura.importacao.iniciar'))
            ->assertStatus(422)
            ->assertJsonPath('errors.importacao.0', fn ($m) => str_contains($m, 'Cadastre as ofertas primeiro'));
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
