<?php

/**
 * Phase 41 Plan 41-05 — Suite Feature da UI admin de onboarding ML por empresa.
 *
 * Cobre os 10 tests pedidos no <interfaces> do PLAN: render Inertia + payload,
 * derivacao de token_state/advertiser/status, gate role:admin (403), toggle de
 * shadow_enabled com timestamps, e dispatch async via Artisan::queue para
 * runSmoke/runShadow.
 *
 * Estado RED: todos os 10 tests falham porque o Controller, as 4 rotas, o
 * sidebar item e a pagina React ainda nao existem.
 *
 * Comentarios em pt-BR conforme convencao do projeto (CLAUDE.md).
 */

namespace Tests\Feature\Phase41;

use App\Models\Company;
use App\Models\MlAdvertiser;
use App\Models\MlToken;
use App\Models\SugadorMlCompanyConfig;
use App\Models\SugadorProviderRun;
use App\Models\User;
use App\Services\Sugadores\ProviderComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SugadoresMlOnboardingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Determinismo do shadow_started_at (toDateString()) + checagens de paridade 7d.
        Carbon::setTestNow('2026-06-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    /** Cria um admin autenticado (role=admin + email_verified). */
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create([
            'role'              => 'admin',
            'active'            => true,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    /** Cria um consultor autenticado (role=consultor + email_verified). */
    private function actingAsConsultor(): User
    {
        $consultor = User::factory()->create([
            'role'              => 'consultor',
            'active'            => true,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($consultor);
        return $consultor;
    }

    /**
     * Cria uma empresa active=true e opcionalmente um MlToken no estado pedido.
     *
     * Estados aceitos:
     *  - 'missing'        → sem MlToken
     *  - 'expired'        → token expires_at no passado, status='active'
     *  - 'error_refresh'  → token valido temporalmente, status='error_refresh'
     *  - 'active'         → token valido + status='active'
     */
    private function makeCompanyWithToken(string $tokenState = 'active', string $name = 'Empresa Teste'): Company
    {
        $company = Company::create([
            'name'   => $name,
            'active' => true,
        ]);

        if ($tokenState === 'missing') {
            return $company;
        }

        $attrs = [
            'company_id'    => $company->id,
            'access_token'  => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'status'        => 'active',
            'expires_at'    => now()->addHour(),
        ];

        if ($tokenState === 'expired') {
            $attrs['expires_at'] = now()->subHour();
        } elseif ($tokenState === 'error_refresh') {
            $attrs['status'] = 'error_refresh';
        }

        MlToken::create($attrs);

        return $company->fresh();
    }

    /** Habilita shadow (e opcionalmente primary) para uma empresa via SugadorMlCompanyConfig. */
    private function enableShadowFor(Company $c, bool $primary = false): SugadorMlCompanyConfig
    {
        return SugadorMlCompanyConfig::updateOrCreate(
            ['company_id' => $c->id],
            [
                'shadow_enabled'    => true,
                'primary_enabled'   => $primary,
                'shadow_started_at' => now()->toDateString(),
            ]
        );
    }

    /** Cria 1 row do MlAdvertiser apontando para a empresa. */
    private function attachAdvertiser(Company $c, string $advertiserId = 'ADV-001'): MlAdvertiser
    {
        return MlAdvertiser::create([
            'company_id'    => $c->id,
            'advertiser_id' => $advertiserId,
            'seller_id'     => 'SELLER-001',
            'site_id'       => 'MLB',
            'raw_data'      => ['site_id' => 'MLB'],
            'discovered_at' => now(),
        ]);
    }

    /**
     * Cria um SugadorProviderRun "completed" para a empresa no dia atual (provider='ml').
     * Util para destravar a leitura de paridade 7d no controller.
     */
    private function makeProviderRun(Company $c, string $provider = 'ml'): SugadorProviderRun
    {
        return SugadorProviderRun::create([
            'company_id'     => $c->id,
            'provider'       => $provider,
            'reference_date' => now()->toDateString(),
            'status'         => 'completed',
            'summary'        => ['campanhas' => 0, 'adgroups' => 0, 'items' => 0],
            'started_at'     => now()->subMinutes(2),
            'finished_at'    => now(),
        ]);
    }

    /**
     * Mocka o ProviderComparisonService::compareWindow retornando paridade fixa.
     * Usado em T6 para forcar o status='ml_primary_candidate' quando paridade >= 95.
     */
    private function bindComparisonReturning(float $paridadePct): void
    {
        $mock = Mockery::mock(ProviderComparisonService::class);
        $mock->shouldReceive('compareWindow')
             ->andReturn(['paridade_motivos_pct' => $paridadePct]);
        $this->app->instance(ProviderComparisonService::class, $mock);
    }

    // ───────────────────────────── T1..T10 ─────────────────────────────

    /** T1: GET / com admin renderiza componente Inertia correto. */
    #[Test]
    public function index_renderiza_inertia_component(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/dev/sugadores-ml-onboarding');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dev/SugadoresMlOnboarding/Index')
            ->has('companies')
        );
    }

    /** T2: payload `companies` tem 1 row por empresa active=true (active=false e excluida). */
    #[Test]
    public function index_payload_tem_companies_array(): void
    {
        $this->actingAsAdmin();

        // 2 empresas ativas + 1 inativa (deve ser excluida do payload).
        $this->makeCompanyWithToken('active', 'Empresa Ativa A');
        $this->makeCompanyWithToken('missing', 'Empresa Ativa B');
        Company::create(['name' => 'Empresa Inativa', 'active' => false]);

        $response = $this->get('/dev/sugadores-ml-onboarding');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dev/SugadoresMlOnboarding/Index')
            ->has('companies', 2)
        );
    }

    /** T3: cada row do payload tem as keys esperadas. */
    #[Test]
    public function index_company_row_tem_keys_esperadas(): void
    {
        $this->actingAsAdmin();
        $this->makeCompanyWithToken('active', 'Empresa Keys');

        $response = $this->get('/dev/sugadores-ml-onboarding');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dev/SugadoresMlOnboarding/Index')
            ->has('companies.0', fn (Assert $row) => $row
                ->has('id')
                ->has('name')
                ->has('token_state')
                ->has('advertiser')
                ->has('smoke_last_at')
                ->has('shadow_paridade_7d')
                ->has('status')
                ->has('actions')
            )
        );
    }

    /** T4: token_state derivado corretamente para os 4 estados canonicos. */
    #[Test]
    public function index_token_state_calculado_corretamente(): void
    {
        $this->actingAsAdmin();

        $this->makeCompanyWithToken('missing',       'Empresa Missing');
        $this->makeCompanyWithToken('expired',       'Empresa Expired');
        $this->makeCompanyWithToken('error_refresh', 'Empresa ErrorRefresh');
        $this->makeCompanyWithToken('active',        'Empresa Active');

        $response = $this->get('/dev/sugadores-ml-onboarding');

        $response->assertOk();
        $response->assertInertia(function (Assert $page) {
            $companies = collect($page->toArray()['props']['companies']);
            $byName = $companies->keyBy('name');

            $this->assertSame('missing',       $byName['Empresa Missing']['token_state']);
            $this->assertSame('expired',       $byName['Empresa Expired']['token_state']);
            $this->assertSame('error_refresh', $byName['Empresa ErrorRefresh']['token_state']);
            $this->assertSame('active',        $byName['Empresa Active']['token_state']);
        });
    }

    /** T5: advertiser='ok' quando MlAdvertiser existe; 'none' caso contrario. */
    #[Test]
    public function index_advertiser_state_ok_quando_mlAdvertiser_existe(): void
    {
        $this->actingAsAdmin();
        $comAdv = $this->makeCompanyWithToken('active', 'Empresa Com Advertiser');
        $semAdv = $this->makeCompanyWithToken('active', 'Empresa Sem Advertiser');
        $this->attachAdvertiser($comAdv);

        $response = $this->get('/dev/sugadores-ml-onboarding');

        $response->assertOk();
        $response->assertInertia(function (Assert $page) {
            $companies = collect($page->toArray()['props']['companies'])->keyBy('name');
            $this->assertSame('ok',   $companies['Empresa Com Advertiser']['advertiser']);
            $this->assertSame('none', $companies['Empresa Sem Advertiser']['advertiser']);
        });
    }

    /** T6: status geral derivado conforme config + paridade. */
    #[Test]
    public function index_status_geral_derivado(): void
    {
        $this->actingAsAdmin();

        // Mockamos paridade alta para forcar candidate em qualquer empresa com shadow_enabled.
        $this->bindComparisonReturning(97.0);

        $admanOnly      = $this->makeCompanyWithToken('active', 'Empresa Adman Only');
        $mlShadow       = $this->makeCompanyWithToken('active', 'Empresa ML Shadow');
        $mlCandidate    = $this->makeCompanyWithToken('active', 'Empresa ML Candidate');
        $mlPrimary      = $this->makeCompanyWithToken('active', 'Empresa ML Primary');

        // mlShadow: shadow_enabled=true, mas paridade baixa via run real (sem candidato).
        SugadorMlCompanyConfig::create([
            'company_id'        => $mlShadow->id,
            'shadow_enabled'    => true,
            'primary_enabled'   => false,
            'shadow_started_at' => now()->toDateString(),
        ]);
        // Forca paridade baixa para mlShadow nao virar candidato: criamos 0 runs, paridade=null
        // SO mlCandidate vai ter run no ultimo 7d → mock retorna 97 → status=candidate.

        SugadorMlCompanyConfig::create([
            'company_id'        => $mlCandidate->id,
            'shadow_enabled'    => true,
            'primary_enabled'   => false,
            'shadow_started_at' => now()->toDateString(),
        ]);
        $this->makeProviderRun($mlCandidate, 'ml');

        SugadorMlCompanyConfig::create([
            'company_id'        => $mlPrimary->id,
            'shadow_enabled'    => true,
            'primary_enabled'   => true,
            'shadow_started_at' => now()->toDateString(),
            'primary_promoted_at' => now()->toDateString(),
        ]);

        $response = $this->get('/dev/sugadores-ml-onboarding');

        $response->assertOk();
        $response->assertInertia(function (Assert $page) {
            $byName = collect($page->toArray()['props']['companies'])->keyBy('name');
            $this->assertSame('adman_only',           $byName['Empresa Adman Only']['status']);
            $this->assertSame('ml_shadow',            $byName['Empresa ML Shadow']['status']);
            $this->assertSame('ml_primary_candidate', $byName['Empresa ML Candidate']['status']);
            $this->assertSame('ml_primary',           $byName['Empresa ML Primary']['status']);
        });
    }

    /** T7: nao-admin recebe 403 em GET. */
    #[Test]
    public function index_nao_admin_recebe_403(): void
    {
        $this->actingAsConsultor();

        $response = $this->get('/dev/sugadores-ml-onboarding');
        $response->assertForbidden();
    }

    /** T8: toggleShadow flipa shadow_enabled + ajusta shadow_started_at. */
    #[Test]
    public function toggleShadow_flip_funciona(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompanyWithToken('active', 'Empresa Toggle');

        // Primeira chamada: ativa.
        $r1 = $this->post("/dev/sugadores-ml-onboarding/{$company->id}/toggle-shadow");
        $r1->assertRedirect();

        $cfg = SugadorMlCompanyConfig::where('company_id', $company->id)->first();
        $this->assertNotNull($cfg);
        $this->assertTrue((bool) $cfg->shadow_enabled);
        $this->assertNotNull($cfg->shadow_started_at);
        $this->assertSame(now()->toDateString(), $cfg->shadow_started_at->toDateString());

        // Segunda chamada: desativa.
        $r2 = $this->post("/dev/sugadores-ml-onboarding/{$company->id}/toggle-shadow");
        $r2->assertRedirect();

        $cfg2 = SugadorMlCompanyConfig::where('company_id', $company->id)->first();
        $this->assertFalse((bool) $cfg2->shadow_enabled);
        $this->assertNull($cfg2->shadow_started_at);
    }

    /** T9: runSmoke + runShadow dispacham comandos via Artisan::queue. */
    #[Test]
    public function runSmoke_e_runShadow_dispatcham_comandos(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompanyWithToken('active', 'Empresa Dispatch');

        Artisan::shouldReceive('queue')
            ->once()
            ->with('sugadores:ml-smoke', Mockery::on(function ($args) use ($company) {
                return isset($args['--company']) && (int) $args['--company'] === $company->id;
            }));

        Artisan::shouldReceive('queue')
            ->once()
            ->with('sugadores:shadow-ml', Mockery::on(function ($args) use ($company) {
                return isset($args['--company']) && (int) $args['--company'] === $company->id
                    && isset($args['--days']);
            }));

        $this->post("/dev/sugadores-ml-onboarding/{$company->id}/smoke")->assertRedirect();
        $this->post("/dev/sugadores-ml-onboarding/{$company->id}/shadow")->assertRedirect();
    }

    /** T10: as 4 rotas (1 GET + 3 POST) sao protegidas por role:admin (403 para consultor). */
    #[Test]
    public function rotas_protegidas_por_role_admin(): void
    {
        $this->actingAsConsultor();
        $company = Company::create(['name' => 'Empresa Proibida', 'active' => true]);

        $this->get('/dev/sugadores-ml-onboarding')->assertForbidden();
        $this->post("/dev/sugadores-ml-onboarding/{$company->id}/smoke")->assertForbidden();
        $this->post("/dev/sugadores-ml-onboarding/{$company->id}/shadow")->assertForbidden();
        $this->post("/dev/sugadores-ml-onboarding/{$company->id}/toggle-shadow")->assertForbidden();
    }
}
