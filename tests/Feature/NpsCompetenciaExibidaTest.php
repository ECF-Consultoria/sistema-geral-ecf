<?php

namespace Tests\Feature;

use App\Mail\NpsMonthlyMail;
use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * 2026-08-14 — toda superfície de NPS diz A QUE MÊS a nota se refere.
 *
 * O sistema coleta o NPS de uma competência no mês SEGUINTE: a pesquisa que
 * sai em agosto pergunta sobre o serviço de julho. O motor do bônus já pratica
 * isso desde a Fase 105 (`DesempenhoScoreService::computeNpsWindow()` e
 * `NpsJanelaResolver::mesDeColeta()`: competência M → coleta em M+1), mas as
 * telas mostravam só o mês da COLETA, sem dizer o que ele avalia — e a equipe
 * lia a nota de julho como se fosse de agosto.
 *
 * Decisão de exibição: o mês mostrado continua sendo o da COLETA (é o que a
 * pessoa reconhece, o que casa com a lista e o que o `?mes=` sempre
 * selecionou), com o mês avaliado ao lado como "ref.". Trocar o rótulo pela
 * competência só transferiria a confusão de lado.
 *
 * As CHAVES internas seguem intocadas (`nps_surveys.month_reference`, `?mes=`,
 * `serie_12m[].mes_iso`, `nps_history[].month`, `heatmap.meses[].chave`) —
 * nenhuma nota muda de bucket, por isso a suíte anterior sobreviveu.
 *
 * O e-mail é o único ponto que estava ERRADO, não apenas ambíguo: dizia ao
 * cliente "agosto/2026" enquanto perguntava sobre julho.
 *
 * @see app/Services/Nps/NpsJanelaResolver.php (a régua M/M+1, na outra ponta)
 */
class NpsCompetenciaExibidaTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;
    use ContrataServicoNpsCoberto;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers (molde de Phase116\NpsFloorCarteiraTest)
    // ═══════════════════════════════════════════════════════════════════

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds, bool $principal = false): NpsTemplate
    {
        if ($principal) {
            NpsTemplate::query()->update(['is_default' => false]);
        }

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Modelo competência ' . uniqid(),
            'active'     => true,
            'is_default' => $principal,
        ]);

        $ordem = 1;
        foreach ($dimensoes as $dim) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => 'Pergunta ' . $dim . ' ' . uniqid() . '?',
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $dim,
                'obrigatoria' => true,
                'ordem'       => $ordem++,
            ]);

            for ($peso = 1; $peso <= 5; $peso++) {
                NpsTemplateOption::create([
                    'question_id' => $question->id,
                    'label'       => (string) $peso,
                    'peso'        => $peso,
                    'ordem'       => $peso,
                ]);
            }
        }

        foreach ($servicoIds as $sid) {
            $template->serviceScopes()->attach($sid);
        }

        return $template->fresh(['questions.options']);
    }

    /** Responde um survey pelo FLUXO REAL (`POST /nps/{token}`). */
    private function responder(Company $empresa, NpsTemplate $template, int $peso, Carbon $mesColeta): void
    {
        $survey = NpsSurvey::create([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => $mesColeta->copy()->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => $mesColeta->copy()->startOfMonth()->toDateString(),
            'auto_generated'  => true,
        ]);

        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente ' . uniqid(),
            'answers'         => $answers,
        ])->assertOk();
    }

    private function propsDe(User $user, string $url, string $componente): array
    {
        $props = null;
        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props, $componente) {
                $page->component($componente);
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — e-mail/WhatsApp: {mes_referencia} é o mês AVALIADO (disparo − 1)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_assunto_do_email_usa_o_mes_avaliado_e_nao_o_mes_do_disparo(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 8, 18, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = Company::create([
            'name'          => 'Empresa Competência ' . uniqid(),
            'cnpj'          => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'        => true,
            'email_cliente' => 'cliente@empresa-competencia.com',
        ]);
        $empresa->timestamps = false;
        $empresa->forceFill([
            'created_at' => '2025-04-18 10:00:00',
            'updated_at' => '2025-04-18 10:00:00',
        ])->save();
        $empresa->timestamps = true;

        $empresa->users()->attach(
            User::factory()->create()->id,
            ['role' => 'estrategista', 'assigned_at' => now()]
        );
        $this->contratarServicoNpsCoberto($empresa);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // O survey continua sendo do mês do DISPARO (chave de idempotência).
        $this->assertSame('2026-08-01', NpsSurvey::first()->month_reference->toDateString());

        // Já o texto que chega ao cliente fala do mês AVALIADO: julho.
        Mail::assertSent(NpsMonthlyMail::class, function ($mail) {
            return str_contains($mail->vars['assuntoRender'], 'julho/2026')
                && ! str_contains($mail->vars['assuntoRender'], 'agosto/2026');
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — /nps: série de 12 meses rotula pela competência
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_serie_12m_do_nps_mostra_coleta_e_o_mes_de_referencia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $admin    = User::factory()->create(['role' => 'admin', 'active' => true]);
        $empresa  = Company::factory()->create(['active' => true]);
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);

        // Coleta em AGOSTO — avalia JULHO.
        $this->responder($empresa, $template, 5, Carbon::parse('2026-08-01'));

        $props = $this->propsDe($admin, route('nps.index', ['template_id' => $template->id]), 'Nps/Index');

        $bucket = collect($props['serie_12m'])->firstWhere('mes_iso', '2026-08');

        $this->assertNotNull($bucket, 'o bucket continua chaveado pelo mês de COLETA (ago/26).');
        $this->assertStringContainsString('ago', mb_strtolower($bucket['mes']), 'o rótulo do mês é o da COLETA.');
        $this->assertSame('2026-07', $bucket['competencia'], 'coleta de agosto avalia julho.');
        $this->assertStringContainsString('jul', mb_strtolower($bucket['competencia_label']), 'o "ref." é o mês avaliado.');

        // A nota não mudou de bucket: continua no mês de coleta.
        $this->assertNotNull($bucket['empresa']);

        // Cabeçalho: "Coletado em ago/26 · referente a jul/26".
        $this->assertStringContainsString('ago', mb_strtolower($props['coleta_filtro']));
        $this->assertStringContainsString('jul', mb_strtolower($props['competencia_filtro']));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — carteira do profissional: nps_history expõe a competência
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_historico_nps_da_carteira_expoe_a_competencia_do_mes_coletado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $analista    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        // Modelo PRINCIPAL — é o único que o histórico da carteira lê.
        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
            principal: true
        );

        $this->responder($empresa, $template, 5, Carbon::parse('2026-08-01'));

        $props = $this->propsDe($analista, route('portfolio.show', $analista), 'Portfolio/Show');

        $linha = collect($props['nps_history'])->firstWhere('month', '2026-08');

        $this->assertNotNull($linha, 'a chave `month` continua sendo o mês de COLETA.');
        $this->assertSame('2026-07', $linha['competencia'], 'coleta de agosto avalia julho.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — heatmap da carteira: coluna rotulada pela competência
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_heatmap_do_dashboard_anota_o_mes_de_referencia_em_cada_coluna(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $props = $this->propsDe($analista, route('dashboard'), 'Performance/Dashboard');

        $meses  = collect($props['nps']['heatmap']['meses']);
        $ultimo = $meses->last();

        // A coluna é o mês da coleta (chave e rótulo)...
        $this->assertSame('2026-08', $ultimo['chave']);
        $this->assertStringContainsString('ago', mb_strtolower($ultimo['label']));
        // ...e o `ref` diz o mês avaliado.
        $this->assertStringContainsString('jul', mb_strtolower($ultimo['ref']));

        // Toda coluna: ref = chave − 1 mês, sem exceção.
        foreach ($meses as $mes) {
            $esperado = mb_strtolower(
                Carbon::parse($mes['chave'] . '-01')->subMonthNoOverflow()->translatedFormat('M/y')
            );
            $this->assertSame($esperado, mb_strtolower($mes['ref']));
        }
    }
}
