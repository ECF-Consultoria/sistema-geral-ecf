<?php

// Phase 20 — testes do comando grants:sync-ecf (dry-run, apply, match, fallback CNPJ, orfahs).

namespace Tests\Feature\Phase20;

use App\Models\Company;
use App\Models\CompanyGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Testa o comando SyncGrantsFromEcfDrive em todos os cenários relevantes.
 *
 * Usa Http::fake para simular a API ECF Drive e RefreshDatabase para
 * isolar cada teste. Company criada via create() direto (sem factory).
 */
class SyncGrantsFromEcfDriveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'fake-key',
        ]);
        // Limpa arquivo de órfãos entre testes para isolamento
        @unlink(storage_path('logs/grants-orfaos.log'));
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function fakeGrants(array $grants): void
    {
        Http::fake([
            '*/clientes/grants*' => Http::response(
                ['data' => $grants, 'total' => count($grants)],
                200
            ),
        ]);
    }

    private function grantPayload(array $overrides = []): array
    {
        return array_merge([
            'custId'          => '999',
            'razaoSocial'     => 'Loja Teste',
            'cnpj'            => '11.222.333/0001-44',
            'email'           => 'teste@x.com',
            'telefone'        => '11999999999',
            'segmento'        => 'Moda',
            'grantInicio'     => now()->subDays(10)->toDateString(),
            'grantFim'        => now()->addDays(30)->toDateString(),
            'diasParaExpirar' => 30,
            'expirado'        => false,
        ], $overrides);
    }

    private static int $cnpjSeq = 0;

    private function makeCompany(array $attrs = []): Company
    {
        self::$cnpjSeq++;
        $seq = str_pad((string) self::$cnpjSeq, 14, '0', STR_PAD_LEFT);
        return Company::create(array_merge([
            'name'             => 'Empresa Teste ' . uniqid(),
            'cnpj'             => $seq, // CNPJ único por sequência para evitar violação UNIQUE
            'adman_account_id' => null,
            'active'           => true,
        ], $attrs));
    }

    // ─── Testes ────────────────────────────────────────────────────────────

    public function test_dry_run_nao_persiste_em_company_grants(): void
    {
        $this->makeCompany(['adman_account_id' => '999']);
        $this->fakeGrants([$this->grantPayload()]);

        Artisan::call('grants:sync-ecf', ['--dry-run' => true]);

        $this->assertSame(0, CompanyGrant::count());
    }

    public function test_apply_faz_upsert_e_persiste_segmento(): void
    {
        $c = $this->makeCompany(['adman_account_id' => '999']);
        $this->fakeGrants([$this->grantPayload(['segmento' => 'Eletrônicos'])]);

        Artisan::call('grants:sync-ecf');

        $g = CompanyGrant::where('company_id', $c->id)->first();
        $this->assertNotNull($g);
        $this->assertSame('Eletrônicos', $g->segmento);
        $this->assertSame('active', $g->status);
        $this->assertSame('999', $g->ml_cust_id);
    }

    public function test_match_por_cust_id_quando_adman_account_id_bate(): void
    {
        $c1 = $this->makeCompany(['adman_account_id' => '111']);
        $c2 = $this->makeCompany(['adman_account_id' => '222']);
        $this->fakeGrants([$this->grantPayload(['custId' => '111'])]);

        Artisan::call('grants:sync-ecf');

        $this->assertSame(1, CompanyGrant::where('company_id', $c1->id)->count());
        $this->assertSame(0, CompanyGrant::where('company_id', $c2->id)->count());
    }

    /**
     * Plano 02 (2026-06-05): match secundário por ml_store_id.
     * Algumas companies têm o cust_id ML em ml_store_id (cadastro Comercial)
     * em vez de adman_account_id (planilha Adman). Ambos representam o
     * mesmo cust_id ML — fonte autoritativa do ECF Drive.
     */
    public function test_match_por_ml_store_id_quando_adman_account_id_diferente(): void
    {
        $c = $this->makeCompany([
            'adman_account_id' => 'OUTRO',
            'ml_store_id'      => '999',
        ]);
        $this->fakeGrants([$this->grantPayload(['custId' => '999'])]);

        Artisan::call('grants:sync-ecf');

        $this->assertSame(1, CompanyGrant::where('company_id', $c->id)->count());
        $this->assertSame('999', CompanyGrant::where('company_id', $c->id)->first()->ml_cust_id);
    }

    /**
     * Plano 02 (2026-06-05): match ESTRITO — fallback CNPJ foi removido para
     * evitar associar grants de alunos de cursos (mesma pessoa física pode
     * casar com CNPJ de empresa cliente). Grant cujo cust_id não bate vai
     * pra órfão MESMO se o CNPJ casa com alguma company.
     */
    public function test_match_estrito_cnpj_only_nao_associa_a_company(): void
    {
        $c = $this->makeCompany([
            'adman_account_id' => 'COMPANY_CUST',
            'cnpj'             => '12.345.678/0001-90',
        ]);
        // Grant vem com cust_id diferente mas CNPJ que casa — não deve associar
        $this->fakeGrants([$this->grantPayload([
            'custId' => 'GRANT_CUST_DIFERENTE',
            'cnpj'   => '12345678000190',
        ])]);

        Artisan::call('grants:sync-ecf');

        $this->assertSame(0, CompanyGrant::where('company_id', $c->id)->count());
        $this->assertSame(0, CompanyGrant::count(), 'Grant deve ir para órfão, não criar registro.');
    }

    /**
     * Plano 02 (2026-06-05): trim defensivo no cust_id da API
     * — espaços improváveis mas custo zero de cobrir.
     */
    public function test_match_aplica_trim_no_cust_id(): void
    {
        $c = $this->makeCompany(['adman_account_id' => '12345']);
        $this->fakeGrants([$this->grantPayload(['custId' => '  12345  '])]);

        Artisan::call('grants:sync-ecf');

        $this->assertSame(1, CompanyGrant::where('company_id', $c->id)->count());
    }

    public function test_orfaos_logam_em_arquivo(): void
    {
        // Nenhuma Company seedada — grant deve virar órfão
        $this->fakeGrants([$this->grantPayload(['custId' => 'fantasma', 'cnpj' => '00.000.000/0000-00'])]);

        Artisan::call('grants:sync-ecf');

        $this->assertSame(0, CompanyGrant::count());
        $logPath = storage_path('logs/grants-orfaos.log');
        $this->assertFileExists($logPath);
        $this->assertStringContainsString('ÓRFÃO custId=fantasma', file_get_contents($logPath));
    }

    public function test_apply_atualiza_quando_grant_ja_existe(): void
    {
        $c = $this->makeCompany(['adman_account_id' => '999']);
        CompanyGrant::create([
            'company_id' => $c->id,
            'ml_cust_id' => '999',
            'segmento'   => 'Antigo',
            'status'     => 'active',
        ]);
        $this->fakeGrants([$this->grantPayload(['segmento' => 'Novo'])]);

        Artisan::call('grants:sync-ecf');

        $this->assertSame(1, CompanyGrant::count());
        $this->assertSame('Novo', CompanyGrant::first()->segmento);
    }

    public function test_status_expired_quando_api_retorna_expirado_true(): void
    {
        $this->makeCompany(['adman_account_id' => '999']);
        $this->fakeGrants([$this->grantPayload(['expirado' => true])]);

        Artisan::call('grants:sync-ecf');

        $this->assertSame('expired', CompanyGrant::first()->status);
    }

    public function test_status_pending_quando_grantInicio_futuro(): void
    {
        $this->makeCompany(['adman_account_id' => '999']);
        $this->fakeGrants([$this->grantPayload([
            'grantInicio' => now()->addDays(10)->toDateString(),
            'expirado'    => false,
        ])]);

        Artisan::call('grants:sync-ecf');

        $this->assertSame('pending', CompanyGrant::first()->status);
    }
}
