<?php

namespace Tests\Feature\Phase19;

use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorAcao;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 19 W3-T2 — Testes do comando `sugadores:limpar-orfaos`.
 *
 * Verifica:
 *  - Dry-run (sem --apply) não modifica nada.
 *  - --apply marca antigos como auto_resolvido e cria audit log.
 *  - STATUS_TRAVADOS (em_acao) não são tocados mesmo sendo antigos.
 *  - Sugadores de HOJE não são afetados.
 */
class LimparOrfaosSugadoresTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = Carbon::parse('2026-06-03')->startOfDay();
        Carbon::setTestNow($this->hoje);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function company(string $name = 'Empresa Teste'): Company
    {
        return Company::create([
            'name'   => $name,
            'cnpj'   => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'active' => true,
        ]);
    }

    private function seedSugador(Company $c, string $status, Carbon $referenceDate, string $ag = 'AG1'): Sugador
    {
        return Sugador::create([
            'company_id'           => $c->id,
            'reference_date'       => $referenceDate->toDateString(),
            'tipo'                 => Sugador::TIPO_ADGROUP,
            'campaign_id'          => 'C1',
            'campaign_name'        => 'Camp C1',
            'adgroup_id'           => $ag,
            'adgroup_name'         => 'AG ' . $ag,
            'periodo_inicio'       => $referenceDate->copy()->subDays(30)->toDateString(),
            'periodo_fim'          => $referenceDate->copy()->subDay()->toDateString(),
            'investimento_periodo' => 100,
            'faturamento_periodo'  => 0,
            'vendas_periodo'       => 0,
            'cliques'              => 0,
            'impressoes'           => 0,
            'motivos'              => ['gasto_sem_venda'],
            'status'               => $status,
        ]);
    }

    /**
     * Dry-run (sem --apply) imprime sumário mas não altera nenhum registro.
     */
    public function test_dry_run_nao_aplica_mudancas(): void
    {
        $empresa = $this->company();
        $antigo1 = $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDay(), 'AG1');
        $antigo2 = $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDays(2), 'AG2');

        // Snapshot antes
        $statusAntes = Sugador::whereIn('id', [$antigo1->id, $antigo2->id])->pluck('status', 'id');

        Artisan::call('sugadores:limpar-orfaos');

        // Nenhum status mudou
        $statusDepois = Sugador::whereIn('id', [$antigo1->id, $antigo2->id])->pluck('status', 'id');
        $this->assertEquals($statusAntes, $statusDepois);

        // Nenhuma SugadorAcao criada
        $this->assertEquals(0, SugadorAcao::count());

        // Output contém "Dry-run" e a contagem
        $output = Artisan::output();
        $this->assertStringContainsString('Dry-run', $output);
        $this->assertStringContainsString('2', $output);
    }

    /**
     * --apply marca os antigos como auto_resolvido e insere audit log.
     */
    public function test_apply_marca_antigos_como_auto_resolvido(): void
    {
        $empresa = $this->company();
        $antigo1 = $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDay(), 'AG1');
        $antigo2 = $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDays(2), 'AG2');

        Artisan::call('sugadores:limpar-orfaos', ['--apply' => true]);

        // Ambos viraram auto_resolvido
        $antigo1->refresh();
        $antigo2->refresh();
        $this->assertEquals(Sugador::STATUS_AUTO_RESOLVIDO, $antigo1->status);
        $this->assertEquals(Sugador::STATUS_AUTO_RESOLVIDO, $antigo2->status);
        $this->assertNotNull($antigo1->resolvido_em);
        $this->assertNotNull($antigo2->resolvido_em);
        $this->assertNull($antigo1->resolvido_por);
        $this->assertNull($antigo2->resolvido_por);

        // Audit log: 2 registros com acao=limpeza_orfaos
        $this->assertEquals(2, SugadorAcao::count());
        $acoes = SugadorAcao::all();
        foreach ($acoes as $acao) {
            $this->assertEquals(SugadorAcao::ACAO_LIMPEZA_ORFAOS, $acao->acao);
            $this->assertEquals(Sugador::STATUS_PENDENTE, $acao->status_anterior);
            $this->assertEquals(Sugador::STATUS_AUTO_RESOLVIDO, $acao->status_novo);
            $this->assertNull($acao->user_id);
        }
    }

    /**
     * STATUS_TRAVADOS e auto_resolvido antigos não são tocados pelo comando.
     */
    public function test_status_travados_nao_sao_tocados(): void
    {
        $empresa = $this->company();

        // Sugador em_acao antigo — STATUS_TRAVADOS — NÃO deve ser alterado
        $emAcao = $this->seedSugador($empresa, Sugador::STATUS_EM_ACAO, $this->hoje->copy()->subDay(), 'AG1');
        // Sugador já auto_resolvido antigo — NÃO deve criar ação duplicada
        $autoResolvido = $this->seedSugador($empresa, Sugador::STATUS_AUTO_RESOLVIDO, $this->hoje->copy()->subDay(), 'AG2');
        // Sugador pendente antigo — ESTE deve ser afetado
        $pendente = $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDay(), 'AG3');

        Artisan::call('sugadores:limpar-orfaos', ['--apply' => true]);

        // em_acao permanece em_acao
        $emAcao->refresh();
        $this->assertEquals(Sugador::STATUS_EM_ACAO, $emAcao->status);

        // auto_resolvido permanece auto_resolvido (sem nova ação)
        $autoResolvido->refresh();
        $this->assertEquals(Sugador::STATUS_AUTO_RESOLVIDO, $autoResolvido->status);

        // Apenas 1 SugadorAcao criada (para o pendente)
        $this->assertEquals(1, SugadorAcao::count());
        $this->assertEquals($pendente->id, SugadorAcao::first()->sugador_id);
    }

    /**
     * Sugadores pendentes de HOJE não são afetados pelo comando.
     */
    public function test_hoje_nao_e_tocado(): void
    {
        $empresa = $this->company();

        // Pendente de HOJE — não deve ser afetado
        $hoje = $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje, 'AG1');
        // Pendente de ontem — deve ser afetado
        $antigo = $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDay(), 'AG2');

        Artisan::call('sugadores:limpar-orfaos', ['--apply' => true]);

        // HOJE permanece pendente
        $hoje->refresh();
        $this->assertEquals(Sugador::STATUS_PENDENTE, $hoje->status);

        // ONTEM virou auto_resolvido
        $antigo->refresh();
        $this->assertEquals(Sugador::STATUS_AUTO_RESOLVIDO, $antigo->status);

        // Nenhuma SugadorAcao para o sugador de HOJE
        $acaoHoje = SugadorAcao::where('sugador_id', $hoje->id)->first();
        $this->assertNull($acaoHoje);
    }
}
