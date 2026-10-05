<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\EstruturaProduto;
use App\Models\MlToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-10: busca e sugestão de categoria pelo Portal. Dado público do ML:
 * só o app token sai da nossa casa; o token do cliente nunca (T-167-45). Nada
 * é aceito sozinho (D-06). Nenhuma chamada real: Http::fake com closure por URL.
 */
class CategoriaDoProdutoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private bool $preditorFora = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);

        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/domain_discovery/search')) {
                if ($this->preditorFora) {
                    return Http::response(['message' => 'erro'], 500);
                }

                return Http::response([
                    ['category_id' => 'MLB1', 'category_name' => 'Cristaleiras', 'domain_name' => 'Cristaleiras'],
                    ['category_id' => 'MLB2', 'category_name' => 'Móveis', 'domain_name' => 'Móveis'],
                ]);
            }
            if (preg_match('#/categories/(MLB\d+)$#', $r->url(), $m)) {
                return match ($m[1]) {
                    'MLB1' => Http::response([
                        'id' => 'MLB1', 'name' => 'Cristaleiras', 'children_categories' => [],
                        'path_from_root' => [['id' => 'MLB0', 'name' => 'Casa'], ['id' => 'MLB9', 'name' => 'Móveis'], ['id' => 'MLB1', 'name' => 'Cristaleiras']],
                    ]),
                    'MLB2' => Http::response([
                        'id' => 'MLB2', 'name' => 'Móveis', 'children_categories' => [['id' => 'MLB1', 'name' => 'Cristaleiras']],
                        'path_from_root' => [['id' => 'MLB2', 'name' => 'Móveis']],
                    ]),
                    default => Http::response(['message' => 'erro'], 500),
                };
            }

            return Http::response([], 404);
        });
    }

    private function conectar($empresa): void
    {
        MlToken::create([
            'company_id' => $empresa->id, 'ml_user_id' => '436501796',
            'access_token' => 'token-do-cliente', 'refresh_token' => 'refresh-do-cliente',
            'token_type' => 'bearer', 'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6), 'last_refreshed_at' => now(),
            'status' => 'active', 'connected_at' => now(),
        ]);
    }

    public function test_busca_devolve_caminho_inteiro_e_folha_so_com_o_app_token(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);

        $r = $this->entrarNoPortal($empresa)
            ->getJson(route('portal.auth.estrutura.produtos.categorias', ['q' => 'cristaleira']))
            ->assertOk();

        $this->assertFalse($r->json('indisponivel'));
        $this->assertSame(['MLB1', 'MLB2'], array_column($r->json('categorias'), 'id'));
        $primeira = $r->json('categorias.0');
        $this->assertSame('Casa > Móveis > Cristaleiras', $primeira['caminho_texto']);
        $this->assertTrue($primeira['folha']);
        $this->assertFalse($r->json('categorias.1.folha'));

        // O token do cliente nunca saiu daqui.
        $this->assertNotEmpty(Http::recorded());
        Http::assertNotSent(fn (Request $req) => $req->hasHeader('Authorization', 'Bearer token-do-cliente'));
        Http::assertSent(fn (Request $req) => $req->hasHeader('Authorization', 'Bearer app-token-teste'));
    }

    public function test_busca_com_um_caractere_da_422(): void
    {
        $this->entrarNoPortal($this->empresaDoGabarito())
            ->getJson(route('portal.auth.estrutura.produtos.categorias', ['q' => 'a']))
            ->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_ml_fora_do_ar_responde_200_indisponivel_e_vazio(): void
    {
        $this->preditorFora = true;

        $this->entrarNoPortal($this->empresaDoGabarito())
            ->getJson(route('portal.auth.estrutura.produtos.categorias', ['q' => 'cristaleira']))
            ->assertOk()
            ->assertExactJson(['categorias' => [], 'indisponivel' => true]);
    }

    public function test_sugerir_devolve_a_folha_por_produto_sem_gravar_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $a = EstruturaProduto::create(['company_id' => $empresa->id, 'nome' => 'Cristaleira Rústica', 'codigo' => 'CR-1']);
        $b = EstruturaProduto::create(['company_id' => $empresa->id, 'nome' => 'Mesa Lateral', 'codigo' => 'ML-1']);
        $alheio = EstruturaProduto::create(['company_id' => $outra->id, 'nome' => 'Alheio', 'codigo' => 'AL-1']);

        $r = $this->entrarNoPortal($empresa)
            ->postJson(route('portal.auth.estrutura.produtos.categorias.sugerir'), ['produto_ids' => [$a->id, $b->id, $alheio->id]])
            ->assertOk();

        // O produto de outra empresa não aparece.
        $this->assertSame([$a->id, $b->id], array_column($r->json('sugestoes'), 'produto_id'));
        $this->assertSame('MLB1', $r->json('sugestoes.0.sugestao.id'));
        $this->assertSame('Casa > Móveis > Cristaleiras', $r->json('sugestoes.0.sugestao.caminho_texto'));

        // Nada aceito sozinho (D-06): a categoria dos produtos segue vazia.
        foreach ([$a, $b, $alheio] as $p) {
            $this->assertEmpty($p->fresh()->categoria_ml_id);
        }
        Http::assertNotSent(fn (Request $req) => $req->hasHeader('Authorization', 'Bearer token-do-cliente'));
    }

    public function test_sugerir_com_mais_de_10_produtos_da_422(): void
    {
        $this->entrarNoPortal($this->empresaDoGabarito())
            ->postJson(route('portal.auth.estrutura.produtos.categorias.sugerir'), ['produto_ids' => range(1, 11)])
            ->assertStatus(422)->assertJsonValidationErrors('produto_ids');
    }
}
