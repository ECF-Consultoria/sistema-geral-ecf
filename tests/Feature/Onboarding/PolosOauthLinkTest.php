<?php

namespace Tests\Feature\Onboarding;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Link de OAuth por empresa na mensagem de boas-vindas do Polos ({link_oauth}).
 *
 * O ponto do desenho é o que NÃO vai no WhatsApp: a URL do Mercado Livre carrega
 * um `state` que morre em 7 dias, então mandá-la direto significaria link vencido
 * para todo cliente que demora. O que vai é a rota daqui, com o token da
 * implementação — sem validade — e a URL do ML nasce no clique.
 *
 * O outro ponto é o Cust ID: o Grant por polo é o mesmo link para a região
 * inteira e não devolve quem preencheu. Este devolve, e ainda grava o Seller ID
 * da conta autorizada por cima do que foi digitado à mão.
 */
class PolosOauthLinkTest extends TestCase
{
    use RefreshDatabase;

    private function implementacao(array $empresaAttrs = []): MlbImplementacao
    {
        $empresa = MlbEmpresa::create(array_merge(['nome' => 'Cliente Polos'], $empresaAttrs));

        return MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => 'tok' . str_repeat('c', 45),
            'dados'      => MlbImplementacao::dadosPadrao(),
        ]);
    }

    /** Extrai o `state` da URL de autorização para onde o cliente foi mandado. */
    private function stateDoRedirect(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

        return $query['state'] ?? '';
    }

    public function test_o_link_da_mensagem_redireciona_para_a_autorizacao_do_mercado_livre(): void
    {
        $impl = $this->implementacao();

        $resposta = $this->get(route('implementacao.conectar-ml', $impl->token));

        $resposta->assertRedirect();
        $destino = $resposta->headers->get('Location');

        $this->assertStringStartsWith('https://auth.mercadolivre.com.br/authorization', $destino);
        $this->assertNotSame('', $this->stateDoRedirect($destino), 'A URL precisa levar um state.');
    }

    public function test_o_state_amarra_a_empresa_de_polos_e_nao_uma_company(): void
    {
        $impl = $this->implementacao();

        $destino = $this->get(route('implementacao.conectar-ml', $impl->token))->headers->get('Location');
        $state   = $this->stateDoRedirect($destino);

        $guardado = Cache::get("ml_oauth_state_{$state}");

        $this->assertSame($impl->empresa_id, $guardado['mlb_empresa_id']);
        $this->assertArrayNotHasKey('company_id', $guardado);
    }

    public function test_dois_cliques_no_mesmo_link_geram_autorizacoes_novas(): void
    {
        // É isto que faz o link não expirar: o token da implementação é fixo, e
        // cada acesso mina um state novo com validade própria.
        $impl = $this->implementacao();

        $primeiro = $this->stateDoRedirect($this->get(route('implementacao.conectar-ml', $impl->token))->headers->get('Location'));
        $segundo  = $this->stateDoRedirect($this->get(route('implementacao.conectar-ml', $impl->token))->headers->get('Location'));

        $this->assertNotSame($primeiro, $segundo);
        $this->assertNotNull(Cache::get("ml_oauth_state_{$segundo}"));
    }

    public function test_token_inexistente_da_404(): void
    {
        $this->get(route('implementacao.conectar-ml', 'tok' . str_repeat('z', 45)))->assertNotFound();
    }

    public function test_a_autorizacao_grava_o_cust_id_da_conta_autorizada(): void
    {
        $impl = $this->implementacao();

        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token'  => 'APP_USR-token',
                'refresh_token' => 'TG-refresh',
                'user_id'       => 987654321,
                'expires_in'    => 21600,
                'token_type'    => 'bearer',
            ]),
            'api.mercadolibre.com/users/*' => Http::response(['id' => 987654321, 'nickname' => 'LOJA.TESTE']),
        ]);

        $state = $this->stateDoRedirect($this->get(route('implementacao.conectar-ml', $impl->token))->headers->get('Location'));

        $this->get(route('ml.oauth.callback', ['code' => 'AUTH-CODE', 'state' => $state]))
            ->assertOk()
            ->assertSee('sucesso', false);

        $this->assertSame('987654321', $impl->empresa->fresh()->cust_id);

        // Carimbo top-level em `dados` — nunca dentro de `itens`, que o cliente
        // reescreve inteiro a cada salvamento do formulário.
        $carimbo = $impl->fresh()->dados['ml_oauth'];
        $this->assertSame('987654321', $carimbo['cust_id']);
        $this->assertSame('LOJA.TESTE', $carimbo['nickname']);
        $this->assertNotEmpty($carimbo['autorizado_em']);
    }

    public function test_a_conta_autorizada_sobrescreve_o_cust_id_digitado_errado(): void
    {
        $impl = $this->implementacao(['cust_id' => '111111111']);

        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'APP_USR-token', 'refresh_token' => 'TG-refresh',
                'user_id' => 987654321, 'expires_in' => 21600, 'token_type' => 'bearer',
            ]),
            'api.mercadolibre.com/users/*' => Http::response(['id' => 987654321, 'nickname' => 'LOJA.TESTE']),
        ]);

        $state = $this->stateDoRedirect($this->get(route('implementacao.conectar-ml', $impl->token))->headers->get('Location'));

        $this->get(route('ml.oauth.callback', ['code' => 'AUTH-CODE', 'state' => $state]))->assertOk();

        $this->assertSame('987654321', $impl->empresa->fresh()->cust_id);
        $this->assertSame('111111111', $impl->fresh()->dados['ml_oauth']['cust_id_anterior']);
    }

    public function test_apelido_indisponivel_nao_derruba_a_autorizacao(): void
    {
        // O cliente já autorizou do lado do ML — perder o /users é perder um
        // enfeite, não a autorização.
        $impl = $this->implementacao();

        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'APP_USR-token', 'refresh_token' => 'TG-refresh',
                'user_id' => 987654321, 'expires_in' => 21600, 'token_type' => 'bearer',
            ]),
            'api.mercadolibre.com/users/*' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $state = $this->stateDoRedirect($this->get(route('implementacao.conectar-ml', $impl->token))->headers->get('Location'));

        $this->get(route('ml.oauth.callback', ['code' => 'AUTH-CODE', 'state' => $state]))->assertOk();

        $this->assertSame('987654321', $impl->empresa->fresh()->cust_id);
        $this->assertNull($impl->fresh()->dados['ml_oauth']['nickname']);
    }

    public function test_nao_persiste_token_para_polos(): void
    {
        // `ml_tokens.company_id` é UNIQUE e a empresa de Polos não tem Company —
        // guardar token aqui exigiria criar Company, que é o pivô de
        // Desempenho/carteira/NPS. O fluxo é de identificação, não de conexão.
        $impl = $this->implementacao();

        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'APP_USR-token', 'refresh_token' => 'TG-refresh',
                'user_id' => 987654321, 'expires_in' => 21600, 'token_type' => 'bearer',
            ]),
            'api.mercadolibre.com/users/*' => Http::response(['id' => 987654321, 'nickname' => 'LOJA.TESTE']),
        ]);

        $state = $this->stateDoRedirect($this->get(route('implementacao.conectar-ml', $impl->token))->headers->get('Location'));

        $this->get(route('ml.oauth.callback', ['code' => 'AUTH-CODE', 'state' => $state]))->assertOk();

        $this->assertDatabaseCount('ml_tokens', 0);
    }
}
