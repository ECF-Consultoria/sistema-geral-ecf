<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ShopeeToken;
use App\Models\User;
use App\Services\Shopee\ShopeeService;
use App\Services\Shopee\ShopeeSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Ciclo OAuth Shopee com DOIS apps (consent encadeado) e API mockada (Http::fake):
 * link único assinado → landing guiada → Passo 1 (ERP) → Passo 2 (Ads).
 * Cobre: link assinado, troca de code, ROTAÇÃO do refresh_token, revogação,
 * encadeamento ERP→Ads, e a trava de "loja diferente" no passo Ads.
 */
class ShopeeOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.host'                 => 'https://openplatform.sandbox.test-stable.shopee.sg',
            'services.shopee.verify_ssl'           => false,
            'services.shopee.apps.erp.partner_id'  => 123456,
            'services.shopee.apps.erp.partner_key' => 'shpk_test_key',
            'services.shopee.apps.erp.redirect'    => 'https://desafio.ecfconsultoria.com.br/oauth/shopee/callback',
            // Ads DESLIGADO por padrão (isConfigured=false) — cada teste liga quando precisa.
            'services.shopee.apps.ads.partner_id'  => null,
            'services.shopee.apps.ads.partner_key' => null,
            'services.shopee.apps.ads.redirect'    => 'https://desafio.ecfconsultoria.com.br/oauth/shopee/ads/callback',
        ]);
    }

    private function configurarAds(): void
    {
        config([
            'services.shopee.apps.ads.partner_id'  => 999888,
            'services.shopee.apps.ads.partner_key' => 'shpk_ads_key',
            'services.shopee.apps.ads.redirect'    => 'https://desafio.ecfconsultoria.com.br/oauth/shopee/ads/callback',
        ]);
    }

    /** Extrai o `state` embutido no redirect de uma auth URL (o que a Shopee devolve). */
    private function extrairState(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        parse_str((string) parse_url($q['redirect'], PHP_URL_QUERY), $rq);

        return (string) $rq['state'];
    }

    // ── Link / landing ────────────────────────────────────────────────────────

    public function test_landing_assinada_mostra_passo1_com_botao_erp(): void
    {
        $company = Company::factory()->create();

        $url  = URL::signedRoute('shopee.connect.landing', ['company' => $company->id]);
        $resp = $this->get($url);

        $resp->assertOk();
        $resp->assertSee('Passo 1', false);
        $resp->assertSee('/api/v2/shop/auth_partner', false); // botão leva ao consent ERP
    }

    public function test_landing_sem_assinatura_e_bloqueada(): void
    {
        $company = Company::factory()->create();

        $this->get("/shopee/conectar/{$company->id}")
            ->assertStatus(403)
            ->assertSee('Link inválido', false);
    }

    public function test_landing_com_link_vencido_explica_que_venceu(): void
    {
        $company = Company::factory()->create();

        $url = URL::temporarySignedRoute('shopee.connect.landing', now()->addDays(7), ['company' => $company->id]);
        $this->travel(8)->days();

        $this->get($url)
            ->assertStatus(403)
            ->assertSee('Este link venceu', false)
            ->assertDontSee('/api/v2/shop/auth_partner', false);
    }

    public function test_landing_com_assinatura_adulterada_e_bloqueada(): void
    {
        $company = Company::factory()->create();
        $outra   = Company::factory()->create();

        $url = URL::temporarySignedRoute('shopee.connect.landing', now()->addDays(7), ['company' => $company->id]);
        $adulterada = str_replace("/conectar/{$company->id}?", "/conectar/{$outra->id}?", $url);

        $this->get($adulterada)
            ->assertStatus(403)
            ->assertSee('Link inválido', false);
    }

    public function test_regerar_link_vencido_grava_link_novo_e_devolve_a_validade_nova(): void
    {
        $admin   = User::factory()->create(['role' => 'admin', 'active' => true]);
        $company = Company::factory()->create([
            'shopee_link_generated_at' => now()->subDays(21),
            'shopee_link_url'          => 'https://link-velho-vencido',
        ]);

        $resp = $this->actingAs($admin)
            ->postJson(route('shopee.oauth.initiate', $company->id))
            ->assertOk()
            ->assertJsonStructure(['url', 'generated_at', 'expires_at']);

        // A tela de Empresas atualiza o selo com este expires_at — sem ele o
        // "Regerar" seguia mostrando "Link expirado".
        $this->assertTrue(now()->addDays(7)->diffInSeconds($resp->json('expires_at'), true) < 5);

        $company->refresh();
        $this->assertSame($resp->json('url'), $company->shopee_link_url);
        $this->assertTrue($company->shopee_link_generated_at->isToday());

        // O link novo abre a landing (não a página de link vencido).
        $this->get($resp->json('url'))->assertOk()->assertSee('Passo 1', false);
    }

    public function test_build_auth_url_monta_link_assinado(): void
    {
        $company = Company::factory()->create();

        $url = app(ShopeeService::class)->buildAuthUrl($company);

        $this->assertStringStartsWith(
            'https://openplatform.sandbox.test-stable.shopee.sg/api/v2/shop/auth_partner?',
            $url
        );
        $this->assertStringContainsString('partner_id=123456', $url);

        // O sign do link fecha com a assinatura PÚBLICA do app ERP p/ o timestamp embutido.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $signer = new ShopeeSigner(123456, 'shpk_test_key');
        $this->assertSame(
            $signer->sign('/api/v2/shop/auth_partner', (int) $q['timestamp']),
            $q['sign']
        );

        // O redirect carrega nosso state (vincula empresa+app no callback).
        parse_str((string) parse_url($q['redirect'], PHP_URL_QUERY), $rq);
        $this->assertNotEmpty($rq['state']);
    }

    // ── Tokens (troca / refresh) ──────────────────────────────────────────────

    public function test_exchange_code_persiste_token_erp_ativo(): void
    {
        Http::fake([
            '*api/v2/auth/token/get*' => Http::response([
                'access_token'  => 'atk_1',
                'refresh_token' => 'rtk_1',
                'expire_in'     => 14400,
            ], 200),
        ]);

        $company = Company::factory()->create();
        $svc     = app(ShopeeService::class);

        $data  = $svc->exchangeCode('code123', '55667788');
        $token = $svc->saveToken($company, '55667788', $data);

        $this->assertDatabaseHas('shopee_tokens', [
            'company_id' => $company->id,
            'app'        => 'erp',
            'shop_id'    => '55667788',
            'status'     => 'active',
        ]);
        $this->assertSame('atk_1', $token->access_token);   // cast 'encrypted' decripta
        $this->assertSame('rtk_1', $token->refresh_token);
        $this->assertTrue($token->expires_at->isFuture());
    }

    public function test_refresh_rotaciona_o_refresh_token(): void
    {
        Http::fake([
            '*api/v2/auth/access_token/get*' => Http::response([
                'access_token'  => 'atk_2',
                'refresh_token' => 'rtk_2',   // NOVO refresh — precisa ser persistido
                'expire_in'     => 14400,
            ], 200),
        ]);

        $company = Company::factory()->create();
        $token   = ShopeeToken::create([
            'company_id'         => $company->id,
            'app'                => 'erp',
            'shop_id'            => '99',
            'access_token'       => 'atk_1',
            'refresh_token'      => 'rtk_1',
            'expires_at'         => now()->addMinutes(5),
            'refresh_expires_at' => now()->addDays(30),
            'status'             => 'active',
        ]);

        $fresh = app(ShopeeService::class)->refreshToken($token);

        $this->assertSame('atk_2', $fresh->access_token);
        $this->assertSame('rtk_2', $fresh->refresh_token);          // rotacionou
        $this->assertSame('rtk_2', $token->fresh()->refresh_token); // e persistiu
    }

    public function test_refresh_com_erro_da_shopee_revoga(): void
    {
        Http::fake([
            '*api/v2/auth/access_token/get*' => Http::response([
                'error'   => 'invalid_refresh_token',
                'message' => 'refresh token expired',
            ], 200),
        ]);

        $company = Company::factory()->create();
        $token   = ShopeeToken::create([
            'company_id'         => $company->id,
            'app'                => 'erp',
            'shop_id'            => '99',
            'access_token'       => 'atk_1',
            'refresh_token'      => 'rtk_old',
            'expires_at'         => now()->addMinutes(5),
            'refresh_expires_at' => now()->addDays(30),
            'status'             => 'active',
        ]);

        try {
            app(ShopeeService::class)->refreshToken($token);
            $this->fail('deveria ter lançado RuntimeException');
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame('revoked', $token->fresh()->status);
    }

    // ── Callback ERP (passo 1) ────────────────────────────────────────────────

    public function test_callback_erp_sem_app_ads_conclui_so_com_erp(): void
    {
        Http::fake([
            '*api/v2/auth/token/get*' => Http::response([
                'access_token' => 'atk_x', 'refresh_token' => 'rtk_x', 'expire_in' => 14400,
            ], 200),
        ]);

        $company = Company::factory()->create();
        $state   = $this->extrairState(app(ShopeeService::class)->buildAuthUrl($company));

        $resp = $this->get("/oauth/shopee/callback?state={$state}&code=abc&shop_id=778899");

        $resp->assertOk();
        $resp->assertSee('sucesso', false);
        $this->assertDatabaseHas('shopee_tokens', [
            'company_id' => $company->id, 'app' => 'erp', 'shop_id' => '778899',
        ]);
        $this->assertDatabaseHas('company_marketplaces', [
            'company_id' => $company->id, 'marketplace' => 'shopee', 'store_id' => '778899', 'integracao_status' => 'ativa',
        ]);
    }

    public function test_callback_erp_com_app_ads_encadeia_para_passo2(): void
    {
        $this->configurarAds();
        Http::fake([
            '*api/v2/auth/token/get*' => Http::response([
                'access_token' => 'atk_x', 'refresh_token' => 'rtk_x', 'expire_in' => 14400,
            ], 200),
        ]);

        $company = Company::factory()->create();
        $state   = $this->extrairState(app(ShopeeService::class)->buildAuthUrl($company));

        $resp = $this->get("/oauth/shopee/callback?state={$state}&code=abc&shop_id=778899");

        $resp->assertOk();
        $resp->assertSee('Passo 2', false);
        $resp->assertSee('Ads', false);
        // O botão do passo 2 leva ao consent do APP ADS (partner_id do Ads).
        $resp->assertSee('partner_id=999888', false);
        // ERP já salvo; Ads ainda não.
        $this->assertDatabaseHas('shopee_tokens', ['company_id' => $company->id, 'app' => 'erp']);
        $this->assertDatabaseMissing('shopee_tokens', ['company_id' => $company->id, 'app' => 'ads']);
    }

    // ── Callback ADS (passo 2) ────────────────────────────────────────────────

    public function test_ads_callback_salva_token_ads_para_mesma_loja(): void
    {
        $this->configurarAds();
        Http::fake([
            '*api/v2/auth/token/get*' => Http::response([
                'access_token' => 'atk_ads', 'refresh_token' => 'rtk_ads', 'expire_in' => 14400,
            ], 200),
        ]);

        $company = Company::factory()->create();
        // Semeia o state do Ads (esperando a loja 778899, a mesma do passo 1).
        $adsUrl = ShopeeService::for('ads')->buildAuthUrl($company, '778899');
        $state  = $this->extrairState($adsUrl);

        $resp = $this->get("/oauth/shopee/ads/callback?state={$state}&code=abc&shop_id=778899");

        $resp->assertOk();
        $resp->assertSee('sucesso', false);
        $this->assertDatabaseHas('shopee_tokens', [
            'company_id' => $company->id, 'app' => 'ads', 'shop_id' => '778899', 'status' => 'active',
        ]);
    }

    public function test_ads_callback_bloqueia_loja_diferente(): void
    {
        $this->configurarAds();
        Http::fake(); // não deve trocar code nenhum

        $company = Company::factory()->create();
        $adsUrl  = ShopeeService::for('ads')->buildAuthUrl($company, '778899'); // esperava 778899
        $state   = $this->extrairState($adsUrl);

        $resp = $this->get("/oauth/shopee/ads/callback?state={$state}&code=abc&shop_id=000000");

        $resp->assertOk();
        $resp->assertSee('loja diferente', false);
        $this->assertDatabaseMissing('shopee_tokens', ['company_id' => $company->id, 'app' => 'ads']);
        Http::assertNothingSent();
    }

    // ── Robustez ──────────────────────────────────────────────────────────────

    public function test_callback_sem_parametros_falha_sem_500(): void
    {
        $resp = $this->get('/oauth/shopee/callback?state=&code=&shop_id=');

        $resp->assertOk();
        $resp->assertSee('inválidos', false);
        $this->assertDatabaseCount('shopee_tokens', 0);
    }

    public function test_callback_com_state_invalido_nao_salva(): void
    {
        $resp = $this->get('/oauth/shopee/callback?state=naoexiste&code=abc&shop_id=778899');

        $resp->assertOk();
        $resp->assertSee('expirado', false);
        $this->assertDatabaseCount('shopee_tokens', 0);
    }
}
