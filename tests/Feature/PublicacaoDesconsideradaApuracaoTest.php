<?php

namespace Tests\Feature;

use App\Models\Publicacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Anúncio publicado fora da metodologia para de contar em vendas, meta,
 * conversão e score — mas continua existindo.
 *
 * O que estes testes protegem: antes, a única forma de tirar um anúncio errado
 * da apuração era apagá-lo, o que destruía a evidência de que ele foi
 * publicado. O flag separa as duas coisas, e é fácil alguém reintroduzir a
 * contagem esquecendo o scope `considerado()` numa query nova.
 *
 * @group revisao
 */
class PublicacaoDesconsideradaApuracaoTest extends TestCase
{
    use RefreshDatabase;

    private function publicacao(User $dono, array $attrs = []): Publicacao
    {
        static $seq = 0;
        $seq++;

        return Publicacao::create(array_merge([
            'data'     => now()->startOfMonth()->toDateString(),
            'user_id'  => $dono->id,
            'empresa'  => 'Loja Teste',
            'mlb_code' => 'MLB' . str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
            'tipo'     => 'anuncio',
            'vendido'  => false,
        ], $attrs));
    }

    public function test_scope_considerado_exclui_o_que_esta_fora_da_metodologia(): void
    {
        $dono = User::factory()->create();

        $this->publicacao($dono);
        $this->publicacao($dono);
        $fora = $this->publicacao($dono, ['desconsiderado' => true]);

        $this->assertSame(3, Publicacao::count(), 'o registro não pode sumir do banco');
        $this->assertSame(2, Publicacao::considerado()->count());
        $this->assertFalse(Publicacao::considerado()->where('id', $fora->id)->exists());
    }

    public function test_endpoint_marca_e_desmarca_registrando_autoria(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pub   = $this->publicacao($admin);

        $this->actingAs($admin)
            ->patch(route('mlb.revisao.desconsiderar', $pub->id), ['desconsiderado' => true])
            ->assertRedirect();

        $pub->refresh();
        $this->assertTrue($pub->desconsiderado);
        $this->assertSame($admin->id, $pub->desconsiderado_por);
        $this->assertNotNull($pub->desconsiderado_em);

        $this->actingAs($admin)
            ->patch(route('mlb.revisao.desconsiderar', $pub->id), ['desconsiderado' => false])
            ->assertRedirect();

        $pub->refresh();
        $this->assertFalse($pub->desconsiderado);
        // A autoria some junto: manter o nome de quem marcou num anúncio que
        // voltou a contar faria a tela exibir um selo sem fato por trás.
        $this->assertNull($pub->desconsiderado_por);
        $this->assertNull($pub->desconsiderado_em);
    }

    public function test_publicador_comum_nao_pode_desconsiderar(): void
    {
        $publicador = User::factory()->create(['role' => 'consultor']);
        $pub        = $this->publicacao($publicador);

        $this->actingAs($publicador)
            ->patch(route('mlb.revisao.desconsiderar', $pub->id), ['desconsiderado' => true])
            ->assertForbidden();

        $this->assertFalse($pub->refresh()->desconsiderado);
    }

    public function test_desconsiderado_sai_dos_kpis_da_fila_de_revisao(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->publicacao($admin);
        $this->publicacao($admin);
        $this->publicacao($admin, ['desconsiderado' => true]);

        $resposta = $this->actingAs($admin)->get(route('mlb.revisao', [
            'mes' => now()->format('Y-m'),
        ]));

        $resposta->assertOk();

        $kpis = $resposta->viewData('page')['props']['kpis'];

        $this->assertSame(2, $kpis['total'], 'o total da competência não conta o que está fora da metodologia');
        $this->assertSame(1, $kpis['desconsiderados']);
    }
}
