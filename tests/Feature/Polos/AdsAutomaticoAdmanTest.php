<?php

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Jobs\SyncPolosFaturamentoJob;
use App\Models\MlbEmpresa;
use App\Models\PoloAdsStatus;
use App\Models\PoloFaturamentoSnapshot;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\MlCategoriaService;
use App\Services\Polos\AdsCampanhasPolos;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TKT-0003, segunda volta — "ADS ligado/desligado" AUTOMÁTICO pela Adman.
 *
 * Ligado = ao menos uma campanha "active" em /ads/{cust}/campaigns. A leitura grava
 * `mlb_empresas.ads_desligado` (o que as telas já leem) e `polos_ads_status` (de onde e
 * quando). Conta que a Adman não enxerga fica como estava — e segue marcável à mão.
 *
 * @group polos
 */
class AdsAutomaticoAdmanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.adman.polos_pausa_ms' => 0]);
    }

    private function empresa(string $cust, array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome' => "Empresa {$cust}", 'tipo' => 'POLO', 'projeto' => 'POLOS', 'fase' => 'M2',
            'cust_id' => $cust, 'polo' => 'Arapongas', 'estagio' => 'Não Listado',
        ], $opts));
    }

    private function campanhas(array $status): array
    {
        return array_map(fn ($s, $i) => ['campaignId' => "c{$i}", 'name' => "Campanha {$i}", 'status' => $s, 'budget' => 50], $status, array_keys($status));
    }

    // ─── Leitura na Adman ────────────────────────────────────────────────────

    public function test_conta_campanhas_ativas_e_trata_conta_nao_vinculada_como_sem_resposta(): void
    {
        Http::fake([
            '*/ads/111/campaigns' => Http::response($this->campanhas(['active', 'paused', 'ACTIVE'])),
            '*/ads/222/campaigns' => Http::response(['data' => $this->campanhas(['paused'])]),
            '*/ads/333/campaigns' => Http::response(['message' => 'User is not mentored by agency'], 500),
        ]);
        $adman = app(AdmanService::class);

        $this->assertSame(['ativas' => 2, 'total' => 3], $adman->fetchCampanhasAtivas('111'));
        $this->assertSame(['ativas' => 0, 'total' => 1], $adman->fetchCampanhasAtivas('222'));
        $this->assertNull($adman->fetchCampanhasAtivas('333'));
    }

    public function test_grava_no_cadastro_e_na_leitura_inclusive_em_cadastro_duplicado(): void
    {
        $a = $this->empresa('111');
        $b = $this->empresa('111', ['nome' => 'Duplicada']);
        $c = $this->empresa('222');
        Http::fake([
            '*/ads/111/campaigns' => Http::response($this->campanhas(['active'])),
            '*/ads/222/campaigns' => Http::response([]),   // sem campanha nenhuma = desligado
        ]);

        $servico = app(AdsCampanhasPolos::class);
        $mapa    = AdsCampanhasPolos::mapaIds();
        $this->assertFalse($servico->atualizar('111', $mapa['111']));
        $this->assertTrue($servico->atualizar('222', $mapa['222']));

        $this->assertFalse($a->refresh()->ads_desligado);
        $this->assertFalse($b->refresh()->ads_desligado);
        $this->assertTrue($c->refresh()->ads_desligado);
        $this->assertSame(0, PoloAdsStatus::where('cust_id', '222')->value('campanhas_total'));
        $this->assertNotNull(PoloAdsStatus::where('cust_id', '111')->value('verificado_em'));
    }

    public function test_conta_que_a_adman_nao_enxerga_preserva_a_marcacao_manual(): void
    {
        $e = $this->empresa('333', ['ads_desligado' => true]);
        Http::fake(['*/ads/333/campaigns' => Http::response(['message' => 'User is not mentored by agency'], 500)]);

        $this->assertNull(app(AdsCampanhasPolos::class)->atualizar('333', [$e->id]));

        $this->assertTrue($e->refresh()->ads_desligado);
        $this->assertSame(0, PoloAdsStatus::count());
    }

    // ─── Sync diário (polos:warm / botão Sincronizar) ────────────────────────

    private function admanMock(array $recusados = [], ?\Closure $campanhas = null): void
    {
        $this->mock(AdmanService::class, function ($m) use ($recusados, $campanhas) {
            $m->shouldReceive('fetchPerformanceBreakdown')->andReturnUsing(
                fn ($cust) => in_array($cust, $recusados, true) ? null : ['gross_billing' => 1000.0, 'net_por_categoria' => []],
            );
            $m->shouldReceive('fetchAdsInvestmentTotal')->andReturn(0.0);
            $m->shouldReceive('fetchCampanhasAtivas')->andReturnUsing($campanhas ?? fn () => ['ativas' => 1, 'total' => 2]);
        });
        $this->mock(MlCategoriaService::class);
    }

    public function test_sync_do_mes_corrente_le_as_campanhas_de_quem_respondeu(): void
    {
        $ok  = $this->empresa('201');
        $rec = $this->empresa('202', ['ads_desligado' => true]);
        $this->admanMock(recusados: ['202']);

        (new SyncPolosFaturamentoJob(null, null, null))->handle(app(AdmanService::class), app(MlCategoriaService::class));

        $this->assertFalse($ok->refresh()->ads_desligado);
        $this->assertTrue($rec->refresh()->ads_desligado, 'Conta recusada pela Adman não pode perder a marcação.');
        $this->assertSame(['201'], PoloAdsStatus::pluck('cust_id')->all());
    }

    public function test_falha_no_status_de_ads_nunca_custa_o_faturamento(): void
    {
        $this->empresa('201');
        $this->admanMock(campanhas: fn () => throw new \RuntimeException('Adman caiu'));

        (new SyncPolosFaturamentoJob(null, null, null))->handle(app(AdmanService::class), app(MlCategoriaService::class));

        $this->assertSame(1, PoloFaturamentoSnapshot::where('cust_id', '201')->count());
        $this->assertSame(0, PoloAdsStatus::count());
    }

    public function test_sincronizar_mes_passado_nao_regrava_o_status_de_hoje(): void
    {
        $e = $this->empresa('201', ['ads_desligado' => true]);
        $this->admanMock();

        $mesPassado = now()->subMonthNoOverflow();
        (new SyncPolosFaturamentoJob($mesPassado->copy()->startOfMonth()->toDateString(), $mesPassado->copy()->endOfMonth()->toDateString(), null))
            ->handle(app(AdmanService::class), app(MlCategoriaService::class));

        $this->assertTrue($e->refresh()->ads_desligado);
        $this->assertSame(0, PoloAdsStatus::count());
    }

    // ─── Segunda leitura do dia (polos:ads-status) ───────────────────────────

    public function test_comando_da_tarde_le_todo_mundo_e_resume_pelo_banco(): void
    {
        $this->empresa('201');
        $this->empresa('202', ['fase' => 'Fechamento']);
        $this->empresa('203');
        $this->empresa('999', ['fase' => 'M0']); // fora das telas de faturamento
        $this->admanMock(campanhas: fn ($cust) => match ($cust) {
            '201'   => ['ativas' => 2, 'total' => 2],
            '202'   => ['ativas' => 0, 'total' => 3],
            default => null,
        });

        $this->artisan('polos:ads-status')
            ->expectsOutputToContain('lidas 2/3 (1 desligado)')
            ->expectsOutputToContain('seguem manuais): 203')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['201', '202'], PoloAdsStatus::pluck('cust_id')->all());
    }

    public function test_comando_da_tarde_nao_roda_junto_com_o_sync_de_faturamento(): void
    {
        $this->empresa('201');
        $this->mock(AdmanService::class, fn ($m) => $m->shouldNotReceive('fetchCampanhasAtivas'));

        $outra = Cache::lock('polos-sync-faturamento:handle', 60);
        $this->assertTrue($outra->get());
        try {
            $this->artisan('polos:ads-status')->expectsOutputToContain('pulado')->assertSuccessful();
        } finally {
            $outra->release();
        }
    }

    public function test_comando_da_tarde_esta_agendado_as_17h30(): void
    {
        $ev = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'polos:ads-status'));

        $this->assertNotNull($ev);
        $this->assertSame('30 17 * * *', $ev->expression);
        $this->assertSame('America/Sao_Paulo', $ev->timezone);
        $this->assertTrue($ev->runInBackground);
    }

    // ─── Tela ────────────────────────────────────────────────────────────────

    private function comAdsAutomatico(array $linhas): array
    {
        $m = new ReflectionMethod(PolosController::class, 'comAdsAutomatico');
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), $linhas);
    }

    public function test_linha_com_leitura_fresca_vira_automatica_e_velha_volta_ao_manual(): void
    {
        PoloAdsStatus::create(['cust_id' => '111', 'campanhas_ativas' => 0, 'campanhas_total' => 4, 'verificado_em' => now()->subHour()]);
        PoloAdsStatus::create(['cust_id' => '222', 'campanhas_ativas' => 3, 'campanhas_total' => 3, 'verificado_em' => now()->subHours(PoloAdsStatus::FRESCOR_HORAS + 1)]);

        $linhas = collect($this->comAdsAutomatico([
            ['cust_id' => '111', 'ads_desligado' => null],
            ['cust_id' => '222', 'ads_desligado' => true],
            ['cust_id' => '333', 'ads_desligado' => false],
        ]))->keyBy('cust_id');

        $this->assertTrue($linhas['111']['ads_desligado'], 'A leitura manda no valor da linha.');
        $this->assertSame(['ativas' => 0, 'total' => 4], array_intersect_key($linhas['111']['ads_auto'], ['ativas' => 1, 'total' => 1]));
        $this->assertNull($linhas['222']['ads_auto'], 'Leitura velha não vale como automática.');
        $this->assertTrue($linhas['222']['ads_desligado']);
        $this->assertNull($linhas['333']['ads_auto']);
        $this->assertFalse($linhas['333']['ads_desligado']);
    }

    public function test_marcacao_manual_e_recusada_quando_a_adman_ja_responde_pela_conta(): void
    {
        $auto   = $this->empresa('111', ['ads_desligado' => false]);
        $manual = $this->empresa('222');
        PoloAdsStatus::create(['cust_id' => '111', 'campanhas_ativas' => 1, 'campanhas_total' => 1, 'verificado_em' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patch(route('polos.empresas.ads', $auto->id), ['ads_desligado' => true])
            ->assertSessionHas('error');
        $this->assertFalse($auto->refresh()->ads_desligado);

        $this->actingAs($admin)
            ->patch(route('polos.empresas.ads', $manual->id), ['ads_desligado' => true])
            ->assertSessionHas('success');
        $this->assertTrue($manual->refresh()->ads_desligado);
    }
}
