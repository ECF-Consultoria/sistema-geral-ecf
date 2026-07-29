<?php

namespace Tests\Feature\Phase119_1;

use App\Models\Company;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Desempenho\NpsPorEmpresaService;
use App\Services\Desempenho\NpsSemLinkService;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 03 (Task 3) — Prova de que o consumidor de produção da
 * Fase 118 (`NpsPorEmpresaService`, via `CompanyScoreService:167`) aplica a
 * MESMA elegibilidade que o ramo (D) do bônus (`NpsSemLinkService`). É o
 * bloqueador que o plan-check identificou: sem este alinhamento, a mesma
 * empresa valeria 1 no relatório por empresa e nada no bônus.
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 *
 * @see app/Services/Desempenho/NpsPorEmpresaService.php
 * @see app/Services/Desempenho/NpsSemLinkService.php
 * @see app/Services/Desempenho/CompanyScoreService.php
 */
class NpsPorEmpresaElegibilidadeTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fixtures
    // ═══════════════════════════════════════════════════════════════════

    private function npsPorEmpresa(): NpsPorEmpresaService
    {
        return app(NpsPorEmpresaService::class);
    }

    private function npsSemLink(): NpsSemLinkService
    {
        return app(NpsSemLinkService::class);
    }

    /**
     * Empresa ELEGÍVEL: contrato ativo em serviço coberto por um modelo
     * automático + estrategista atribuído. SEM nenhum survey.
     */
    private function criarEmpresaElegivel(User $estrategista, ?User $analista = null): Company
    {
        $empresa = Company::factory()->create(['active' => true, 'name' => 'Empresa Elegivel ' . uniqid()]);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);

        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);
        if ($analista) {
            $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servico);
        }

        $modelo = NpsTemplate::factory()->create(['active' => true, 'envio_automatico_mensal' => true]);
        $modelo->serviceScopes()->attach($servico);

        return $empresa;
    }

    /**
     * Empresa NÃO ELEGÍVEL: contrato ativo, MAS sem estrategista atribuído
     * (nenhum modelo automático precisa nem ser considerado — já falha no
     * guard de estrategista, o motivo mais comum de NPSMAN-07).
     */
    private function criarEmpresaNaoElegivel(?User $analista = null): Company
    {
        $empresa = Company::factory()->create(['active' => true, 'name' => 'Empresa Nao Elegivel ' . uniqid()]);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);

        if ($analista) {
            $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servico);
        }

        return $empresa;
    }

    /** Congela "hoje" DEPOIS do fim do mês de coleta (M+1) — janela fechada. */
    private function congelarJanelaFechada(Carbon $mesFinanceiro): void
    {
        $mesColeta = $mesFinanceiro->copy()->addMonthNoOverflow();
        Carbon::setTestNow($mesColeta->copy()->endOfMonth()->addDay()->setTime(9, 0));
    }

    /** Congela "hoje" DENTRO do mês de coleta (M+1) — janela ainda aberta. */
    private function congelarJanelaAberta(Carbon $mesFinanceiro): void
    {
        $mesColeta = $mesFinanceiro->copy()->addMonthNoOverflow();
        Carbon::setTestNow($mesColeta->copy()->startOfMonth()->addDays(5)->setTime(9, 0));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Comportamento do D-04 alinhado (item 1/2 do <behavior>)
    // ═══════════════════════════════════════════════════════════════════

    public function test_janela_fechada_empresa_elegivel_sem_nota_mantem_sem_nps_1_0(): void
    {
        $mes = Carbon::create(2026, 6, 1);
        $this->congelarJanelaFechada($mes);

        $estrategista = User::factory()->create(['active' => true]);
        $empresa      = $this->criarEmpresaElegivel($estrategista);

        $notas = $this->npsPorEmpresa()->notasNpsPorEmpresa($estrategista, $mes, true, collect());
        $linha = $notas->get($empresa->id);

        $this->assertNotNull($linha);
        $this->assertSame(1.0, $linha->nota, 'Comportamento da Fase 118 preservado para empresa ELEGÍVEL.');
        $this->assertSame('sem_nps', $linha->origem);
    }

    public function test_janela_fechada_empresa_nao_elegivel_sem_nota_vira_nao_elegivel_null(): void
    {
        $mes = Carbon::create(2026, 6, 1);
        $this->congelarJanelaFechada($mes);

        $analista = User::factory()->create(['active' => true]);
        $empresa  = $this->criarEmpresaNaoElegivel($analista);

        $notas = $this->npsPorEmpresa()->notasNpsPorEmpresa($analista, $mes, true, collect());
        $linha = $notas->get($empresa->id);

        $this->assertNotNull($linha);
        $this->assertNull($linha->nota, 'NPSMAN-07 — empresa não elegível fica FORA do denominador, nunca nota zero.');
        $this->assertSame('nao_elegivel', $linha->origem);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Janela aberta vence sobre elegibilidade (ordem fixa) — Phase 118
    // e mes_em_curso não mudam de veredito, elegível ou não
    // ═══════════════════════════════════════════════════════════════════

    public function test_janela_aberta_e_mes_em_curso_nao_mudam_de_veredito_elegivel_ou_nao(): void
    {
        $mes = Carbon::create(2026, 6, 1);

        $estrategista = User::factory()->create(['active' => true]);
        $analista     = User::factory()->create(['active' => true]);
        $empresaElegivel    = $this->criarEmpresaElegivel($estrategista);
        $empresaNaoElegivel = $this->criarEmpresaNaoElegivel($analista);

        // mes_em_curso ($mesFechado=false) — piso 1.0 para TODA a carteira,
        // elegível ou não (checagem de janela vem ANTES da elegibilidade).
        $notasEstrategista = $this->npsPorEmpresa()->notasNpsPorEmpresa($estrategista, $mes, false, collect());
        $this->assertSame('mes_em_curso', $notasEstrategista->get($empresaElegivel->id)->origem);
        $this->assertSame(1.0, $notasEstrategista->get($empresaElegivel->id)->nota);

        $notasAnalista = $this->npsPorEmpresa()->notasNpsPorEmpresa($analista, $mes, false, collect());
        $this->assertSame('mes_em_curso', $notasAnalista->get($empresaNaoElegivel->id)->origem);
        $this->assertSame(1.0, $notasAnalista->get($empresaNaoElegivel->id)->nota);

        // janela_aberta ($mesFechado=true, mas M+1 ainda em coleta) — nota
        // null para AMBAS, elegível ou não (janela vence sobre elegibilidade).
        $this->congelarJanelaAberta($mes);

        $notasEstrategista2 = $this->npsPorEmpresa()->notasNpsPorEmpresa($estrategista, $mes, true, collect());
        $this->assertSame('janela_aberta', $notasEstrategista2->get($empresaElegivel->id)->origem);
        $this->assertNull($notasEstrategista2->get($empresaElegivel->id)->nota);

        $notasAnalista2 = $this->npsPorEmpresa()->notasNpsPorEmpresa($analista, $mes, true, collect());
        $this->assertSame('janela_aberta', $notasAnalista2->get($empresaNaoElegivel->id)->origem);
        $this->assertNull($notasAnalista2->get($empresaNaoElegivel->id)->nota);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Coerência local: NpsSemLinkService (ramo D do bônus) × NpsPorEmpresaService
    // (D-04 do relatório por empresa) concordam no MESMO cenário/mês
    // ═══════════════════════════════════════════════════════════════════

    public function test_nps_sem_link_service_e_nps_por_empresa_service_concordam_no_mesmo_cenario(): void
    {
        $mes = Carbon::create(2026, 6, 1);
        $this->congelarJanelaFechada($mes);

        $estrategista = User::factory()->create(['active' => true]);
        $empresaElegivel    = $this->criarEmpresaElegivel($estrategista);
        // O MESMO estrategista precisa ter algum vínculo com a empresa não
        // elegível para ela aparecer no universo vivo dele (senão o teste
        // não provaria nada sobre elegibilidade — a empresa já estaria fora
        // por não pertencer à carteira). O vínculo é como 'consultor'
        // (analista) — a empresa continua SEM estrategista atribuído, o
        // motivo mais comum de NPSMAN-07.
        $empresaNaoElegivel = $this->criarEmpresaNaoElegivel($estrategista);

        $mesColeta = $mes->copy()->addMonthNoOverflow();

        $notasSemLink = $this->npsSemLink()->notasDoUsuario(
            $estrategista,
            $mesColeta->copy()->startOfMonth(),
            $mesColeta->copy()->endOfMonth(),
        );

        $notasPorEmpresa = $this->npsPorEmpresa()->notasNpsPorEmpresa($estrategista, $mes, true, collect());

        // A empresa ELEGÍVEL aparece em AMBOS: ramo (D) do bônus dá 1.0, e o
        // D-04 do relatório por empresa também dá 1.0/sem_nps — MESMO mês,
        // MESMO usuário. Nenhuma empresa vale 1 numa leitura e nada na outra.
        $this->assertCount(1, $notasSemLink, 'Ramo (D) só produz nota para a empresa ELEGÍVEL.');
        $this->assertSame($empresaElegivel->id, $notasSemLink->first()->company_id);
        $this->assertSame(1.0, $notasSemLink->first()->nota);

        $linhaElegivel = $notasPorEmpresa->get($empresaElegivel->id);
        $this->assertSame(1.0, $linhaElegivel->nota);
        $this->assertSame('sem_nps', $linhaElegivel->origem);

        // A empresa NÃO ELEGÍVEL não aparece no ramo (D) (nem elegível a
        // ninguém) e o D-04 marca `nao_elegivel` (null) — concordam em
        // "fora" nos dois lugares.
        $linhaNaoElegivel = $notasPorEmpresa->get($empresaNaoElegivel->id);
        $this->assertSame('nao_elegivel', $linhaNaoElegivel->origem);
        $this->assertNull($linhaNaoElegivel->nota);
    }

    // ═══════════════════════════════════════════════════════════════════
    // CompanyScoreService reporta o motivo nps_nao_elegivel
    // ═══════════════════════════════════════════════════════════════════

    public function test_company_score_service_reporta_nps_nao_elegivel_no_quality_motivos(): void
    {
        $mes = Carbon::create(2026, 6, 1);
        $this->congelarJanelaFechada($mes);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        // Empresa sem fonte financeira (setor polos) — evita mocks de Adman e
        // isola a asserção no motivo do NPS, mesmo padrão de
        // CompanyScoreServiceStatusTest::test_status_sem_fonte_...
        $empresa = Company::factory()->create(['active' => true]);
        $servico = $this->criarServico(Servico::SETOR_POLOS, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servico);
        // SEM estrategista — empresa não elegível a NPS (NPSMAN-07).

        $periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);

        $resultado = app(CompanyScoreService::class)->computeEmpresasScore($analista, $mes, $periodo, collect());
        $linha     = $resultado->get($empresa->id);

        $this->assertNotNull($linha);
        $this->assertSame('sem_fonte', $linha->status);
        $this->assertContains('nps_nao_elegivel', $linha->quality['motivos']);
        $this->assertNotContains('nps_janela_aberta', $linha->quality['motivos'], 'Motivo específico não pode ser mascarado como janela aberta.');
    }
}
