<?php

namespace Tests\Feature;

use App\Jobs\MlbColetaJob;
use App\Models\MlbColeta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Testes de integração da feature de Coleta de Dados ML (Phase 17).
 *
 * Cobre D-06 (store cria pendente + dispatch; status JSON) e D-07 (403 sem permissão).
 *
 * @group phase17
 */
class Phase17ColetaTest extends TestCase
{
    use RefreshDatabase;

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_store_cria_coleta_pendente(): void
    {
        // D-06: store valida, cria status=pendente e despacha o Job
        Queue::fake();
        $admin = $this->criarAdmin();

        $response = $this->actingAs($admin)->post('/mlb/coleta', ['keyword' => 'fone bluetooth']);

        $response->assertRedirect();
        $this->assertDatabaseHas('mlb_coletas', [
            'keyword' => 'fone bluetooth',
            'status'  => 'pendente',
        ]);
        Queue::assertPushed(MlbColetaJob::class);
    }

    public function test_status_endpoint_json(): void
    {
        // D-06: endpoint de status retorna JSON com a chave status (para o polling)
        $admin  = $this->criarAdmin();
        $coleta = MlbColeta::create([
            'user_id' => $admin->id,
            'keyword' => 'teste',
            'status'  => 'rodando',
        ]);

        $response = $this->actingAs($admin)->getJson("/mlb/coleta/{$coleta->id}/status");

        $response->assertOk()->assertJsonFragment(['status' => 'rodando']);
    }

    public function test_acesso_403_sem_pub_role(): void
    {
        // D-07: usuário sem acesso ao módulo MLB recebe 403
        $user = User::factory()->create(['role' => 'consultor']);

        $response = $this->actingAs($user)->get('/mlb/coleta');

        $response->assertForbidden();
    }
}
