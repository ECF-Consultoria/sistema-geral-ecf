<?php

namespace Tests\Feature\Publicador;

use App\Models\MlToken;
use App\Models\User;
use App\Services\Publicador\CategoriaBuscaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * 11/10/2026 — navegar pela árvore de categorias, além da busca. O usuário procurou a categoria de um
 * anúncio que mostrava "… > Cadeiras, Sofás e Banquetas > Sofás > Cadeira" e não achou: a busca é o
 * preditor (até 8 palpites) e "Sofás" é o último nível — o "Cadeira" dali não é categoria. Navegando,
 * cada nível mostra tudo o que existe e onde a árvore acaba.
 *
 * A árvore abaixo é o recorte real medido em produção. Nenhuma chamada real: Http::fake por URL.
 */
class CategoriaArvoreTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** id => [nome, pai, filhas] */
    private const ARVORE = [
        'MLB1574'   => ['Casa, Móveis e Decoração', null, ['MLB436380']],
        'MLB436380' => ['Móveis para Casa', 'MLB1574', ['MLB436389']],
        'MLB436389' => ['Cadeiras, Sofás e Banquetas', 'MLB436380', ['MLB458191', 'MLB1626', 'MLB999']],
        'MLB458191' => ['Poltronas', 'MLB436389', []],
        'MLB1626'   => ['Sofás', 'MLB436389', []],
        // Último nível que não recebe anúncio.
        'MLB999'    => ['Outros', 'MLB436389', []],
    ];

    /** Categorias que o catálogo deixa de responder (500). */
    private array $fora = [];

    private bool $raizesFora = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);

        Http::fake(function (Request $r) {
            if (str_ends_with($r->url(), '/sites/MLB/categories')) {
                return $this->raizesFora
                    ? Http::response(['message' => 'erro'], 500)
                    : Http::response([['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'], ['id' => 'MLB1648', 'name' => 'Informática']]);
            }
            if (preg_match('#/categories/(MLB\d+)$#', $r->url(), $m) && isset(self::ARVORE[$m[1]]) && ! in_array($m[1], $this->fora, true)) {
                return Http::response(self::categoria($m[1]));
            }

            return Http::response(['message' => 'erro'], 500);
        });
    }

    private static function categoria(string $id): array
    {
        [$nome, , $filhas] = self::ARVORE[$id];
        $caminho = [];
        for ($n = $id; $n !== null; $n = self::ARVORE[$n][1]) {
            array_unshift($caminho, ['id' => $n, 'name' => self::ARVORE[$n][0]]);
        }

        return [
            'id' => $id, 'name' => $nome, 'path_from_root' => $caminho,
            'children_categories' => array_map(fn ($f) => ['id' => $f, 'name' => self::ARVORE[$f][0], 'total_items_in_this_category' => 10], $filhas),
            'settings' => ['listing_allowed' => $filhas === [] && $id !== 'MLB999'],
        ];
    }

    private function chamadas(string $final): int
    {
        return count(Http::recorded(fn (Request $q) => str_ends_with($q->url(), $final)));
    }

    public function test_sem_pai_devolve_as_raizes_e_guarda_no_cache(): void
    {
        $nivel = app(CategoriaBuscaService::class)->nivel();

        $this->assertNull($nivel['atual']);
        $this->assertSame([], $nivel['caminho']);
        $this->assertSame([
            ['id' => 'MLB1574', 'nome' => 'Casa, Móveis e Decoração', 'folha' => false, 'anunciavel' => false],
            ['id' => 'MLB1648', 'nome' => 'Informática', 'folha' => false, 'anunciavel' => false],
        ], $nivel['filhas']);

        app(CategoriaBuscaService::class)->nivel('');
        $this->assertSame(1, $this->chamadas('/sites/MLB/categories'), 'a 2ª leitura sai do cache');
    }

    public function test_um_nivel_traz_o_caminho_as_filhas_e_quem_e_o_ultimo_nivel(): void
    {
        $nivel = app(CategoriaBuscaService::class)->nivel('MLB436389');

        $this->assertSame(['id' => 'MLB436389', 'nome' => 'Cadeiras, Sofás e Banquetas', 'folha' => false, 'anunciavel' => false], $nivel['atual']);
        $this->assertSame(['Casa, Móveis e Decoração', 'Móveis para Casa', 'Cadeiras, Sofás e Banquetas'], array_column($nivel['caminho'], 'nome'));
        $this->assertSame(['MLB1574', 'MLB436380', 'MLB436389'], array_column($nivel['caminho'], 'id'));
        $this->assertSame([
            ['id' => 'MLB458191', 'nome' => 'Poltronas', 'folha' => true, 'anunciavel' => true],
            // O caso do usuário: "Sofás" é o último nível; não há "Cadeira" abaixo dele.
            ['id' => 'MLB1626', 'nome' => 'Sofás', 'folha' => true, 'anunciavel' => true],
            ['id' => 'MLB999', 'nome' => 'Outros', 'folha' => true, 'anunciavel' => false],
        ], $nivel['filhas']);

        // Entrar numa filha já lida não chama o catálogo de novo (mesmo cache de 7 dias da busca).
        $antes = count(Http::recorded());
        $sofas = app(CategoriaBuscaService::class)->nivel('MLB1626');
        $this->assertSame($antes, count(Http::recorded()));
        $this->assertSame(['id' => 'MLB1626', 'nome' => 'Sofás', 'folha' => true, 'anunciavel' => true], $sofas['atual']);
        $this->assertSame([], $sofas['filhas']);
        $this->assertSame('Sofás', $sofas['caminho'][3]['nome']);
    }

    public function test_filha_que_nao_respondeu_fica_sem_saber_se_e_a_ultima_e_nao_derruba_o_nivel(): void
    {
        $this->fora = ['MLB1626'];

        $filhas = array_column(app(CategoriaBuscaService::class)->nivel('MLB436389')['filhas'], null, 'id');

        $this->assertTrue($filhas['MLB458191']['folha']);
        $this->assertNull($filhas['MLB1626']['folha'], 'não deu para saber: a tela descobre ao entrar');
        $this->assertNull($filhas['MLB1626']['anunciavel']);
    }

    public function test_catalogo_fora_devolve_nulo_e_a_proxima_tentativa_nao_fica_presa_no_vazio(): void
    {
        $this->fora = ['MLB436389'];
        $this->raizesFora = true;
        $servico = app(CategoriaBuscaService::class);

        $this->assertNull($servico->nivel('MLB436389'));
        $this->assertNull($servico->nivel());

        $this->fora = [];
        $this->raizesFora = false;
        $this->assertSame('Cadeiras, Sofás e Banquetas', $servico->nivel('MLB436389')['atual']['nome']);
        $this->assertCount(2, $servico->nivel()['filhas']);
    }

    public function test_rota_do_editor_interno_so_para_admin_e_com_id_valido(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $rota = fn (array $q = []) => route('mlb.anuncios.publicador.categorias.arvore', $q);

        $this->actingAs($admin)->getJson($rota())->assertOk()
            ->assertJsonPath('indisponivel', false)->assertJsonPath('atual', null)->assertJsonPath('filhas.0.id', 'MLB1574');
        $this->actingAs($admin)->getJson($rota(['pai' => 'MLB436389']))->assertOk()
            ->assertJsonPath('atual.nome', 'Cadeiras, Sofás e Banquetas')->assertJsonPath('filhas.1.nome', 'Sofás')->assertJsonPath('filhas.1.folha', true);

        // Id que não é de categoria nunca vira chamada ao catálogo.
        $antes = count(Http::recorded());
        $this->actingAs($admin)->getJson($rota(['pai' => 'MLB12/attributes']))->assertUnprocessable();
        $this->actingAs($admin)->getJson($rota(['pai' => 'cadeira']))->assertUnprocessable();
        $this->assertSame($antes, count(Http::recorded()));

        // Catálogo fora do ar: 200 com `indisponivel`, nunca erro.
        $this->fora = ['MLB436380'];
        $this->actingAs($admin)->getJson($rota(['pai' => 'MLB436380']))->assertOk()
            ->assertExactJson(['atual' => null, 'caminho' => [], 'filhas' => [], 'indisponivel' => true]);

        $consultor = User::factory()->create(['role' => 'consultor']);
        $this->actingAs($consultor)->getJson($rota())->assertForbidden();
    }

    public function test_portal_navega_so_com_o_app_token_e_exige_sessao(): void
    {
        $this->get('/portal/estrutura/produtos/categorias/arvore')->assertRedirect();

        $empresa = $this->empresaDoGabarito();
        MlToken::create([
            'company_id' => $empresa->id, 'ml_user_id' => '436501796',
            'access_token' => 'token-do-cliente', 'refresh_token' => 'refresh-do-cliente',
            'token_type' => 'bearer', 'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6), 'last_refreshed_at' => now(),
            'status' => 'active', 'connected_at' => now(),
        ]);
        $sessao = $this->entrarNoPortal($empresa);

        $sessao->getJson(route('portal.auth.estrutura.produtos.categorias.arvore'))->assertOk()
            ->assertJsonPath('filhas.0.nome', 'Casa, Móveis e Decoração')->assertJsonPath('indisponivel', false);
        $r = $sessao->getJson(route('portal.auth.estrutura.produtos.categorias.arvore', ['pai' => 'MLB436389']))->assertOk();
        $this->assertSame(['Poltronas', 'Sofás', 'Outros'], array_column($r->json('filhas'), 'nome'));
        $this->assertSame('Móveis para Casa', $r->json('caminho.1.nome'));

        $sessao->getJson(route('portal.auth.estrutura.produtos.categorias.arvore', ['pai' => 'x']))->assertStatus(422)->assertJsonValidationErrors('pai');

        // O token do cliente nunca saiu daqui.
        Http::assertNotSent(fn (Request $req) => $req->hasHeader('Authorization', 'Bearer token-do-cliente'));
        Http::assertSent(fn (Request $req) => $req->hasHeader('Authorization', 'Bearer app-token-teste'));
    }
}
