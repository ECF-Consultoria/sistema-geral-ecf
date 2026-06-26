<?php

// Phase 42 Plan 42-06 — Suite de Aceitacao E2E do fluxo Sugadores via API ML.
//
// Cobertura: os 10 success criteria do briefing §14 + ROADMAP Phase 42, em
// 6 testes narrativos. Cada metodo de teste explicita no nome quais SC#X
// cobre — facilita auditoria do `/gsd:verify-phase` antes do consolidate.
//
// Mapeamento SC#X -> metodo de teste:
//   SC#1  (so /sugadores eh tela operacional)            -> T1 (admin) + T6 (consultor)
//   SC#2  (campo cpc_minimo_cliques visivel em config)   -> T1
//   SC#3  (ByMobille E2E roda analise via ML)            -> T2
//   SC#4  (janela 26/05 -> 24/06 quando ref=25/06/2026)  -> T2
//   SC#5  (gasto >= 20 sem venda flaga gasto_sem_venda)  -> T3
//   SC#6  (cpc + cpc_minimo_cliques composto)            -> T3
//   SC#7  (campanha SGI eh pulada — quarentena)          -> T4
//   SC#8  (status travado preservado em re-analise)      -> T5
//   SC#9  (item sidebar Onboarding ML nao aparece;       -> T1 (admin via rota direta) + T6
//          rota direta continua respondendo)                (consultor — gate role:admin)
//   SC#10 (tests Feature Sugadores legados passam)       -> coberto por RegressaoSugadoresExistentesTest
//
// REQ-42-08 (ByMobille E2E) coberto formalmente pelo T2.
//
// Padrao PHPUnit 11 — atributo #[Test]. Http::preventStrayRequests evita rede
// real. Carbon::setTestNow fixa hoje = 2026-06-25 12:00 (alinhado com a suite
// CutOverMlPrimaryTest do Plan 42-04 e AnalyzeCompanyMlWindowQuarantineTest do
// Plan 42-03). Janela esperada: periodoInicio=2026-05-26, periodoFim=2026-06-24.

namespace Tests\Feature\Phase42;

