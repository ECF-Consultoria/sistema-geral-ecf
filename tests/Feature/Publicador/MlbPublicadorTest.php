<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\ConferirRascunhoJob;
use App\Jobs\Publicador\PublicarRascunhoJob;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlCategoriaSchema;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\ConferenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * O editor INTERNO do Publicador pelo HTTP (Fase 164, plano 08): o mesmo contrato do piloto do
 * Portal, agora por produto e só para admin — abrir, cada parte que a pessoa edita, fotos,
 * conferir e publicar, sobre o gabarito da planilha e com o ML simulado pelas respostas reais
 * da sondagem. A segurança (403/404/IDOR/trava) está em MlbPublicadorAcessoTest.
 */
class MlbPublicadorTest extends TestCase
{
    use CarregaSchemas;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private Company $empresa;

    private array $ofertas;

    private int $fotosEnviadas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

        $this->empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($this->empresa);
        $this->ofertas = $this->listaDoGabarito($this->empresa, $ator);
        $this->anunciosDoGabarito($this->ofertas, $ator);
        config(['publicador.contas_liberadas.companies' => [$this->empresa->id]]);

        MlToken::create(['company_id' => $this->empresa->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class), fn () => null));

        foreach ([self::CADEIRA, self::CAMISETA] as $cat) {
            $s = self::schema($cat);
            MlCategoriaSchema::create(['category_id' => $cat, 'domain_id' => $s->dominio(), 'categoria' => $s->categoria, 'atributos' => $s->atributos,
                'technical_specs' => $s->technicalSpecs, 'sale_terms' => $s->saleTerms, 'schema_hash' => $s->hash(), 'fetched_at' => now()]);
        }

        $fixture = fn (string $r) => json_decode(file_get_contents(base_path("tests/fixtures-ml/sondagem/{$r}.json")), true)['resposta'];
        Http::fake([
            '*/users/me' => Http::response($fixture('conta/usuario')),
            '*/shipping_preferences*' => Http::response($fixture('conta/shipping_preferences')),
            '*/pictures/items/upload' => function () {
                $this->fotosEnviadas++;

                return Http::response(['id' => "PIC-{$this->fotosEnviadas}", 'variations' => [['secure_url' => "https://http2.mlstatic.com/D_{$this->fotosEnviadas}-O.jpg"]]]);
            },
            '*/attributes/conditional*' => Http::response(['required_attributes' => [], 'callbacks' => [], 'status' => 200]),
            '*/oauth/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 21600]),
            '*/sites/MLB/listing_prices*' => Http::response($fixture('publico/categorias/MLB193945/listing_prices_gold_special_me2_drop_off')),
            '*/shipping_options/free*' => Http::response(['coverage' => ['all_country' => ['list_cost' => 62.35]]]),
        ]);
    }

    /** A mesa: um admin logado, sem Vite. */
    private function mesa(): static
    {
        return $this->withoutVite()->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function produto(): PubProduto
    {
        return PubProduto::daOferta($this->ofertas['CAD-01-CB3']);
    }

    private function rota(string $nome, array $extra = []): string
    {
        return route("mlb.anuncios.publicador.{$nome}", ['produto' => $this->produto()->id, ...$extra]);
    }

    public function test_abrir_cria_o_rascunho_com_os_tipos_que_faltam_e_le_a_conta(): void
    {
        $r = $this->mesa()->getJson($this->rota('abrir'))->assertOk()->json();

        $this->assertSame('CAD-01-CB3', $r['produto']['sku']);
        $this->assertTrue($r['publicacao_liberada']);
        $this->assertSame(['gold_special', 'gold_pro'], array_column($r['alvos'], 'listing_type_id'));
        $this->assertSame([true, true], array_column($r['alvos'], 'ativo'), 'a CB3 não tem anúncio no gabarito');
        $this->assertSame('USER_PRODUCTS', $r['conta']['modelo']);
        $this->assertFalse($r['conta']['multi_deposito']);
        $this->assertSame(['SELLER_SKU' => ['value_name' => 'CAD-01-CB3']], $r['variantes'][0]['atributos'], 'o SKU da oferta já vem na variante');
        $this->assertNull($r['schema'], 'sem categoria ainda');

        // Abrir de novo não cria outro.
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->assertSame(1, PubRascunho::count());
    }

    public function test_categoria_formulario_e_salvar_por_parte(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();

        $r = $this->mesa()->putJson($this->rota('categoria'), ['categoria_id' => self::CADEIRA])->assertOk()->json();
        $this->assertSame(self::CADEIRA, $r['rascunho']['categoria_id']);
        $this->assertTrue($r['publicacao_liberada']);
        $this->assertSame('PRINCIPAIS', $r['schema']['atributos']['BRAND']['secao']);
        $this->assertNotEmpty($r['schema']['grupos']);
        $this->assertContains('V-ATT-12', array_column($r['problemas'], 'regra'), 'a marca ainda falta');
        $revisao = $r['rascunho']['revisao'];

        $r = $this->mesa()->putJson($this->rota('salvar'), [
            'atributos' => ['BRAND' => ['value_name' => 'ECF'], 'MODEL' => ['value_name' => 'Executiva']],
            'alvos' => [['listing_type_id' => 'gold_special', 'titulo' => 'Kit 3 Cadeiras Executivas ECF', 'ativo' => true], ['listing_type_id' => 'gold_pro', 'ativo' => false]],
            'condicao' => 'new',
            'garantia' => ['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias'],
        ])->assertOk()->json();

        $this->assertNotContains('V-ATT-12', array_column($r['problemas'], 'regra'));
        $this->assertSame('Kit 3 Cadeiras Executivas ECF', $r['alvos'][0]['titulo']);
        $this->assertFalse($r['alvos'][1]['ativo']);
        $this->assertSame(90, $r['rascunho']['garantia']['tempo']);
        $this->assertGreaterThan($revisao, $r['rascunho']['revisao'], 'toda edição sobe a revisão');

        $this->mesa()->putJson($this->rota('salvar'), ['condicao' => 'quebrado'])->assertUnprocessable();
        $this->mesa()->putJson($this->rota('categoria'), ['categoria_id' => 'xyz'])->assertUnprocessable();
    }

    public function test_eixos_geram_as_variantes_e_os_dados_por_variante_sao_gravados(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->mesa()->putJson($this->rota('categoria'), ['categoria_id' => self::CADEIRA])->assertOk();

        $r = $this->mesa()->putJson($this->rota('eixos'), ['eixos' => [
            ['chave' => 'COLOR', 'nome' => 'Cor', 'defines_picture' => true, 'valores' => [['id' => '52049', 'nome' => 'Preto'], ['id' => '52028', 'nome' => 'Azul']]],
        ]])->assertOk()->json();

        $this->assertSame(['COLOR=id:52049', 'COLOR=id:52028'], array_column($r['variantes'], 'chave'));
        $this->assertSame(['Preto', 'Azul'], array_column($r['variantes'], 'rotulo'));
        // A galeria geral é a coluna fixa da tela; os grupos são as cores (Cor define a foto).
        $this->assertSame(['COLOR=id:52049', 'COLOR=id:52028'], array_column($r['grupos_imagem'], 'chave'));

        $r = $this->mesa()->putJson($this->rota('variantes'), ['variantes' => [
            'COLOR=id:52049' => ['estoque' => 4, 'precos' => ['gold_special' => 199.9], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CB3-PRETO']]],
            'COLOR=id:52028' => ['ativa' => false],
        ]])->assertOk()->json();

        $this->assertSame(4, $r['variantes'][0]['estoque']);
        $this->assertSame(199.9, $r['variantes'][0]['precos']['gold_special']);
        $this->assertFalse($r['variantes'][1]['ativa']);

        // Mais de 3 eixos não passa.
        $quatro = array_map(fn ($i) => ['chave' => "~custom{$i}", 'nome' => "E{$i}", 'valores' => [['nome' => 'a']]], range(1, 4));
        $this->mesa()->putJson($this->rota('eixos'), ['eixos' => $quatro])->assertUnprocessable();
    }

    public function test_foto_sobe_entra_na_geral_e_foto_pequena_volta_com_o_problema(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();

        $r = $this->mesa()->post($this->rota('fotos'), ['imagem' => UploadedFile::fake()->image('capa.jpg', 1200, 1200)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertTrue($r['foto']['nova']);
        $this->assertSame('uploaded', $r['imagens'][0]['upload_status']);
        $this->assertSame('https://http2.mlstatic.com/D_1-O.jpg', $r['imagens'][0]['url']);
        $this->assertSame([['imagem' => $r['foto']['id'], 'grupo' => 'GENERAL', 'posicao' => 0]], $r['atribuicoes']);

        $r = $this->mesa()->post($this->rota('fotos'), ['imagem' => UploadedFile::fake()->image('mini.jpg', 300, 300)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertNull($r['foto']['id']);
        $this->assertSame('V-IMG-03', $r['foto']['problemas'][0]['regra']);
        $this->assertSame(1, PubImagem::count());

        $id = PubImagem::value('id');
        $this->mesa()->deleteJson($this->rota('fotos.remover', ['imagem' => $id]))->assertOk();
        $this->assertSame(0, PubImagem::count());
    }

    public function test_conferir_vai_para_a_fila_e_publicar_sem_conferencia_e_recusado(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();

        $this->mesa()->postJson($this->rota('conferir'))->assertStatus(202)->assertJson(['conferindo' => true, 'publicacao_liberada' => true]);
        Queue::assertPushedOn('high', ConferirRascunhoJob::class);

        $this->mesa()->postJson($this->rota('publicar'), ['ciente' => true])->assertUnprocessable()->assertJson(['regra' => 'RN-90']);
        Queue::assertNotPushed(PublicarRascunhoJob::class);
    }

    public function test_publicar_com_conferencia_valida_dispara_o_job_com_o_ator_da_equipe(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Dev ECF']);
        $this->withoutVite()->actingAs($admin)->getJson($this->rota('abrir'))->assertOk();
        $r = PubRascunho::first();
        // A conferência aprovada desta revisão (o caminho dela está no ConferenciaTest), com o vendedor lido.
        $r->validacoes()->create(['revisao' => $r->revisao, 'camada' => 'L3', 'plano_hash' => str_repeat('a', 64), 'resultado' => ConferenciaService::OK, 'issues' => [],
            'respostas_ml' => ['conta' => ['sellerId' => '1555596317']]]);

        $estado = $this->withoutVite()->actingAs($admin)->postJson($this->rota('publicar'))->assertStatus(202)->json();

        Queue::assertPushedOn('high', PublicarRascunhoJob::class);
        $this->assertSame('RUNNING', $estado['publicacao']['status']);
        $this->assertSame('PUBLISHING', $estado['rascunho']['status']);
        $this->assertTrue($estado['publicacao_liberada']);
        $ator = (array) $r->publicacoes()->firstOrFail()->ator;
        $this->assertTrue($ator['equipe']);
        $this->assertSame($admin->id, $ator['id']);
    }

    public function test_simulador_usa_tarifa_e_frete_do_ml(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->mesa()->putJson($this->rota('categoria'), ['categoria_id' => self::CADEIRA])->assertOk();
        $this->mesa()->putJson($this->rota('salvar'), ['atributos' => [
            'SELLER_PACKAGE_HEIGHT' => ['value_name' => '15 cm'], 'SELLER_PACKAGE_WIDTH' => ['value_name' => '15 cm'],
            'SELLER_PACKAGE_LENGTH' => ['value_name' => '20 cm'], 'SELLER_PACKAGE_WEIGHT' => ['value_name' => '500 g'],
        ]])->assertOk();
        $this->mesa()->putJson($this->rota('variantes'), ['variantes' => ['__single__' => ['precos' => ['gold_special' => 150]]]])->assertOk();

        $s = $this->mesa()->getJson($this->rota('simular'))->assertOk()->json('simulacao');

        // O print: 150 − 16,50 − 62,35 = 71,15 (TC-105).
        $this->assertSame(71.15, $s['gold_special']['voce_recebe']);
        $frete = collect(Http::recorded(fn (Request $q) => str_contains($q->url(), 'shipping_options/free')))->first()[0];
        $this->assertSame('15x15x20,500', $frete->data()['dimensions']);
    }

    /** SC4: produto de MlbEmpresa SEM Company, com o token em `mlb_empresa_id` e conta liberada, abre e publica pelo mesmo HTTP. */
    public function test_produto_de_mlb_empresa_sem_company_abre_e_publica_com_o_token_dela(): void
    {
        $e = MlbEmpresa::create(['nome' => 'Loja Incubadora', 'projeto' => 'Incubadora'])->fresh();
        MlToken::create(['company_id' => null, 'mlb_empresa_id' => $e->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-token-mlb-empresa', 'refresh_token' => 'x',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => [$e->id]]]);
        $p = PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => 'INC-01', 'nome' => 'Produto da Incubadora', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        $r = $this->mesa()->getJson(route('mlb.anuncios.publicador.abrir', $p->id))->assertOk()->json();

        $this->assertSame('INC-01', $r['produto']['sku']);
        $this->assertTrue($r['publicacao_liberada']);
        $this->assertNull($r['conta']['erro'], json_encode($r['conta']));
        $bearers = Http::recorded(fn (Request $q) => str_contains($q->url(), '/users/me'))->map(fn ($par) => $par[0]->header('Authorization')[0] ?? '')->unique()->values()->all();
        $this->assertSame(['Bearer fake-token-mlb-empresa'], $bearers);

        $rasc = PubRascunho::where('produto_id', $p->id)->firstOrFail();
        $rasc->validacoes()->create(['revisao' => $rasc->revisao, 'camada' => 'L3', 'plano_hash' => str_repeat('a', 64), 'resultado' => ConferenciaService::OK, 'issues' => [],
            'respostas_ml' => ['conta' => ['sellerId' => '1555596317']]]);
        $this->mesa()->postJson(route('mlb.anuncios.publicador.publicar', $p->id))->assertStatus(202)->assertJsonPath('publicacao.status', 'RUNNING');
        Queue::assertPushedOn('high', PublicarRascunhoJob::class);
    }
}
