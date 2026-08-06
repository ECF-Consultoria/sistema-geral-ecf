<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\MlbEmpresa;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite Feature — Phase 37 Plan 37-06 (REQ-37-07).
 *
 * Refoca /companies em Performance: CompanyController::index passa a filtrar
 * apenas empresas com >=1 contrato ATIVO em Servico::setor='performance'.
 *
 * Cenarios cobertos:
 *  1. Scope performance — apresenta apenas empresas com contrato performance ativo
 *  2. Setor publicacao NAO aparece
 *  3. Setor outros NAO aparece
 *  4. Contrato performance INATIVO NAO aparece
 *  5. Empresa sem contratos NAO aparece
 *  6. Empresa com contratos mistos (1 performance + 1 outros) APARECE
 *  7. Empresa com MlbEmpresa associada NAO aparece (zero regressao Phase 35)
 *  8. Payload NAO contem pendencia 'sem_servico'
 *  9. Payload preserva demais pendencias (sem_responsavel, etc.)
 * 10. Filtro ?cust_id_status=invalido continua funcional
 * 11. Sort por created_at continua funcional
 *
 * Licao Phase 35 preservada: whereDoesntHave('mlbEmpresa') segue ativo —
 * empresas com MlbEmpresa nunca entram em /companies.
 */
class Phase37CompaniesPerformanceFilterTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Phase37-06 ' . uniqid(),
            'email'    => 'admin.p37-06.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    private function criarServico(string $nome, string $setor, float $valor = 1500.0): Servico
    {
        return Servico::create([
            'nome'          => $nome . ' ' . uniqid(),
            'valor_padrao'  => $valor,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => $setor,
        ]);
    }

    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'   => 'Empresa P37-06 ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
            'status' => 'ativo',
            // Phase 34 — preenche os campos minimos para nao gerar pendencias colaterais
            // que nao sao foco deste teste (foco eh sem_servico / scope performance).
            'email_colaborador'  => 'colab.' . uniqid() . '@ecf.test',
            'adman_account_id'   => (string) random_int(100000, 999999),
            'empresa_nova'       => false,
        ], $overrides));
    }

    private function criarContrato(Company $c, Servico $s, bool $ativo = true): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => $ativo,
        ]);
    }

    private function payloadCompanies($response): \Illuminate\Support\Collection
    {
        return collect($response->viewData('page')['props']['companies']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. Scope Performance — APARECE
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_com_contrato_performance_aparece(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $response = $this->get('/companies');
        $response->assertStatus(200);

        $ids = $this->payloadCompanies($response)->pluck('id');
        $this->assertContains($empresa->id, $ids->all(),
            'Empresa com contrato Performance ativo deve aparecer em /companies');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. Setor publicacao NAO aparece
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_com_contrato_publicacao_nao_aparece(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Publicidade ML', Servico::SETOR_PUBLICACAO);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $response = $this->get('/companies');
        $ids = $this->payloadCompanies($response)->pluck('id');

        $this->assertNotContains($empresa->id, $ids->all(),
            'Empresa com contrato APENAS em Publicacao NAO deve aparecer em /companies');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. Setor outros NAO aparece
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_com_contrato_outros_nao_aparece(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Polos', Servico::SETOR_OUTROS);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $response = $this->get('/companies');
        $ids = $this->payloadCompanies($response)->pluck('id');

        $this->assertNotContains($empresa->id, $ids->all(),
            'Empresa com contrato APENAS em Outros NAO deve aparecer em /companies');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. Contrato performance INATIVO NAO aparece
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_com_contrato_performance_inativo_nao_aparece(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, false); // inativo

        $response = $this->get('/companies');
        $ids = $this->payloadCompanies($response)->pluck('id');

        $this->assertNotContains($empresa->id, $ids->all(),
            'Empresa com contrato Performance INATIVO NAO deve aparecer (filtro exige ativo=true)');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. Empresa sem contratos NAO aparece
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_sem_contratos_nao_aparece(): void
    {
        $this->actingAsAdmin();
        $empresa = $this->criarEmpresa();
        // Sem contratos

        $response = $this->get('/companies');
        $ids = $this->payloadCompanies($response)->pluck('id');

        $this->assertNotContains($empresa->id, $ids->all(),
            'Empresa sem contratos NAO deve aparecer (filtro exige >=1 contrato Performance ativo)');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 6. Empresa com contratos mistos APARECE (basta 1 performance ativo)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_com_contratos_mistos_aparece(): void
    {
        $this->actingAsAdmin();
        $performance = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $outros      = $this->criarServico('Polos', Servico::SETOR_OUTROS);

        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $performance, true);
        $this->criarContrato($empresa, $outros, true);

        $response = $this->get('/companies');
        $ids = $this->payloadCompanies($response)->pluck('id');

        $this->assertContains($empresa->id, $ids->all(),
            'Empresa com >=1 contrato Performance ativo + outros DEVE aparecer (basta 1)');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 7. Empresa com MlbEmpresa NAO aparece (zero regressao Phase 35)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_com_mlb_empresa_nao_aparece(): void
    {
        $admin = $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        // Cria MlbEmpresa associada — deve ser excluida do listing /companies
        MlbEmpresa::create([
            'nome'       => 'Empresa MLB ' . uniqid(),
            'tipo'       => 'POLO',
            'projeto'    => 'POLOS',
            'fase'       => 'M1',
            'polo'       => 'Arapongas',
            'estagio'    => 'Não Listado',
            'criado_por' => $admin->id,
            'company_id' => $empresa->id,
        ]);

        $response = $this->get('/companies');
        $ids = $this->payloadCompanies($response)->pluck('id');

        $this->assertNotContains($empresa->id, $ids->all(),
            'Empresa com MlbEmpresa associada NAO deve aparecer (whereDoesntHave preserva Phase 35)');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 8. Payload NAO contem pendencia 'sem_servico'
    // ═════════════════════════════════════════════════════════════════════════

    public function test_payload_nao_contem_pendencia_sem_servico(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $response = $this->get('/companies');
        $alvo = $this->payloadCompanies($response)->firstWhere('id', $empresa->id);

        $this->assertNotNull($alvo, 'Empresa deve estar no payload');
        $this->assertNotContains('sem_servico', $alvo['pendencias'],
            'Pendencia sem_servico foi removida no Plan 37-06 — migrou para /comercial/empresas/listagem');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 9. Payload preserva demais pendencias (sem_responsavel)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_payload_contem_demais_pendencias(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        // Empresa SEM consultor/estrategista — deve gerar pendencia sem_responsavel
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $response = $this->get('/companies');
        $alvo = $this->payloadCompanies($response)->firstWhere('id', $empresa->id);

        $this->assertNotNull($alvo);
        $this->assertContains('sem_responsavel', $alvo['pendencias'],
            'Pendencias operacionais (sem_responsavel) devem ser preservadas');
    }

    public function test_payload_contem_pendencia_sem_email_colaborador(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa(['email_colaborador' => null]);
        $this->criarContrato($empresa, $servico, true);

        $response = $this->get('/companies');
        $alvo = $this->payloadCompanies($response)->firstWhere('id', $empresa->id);

        $this->assertNotNull($alvo);
        $this->assertContains('sem_email_colaborador', $alvo['pendencias'],
            'Pendencia sem_email_colaborador deve continuar funcionando');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 10. Filtro ?cust_id_status=invalido continua funcional
    // ═════════════════════════════════════════════════════════════════════════

    public function test_filtro_cust_id_status_invalido_continua_funcional(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);

        $empresaInvalida = $this->criarEmpresa(['cust_id_status' => 'invalido']);
        $this->criarContrato($empresaInvalida, $servico, true);

        $empresaOk = $this->criarEmpresa(['cust_id_status' => 'ok']);
        $this->criarContrato($empresaOk, $servico, true);

        $response = $this->get('/companies?cust_id_status=invalido');
        $ids = $this->payloadCompanies($response)->pluck('id');

        $this->assertContains($empresaInvalida->id, $ids->all(),
            'Filtro cust_id_status=invalido deve manter empresa invalida no payload');
        $this->assertNotContains($empresaOk->id, $ids->all(),
            'Filtro cust_id_status=invalido deve excluir empresa OK');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 11. Sort por created_at continua funcional
    // ═════════════════════════════════════════════════════════════════════════

    public function test_sort_nova_recente_continua_funcional(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);

        $antiga = $this->criarEmpresa(['name' => 'Z - Antiga']);
        $this->criarContrato($antiga, $servico, true);
        // Forca antiga ter created_at no passado
        $antiga->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        $recente = $this->criarEmpresa(['name' => 'A - Recente']);
        $this->criarContrato($recente, $servico, true);
        $recente->forceFill(['created_at' => now()])->saveQuietly();

        $response = $this->get('/companies?sort=nova_recente');
        $ids = $this->payloadCompanies($response)->pluck('id')->all();

        // Filtra apenas as 2 criadas pelo teste para evitar ruido
        $idsTeste = array_values(array_intersect($ids, [$antiga->id, $recente->id]));

        $this->assertSame([$recente->id, $antiga->id], $idsTeste,
            'Sort nova_recente deve ordenar empresas recentes primeiro');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 12. fast-260806 — flag `em_operacao`
    //
    // Empresa sem analista E sem estrategista nao entrou em operacao: some da
    // aba "Empresas" (lista e contagem) e vive so na aba "Pendencias", onde a
    // barra de acoes em massa permite atribuir os responsaveis.
    //
    // A flag e um E, de proposito — NAO e a negacao da pendencia
    // `sem_responsavel`, que e um OU. Empresa com estrategista mas sem analista
    // continua em operacao (alguem cuida dela) e permanece nas duas listas.
    // ═════════════════════════════════════════════════════════════════════════

    /** Vincula um usuario a empresa pelo pivot que as relacoes de Performance leem. */
    private function vincular(Company $empresa, string $role): User
    {
        $user = User::create([
            'name'     => 'Resp ' . $role . ' ' . uniqid(),
            'email'    => 'resp.' . $role . '.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => $role === 'consultor' ? 'consultor' : 'mentor',
            'active'   => true,
        ]);

        // servico_id null + contrato Performance ativo casa com o ramo
        // "consolidado" de analistaPerformance()/estrategistaPerformance().
        $empresa->users()->attach($user->id, [
            'role'        => $role,
            'servico_id'  => null,
            'assigned_at' => now(),
        ]);

        return $user;
    }

    public function test_empresa_sem_analista_e_sem_estrategista_nao_esta_em_operacao(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $alvo = $this->payloadCompanies($this->get('/companies'))->firstWhere('id', $empresa->id);

        $this->assertNotNull($alvo);
        $this->assertFalse($alvo['em_operacao'],
            'Sem nenhum responsavel a empresa nao esta em operacao');
        // Continua sendo cobrada na aba Pendencias.
        $this->assertContains('sem_responsavel', $alvo['pendencias']);
    }

    public function test_empresa_com_estrategista_mas_sem_analista_continua_em_operacao(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);
        $this->vincular($empresa, 'estrategista');

        $alvo = $this->payloadCompanies($this->get('/companies'))->firstWhere('id', $empresa->id);

        $this->assertNotNull($alvo);
        $this->assertTrue($alvo['em_operacao'],
            'Com estrategista alguem cuida da empresa — ela segue na aba Empresas');
        // E ainda assim cobra o analista que falta (a pendencia e um OU).
        $this->assertContains('sem_responsavel', $alvo['pendencias'],
            'em_operacao NAO pode ser a negacao de sem_responsavel');
    }

    public function test_empresa_com_analista_e_estrategista_esta_em_operacao_sem_pendencia(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);
        $this->vincular($empresa, 'consultor');
        $this->vincular($empresa, 'estrategista');

        $alvo = $this->payloadCompanies($this->get('/companies'))->firstWhere('id', $empresa->id);

        $this->assertNotNull($alvo);
        $this->assertTrue($alvo['em_operacao']);
        $this->assertNotContains('sem_responsavel', $alvo['pendencias']);
    }
}
