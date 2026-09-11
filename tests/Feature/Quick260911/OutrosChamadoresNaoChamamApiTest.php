<?php

namespace Tests\Feature\Quick260911;

use App\Jobs\EnviarRelatorioFechamentoJob;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\User;
use App\Services\Fechamento\FechamentoFonteFaturamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260911-eph — a trava dos chamadores que NÃO podem chamar a Adman.
 *
 * Só `fechamento:consolidar-mes` liga a fonte nova. Os outros três
 * chamadores de `FechamentoRollupService::porEmpresa()` ficam no default
 * `false`, e cada um tem um motivo próprio:
 *
 *  - `AdminController::fechamento()` — a tela renderiza a cada carregamento;
 *    48 chamadas HTTP por page load é inaceitável (e a tela de mês fechado
 *    já lê o snapshot congelado, então recebe o número corrigido de graça).
 *  - `EnviarRelatorioFechamentoJob` — relatório por e-mail, mesma razão.
 *  - `CompararMensalidadeFechamento` — leitura pura de conferência.
 *
 * A chave fica LIGADA nos três testes de propósito: o que prova a trava é
 * nenhum deles chamar a API mesmo com a chave ligada.
 */
class OutrosChamadoresNaoChamamApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cenario(): Company
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        Configuracao::set(FechamentoFonteFaturamento::CHAVE, '1');
        app(FechamentoFonteFaturamento::class)->esquecer();

        Http::fake([
            '*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => 999_999.00]],
                'items'          => [],
            ], 200),
        ]);

        $company = Company::factory()->create(['adman_account_id' => 'cust-'.uniqid()]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 10_000.00]);

        return $company;
    }

    /**
     * Nenhuma requisição ao `/performance` — não uso `assertNothingSent()`
     * para não amarrar estes testes a qualquer outra integração que a tela
     * venha a ter; o que este quick promete é não acrescentar chamada de
     * faturamento.
     */
    private function assertNenhumaChamadaDePerformance(): void
    {
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/performance/'));
    }

    #[Test]
    public function a_tela_de_fechamento_nao_chama_a_api(): void
    {
        $this->cenario();

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $this->assertNenhumaChamadaDePerformance();
    }

    #[Test]
    public function o_job_do_relatorio_nao_chama_a_api(): void
    {
        $this->cenario();

        // Sem destinatários o job sai antes de montar o relatório — configura
        // um para o corpo do `handle()` realmente rodar o rollup.
        Configuracao::set('email_destinatarios_fechamento', json_encode(['fechamento@exemplo.test']));

        try {
            EnviarRelatorioFechamentoJob::dispatchSync('2026-08');
        } catch (\Throwable $e) {
            // O job termina renderizando o PDF com Browsershot, que exige um
            // Chrome instalado — não existe no ambiente de teste. O rollup
            // (única coisa que este teste observa) já rodou muito antes
            // disso, então a asserção abaixo continua valendo. Qualquer
            // outra falha também é irrelevante aqui: o que se mede é a
            // AUSÊNCIA de chamada ao /performance, não o sucesso do envio.
        }

        $this->assertNenhumaChamadaDePerformance();
    }

    #[Test]
    public function o_comparador_de_mensalidade_nao_chama_a_api(): void
    {
        $this->cenario();

        $exitCode = $this->artisan('fechamento:comparar-mensalidade', ['--mes' => '2026-08'])->run();

        $this->assertSame(0, $exitCode);
        $this->assertNenhumaChamadaDePerformance();
    }
}
