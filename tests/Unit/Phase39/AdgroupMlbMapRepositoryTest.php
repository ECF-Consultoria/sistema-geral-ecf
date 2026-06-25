<?php

// Phase 39 Plan 39-03 — Testes Unit do AdgroupMlbMapRepository.
// Cobre os 3 métodos do contrato (§decisions do 39-CONTEXT.md):
//   - getMlbsForAdgroup(int $companyId, string $adgroupId): array
//   - setMlbsForAdgroup(int $companyId, string $adgroupId, array $mlbIds, ?Carbon $lastSeenAt = null): void
//   - bulkSetFromProvider(int $companyId, array $adgroupMlbsMap): int
//
// Repository abstrai a tabela legada `adman_adgroup_mlbs` atrás de uma API neutra
// — Phase 43 vai renomear a tabela para `sugador_adgroup_mlbs`. companyId (int) é
// resolvido internamente para cust_id (string Adman) via accessor Company::cust_id.
// Usa SQLite em-memory (default phpunit.xml) + RefreshDatabase para CRUD real.

namespace Tests\Unit\Phase39;

use App\Models\Company;
use App\Repositories\AdgroupMlbMapRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdgroupMlbMapRepositoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cria Company persistida com adman_account_id (cust_id) controlado.
     * Helper minimalista — só os campos necessários para o accessor cust_id resolver.
     */
    private function makeCompanyWithCustId(string $custId = 'CUST123'): Company
    {
        return Company::create([
            'name'             => 'Empresa Teste ' . $custId,
            'cnpj'             => str_pad((string) crc32($custId), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => $custId,
            'active'           => true,
        ]);
    }

    /**
     * Insere row diretamente via DB::table (bypass do repository) para validar
     * comportamento de leitura sem depender de setMlbsForAdgroup ainda não testado.
     */
    private function insertRow(array $attrs): void
    {
        $now = now();
        DB::table('adman_adgroup_mlbs')->insert(array_merge([
            'period_from'    => $now->copy()->startOfDay()->toDateString(),
            'period_to'      => $now->copy()->startOfDay()->toDateString(),
            'last_synced_at' => $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $attrs));
    }

    // ─────────── Test 1: getMlbsForAdgroup retorna [] quando sem cache ───────────

    public function test_getMlbsForAdgroup_returns_empty_array_when_no_data_cached(): void
    {
        $company = $this->makeCompanyWithCustId('CUST_EMPTY');
        $repo    = new AdgroupMlbMapRepository();

        $this->assertSame([], $repo->getMlbsForAdgroup($company->id, 'ADGROUP_X'));
    }

    // ─────────── Test 2: getMlbsForAdgroup retorna MLBs ordenados ───────────

    public function test_getMlbsForAdgroup_returns_mlb_ids_from_cache(): void
    {
        $company = $this->makeCompanyWithCustId('CUST_READ');
        $this->insertRow(['cust_id' => 'CUST_READ', 'adgroup_id' => 'ADG1', 'mlb_id' => 'MLB003']);
        $this->insertRow(['cust_id' => 'CUST_READ', 'adgroup_id' => 'ADG1', 'mlb_id' => 'MLB001']);
        $this->insertRow(['cust_id' => 'CUST_READ', 'adgroup_id' => 'ADG1', 'mlb_id' => 'MLB002']);

        $repo   = new AdgroupMlbMapRepository();
        $result = $repo->getMlbsForAdgroup($company->id, 'ADG1');

        // Ordenação garantida por orderBy('mlb_id') no repository.
        $this->assertSame(['MLB001', 'MLB002', 'MLB003'], $result);
    }

    // ─────────── Test 3: resolve companyId → cust_id internamente ───────────

    public function test_getMlbsForAdgroup_resolves_company_id_to_cust_id_internally(): void
    {
        $company = $this->makeCompanyWithCustId('ABC123');
        $this->insertRow(['cust_id' => 'ABC123', 'adgroup_id' => 'ADG_RES', 'mlb_id' => 'MLB100']);
        $this->insertRow(['cust_id' => 'ABC123', 'adgroup_id' => 'ADG_RES', 'mlb_id' => 'MLB200']);

        $repo = new AdgroupMlbMapRepository();

        // Caller passa apenas companyId (int) — repository busca Company e usa cust_id.
        $result = $repo->getMlbsForAdgroup($company->id, 'ADG_RES');

        $this->assertSame(['MLB100', 'MLB200'], $result);
    }

    // ─────────── Test 4: empresa sem cust_id retorna [] sem query ───────────

    public function test_getMlbsForAdgroup_returns_empty_when_company_has_no_cust_id(): void
    {
        // Empresa sem adman_account_id nem ml_store_id → accessor cust_id retorna null.
        $company = Company::create([
            'name'   => 'Empresa sem integração',
            'cnpj'  => '99999999999999',
            'active' => true,
        ]);

        $repo = new AdgroupMlbMapRepository();

        $this->assertSame([], $repo->getMlbsForAdgroup($company->id, 'ADG_QUALQUER'));
    }

    // ─────────── Test 5: setMlbsForAdgroup insere com unique constraint ───────────

    public function test_setMlbsForAdgroup_inserts_rows_with_unique_constraint(): void
    {
        $company = $this->makeCompanyWithCustId('CUST_SET');
        $repo    = new AdgroupMlbMapRepository();

        $repo->setMlbsForAdgroup($company->id, 'ADG_SET', ['MLB_A', 'MLB_B', 'MLB_C']);

        $rows = DB::table('adman_adgroup_mlbs')
            ->where('cust_id', 'CUST_SET')
            ->where('adgroup_id', 'ADG_SET')
            ->orderBy('mlb_id')
            ->get();

        $this->assertCount(3, $rows);
        $this->assertSame('MLB_A', $rows[0]->mlb_id);
        $this->assertSame('MLB_B', $rows[1]->mlb_id);
        $this->assertSame('MLB_C', $rows[2]->mlb_id);
        // period_from/period_to default = hoje (snapshot do dia).
        $this->assertSame(now()->startOfDay()->toDateString(), (string) $rows[0]->period_from);
        $this->assertSame(now()->startOfDay()->toDateString(), (string) $rows[0]->period_to);
    }

    // ─────────── Test 6: setMlbsForAdgroup é idempotente (upsert) ───────────

    public function test_setMlbsForAdgroup_updates_existing_rows_on_conflict(): void
    {
        $company = $this->makeCompanyWithCustId('CUST_IDEMP');
        $repo    = new AdgroupMlbMapRepository();

        // 1ª chamada: insere 3 rows.
        $repo->setMlbsForAdgroup($company->id, 'ADG_IDEMP', ['MLB_X', 'MLB_Y', 'MLB_Z']);
        $countAfterFirst = DB::table('adman_adgroup_mlbs')
            ->where('cust_id', 'CUST_IDEMP')
            ->where('adgroup_id', 'ADG_IDEMP')
            ->count();
        $this->assertSame(3, $countAfterFirst);

        // 2ª chamada com mesmos params: upsert NÃO duplica (unique adgmlb_unique).
        $repo->setMlbsForAdgroup($company->id, 'ADG_IDEMP', ['MLB_X', 'MLB_Y', 'MLB_Z']);
        $countAfterSecond = DB::table('adman_adgroup_mlbs')
            ->where('cust_id', 'CUST_IDEMP')
            ->where('adgroup_id', 'ADG_IDEMP')
            ->count();
        $this->assertSame(3, $countAfterSecond);
    }

    // ─────────── Test 7: bulkSetFromProvider insere múltiplos adgroups ───────────

    public function test_bulkSetFromProvider_inserts_multiple_adgroups_in_one_call(): void
    {
        $company = $this->makeCompanyWithCustId('CUST_BULK');
        $repo    = new AdgroupMlbMapRepository();

        $map = [
            'adg1' => ['MLB1', 'MLB2'],
            'adg2' => ['MLB3'],
        ];
        $total = $repo->bulkSetFromProvider($company->id, $map);

        // Retorna número total de pares (adgroup, mlb) processados.
        $this->assertSame(3, $total);

        $rows = DB::table('adman_adgroup_mlbs')
            ->where('cust_id', 'CUST_BULK')
            ->orderBy('adgroup_id')
            ->orderBy('mlb_id')
            ->get();
        $this->assertCount(3, $rows);
        $this->assertSame('adg1', $rows[0]->adgroup_id);
        $this->assertSame('MLB1', $rows[0]->mlb_id);
        $this->assertSame('adg1', $rows[1]->adgroup_id);
        $this->assertSame('MLB2', $rows[1]->mlb_id);
        $this->assertSame('adg2', $rows[2]->adgroup_id);
        $this->assertSame('MLB3', $rows[2]->mlb_id);
    }

    // ─────────── Test 8: bulkSetFromProvider retorna 0 em map vazio ───────────

    public function test_bulkSetFromProvider_returns_zero_when_map_empty(): void
    {
        $company = $this->makeCompanyWithCustId('CUST_EMPTY_BULK');
        $repo    = new AdgroupMlbMapRepository();

        $total = $repo->bulkSetFromProvider($company->id, []);

        $this->assertSame(0, $total);
        // Confirma que nada foi inserido.
        $this->assertSame(0, DB::table('adman_adgroup_mlbs')->count());
    }
}
