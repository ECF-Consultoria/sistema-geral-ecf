<?php

namespace Tests\Feature\DemandasDev;

use App\Models\DevDemanda;
use App\Models\DevReuniao;
use App\Models\User;
use App\Services\DevDemandas\DemandasDevService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Demandas Dev — o mecanismo da planilha de gestão do time dev.
 *
 * O que estes testes prendem:
 *  - status/próxima ação/bloqueio vêm da ÚLTIMA atualização (maior id), nunca de coluna;
 *  - a cascata da "Situação" e a ordem da fila são as da planilha;
 *  - quem não é admin só entra com demanda atribuída e só mexe nas próprias.
 */
class DemandasDevTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-09-22 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function dev(): User
    {
        return User::factory()->create(['role' => 'consultor', 'active' => true]);
    }

    private function demanda(array $attrs = []): DevDemanda
    {
        static $n = 0;
        $n++;

        return DevDemanda::create($attrs + [
            'codigo'       => sprintf('DEV-%02d', $n),
            'titulo'       => "Demanda {$n}",
            'prioridade'   => 2,
            'data_entrada' => '2026-09-19',
        ]);
    }

    private function atualizar(DevDemanda $d, string $status, array $extra = []): void
    {
        $d->atualizacoes()->create($extra + ['data' => '2026-09-22', 'status' => $status]);
    }

    private function linha(DevDemanda $d): array
    {
        $d = DevDemanda::with(['responsavel', 'ultimaAtualizacao'])->withCount('atualizacoes')->find($d->id);

        return app(DemandasDevService::class)->serializar($d, now()->startOfDay());
    }

    // ═══ Status derivado ═══

    public function test_sem_atualizacao_a_demanda_esta_em_backlog(): void
    {
        $l = $this->linha($this->demanda());

        $this->assertSame('backlog', $l['status']);
        $this->assertNull($l['proxima_acao']);
        $this->assertNull($l['ultima_atualizacao']);
        $this->assertFalse($l['bloqueado']);
    }

    public function test_status_vem_da_ultima_atualizacao_registrada_mesmo_com_data_anterior(): void
    {
        $d = $this->demanda();
        $this->atualizar($d, 'em_desenvolvimento', ['data' => '2026-09-22', 'proxima_acao' => 'antiga']);
        // Registrada depois, mas com data mais antiga: a planilha usa a ordem das linhas, não a data.
        $this->atualizar($d, 'em_validacao', ['data' => '2026-09-20', 'proxima_acao' => 'nova', 'bloqueado' => true, 'motivo_bloqueio' => 'Erlon']);

        $l = $this->linha($d);

        $this->assertSame('em_validacao', $l['status']);
        $this->assertSame('nova', $l['proxima_acao']);
        $this->assertSame('2026-09-20', $l['ultima_atualizacao']);
        $this->assertTrue($l['bloqueado']);
        $this->assertSame('Erlon', $l['motivo_bloqueio']);
        $this->assertSame(2, $l['total_atualizacoes']);
    }

    // ═══ Situação ═══

    public function test_situacao_segue_a_cascata_da_planilha(): void
    {
        $this->assertSame('sem_prazo', $this->linha($this->demanda())['situacao']);
        $this->assertSame('no_prazo', $this->linha($this->demanda(['prazo' => '2026-09-25']))['situacao']);
        // Até 2 dias = prazo próximo (inclui o próprio dia).
        $this->assertSame('prazo_proximo', $this->linha($this->demanda(['prazo' => '2026-09-24']))['situacao']);
        $this->assertSame('prazo_proximo', $this->linha($this->demanda(['prazo' => '2026-09-22']))['situacao']);

        $atrasada = $this->linha($this->demanda(['prazo' => '2026-09-19']));
        $this->assertSame('atrasada', $atrasada['situacao']);
        $this->assertSame(3, $atrasada['dias_atraso']);

        // Bloqueio vence atraso; conclusão vence tudo e zera o atraso.
        $bloq = $this->demanda(['prazo' => '2026-09-19']);
        $this->atualizar($bloq, 'em_desenvolvimento', ['bloqueado' => true]);
        $this->assertSame('bloqueado', $this->linha($bloq)['situacao']);

        $status = $this->demanda();
        $this->atualizar($status, 'bloqueado');
        $this->assertSame('bloqueado', $this->linha($status)['situacao']);

        $concl = $this->demanda(['prazo' => '2026-09-01']);
        $this->atualizar($concl, 'concluido', ['bloqueado' => true]);
        $l = $this->linha($concl);
        $this->assertSame('concluido', $l['situacao']);
        $this->assertSame(0, $l['dias_atraso']);
        $this->assertTrue($l['encerrada']);
    }

    // ═══ Fila ═══

    public function test_fila_ordena_bloqueio_p0_p1_atraso_validacao_desenvolvimento_e_prazo(): void
    {
        $dev = $this->dev();
        $base = ['responsavel_id' => $dev->id];

        $resto      = $this->demanda($base + ['codigo' => 'X-07', 'prazo' => '2026-10-30']);
        $restoCedo  = $this->demanda($base + ['codigo' => 'X-08', 'prazo' => '2026-10-01']);
        $emDev      = $this->demanda($base + ['codigo' => 'X-06']);
        $this->atualizar($emDev, 'em_desenvolvimento');
        $validacao  = $this->demanda($base + ['codigo' => 'X-05']);
        $this->atualizar($validacao, 'em_validacao');
        $atrasada   = $this->demanda($base + ['codigo' => 'X-04', 'prazo' => '2026-09-01']);
        $p1         = $this->demanda($base + ['codigo' => 'X-03', 'prioridade' => 1]);
        $p0         = $this->demanda($base + ['codigo' => 'X-02', 'prioridade' => 0]);
        $bloqueada  = $this->demanda($base + ['codigo' => 'X-01', 'prioridade' => 3]);
        $this->atualizar($bloqueada, 'a_fazer', ['bloqueado' => true]);
        $concluida  = $this->demanda($base + ['codigo' => 'X-00', 'prioridade' => 0]);
        $this->atualizar($concluida, 'concluido');
        $deOutro    = $this->demanda(['codigo' => 'X-99', 'prioridade' => 0]);

        $service = app(DemandasDevService::class);
        $linhas = $service->demandasVisiveis($this->admin())->map(fn ($d) => $service->serializar($d, now()))->all();

        $fila = array_column($service->fila($linhas, $dev->id), 'codigo');

        $this->assertSame(['X-01', 'X-02', 'X-03', 'X-04', 'X-05', 'X-06', 'X-08', 'X-07'], $fila);
    }

    // ═══ Acesso ═══

    public function test_admin_acessa_e_ve_todas(): void
    {
        $this->demanda();
        $this->demanda(['responsavel_id' => $this->dev()->id]);

        $this->actingAs($this->admin())->get('/dev/demandas')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Dev/Demandas/Index')
                ->has('demandas', 2)
                ->where('pode.gerenciar', true));
    }

    public function test_quem_nao_e_admin_e_nao_tem_demanda_leva_403(): void
    {
        $this->demanda();

        $this->actingAs($this->dev())->get('/dev/demandas')->assertForbidden();
    }

    public function test_responsavel_nao_admin_ve_so_as_proprias_e_nao_abre_detalhe_alheio(): void
    {
        $dev = $this->dev();
        $minha = $this->demanda(['responsavel_id' => $dev->id]);
        $alheia = $this->demanda(['responsavel_id' => $this->dev()->id]);

        $this->actingAs($dev)->get('/dev/demandas?demanda=' . $alheia->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('demandas', 1)
                ->where('demandas.0.id', $minha->id)
                ->where('pode.gerenciar', false)
                ->where('detalhe', null));
    }

    public function test_flag_do_menu_acompanha_a_regra_de_acesso(): void
    {
        $dev = $this->dev();
        $this->actingAs($dev)->get('/profile')->assertInertia(fn (Assert $p) => $p->where('auth.demandas_dev', false));

        $this->demanda(['responsavel_id' => $dev->id]);
        $this->actingAs($dev)->get('/profile')->assertInertia(fn (Assert $p) => $p->where('auth.demandas_dev', true));
    }

    // ═══ Atualizações ═══

    public function test_responsavel_registra_atualizacao_na_propria_e_nao_na_alheia(): void
    {
        $dev = $this->dev();
        $minha = $this->demanda(['responsavel_id' => $dev->id]);
        $alheia = $this->demanda(['responsavel_id' => $this->dev()->id]);
        $payload = ['data' => '2026-09-22', 'status' => 'em_desenvolvimento', 'feito' => 'layout', 'proxima_acao' => 'validar', 'bloqueado' => false];

        $this->actingAs($dev)->post("/dev/demandas/{$minha->id}/atualizacoes", $payload)->assertRedirect();
        $this->actingAs($dev)->post("/dev/demandas/{$alheia->id}/atualizacoes", $payload)->assertForbidden();

        $a = $minha->atualizacoes()->sole();
        $this->assertSame($dev->id, $a->user_id);
        $this->assertSame('em_desenvolvimento', $a->status);
        $this->assertSame(0, $alheia->atualizacoes()->count());
    }

    public function test_bloqueio_exige_motivo_e_motivo_some_sem_bloqueio(): void
    {
        $admin = $this->admin();
        $d = $this->demanda();

        $this->actingAs($admin)
            ->post("/dev/demandas/{$d->id}/atualizacoes", ['data' => '2026-09-22', 'status' => 'a_fazer', 'bloqueado' => true])
            ->assertSessionHasErrors('motivo_bloqueio');

        $this->actingAs($admin)
            ->post("/dev/demandas/{$d->id}/atualizacoes", ['data' => '2026-09-22', 'status' => 'a_fazer', 'bloqueado' => false, 'motivo_bloqueio' => 'resto'])
            ->assertSessionHasNoErrors();

        $this->assertNull($d->atualizacoes()->sole()->motivo_bloqueio);
    }

    public function test_status_invalido_e_recusado(): void
    {
        $d = $this->demanda();

        $this->actingAs($this->admin())
            ->post("/dev/demandas/{$d->id}/atualizacoes", ['data' => '2026-09-22', 'status' => 'pronto'])
            ->assertSessionHasErrors('status');
    }

    // ═══ Cadastro ═══

    public function test_admin_cadastra_com_codigo_sequencial_por_prefixo(): void
    {
        $admin = $this->admin();
        $this->demanda(['codigo' => 'DEV-24']);
        $this->demanda(['codigo' => 'MKT-03']);

        $payload = ['prefixo' => 'dev', 'titulo' => 'Nova', 'prioridade' => 1, 'data_entrada' => '2026-09-22'];
        $this->actingAs($admin)->post('/dev/demandas', $payload)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/dev/demandas', ['prefixo' => 'ADM'] + $payload)->assertSessionHasNoErrors();

        $this->assertTrue(DevDemanda::where('codigo', 'DEV-25')->where('criado_por', $admin->id)->exists());
        $this->assertTrue(DevDemanda::where('codigo', 'ADM-01')->exists());
    }

    public function test_quem_nao_e_admin_nao_cadastra_nem_edita(): void
    {
        $dev = $this->dev();
        $d = $this->demanda(['responsavel_id' => $dev->id]);

        $this->actingAs($dev)->post('/dev/demandas', ['prefixo' => 'DEV', 'titulo' => 'x', 'prioridade' => 1, 'data_entrada' => '2026-09-22'])->assertForbidden();
        $this->actingAs($dev)->put("/dev/demandas/{$d->id}", ['titulo' => 'x', 'prioridade' => 0, 'data_entrada' => '2026-09-22'])->assertForbidden();
    }

    public function test_edicao_nao_troca_o_codigo(): void
    {
        $d = $this->demanda(['codigo' => 'DEV-01']);

        $this->actingAs($this->admin())
            ->put("/dev/demandas/{$d->id}", ['prefixo' => 'MKT', 'titulo' => 'x', 'prioridade' => 0, 'data_entrada' => '2026-09-22'])
            ->assertSessionHasErrors('prefixo');

        $this->assertSame('DEV-01', $d->fresh()->codigo);
    }

    // ═══ Reuniões ═══

    public function test_reuniao_liga_demandas_e_responsavel_so_ve_as_ligadas_as_proprias(): void
    {
        $admin = $this->admin();
        $dev = $this->dev();
        $minha = $this->demanda(['responsavel_id' => $dev->id]);
        $alheia = $this->demanda();

        $this->actingAs($admin)->post('/dev/demandas/reunioes', [
            'data' => '2026-09-19', 'titulo' => 'Alinhamento', 'link_gravacao' => 'https://drive.google.com/x',
            'demandas' => [$minha->id, $alheia->id],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/dev/demandas/reunioes', [
            'data' => '2026-09-20', 'titulo' => 'Só do outro', 'demandas' => [$alheia->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, DevReuniao::first()->demandas()->count());

        $this->actingAs($dev)->get('/dev/demandas')->assertInertia(fn (Assert $p) => $p
            ->has('reunioes', 1)
            ->where('reunioes.0.titulo', 'Alinhamento')
            ->has('reunioes.0.demandas', 1));
    }
}
