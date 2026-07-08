<?php

namespace Tests\Feature\Phase68;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 68 Plan 05 — Suite Feature de backward-compat pos-migration.
 *
 * Valida SC #5 do ROADMAP: dashboards existentes NAO quebram apos as 5
 * migrations da Phase 68 rodarem. Prova observavel de:
 *  - Rota `/nps` (NpsController@index) continua renderizando 200
 *  - Rota `/companies/{id}` continua expondo payload nps_surveys legado
 *  - Query legada AVG(score_estrategista) sobre rows historicas continua funcional
 *  - Colunas score_* nullable no schema NAO zeram rows legadas ja populadas
 *  - Novas rows podem gravar scores NULL (fluxo Phase 69)
 *
 * @see .planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/68-05-PLAN.md
 * @see app/Http/Controllers/NpsController.php@index
 * @see app/Http/Controllers/CompanyController.php@show
 */
class NpsBackwardCompatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cria admin autenticado — passa por todas as gates (role:admin,
     * userCanViewCompany, isAdmin(), etc). email_verified_at do factory
     * ja atende o middleware 'verified'.
     */
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create([
            'role'              => 'admin',
            'active'            => true,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    // ═══════════════════════════════════════════════════════════════════
    // SC #5 parte 1 — rotas legadas renderizam
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_rota_nps_index_renderiza_sem_erro_apos_migrations(): void
    {
        // Fase 68 NAO altera NpsController@index. Dashboard legado deve
        // continuar carregando os cards 3 dimensoes + serie 12 meses das
        // rows historicas + rows novas (rows novas com score_* NULL sao
        // ignoradas naturalmente pelo AVG SQL).
        $this->actingAsAdmin();

        $response = $this->get(route('nps.index'));

        $response->assertOk();
        // Sanity Inertia — payload esperado do controller (cards + serie_12m).
        $response->assertInertia(fn ($page) => $page
            ->component('Nps/Index')
            ->has('cards')
            ->has('serie_12m')
            ->has('surveys')
        );
    }

    #[Test]
    public function test_rota_companies_show_expoe_nps_surveys_com_scores_legados(): void
    {
        // Fase 68 NAO altera CompanyController@show — payload nps_surveys
        // continua expondo response.score_estrategista/analista/empresa.
        // Cria empresa "limpa" (sem adman_account_id, sem ml_store_id) pra
        // evitar side-effects de servicos externos (Adman/ECF Drive) durante
        // o smoke test.
        $admin = $this->actingAsAdmin();

        $company = Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => null,
        ]);

        // Survey completed + response com scores legados populados —
        // representa row historica pre-Phase-69.
        $survey = NpsSurvey::factory()->create([
            'company_id'   => $company->id,
            'status'       => 'completed',
            'completed_at' => now(),
        ]);
        NpsResponse::factory()
            ->for($survey, 'survey')
            ->legacyScores(4, 5, 4)
            ->create(['respondent_name' => 'Cliente Teste']);

        $response = $this->get(route('companies.show', $company->id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Companies/Show')
            ->has('company.nps_surveys', 1)
            ->where('company.nps_surveys.0.response.score_estrategista', 4)
            ->where('company.nps_surveys.0.response.score_analista',     5)
            ->where('company.nps_surveys.0.response.score_empresa',      4)
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // SC #5 parte 2 — Queries legadas continuam funcionais
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_avg_score_estrategista_query_legada_ainda_retorna_numero(): void
    {
        // Simula 3 rows historicas com scores populados — mesma query bruta
        // do NpsController@index cards (linha 153): AVG(score_estrategista).
        // Se a migration 100003 quebrasse o storage numerico, a query falharia.
        $company = Company::factory()->create();

        $survey1 = NpsSurvey::factory()->create(['company_id' => $company->id]);
        $survey2 = NpsSurvey::factory()->create(['company_id' => $company->id]);
        $survey3 = NpsSurvey::factory()->create(['company_id' => $company->id]);

        NpsResponse::factory()->for($survey1, 'survey')->legacyScores(3, 3, 3)->create();
        NpsResponse::factory()->for($survey2, 'survey')->legacyScores(4, 4, 4)->create();
        NpsResponse::factory()->for($survey3, 'survey')->legacyScores(5, 5, 5)->create();

        // Query bruta legada — DB::table pra bypassar qualquer scope Eloquent.
        $avg = DB::table('nps_responses')
            ->whereNotNull('score_estrategista')
            ->avg('score_estrategista');

        $this->assertIsNumeric($avg, 'AVG deve retornar valor numerico');
        $this->assertEquals(4.0, (float) $avg, 'media dos scores 3,4,5 deve ser 4.0');

        // Bonus: query via Eloquent (mesmo padrao do controller).
        $avgEloquent = NpsResponse::whereNotNull('score_estrategista')->avg('score_estrategista');
        $this->assertEquals(4.0, (float) $avgEloquent);
    }

    #[Test]
    public function test_row_legada_com_score_populated_nao_e_zerada_pela_migration(): void
    {
        // Cria row com score_estrategista=4 (via factory state legacyScores).
        // Migration 100003 alter score_* NULLABLE via ->change() NAO deve
        // afetar valores existentes. Rows historicas mantem seus valores.
        $response = NpsResponse::factory()->legacyScores(4, null, 5)->create();

        // Fresh do DB pra garantir que estamos lendo do storage, nao do cache do factory.
        $recarregado = NpsResponse::find($response->id);

        $this->assertEquals(4, $recarregado->score_estrategista, 'score_estrategista=4 deve permanecer intacto');
        $this->assertNull($recarregado->score_analista,        'score_analista pode ser NULL (ja era nullable Phase 31)');
        $this->assertEquals(5, $recarregado->score_empresa,    'score_empresa=5 deve permanecer intacto');
    }

    #[Test]
    public function test_novo_response_pode_gravar_scores_null_sem_erro(): void
    {
        // Fluxo Phase 69: novas responses gravam score_* NULL e persistem
        // resposta detalhada em nps_response_answers. Antes da migration
        // 100003, gravar NULL em score_estrategista/empresa dispararia
        // constraint NOT NULL — agora deve passar sem erro.
        $response = NpsResponse::factory()->create();
        // Factory default ja seta scores=null (Plan 68-02 mudou o factory
        // pra refletir o schema v15.0). Recarrega pra confirmar storage.
        $fresh = NpsResponse::find($response->id);

        $this->assertNull($fresh->score_estrategista);
        $this->assertNull($fresh->score_analista);
        $this->assertNull($fresh->score_empresa);

        // Explicit assert em DB (sem cast do Model) — coluna aceita NULL.
        $this->assertDatabaseHas('nps_responses', [
            'id'                 => $response->id,
            'score_estrategista' => null,
            'score_empresa'      => null,
        ]);
    }
}
