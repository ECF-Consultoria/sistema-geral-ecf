<?php

namespace Tests\Feature\Quick261001;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\FechamentoSnapshot;
use App\Models\ShopeeMetric;
use App\Services\Fechamento\FechamentoSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 261001-gi1 — `fechamento:verificar-consolidacao` passa a conferir
 * NÚMEROS, não só estrutura.
 *
 * Os números dos cenários são do incidente real de 2026-10-01: setembro
 * consolidado às 10:23, sync da Shopee às 10:42 reescrevendo o mês de 19
 * empresas, e a Gabs Folheados (#395) saindo de 6.378,91 gravado para
 * 40.154,54 reais — 6x, e ainda assim na mesma faixa (por sorte).
 *
 * O veredito é o EXIT CODE e a saída `--json`, nunca o texto impresso
 * (`.planning/learnings/desempenho-bonificacao.md` §4).
 */
class ConferenciaFaturamentoDoFechamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum cenário deste quick pode fazer HTTP: a conferência roda em
        // modo cache-only por default. `Http::fake()` sem argumento faz
        // qualquer chamada estourar como falha de asserção visível.
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Linha de fechamento gravada, com a faixa CONGELADA (é por esses
     * limites que a conferência decide se a faixa mudaria).
     */
    private function gravarLinha(Company $company, array $overrides = []): void
    {
        DB::table('fechamento_snapshots')->insert(array_merge([
            'company_id'            => $company->id,
            'company_name'          => $company->name,
            'mes_referencia'        => '2026-09-01',
            'faturamento_ml'        => null,
            'faturamento_shopee'    => null,
            'faturamento_total'     => null,
            'faturamento_fonte'     => FechamentoSnapshot::FONTE_SOMA_DIARIA,
            'faixa_ordem'           => 3,
            'faixa_aplicada'        => 'faixa_3',
            'faixa_limite_inferior' => 5_000.00,
            'faixa_limite_superior' => 50_000.00,
            'valor_faixa'           => 3_000.00,
            'cobranca_mensal'       => 3_000.00,
            'estado'                => FechamentoSnapshot::ESTADO_OK,
            'origem'                => FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'             => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ], $overrides));
    }

    private function conferir(string $mes = '2026-09'): array
    {
        Artisan::call('fechamento:verificar-consolidacao', ['--mes' => $mes, '--json' => true]);

        return json_decode(Artisan::output(), true)['avisos']['faturamento'];
    }

    // ── 1. Competência sem divergência ────────────────────────────────

    #[Test]
    public function competencia_sem_divergencia_nao_alarma_e_sai_com_exit_0(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 40_154.54]);
        $this->gravarLinha($company, [
            'faturamento_shopee' => 40_154.54,
            'faturamento_total'  => 40_154.54,
        ]);

        $f = $this->conferir();

        $this->assertSame([], $f['divergentes']);
        $this->assertSame(0, $f['total_divergentes']);
        $this->assertSame(0, $f['total_faixas_mudariam']);
        $this->assertSame(1, $f['linhas_conferidas']);

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-09'])->assertExitCode(0);
    }

    // ── 2. Valor diferente, MESMA faixa → informação ───────────────────

    #[Test]
    public function faturamento_diferente_na_mesma_faixa_e_informacao_e_nao_derruba_o_exit_code(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        // O caso literal da Gabs Folheados: gravado 6.378,91, real 40.154,54.
        // Os dois valores caem na faixa congelada de 5.000 a 50.000.
        $company = Company::factory()->create(['name' => 'Gabs Folheados', 'adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 40_154.54]);
        $this->gravarLinha($company, [
            'faturamento_shopee' => 6_378.91,
            'faturamento_total'  => 6_378.91,
        ]);

        $f = $this->conferir();

        $this->assertSame(1, $f['total_divergentes']);
        $this->assertSame(0, $f['total_faixas_mudariam']);
        $this->assertSame(6_378.91, $f['divergentes'][0]['gravado']);
        $this->assertSame(40_154.54, $f['divergentes'][0]['atual']);
        $this->assertEqualsWithDelta(33_775.63, $f['divergentes'][0]['diferenca'], 0.01);
        $this->assertFalse($f['divergentes'][0]['faixa_mudaria']);
        $this->assertSame('shopee', $f['divergentes'][0]['causa']);

        // Dinheiro não mudou → o exit code continua 0.
        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-09'])->assertExitCode(0);
    }

    // ── 3. Valor diferente E faixa MUDARIA → urgência ──────────────────

    #[Test]
    public function faturamento_que_muda_de_faixa_entra_nas_inconsistencias_e_derruba_o_exit_code(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        // Mesma sorte da Gabs, invertida: o valor real estoura o teto de
        // 50.000 da faixa congelada, então a mensalidade gravada está errada.
        $company = Company::factory()->create(['name' => 'Empresa Que Estourou', 'adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 80_000.00]);
        $this->gravarLinha($company, [
            'faturamento_shopee' => 6_378.91,
            'faturamento_total'  => 6_378.91,
        ]);

        $f = $this->conferir();

        $this->assertSame(1, $f['total_divergentes']);
        $this->assertSame(1, $f['total_faixas_mudariam']);
        $this->assertTrue($f['divergentes'][0]['faixa_mudaria']);

        Artisan::call('fechamento:verificar-consolidacao', ['--mes' => '2026-09', '--json' => true]);
        $relatorio = json_decode(Artisan::output(), true);

        $classes = array_column($relatorio['inconsistencias'], 'classe');
        $this->assertContains('FAIXA_MUDARIA', $classes);
        $this->assertFalse($relatorio['ok']);

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-09'])->assertExitCode(1);
    }

    #[Test]
    public function valor_abaixo_do_piso_da_faixa_congelada_tambem_conta_como_mudanca_de_faixa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 900.00]);
        $this->gravarLinha($company, [
            'faturamento_shopee' => 30_000.00,
            'faturamento_total'  => 30_000.00,
        ]);

        $f = $this->conferir();

        $this->assertTrue($f['divergentes'][0]['faixa_mudaria']);
        $this->assertSame(1, $f['total_faixas_mudariam']);
    }

    #[Test]
    public function linha_sem_faixa_congelada_nao_conta_como_mudanca_de_faixa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        // Valor fixo de contrato (Mentoria): não há faixa para mudar. A
        // divergência de valor continua aparecendo, mas não como urgência —
        // "não sei" nunca pode virar "mudaria".
        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 80_000.00]);
        $this->gravarLinha($company, [
            'faturamento_shopee'    => 6_378.91,
            'faturamento_total'     => 6_378.91,
            'faixa_ordem'           => null,
            'faixa_aplicada'        => null,
            'faixa_limite_inferior' => null,
            'faixa_limite_superior' => null,
            'estado'                => FechamentoSnapshot::ESTADO_VALOR_FIXO,
        ]);

        $f = $this->conferir();

        $this->assertSame(1, $f['total_divergentes']);
        $this->assertNull($f['divergentes'][0]['faixa_mudaria']);
        $this->assertSame(0, $f['total_faixas_mudariam']);

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-09'])->assertExitCode(0);
    }

    // ── 4. Dado escrito depois do fechamento ───────────────────────────

    #[Test]
    public function relata_a_hora_do_fechamento_e_a_hora_do_dado_escrito_depois(): void
    {
        // A cronologia literal do incidente: fechado 10:23, Shopee 10:42.
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:23:00'));

        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 6_378.91]);
        $this->gravarLinha($company, [
            'faturamento_shopee' => 6_378.91,
            'faturamento_total'  => 6_378.91,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:42:00'));
        ShopeeMetric::where('company_id', $company->id)->first()->update(['revenue' => 40_154.54]);

        Carbon::setTestNow(Carbon::parse('2026-10-01 11:38:00'));
        $f = $this->conferir();

        $this->assertTrue($f['dado_mudou_depois']);
        $this->assertSame('2026-10-01 10:23:00', Carbon::parse($f['fechado_em'])->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 10:42:00', Carbon::parse($f['dado_atualizado_em'])->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 10:42:00', Carbon::parse($f['dado_atualizado_em_por_plataforma']['shopee'])->format('Y-m-d H:i:s'));
        $this->assertSame(1, $f['total_divergentes']);
    }

    // ── 5. Só escrita posterior, SEM mudar valor → não alarma ──────────

    #[Test]
    public function escrita_posterior_que_nao_muda_valor_nao_alarma(): void
    {
        // Este é o teste do falso positivo diário: `adman:reler-dias` roda
        // às 19h e reescreve dias já passados TODO DIA. Se o alarme fosse a
        // existência de escrita posterior, ele apareceria todo santo dia.
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:23:00'));

        $company = Company::factory()->create(['adman_account_id' => 'cust-rele', 'ml_store_id' => null]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 30_000.00]);
        $this->gravarLinha($company, [
            'faturamento_ml'    => 30_000.00,
            'faturamento_total' => 30_000.00,
        ]);

        // A releitura das 19h reescreve a linha com o MESMO número.
        Carbon::setTestNow(Carbon::parse('2026-10-01 19:05:00'));
        AdmanMetric::where('company_id', $company->id)->first()->update(['revenue' => 30_000.00, 'synced_at' => now()]);

        Carbon::setTestNow(Carbon::parse('2026-10-02 08:00:00'));
        $f = $this->conferir();

        // A escrita posterior é FATO (e sai como contexto)...
        $this->assertTrue($f['dado_mudou_depois']);
        // ...mas não é alarme: nenhum valor mudou.
        $this->assertSame([], $f['divergentes']);
        $this->assertSame(0, $f['total_divergentes']);

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-09'])->assertExitCode(0);
    }

    #[Test]
    public function diferenca_de_centavos_fica_dentro_da_tolerancia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 30_000.00]);
        $this->gravarLinha($company, [
            'faturamento_shopee' => 30_000.00,
            'faturamento_total'  => 30_000.00,
        ]);

        $f = $this->conferir();

        $this->assertSame([], $f['divergentes']);
    }

    // ── 6. O comando NÃO altera nada ───────────────────────────────────

    #[Test]
    public function o_comando_nao_escreve_nenhuma_linha_de_fechamento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:23:00'));

        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 80_000.00]);
        $this->gravarLinha($company, [
            'faturamento_shopee' => 6_378.91,
            'faturamento_total'  => 6_378.91,
        ]);

        // Reconsulta ANTES — a linha inteira, não só o número.
        $antes = DB::table('fechamento_snapshots')->get()->map(fn ($l) => (array) $l)->all();

        Carbon::setTestNow(Carbon::parse('2026-10-01 11:38:00'));
        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-09'])->assertExitCode(1);

        // Reconsulta DEPOIS — prova por banco, nunca por stdout.
        $depois = DB::table('fechamento_snapshots')->get()->map(fn ($l) => (array) $l)->all();

        $this->assertSame($antes, $depois);
        $this->assertSame(1, DB::table('fechamento_snapshots')->count());
        $this->assertSame(0, DB::table('fechamento_grupo_snapshots')->count());
        // A métrica também fica intacta: o verificador não "ajusta" a base.
        $this->assertEqualsWithDelta(80_000.00, (float) ShopeeMetric::where('company_id', $company->id)->first()->revenue, 0.01);
    }

    // ── Competência sem fechamento gravado ─────────────────────────────

    #[Test]
    public function competencia_sem_fechamento_gravado_nao_confere_nada_e_nao_alarma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 80_000.00]);

        $f = $this->conferir();

        $this->assertFalse($f['congelado']);
        $this->assertSame(0, $f['linhas_gravadas']);
        $this->assertSame([], $f['divergentes']);
        $this->assertNull($f['fechado_em']);
    }

    // ── Linha gravada sem métrica alguma hoje → não comparável ─────────

    #[Test]
    public function linha_gravada_sem_metrica_agora_sai_como_nao_comparavel_e_nao_como_divergencia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        $this->gravarLinha($company, [
            'faturamento_shopee' => 30_000.00,
            'faturamento_total'  => 30_000.00,
        ]);

        $f = $this->conferir();

        $this->assertSame([], $f['divergentes']);
        $this->assertSame(0, $f['linhas_conferidas']);
        $this->assertSame('sem_metrica_agora', $f['nao_comparaveis'][0]['motivo']);
    }

    // ── Causa da diferença: Shopee x Adman ────────────────────────────

    #[Test]
    public function separa_a_causa_da_diferenca_entre_shopee_e_mercado_livre(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        $company = Company::factory()->create(['adman_account_id' => 'cust-amb', 'ml_store_id' => null]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 20_000.00]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 10_000.00]);
        $this->gravarLinha($company, [
            'faturamento_ml'     => 18_000.00,
            'faturamento_shopee' => 9_000.00,
            'faturamento_total'  => 27_000.00,
        ]);

        $f = $this->conferir();

        $this->assertSame('ambas', $f['divergentes'][0]['causa']);
        $this->assertEqualsWithDelta(18_000.00, $f['divergentes'][0]['gravado_ml'], 0.01);
        $this->assertEqualsWithDelta(20_000.00, $f['divergentes'][0]['atual_ml'], 0.01);
        $this->assertEqualsWithDelta(9_000.00, $f['divergentes'][0]['gravado_shopee'], 0.01);
        $this->assertEqualsWithDelta(10_000.00, $f['divergentes'][0]['atual_shopee'], 0.01);
        $this->assertTrue($f['divergentes'][0]['ml_conferido']);
    }

    #[Test]
    public function linha_gravada_com_a_api_nao_e_comparada_contra_a_soma_diaria(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        // Linha congelada com o total do `/performance` da Adman. Sem
        // --com-api o recálculo devolve a soma diária: réguas diferentes,
        // divergência de ~3,5% garantida em TODA empresa. Isso não pode
        // virar alarme — sai como "não conferido".
        $company = Company::factory()->create(['adman_account_id' => 'cust-api', 'ml_store_id' => null]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 20_000.00]);
        $this->gravarLinha($company, [
            'faturamento_ml'    => 20_700.00,
            'faturamento_total' => 20_700.00,
            'faturamento_fonte' => FechamentoSnapshot::FONTE_API,
        ]);

        $f = $this->conferir();

        $this->assertSame([], $f['divergentes']);
        $this->assertSame('gravado_com_api_sem_conferir', $f['nao_comparaveis'][0]['motivo']);

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-09'])->assertExitCode(0);
    }

    #[Test]
    public function lado_shopee_continua_valendo_quando_o_lado_ml_nao_da_para_conferir(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        // Foi a Shopee que causou o incidente: mesmo sem poder conferir o
        // lado ML, o lado Shopee precisa continuar sendo conferido.
        $company = Company::factory()->create(['adman_account_id' => 'cust-mix', 'ml_store_id' => null]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 20_000.00]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 10_000.00]);
        $this->gravarLinha($company, [
            'faturamento_ml'     => 20_700.00,
            'faturamento_shopee' => 1_000.00,
            'faturamento_total'  => 21_700.00,
            'faturamento_fonte'  => FechamentoSnapshot::FONTE_API,
        ]);

        $f = $this->conferir();

        $this->assertSame(1, $f['total_divergentes']);
        $this->assertSame('shopee', $f['divergentes'][0]['causa']);
        $this->assertFalse($f['divergentes'][0]['ml_conferido']);
        // O lado ML entra no total com o valor GRAVADO, nunca somando réguas
        // diferentes: 20.700 (gravado) + 10.000 (Shopee de hoje).
        $this->assertEqualsWithDelta(20_700.00, $f['divergentes'][0]['atual_ml'], 0.01);
        $this->assertEqualsWithDelta(30_700.00, $f['divergentes'][0]['atual'], 0.01);
    }

    // ── Linha ao vivo (não congelada) fica fora ────────────────────────

    #[Test]
    public function linha_de_origem_diferente_de_consolidar_mes_nao_entra_na_conferencia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 80_000.00]);
        $this->gravarLinha($company, [
            'faturamento_shopee' => 6_378.91,
            'faturamento_total'  => 6_378.91,
            'origem'             => 'tela',
        ]);

        $f = $this->conferir();

        $this->assertFalse($f['congelado']);
        $this->assertSame([], $f['divergentes']);
    }

    // ── Várias empresas: o resumo conta certo ─────────────────────────

    #[Test]
    public function o_resumo_conta_quantas_divergem_e_quantas_mudariam_de_faixa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));

        $mesmaFaixa = Company::factory()->create(['name' => 'Gabs Folheados', 'adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $mesmaFaixa->id, 'reference_date' => '2026-09-10', 'revenue' => 40_154.54]);
        $this->gravarLinha($mesmaFaixa, ['faturamento_shopee' => 6_378.91, 'faturamento_total' => 6_378.91]);

        $mudaFaixa = Company::factory()->create(['name' => 'Estourou o teto', 'adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $mudaFaixa->id, 'reference_date' => '2026-09-10', 'revenue' => 90_000.00]);
        $this->gravarLinha($mudaFaixa, ['faturamento_shopee' => 6_378.91, 'faturamento_total' => 6_378.91]);

        $bate = Company::factory()->create(['name' => 'Bate', 'adman_account_id' => null, 'ml_store_id' => null]);
        ShopeeMetric::create(['company_id' => $bate->id, 'reference_date' => '2026-09-10', 'revenue' => 30_000.00]);
        $this->gravarLinha($bate, ['faturamento_shopee' => 30_000.00, 'faturamento_total' => 30_000.00]);

        $f = $this->conferir();

        $this->assertSame(2, $f['total_divergentes']);
        $this->assertSame(1, $f['total_faixas_mudariam']);
        // Quem muda de dinheiro aparece primeiro.
        $this->assertTrue($f['divergentes'][0]['faixa_mudaria']);
        $this->assertSame('Estourou o teto', $f['divergentes'][0]['company_name']);
    }
}
