<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 135 Plano 05 (Task 2) — cobre `OnboardingEngineService::sugerirResponsavel()`,
 * `confirmarResponsavel()` e `podeIniciar()`: rascunho nasce com sugestão (D-17)
 * mas não corre SLA (D-05/SC-04) até a Coordenação confirmar o responsável.
 */
class OnboardingTransicaoStatusTest extends TestCase
{
    use RefreshDatabase;

    /** O Servico "Gestão" real, publicado pelas migrations do catálogo. */
    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /** Insere uma linha de responsável em `company_users`, servico-específica. */
    private function vincularResponsavel(Company $company, User $user, string $role, int $servicoId): void
    {
        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => $role,
            'servico_id'  => $servicoId,
            'assigned_at' => now()->toDateString(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** Contrato de Gestão para uma empresa nova — dispara o Observer (Task 1). */
    private function criarContratoDeGestao(Company $company): ContratoServico
    {
        return ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);
    }

    protected function setUp(): void
    {
        parent::setUp();

    }

    /** @test */
    public function onboarding_nasce_em_rascunho_com_responsavel_sugerido_quando_ha_vinculo_consultor(): void
    {
        $servico = $this->servicoDeGestao();
        $company = Company::factory()->create();
        $consultor = User::factory()->create();
        $this->vincularResponsavel($company, $consultor, 'consultor', $servico->id);

        $contrato = $this->criarContratoDeGestao($company);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->status);
        $this->assertSame($consultor->id, $onboarding->responsavel_id);
    }

    /** @test */
    public function onboarding_nasce_com_responsavel_via_fallback_estrategista_quando_nao_ha_consultor(): void
    {
        $servico = $this->servicoDeGestao();
        $company = Company::factory()->create();
        $estrategista = User::factory()->create();
        $this->vincularResponsavel($company, $estrategista, 'estrategista', $servico->id);

        $contrato = $this->criarContratoDeGestao($company);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $this->assertSame($estrategista->id, $onboarding->responsavel_id);
    }

    /** @test */
    public function onboarding_nasce_sem_responsavel_quando_nao_ha_vinculo_nenhum_e_nao_pode_iniciar(): void
    {
        $company = Company::factory()->create();
        $contrato = $this->criarContratoDeGestao($company);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $this->assertNull($onboarding->responsavel_id);
        $this->assertFalse((new OnboardingEngineService())->podeIniciar($onboarding));
    }

    /** @test */
    public function rascunho_nao_carimba_disponivel_em_em_nenhum_passo_d05_sc04(): void
    {
        $servico = $this->servicoDeGestao();
        $company = Company::factory()->create();
        $consultor = User::factory()->create();
        $this->vincularResponsavel($company, $consultor, 'consultor', $servico->id);

        $contrato = $this->criarContratoDeGestao($company);
        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->status);
        $this->assertSame(
            0,
            OnboardingPasso::where('onboarding_id', $onboarding->id)->whereNotNull('disponivel_em')->count()
        );
    }

    /** @test */
    public function confirmar_responsavel_leva_a_andamento_grava_iniciado_em_e_destrava_os_6_passos_sem_dependencia(): void
    {
        $servico = $this->servicoDeGestao();
        $company = Company::factory()->create();
        $consultor = User::factory()->create();
        $this->vincularResponsavel($company, $consultor, 'consultor', $servico->id);

        $contrato = $this->criarContratoDeGestao($company);
        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $confirmado = (new OnboardingEngineService())->confirmarResponsavel($onboarding, $consultor);

        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $confirmado->status);
        $this->assertNotNull($confirmado->iniciado_em);
        $this->assertSame($consultor->id, $confirmado->responsavel_id);
        // 6 desde que a ficha da conta entrou na definição: ela não depende de
        // nada, então destrava junto com os outros 5.
        $this->assertSame(
            6,
            OnboardingPasso::where('onboarding_id', $confirmado->id)->whereNotNull('disponivel_em')->count()
        );
    }

    /** @test */
    public function confirmar_responsavel_sobre_onboarding_ja_em_andamento_lanca_domain_exception(): void
    {
        $servico = $this->servicoDeGestao();
        $company = Company::factory()->create();
        $consultor = User::factory()->create();
        $this->vincularResponsavel($company, $consultor, 'consultor', $servico->id);

        $contrato = $this->criarContratoDeGestao($company);
        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $service = new OnboardingEngineService();
        $service->confirmarResponsavel($onboarding, $consultor);

        $this->expectException(\DomainException::class);
        $service->confirmarResponsavel($onboarding->fresh(), $consultor);
    }

    /** @test */
    public function roles_responsavel_sugerido_e_consultor_e_estrategista_nesta_ordem(): void
    {
        $this->assertSame(['consultor', 'estrategista'], Onboarding::ROLES_RESPONSAVEL_SUGERIDO);
    }
}
