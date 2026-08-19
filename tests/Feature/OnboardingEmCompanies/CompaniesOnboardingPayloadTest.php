<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * `/companies` passa a carregar a leitura de Onboarding de cada empresa — a
 * mesma que o painel `/onboarding` mostra, vinda da mesma fonte.
 *
 * O teste que mais importa aqui é o de PARIDADE: o dia em que alguém alterar a
 * régua de situação de um lado só, ele quebra. Era exatamente essa divergência
 * silenciosa que a extração do `OnboardingSituacaoService` foi feita para
 * impedir.
 */
class CompaniesOnboardingPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::create([
            'name'     => 'Admin OnbCompanies '.uniqid(),
            'email'    => 'admin.onbc.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /** Serviço de Performance SEM definição de onboarding (não é "Gestão"). */
    private function servicoPerformanceSemOnboarding(): Servico
    {
        return Servico::create([
            'nome'          => 'Mentoria '.uniqid(),
            'valor_padrao'  => 1500,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);
    }

    private function criarEmpresa(): Company
    {
        return Company::create([
            'name'              => 'Empresa Onb '.uniqid(),
            'cnpj'              => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'            => true,
            'status'            => 'ativo',
            'email_colaborador' => 'colab.'.uniqid().'@ecf.test',
            'adman_account_id'  => (string) random_int(100000, 999999),
            'empresa_nova'      => false,
        ]);
    }

    private function contrato(Company $company, Servico $servico): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
    }

    /** @return array{0:Company,1:Onboarding} */
    private function empresaComOnboarding(): array
    {
        $company = $this->criarEmpresa();
        $contrato = $this->contrato($company, $this->servicoDeGestao());
        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        return [$company, $onboarding];
    }

    private function linhaDaEmpresa(array $companies, int $companyId): ?array
    {
        foreach ($companies as $linha) {
            if ($linha['id'] === $companyId) {
                return $linha;
            }
        }

        return null;
    }

    /** @test */
    public function listagem_expoe_onboardings_e_resumo_por_empresa(): void
    {
        $this->admin();
        [$company, $onboarding] = $this->empresaComOnboarding();

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($company, $onboarding) {
                $linha = $this->linhaDaEmpresa($page->toArray()['props']['companies'], $company->id);

                $this->assertNotNull($linha, 'A empresa com onboarding sumiu da listagem.');
                $this->assertCount(1, $linha['onboardings']);
                $this->assertSame($onboarding->id, $linha['onboardings'][0]['id']);

                // Rascunho recém-criado: é o que a tela precisa destacar.
                $this->assertSame('rascunho', $linha['onboarding_resumo']['situacao']);
                $this->assertTrue($linha['onboarding_resumo']['em_rascunho']);
                $this->assertSame(1, $linha['onboarding_resumo']['total']);
                $this->assertNotNull($linha['onboarding_resumo']['chegou_em']);
            });
    }

    /**
     * Empresa sem onboarding nenhum não pode quebrar a linha — a maior parte
     * da base é assim (só Gestão tem definição hoje).
     *
     * @test
     */
    public function empresa_sem_onboarding_traz_lista_vazia_e_resumo_nulo(): void
    {
        $this->admin();
        $company = $this->criarEmpresa();
        $this->contrato($company, $this->servicoPerformanceSemOnboarding());

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($company) {
                $linha = $this->linhaDaEmpresa($page->toArray()['props']['companies'], $company->id);

                $this->assertNotNull($linha);
                $this->assertSame([], $linha['onboardings']);
                $this->assertNull($linha['onboarding_resumo']);
            });
    }

    /**
     * Uma linha por EMPRESA, com os onboardings dentro — e o resumo aponta
     * para o mais grave, porque a linha responde "esta empresa precisa de mim
     * agora?".
     *
     * @test
     */
    public function empresa_com_dois_onboardings_aparece_uma_vez_e_o_resumo_pega_o_mais_grave(): void
    {
        $this->admin();
        [$company, $rascunho] = $this->empresaComOnboarding();

        // Segundo onboarding, já em andamento — menos grave que o rascunho.
        $outroServico = Servico::create([
            'nome'          => 'Gestão Shopee '.uniqid(),
            'valor_padrao'  => 1500,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);
        $contrato2 = $this->contrato($company, $outroServico);
        $segundo = Onboarding::where('contrato_servico_id', $contrato2->id)->firstOrFail();
        (new OnboardingEngineService())->definirResponsaveis($segundo, null, User::factory()->create());

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($company, $rascunho) {
                $linhas = array_filter(
                    $page->toArray()['props']['companies'],
                    fn ($l) => $l['id'] === $company->id
                );

                $this->assertCount(1, $linhas, 'A empresa foi listada mais de uma vez.');

                $linha = array_values($linhas)[0];
                $this->assertCount(2, $linha['onboardings']);
                $this->assertSame(2, $linha['onboarding_resumo']['total']);
                // O rascunho é o mais grave — nem SLA corre, nem o cliente vê.
                $this->assertSame('rascunho', $linha['onboarding_resumo']['situacao']);
                $this->assertSame($rascunho->id, $linha['onboarding_resumo']['onboarding_id']);
            });
    }

    /**
     * PARIDADE — o resumo do onboarding em `/companies` é idêntico ao do
     * painel `/onboarding`. Quebra no dia em que alguém mudar a régua de um
     * lado só.
     *
     * @test
     */
    public function o_resumo_em_companies_e_identico_ao_do_painel_de_onboarding(): void
    {
        $this->admin();
        [$company, $onboarding] = $this->empresaComOnboarding();
        (new OnboardingEngineService())->definirResponsaveis($onboarding, null, User::factory()->create());

        $doPainel = null;
        $this->get(route('onboarding.painel.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$doPainel, $company) {
                foreach ($page->toArray()['props']['empresas'] as $empresa) {
                    if ($empresa['empresa']['id'] === $company->id) {
                        $doPainel = $empresa['onboardings'][0];
                    }
                }
            });

        $deCompanies = null;
        $this->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$deCompanies, $company) {
                $linha = $this->linhaDaEmpresa($page->toArray()['props']['companies'], $company->id);
                $deCompanies = $linha['onboardings'][0];
            });

        $this->assertNotNull($doPainel, 'O onboarding não apareceu no painel.');
        $this->assertNotNull($deCompanies, 'O onboarding não apareceu em /companies.');
        $this->assertSame($doPainel, $deCompanies);
    }

    /** Consultas gastas por UMA requisição a /companies, sem contar a preparação. */
    private function consultasDaListagem(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('companies.index'))->assertOk();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $total;
    }

    /**
     * O eager loading do onboarding precisa segurar: a listagem não tem
     * paginação, e N+1 aqui é a página inteira caindo (foi assim que
     * /dashboard morreu de OOM antes).
     *
     * O teste compara DELTAS em vez de exigir contagem constante porque
     * `/companies` já tem um N+1 pré-existente — o accessor `cust_id` consulta
     * `company_marketplaces` por empresa. Esse custo não é assunto deste
     * trabalho; o que este teste trava é que empresa COM onboarding não custe
     * mais consultas do que empresa SEM.
     *
     * @test
     */
    public function empresa_com_onboarding_nao_custa_mais_consultas_do_que_empresa_sem(): void
    {
        $this->admin();
        $semOnboarding = $this->servicoPerformanceSemOnboarding();

        $base = $this->consultasDaListagem();

        for ($i = 0; $i < 3; $i++) {
            $this->contrato($this->criarEmpresa(), $semOnboarding);
        }
        $comTresSemOnboarding = $this->consultasDaListagem();

        for ($i = 0; $i < 3; $i++) {
            $this->empresaComOnboarding();
        }
        $maisTresComOnboarding = $this->consultasDaListagem();

        $custoSem = $comTresSemOnboarding - $base;
        $custoCom = $maisTresComOnboarding - $comTresSemOnboarding;

        // `lessThanOrEqual`, não igualdade: a listagem tem ruído de 1 consulta
        // por empresa que não vem do onboarding, e um teste que quebra com
        // ruído deixa de ser lido. O que precisa ser impossível é o salto de
        // N+1 de verdade — carregar passos dentro do laço custaria +15 por
        // empresa, não ±1.
        $this->assertLessThanOrEqual(
            $custoSem,
            $custoCom,
            "3 empresas SEM onboarding custaram {$custoSem} consultas; 3 COM onboarding custaram {$custoCom}. "
            . 'A diferença é N+1 introduzido pelo onboarding.'
        );
    }

    /** @test */
    public function definir_responsaveis_inicia_o_onboarding_a_partir_de_companies(): void
    {
        $this->admin();
        [, $onboarding] = $this->empresaComOnboarding();
        $analista = User::factory()->create();

        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->status);

        $this->post(route('onboarding.responsaveis.definir', $onboarding), [
            'responsavel_analista_id' => $analista->id,
        ])->assertRedirect();

        $onboarding->refresh();

        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $onboarding->status);
        $this->assertSame($analista->id, $onboarding->responsavel_analista_id);
        $this->assertNotNull($onboarding->iniciado_em);
    }

    /** @test */
    public function definir_responsaveis_sem_nenhum_dos_dois_e_recusado(): void
    {
        $this->admin();
        [, $onboarding] = $this->empresaComOnboarding();

        $this->post(route('onboarding.responsaveis.definir', $onboarding), [])
            ->assertSessionHasErrors('responsavel_analista_id');

        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->fresh()->status);
    }
}
