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
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * 03/11/2026 — gerar link NOVO de um ciclo prorrogado.
 *
 * Caso de produção: os links de out/26 (ref. set/26) foram prorrogados até
 * 07/11 por `nps:prorrogar-validade`, mas a tela só gerava link no mês
 * corrente — a empresa que ficou sem link recebia um de nov/26, outro ciclo.
 * Agora, com o ciclo exibido prorrogado e vigente, o link nasce naquele mês e
 * vale até a prorrogação. Fora disso nada muda, e mês passado sem prorrogação
 * é recusado (nunca cai calado no mês corrente).
 */
class NpsGerarLinkCicloProrrogadoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;
    use ContrataServicoNpsCoberto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function template(): NpsTemplate
    {
        NpsTemplate::query()->update(['is_default' => false]);

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Modelo prorrogado '.uniqid(),
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

    private function empresaCoberta(NpsTemplate $template): Company
    {
        $empresa = Company::factory()->create(['active' => true]);
        $this->contratarServicoNpsCoberto($empresa, $template->id);

        return $empresa;
    }

    /**
     * Link manual de outubro, como os de produção: `month_reference` nulo,
     * competência pelo `created_at`, validade empurrada pela prorrogação.
     */
    private function linkDeOutubro(NpsTemplate $template, ?Company $empresa = null, string $valeAte = '2026-11-07 23:59:59'): NpsSurvey
    {
        $empresa ??= Company::factory()->create(['active' => true]);

        $survey = NpsSurvey::create([
            'token'          => Str::uuid()->toString(),
            'company_id'     => $empresa->id,
            'generated_by'   => null,
            'expires_at'     => Carbon::parse($valeAte),
            'status'         => 'pending',
            'template_id'    => $template->id,
            'auto_generated' => false,
        ]);
        $survey->forceFill(['created_at' => '2026-10-30 16:37:00', 'updated_at' => '2026-10-30 16:37:00'])->save();

        return $survey->fresh();
    }

    private function resposta(NpsTemplate $template): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', 5)->id;
        }

        return ['respondent_name' => 'Cliente '.uniqid(), 'answers' => $answers];
    }

    // ─── Link individual ─────────────────────────────────────────────────

    #[Test]
    public function gera_link_do_ciclo_prorrogado_valendo_ate_a_prorrogacao(): void
    {
        $template = $this->template();
        $this->linkDeOutubro($template);
        $empresa = $this->empresaCoberta($template);

        $this->actingAs($this->admin())
            ->post('/nps/generate', ['company_id' => $empresa->id, 'template_id' => $template->id, 'mes' => '2026-10'])
            ->assertSessionHas('nps_link');

        $novo = NpsSurvey::where('company_id', $empresa->id)->sole();
        $this->assertSame('2026-10-01', $novo->month_reference->toDateString(), 'nasce no ciclo de outubro, não no de novembro.');
        $this->assertSame('2026-11-07 23:59:59', $novo->expires_at->format('Y-m-d H:i:s'));
        $this->assertFalse((bool) $novo->auto_generated);

        // O cliente responde dentro da prorrogação — sem sessão: resposta com
        // usuário interno logado é bloqueada de propósito (Fase 96).
        $this->app['auth']->forgetGuards();
        Carbon::setTestNow(Carbon::parse('2026-11-06 15:00:00'));
        $this->post("/nps/{$novo->token}", $this->resposta($template))->assertOk();
        $this->assertSame('completed', $novo->fresh()->status);
    }

    #[Test]
    public function sem_mes_continua_gerando_no_mes_corrente(): void
    {
        $template = $this->template();
        $this->linkDeOutubro($template);
        $empresa = $this->empresaCoberta($template);

        $this->actingAs($this->admin())
            ->post('/nps/generate', ['company_id' => $empresa->id, 'template_id' => $template->id])
            ->assertSessionHas('nps_link');

        $novo = NpsSurvey::where('company_id', $empresa->id)->sole();
        $this->assertNull($novo->month_reference);
        $this->assertSame('2026-11-30', $novo->expires_at->format('Y-m-d'));
    }

    #[Test]
    public function mes_passado_sem_prorrogacao_e_recusado(): void
    {
        $template = $this->template();
        $empresa  = $this->empresaCoberta($template);

        // Outubro sem nenhum link prorrogado: todos venceram em 31/10.
        $this->linkDeOutubro($template, null, '2026-10-31 23:59:59');

        $this->actingAs($this->admin())
            ->post('/nps/generate', ['company_id' => $empresa->id, 'template_id' => $template->id, 'mes' => '2026-10'])
            ->assertSessionHas('error');

        $this->assertSame(0, NpsSurvey::where('company_id', $empresa->id)->count(),
            'mês passado sem prorrogação não pode virar link de novembro calado.');
    }

    #[Test]
    public function prorrogacao_vencida_nao_gera_mais(): void
    {
        $template = $this->template();
        $this->linkDeOutubro($template);
        $empresa = $this->empresaCoberta($template);

        Carbon::setTestNow(Carbon::parse('2026-11-08 00:00:01'));

        $this->actingAs($this->admin())
            ->post('/nps/generate', ['company_id' => $empresa->id, 'template_id' => $template->id, 'mes' => '2026-10'])
            ->assertSessionHas('error');

        $this->assertSame(0, NpsSurvey::where('company_id', $empresa->id)->count());
    }

    #[Test]
    public function ciclo_encerrado_a_mao_nao_gera_mesmo_prorrogado(): void
    {
        $template = $this->template();
        $this->linkDeOutubro($template);
        $empresa = $this->empresaCoberta($template);
        $admin   = $this->admin();

        NpsCiclo::create([
            'mes_coleta'  => '2026-10-01',
            'fechado_em'  => now(),
            'fechado_por' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post('/nps/generate', ['company_id' => $empresa->id, 'template_id' => $template->id, 'mes' => '2026-10'])
            ->assertSessionHas('error');

        $this->assertSame(0, NpsSurvey::where('company_id', $empresa->id)->count());
    }

    #[Test]
    public function empresa_que_ja_tem_link_de_outubro_recebe_o_link_existente(): void
    {
        $template  = $this->template();
        $empresa   = $this->empresaCoberta($template);
        $existente = $this->linkDeOutubro($template, $empresa);

        $this->actingAs($this->admin())
            ->post('/nps/generate', ['company_id' => $empresa->id, 'template_id' => $template->id, 'mes' => '2026-10'])
            ->assertSessionHas('nps_link_existente', route('nps.respond', $existente->token));

        $this->assertSame(1, NpsSurvey::where('company_id', $empresa->id)->count());
    }

    // ─── Tela ────────────────────────────────────────────────────────────

    #[Test]
    public function tela_mostra_a_prorrogacao_e_o_ciclo_do_link_novo(): void
    {
        $template = $this->template();
        $this->linkDeOutubro($template);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/nps?mes=2026-10')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ciclo.prorrogado_ate', '07/11')
                ->where('geracao_link.mes', '2026-10')
                ->where('geracao_link.vale_ate', '07/11/2026'));

        $this->actingAs($admin)->get('/nps?mes=2026-11')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ciclo.prorrogado_ate', null)
                ->where('geracao_link.mes', '2026-11')
                ->where('geracao_link.vale_ate', '30/11/2026'));

        // Setembro não foi prorrogado: o link gerado de lá vai para novembro.
        $this->actingAs($admin)->get('/nps?mes=2026-09')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ciclo.prorrogado_ate', null)
                ->where('geracao_link.mes', '2026-11'));
    }

    // ─── Link de grupo ───────────────────────────────────────────────────

    #[Test]
    public function gera_link_de_grupo_do_ciclo_prorrogado(): void
    {
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = NpsTemplate::factory()->create(['active' => true]);
        $template->serviceScopes()->attach([$servico]);
        $this->linkDeOutubro($template);

        $grupo        = CompanyGroup::create(['name' => 'Grupo prorrogado']);
        $estrategista = User::factory()->create();
        foreach ([1, 2] as $_) {
            $empresa = Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id]);
            $this->criarContrato($empresa->id, $servico, true);
            $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);
        }

        $this->actingAs($this->admin())
            ->post(route('nps.grupo.generate'), [
                'company_group_id' => $grupo->id,
                'template_id'      => $template->id,
                'mes'              => '2026-10',
            ])
            ->assertSessionHas('nps_link');

        $link = NpsGroupSurvey::where('company_group_id', $grupo->id)->sole();
        $this->assertSame('2026-10-01', $link->month_reference->toDateString());
        $this->assertSame('2026-11-07 23:59:59', $link->expires_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function link_de_grupo_de_mes_passado_sem_prorrogacao_e_recusado(): void
    {
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = NpsTemplate::factory()->create(['active' => true]);
        $template->serviceScopes()->attach([$servico]);

        $grupo   = CompanyGroup::create(['name' => 'Grupo sem prorrogação']);
        $empresa = Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id]);
        $this->criarContrato($empresa->id, $servico, true);
        $this->inserirPivot($empresa->id, User::factory()->create()->id, 'estrategista', $servico);

        $this->actingAs($this->admin())
            ->post(route('nps.grupo.generate'), [
                'company_group_id' => $grupo->id,
                'template_id'      => $template->id,
                'mes'              => '2026-10',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, NpsGroupSurvey::where('company_group_id', $grupo->id)->count());
    }
}
