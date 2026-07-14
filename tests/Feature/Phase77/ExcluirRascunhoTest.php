<?php

namespace Tests\Feature\Phase77;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exclusão de rascunho a partir do painel "Rascunhos recentes".
 *
 * @group phase77
 */
class ExcluirRascunhoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function companyComToken(): Company
    {
        $company = Company::factory()->create();
        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => 'U' . uniqid(),
            'access_token'  => 'x',
            'refresh_token' => 'y',
            'status'        => 'active',
            'expires_at'    => now()->addDays(30),
            'connected_at'  => now(),
        ]);
        return $company;
    }

    private function rascunho(Company $company, User $user): MlAnuncioRascunho
    {
        return MlAnuncioRascunho::create([
            'company_id' => $company->id,
            'user_id'    => $user->id,
            'payload'    => ['title' => 'Poltrona'],
            'status'     => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);
    }

    public function test_admin_exclui_rascunho(): void
    {
        $admin    = $this->admin();
        $company  = $this->companyComToken();
        $rascunho = $this->rascunho($company, $admin);

        $this->actingAs($admin)
            ->deleteJson(route('mlb.anuncios.rascunho.destroy', ['rascunho' => $rascunho->id]))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('ml_anuncio_rascunhos', ['id' => $rascunho->id]);
    }

    public function test_nao_admin_bloqueado_pelo_middleware(): void
    {
        $admin    = $this->admin();
        $company  = $this->companyComToken();
        $rascunho = $this->rascunho($company, $admin);
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)
            ->deleteJson(route('mlb.anuncios.rascunho.destroy', ['rascunho' => $rascunho->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('ml_anuncio_rascunhos', ['id' => $rascunho->id]);
    }
}
