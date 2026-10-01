<?php

namespace Tests\Feature\DemandasDev;

use App\Models\DevDemanda;
use App\Models\User;
use App\Services\DevDemandas\LinhaDoTempoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Linha do tempo das demandas dev — o prometido contra o realizado, tirado do diário.
 *
 * O que estes testes prendem:
 *  - as fases saem das atualizações, na ordem de registro, sem voltar no tempo;
 *  - o prazo dado é o da atualização de INÍCIO e não muda com revisões;
 *  - revisão "antes de vencer" conta pela data de REGISTRO, não pela data digitada;
 *  - começar sem prazo é recusado; demanda que já começou (importada) não é travada;
 *  - demanda sem prazo dado fica fora da pontualidade, sem prazo inventado.
 */
class LinhaDoTempoTest extends TestCase
{
    use LiberaModulosDev;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->liberarModulosDev();
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

    private function demanda(array $attrs = []): DevDemanda
    {
        static $n = 0;
        $n++;

        return DevDemanda::create($attrs + [
            'codigo'       => sprintf('DEV-%02d', $n),
            'titulo'       => "Demanda {$n}",
            'prioridade'   => 2,
            'data_entrada' => '2026-09-01',
        ]);
    }

    /** Grava direto no diário, com `created_at` controlado (o registro do aviso). */
    private function registrar(DevDemanda $d, string $data, string $status, array $extra = [], ?string $registradoEm = null): void
    {
        Carbon::setTestNow(($registradoEm ?? $data) . ' 18:00:00');
        $d->atualizacoes()->create($extra + ['data' => $data, 'status' => $status]);
        Carbon::setTestNow('2026-09-22 10:00:00');
    }

    private function tempo(DevDemanda $d): array
    {
        return app(LinhaDoTempoService::class)->paraDemandas(collect([$d->fresh()]), now()->startOfDay())[$d->id];
    }

    // ═══ Fases ═══

