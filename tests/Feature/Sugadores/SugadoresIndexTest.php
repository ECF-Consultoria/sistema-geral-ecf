<?php

namespace Tests\Feature\Sugadores;

use App\Models\AdmanSyncLog;
use App\Models\Company;
use App\Models\Sugador;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 15 (W2-T1) — Cobre a prop `companies_summary` da vista de cards
 * em `SugadorController::index`.
 *
 * Verifica:
 *  - Endpoint retorna prop `companies_summary` como array.
 *  - Empresa sem sugadores aparece com counts zerados (LEFT JOIN lógico).
 *  - Ordenação: count_hoje DESC, total_pendentes DESC, name ASC.
 *  - Status `auto_resolvido` NÃO conta em `count_hoje` nem `total_pendentes`.
 *  - `view_mode` default = 'cards'.
 *  - Query é agregada (sem N+1) — limite folgado de 12 queries totais no index.
 */
class SugadoresIndexTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        // Data fixa pra facilitar asserts de `reference_date = hoje`.
        $this->hoje = Carbon::parse('2026-05-27')->startOfDay();
        Carbon::setTestNow($this->hoje);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Cria admin (visão global) — usa factory padrão. */
    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Cria empresa ativa. */
    private function company(string $name, ?string $custId = 'ACC-X'): Company
    {
        return Company::create([
            'name'             => $name,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => $custId,
            'active'           => true,
        ]);
    }

    /** Helper: cria sugador minimamente preenchido pra contar nas agregações. */
    private function seedSugador(Company $c, string $status, Carbon $referenceDate, string $campaignId = 'C1', string $adgroupId = 'AG1'): Sugador
    {
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

    public function test_index_retorna_companies_summary_como_array(): void
    {
        $admin = $this->admin();
        $this->company('Empresa A');

        $response = $this->actingAs($admin)->get('/sugadores');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Sugadores/Index')
            ->has('companies_summary')
            ->where('companies_summary.0.name', 'Empresa A')
        );
    }

    public function test_empresa_sem_sugadores_aparece_com_counts_zerados(): void
    {
        $admin = $this->admin();
        $this->company('Empresa Vazia');

        $response = $this->actingAs($admin)->get('/sugadores');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('companies_summary.0.name', 'Empresa Vazia')
            ->where('companies_summary.0.count_hoje', 0)
            ->where('companies_summary.0.total_pendentes', 0)
            ->where('companies_summary.0.ultima_analise', null)
            ->where('companies_summary.0.can_analyze', true)
        );
    }

    public function test_ordenacao_count_hoje_desc_total_desc_nome_asc(): void
    {
        $admin = $this->admin();

        // Empresa Z — 3 pendentes hoje, 5 acumulados → primeiro.
        $z = $this->company('Z Empresa');
        for ($i = 0; $i < 3; $i++) {
            $this->seedSugador($z, Sugador::STATUS_PENDENTE, $this->hoje, 'CZ', 'AGZ' . $i);
        }
        // 2 pendentes antigos (sem reference_date = hoje) pra inflar total_pendentes.
        $this->seedSugador($z, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDays(5), 'CZ', 'AGZ-OLD-1');
        $this->seedSugador($z, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDays(5), 'CZ', 'AGZ-OLD-2');

        // Empresa A — 3 pendentes hoje, 3 acumulados → empata em count_hoje mas
        // perde em total_pendentes → fica em 2º.
        $a = $this->company('A Empresa');
        for ($i = 0; $i < 3; $i++) {
            $this->seedSugador($a, Sugador::STATUS_PENDENTE, $this->hoje, 'CA', 'AGA' . $i);
        }

        // Empresa B — 1 pendente hoje, 1 acumulado.
        $b = $this->company('B Empresa');
        $this->seedSugador($b, Sugador::STATUS_PENDENTE, $this->hoje, 'CB', 'AGB1');

        // Empresa C — sem sugadores. Empata em 0 com D — desempate por nome ASC.
        $this->company('C Empresa');
        $this->company('D Empresa');

        $response = $this->actingAs($admin)->get('/sugadores');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('companies_summary.0.name', 'Z Empresa')
            ->where('companies_summary.0.count_hoje', 3)
            ->where('companies_summary.0.total_pendentes', 5)
            ->where('companies_summary.1.name', 'A Empresa')
            ->where('companies_summary.1.count_hoje', 3)
            ->where('companies_summary.1.total_pendentes', 3)
            ->where('companies_summary.2.name', 'B Empresa')
            ->where('companies_summary.2.count_hoje', 1)
            ->where('companies_summary.3.name', 'C Empresa')
            ->where('companies_summary.4.name', 'D Empresa')
        );
    }

    public function test_auto_resolvido_nao_conta_em_count_hoje_nem_total_pendentes(): void
    {
        $admin = $this->admin();
        $empresa = $this->company('Empresa Auto-Resolve');

        // 1 pendente hoje, 1 auto_resolvido hoje, 1 auto_resolvido antigo,
        // 1 resolvido hoje (não deve contar tampouco).
        $this->seedSugador($empresa, Sugador::STATUS_PENDENTE, $this->hoje, 'C1', 'AG1');
        $this->seedSugador($empresa, Sugador::STATUS_AUTO_RESOLVIDO, $this->hoje, 'C1', 'AG2');
        $this->seedSugador($empresa, Sugador::STATUS_AUTO_RESOLVIDO, $this->hoje->copy()->subDay(), 'C1', 'AG3');
        $this->seedSugador($empresa, Sugador::STATUS_RESOLVIDO, $this->hoje, 'C1', 'AG4');

        $response = $this->actingAs($admin)->get('/sugadores');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('companies_summary.0.name', 'Empresa Auto-Resolve')
            ->where('companies_summary.0.count_hoje', 1)
            ->where('companies_summary.0.total_pendentes', 1)
        );
    }

    public function test_view_mode_default_eh_cards(): void
    {
        $admin = $this->admin();
        $this->company('Qualquer');

        $response = $this->actingAs($admin)->get('/sugadores');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('view_mode', 'cards'));
    }

    public function test_view_mode_aceita_list_via_query_string(): void
    {
        $admin = $this->admin();
        $this->company('Qualquer');

        $response = $this->actingAs($admin)->get('/sugadores?view=list');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('view_mode', 'list'));
    }

    public function test_view_mode_invalido_volta_para_cards(): void
    {
        $admin = $this->admin();
        $this->company('Qualquer');

        $response = $this->actingAs($admin)->get('/sugadores?view=qualquer-coisa');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('view_mode', 'cards'));
    }

    // ─── Phase 16 W3-T1: bloqueio do "Reanalisar" via flag `analisado_hoje` ──────
    // Empresa com sync Adman bem-sucedido HOJE não deve aceitar reanálise
    // (cadência D-1 — não há dado novo até o próximo ciclo).
    public function test_analisado_hoje_true_quando_log_de_sucesso_hoje(): void
    {
        $admin   = $this->admin();
        $empresa = $this->company('Empresa Analisada');

        // Sync com sucesso hoje (`error_message` null) → bloqueia botão.
        AdmanSyncLog::create([
            'company_id'    => $empresa->id,
            'created_count' => 3,
            'updated_count' => 2,
            'skipped_count' => 0,
            'error_message' => null,
            'synced_at'     => $this->hoje,
            'created_at'    => $this->hoje,
            'updated_at'    => $this->hoje,
        ]);

        $response = $this->actingAs($admin)->get('/sugadores');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('companies_summary.0.name', 'Empresa Analisada')
            ->where('companies_summary.0.analisado_hoje', true)
        );
    }

    public function test_analisado_hoje_false_sem_log_hoje(): void
    {
        $admin   = $this->admin();
        $empresa = $this->company('Empresa Ontem');

        // Sync de ONTEM (não conta como "rodado hoje"). `created_at` é auto-gerenciado
        // pelo Eloquent — viajamos o Carbon pra ontem só pra criar o log e voltamos.
        Carbon::setTestNow($this->hoje->copy()->subDay());
        AdmanSyncLog::create([
            'company_id'    => $empresa->id,
            'created_count' => 1,
            'updated_count' => 0,
            'skipped_count' => 0,
            'error_message' => null,
            'synced_at'     => Carbon::now(),
        ]);
        Carbon::setTestNow($this->hoje);

        $response = $this->actingAs($admin)->get('/sugadores');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('companies_summary.0.name', 'Empresa Ontem')
            ->where('companies_summary.0.analisado_hoje', false)
        );
    }

    public function test_analisado_hoje_false_quando_log_hoje_com_erro(): void
    {
        $admin   = $this->admin();
        $empresa = $this->company('Empresa Sync Falhou');

        // Sync de hoje COM erro → não conta; permite retry manual.
        AdmanSyncLog::create([
            'company_id'    => $empresa->id,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'error_message' => 'HTTP 429 rate limit',
            'synced_at'     => $this->hoje,
            'created_at'    => $this->hoje,
            'updated_at'    => $this->hoje,
        ]);

        $response = $this->actingAs($admin)->get('/sugadores');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('companies_summary.0.name', 'Empresa Sync Falhou')
            ->where('companies_summary.0.analisado_hoje', false)
        );
    }

    public function test_agregacao_nao_dispara_N_mais_um(): void
    {
        $admin = $this->admin();

        // 10 empresas, cada uma com 2 sugadores — sem agregação seriam 10 queries
        // extras só pra contar. Esperamos teto folgado de ~12 queries totais.
        for ($i = 1; $i <= 10; $i++) {
            $c = $this->company("Empresa $i", "ACC-$i");
            $this->seedSugador($c, Sugador::STATUS_PENDENTE, $this->hoje, "C$i", "AG$i-a");
            $this->seedSugador($c, Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDays(2), "C$i", "AG$i-b");
        }

        DB::enableQueryLog();

        $response = $this->actingAs($admin)->get('/sugadores');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();
        // Limite folgado pra absorver sessão, paginação, agregação, etc.
        // Phase 19: +1 query para MAX(created_at) de analise_diaria — total esperado = 16.
        $this->assertLessThanOrEqual(
            16,
            count($queries),
            'Index dispara mais queries do que o esperado — possível N+1.'
        );
    }
}
