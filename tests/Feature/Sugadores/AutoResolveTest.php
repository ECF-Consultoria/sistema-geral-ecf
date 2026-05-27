<?php

namespace Tests\Feature\Sugadores;

use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorAcao;
use App\Models\SugadorConfig;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\SugadorAnalysisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Phase 15 — Cobre a auto-resolução de sugadores pendentes antigos
 * em SugadorAnalysisService::analyzeCompany.
 *
 * Cenários:
 *  1. Pendente antigo NÃO re-detectado → vira auto_resolvido (+ audit log)
 *  2. Pendente antigo re-detectado → permanece pendente
 *  3. STATUS_TRAVADOS antigos → intocados
 *  4. Pendente DE HOJE (reference_date = hoje) → não é auto-resolvido (rerun manual)
 *  5. dryRun=true → não auto-resolve nada
 */
class AutoResolveTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Carbon $hoje;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'             => 'Empresa Auto-Resolve',
            'cnpj'             => '99999999999999',
            'adman_account_id' => 'ACC-AR-1',
            'active'           => true,
        ]);

        // Config padrão "ligado" — usa defaults com gasto_minimo_sem_venda=20.
        SugadorConfig::create([
            'company_id'                      => $this->company->id,
            'dias_analise'                    => 30,
            'gasto_minimo_sem_venda'          => 20.00,
            'gasto_minimo_logic'              => SugadorConfig::LOGIC_OPTIONAL,
            'pct_anuncios_para_flag_campanha' => 50,
            'incluir_campanhas'               => false,
            'incluir_anuncios'                => true,
            'ativo'                           => true,
        ]);

        $this->hoje = Carbon::create(2026, 5, 27)->startOfDay();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Mocka o AdmanService — fetchAdsMetrics retorna o array dado;
     * fetchAllCampaigns/fetchCampaignsRange retornam vazio (não relevante aqui).
     *
     * @param  array<int, array<string, mixed>>  $ads
     */
    private function mockAdman(array $ads): void
    {
        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldReceive('fetchAllCampaigns')->andReturn([]);
        $mock->shouldReceive('fetchAdsMetrics')->andReturn($ads);
        $mock->shouldReceive('fetchCampaignsRange')->andReturn([]);

        $this->app->instance(AdmanService::class, $mock);
    }

    /**
     * Seeda um sugador antigo já existente em data anterior — simula registro
     * que ficou no histórico de rodadas passadas.
     */
    private function seedSugadorAntigo(string $tipo, string $campaignId, string $adgroupId, string $status, ?Carbon $when = null): Sugador
    {
        $when = $when ?? $this->hoje->copy()->subDay();

        return Sugador::create([
            'company_id'           => $this->company->id,
            'reference_date'       => $when->toDateString(),
            'tipo'                 => $tipo,
            'campaign_id'          => $campaignId,
            'campaign_name'        => 'Camp X',
            'adgroup_id'           => $adgroupId,
            'adgroup_name'         => $adgroupId !== '' ? 'AG X' : null,
            'periodo_inicio'       => $when->copy()->subDays(30)->toDateString(),
            'periodo_fim'          => $when->copy()->subDay()->toDateString(),
            'investimento_periodo' => 100,
            'faturamento_periodo'  => 0,
            'vendas_periodo'       => 0,
            'cliques'              => 10,
            'impressoes'           => 100,
            'motivos'              => ['gasto_sem_venda'],
            'status'               => $status,
        ]);
    }

    /** Helper: array de "ad" no formato esperado por fetchAdsMetrics + evaluateMetrics. */
    private function adArray(string $campaignId, string $adgroupId, float $investment = 100.0, int $vendas = 0): array
    {
        return [
            'campaign_id'    => $campaignId,
            'adgroup_id'     => $adgroupId,
            'adgroup_name'   => 'AG ' . $adgroupId,
            'thumbnail'      => null,
            'adgroup_type'   => null,
            'catalog_listing' => false,
            'investment'     => $investment,
            'revenue'        => 0,
            'sold_quantity'  => $vendas,
            'clicks'         => 10,
            'impressions'    => 100,
            'cpc'            => 1.0,
            'ctr'            => 0.1,
            'acos'           => null,
            'roas'           => null,
            'organic_amount' => null,
            'organic_units'  => null,
            'raw'            => null,
        ];
    }

    public function test_pendente_antigo_nao_redetectado_vira_auto_resolvido(): void
    {
        // Pendente de ontem com chave (adgroup, C1, AG1) — NÃO está no upsert de hoje.
        $sug = $this->seedSugadorAntigo(Sugador::TIPO_ADGROUP, 'C1', 'AG1', Sugador::STATUS_PENDENTE);

        // Hoje, o Adman retorna apenas (C2, AG2) — chave diferente.
        $this->mockAdman([$this->adArray('C2', 'AG2')]);

        $service = $this->app->make(SugadorAnalysisService::class);
        $result  = $service->analyzeCompany($this->company, $this->hoje, false);

        $this->assertSame(1, $result['auto_resolvidos']);

        $sug->refresh();
        $this->assertSame(Sugador::STATUS_AUTO_RESOLVIDO, $sug->status);
        $this->assertNotNull($sug->resolvido_em);
        $this->assertNull($sug->resolvido_por);

        $this->assertDatabaseHas('sugador_acoes', [
            'sugador_id'      => $sug->id,
            'user_id'         => null,
            'acao'            => SugadorAcao::ACAO_AUTO_RESOLVIDO,
            'status_anterior' => Sugador::STATUS_PENDENTE,
            'status_novo'     => Sugador::STATUS_AUTO_RESOLVIDO,
        ]);
    }

    public function test_pendente_antigo_redetectado_permanece_pendente(): void
    {
        // Pendente de ontem com chave (adgroup, C1, AG1).
        $sug = $this->seedSugadorAntigo(Sugador::TIPO_ADGROUP, 'C1', 'AG1', Sugador::STATUS_PENDENTE);

        // Hoje, o Adman retorna (C1, AG1) batendo critério gasto_sem_venda.
        $this->mockAdman([$this->adArray('C1', 'AG1', 100.0, 0)]);

        $service = $this->app->make(SugadorAnalysisService::class);
        $result  = $service->analyzeCompany($this->company, $this->hoje, false);

        $this->assertSame(0, $result['auto_resolvidos']);

        $sug->refresh();
        $this->assertSame(Sugador::STATUS_PENDENTE, $sug->status);

        // Linha de hoje deve existir (upsert criou).
        $this->assertDatabaseHas('sugadores', [
            'company_id'     => $this->company->id,
            'reference_date' => $this->hoje->toDateString(),
            'tipo'           => Sugador::TIPO_ADGROUP,
            'campaign_id'    => 'C1',
            'adgroup_id'     => 'AG1',
            'status'         => Sugador::STATUS_PENDENTE,
        ]);
    }

    public function test_status_travado_nao_eh_tocado(): void
    {
        // Cria um registro travado para cada status protegido — todos com chaves
        // que NÃO aparecem no upsert de hoje.
        $emAcao    = $this->seedSugadorAntigo(Sugador::TIPO_ADGROUP, 'CT1', 'AG-T1', Sugador::STATUS_EM_ACAO);
        $resolvido = $this->seedSugadorAntigo(Sugador::TIPO_ADGROUP, 'CT2', 'AG-T2', Sugador::STATUS_RESOLVIDO);
        $ignorado  = $this->seedSugadorAntigo(Sugador::TIPO_ADGROUP, 'CT3', 'AG-T3', Sugador::STATUS_IGNORADO);
        $movido    = $this->seedSugadorAntigo(Sugador::TIPO_ADGROUP, 'CT4', 'AG-T4', Sugador::STATUS_MOVIDO);

        $this->mockAdman([$this->adArray('OTHER', 'OTHER-AG')]);

        $service = $this->app->make(SugadorAnalysisService::class);
        $result  = $service->analyzeCompany($this->company, $this->hoje, false);

        $this->assertSame(0, $result['auto_resolvidos']);

        $emAcao->refresh();
        $resolvido->refresh();
        $ignorado->refresh();
        $movido->refresh();

        $this->assertSame(Sugador::STATUS_EM_ACAO, $emAcao->status);
        $this->assertSame(Sugador::STATUS_RESOLVIDO, $resolvido->status);
        $this->assertSame(Sugador::STATUS_IGNORADO, $ignorado->status);
        $this->assertSame(Sugador::STATUS_MOVIDO, $movido->status);

        // Nenhum audit log foi criado para nenhum desses.
        foreach ([$emAcao, $resolvido, $ignorado, $movido] as $s) {
            $this->assertDatabaseMissing('sugador_acoes', [
                'sugador_id' => $s->id,
                'acao'       => SugadorAcao::ACAO_AUTO_RESOLVIDO,
            ]);
        }
    }

    public function test_pendente_de_hoje_nao_eh_auto_resolvido(): void
    {
        // Seedar pendente DE HOJE com chave não re-detectada — simula rerun manual
        // que poderia auto-resolver indevidamente se o filtro fosse `<=` em vez de `<`.
        $sug = $this->seedSugadorAntigo(
            Sugador::TIPO_ADGROUP,
            'C-HOJE',
            'AG-HOJE',
            Sugador::STATUS_PENDENTE,
            $this->hoje->copy()
        );

        $this->mockAdman([$this->adArray('C-OUTRA', 'AG-OUTRA')]);

        $service = $this->app->make(SugadorAnalysisService::class);
        $result  = $service->analyzeCompany($this->company, $this->hoje, false);

        $this->assertSame(0, $result['auto_resolvidos']);

        $sug->refresh();
        $this->assertSame(Sugador::STATUS_PENDENTE, $sug->status);
    }

    public function test_dryrun_nao_auto_resolve(): void
    {
        $sug = $this->seedSugadorAntigo(Sugador::TIPO_ADGROUP, 'C-DRY', 'AG-DRY', Sugador::STATUS_PENDENTE);

        $this->mockAdman([$this->adArray('C-NEW', 'AG-NEW')]);

        $service = $this->app->make(SugadorAnalysisService::class);
        $result  = $service->analyzeCompany($this->company, $this->hoje, true);

        $this->assertSame(0, $result['auto_resolvidos']);

        $sug->refresh();
        $this->assertSame(Sugador::STATUS_PENDENTE, $sug->status);

        $this->assertDatabaseMissing('sugador_acoes', [
            'sugador_id' => $sug->id,
        ]);
    }
}
