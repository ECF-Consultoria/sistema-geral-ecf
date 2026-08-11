<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\OnboardingTemplate;
use App\Models\Servico;
use App\Models\TemplatePasso;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingTemplateVersionService;
use Database\Seeders\OnboardingTemplateGestaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fase 135 Plano 08 (Task 1) — cobre `OnboardingTemplateVersionService`:
 * publicação de versão N+1 por INSERT (nunca UPDATE em versão anterior),
 * onboardings vivos presos à versão em que nasceram (SC-09), migração
 * explícita preservando histórico e a guarda de ciclo (`detectarCiclo`).
 */
class OnboardingTemplateVersionamentoTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function service(): OnboardingTemplateVersionService
    {
        return new OnboardingTemplateVersionService(new OnboardingEngineService());
    }

    /** @return array<int, array<string, mixed>> */
    private function passosSimples(): array
    {
        return [
            [
                'chave'  => 'passo_a',
                'titulo' => 'Passo A',
                'dono'   => TemplatePasso::DONO_INTERNO,
            ],
            [
                'chave'      => 'passo_b',
                'titulo'     => 'Passo B',
                'dono'       => TemplatePasso::DONO_INTERNO,
                'depende_de' => ['passo_a'],
            ],
        ];
    }

    // ─── publicarNovaVersao() ──────────────────────────────────────────────

    /** @test */
    public function publicar_segunda_versao_cria_versao_2_ativa_e_desativa_a_versao_1(): void
    {
        $servico = $this->servicoDeGestao();
        $service = $this->service();
        $publicador = $this->admin();

        $v1 = $service->publicarNovaVersao($servico, $this->passosSimples(), $publicador);
        $this->assertSame(1, $v1->versao);
        $this->assertTrue($v1->ativo);

        $v2 = $service->publicarNovaVersao($servico, $this->passosSimples(), $publicador);

        $this->assertSame(2, $v2->versao);
        $this->assertTrue($v2->fresh()->ativo);
        $this->assertFalse($v1->fresh()->ativo);
    }

    /** @test */
    public function publicar_v2_nao_altera_updated_at_dos_passos_da_v1(): void
    {
        $servico = $this->servicoDeGestao();
        $service = $this->service();
        $publicador = $this->admin();

        Carbon::setTestNow('2026-01-01 10:00:00');
        $v1 = $service->publicarNovaVersao($servico, $this->passosSimples(), $publicador);
        $passoV1 = TemplatePasso::where('template_id', $v1->id)->where('chave', 'passo_a')->firstOrFail();
        $updatedAtOriginal = $passoV1->updated_at;
        $this->assertNotNull($updatedAtOriginal);

        Carbon::setTestNow('2026-01-01 11:00:00');
        $service->publicarNovaVersao($servico, $this->passosSimples(), $publicador);

        $this->assertTrue($updatedAtOriginal->equalTo($passoV1->fresh()->updated_at));

        Carbon::setTestNow();
    }

    /** @test */
    public function onboarding_criado_na_v1_mantem_template_id_apos_publicar_v2(): void
    {
        $servico = $this->servicoDeGestao();
        $engine = new OnboardingEngineService();
        $service = $this->service();
        $publicador = $this->admin();

        $v1 = $service->publicarNovaVersao($servico, $this->passosSimples(), $publicador);

        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id]);
        $onboarding = $engine->criarParaContrato($contrato);

        $this->assertSame($v1->id, $onboarding->template_id);

        $service->publicarNovaVersao($servico, $this->passosSimples(), $publicador);

        $this->assertSame($v1->id, $onboarding->fresh()->template_id);
    }

    // ─── migrarOnboardings() ───────────────────────────────────────────────

    /** @test */
    public function migrar_onboardings_mantem_status_e_disponivel_em_dos_passos_de_mesma_chave(): void
    {
        $servico = $this->servicoDeGestao();
        $engine = new OnboardingEngineService();
        $service = $this->service();
        $publicador = $this->admin();

        $v1 = $service->publicarNovaVersao($servico, $this->passosSimples(), $publicador);

        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id]);
        $onboarding = $engine->criarParaContrato($contrato);
        $onboarding->update(['status' => Onboarding::STATUS_ANDAMENTO, 'iniciado_em' => now()]);
        $engine->reavaliar($onboarding->fresh());

        $passoA = OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', 'passo_a')->firstOrFail();
        $engine->concluirManualmente($passoA, $publicador);
        $passoA->refresh();
        $disponivelEmOriginal = $passoA->disponivel_em;
        $this->assertNotNull($disponivelEmOriginal);

        $v2 = $service->publicarNovaVersao($servico, $this->passosSimples(), $publicador);

        $migrados = $service->migrarOnboardings($v2, [$onboarding->id]);

        $this->assertSame(1, $migrados);
        $this->assertSame($v2->id, $onboarding->fresh()->template_id);

        $passoAMigrado = OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', 'passo_a')->firstOrFail();
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passoAMigrado->status);
        $this->assertTrue($disponivelEmOriginal->equalTo($passoAMigrado->disponivel_em));
    }

    /** @test */
    public function passo_removido_do_template_novo_fica_nao_aplicavel_sem_apagar_e_sem_diminuir_a_contagem(): void
    {
        $servico = $this->servicoDeGestao();
        $engine = new OnboardingEngineService();
        $service = $this->service();
        $publicador = $this->admin();

        $service->publicarNovaVersao($servico, $this->passosSimples(), $publicador);

        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id]);
        $onboarding = $engine->criarParaContrato($contrato);
        $onboarding->update(['status' => Onboarding::STATUS_ANDAMENTO, 'iniciado_em' => now()]);
        $engine->reavaliar($onboarding->fresh());

        $totalPassosAntes = OnboardingPasso::where('onboarding_id', $onboarding->id)->count();
        $this->assertSame(2, $totalPassosAntes);

        // v2 remove 'passo_b' do template.
        $v2 = $service->publicarNovaVersao($servico, [
            [
                'chave'  => 'passo_a',
                'titulo' => 'Passo A',
                'dono'   => TemplatePasso::DONO_INTERNO,
            ],
        ], $publicador);

        $service->migrarOnboardings($v2, [$onboarding->id]);

        $totalPassosDepois = OnboardingPasso::where('onboarding_id', $onboarding->id)->count();
        $this->assertSame($totalPassosAntes, $totalPassosDepois);

        $passoB = OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', 'passo_b')->firstOrFail();
        $this->assertSame(OnboardingPasso::STATUS_NAO_APLICAVEL, $passoB->status);
    }

    // ─── detectarCiclo() ────────────────────────────────────────────────────

    /** @test */
    public function detectar_ciclo_devolve_null_para_o_grafo_do_template_de_gestao(): void
    {
        (new OnboardingTemplateGestaoSeeder())->run();
        $template = OnboardingTemplate::where('versao', 1)->firstOrFail();
        $passos = TemplatePasso::where('template_id', $template->id)
            ->get(['chave', 'depende_de'])
            ->map(fn (TemplatePasso $p) => ['chave' => $p->chave, 'depende_de' => $p->depende_de ?? []])
            ->all();

        $this->assertNull($this->service()->detectarCiclo($passos));
    }

    /** @test */
    public function detectar_ciclo_devolve_caminho_com_pelo_menos_3_elementos_para_grafo_a_b_a(): void
    {
        $caminho = $this->service()->detectarCiclo([
            ['chave' => 'a', 'depende_de' => ['b']],
            ['chave' => 'b', 'depende_de' => ['a']],
        ]);

        $this->assertNotNull($caminho);
        $this->assertGreaterThanOrEqual(3, count($caminho));
    }

    /** @test */
    public function detectar_ciclo_ignora_dependencia_para_chave_ausente_do_payload(): void
    {
        $caminho = $this->service()->detectarCiclo([
            ['chave' => 'a', 'depende_de' => ['chave_inexistente']],
        ]);

        $this->assertNull($caminho);
    }
}
