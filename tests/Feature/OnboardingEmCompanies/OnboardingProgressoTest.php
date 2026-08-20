<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingSituacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * O cockpit de `/companies?tab=onboarding` ganhou colunas de PROGRESSO e
 * ATIVIDADES (20/08), e a tela individual ganhou a mesma barra.
 *
 * O teste que mais importa aqui é o de PARIDADE: no dia em que alguém contar
 * atividade de um jeito na listagem e de outro no detalhe, ele quebra. Foi
 * exatamente para impedir esse tipo de divergência silenciosa que a fração
 * passou a sair de `OnboardingSituacaoService::progresso()`, e não de cada
 * tela.
 *
 * A regra de negócio sob teste: `nao_aplicavel` sai dos DOIS lados da fração.
 * No denominador ele trava o onboarding num teto abaixo de 100% para sempre;
 * no numerador ele infla o andamento de quem teve muita coisa dispensada.
 */
class OnboardingProgressoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Progresso '.uniqid(),
            'email'    => 'admin.prog.'.uniqid().'@ecf.test',
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

    /** @return array{0:Company,1:Onboarding} */
    private function empresaComOnboarding(): array
    {
        $company = Company::create([
            'name'              => 'Empresa Prog '.uniqid(),
            'cnpj'              => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'            => true,
            'status'            => 'ativo',
            'email_colaborador' => 'colab.'.uniqid().'@ecf.test',
            'adman_account_id'  => (string) random_int(100000, 999999),
            'empresa_nova'      => false,
        ]);

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $this->servicoDeGestao()->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        return [$company, $onboarding];
    }

    /**
     * Marca os `$quantos` primeiros passos (por ordem) com `$status`, pulando
     * os que já foram tocados por uma chamada anterior.
     */
    private function marcar(Onboarding $onboarding, string $status, int $quantos, array $jaUsados = []): array
    {
        $ids = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->whereNotIn('id', $jaUsados)
            ->orderBy('ordem')
            ->limit($quantos)
            ->pluck('id')
            ->all();

        OnboardingPasso::whereIn('id', $ids)->update(['status' => $status]);

        return array_merge($jaUsados, $ids);
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
    public function progresso_conta_concluidos_sobre_o_total_de_atividades(): void
    {
        $this->admin();
        [, $onboarding] = $this->empresaComOnboarding();

        $total = $onboarding->passos()->count();
        $this->assertGreaterThan(4, $total, 'A definição precisa ter passos suficientes para o teste ser significativo.');

        $this->marcar($onboarding, OnboardingPasso::STATUS_CONCLUIDO, 4);

        $progresso = app(OnboardingSituacaoService::class)
            ->progresso($onboarding->fresh()->passos);

        $this->assertSame(4, $progresso['feitos']);
        $this->assertSame($total, $progresso['total']);
        $this->assertSame((int) round(4 / $total * 100), $progresso['percentual']);
    }

    /**
     * `nao_aplicavel` não é atividade: sai do numerador E do denominador.
     *
     * @test
     */
    public function nao_aplicavel_sai_dos_dois_lados_da_fracao(): void
    {
        $this->admin();
        [, $onboarding] = $this->empresaComOnboarding();

        $total = $onboarding->passos()->count();

        $usados = $this->marcar($onboarding, OnboardingPasso::STATUS_CONCLUIDO, 3);
        $this->marcar($onboarding, OnboardingPasso::STATUS_NAO_APLICAVEL, 2, $usados);

        $progresso = app(OnboardingSituacaoService::class)
            ->progresso($onboarding->fresh()->passos);

        // 3 feitos, e o denominador perdeu os 2 dispensados — nunca os ganhou
        // como "feitos".
        $this->assertSame(3, $progresso['feitos']);
        $this->assertSame($total - 2, $progresso['total']);
    }

    /**
     * Onboarding em que TODA atividade foi dispensada tem `total = 0`. O
     * percentual precisa devolver 0 em vez de estourar divisão por zero.
     *
     * @test
     */
    public function tudo_nao_aplicavel_nao_estoura_divisao_por_zero(): void
    {
        $this->admin();
        [, $onboarding] = $this->empresaComOnboarding();

        OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->update(['status' => OnboardingPasso::STATUS_NAO_APLICAVEL]);

        $progresso = app(OnboardingSituacaoService::class)
            ->progresso($onboarding->fresh()->passos);

        $this->assertSame(0, $progresso['total']);
        $this->assertSame(0, $progresso['feitos']);
        $this->assertSame(0, $progresso['percentual']);
    }

    /**
     * Onboarding com tudo concluído chega a 100% — o teto precisa ser
     * alcançável, senão a barra nunca diz "acabou".
     *
     * @test
     */
    public function tudo_concluido_chega_a_cem_por_cento(): void
    {
        $this->admin();
        [, $onboarding] = $this->empresaComOnboarding();

        OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->update(['status' => OnboardingPasso::STATUS_CONCLUIDO]);

        $progresso = app(OnboardingSituacaoService::class)
            ->progresso($onboarding->fresh()->passos);

        $this->assertSame(100, $progresso['percentual']);
    }

    /**
     * PARIDADE — a listagem e o detalhe mostram a MESMA fração.
     *
     * Este é o teste que protege a promessa da tela: "se a linha diz 45%, o
     * detalhe diz 45%". Ele quebra no instante em que alguém recalcular o
     * progresso numa das duas pontas.
     *
     * @test
     */
    public function listagem_e_detalhe_mostram_o_mesmo_progresso(): void
    {
        $this->admin();
        [$company, $onboarding] = $this->empresaComOnboarding();

        $usados = $this->marcar($onboarding, OnboardingPasso::STATUS_CONCLUIDO, 5);
        $this->marcar($onboarding, OnboardingPasso::STATUS_NAO_APLICAVEL, 2, $usados);

        $daListagem = null;

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($company, &$daListagem) {
                $linha = $this->linhaDaEmpresa($page->toArray()['props']['companies'], $company->id);

                $this->assertNotNull($linha, 'A empresa com onboarding sumiu da listagem.');

                // O cockpit desenha uma linha por ONBOARDING, e é de
                // `onboardings[]` que ele lê o progresso.
                $daListagem = $linha['onboardings'][0]['progresso'];

                // O resumo da empresa carrega a mesma fração do onboarding
                // mais grave — nunca uma média entre serviços.
                $this->assertSame($daListagem, $linha['onboarding_resumo']['progresso']);
            });

        $this->get(route('onboarding.painel.show', $onboarding->id))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($daListagem) {
                $doDetalhe = $page->toArray()['props']['onboarding']['progresso'];

                $this->assertSame(
                    $daListagem,
                    $doDetalhe,
                    'Listagem e detalhe divergiram no progresso do mesmo onboarding.'
                );
            });

        $this->assertSame(5, $daListagem['feitos']);
    }
}
