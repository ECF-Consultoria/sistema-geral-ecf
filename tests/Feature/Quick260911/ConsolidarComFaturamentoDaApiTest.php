<?php

namespace Tests\Feature\Quick260911;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\FechamentoSnapshot;
use App\Services\Fechamento\FechamentoFonteFaturamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260911-eph — `fechamento:consolidar-mes` com a fonte nova.
 *
 * Toda asserção de resultado é por RECONSULTA ao banco, nunca por
 * `expectsOutput` (disciplina de `.planning/learnings/desempenho-bonificacao.md` §4).
 *
 * O teste mais importante desta suíte é o da chave DESLIGADA: ele é a trava
 * que garante que nenhuma consolidação existente passou a fazer chamada HTTP
 * de repente — a consolidação também é acionada pelo botão "Refazer
 * fechamento" da tela.
 */
class ConsolidarComFaturamentoDaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ligarChave(): void
    {
        Configuracao::set(FechamentoFonteFaturamento::CHAVE, '1');
        // O leitor memoiza por instância e o container é singleton dentro do
        // teste — sem isto, o comando leria o valor anterior.
        app(FechamentoFonteFaturamento::class)->esquecer();
    }

    private function fakeApi(float $grossBilling): void
    {
        Http::fake([
            '*/performance/*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => $grossBilling]],
                'items'          => [],
            ], 200),
        ]);
    }

    private function empresaAdmanDriven(): Company
    {
        return Company::factory()->create(['adman_account_id' => 'cust-'.uniqid()]);
    }

    private function fonteGravada(Company $company): ?string
    {
        return FechamentoSnapshot::query()
            ->where('company_id', $company->id)
            ->whereDate('mes_referencia', '2026-08-01')
            ->value('faturamento_fonte');
    }

    #[Test]
    public function chave_desligada_nao_faz_nenhuma_chamada_http_e_grava_soma_diaria(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 167_537.54]);

        $this->fakeApi(170_363.19);

        $exitCode = $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->run();

        Http::assertNothingSent();
        $this->assertSame(0, $exitCode);

        $snapshot = FechamentoSnapshot::query()
            ->where('company_id', $company->id)
            ->whereDate('mes_referencia', '2026-08-01')
            ->firstOrFail();

        $this->assertEqualsWithDelta(167_537.54, (float) $snapshot->faturamento_total, 0.01);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $snapshot->faturamento_fonte);
    }

    #[Test]
    public function chave_ligada_em_mes_fechado_grava_o_numero_da_api(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 167_537.54]);

        $this->fakeApi(170_363.19);
        $this->ligarChave();

        $exitCode = $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->run();

        $this->assertSame(0, $exitCode);

        $snapshot = FechamentoSnapshot::query()
            ->where('company_id', $company->id)
            ->whereDate('mes_referencia', '2026-08-01')
            ->firstOrFail();

        $this->assertEqualsWithDelta(170_363.19, (float) $snapshot->faturamento_total, 0.01);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $snapshot->faturamento_fonte);
    }

    #[Test]
    public function as_duas_chamadas_do_rollup_usam_a_mesma_fonte(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 100_000.00]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-07-10', 'revenue' => 90_000.00]);

        $this->fakeApi(120_000.00);
        $this->ligarChave();

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->run();

        // A competência consolidada E o mês anterior (usado para a evolução
        // de faixa) precisam vir da MESMA fonte. Se só a competência usasse a
        // API, a diferença sistemática de ~3,4% entre as fontes viraria
        // "subiu de faixa" inventada e o Passo 8 avisaria os admins.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'dateFrom=2026-08-01')
            && str_contains($request->url(), 'dateTo=2026-08-31'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'dateFrom=2026-07-01')
            && str_contains($request->url(), 'dateTo=2026-07-31'));
    }

    #[Test]
    public function competencia_em_curso_nao_chama_a_api_nem_com_a_chave_ligada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 8_000.00]);

        $this->fakeApi(50_000.00);
        $this->ligarChave();

        $exitCode = $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-09'])->run();

        // Nem para setembro (janela até hoje) nem para agosto (o mês anterior
        // da mesma execução) — as duas chamadas andam juntas.
        Http::assertNothingSent();
        $this->assertSame(0, $exitCode);

        $snapshot = FechamentoSnapshot::query()
            ->where('company_id', $company->id)
            ->whereDate('mes_referencia', '2026-09-01')
            ->firstOrFail();

        $this->assertEqualsWithDelta(8_000.00, (float) $snapshot->faturamento_total, 0.01);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $snapshot->faturamento_fonte);
    }

    #[Test]
    public function mais_da_metade_em_fallback_nao_grava_e_sai_com_erro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $companyA = $this->empresaAdmanDriven();
        $companyB = $this->empresaAdmanDriven();

        // As duas têm faturamento na soma diária — a COBERTURA fica em 100% e
        // o gate do Passo 6 passa. É exatamente por isso que o gate da fonte
        // precisa existir: o fallback não deixa rastro na cobertura.
        AdmanMetric::create(['company_id' => $companyA->id, 'reference_date' => '2026-08-10', 'revenue' => 50_000.00]);
        AdmanMetric::create(['company_id' => $companyB->id, 'reference_date' => '2026-08-10', 'revenue' => 60_000.00]);

        Http::fake(['*/performance/*' => Http::response([], 500)]);
        $this->ligarChave();

        $exitCode = $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->run();

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, FechamentoSnapshot::count());
    }

    #[Test]
    public function fallback_dentro_do_teto_grava_e_registra_a_fonte_por_empresa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $ok      = Company::factory()->create(['adman_account_id' => 'cust-ok']);
        $quebrou = Company::factory()->create(['adman_account_id' => 'cust-quebrou']);

        AdmanMetric::create(['company_id' => $ok->id, 'reference_date' => '2026-08-10', 'revenue' => 50_000.00]);
        AdmanMetric::create(['company_id' => $quebrou->id, 'reference_date' => '2026-08-10', 'revenue' => 60_000.00]);

        // Uma empresa responde, a outra não: 1 de 2 em fallback NÃO é "mais
        // da metade" — o mês é gravado, com a fonte registrada linha a linha.
        Http::fake([
            '*/performance/cust-ok*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => 55_000.00]],
                'items'          => [],
            ], 200),
            '*/performance/*' => Http::response([], 500),
        ]);
        $this->ligarChave();

        $exitCode = $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->run();

        $this->assertSame(0, $exitCode);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $this->fonteGravada($ok));
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK, $this->fonteGravada($quebrou));

        // O número da empresa em fallback continua sendo o da soma diária —
        // o fallback marca a procedência, não inventa valor.
        $snapshotQuebrou = FechamentoSnapshot::query()
            ->where('company_id', $quebrou->id)
            ->whereDate('mes_referencia', '2026-08-01')
            ->firstOrFail();

        $this->assertEqualsWithDelta(60_000.00, (float) $snapshotQuebrou->faturamento_total, 0.01);
    }

    #[Test]
    public function empresa_sem_cust_id_nao_conta_no_gate_da_fonte(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        // Nenhuma empresa tenta a API: o denominador do gate é zero e o
        // fechamento roda normalmente, sem recusa.
        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 30_000.00]);

        Http::fake(['*/performance/*' => Http::response([], 500)]);
        $this->ligarChave();

        $exitCode = $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->run();

        Http::assertNothingSent();
        $this->assertSame(0, $exitCode);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $this->fonteGravada($company));
    }
}
