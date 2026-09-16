<?php

namespace Tests\Feature\Quick260916;

use App\Jobs\ConsolidarMesFechamentoJob;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260916-ejt — o job que refaz o fechamento fora da requisição web.
 *
 * ⚠️ A consolidação de verdade NUNCA roda a partir do job nestes testes: o
 * comando é simulado (`Artisan::shouldReceive('call')`). O que se mede é o
 * andamento que o job publica — running antes de calcular, ready ao terminar,
 * failed quando o comando sai com erro. A consolidação em si tem os testes
 * dela na Fase 137, e este quick não mudou uma linha dela.
 */
class ConsolidarMesFechamentoJobTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'Adman corrigiu o faturamento na origem depois do fechamento.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function chave(string $mes = '2026-08'): string
    {
        return ConsolidarMesFechamentoJob::statusCacheKeyFor($mes);
    }

    private function job(string $mes = '2026-08', ?int $por = 7): ConsolidarMesFechamentoJob
    {
        return new ConsolidarMesFechamentoJob($mes, self::MOTIVO, $por);
    }

    // ═══ Caminho feliz ════════════════════════════════════════════════════

    #[Test]
    public function marca_running_antes_de_calcular_e_ready_ao_terminar(): void
    {
        $chave = $this->chave();

        Artisan::shouldReceive('call')
            ->once()
            ->with('fechamento:consolidar-mes', [
                '--mes'    => '2026-08',
                '--motivo' => self::MOTIVO,
                '--por'    => 7,
            ])
            ->andReturnUsing(function () use ($chave) {
                // Enquanto o comando roda, a tela precisa conseguir ver que
                // está em andamento — é isso que segura a trava de duplo
                // disparo e o aviso na tela.
                $this->assertSame('running', Cache::get($chave)['status']);

                return 0;
            });

        $this->job()->handle();

        $andamento = Cache::get($chave);

        $this->assertSame('ready', $andamento['status']);
        $this->assertSame('2026-08', $andamento['mes']);
        $this->assertNotNull($andamento['started_at']);
        $this->assertNotNull($andamento['completed_at']);
        $this->assertNull($andamento['error']);
    }

    #[Test]
    public function o_job_chama_o_mesmo_comando_do_cron_e_nao_reimplementa_o_calculo(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->withArgs(function (string $comando, array $opcoes) {
                $this->assertSame('fechamento:consolidar-mes', $comando);
                $this->assertSame(self::MOTIVO, $opcoes['--motivo']);

                return true;
            })
            ->andReturn(0);

        $this->job()->handle();

        $this->assertSame('ready', Cache::get($this->chave())['status']);
    }

    // ═══ Falha ════════════════════════════════════════════════════════════

    #[Test]
    public function exit_diferente_de_zero_grava_failed_e_o_registro_anterior_continua_valendo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $company = $this->criarEmpresaComFaturamento($this->criarServicoGestao());

        // Fecha agosto DE VERDADE (pelo kernel, não pela facade) para haver um
        // registro anterior que precisa sobreviver à falha.
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $faturamentoAntes = DB::table('fechamento_snapshots')
            ->where('company_id', $company->id)
            ->whereDate('mes_referencia', '2026-08-01')
            ->value('faturamento_total');

        Artisan::shouldReceive('call')->once()->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn(
            "Lendo empresas...\nApurando faturamento...\nErro: a Adman devolveu 500 no meio do cálculo.\n"
        );

        $this->job()->handle();

        $andamento = Cache::get($this->chave());

        $this->assertSame('failed', $andamento['status']);
        $this->assertStringContainsString('a Adman devolveu 500', $andamento['error']);
        $this->assertNotNull($andamento['completed_at']);

        $faturamentoDepois = DB::table('fechamento_snapshots')
            ->where('company_id', $company->id)
            ->whereDate('mes_referencia', '2026-08-01')
            ->value('faturamento_total');

        $this->assertEquals((float) $faturamentoAntes, (float) $faturamentoDepois);
        $this->assertSame(0, DB::table('fechamento_reconsolidacoes')->count());
    }

    #[Test]
    public function comando_falha_sem_dizer_nada_ainda_assim_deixa_uma_mensagem_legivel(): void
    {
        Artisan::shouldReceive('call')->once()->andReturn(2);
        Artisan::shouldReceive('output')->once()->andReturn('   ');

        $this->job()->handle();

        $andamento = Cache::get($this->chave());

        $this->assertSame('failed', $andamento['status']);
        $this->assertNotEmpty($andamento['error'], 'Falha sem texto nenhum deixava a tela girando para sempre — precisa sobrar alguma frase.');
        $this->assertStringContainsString('2', $andamento['error']);
    }

    #[Test]
    public function failed_do_job_grava_failed_no_andamento(): void
    {
        // Antes deste quick a falha sumia: nada era gravado e a tela não tinha
        // como saber que acabou mal.
        $this->job()->failed(new \RuntimeException('Allowed memory size of 536870912 bytes exhausted'));

        $andamento = Cache::get($this->chave());

        $this->assertSame('failed', $andamento['status']);
        $this->assertStringContainsString('memory size', $andamento['error']);
        $this->assertNotNull($andamento['completed_at']);
    }

    // ═══ Contrato do job ══════════════════════════════════════════════════

    #[Test]
    public function tenta_uma_unica_vez_e_tem_folga_de_tempo(): void
    {
        $job = $this->job();

        $this->assertSame(
            1,
            $job->tries,
            'Refazer duas vezes sozinho é pior que falhar: cada execução regrava a competência e escreve na trilha de auditoria.',
        );
        $this->assertGreaterThanOrEqual(1800, $job->timeout);
    }

    #[Test]
    public function a_chave_de_andamento_e_uma_por_mes(): void
    {
        $this->assertNotSame(
            ConsolidarMesFechamentoJob::statusCacheKeyFor('2026-08'),
            ConsolidarMesFechamentoJob::statusCacheKeyFor('2026-07'),
        );
    }

    // ─── Fixture (mesmo molde do Phase137CompetenciaEndpointTest) ─────────

    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    private function criarEmpresaComFaturamento(Servico $servico): Company
    {
        $company = Company::factory()->create(['adman_account_id' => 'cust-'.uniqid()]);

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            // Quick 260915-jpr: contrato começando hoje ficaria fora do
            // fechamento do mês anterior.
            'data_contratacao' => '2025-01-01',
            'ativo'            => true,
        ]);

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-08-10',
            'revenue'        => 300_000.00,
        ]);

        return $company;
    }
}
