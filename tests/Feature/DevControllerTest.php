<?php

namespace Tests\Feature;

use App\Jobs\SyncAdmanCompanyJob;
use App\Models\AdmanMetric;
use App\Models\AdmanSyncLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DevControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cria usuário admin e empresa para reutilização nos testes.
     * Usa Company::create() pois não existe CompanyFactory no projeto.
     */
    private function criarAdminEEmpresa(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $company = Company::create([
            'name'              => 'Empresa Teste',
            'cnpj'              => '00000000000000',
            'adman_account_id'  => 'ACC123',
            'active'            => true,
        ]);

        return ['admin' => $admin, 'company' => $company];
    }

    // DEV-01: admin vê lista de empresas com synced_at

    public function test_index_retorna_empresas_com_synced_at(): void
    {
        ['admin' => $admin, 'company' => $company] = $this->criarAdminEEmpresa();

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => now()->toDateString(),
            'synced_at'      => now(),
            'raw_data'       => ['x' => 1],
        ]);

        $response = $this->actingAs($admin)->get('/dev/desenvolvimento');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dev/Desenvolvimento')
            ->has('empresas', 1)
            ->where('empresas.0.synced_at', fn ($v) => !is_null($v))
        );
    }

    // DEV-02: admin vê raw_data no payload

    public function test_index_retorna_raw_data_no_payload(): void
    {
        ['admin' => $admin, 'company' => $company] = $this->criarAdminEEmpresa();

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => now()->toDateString(),
            'synced_at'      => now(),
            'raw_data'       => ['grossBilling' => ['value' => 12345.67]],
        ]);

        $response = $this->actingAs($admin)->get('/dev/desenvolvimento');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dev/Desenvolvimento')
            ->where('empresas.0.raw_data.grossBilling.value', 12345.67)
        );
    }

    // DEV-03: admin vê diff do último log

    public function test_index_retorna_diff_do_ultimo_log(): void
    {
        ['admin' => $admin, 'company' => $company] = $this->criarAdminEEmpresa();

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => now()->toDateString(),
            'synced_at'      => now(),
        ]);

        AdmanSyncLog::create([
            'company_id'    => $company->id,
            'created_count' => 2,
            'updated_count' => 3,
            'skipped_count' => 1,
            'synced_at'     => now(),
        ]);

        $response = $this->actingAs($admin)->get('/dev/desenvolvimento');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dev/Desenvolvimento')
            ->where('empresas.0.criados', 2)
            ->where('empresas.0.atualizados', 3)
            ->where('empresas.0.ignorados', 1)
            ->where('empresas.0.status', 'ok')
        );
    }

    // DEV-04: dispatch enfileira job

    public function test_dispatch_sync_enfileira_job(): void
    {
        Queue::fake();

        ['admin' => $admin, 'company' => $company] = $this->criarAdminEEmpresa();

        $response = $this->actingAs($admin)
            ->post("/dev/adman/{$company->id}/sync");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Queue::assertPushed(SyncAdmanCompanyJob::class, fn ($job) => $job->company->id === $company->id);
    }

    // DEV-04 + Access Control: não-admin recebe 403

    public function test_dispatch_sync_rejeita_nao_admin(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);

        $company = Company::create([
            'name'             => 'Empresa Restrita',
            'cnpj'             => '11111111111111',
            'adman_account_id' => 'ACC456',
            'active'           => true,
        ]);

        $response = $this->actingAs($consultor)
            ->post("/dev/adman/{$company->id}/sync");

        $response->assertStatus(403);
    }
}
