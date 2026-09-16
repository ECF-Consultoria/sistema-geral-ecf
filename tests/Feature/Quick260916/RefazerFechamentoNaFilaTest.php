<?php

namespace Tests\Feature\Quick260916;

use App\Jobs\ConsolidarMesFechamentoJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260916-ejt — o "Refazer fechamento" para de quebrar a página.
 *
 * O que se mede aqui: o clique NÃO calcula mais nada dentro da requisição
 * (era isso que estourava o `memory_limit` de 512M do PHP do site com ~200
 * empresas, devolvia 500 e não gravava nada), a resposta é 202 "estou
 * refazendo", e dois disparos simultâneos não regravam a mesma competência
 * em paralelo.
 */
class RefazerFechamentoNaFilaTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'Adman corrigiu o faturamento na origem depois do fechamento.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function criarNaoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor', 'active' => true]);
    }

    private function refazer(User $usuario, array $payload = [])
    {
        return $this->actingAs($usuario)->postJson(
            '/administrativo/financeiro/competencia/refazer',
            array_merge(['mes' => '2026-08', 'motivo' => self::MOTIVO], $payload),
        );
    }

    // ═══ Disparo ══════════════════════════════════════════════════════════

    #[Test]
    public function admin_dispara_recebe_202_e_nada_e_calculado_dentro_da_requisicao(): void
    {
        Queue::fake();

        $admin = $this->criarAdmin();

        $r = $this->refazer($admin);

        $r->assertStatus(202);
        $r->assertJson(['status' => 'running']);

        Queue::assertPushed(ConsolidarMesFechamentoJob::class, 1);
        Queue::assertPushed(
            ConsolidarMesFechamentoJob::class,
            fn (ConsolidarMesFechamentoJob $job) => $job->mes === '2026-08'
                && $job->motivo === self::MOTIVO
                && $job->porUserId === $admin->id,
        );

        // A conferência que importa é por reconsulta ao banco: o cálculo não
        // aconteceu aqui dentro.
        $this->assertSame(0, DB::table('fechamento_snapshots')->count());
        $this->assertSame(0, DB::table('fechamento_reconsolidacoes')->count());
    }

    #[Test]
    public function a_mensagem_do_202_nao_pode_dizer_que_ja_terminou(): void
    {
        Queue::fake();

        $mensagem = (string) $this->refazer($this->criarAdmin())->json('message');

        $this->assertStringNotContainsStringIgnoringCase(
            'refeito com sucesso',
            $mensagem,
            'Nada terminou ainda quando esta resposta sai — declarar sucesso aqui é a mentira que fez a pessoa confiar em número velho.',
        );
        $this->assertStringContainsStringIgnoringCase('Refazendo o fechamento', $mensagem);
        $this->assertStringContainsStringIgnoringCase('Agosto 2026', $mensagem);
    }

    #[Test]
    public function nao_admin_recebe_403_e_nada_vai_para_a_fila(): void
    {
        Queue::fake();

        $this->refazer($this->criarNaoAdmin())->assertStatus(403);

        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('fechamento_reconsolidacoes')->count());
    }

    #[Test]
    public function motivo_curto_e_mes_invalido_continuam_422(): void
    {
        Queue::fake();

        $admin = $this->criarAdmin();

        $this->refazer($admin, ['motivo' => 'curto'])->assertStatus(422);
        $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/refazer', ['mes' => '2026-08'])
            ->assertStatus(422);
        $this->refazer($admin, ['mes' => '2026-8'])->assertStatus(422);
        $this->refazer($admin, ['mes' => 'agosto/2026'])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ═══ Trava anti-duplo-disparo ═════════════════════════════════════════

    #[Test]
    public function segundo_disparo_com_um_em_andamento_devolve_409_e_mantem_um_unico_job(): void
    {
        Queue::fake();

        $admin = $this->criarAdmin();

        $this->refazer($admin)->assertStatus(202);

        $segundo = $this->refazer($admin);

        $segundo->assertStatus(409);
        $this->assertSame(
            'Este fechamento já está sendo refeito — aguarde terminar.',
            $segundo->json('message'),
        );

        // Dois jobs regravariam a MESMA competência em paralelo, cada um
        // deixando a sua linha na trilha de auditoria.
        Queue::assertPushed(ConsolidarMesFechamentoJob::class, 1);
    }

    #[Test]
    public function outro_mes_nao_e_bloqueado_por_um_refazer_em_andamento(): void
    {
        Queue::fake();

        $admin = $this->criarAdmin();

        $this->refazer($admin, ['mes' => '2026-08'])->assertStatus(202);
        $this->refazer($admin, ['mes' => '2026-07'])->assertStatus(202);

        Queue::assertPushed(ConsolidarMesFechamentoJob::class, 2);
    }

    #[Test]
    public function a_trava_usa_exatamente_a_chave_de_andamento_do_job(): void
    {
        Queue::fake();

        $this->refazer($this->criarAdmin())->assertStatus(202);

        $andamento = Cache::get(ConsolidarMesFechamentoJob::statusCacheKeyFor('2026-08'));

        $this->assertIsArray($andamento, 'Sem isto a trava e a tela leriam chaves diferentes e o 409 nunca aconteceria.');
        $this->assertSame('running', $andamento['status']);
    }

    // ═══ Rota de andamento ════════════════════════════════════════════════

    private function andamento(User $usuario, string $mes = '2026-08')
    {
        return $this->actingAs($usuario)
            ->getJson('/administrativo/financeiro/competencia/refazer/status?mes='.$mes);
    }

    #[Test]
    public function andamento_de_quem_nunca_refez_e_idle_e_nao_erro(): void
    {
        $r = $this->andamento($this->criarAdmin());

        $r->assertOk();
        $r->assertJson([
            'status'       => 'idle',
            'started_at'   => null,
            'completed_at' => null,
            'error'        => null,
        ]);
    }

    #[Test]
    public function andamento_durante_o_refazer_e_running_com_o_inicio(): void
    {
        Queue::fake();

        $admin = $this->criarAdmin();
        $this->refazer($admin)->assertStatus(202);

        $r = $this->andamento($admin);

        $r->assertOk();
        $r->assertJson(['status' => 'running']);
        $this->assertNotNull($r->json('started_at'));
        $this->assertNull($r->json('completed_at'));
    }

    #[Test]
    public function andamento_reflete_o_que_o_job_gravou_ao_terminar(): void
    {
        $admin = $this->criarAdmin();

        Cache::put(ConsolidarMesFechamentoJob::statusCacheKeyFor('2026-08'), [
            'status'       => 'failed',
            'mes'          => '2026-08',
            'started_at'   => '2026-09-16T13:12:00+00:00',
            'completed_at' => '2026-09-16T13:14:49+00:00',
            'error'        => 'A Adman devolveu erro no meio do cálculo.',
        ], now()->addHour());

        $r = $this->andamento($admin);

        $r->assertOk();
        $r->assertJson([
            'status' => 'failed',
            'error'  => 'A Adman devolveu erro no meio do cálculo.',
        ]);
    }

    #[Test]
    public function andamento_sem_mes_ou_com_mes_invalido_e_422(): void
    {
        $admin = $this->criarAdmin();

        $this->actingAs($admin)
            ->getJson('/administrativo/financeiro/competencia/refazer/status')
            ->assertStatus(422);

        $this->andamento($admin, 'agosto')->assertStatus(422);
    }

    #[Test]
    public function andamento_para_nao_admin_e_403(): void
    {
        $this->andamento($this->criarNaoAdmin())->assertStatus(403);
    }

    #[Test]
    public function a_rota_de_andamento_esta_registrada_no_grupo_administrativo(): void
    {
        $rotas = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->map(fn ($rota) => $rota->getName())
            ->filter()
            ->values()
            ->all();

        $this->assertContains('admin.financeiro.competencia.refazer.status', $rotas);
    }
}
