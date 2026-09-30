<?php

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\MlbEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * TKT-0003 — opção "ADS ligado / desligado" na coluna Sinais de /polos/empresas.
 *
 * `mlb_empresas.ads_desligado` já existia (true = desligado, false = ligado,
 * null = não informado) e já alimentava ícones, o chip "Ads desligado" e os
 * alertas — mas nenhuma rota gravava nele, então ficava sempre vazio.
 *
 * @group polos
 */
class AdsLigadoDesligadoTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => 'Loja ' . Str::random(4),
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
        ], $opts));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_marca_desligado_ligado_e_volta_para_nao_informado(): void
    {
        $empresa = $this->empresa();
        $admin   = $this->admin();
        $this->assertNull($empresa->ads_desligado);

        $this->actingAs($admin)
            ->patch(route('polos.empresas.ads', $empresa->id), ['ads_desligado' => true])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertTrue($empresa->refresh()->ads_desligado);

        $this->actingAs($admin)
            ->patch(route('polos.empresas.ads', $empresa->id), ['ads_desligado' => false])
            ->assertRedirect();
        $this->assertFalse($empresa->refresh()->ads_desligado);

        // Clicar no estado já marcado limpa: volta para "não informado", não para "ligado".
        $this->actingAs($admin)
            ->patch(route('polos.empresas.ads', $empresa->id), ['ads_desligado' => null])
            ->assertRedirect();
        $this->assertNull($empresa->refresh()->ads_desligado);
    }

    public function test_cada_troca_fica_no_historico_com_quem_fez(): void
    {
        $empresa = $this->empresa();
        $admin   = $this->admin();

        $this->actingAs($admin)->patch(route('polos.empresas.ads', $empresa->id), ['ads_desligado' => true]);
        // Mesmo valor de novo não gera linha: não houve troca.
        $this->actingAs($admin)->patch(route('polos.empresas.ads', $empresa->id), ['ads_desligado' => true]);

        $logs = Activity::where('log_name', 'polos')->where('subject_id', $empresa->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame($admin->id, $logs[0]->causer_id);
        $this->assertSame('não informado', $logs[0]->properties['de']);
        $this->assertSame('desligado', $logs[0]->properties['para']);
    }

    public function test_campo_ausente_ou_invalido_nao_grava(): void
    {
        $empresa = $this->empresa(['ads_desligado' => false]);

        $this->actingAs($this->admin())
            ->patch(route('polos.empresas.ads', $empresa->id), [])
            ->assertSessionHasErrors('ads_desligado');

        $this->actingAs($this->admin())
            ->patch(route('polos.empresas.ads', $empresa->id), ['ads_desligado' => 'talvez'])
            ->assertSessionHasErrors('ads_desligado');

        $this->assertFalse($empresa->refresh()->ads_desligado);
    }

    public function test_sem_acesso_ao_faturamento_polos_recebe_403(): void
    {
        $empresa = $this->empresa();

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->patch(route('polos.empresas.ads', $empresa->id), ['ads_desligado' => true])
            ->assertForbidden();

        $this->assertNull($empresa->refresh()->ads_desligado);
    }

    public function test_empresa_fora_do_projeto_polos_recebe_403(): void
    {
        $empresa = $this->empresa(['projeto' => 'PUBLICACAO', 'tipo' => 'PUBLICADOR']);

        $this->actingAs($this->admin())
            ->patch(route('polos.empresas.ads', $empresa->id), ['ads_desligado' => true])
            ->assertForbidden();

        $this->assertNull($empresa->refresh()->ads_desligado);
    }

    public function test_linha_da_tabela_leva_o_id_da_empresa_e_os_tres_estados(): void
    {
        $desligada = $this->empresa(['cust_id' => '111', 'ads_desligado' => true]);
        $ligada    = $this->empresa(['cust_id' => '222', 'ads_desligado' => false]);
        $vazia     = $this->empresa(['cust_id' => '333']);

        // Mesmo shape que montarAtivosDoMes() entrega no mês corrente.
        $ativos = collect([$desligada, $ligada, $vazia])
            ->map(fn ($e) => $e->only(['id', 'nome', 'cust_id', 'polo', 'fase', 'problema', 'problema_nota', 'problema_desconsidera_meta', 'ads_desligado']))
            ->all();

        $m = new ReflectionMethod(PolosController::class, 'agregarPorPolo');
        $m->setAccessible(true);
        $polos = $m->invoke(app(PolosController::class), $ativos, [], ['M2' => 1000]);

        $linhas = collect($polos[0]['empresas'])->keyBy('cust_id');
        $this->assertSame($desligada->id, $linhas['111']['mlb_empresa_id']);
        $this->assertTrue($linhas['111']['ads_desligado']);
        $this->assertFalse($linhas['222']['ads_desligado']);
        $this->assertNull($linhas['333']['ads_desligado']);

        // Roster reconstruído do CSV não tem cadastro: a tela mostra só leitura.
        $semId = $ativos[0];
        unset($semId['id']);
        $linha = $m->invoke(app(PolosController::class), [$semId], [], ['M2' => 1000])[0]['empresas'][0];
        $this->assertNull($linha['mlb_empresa_id']);
    }
}
