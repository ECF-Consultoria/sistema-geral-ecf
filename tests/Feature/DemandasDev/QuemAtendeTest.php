<?php

namespace Tests\Feature\DemandasDev;

use App\Models\Chamado;
use App\Models\User;
use App\Notifications\ChamadoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Nem todo Dev atende: `demandas_dev.atendimento_ids` recorta "Quem atende",
 * o aviso da fila, a transferência e o responsável da demanda — sem mexer no
 * cargo Dev de quem ficou fora.
 */
class QuemAtendeTest extends TestCase
{
    use LiberaModulosDev;
    use RefreshDatabase;

    private User $maycon;
    private User $barreto;
    private User $thalissa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->liberarModulosDev();

        $this->maycon   = $this->dev('Maycon Gomes');
        $this->barreto  = $this->dev('Matheus Barreto');
        $this->thalissa = $this->dev('Thalissa');
        config(['demandas_dev.atendimento_ids' => [$this->maycon->id, $this->barreto->id]]);
    }

    private function dev(string $nome): User
    {
        $u = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);
        $u->forceFill(['is_dev' => true])->save();

        return $u->refresh();
    }

    public function test_quem_atende_mostra_so_os_configurados(): void
    {
        $karen = User::factory()->create(['name' => 'Karen Souza', 'role' => 'consultor', 'active' => true]);

        $this->actingAs($karen)->get('/tickets')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('devs', 2)
            ->where('devs.0.name', 'Matheus Barreto')
            ->where('devs.1.name', 'Maycon Gomes'));
    }

    public function test_fila_avisa_so_quem_atende_e_nao_transfere_para_quem_ficou_fora(): void
    {
        Notification::fake();
        $karen = User::factory()->create(['name' => 'Karen Souza', 'role' => 'consultor', 'active' => true]);

        $this->actingAs($karen)->post('/tickets', [
            'tipo' => 'problema', 'area' => 'Entrada', 'titulo' => 'Erro',
            'descricao' => 'Erro 500.', 'impacto' => 'impedido',
        ])->assertRedirect();
        $c = Chamado::sole();

        Notification::assertSentTo([$this->maycon, $this->barreto], ChamadoNotification::class);
        Notification::assertNotSentTo($this->thalissa, ChamadoNotification::class);

        // Abrir direto para quem não atende também é recusado.
        $this->actingAs($karen)->post('/tickets', [
            'tipo' => 'problema', 'area' => 'Entrada', 'titulo' => 'Outro',
            'descricao' => 'x', 'impacto' => 'impedido', 'responsavel_id' => $this->thalissa->id,
        ]);
        $this->assertSame(1, Chamado::count());

        // Thalissa segue Dev: continua vendo a fila, só não entra nas listas.
        $this->assertTrue(Chamado::ehEquipe($this->thalissa));
        $this->actingAs($this->maycon)->post("/dev/demandas/chamados/{$c->id}/transferir", ['responsavel_id' => $this->thalissa->id, 'motivo' => 'teste']);
        $this->assertNotSame($this->thalissa->id, $c->fresh()->responsavel_id);
    }

    public function test_responsavel_da_demanda_lista_so_quem_atende_e_reuniao_segue_com_todos(): void
    {
        $admin = User::factory()->create(['name' => 'Admin', 'role' => 'admin', 'active' => true]);

        $this->actingAs($admin)->get('/dev/demandas')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('responsaveis', 2)
            ->where('responsaveis.0.name', 'Matheus Barreto')
            ->where('responsaveis.1.name', 'Maycon Gomes')
            ->has('usuarios', 4));
    }
}
