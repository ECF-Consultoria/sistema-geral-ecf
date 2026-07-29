<?php

namespace Tests\Feature\Phase119_1;

use App\Models\Company;
use App\Models\NpsImputedAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\NpsSemLinkService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 03 (Task 1) — Prova de D1: empresa ELEGÍVEL sem NENHUM
 * link de NPS no mês conta nota 1 para o estrategista e o analista (NPSMAN-06),
 * o contrapeso NPSMAN-07 (empresa NÃO elegível não conta, nos 4 motivos) e
 * NPSMAN-12 (empresa sem canal de contato conta 1 igual, D5).
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 *
 * @see app/Services/Desempenho/NpsSemLinkService.php
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-03-PLAN.md
 */
class NpsFloorD1Test extends TestCase
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
    // Fixtures
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

        return ['empresa' => $empresa, 'servico' => $servico, 'template' => $template];
    }

    /** Congela "hoje" DEPOIS do fim do mês de coleta informado (janela fechada). */
    private function congelarMesFechado(Carbon $mesColeta): void
    {
        Carbon::setTestNow($mesColeta->copy()->endOfMonth()->addDay()->setTime(9, 0));
    }

    /** Congela "hoje" DENTRO do mês de coleta informado (janela ainda aberta). */
    private function congelarMesAberto(Carbon $mesColeta): void
    {
        Carbon::setTestNow($mesColeta->copy()->startOfMonth()->addDays(5)->setTime(9, 0));
    }

    // ═══════════════════════════════════════════════════════════════════
    // NPSMAN-06 — elegível sem link conta 1
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_elegivel_sem_link_mes_fechado_conta_nota_1_para_estrategista_e_analista(): void
    {
        $mesColeta = Carbon::create(2026, 6, 1);
        $this->congelarMesFechado($mesColeta);

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $analista     = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servico);

        $notasEstrategista = $this->service->notasDoUsuario(
            $estrategista,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
        );
        $notasAnalista = $this->service->notasDoUsuario(
            $analista,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
        );

        $this->assertCount(1, $notasEstrategista);
        $this->assertSame(1.0, $notasEstrategista->first()->nota);
        $this->assertSame('sem_link', $notasEstrategista->first()->origem);
        $this->assertSame('estrategista', $notasEstrategista->first()->role);

        $this->assertCount(1, $notasAnalista);
        $this->assertSame(1.0, $notasAnalista->first()->nota);
        $this->assertSame('consultor', $notasAnalista->first()->role);
    }

    public function test_mes_de_coleta_ainda_aberto_nao_gera_nenhuma_nota(): void
    {
        $mesColeta = Carbon::create(2026, 6, 1);
        $this->congelarMesAberto($mesColeta);

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $notas = $this->service->notasDoUsuario(
            $estrategista,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
        );

        $this->assertCount(0, $notas, 'Enquanto o mês de coleta está aberto, ninguém é penalizado.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // NPSMAN-12 / D5 — sem canal de contato conta 1 igual
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_sem_contato_conta_nota_1_do_mesmo_jeito(): void
    {
        $mesColeta = Carbon::create(2026, 6, 1);
        $this->congelarMesFechado($mesColeta);

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel([
            'email_cliente'            => null,
            'digisac_group_contact_id' => null,
        ]);
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $notas = $this->service->notasDoUsuario(
            $estrategista,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
        );

        $this->assertCount(1, $notas, 'D5 — ausência de canal de contato NÃO filtra a elegibilidade.');
        $this->assertSame(1.0, $notas->first()->nota);
    }

    // ═══════════════════════════════════════════════════════════════════
    // NPSMAN-07 — 4 motivos de NÃO elegibilidade, nenhuma nota
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_sem_estrategista_nao_elegivel_nao_gera_nota(): void
    {
        $mesColeta = Carbon::create(2026, 6, 1);
        $this->congelarMesFechado($mesColeta);

        $this->criarCenarioElegivel(); // sem atribuir estrategista

        $qualquerUser = User::factory()->create();

        $notas = $this->service->notasDoUsuario(
            $qualquerUser,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
        );

        $this->assertCount(0, $notas, 'Empresa sem estrategista não é elegível — NPSMAN-07.');
    }

    public function test_empresa_sem_contrato_ativo_em_servico_coberto_nao_elegivel_nao_gera_nota(): void
    {
        $mesColeta = Carbon::create(2026, 6, 1);
        $this->congelarMesFechado($mesColeta);

        $empresa = Company::factory()->create(['active' => true]);
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', null);

        // Modelo existe e cobre um serviço, mas a empresa não tem NENHUM
        // contrato ativo naquele serviço.
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = NpsTemplate::factory()->create(['active' => true, 'envio_automatico_mensal' => true]);
        $template->serviceScopes()->attach($servico);

        $notas = $this->service->notasDoUsuario(
            $estrategista,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
        );

        $this->assertCount(0, $notas, 'Empresa sem contrato ativo em serviço coberto não é elegível — NPSMAN-07.');
    }

    public function test_empresa_inativa_nao_elegivel_nao_gera_nota(): void
    {
        $mesColeta = Carbon::create(2026, 6, 1);
        $this->congelarMesFechado($mesColeta);

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel(['active' => false]);
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $notas = $this->service->notasDoUsuario(
            $estrategista,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
        );

        $this->assertCount(0, $notas, 'Empresa active=false não é elegível — NPSMAN-07.');
    }

    public function test_empresa_invalidada_na_competencia_nao_elegivel_nao_gera_nota(): void
    {
        $mesColeta = Carbon::create(2026, 6, 1);
        $this->congelarMesFechado($mesColeta);

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $notas = $this->service->notasDoUsuario(
            $estrategista,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
            collect([$empresa->id]),
        );

        $this->assertCount(0, $notas, 'Empresa invalidada para bônus na competência não conta — NPSMAN-07.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Disjunção com os ramos (A)/(B)/(C) — empresa COM link não conta aqui
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_elegivel_com_link_no_mes_nao_conta_mesmo_pendente_e_month_reference_null(): void
    {
        $mesColeta = Carbon::create(2026, 6, 1);
        $this->congelarMesFechado($mesColeta);

        ['empresa' => $empresa, 'servico' => $servico, 'template' => $template] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        // Link existe, pendente, month_reference NULL (disparo manual, D-12).
        NpsSurvey::factory()->for($empresa)->create([
            'template_id'     => $template->id,
            'status'          => 'pending',
            'month_reference' => null,
            'created_at'      => $mesColeta->copy()->startOfMonth()->addDays(3),
        ]);

        $notas = $this->service->notasDoUsuario(
            $estrategista,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
        );

        $this->assertCount(0, $notas, 'Empresa com link no mês (mesmo pendente) é coberta pelo ramo (C), não por este.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Dedup por (empresa, modelo, papel)
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_elegivel_para_dois_modelos_sem_link_gera_1_nota_por_modelo_e_papel(): void
    {
        $mesColeta = Carbon::create(2026, 6, 1);
        $this->congelarMesFechado($mesColeta);

        $empresa = Company::factory()->create(['active' => true]);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);

        $templateA = NpsTemplate::factory()->create(['active' => true, 'envio_automatico_mensal' => true]);
        $templateA->serviceScopes()->attach($servico);
        $templateB = NpsTemplate::factory()->create(['active' => true, 'envio_automatico_mensal' => true]);
        $templateB->serviceScopes()->attach($servico);

        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $notas = $this->service->notasDoUsuario(
            $estrategista,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
        );

        $this->assertCount(2, $notas, 'Elegível para 2 modelos sem link = 1 nota POR MODELO, não a mesma nota duplicada.');
        foreach ($notas as $nota) {
            $this->assertSame(1.0, $nota->nota);
            $this->assertSame('estrategista', $nota->role);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Leitura pura — nenhuma escrita em nps_imputed_assignments
    // ═══════════════════════════════════════════════════════════════════

    public function test_nenhuma_linha_e_criada_em_nps_imputed_assignments(): void
    {
        $mesColeta = Carbon::create(2026, 6, 1);
        $this->congelarMesFechado($mesColeta);

        ['empresa' => $empresa, 'servico' => $servico] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $antes = NpsImputedAssignment::count();

        $notas = $this->service->notasDoUsuario(
            $estrategista,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
        );

        $depois = NpsImputedAssignment::count();

        $this->assertCount(1, $notas);
        $this->assertSame($antes, $depois, 'É cálculo de LEITURA — nenhuma linha é escrita em nps_imputed_assignments.');
    }
}
