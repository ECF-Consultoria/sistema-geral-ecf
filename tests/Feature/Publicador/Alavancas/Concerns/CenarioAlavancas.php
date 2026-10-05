<?php

namespace Tests\Feature\Publicador\Alavancas\Concerns;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Publicador\Alavancas\ContaAlavanca;
use App\Services\Publicador\Alavancas\ContextoAlavancas;
use App\Services\Publicador\ClienteMlPublicador;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cenário das Alavancas com o ML simulado. O fake HTTP é registrado UMA vez e a
 * closure lê o estado do teste (`$this->rotasMl`): `Http::fake` acumula e o
 * primeiro stub que casa vence (learnings §5). A mesma URL responde por MÉTODO
 * (Armadilha 7 do RESEARCH): a rota casa método + expressão regular do caminho.
 */
trait CenarioAlavancas
{
    protected Company|MlbEmpresa $ancora;

    protected User $admin;

    /** O `/users/me` agora; nulo = a conta #459 real da sondagem. */
    protected ?array $usuario = null;

    /** @var list<array{metodo: string, padrao: string, resposta: \Closure|array, status: int}> */
    protected array $rotasMl = [];

    /** @var list<array{metodo: string, url: string, caminho: string, query: array, corpo: mixed, cabecalhos: array}> */
    protected array $chamadas = [];

    /** @var list<int> esperas pedidas ao cliente (o teste não dorme) */
    protected array $esperas = [];

    /**
     * @param  string  $ancora  'company' ou 'mlb_empresa' (Incubadora, SEM Company)
     * @param  bool  $liberada  se a trava própria das Alavancas libera a conta
     */
    protected function montarAlavancas(string $ancora = 'company', bool $liberada = true): void
    {
        $token = ['ml_user_id' => '1555596317', 'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()];
        $listas = ['companies' => [], 'mlb_empresas' => []];

        if ($ancora === 'mlb_empresa') {
            $this->ancora = MlbEmpresa::create(['nome' => 'Loja Incubadora', 'projeto' => 'Incubadora'])->fresh();
            MlToken::create(['company_id' => null, 'mlb_empresa_id' => $this->ancora->id, ...$token]);
            $liberada && $listas['mlb_empresas'] = [$this->ancora->id];
        } else {
            $this->ancora = Company::factory()->create();
            MlToken::create(['company_id' => $this->ancora->id, ...$token]);
            $liberada && $listas['companies'] = [$this->ancora->id];
        }

        // As duas travas são independentes: a de publicação fica vazia.
        config(['publicador.alavancas.contas_liberadas' => $listas]);
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);
        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class),
            function (int $s) { $this->esperas[] = $s; }));

        $this->fakeMl();
    }

    protected function fakeMl(): void
    {
        Http::fake(function (Request $req) {
            $url = $req->url();
            $caminho = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->chamadas[] = ['metodo' => $req->method(), 'url' => $url, 'caminho' => $caminho, 'query' => $query,
                'corpo' => $req->data(), 'cabecalhos' => $req->headers()];

            // A última rota registrada vence: o teste sobrescreve o que o cenário pôs.
            foreach (array_reverse($this->rotasMl) as $rota) {
                if ($rota['metodo'] === $req->method() && preg_match($rota['padrao'], $caminho)) {
                    return $rota['resposta'] instanceof \Closure
                        ? ($rota['resposta'])($req)
                        : Http::response($rota['resposta'], $rota['status']);
                }
            }
            if ($req->method() === 'GET' && $caminho === '/users/me') {
                return Http::response($this->usuario ?? self::fixtureSondagem('conta/usuario'), 200);
            }

            return Http::response(['message' => 'sem stub no teste'], 404);
        });
    }

    protected function responder(string $metodo, string $padrao, \Closure|array $resposta, int $status = 200): void
    {
        $this->rotasMl[] = ['metodo' => strtoupper($metodo), 'padrao' => $padrao, 'resposta' => $resposta, 'status' => $status];
    }

    protected static function fixtureAlavanca(string $relativo): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures-ml/alavancas/{$relativo}.json")), true)['resposta'];
    }

    protected static function fixtureSondagem(string $relativo): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures-ml/sondagem/{$relativo}.json")), true)['resposta'];
    }

    /** @return list<array> as chamadas que escrevem (método diferente de GET) */
    protected function chamadasNaoGet(): array
    {
        return array_values(array_filter($this->chamadas, fn (array $c) => $c['metodo'] !== 'GET'));
    }

    protected function contaAlavanca(): ContaAlavanca
    {
        $ctx = app(ContextoAlavancas::class);
        $alvo = $ctx->resolver($this->ancora->chaveContaMl());

        return $ctx->daTela($alvo) ?? throw new \RuntimeException('cenário sem conta de alavanca');
    }
}
