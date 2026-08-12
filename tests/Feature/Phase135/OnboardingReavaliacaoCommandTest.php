<?php

namespace Tests\Feature\Phase135;

use App\Contracts\OnboardingResolver;
use App\Jobs\ResolveOnboardingPassoJob;
use App\Models\Company;
use App\Models\MlToken;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\OnboardingTemplate;
use App\Models\Servico;
use App\Models\TemplatePasso;
use App\Services\Onboarding\OnboardingResolverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 135 Plano 07 (Task 2) — comando `onboarding:reavaliar-passos`, a
 * passada periódica que reconfere os passos automáticos que ficaram
 * esperando (Pitfall 3: o resolver do passo 8 dispara a coleta e devolve o
 * controle, alguém precisa voltar depois).
 */
class OnboardingReavaliacaoCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Monta um Onboarding + OnboardingPasso mínimos com o `auto_fonte`
     * informado, mesmo molde dos testes de resolver dos Planos 06/07.
     */
    private function criarOnboardingComPasso(
        Company $company,
        string $chave,
        string $autoFonte,
        string $statusOnboarding = Onboarding::STATUS_ANDAMENTO,
        string $statusPasso = OnboardingPasso::STATUS_ABERTO,
    ): array {
        $servico = Servico::create([
            'nome'          => 'Gestao ' . uniqid(),
            'valor_padrao'  => 3000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        $template = OnboardingTemplate::create([
            'servico_id'   => $servico->id,
            'versao'       => 1,
            'ativo'        => true,
            'publicado_em' => now(),
        ]);

        $templatePasso = TemplatePasso::create([
            'template_id' => $template->id,
            'ordem'       => 1,
            'chave'       => $chave,
            'titulo'      => $chave,
            'dono'        => TemplatePasso::DONO_SISTEMA,
            'auto_fonte'  => $autoFonte,
        ]);

        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'template_id' => $template->id,
            'status'      => $statusOnboarding,
            'iniciado_em' => $statusOnboarding === Onboarding::STATUS_ANDAMENTO ? now() : null,
        ]);

        $passo = OnboardingPasso::create([
            'onboarding_id'     => $onboarding->id,
            'template_passo_id' => $templatePasso->id,
            'chave'             => $chave,
            'status'            => $statusPasso,
            'disponivel_em'     => now(),
        ]);

        return [$onboarding, $passo];
    }

    private function criarTokenAtivo(Company $company): MlToken
    {
        return MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => 'ML_USER_' . $company->id,
            'access_token'  => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_at'    => now()->addHours(6),
            'status'        => 'active',
            'connected_at'  => now(),
        ]);
    }

    /**
     * Substitui o `OnboardingResolverFactory` do container por um que lança
     * exceção para uma chave específica e delega todo o resto ao factory
     * real — usado só no teste de resiliência a falha isolada.
     */
    private function bindFactoryComFalhaSimulada(string $chaveComFalha): void
    {
        $original = app(OnboardingResolverFactory::class);

        $fake = new class($original, $chaveComFalha) extends OnboardingResolverFactory {
            public function __construct(private OnboardingResolverFactory $original, private string $chaveComFalha)
            {
                // Não chama parent::__construct() de propósito — delega tudo
                // ao factory real já construído, sem reconstruir o catálogo.
            }

            public function for(string $autoFonte): OnboardingResolver
            {
                if ($autoFonte === $this->chaveComFalha) {
                    throw new \RuntimeException('Falha simulada para teste de resiliência do comando');
                }

                return $this->original->for($autoFonte);
            }
        };

        $this->app->instance(OnboardingResolverFactory::class, $fake);
    }

    /** @test */
    public function roda_sem_erro_numa_base_sem_onboardings(): void
    {
        $this->artisan('onboarding:reavaliar-passos', ['--limite' => 1])
            ->assertExitCode(0);
    }

    /** @test */
    public function onboarding_em_rascunho_e_ignorado(): void
    {
        Queue::fake();

        $company = Company::factory()->create();
        $this->criarTokenAtivo($company);
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            $company,
            'grant_sistema_ecf',
            TemplatePasso::AUTO_FONTE_ML_TOKEN,
            statusOnboarding: Onboarding::STATUS_RASCUNHO,
        );

        $this->artisan('onboarding:reavaliar-passos')->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->fresh()->status);
        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->fresh()->status);
    }

    /** @test */
    public function passo_sincrono_pendente_e_resolvido_inline_sem_job(): void
    {
        Queue::fake();

        $company = Company::factory()->create();
        $this->criarTokenAtivo($company);
        [, $passo] = $this->criarOnboardingComPasso(
            $company,
            'grant_sistema_ecf',
            TemplatePasso::AUTO_FONTE_ML_TOKEN,
        );

        $this->artisan('onboarding:reavaliar-passos')->assertExitCode(0);

        Queue::assertNotPushed(ResolveOnboardingPassoJob::class);
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->fresh()->status);
    }

    /** @test */
    public function passo_assincrono_pendente_despacha_o_job_de_resolucao(): void
    {
        Queue::fake();

        $company = Company::factory()->create(['adman_account_id' => 'CUST_REAVAL']);
        [, $passo] = $this->criarOnboardingComPasso(
            $company,
            'grant_consultoria_adman',
            TemplatePasso::AUTO_FONTE_ADMAN_GRANT,
        );

        $this->artisan('onboarding:reavaliar-passos')->assertExitCode(0);

        Queue::assertPushed(ResolveOnboardingPassoJob::class, fn ($job) => $job->passo->is($passo));
        // O comando nunca resolve inline um passo assíncrono — status
        // permanece aberto até o job rodar de verdade.
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->fresh()->status);
    }

    /** @test */
    public function falha_isolada_num_passo_nao_impede_o_processamento_dos_demais(): void
    {
        Queue::fake();

        $companyComFalha = Company::factory()->create();
        $this->criarTokenAtivo($companyComFalha);
        [, $passoComFalha] = $this->criarOnboardingComPasso(
            $companyComFalha,
            'grant_sistema_ecf',
            TemplatePasso::AUTO_FONTE_ML_TOKEN,
        );

        $companyOk = Company::factory()->create(['adman_account_id' => 'CUST_OK_RESILIENCIA']);
        [, $passoOk] = $this->criarOnboardingComPasso(
            $companyOk,
            'grant_consultoria_adman',
            TemplatePasso::AUTO_FONTE_ADMAN_GRANT,
        );

        $this->bindFactoryComFalhaSimulada(TemplatePasso::AUTO_FONTE_ML_TOKEN);

        $this->artisan('onboarding:reavaliar-passos')
            ->expectsOutputToContain('falhas=1')
            ->assertExitCode(0);

        // O passo cuja resolução lançou exceção não muda de estado...
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passoComFalha->fresh()->status);
        // ...mas o outro passo, de outro onboarding, foi processado normalmente.
        Queue::assertPushed(ResolveOnboardingPassoJob::class, fn ($job) => $job->passo->is($passoOk));
    }

    /** @test */
    public function limite_restringe_a_quantidade_de_passos_processados(): void
    {
        Queue::fake();

        $passos = [];
        for ($i = 0; $i < 3; $i++) {
            $company = Company::factory()->create();
            $this->criarTokenAtivo($company);
            [, $passo] = $this->criarOnboardingComPasso(
                $company,
                'grant_sistema_ecf',
                TemplatePasso::AUTO_FONTE_ML_TOKEN,
            );
            $passos[] = $passo;
        }

        $this->artisan('onboarding:reavaliar-passos', ['--limite' => 1])->assertExitCode(0);

        $concluidos = collect($passos)->filter(fn ($p) => $p->fresh()->status === OnboardingPasso::STATUS_CONCLUIDO)->count();
        $this->assertSame(1, $concluidos);
    }

    /** @test */
    public function opcao_onboarding_restringe_o_escopo_a_um_unico_onboarding(): void
    {
        Queue::fake();

        $companyA = Company::factory()->create();
        $this->criarTokenAtivo($companyA);
        [$onboardingA, $passoA] = $this->criarOnboardingComPasso(
            $companyA,
            'grant_sistema_ecf',
            TemplatePasso::AUTO_FONTE_ML_TOKEN,
        );

        $companyB = Company::factory()->create();
        $this->criarTokenAtivo($companyB);
        [, $passoB] = $this->criarOnboardingComPasso(
            $companyB,
            'grant_sistema_ecf',
            TemplatePasso::AUTO_FONTE_ML_TOKEN,
        );

        $this->artisan('onboarding:reavaliar-passos', ['--onboarding' => $onboardingA->id])
            ->assertExitCode(0);

        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passoA->fresh()->status);
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passoB->fresh()->status);
    }

    /** @test */
    public function rodar_duas_vezes_seguidas_e_idempotente(): void
    {
        Queue::fake();

        $company = Company::factory()->create();
        $this->criarTokenAtivo($company);
        [, $passo] = $this->criarOnboardingComPasso(
            $company,
            'grant_sistema_ecf',
            TemplatePasso::AUTO_FONTE_ML_TOKEN,
        );

        $this->artisan('onboarding:reavaliar-passos')->assertExitCode(0);
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->fresh()->status);
        $tentativasAposPrimeiraRodada = $passo->fresh()->tentativas;

        $this->artisan('onboarding:reavaliar-passos')->assertExitCode(0);

        $passoFinal = $passo->fresh();
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passoFinal->status);
        $this->assertSame($tentativasAposPrimeiraRodada, $passoFinal->tentativas);
    }
}
