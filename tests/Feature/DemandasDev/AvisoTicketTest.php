<?php

namespace Tests\Feature\DemandasDev;

use App\Models\Chamado;
use App\Models\Module;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cartão de aviso no canto da tela (/api/notificacoes/tickets): só quem ABRIU
 * o ticket vê, cada um só os seus, e ele some ao abrir o ticket ou ao fechar.
 */
class AvisoTicketTest extends TestCase
{
    use LiberaModulosDev;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->liberarModulosDev();
        Carbon::setTestNow('2026-09-29 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dev(string $nome): User
    {
        $u = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);
        $u->forceFill(['is_dev' => true])->save();

        return $u->refresh();
    }

    private function colaborador(string $nome): User
    {
        return User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);
    }

    private function abrir(User $quem, User $dev, string $titulo = 'Erro ao cadastrar cliente'): Chamado
    {
        $this->actingAs($quem)->post('/tickets', [
            'tipo' => 'problema', 'area' => 'Entrada', 'titulo' => $titulo,
            'descricao' => 'Clico em salvar e aparece erro 500.', 'impacto' => 'impedido',
            'responsavel_id' => $dev->id,
        ])->assertRedirect();

        return Chamado::query()->latest('id')->firstOrFail();
    }

    private function responder(User $dev, Chamado $c, string $texto = 'Consegue mandar um print?', bool $interna = false): void
    {
        $this->actingAs($dev)->post("/tickets/{$c->id}/mensagens", ['texto' => $texto, 'interna' => $interna]);
    }

    public function test_resposta_da_equipe_aparece_so_para_quem_abriu(): void
    {
        $maycon = $this->dev('Maycon Gomes');
        $karen  = $this->colaborador('Karen Souza');
        $joao   = $this->colaborador('João Lima');
        $c      = $this->abrir($karen, $maycon);

        $this->responder($maycon, $c);

        $this->actingAs($karen)->getJson('/api/notificacoes/tickets')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('avisos.0.url', "/tickets/{$c->id}")
            ->assertJsonPath('avisos.0.autor_nome', 'Maycon Gomes')
            ->assertJsonPath('avisos.0.mensagem', 'Erro ao cadastrar cliente');

        // Outra pessoa não vê o aviso da Karen.
        $this->actingAs($joao)->getJson('/api/notificacoes/tickets')->assertOk()->assertJsonPath('total', 0);
        // O dev foi avisado quando o ticket foi aberto, mas esse aviso é da equipe (sino), não do cartão.
        $this->assertSame(1, $maycon->unreadNotifications()->count());
        $this->actingAs($maycon)->getJson('/api/notificacoes/tickets')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_nota_interna_nao_gera_aviso_para_quem_abriu(): void
    {
        $maycon = $this->dev('Maycon Gomes');
        $karen  = $this->colaborador('Karen Souza');
        $c      = $this->abrir($karen, $maycon);

        $this->responder($maycon, $c, 'SEGREDO', interna: true);

        $this->actingAs($karen)->getJson('/api/notificacoes/tickets')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_abrir_o_ticket_tira_o_aviso_so_daquele_ticket(): void
    {
        $maycon = $this->dev('Maycon Gomes');
        $karen  = $this->colaborador('Karen Souza');
        $c1     = $this->abrir($karen, $maycon, 'Primeiro');
        $c2     = $this->abrir($karen, $maycon, 'Segundo');

        $this->responder($maycon, $c1);
        $this->responder($maycon, $c2);
        $this->actingAs($karen)->getJson('/api/notificacoes/tickets')->assertJsonPath('total', 2);

        $this->actingAs($karen)->get("/tickets/{$c1->id}")->assertOk();

        $this->actingAs($karen)->getJson('/api/notificacoes/tickets')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('avisos.0.url', "/tickets/{$c2->id}");
    }

    public function test_fechar_o_aviso_marca_como_lido_sem_redirecionar(): void
    {
        $maycon = $this->dev('Maycon Gomes');
        $karen  = $this->colaborador('Karen Souza');
        $joao   = $this->colaborador('João Lima');
        $c      = $this->abrir($karen, $maycon);
        $this->responder($maycon, $c);

        $id = $this->actingAs($karen)->getJson('/api/notificacoes/tickets')->json('avisos.0.id');

        // Ninguém fecha o aviso de outra pessoa.
        $this->actingAs($joao)->patchJson("/notificacoes/{$id}/marcar-lida")->assertForbidden();

        $this->actingAs($karen)->patchJson("/notificacoes/{$id}/marcar-lida")->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($karen)->getJson('/api/notificacoes/tickets')->assertJsonPath('total', 0);
    }

    public function test_quem_nao_ve_o_modulo_recebe_lista_vazia_e_nao_404(): void
    {
        $karen = $this->colaborador('Karen Souza');
        Module::query()->where('key', 'chamados')->update(['visivel_para_todos' => false]);

        $this->actingAs($karen)->getJson('/api/notificacoes/tickets')->assertOk()->assertJsonPath('total', 0);
    }
}
