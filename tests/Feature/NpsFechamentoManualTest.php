<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NpsCiclo;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Nps\NpsJanelaResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fechamento MANUAL do ciclo de NPS — spec 2026-08-14, item 2.
 *
 * Antes, "fechado" era só uma conta de datas: o ciclo virava fechado sozinho no
 * último dia do mês de coleta, sem como encerrar antes e sem registro de quem
 * encerrou. Agora existe estado (`nps_ciclos`) e ele PREVALECE sobre a data.
 *
 * O que a spec exige e estes testes travam:
 *  - não gerar novos links do ciclo encerrado;
 *  - link já emitido e ainda na validade não aceita mais resposta;
 *  - o ciclo fica marcado como fechado, com data/hora e responsável;
 *  - o fechamento manual vale mesmo antes da data automática.
 *
 * Os bloqueios são checados NO SERVIDOR, pelo caminho HTTP real — o link já
 * está na mão do cliente, então esconder o botão não protege nada.
 */
class NpsFechamentoManualTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;
    use ContrataServicoNpsCoberto;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function janela(): NpsJanelaResolver
    {
        // Instância NOVA: o resolver memoiza os meses fechados por request, e o
        // teste fecha/reabre em sequência dentro do mesmo processo.
        return new NpsJanelaResolver();
    }

    private function templateComEmpresa(): NpsTemplate
    {
        NpsTemplate::query()->update(['is_default' => false]);

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Modelo ciclo ' . uniqid(),
            'active'     => true,
            'is_default' => true,
        ]);

        $q = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Como foi o mês? ' . uniqid(),
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

    private function surveyPendente(Company $empresa, NpsTemplate $template, Carbon $mes): NpsSurvey
    {
        return NpsSurvey::create([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => $mes->copy()->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => $mes->copy()->startOfMonth()->toDateString(),
            'auto_generated'  => true,
        ]);
    }

    private function respostaValida(NpsTemplate $template, int $peso = 5): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        return ['respondent_name' => 'Cliente ' . uniqid(), 'answers' => $answers];
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — fechar registra data/hora e responsável, e vale antes da data
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_fechar_registra_a_trilha_e_prevalece_sobre_a_data_automatica(): void
    {
        // Dia 14: a coleta de agosto só encerraria sozinha em 31/08.
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->assertFalse($this->janela()->fechada(Carbon::parse('2026-08-01')),
            'antes do fechamento manual, agosto está aberto pela régua de data.');

        $this->actingAs($admin)
            ->post(route('nps.ciclo.fechar'), ['mes' => '2026-08'])
            ->assertRedirect();

        $ciclo = NpsCiclo::firstOrFail();
        $this->assertSame('2026-08-01', $ciclo->mes_coleta->toDateString());
        $this->assertSame($admin->id, $ciclo->fechado_por);
        $this->assertNotNull($ciclo->fechado_em);
        // A competência avaliada é derivada, nunca gravada.
        $this->assertSame('2026-07-01', $ciclo->competencia()->toDateString());

        $this->assertTrue($this->janela()->fechada(Carbon::parse('2026-08-01')),
            'o fechamento manual prevalece mesmo com a data automática por vir.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — ciclo fechado não gera link novo
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_ciclo_fechado_nao_gera_novo_link(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $admin   = User::factory()->create(['role' => 'admin', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true]);
        $this->contratarServicoNpsCoberto($empresa);

        NpsCiclo::create([
            'mes_coleta'  => '2026-08-01',
            'fechado_em'  => now(),
            'fechado_por' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post('/nps/generate', ['company_id' => $empresa->id])
            ->assertRedirect();

        $this->assertSame(0, NpsSurvey::count(), 'nenhum survey pode nascer em ciclo encerrado.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — link já emitido, ainda na validade, deixa de aceitar resposta
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_link_dentro_da_validade_nao_aceita_resposta_apos_o_fechamento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $admin    = User::factory()->create(['role' => 'admin', 'active' => true]);
        $empresa  = Company::factory()->create(['active' => true]);
        $template = $this->templateComEmpresa();

        $survey = $this->surveyPendente($empresa, $template, Carbon::parse('2026-08-01'));
        $this->assertFalse($survey->isExpired(), 'o link ainda está na validade — é esse o caso que importa.');

        NpsCiclo::create([
            'mes_coleta'  => '2026-08-01',
            'fechado_em'  => now(),
            'fechado_por' => $admin->id,
        ]);

        $this->post("/nps/{$survey->token}", $this->respostaValida($template))
            ->assertStatus(422);

        $this->assertSame('pending', $survey->fresh()->status, 'a resposta não pode ter sido registrada.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — antes de fechar, o MESMO link responde normalmente (controle)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_com_o_ciclo_aberto_o_link_responde_normalmente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $empresa  = Company::factory()->create(['active' => true]);
        $template = $this->templateComEmpresa();
        $survey   = $this->surveyPendente($empresa, $template, Carbon::parse('2026-08-01'));

        $this->post("/nps/{$survey->token}", $this->respostaValida($template))->assertOk();

        $this->assertSame('completed', $survey->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 — fechar duas vezes não duplica nem reescreve quem fechou
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_fechar_duas_vezes_preserva_o_responsavel_original(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $primeiro = User::factory()->create(['role' => 'admin', 'active' => true]);
        $segundo  = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($primeiro)->post(route('nps.ciclo.fechar'), ['mes' => '2026-08']);
        $this->actingAs($segundo)->post(route('nps.ciclo.fechar'), ['mes' => '2026-08']);

        $this->assertSame(1, NpsCiclo::count(), 'o unique de mes_coleta impede o segundo registro.');
        $this->assertSame($primeiro->id, NpsCiclo::firstOrFail()->fechado_por);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6 — só admin fecha
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nao_admin_nao_pode_fechar_ciclo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $comum = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->actingAs($comum)
            ->post(route('nps.ciclo.fechar'), ['mes' => '2026-08'])
            ->assertForbidden();

        $this->assertSame(0, NpsCiclo::count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7 — mês futuro é recusado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nao_fecha_mes_que_ainda_nao_comecou(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin)->post(route('nps.ciclo.fechar'), ['mes' => '2026-09']);

        $this->assertSame(0, NpsCiclo::count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 8 — reabrir desfaz a antecipação, mas não ressuscita mês vencido
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_reabrir_desfaz_a_antecipacao_mas_nao_ressuscita_mes_vencido(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        // Agosto (em curso) — reabrir devolve o ciclo ao estado aberto.
        $this->actingAs($admin)->post(route('nps.ciclo.fechar'), ['mes' => '2026-08']);
        $this->assertTrue($this->janela()->fechada(Carbon::parse('2026-08-01')));

        $this->actingAs($admin)->post(route('nps.ciclo.reabrir'), ['mes' => '2026-08']);
        $this->assertFalse($this->janela()->fechada(Carbon::parse('2026-08-01')));

        // Julho — a data já encerrou; reabrir não pode desfazer isso.
        $this->actingAs($admin)->post(route('nps.ciclo.fechar'), ['mes' => '2026-07']);
        $this->actingAs($admin)->post(route('nps.ciclo.reabrir'), ['mes' => '2026-07']);
        $this->assertTrue($this->janela()->fechada(Carbon::parse('2026-07-01')),
            'reabrir só desfaz a antecipação — mês vencido segue fechado pela data.');
    }
}
