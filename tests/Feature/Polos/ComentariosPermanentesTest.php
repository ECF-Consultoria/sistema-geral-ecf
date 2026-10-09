<?php

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\PolosComentario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TKT-0007 — o comentário da empresa em /polos/empresas passa a ser PERMANENTE.
 *
 * Antes a tela só listava os comentários do mês selecionado (`where mes = ?`), e o time
 * reescrevia todo mês a mesma anotação. Agora a empresa mostra os comentários de todos
 * os meses, cada um com o mês em que foi escrito. Sem mudança de schema: `mes` segue
 * gravado e vira só a referência.
 *
 * @group polos
 */
class ComentariosPermanentesTest extends TestCase
{
    use RefreshDatabase;

    private function comentario(string $cust, string $mes, string $texto, User $autor, string $em): PolosComentario
    {
        $c = PolosComentario::create([
            'cust_id'    => $cust,
            'mes'        => $mes,
            'user_id'    => $autor->id,
            'autor_nome' => $autor->name,
            'texto'      => $texto,
        ]);
        // created_at controlado: a ordem da lista depende dele.
        $c->forceFill(['created_at' => Carbon::parse($em)])->save();

        return $c;
    }

    /** Chama o montador privado com o mesmo argumento que todasEmpresas() passa. */
    private function comentarios(array $custIds, ?User $user): array
    {
        $m = new ReflectionMethod(PolosController::class, 'comentariosDasEmpresas');
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), $custIds, $user);
    }

    public function test_comentario_de_um_mes_aparece_em_todos_os_meses_com_a_referencia(): void
    {
        $autor = User::factory()->create(['role' => 'admin']);
        $this->comentario('3308946595', '202609', 'Foi pra consultoria!', $autor, '2026-09-17 11:55');
        $this->comentario('3308946595', '202610', 'Foi para outra consultoria.', $autor, '2026-10-05 09:27');
        $this->comentario('999', '202609', 'Outra empresa', $autor, '2026-09-10 10:00');

        $lista = $this->comentarios(['3308946595', '111'], $autor);

        // Só as empresas da tela; a de fora da lista não viaja.
        // (array_map strval: o PHP converte chave numérica em int, o JSON volta a string.)
        $this->assertSame(['3308946595'], array_map('strval', array_keys($lista)));

        // Os dois meses juntos, o mais recente primeiro, cada um com o seu mês.
        $doCust = $lista['3308946595'];
        $this->assertCount(2, $doCust);
        $this->assertSame('Foi para outra consultoria.', $doCust[0]['texto']);
        $this->assertSame('202610', $doCust[0]['mes']);
        $this->assertSame('Outubro/2026', $doCust[0]['mes_label']);
        $this->assertSame('Foi pra consultoria!', $doCust[1]['texto']);
        $this->assertSame('Setembro/2026', $doCust[1]['mes_label']);
        $this->assertTrue($doCust[0]['pode_editar']);
    }

    public function test_pode_editar_continua_so_do_autor_ou_admin(): void
    {
        $autor  = User::factory()->create(['role' => 'consultor']);
        $outro  = User::factory()->create(['role' => 'consultor']);
        $this->comentario('123', '202608', 'Sem bônus', $autor, '2026-08-20 10:00');

        $this->assertTrue($this->comentarios(['123'], $autor)['123'][0]['pode_editar']);
        $this->assertFalse($this->comentarios(['123'], $outro)['123'][0]['pode_editar']);
        $this->assertFalse($this->comentarios(['123'], null)['123'][0]['pode_editar']);
    }

    public function test_lista_vazia_nao_consulta_nada(): void
    {
        $autor = User::factory()->create(['role' => 'admin']);
        $this->comentario('123', '202609', 'Qualquer', $autor, '2026-09-20 10:00');

        $this->assertSame([], $this->comentarios([], $autor));
        $this->assertSame([], $this->comentarios([null, ''], $autor));
    }

    public function test_comentario_gravado_num_mes_volta_ao_abrir_outro_mes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Grava pela rota da tela, em setembro, com o cust cru (a rota normaliza).
        $this->actingAs($admin)
            ->post(route('polos.comentarios.store'), ['cust_id' => '3333141045', 'mes' => '202609', 'texto' => '  Seller cuidando das campanhas!  '])
            ->assertRedirect()
            ->assertSessionHas('success');

        $gravado = PolosComentario::sole();
        $this->assertSame('202609', $gravado->mes);
        $this->assertSame('Seller cuidando das campanhas!', $gravado->texto);

        // A montagem não depende do mês selecionado: o comentário de setembro está lá.
        $lista = $this->comentarios([$gravado->cust_id], $admin);
        $this->assertSame('Seller cuidando das campanhas!', $lista[$gravado->cust_id][0]['texto']);
        $this->assertSame('Setembro/2026', $lista[$gravado->cust_id][0]['mes_label']);
    }
}
