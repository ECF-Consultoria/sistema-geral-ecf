<?php

// Phase 40 Plan 40-01 — Suite RED → GREEN do schema das tabelas auxiliares de shadow mode.
//
// Esta suite valida:
//  - A migration cria `sugador_provider_runs` e `sugador_provider_items` com as colunas
//    EXATAS do §Implementation Decisions do 40-CONTEXT.md.
//  - Índices compostos `idx_company_ref_provider` e `idx_run_tipo` estão presentes
//    (consultados via sqlite_master, já que o driver de teste é SQLite em-memory).
//  - Foreign keys com cascadeOnDelete funcionam: deletar Company → apaga runs; deletar
//    Run → apaga items.
//  - Models Eloquent `SugadorProviderRun` e `SugadorProviderItem` declaram casts
//    corretos (arrays JSON, immutable_datetime) e relações hasMany/belongsTo.
//
// Phase 40 NÃO modifica `sugadores`, `sugador_configs` ou `sugador_acoes` — apenas adiciona
// as 2 tabelas auxiliares para o shadow mode (rodar Adman+ML em paralelo sem afetar prod).

namespace Tests\Feature\Phase40;

use App\Models\Company;
use App\Models\SugadorProviderItem;
use App\Models\SugadorProviderRun;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateSugadorProviderTablesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cria empresa mínima válida para os testes de FK.
     * Não usa factory (Company não tem factory definida — só HasFactory trait).
     */
    private function makeCompany(array $attrs = []): Company
    {
        return Company::create(array_merge([
            'name'             => 'Empresa Teste Phase 40',
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'ACC-40-' . random_int(1000, 9999),
            'active'           => true,
        ], $attrs));
    }

    /**
     * Cria um SugadorProviderRun preenchido com defaults razoáveis.
     */
    private function makeRun(Company $company, array $attrs = []): SugadorProviderRun
    {
        return SugadorProviderRun::create(array_merge([
            'company_id'     => $company->id,
            'provider'       => 'adman',
            'reference_date' => Carbon::today(),
            'periodo_inicio' => Carbon::today()->subDays(7),
            'periodo_fim'    => Carbon::today()->subDay(),
            'status'         => 'running',
            'started_at'     => now(),
        ], $attrs));
    }

    #[Test]
    public function schema_sugador_provider_runs_tem_colunas_corretas(): void
    {
        $this->assertTrue(
            Schema::hasTable('sugador_provider_runs'),
            'Tabela sugador_provider_runs deveria existir após a migration.'
        );

        $this->assertTrue(
            Schema::hasColumns('sugador_provider_runs', [
                'id', 'company_id', 'provider', 'reference_date',
                'periodo_inicio', 'periodo_fim', 'status',
                'started_at', 'finished_at', 'error', 'summary',
                'created_at', 'updated_at',
            ]),
            'sugador_provider_runs não tem todas as colunas esperadas do §decisions.'
        );
    }

    #[Test]
    public function schema_sugador_provider_items_tem_colunas_corretas(): void
    {
        $this->assertTrue(
            Schema::hasTable('sugador_provider_items'),
            'Tabela sugador_provider_items deveria existir após a migration.'
        );

        $this->assertTrue(
            Schema::hasColumns('sugador_provider_items', [
                'id', 'run_id', 'tipo', 'campaign_id', 'adgroup_id',
                'mlb_id', 'motivos', 'metrics_json', 'raw_hash',
                'created_at', 'updated_at',
            ]),
            'sugador_provider_items não tem todas as colunas esperadas do §decisions.'
        );
    }

    #[Test]
    public function indice_composto_runs_company_ref_provider_existe(): void
    {
        // SQLite em-memory: sqlite_master tem type='index' para índices nomeados.
        $rows = DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?",
            ['idx_company_ref_provider']
        );

        $this->assertCount(
            1,
            $rows,
            'Índice composto idx_company_ref_provider deveria estar presente em sugador_provider_runs.'
        );
    }

    #[Test]
    public function indice_composto_items_run_tipo_existe(): void
    {
        $rows = DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?",
            ['idx_run_tipo']
        );

        $this->assertCount(
            1,
            $rows,
            'Índice composto idx_run_tipo deveria estar presente em sugador_provider_items.'
        );
    }

    #[Test]
    public function foreign_key_company_id_cascade_on_delete(): void
    {
        // SQLite por padrão não força FKs sem PRAGMA. Garantir comportamento de cascade.
        DB::statement('PRAGMA foreign_keys = ON');

        $company = $this->makeCompany();
        $run     = $this->makeRun($company);

        $this->assertDatabaseHas('sugador_provider_runs', ['id' => $run->id]);

        $company->delete();

        $this->assertDatabaseMissing(
            'sugador_provider_runs',
            ['company_id' => $company->id],
            connection: null
        );
    }

    #[Test]
    public function foreign_key_run_id_cascade_on_delete(): void
    {
        DB::statement('PRAGMA foreign_keys = ON');

        $company = $this->makeCompany();
        $run     = $this->makeRun($company);

        SugadorProviderItem::create([
            'run_id'       => $run->id,
            'tipo'         => 'campanha',
            'campaign_id'  => 'C-1',
            'motivos'      => ['cpc_alto'],
            'metrics_json' => ['investment' => 100.5],
            'raw_hash'     => str_repeat('a', 64),
        ]);
        SugadorProviderItem::create([
            'run_id'       => $run->id,
            'tipo'         => 'adgroup',
            'campaign_id'  => 'C-1',
            'adgroup_id'   => 'AG-1',
            'mlb_id'       => 'MLB123',
            'motivos'      => ['sem_conversao'],
            'metrics_json' => ['investment' => 50.0],
            'raw_hash'     => str_repeat('b', 64),
        ]);

        $this->assertDatabaseCount('sugador_provider_items', 2);

        $run->delete();

        $this->assertDatabaseCount('sugador_provider_items', 0);
    }

    #[Test]
    public function model_sugador_provider_run_casts_e_relacao(): void
    {
        $company = $this->makeCompany();

        $run = SugadorProviderRun::create([
            'company_id'     => $company->id,
            'provider'       => 'ml',
            'reference_date' => Carbon::today(),
            'periodo_inicio' => Carbon::today()->subDays(7),
            'periodo_fim'    => Carbon::today()->subDay(),
            'status'         => 'completed',
            'started_at'     => now(),
            'finished_at'    => now(),
            'summary'        => ['campanhas_detectadas' => 3, 'adgroups_detectados' => 7],
        ]);

        $run->refresh();

        // Casts JSON e datetime
        $this->assertIsArray($run->summary, 'summary deveria estar cast como array.');
        $this->assertEquals(3, $run->summary['campanhas_detectadas']);
        $this->assertInstanceOf(
            CarbonInterface::class,
            $run->started_at,
            'started_at deveria estar cast como Carbon (immutable_datetime).'
        );
        $this->assertInstanceOf(CarbonInterface::class, $run->finished_at);

        // Relação hasMany items()
        $item = SugadorProviderItem::create([
            'run_id'       => $run->id,
            'tipo'         => 'campanha',
            'campaign_id'  => 'C-99',
            'motivos'      => ['cpc_alto'],
            'metrics_json' => ['investment' => 200.0],
            'raw_hash'     => str_repeat('c', 64),
        ]);

        $this->assertEquals($item->id, $run->items()->first()->id);
        $this->assertEquals($company->id, $run->company->id);
    }

    #[Test]
    public function model_sugador_provider_item_casts_e_relacao(): void
    {
        $company = $this->makeCompany();
        $run     = $this->makeRun($company);

        $item = SugadorProviderItem::create([
            'run_id'       => $run->id,
            'tipo'         => 'adgroup',
            'campaign_id'  => 'C-1',
            'adgroup_id'   => 'AG-42',
            'mlb_id'       => 'MLB987654321',
            'motivos'      => ['cpc_alto', 'sem_conversao'],
            'metrics_json' => ['investment' => 100.5, 'revenue' => 0.0, 'acos' => null],
            'raw_hash'     => str_repeat('d', 64),
        ]);

        $item->refresh();

        $this->assertIsArray($item->motivos, 'motivos deveria estar cast como array.');
        $this->assertEquals(['cpc_alto', 'sem_conversao'], $item->motivos);

        $this->assertIsArray($item->metrics_json, 'metrics_json deveria estar cast como array.');
        $this->assertEquals(100.5, $item->metrics_json['investment']);
        $this->assertNull($item->metrics_json['acos']);

        // Relação belongsTo run()
        $this->assertEquals($run->id, $item->run->id);
        $this->assertEquals('adman', $item->run->provider);
    }
}
