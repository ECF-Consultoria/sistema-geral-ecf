<?php

namespace Tests\Feature\Quick260930;

use App\Jobs\SyncAdmanCompanyJob;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\MlToken;
use App\Services\AdmanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260930-njd (T3) — a coleta diária passa a reler os últimos dias.
 *
 * O `adman:sync` grava D-1 uma vez e nunca volta àquele dia, e a Adman revisa
 * dias já passados depois da nossa coleta — para os dois lados. Medido em
 * produção em 30/09/2026, mesma empresa: 01/09 guardado R$ 24.770,86 contra
 * R$ 26.506,96 na Adman (+7,0%); 28/09 guardado R$ 15.933,13 contra R$ 17.904,08
 * (+12,4%); 22/09 idêntico.
 *
 * O que estes testes protegem:
 *  - releitura ATUALIZA a linha do dia, nunca duplica;
 *  - SEM campanhas: 1 chamada por dia por empresa, e não `2 + N`;
 *  - nenhum agendamento existente muda de comportamento (o `adman:sync` continua
 *    gravando D-1 com campanhas);
 *  - empresa com token ML ativo fica de fora (senão a releitura sobrescreveria o
 *    faturamento do ML com o da Adman — a linha mista que o cutover acabou);
 *  - o espaçamento de 7 s entre jobs, e a fila que NÃO é a `high`.
 */
class RelerUltimosDiasAdmanTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function hojeNoDiaDaMedicao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 19:00:00'));
    }

    private function empresaAdmanDriven(string $custId = 'CUST-A'): Company
    {
        return Company::factory()->create([
            'active'           => true,
            'adman_account_id' => $custId,
            'ml_store_id'      => null,
        ]);
    }

    /** `/performance` responde; `/campaigns` responde vazio se for chamado. */
    private function fakeApi(float $grossBilling): void
    {
        Http::fake([
            '*/performance/*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => $grossBilling]],
                'items'          => [],
            ], 200),
            '*/campaigns*'    => Http::response([], 200),
            '*'               => Http::response([], 200),
        ]);
    }

    // ─── Idempotência ─────────────────────────────────────────────────────

    #[Test]
    public function releitura_atualiza_a_linha_do_dia_e_nao_duplica(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();

        // O que o sync gravou em 29/09, de manhã.
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-09-28',
            'revenue'        => 15_933.13,
        ]);

        // O que a Adman devolve agora para o mesmo dia.
        $this->fakeApi(17_904.08);

        $exitCode = $this->artisan('adman:reler-dias', ['--company' => $company->id, '--dias' => 2])->run();

        $this->assertSame(0, $exitCode);

        // Reconsulta ao banco, nunca stdout.
        $linhas = AdmanMetric::where('company_id', $company->id)
            ->whereDate('reference_date', '2026-09-28')
            ->get();

        $this->assertCount(1, $linhas, 'A releitura duplicou a linha do dia em vez de atualizá-la.');
        $this->assertEqualsWithDelta(17_904.08, (float) $linhas->first()->revenue, 0.001);
    }

    #[Test]
    public function rele_de_d_menos_1_para_tras_e_nunca_hoje(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        $this->fakeApi(1_000.00);

        $this->artisan('adman:reler-dias', ['--company' => $company->id, '--dias' => 3])->run();

        $dias = AdmanMetric::where('company_id', $company->id)
            ->orderBy('reference_date')
            ->pluck('reference_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        // D-3, D-2, D-1 — e HOJE fora: a Adman é D-1 e o dia em curso viria pela
        // metade; gravá-lo trocaria dado bom por dado incompleto.
        $this->assertSame(['2026-09-27', '2026-09-28', '2026-09-29'], $dias);
    }

    // ─── O custo por dia relido ───────────────────────────────────────────

    #[Test]
    public function um_dia_relido_custa_uma_chamada_so_sem_campanhas(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        $this->fakeApi(1_000.00);

        $this->artisan('adman:reler-dias', ['--company' => $company->id, '--dias' => 5])->run();

        // 5 dias = 5 chamadas. Com `syncCampaigns()` seriam `2 + N` por dia, e a
        // releitura da base inteira sairia de ~420 chamadas para milhares.
        Http::assertSentCount(5);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/campaigns'));
    }

    /**
     * A trava do outro lado: o `adman:sync` de sempre CONTINUA sincronizando
     * campanhas. O parâmetro novo é opt-out, com default `true` — nenhum
     * agendamento existente pode mudar de comportamento.
     */
    #[Test]
    public function o_sync_de_sempre_continua_sincronizando_campanhas(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        $this->fakeApi(1_000.00);

        app(AdmanService::class)->syncCompany($company, '2026-09-29');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/campaigns'));
    }

    #[Test]
    public function o_job_com_o_default_continua_sincronizando_campanhas(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        $this->fakeApi(1_000.00);

        // Como o fan-out do `adman:sync` dispara: sem o terceiro argumento.
        SyncAdmanCompanyJob::dispatchSync($company, '2026-09-29');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/campaigns'));
    }

    #[Test]
    public function o_job_da_releitura_nao_sincroniza_campanhas(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        $this->fakeApi(1_000.00);

        SyncAdmanCompanyJob::dispatchSync($company, '2026-09-29', false);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/campaigns'));
        $this->assertEqualsWithDelta(
            1_000.00,
            (float) AdmanMetric::where('company_id', $company->id)->value('revenue'),
            0.001,
        );
    }

    // ─── O fan-out ────────────────────────────────────────────────────────

    #[Test]
    public function o_fan_out_enfileira_um_job_por_empresa_e_por_dia_espacados_em_7s(): void
    {
        $this->hojeNoDiaDaMedicao();

        Queue::fake();

        $a = $this->empresaAdmanDriven('CUST-A');
        $b = $this->empresaAdmanDriven('CUST-B');

        $this->artisan('adman:reler-dias', ['--dias' => 3])->run();

        // 2 empresas × 3 dias.
        Queue::assertPushed(SyncAdmanCompanyJob::class, 6);

        // Nenhum deles sincroniza campanhas.
        Queue::assertPushed(
            SyncAdmanCompanyJob::class,
            fn (SyncAdmanCompanyJob $job) => $job->incluirCampanhas === false,
        );

        // O primeiro sai sem atraso, o último 5 × 7s depois — é o espaçamento
        // que faz caber em 10 rpm mesmo com numprocs=2 no Supervisor. Medido
        // sobre TODOS os jobs, porque o que importa é a série inteira: um único
        // job com atraso certo não prova ritmo nenhum.
        $atrasos = Queue::pushed(SyncAdmanCompanyJob::class)
            ->map(fn (SyncAdmanCompanyJob $job) => (int) round(Carbon::now()->diffInSeconds($job->delay, false)))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([0, 7, 14, 21, 28, 35], $atrasos);

        $this->assertNotSame($a->id, $b->id);
    }

    #[Test]
    public function empresa_com_token_ml_ativo_fica_de_fora_do_fan_out(): void
    {
        $this->hojeNoDiaDaMedicao();

        Queue::fake();

        $mlDriven = $this->empresaAdmanDriven('CUST-ML');
        MlToken::create([
            'company_id'    => $mlDriven->id,
            'ml_user_id'    => '999'.$mlDriven->id,
            'access_token'  => 'token-fake',
            'refresh_token' => 'refresh-fake',
            'status'        => 'active',
            'expires_at'    => Carbon::now()->addDay(),
        ]);

        $this->artisan('adman:reler-dias', ['--dias' => 2])->run();

        // Para ela o `adman_metrics` é escrito pelo `ml:sync`; reler da Adman
        // sobrescreveria o faturamento do ML com o da Adman — a linha mista
        // (revenue ML + margem Adman) que o cutover de 01/06/2026 acabou.
        Queue::assertNothingPushed();
    }

    #[Test]
    public function empresa_inativa_fica_de_fora_do_fan_out(): void
    {
        $this->hojeNoDiaDaMedicao();

        Queue::fake();

        Company::factory()->create(['active' => false, 'adman_account_id' => 'CUST-OFF', 'ml_store_id' => null]);

        $this->artisan('adman:reler-dias', ['--dias' => 2])->run();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function o_default_de_dias_e_cinco(): void
    {
        $this->hojeNoDiaDaMedicao();

        Queue::fake();

        $this->empresaAdmanDriven();

        $this->artisan('adman:reler-dias')->run();

        Queue::assertPushed(SyncAdmanCompanyJob::class, 5);
    }

    #[Test]
    public function dias_fora_do_intervalo_e_recusado(): void
    {
        $this->hojeNoDiaDaMedicao();

        Queue::fake();
        $this->empresaAdmanDriven();

        $this->assertSame(1, $this->artisan('adman:reler-dias', ['--dias' => 0])->run());
        $this->assertSame(1, $this->artisan('adman:reler-dias', ['--dias' => 60])->run());

        Queue::assertNothingPushed();
    }

    // ─── Agendamento e fila ───────────────────────────────────────────────

    #[Test]
    public function o_comando_esta_agendado_uma_vez_ao_dia(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString("Schedule::command('adman:reler-dias')", $console);
        $this->assertStringContainsString("->name('adman-reler-ultimos-dias')", $console);
        $this->assertStringContainsString("->withoutOverlapping()", $console);
    }

    /**
     * Nenhum agendamento EXISTENTE mudou: o `adman:sync` continua às 11:00 sem
     * `--from`/`--to`, e a releitura é um agendamento novo e separado.
     */
    #[Test]
    public function o_agendamento_do_adman_sync_das_11h_nao_foi_tocado(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertMatchesRegularExpression(
            "/Schedule::command\('adman:sync'\)\s*\n\s*->dailyAt\('11:00'\)/",
            $console,
        );
    }

    /**
     * Trabalho em lote NÃO vai para a fila `high` — ela é dos jobs interativos e
     * de webhook (179 jobs de acervo ML seguraram o webhook do Clicksign por
     * horas em 16/09/2026). `SyncAdmanCompanyJob` fica na default, como no
     * `adman:sync`.
     */
    #[Test]
    public function a_releitura_nao_usa_a_fila_high(): void
    {
        $comando = file_get_contents(app_path('Console/Commands/RelerDiasAdman.php'));
        $job     = file_get_contents(app_path('Jobs/SyncAdmanCompanyJob.php'));

        $this->assertStringNotContainsString("onQueue('high')", $comando);
        $this->assertStringNotContainsString("'high'", $job);
    }

    #[Test]
    public function o_espacamento_entre_jobs_respeita_o_limite_de_10_rpm(): void
    {
        $comando = file_get_contents(app_path('Console/Commands/RelerDiasAdman.php'));

        $this->assertStringContainsString('INTERVALO_ENTRE_JOBS_SEG = 7', $comando);
        $this->assertSame(10, AdmanService::ADMAN_RATE_LIMIT_RPM);
    }
}
