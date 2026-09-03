<?php

namespace Tests\Feature\Phase137;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 06 — Tarefa 3: endpoints de fechar/refazer competência
 * (D-11/D-12) e o registro das rotas novas dentro do grupo administrativo.
 *
 * Toda asserção de efeito é por RECONSULTA às tabelas (`DB::table`), nunca
 * por inspeção isolada da resposta HTTP — disciplina registrada em
 * `.planning/learnings/desempenho-bonificacao.md` §4.
 */
class Phase137CompetenciaEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function criarNaoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor', 'active' => true]);
    }

    /**
     * A migration `2026_09_02_100003_seed_faixas_faturamento_iniciais` já
     * semeia as 7 faixas de "Gestão" dentro do RefreshDatabase — só falta
     * marcar `plataforma`/`setor` (mesmo padrão de
     * Phase137ConsolidarMesTest::criarServicoGestao()).
     */
    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    /** Empresa com contrato ativo no serviço informado + faturamento no mês. */
    private function criarEmpresaComFaturamento(Servico $servico, string $mesReferencia = '2026-08-10', float $revenue = 300_000.00): Company
    {
        $company = Company::factory()->create(['adman_account_id' => 'cust-'.uniqid()]);

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id' => $company->id,
            'ativo'      => true,
        ]);

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => $mesReferencia,
            'revenue'        => $revenue,
        ]);

        return $company;
    }

    // ═══ Fechar competência ═══════════════════════════════════════════

    #[Test]
    public function post_fechar_com_mes_valido_grava_snapshot_e_devolve_200(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin   = $this->criarAdmin();
        $servico = $this->criarServicoGestao();
        $company = $this->criarEmpresaComFaturamento($servico);

        $r = $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/fechar', ['mes' => '2026-08']);

        $r->assertOk();
        $this->assertSame(
            1,
            DB::table('fechamento_snapshots')
                ->where('company_id', $company->id)
                ->whereDate('mes_referencia', '2026-08-01')
                ->where('origem', 'consolidar_mes')
                ->count()
        );
    }

    #[Test]
    public function post_fechar_competencia_ja_fechada_devolve_409_e_nao_altera_o_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin   = $this->criarAdmin();
        $servico = $this->criarServicoGestao();
        $company = $this->criarEmpresaComFaturamento($servico);

        $r1 = $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/fechar', ['mes' => '2026-08']);
        $r1->assertOk();

        $faturamentoAntes = DB::table('fechamento_snapshots')
            ->where('company_id', $company->id)
            ->whereDate('mes_referencia', '2026-08-01')
            ->value('faturamento_total');

        // 2ª tentativa de fechar a MESMA competência sem --motivo — a
        // trava do writer recusa (RuntimeException → comando sai com
        // FAILURE → controller devolve 409).
        $r2 = $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/fechar', ['mes' => '2026-08']);
        $r2->assertStatus(409);
        $r2->assertJsonFragment([
            'message' => 'Não foi possível fechar Agosto 2026. Nada foi alterado — tente novamente ou avise o time técnico.',
        ]);

        $faturamentoDepois = DB::table('fechamento_snapshots')
            ->where('company_id', $company->id)
            ->whereDate('mes_referencia', '2026-08-01')
            ->value('faturamento_total');

        $this->assertEquals((float) $faturamentoAntes, (float) $faturamentoDepois);
    }

    #[Test]
    public function post_fechar_com_mes_no_futuro_devolve_422(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin = $this->criarAdmin();

        $r = $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/fechar', ['mes' => '2026-12']);

        $r->assertStatus(422);
        $this->assertSame(0, DB::table('fechamento_snapshots')->count());
    }

    #[Test]
    public function post_fechar_com_mes_fora_do_formato_devolve_422(): void
    {
        $admin = $this->criarAdmin();

        $r1 = $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/fechar', ['mes' => '2026-8']);
        $r1->assertStatus(422);

        $r2 = $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/fechar', ['mes' => 'agosto/2026']);
        $r2->assertStatus(422);

        $r3 = $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/fechar', []);
        $r3->assertStatus(422);
    }

    #[Test]
    public function nao_admin_recebe_403_ao_fechar(): void
    {
        $naoAdmin = $this->criarNaoAdmin();

        $r = $this->actingAs($naoAdmin)
            ->postJson('/administrativo/financeiro/competencia/fechar', ['mes' => '2026-08']);

        $r->assertStatus(403);
        $this->assertSame(0, DB::table('fechamento_snapshots')->count());
    }

    // ═══ Refazer competência ══════════════════════════════════════════

    #[Test]
    public function post_refazer_sem_motivo_devolve_422(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin   = $this->criarAdmin();
        $servico = $this->criarServicoGestao();
        $this->criarEmpresaComFaturamento($servico);

        $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/fechar', ['mes' => '2026-08'])
            ->assertOk();

        $r = $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/refazer', ['mes' => '2026-08']);

        $r->assertStatus(422);
        $this->assertSame(0, DB::table('fechamento_reconsolidacoes')->count());
    }

    #[Test]
    public function post_refazer_com_motivo_grava_reconsolidacao_com_autor_e_devolve_200(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin   = $this->criarAdmin();
        $servico = $this->criarServicoGestao();
        $company = $this->criarEmpresaComFaturamento($servico);

        $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/fechar', ['mes' => '2026-08'])
            ->assertOk();

        $r = $this->actingAs($admin)
            ->postJson('/administrativo/financeiro/competencia/refazer', [
                'mes'    => '2026-08',
                'motivo' => 'Adman corrigiu faturamento na origem após o fechamento.',
            ]);

        $r->assertOk();

        $this->assertSame(1, DB::table('fechamento_reconsolidacoes')->whereDate('mes_referencia', '2026-08-01')->count());
        $reconsolidacao = DB::table('fechamento_reconsolidacoes')->whereDate('mes_referencia', '2026-08-01')->first();
        $this->assertSame($admin->id, $reconsolidacao->reconsolidado_por);

        // Confirma que a empresa ainda tem exatamente 1 snapshot na
        // competência (upsert, não duplicação).
        $this->assertSame(
            1,
            DB::table('fechamento_snapshots')->where('company_id', $company->id)->whereDate('mes_referencia', '2026-08-01')->count()
        );
    }

    #[Test]
    public function nao_admin_recebe_403_ao_refazer(): void
    {
        $naoAdmin = $this->criarNaoAdmin();

        $r = $this->actingAs($naoAdmin)
            ->postJson('/administrativo/financeiro/competencia/refazer', [
                'mes'    => '2026-08',
                'motivo' => 'Motivo qualquer com mais de dez caracteres.',
            ]);

        $r->assertStatus(403);
        $this->assertSame(0, DB::table('fechamento_reconsolidacoes')->count());
    }

    // ═══ Registro das rotas ═══════════════════════════════════════════

    #[Test]
    public function as_5_rotas_novas_de_financeiro_estao_registradas(): void
    {
        $rotas = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->filter(fn ($rota) => str_starts_with((string) $rota->getName(), 'admin.financeiro'))
            ->map(fn ($rota) => $rota->getName())
            ->values()
            ->all();

        foreach ([
            'admin.financeiro.competencia.fechar',
            'admin.financeiro.competencia.refazer',
            'admin.financeiro.faixas.servico',
            'admin.financeiro.faixas.empresa',
            'admin.financeiro.faixas.empresa.remover',
        ] as $nomeEsperado) {
            $this->assertContains($nomeEsperado, $rotas, "Rota '{$nomeEsperado}' não foi registrada.");
        }

        // As 7 rotas antigas continuam presentes (nada foi removido).
        foreach ([
            'admin.financeiro',
            'admin.financeiro.relatorio.geral',
            'admin.financeiro.relatorio.enviar',
            'admin.financeiro.sync',
            'admin.financeiro.update',
            'admin.financeiro.recebido',
            'admin.financeiro.relatorio',
        ] as $nomeAntigo) {
            $this->assertContains($nomeAntigo, $rotas, "Rota antiga '{$nomeAntigo}' foi removida ou renomeada.");
        }
    }
}
