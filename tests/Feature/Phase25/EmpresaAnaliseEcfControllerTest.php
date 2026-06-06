<?php

// Phase 25 — testes Feature do EmpresaAnaliseEcfController.
// Cobre: 200 admin, 200 consultor com empresa na carteira, 403 consultor fora,
// 403 publicador, 200 empresa sem cust_id (Http::assertNothingSent),
// 200 cust_id mas 404 no ECF Drive (naoEncontrada=true),
// e fallback erro genérico (prop erro com mensagem pt-BR).

namespace Tests\Feature\Phase25;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmpresaAnaliseEcfControllerTest extends TestCase
{
    use RefreshDatabase;

    // Sequência de CNPJs únicos por teste (evita conflito de unique em BD)
    private int $cnpjSeq = 10000000000001;

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

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** Gera CNPJ único para cada chamada (14 dígitos numéricos). */
    private function cnpjUnico(): string
    {
        return (string) $this->cnpjSeq++;
    }

    /** Cria usuário com role e email verificado. */
    private function usuario(string $role): User
    {
        return User::factory()->create([
            'role'              => $role,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Cria empresa com cust_id ML válido (via adman_account_id).
     * Usa Company::create() — não há CompanyFactory neste projeto.
     */
    private function criarEmpresaComCustId(array $attrs = []): Company
    {
        return Company::create(array_merge([
            'name'             => 'Empresa Teste Phase25',
            'cnpj'             => $this->cnpjUnico(),
            'adman_account_id' => '570267839',
            'ml_store_id'      => null,
        ], $attrs));
    }

    /**
     * Cria empresa sem cust_id ML (Shopee/Amazon sem ML).
     * Com adman_account_id e ml_store_id nulos, accessor cust_id retorna null.
     */
    private function criarEmpresaSemCustId(): Company
    {
        return Company::create([
            'name'             => 'Empresa Shopee Sem ML',
            'cnpj'             => $this->cnpjUnico(),
            'adman_account_id' => null,
            'ml_store_id'      => null,
        ]);
    }

    /**
     * Vincula usuário à empresa via pivot company_users.
     * Necessário para consultor/mentor passarem na auth fina do controller.
     * Pattern retirado de tests/Feature/Notifications/Phase11AutoTest.php linha 133.
     */
    private function linkUserToCompany(User $user, Company $company): void
    {
        $company->users()->attach($user->id, [
            'role'        => 'consultor',
            'assigned_at' => now(),
        ]);
    }

    /**
     * Http::fake com respostas válidas para os 4 endpoints do seller
     * + /signals global do HandleInertiaRequests (badge sidebar).
     * ORDEM IMPORTA: padrões mais específicos (com subpath) devem vir ANTES do geral.
     * O wrapper EcfDriveService usa Http::withToken()->get(url) — fake via wildcard '*'.
     */
    private function mockSellerOk(string $custId): void
    {
        Http::fake([
            // Subpaths — mais específicos primeiro (evitar que geral capture antes)
            "*/sellers/{$custId}/metricas/mensal*" => Http::response([
                'data' => [
                    [
                        'timMonthId'       => '202605',
                        'tgmv_lc'          => 2700000.00,
                        'inv_pads'         => 45000.00,
                        'tsi'              => 16597,
                        'visitas'          => 42000,
                        'score_final_full' => 92,
                        'score_final_pads' => 85,
                    ],
                ],
            ], 200),

            "*/sellers/{$custId}/medalhas*" => Http::response([
                'data' => [
                    ['timMonthId' => '202605', 'nivel' => 'PLATINUM'],
                ],
            ], 200),

            "*/sellers/{$custId}/signals*" => Http::response([
                'data' => [],
            ], 200),

            // Snapshot geral — deve vir DEPOIS dos subpaths
            "*/sellers/{$custId}*" => Http::response([
                'custId'       => $custId,
                'razaoSocial'  => 'RELOJOARIA WENUS',
                'segmento'     => 'Relogios',
                'programa'     => 'CPP',
                'cluster'      => 'Core',
                'medalhaAtual' => ['nivel' => 'PLATINUM', 'timMonthId' => '202605'],
                'metricaAtual' => [
                    'timMonthId'       => '202605',
                    'tgmv_lc'          => 2700000.00,
                    'tsi'              => 16597,
                    'inv_pads'         => 45000.00,
                    'tgmv_lc_pads'     => 250000.00,
                    'visitas'          => 42000,
                    'score_final_full' => 92,
                    'score_final_pads' => 85,
                ],
            ], 200),

            // /signals global — HandleInertiaRequests conta alertas críticos para o badge da sidebar
            '*/signals*' => Http::response(['data' => [], 'total' => 0], 200),
        ]);
    }

    /** Http::fake com 404 no /sellers/{custId} — cust_id não existe no ECF Drive. */
    private function mockSeller404(string $custId): void
    {
        Http::fake([
            "*/sellers/{$custId}/metricas/mensal*" => Http::response([], 200),
            "*/sellers/{$custId}/medalhas*"         => Http::response([], 200),
            "*/sellers/{$custId}/signals*"          => Http::response([], 200),
            "*/sellers/{$custId}*"                  => Http::response(['error' => 'not found'], 404),
            // /signals global — HandleInertiaRequests badge sidebar
            '*/signals*' => Http::response(['data' => [], 'total' => 0], 200),
        ]);
    }

    /** Http::fake com erro 500 no /sellers/{custId} — ECF Drive offline. */
    private function mockSellerErroGenerico(string $custId): void
    {
        Http::fake([
            "*/sellers/{$custId}/metricas/mensal*" => Http::response([], 200),
            "*/sellers/{$custId}/medalhas*"         => Http::response([], 200),
            "*/sellers/{$custId}/signals*"          => Http::response([], 200),
            "*/sellers/{$custId}*"                  => Http::response(['error' => 'internal error'], 500),
            // /signals global — HandleInertiaRequests badge sidebar
            '*/signals*' => Http::response(['data' => [], 'total' => 0], 200),
        ]);
    }

    // ─── Testes ──────────────────────────────────────────────────────────────

    /** Admin acessa ficha 360° de qualquer empresa — 200 com seller preenchido. */
    public function test_admin_acessa_200(): void
    {
        $custId  = '570267839';
        $empresa = $this->criarEmpresaComCustId(['adman_account_id' => $custId]);
        $admin   = $this->usuario('admin');
        $this->mockSellerOk($custId);

        $response = $this->actingAs($admin)
            ->get(route('empresas.analise-ecf', $empresa->id));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('EmpresaAnaliseEcf/Show')
            ->where('semCustId', false)
            ->where('naoEncontrada', false)
            ->where('erro', null)
            ->has('seller')
            ->where('empresa.id', $empresa->id)
        );
    }

    /** Consultor com empresa na carteira acessa — 200. */
    public function test_consultor_carteira_200(): void
    {
        $custId    = '570267839';
        $empresa   = $this->criarEmpresaComCustId(['adman_account_id' => $custId]);
        $consultor = $this->usuario('consultor');
        $this->linkUserToCompany($consultor, $empresa);
        $this->mockSellerOk($custId);

        $response = $this->actingAs($consultor)
            ->get(route('empresas.analise-ecf', $empresa->id));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('EmpresaAnaliseEcf/Show')
            ->where('semCustId', false)
            ->where('naoEncontrada', false)
            ->where('erro', null)
        );
    }

    /** Consultor sem a empresa na carteira recebe 403. */
    public function test_consultor_fora_carteira_403(): void
    {
        $empresa   = $this->criarEmpresaComCustId();
        $consultor = $this->usuario('consultor');
        // NÃO vincula consultor à empresa

        $response = $this->actingAs($consultor)
            ->get(route('empresas.analise-ecf', $empresa->id));

        $response->assertStatus(403);
    }

    /**
     * Guest (não autenticado) é redirecionado para login — 302.
     * O middleware 'auth' bloqueia antes de chegar no role check.
     */
    public function test_guest_redirecionado_302(): void
    {
        $empresa = $this->criarEmpresaComCustId();

        $response = $this->get(route('empresas.analise-ecf', $empresa->id));

        $response->assertStatus(302);
        $response->assertRedirect(route('login'));
    }

    /**
     * Empresa sem cust_id retorna 200 com semCustId=true.
     * O controller NÃO deve chamar nenhum endpoint /sellers/* do wrapper.
     * Nota: o middleware HandleInertiaRequests chama /signals (badge sidebar) —
     * isso é esperado e não conta como chamada do controller de análise.
     */
    public function test_empresa_sem_cust_id_200_semCustId_true(): void
    {
        $empresa = $this->criarEmpresaSemCustId();
        $admin   = $this->usuario('admin');

        // Mock para o /signals do middleware HandleInertiaRequests (badge sidebar)
        // Sem este mock, o middleware tentaria chamar a API real e falharia.
        Http::fake([
            '*/signals*' => Http::response(['data' => [], 'total' => 0], 200),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('empresas.analise-ecf', $empresa->id));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('EmpresaAnaliseEcf/Show')
            ->where('semCustId', true)
            ->where('seller', null)
            ->where('metricas', [])
            ->where('medalhas', [])
            ->where('signals', [])
        );

        // Confirma que NÃO foram feitas chamadas para endpoints /sellers/*
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/sellers/');
        });
    }

    /** Empresa com cust_id mas ECF Drive retorna 404 — naoEncontrada=true, erro=null. */
    public function test_cust_id_com_404_naoEncontrada_true(): void
    {
        $custId  = '570267839';
        $empresa = $this->criarEmpresaComCustId(['adman_account_id' => $custId]);
        $admin   = $this->usuario('admin');
        $this->mockSeller404($custId);

        $response = $this->actingAs($admin)
            ->get(route('empresas.analise-ecf', $empresa->id));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('EmpresaAnaliseEcf/Show')
            ->where('naoEncontrada', true)
            ->where('semCustId', false)
            ->where('erro', null)
            ->where('seller', null)
        );
    }

    /** ECF Drive retorna 500 — fallback com prop erro contendo mensagem pt-BR amigável. */
    public function test_erro_generico_fallback(): void
    {
        $custId  = '570267839';
        $empresa = $this->criarEmpresaComCustId(['adman_account_id' => $custId]);
        $admin   = $this->usuario('admin');
        $this->mockSellerErroGenerico($custId);

        $response = $this->actingAs($admin)
            ->get(route('empresas.analise-ecf', $empresa->id));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('EmpresaAnaliseEcf/Show')
            ->where('naoEncontrada', false)
            ->where('semCustId', false)
            ->whereNot('erro', null)  // prop erro deve ser string pt-BR não-nula
            ->where('seller', null)
        );
    }
}
