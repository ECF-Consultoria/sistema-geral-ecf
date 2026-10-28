<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\MlAcervoItem;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\AnunciosNoPortal;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\ProdutosDoMercadoLivreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\TestCase;

/**
 * "Importar do Mercado Livre" (28/10/2026): o anúncio que o próprio Portal + Publicador publicou NÃO é
 * recomendado nem importado de novo — o produto dele já está no Portal. O usuário viu, em produção, os
 * anúncios da #459 (publicados pelo Publicador) com "Recomendado importar".
 */
class ImportarDoMlPublicadoPeloPortalTest extends TestCase
{
    use CenarioDoPreparo;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarPreparo();
    }

    public function test_anuncio_publicado_pelo_publicador_ja_esta_no_portal(): void
    {
        $produto = $this->produtoDoPortal('CAD');
        $this->salvarERodar($produto);
        $rascunho = $this->rascunhoDo($produto);

        // A publicação que o Publicador gravou ao criar o anúncio no ML.
        $publicacao = DB::table('pub_publicacoes')->insertGetId([
            'rascunho_id' => $rascunho->id, 'revisao' => 1, 'modelo_publicacao' => 'user_products',
            'chave_idempotencia' => (string) Str::uuid(), 'status' => 'PUBLISHED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pub_publicacao_itens')->insert([
            'publicacao_id' => $publicacao, 'indice' => 0, 'listing_type_id' => 'gold_special', 'variante_chave' => 'unica',
            'status' => 'CREATED', 'ml_item_id' => 'MLB900', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['MLB900', 'MLB901'] as $mlb) {
            MlAcervoItem::create(['company_id' => $this->empresa->id, 'ml_item_id' => $mlb, 'title' => "Anúncio {$mlb}",
                'status' => 'active', 'sold_quantity' => 1, 'nota_ecf' => 30, 'catalog_listing' => false]);
        }

        $noPortal = app(AnunciosNoPortal::class)->daEmpresa($this->empresa);
        $this->assertSame(['MLB900'], array_keys($noPortal));
        $this->assertSame(['produto_id' => $produto->id, 'origem' => AnunciosNoPortal::PUBLICADOR, 'nome' => $produto->nome], $noPortal['MLB900']);

        $lista = collect(app(ProdutosDoMercadoLivreService::class)->candidatos($this->empresa)['itens'])->keyBy('mlb');
        $this->assertSame('publicador', $lista['MLB900']['no_portal']['origem']);
        $this->assertNull($lista['MLB901']['no_portal']);

        $soFora = app(ProdutosDoMercadoLivreService::class)->candidatos($this->empresa, soNovos: true)['itens'];
        $this->assertSame(['MLB901'], array_column($soFora, 'mlb'));
    }
}
