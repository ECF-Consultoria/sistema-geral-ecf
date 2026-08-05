<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Suite Feature — Phase 37 Plan 37-05 (Listagem Comercial Unificada).
 *
 * Cobre o endpoint GET /comercial/empresas/listagem:
 *  - Filtros snake_case empilháveis (servico, setor, ordem, pendencia, q)
 *  - Cards de pendência comercial (sem_servico, sem_valor,
 *    servico_nao_reconhecido, sem_setor). A quick task 260805-eqk removeu
 *    `dados_close_incompletos` junto com as colunas que a alimentavam.
 *  - Origem HubSpot via EXISTS(hubspot_eventos.company_id_criada)
 *  - REQ-37-10: empresas legacy (sem hubspot_evento) NAO geram pendencia
 *  - Autorizacao via permission comercial.cadastrar_empresa + isAdmin()
 *
 * Lição Phase 18 aplicada: filtros SEMPRE empilháveis — combinar
 * servico+setor+ordem+q em uma única requisição preserva os 5 valores.
 */
class Phase37ComercialListagemTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Phase37 ' . uniqid(),
            'email'    => 'admin.p37-05.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    private function userSemPermissao(): User
    {
        return User::create([
            'name'     => 'Consultor Phase37 ' . uniqid(),
            'email'    => 'consultor.p37-05.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);
    }

    private function criarServico(string $nome, string $setor = Servico::SETOR_OUTROS, float $valorPadrao = 100): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => $valorPadrao,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => $setor,
        ]);
    }

    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'   => 'Empresa P37-05 ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
            'status' => 'ativo',
        ], $overrides));
    }

    /**
     * Marca uma empresa como "origem HubSpot" criando um HubspotEvento
     * que aponta para ela via company_id_criada. Aceita payload opcional
     * (usado em cenarios de servico_nao_reconhecido).
     */
    private function marcarOrigemHubspot(Company $c, array $payload = []): HubspotEvento
    {
        return HubspotEvento::create([
            'signature_valid'   => true,
            'portal_id'         => 12345,
            'object_type'       => 'DEAL',
            'object_id'         => random_int(1000, 99999),
            'subscription_type' => 'deal.propertyChange',
            'property_name'     => 'dealstage',
            'property_value'    => 'closedwon',
            'payload'           => $payload,
            'status'            => 'processado',
            'company_id_criada' => $c->id,
            'processado_em'     => now(),
        ]);
    }

    private function criarContrato(Company $c, Servico $s, array $overrides = []): ContratoServico
    {
        return ContratoServico::create(array_merge([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ], $overrides));
    }

    // ─── 1. Autorizacao ──────────────────────────────────────────────────────

    public function test_acesso_403_sem_permissao(): void
    {
        $user = $this->userSemPermissao();

        $response = $this->actingAs($user)->get('/comercial/empresas/listagem');

        $response->assertStatus(403);
    }

    public function test_admin_acessa_sem_setor_permissao(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) =>
            $page->component('Comercial/EmpresasListagem')
        );
    }

    // ─── 2. Listagem base (sem filtros) ──────────────────────────────────────

    public function test_lista_todas_empresas_active_sem_filtros(): void
    {
        $this->actingAsAdmin();

        $this->criarEmpresa(['name' => 'A Empresa']);
        $this->criarEmpresa(['name' => 'B Empresa']);
        $this->criarEmpresa(['name' => 'C Empresa']);
        $this->criarEmpresa(['name' => 'D Inativa', 'active' => false]);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(3, $props['companies']['data']);
    }

    // ─── 3. Filtros snake_case ───────────────────────────────────────────────

    public function test_filtro_servico_aplicado(): void
    {
        $this->actingAsAdmin();

        $servicoA = $this->criarServico('Gestão Premium', Servico::SETOR_PERFORMANCE);
        $servicoB = $this->criarServico('Polos SP', Servico::SETOR_PUBLICACAO);

        $e1 = $this->criarEmpresa(['name' => 'E1']);
        $e2 = $this->criarEmpresa(['name' => 'E2']);
        $e3 = $this->criarEmpresa(['name' => 'E3']);

        $this->criarContrato($e1, $servicoA);
        $this->criarContrato($e2, $servicoA);
        $this->criarContrato($e3, $servicoB);

        $response = $this->get('/comercial/empresas/listagem?servico=' . $servicoA->id);

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(2, $props['companies']['data']);
    }

    public function test_filtro_setor_performance(): void
    {
        $this->actingAsAdmin();

        $sPerf = $this->criarServico('Gestão Premium', Servico::SETOR_PERFORMANCE);
        $sPub  = $this->criarServico('Polos SP', Servico::SETOR_PUBLICACAO);
        $sOut  = $this->criarServico('Outro Serviço', Servico::SETOR_OUTROS);

        $eP1 = $this->criarEmpresa(['name' => 'Perf1']);
        $eP2 = $this->criarEmpresa(['name' => 'Pub1']);
        $eP3 = $this->criarEmpresa(['name' => 'Outr1']);

        $this->criarContrato($eP1, $sPerf);
        $this->criarContrato($eP2, $sPub);
        $this->criarContrato($eP3, $sOut);

        $response = $this->get('/comercial/empresas/listagem?setor=performance');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['companies']['data']);
        $this->assertSame('Perf1', $props['companies']['data'][0]['name']);
    }

    public function test_ordem_recentes_vs_antigas(): void
    {
        $this->actingAsAdmin();

        $e1 = $this->criarEmpresa(['name' => 'Mais Antiga']);
        $e1->created_at = now()->subDays(10);
        $e1->save();

        $e2 = $this->criarEmpresa(['name' => 'Meio']);
        $e2->created_at = now()->subDays(5);
        $e2->save();

        $e3 = $this->criarEmpresa(['name' => 'Mais Recente']);
        $e3->created_at = now();
        $e3->save();

        // Default = recentes
        $response = $this->get('/comercial/empresas/listagem');
        $props = $response->viewData('page')['props'];
        $this->assertSame('Mais Recente', $props['companies']['data'][0]['name']);

        // ?ordem=antigas inverte
        $response = $this->get('/comercial/empresas/listagem?ordem=antigas');
        $props = $response->viewData('page')['props'];
        $this->assertSame('Mais Antiga', $props['companies']['data'][0]['name']);
    }

    public function test_busca_q_match_name_ou_cnpj(): void
    {
        $this->actingAsAdmin();

        $this->criarEmpresa(['name' => 'ACME Ltda',     'cnpj' => '11111111000111']);
        $this->criarEmpresa(['name' => 'Beta Comercio', 'cnpj' => '22222222000222']);
        $this->criarEmpresa(['name' => 'Outra',         'cnpj' => '33333333000ACME']);

        $response = $this->get('/comercial/empresas/listagem?q=ACME');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        // ACME bate em name (1) E em cnpj (1) — total 2
        $this->assertCount(2, $props['companies']['data']);
    }

    public function test_filtros_empilhaveis_query_string_preservada(): void
    {
        $this->actingAsAdmin();

        $servicoA = $this->criarServico('Servico A', Servico::SETOR_PERFORMANCE);

        $response = $this->get('/comercial/empresas/listagem?servico=' . $servicoA->id . '&setor=performance&ordem=antigas&q=ACME');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame((string) $servicoA->id, $props['filters']['servico']);
        $this->assertSame('performance', $props['filters']['setor']);
        $this->assertSame('antigas', $props['filters']['ordem']);
        $this->assertSame('ACME', $props['filters']['q']);
        $this->assertNull($props['filters']['pendencia']);
    }

    // ─── 4. Pendencias comerciais (REQ-37-06) ────────────────────────────────

    public function test_empresa_origem_hubspot_gera_pendencia_sem_servico(): void
    {
        $this->actingAsAdmin();

        $e = $this->criarEmpresa(['name' => 'HubSpot Sem Servico']);
        $this->marcarOrigemHubspot($e);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = collect($props['companies']['data'])->firstWhere('id', $e->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['is_origem_hubspot']);
        $this->assertContains('sem_servico', $row['pendencias_comerciais']);
    }

    public function test_empresa_legacy_NAO_gera_pendencias_REQ_37_10(): void
    {
        $this->actingAsAdmin();

        // Empresa SEM HubspotEvento + sem contrato + dados close vazios — seria
        // multi-pendente se fosse origem HubSpot. REQ-37-10: legacy nao gera.
        $e = $this->criarEmpresa(['name' => 'Legacy Sem Tudo']);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = collect($props['companies']['data'])->firstWhere('id', $e->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_origem_hubspot']);
        $this->assertSame([], $row['pendencias_comerciais']);
    }

    public function test_pendencia_servico_nao_reconhecido(): void
    {
        $this->actingAsAdmin();

        $e = $this->criarEmpresa(['name' => 'HubSpot Nao Mapeado']);
        $this->marcarOrigemHubspot($e, [
            'line_items_nao_mapeados' => [
                ['name' => 'Servico Desconhecido', 'price' => '500', 'recurringbillingfrequency' => null],
            ],
        ]);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = collect($props['companies']['data'])->firstWhere('id', $e->id);
        $this->assertNotNull($row);
        $this->assertContains('servico_nao_reconhecido', $row['pendencias_comerciais']);
    }

    public function test_pendencia_sem_setor(): void
    {
        $this->actingAsAdmin();

        $servicoOutros = $this->criarServico('Servico Outros', Servico::SETOR_OUTROS);

        $e = $this->criarEmpresa(['name' => 'HubSpot Setor Outros']);
        $this->marcarOrigemHubspot($e);
        $this->criarContrato($e, $servicoOutros);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = collect($props['companies']['data'])->firstWhere('id', $e->id);
        $this->assertNotNull($row);
        $this->assertContains('sem_setor', $row['pendencias_comerciais']);
    }

    /**
     * Quick task 260805-eqk — a pendência `dados_close_incompletos` foi
     * removida por completo (cálculo, filtro, counts e detalhes) junto com as
     * colunas nicho/dor/faturamento_mensal que a alimentavam. Este teste virou
     * a guarda de que ela não volta.
     */
    public function test_pendencia_dados_close_incompletos_nao_existe_mais(): void
    {
        $this->actingAsAdmin();

        $e = $this->criarEmpresa(['name' => 'HubSpot Sem Close']);
        $this->marcarOrigemHubspot($e);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = collect($props['companies']['data'])->firstWhere('id', $e->id);
        $this->assertNotNull($row);
        $this->assertNotContains('dados_close_incompletos', $row['pendencias_comerciais']);
        $this->assertArrayNotHasKey('dados_close_incompletos', $props['pendencia_counts']);
    }

    public function test_pendencia_sem_valor(): void
    {
        $this->actingAsAdmin();

        $servico = $this->criarServico('Servico Pago', Servico::SETOR_PERFORMANCE);

        $e = $this->criarEmpresa(['name' => 'HubSpot Sem Valor']);
        $this->marcarOrigemHubspot($e);
        $this->criarContrato($e, $servico, ['valor_contratado' => 0]);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = collect($props['companies']['data'])->firstWhere('id', $e->id);
        $this->assertNotNull($row);
        $this->assertContains('sem_valor', $row['pendencias_comerciais']);
    }

    public function test_pendencia_counts_corresponde_a_lista_completa(): void
    {
        $this->actingAsAdmin();

        // 2 empresas com pendencia sem_servico (origem HubSpot + sem contrato)
        $e1 = $this->criarEmpresa(['name' => 'HS1']);
        $this->marcarOrigemHubspot($e1);
        $e2 = $this->criarEmpresa(['name' => 'HS2']);
        $this->marcarOrigemHubspot($e2);

        // 1 empresa legacy sem contrato (NAO conta, REQ-37-10)
        $this->criarEmpresa(['name' => 'Legacy']);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(2, $props['pendencia_counts']['sem_servico']);
        // Counts existem para as 4 chaves (260805-eqk removeu dados_close_incompletos)
        $this->assertArrayHasKey('sem_valor', $props['pendencia_counts']);
        $this->assertArrayHasKey('servico_nao_reconhecido', $props['pendencia_counts']);
        $this->assertArrayHasKey('sem_setor', $props['pendencia_counts']);
    }

    public function test_filtro_pendencia_aplicado(): void
    {
        $this->actingAsAdmin();

        // E1 e E2: pendencia sem_servico (origem HubSpot + sem contrato)
        $e1 = $this->criarEmpresa(['name' => 'HS Sem Servico 1']);
        $this->marcarOrigemHubspot($e1);
        $e2 = $this->criarEmpresa(['name' => 'HS Sem Servico 2']);
        $this->marcarOrigemHubspot($e2);

        // E3: legacy SEM pendencia
        $this->criarEmpresa(['name' => 'Legacy']);

        // E4: origem HubSpot COM contrato (sem pendencia sem_servico)
        $servico = $this->criarServico('Servico OK', Servico::SETOR_PERFORMANCE);
        $e4 = $this->criarEmpresa(['name' => 'HS OK']);
        $this->marcarOrigemHubspot($e4);
        $this->criarContrato($e4, $servico);

        $response = $this->get('/comercial/empresas/listagem?pendencia=sem_servico');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(2, $props['companies']['data']);
    }

    public function test_servico_counts_exporta_setor(): void
    {
        $this->actingAsAdmin();

        $sPerf = $this->criarServico('Gestão Premium', Servico::SETOR_PERFORMANCE);
        $sPub  = $this->criarServico('Polos SP', Servico::SETOR_PUBLICACAO);

        $e1 = $this->criarEmpresa(['name' => 'E1']);
        $e2 = $this->criarEmpresa(['name' => 'E2']);
        $this->criarContrato($e1, $sPerf);
        $this->criarContrato($e2, $sPub);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertNotEmpty($props['servico_counts']);
        // Cada item tem id, nome, setor, total. DB::table retorna stdClass;
        // convertemos para array para o assertArrayHasKey.
        $first = (array) $props['servico_counts'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('nome', $first);
        $this->assertArrayHasKey('setor', $first);
        $this->assertArrayHasKey('total', $first);
    }
}
