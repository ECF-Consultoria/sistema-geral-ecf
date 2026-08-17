<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `onboarding:backfill-contratos` — o Observer só dispara em `created`, então
 * contrato que já existia antes do motor nunca ganhou onboarding.
 *
 * O que estes testes protegem, em ordem de risco: dry-run não escreve;
 * `--apply` cria em RASCUNHO (não dispara nada para cliente nenhum); rodar
 * duas vezes não duplica; contrato inativo e serviço sem definição ficam de
 * fora.
 */
class OnboardingBackfillContratosTest extends TestCase
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

    /**
     * Contrato ativo SEM onboarding — o cenário que o backfill existe para
     * resolver. Criado com o Observer desligado, que é exatamente como as
     * linhas de produção anteriores à fase se parecem.
     */
    private function contratoOrfao(?Company $company = null): ContratoServico
    {
        $company ??= Company::factory()->create();

        return ContratoServico::withoutEvents(fn () => ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]));
    }

    #[Test]
    public function dry_run_e_o_padrao_e_nao_grava_nada(): void
    {
        $this->contratoOrfao();
        $this->contratoOrfao();

        $this->artisan('onboarding:backfill-contratos')
            ->expectsOutputToContain('MODO DRY-RUN')
            ->assertSuccessful();

        $this->assertSame(0, Onboarding::count(), 'Dry-run não pode gravar');
    }

    #[Test]
    public function apply_cria_o_onboarding_do_contrato_orfao(): void
    {
        $contrato = $this->contratoOrfao();

        $this->artisan('onboarding:backfill-contratos', ['--apply' => true])
            ->assertSuccessful();

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->first();

        $this->assertNotNull($onboarding, 'O contrato órfão precisa ganhar onboarding');
        $this->assertSame($contrato->company_id, $onboarding->company_id);
        $this->assertGreaterThan(0, $onboarding->passos()->count(), 'Nasce com os passos montados');
    }

    /**
     * A propriedade que torna o backfill seguro em massa: rascunho não corre
     * SLA e não expõe passo nenhum no portal (D-05/SC-04). Backfillar a base
     * inteira não dispara nada para cliente nenhum.
     */
    #[Test]
    public function onboarding_do_backfill_nasce_em_rascunho_sem_carimbar_sla(): void
    {
        $this->contratoOrfao();

        $this->artisan('onboarding:backfill-contratos', ['--apply' => true])->assertSuccessful();

        $onboarding = Onboarding::firstOrFail();

        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->status);
        $this->assertNull($onboarding->iniciado_em);
        $this->assertSame(
            0,
            $onboarding->passos()->whereNotNull('disponivel_em')->count(),
            'Rascunho não carimba disponivel_em em passo nenhum'
        );
    }

    #[Test]
    public function rodar_duas_vezes_nao_duplica(): void
    {
        $this->contratoOrfao();

        $this->artisan('onboarding:backfill-contratos', ['--apply' => true])->assertSuccessful();
        $this->artisan('onboarding:backfill-contratos', ['--apply' => true])->assertSuccessful();

        $this->assertSame(1, Onboarding::count());
    }

    #[Test]
    public function contrato_inativo_fica_de_fora(): void
    {
        $contrato = $this->contratoOrfao();
        $contrato->update(['ativo' => false]);

        $this->artisan('onboarding:backfill-contratos', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, Onboarding::count(), 'Mesma regra do Observer: contrato inativo não gera onboarding');
    }

    #[Test]
    public function servico_sem_definicao_nao_gera_onboarding(): void
    {
        $servicoSemDefinicao = Servico::query()
            ->where('ativo', true)
            ->where('nome', 'not like', '%Gestão%')
            ->firstOrFail();

        ContratoServico::withoutEvents(fn () => ContratoServico::factory()
            ->paraServico($servicoSemDefinicao)
            ->create(['company_id' => Company::factory()->create()->id]));

        $this->artisan('onboarding:backfill-contratos', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, Onboarding::count(), 'D-08: só Gestão tem definição na v1');
    }

    #[Test]
    public function contrato_que_ja_tem_onboarding_nao_e_reprocessado(): void
    {
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => Company::factory()->create()->id]);

        // Nasceu pelo Observer, com responsável já confirmado.
        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();
        app(OnboardingEngineService::class)->confirmarResponsavel($onboarding, User::factory()->create());

        $this->artisan('onboarding:backfill-contratos', ['--apply' => true])->assertSuccessful();

        $this->assertSame(1, Onboarding::count());
        $this->assertSame(
            Onboarding::STATUS_ANDAMENTO,
            $onboarding->fresh()->status,
            'O backfill não pode reverter para rascunho um onboarding já em andamento'
        );
    }

    #[Test]
    public function filtro_por_empresa_restringe_o_escopo(): void
    {
        $alvo = Company::factory()->create();
        $this->contratoOrfao($alvo);
        $this->contratoOrfao(Company::factory()->create());

        $this->artisan('onboarding:backfill-contratos', ['--apply' => true, '--company' => $alvo->id])
            ->assertSuccessful();

        $this->assertSame(1, Onboarding::count());
        $this->assertSame($alvo->id, Onboarding::firstOrFail()->company_id);
    }

    #[Test]
    public function limite_corta_o_lote(): void
    {
        $this->contratoOrfao();
        $this->contratoOrfao();
        $this->contratoOrfao();

        $this->artisan('onboarding:backfill-contratos', ['--apply' => true, '--limite' => 2])
            ->assertSuccessful();

        $this->assertSame(2, Onboarding::count());
    }
}
