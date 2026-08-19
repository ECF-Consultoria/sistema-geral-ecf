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
use Tests\TestCase;

/**
 * O onboarding passa a ter DOIS responsáveis — estrategista e analista (R-01) —
 * e **qualquer um dos dois liga o SLA** (R-02).
 *
 * Desenho e motivos em
 * `.planning/seeds/onboarding-dois-responsaveis-decisao-schema.md`.
 *
 * O que estes testes travam, e que é fácil quebrar sem perceber:
 *  - `responsavel_id` continua sendo o responsável PRINCIPAL e sempre aponta
 *    para um dos dois slots (invariante do §2.2 da decisão de schema);
 *  - preencher o papel que faltava NÃO re-liga o SLA nem reescreve
 *    `iniciado_em` — senão a data de início mudaria toda vez que alguém
 *    completasse um cadastro.
 */
class DoisResponsaveisTest extends TestCase
{
    use RefreshDatabase;

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

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

    /** Empresa SEM vínculo nenhum — o onboarding nasce em rascunho e sem sugestão. */
    private function onboardingEmRascunho(): Onboarding
    {
        $company = Company::factory()->create();

        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        return Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();
    }

    /** @test */
    public function so_o_estrategista_ja_liga_o_sla_e_deixa_o_slot_de_analista_vazio(): void
    {
        $onboarding = $this->onboardingEmRascunho();
        $estrategista = User::factory()->create();

        (new OnboardingEngineService())->definirResponsaveis($onboarding, $estrategista, null);

        $onboarding->refresh();

        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $onboarding->status);
        $this->assertNotNull($onboarding->iniciado_em);
        $this->assertSame($estrategista->id, $onboarding->responsavel_estrategista_id);
        $this->assertNull($onboarding->responsavel_analista_id);
        // Invariante do principal: aponta para o único slot preenchido.
        $this->assertSame($estrategista->id, $onboarding->responsavel_id);
    }

    /** @test */
    public function so_o_analista_tambem_liga_o_sla(): void
    {
        $onboarding = $this->onboardingEmRascunho();
        $analista = User::factory()->create();

        (new OnboardingEngineService())->definirResponsaveis($onboarding, null, $analista);

        $onboarding->refresh();

        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $onboarding->status);
        $this->assertSame($analista->id, $onboarding->responsavel_analista_id);
        $this->assertNull($onboarding->responsavel_estrategista_id);
        $this->assertSame($analista->id, $onboarding->responsavel_id);
    }

    /** @test */
    public function preencher_o_papel_que_faltava_nao_re_liga_o_sla_nem_reescreve_iniciado_em(): void
    {
        $onboarding = $this->onboardingEmRascunho();
        $service = new OnboardingEngineService();

        $analista = User::factory()->create();
        $service->definirResponsaveis($onboarding, null, $analista);

        $onboarding->refresh();
        $iniciadoOriginal = $onboarding->iniciado_em;

        // Passa o tempo — se o SLA re-ligasse, `iniciado_em` andaria com ele.
        $this->travel(3)->days();

        $estrategista = User::factory()->create();
        $service->definirResponsaveis($onboarding, $estrategista, $analista);

        $onboarding->refresh();

        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $onboarding->status);
        $this->assertSame(
            $iniciadoOriginal->toDateTimeString(),
            $onboarding->iniciado_em->toDateTimeString(),
            'iniciado_em não pode andar quando o segundo responsável é preenchido depois.'
        );
        $this->assertSame($estrategista->id, $onboarding->responsavel_estrategista_id);
        $this->assertSame($analista->id, $onboarding->responsavel_analista_id);
        // O principal já ocupava um slot — não é trocado à toa.
        $this->assertSame($analista->id, $onboarding->responsavel_id);
    }

    /** @test */
    public function onboarding_sem_nenhum_dos_dois_responsaveis_e_recusado(): void
    {
        $onboarding = $this->onboardingEmRascunho();

        $this->expectException(\DomainException::class);

        (new OnboardingEngineService())->definirResponsaveis($onboarding, null, null);
    }

    /** @test */
    public function onboarding_concluido_nao_muda_mais_de_responsavel(): void
    {
        $onboarding = $this->onboardingEmRascunho();
        $onboarding->update(['status' => Onboarding::STATUS_CONCLUIDO]);

        $this->expectException(\DomainException::class);

        (new OnboardingEngineService())->definirResponsaveis($onboarding, User::factory()->create(), null);
    }

    /**
     * O botão de um clique do painel atual continua funcionando e passa a
     * escolher o slot pelo vínculo que a pessoa já tem com a empresa.
     *
     * @test
     */
    public function confirmar_responsavel_manda_estrategista_da_carteira_para_o_slot_de_estrategista(): void
    {
        $servico = $this->servicoDeGestao();
        $company = Company::factory()->create();
        $estrategista = User::factory()->create();
        $this->vincularResponsavel($company, $estrategista, 'estrategista', $servico->id);

        $contrato = ContratoServico::factory()
            ->paraServico($servico)
            ->create(['company_id' => $company->id]);
        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        (new OnboardingEngineService())->confirmarResponsavel($onboarding, $estrategista);

        $onboarding->refresh();

        $this->assertSame($estrategista->id, $onboarding->responsavel_estrategista_id);
        $this->assertNull($onboarding->responsavel_analista_id);
        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $onboarding->status);
    }

    /** Vínculo `consultor` é o analista de Performance — vai para o outro slot. */
    /** @test */
    public function confirmar_responsavel_manda_consultor_da_carteira_para_o_slot_de_analista(): void
    {
        $servico = $this->servicoDeGestao();
        $company = Company::factory()->create();
        $consultor = User::factory()->create();
        $this->vincularResponsavel($company, $consultor, 'consultor', $servico->id);

        $contrato = ContratoServico::factory()
            ->paraServico($servico)
            ->create(['company_id' => $company->id]);
        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        (new OnboardingEngineService())->confirmarResponsavel($onboarding, $consultor);

        $onboarding->refresh();

        $this->assertSame($consultor->id, $onboarding->responsavel_analista_id);
        $this->assertNull($onboarding->responsavel_estrategista_id);
    }

    /** @test */
    public function schema_tem_os_dois_slots_e_preserva_responsavel_id(): void
    {
        $this->assertTrue(\Schema::hasColumn('onboardings', 'responsavel_id'));
        $this->assertTrue(\Schema::hasColumn('onboardings', 'responsavel_estrategista_id'));
        $this->assertTrue(\Schema::hasColumn('onboardings', 'responsavel_analista_id'));
    }
}
