<?php

namespace Tests\Feature\Phase37;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Suíte de testes para os novos blocos do endpoint dadosMl() (Phase 37 Plan 01).
 *
 * Cobre: ONB-15 (reputacao: nível + 4 taxas + alerta de risco),
 *        ONB-16 (maturidade: mesesNoPrograma + cluster + subCluster),
 *        ONB-17 (serie cronológica multi-mês com GMV nulo preservado).
 *
 * Reutiliza a estrutura da suíte Phase36 (setUp + helpers criarAdmin,
 * criarEmpresaComImpl, fakeEcf) para garantir paridade de comportamento.
 *
 * @group phase37
 */
class MlbDadosMlReputacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Configura EcfDriveService para testes (sem chamar API real)
        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'test-key',
        ]);
        // Limpa cache para reprodutibilidade (wrapper usa Cache::remember)
        Cache::flush();
    }

    // ─── Helpers de fixture ──────────────────────────────────────────────────

    private function criarAdmin(): User
    {
        return User::factory()->create([
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Cria MlbEmpresa POLO/POLOS + MlbImplementacao com token e dados padrão.
     * Aceita override de colunas de empresa (incluindo cust_id) via $empresaOpts.
     */
    private function criarEmpresaComImpl(array $empresaOpts = [], array $implOpts = []): array
    {
        $empresa = MlbEmpresa::create(array_merge([
            'nome'       => 'Empresa Phase37 ' . Str::random(4),
            'tipo'       => 'POLO',
            'projeto'    => 'POLOS',
            'fase'       => 'M1',
            'polo'       => 'Arapongas',
            'estagio'    => 'Não Listado',
            'criado_por' => 1,
        ], $empresaOpts));

        $impl = MlbImplementacao::create(array_merge([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => MlbImplementacao::dadosPadrao(),
        ], $implOpts));

        return [$empresa, $impl];
    }

    /**
     * Monta Http::fake para o endpoint dados-ml com dados de métricas customizados.
     *
     * ORDEM IMPORTA: subpaths mais específicos ANTES do geral (metricas/mensal e
     * medalhas antes do snapshot /sellers/{custId}). Sempre inclui mock de /signals
     * por causa do HandleInertiaRequests que chama listSignals() em todo request
     * Inertia — sem este mock o request falha no badge da sidebar (Pitfall 4 Phase36).
     *
     * @param string $custId   Cust ID do seller
     * @param array  $metricas Array de métricas para o campo 'data' da resposta mensal
     */
    private function fakeEcf(string $custId, array $metricas = []): void
    {
        Http::fake([
            // Subpaths específicos — DEVEM vir antes do geral
            "*/sellers/{$custId}/metricas/mensal*" => Http::response([
                'data' => $metricas,
            ], 200),

            "*/sellers/{$custId}/medalhas*" => Http::response([
                'data' => [
                    ['timMonthId' => '202605', 'nivelSolucion' => 'GOLD'],
                ],
            ], 200),

            // Snapshot geral — DEVE vir depois dos subpaths
            "*/sellers/{$custId}*" => Http::response([
                'custId'       => $custId,
                'medalhaAtual' => ['nivelSolucion' => 'GOLD', 'programa' => 'POLOS'],
            ], 200),

            // /signals global — HandleInertiaRequests badge sidebar (Pitfall 4 Phase36)
            '*/signals*' => Http::response(['data' => [], 'total' => 0], 200),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ONB-15 — Reputação
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * ONB-15: todas as 4 taxas em '0' → reputacao.alerta=false e nivel correto.
     * Verifica que string '0' NÃO dispara alerta (cast float '0' = 0.0, não > 0).
     */
    public function test_reputacao_sem_alerta(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        $this->fakeEcf('570267839', [[
            'timMonthId'                   => '202605',
            'tgmvLc'                       => 15000.00,
            'totalLivelistings'            => 200,
            'tgmvLcMe2'                    => 15000.00,
            'tgmvLcFlex'                   => 0,
            'fTgmvLcPlaces'                => 0,
            'repCurrentLevel'              => 'green',
            'repClaimsRate'                => '0',
            'repDisputesRate'              => '0',
            'repSellerCancellationsRate'   => '0',
            'repDelayedHtRate'             => '0',
            'mesesNoPrograma'              => 1,
            'clusterSeller'                => 'Seasonal',
            'subClusterSeller'             => 'In professionalization',
        ]]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl));

        $response->assertOk();
        // Nível de reputação mapeado corretamente
        $response->assertJsonPath('reputacao.nivel', 'green');
        // Alerta FALSO quando todas as taxas são '0' (string numérica)
        $response->assertJsonPath('reputacao.alerta', false);
        // As 4 taxas presentes no payload
        $response->assertJsonPath('reputacao.taxas.claims', '0');
        $response->assertJsonPath('reputacao.taxas.disputas', '0');
        $response->assertJsonPath('reputacao.taxas.cancelamentos', '0');
        $response->assertJsonPath('reputacao.taxas.atraso', '0');
    }

    /**
     * ONB-15: repClaimsRate='2' (demais '0') → reputacao.alerta=true.
     * Verifica que string numérica > 0 ativa o alerta de risco.
     */
    public function test_reputacao_com_alerta(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        $this->fakeEcf('570267839', [[
            'timMonthId'                   => '202605',
            'tgmvLc'                       => 12000.00,
            'totalLivelistings'            => 150,
            'tgmvLcMe2'                    => 12000.00,
            'tgmvLcFlex'                   => 0,
            'fTgmvLcPlaces'                => 0,
            'repCurrentLevel'              => 'yellow',
            // taxa de reclamações > 0 — deve disparar alerta
            'repClaimsRate'                => '2',
            'repDisputesRate'              => '0',
            'repSellerCancellationsRate'   => '0',
            'repDelayedHtRate'             => '0',
            'mesesNoPrograma'              => 2,
            'clusterSeller'                => 'Newbie',
            'subClusterSeller'             => 'In professionalization',
        ]]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl));

        $response->assertOk();
        $response->assertJsonPath('reputacao.nivel', 'yellow');
        // Alerta VERDADEIRO porque repClaimsRate='2' → (float)'2' = 2.0 > 0
        $response->assertJsonPath('reputacao.alerta', true);
        $response->assertJsonPath('reputacao.taxas.claims', '2');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ONB-16 — Maturidade
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * ONB-16: maturidade mapeada corretamente de mesesNoPrograma + cluster + subCluster.
     */
    public function test_maturidade(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        $this->fakeEcf('570267839', [[
            'timMonthId'                   => '202605',
            'tgmvLc'                       => 18000.00,
            'totalLivelistings'            => 300,
            'tgmvLcMe2'                    => 18000.00,
            'tgmvLcFlex'                   => 0,
            'fTgmvLcPlaces'                => 0,
            'repCurrentLevel'              => 'green',
            'repClaimsRate'                => '0',
            'repDisputesRate'              => '0',
            'repSellerCancellationsRate'   => '0',
            'repDelayedHtRate'             => '0',
            'mesesNoPrograma'              => 2,
            'clusterSeller'                => 'Newbie',
            'subClusterSeller'             => 'In professionalization',
        ]]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl));

        $response->assertOk();
        // Maturidade com os campos mapeados corretamente (ONB-16)
        $response->assertJsonPath('maturidade.meses', 2);
        $response->assertJsonPath('maturidade.cluster', 'Newbie');
        $response->assertJsonPath('maturidade.subCluster', 'In professionalization');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ONB-17 — Tendência (série multi-mês)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * ONB-17: 3 meses fora de ordem → serie ordenada ASC; mês corrente com GMV nulo preservado.
     *
     * O ECF Drive retorna os meses em ordem não garantida. O controller ordena DESC
     * (usort por timMonthId) e depois inverte para ASC via array_reverse antes de
     * expor a serie. O mês corrente (202606) com tgmvLc=null deve aparecer na serie
     * com gmv=null (não 0) para distinguir ausência de dado de zero vendas.
     */
    public function test_serie_tendencia_multi_mes(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        // Três meses FORA de ordem no payload da API (202605 primeiro, não 202604)
        $this->fakeEcf('570267839', [
            [
                'timMonthId'                   => '202605',
                'tgmvLc'                       => 16000.00,
                'totalLivelistings'            => 280,
                'tgmvLcMe2'                    => 16000.00,
                'tgmvLcFlex'                   => 0,
                'fTgmvLcPlaces'                => 0,
                'repCurrentLevel'              => 'green',
                'repClaimsRate'                => '0',
                'repDisputesRate'              => '0',
                'repSellerCancellationsRate'   => '0',
                'repDelayedHtRate'             => '0',
                'mesesNoPrograma'              => 2,
                'clusterSeller'                => 'Newbie',
                'subClusterSeller'             => 'In professionalization',
                'visitas'                      => 4500,
            ],
            [
                'timMonthId'                   => '202604',
                'tgmvLc'                       => 12000.00,
                'totalLivelistings'            => 200,
                'tgmvLcMe2'                    => 12000.00,
                'tgmvLcFlex'                   => 0,
                'fTgmvLcPlaces'                => 0,
                'repCurrentLevel'              => 'green',
                'repClaimsRate'                => '0',
                'repDisputesRate'              => '0',
                'repSellerCancellationsRate'   => '0',
                'repDelayedHtRate'             => '0',
                'mesesNoPrograma'              => 1,
                'clusterSeller'                => 'Newbie',
                'subClusterSeller'             => 'In professionalization',
                'visitas'                      => 3000,
            ],
            [
                // Mês corrente em curso — GMV nulo (ainda não fechado)
                'timMonthId'                   => '202606',
                'tgmvLc'                       => null,
                'totalLivelistings'            => 310,
                'tgmvLcMe2'                    => null,
                'tgmvLcFlex'                   => null,
                'fTgmvLcPlaces'                => null,
                'repCurrentLevel'              => 'green',
                'repClaimsRate'                => '0',
                'repDisputesRate'              => '0',
                'repSellerCancellationsRate'   => '0',
                'repDelayedHtRate'             => '0',
                'mesesNoPrograma'              => 3,
                'clusterSeller'                => 'Newbie',
                'subClusterSeller'             => 'In professionalization',
                'visitas'                      => 2000,
            ],
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl));

        $response->assertOk();

        // Serie deve ter 3 itens ordenados ASC (202604 → 202605 → 202606)
        $serie = $response->json('serie');
        $this->assertCount(3, $serie, 'Serie deve ter 3 meses');
        $this->assertEquals('202604', $serie[0]['mes'], 'Primeiro item deve ser 202604 (ASC)');
        $this->assertEquals('202605', $serie[1]['mes'], 'Segundo item deve ser 202605');
        $this->assertEquals('202606', $serie[2]['mes'], 'Terceiro item deve ser 202606');

        // GMV do mês corrente (202606) deve ser null — NÃO convertido para 0
        $response->assertJsonPath('serie.2.gmv', null);

        // GMV dos meses anteriores preservado como número
        $this->assertNotNull($serie[0]['gmv'], 'GMV de 202604 não deve ser null');
        $this->assertNotNull($serie[1]['gmv'], 'GMV de 202605 não deve ser null');

        // Visitas presentes nos 3 meses
        $this->assertEquals(3000, $serie[0]['visitas']);
        $this->assertEquals(4500, $serie[1]['visitas']);
        $this->assertEquals(2000, $serie[2]['visitas']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Degradação gracioso — seller M0 (sem meses POLOS)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Seller M0 (data=[]) → resposta 200, sem erro 500; reputacao/maturidade null, serie [].
     * Verifica que campos ausentes degradam graciosamente sem quebrar o endpoint.
     */
    public function test_seller_m0_degrada(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        // Seller sem meses POLOS — data vazio
        $this->fakeEcf('570267839', []);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl));

        // Deve retornar 200 (não 500) — degradação graciosa
        $response->assertOk();
        $response->assertJsonPath('semCustId', false);
        $response->assertJsonPath('naoEncontrada', false);
        $response->assertJsonPath('erro', null);

        // Reputação e maturidade null quando não há meses com dados
        $response->assertJsonPath('reputacao', null);
        $response->assertJsonPath('maturidade', null);

        // Serie vazia quando não há meses
        $response->assertJsonPath('serie', []);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Novos blocos — Vendas, Tráfego, Contexto e Qualidade
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Vendas & Forecast: tsi (string→int) e fTgmvLc (string→float) mapeados.
     * Garante que a conversão de tipo ocorre e os valores chegam nos campos corretos.
     */
    public function test_vendas_e_forecast_mapeados(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        $this->fakeEcf('570267839', [[
            'timMonthId'                 => '202605',
            'tgmvLc'                     => 18000.00,
            'totalLivelistings'          => 250,
            'tgmvLcMe2'                  => 18000.00,
            'tgmvLcFlex'                 => 0,
            'fTgmvLcPlaces'              => 0,
            'repCurrentLevel'            => 'green',
            'repClaimsRate'              => '0',
            'repDisputesRate'            => '0',
            'repSellerCancellationsRate' => '0',
            'repDelayedHtRate'           => '0',
            'mesesNoPrograma'            => 3,
            'clusterSeller'              => 'Newbie',
            'subClusterSeller'           => 'In professionalization',
            // Campos novos — Vendas & Forecast
            'tsi'                        => '120',   // itens vendidos como string
            'fTgmvLc'                    => '22500.50', // GMV previsto como string
        ]]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl));

        $response->assertOk();
        // tsi convertido para int
        $response->assertJsonPath('vendas.itens', 120);
        // fTgmvLc convertido para float
        $response->assertJsonPath('vendas.forecastGmv', 22500.50);
    }

    /**
     * Tráfego: visitas e visitasClips presentes e mapeados para int.
     */
    public function test_trafego_mapeado(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        $this->fakeEcf('570267839', [[
            'timMonthId'                 => '202605',
            'tgmvLc'                     => 15000.00,
            'totalLivelistings'          => 200,
            'tgmvLcMe2'                  => 15000.00,
            'tgmvLcFlex'                 => 0,
            'fTgmvLcPlaces'              => 0,
            'repCurrentLevel'            => 'green',
            'repClaimsRate'              => '0',
            'repDisputesRate'            => '0',
            'repSellerCancellationsRate' => '0',
            'repDelayedHtRate'           => '0',
            'mesesNoPrograma'            => 2,
            'clusterSeller'              => 'Newbie',
            'subClusterSeller'           => null,
            // Campos novos — Tráfego
            'visitas'                    => 5800,
            'visitasClips'               => 320,
        ]]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl));

        $response->assertOk();
        $response->assertJsonPath('trafego.visitas', 5800);
        $response->assertJsonPath('trafego.clips', 320);
    }

    /**
     * Contexto: custState, subPolo e hL mapeados para estado/subPolo/atendimento.
     */
    public function test_contexto_mapeado(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        $this->fakeEcf('570267839', [[
            'timMonthId'                 => '202605',
            'tgmvLc'                     => 12000.00,
            'totalLivelistings'          => 180,
            'tgmvLcMe2'                  => 12000.00,
            'tgmvLcFlex'                 => 0,
            'fTgmvLcPlaces'              => 0,
            'repCurrentLevel'            => 'green',
            'repClaimsRate'              => '0',
            'repDisputesRate'            => '0',
            'repSellerCancellationsRate' => '0',
            'repDelayedHtRate'           => '0',
            'mesesNoPrograma'            => 1,
            'clusterSeller'              => 'Newbie',
            'subClusterSeller'           => null,
            // Campos novos — Contexto
            'custState'                  => 'BR-RS',
            'subPolo'                    => 'MOVEIS',
            'hL'                         => 'HIGH TOUCH',
        ]]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl));

        $response->assertOk();
        $response->assertJsonPath('contexto.estado', 'BR-RS');
        $response->assertJsonPath('contexto.subPolo', 'MOVEIS');
        $response->assertJsonPath('contexto.atendimento', 'HIGH TOUCH');
    }

    /**
     * Qualidade: newListers, itensFull e scoreQualidadeFinal mapeados.
     * Verifica conversão de tipo (int/float) e que os campos chegam nos nomes corretos.
     */
    public function test_qualidade_mapeada(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        $this->fakeEcf('570267839', [[
            'timMonthId'                 => '202605',
            'tgmvLc'                     => 25000.00,
            'totalLivelistings'          => 400,
            'tgmvLcMe2'                  => 25000.00,
            'tgmvLcFlex'                 => 500.00,
            'fTgmvLcPlaces'              => 0,
            'repCurrentLevel'            => 'green',
            'repClaimsRate'              => '0',
            'repDisputesRate'            => '0',
            'repSellerCancellationsRate' => '0',
            'repDelayedHtRate'           => '0',
            'mesesNoPrograma'            => 6,
            'clusterSeller'              => 'Seasonal',
            'subClusterSeller'           => 'Consolidation',
            // Campos novos — Qualidade
            'newListers'                 => 12,
            'itensFull'                  => 350,
            'scoreQualidadeFinal'        => 87.5,
        ]]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl));

        $response->assertOk();
        $response->assertJsonPath('qualidade.novosListadores', 12);
        $response->assertJsonPath('qualidade.itensFull', 350);
        $response->assertJsonPath('qualidade.scoreQualidade', 87.5);
    }

    /**
     * Qualidade nula (campos ausentes no ONBOARDING): todos os campos de qualidade
     * chegam null — o bloco existe no JSON mas com valores null.
     */
    public function test_qualidade_null_quando_ausente_onboarding(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        // Fixture sem newListers, itensFull, scoreQualidadeFinal (caso ONBOARDING típico)
        $this->fakeEcf('570267839', [[
            'timMonthId'                 => '202605',
            'tgmvLc'                     => 8000.00,
            'totalLivelistings'          => 50,
            'tgmvLcMe2'                  => 8000.00,
            'tgmvLcFlex'                 => 0,
            'fTgmvLcPlaces'              => 0,
            'repCurrentLevel'            => 'green',
            'repClaimsRate'              => '0',
            'repDisputesRate'            => '0',
            'repSellerCancellationsRate' => '0',
            'repDelayedHtRate'           => '0',
            'mesesNoPrograma'            => 1,
            'clusterSeller'              => 'Newbie',
            'subClusterSeller'           => null,
            // newListers, itensFull e scoreQualidadeFinal ausentes (ONBOARDING)
        ]]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl));

        $response->assertOk();
        // Bloco qualidade presente mas campos internos null (não 0 nem string vazia)
        $response->assertJsonPath('qualidade.novosListadores', null);
        $response->assertJsonPath('qualidade.itensFull', null);
        $response->assertJsonPath('qualidade.scoreQualidade', null);
    }

    /**
     * Novos blocos todos null quando seller M0 (sem meses no programa).
     * Garante degradação graciosa: vendas/trafego/contexto/qualidade = null.
     */
    public function test_novos_blocos_null_seller_m0(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        // Seller sem meses POLOS — data vazio
        $this->fakeEcf('570267839', []);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl));

        $response->assertOk();
        // Todos os novos blocos devem ser null quando não há meses ($ultimo = null)
        $response->assertJsonPath('vendas', null);
        $response->assertJsonPath('trafego', null);
        $response->assertJsonPath('contexto', null);
        $response->assertJsonPath('qualidade', null);
    }
}
