<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsCiclo;
use App\Models\NpsGroupSurvey;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TKT-0029 (30/10/2026) — prorrogar a validade dos links de NPS de um mês.
 *
 * Caso de produção: os links de out/2026 venciam em 31/10 (sábado). Dois
 * problemas que estes testes travam:
 *  1. o link individual recusava resposta durante TODO o último dia do mês,
 *     porque o envio consultava a régua de data do bônus (`gte`, fecha às
 *     00:00 do último dia) em vez da validade do próprio link;
 *  2. não havia como prorrogar a validade: `nps:prorrogar-validade` move o
 *     `expires_at` dos links não respondidos de um mês de coleta.
 * O fechamento MANUAL do ciclo continua barrando resposta, prorrogado ou não.
 */
class NpsProrrogarValidadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A tela pública é página Inertia: sem isto o teste depende do build.
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function template(): NpsTemplate
    {
        NpsTemplate::query()->update(['is_default' => false]);

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Modelo prorrogação '.uniqid(),
            'active'     => true,
            'is_default' => true,
        ]);

        $q = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Como foi o mês? '.uniqid(),
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);

        for ($peso = 1; $peso <= 5; $peso++) {
            NpsTemplateOption::create([
                'question_id' => $q->id,
                'label'       => (string) $peso,
                'peso'        => $peso,
                'ordem'       => $peso,
            ]);
        }

        return $template->fresh(['questions.options']);
    }

    /**
     * Link manual, como os de out/2026: `month_reference` nulo, competência
     * pelo `created_at`, validade até o fim do mês em que nasceu.
     */
    private function linkManual(NpsTemplate $template, string $criadoEm, string $status = 'pending'): NpsSurvey
    {
        $criado = Carbon::parse($criadoEm);
        $survey = NpsSurvey::create([
            'token'          => Str::uuid()->toString(),
            'company_id'     => Company::factory()->create(['active' => true])->id,
            'generated_by'   => null,
            'expires_at'     => $criado->copy()->endOfMonth(),
            'status'         => $status,
            'template_id'    => $template->id,
            'auto_generated' => false,
        ]);
        $survey->forceFill(['created_at' => $criado, 'updated_at' => $criado])->save();

        return $survey->fresh();
    }

    private function linkDeGrupo(NpsTemplate $template, string $mes): NpsGroupSurvey
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo '.uniqid()]);

        return NpsGroupSurvey::create([
            'token'            => Str::uuid()->toString(),
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
            'generated_by'     => null,
            'month_reference'  => $mes,
            'expires_at'       => Carbon::parse($mes)->endOfMonth(),
            'status'           => 'pending',
        ]);
    }

    private function resposta(NpsTemplate $template): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', 5)->id;
        }

        return ['respondent_name' => 'Cliente '.uniqid(), 'answers' => $answers];
    }

    // ─── Envio do link individual ────────────────────────────────────────

    #[Test]
    public function link_individual_aceita_resposta_no_ultimo_dia_do_mes(): void
    {
        $template = $this->template();
        $survey   = $this->linkManual($template, '2026-10-30 11:00:00');

        // Sábado, 31/10, às 10h: o link vale até 23:59:59 de hoje.
        Carbon::setTestNow(Carbon::parse('2026-10-31 10:00:00'));

        $this->post("/nps/{$survey->token}", $this->resposta($template))->assertOk();

        $this->assertSame('completed', $survey->fresh()->status);
    }

    #[Test]
    public function link_individual_vencido_continua_recusando(): void
    {
        $template = $this->template();
        $survey   = $this->linkManual($template, '2026-10-30 11:00:00');

        Carbon::setTestNow(Carbon::parse('2026-11-01 08:00:00'));

        $this->post("/nps/{$survey->token}", $this->resposta($template))->assertStatus(422);

        $this->assertSame('pending', $survey->fresh()->status);
    }

    #[Test]
    public function ciclo_encerrado_a_mao_recusa_mesmo_com_link_prorrogado(): void
    {
        $template = $this->template();
        $survey   = $this->linkManual($template, '2026-10-30 11:00:00');
        $survey->update(['expires_at' => Carbon::parse('2026-11-07 23:59:59')]);

        NpsCiclo::create([
            'mes_coleta'  => '2026-10-01',
            'fechado_em'  => Carbon::parse('2026-11-02 09:00:00'),
            'fechado_por' => User::factory()->create(['role' => 'admin'])->id,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00'));

        $this->post("/nps/{$survey->token}", $this->resposta($template))->assertStatus(422);

        $this->assertSame('pending', $survey->fresh()->status);
    }

    // ─── Comando nps:prorrogar-validade ─────────────────────────────────

    #[Test]
    public function comando_prorroga_so_os_nao_respondidos_do_mes(): void
    {
        $template = $this->template();

        $pendente   = $this->linkManual($template, '2026-10-27 15:00:00');
        $vencido    = $this->linkManual($template, '2026-10-02 09:00:00', 'expired');
        $respondido = $this->linkManual($template, '2026-10-28 10:00:00', 'completed');
        $setembro   = $this->linkManual($template, '2026-09-29 10:00:00');
        $grupoOut   = $this->linkDeGrupo($template, '2026-10-01');
        $grupoSet   = $this->linkDeGrupo($template, '2026-09-01');

        Carbon::setTestNow(Carbon::parse('2026-10-30 21:00:00'));

        $this->artisan('nps:prorrogar-validade', ['--mes' => '2026-10', '--ate' => '2026-11-07'])
            ->assertSuccessful();

        $novo = '2026-11-07 23:59:59';

        $this->assertSame($novo, $pendente->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame($novo, $vencido->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('pending', $vencido->fresh()->status, 'link aberto depois de vencer volta a pendente.');
        $this->assertSame($novo, $grupoOut->fresh()->expires_at->format('Y-m-d H:i:s'));

        $this->assertSame('2026-10-31', $respondido->fresh()->expires_at->format('Y-m-d'), 'respondido não é tocado.');
        $this->assertSame('completed', $respondido->fresh()->status);
        $this->assertSame('2026-09-30', $setembro->fresh()->expires_at->format('Y-m-d'), 'outro mês não é tocado.');
        $this->assertSame('2026-09-30', $grupoSet->fresh()->expires_at->format('Y-m-d'), 'grupo de outro mês não é tocado.');
    }

    #[Test]
    public function dry_run_nao_grava(): void
    {
        $template = $this->template();
        $survey   = $this->linkManual($template, '2026-10-27 15:00:00');
        $grupo    = $this->linkDeGrupo($template, '2026-10-01');

        $this->artisan('nps:prorrogar-validade', ['--mes' => '2026-10', '--ate' => '2026-11-07', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame('2026-10-31', $survey->fresh()->expires_at->format('Y-m-d'));
        $this->assertSame('2026-10-31', $grupo->fresh()->expires_at->format('Y-m-d'));
    }

    #[Test]
    public function data_dentro_do_mes_e_recusada(): void
    {
        $this->artisan('nps:prorrogar-validade', ['--mes' => '2026-10', '--ate' => '2026-10-31'])
            ->assertFailed();
    }

    #[Test]
    public function links_prorrogados_aceitam_resposta_em_novembro(): void
    {
        $template = $this->template();
        $survey   = $this->linkManual($template, '2026-10-30 11:00:00');
        $grupo    = $this->linkDeGrupo($template, '2026-10-01');

        Carbon::setTestNow(Carbon::parse('2026-10-30 21:00:00'));
        $this->artisan('nps:prorrogar-validade', ['--mes' => '2026-10', '--ate' => '2026-11-07'])
            ->assertSuccessful();

        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00'));

        $this->post("/nps/{$survey->token}", $this->resposta($template))->assertOk();
        $this->assertSame('completed', $survey->fresh()->status);

        // Link de grupo: a tela pública abre o formulário, não a de "expirado".
        $this->get("/nps/grupo/{$grupo->token}")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Nps/Respond', false));

        // E vence de novo depois do dia prorrogado.
        Carbon::setTestNow(Carbon::parse('2026-11-08 00:00:01'));
        $this->get("/nps/grupo/{$grupo->token}")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Nps/Expired', false));
    }
}
