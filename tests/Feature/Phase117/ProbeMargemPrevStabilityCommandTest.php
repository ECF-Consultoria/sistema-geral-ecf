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

    // ─────────────────────── Task 3 — modo --relatorio ───────────────────────

    /**
     * Grava uma leitura direto no banco (sem HTTP) — usado por todos os
     * cenários de --relatorio, que montam o histórico manualmente e
     * conferem o veredito por reconsulta ao banco (D-10).
     */
    private function gravarLeitura(
        int $companyId,
        string $periodoKey,
        \DateTimeInterface|string $lidaEm,
        ?float $value = 27.47,
        ?float $prev = 24.08,
        ?int $notaRegua = 4,
        ?string $janela = null,
        ?string $leituraHash = 'hash-padrao',
        bool $httpFalhou = false,
    ): AdmanProbeMargemPrevLeitura {
        return AdmanProbeMargemPrevLeitura::create([
            'company_id'      => $companyId,
            'periodo_key'     => $periodoKey,
            'lida_em'         => $lidaEm,
            'janela_esperada' => $janela,
            'value'           => $value,
            'prev'            => $prev,
            'diff_nativo'     => 14.09,
            'margem_var_pp'   => ($value !== null && $prev !== null) ? round($value - $prev, 6) : null,
            'nota_regua'      => $notaRegua,
            'leitura_hash'    => $leituraHash,
            'http_falhou'     => $httpFalhou,
        ]);
    }

    /** 5 rodadas + 1 na janela de contenção — desenho amostral D-02 completo. */
    private function cincoRodadasComContencao(): array
    {
        return [
            ['lida_em' => '2026-07-20 03:00:00', 'janela' => 'madrugada'],
            ['lida_em' => '2026-07-20 11:15:00', 'janela' => 'contencao_11h'],
            ['lida_em' => '2026-07-20 16:00:00', 'janela' => 'pico_tarde'],
            ['lida_em' => '2026-07-21 11:20:00', 'janela' => 'repeticao_24h'],
            ['lida_em' => '2026-07-21 11:35:00', 'janela' => 'contencao_11h'],
        ];
    }

    public function test_relatorio_reprova_com_flip_de_nota_entre_leituras(): void
    {
        $company = Company::factory()->create();
        $rodadas = $this->cincoRodadasComContencao();

        // Notas: 3, 4, 3, 4, 4 — flip entre leituras NÃO-consecutivas (1ª e
        // 2ª) e entre a 1ª e a 3ª também — D-01 exige zero flip entre
        // DUAS LEITURAS QUAISQUER, não só pares consecutivos.
        $notas = [3, 4, 3, 4, 4];
        foreach ($rodadas as $i => $r) {
            $this->gravarLeitura(
                $company->id, '2026-06', $r['lida_em'],
                value: 100.0, prev: 100.0 - $notas[$i], // valor irrelevante aqui, só precisa existir
                notaRegua: $notas[$i], janela: $r['janela'],
                leituraHash: 'hash-' . $i, // hashes distintos — não é o cenário anti-cache
            );
        }

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--relatorio' => true])->assertExitCode(0);

        $veredito = AdmanProbeMargemPrevVeredito::latest('gerado_em')->firstOrFail();
        $this->assertSame(AdmanProbeMargemPrevVeredito::VEREDITO_REPROVADO, $veredito->veredito);
        $this->assertSame(1, $veredito->empresas_com_flip_count);
        $this->assertSame($company->id, $veredito->empresas_com_flip[0]['company_id']);
        $this->assertEqualsCanonicalizing([3, 4], $veredito->empresas_com_flip[0]['notas']);
    }

    public function test_relatorio_aprova_com_notas_estaveis_e_valores_variando(): void
    {
        $company = Company::factory()->create();
        $rodadas = $this->cincoRodadasComContencao();

        // Mesma nota (4) em todas as 5 leituras, mas value/prev/hash
        // DIFERENTES entre si — prova que "aprovado" não exige payloads
        // idênticos, só notas estáveis. Cobertura de prev 100%.
        foreach ($rodadas as $i => $r) {
            $this->gravarLeitura(
                $company->id, '2026-06', $r['lida_em'],
                value: 100.0 + $i, prev: 96.5 + $i, // margem_var_pp varia mas sempre cai na faixa "nota 4"
                notaRegua: 4, janela: $r['janela'],
                leituraHash: 'hash-' . $i,
            );
        }

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--relatorio' => true])->assertExitCode(0);

        $veredito = AdmanProbeMargemPrevVeredito::latest('gerado_em')->firstOrFail();
        $this->assertSame(AdmanProbeMargemPrevVeredito::VEREDITO_APROVADO, $veredito->veredito);
        $this->assertSame(0, $veredito->empresas_com_flip_count);
        $this->assertSame(1.0, $veredito->cobertura_prev);
    }

    public function test_relatorio_reprova_com_cobertura_de_prev_abaixo_de_80_por_cento(): void
    {
        $company = Company::factory()->create();

        // 7 leituras com prev presente (nota 4 estável, incluindo a rodada
        // obrigatória de contenção — D-02) + 3 leituras SEM prev
        // (nota_regua null, já que margem_var_pp não existiria sem prev) —
        // cobertura = 7/10 = 70% < 80% (MARGEM_COBERTURA_MINIMA).
        $rodadasComPrev = [
            ['lida_em' => '2026-07-20 03:00:00', 'janela' => 'madrugada'],
            ['lida_em' => '2026-07-20 11:15:00', 'janela' => 'contencao_11h'],
            ['lida_em' => '2026-07-20 16:00:00', 'janela' => 'pico_tarde'],
            ['lida_em' => '2026-07-21 03:00:00', 'janela' => null],
            ['lida_em' => '2026-07-21 11:20:00', 'janela' => 'contencao_11h'],
            ['lida_em' => '2026-07-21 16:00:00', 'janela' => null],
            ['lida_em' => '2026-07-22 03:00:00', 'janela' => 'repeticao_24h'],
        ];
        foreach ($rodadasComPrev as $i => $r) {
            $this->gravarLeitura($company->id, '2026-06', $r['lida_em'], value: 100.0, prev: 96.0, notaRegua: 4, janela: $r['janela'], leituraHash: 'hash-' . $i);
        }
        foreach (range(1, 3) as $j) {
            $this->gravarLeitura($company->id, '2026-06', "2026-07-22 1{$j}:00:00", value: null, prev: null, notaRegua: null, janela: null, leituraHash: null, httpFalhou: true);
        }

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--relatorio' => true])->assertExitCode(0);

        $veredito = AdmanProbeMargemPrevVeredito::latest('gerado_em')->firstOrFail();
        $this->assertSame(AdmanProbeMargemPrevVeredito::VEREDITO_REPROVADO, $veredito->veredito);

        // CONTRATO ALTERADO em 2026-07-29: `cobertura_prev` deixou de ser a
        // média agregada de todas as leituras e passou a ser a da PIOR rodada.
        // Nesta fixture cada leitura está numa rodada própria (janela+hora
        // distintas), então as 3 leituras sem `prev` formam rodadas de 0% e a
        // pior é 0.0 — antes o número reportado era a média, 0.7.
        // O veredito REPROVADO, que é o que de fato importa aqui, não mudou.
        $this->assertEqualsWithDelta(0.0, $veredito->cobertura_prev, 0.0001);

        // Prova que o patamar reusado é 0.8 (AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA),
        // não um número inventado no comando.
        $motivoCobertura = collect($veredito->motivos)->first(fn ($m) => str_contains($m, 'cobertura de prev'));
        $this->assertNotNull($motivoCobertura);
        $this->assertStringContainsString('80%', $motivoCobertura);
    }

    /**
     * REGRESSÃO DE BUG REAL — coleta de 2026-07-29.
     *
     * O cálculo original avaliava cobertura de `prev` de forma AGREGADA sobre
     * todas as leituras. Na coleta real isso devolveu `aprovado` indevidamente:
     * quatro rodadas em condição folgada (92,5% cada) diluíram a rodada sob
     * contenção (64,2%) até um agregado de 86,8%, acima do piso de 80%.
     *
     * As condições boas escondiam exatamente a condição ruim que este gate
     * existe para detectar — o gate aprovava o que deveria reprovar.
     *
     * Este teste reproduz a proporção real e exige REPROVADO.
     */
    public function test_rodadas_boas_nao_diluem_rodada_ruim_de_cobertura(): void
    {
        $empresas = Company::factory()->count(10)->create();

        // 4 rodadas folgadas: 9 de 10 empresas com `prev` = 90% (acima do piso)
        $rodadasBoas = [
            ['lida_em' => '2026-07-20 03:00', 'janela' => 'madrugada'],
            ['lida_em' => '2026-07-20 16:00', 'janela' => 'pico_tarde'],
            ['lida_em' => '2026-07-21 03:00', 'janela' => 'madrugada'],
            ['lida_em' => '2026-07-21 16:00', 'janela' => 'repeticao_24h'],
        ];

        foreach ($rodadasBoas as $r => $rodada) {
            foreach ($empresas as $i => $empresa) {
                $temPrev = $i < 9; // 9 de 10
                $this->gravarLeitura(
                    $empresa->id,
                    '2026-06',
                    $rodada['lida_em'] . ':0' . $i,
                    value: $temPrev ? 100.0 : null,
                    prev: $temPrev ? 96.0 : null,
                    notaRegua: $temPrev ? 4 : null,
                    janela: $rodada['janela'],
                    leituraHash: $temPrev ? "boa-{$r}-{$i}" : null,
                    httpFalhou: ! $temPrev,
                );
            }
        }

        // 1 rodada sob contenção: só 6 de 10 com `prev` = 60% (abaixo do piso)
        foreach ($empresas as $i => $empresa) {
            $temPrev = $i < 6;
            $this->gravarLeitura(
                $empresa->id,
                '2026-06',
                '2026-07-22 11:10:0' . $i,
                value: $temPrev ? 100.0 : null,
                prev: $temPrev ? 96.0 : null,
                notaRegua: $temPrev ? 4 : null,
                janela: 'contencao_11h',
                leituraHash: $temPrev ? "cont-{$i}" : null,
                httpFalhou: ! $temPrev,
            );
        }

        // Agregado seria (36+6)/50 = 84% — PASSARIA no piso de 80%.
        // Por rodada, a pior é 60% — tem de REPROVAR.
        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--relatorio' => true])->assertExitCode(0);

        $veredito = AdmanProbeMargemPrevVeredito::latest('gerado_em')->firstOrFail();

        $this->assertSame(
            AdmanProbeMargemPrevVeredito::VEREDITO_REPROVADO,
            $veredito->veredito,
            'Quatro rodadas boas não podem diluir uma rodada ruim — foi assim que o gate aprovou indevidamente em 2026-07-29.'
        );
        $this->assertEqualsWithDelta(0.6, $veredito->cobertura_prev, 0.0001);

        // A contagem de rodadas tem de ser 5 (execuções), não 50 (leituras).
        $this->assertSame(5, $veredito->total_rodadas);
    }

    public function test_relatorio_sinaliza_instrumentacao_suspeita_quando_tudo_identico(): void
    {
        $company = Company::factory()->create();
        $rodadas = $this->cincoRodadasComContencao();

        // As 5 leituras têm o MESMO leitura_hash — sintoma de cache, não de
        // estabilidade real. Precisa vir ANTES de "aprovado" mesmo com
        // desenho amostral e cobertura perfeitos.
        foreach ($rodadas as $i => $r) {
            $this->gravarLeitura($company->id, '2026-06', $r['lida_em'], value: 100.0, prev: 96.0, notaRegua: 4, janela: $r['janela'], leituraHash: 'hash-identico');
        }

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--relatorio' => true])->assertExitCode(0);

        $veredito = AdmanProbeMargemPrevVeredito::latest('gerado_em')->firstOrFail();
        $this->assertSame(AdmanProbeMargemPrevVeredito::VEREDITO_INSTRUMENTACAO_SUSPEITA, $veredito->veredito);
        $this->assertNotSame(AdmanProbeMargemPrevVeredito::VEREDITO_APROVADO, $veredito->veredito);
    }

    public function test_relatorio_reprova_quando_nao_ha_leitura_na_janela_de_contencao(): void
    {
        $company = Company::factory()->create();

        // 5 rodadas, nenhuma delas na janela de contenção (D-02).
        $rodadas = [
            ['lida_em' => '2026-07-20 03:00:00', 'janela' => 'madrugada'],
            ['lida_em' => '2026-07-20 16:00:00', 'janela' => 'pico_tarde'],
            ['lida_em' => '2026-07-21 03:00:00', 'janela' => 'madrugada'],
            ['lida_em' => '2026-07-21 16:00:00', 'janela' => 'pico_tarde'],
            ['lida_em' => '2026-07-22 03:00:00', 'janela' => 'repeticao_24h'],
        ];
        foreach ($rodadas as $i => $r) {
            $this->gravarLeitura($company->id, '2026-06', $r['lida_em'], value: 100.0 + $i, prev: 96.0 + $i, notaRegua: 4, janela: $r['janela'], leituraHash: 'hash-' . $i);
        }

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--relatorio' => true])->assertExitCode(0);

        $veredito = AdmanProbeMargemPrevVeredito::latest('gerado_em')->firstOrFail();
        $this->assertNotSame(AdmanProbeMargemPrevVeredito::VEREDITO_APROVADO, $veredito->veredito);
        $motivoDesenho = collect($veredito->motivos)->first(fn ($m) => str_contains($m, 'D-02'));
        $this->assertNotNull($motivoDesenho);
    }

    public function test_relatorio_exige_minimo_de_5_rodadas(): void
    {
        $company = Company::factory()->create();

        // Só 3 rodadas distintas, uma delas na janela de contenção — ainda
        // assim não pode aprovar (D-02 exige >= 5).
        $rodadas = [
            ['lida_em' => '2026-07-20 03:00:00', 'janela' => 'madrugada'],
            ['lida_em' => '2026-07-20 11:15:00', 'janela' => 'contencao_11h'],
            ['lida_em' => '2026-07-20 16:00:00', 'janela' => 'pico_tarde'],
        ];
        foreach ($rodadas as $i => $r) {
            $this->gravarLeitura($company->id, '2026-06', $r['lida_em'], value: 100.0 + $i, prev: 96.0 + $i, notaRegua: 4, janela: $r['janela'], leituraHash: 'hash-' . $i);
        }

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--relatorio' => true])->assertExitCode(0);

        $veredito = AdmanProbeMargemPrevVeredito::latest('gerado_em')->firstOrFail();
        $this->assertNotSame(AdmanProbeMargemPrevVeredito::VEREDITO_APROVADO, $veredito->veredito);
        $motivoDesenho = collect($veredito->motivos)->first(fn ($m) => str_contains($m, 'D-02'));
        $this->assertNotNull($motivoDesenho);
    }

    public function test_relatorio_nao_toca_a_adman(): void
    {
        Http::fake(); // sem NENHUMA resposta registrada

        $company = Company::factory()->create();
        foreach ($this->cincoRodadasComContencao() as $i => $r) {
            $this->gravarLeitura($company->id, '2026-06', $r['lida_em'], value: 100.0, prev: 96.0, notaRegua: 4, janela: $r['janela'], leituraHash: 'hash-' . $i);
        }

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--relatorio' => true])->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_relatorio_persiste_veredito_reconsultavel(): void
    {
        $company = Company::factory()->create();
        foreach ($this->cincoRodadasComContencao() as $i => $r) {
            $this->gravarLeitura($company->id, '2026-06', $r['lida_em'], value: 100.0, prev: 96.0, notaRegua: 4, janela: $r['janela'], leituraHash: 'hash-' . $i);
        }

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--relatorio' => true])->assertExitCode(0);
        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--relatorio' => true])->assertExitCode(0);

        $this->assertSame(2, AdmanProbeMargemPrevVeredito::where('periodo_key', '2026-06')->count());
        $maisRecente = AdmanProbeMargemPrevVeredito::where('periodo_key', '2026-06')->latest('gerado_em')->firstOrFail();
        $this->assertNotNull($maisRecente);
    }

    public function test_relatorio_ignora_competencia_diferente(): void
    {
        $company = Company::factory()->create();

        // Leituras de maio NÃO devem contaminar o veredito de --mes=2026-06.
        $this->gravarLeitura($company->id, '2026-05', '2026-06-30 11:00:00', value: 999.0, prev: 1.0, notaRegua: 1, janela: 'contencao_11h', leituraHash: 'maio-hash');

        foreach ($this->cincoRodadasComContencao() as $i => $r) {
            $this->gravarLeitura($company->id, '2026-06', $r['lida_em'], value: 100.0, prev: 96.0, notaRegua: 4, janela: $r['janela'], leituraHash: 'hash-' . $i);
        }

        $this->artisan('adman:probe-margem-prev', ['--mes' => '2026-06', '--relatorio' => true])->assertExitCode(0);

        $veredito = AdmanProbeMargemPrevVeredito::latest('gerado_em')->firstOrFail();
        $this->assertSame(5, $veredito->total_leituras);
        $this->assertSame(AdmanProbeMargemPrevVeredito::VEREDITO_APROVADO, $veredito->veredito);
    }
}
