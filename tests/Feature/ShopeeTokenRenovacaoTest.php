<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ShopeeToken;
use App\Models\User;
use App\Services\Shopee\ShopeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Robustez da conexão Shopee (quick 260917-jol): a falha de renovação fica
 * registrada no token (visível no painel), o keep-alive shopee:refresh-tokens
 * renova só o que precisa, e o "Gerar link" devolve a nova expiração.
 */
class ShopeeTokenRenovacaoTest extends TestCase
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
            'services.shopee.apps.erp.redirect'    => 'https://admin.test/oauth/shopee/callback',
            'services.shopee.apps.ads.partner_id'  => 999888,
            'services.shopee.apps.ads.partner_key' => 'shpk_ads_key',
            'services.shopee.apps.ads.redirect'    => 'https://admin.test/oauth/shopee/ads/callback',
        ]);
    }

    private function token(Company $company, array $attrs = []): ShopeeToken
    {
        return ShopeeToken::create(array_merge([
            'company_id'         => $company->id,
            'app'                => 'erp',
            'shop_id'            => '99',
            'access_token'       => 'atk_1',
            'refresh_token'      => 'rtk_1',
            'expires_at'         => now()->addMinutes(5),
            'refresh_expires_at' => now()->addDays(30),
            'last_refreshed_at'  => now()->subHours(4),
            'status'             => 'active',
        ], $attrs));
    }

    private function fakeRefreshOk(): void
    {
        Http::fake([
            '*api/v2/auth/access_token/get*' => Http::response([
                'access_token' => 'atk_novo', 'refresh_token' => 'rtk_novo', 'expire_in' => 14400,
            ], 200),
        ]);
    }

    // ── Registro da falha ─────────────────────────────────────────────────────

    public function test_erro_transitorio_mantem_ativo_e_grava_last_error(): void
    {
        Http::fake([
            '*api/v2/auth/access_token/get*' => Http::response(['error' => 'error_server', 'message' => 'busy'], 500),
        ]);

        $token = $this->token(Company::factory()->create());

        try {
            app(ShopeeService::class)->refreshToken($token);
            $this->fail('deveria ter lançado RuntimeException');
        } catch (\RuntimeException) {
        }

        $fresh = $token->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertStringContainsString('error_server', $fresh->last_error);
        $this->assertNotNull($fresh->last_error_at);
        $this->assertTrue($fresh->renovacaoComProblema());
    }

    public function test_revogacao_grava_last_error(): void
    {
        Http::fake([
            '*api/v2/auth/access_token/get*' => Http::response(['error' => 'invalid_refresh_token', 'message' => 'expired'], 200),
        ]);

        $token = $this->token(Company::factory()->create());

        try {
            app(ShopeeService::class)->refreshToken($token);
        } catch (\RuntimeException) {
        }

        $fresh = $token->fresh();
        $this->assertSame('revoked', $fresh->status);
        $this->assertStringContainsString('reconectar', $fresh->last_error);
    }

    public function test_renovacao_com_sucesso_limpa_o_erro(): void
    {
        $this->fakeRefreshOk();

        $token = $this->token(Company::factory()->create(), [
            'last_error'    => 'Erro transitório — conexão mantida ativa (error_server)',
            'last_error_at' => now()->subHour(),
        ]);

        $fresh = app(ShopeeService::class)->refreshToken($token);

        $this->assertNull($fresh->last_error);
        $this->assertNull($fresh->last_error_at);
        $this->assertFalse($fresh->renovacaoComProblema());
    }

    public function test_renovacao_atrasada_conta_como_problema(): void
    {
        $token = $this->token(Company::factory()->create(), ['last_refreshed_at' => now()->subHours(40)]);

        $this->assertTrue($token->renovacaoComProblema());
    }

    // ── Keep-alive ────────────────────────────────────────────────────────────

    public function test_keep_alive_renova_so_tokens_antigos_mesmo_com_access_valido(): void
    {
        $this->fakeRefreshOk();

        $antigo = $this->token(Company::factory()->create(), [
            'expires_at'        => now()->addHours(3), // access ainda válido → só renova com force
            'last_refreshed_at' => now()->subHours(20),
        ]);
        $recente = $this->token(Company::factory()->create(), [
            'app'               => 'ads',
            'expires_at'        => now()->addHours(3),
            'last_refreshed_at' => now()->subHours(2),
        ]);

        $this->artisan('shopee:refresh-tokens')->assertSuccessful();

        $this->assertSame('rtk_novo', $antigo->fresh()->refresh_token);
        $this->assertSame('rtk_1', $recente->fresh()->refresh_token);
        Http::assertSentCount(1);
    }

    public function test_keep_alive_reativa_revogado_recente(): void
    {
        $this->fakeRefreshOk();

        $token = $this->token(Company::factory()->create(), ['status' => 'revoked']);

        $this->artisan('shopee:refresh-tokens')->assertSuccessful();

        $this->assertSame('active', $token->fresh()->status);
    }

    // ── Painel ────────────────────────────────────────────────────────────────

    public function test_gerar_link_devolve_nova_expiracao(): void
    {
        $admin   = User::factory()->create(['role' => 'admin', 'active' => true]);
        $company = Company::factory()->create(['shopee_link_generated_at' => now()->subDays(40)]);

        $resp = $this->actingAs($admin)->postJson(route('shopee.oauth.initiate', $company));

        $resp->assertOk()->assertJsonStructure(['url', 'generated_at', 'expires_at']);
        $this->assertTrue(\Carbon\Carbon::parse($resp->json('expires_at'))->gt(now()->addDays(6)));
    }

    public function test_painel_expoe_status_de_renovacao_sem_vazar_tokens(): void
    {
        $admin   = User::factory()->create(['role' => 'admin', 'active' => true]);
        $company = Company::factory()->create();
        $this->token($company, ['last_error' => 'Falha de conexão: timeout', 'last_error_at' => now()]);
        $this->token($company, ['app' => 'ads']);

        $resp = $this->actingAs($admin)->get(route('shopee.oauth.index'));

        $resp->assertOk();
        $linha = collect($resp->viewData('page')['props']['companies'])->firstWhere('id', $company->id);
        $this->assertTrue($linha['shopee_token']['com_problema']);
        $this->assertSame('active', $linha['shopee_ads_token']['status']);
        $this->assertArrayNotHasKey('access_token', $linha['shopee_token']);
        $this->assertStringNotContainsString('rtk_1', $resp->getContent());
    }
}
