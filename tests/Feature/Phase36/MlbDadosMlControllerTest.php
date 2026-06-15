<?php

namespace Tests\Feature\Phase36;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Suíte de testes para o endpoint async dadosMl() (Phase 36 Plan 01).
 *
 * Cobre: ONB-12 (consumo dos 3 endpoints por cust_id),
 *        ONB-13 (shape do JSON de retorno),
 *        ONB-14 (resiliência: offline / 404 / sem cust_id).
 *
 * @group phase36
 */
class MlbDadosMlControllerTest extends TestCase
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
     * Consultor sem publication_role e sem publication_permissions.
     * Deve receber 403 ao chamar dados-ml.
     */
    private function criarConsultorSemPublicacao(): User
    {
        return User::factory()->create([
            'role'                    => 'consultor',
            'publication_role'        => null,
            'publication_permissions' => null,
            'email_verified_at'       => now(),
        ]);
    }

    /**
     * Cria MlbEmpresa POLO/POLOS + MlbImplementacao com token e dados padrão.
     * Aceita override de colunas de empresa (incluindo cust_id) via $empresaOpts.
     */
    private function criarEmpresaComImpl(array $empresaOpts = [], array $implOpts = []): array
    {
        $empresa = MlbEmpresa::create(array_merge([
            'nome'       => 'Empresa Teste ' . Str::random(4),
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
     * Monta Http::fake na ordem correta para o endpoint dados-ml.
     *
     * ORDEM IMPORTA: subpaths mais específicos ANTES do geral (metricas/mensal e
     * medalhas antes do snapshot /sellers/{custId}). Sempre inclui mock de /signals
     * por causa do HandleInertiaRequests que chama listSignals() em todo request
     * Inertia — Pitfall 4 do RESEARCH.
     *
     * @param string $custId    Cust ID do seller
     * @param array  $metricas  Array de métricas para o campo 'data' da resposta mensal
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

            // /signals global — HandleInertiaRequests badge sidebar (Pitfall 4)
            '*/signals*' => Http::response(['data' => [], 'total' => 0], 200),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Testes
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * ONB-12: admin com empresa com cust_id válido recebe 200 com dados do seller.
     */
    public function test_admin_retorna_dados_ml(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        $this->fakeEcf('570267839', [[
            'timMonthId'         => '202605',
            'tgmvLc'             => 18500.00,
            'totalLivelistings'  => 347,
            'tgmvLcMe2'          => 15200.00,
            'tgmvLcFlex'         => 0,
            'fTgmvLcPlaces'      => 3300.00,
        ]]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk();
    }

    /**
     * ONB-13: shape da resposta JSON — medalha, anunciosAtivos, gmvPolos, canais, mesRef.
     */
    public function test_shape_resposta(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        $this->fakeEcf('570267839', [[
            'timMonthId'         => '202605',
            'tgmvLc'             => 18500.00,
            'totalLivelistings'  => 347,
            'tgmvLcMe2'          => 15200.00,
            'tgmvLcFlex'         => 0,
            'fTgmvLcPlaces'      => 3300.00,
        ]]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk();
        $response->assertJsonPath('medalha', 'GOLD');
        $response->assertJsonPath('programa', 'POLOS');
        $response->assertJsonPath('anunciosAtivos', 347);
        // gmvPolos: JSON serializa floats inteiros como int (18500.0 → 18500).
        // Usar assertJson para comparação flexível de tipo numérico.
        $response->assertJson(['gmvPolos' => 18500]);
        $response->assertJsonPath('canais.me2.ativo', true);
        $response->assertJsonPath('canais.flex.ativo', false);
        $response->assertJsonPath('canais.places.ativo', true);
        $response->assertJsonPath('mesRef', '202605');
    }

    /**
     * ONB-14: empresa sem cust_id retorna semCustId=true sem chamar o ECF Drive.
     */
    public function test_sem_cust_id(): void
    {
        $admin = $this->criarAdmin();
        // cust_id=null — coluna direta na mlb_empresas
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => null]);

        // NÃO configurar Http::fake — o teste verifica que não houve chamada HTTP
        Http::fake([
            '*/signals*' => Http::response(['data' => [], 'total' => 0], 200),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk();
        $response->assertJsonPath('semCustId', true);

        // Verifica que nenhuma chamada ao ECF Drive foi feita
        Http::assertNothingSent();
    }

    /**
     * ONB-14: ECF Drive offline retorna {erro: string} com HTTP 200 — não quebra nada.
     */
    public function test_ecf_drive_offline(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        Http::fake([
            '*/sellers*' => Http::response('', 500),
            '*/signals*' => Http::response(['data' => [], 'total' => 0], 200),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        // Deve retornar 200 (não 500) com campo erro preenchido e sem stacktrace
        $response->assertOk();
        $response->assertJsonPath('semCustId', false);
        $response->assertJsonPath('naoEncontrada', false);

        $erro = $response->json('erro');
        $this->assertNotNull($erro, 'Campo erro deve ser string não-nula quando ECF Drive offline');
        // Confirma que não vaza mensagem de exceção interna ao cliente
        $this->assertStringNotContainsString('Throwable', $erro);
        $this->assertStringNotContainsString('Exception', $erro);
        $this->assertStringNotContainsString('Stack trace', $erro);
    }

    /**
     * ONB-14: cust_id retorna 404 no ECF Drive → naoEncontrada=true.
     */
    public function test_nao_encontrada_404(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        Http::fake([
            '*/sellers*' => Http::response('', 404),
            '*/signals*' => Http::response(['data' => [], 'total' => 0], 200),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('mlb.implementacao.dados-ml', $impl), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk();
        $response->assertJsonPath('naoEncontrada', true);
        $response->assertJsonPath('semCustId', false);
        $response->assertJsonPath('erro', null);
    }

    /**
     * T-36-01 (STRIDE): consultor sem publication_role recebe 403 em GET dados-ml.
     */
    public function test_sem_permissao_403(): void
    {
        $consultor = $this->criarConsultorSemPublicacao();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['cust_id' => '570267839']);

        $response = $this->actingAs($consultor)
            ->getJson(route('mlb.implementacao.dados-ml', $impl), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertForbidden();
    }
}
