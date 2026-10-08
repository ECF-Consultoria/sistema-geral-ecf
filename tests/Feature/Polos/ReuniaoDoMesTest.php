<?php

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\PolosReuniaoMes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * TKT-0004 — check "reunião do mês feita" na gaveta da empresa em /polos/empresas.
 *
 * O sistema não registrava reunião mensal das empresas dos Polos em lugar nenhum
 * (`meetings` e a agenda do onboarding são por `companies`; `reuniao_onboarding` é a
 * reunião de entrada). A marcação é manual, por cust_id + mês, em `polos_reunioes_mes`.
 *
 * @group polos
 */
class ReuniaoDoMesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    }

    /** @return array<string, array<string, mixed>> */
    private function reunioesDoMes(?string $mes): array
    {
        $m = new ReflectionMethod(PolosController::class, 'reunioesDoMes');
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), $mes);
    }

    public function test_marca_feita_e_desmarca_na_mesma_linha_guardando_quem_fez(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('polos.reunioes.marcar'), ['cust_id' => '3323737411', 'mes' => '202610', 'feita' => true])
            ->assertRedirect()
            ->assertSessionHas('success');

        $r = PolosReuniaoMes::sole();
        $this->assertTrue($r->feita);
        $this->assertSame($admin->id, $r->user_id);
        $this->assertSame($admin->name, $r->marcado_por_nome);
        $this->assertNotNull($r->marcado_em);

        // Desmarcar não apaga: grava "não feita" e quem desmarcou.
        $this->actingAs($admin)
            ->post(route('polos.reunioes.marcar'), ['cust_id' => '3323737411', 'mes' => '202610', 'feita' => false])
            ->assertRedirect();

        $this->assertSame(1, PolosReuniaoMes::count());
        $this->assertFalse(PolosReuniaoMes::sole()->feita);
    }

    public function test_cust_id_do_csv_e_normalizado_e_o_mes_separa_as_marcacoes(): void
    {
        $admin = $this->admin();

        // Formato cru do CSV ("<id>,0") tem de cair na mesma empresa da lista da tela.
        $this->actingAs($admin)->post(route('polos.reunioes.marcar'), ['cust_id' => '3323737411,0', 'mes' => '202609', 'feita' => true]);
        $this->actingAs($admin)->post(route('polos.reunioes.marcar'), ['cust_id' => '3323737411', 'mes' => '202610', 'feita' => true]);

        $this->assertSame(['202609', '202610'], PolosReuniaoMes::where('cust_id', '3323737411')->orderBy('mes')->pluck('mes')->all());
    }

    public function test_tela_recebe_so_as_marcacoes_do_mes_selecionado(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('polos.reunioes.marcar'), ['cust_id' => '111', 'mes' => '202610', 'feita' => true]);
        $this->actingAs($admin)->post(route('polos.reunioes.marcar'), ['cust_id' => '222', 'mes' => '202610', 'feita' => false]);
        $this->actingAs($admin)->post(route('polos.reunioes.marcar'), ['cust_id' => '333', 'mes' => '202609', 'feita' => true]);

        $out = $this->reunioesDoMes('202610');

        // Chave numérica vira int no array do PHP; na tela chega como objeto por cust_id.
        $this->assertSame(['111', '222'], array_map('strval', array_keys($out)));
        $this->assertTrue($out['111']['feita']);
        $this->assertFalse($out['222']['feita']);
        $this->assertSame($admin->name, $out['111']['por']);
        $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', $out['111']['em']);

        $this->assertSame([], $this->reunioesDoMes(null));
    }

    public function test_cada_troca_fica_no_historico_e_repetir_nao_gera_linha(): void
    {
        $admin = $this->admin();
        $dados = ['cust_id' => '3323737411', 'mes' => '202610'];

        $this->actingAs($admin)->post(route('polos.reunioes.marcar'), $dados + ['feita' => true]);
        $this->actingAs($admin)->post(route('polos.reunioes.marcar'), $dados + ['feita' => true]);
        $this->actingAs($admin)->post(route('polos.reunioes.marcar'), $dados + ['feita' => false]);

        $logs = Activity::where('log_name', 'polos')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($admin->id, $logs[0]->causer_id);
        $this->assertSame(['cust_id' => '3323737411', 'mes' => '202610', 'feita' => true], $logs[0]->properties->only(['cust_id', 'mes', 'feita'])->all());
        $this->assertFalse($logs[1]->properties['feita']);
    }

    public function test_dados_invalidos_nao_gravam(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('polos.reunioes.marcar'), ['cust_id' => '111', 'mes' => '2026-10', 'feita' => true])
            ->assertSessionHasErrors('mes');

        $this->actingAs($admin)
            ->post(route('polos.reunioes.marcar'), ['cust_id' => '111', 'mes' => '202610'])
            ->assertSessionHasErrors('feita');

        $this->actingAs($admin)
            ->post(route('polos.reunioes.marcar'), ['cust_id' => ' ', 'mes' => '202610', 'feita' => true])
            ->assertSessionHasErrors('cust_id');

        $this->assertSame(0, PolosReuniaoMes::count());
    }

    public function test_sem_acesso_ao_faturamento_polos_recebe_403(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->post(route('polos.reunioes.marcar'), ['cust_id' => '111', 'mes' => '202610', 'feita' => true])
            ->assertForbidden();

        $this->assertSame(0, PolosReuniaoMes::count());
    }

    public function test_tela_sem_dados_do_drive_ainda_entrega_reunioes_vazio(): void
    {
        config(['services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1', 'services.ecf.key' => 'test-key']);
        Cache::flush();
        // Drive sem o POLOS MENSAL: a tela cai no shape vazio e precisa do prop mesmo assim.
        Http::fake(['*' => Http::response(['data' => [], 'total' => 0], 200)]);

        $this->withoutVite();

        $this->actingAs($this->admin())
            ->get(route('polos.empresas'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Empresas', false)
                ->has('reunioes')
            );
    }
}
