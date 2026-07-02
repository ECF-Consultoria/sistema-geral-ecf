<?php

namespace Tests\Feature\Phase54;

use App\Models\Company;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\Sugador;
use App\Models\User;
use App\Support\Permissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 54 Plan 54-01 (B1) — Filtro de periodo em /sugadores/empresa/{id}.
 *
 * CONTEXT §B1 locka:
 *  - Presets: hoje (default), 7d, 30d, todos
 *  - Backend filtra reference_date via whereDate(hoje) OU where >= now->subDays(N)
 *  - Param invalido cai no default hoje (seguranca contra query manipulation)
 *  - Prop `periodo` (echo) + `periodo_presets` (lista de opcoes) propagados
 *  - Regressao: analista fora da carteira continua recebendo 403
 *
 * OBS: RESEARCH §4 e PLAN.md fixam o filtro em `reference_date` (não `detected_at`
 * — a coluna real da tabela sugadores é `reference_date`, ja usado por
 * SugadorController::index linha 109 como pattern canônico).
 */
class SugadorPorEmpresaPeriodoFilterTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        // Fixa "hoje" — sugadores criados usam esta data como referência.
        $this->hoje = Carbon::parse('2026-07-02')->startOfDay();
        Carbon::setTestNow($this->hoje->copy()->setTime(10, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresa(string $suffix = 'E1'): Company
    {
        return Company::create([
            'name'             => 'Empresa ' . $suffix,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'CID-' . $suffix,
            'active'           => true,
        ]);
    }

    /**
     * Cria sugador com reference_date parametrizada. Aceita Carbon ou string.
     */
    private function criarSugador(
        Company $company,
        $refDate,
        string $status = Sugador::STATUS_PENDENTE,
        ?string $adgroupId = null,
    ): Sugador {
        $refCarbon = $refDate instanceof Carbon
            ? $refDate
            : Carbon::parse($refDate);
        $adgroupId ??= 'AG-' . uniqid();

        return Sugador::create([
            'company_id'           => $company->id,
            'reference_date'       => $refCarbon->toDateString(),
            'tipo'                 => Sugador::TIPO_ADGROUP,
            'campaign_id'          => 'CAMP-' . $adgroupId,
            'campaign_name'        => 'Camp ' . $adgroupId,
            'adgroup_id'           => $adgroupId,
            'adgroup_name'         => 'Adgroup ' . $adgroupId,
            'periodo_inicio'       => $refCarbon->copy()->subDays(7)->toDateString(),
            'periodo_fim'          => $refCarbon->copy()->subDay()->toDateString(),
            'investimento_periodo' => 100,
            'faturamento_periodo'  => 0,
            'vendas_periodo'       => 0,
            'cliques'              => 0,
            'impressoes'           => 0,
            'motivos'              => ['gasto_sem_venda'],
            'status'               => $status,
        ]);
    }

    private function userComCoreSugadores(string $slugPrefix = 'analista', string $role = 'consultor'): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor ' . $slugPrefix,
            'slug'   => $slugPrefix . '-' . uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => Permissions::CORE_SUGADORES,
        ]);
        $user = User::factory()->create(['role' => $role]);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);
        return $user;
    }

    private function extractInertiaPage($response): array
    {
        $html = $response->getContent();
        $ok   = preg_match('/data-page="([^"]+)"/', $html, $m);
        $this->assertSame(1, $ok, 'Nao encontrou data-page no HTML da response Inertia.');
        $json = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $arr  = json_decode($json, true);
        $this->assertIsArray($arr, 'data-page nao decodificou como JSON valido.');
        return $arr;
    }

    // ─── Testes ─────────────────────────────────────────────────────────────

    /**
     * Cenário 1 — Sem ?periodo (default hoje): apenas sugadores com
     * reference_date = hoje aparecem.
     */
    public function test_default_periodo_hoje_filtra_apenas_sugadores_do_dia(): void
    {
        $admin = $this->admin();
        $emp   = $this->empresa('P1');

        $sHoje    = $this->criarSugador($emp, $this->hoje, Sugador::STATUS_PENDENTE, 'HOJE');
        $sOntem   = $this->criarSugador($emp, $this->hoje->copy()->subDay(), Sugador::STATUS_PENDENTE, 'ONTEM');
        $sAntigo  = $this->criarSugador($emp, $this->hoje->copy()->subDays(10), Sugador::STATUS_PENDENTE, 'ANTIGO');

        $response = $this->actingAs($admin)
            ->get(route('sugadores.empresa.listagem', $emp->id));
        $response->assertOk();

        $props = $this->extractInertiaPage($response)['props'];

        $ids = array_column($props['sugadores'], 'id');
        $this->assertSame([$sHoje->id], $ids, 'Default hoje deveria trazer só o de hoje.');
    }

    /**
     * Cenário 2 — ?periodo=7d: hoje + ontem entram; 10 dias atrás sai.
     */
    public function test_periodo_7d_traz_ultima_semana(): void
    {
        $admin = $this->admin();
        $emp   = $this->empresa('P7');

        $sHoje    = $this->criarSugador($emp, $this->hoje, Sugador::STATUS_PENDENTE, 'HOJE');
        $sOntem   = $this->criarSugador($emp, $this->hoje->copy()->subDay(), Sugador::STATUS_PENDENTE, 'ONTEM');
        $sAntigo  = $this->criarSugador($emp, $this->hoje->copy()->subDays(10), Sugador::STATUS_PENDENTE, 'ANTIGO');

        $response = $this->actingAs($admin)
            ->get(route('sugadores.empresa.listagem', ['company' => $emp->id, 'periodo' => '7d']));
        $response->assertOk();

        $props = $this->extractInertiaPage($response)['props'];

        $ids = array_column($props['sugadores'], 'id');
        sort($ids);
        $expected = [$sHoje->id, $sOntem->id];
        sort($expected);
        $this->assertSame($expected, $ids, '7d: hoje + ontem, sem o de 10 dias.');
    }

    /**
     * Cenário 3 — ?periodo=30d: hoje + ontem + 10d entram; 45d sai.
     */
    public function test_periodo_30d_traz_ultimo_mes(): void
    {
        $admin = $this->admin();
        $emp   = $this->empresa('P30');

        $sHoje   = $this->criarSugador($emp, $this->hoje, Sugador::STATUS_PENDENTE, 'HOJE');
        $sOntem  = $this->criarSugador($emp, $this->hoje->copy()->subDay(), Sugador::STATUS_PENDENTE, 'ONTEM');
        $s10d    = $this->criarSugador($emp, $this->hoje->copy()->subDays(10), Sugador::STATUS_PENDENTE, 'D10');
        $s45d    = $this->criarSugador($emp, $this->hoje->copy()->subDays(45), Sugador::STATUS_PENDENTE, 'D45');

        $response = $this->actingAs($admin)
            ->get(route('sugadores.empresa.listagem', ['company' => $emp->id, 'periodo' => '30d']));
        $response->assertOk();

        $props = $this->extractInertiaPage($response)['props'];

        $ids = array_column($props['sugadores'], 'id');
        sort($ids);
        $expected = [$sHoje->id, $sOntem->id, $s10d->id];
        sort($expected);
        $this->assertSame($expected, $ids, '30d: hoje + ontem + 10d, sem 45d.');
        $this->assertNotContains($s45d->id, $ids);
    }

    /**
     * Cenário 4 — ?periodo=todos: SEM filtro de data — todos pendente/em_acao.
     */
    public function test_periodo_todos_nao_aplica_filtro_data(): void
    {
        $admin = $this->admin();
        $emp   = $this->empresa('PTD');

        $sHoje  = $this->criarSugador($emp, $this->hoje, Sugador::STATUS_PENDENTE, 'HOJE');
        $sOntem = $this->criarSugador($emp, $this->hoje->copy()->subDay(), Sugador::STATUS_PENDENTE, 'ONTEM');
        $s10d   = $this->criarSugador($emp, $this->hoje->copy()->subDays(10), Sugador::STATUS_EM_ACAO, 'D10');
        $s45d   = $this->criarSugador($emp, $this->hoje->copy()->subDays(45), Sugador::STATUS_PENDENTE, 'D45');

        $response = $this->actingAs($admin)
            ->get(route('sugadores.empresa.listagem', ['company' => $emp->id, 'periodo' => 'todos']));
        $response->assertOk();

        $props = $this->extractInertiaPage($response)['props'];

        $ids = array_column($props['sugadores'], 'id');
        sort($ids);
        $expected = [$sHoje->id, $sOntem->id, $s10d->id, $s45d->id];
        sort($expected);
        $this->assertSame($expected, $ids, 'todos: sem filtro de data (traz pendente + em_acao independente da data).');
    }

    /**
     * Cenário 5 — Prop `periodo` propagada + prop `periodo_presets` populada.
     */
    public function test_props_periodo_e_presets_propagadas(): void
    {
        $admin = $this->admin();
        $emp   = $this->empresa('PP');
        $this->criarSugador($emp, $this->hoje);

        // 5a — sem query → periodo === 'hoje'
        $r1 = $this->actingAs($admin)->get(route('sugadores.empresa.listagem', $emp->id));
        $r1->assertOk();
        $p1 = $this->extractInertiaPage($r1)['props'];
        $this->assertArrayHasKey('periodo', $p1);
        $this->assertSame('hoje', $p1['periodo']);

        $this->assertArrayHasKey('periodo_presets', $p1);
        $this->assertIsArray($p1['periodo_presets']);
        $valores = array_column($p1['periodo_presets'], 'value');
        $this->assertSame(['hoje', '7d', '30d', 'todos'], $valores);
        // Cada preset tem `label` (string não vazia)
        foreach ($p1['periodo_presets'] as $preset) {
            $this->assertArrayHasKey('label', $preset);
            $this->assertNotEmpty($preset['label']);
        }

        // 5b — ?periodo=7d → prop periodo === '7d'
        $r2 = $this->actingAs($admin)->get(route('sugadores.empresa.listagem', ['company' => $emp->id, 'periodo' => '7d']));
        $r2->assertOk();
        $p2 = $this->extractInertiaPage($r2)['props'];
        $this->assertSame('7d', $p2['periodo']);
    }

    /**
     * Cenário 6 — ?periodo=xpto (invalido): fallback para hoje. Prop `periodo`
     * eco DEVE ser 'hoje' (nao 'xpto'). Seguranca contra manipulacao.
     */
    public function test_periodo_invalido_cai_no_default_hoje(): void
    {
        $admin = $this->admin();
        $emp   = $this->empresa('PINV');

        $sHoje   = $this->criarSugador($emp, $this->hoje, Sugador::STATUS_PENDENTE, 'HOJE');
        $sAntigo = $this->criarSugador($emp, $this->hoje->copy()->subDays(10), Sugador::STATUS_PENDENTE, 'ANTIGO');

        $response = $this->actingAs($admin)
            ->get(route('sugadores.empresa.listagem', ['company' => $emp->id, 'periodo' => 'xpto']));
        $response->assertOk();

        $props = $this->extractInertiaPage($response)['props'];

        // Filtro efetivo = hoje (só o de hoje aparece)
        $ids = array_column($props['sugadores'], 'id');
        $this->assertSame([$sHoje->id], $ids, 'Invalido deveria filtrar como hoje.');
        // Prop `periodo` eco tambem = hoje
        $this->assertSame('hoje', $props['periodo']);
    }

    /**
     * Cenário 7 — REGRESSAO: analista sem carteira ainda recebe 403 mesmo
     * com ?periodo=todos. Filtro de periodo NAO deve furar a checagem de
     * carteira (que roda ANTES do filtro no controller).
     */
    public function test_regressao_analista_fora_carteira_ainda_403(): void
    {
        $emp = $this->empresa('P403');
        $this->criarSugador($emp, $this->hoje);

        // Analista com CORE_SUGADORES mas SEM carteira nesta empresa.
        $analista = $this->userComCoreSugadores('fora-carteira');

        $response = $this->actingAs($analista)
            ->get(route('sugadores.empresa.listagem', ['company' => $emp->id, 'periodo' => 'todos']));

        $response->assertStatus(403);
    }
}
