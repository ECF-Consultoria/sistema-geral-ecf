<?php

namespace Tests\Feature\Phase156;

use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fechamento da v23.0 — as telas das Fases 153 e 154 ganharam item de menu.
 *
 * Sem isso elas existiam só por URL direta: o operador não tinha como chegar
 * nelas, e a fase estaria "pronta" sem ser alcançável.
 *
 * O gating do menu é do FRONT (`AppLayout.jsx` filtra por `permission`), então o
 * que se prova aqui é o insumo dele: as chaves que o backend compartilha em
 * `auth.permissions`. Se a chave não chega, o item nunca acende.
 */
class MenuItensNovosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    private function userComPermissoes(array $chaves): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Menu '.uniqid(),
            'slug'   => 'menu-'.uniqid(),
            'active' => true,
        ]);

        foreach ($chaves as $k) {
            SetorPermissao::create(['setor_id' => $setor->id, 'permission_key' => $k]);
        }

        $u = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $setor->membros()->attach($u->id, ['is_principal' => true, 'assigned_at' => now()]);

        return $u;
    }

    /** As chaves que o front recebe para decidir o menu. */
    private function permissoesCompartilhadas(User $user): array
    {
        $props = $this->actingAs($user)->get(route('dashboard'))->viewData('page')['props'];

        return $props['auth']['permissions'] ?? [];
    }

    // ─── Fase 153 — Boas-vindas, permissão em OR ────────────────────────────

    /**
     * O item usa `permission: ['admin.contratos', 'comercial.entrada']` — array
     * = OR. Usar só uma das chaves esconderia a tela de metade de quem pode
     * abri-la, porque a rota é servida por `permission:a,b`.
     */
    public function test_boas_vindas_alcanca_os_dois_perfis_que_a_rota_admite(): void
    {
        foreach ([Permissions::ADMIN_CONTRATOS, Permissions::COMERCIAL_ENTRADA] as $chave) {
            $user = $this->userComPermissoes([$chave]);

            $this->assertContains(
                $chave,
                $this->permissoesCompartilhadas($user),
                "a chave {$chave} precisa chegar ao front, senão o item de menu nunca acende."
            );

            // E a rota realmente abre para esse perfil.
            $this->actingAs($user)->get(route('admin.boas-vindas.index'))->assertOk();
        }
    }

    // ─── Fase 154 — Distribuição, chave própria ─────────────────────────────

    public function test_distribuicao_exige_a_chave_propria_da_coordenacao(): void
    {
        $coord = $this->userComPermissoes([Permissions::COORDENACAO_DISTRIBUIR]);

        $this->assertContains(Permissions::COORDENACAO_DISTRIBUIR, $this->permissoesCompartilhadas($coord));
        $this->actingAs($coord)->get(route('coordenacao.distribuicao.index'))->assertOk();
    }

    /**
     * Quem opera a Entrada NÃO herda a distribuição — é a separação que o §10 do
     * PDF estabelece, e o motivo de a chave ser própria (D-G da Fase 154).
     */
    public function test_perfil_de_entrada_nao_alcanca_a_distribuicao(): void
    {
        $entrada = $this->userComPermissoes([Permissions::COMERCIAL_ENTRADA]);

        $this->assertNotContains(Permissions::COORDENACAO_DISTRIBUIR, $this->permissoesCompartilhadas($entrada));
        $this->actingAs($entrada)->get(route('coordenacao.distribuicao.index'))->assertStatus(403);
    }

    public function test_usuario_sem_nenhuma_das_chaves_nao_recebe_nenhuma_delas(): void
    {
        $user = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $permissoes = $this->permissoesCompartilhadas($user);

        foreach ([
            Permissions::ADMIN_CONTRATOS,
            Permissions::COMERCIAL_ENTRADA,
            Permissions::COORDENACAO_DISTRIBUIR,
        ] as $chave) {
            $this->assertNotContains($chave, $permissoes);
        }
    }
}
