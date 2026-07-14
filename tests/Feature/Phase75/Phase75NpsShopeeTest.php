<?php

namespace Tests\Feature\Phase75;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Suite Feature — Phase 75 Plan 75-04 (DEC-5).
 *
 * Prova que o motor de NPS gera link para uma empresa atendida APENAS na Shopee
 * (sem adman_account_id/ml_store_id, sem nenhuma métrica) — reusando o endpoint
 * existente POST /nps/generate SEM qualquer alteração no motor. O template é
 * resolvido pelo fallback is_default ("NPS Padrão", seed migration 100004).
 *
 * @see app/Http/Controllers/NpsController.php (generate :353-412)
 * @see app/Services/Nps/NpsTemplateService.php (resolveForCompany, fallback is_default)
 */
class Phase75NpsShopeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // FKs SQLite ativas — padrão das suites NPS (Phase 68/69).
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Empresa Shopee (sem métrica) com estrategista atribuído gera NPS via
     * POST /nps/generate — cria NpsSurvey com o template is_default (fallback),
     * sem exigir métrica nem integração ML.
     */
    public function test_empresa_shopee_sem_metrica_gera_nps_com_template_padrao(): void
    {
        // Empresa SEM dados ML (adman_account_id/ml_store_id nulos)
        $empresa = Company::create([
            'name'   => 'Empresa Shopee NPS ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
            'status' => 'ativo',
        ]);

        // Estrategista atribuído no pivot company_users — auth por carteira.
        $estrategista = User::create([
            'name'     => 'Estrategista Shopee ' . uniqid(),
            'email'    => 'estr.shopee.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);
        $empresa->users()->attach($estrategista->id, [
            'role'        => 'estrategista',
            'assigned_at' => now()->toDateString(),
        ]);

        $padrao = NpsTemplate::where('is_default', true)->first();
        $this->assertNotNull($padrao, 'seed NPS Padrão (is_default=true) deve existir');

        $response = $this->actingAs($estrategista)->post(route('nps.generate'), [
            'company_id' => $empresa->id,
        ]);

        $response->assertStatus(302); // back() redirect
        $this->assertDatabaseCount('nps_surveys', 1);

        $survey = NpsSurvey::first();
        $this->assertSame($empresa->id, $survey->company_id);
        $this->assertSame($estrategista->id, $survey->generated_by);
        $this->assertSame('pending', $survey->status);
        $this->assertSame($padrao->id, $survey->template_id,
            'empresa Shopee sem contratos deve receber o template is_default (fallback)');
    }

    /**
     * Admin também gera NPS para empresa Shopee (short-circuit isAdmin).
     */
    public function test_admin_gera_nps_para_empresa_shopee(): void
    {
        $admin = User::create([
            'name'     => 'Admin NPS Shopee ' . uniqid(),
            'email'    => 'admin.nps.shopee.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);

        $empresa = Company::create([
            'name'   => 'Empresa Shopee NPS Admin ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
            'status' => 'ativo',
        ]);

        $response = $this->actingAs($admin)->post(route('nps.generate'), [
            'company_id' => $empresa->id,
        ]);

        $response->assertStatus(302);
        $this->assertDatabaseCount('nps_surveys', 1);
        $this->assertSame($empresa->id, NpsSurvey::first()->company_id);
    }
}
