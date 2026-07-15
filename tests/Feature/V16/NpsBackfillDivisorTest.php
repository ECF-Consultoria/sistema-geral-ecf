<?php

namespace Tests\Feature\V16;

use App\Models\NpsResponse;
use App\Models\NpsResponseScore;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick task 260715-kam — cobertura do backfill `nps:backfill-divisor-texto-livre`.
 *
 * Cobre idempotência, dry-run, propagação pra `nps_score_assignments`, e os
 * dois desvios que o comando PULA em vez de adivinhar (divergente, sem_base).
 *
 * @see app/Console/Commands/NpsBackfillDivisorTextoLivre.php
 * @see .planning/quick/260715-kam-nps-divisor-texto-livre/260715-kam-PLAN.md
 */
class NpsBackfillDivisorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Monta o cenário buggado canônico (caso real LUCCMAX): template com 5
     * perguntas escala + 1 texto_livre na dimensão empresa; score congelado
     * com o divisor ANTIGO (question_count=6, average_score=25/6=4.1666...).
     *
     * @return array{score: NpsResponseScore, response: NpsResponse, survey: NpsSurvey}
     */
    private function criarCenarioBugado(): array
    {
        $template = NpsTemplate::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            NpsTemplateQuestion::factory()->create([
                'template_id' => $template->id,
                'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'ordem'       => $i,
            ]);
        }
        NpsTemplateQuestion::factory()->create([
            'template_id' => $template->id,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'tipo'        => NpsTemplateQuestion::TIPO_TEXTO_LIVRE,
            'ordem'       => 6,
        ]);

        $survey   = NpsSurvey::factory()->create(['template_id' => $template->id]);
        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        // question_count=6 e average_score=25/6 replicam EXATAMENTE o que o
        // NpsSnapshotService gravava ANTES do fix de Task 2 (bug do divisor).
        $score = NpsResponseScore::create([
            'nps_response_id' => $response->id,
            'company_id'      => $survey->company_id,
            'dimensao'        => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'score_sum'       => 25.0,
            'question_count'  => 6,
            'average_score'   => 25 / 6,
            'calculated_at'   => now(),
        ]);

        return ['score' => $score, 'response' => $response, 'survey' => $survey];
    }

    // ═══════════════════════════════════════════════════════════════════
    // Dry-run não grava
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dry_run_nao_grava(): void
    {
        $score = $this->criarCenarioBugado()['score'];

        $exitCode = Artisan::call('nps:backfill-divisor-texto-livre', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);

        $score->refresh();
        $this->assertSame(6, $score->question_count);
        $this->assertEqualsWithDelta(25 / 6, $score->average_score, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════════
    // --force corrige qc e média
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_force_corrige_question_count_e_average_score(): void
    {
        $score = $this->criarCenarioBugado()['score'];

        $exitCode = Artisan::call('nps:backfill-divisor-texto-livre', ['--force' => true]);

        $this->assertSame(0, $exitCode);

        $score->refresh();
        $this->assertSame(5, $score->question_count);
        $this->assertEqualsWithDelta(5.0, $score->average_score, 0.001);

        // score_sum PRESERVADO intacto — o bug é só do denominador.
        $this->assertEqualsWithDelta(25.0, $score->score_sum, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Idempotência: 2 execuções seguidas = mesmo resultado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_idempotencia_duas_execucoes_seguidas_mesmo_resultado(): void
    {
        $score = $this->criarCenarioBugado()['score'];

        Artisan::call('nps:backfill-divisor-texto-livre', ['--force' => true]);
        $score->refresh();
        $qcAposPrimeira    = $score->question_count;
        $mediaAposPrimeira = $score->average_score;

        Artisan::call('nps:backfill-divisor-texto-livre', ['--force' => true]);
        $saidaSegunda = Artisan::output();
        $score->refresh();

        // Mesmo resultado — a 2a execução não re-divide o score_sum.
        $this->assertSame($qcAposPrimeira, $score->question_count);
        $this->assertEqualsWithDelta($mediaAposPrimeira, $score->average_score, 0.001);
        $this->assertSame(5, $score->question_count);
        $this->assertEqualsWithDelta(5.0, $score->average_score, 0.001);

        // A 2a execução classifica a linha como ja_corrigido (estado no-op).
        $this->assertStringContainsString('ja_corrigido', $saidaSegunda);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Propagação para nps_score_assignments
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_propaga_average_score_corrigido_para_assignments(): void
    {
        $cenario  = $this->criarCenarioBugado();
        $score    = $cenario['score'];
        $response = $cenario['response'];

        $user = User::factory()->create();

        $assignment = NpsScoreAssignment::create([
            'nps_response_id'       => $response->id,
            'nps_response_score_id' => $score->id,
            'company_id'            => $score->company_id,
            'servico_id'            => null,
            'service_setor'         => 'performance',
            'role'                  => 'consultor',
            'user_id'               => $user->id,
            // Congelado com o average_score BUGADO no momento do submit original.
            'average_score'         => $score->average_score,
            'assigned_at'           => now(),
        ]);

        Artisan::call('nps:backfill-divisor-texto-livre', ['--force' => true]);

        $assignment->refresh();
        // average_score do assignment vai de 4.1666... (4.17) para 5.00.
        $this->assertEqualsWithDelta(5.0, $assignment->average_score, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════════
    // DIVERGENTE: question_count não bate com nada conhecido → PULA intacto
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_divergente_e_pulado_e_permanece_intacto(): void
    {
        $template = NpsTemplate::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            NpsTemplateQuestion::factory()->create([
                'template_id' => $template->id,
                'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'ordem'       => $i,
            ]);
        }
        NpsTemplateQuestion::factory()->create([
            'template_id' => $template->id,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'tipo'        => NpsTemplateQuestion::TIPO_TEXTO_LIVRE,
            'ordem'       => 6,
        ]);

        $survey   = NpsSurvey::factory()->create(['template_id' => $template->id]);
        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        // question_count=99 não bate nem com o alvo correto (5) nem com o
        // total bugado (6) — simula template que mudou de estrutura depois
        // da resposta. Não dá pra provar o divisor histórico → PULA.
        $score = NpsResponseScore::create([
            'nps_response_id' => $response->id,
            'company_id'      => $survey->company_id,
            'dimensao'        => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'score_sum'       => 25.0,
            'question_count'  => 99,
            'average_score'   => 25 / 99,
            'calculated_at'   => now(),
        ]);

        $exitCode = Artisan::call('nps:backfill-divisor-texto-livre', ['--force' => true]);

        $this->assertSame(0, $exitCode);

        $score->refresh();
        $this->assertSame(99, $score->question_count);
        $this->assertEqualsWithDelta(25 / 99, $score->average_score, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════════
    // sem_base: dimensão só com texto_livre não gera divisão por zero
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_sem_base_dimensao_so_texto_livre_nao_grava_nem_divide_por_zero(): void
    {
        // `nps_response_scores.dimensao` só aceita estrategista/analista/empresa
        // (CHECK/ENUM do schema — 'geral' nunca gera score de pessoa). Usamos
        // 'empresa' aqui, simulando um template que hoje só tem texto_livre
        // nessa dimensão (alvo=0).
        $template = NpsTemplate::factory()->create();

        NpsTemplateQuestion::factory()->create([
            'template_id' => $template->id,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'tipo'        => NpsTemplateQuestion::TIPO_TEXTO_LIVRE,
            'ordem'       => 1,
        ]);
        NpsTemplateQuestion::factory()->create([
            'template_id' => $template->id,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'tipo'        => NpsTemplateQuestion::TIPO_TEXTO_LIVRE,
            'ordem'       => 2,
        ]);

        $survey   = NpsSurvey::factory()->create(['template_id' => $template->id]);
        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        // Score histórico anômalo: o template tinha pergunta com peso antes,
        // foi editado depois para 100% texto_livre — hoje o alvo é 0.
        $score = NpsResponseScore::create([
            'nps_response_id' => $response->id,
            'company_id'      => $survey->company_id,
            'dimensao'        => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'score_sum'       => 3.0,
            'question_count'  => 1,
            'average_score'   => 3.0,
            'calculated_at'   => now(),
        ]);

        $exitCode = Artisan::call('nps:backfill-divisor-texto-livre', ['--force' => true]);

        // Sem exception (nunca divisão por zero) e sem gravação.
        $this->assertSame(0, $exitCode);

        $score->refresh();
        $this->assertSame(1, $score->question_count);
        $this->assertEqualsWithDelta(3.0, $score->average_score, 0.001);
    }
}
