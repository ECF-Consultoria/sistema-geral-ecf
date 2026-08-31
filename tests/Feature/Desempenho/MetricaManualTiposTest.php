<?php

namespace Tests\Feature\Desempenho;

use App\Models\Company;
use App\Models\DesempenhoMetricaManual;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Os três modos de lançar uma célula (2026-08-31): valor cheio, percentual de
 * crescimento e o ponto direto.
 *
 * Cada um entra em um ANDAR diferente do cálculo, e é isso que estes testes
 * travam:
 *
 *  - `valor`      → vira faturamento/CMV, o motor deriva a variação e a régua
 *                   produz o ponto (comportamento original da Fase 136);
 *  - `percentual` → JÁ é a variação; a régua roda sobre ela sem baseline;
 *  - `ponto`      → JÁ é a saída da régua; ela não roda.
 *
 * Fixture Shopee de propósito: leitura 100% local, zero HTTP à Adman.
 */
class MetricaManualTiposTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private ?int $servicoShopeeId = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome' => 'Shopee (fixture tipos)', 'slug' => 'shopee-tipos', 'active' => true,
            'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id' => $this->setorId, 'nome' => 'Analista', 'slug' => 'analista',
            'active' => true, 'ordem' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function percentual_de_faturamento_vira_a_variacao_sem_precisar_de_baseline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        [$user, $company] = $this->cenario();
        // NENHUM ShopeeMetric semeado: sem baseline, o caminho automático não
        // produziria variação nenhuma. O percentual lançado é o atalho.
        $this->lancar($company, DesempenhoMetricaManual::METRICA_FATURAMENTO, DesempenhoMetricaManual::TIPO_PERCENTUAL, 12.5);

        $linha = $this->linhaDaEmpresa($user, $company);

        $this->assertSame(12.5, $linha->faturamento_var_pct);
        $this->assertNotNull($linha->faturamento_pontos, 'a régua tem que rodar sobre o percentual lançado');
        // Sem valor absoluto informado, inventar um seria pior que a ausência.
        $this->assertNull($linha->faturamento_atual);
        $this->assertSame('manual', $linha->quality['faturamento_fonte']);
    }

    #[Test]
    public function percentual_de_margem_entra_como_pontos_percentuais_nao_como_variacao_relativa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        [$user, $company] = $this->cenario();
        $this->lancar($company, DesempenhoMetricaManual::METRICA_MARGEM_CMV, DesempenhoMetricaManual::TIPO_PERCENTUAL, 6.0);

        $linha = $this->linhaDaEmpresa($user, $company);

        // EMPS-03: a régua de margem consome `diff_pp`. Tratar o número como
        // variação relativa daria erro médio de 16,66 (learnings §0.4).
        $this->assertSame(6.0, $linha->margem_var_pp);
        $this->assertNotNull($linha->margem_pontos, 'Shopee com margem manual passa a pontuar (D-07)');
    }

    #[Test]
    public function ponto_lancado_substitui_a_saida_da_regua(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        [$user, $company] = $this->cenario();
        // Faturamento real em QUEDA: a régua daria a nota mínima.
        $this->semearShopee($company, '2026-08', 100.0);
        $this->semearShopee($company, '2026-07', 5000.0);

        $semLancamento = $this->linhaDaEmpresa($user, $company);
        $this->assertNotSame(4.0, $semLancamento->faturamento_pontos);

        $this->lancar($company, DesempenhoMetricaManual::METRICA_FATURAMENTO, DesempenhoMetricaManual::TIPO_PONTO, 4.0);

        $linha = $this->linhaDaEmpresa($user, $company);

        $this->assertSame(4.0, $linha->faturamento_pontos, 'o ponto lançado manda na régua');
        $this->assertSame('manual', $linha->quality['faturamento_fonte']);
    }

    #[Test]
    public function ponto_nao_contamina_a_variacao_exibida(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        [$user, $company] = $this->cenario();
        $this->semearShopee($company, '2026-08', 2000.0);
        $this->semearShopee($company, '2026-07', 1000.0);

        $this->lancar($company, DesempenhoMetricaManual::METRICA_FATURAMENTO, DesempenhoMetricaManual::TIPO_PONTO, 3.0);

        $linha = $this->linhaDaEmpresa($user, $company);

        // O 3.0 é PONTO, não R$ 3,00 de faturamento. Se o override de métrica
        // tivesse tratado como valor, a variação viraria -99,7% e o
        // faturamento exibido, R$ 3,00.
        $this->assertSame(3.0, $linha->faturamento_pontos);
        $this->assertSame(2000.0, $linha->faturamento_atual);
        $this->assertSame(100.0, $linha->faturamento_var_pct);
    }

    #[Test]
    public function lancamento_antigo_sem_tipo_continua_sendo_lido_como_valor_cheio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        [$user, $company] = $this->cenario();
        $this->semearShopee($company, '2026-07', 1000.0);

        // Escrita CRUA, sem passar pelo model — simula a linha que já existia
        // em produção antes da coluna `tipo`, cujo backfill precisa continuar
        // valendo. Se ela fosse lida como ponto, 4000 viraria nota 4000.
        DB::table('desempenho_metricas_manuais')->insert([
            'company_id'     => $company->id,
            'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
            'mes_referencia' => '2026-08-01',
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'tipo'           => DesempenhoMetricaManual::TIPO_VALOR,
            'valor'          => 4000.0,
            'ativo'          => true,
            'lancado_por'    => $user->id,
            'lancado_em'     => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $linha = $this->linhaDaEmpresa($user, $company);

        $this->assertSame(4000.0, $linha->faturamento_atual);
        $this->assertLessThanOrEqual(5.0, (float) $linha->faturamento_pontos);
    }

    // ═══ Fixtures ══════════════════════════════════════════════════════════

    /** @return array{0: User, 1: Company} */
    private function cenario(): array
    {
        $user = User::factory()->create(['name' => 'Analista tipos', 'role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id' => $user->id, 'setor_id' => $this->setorId, 'cargo_id' => $this->cargoAnalistaId,
            'is_principal' => true, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ts      = Carbon::parse('-6 months')->toDateTimeString();
        $company = Company::factory()->create();
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        $this->servicoShopeeId ??= (int) DB::table('servicos')->insertGetId([
            'nome' => 'Serviço Shopee (fixture tipos)', 'valor_padrao' => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true,
            'setor' => Servico::SETOR_SHOPEE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('contratos_servico')->insert([
            'company_id' => $company->id, 'servico_id' => $this->servicoShopeeId,
            'valor_contratado' => 0, 'data_contratacao' => now()->toDateString(),
            'ativo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('company_users')->insert([
            'company_id' => $company->id, 'user_id' => $user->id, 'role' => 'consultor',
            'servico_id' => $this->servicoShopeeId, 'assigned_at' => $ts,
            'created_at' => $ts, 'updated_at' => $ts,
        ]);

        return [$user, $company->fresh()];
    }

    private function lancar(Company $company, string $metrica, string $tipo, float $valor): void
    {
        DesempenhoMetricaManual::create([
            'company_id'     => $company->id,
            'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
            'mes_referencia' => '2026-08-01',
            'metrica'        => $metrica,
            'tipo'           => $tipo,
            'valor'          => $valor,
            'ativo'          => true,
            'lancado_por'    => null,
            'lancado_em'     => now(),
        ]);
    }

    private function semearShopee(Company $c, string $mesYm, float $revenue): void
    {
        ShopeeMetric::create([
            'company_id'     => $c->id,
            'reference_date' => Carbon::parse($mesYm . '-10')->toDateString(),
            'revenue'        => $revenue,
        ]);
    }

    private function linhaDaEmpresa(User $user, Company $company): object
    {
        $linhas = app(CompanyScoreService::class)->computeEmpresasScore(
            $user,
            Carbon::parse('2026-08-01'),
            app(\App\Services\Metrics\MetricPeriodResolver::class)->resolve(['period_key' => '2026-08']),
        );

        $linha = collect($linhas)->firstWhere('company_id', $company->id);

        $this->assertNotNull($linha, 'a empresa precisa aparecer no score por empresa');

        return (object) (array) $linha;
    }
}
