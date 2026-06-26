<?php

namespace Tests\Feature\Phase42;

use App\Models\Company;
use App\Models\SugadorConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 42 Plan 42-01 — Suite Feature (RED) para o campo `cpc_minimo_cliques`.
 *
 * Cobre o requisito D-01 do CONTEXT (Opcao B do briefing §8): adicionar
 * campo opcional `cpc_minimo_cliques` em `sugador_configs` para que a regra
 * `cpc_alto` possa exigir volume minimo de cliques antes de marcar hit.
 *
 * Valida:
 *   - Schema: coluna `cpc_minimo_cliques` existe em `sugador_configs`
 *   - Fillable: mass-assign do campo persiste como inteiro
 *   - Cast `integer`: round-trip preserva null e int (NAO transforma null em 0)
 *   - DEFAULTS: chave presente com valor null (opt-in, nao afeta empresas existentes)
 *
 * Pattern PHPUnit 11: atributo Test (doc-comment @test foi deprecado).
 * SQLite em-memory + RefreshDatabase aplica migrations.
 */
class CpcMinimoCliquesSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cria Company minima para os testes que precisam de uma row em sugador_configs.
     * NAO usa factory pois o projeto so tem UserFactory; Phase41SchemaTest segue o
     * mesmo padrao de Company::create direto.
     */
    private function makeCompany(): Company
    {
        return Company::create([
            'name'   => 'Empresa Teste Phase42 ' . random_int(1, 999999),
            'cnpj'   => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'active' => true,
        ]);
    }

    /**
     * Helper: cria SugadorConfig usando DEFAULTS + overrides. Reutilizado em T2/T3.
     */
    private function makeConfig(array $overrides = []): SugadorConfig
    {
        $company = $this->makeCompany();

        return SugadorConfig::firstOrCreate(
            ['company_id' => $company->id],
            array_merge(SugadorConfig::DEFAULTS, $overrides)
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T1 — Schema tem a coluna cpc_minimo_cliques
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function schema_tem_coluna_cpc_minimo_cliques(): void
    {
        $this->assertTrue(
            Schema::hasColumn('sugador_configs', 'cpc_minimo_cliques'),
            'Coluna `cpc_minimo_cliques` deveria existir em sugador_configs (D-01 / Opcao B).'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T2 — Fillable inclui cpc_minimo_cliques (mass-assign + persistencia)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function fillable_inclui_cpc_minimo_cliques(): void
    {
        $cfg = $this->makeConfig(['cpc_minimo_cliques' => 5]);

        $fresh = SugadorConfig::find($cfg->id);

        $this->assertSame(
            5,
            $fresh->cpc_minimo_cliques,
            'Mass-assign deve persistir cpc_minimo_cliques=5 como int (nao string).'
        );
        $this->assertIsInt(
            $fresh->cpc_minimo_cliques,
            'Cast deve transformar valor em int, nao string.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T3 — Cast integer preserva null (nao vira 0 nem false)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function cast_integer_preserva_null(): void
    {
        // Cria config SEM o campo cpc_minimo_cliques — default deve ser null.
        $cfg = $this->makeConfig(); // usa DEFAULTS que tem cpc_minimo_cliques => null

        $fresh = SugadorConfig::find($cfg->id);

        $this->assertNull(
            $fresh->cpc_minimo_cliques,
            'cpc_minimo_cliques nao informado deve permanecer null (NAO 0, NAO false).'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T4 — DEFAULTS expoe a chave com valor null
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function defaults_inclui_chave_com_valor_null(): void
    {
        $this->assertArrayHasKey(
            'cpc_minimo_cliques',
            SugadorConfig::DEFAULTS,
            'SugadorConfig::DEFAULTS deve expor a chave cpc_minimo_cliques (nao apenas undefined).'
        );

        $this->assertNull(
            SugadorConfig::DEFAULTS['cpc_minimo_cliques'],
            'Default de cpc_minimo_cliques deve ser null (opt-in, D-01).'
        );
    }
}
