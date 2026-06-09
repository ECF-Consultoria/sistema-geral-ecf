<?php

namespace Tests\Feature\Phase30;

use App\Jobs\SyncCompanyAdgroupMlbsJob;
use App\Models\AdmanAdgroupMlb;
use App\Models\Company;
use App\Services\AdmanAdgroupMlbsRepository;
use App\Services\AdmanMcpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Tests\TestCase;

/**
 * Phase 30 Plan 30-04 — Sync agendado + drilldown via banco local.
 * Cobre Repository, Job, Command e integração no controller.
 */
class SyncAdgroupMlbsTest extends TestCase
{
    use RefreshDatabase;

    private AdmanAdgroupMlbsRepository $repo;
    private string $periodFrom;
    private string $periodTo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new AdmanAdgroupMlbsRepository();
        $this->periodFrom = '2026-05-10';
        $this->periodTo   = '2026-06-09';
    }

    // ─────────── Repository: findByAdgroup ───────────

    public function test_repository_find_by_adgroup_filtra_corretamente(): void
    {
        AdmanAdgroupMlb::create([
            'cust_id'       => '123',
            'adgroup_id'    => 'AG-1',
            'adgroup_name'  => 'Promo Black',
            'campaign_id'   => 'C-1',
            'campaign_name' => 'Promo',
            'mlb_id'        => 'MLB001',
            'title'         => 'Anuncio 1',
            'status'        => 'ACTIVE',
            'period_from'   => $this->periodFrom,
            'period_to'     => $this->periodTo,
            'metrics'       => ['clicks' => 10],
            'last_synced_at' => now(),
        ]);

        AdmanAdgroupMlb::create([
            'cust_id'       => '123',
            'adgroup_id'    => 'AG-2',
            'adgroup_name'  => 'Outro',
            'campaign_id'   => 'C-1',
            'campaign_name' => 'Promo',
            'mlb_id'        => 'MLB002',
            'title'         => 'Anuncio 2',
            'status'        => 'ACTIVE',
            'period_from'   => $this->periodFrom,
            'period_to'     => $this->periodTo,
            'metrics'       => ['clicks' => 20],
            'last_synced_at' => now(),
        ]);

        $result = $this->repo->findByAdgroup('123', 'Promo Black', $this->periodFrom, $this->periodTo);

        $this->assertCount(1, $result);
        $this->assertSame('MLB001', $result->first()->mlb_id);
    }

    public function test_repository_find_by_adgroup_retorna_vazio_quando_inexistente(): void
    {
        $result = $this->repo->findByAdgroup('999', 'Inexistente', $this->periodFrom, $this->periodTo);
        $this->assertCount(0, $result);
    }

    // ─────────── Repository: upsertBatch ───────────

    public function test_repository_upsert_batch_insere_e_atualiza(): void
    {
        $rows = [
            [
                'cust_id'       => '123',
                'adgroup_id'    => 'AG-1',
                'adgroup_name'  => 'Promo',
                'campaign_id'   => 'C-1',
                'campaign_name' => 'Promo',
                'mlb_id'        => 'MLB001',
                'title'         => 'Original',
                'status'        => 'ACTIVE',
                'period_from'   => $this->periodFrom,
                'period_to'     => $this->periodTo,
                'metrics'       => json_encode(['clicks' => 10]),
                'last_synced_at' => now(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ];
        $this->repo->upsertBatch($rows);
        $this->assertSame(1, AdmanAdgroupMlb::count());

        // 2ª chamada: mesma unique key — deve UPDATE, não INSERT
        $rows[0]['title']  = 'Atualizado';
        $rows[0]['metrics'] = json_encode(['clicks' => 99]);
        $this->repo->upsertBatch($rows);
        $this->assertSame(1, AdmanAdgroupMlb::count());

        $row = AdmanAdgroupMlb::first();
        $this->assertSame('Atualizado', $row->title);
        $this->assertSame(['clicks' => 99], $row->metrics);
    }

    public function test_repository_upsert_batch_vazio_retorna_zero(): void
    {
        $this->assertSame(0, $this->repo->upsertBatch([]));
    }

    // ─────────── Repository: lastSyncForCompany ───────────

    public function test_repository_last_sync_for_company_retorna_max(): void
    {
        $primeiro = now()->subHours(5);
        $ultimo   = now()->subMinutes(15);

        AdmanAdgroupMlb::create([
            'cust_id'       => '123',
            'adgroup_id'    => 'AG-1',
            'adgroup_name'  => 'Promo',
            'mlb_id'        => 'MLB001',
            'period_from'   => $this->periodFrom,
            'period_to'     => $this->periodTo,
            'last_synced_at' => $primeiro,
        ]);
        AdmanAdgroupMlb::create([
            'cust_id'       => '123',
            'adgroup_id'    => 'AG-2',
            'adgroup_name'  => 'Outro',
            'mlb_id'        => 'MLB002',
            'period_from'   => $this->periodFrom,
            'period_to'     => $this->periodTo,
            'last_synced_at' => $ultimo,
        ]);

        $result = $this->repo->lastSyncForCompany('123');
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta($ultimo->timestamp, $result->timestamp, 1);
    }

    public function test_repository_last_sync_retorna_null_quando_sem_registros(): void
    {
        $this->assertNull($this->repo->lastSyncForCompany('999'));
    }

    // ─────────── Repository: mlbsCountForCompany ───────────

    public function test_repository_count_for_company_no_range(): void
    {
        AdmanAdgroupMlb::create([
            'cust_id' => '123', 'adgroup_id' => 'AG-1', 'mlb_id' => 'MLB001',
            'period_from' => $this->periodFrom, 'period_to' => $this->periodTo, 'last_synced_at' => now(),
        ]);
        AdmanAdgroupMlb::create([
            'cust_id' => '123', 'adgroup_id' => 'AG-2', 'mlb_id' => 'MLB002',
            'period_from' => $this->periodFrom, 'period_to' => $this->periodTo, 'last_synced_at' => now(),
        ]);
        AdmanAdgroupMlb::create([
            'cust_id' => '999', 'adgroup_id' => 'AG-3', 'mlb_id' => 'MLB003',
            'period_from' => $this->periodFrom, 'period_to' => $this->periodTo, 'last_synced_at' => now(),
        ]);

        $this->assertSame(2, $this->repo->mlbsCountForCompany('123', $this->periodFrom, $this->periodTo));
        $this->assertSame(1, $this->repo->mlbsCountForCompany('999', $this->periodFrom, $this->periodTo));
    }

    // ─────────── Unique key constraint ───────────

    public function test_unique_key_impede_duplicata(): void
    {
        AdmanAdgroupMlb::create([
            'cust_id' => '123', 'adgroup_id' => 'AG-1', 'mlb_id' => 'MLB001',
            'period_from' => $this->periodFrom, 'period_to' => $this->periodTo, 'last_synced_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AdmanAdgroupMlb::create([
            'cust_id' => '123', 'adgroup_id' => 'AG-1', 'mlb_id' => 'MLB001',
            'period_from' => $this->periodFrom, 'period_to' => $this->periodTo, 'last_synced_at' => now(),
        ]);
    }

    // ─────────── Job: SyncCompanyAdgroupMlbsJob ───────────

    public function test_job_aplica_middleware_rate_limited_adman_api(): void
    {
        $company = Company::create(['name' => 'Teste 999', 'adman_account_id' => '999', 'active' => true]);

        $job = new SyncCompanyAdgroupMlbsJob($company, $this->periodFrom, $this->periodTo);
        $middlewares = $job->middleware();

        $this->assertNotEmpty($middlewares);
        $this->assertInstanceOf(RateLimited::class, $middlewares[0]);

        $ref = new \ReflectionObject($middlewares[0]);
        $prop = $ref->getProperty('limiterName');
        $prop->setAccessible(true);
        $this->assertSame('adman-api', $prop->getValue($middlewares[0]));
    }

    public function test_job_skip_quando_empresa_sem_adman_account_id(): void
    {
        $company = Company::create(['name' => 'Sem Adman', 'adman_account_id' => null, 'active' => true]);

        $mcp  = $this->createMock(AdmanMcpService::class);
        $mcp->expects($this->never())->method('fetchAllProductAds');

        $repo = $this->createMock(AdmanAdgroupMlbsRepository::class);
        $repo->expects($this->never())->method('upsertBatch');

        $job = new SyncCompanyAdgroupMlbsJob($company, $this->periodFrom, $this->periodTo);
        $job->handle($mcp, $repo);

        $this->assertSame(0, AdmanAdgroupMlb::count());
    }

    public function test_job_persiste_mlbs_em_tabela(): void
    {
        $company = Company::create(['name' => 'Teste 789', 'adman_account_id' => '789', 'active' => true]);

        $mcp = $this->createMock(AdmanMcpService::class);
        $mcp->expects($this->once())
            ->method('fetchAllProductAds')
            ->willReturn([
                'items' => [
                    [
                        'listingId'    => 'MLB100',
                        'adgroupId'    => 'AG-X',
                        'adgroupName'  => 'Adgroup X',
                        'campaignId'   => 'C-X',
                        'campaignName' => 'Campanha X',
                        'title'        => 'Item 1',
                        'status'       => 'ACTIVE',
                        'impressions'  => 1000,
                        'clicks'       => 50,
                    ],
                    [
                        'listingId'    => 'MLB101',
                        'adgroupId'    => 'AG-X',
                        'adgroupName'  => 'Adgroup X',
                        'campaignId'   => 'C-X',
                        'campaignName' => 'Campanha X',
                        'title'        => 'Item 2',
                        'status'       => 'PAUSED',
                        'impressions'  => 500,
                        'clicks'       => 10,
                    ],
                ],
                'rate_limited' => false,
                'pages_read'   => 1,
                'total_pages'  => 1,
                'truncated'    => false,
            ]);

        $repo = new AdmanAdgroupMlbsRepository();
        $job  = new SyncCompanyAdgroupMlbsJob($company, $this->periodFrom, $this->periodTo);
        $job->handle($mcp, $repo);

        $this->assertSame(2, AdmanAdgroupMlb::count());
        $first = AdmanAdgroupMlb::orderBy('mlb_id')->first();
        $this->assertSame('MLB100', $first->mlb_id);
        $this->assertSame('Adgroup X', $first->adgroup_name);
        $this->assertSame(1000, $first->metrics['impressions']);
    }

    // ─────────── Command: sugadores:sync-adgroup-mlbs ───────────

    public function test_command_dispatcha_jobs_pra_todas_empresas_com_adman_account_id(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        Company::create(['name' => 'Adman 1', 'adman_account_id' => '111', 'active' => true]);
        Company::create(['name' => 'Adman 2', 'adman_account_id' => '222', 'active' => true]);
        Company::create(['name' => 'Sem Adman', 'adman_account_id' => null, 'active' => true]);
        Company::create(['name' => 'Inativa', 'adman_account_id' => '333', 'active' => false]);

        $this->artisan('sugadores:sync-adgroup-mlbs --all')
            ->expectsOutputToContain('2 empresa(s) enfileirada(s)')
            ->assertExitCode(0);

        \Illuminate\Support\Facades\Queue::assertPushed(SyncCompanyAdgroupMlbsJob::class, 2);
    }

    public function test_command_aceita_option_company_especifica(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $alvo = Company::create(['name' => 'Alvo', 'adman_account_id' => '444', 'active' => true]);
        Company::create(['name' => 'Outra', 'adman_account_id' => '555', 'active' => true]);

        $this->artisan("sugadores:sync-adgroup-mlbs --company={$alvo->id}")
            ->assertExitCode(0);

        \Illuminate\Support\Facades\Queue::assertPushed(SyncCompanyAdgroupMlbsJob::class, 1);
    }

    public function test_command_sem_options_retorna_erro(): void
    {
        $this->artisan('sugadores:sync-adgroup-mlbs')
            ->expectsOutputToContain('Use --company=ID ou --all')
            ->assertExitCode(1);
    }

    // ─────────── Controller: refresh endpoint dispatcha Job ───────────

    public function test_controller_refresh_endpoint_dispatcha_sync_job(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $user = \App\Models\User::create([
            'name'     => 'Admin',
            'email'    => 'admin-' . uniqid() . '@teste.com',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);

        $company = Company::create(['name' => 'Refresh Co', 'adman_account_id' => '888', 'active' => true]);

        $res = $this->actingAs($user)->postJson(route('sugadores.refresh-adgroup-mlbs'), [
            'company_id' => $company->id,
        ]);

        $res->assertOk()
            ->assertJson([
                'status'     => 'enqueued',
                'company_id' => $company->id,
            ]);

        \Illuminate\Support\Facades\Queue::assertPushed(SyncCompanyAdgroupMlbsJob::class, 1);
    }

    public function test_controller_refresh_endpoint_rejeita_empresa_sem_adman(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $user = \App\Models\User::create([
            'name'     => 'Admin',
            'email'    => 'admin-' . uniqid() . '@teste.com',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);

        $company = Company::create(['name' => 'Sem Adman', 'adman_account_id' => null, 'active' => true]);

        $res = $this->actingAs($user)->postJson(route('sugadores.refresh-adgroup-mlbs'), [
            'company_id' => $company->id,
        ]);

        $res->assertStatus(422);
        \Illuminate\Support\Facades\Queue::assertNotPushed(SyncCompanyAdgroupMlbsJob::class);
    }

    public function test_job_aceita_rate_limited_e_persiste_parcial(): void
    {
        $company = Company::create(['name' => 'Teste 777', 'adman_account_id' => '777', 'active' => true]);

        $mcp = $this->createMock(AdmanMcpService::class);
        $mcp->expects($this->once())
            ->method('fetchAllProductAds')
            ->willReturn([
                'items' => [
                    [
                        'listingId'   => 'MLB200',
                        'adgroupId'   => 'AG-Y',
                        'adgroupName' => 'Adgroup Y',
                    ],
                ],
                'rate_limited' => true,  // Service estourou bucket no meio
                'pages_read'   => 50,
                'total_pages'  => 198,
                'truncated'    => true,
            ]);

        $repo = new AdmanAdgroupMlbsRepository();
        $job  = new SyncCompanyAdgroupMlbsJob($company, $this->periodFrom, $this->periodTo);

        // Não deve lançar exception — Job termina graciosamente
        $job->handle($mcp, $repo);

        $this->assertSame(1, AdmanAdgroupMlb::count(), 'Parcial deve ser persistido mesmo com rate_limited=true');
    }
}
