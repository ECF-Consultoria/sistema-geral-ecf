<?php

namespace Tests\Feature\Phase131;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fase 131 Plano 06 (D-10) — prova que a rota antiga da Fase 130
 * (`contratos.liberacao-manual.*`) foi REMOVIDA de verdade: 404 exato,
 * nunca redirect. Uma rota órfã manteria um caminho paralelo fora do novo
 * controle de permissão (T-131-06-05).
 *
 * Nasce NA MESMA task da remoção (ver 131-06-PLAN.md `<aviso_de_ambiente>`)
 * — o teste nunca fica "esperando" código de task futura.
 */
class LiberacaoManualRotaAntigaRemovidaTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotas_antigas_nao_existem_mais_pelo_nome(): void
    {
        $this->assertFalse(Route::has('contratos.liberacao-manual.index'));
        $this->assertFalse(Route::has('contratos.liberacao-manual.store'));
    }

    public function test_get_no_caminho_antigo_devolve_404_exato_para_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/admin/contratos/liberacao-manual');

        $response->assertStatus(404);
    }

    public function test_post_no_caminho_antigo_devolve_404_exato_para_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post('/admin/contratos/liberacao-manual', []);

        $response->assertStatus(404);
    }

    public function test_visitante_anonimo_tambem_recebe_404_nunca_redirect_para_login(): void
    {
        // A rota nem existe mais: o middleware `auth` nunca chega a rodar,
        // então é 404 puro — nunca um redirect para o login que sugeriria
        // que a rota ainda existe atrás de autenticação.
        $response = $this->get('/admin/contratos/liberacao-manual');

        $response->assertStatus(404);
    }
}
