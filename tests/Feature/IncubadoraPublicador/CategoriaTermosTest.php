<?php

namespace Tests\Feature\IncubadoraPublicador;

use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Publicador da Incubadora, passo 1: nome do produto → categoria → termos mais
 * buscados. Módulo oculto: só o Dev entra, mesmo com a URL na mão.
 */
class CategoriaTermosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function dev(): User
    {
        $u = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $u->forceFill(['is_dev' => true])->save();

        return $u->refresh();
    }

    /** @param  array<string, mixed>  $extra  stubs que vencem os padrões (o 1º stub que casa é o usado) */
    private function fakeMl(array $extra = []): void
    {
        Http::fake($extra + [
            'api.mercadolibre.com/oauth/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 21600]),
            'api.mercadolibre.com/sites/MLB/domain_discovery/search*' => Http::response([
                ['domain_id' => 'MLB-DINING_TABLES', 'domain_name' => 'Mesas de jantar', 'category_id' => 'MLB100', 'category_name' => 'Mesas de Jantar'],
                ['domain_id' => 'MLB-COFFEE_TABLES', 'domain_name' => 'Mesas de centro', 'category_id' => 'MLB200', 'category_name' => 'Mesas de Centro'],
                ['domain_id' => 'MLB-DINING_TABLES', 'domain_name' => 'Mesas de jantar', 'category_id' => 'MLB100', 'category_name' => 'Mesas de Jantar'],
            ]),
            'api.mercadolibre.com/categories/MLB100' => Http::response([
                'id' => 'MLB100', 'name' => 'Mesas de Jantar', 'children_categories' => [],
                'path_from_root' => [
                    ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
                    ['id' => 'MLB10', 'name' => 'Mesas'],
                    ['id' => 'MLB100', 'name' => 'Mesas de Jantar'],
                ],
            ]),
            'api.mercadolibre.com/categories/MLB200' => Http::response([
                'id' => 'MLB200', 'name' => 'Mesas de Centro', 'children_categories' => [],
                'path_from_root' => [
                    ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
                    ['id' => 'MLB200', 'name' => 'Mesas de Centro'],
                ],
            ]),
            'api.mercadolibre.com/categories/MLB10' => Http::response([
                'id' => 'MLB10', 'name' => 'Mesas', 'children_categories' => [['id' => 'MLB100']],
                'path_from_root' => [
                    ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
                    ['id' => 'MLB10', 'name' => 'Mesas'],
                ],
            ]),
        ]);
    }

    /** @return array<int, array{keyword: string, url: string}> */
    private function trends(int $quantos): array
    {
        $lista = [];
        for ($i = 1; $i <= $quantos; $i++) {
            $lista[] = ['keyword' => "termo {$i}", 'url' => "https://lista.mercadolivre.com.br/termo-{$i}"];
        }

        return $lista;
    }

    public function test_quem_nao_e_dev_recebe_404_mesmo_com_a_url(): void
    {
        $this->fakeMl();
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin)->get('/incubadora/publicador')->assertNotFound();
        $this->actingAs($admin)->getJson('/incubadora/publicador/categorias?produto=mesa')->assertNotFound();
        $this->actingAs($admin)->getJson('/incubadora/publicador/categorias/MLB100/termos')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_dev_abre_a_tela(): void
    {
        $this->actingAs($this->dev())
            ->get('/incubadora/publicador')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Incubadora/Publicador/Index'));
    }

    public function test_sync_cria_o_modulo_ja_oculto(): void
    {
        $this->artisan('modules:sync')->assertSuccessful();

        $this->assertFalse((bool) Module::where('key', 'incubadora.publicador')->value('visivel_para_todos'));
    }

    public function test_sugere_categorias_sem_repetir_e_com_o_caminho_de_cada_uma(): void
    {
        $this->fakeMl();

        $resp = $this->actingAs($this->dev())
            ->getJson('/incubadora/publicador/categorias?produto='.urlencode('Mesa de jantar redonda'))
            ->assertOk();

        $categorias = $resp->json('categorias');
        $this->assertCount(2, $categorias, 'MLB100 veio duas vezes do preditor e deve aparecer uma só');
        $this->assertSame('MLB100', $categorias[0]['id']);
        $this->assertSame('Mesas de jantar', $categorias[0]['dominio']);
        $this->assertSame(
            [['id' => 'MLB1574', 'nome' => 'Casa, Móveis e Decoração'], ['id' => 'MLB10', 'nome' => 'Mesas'], ['id' => 'MLB100', 'nome' => 'Mesas de Jantar']],
            $categorias[0]['caminho'],
        );
        $this->assertSame('MLB200', $categorias[1]['id']);
    }

    public function test_nome_do_produto_e_obrigatorio(): void
    {
        $this->fakeMl();

        $this->actingAs($this->dev())
            ->getJson('/incubadora/publicador/categorias?produto=')
            ->assertStatus(422)
            ->assertJsonValidationErrors('produto');
        Http::assertNothingSent();
    }

    public function test_lista_cheia_vem_com_os_grupos_da_doc_e_marca_os_relacionados(): void
    {
        $lista = $this->trends(50);
        $lista[0] = ['keyword' => 'mesas de jantar 6 lugares', 'url' => 'https://lista.mercadolivre.com.br/a'];
        $lista[1] = ['keyword' => 'cadeira gamer', 'url' => 'https://lista.mercadolivre.com.br/b'];
        $lista[2] = ['keyword' => 'Mesa Redônda', 'url' => 'https://lista.mercadolivre.com.br/c'];
        $this->fakeMl(['api.mercadolibre.com/trends/MLB/MLB100' => Http::response($lista)]);

        $resp = $this->actingAs($this->dev())
            ->getJson('/incubadora/publicador/categorias/MLB100/termos?produto='.urlencode('Mesa de Jantar Redonda'))
            ->assertOk();

        $this->assertSame(50, $resp->json('total'));
        $this->assertTrue($resp->json('com_grupos'));
        $this->assertTrue($resp->json('categoria.folha'));
        $this->assertSame('MLB10', $resp->json('categoria.caminho.1.id'));

        // "mesa" e "jantar" estão no caminho (Mesas › Mesas de Jantar): não distinguem.
        $this->assertSame(['redonda'], $resp->json('palavras_do_produto'));

        $termos = $resp->json('termos');
        $this->assertSame(1, $termos[0]['posicao']);
        $this->assertSame('mesas de jantar 6 lugares', $termos[0]['termo']);
        $this->assertFalse($termos[0]['relacionado']);
        $this->assertFalse($termos[1]['relacionado']);
        // Acento e caixa não escondem a palavra do produto.
        $this->assertTrue($termos[2]['relacionado']);
        $this->assertSame(['redonda'], $termos[2]['em_comum']);

        $this->assertSame('crescimento', $termos[9]['grupo']);
        $this->assertSame('desejado', $termos[10]['grupo']);
        $this->assertSame('desejado', $termos[29]['grupo']);
        $this->assertSame('popular', $termos[30]['grupo']);
        $this->assertSame('popular', $termos[49]['grupo']);
    }

    public function test_folha_de_nicho_com_menos_termos_vem_sem_grupos(): void
    {
        $this->fakeMl(['api.mercadolibre.com/trends/MLB/MLB100' => Http::response($this->trends(15))]);

        $resp = $this->actingAs($this->dev())
            ->getJson('/incubadora/publicador/categorias/MLB100/termos')
            ->assertOk();

        $this->assertSame(15, $resp->json('total'));
        $this->assertFalse($resp->json('com_grupos'));
        $this->assertNull($resp->json('termos.0.grupo'));
        $this->assertFalse($resp->json('termos.0.relacionado'));
    }

    public function test_termos_de_um_nivel_acima_no_caminho_usam_a_regua_daquele_nivel(): void
    {
        $lista = $this->trends(50);
        $lista[0] = ['keyword' => 'mesas de jantar 4 lugares', 'url' => 'https://lista.mercadolivre.com.br/a'];
        $this->fakeMl(['api.mercadolibre.com/trends/MLB/MLB10' => Http::response($lista)]);

        // Em "Mesas" (Casa › Mesas), "jantar" e "lugares" distinguem; "mesa" não.
        $this->actingAs($this->dev())
            ->getJson('/incubadora/publicador/categorias/MLB10/termos?produto='.urlencode('Mesa de jantar 4 lugares'))
            ->assertOk()
            ->assertJsonPath('categoria.id', 'MLB10')
            ->assertJsonPath('categoria.folha', false)
            ->assertJsonPath('total', 50)
            ->assertJsonPath('palavras_do_produto', ['jantar', 'lugares'])
            ->assertJsonPath('termos.0.em_comum', ['jantar', 'lugares']);
    }

    public function test_produto_so_com_palavras_da_categoria_nao_destaca_nada(): void
    {
        $this->fakeMl(['api.mercadolibre.com/trends/MLB/MLB100' => Http::response([
            ['keyword' => 'mesa de jantar', 'url' => 'https://lista.mercadolivre.com.br/a'],
        ])]);

        $this->actingAs($this->dev())
            ->getJson('/incubadora/publicador/categorias/MLB100/termos?produto='.urlencode('Mesas de Jantar'))
            ->assertOk()
            ->assertJsonPath('palavras_do_produto', [])
            ->assertJsonPath('termos.0.relacionado', false);
    }

    public function test_falha_do_ml_vira_502_e_nao_fica_presa_no_cache(): void
    {
        $this->fakeMl(['api.mercadolibre.com/trends/MLB/MLB100' => Http::sequence()
            ->push(['message' => 'boom'], 500)
            ->push($this->trends(50), 200)]);
        $dev = $this->dev();

        $this->actingAs($dev)
            ->getJson('/incubadora/publicador/categorias/MLB100/termos')
            ->assertStatus(502)
            ->assertJsonPath('message', 'O Mercado Livre não devolveu os termos agora. Tente de novo em instantes.');

        $this->actingAs($dev)
            ->getJson('/incubadora/publicador/categorias/MLB100/termos')
            ->assertOk()
            ->assertJsonPath('total', 50);
    }

    public function test_falha_passageira_ao_ler_a_categoria_nao_fica_presa_no_cache(): void
    {
        $this->fakeMl([
            'api.mercadolibre.com/categories/MLB100' => Http::sequence()
                ->push(['message' => 'boom'], 503)
                ->push(['id' => 'MLB100', 'name' => 'Mesas de Jantar', 'children_categories' => [], 'path_from_root' => [['id' => 'MLB100', 'name' => 'Mesas de Jantar']]]),
            'api.mercadolibre.com/trends/MLB/MLB100' => Http::response($this->trends(50)),
        ]);
        $dev = $this->dev();

        $this->actingAs($dev)->getJson('/incubadora/publicador/categorias/MLB100/termos')->assertNotFound();
        $this->actingAs($dev)->getJson('/incubadora/publicador/categorias/MLB100/termos')
            ->assertOk()
            ->assertJsonPath('categoria.nome', 'Mesas de Jantar');
    }

    public function test_categoria_desconhecida_no_ml_da_404_e_id_malformado_nem_chega_ao_controller(): void
    {
        $this->fakeMl(['api.mercadolibre.com/categories/MLB999' => Http::response(['message' => 'not found'], 404)]);
        $dev = $this->dev();

        $this->actingAs($dev)
            ->getJson('/incubadora/publicador/categorias/MLB999/termos')
            ->assertNotFound()
            ->assertJsonPath('message', 'Categoria não encontrada no Mercado Livre.');

        $this->actingAs($dev)->getJson('/incubadora/publicador/categorias/abc/termos')->assertNotFound();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/trends/'));
    }
}
