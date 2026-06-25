<?php

namespace Tests\Feature\Phase41;

use App\Models\Company;
use App\Models\MlAdvertiser;
use App\Models\SugadorMlCompanyConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suite Feature — Schema da Phase 41 (RED).
 *
 * Valida:
 *   - Schema exato das 2 tabelas (ml_advertisers + sugador_ml_company_config)
 *   - FK cascade funciona ao deletar Company
 *   - Casts dos 2 Models novos (raw_data array, discovered_at immutable, booleans, dates)
 *   - Relacoes em Company (mlAdvertiser + sugadorMlConfig)
 *   - Unique constraint em company_id em ambas tabelas
 *
 * Pattern PHPUnit 11: usa atributo Test (PHPUnit 11 deprecou doc-comment @test).
 * SQLite em-memory + ativacao explicita de foreign_keys ON em setUp
 * (SQLite default nao forca FKs; pattern do Plan 40-01 herdado).
 */
class Phase41SchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // SQLite desliga FKs por default; precisamos ligar pra cobrir cascade.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Helper privado para criar Company minima para os testes.
     * NAO precisa adman_account_id — testes de schema sao isolados.
     */
    private function makeCompany(): Company
    {
        return Company::create([
            'name'   => 'Empresa Teste Phase41',
            'cnpj'   => '12345678000199',
            'active' => true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 1 — schema da tabela ml_advertisers
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function tabela_ml_advertisers_tem_colunas_esperadas(): void
    {
        $this->assertTrue(
            Schema::hasTable('ml_advertisers'),
            'Tabela ml_advertisers deveria existir'
        );

        $this->assertTrue(
            Schema::hasColumns('ml_advertisers', [
                'id',
                'company_id',
                'advertiser_id',
                'seller_id',
                'site_id',
                'raw_data',
                'discovered_at',
                'created_at',
                'updated_at',
            ]),
            'Tabela ml_advertisers deve ter as 9 colunas esperadas (§1 do CONTEXT.md)'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 2 — schema da tabela sugador_ml_company_config
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function tabela_sugador_ml_company_config_tem_colunas_esperadas(): void
    {
        $this->assertTrue(
            Schema::hasTable('sugador_ml_company_config'),
            'Tabela sugador_ml_company_config deveria existir'
        );

        $this->assertTrue(
            Schema::hasColumns('sugador_ml_company_config', [
                'id',
                'company_id',
                'shadow_enabled',
                'primary_enabled',
                'shadow_started_at',
                'primary_promoted_at',
                'notes',
                'created_at',
                'updated_at',
            ]),
            'Tabela sugador_ml_company_config deve ter as 9 colunas esperadas (§2 do CONTEXT.md)'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 3 — FK cascade em ml_advertisers
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function fk_company_id_cascade_em_ml_advertisers(): void
    {
        $company = $this->makeCompany();

        MlAdvertiser::create([
            'company_id'    => $company->id,
            'advertiser_id' => 'ADV-123',
            'seller_id'     => 'SEL-456',
            'site_id'       => 'MLB',
            'raw_data'      => ['x' => 1],
            'discovered_at' => now(),
        ]);

        $this->assertSame(1, MlAdvertiser::count());

        // Deletar Company deve apagar o MlAdvertiser via cascade.
        $company->delete();

        $this->assertSame(
            0,
            MlAdvertiser::count(),
            'FK cascade deveria apagar MlAdvertiser ao deletar Company'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 4 — FK cascade em sugador_ml_company_config
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function fk_company_id_cascade_em_sugador_ml_company_config(): void
    {
        $company = $this->makeCompany();

        SugadorMlCompanyConfig::create([
            'company_id'      => $company->id,
            'shadow_enabled'  => true,
            'primary_enabled' => false,
        ]);

        $this->assertSame(1, SugadorMlCompanyConfig::count());

        $company->delete();

        $this->assertSame(
            0,
            SugadorMlCompanyConfig::count(),
            'FK cascade deveria apagar SugadorMlCompanyConfig ao deletar Company'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 5 — Model MlAdvertiser: casts + relacao
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function model_ml_advertiser_casts_e_relacoes(): void
    {
        $company = $this->makeCompany();

        $payload = ['advertiser_name' => 'Loja X', 'tier' => 'gold'];

        $adv = MlAdvertiser::create([
            'company_id'    => $company->id,
            'advertiser_id' => 'ADV-999',
            'seller_id'     => 'SEL-888',
            'site_id'       => 'MLB',
            'raw_data'      => $payload,
            'discovered_at' => now(),
        ]);

        $fresh = MlAdvertiser::find($adv->id);

        // Cast raw_data => array
        $this->assertIsArray($fresh->raw_data);
        $this->assertSame($payload, $fresh->raw_data);

        // Cast discovered_at => immutable_datetime
        $this->assertInstanceOf(CarbonImmutable::class, $fresh->discovered_at);

        // Relacao company() BelongsTo
        $this->assertInstanceOf(Company::class, $fresh->company);
        $this->assertSame($company->id, $fresh->company->id);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 6 — Model SugadorMlCompanyConfig: casts + relacao
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function model_sugador_ml_company_config_casts_e_relacoes(): void
    {
        $company = $this->makeCompany();

        // Insere boolean via int 1 / 0 (formato SQLite raw) para validar o cast.
        $cfg = SugadorMlCompanyConfig::create([
            'company_id'          => $company->id,
            'shadow_enabled'      => 1,
            'primary_enabled'     => 0,
            'shadow_started_at'   => '2026-06-25',
            'primary_promoted_at' => null,
            'notes'               => 'Empresa piloto Bymobille',
        ]);

        $fresh = SugadorMlCompanyConfig::find($cfg->id);

        // Casts boolean
        $this->assertTrue($fresh->shadow_enabled, 'shadow_enabled deve ser bool true');
        $this->assertFalse($fresh->primary_enabled, 'primary_enabled deve ser bool false');

        // Cast date
        $this->assertNotNull($fresh->shadow_started_at);
        $this->assertSame('2026-06-25', $fresh->shadow_started_at->format('Y-m-d'));
        $this->assertNull($fresh->primary_promoted_at);

        // String passa direto
        $this->assertSame('Empresa piloto Bymobille', $fresh->notes);

        // Relacao company()
        $this->assertInstanceOf(Company::class, $fresh->company);
        $this->assertSame($company->id, $fresh->company->id);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 7 — relacoes novas em Company
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function company_relacoes_novas(): void
    {
        $company = $this->makeCompany();

        MlAdvertiser::create([
            'company_id'    => $company->id,
            'advertiser_id' => 'ADV-1',
            'site_id'       => 'MLB',
            'discovered_at' => now(),
        ]);

        SugadorMlCompanyConfig::create([
            'company_id'     => $company->id,
            'shadow_enabled' => true,
        ]);

        $reloaded = Company::find($company->id);

        $this->assertInstanceOf(MlAdvertiser::class, $reloaded->mlAdvertiser);
        $this->assertInstanceOf(SugadorMlCompanyConfig::class, $reloaded->sugadorMlConfig);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 8 — unique constraint em company_id (ambas tabelas)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function company_id_unique_em_ml_advertisers(): void
    {
        $company = $this->makeCompany();

        // ml_advertisers: 2 registros para mesma company -> deve quebrar unique.
        MlAdvertiser::create([
            'company_id'    => $company->id,
            'advertiser_id' => 'ADV-A',
            'site_id'       => 'MLB',
            'discovered_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        MlAdvertiser::create([
            'company_id'    => $company->id,
            'advertiser_id' => 'ADV-B',
            'site_id'       => 'MLB',
            'discovered_at' => now(),
        ]);
    }

    #[Test]
    public function company_id_unique_em_sugador_ml_company_config(): void
    {
        $company = $this->makeCompany();

        SugadorMlCompanyConfig::create([
            'company_id'     => $company->id,
            'shadow_enabled' => true,
        ]);

        $this->expectException(QueryException::class);

        SugadorMlCompanyConfig::create([
            'company_id'     => $company->id,
            'shadow_enabled' => false,
        ]);
    }
}
