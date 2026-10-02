<?php

namespace Tests\Feature\Publicador;

use App\Models\User;
use App\Services\Publicador\CategoriaBuscaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * D18: a busca de categoria do editor mora em `CategoriaBuscaService`, extraída do
 * Anunciar do Portal antes de ele sair. Cada sugestão traz o caminho inteiro da árvore;
 * a categoria cujo caminho falha volta com `caminho` vazio e as outras não caem.
 */
class CategoriaBuscaServiceTest extends TestCase
{
    use RefreshDatabase;

    private const DOMINIO = 'Caixas de direção para veículos';

    private function fakeMl(): void
    {
        Cache::flush();
        $arvore = fn ($id, $nome) => ['id' => $id, 'name' => $nome, 'settings' => ['max_title_length' => 60], 'children_categories' => [],
            'path_from_root' => [['id' => 'MLB5672', 'name' => 'Acessórios para Veículos'], ['id' => 'MLB1747', 'name' => 'Peças de Carros e Caminhonetes'],
                ['id' => 'MLB22693', 'name' => 'Direção'], ['id' => $id, 'name' => $nome]]];

        Http::fake([
            '*/oauth/token'              => Http::response(['access_token' => 'app-token', 'expires_in' => 21600]),
            '*/domain_discovery/search*' => Http::response([
                ['domain_id' => 'MLB-STEERING', 'domain_name' => self::DOMINIO, 'category_id' => 'MLB193420', 'category_name' => 'Caixa de Direção'],
                ['domain_id' => 'MLB-STEERING', 'domain_name' => self::DOMINIO, 'category_id' => 'MLB456920', 'category_name' => 'Caixas de Direção Hidráulica'],
                ['domain_id' => 'MLB-STEERING', 'domain_name' => self::DOMINIO, 'category_id' => 'MLB447370', 'category_name' => 'Cajas de Dirección Hidráulica'],
                // O preditor repete a mesma categoria em outro domínio: aparece uma vez.
                ['domain_id' => 'MLB-OUTRO', 'domain_name' => 'Outro', 'category_id' => 'MLB193420', 'category_name' => 'Caixa de Direção'],
            ]),
            '*/categories/MLB193420'     => Http::response($arvore('MLB193420', 'Caixa de Direção')),
            '*/categories/MLB456920'     => Http::response($arvore('MLB456920', 'Caixas de Direção Hidráulica')),
            '*/categories/MLB447370'     => Http::response(['message' => 'internal error'], 500),
        ]);
    }

    private function chamadasDeCategoria(): int
    {
        return count(Http::recorded(fn (Request $q) => preg_match('#/categories/MLB\d+$#', $q->url()) === 1));
    }

    public function test_sugestoes_trazem_o_caminho_completo_e_uma_falha_nao_derruba_as_outras(): void
    {
        $this->fakeMl();

        $r = app(CategoriaBuscaService::class)->categorias('caixa de direção');

        $this->assertSame(['MLB193420', 'MLB456920', 'MLB447370'], array_column($r, 'id'));
        $this->assertSame(['Acessórios para Veículos', 'Peças de Carros e Caminhonetes', 'Direção', 'Caixa de Direção'], $r[0]['caminho']);
        $this->assertSame(['id' => 'MLB447370', 'nome' => 'Cajas de Dirección Hidráulica', 'dominio' => self::DOMINIO, 'caminho' => []], $r[2]);
        $this->assertSame(3, $this->chamadasDeCategoria());
    }

    public function test_rota_do_editor_interno_responde_so_para_admin_e_exige_q(): void
    {
        $this->fakeMl();
        $admin = User::factory()->create(['role' => 'admin']);

        $r = $this->actingAs($admin)->getJson(route('mlb.anuncios.publicador.categorias', ['q' => 'caixa de direção']))->assertOk()
            ->assertJsonPath('0.caminho.3', 'Caixa de Direção')->assertJsonPath('2.caminho', [])->json();
        $this->assertSame(['id', 'nome', 'dominio', 'caminho'], array_keys($r[0]));

        $this->actingAs($admin)->getJson(route('mlb.anuncios.publicador.categorias'))->assertUnprocessable();

        $consultor = User::factory()->create(['role' => 'consultor']);
        $this->actingAs($consultor)->getJson(route('mlb.anuncios.publicador.categorias', ['q' => 'x']))->assertForbidden();
    }
}
