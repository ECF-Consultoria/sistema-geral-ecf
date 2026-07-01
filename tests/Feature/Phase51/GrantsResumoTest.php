<?php

// Phase 51 — cobre EcfDriveService::grantsResumo/grantsDistribuicao (T1) e
// GrantController::index() com consumo remoto + fallback local + universo
// no_grant corrigido + buckets locais (T2).

namespace Tests\Feature\Phase51;

use App\Models\Company;
use App\Models\CompanyGrant;
use App\Models\MlToken;
use App\Models\User;
use App\Services\EcfDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Wave 2 (Plan 51-02):
 * - T1: EcfDriveService.grantsResumo + grantsDistribuicao (mirror carteiraResumo/breakdown).
 * - T2: GrantController.index remoto+fallback+SQL no_grant corrigido+buckets locais.
 *
 * Ambiente:
 * - Http::fake para /grants/resumo e /grants/distribuicao (pattern Phase 20).
 * - RefreshDatabase (SQLite in-memory; MariaDB local corrompida per memory).
 * - Cache::flush() no setUp para isolar TTL entre testes.
 */
class GrantsResumoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'fake-key',
        ]);
        Cache::flush();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private static int $cnpjSeq = 0;

    private function makeCompany(array $attrs = []): Company
    {
        self::$cnpjSeq++;
        $seq = str_pad((string) self::$cnpjSeq, 14, '0', STR_PAD_LEFT);
        return Company::create(array_merge([
            'name'             => 'Empresa ' . uniqid(),
            'cnpj'             => $seq,
            'adman_account_id' => null,
            'active'           => true,
        ], $attrs));
    }

    private function makeMlToken(int $companyId, string $status = 'active'): MlToken
    {
        return MlToken::create([
            'company_id'    => $companyId,
            'ml_user_id'    => 'user-' . $companyId,
            'access_token'  => 'access-' . $companyId,
            'refresh_token' => 'refresh-' . $companyId,
            'token_type'    => 'bearer',
            'expires_at'    => now()->addHour(),
            'status'        => $status,
            'connected_at'  => now(),
        ]);
    }

    private function makeGrant(int $companyId, ?string $expiresAt = null, string $status = 'active'): CompanyGrant
    {
        return CompanyGrant::create([
            'company_id' => $companyId,
            'ml_cust_id' => 'cust-' . $companyId,
            'segmento'   => 'Moda',
            'status'     => $status,
            'granted_at' => now()->subDays(30)->toDateString(),
            'expires_at' => $expiresAt,
        ]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'              => 'Admin 51-02 ' . uniqid(),
            'email'             => 'admin.5102.' . uniqid() . '@ecf.test',
            'password'          => bcrypt('senha'),
            'role'              => 'admin',
            'active'            => true,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    private function resumoPayload(array $overrides = []): array
    {
        // Shape real da API ECF Drive /grants/resumo (verificado em prod via
        // php artisan tinker → EcfDriveService::grantsResumo() 2026-07-01).
        return array_replace_recursive([
            'total'     => 396,
            'vigentes'  => 345,
            'expirados' => 51,
            'expirandoEm' => [
                '7d'  => 2,
                '15d' => 51,
                '30d' => 73,
                '60d' => 90,
                '90d' => 120,
            ],
            'fontes' => [
                'contatosCpp'    => ['descricao' => 'cliente_snapshots.grant_fim', 'total' => 396, 'vigentes' => 345, 'expirados' => 51],
                'baseVendedores' => ['descricao' => 'seller_medalhas.fecha_out',   'total' => 1122, 'vigentes' => 419, 'expirados' => 703],
            ],
        ], $overrides);
    }

    // ═══ Tarefa 1: EcfDriveService ═══════════════════════════════════════════

    public function test_grants_resumo_chama_endpoint_e_retorna_payload(): void
    {
        $payload = $this->resumoPayload();
        Http::fake([
            '*/grants/resumo' => Http::response($payload, 200),
        ]);

        $result = app(EcfDriveService::class)->grantsResumo();

        $this->assertSame($payload, $result);
        Http::assertSentCount(1);
    }

    public function test_grants_resumo_cache_evita_chamada_duplicada(): void
    {
        Http::fake([
            '*/grants/resumo' => Http::response($this->resumoPayload(), 200),
        ]);

        $service = app(EcfDriveService::class);
        $service->grantsResumo();
        $service->grantsResumo();

        Http::assertSentCount(1);
    }

    public function test_grants_distribuicao_programa_chama_endpoint_com_query_param(): void
    {
        Http::fake([
            '*/grants/distribuicao*' => Http::response(['data' => []], 200),
        ]);

        app(EcfDriveService::class)->grantsDistribuicao('programa');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/grants/distribuicao')
                && str_contains($request->url(), 'dimensao=programa');
        });
    }

    public function test_grants_distribuicao_dimensao_invalida_lanca_excecao_sem_http(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        try {
            app(EcfDriveService::class)->grantsDistribuicao('localidade');
        } finally {
            Http::assertSentCount(0);
        }
    }

    public function test_grants_resumo_propaga_runtime_exception_em_erro_http(): void
    {
        Http::fake([
            '*/grants/resumo' => Http::response(['erro' => 'oops'], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        app(EcfDriveService::class)->grantsResumo();
    }

    // ═══ Tarefa 2: GrantController::index() ══════════════════════════════════

    public function test_index_usa_payload_remoto_quando_api_ok(): void
    {
        $this->actingAsAdmin();
        Http::fake([
            '*/grants/resumo' => Http::response($this->resumoPayload([
                'expirandoEm' => ['7d' => 5, '15d' => 10, '30d' => 20, '60d' => 30, '90d' => 50],
            ]), 200),
        ]);

        $response = $this->get(route('grants.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Grants/Index')
            ->where('stats.source', 'remote')
            ->where('stats.total_grants_ml', 396)
            ->where('stats.vigentes_ml', 345)
            ->where('stats.expirando_30d', 20)
            ->where('stats.expirados_ml', 51)
        );
    }

    public function test_index_fallback_local_quando_api_falha(): void
    {
        $this->actingAsAdmin();
        Log::spy();
        Http::fake([
            '*/grants/resumo' => Http::response(['erro' => 'offline'], 500),
        ]);

        // 2 grants expirando dentro de 30d
        $c1 = $this->makeCompany();
        $c2 = $this->makeCompany();
        $this->makeGrant($c1->id, now()->addDays(3)->toDateString());
        $this->makeGrant($c2->id, now()->addDays(20)->toDateString());

        $response = $this->get(route('grants.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stats.source', 'local')
            ->where('stats.expirando_30d', 2)
            ->where('stats.divergencia_ml', null)
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg) => str_contains($msg, '[Grants]') && str_contains($msg, 'offline'))
            ->once();
    }

    public function test_index_universo_no_grant_inclui_company_com_cust_id_e_sem_grant_ativo(): void
    {
        $this->actingAsAdmin();
        Http::fake(['*/grants/resumo' => Http::response($this->resumoPayload(), 200)]);

        // NOTA: Company::cust_id é ACCESSOR (adman_account_id ?: ml_store_id) —
        // testes precisam setar as colunas físicas, não o accessor.

        // A: com cust_id (via adman_account_id), sem grant → ENTRA
        $this->makeCompany(['adman_account_id' => 'CUST-A', 'name' => 'Empresa A']);
        // B: com cust_id, com grant ativo → NÃO entra
        $b = $this->makeCompany(['adman_account_id' => 'CUST-B', 'name' => 'Empresa B']);
        $this->makeGrant($b->id, now()->addDays(60)->toDateString(), 'active');
        // C: sem cust_id, sem ml_token → NÃO entra (não onboardada)
        $this->makeCompany(['adman_account_id' => null, 'name' => 'Empresa C']);

        $response = $this->get(route('grants.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stats.no_grant', 1)
        );
    }

    public function test_index_universo_no_grant_inclui_company_com_ml_token_ativo_sem_grant(): void
    {
        $this->actingAsAdmin();
        Http::fake(['*/grants/resumo' => Http::response($this->resumoPayload(), 200)]);

        // D: sem cust_id (nem adman_account_id nem ml_store_id), com ml_token ativo, sem grant → ENTRA
        $d = $this->makeCompany(['adman_account_id' => null, 'ml_store_id' => null, 'name' => 'Empresa D']);
        $this->makeMlToken($d->id, 'active');
        // E: sem cust_id, com ml_token EXPIRED, sem grant → NÃO entra
        $e = $this->makeCompany(['adman_account_id' => null, 'ml_store_id' => null, 'name' => 'Empresa E']);
        $this->makeMlToken($e->id, 'expired');
        // F: com cust_id, com ml_token ativo, com grant ativo → NÃO entra
        $f = $this->makeCompany(['adman_account_id' => 'CUST-F', 'name' => 'Empresa F']);
        $this->makeMlToken($f->id, 'active');
        $this->makeGrant($f->id, now()->addDays(60)->toDateString(), 'active');

        $response = $this->get(route('grants.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stats.no_grant', 1)
        );
    }

    public function test_index_divergencia_ml_propagada_quando_remoto_ok(): void
    {
        // Divergência ML = fontes.baseVendedores.total (1122) − fontes.contatosCpp.total (396) = 726
        $this->actingAsAdmin();
        Http::fake([
            '*/grants/resumo' => Http::response($this->resumoPayload(), 200),
        ]);

        $response = $this->get(route('grants.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stats.divergencia_ml', 726)
            ->where('stats.source', 'remote')
        );
    }
}
