<?php

namespace Tests\Feature\Phase117;

use App\Models\AdmanProbeMargemPrevLeitura;
use App\Models\AdmanProbeMargemPrevVeredito;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Traits\LogsActivity;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Suite de mecânica do probe de estabilidade de `percentageMargin.prev`
 * (Fase 117, Plano 02 — gate MPP-04).
 *
 * Cobre schema/models (Task 1), o modo de leitura `adman:probe-margem-prev`
 * (Task 2) e o modo `--relatorio` (Task 3). Todos os cenários com HTTP usam
 * `Http::fake()` — nenhum teste chama a Adman real (D-11 é resolvido fora
 * desta suíte, na VPS). Toda conclusão é verificada por reconsulta ao banco
 * via Eloquent, nunca por `expectsOutput()` (D-10).
 *
 * @see .planning/phases/117-margem-em-pontos-percentuais-probe-de-estabilidade-de-prev/117-02-PLAN.md
 */
class ProbeMargemPrevStabilityCommandTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    // ─────────────────────── Task 1 — schema/models ───────────────────────

    public function test_tabelas_existem_com_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('adman_probe_margem_prev_leituras'));
        $this->assertTrue(Schema::hasTable('adman_probe_margem_prev_vereditos'));

        $this->assertTrue(Schema::hasColumns('adman_probe_margem_prev_leituras', [
            'id', 'company_id', 'periodo_key', 'lida_em', 'janela_esperada',
            'value', 'prev', 'diff_nativo', 'margem_var_pp', 'nota_regua',
            'leitura_hash', 'http_falhou', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('adman_probe_margem_prev_vereditos', [
            'id', 'periodo_key', 'gerado_em', 'total_leituras', 'total_empresas',
            'total_rodadas', 'cobertura_prev', 'empresas_com_flip_count',
            'empresas_com_flip', 'veredito', 'motivos', 'created_at', 'updated_at',
        ]));
    }

    public function test_precisao_de_6_casas_decimais_sobrevive_a_ida_e_volta_do_banco(): void
    {
        $company = Company::factory()->create();

        $leitura = AdmanProbeMargemPrevLeitura::create([
            'company_id'  => $company->id,
            'periodo_key' => '2026-06',
            'lida_em'     => now(),
            'value'       => 27.471234,
            'prev'        => 24.081111,
        ]);

        $recarregada = AdmanProbeMargemPrevLeitura::findOrFail($leitura->id);

        // A proteção real: 6 casas decimais NÃO podem ser achatadas para 2 —
        // isso fabricaria identidade bit-a-bit e quebraria a checagem anti-cache.
        $this->assertNotEquals(27.47, $recarregada->value);
        $this->assertNotEquals(24.08, $recarregada->prev);
        $this->assertEqualsWithDelta(27.471234, $recarregada->value, 0.0000001);
        $this->assertEqualsWithDelta(24.081111, $recarregada->prev, 0.0000001);
    }

    public function test_casts_do_model_de_leitura(): void
    {
        $company = Company::factory()->create();

        $leitura = AdmanProbeMargemPrevLeitura::create([
            'company_id'    => $company->id,
            'periodo_key'   => '2026-06',
            'lida_em'       => '2026-07-27 11:15:00',
            'value'         => 27.47,
            'prev'          => 24.08,
            'diff_nativo'   => 14.09,
            'margem_var_pp' => 3.39,
            'nota_regua'    => 4,
            'http_falhou'   => false,
        ]);

        $recarregada = AdmanProbeMargemPrevLeitura::findOrFail($leitura->id);

        $this->assertInstanceOf(\Carbon\Carbon::class, $recarregada->lida_em);
        $this->assertIsFloat($recarregada->value);
        $this->assertIsFloat($recarregada->prev);
        $this->assertIsFloat($recarregada->diff_nativo);
        $this->assertIsFloat($recarregada->margem_var_pp);
        $this->assertIsInt($recarregada->nota_regua);
        $this->assertIsBool($recarregada->http_falhou);
    }

    public function test_casts_do_model_de_veredito(): void
    {
        $veredito = AdmanProbeMargemPrevVeredito::create([
            'periodo_key'             => '2026-06',
            'gerado_em'               => now(),
            'total_leituras'          => 10,
            'total_empresas'          => 2,
            'total_rodadas'           => 5,
            'cobertura_prev'          => 1.0,
            'empresas_com_flip_count' => 1,
            'empresas_com_flip'       => [['company_id' => 1, 'company_name' => 'X', 'notas' => [3, 4]]],
            'veredito'                => AdmanProbeMargemPrevVeredito::VEREDITO_REPROVADO,
            'motivos'                 => ['flip de nota detectado'],
        ]);

        $recarregado = AdmanProbeMargemPrevVeredito::findOrFail($veredito->id);

        $this->assertIsArray($recarregado->empresas_com_flip);
        $this->assertIsArray($recarregado->motivos);
        $this->assertInstanceOf(\Carbon\Carbon::class, $recarregado->gerado_em);
    }

    public function test_http_falhou_default_false_e_aceita_value_prev_nulos(): void
    {
        $company = Company::factory()->create();

        $leitura = AdmanProbeMargemPrevLeitura::create([
            'company_id'  => $company->id,
            'periodo_key' => '2026-06',
            'lida_em'     => now(),
        ]);

        $this->assertFalse($leitura->fresh()->http_falhou);
        $this->assertNull($leitura->fresh()->value);
        $this->assertNull($leitura->fresh()->prev);
    }

    public function test_relacao_company_devolve_a_empresa_correta(): void
    {
        $company = Company::factory()->create();

        $leitura = AdmanProbeMargemPrevLeitura::create([
            'company_id'  => $company->id,
            'periodo_key' => '2026-06',
            'lida_em'     => now(),
        ]);

        $this->assertTrue($leitura->company->is($company));
    }

    public function test_model_de_leitura_nao_usa_logs_activity(): void
    {
        $this->assertFalse(in_array(
            LogsActivity::class,
            class_uses_recursive(AdmanProbeMargemPrevLeitura::class),
            true
        ));
    }

    // ─────────────────────── Task 2 — modo leitura ───────────────────────

    /** Fixture do endpoint /accounts/{custId}/metrics — só percentageMargin (único campo usado pelo probe). */
    private function respostaPercentageMargin(float $value, float $diff, float $prev): array
    {
        return [
            'metrics' => [
                'percentageMargin' => ['value' => $value, 'diff' => $diff, 'prev' => $prev],
            ],
        ];
    }

    /** Vincula um user (com id explícito, ex.: 3 ou 15) a uma empresa Adman elegível (setor performance). */
    private function vincularUserAEmpresaAdman(int $userId, string $custId): Company
    {
        $company = Company::factory()->create(['adman_account_id' => $custId, 'marketplace' => 'meli']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        if (User::find($userId) === null) {
            User::factory()->create(['id' => $userId]);
        }

        $this->inserirPivot($company->id, $userId, 'consultor', $servicoPerf);

        return $company;
    }

    /** Réplica de AdmanService::cacheDay() (privado) — mesma fórmula, pra pré-popular a chave de cache no teste anti-cache. */
    private function cacheDayDeHoje(): string
    {
        return now()->setTimezone(config('app.timezone'))->toDateString();
    }

    public function test_leitura_persiste_uma_linha_por_empresa_da_amostra(): void
    {
        Http::fake([
            '*/accounts/*/metrics*' => Http::response($this->respostaPercentageMargin(27.47, 14.09, 24.08), 200),
        ]);

        $luiz   = $this->vincularUserAEmpresaAdman(3, 'CUST-LUIZ');
        $danilo = $this->vincularUserAEmpresaAdman(15, 'CUST-DANILO');
        // Empresa de controle, fora das duas carteiras — não deve aparecer na amostra.
        Company::factory()->create(['adman_account_id' => 'CUST-FORA', 'marketplace' => 'meli']);

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06'])->assertExitCode(0);

        $this->assertDatabaseCount('adman_probe_margem_prev_leituras', 2);

        $leituraLuiz = AdmanProbeMargemPrevLeitura::where('company_id', $luiz->id)->firstOrFail();
        $this->assertSame(27.47, $leituraLuiz->value);
        $this->assertSame(24.08, $leituraLuiz->prev);
        $this->assertSame(14.09, $leituraLuiz->diff_nativo);
        $this->assertEqualsWithDelta(3.39, $leituraLuiz->margem_var_pp, 0.0001);
        $this->assertSame(4, $leituraLuiz->nota_regua);
        $this->assertFalse($leituraLuiz->http_falhou);
        $this->assertSame('2026-06', $leituraLuiz->periodo_key);
        $this->assertNotEmpty($leituraLuiz->leitura_hash);

        $this->assertDatabaseHas('adman_probe_margem_prev_leituras', ['company_id' => $danilo->id]);
        $this->assertDatabaseMissing('adman_probe_margem_prev_leituras', ['company_id' => Company::where('adman_account_id', 'CUST-FORA')->value('id')]);
    }

    public function test_empresa_sem_fonte_adman_fica_fora_da_amostra(): void
    {
        Http::fake([
            '*/accounts/*/metrics*' => Http::response($this->respostaPercentageMargin(27.47, 14.09, 24.08), 200),
        ]);

        $company = Company::factory()->create(['adman_account_id' => 'CUST-SHOPEE']);
        User::factory()->create(['id' => 3]);
        // financial_source='shopee' (setor Shopee) — não conta como financial_source='adman'.
        $this->inserirLinhaShopee($company->id, 3, 'consultor');

        // Companheira elegível (setor performance) do outro user da amostra —
        // sem isso a amostra inteira ficaria vazia e o comando abortaria por
        // "amostra vazia" (comportamento intencional do <action> item 4), o
        // que mascararia o que este teste quer provar: que o vínculo Shopee
        // especificamente fica de fora, não que a amostra toda está vazia.
        $elegivel = $this->vincularUserAEmpresaAdman(15, 'CUST-ELEGIVEL');

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06'])->assertExitCode(0);

        $this->assertDatabaseCount('adman_probe_margem_prev_leituras', 1);
        $this->assertDatabaseHas('adman_probe_margem_prev_leituras', ['company_id' => $elegivel->id]);
        $this->assertDatabaseMissing('adman_probe_margem_prev_leituras', ['company_id' => $company->id]);
    }

    public function test_falha_http_persiste_http_falhou_sem_abortar_as_demais(): void
    {
        Http::fake([
            '*/accounts/CUST-FALHA/metrics*' => Http::response([], 429),
            '*/accounts/CUST-OK/metrics*'    => Http::response($this->respostaPercentageMargin(27.47, 14.09, 24.08), 200),
        ]);

        $empresaFalha = $this->vincularUserAEmpresaAdman(3, 'CUST-FALHA');
        $empresaOk    = $this->vincularUserAEmpresaAdman(15, 'CUST-OK');

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06'])->assertExitCode(0);

        $this->assertDatabaseCount('adman_probe_margem_prev_leituras', 2);

        $leituraFalha = AdmanProbeMargemPrevLeitura::where('company_id', $empresaFalha->id)->firstOrFail();
        $this->assertTrue($leituraFalha->http_falhou);
        $this->assertNull($leituraFalha->value);
        $this->assertNull($leituraFalha->prev);
        $this->assertNull($leituraFalha->nota_regua);

        $leituraOk = AdmanProbeMargemPrevLeitura::where('company_id', $empresaOk->id)->firstOrFail();
        $this->assertFalse($leituraOk->http_falhou);
        $this->assertSame(27.47, $leituraOk->value);
    }

    public function test_leitura_nao_passa_pelo_cache_do_diff_service(): void
    {
        Http::fake([
            '*/accounts/*/metrics*' => Http::response($this->respostaPercentageMargin(27.47, 14.09, 24.08), 200),
        ]);

        $company = $this->vincularUserAEmpresaAdman(3, 'CUST-CACHE');

        // Pré-popula a chave de cache do AdmanService com um payload DIFERENTE
        // do que o HTTP fake devolveria — se o comando lesse do cache
        // (violação da invariante 1/D-11b), a linha gravada traria 99.99.
        $cacheKey = "adman:account_metrics_detailed:meli:CUST-CACHE:2026-06-01:2026-06-30:" . $this->cacheDayDeHoje();
        Cache::put($cacheKey, ['percentageMargin' => ['value' => 99.99, 'diff' => 1.0, 'prev' => 90.0]], 1440);

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06'])->assertExitCode(0);

        $leitura = AdmanProbeMargemPrevLeitura::where('company_id', $company->id)->firstOrFail();
        $this->assertSame(27.47, $leitura->value);
        $this->assertNotEquals(99.99, $leitura->value);
    }

    public function test_mes_obrigatorio_e_validado(): void
    {
        Http::fake();

        $this->artisan('adman:probe-margem-prev')->assertExitCode(1);
        $this->assertDatabaseCount('adman_probe_margem_prev_leituras', 0);

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-6'])->assertExitCode(1);
        $this->assertDatabaseCount('adman_probe_margem_prev_leituras', 0);

        $this->artisan('adman:probe-margem-prev', ['--mes' => 'junho'])->assertExitCode(1);
        $this->assertDatabaseCount('adman_probe_margem_prev_leituras', 0);

        Http::assertNothingSent();
    }

    public function test_janela_esperada_e_persistida_quando_informada(): void
    {
        Http::fake([
            '*/accounts/*/metrics*' => Http::response($this->respostaPercentageMargin(27.47, 14.09, 24.08), 200),
        ]);

        $comJanela = $this->vincularUserAEmpresaAdman(3, 'CUST-JANELA');

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--janela' => 'contencao_11h'])->assertExitCode(0);

        $this->assertSame(
            'contencao_11h',
            AdmanProbeMargemPrevLeitura::where('company_id', $comJanela->id)->value('janela_esperada')
        );
    }

    public function test_sem_janela_grava_null(): void
    {
        Http::fake([
            '*/accounts/*/metrics*' => Http::response($this->respostaPercentageMargin(27.47, 14.09, 24.08), 200),
        ]);

        $semJanela = $this->vincularUserAEmpresaAdman(15, 'CUST-SEM-JANELA');

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06'])->assertExitCode(0);

        $this->assertNull(
            AdmanProbeMargemPrevLeitura::where('company_id', $semJanela->id)->value('janela_esperada')
        );
    }

    public function test_regua_de_margem_nas_fronteiras(): void
    {
        // 6 empresas, cada uma com um payload que produz margem_var_pp numa
        // fronteira exata da régua (-5, -2, +1, +4) — inclusive um ponto
        // logo abaixo e logo acima de -5 e +4, provando que a cópia da
        // régua é fiel exatamente onde D-01 diz que o flip importa.
        $casos = [
            'CUST-B1' => ['prev' => 105.01, 'esperado' => 1], // margem = -5.01
            'CUST-B2' => ['prev' => 105.00, 'esperado' => 1], // margem = -5.00
            'CUST-B3' => ['prev' => 102.00, 'esperado' => 2], // margem = -2.00
            'CUST-B4' => ['prev' => 99.00,  'esperado' => 3], // margem = +1.00
            'CUST-B5' => ['prev' => 96.00,  'esperado' => 4], // margem = +4.00
            'CUST-B6' => ['prev' => 95.99,  'esperado' => 5], // margem = +4.01
        ];

        $fakes = [];
        $empresas = [];
        foreach ($casos as $custId => $caso) {
            $fakes["*/accounts/{$custId}/metrics*"] = Http::response(
                $this->respostaPercentageMargin(100.0, 0.0, $caso['prev']),
                200
            );
            $empresas[$custId] = $this->vincularUserAEmpresaAdman(3, $custId);
        }
        Http::fake($fakes);

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06'])->assertExitCode(0);

        foreach ($casos as $custId => $caso) {
            $notaRegua = AdmanProbeMargemPrevLeitura::where('company_id', $empresas[$custId]->id)->value('nota_regua');
            $this->assertSame($caso['esperado'], $notaRegua, "custId={$custId} esperava nota {$caso['esperado']}, recebeu {$notaRegua}");
        }
    }
}