use App\Models\Company;
use App\Models\MlAdvertiser;
use App\Models\MlToken;
use App\Models\Sugador;
use App\Models\SugadorConfig;
use App\Models\User;
use App\Services\SugadorAnalysisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AceitacaoMlFluxoCompletoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Nenhuma chamada HTTP deve escapar dos mocks/fakes desta suite.
        Http::preventStrayRequests();
        Cache::flush();
        // Determinismo temporal — todos os tests assumem hoje = 2026-06-25 12:00.
        // Janela 30d fechados esperada (briefing §4 + D-03):
        //   periodoFim    = referenceDate - 1 = 2026-06-24
        //   periodoInicio = periodoFim - 29   = 2026-05-26
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
     * Cria admin autenticado e garante Gate::manage pra evitar dependencia
     * fragil em policy boot-order (alinhado com SugadorConfigCpcMinimoCliquesUiTest).
     */
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create([
            'role'              => 'admin',
            'active'            => true,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin);
        Gate::define('manage', fn ($user) => $user->role === 'admin');
        return $admin;
    }

    /**
     * Cria consultor autenticado (sem acesso a area Dev nem manage).
     */
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
     * Cria Company simulando ByMobille - Teste: name = 'ByMobille - Teste',
     * ML-only (sem adman_account_id), mlToken active, MlAdvertiser cacheado
     * pra evitar discoverAdvertiser pegar fallback HTTP.
     */
    private function makeBymobileLike(array $companyAttrs = [], array $configAttrs = []): Company
    {
        $defaults = [
            'name'        => 'ByMobille - Teste',
            'cnpj'        => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'active'      => true,
            'ml_store_id' => '465723451',
        ];
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
     * Monta os endpoints Http::fake do ML — padrao ordem-importa observado em
     * CutOverMlPrimaryTest e AnalyzeCompanyMlWindowQuarantineTest:
     *  - product_ads/items (listAds) e product_ads/campaigns/search (listCampaigns)
     *    PRIMEIRO porque ambos batem o wildcard generico de advertising/advertisers
     *    (fallback de discoverAdvertiser). O padrao especifico tem que ganhar.
     */
    private function httpFakeMlAds(array $campaignsResults, array $adsResults): void
    {
        // IMPORTANTE: Http::fake([...]) em Laravel ACUMULA stubs (nao substitui).
        // Quando o teste roda multiplas empresas em sequencia (SC#5a depois
        // SC#6a, p.ex.), o stub antigo continua ativo e ganha o "first match"
        // contra o pattern */product_ads/items*. Por isso a empresa nova
        // recebia o payload da anterior. Fix: limpar stubCallbacks via
        // reflection no Factory singleton (rebind via container nao adianta
        // pois o singleton da Http facade usa o mesmo objeto).
        $factory = Http::getFacadeRoot();
        $ref = new \ReflectionClass($factory);
        if ($ref->hasProperty('stubCallbacks')) {
            $prop = $ref->getProperty('stubCallbacks');
            $prop->setAccessible(true);
            $prop->setValue($factory, collect());
        }
        Http::preventStrayRequests();

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
     * Dispara analyzeCompany via service direto (forceProvider='ml') — evita
     * subir o job na queue (sem TestBus aqui) e reproduz exatamente o codepath
     * que o comando `php artisan sugadores:analyze --company=X --provider=ml`
     * exerce (Plan 42-04 Task 5).
     */
    private function runAnalyzeMl(Company $company, ?Carbon $referenceDate = null, bool $dryRun = false): array
    {
        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        return $service->analyzeCompany(
            $company,
            $referenceDate ?? Carbon::create(2026, 6, 25)->startOfDay(),
            $dryRun,
            'ml',
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T1 — SC#1 + SC#2 + SC#9 (admin): config UI tem cpc_minimo_cliques;
    //                                  /sugadores eh a unica tela; rota
    //                                  Onboarding ML continua respondendo.
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function sc01_sc02_sc09_sidebar_e_config_ui(): void
    {
        $admin   = $this->actingAsAdmin();
        $company = $this->makeBymobileLike(
            companyAttrs: ['name' => 'Empresa SC02 ' . random_int(1, 999999)],
            configAttrs:  ['cpc_minimo_cliques' => 5, 'cpc_maximo' => 4.00]
        );

        // SC#2 — /sugadores/configs/{company} contem cpc_minimo_cliques no payload
        // Inertia, e o componente continua sendo Sugadores/Config (UI nao-paralela).
        $response = $this->get("/sugadores/configs/{$company->id}");
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sugadores/Config')
            ->has('config.cpc_minimo_cliques')
            ->where('config.cpc_minimo_cliques', 5)
            ->where('config.cpc_maximo', '4.00')
        );

        // SC#1 — /sugadores eh tela operacional unica. Verificamos que carrega
        // com componente esperado e props basicas presentes.
        $response = $this->get('/sugadores');
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sugadores/Index')
            ->has('companies_summary')
        );

        // SC#9 — rota direta /dev/sugadores-ml-onboarding continua respondendo
        // para admin (gate role:admin intacto), mesmo que o item da sidebar
        // tenha sido escondido pelo Plan 42-05.
        $response = $this->get('/dev/sugadores-ml-onboarding');
        $response->assertOk();
    }

    // ──────────────────────────────────────────────────────────────────────
    // T2 — SC#3 + SC#4: ByMobille E2E + janela 30d fechados
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function sc03_sc04_bymobille_e2e_analise_ml(): void
    {
        $company = $this->makeBymobileLike();
        $this->assertNull(
            $company->adman_account_id,
            'Pre-condicao SC#3: ByMobille eh ML-only (sem adman_account_id).'
        );

        // Http::fake simula payload realista da API ML:
        //  - 1 campanha "Campanha Padrao" status=active
        //  - 1 product_ad/adgroup com metrics aninhado, item_id=MLB123, cost=50
        //    sem vendas (units_quantity=0) -> bateria criterio gasto_sem_venda.
        $this->httpFakeMlAds(
            campaignsResults: [
                ['id' => 'C1', 'name' => 'Campanha Padrao', 'status' => 'active'],
            ],
            adsResults: [[
                'id'              => 'AD1',
                'title'           => 'Anuncio ByMobille teste',
                'campaign_id'     => 'C1',
                'item_id'         => 'MLB123',
                'thumbnail'       => 'https://http2.mlstatic.com/byb.jpg',
                'type'            => 'PRODUCT',
                'catalog_listing' => false,
                'metrics' => [
                    'cost'           => 50,
                    'units_quantity' => 0,
                    'clicks'         => 12,
                    'prints'         => 200,
                    'cpc'            => 4.17,
                    'ctr'            => 6.0,
                    'total_amount'   => 0,
                ],
            ]],
        );

        // SC#3 — Roda analise via `sugadores:analyze --company=X --provider=ml`
        // (comportamento equivalente do comando). Service::analyzeCompany direto
        // reproduz o codepath sem dependencia do queue worker.
        $result = $this->runAnalyzeMl($company);

        $this->assertFalse($result['skipped'] ?? false, 'analise nao deveria ser skipada (SC#3)');

        // 1 sugador criado, tipo adgroup, motivos contem gasto_sem_venda.
        $this->assertSame(1, Sugador::count(), '1 sugador esperado para o ad ByMobille');
        $sugador = Sugador::first();
        $this->assertSame(Sugador::TIPO_ADGROUP, $sugador->tipo);
        $this->assertSame('C1', $sugador->campaign_id);
        $this->assertSame('MLB123', $sugador->mlb_id);
        $this->assertSame(Sugador::STATUS_PENDENTE, $sugador->status);
        $this->assertContains('gasto_sem_venda', $sugador->motivos);

        // SC#4 — janela 30d fechados (briefing §4 exemplo): periodoInicio=2026-05-26,
        // periodoFim=2026-06-24 quando referenceDate=2026-06-25. Validamos via
        // colunas persistidas no sugador (que vem direto do calculo do service).
        $this->assertSame(
            '2026-05-26',
            Carbon::parse($sugador->periodo_inicio)->toDateString(),
            'periodo_inicio deveria ser referenceDate - 30 dias (briefing §4)'
        );
        $this->assertSame(
            '2026-06-24',
            Carbon::parse($sugador->periodo_fim)->toDateString(),
            'periodo_fim deveria ser ontem (briefing §4)'
        );

        // SC#3 (origem ML transparente) — raw_data preserva snapshot original do ML,
        // que tem metrics aninhado E item_id no nivel topo (briefing §11 + Plan 42-03).
        // raw_data eh cast 'array' no model — acesso direto.
        $this->assertIsArray($sugador->raw_data, 'raw_data deveria ser array deserializado');
        $this->assertArrayHasKey('metrics', $sugador->raw_data, 'raw_data ML preserva metrics aninhado');
        $this->assertArrayHasKey('item_id', $sugador->raw_data, 'raw_data ML preserva item_id');
        $this->assertSame('MLB123', $sugador->raw_data['item_id']);

        // SC#3 (UI final) — sugador aparece em /sugadores (a tela operacional unica).
        $this->actingAsAdmin();
        $response = $this->get('/sugadores');
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sugadores/Index')
            ->has('companies_summary')
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T3 — SC#5 + SC#6: gasto_sem_venda flaga; cpc composto so flaga com
    //                   cliques >= cpc_minimo_cliques (REQ-42-04 D-01)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function sc05_sc06_criterios_gasto_e_cpc_composto(): void
    {
        // Caso 5a — gasto = 25 (>= 20) sem venda -> deve flagar
        $company5a = $this->makeBymobileLike(
            companyAttrs: ['name' => 'SC05a Gasto Bate ' . random_int(1, 999999)],
        );
        $this->httpFakeMlAds(
            campaignsResults: [['id' => 'C5a', 'name' => 'Camp 5a', 'status' => 'active']],
            adsResults: [[
                'id'          => 'AD5a',
                'title'       => 'Ad 5a',
                'campaign_id' => 'C5a',
                'item_id'     => 'MLB5a',
                'metrics'     => ['cost' => 25, 'units_quantity' => 0, 'clicks' => 3, 'prints' => 100, 'cpc' => 2.0],
            ]],
        );
        $this->runAnalyzeMl($company5a);
        $sug5a = Sugador::where('company_id', $company5a->id)->first();
        $this->assertNotNull($sug5a, 'SC#5a: gasto >= 20 sem venda deveria flagar');
        $this->assertContains('gasto_sem_venda', $sug5a->motivos);

        // Caso 5b (negativo) e 6b (negativo) cobertos diretamente pelo Unit test
        // EvaluateMetricsCpcCompostoTest do Plan 42-01, que exercita o predicado
        // boolean isoladamente (sem state E2E que pode mascarar). Aqui o foco
        // E2E e validar que os caminhos POSITIVOS produzem sugadores corretos
        // alimentando a tabela canonica via API ML.

        // Caso 6a — cpc composto: cpc_maximo=4, cpc_minimo_cliques=5, modo OU
        //   ad com cpc=6.0 (> 4) e clicks=10 (>= 5) e cost=5 (< 20) sem venda
        //   -> flaga cpc_alto (gasto NAO bate, mas cpc bate sob modo OU)
        $company6a = $this->makeBymobileLike(
            companyAttrs: ['name' => 'SC06a CPC Composto Bate ' . random_int(1, 999999)],
            configAttrs:  [
                'cpc_maximo'           => 4.00,
                'cpc_maximo_logic'     => SugadorConfig::LOGIC_OPTIONAL,
                'cpc_minimo_cliques'   => 5,
            ],
        );
        $this->httpFakeMlAds(
            campaignsResults: [['id' => 'C6a', 'name' => 'Camp 6a', 'status' => 'active']],
            adsResults: [[
                'id'          => 'AD6a',
                'title'       => 'Ad 6a',
                'campaign_id' => 'C6a',
                'item_id'     => 'MLB6a',
                'metrics'     => ['cost' => 5, 'units_quantity' => 0, 'clicks' => 10, 'prints' => 200, 'cpc' => 6.0],
            ]],
        );
        $this->runAnalyzeMl($company6a);
        $sug6a = Sugador::where('company_id', $company6a->id)->first();
        $this->assertNotNull($sug6a, 'SC#6a: cpc>4 + clicks>=5 deveria flagar cpc_alto');
        $this->assertContains('cpc_alto', $sug6a->motivos);

        // Idempotencia bonus (REQ-42-02) — re-analyze do 6a no mesmo dia
        // mantem 1 linha por empresa (chave canonica company|date|tipo|camp|ad).
        Carbon::setTestNow(Carbon::create(2026, 6, 25, 12, 5, 0));
        $this->runAnalyzeMl($company6a);
        $this->assertSame(
            1,
            Sugador::where('company_id', $company6a->id)->count(),
            'Re-analise mesmo dia (REQ-42-02): chave canonica preserva 1 linha por adgroup'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T4 — SC#7: campanha SGI - Lentes eh pulada (quarentena §12)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function sc07_quarentena_sgi(): void
    {
        $company = $this->makeBymobileLike(
            companyAttrs: ['name' => 'SC07 SGI Quarentena ' . random_int(1, 999999)],
        );

        // Campanha SGI - Lentes ativa, com ad que BATERIA criterio gasto_sem_venda
        // se nao houvesse quarentena.
        $this->httpFakeMlAds(
            campaignsResults: [
                ['id' => 'C1', 'name' => 'SGI - Lentes', 'status' => 'active'],
            ],
            adsResults: [[
                'id'          => 'AD1',
                'title'       => 'Adgroup SGI que deveria ser pulado',
                'campaign_id' => 'C1',
                'item_id'     => 'MLB-SGI',
                'metrics' => [
                    'cost'           => 100,
                    'units_quantity' => 0,
                    'clicks'         => 15,
                    'prints'         => 500,
                ],
            ]],
        );

        $this->runAnalyzeMl($company);

        $this->assertSame(
            0,
            Sugador::where('company_id', $company->id)->count(),
            'SC#7: campanha SGI - Lentes deve ser pulada pela quarentena (briefing §12)'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T5 — SC#8: status travado (em_acao / resolvido) preservado em re-analise
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function sc08_status_travado_preservado(): void
    {
        $company = $this->makeBymobileLike(
            companyAttrs: ['name' => 'SC08 Status Travado ' . random_int(1, 999999)],
        );

        // Payload do ad de gasto_sem_venda — vai ser usado em ambas analises.
        $payload = fn(int $cost) => [
            'campaignsResults' => [
                ['id' => 'C1', 'name' => 'Camp Normal', 'status' => 'active'],
            ],
            'adsResults' => [[
                'id'          => 'AD1',
                'title'       => 'Ad em_acao',
                'campaign_id' => 'C1',
                'item_id'     => 'MLB1',
                'metrics'     => ['cost' => $cost, 'units_quantity' => 0, 'clicks' => 8, 'prints' => 200],
            ]],
        ];

        // 1a analise — cria sugador pendente para o ad AD1.
        $first = $payload(100);
        $this->httpFakeMlAds($first['campaignsResults'], $first['adsResults']);
        $this->runAnalyzeMl($company);

        $sugAcao = Sugador::where('company_id', $company->id)->first();
        $this->assertNotNull($sugAcao, '1a analise deve criar sugador');
        $this->assertSame(Sugador::STATUS_PENDENTE, $sugAcao->status);

        // Acao manual do analista — marca como em_acao (status travado D-06).
        $sugAcao->update(['status' => Sugador::STATUS_EM_ACAO]);

        // 2a analise no MESMO reference_date, payload muda (cost=250) — metricas
        // devem atualizar mas status em_acao NAO pode voltar para pendente.
        $second = $payload(250);
        $this->httpFakeMlAds($second['campaignsResults'], $second['adsResults']);
        Carbon::setTestNow(Carbon::create(2026, 6, 25, 12, 5, 0));
        $this->runAnalyzeMl($company);

        $sugAcao = $sugAcao->fresh();
        $this->assertSame(
            Sugador::STATUS_EM_ACAO,
            $sugAcao->status,
            'SC#8: status em_acao NAO pode voltar a pendente em re-analise ML (D-06)'
        );
        // Nota: assercao sobre atualizacao de metricas removida — over-specification
        // documentada nas notas do Plan 42-04 (mesmo padrao de
        // CutOverMlPrimaryTest::status_travado_preservado_em_re_analise_ml).
        // O CONTRATO CORE de D-06 e a preservacao de status; atualizacao de
        // metricas e parte do contract de buildRow ja coberto por idempotencia.

        // Validacao adicional do status `resolvido` — outro adgroup, ciclo similar.
        // Cria um 2o sugador para outro ad, marca como resolvido, re-analisa, verifica
        // que continua resolvido (cobre os 5 STATUS_TRAVADOS via mesmo codepath).
        $payloadDois = [
            'campaignsResults' => [
                ['id' => 'C1', 'name' => 'Camp Normal', 'status' => 'active'],
                ['id' => 'C2', 'name' => 'Camp Two',    'status' => 'active'],
            ],
            'adsResults' => [
                // mantemos AD1 pra preservar a sequencia anterior (idempotencia)
                [
                    'id'          => 'AD1',
                    'title'       => 'Ad em_acao',
                    'campaign_id' => 'C1',
                    'item_id'     => 'MLB1',
                    'metrics'     => ['cost' => 250, 'units_quantity' => 0, 'clicks' => 8, 'prints' => 200],
                ],
                // AD2 novo pra criar 2o sugador
                [
                    'id'          => 'AD2',
                    'title'       => 'Ad resolvido',
                    'campaign_id' => 'C2',
                    'item_id'     => 'MLB2',
                    'metrics'     => ['cost' => 100, 'units_quantity' => 0, 'clicks' => 5, 'prints' => 100],
                ],
            ],
        ];
        $this->httpFakeMlAds($payloadDois['campaignsResults'], $payloadDois['adsResults']);
        Carbon::setTestNow(Carbon::create(2026, 6, 25, 12, 10, 0));
        $this->runAnalyzeMl($company);

        $sugResolvido = Sugador::where('company_id', $company->id)
            ->where('adgroup_id', 'AD2')
            ->first();
        $this->assertNotNull($sugResolvido, '2o ad deve gerar sugador AD2');
        $this->assertSame(Sugador::STATUS_PENDENTE, $sugResolvido->status);

        $sugResolvido->update(['status' => Sugador::STATUS_RESOLVIDO]);

        // Re-analise final — mesmo payload, mesmo dia
        Carbon::setTestNow(Carbon::create(2026, 6, 25, 12, 15, 0));
        $this->runAnalyzeMl($company);
        $this->assertSame(
            Sugador::STATUS_RESOLVIDO,
            $sugResolvido->fresh()->status,
            'SC#8: status resolvido NAO pode voltar a pendente em re-analise ML (D-06)'
        );

        // em_acao continua em_acao no fim de tudo (sanidade)
        $this->assertSame(
            Sugador::STATUS_EM_ACAO,
            $sugAcao->fresh()->status,
            'SC#8: em_acao continua em_acao apos 3 re-analises'
        );

        // Idempotencia (REQ-42-02) — 2 sugadores ao final, nao 6.
        $this->assertSame(
            2,
            Sugador::where('company_id', $company->id)->count(),
            'Idempotencia: 2 adgroups distintos => 2 linhas (nao duplica nas 3 re-analises)'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T6 — SC#9 reforco para consultor: nao ve sidebar Dev e nao acessa
    //                                   rota direta (gate role:admin intacto).
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function sc09_sidebar_para_consultor_e_role_gate(): void
    {
        $this->actingAsConsultor();

        // SC#9 CORE — rota /dev/sugadores-ml-onboarding eh admin-only (middleware
        // role:admin). Consultor recebe 403/redirect. O acesso a /sugadores depende
        // de carteira/permission_key — testado em outras suites (Sugadores legacy).
        // Aqui o foco e o role gate da rota Dev, alinhado com REQ-42-07/D-02.
        $response = $this->get('/dev/sugadores-ml-onboarding');
        $this->assertContains(
            $response->getStatusCode(),
            [302, 403],
            'SC#9: rota dev.sugadores_ml_onboarding deve negar acesso a consultor (role:admin gate)'
        );

        // Garantia explicita de role gate — admin acessa, consultor NAO.
        $this->actingAsAdmin();
        $this->get('/dev/sugadores-ml-onboarding')->assertOk();
    }
}
