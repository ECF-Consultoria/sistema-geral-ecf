<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlCategoriaSchema;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Publicador\CategorySchemaRepository;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\ContaMlService;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A camada que fala com o ML (F1.7), sobre as respostas REAIS da sondagem.
 * `09` §2 e §6: o que se repete, o que não se repete, e o token.
 */
class CamadaMlTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    /** @var list<int> as esperas pedidas (o teste não dorme de verdade) */
    private array $esperas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Company::factory()->create();
        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(
            app(MercadoLivreService::class), app(MlColetaService::class), function (int $s) { $this->esperas[] = $s; },
        ));
    }

    private function conectar(array $mudar = []): MlToken
    {
        return MlToken::create([
            'company_id' => $this->empresa->id, 'ml_user_id' => '1555596317',
            'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token', 'token_type' => 'bearer',
            'expires_at' => now()->addHours(5), 'last_refreshed_at' => now()->subHour(), 'status' => 'active', 'connected_at' => now(),
            ...$mudar,
        ]);
    }

    private static function fixture(string $relativo): mixed
    {
        return json_decode(file_get_contents(base_path("tests/fixtures-ml/sondagem/{$relativo}.json")), true)['resposta'];
    }

    private function cliente(): ClienteMlPublicador
    {
        return app(ClienteMlPublicador::class);
    }

    private function chamadas(string $sufixo): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), $sufixo)));
    }

    // ═══ Cliente ═════════════════════════════════════════════════════════════

    public function test_tc86_429_espera_e_repete_ate_criar(): void
    {
        $this->conectar();
        Http::fake(['*/items' => Http::sequence()
            ->push(['message' => 'local_rate_limited'], 429, ['Retry-After' => '3'])
            ->push(['message' => 'local_rate_limited'], 429)
            ->push(['id' => 'MLB123'], 201)]);

        $r = $this->cliente()->daConta($this->empresa, 'POST', '/items', [], ['family_name' => 'x'], repetir: false);

        $this->assertSame(201, $r->status);
        $this->assertSame('MLB123', $r->corpo['id']);
        $this->assertSame([3, 2], $this->esperas, 'honra o Retry-After; senão 1, 2, 4…');
        $this->assertSame(3, $this->chamadas('/items'));
    }

    public function test_tc87_token_vencido_renova_uma_vez_e_guarda_o_refresh_novo(): void
    {
        $token = $this->conectar();
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'novo-access', 'refresh_token' => 'novo-refresh', 'expires_in' => 21600, 'user_id' => 1555596317]),
            '*/items/validate' => Http::sequence()->push(['message' => 'invalid access token'], 401)->push('', 204),
        ]);

        $r = $this->cliente()->daConta($this->empresa, 'POST', '/items/validate', [], ['family_name' => 'x']);

        $this->assertSame(204, $r->status);
        $this->assertSame(1, $this->chamadas('/oauth/token'));
        $this->assertSame('novo-access', $token->fresh()->access_token);
        $this->assertSame('novo-refresh', $token->fresh()->refresh_token, 'RN-01: o refresh é de uso único — o novo tem de ficar salvo');
    }

    public function test_tc88_invalid_grant_pede_reconexao(): void
    {
        $token = $this->conectar();
        Http::fake([
            '*/oauth/token' => Http::response(['error' => 'invalid_grant', 'message' => 'invalid_grant'], 400),
            '*/items/validate' => Http::response(['message' => 'invalid access token'], 401),
        ]);

        try {
            $this->cliente()->daConta($this->empresa, 'POST', '/items/validate', [], []);
            $this->fail('Esperava RegraViolada');
        } catch (RegraViolada $e) {
            $this->assertSame('V-ACC-01', $e->regra);
            $this->assertStringContainsString('reconectada', $e->getMessage());
        }
        $this->assertSame('revoked', $token->fresh()->status);
    }

    private function tokenDaMlbEmpresa(MlbEmpresa $e, string $access): MlToken
    {
        return MlToken::create([
            'company_id' => null, 'mlb_empresa_id' => $e->id, 'ml_user_id' => '2000000001',
            'access_token' => $access, 'refresh_token' => 'refresh-'.$access, 'token_type' => 'bearer',
            'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now(),
        ]);
    }

    private function bearerDe(string $sufixo): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), $sufixo))
            ->map(fn ($par) => $par[0]->header('Authorization')[0] ?? '')->values()->all();
    }

    public function test_mlb_empresa_sem_company_usa_o_token_da_propria_ancora(): void
    {
        $e = MlbEmpresa::create(['nome' => 'Loja Incubadora', 'projeto' => 'Incubadora'])->fresh();
        $this->tokenDaMlbEmpresa($e, 'token-da-mlb-empresa');
        Http::fake(['*/users/me' => Http::response(self::fixture('conta/usuario'))]);

        $r = $this->cliente()->daConta($e, 'GET', '/users/me');

        $this->assertTrue($r->ok());
        $this->assertSame(['Bearer token-da-mlb-empresa'], $this->bearerDe('/users/me'));
    }

    /**
     * Mutação: se o motor escolher a conta pelo id cru (ou pela âncora errada),
     * o header Authorization troca de token e este teste quebra.
     */
    public function test_company_e_mlb_empresa_de_mesmo_id_nao_se_misturam(): void
    {
        $e = MlbEmpresa::create(['nome' => 'Loja Incubadora', 'projeto' => 'Incubadora'])->fresh();
        // O setUp já criou a Company #1 e esta é a MlbEmpresa #1: mesmo número, âncoras diferentes.
        $company = $this->empresa;
        $this->assertSame($company->id, $e->id);
        $this->tokenDaMlbEmpresa($e, 'token-da-mlb-empresa');
        MlToken::create(['company_id' => $company->id, 'ml_user_id' => '1555596317', 'access_token' => 'token-da-company', 'refresh_token' => 'r-c',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        Http::fake(['*/users/me' => Http::response(self::fixture('conta/usuario'))]);

        $this->cliente()->daConta($company, 'GET', '/users/me');
        $this->cliente()->daConta($e, 'GET', '/users/me');

        $this->assertSame(['Bearer token-da-company', 'Bearer token-da-mlb-empresa'], $this->bearerDe('/users/me'));
    }

    public function test_sem_conta_conectada(): void
    {
        $this->expectException(RegraViolada::class);

        $this->cliente()->daConta($this->empresa, 'GET', '/users/me');
    }

    public function test_post_items_com_5xx_nao_repete_e_avisa_que_pode_ter_criado(): void
    {
        $this->conectar();
        Http::fake(['*/items' => Http::response('Service Unavailable', 503)]);

        $r = $this->cliente()->daConta($this->empresa, 'POST', '/items', [], ['x' => 1], repetir: false);

        $this->assertSame(RespostaMl::SERVER, $r->classe);
        $this->assertTrue($r->podeTerCriado(), 'RN-93: o orquestrador marca UNKNOWN e reconcilia');
        $this->assertSame(1, $this->chamadas('/items'));
    }

    public function test_leitura_com_5xx_repete(): void
    {
        $this->conectar();
        Http::fake(['*/users/me' => Http::sequence()->push('erro', 503)->push(['id' => 1, 'tags' => []], 200)]);

        $this->assertSame(200, $this->cliente()->daConta($this->empresa, 'GET', '/users/me')->status);
        $this->assertSame([1], $this->esperas);
    }

    public function test_queda_de_rede_vira_status_zero(): void
    {
        $this->conectar();
        Http::fake(['*/items' => Http::failedConnection()]);

        $r = $this->cliente()->daConta($this->empresa, 'POST', '/items', [], [], repetir: false);

        $this->assertSame(RespostaMl::NETWORK, $r->classe);
        $this->assertTrue($r->podeTerCriado());
    }

    public function test_token_nunca_vai_para_o_log(): void
    {
        $this->conectar();
        $logs = [];
        Log::listen(function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->message.' '.json_encode($e->context);
        });
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'novo-access', 'refresh_token' => 'novo-refresh', 'expires_in' => 21600, 'user_id' => 1]),
            '*/items/validate' => Http::sequence()->push(['message' => 'invalid access token'], 401)->push(self::fixture('conta/categorias/MLB193945/validate_numero_sem_unidade'), 400),
        ]);

        $this->cliente()->daConta($this->empresa, 'POST', '/items/validate', [], ['family_name' => 'x']);

        $this->assertNotEmpty($logs);
        foreach ($logs as $linha) {
            foreach (['fake-access-token', 'fake-refresh-token', 'novo-access', 'novo-refresh'] as $segredo) {
                $this->assertStringNotContainsString($segredo, $linha);
            }
        }
    }

    // ═══ Conta ═══════════════════════════════════════════════════════════════

    public function test_conta_da_dev_02_e_user_products(): void
    {
        $this->conectar();
        Http::fake([
            '*/users/me' => Http::response(self::fixture('conta/usuario')),
            '*/shipping_preferences' => Http::response(self::fixture('conta/shipping_preferences')),
        ]);

        $c = app(ContaMlService::class)->contexto($this->empresa);

        $this->assertSame(MontadorDePlano::UP, $c->modelo);
        $this->assertSame(['custom', 'not_specified', 'me2'], $c->modosEnvio);
        $this->assertNull($c->depositos);
        $this->assertFalse($c->multiDeposito());
    }

    public function test_conta_multideposito_traz_os_depositos(): void
    {
        $this->conectar();
        Http::fake([
            '*/users/me' => Http::response(self::fixture('conta-multideposito/usuario')),
            '*/shipping_preferences' => Http::response(self::fixture('conta-multideposito/shipping_preferences')),
            '*/stores/search*' => Http::response(self::fixture('conta-multideposito/stores_stock_location')),
        ]);

        $c = app(ContaMlService::class)->contexto($this->empresa);

        $this->assertTrue($c->multiDeposito());
        $this->assertSame([['store_id' => 'DEPOSITO_1', 'nome' => 'Depósito 1', 'network_node_id' => null], ['store_id' => 'DEPOSITO_2', 'nome' => 'Depósito 2', 'network_node_id' => null]], $c->depositos);
    }

    public function test_falha_ao_ler_a_conta_nunca_vira_classico(): void
    {
        $this->conectar();
        Http::fake(['*/users/me' => Http::response('erro', 500)]);

        try {
            app(ContaMlService::class)->contexto($this->empresa);
            $this->fail('Esperava RegraViolada');
        } catch (RegraViolada $e) {
            $this->assertSame('V-ACC-01', $e->regra, 'o serviço antigo caía em "clássico" e guardava por 24h (V2)');
        }
    }

    // ═══ Schema da categoria ═════════════════════════════════════════════════

    /** O que o ML falso da categoria devolve AGORA. `Http::fake()` acumula e o 1º stub vence: muda-se o estado, não o fake. */
    private ?\Closure $ajusteCategoria = null;

    /** @var list<string> */
    private array $fontesFora = [];

    private function fakeCategoria(string $id = 'MLB193945'): void
    {
        $base = "publico/categorias/{$id}/";
        $resp = fn (string $arq, string $chave) => fn () => in_array($chave, $this->fontesFora, true) || in_array('todas', $this->fontesFora, true)
            ? Http::response('erro', 500)
            : Http::response($this->ajusteCategoria ? ($this->ajusteCategoria)($chave, self::fixture($base.$arq)) : self::fixture($base.$arq));

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 21600]),
            "*/categories/{$id}/attributes" => $resp('atributos', 'atributos'),
            "*/categories/{$id}/technical_specs/input" => $resp('technical_specs_input', 'technical_specs'),
            "*/categories/{$id}/sale_terms" => $resp('sale_terms', 'sale_terms'),
            "*/categories/{$id}" => $resp('categoria', 'categoria'),
        ]);
    }

    public function test_schema_e_lido_uma_vez_e_guardado_com_hash(): void
    {
        $this->fakeCategoria();
        $repo = app(CategorySchemaRepository::class);

        $schema = $repo->obter('MLB193945');
        $repo->obter('MLB193945');

        $this->assertSame('MLB-OFFICE_CHAIRS', MlCategoriaSchema::find('MLB193945')->domain_id);
        $this->assertSame($schema->hash(), MlCategoriaSchema::find('MLB193945')->schema_hash);
        $this->assertSame(1, $this->chamadas('/categories/MLB193945/attributes'), 'a segunda leitura vem do banco');
    }

    public function test_falha_do_ml_nunca_e_guardada(): void
    {
        $this->fontesFora = ['atributos'];
        $this->fakeCategoria();

        try {
            app(CategorySchemaRepository::class)->obter('MLB193945');
            $this->fail('Esperava RegraViolada');
        } catch (RegraViolada) {
            $this->assertNull(MlCategoriaSchema::find('MLB193945'), 'V13: o cache antigo guardava [] por 7 dias');
        }
    }

    public function test_ml_fora_do_ar_usa_o_guardado_mesmo_vencido(): void
    {
        $this->fakeCategoria();
        app(CategorySchemaRepository::class)->obter('MLB193945');
        MlCategoriaSchema::query()->update(['fetched_at' => now()->subDays(3)]);

        $this->fontesFora = ['todas'];
        $schema = app(CategorySchemaRepository::class)->obter('MLB193945');

        $this->assertSame('Cadeiras para Escritório', $schema->nome());
    }

    public function test_tc75_schema_mudou_depois_do_rascunho(): void
    {
        $this->fakeCategoria();
        $antigo = app(CategorySchemaRepository::class)->obter('MLB193945')->hash();
        MlCategoriaSchema::query()->update(['fetched_at' => now()->subHours(25)]);

        $this->ajusteCategoria = function (string $chave, $dados) {
            if ($chave === 'categoria') {
                $dados['settings']['max_title_length'] = 61;
            }

            return $dados;
        };
        $r = app(CategorySchemaRepository::class)->revalidar('MLB193945', $antigo);

        $this->assertTrue($r['mudou']);
        $this->assertSame(61, $r['schema']->settings()['max_title_length']);
        $this->assertSame($r['schema']->hash(), MlCategoriaSchema::find('MLB193945')->schema_hash);
    }
}
