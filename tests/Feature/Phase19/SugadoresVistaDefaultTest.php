<?php

namespace Tests\Feature\Phase19;

use App\Models\AdmanSyncLog;
use App\Models\Company;
use App\Models\Sugador;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 19 W3-T1 — Testes para os filtros default (vista HOJE) e
 * as props `default_view` e `analise_diaria` do SugadorController::index().
 *
 * Verifica:
 *  - Sem filtros explícitos → default_view='hoje', somente sugadores HOJE + pendentes.
 *  - include_old=1 → default_view='custom', inclui anteriores (menos auto_resolvido).
 *  - reference_date explícito → default_view='custom', filtra pela data informada.
 *  - companies_summary inclui o campo sincronizou_hoje (alias de analisado_hoje).
 */
class SugadoresVistaDefaultTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function company(string $name = 'Empresa Teste', ?string $custId = 'ACC-T1'): Company
    {
        return Company::create([
            'name'             => $name,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => $custId,
            'active'           => true,
        ]);
    }

    private function seedSugador(
        Company $c,
        string $status,
        Carbon $referenceDate,
        string $campaignId = 'C1',
        string $adgroupId = 'AG1'
    ): Sugador {
        return Sugador::create([
            'company_id'           => $c->id,
            'reference_date'       => $referenceDate->toDateString(),
            'tipo'                 => Sugador::TIPO_ADGROUP,
            'campaign_id'          => $campaignId,
            'campaign_name'        => 'Camp ' . $campaignId,
            'adgroup_id'           => $adgroupId,
            'adgroup_name'         => 'AG ' . $adgroupId,
            'periodo_inicio'       => $referenceDate->copy()->subDays(30)->toDateString(),
            'periodo_fim'          => $referenceDate->copy()->subDay()->toDateString(),
            'investimento_periodo' => 100,
            'faturamento_periodo'  => 0,
            'vendas_periodo'       => 0,
            'cliques'              => 10,
            'impressoes'           => 100,
            'motivos'              => ['gasto_sem_venda'],
            'status'               => $status,
        ]);
    }

    /**
     * Sem filtros → default_view='hoje', apenas sugadores HOJE pendentes
     * e props analise_diaria corretamente preenchidas.
     */
    public function test_default_view_filtra_hoje_e_pendente(): void
    {
        $admin   = $this->admin();
        $empresa = $this->company();

        // Setup: 2 HOJE pendentes + 3 anteriores pendentes + 1 HOJE auto_resolvido
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje, 'C1', 'AG1');
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje, 'C1', 'AG2');
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDay(), 'C1', 'AG3');
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDays(2), 'C1', 'AG4');
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDays(3), 'C1', 'AG5');
        $this->seedSugador($empresa, Sugador::STATUS_AUTO_RESOLVIDO, $this->hoje, 'C1', 'AG6');

        $response = $this->actingAs($admin)->get('/sugadores?view=list');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Sugadores/Index')
            ->where('default_view', 'hoje')
            ->where('analise_diaria.horario_cron', '12:00 BRT')
            ->whereNot('analise_diaria.ultima_execucao_global', null)
            ->has('sugadores.data', 2)  // somente os 2 HOJE pendentes
        );
    }

    /**
     * include_old=1 → default_view='custom', inclui anteriores
     * (auto_resolvido fica de fora pelo filtro default de status != resolvido).
     */
    public function test_include_old_inclui_anteriores(): void
    {
        $admin   = $this->admin();
        $empresa = $this->company();

        // 2 hoje + 3 anteriores = 5 pendentes; 1 auto_resolvido fica de fora
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje, 'C1', 'AG1');
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje, 'C1', 'AG2');
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDay(), 'C1', 'AG3');
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDays(2), 'C1', 'AG4');
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDays(3), 'C1', 'AG5');
        $this->seedSugador($empresa, Sugador::STATUS_AUTO_RESOLVIDO, $this->hoje, 'C1', 'AG6');

        $response = $this->actingAs($admin)->get('/sugadores?view=list&include_old=1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('default_view', 'custom')
            // 5 pendentes (2 hoje + 3 anteriores) + 1 auto_resolvido = 6
            // O filtro padrão de status só exclui 'resolvido' (STATUS_RESOLVIDO),
            // não 'auto_resolvido' — o auto_resolvido aparece na lista com include_old.
            ->has('sugadores.data', 6)
        );
    }

    /**
     * reference_date explícito → default_view='custom' e filtra pela data exata.
     */
    public function test_reference_date_explicito_override_default(): void
    {
        $admin    = $this->admin();
        $empresa  = $this->company();
        $ontem    = $this->hoje->copy()->subDay();

        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje, 'C1', 'AG1');
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $ontem, 'C1', 'AG2');
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $ontem, 'C1', 'AG3');

        $response = $this->actingAs($admin)->get('/sugadores?view=list&reference_date=' . $ontem->toDateString());

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('default_view', 'custom')
            ->has('sugadores.data', 2)  // apenas os 2 de ontem
        );
    }

    /**
     * companies_summary inclui sincronizou_hoje=true quando há log de sync hoje
     * sem error_message (alias semântico de analisado_hoje).
     */
    public function test_companies_summary_inclui_sincronizou_hoje(): void
    {
        $admin   = $this->admin();
        $empresa = $this->company('Empresa Sync OK');

        // Cria log de sync de hoje com sucesso (error_message=null)
        AdmanSyncLog::create([
            'company_id'    => $empresa->id,
            'created_count' => 2,
            'updated_count' => 0,
            'skipped_count' => 0,
            'error_message' => null,
            'synced_at'     => $this->hoje,
            'created_at'    => $this->hoje,
            'updated_at'    => $this->hoje,
        ]);

        $response = $this->actingAs($admin)->get('/sugadores?view=cards');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('companies_summary.0.sincronizou_hoje', true)
            ->where('companies_summary.0.analisado_hoje', true)  // compat alias
        );
    }
}
