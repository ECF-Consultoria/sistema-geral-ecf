<?php

namespace Tests\Feature\Phase119_1;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\NpsSemLinkService;
use App\Services\Nps\NpsElegibilidadeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 09 (Task 1) — Prova da janela multi-mês, do piso
 * retroativo (DEC-09-B/C), da resolução de invalidação POR MÊS (DEC-09-E)
 * e do memo de `NpsElegibilidadeService::empresasElegiveis()` (T-119.1-41).
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 *
 * @see app/Services/Desempenho/NpsSemLinkService.php
 * @see app/Services/Nps/NpsElegibilidadeService.php
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-09-PLAN.md
 */
class NpsSemLinkJanelaTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private NpsSemLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');

        $this->service = app(NpsSemLinkService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fixtures (mesmo molde de NpsFloorD1Test — não inventar outro)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Monta empresa ativa + serviço/contrato ativo + modelo NPS aplicável
     * (active + envio_automatico_mensal, cobrindo o serviço).
     *
     * @return array{empresa: Company, servico: int, template: NpsTemplate}
     */
    private function criarCenarioElegivel(array $atributosEmpresa = []): array
    {
        $empresa = Company::factory()->create(array_merge([
            'active' => true,
        ], $atributosEmpresa));

        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);

        $template = NpsTemplate::factory()->create([
            'active'                  => true,
            'envio_automatico_mensal' => true,
        ]);
        $template->serviceScopes()->attach($servico);

        // Pergunta dimensão EMPRESA — necessária pra `notasDaEmpresaSemLink()`
        // (que checa `contarPerguntasComPeso()`, molde de `NpsAreaD1Test`).
        $question = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Pergunta empresa ' . uniqid() . '?',
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);
        for ($peso = 1; $peso <= 5; $peso++) {
            NpsTemplateOption::create([
                'question_id' => $question->id,
                'label'       => (string) $peso,
                'peso'        => $peso,
                'ordem'       => $peso,
            ]);
        }

        return ['empresa' => $empresa, 'servico' => $servico, 'template' => $template];
    }

    // ═══════════════════════════════════════════════════════════════════
    // pisoRetroativo()
    // ═══════════════════════════════════════════════════════════════════

    public function test_piso_retroativo_devolve_o_mes_anterior_ao_atual(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0));

        $piso = $this->service->pisoRetroativo();

        $this->assertTrue($piso->isSameDay(Carbon::create(2026, 6, 1)));
    }

    // ═══════════════════════════════════════════════════════════════════
    // notasDoUsuarioNaJanela() — janela multi-mês + piso
    // ═══════════════════════════════════════════════════════════════════

    public function test_janela_multi_mes_com_piso_devolve_apenas_o_mes_anterior(): void
    {
        // "Hoje" = 15/07/2026 ⇒ mês anterior fechado = junho; julho ainda
        // está com a coleta aberta.
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0));

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $de   = now()->copy()->subMonths(5)->startOfMonth(); // fevereiro
        $ate  = now();
        $piso = $this->service->pisoRetroativo(); // junho

        $notas = $this->service->notasDoUsuarioNaJanela($estrategista, $de, $ate, null, $piso);

        $this->assertCount(1, $notas, 'Com piso, só o mês anterior (junho) deve gerar nota — nunca fevereiro/março/abril/maio.');
        $this->assertTrue($notas->first()->competencia_nps->isSameMonth(Carbon::create(2026, 6, 1)));
    }

    public function test_janela_multi_mes_sem_piso_devolve_nota_de_todo_mes_fechado(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0));

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $de  = now()->copy()->subMonths(5)->startOfMonth(); // fevereiro
        $ate = now();

        $notas = $this->service->notasDoUsuarioNaJanela($estrategista, $de, $ate, null, null);

        // Fevereiro, março, abril, maio, junho fechados; julho aberto = 5 meses.
        $this->assertCount(5, $notas, 'Sem piso (opt-in), TODO mês fechado da janela conta — prova de que o piso não é comportamento global.');
    }

    public function test_janela_com_mes_corrente_de_coleta_aberta_devolve_vazio(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0));

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $notas = $this->service->notasDoUsuarioNaJanela($estrategista, now(), now());

        $this->assertCount(0, $notas, 'Julho ainda está com a coleta aberta — ninguém é penalizado.');
    }

    public function test_janela_de_1_mes_fechado_devolve_o_mesmo_conjunto_de_notasDoUsuario(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0));

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $junho = Carbon::create(2026, 6, 1);

        $notasJanela = $this->service->notasDoUsuarioNaJanela($estrategista, $junho->copy(), $junho->copy());
        $notasDireto = $this->service->notasDoUsuario($estrategista, $junho->copy()->startOfMonth(), $junho->copy()->endOfMonth());

        $this->assertCount(1, $notasJanela);
        $this->assertCount(1, $notasDireto);
        $this->assertSame($notasDireto->first()->company_id, $notasJanela->first()->company_id);
        $this->assertSame($notasDireto->first()->nota, $notasJanela->first()->nota);
        $this->assertSame($notasDireto->first()->role, $notasJanela->first()->role);
        $this->assertTrue($notasDireto->first()->competencia_nps->isSameMonth($notasJanela->first()->competencia_nps));
    }

    public function test_janela_com_invalidadas_null_exclui_empresa_invalidada_na_competencia_financeira_correspondente(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0));

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        // Mês de coleta = junho (M+1); competência FINANCEIRA correspondente
        // (M) = maio — precedente literal NpsController.php:807.
        BonusInvalidacao::create([
            'company_id'     => $empresa->id,
            'competencia'    => Carbon::create(2026, 5, 1),
            'motivo'         => 'teste',
            'invalidated_by' => null,
        ]);

        $notas = $this->service->notasDoUsuarioNaJanela(
            $estrategista,
            Carbon::create(2026, 6, 1),
            Carbon::create(2026, 6, 1),
            null,
        );

        $this->assertCount(0, $notas, 'Empresa invalidada na competência financeira (maio) exclui a nota de junho, sem o chamador informar nada.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // notasDaEmpresaSemLink() — piso + invalidação por mês
    // ═══════════════════════════════════════════════════════════════════

    public function test_notasDaEmpresaSemLink_com_piso_informado_ignora_meses_anteriores(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0));

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $de   = now()->copy()->subMonths(3)->startOfMonth(); // abril
        $ate  = now();
        $piso = $this->service->pisoRetroativo(); // junho

        $notas = $this->service->notasDaEmpresaSemLink(collect([$empresa->id]), 'empresa', $de, $ate, null, null, $piso);

        $this->assertCount(1, $notas, 'Abril e maio ficam fora do piso — só junho conta.');
        $this->assertTrue($notas->first()->competencia_nps->isSameMonth(Carbon::create(2026, 6, 1)));
    }

    public function test_notasDaEmpresaSemLink_com_invalidadas_null_resolve_por_mes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 1, 9, 0));

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        // Invalida só a competência financeira de abril (coleta = maio).
        BonusInvalidacao::create([
            'company_id'     => $empresa->id,
            'competencia'    => Carbon::create(2026, 4, 1),
            'motivo'         => 'teste',
            'invalidated_by' => null,
        ]);

        $notas = $this->service->notasDaEmpresaSemLink(
            collect([$empresa->id]),
            'empresa',
            Carbon::create(2026, 5, 1),
            Carbon::create(2026, 6, 1),
            null,
        );

        $meses = $notas->map(fn ($n) => $n->competencia_nps->format('Y-m'))->values()->all();

        $this->assertNotContains('2026-05', $meses, 'Maio (coleta) tem competência financeira abril invalidada — não conta.');
        $this->assertContains('2026-06', $meses, 'Junho (coleta) tem competência financeira maio, NÃO invalidada — continua contando.');
    }

    public function test_notasDaEmpresaSemLink_com_invalidadas_explicita_se_comporta_como_antes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 1, 9, 0));

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $notas = $this->service->notasDaEmpresaSemLink(
            collect([$empresa->id]),
            'empresa',
            Carbon::create(2026, 5, 1),
            Carbon::create(2026, 6, 1),
            collect([$empresa->id]), // invalidada explicitamente para TODOS os meses
        );

        $this->assertCount(0, $notas, 'Invalidadas explícita se aplica IDÊNTICA a todos os meses da janela — regressão do plano 04 preservada.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Memo de empresasElegiveis() — T-119.1-41
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresasElegiveis_memoiza_por_mes_na_mesma_instancia(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0));

        $this->criarCenarioElegivel();

        $elegibilidade = app(NpsElegibilidadeService::class);
        $mes           = Carbon::create(2026, 6, 1);

        $elegibilidade->empresasElegiveis($mes);

        DB::enableQueryLog();
        $elegibilidade->empresasElegiveis($mes->copy());
        $queriesMesmoMes = count(DB::getQueryLog());

        DB::flushQueryLog();
        $elegibilidade->empresasElegiveis(Carbon::create(2026, 5, 1));
        $queriesMesDiferente = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queriesMesmoMes, 'Segunda chamada para o MESMO mês não deve emitir nenhuma query nova (memo).');
        $this->assertGreaterThan(0, $queriesMesDiferente, 'Mês DIFERENTE deve emitir query nova — o memo não é global.');
    }

    public function test_surveyExistenteNaCompetencia_nao_e_memoizado(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0));

        ['empresa' => $empresa, 'servico' => $servico, 'template' => $template] = $this->criarCenarioElegivel();
        $mes = Carbon::create(2026, 6, 1);

        $elegibilidade = app(NpsElegibilidadeService::class);

        $antes = $elegibilidade->surveyExistenteNaCompetencia($empresa->id, $template->id, $mes);
        $this->assertNull($antes, 'Ainda não existe survey nenhum na competência.');

        NpsSurvey::factory()->for($empresa)->create([
            'template_id'     => $template->id,
            'status'          => 'pending',
            'month_reference' => $mes->copy(),
        ]);

        $depois = $elegibilidade->surveyExistenteNaCompetencia($empresa->id, $template->id, $mes);
        $this->assertNotNull($depois, 'Criar um survey ENTRE as duas chamadas muda o resultado da segunda — prova de que NÃO há memo aqui.');
    }
}
