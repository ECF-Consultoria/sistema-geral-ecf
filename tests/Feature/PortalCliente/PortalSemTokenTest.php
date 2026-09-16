<?php

namespace Tests\Feature\PortalCliente;

use App\Http\Middleware\AposentaTokenDoPortal;
use App\Models\Company;
use App\Models\OnboardingLink;
use App\Models\PortalUsuario;
use App\Services\Portal\AcessosDoPortalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PortalSemTokenTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(): Company
    {
        return Company::create([
            'name' => 'Empresa de teste', 'cnpj' => '12345678000190',
            'active' => true, 'status' => 'ativo', 'empresa_nova' => true,
            'nome_contato' => 'Contato de teste', 'email_cliente' => '  CLIENTE@EXAMPLE.TEST  ',
            'telefone' => '11999999999', 'cargo_contato' => 'Diretor',
        ]);
    }

    public function test_todas_as_rotas_legadas_recusam_tokens_reais_e_inventados_em_ambos_os_hosts(): void
    {
        config(['portal.dominio_cliente' => 'cliente.example.test']);
        $empresa = $this->empresa();
        $link = OnboardingLink::create(['company_id' => $empresa->id, 'token' => str_repeat('a', 48)]);
        $rotas = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) =>
            str_starts_with($r->uri(), 'portal-cliente/') || str_starts_with($r->uri(), 'onboarding-cliente/'));
        $this->assertGreaterThanOrEqual(12, $rotas->count());
        foreach (['admin.example.test', 'cliente.example.test'] as $host) {
            foreach ([$link->token, str_repeat('b', 48)] as $token) {
                foreach ($rotas as $rota) {
                    $this->assertContains(AposentaTokenDoPortal::class, $rota->gatherMiddleware());
                    $url = 'http://'.$host.'/'.str_replace(['{token}', '{task}'], [$token, '999999'], $rota->uri());
                    $metodo = $rota->methods()[0];
                    $resposta = $this->call($metodo, $url, ['company_id' => $empresa->id]);
                    if ($metodo === 'GET') {
                        $resposta->assertRedirect('http://cliente.example.test/entrar')
                            ->assertHeader('Referrer-Policy', 'no-referrer');
                    } else {
                        $resposta->assertStatus(410);
                    }
                    $resposta->assertDontSee('Empresa de teste');
                    $this->assertStringContainsString('no-store', $resposta->headers->get('Cache-Control'));
                }
            }
        }
        $this->assertNull($link->fresh()->ultimo_acesso);
        $this->assertDatabaseCount('onboarding_links', 1);
        $this->assertDatabaseCount('portal_usuarios', 0);
    }

    public function test_token_de_outra_empresa_nao_troca_contexto_de_cliente_logado(): void
    {
        $empresa = $this->empresa();
        $usuario = PortalUsuario::create(['nome' => 'Cliente', 'email' => 'cliente@example.test', 'ativo' => true]);
        $usuario->empresas()->attach($empresa->id, ['principal' => true]);
        $this->actingAs($usuario, 'portal')->withSession(['portal_empresa_id' => $empresa->id])
            ->get('/portal-cliente/token-de-outra-empresa')->assertRedirect(route('portal.entrada'));
        $this->assertSame($empresa->id, session('portal_empresa_id'));
    }

    public function test_contato_e_sugerido_antes_do_onboarding_sem_liberar_acesso(): void
    {
        $empresa = $this->empresa();
        $dados = app(AcessosDoPortalService::class)->dados();
        $contato = $dados['empresas']->firstWhere('id', $empresa->id)['contato'];
        $this->assertSame(['nome' => 'Contato de teste', 'email' => 'cliente@example.test', 'telefone' => '11999999999', 'cargo' => 'Diretor'], $contato);
        $this->assertSame(route('portal.entrada'), $dados['login_url']);
        $this->assertDatabaseCount('portal_usuarios', 0);
        $this->assertDatabaseCount('onboarding_links', 0);
        $this->assertDatabaseCount('onboardings', 0);
        $empresa->update(['email_cliente' => 'um@example.test; dois@example.test']);
        $this->assertSame('', app(AcessosDoPortalService::class)->dados()['empresas']->first()['contato']['email']);
    }

    public function test_link_avulso_de_ppa_da_empresa_tambem_exige_login(): void
    {
        $empresa = $this->empresa();
        $mentor = \App\Models\User::factory()->create();
        $ppa = \App\Models\Ppa::create([
            'company_id' => $empresa->id, 'mentor_id' => $mentor->id,
            'title' => 'Plano privado de teste', 'status' => 'sent', 'workspace_token' => 'token-quadro-legado',
        ]);
        $tarefa = \App\Models\PpaTask::create(['ppa_id' => $ppa->id, 'title' => 'Tarefa privada', 'status' => 'todo', 'order' => 1]);
        $this->get(route('ppa.workspace', $ppa->workspace_token))
            ->assertRedirect(route('portal.entrada'))->assertDontSee('Plano privado');
        $this->patch(route('ppa.workspace.task.update', [$ppa->workspace_token, $tarefa->id]), ['status' => 'done'])->assertStatus(410);
        $this->assertSame('todo', $tarefa->fresh()->status);
    }
}
