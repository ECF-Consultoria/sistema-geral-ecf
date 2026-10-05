<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Console\Commands\PublicadorSondarAlavancas;
use App\Models\Company;
use App\Models\MlToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 166-05: a sondagem das Alavancas só lê, só em conta liberada e não grava token nem dado pessoal. */
class SondarAlavancasCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-secreto-da-sondagem';

    private string $saida;

    /** @var list<array{metodo: string, url: string, caminho: string}> */
    private array $chamadas = [];

    /** @var array<string, array> cabeçalhos por caminho (o `Http::fake` acumula: o 1º stub vence, então o registro é um só) */
    private array $cabecalhos = [];

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saida = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sondagem-alav-'.uniqid();

        $this->empresa = Company::factory()->create();
        MlToken::create(['company_id' => $this->empresa->id, 'ml_user_id' => '1555596317', 'access_token' => self::TOKEN,
            'refresh_token' => 'refresh-secreto', 'token_type' => 'bearer', 'expires_at' => now()->addHours(5),
            'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        config(['publicador.alavancas.contas_liberadas.companies' => [$this->empresa->id]]);

        Http::fake(function (Request $req) {
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            $this->chamadas[] = ['metodo' => $req->method(), 'url' => $req->url(), 'caminho' => $caminho];
            $this->cabecalhos[$caminho] = $req->headers();

            return match (true) {
                $caminho === '/users/me' => Http::response(['id' => 1555596317, 'nickname' => 'LOJA_TESTE', 'site_id' => 'MLB', 'tags' => ['business'],
                    'email' => 'dono@exemplo.com', 'first_name' => 'Fulano', 'phone' => ['number' => '999'], 'address' => ['city' => 'X']]),
                str_starts_with($caminho, '/seller-promotions/users/') => Http::response(['results' => [
                    ['id' => 'P-MLB1', 'type' => 'DEAL'], ['id' => 'C-MLB2', 'type' => 'SMART'], ['id' => '../etc', 'type' => 'DEAL']], 'paging' => ['total' => 3]]),
                $caminho === '/advertising/advertisers' => Http::response(['advertisers' => [['advertiser_id' => 1000001, 'site_id' => 'MLB']]]),
                str_ends_with($caminho, '/items/search') => Http::response(['results' => ['MLB1000000001', 'MLB1000000002']]),
                default => Http::response(['ok' => true, 'thumbnail' => 'http://x/y.jpg']),
            };
        });
    }

    protected function tearDown(): void
    {
        if (is_dir($this->saida)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->saida, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->saida);
        }
        parent::tearDown();
    }

    /** @return list<string> */
    private function arquivosGravados(): array
    {
        if (! is_dir($this->saida)) {
            return [];
        }
        $achados = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->saida, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $achados[] = $f->getPathname();
        }

        return $achados;
    }

    public function test_sem_empresa_recusa_e_nao_chama_o_ml(): void
    {
        $this->artisan('publicador:sondar-alavancas', ['--saida' => $this->saida])->assertExitCode(1);

        $this->assertSame([], $this->chamadas);
        $this->assertSame([], $this->arquivosGravados());
    }

    public function test_conta_fora_da_lista_e_recusada_sem_chamada(): void
    {
        $outra = Company::factory()->create();
        MlToken::create(['company_id' => $outra->id, 'ml_user_id' => '99', 'access_token' => 'outro', 'refresh_token' => 'r', 'token_type' => 'bearer',
            'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);

        $this->artisan('publicador:sondar-alavancas', ['--empresa' => $outra->id, '--saida' => $this->saida])
            ->expectsOutputToContain('Sondagem só em conta liberada das Alavancas (conta de teste). Conta de cliente: nunca.')
            ->assertExitCode(1);

        $this->assertSame([], $this->chamadas);
        $this->assertSame([], $this->arquivosGravados());
    }

    public function test_conta_liberada_so_faz_get_e_grava_envelopes_sem_token(): void
    {
        $this->artisan('publicador:sondar-alavancas', ['--empresa' => $this->empresa->id, '--saida' => $this->saida])->assertExitCode(0);

        $this->assertNotEmpty($this->chamadas);
        foreach ($this->chamadas as $c) {
            $this->assertSame('GET', $c['metodo'], $c['url']);
        }
        // Nada de caminho vindo de dado do ML fora do padrão seguro.
        $this->assertSame([], array_filter($this->chamadas, fn ($c) => str_contains($c['url'], '..')));

        $arquivos = $this->arquivosGravados();
        $this->assertNotEmpty($arquivos);
        foreach ($arquivos as $arq) {
            $texto = (string) file_get_contents($arq);
            $envelope = json_decode($texto, true);
            $this->assertSame('sondagem', $envelope['origem'], $arq);
            $this->assertArrayHasKey('requisicao', $envelope);
            $this->assertArrayHasKey('status', $envelope);
            $this->assertArrayHasKey('capturado_em', $envelope);
            $this->assertStringNotContainsString(self::TOKEN, $texto, $arq);
            $this->assertStringNotContainsString('refresh-secreto', $texto, $arq);
            $this->assertStringNotContainsString('Bearer', $texto, $arq);
        }

        $caminhos = array_column($this->chamadas, 'caminho');
        $this->assertContains('/advertising/MLB/advertisers/1000001/product_ads/campaigns/search', $caminhos);
        $this->assertContains('/advertising/advertisers/bonifications', $caminhos);
        $this->assertContains('/items/MLB1000000001/prices', $caminhos);
    }

    public function test_os_cabecalhos_de_versao_e_de_precos_vao_nos_pedidos(): void
    {
        $this->artisan('publicador:sondar-alavancas', ['--empresa' => $this->empresa->id, '--itens' => 'MLB1000000009', '--saida' => $this->saida])->assertExitCode(0);

        $this->assertSame('1', $this->cabecalhos['/advertising/advertisers']['Api-Version'][0]);
        $this->assertSame('2', $this->cabecalhos['/advertising/MLB/advertisers/1000001/product_ads/campaigns/search']['api-version'][0]);
        $this->assertSame('true', $this->cabecalhos['/items/MLB1000000009/prices']['show-all-prices'][0]);
    }

    public function test_usuario_gravado_tem_so_os_campos_permitidos(): void
    {
        $this->artisan('publicador:sondar-alavancas', ['--empresa' => $this->empresa->id, '--saida' => $this->saida])->assertExitCode(0);

        $usuario = json_decode((string) file_get_contents($this->saida.'/conta/usuario.json'), true)['resposta'];

        $this->assertSame(['id', 'nickname', 'site_id', 'tags'], array_keys($usuario));
        $this->assertArrayNotHasKey('email', $usuario);
        $this->assertArrayNotHasKey('first_name', $usuario);
    }

    public function test_garantir_somente_leitura_recusa_qualquer_escrita(): void
    {
        PublicadorSondarAlavancas::garantirSomenteLeitura('GET', '/users/me');

        foreach ([['POST', '/prices-per-quantity/v1/recommendations'], ['POST', '/items/validate'], ['PUT', '/items/MLB1'], ['DELETE', '/items/MLB1']] as [$m, $c]) {
            try {
                PublicadorSondarAlavancas::garantirSomenteLeitura($m, $c);
                $this->fail("{$m} {$c} deveria ser recusado");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('só lê', $e->getMessage());
            }
        }
    }
}
