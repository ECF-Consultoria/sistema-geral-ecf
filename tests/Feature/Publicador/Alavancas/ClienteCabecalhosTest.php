<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\Company;
use App\Models\MlToken;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Publicador\ClienteMlPublicador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 166-02: o cliente do Publicador aceita cabeçalhos por chamada, manda DELETE
 * sem corpo e nunca troca de host em produção.
 */
class ClienteCabecalhosTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    /** @var list<array> o que o fake viu */
    private array $vistas = [];

    private ClienteMlPublicador $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Company::factory()->create();
        MlToken::create(['company_id' => $this->empresa->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token', 'token_type' => 'bearer', 'expires_at' => now()->addHours(5),
            'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        $this->cliente = new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class), fn (int $s) => null);
        Http::fake(function (Request $req) {
            $this->vistas[] = ['metodo' => $req->method(), 'url' => $req->url(), 'corpo' => $req->body(), 'cab' => $req->headers()];

            return Http::response(['ok' => true], 200);
        });
    }

    public function test_cabecalho_por_chamada_sai_junto_do_bearer(): void
    {
        $this->cliente->daConta($this->empresa, 'GET', '/x', [], null, true, ['api-version' => '2']);

        $this->assertSame(['2'], $this->vistas[0]['cab']['api-version']);
        $this->assertSame(['Bearer fake-access-token'], $this->vistas[0]['cab']['Authorization']);
    }

    public function test_chamada_sem_cabecalho_sai_como_antes(): void
    {
        $this->cliente->daConta($this->empresa, 'POST', '/x', ['a' => 'b'], ['k' => 1]);

        $v = $this->vistas[0];
        $this->assertSame('POST', $v['metodo']);
        $this->assertStringEndsWith('/x?a=b', $v['url']);
        $this->assertSame(['k' => 1], json_decode($v['corpo'], true));
        $this->assertArrayNotHasKey('api-version', $v['cab']);
    }

    public function test_delete_sem_corpo_sai_sem_corpo_e_post_vazio_manda_json_vazio(): void
    {
        $this->cliente->daConta($this->empresa, 'DELETE', '/x', ['a' => 'b'], null);
        $this->cliente->daConta($this->empresa, 'POST', '/x', [], null);

        $this->assertSame('DELETE', $this->vistas[0]['metodo']);
        $this->assertStringEndsWith('/x?a=b', $this->vistas[0]['url']);
        $this->assertSame('', $this->vistas[0]['corpo']);
        $this->assertSame('[]', $this->vistas[1]['corpo']);
    }

    public function test_base_configuravel_fora_de_producao_e_host_oficial_em_producao(): void
    {
        config(['publicador.ml_api_base' => 'http://127.0.0.1:8167']);
        $this->cliente->daConta($this->empresa, 'GET', '/x');
        $this->assertStringStartsWith('http://127.0.0.1:8167/x', $this->vistas[0]['url']);

        $this->app['env'] = 'production';
        try {
            $this->cliente->daConta($this->empresa, 'GET', '/x');
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertStringStartsWith('https://api.mercadolibre.com/x', $this->vistas[1]['url']);
    }
}