    public function test_fases_saem_do_diario_com_bloqueio_e_validacao(): void
    {
        $d = $this->demanda();
        $this->registrar($d, '2026-09-03', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-10']);
        $this->registrar($d, '2026-09-06', 'em_desenvolvimento', ['bloqueado' => true, 'motivo_bloqueio' => 'acesso']);
        $this->registrar($d, '2026-09-08', 'em_desenvolvimento');
        $this->registrar($d, '2026-09-09', 'em_validacao');
        $this->registrar($d, '2026-09-11', 'concluido', ['feito' => 'pronto']);

        $t = $this->tempo($d);

        $this->assertSame(
            [['fila', 2], ['desenvolvimento', 3], ['bloqueada', 2], ['desenvolvimento', 1], ['validacao', 2]],
            array_map(fn ($f) => [$f['tipo'], $f['dias']], $t['fases']),
        );
        $this->assertSame('concluida', $t['estado']);
        $this->assertSame('2026-09-11', $t['concluida_em']);
        $this->assertSame('2026-09-03', $t['iniciada_em']);
        $this->assertSame('2026-09-10', $t['prazo_dado']);
        $this->assertSame(1, $t['desvio']);
        $this->assertSame(['desenvolvimento' => 4, 'bloqueada' => 2, 'validacao' => 2, 'fila' => 2], $t['dias']);
        $this->assertSame(0, $t['retrabalho']);
    }

    public function test_aberta_segue_ate_hoje_e_mede_o_desvio_ate_hoje(): void
    {
        $d = $this->demanda();
        $this->registrar($d, '2026-09-10', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-20']);

        $t = $this->tempo($d);

        $this->assertSame('aberta', $t['estado']);
        $this->assertNull($t['concluida_em']);
        $this->assertSame(['fila' => 9, 'desenvolvimento' => 12], array_column(array_map(fn ($f) => [$f['tipo'], $f['dias']], $t['fases']), 1, 0));
        $this->assertSame(2, $t['desvio']); // 22/09 contra o prazo de 20/09
    }

    public function test_data_digitada_nao_volta_antes_do_marco_anterior(): void
    {
        $d = $this->demanda();
        $this->registrar($d, '2026-09-10', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-20']);
        // Registrada depois, mas com data anterior: não pode abrir fase "no passado".
        $this->registrar($d, '2026-09-05', 'em_validacao', [], '2026-09-12');

        $fases = $this->tempo($d)['fases'];

        $this->assertSame(['fila', 'validacao'], array_column($fases, 'tipo'));
        $this->assertSame('2026-09-10', $fases[1]['de']);
    }

    public function test_retrabalho_conta_volta_da_validacao_e_reabertura(): void
    {
        $d = $this->demanda();
        $this->registrar($d, '2026-09-02', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-15']);
        $this->registrar($d, '2026-09-04', 'em_validacao');
        $this->registrar($d, '2026-09-04', 'em_desenvolvimento'); // voltou no mesmo dia: conta
        $this->registrar($d, '2026-09-06', 'concluido', ['feito' => 'x']);
        $this->registrar($d, '2026-09-08', 'em_desenvolvimento'); // reaberta: conta

        $this->assertSame(2, $this->tempo($d)['retrabalho']);
    }

    // ═══ Prazo dado e revisões ═══

    public function test_revisao_nao_muda_o_prazo_dado_e_conta_pelo_registro(): void
    {
        $d = $this->demanda();
        $this->registrar($d, '2026-09-02', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-10']);
        // Registrada em 09/09 (antes de vencer) → avisou antes.
        $this->registrar($d, '2026-09-09', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-14']);
        // Data digitada 12/09, mas registrada em 16/09 — depois de vencer o prazo vigente (14/09).
        $this->registrar($d, '2026-09-12', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-18'], '2026-09-16');

        $t = $this->tempo($d);

        $this->assertSame('2026-09-10', $t['prazo_dado']);
        $this->assertSame([
            ['em' => '2026-09-09', 'de' => '2026-09-10', 'para' => '2026-09-14', 'antes_de_vencer' => true],
            ['em' => '2026-09-16', 'de' => '2026-09-14', 'para' => '2026-09-18', 'antes_de_vencer' => false],
        ], $t['revisoes']);
        $this->assertTrue($t['revisou_antes']);
    }

    public function test_repetir_a_mesma_data_nao_e_revisao(): void
    {
        $d = $this->demanda();
        $this->registrar($d, '2026-09-02', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-10']);
        $this->registrar($d, '2026-09-05', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-10']);

        $this->assertSame([], $this->tempo($d)['revisoes']);
    }

    public function test_iniciada_sem_prazo_fica_sem_prazo_dado_e_sem_desvio(): void
    {
        $d = $this->demanda(['prazo' => '2026-09-10']);
        // Como as linhas importadas da planilha: começou sem prazo no diário.
        $this->registrar($d, '2026-09-02', 'em_desenvolvimento');
        $this->registrar($d, '2026-09-05', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-12']);

        $t = $this->tempo($d);

        $this->assertNull($t['prazo_dado']);
        $this->assertNull($t['desvio']);
        $this->assertSame([], $t['revisoes']);
    }

    // ═══ Registro pela tela ═══

    public function test_comecar_sem_prazo_e_recusado_e_com_prazo_vira_o_prazo_da_demanda(): void
    {
        $admin = $this->admin();
        $d = $this->demanda(['prazo' => '2026-10-15']);
        $base = ['data' => '2026-09-22', 'status' => 'em_desenvolvimento', 'bloqueado' => false];

        $this->actingAs($admin)->post("/dev/demandas/{$d->id}/atualizacoes", $base)
            ->assertSessionHasErrors('previsao_revisada');
        $this->assertSame(0, $d->atualizacoes()->count());

        $this->actingAs($admin)->post("/dev/demandas/{$d->id}/atualizacoes", $base + ['previsao_revisada' => '2026-09-30'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-09-30', $d->fresh()->prazo->toDateString());
        $this->assertSame('2026-09-30', $this->tempo($d)['prazo_dado']);
    }

    public function test_depois_de_comecar_o_prazo_e_opcional_e_revisao_move_o_vigente(): void
    {
        $admin = $this->admin();
        $d = $this->demanda();
        $this->registrar($d, '2026-09-15', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-25']);

        $this->actingAs($admin)->post("/dev/demandas/{$d->id}/atualizacoes", ['data' => '2026-09-22', 'status' => 'em_validacao', 'bloqueado' => false])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/dev/demandas/{$d->id}/atualizacoes", ['data' => '2026-09-22', 'status' => 'em_validacao', 'bloqueado' => false, 'previsao_revisada' => '2026-09-28'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-09-28', $d->fresh()->prazo->toDateString());
        $this->assertSame('2026-09-25', $this->tempo($d)['prazo_dado']);
    }

    public function test_demanda_ja_comecada_sem_prazo_nao_e_travada(): void
    {
        $admin = $this->admin();
        $d = $this->demanda();
        $this->registrar($d, '2026-09-10', 'em_desenvolvimento'); // importada, sem prazo

        $this->actingAs($admin)->post("/dev/demandas/{$d->id}/atualizacoes", ['data' => '2026-09-22', 'status' => 'em_desenvolvimento', 'bloqueado' => false])
            ->assertSessionHasNoErrors();
    }

    public function test_prazo_antes_da_data_da_atualizacao_e_recusado(): void
    {
        $admin = $this->admin();
        $d = $this->demanda();

        $this->actingAs($admin)->post("/dev/demandas/{$d->id}/atualizacoes", ['data' => '2026-09-22', 'status' => 'em_desenvolvimento', 'bloqueado' => false, 'previsao_revisada' => '2026-09-20'])
            ->assertSessionHasErrors('previsao_revisada');
    }

    public function test_voltar_para_a_fila_nao_exige_prazo(): void
    {
        $admin = $this->admin();
        $d = $this->demanda();

        $this->actingAs($admin)->post("/dev/demandas/{$d->id}/atualizacoes", ['data' => '2026-09-22', 'status' => 'a_fazer', 'bloqueado' => false])
            ->assertSessionHasNoErrors();
    }

    // ═══ Métricas por dev ═══

    public function test_metricas_por_dev_separam_prazo_folga_revisao_e_janela(): void
    {
        $admin = $this->admin();
        $dev = User::factory()->create(['role' => 'consultor', 'active' => true, 'name' => 'Dev Um']);

        // No prazo, 2 dias antes.
        $a = $this->demanda(['responsavel_id' => $dev->id]);
        $this->registrar($a, '2026-09-02', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-10']);
        $this->registrar($a, '2026-09-08', 'concluido', ['feito' => 'x']);

        // Atrasou 3 dias, mas revisou antes de vencer.
        $b = $this->demanda(['responsavel_id' => $dev->id]);
        $this->registrar($b, '2026-09-02', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-10']);
        $this->registrar($b, '2026-09-09', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-13']);
        $this->registrar($b, '2026-09-13', 'concluido', ['feito' => 'x']);

        // Atrasou 1 dia sem aviso, com retrabalho e 2 dias bloqueada.
        $c = $this->demanda(['responsavel_id' => $dev->id]);
        $this->registrar($c, '2026-09-02', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-10']);
        $this->registrar($c, '2026-09-04', 'bloqueado', ['bloqueado' => true, 'motivo_bloqueio' => 'y']);
        $this->registrar($c, '2026-09-06', 'em_validacao');
        $this->registrar($c, '2026-09-07', 'em_desenvolvimento');
        $this->registrar($c, '2026-09-11', 'concluido', ['feito' => 'x']);

        // Concluída sem prazo dado (direto do backlog).
        $s = $this->demanda(['responsavel_id' => $dev->id]);
        $this->registrar($s, '2026-09-05', 'concluido', ['feito' => 'x']);

        // Fora da janela de 90 dias.
        $velha = $this->demanda(['responsavel_id' => $dev->id, 'data_entrada' => '2026-05-01']);
        $this->registrar($velha, '2026-05-02', 'em_desenvolvimento', ['previsao_revisada' => '2026-05-05']);
        $this->registrar($velha, '2026-05-20', 'concluido', ['feito' => 'x']);

        $m = null;
        $this->actingAs($admin)->get('/dev/demandas')->assertOk()->assertInertia(function (Assert $page) use (&$m) {
            $m = $page->toArray()['props']['metricas'];
        });

        $this->assertSame(90, $m['janela_dias']);
        $devUm = collect($m['devs'])->firstWhere('id', $dev->id);
        $this->assertSame(4, $devUm['entregas']);
        $this->assertSame(3, $devUm['com_prazo']);
        $this->assertSame(1, $devUm['sem_prazo']);
        $this->assertSame(1, $devUm['no_prazo']);
        $this->assertSame(2, $devUm['atrasos']);
        $this->assertSame(1, $devUm['revisou_antes']);
        $this->assertSame(-1, $devUm['sobra_mediana']); // sobras 2, -3, -1 → mediana -1
        $this->assertSame(1, $devUm['retrabalho']);
        $this->assertSame(2, $devUm['espera_bloqueio']);
        $this->assertCount(3, $devUm['pontos']);
    }

    public function test_linhas_da_tela_trazem_a_linha_do_tempo(): void
    {
        $admin = $this->admin();
        $d = $this->demanda();
        $this->registrar($d, '2026-09-10', 'em_desenvolvimento', ['previsao_revisada' => '2026-09-20']);

        $this->actingAs($admin)->get('/dev/demandas')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('demandas.0.tempo.prazo_dado', '2026-09-20')
            ->where('demandas.0.tempo.estado', 'aberta')
            ->has('demandas.0.tempo.fases', 2));
    }
}
