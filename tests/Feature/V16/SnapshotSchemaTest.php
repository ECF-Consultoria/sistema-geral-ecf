<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GATE de schema da Phase 79 Plan 01 (DEC-79-C).
 *
 * Prova a fundação de persistência do snapshot congelado do NPS multi-modelo:
 *  - as 3 tabelas de snapshot (`nps_response_scores`, `nps_response_covered_services`,
 *    `nps_score_assignments`) existem e migram no SQLite in-memory (mesma base dos
 *    testes V16) — garantindo compatibilidade cross-driver com o MySQL de produção;
 *  - cada tabela tem exatamente as colunas de DEC-79-C;
 *  - o encadeamento por FK funciona e apagar a `nps_responses` raiz remove em
 *    cascata os scores, os serviços cobertos e as atribuições (cascadeOnDelete).
 *
 * RED→GREEN: escrito ANTES da migration (Tarefa 2). Falha hoje (tabelas
 * inexistentes) e passa após a migration + models. Usa `DB::table` puro nas
 * inserções (não há factories para estas tabelas de snapshot append-only).
 */
class SnapshotSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /**
     * As 3 tabelas de snapshot existem após o migrate (cross-driver — SQLite aqui).
     */
    public function test_as_tres_tabelas_de_snapshot_existem(): void
    {
        $this->assertTrue(Schema::hasTable('nps_response_scores'), 'nps_response_scores deveria existir');
        $this->assertTrue(Schema::hasTable('nps_response_covered_services'), 'nps_response_covered_services deveria existir');
        $this->assertTrue(Schema::hasTable('nps_score_assignments'), 'nps_score_assignments deveria existir');
    }

    /**
     * Cada tabela tem exatamente as colunas de DEC-79-C.
     */
    public function test_colunas_de_cada_tabela_conforme_dec_79_c(): void
    {
        // nps_response_scores — médias congeladas por dimensão.
        $this->assertTrue(Schema::hasColumns('nps_response_scores', [
            'nps_response_id',
            'company_id',
            'dimensao',
            'score_sum',
            'question_count',
            'average_score',
            'calculated_at',
        ]), 'nps_response_scores sem as colunas de DEC-79-C');

        // nps_response_covered_services — serviços cobertos no momento da resposta.
        $this->assertTrue(Schema::hasColumns('nps_response_covered_services', [
            'nps_response_id',
            'servico_id',
            'service_setor',
            'captured_at',
        ]), 'nps_response_covered_services sem as colunas de DEC-79-C');

        // nps_score_assignments — atribuição congelada média×user×role×serviço.
        $this->assertTrue(Schema::hasColumns('nps_score_assignments', [
            'nps_response_id',
            'nps_response_score_id',
            'company_id',
            'servico_id',
            'service_setor',
            'role',
            'user_id',
            'average_score',
            'assigned_at',
        ]), 'nps_score_assignments sem as colunas de DEC-79-C');
    }

    /**
     * Encadeamento por FK + cascade: apagar a `nps_responses` raiz remove em
     * cascata as linhas filhas das 3 tabelas de snapshot.
     */
    public function test_apagar_nps_responses_zera_as_tres_filhas_em_cascata(): void
    {
        // ─── Cenário mínimo: company + user + serviço performance ───
        $company  = Company::factory()->create();
        $gerador  = User::factory()->create();
        $analista = User::factory()->create();
        $servicoId = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        // Survey → response (a raiz do cascade).
        $surveyId = DB::table('nps_surveys')->insertGetId([
            'token'        => (string) Str::uuid(),
            'company_id'   => $company->id,
            'generated_by' => $gerador->id,
            'status'       => 'completed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $responseId = DB::table('nps_responses')->insertGetId([
            'survey_id'          => $surveyId,
            'respondent_name'    => 'Cliente Teste',
            'score_estrategista' => 5,
            'score_analista'     => 4,
            'score_empresa'      => 5,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // score (dimensão analista) — depende de nps_responses + companies.
        $scoreId = DB::table('nps_response_scores')->insertGetId([
            'nps_response_id' => $responseId,
            'company_id'      => $company->id,
            'dimensao'        => 'analista',
            'score_sum'       => 8.00,
            'question_count'  => 2,
            'average_score'   => 4.00,
            'calculated_at'   => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // serviço coberto — depende de nps_responses + servicos.
        DB::table('nps_response_covered_services')->insert([
            'nps_response_id' => $responseId,
            'servico_id'      => $servicoId,
            'service_setor'   => Servico::SETOR_PERFORMANCE,
            'captured_at'     => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // atribuição congelada — depende de nps_responses + nps_response_scores + user + servico.
        // `role` guarda o valor da pivot company_users ('consultor' = Analista).
        DB::table('nps_score_assignments')->insert([
            'nps_response_id'       => $responseId,
            'nps_response_score_id' => $scoreId,
            'company_id'            => $company->id,
            'servico_id'            => $servicoId,
            'service_setor'         => Servico::SETOR_PERFORMANCE,
            'role'                  => 'consultor',
            'user_id'               => $analista->id,
            'average_score'         => 4.00,
            'assigned_at'           => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        // Sanidade: as 3 filhas existem antes do delete.
        $this->assertSame(1, DB::table('nps_response_scores')->count());
        $this->assertSame(1, DB::table('nps_response_covered_services')->count());
        $this->assertSame(1, DB::table('nps_score_assignments')->count());

        // Apaga a raiz — cascade deve zerar TODAS as filhas.
        DB::table('nps_responses')->where('id', $responseId)->delete();

        $this->assertSame(0, DB::table('nps_response_scores')->count(), 'scores deveriam sumir no cascade');
        $this->assertSame(0, DB::table('nps_response_covered_services')->count(), 'covered_services deveriam sumir no cascade');
        $this->assertSame(0, DB::table('nps_score_assignments')->count(), 'assignments deveriam sumir no cascade');
    }
}
