<?php

namespace Tests\Feature\Phase119_1;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 02 (RED) — bloqueio de segundo link NPS manual para a
 * mesma empresa + mesmo modelo + mesmo mês.
 *
 * A brecha real (C1/119.1-CONTEXT.md): `NpsController::generate()` cria o
 * survey manual sem NENHUMA verificação prévia, e `month_reference` nasce
 * sempre NULL nesse caminho — o índice único do banco (Fase 68 Plan 04) só
 * trava DUAS respostas `completed`, nunca dois links pendentes.
 *
 * Molde de fixture herdado de `tests/Feature/Phase116/NpsImputacaoServiceTest.php`.
 * Usa templates SEM "Serviços cobertos" (fallback do branch b em generate(),
 * aceito para qualquer empresa) para não precisar montar contrato/serviço —
 * o alvo desta suíte é o guard de duplicidade, não a resolução de template.
 *
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-02-PLAN.md
 */
class NpsGeracaoDuplicidadeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function templateSemEscopo(): NpsTemplate
    {
        return NpsTemplate::factory()->create(['active' => true]);
    }

    private function gerarLink(User $user, Company $empresa, NpsTemplate $template)
    {
        return $this->actingAs($user)->post(route('nps.generate'), [
            'company_id'  => $empresa->id,
            'template_id' => $template->id,
        ]);
    }

    private function criarSurveyManual(Company $empresa, NpsTemplate $template, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => now()->endOfMonth(),
            'status'          => 'pending',
            'auto_generated'  => false,
            'template_id'     => $template->id,
            'month_reference' => null,
        ], $overrides));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — primeiro link do mês cria normalmente (comportamento intocado)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_primeiro_link_do_mes_e_criado_normalmente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $admin    = $this->admin();
        $empresa  = Company::factory()->create();
        $template = $this->templateSemEscopo();

        $response = $this->gerarLink($admin, $empresa, $template);

        $response->assertSessionHas('success', 'Link NPS gerado com sucesso.');
        $response->assertSessionHas('nps_link');

        $this->assertSame(
            1,
            NpsSurvey::where('company_id', $empresa->id)->where('template_id', $template->id)->count(),
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — segundo link idêntico é bloqueado e devolve o link já existente
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_segundo_link_mesma_empresa_mesmo_modelo_mesmo_mes_e_bloqueado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $admin    = $this->admin();
        $empresa  = Company::factory()->create();
        $template = $this->templateSemEscopo();

        $primeiraResposta = $this->gerarLink($admin, $empresa, $template);
        $primeiraResposta->assertSessionHas('success');

        $primeiroSurvey = NpsSurvey::where('company_id', $empresa->id)
            ->where('template_id', $template->id)
            ->firstOrFail();

        $segundaResposta = $this->gerarLink($admin, $empresa, $template);

        // A contagem PROVA o bloqueio — o flash isolado não é suficiente.
        $this->assertSame(
            1,
            NpsSurvey::where('company_id', $empresa->id)->where('template_id', $template->id)->count(),
        );

        $segundaResposta->assertSessionHas('nps_link_existente', route('nps.respond', $primeiroSurvey->token));
        $segundaResposta->assertSessionHas('error');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — o caso real da brecha: primeiro link nasceu com month_reference
    //     NULL (disparo manual antigo) e o segundo POST bloqueia mesmo assim
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_primeiro_link_com_month_reference_null_tambem_bloqueia_o_segundo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $admin    = $this->admin();
        $empresa  = Company::factory()->create();
        $template = $this->templateSemEscopo();

        // Survey manual "antigo" — exatamente a brecha documentada em C1:
        // month_reference nasce NULL, só created_at (deste mês) identifica a competência.
        $primeiroSurvey = $this->criarSurveyManual($empresa, $template, [
            'month_reference' => null,
            'created_at'      => Carbon::parse('2026-07-05 09:00:00'),
        ]);

        $segundaResposta = $this->gerarLink($admin, $empresa, $template);

        $this->assertSame(
            1,
            NpsSurvey::where('company_id', $empresa->id)->where('template_id', $template->id)->count(),
        );

        $segundaResposta->assertSessionHas('nps_link_existente', route('nps.respond', $primeiroSurvey->token));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — link do MESMO modelo mas de mês anterior não bloqueia (cria normal)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_link_do_mesmo_modelo_no_mes_anterior_nao_bloqueia_link_no_mes_corrente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $admin    = $this->admin();
        $empresa  = Company::factory()->create();
        $template = $this->templateSemEscopo();

        $this->criarSurveyManual($empresa, $template, [
            'month_reference' => Carbon::parse('2026-06-01')->startOfMonth(),
            'created_at'      => Carbon::parse('2026-06-05 09:00:00'),
        ]);

        $response = $this->gerarLink($admin, $empresa, $template);

        $response->assertSessionHas('success', 'Link NPS gerado com sucesso.');
        $this->assertSame(
            2,
            NpsSurvey::where('company_id', $empresa->id)->where('template_id', $template->id)->count(),
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 — link de OUTRO modelo na mesma empresa e mês não bloqueia
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_link_de_outro_modelo_na_mesma_empresa_e_mes_nao_bloqueia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $admin      = $this->admin();
        $empresa    = Company::factory()->create();
        $templateX  = $this->templateSemEscopo();
        $templateY  = $this->templateSemEscopo();

        $this->gerarLink($admin, $empresa, $templateX)->assertSessionHas('success');

        $response = $this->gerarLink($admin, $empresa, $templateY);

        $response->assertSessionHas('success', 'Link NPS gerado com sucesso.');
        $this->assertSame(1, NpsSurvey::where('company_id', $empresa->id)->where('template_id', $templateX->id)->count());
        $this->assertSame(1, NpsSurvey::where('company_id', $empresa->id)->where('template_id', $templateY->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6 — survey do mês já respondido (completed) também bloqueia
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_ja_completed_no_mes_tambem_bloqueia_o_segundo_link(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $admin    = $this->admin();
        $empresa  = Company::factory()->create();
        $template = $this->templateSemEscopo();

        $primeiroSurvey = $this->criarSurveyManual($empresa, $template, [
            'status'          => 'completed',
            'completed_at'    => Carbon::parse('2026-07-10 12:00:00'),
            'month_reference' => null,
            'created_at'      => Carbon::parse('2026-07-02 08:00:00'),
        ]);

        $segundaResposta = $this->gerarLink($admin, $empresa, $template);

        $this->assertSame(
            1,
            NpsSurvey::where('company_id', $empresa->id)->where('template_id', $template->id)->count(),
        );

        $segundaResposta->assertSessionHas('nps_link_existente', route('nps.respond', $primeiroSurvey->token));
    }
}
