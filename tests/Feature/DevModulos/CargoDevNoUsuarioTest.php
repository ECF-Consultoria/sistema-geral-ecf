<?php

namespace Tests\Feature\DevModulos;

use App\Models\Cargo;
use App\Models\Setor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cargo Dev concedido no cadastro do usuário (quick 260727-mx3).
 *
 * O cargo Dev deixou de ser uma lista dentro de /dev/modulos e passou a ser um
 * cargo de verdade: um vínculo em `user_setores` (setor 'desenvolvimento' +
 * cargo 'dev'), com `users.is_dev` como espelho — é a coluna que
 * `isAdminDev()` lê no menu. O que estes testes travam é justamente que os
 * dois NÃO se separem, em nenhum caminho de escrita.
 */
class CargoDevNoUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Payload mínimo aceito por users.update. */
    private function payload(User $u, array $extra = []): array
    {
        return array_merge([
            'name'     => $u->name,
            'email'    => $u->email,
            'is_admin' => $u->role === 'admin',
            'active'   => true,
            'vinculos' => [],
        ], $extra);
    }

    private function vinculoDev(User $u): ?object
    {
        $setorId = Setor::where('slug', User::SETOR_DEV_SLUG)->value('id');

        return DB::table('user_setores')
            ->where('user_id', $u->id)
            ->where('setor_id', $setorId)
            ->first();
    }

    public function test_marcar_dev_cria_o_vinculo_do_cargo_e_o_espelho(): void
    {
        $alvo = $this->admin();

        $this->actingAs($this->admin())
            ->put("/users/{$alvo->id}", $this->payload($alvo, ['is_dev' => true]))
            ->assertRedirect();

        $cargoDevId = Cargo::where('slug', User::CARGO_DEV_SLUG)->value('id');
        $vinculo = $this->vinculoDev($alvo);

        $this->assertNotNull($vinculo, 'O cargo Dev precisa virar vínculo em user_setores.');
        $this->assertSame($cargoDevId, (int) $vinculo->cargo_id);
        $this->assertTrue($alvo->fresh()->isAdminDev(), 'Espelho users.is_dev deve acompanhar o vínculo.');
        $this->assertTrue($alvo->fresh()->temCargoDev());
    }

    public function test_desmarcar_dev_remove_o_vinculo_e_o_espelho(): void
    {
        $alvo = $this->admin();
        $ator = $this->admin();

        $this->actingAs($ator)->put("/users/{$alvo->id}", $this->payload($alvo, ['is_dev' => true]));
        $this->assertTrue($alvo->fresh()->isAdminDev());

        $this->actingAs($ator)
            ->put("/users/{$alvo->id}", $this->payload($alvo, ['is_dev' => false]))
            ->assertRedirect();

        $this->assertNull($this->vinculoDev($alvo));
        $this->assertFalse($alvo->fresh()->isAdminDev());
        $this->assertFalse($alvo->fresh()->temCargoDev());
    }

    public function test_nao_pode_remover_o_proprio_cargo_dev(): void
    {
        $dev = $this->admin();
        $this->actingAs($this->admin())->put("/users/{$dev->id}", $this->payload($dev, ['is_dev' => true]));

        $this->actingAs($dev->fresh())
            ->put("/users/{$dev->id}", $this->payload($dev, ['is_dev' => false]))
            ->assertSessionHas('error');

        $this->assertTrue($dev->fresh()->isAdminDev(), 'Anti-lockout: ninguém se auto-rebaixa de Dev.');
        $this->assertNotNull($this->vinculoDev($dev));
    }

    /**
     * O vínculo do Dev não trafega no array `vinculos` (o setor fica fora do
     * dropdown). Sem blindagem, a etapa "remove os que sumiram" do syncVinculos
     * derrubaria o cargo Dev a cada salvamento comum do cadastro.
     */
    public function test_salvar_o_cadastro_sem_mexer_no_dev_preserva_o_vinculo(): void
    {
        $alvo = $this->admin();
        $ator = $this->admin();

        $this->actingAs($ator)->put("/users/{$alvo->id}", $this->payload($alvo, ['is_dev' => true]));

        $this->actingAs($ator)
            ->put("/users/{$alvo->id}", $this->payload($alvo, ['is_dev' => true, 'name' => 'Outro Nome']))
            ->assertRedirect();

        $this->assertNotNull($this->vinculoDev($alvo), 'Salvar o cadastro não pode derrubar o cargo Dev.');
        $this->assertTrue($alvo->fresh()->isAdminDev());
    }

    /**
     * Um segundo caminho de escrita (escolher Desenvolvimento no dropdown)
     * gravaria o vínculo sem o espelho — e o Dev não veria os módulos ocultos.
     */
    public function test_setor_desenvolvimento_fica_fora_do_catalogo_de_setores(): void
    {
        $resposta = $this->actingAs($this->admin())->get('/users');

        $resposta->assertOk();
        $slugs = collect($resposta->viewData('page')['props']['setoresDisponiveis'])->pluck('slug');

        $this->assertNotContains(User::SETOR_DEV_SLUG, $slugs);
    }

    /** O cargo Dev não vira setor principal — o principal segue sendo o de negócio. */
    public function test_vinculo_do_dev_nunca_e_principal(): void
    {
        $alvo = $this->admin();

        $this->actingAs($this->admin())->put("/users/{$alvo->id}", $this->payload($alvo, ['is_dev' => true]));

        $this->assertFalse((bool) $this->vinculoDev($alvo)->is_principal);
    }
}
