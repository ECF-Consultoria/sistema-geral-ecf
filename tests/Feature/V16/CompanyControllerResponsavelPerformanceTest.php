<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 89 Plan 02 (CART-08).
 *
 * Cobre a leitura de responsável de PERFORMANCE em /companies (index + show):
 * `Company::consultor()`/`estrategista()` são relações CONSOLIDADAS (misturam
 * ML e Shopee) — `->first()` pode devolver o responsável Shopee, mesmo em um
 * painel 100% Performance. As novas relações `analistaPerformance()`/
 * `estrategistaPerformance()` filtram por `servicos.setor='performance'`
 * (+ ramo legado `servico_id NULL` com contrato performance ativo).
 *
 * Também cobre a mudança da pendência `sem_responsavel` de AND para OR:
 * a lista de pendências CRESCE (empresa com só 1 dos 2 papéis de performance
 * atribuídos passa a aparecer) — comportamento confirmado pelo usuário.
 */
class CompanyControllerResponsavelPerformanceTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Localiza a linha da empresa no payload `companies[]` do index.
     */
    private function acharEmpresa(array $companies, int $companyId): array
    {
        foreach ($companies as $c) {
            if ($c['id'] === $companyId) {
                return $c;
            }
        }
        $this->fail("Empresa {$companyId} não encontrada no payload de /companies.");
    }

    /**
     * Monta um cenário com a linha Shopee inserida ANTES da linha Performance
     * (ordem que derruba um `->first()` ingênuo — se invertida, o teste passa
     * com código bugado, já que DISTINCT costuma ordenar por id crescente).
     * A linha Shopee também recebe assigned_at/created_at mais ANTIGO, dupla
     * garantia contra qualquer critério de ordenação natural da query.
     *
     * @return array{company: Company, analistaPerf: User, analistaShopee: User}
     */
    private function montarCenarioShopeeAntesDePerformance(): array
    {
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        // Shopee PRIMEIRO — usuário e linha de pivot nascem com id menor e
        // timestamp mais antigo que o analista de performance.
        $analistaShopee = User::factory()->create();
        $shopee = $this->inserirLinhaShopee($company->id, $analistaShopee->id, 'consultor');
        DB::table('company_users')->where('id', $shopee['row'])->update([
            'assigned_at' => now()->subDays(30)->toDateString(),
            'created_at'  => now()->subDays(30),
            'updated_at'  => now()->subDays(30),
        ]);

        // Performance DEPOIS.
        $analistaPerf = User::factory()->create();
        $this->inserirPivot($company->id, $analistaPerf->id, 'consultor', $servicoPerf);

        return [
            'company'        => $company,
            'analistaPerf'   => $analistaPerf,
            'analistaShopee' => $analistaShopee,
        ];
    }

    /**
     * TESTE ANTI-->first()-INGÊNUO (index) — a linha Shopee é inserida ANTES
     * da linha Performance (com timestamp mais antigo também). Um `->first()`
     * sem filtro por setor devolveria o analista Shopee; a relação nova deve
     * devolver o analista de Performance mesmo assim.
     */
    public function test_index_mostra_analista_performance_mesmo_com_pivot_shopee_inserida_antes(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->montarCenarioShopeeAntesDePerformance();

        $response = $this->actingAs($admin)->get(route('companies.index'));
        $response->assertOk();

        $props    = $response->viewData('page')['props'];
        $empresaP = $this->acharEmpresa($props['companies'], $cenario['company']->id);

        $this->assertNotNull($empresaP['consultor'], 'Coluna Analista não pode vir vazia.');
        $this->assertSame(
            $cenario['analistaPerf']->id,
            $empresaP['consultor']['id'],
            'Coluna Analista deve mostrar o responsável de PERFORMANCE, nunca o Shopee — mesmo com a linha Shopee inserida antes.'
        );
    }

    /**
     * Mesmo cenário do teste anterior, mas via `show()` — shape ARRAY
     * preservado (não objeto único), contendo SÓ o analista de performance.
     */
    public function test_show_mostra_apenas_analista_performance_shape_array(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->montarCenarioShopeeAntesDePerformance();

        $response = $this->actingAs($admin)->get(route('companies.show', $cenario['company']));
        $response->assertOk();

        $props     = $response->viewData('page')['props'];
        $consultor = $props['company']['consultor'];

        $this->assertIsArray($consultor, 'Shape do consultor em show() deve continuar array.');
        $this->assertCount(1, $consultor, 'Show() deve trazer só o analista de performance, não o Shopee.');
        $this->assertSame($cenario['analistaPerf']->id, $consultor[0]['id']);
    }

    /**
     * Pendência OR — empresa com contrato performance ativo, mas cujo ÚNICO
     * vínculo é estrategista SHOPEE (nenhum vínculo de performance): a
     * pendência `sem_responsavel` deve acusar.
     */
    public function test_pendencia_acusa_empresa_so_com_estrategista_shopee(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $estrategistaShopee = User::factory()->create();
        $this->inserirLinhaShopee($company->id, $estrategistaShopee->id, 'estrategista');

        $response = $this->actingAs($admin)->get(route('companies.index'));
        $response->assertOk();

        $props    = $response->viewData('page')['props'];
        $empresaP = $this->acharEmpresa($props['companies'], $company->id);

        $this->assertContains(
            'sem_responsavel',
            $empresaP['pendencias'],
            'Empresa sem NENHUM vínculo de performance (só estrategista Shopee) deve acusar sem_responsavel.'
        );
    }

    /**
     * Contraprova 1 — empresa com analista E estrategista de performance
     * atribuídos: NÃO deve acusar sem_responsavel.
     */
    public function test_pendencia_nao_acusa_com_analista_e_estrategista_performance(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();

        $response = $this->actingAs($admin)->get(route('companies.index'));
        $response->assertOk();

        $props    = $response->viewData('page')['props'];
        $empresaP = $this->acharEmpresa($props['companies'], $cenario['company']->id);

        $this->assertNotContains(
            'sem_responsavel',
            $empresaP['pendencias'],
            'Empresa com analista E estrategista de performance não deve acusar sem_responsavel.'
        );
    }

    /**
     * Contraprova 2 (mudança AND→OR explícita) — empresa com analista de
     * performance mas SEM estrategista de performance: com AND (comportamento
     * antigo) não acusaria; com OR (novo) DEVE acusar.
     */
    public function test_pendencia_acusa_com_analista_mas_sem_estrategista_performance(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $analista = User::factory()->create();
        $this->inserirPivot($company->id, $analista->id, 'consultor', $servicoPerf);
        // Nenhum vínculo de estrategista — nem performance, nem shopee.

        $response = $this->actingAs($admin)->get(route('companies.index'));
        $response->assertOk();

        $props    = $response->viewData('page')['props'];
        $empresaP = $this->acharEmpresa($props['companies'], $company->id);

        $this->assertContains(
            'sem_responsavel',
            $empresaP['pendencias'],
            'AND→OR: empresa com só 1 dos 2 papéis de performance preenchido deve acusar sem_responsavel (comportamento novo).'
        );
    }

    /**
     * Mentoria — vínculo aponta para um SEGUNDO serviço de setor performance
     * (id ≠ Gestão). `analistaPerformance()` deve resolver sem hardcode de id.
     */
    public function test_analista_performance_resolve_segundo_servico_setor_performance(): void
    {
        $company = Company::factory()->create();
        // Serviço "Gestão" (o do cenário padrão) + um segundo serviço de
        // performance simulando "Mentoria" — ids diferentes, mesmo setor.
        $servicoMentoria = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoMentoria, true);

        $analista = User::factory()->create();
        $this->inserirPivot($company->id, $analista->id, 'consultor', $servicoMentoria);

        $company = Company::find($company->id);

        $this->assertSame(
            $analista->id,
            $company->analistaPerformance->first()->id,
            'analistaPerformance() deve resolver o responsável do segundo serviço de setor performance, sem hardcode de servico_id.'
        );
    }

    /**
     * Legado CTX-05 — vínculo `servico_id NULL` em empresa com contrato
     * performance ATIVO: `analistaPerformance()` encontra o responsável.
     */
    public function test_analista_performance_resolve_ramo_legado_servico_id_null(): void
    {
        $company = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $analista = User::factory()->create();
        $this->inserirPivot($company->id, $analista->id, 'consultor', null);

        $company = Company::find($company->id);

        $this->assertSame(
            $analista->id,
            $company->analistaPerformance->first()->id,
            'Ramo legado (servico_id NULL) com contrato performance ativo deve resolver via analistaPerformance().'
        );
    }

    /**
     * Legado CTX-05 (negativo) — vínculo `servico_id NULL` em empresa SEM
     * contrato performance ativo (só Shopee): `analistaPerformance()` NÃO
     * encontra ninguém — nunca promove o legado a Shopee automaticamente.
     */
    public function test_analista_performance_nao_resolve_legado_sem_contrato_performance_ativo(): void
    {
        $company = Company::factory()->create();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servicoShopee, true);

        $userShopee = User::factory()->create();
        // Vínculo legado (servico_id NULL) — mas a empresa só tem contrato
        // Shopee ativo, nunca performance.
        $this->inserirPivot($company->id, $userShopee->id, 'consultor', null);

        $company = Company::find($company->id);

        $this->assertTrue(
            $company->analistaPerformance->isEmpty(),
            'Sem contrato performance ativo, o ramo legado NÃO pode resolver responsável (nunca promove a Shopee).'
        );
    }

    /**
     * Invariante — após inserir a linha Shopee, as relações ANTIGAS
     * `consultor()`/`estrategista()` continuam consolidadas (count 1, mesmo
     * user) — os 9 call-sites consolidados não podem regredir.
     */
    public function test_relacoes_antigas_consultor_estrategista_permanecem_consolidadas(): void
    {
        $cenario  = $this->criarCenarioMlComResponsaveis();
        $company  = $cenario['company'];
        $analista = $cenario['analista'];

        $this->inserirLinhaShopee($company->id, $analista->id, 'consultor');

        $company = Company::find($company->id);

        $this->assertSame(1, $company->consultor()->count(), 'consultor() consolidado deve continuar dedup (count 1).');
        $this->assertSame($analista->id, $company->consultor()->first()->id, 'consultor()->first() deve continuar apontando pro mesmo analista.');
    }
}
