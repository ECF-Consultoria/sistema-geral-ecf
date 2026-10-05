<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\MlToken;
use App\Models\PubAlavancaEscrita;
use App\Models\User;
use App\Support\Publicador\AlavancasLiberadas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publicador\Alavancas\Concerns\ApoioEscritaHttp;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/**
 * 166-11 (D-03): a recusa é do SERVIDOR. Conta fora da lista das Alavancas = 403 {message, regra} e linha
 * RECUSADA, sem nenhuma escrita, nas duas âncoras — com ou sem assinatura.
 */
class TravaEscritaHttpTest extends TestCase
{
    use ApoioEscritaHttp;
    use CenarioAlavancas;
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function ancoras(): array
    {
        return ['Company' => ['company'], 'MlbEmpresa sem Company' => ['mlb_empresa']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ancoras')]
    public function test_conta_fora_da_lista_sem_assinatura_e_403_e_grava_recusada(string $ancora): void
    {
        $this->cenarioDeal(1, $ancora, false);

        $r = $this->confirmarCom('convite.inscrever', [$this->itemDeal()], null);

        $r->assertStatus(403)->assertJsonPath('regra', 'ALAV-LIB')->assertJsonPath('message', AlavancasLiberadas::MOTIVO);
        $this->assertSame([], $this->escritasNoMl());
        $linha = PubAlavancaEscrita::sole();
        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-LIB', $linha->erro_codigo);
        $this->assertSame('MLB1', $linha->item_id);
        $this->assertSame($this->ancora->chaveContaMl(), $linha->conta_chave);
        $this->assertSame($this->ancora->id, (int) ($ancora === 'company' ? $linha->company_id : $linha->mlb_empresa_id));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ancoras')]
    public function test_conta_fora_da_lista_com_assinatura_forjada_tambem_e_403(string $ancora): void
    {
        $this->cenarioDeal(1, $ancora, false);
        $itens = [$this->itemDeal()];

        $r = $this->confirmarCom('convite.inscrever', $itens, $this->assinaturaForjada('convite.inscrever', $itens));

        $r->assertStatus(403)->assertJsonPath('regra', 'ALAV-LIB')->assertJsonPath('message', AlavancasLiberadas::MOTIVO);
        $this->assertSame([], $this->escritasNoMl());
        $this->assertSame(1, PubAlavancaEscrita::where('resultado', PubAlavancaEscrita::RECUSADA)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ancoras')]
    public function test_conta_que_saiu_da_lista_depois_da_previa_e_recusada(string $ancora): void
    {
        $this->cenarioDeal(1, $ancora);
        $itens = [$this->itemDeal()];
        $assinatura = $this->previaDe('convite.inscrever', $itens)->assertOk()->json('assinatura');

        config(['publicador.alavancas.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);

        $this->confirmarCom('convite.inscrever', $itens, $assinatura)
            ->assertStatus(403)->assertJsonPath('regra', 'ALAV-LIB')->assertJsonPath('message', AlavancasLiberadas::MOTIVO);
        $this->assertSame([], $this->escritasNoMl());
        $this->assertSame(PubAlavancaEscrita::RECUSADA, PubAlavancaEscrita::sole()->resultado);
    }

    public function test_liberar_so_a_publicacao_nao_libera_as_alavancas(): void
    {
        $this->cenarioDeal(1, 'company', false);
        config(['publicador.contas_liberadas' => ['companies' => [$this->ancora->id], 'mlb_empresas' => []]]);
        $itens = [$this->itemDeal()];

        $this->confirmarCom('convite.inscrever', $itens, $this->assinaturaForjada('convite.inscrever', $itens))
            ->assertStatus(403)->assertJsonPath('regra', 'ALAV-LIB');
        $this->assertSame([], $this->escritasNoMl());
    }

    public function test_liberada_so_nas_alavancas_com_a_publicacao_vazia_escreve(): void
    {
        $this->cenarioDeal(1);
        $this->assertSame([], config('publicador.contas_liberadas.companies'));
        $itens = [$this->itemDeal()];

        $this->confirmarCom('convite.inscrever', $itens, $this->previaDe('convite.inscrever', $itens)->json('assinatura'))
            ->assertOk()->assertJsonPath('escrita.resultado', 'OK');
        $this->assertCount(1, $this->escritasNoMl());
    }

    public function test_item_id_malformado_na_conta_fora_da_lista_nao_estoura(): void
    {
        $this->cenarioDeal(1, 'company', false);

        foreach ([['MLB1'], str_repeat('MLB', 70), ['x' => 1]] as $ruim) {
            $this->confirmarCom('convite.inscrever', [['item_id' => $ruim, 'promotion_type' => ['a'], 'promotion_id' => str_repeat('z', 200)]], null)
                ->assertStatus(403)->assertJsonPath('regra', 'ALAV-LIB');
        }
        $this->assertSame(3, PubAlavancaEscrita::where('resultado', PubAlavancaEscrita::RECUSADA)->count());
        $this->assertSame(0, PubAlavancaEscrita::whereNotNull('item_id')->count());
        $this->assertSame([], $this->escritasNoMl());
    }

    public function test_vendedor_do_token_diferente_do_users_me_e_403_v_acc_03(): void
    {
        $this->cenarioDeal(1);
        $itens = [$this->itemDeal()];
        $assinatura = $this->previaDe('convite.inscrever', $itens)->assertOk()->json('assinatura');
        $this->usuario = [...self::fixtureSondagem('conta/usuario'), 'id' => 999000111];

        $this->confirmarCom('convite.inscrever', $itens, $assinatura)
            ->assertStatus(403)->assertJsonPath('regra', 'V-ACC-03');
        $this->assertSame([], $this->escritasNoMl());
        $this->assertSame('V-ACC-03', PubAlavancaEscrita::sole()->erro_codigo);
    }

    public function test_consultor_recebe_403_nas_tres_rotas_de_escrita(): void
    {
        $this->cenarioDeal(1);
        $consultor = User::factory()->create(['role' => 'consultor']);
        $uuid = '11111111-1111-4111-8111-111111111111';

        $this->actingAs($consultor)->postJson($this->rota('escritas.previa'), ['acao' => 'convite.inscrever', 'itens' => [$this->itemDeal()]])->assertForbidden();
        $this->actingAs($consultor)->postJson($this->rota('escritas.confirmar'), ['acao' => 'convite.inscrever', 'itens' => [$this->itemDeal()]])->assertForbidden();
        $this->actingAs($consultor)->getJson($this->rota('lotes', ['lote' => $uuid]))->assertForbidden();
        $this->assertSame(0, PubAlavancaEscrita::count());
        $this->assertSame([], $this->chamadasAoMl('GET', '#.*#'));
    }

    public function test_conta_sem_token_e_409_v_acc_01_sem_chamar_o_ml(): void
    {
        $this->cenarioDeal(1);
        MlToken::query()->delete();

        $this->previaDe('convite.inscrever', [$this->itemDeal()])->assertStatus(409)->assertJsonPath('regra', 'V-ACC-01');
        $this->confirmarCom('convite.inscrever', [$this->itemDeal()], null)->assertStatus(409)->assertJsonPath('regra', 'V-ACC-01');
        $this->assertSame([], $this->chamadasAoMl('GET', '#.*#'));
        $this->assertSame([], $this->escritasNoMl());
    }
}
