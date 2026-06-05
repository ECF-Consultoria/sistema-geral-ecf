<?php

// Phase 21 — Testes Feature do Manual do Sistema.
// Cobre: acesso autenticado, rejeição guest, lookup de slug (válido + inválido).

namespace Tests\Feature\Phase21;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ManualControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_retorna_200_para_usuario_autenticado(): void
    {
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get('/manual');

        $resp->assertOk();
        $resp->assertInertia(fn (AssertableInertia $page) => $page->component('Manual/Index'));
    }

    public function test_index_redireciona_para_login_quando_guest(): void
    {
        $resp = $this->get('/manual');

        $resp->assertRedirect(route('login'));
    }

    public function test_show_retorna_200_para_slug_valido_cronograma(): void
    {
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get('/manual/cronograma');

        $resp->assertOk();
        $resp->assertInertia(fn (AssertableInertia $page) =>
            $page->component('Manual/Show')->where('slug', 'cronograma')
        );
    }

    public function test_show_retorna_200_para_slug_inexistente_frontend_lida(): void
    {
        // D-02 do PLAN: o backend NÃO valida slug — frontend (Show.jsx) mostra
        // mensagem amigável quando buscarArtigo() retorna null. Isso preserva
        // a propriedade "adicionar novo artigo = só JSX" (controller nunca muda).
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get('/manual/slug-que-nao-existe');

        $resp->assertOk();
        $resp->assertInertia(fn (AssertableInertia $page) =>
            $page->component('Manual/Show')->where('slug', 'slug-que-nao-existe')
        );
    }
}
