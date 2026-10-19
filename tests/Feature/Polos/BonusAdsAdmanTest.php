<?php

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\MlbEmpresa;
use App\Models\PoloAdsBonificacao;
use App\Services\AdmanService;
use App\Services\Polos\BonificacoesAdsPolos;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TKT-0021 (DEV-47) — bônus de ADS ao lado do consumo em /polos/empresas.
 *
 * A Adman liberou para integrador, em 19/10/2026, as bonificações de Product Ads da conta
 * (`/ads/{cust}/bonifications`): só bônus vigentes e com saldo. O `polos:ads-bonus` lê uma vez
 * por dia e grava em `polos_ads_bonificacoes`; a tela mostra o saldo somado e os dias do bônus
 * que vence primeiro. Os "dias restantes" da Adman contam o dia de hoje.
 *
 * @group polos
 */
class BonusAdsAdmanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.adman.polos_pausa_ms' => 0]);
        // Mesmo dia das contas conferidas ao vivo na Adman (ACASANSA, Grife Home).
        $this->travelTo(Carbon::parse('2026-10-19 10:30:00', 'America/Sao_Paulo'));
    }

    private function empresa(string $cust, array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome' => "Empresa {$cust}", 'tipo' => 'POLO', 'projeto' => 'POLOS', 'fase' => 'M2',
            'cust_id' => $cust, 'polo' => 'Arapongas', 'estagio' => 'Não Listado',
        ], $opts));
    }

    /** Formato real da Adman (Grife Home, 19/10/2026). */
    private function bonusGrifeHome(): array
    {
        return [
            ['level' => 'account', 'campaignId' => null, 'campaignName' => null, 'benefitName' => 'seller-startup-program',
             'amount' => 250, 'balance' => 250, 'currencyId' => 'BRL', 'endDate' => '2027-07-27T23:59:00Z', 'daysRemaining' => 282],
            ['level' => 'account', 'campaignId' => null, 'campaignName' => null, 'benefitName' => 'manual-credits',
             'amount' => 3000, 'balance' => 2978.96, 'currencyId' => 'BRL', 'endDate' => '2026-11-06T00:00:00Z', 'daysRemaining' => 19],
        ];
    }

    // ─── Leitura na Adman ────────────────────────────────────────────────────

    public function test_le_bonificacoes_e_trata_conta_nao_vinculada_como_sem_resposta(): void
    {
        Http::fake([
            '*/ads/111/bonifications' => Http::response(['bonifications' => $this->bonusGrifeHome()]),
            '*/ads/222/bonifications' => Http::response(['bonifications' => []]),
            '*/ads/333/bonifications' => Http::response(['message' => 'User is not mentored by agency'], 500),
            '*/ads/444/bonifications' => Http::response(['message' => 'algo inesperado']),
        ]);
        $adman = app(AdmanService::class);

        $this->assertCount(2, $adman->fetchBonificacoes('111'));
        $this->assertSame([], $adman->fetchBonificacoes('222'), 'Lista vazia é resposta válida: sem bônus.');
        $this->assertNull($adman->fetchBonificacoes('333'));
        $this->assertNull($adman->fetchBonificacoes('444'), 'Resposta sem a lista não pode virar "sem bônus".');
    }

    public function test_grava_saldo_somado_vencimento_mais_proximo_e_cada_bonus(): void
    {
        Http::fake([
            '*/ads/111/bonifications' => Http::response(['bonifications' => $this->bonusGrifeHome()]),
            '*/ads/222/bonifications' => Http::response(['bonifications' => []]),
        ]);
        $servico = app(BonificacoesAdsPolos::class);

        $servico->atualizar('111');
        $servico->atualizar('222');

        $l = PoloAdsBonificacao::where('cust_id', '111')->first();
        $this->assertSame(3228.96, $l->saldo);
        $this->assertSame('2026-11-06', $l->vence_em->toDateString(), 'O dia do fim é o da Adman, sem converter fuso.');
        $this->assertSame(['manual-credits', 'seller-startup-program'], array_column($l->itens, 'programa'));
        // assertEquals: o JSON devolve 3000.0 como 3000 — mesmo número na tela.
        $this->assertEquals(['valor' => 3000, 'saldo' => 2978.96], array_intersect_key($l->itens[0], ['valor' => 1, 'saldo' => 1]));
        $this->assertNotNull($l->verificado_em);

        $vazia = PoloAdsBonificacao::where('cust_id', '222')->first();
        $this->assertSame(0.0, $vazia->saldo);
        $this->assertNull($vazia->vence_em);
        $this->assertSame([], $vazia->itens);
    }

    public function test_conta_que_a_adman_nao_enxerga_preserva_a_ultima_leitura(): void
    {
        PoloAdsBonificacao::create(['cust_id' => '333', 'saldo' => 100, 'vence_em' => '2026-11-06', 'itens' => [], 'verificado_em' => now()->subDay()]);
        Http::fake(['*/ads/333/bonifications' => Http::response(['message' => 'User is not mentored by agency'], 500)]);

        $this->assertNull(app(BonificacoesAdsPolos::class)->atualizar('333'));
        $this->assertSame(100.0, PoloAdsBonificacao::where('cust_id', '333')->value('saldo'));
    }

    // ─── Dias restantes e o que a tela mostra ────────────────────────────────

    public function test_dias_restantes_contam_o_dia_de_hoje_como_a_adman(): void
    {
        $this->assertSame(19, PoloAdsBonificacao::diasRestantes('2026-11-06'));
        $this->assertSame(282, PoloAdsBonificacao::diasRestantes('2027-07-27'));
        $this->assertSame(1, PoloAdsBonificacao::diasRestantes('2026-10-19'), 'Vence hoje = último dia.');
        $this->assertSame(0, PoloAdsBonificacao::diasRestantes('2026-10-18'));
        // Print do TKT-0006: fim 12/10, visto em 05/10 → a Adman mostrava 8 dias.
        $this->assertSame(8, PoloAdsBonificacao::diasRestantes('2026-10-12', Carbon::parse('2026-10-05 09:13', 'America/Sao_Paulo')));
        // 23h30 em Brasília já é o dia seguinte em UTC: continua contando pelo dia daqui.
        $this->assertSame(19, PoloAdsBonificacao::diasRestantes('2026-11-06', Carbon::parse('2026-10-19 23:30', 'America/Sao_Paulo')));
    }

    public function test_tela_soma_so_o_que_ainda_vale_e_mostra_o_prazo_mais_proximo(): void
    {
        $leitura = new PoloAdsBonificacao([
            'cust_id'       => '111',
            'saldo'         => 3328.96,
            'vence_em'      => '2026-10-18',
            'itens'         => array_merge(
                [['nivel' => 'campaign', 'campanha' => 'Liquida 10.10', 'programa' => 'manual-credits', 'valor' => 21, 'saldo' => 100, 'moeda' => 'BRL', 'vence_em' => '2026-10-18']],
                BonificacoesAdsPolos::normalizar($this->bonusGrifeHome()),
            ),
            'verificado_em' => now()->subDays(2),
        ]);

        $tela = $leitura->paraTela();

        $this->assertSame(3228.96, $tela['saldo'], 'O bônus que venceu ontem sai da soma.');
        $this->assertSame(3250.0, $tela['valor']);
        $this->assertSame(19, $tela['dias']);
        $this->assertSame('2026-11-06', $tela['vence_em']);
        $this->assertCount(2, $tela['itens']);
        $this->assertSame([19, 282], array_column($tela['itens'], 'dias'));

        $vazia = new PoloAdsBonificacao(['cust_id' => '222', 'saldo' => 0, 'itens' => [], 'verificado_em' => now()]);
        $this->assertNull($vazia->paraTela(), 'Sem bônus vigente a célula não mostra nada.');
    }

    private function invocar(string $metodo, array $arg): array
    {
        $m = new ReflectionMethod(PolosController::class, $metodo);
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), $arg);
    }

    public function test_linha_da_empresa_recebe_o_bonus_so_com_leitura_fresca(): void
    {
        $itens = BonificacoesAdsPolos::normalizar($this->bonusGrifeHome());
        PoloAdsBonificacao::create(['cust_id' => '111', 'saldo' => 3228.96, 'vence_em' => '2026-11-06', 'itens' => $itens, 'verificado_em' => now()->subHours(2)]);
        PoloAdsBonificacao::create(['cust_id' => '222', 'saldo' => 3228.96, 'vence_em' => '2026-11-06', 'itens' => $itens, 'verificado_em' => now()->subHours(PoloAdsBonificacao::FRESCOR_HORAS + 1)]);
        PoloAdsBonificacao::create(['cust_id' => '333', 'saldo' => 0, 'vence_em' => null, 'itens' => [], 'verificado_em' => now()]);

        $linhas = collect($this->invocar('comBonusAds', [
            ['cust_id' => '111'], ['cust_id' => '222'], ['cust_id' => '333'], ['cust_id' => '444'],
        ]))->keyBy('cust_id');

        $this->assertSame(3228.96, $linhas['111']['ads_bonus']['saldo']);
        $this->assertSame(19, $linhas['111']['ads_bonus']['dias']);
        $this->assertNull($linhas['222']['ads_bonus'], 'Leitura velha não aparece.');
        $this->assertNull($linhas['333']['ads_bonus'], 'Conta lida sem bônus não aparece.');
        $this->assertNull($linhas['444']['ads_bonus'], 'Conta sem leitura não aparece.');
    }

    public function test_bonus_so_aparece_no_mes_corrente(): void
    {
        PoloAdsBonificacao::create(['cust_id' => '111', 'saldo' => 3228.96, 'vence_em' => '2026-11-06',
            'itens' => BonificacoesAdsPolos::normalizar($this->bonusGrifeHome()), 'verificado_em' => now()]);
        $polos = [['polo' => 'Arapongas', 'empresas' => [['cust_id' => '111', 'faturamento' => 10.0, 'ads_desligado' => null]]]];

        $corrente = $this->invocar('linhasDaVisaoCompleta', ['polos' => $polos, 'parcial' => true]);
        $fechado  = $this->invocar('linhasDaVisaoCompleta', ['polos' => $polos, 'parcial' => false]);

        $this->assertSame(3228.96, $corrente[0]['ads_bonus']['saldo']);
        $this->assertArrayNotHasKey('ads_bonus', $fechado[0], 'Bônus é estado de agora: mês fechado não traz.');
    }

    // ─── Leitura diária (polos:ads-bonus) ────────────────────────────────────

    public function test_comando_le_o_universo_dos_polos_e_resume_pelo_banco(): void
    {
        $this->empresa('201');
        $this->empresa('202', ['fase' => 'Fechamento']);
        $this->empresa('203');
        $this->empresa('999', ['fase' => 'M0']); // fora das telas de faturamento
        $this->mock(AdmanService::class, fn ($m) => $m->shouldReceive('fetchBonificacoes')->andReturnUsing(fn ($cust) => match ($cust) {
            '201'   => $this->bonusGrifeHome(),
            '202'   => [],
            default => null,
        }));

        $this->artisan('polos:ads-bonus')
            ->expectsOutputToContain('lidas 2/3 (1 com bônus)')
            ->expectsOutputToContain('ou falhou): 203')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['201', '202'], PoloAdsBonificacao::pluck('cust_id')->all());
    }

    public function test_comando_nao_roda_junto_com_o_sync_de_faturamento(): void
    {
        $this->empresa('201');
        $this->mock(AdmanService::class, fn ($m) => $m->shouldNotReceive('fetchBonificacoes'));

        $outra = Cache::lock('polos-sync-faturamento:handle', 60);
        $this->assertTrue($outra->get());
        try {
            $this->artisan('polos:ads-bonus')->expectsOutputToContain('pulado')->assertSuccessful();
        } finally {
            $outra->release();
        }
    }

    public function test_comando_esta_agendado_de_manha(): void
    {
        $ev = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'polos:ads-bonus'));

        $this->assertNotNull($ev);
        $this->assertSame('15 8 * * *', $ev->expression);
        $this->assertSame('America/Sao_Paulo', $ev->timezone);
        $this->assertTrue($ev->runInBackground);
    }
}
