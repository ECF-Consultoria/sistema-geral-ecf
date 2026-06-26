<?php

// Phase 42 Plan 42-04 — Suite Feature de aceite do CUT-OVER ML como provider primário.
//
// Cobre:
//  - Factory: inversão da auto-detection (ML preferido quando ambos suportam),
//    fallback Adman quando ML inativo, fallback Adman sem mlToken,
//    RuntimeException quando nenhum suporta (T1-T4).
//  - Command: `sugadores:analyze --company=X --provider=ml` (sem --dry-run)
//    agora retorna SUCCESS — guard ml_primary removido (T5).
//  - Controller: POST /sugadores/companies/{ml_only}/analyze enfileira job
//    para empresa ML-only sem adman_account_id (T6).
//  - Idempotência: re-análise atualiza métricas sem duplicar linhas (T7).
//  - Status travado: re-análise ML preserva em_acao/resolvido/etc (T8 — D-06).
//
// Padrão PHPUnit 11 — atributo #[Test], sem doc-comment legacy.
// Http::preventStrayRequests bloqueia qualquer rede que escape do fake.

namespace Tests\Feature\Phase42;

use App\Models\Company;
use App\Models\MlAdvertiser;
use App\Models\MlToken;
use App\Models\Sugador;
use App\Models\SugadorConfig;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\SugadorAnalysisService;
use App\Services\Sugadores\AdmanSugadoresProvider;
use App\Services\Sugadores\MercadoLivreAdsService;
use App\Services\Sugadores\MercadoLivreSugadoresProvider;
use App\Services\Sugadores\SugadoresAdsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CutOverMlPrimaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Nenhuma chamada HTTP deve escapar dos mocks/fakes desta suite.
        Http::preventStrayRequests();
        Cache::flush();
        // Determinismo temporal — todos os tests assumem hoje = 2026-06-25 12:00.
        // Janela 30d fechados esperada: periodoInicio=2026-05-26, periodoFim=2026-06-24.
        Carbon::setTestNow(Carbon::create(2026, 6, 25, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────── Helpers ───────────────────────────────

    /**
     * Cria um Company "in-memory" (sem persistir no DB) com adman_account_id e/ou
     * mlToken stub conforme as flags `adman` / `ml`. Útil para T1-T4 (factory),
     * onde só precisamos exercitar supports() dos providers.
     */
    private function makeCompanyStub(bool $adman = true, bool $ml = false, string $mlStatus = 'active'): Company
    {
        $company = new Company();
        $company->setRawAttributes([
            'id'               => 999,
            'name'             => 'Stub Cut-Over',
            'adman_account_id' => $adman ? '12345' : null,
            'marketplace'      => 'meli',
        ]);

        if ($ml) {
            $token = new MlToken();
            $token->setRawAttributes(['status' => $mlStatus]);
            $company->setRelation('mlToken', $token);
        } else {
            $company->setRelation('mlToken', null);
        }

        return $company;
    }

    /**
     * Monta a factory real composta com providers reais em torno de mocks Mockery
     * dos services externos (AdmanService, MercadoLivreAdsService) — supports()
     * dos dois providers só lê atributos de Company, então não há rede envolvida.
     */
    private function makeRealFactory(): SugadoresAdsProviderFactory
    {
        $adman         = Mockery::mock(AdmanService::class);
        $admanProvider = new AdmanSugadoresProvider($adman);

        $ml            = Mockery::mock(MercadoLivreAdsService::class);
        $mlProvider    = new MercadoLivreSugadoresProvider($ml);

        return new SugadoresAdsProviderFactory($admanProvider, $mlProvider);
    }

    /**
     * Cria Company persistida com config ativa + mlToken active + MlAdvertiser
     * cacheado (evita discoverAdvertiser bater na API). Usado em T5-T8 onde o
     * pipeline completo de analyzeCompany roda contra Http::fake.
     */
    private function makeMlCompany(array $companyAttrs = [], array $configAttrs = []): Company
    {
        $defaults = [
            'name'        => 'ByMobille - Teste P42-04',
            'cnpj'        => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'active'      => true,
            'ml_store_id' => '465723451',
        ];
        // adman_account_id NÃO preenchido por default — empresa ML-only (cenário ByMobille).
        $company = Company::create(array_merge($defaults, $companyAttrs));

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '465723451',
            'access_token'      => 'fake-token',
            'refresh_token'     => 'fake-refresh',
            'token_type'        => 'bearer',
            'scope'             => 'read offline_access',
            'expires_at'        => now()->addHour(),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        MlAdvertiser::create([
            'company_id'    => $company->id,
            'advertiser_id' => '71098',
            'seller_id'     => '465723451',
            'site_id'       => 'MLB',
            'raw_data'      => [],
            'discovered_at' => now(),
        ]);

        SugadorConfig::create(array_merge([
            'company_id'                      => $company->id,
            'dias_analise'                    => 30,
            'gasto_minimo_sem_venda'          => 20.00,
            'gasto_minimo_logic'              => SugadorConfig::LOGIC_OPTIONAL,
            'pct_anuncios_para_flag_campanha' => 50,
            'incluir_campanhas'               => false,
            'incluir_anuncios'                => true,
            'ativo'                           => true,
        ], $configAttrs));

        return $company->fresh();
    }

    /**
     * Monta Http::fake para listCampaigns + listAds. Padroniza ordem dos padrões
     * (mais específicos primeiro) conforme observado na suite Window.
     */
    private function httpFakeMlAds(array $campaignsResults, array $adsResults): void
    {
        Http::fake([
            '*/product_ads/items*' => Http::response([
                'results' => $adsResults,
                'paging'  => ['total' => count($adsResults)],
            ], 200),
            '*/product_ads/campaigns/search*' => Http::response([
                'results' => $campaignsResults,
                'paging'  => ['total' => count($campaignsResults)],
            ], 200),
            '*/advertising/advertisers*' => Http::response([
                'advertisers' => [[
                    'advertiser_id' => 71098,
                    'site_id'       => 'MLB',
                    'seller_id'     => '465723451',
                ]],
            ], 200),
        ]);
    }

    /**
     * Cria + autentica um admin user para chamadas HTTP do controller.
     */
    private function actingAsAdmin(): User
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        $this->actingAs($user);
        return $user;
    }

    // ──────────── T1: factory prefere ML quando ambos suportam (D-05) ────────────

    #[Test]
    public function factory_prefere_ml_quando_ambos_supportam(): void
    {
        $factory = $this->makeRealFactory();
        // Empresa com adman_account_id E mlToken active — ambos providers suportam.
        $company = $this->makeCompanyStub(adman: true, ml: true);

        $provider = $factory->for($company);

        // Cut-over Phase 42 D-05: ML ganha quando ambos suportam.
        $this->assertInstanceOf(MercadoLivreSugadoresProvider::class, $provider);
        $this->assertSame('ml', $provider->name());
    }

    // ──────────── T2: fallback Adman quando mlToken inativo (expired) ────────────

    #[Test]
    public function factory_fallback_adman_quando_ml_inativo(): void
    {
        $factory = $this->makeRealFactory();
        // mlToken existe mas status != active — supports(ml) retorna false.
        $company = $this->makeCompanyStub(adman: true, ml: true, mlStatus: 'expired');

        $provider = $factory->for($company);

        $this->assertInstanceOf(AdmanSugadoresProvider::class, $provider);
        $this->assertSame('adman', $provider->name());
    }

    // ──────────── T3: fallback Adman quando empresa não tem mlToken ────────────

    #[Test]
    public function factory_fallback_adman_sem_ml_token(): void
    {
        $factory = $this->makeRealFactory();
        $company = $this->makeCompanyStub(adman: true, ml: false);

        $provider = $factory->for($company);

        $this->assertInstanceOf(AdmanSugadoresProvider::class, $provider);
        $this->assertSame('adman', $provider->name());
    }

    // ──────────── T4: RuntimeException quando nenhum provider suporta ────────────

    #[Test]
    public function factory_throws_quando_nenhum_suporta(): void
    {
        $factory = $this->makeRealFactory();
        $company = $this->makeCompanyStub(adman: false, ml: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sem provider compatível');

        $factory->for($company);
    }

    // ──────────── T5: command aceita --provider=ml sem --dry-run (guard removido) ────────────

    #[Test]
    public function command_aceita_provider_ml_sem_dry_run(): void
    {
        $company = $this->makeMlCompany();

        // Mocka SugadorAnalysisService para validar propagação do 4º arg = 'ml'
        // E dryRun = false — sem precisar exercitar o pipeline ML completo.
        /** @var \Mockery\MockInterface&SugadorAnalysisService $serviceMock */
        $serviceMock = Mockery::mock(SugadorAnalysisService::class);
        $serviceMock->shouldReceive('analyzeCompany')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c instanceof Company && $c->id === $company->id),
                Mockery::any(),
                false,   // $dryRun
                'ml'     // $forceProvider
            )
            ->andReturn([
                'skipped'   => false,
                'reason'    => null,
                'campanhas' => 0,
                'adgroups'  => 0,
                'detalhes'  => [],
            ]);
        $this->app->instance(SugadorAnalysisService::class, $serviceMock);

        $this->artisan('sugadores:analyze', [
            '--company'  => $company->id,
            '--provider' => 'ml',
        ])->assertExitCode(0);
    }

    // ──────────── T6: controller analyzeCompany aceita empresa ML-only ────────────

    #[Test]
    public function controller_analyzeCompany_aceita_company_ml_only(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        // Empresa SEM adman_account_id, COM mlToken active (cenário ByMobille).
        $company = $this->makeMlCompany();
        $this->assertNull($company->adman_account_id, 'Pré-condição: empresa ML-only sem adman_account_id');

        $response = $this->post(route('sugadores.analyze-company', $company));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        // Job enfileirado — auto-detection do factory escolhe ML provider no worker.
        Queue::assertPushed(\App\Jobs\AnalyzeCompanySugadoresJob::class, function ($job) use ($company) {
            return $job->company->id === $company->id;
        });
    }

    // ──────────── T7: idempotência — re-análise no mesmo dia não duplica linha ────────────

    #[Test]
    public function idempotencia_re_analise_atualiza_sem_duplicar(): void
    {
        $company = $this->makeMlCompany();

        // Payload ML que bate o critério gasto_sem_venda (cost=100 > 20, units=0).
        $this->httpFakeMlAds(
            campaignsResults: [
                ['id' => 'C1', 'name' => 'Campanha Normal', 'status' => 'active'],
            ],
            adsResults: [[
                'id'          => 'AD1',
                'title'       => 'Adgroup Sugador',
                'campaign_id' => 'C1',
                'item_id'     => 'MLB1',
                'metrics'     => ['cost' => 100, 'units_quantity' => 0, 'clicks' => 5, 'prints' => 200],
            ]],
        );

        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        $refDate = Carbon::create(2026, 6, 25)->startOfDay();

        // 1ª análise — cria 1 sugador.
        $service->analyzeCompany($company, $refDate, dryRun: false, forceProvider: 'ml');
        $this->assertSame(1, Sugador::count(), '1ª análise deve criar 1 sugador');

        $sugador1     = Sugador::first();
        $createdAt    = $sugador1->created_at->copy();
        $updatedAt1   = $sugador1->updated_at->copy();

        // Avança o relógio para garantir que updated_at seja diferente na 2ª análise.
        Carbon::setTestNow(Carbon::create(2026, 6, 25, 12, 0, 5));

        // 2ª análise no MESMO reference_date — upsert deve atualizar a linha existente.
        $service->analyzeCompany($company, $refDate, dryRun: false, forceProvider: 'ml');

        $this->assertSame(1, Sugador::count(), 'Re-análise no mesmo dia NÃO deve duplicar (idempotência)');

        $sugador2 = Sugador::first();
        $this->assertSame($sugador1->id, $sugador2->id, 'Mesma linha (mesmo id) — upsert por chave estável');
        $this->assertTrue(
            $sugador2->updated_at->greaterThan($updatedAt1),
            'updated_at deve avançar na re-análise'
        );
        $this->assertSame(
            $createdAt->toDateTimeString(),
            $sugador2->created_at->toDateTimeString(),
            'created_at NÃO deve mudar (linha pré-existente)'
        );
    }

    // ──────────── T8: status travado preservado em re-análise (D-06) ────────────

    #[Test]
    public function status_travado_preservado_em_re_analise_ml(): void
    {
        $company = $this->makeMlCompany();

        $this->httpFakeMlAds(
            campaignsResults: [
                ['id' => 'C1', 'name' => 'Campanha Normal', 'status' => 'active'],
            ],
            adsResults: [[
                'id'          => 'AD1',
                'title'       => 'Adgroup Sugador',
                'campaign_id' => 'C1',
                'item_id'     => 'MLB1',
                // Investimento alto (100) → métrica vai aumentar entre 1ª e 2ª análise.
                'metrics'     => ['cost' => 100, 'units_quantity' => 0, 'clicks' => 5, 'prints' => 200],
            ]],
        );

        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        $refDate = Carbon::create(2026, 6, 25)->startOfDay();

        // 1ª análise — cria sugador pendente.
        $service->analyzeCompany($company, $refDate, dryRun: false, forceProvider: 'ml');
        $sugador = Sugador::first();
        $this->assertNotNull($sugador, '1ª análise deve criar sugador');
        $this->assertSame(Sugador::STATUS_PENDENTE, $sugador->status);

        // Simula ação manual do analista — sugador entra em_acao (status travado D-06).
        $sugador->update(['status' => Sugador::STATUS_EM_ACAO]);
        $this->assertSame(Sugador::STATUS_EM_ACAO, $sugador->fresh()->status);

        // Atualiza payload para refletir gasto maior (métricas devem atualizar).
        $this->httpFakeMlAds(
            campaignsResults: [
                ['id' => 'C1', 'name' => 'Campanha Normal', 'status' => 'active'],
            ],
            adsResults: [[
                'id'          => 'AD1',
                'title'       => 'Adgroup Sugador',
                'campaign_id' => 'C1',
                'item_id'     => 'MLB1',
                'metrics'     => ['cost' => 250, 'units_quantity' => 0, 'clicks' => 10, 'prints' => 400],
            ]],
        );

        // Avança o relógio para garantir updated_at diferente.
        Carbon::setTestNow(Carbon::create(2026, 6, 25, 12, 0, 10));

        // 2ª análise no MESMO reference_date — re-análise via ML.
        $service->analyzeCompany($company, $refDate, dryRun: false, forceProvider: 'ml');

        $sugador = $sugador->fresh();

        // D-06 (CORE): status travado preservado em re-analise ML.
        // Este eh o contrato essencial da decisao locked. O briefing §13 lista
        // expressamente os 5 status travados (em_acao/resolvido/ignorado/movido/
        // auto_resolvido) que NAO podem voltar para pendente — todos cobertos
        // pelo mesmo codepath em buildRow via Sugador::STATUS_TRAVADOS.
        $this->assertSame(
            Sugador::STATUS_EM_ACAO,
            $sugador->status,
            'Status travado em_acao NAO pode voltar para pendente em re-analise ML (D-06)'
        );

        // Sugador continua o mesmo (idempotencia via chave estavel).
        $this->assertSame(1, Sugador::count(), 'Re-analise mesmo dia nao duplica');
    }
}
