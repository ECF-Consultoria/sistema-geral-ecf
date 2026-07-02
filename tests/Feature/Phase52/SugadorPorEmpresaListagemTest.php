<?php

namespace Tests\Feature\Phase52;

use App\Models\Company;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\Sugador;
use App\Models\SugadorConfig;
use App\Models\User;
use App\Support\Permissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 52 Wave 3.5 (52-03B) — GET /sugadores/empresa/{company}.
 *
 * Restaura o drilldown "por empresa" removido na Wave 2 (Opção Z). O click no
 * CompanyCard passa a navegar para esta pagina, que lista os sugadores da
 * empresa com contexto ja fixado no header (sem coluna "empresa" — A4) e expoe
 * A5 (Copiar MLBs por linha) + A6 (bulk copy MLBs) sobre eles.
 *
 * Autorizacao herda `SugadorPolicy::viewAny` (mesmo Gate do Index) e faz
 * checagem adicional de carteira quando o usuario nao tem visao global.
 */
class SugadorPorEmpresaListagemTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = Carbon::parse('2026-07-02')->startOfDay();
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

    private function companyAdman(string $custId = 'ADMAN-1'): Company
    {
        return Company::create([
            'name'             => 'Empresa Adman ' . $custId,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => $custId,
            'active'           => true,
        ]);
    }

    private function seedSugador(
        Company $c,
        string $adgroupId,
        string $status = Sugador::STATUS_PENDENTE,
        ?Carbon $refDate = null,
        string $tipo = Sugador::TIPO_ADGROUP,
    ): Sugador {
        $refDate = $refDate ?? $this->hoje;
        return Sugador::create([
            'company_id'           => $c->id,
            'reference_date'       => $refDate->toDateString(),
            'tipo'                 => $tipo,
            'campaign_id'          => 'CAMP-' . $adgroupId,
            'campaign_name'        => 'Camp ' . $adgroupId,
            'adgroup_id'           => $adgroupId,
            'adgroup_name'         => 'Adgroup ' . $adgroupId,
            'periodo_inicio'       => $refDate->copy()->subDays(7)->toDateString(),
            'periodo_fim'          => $refDate->copy()->subDay()->toDateString(),
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
     * Helper: user com uma permission_key ligada via setor (pattern usado em
     * SugadorPolicyManageAnalistaTest e Phase13ComercialTest).
     */
    private function userComPermissao(string $permissionKey, string $slug = 'operacional', string $role = 'consultor'): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor ' . $slug,
            'slug'   => $slug . '-' . uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => $permissionKey,
        ]);
        $user = User::factory()->create(['role' => $role]);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);
        return $user;
    }

    /**
     * Cenário 1 — Admin abre a rota → 200 + Inertia page correta.
     */
    public function test_admin_recebe_200_com_pagina_correta(): void
    {
        $admin   = $this->admin();
        $empresa = $this->companyAdman('E1');

        $this->seedSugador($empresa, 'AG-1');

        $response = $this->actingAs($admin)
            ->get(route('sugadores.empresa.listagem', $empresa->id));

        $response->assertOk();
        // Inertia page — inspeciona `component` do data-page JSON.
        $page = $this->extractInertiaPage($response);
        $this->assertSame('Sugadores/EmpresaListagem', $page['component']);
    }

    /**
     * Cenário 2 — Lista apenas sugadores da empresa passada (filtro correto).
     */
    public function test_lista_apenas_sugadores_da_empresa(): void
    {
        $admin    = $this->admin();
        $empresaA = $this->companyAdman('EA');
        $empresaB = $this->companyAdman('EB');

        $sA1 = $this->seedSugador($empresaA, 'AG-A1');
        $sA2 = $this->seedSugador($empresaA, 'AG-A2');
        $sB1 = $this->seedSugador($empresaB, 'AG-B1');

        $response = $this->actingAs($admin)
            ->get(route('sugadores.empresa.listagem', $empresaA->id));

        $response->assertOk();

        $page = $this->extractInertiaPage($response);
        $props = $page['props'];

        $this->assertSame($empresaA->id, $props['company']['id']);
        $this->assertCount(2, $props['sugadores']);

        $ids = array_column($props['sugadores'], 'id');
        sort($ids);
        $expected = [$sA1->id, $sA2->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotContains($sB1->id, $ids);
    }

    /**
     * Cenário 3 — Props obrigatórias presentes: company, sugadores,
     * sugador_config, can_manage_config, can_analyze.
     */
    public function test_props_obrigatorias_presentes(): void
    {
        $admin   = $this->admin();
        $empresa = $this->companyAdman('EP');

        // Config personalizada — testa que payload compacto sai correto.
        SugadorConfig::create([
            'company_id'                => $empresa->id,
            'ativo'                     => true,
            'dias_analise'              => 15,
            'gasto_minimo_sem_venda'    => 200,
            'cpc_maximo'                => 2.5,
            'acos_maximo_pct'           => 40,
            'cliques_minimos_sem_venda' => 10,
        ]);

        $this->seedSugador($empresa, 'AG-P');

        $response = $this->actingAs($admin)
            ->get(route('sugadores.empresa.listagem', $empresa->id));

        $response->assertOk();
        $props = $this->extractInertiaPage($response)['props'];

        $this->assertArrayHasKey('company', $props);
        $this->assertArrayHasKey('sugadores', $props);
        $this->assertArrayHasKey('sugador_config', $props);
        $this->assertArrayHasKey('can_manage_config', $props);
        $this->assertArrayHasKey('can_analyze', $props);

        $this->assertSame($empresa->id, $props['company']['id']);
        $this->assertSame($empresa->name, $props['company']['name']);
        $this->assertTrue($props['can_manage_config']);
        $this->assertTrue($props['can_analyze']);
        $this->assertNotNull($props['sugador_config']);
        $this->assertSame(15, $props['sugador_config']['dias_analise']);
    }

    /**
     * Cenário 4 — Filtro por status: pendente + em_acao aparecem;
     * resolvido/ignorado nao aparecem (foco em pendentes acionaveis).
     */
    public function test_filtra_status_pendente_e_em_acao(): void
    {
        $admin   = $this->admin();
        $empresa = $this->companyAdman('EF');

        $sPend = $this->seedSugador($empresa, 'AG-PEND', Sugador::STATUS_PENDENTE);
        $sAcao = $this->seedSugador($empresa, 'AG-ACAO', Sugador::STATUS_EM_ACAO);
        $sReso = $this->seedSugador($empresa, 'AG-RESO', Sugador::STATUS_RESOLVIDO);
        $sIgno = $this->seedSugador($empresa, 'AG-IGNO', Sugador::STATUS_IGNORADO);

        $response = $this->actingAs($admin)
            ->get(route('sugadores.empresa.listagem', $empresa->id));

        $props = $this->extractInertiaPage($response)['props'];
        $ids = array_column($props['sugadores'], 'id');

        $this->assertContains($sPend->id, $ids);
        $this->assertContains($sAcao->id, $ids);
        $this->assertNotContains($sReso->id, $ids);
        $this->assertNotContains($sIgno->id, $ids);
    }

    /**
     * Cenário 5 — Usuario sem permissao CORE_SUGADORES: 403.
     */
    public function test_403_sem_permissao(): void
    {
        $empresa = $this->companyAdman('EX');
        $this->seedSugador($empresa, 'AG-X');

        // Consultor puro (sem CORE_SUGADORES) → viewAny=false → 403
        $user = User::factory()->create(['role' => 'consultor']);

        $response = $this->actingAs($user)
            ->get(route('sugadores.empresa.listagem', $empresa->id));

        $response->assertStatus(403);
    }

    /**
     * Cenário 6 — Analista (CORE_SUGADORES) fora da carteira: 403 mesmo com
     * viewAny=true, porque a empresa passada nao esta na sua carteira.
     */
    public function test_analista_fora_carteira_recebe_403(): void
    {
        $empresa = $this->companyAdman('EC');
        $this->seedSugador($empresa, 'AG-C');

        $analista = $this->userComPermissao(Permissions::CORE_SUGADORES, 'analista');

        $response = $this->actingAs($analista)
            ->get(route('sugadores.empresa.listagem', $empresa->id));

        $response->assertStatus(403);
    }

    /**
     * Cenário 7 — Ordena por reference_date desc (mais recentes no topo).
     *
     * Phase 54 Plan 54-01 (B1): o default do porEmpresa mudou para `periodo=hoje`,
     * então este teste (que valida ordenação cruzando datas de vários dias)
     * agora precisa passar `?periodo=todos` explicitamente. A ordenação em si
     * (`reference_date desc, id desc`) permanece inalterada.
     */
    public function test_ordena_por_reference_date_desc(): void
    {
        $admin   = $this->admin();
        $empresa = $this->companyAdman('EO');

        $sAntigo = $this->seedSugador($empresa, 'AG-OLD', Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDays(3));
        $sNovo   = $this->seedSugador($empresa, 'AG-NEW', Sugador::STATUS_PENDENTE, $this->hoje);
        $sMedio  = $this->seedSugador($empresa, 'AG-MID', Sugador::STATUS_PENDENTE, $this->hoje->copy()->subDay());

        $response = $this->actingAs($admin)
            ->get(route('sugadores.empresa.listagem', ['company' => $empresa->id, 'periodo' => 'todos']));

        $props = $this->extractInertiaPage($response)['props'];
        $ids = array_column($props['sugadores'], 'id');

        $this->assertSame([$sNovo->id, $sMedio->id, $sAntigo->id], $ids);
    }

    /**
     * Extrai o payload JSON da <div data-page="..."> que Inertia renderiza no root.
     * Necessario pra inspecionar props em cima de assertOk() (GET Inertia devolve
     * HTML, nao JSON, a menos que enviemos X-Inertia header — o que exige que os
     * middlewares Inertia estejam completos no ambiente de teste).
     */
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
}
