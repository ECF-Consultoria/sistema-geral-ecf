<?php

namespace Tests\Feature\V16;

use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsResponseScore;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateQuestion;
use App\Services\Nps\NpsScoreCalculator;
use App\Services\Nps\NpsSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick task 260715-kam — RED do bug do divisor de nota NPS por dimensão.
 *
 * Causa raiz PROVADA (ver PLAN): `NpsScoreCalculator::compute()` conta
 * perguntas do tipo `texto_livre` no divisor, mesmo elas NUNCA tendo peso
 * (`option_peso_snapshot = NULL`). Isso torna nota 5.00 matematicamente
 * inalcançável em qualquer dimensão que tenha ao menos 1 pergunta aberta.
 *
 * Caso real PROVADO contra o template principal de produção (id=2), cliente
 * LUCCMAX Luccauto Itajaí que respondeu peso 5 em TODAS as perguntas com
 * peso: recebeu 4.17 (empresa) / 3.89 (analista) / 3.75 (estrategista) em
 * vez de 5.00 nas 3 dimensões.
 *
 * ARMADILHA que este teste precisa respeitar: pergunta de escala/opções
 * NÃO respondida CONTINUA no divisor (regra deliberada de 2026-07-08,
 * PRESERVADA — ver Teste 4). O bug é EXCLUSIVO de `texto_livre`.
 *
 * @see app/Services/Nps/NpsScoreCalculator.php
 * @see app/Services/Nps/NpsSnapshotService.php
 * @see .planning/quick/260715-kam-nps-divisor-texto-livre/260715-kam-PLAN.md
 */
class NpsDivisorTextoLivreTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    // ═══════════════════════════════════════════════════════════════════
    // Teste 1 — caso canônico LUCCMAX (dimensão empresa)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dimensao_empresa_todos_pesos_maximos_retorna_5_luccmax(): void
    {
        // Reprodução mínima do caso real: 5 perguntas com peso (escala) + 1
        // texto_livre na dimensão empresa. Cliente responde peso 5 nas 5
        // com peso — a texto_livre nunca gera answer com peso.
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

        for ($i = 1; $i <= 5; $i++) {
            NpsResponseAnswer::factory()->create([
                'response_id'                => $response->id,
                'question_dimensao_snapshot' => NpsTemplateQuestion::DIMENSAO_EMPRESA,
                'option_peso_snapshot'       => 5,
            ]);
        }

        $calc = app(NpsScoreCalculator::class);

        // HOJE (bug): 25 / 6 perguntas (conta a texto_livre) = 4.1666...
        // ESPERADO (fix): 25 / 5 perguntas com peso = 5.0
        $this->assertEqualsWithDelta(
            5.0,
            $calc->compute($response, NpsTemplateQuestion::DIMENSAO_EMPRESA),
            0.001
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 2 — dimensão analista (9 perguntas, 7 com peso + 2 texto_livre)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dimensao_analista_todos_pesos_maximos_retorna_5(): void
    {
        $template = NpsTemplate::factory()->create();

        for ($i = 1; $i <= 7; $i++) {
            NpsTemplateQuestion::factory()->create([
                'template_id' => $template->id,
                'dimensao'    => NpsTemplateQuestion::DIMENSAO_ANALISTA,
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'ordem'       => $i,
            ]);
        }
        for ($i = 8; $i <= 9; $i++) {
            NpsTemplateQuestion::factory()->create([
                'template_id' => $template->id,
                'dimensao'    => NpsTemplateQuestion::DIMENSAO_ANALISTA,
                'tipo'        => NpsTemplateQuestion::TIPO_TEXTO_LIVRE,
                'ordem'       => $i,
            ]);
        }

        $survey   = NpsSurvey::factory()->create(['template_id' => $template->id]);
        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        for ($i = 1; $i <= 7; $i++) {
            NpsResponseAnswer::factory()->create([
                'response_id'                => $response->id,
                'question_dimensao_snapshot' => NpsTemplateQuestion::DIMENSAO_ANALISTA,
                'option_peso_snapshot'       => 5,
            ]);
        }

        $calc = app(NpsScoreCalculator::class);

        // HOJE (bug): 35 / 9 = 3.888... ESPERADO (fix): 35 / 7 = 5.0
        $this->assertEqualsWithDelta(
            5.0,
            $calc->compute($response, NpsTemplateQuestion::DIMENSAO_ANALISTA),
            0.001
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 3 — dimensão estrategista (8 perguntas, 6 com peso + 2 texto_livre)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dimensao_estrategista_todos_pesos_maximos_retorna_5(): void
    {
        $template = NpsTemplate::factory()->create();

        for ($i = 1; $i <= 6; $i++) {
            NpsTemplateQuestion::factory()->create([
                'template_id' => $template->id,
                'dimensao'    => NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'ordem'       => $i,
            ]);
        }
        for ($i = 7; $i <= 8; $i++) {
            NpsTemplateQuestion::factory()->create([
                'template_id' => $template->id,
                'dimensao'    => NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
                'tipo'        => NpsTemplateQuestion::TIPO_TEXTO_LIVRE,
                'ordem'       => $i,
            ]);
        }

        $survey   = NpsSurvey::factory()->create(['template_id' => $template->id]);
        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        for ($i = 1; $i <= 6; $i++) {
            NpsResponseAnswer::factory()->create([
                'response_id'                => $response->id,
                'question_dimensao_snapshot' => NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
                'option_peso_snapshot'       => 5,
            ]);
        }

        $calc = app(NpsScoreCalculator::class);

        // HOJE (bug): 30 / 8 = 3.75. ESPERADO (fix): 30 / 6 = 5.0
        $this->assertEqualsWithDelta(
            5.0,
            $calc->compute($response, NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA),
            0.001
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 4 — REGRESSÃO da regra 2026-07-08 (DEVE continuar passando)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_pergunta_escala_pulada_continua_puxando_media_pra_baixo(): void
    {
        // Regra deliberada de 2026-07-08 (docblock do calculator): pergunta
        // de ESCALA não respondida CONTINUA no divisor, puxando a média pra
        // baixo. Este teste PASSA tanto ANTES quanto DEPOIS do fix desta
        // task — a pergunta texto_livre do cenário fica numa dimensão
        // DIFERENTE (empresa) da testada (analista) DE PROPÓSITO: o filtro
        // `WHERE dimensao = 'analista'` já a exclui em AMBOS os códigos
        // (antigo e novo), isolando puramente a regra de 08/07 sem
        // interferência do bug desta task.
        $template = NpsTemplate::factory()->create();

        for ($i = 1; $i <= 4; $i++) {
            NpsTemplateQuestion::factory()->create([
                'template_id' => $template->id,
                'dimensao'    => NpsTemplateQuestion::DIMENSAO_ANALISTA,
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'ordem'       => $i,
            ]);
        }
        // Noise proposital em OUTRA dimensão — não deve afetar o cálculo de 'analista'.
        NpsTemplateQuestion::factory()->create([
            'template_id' => $template->id,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'tipo'        => NpsTemplateQuestion::TIPO_TEXTO_LIVRE,
            'ordem'       => 5,
        ]);

        $survey   = NpsSurvey::factory()->create(['template_id' => $template->id]);
        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        foreach ([4, 5, 5] as $peso) {
            NpsResponseAnswer::factory()->create([
                'response_id'                => $response->id,
                'question_dimensao_snapshot' => NpsTemplateQuestion::DIMENSAO_ANALISTA,
                'option_peso_snapshot'       => $peso,
            ]);
        }
        // A 4a pergunta de escala fica sem resposta — CONTINUA no divisor.

        $calc = app(NpsScoreCalculator::class);

        // 14 / 4 = 3.5 — vale ANTES e DEPOIS do fix desta task.
        $this->assertEqualsWithDelta(
            3.5,
            $calc->compute($response, NpsTemplateQuestion::DIMENSAO_ANALISTA),
            0.001
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 5 — texto_livre RESPONDIDA não altera SUM nem divisor
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_texto_livre_respondida_nao_altera_sum_nem_divisor(): void
    {
        // Mesmo cenário do Teste 1, mas agora a pergunta texto_livre É
        // respondida (comentário livre, sem peso). option_peso_snapshot é
        // sempre NULL para texto_livre — SUM ignora NULL e o divisor não
        // deve contá-la, respondida ou não. Resultado idêntico ao Teste 1.
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

        for ($i = 1; $i <= 5; $i++) {
            NpsResponseAnswer::factory()->create([
                'response_id'                => $response->id,
                'question_dimensao_snapshot' => NpsTemplateQuestion::DIMENSAO_EMPRESA,
                'option_peso_snapshot'       => 5,
            ]);
        }
        // Answer da texto_livre: comentário preenchido, peso NULL (snapshot real).
        NpsResponseAnswer::factory()->create([
            'response_id'                => $response->id,
            'question_dimensao_snapshot' => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'option_peso_snapshot'       => null,
            'option_label_snapshot'      => null,
            'comentario'                 => 'Adorei o atendimento!',
        ]);

        $calc = app(NpsScoreCalculator::class);

        $this->assertEqualsWithDelta(
            5.0,
            $calc->compute($response, NpsTemplateQuestion::DIMENSAO_EMPRESA),
            0.001
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 6 — edge: dimensão só com texto_livre retorna null, não 0.0
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dimensao_so_com_texto_livre_retorna_null_nao_zero(): void
    {
        // Dimensão cujas ÚNICAS perguntas são texto_livre. Divisor (perguntas
        // COM peso) = 0 → semântica "sem base": null, NUNCA 0.0 e NUNCA
        // divisão por zero. HOJE (bug) o divisor conta a texto_livre (2),
        // então SUM (sempre 0, pesos são NULL) / 2 = 0.0 — errado.
        $template = NpsTemplate::factory()->create();

        NpsTemplateQuestion::factory()->create([
            'template_id' => $template->id,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_GERAL,
            'tipo'        => NpsTemplateQuestion::TIPO_TEXTO_LIVRE,
            'ordem'       => 1,
        ]);
        NpsTemplateQuestion::factory()->create([
            'template_id' => $template->id,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_GERAL,
            'tipo'        => NpsTemplateQuestion::TIPO_TEXTO_LIVRE,
            'ordem'       => 2,
        ]);

        $survey   = NpsSurvey::factory()->create(['template_id' => $template->id]);
        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        NpsResponseAnswer::factory()->create([
            'response_id'                => $response->id,
            'question_dimensao_snapshot' => NpsTemplateQuestion::DIMENSAO_GERAL,
            'option_peso_snapshot'       => null,
            'option_label_snapshot'      => null,
            'comentario'                 => 'Só comentário, sem nota.',
        ]);

        $calc = app(NpsScoreCalculator::class);

        $this->assertNull($calc->compute($response, NpsTemplateQuestion::DIMENSAO_GERAL));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 7 — invariante do snapshot: question_count não conta texto_livre
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_invariante_snapshot_question_count_nao_conta_texto_livre(): void
    {
        // Invariante score_sum / question_count == average_score em
        // nps_response_scores, gravada pelo NpsSnapshotService. question_count
        // NÃO pode contar as perguntas texto_livre — senão a invariante
        // quebra quando o calculator for corrigido (Task 2), já que ele
        // passaria a dividir por um denominador diferente do gravado aqui.
        $cenario     = $this->criarCenarioMlComResponsaveis();
        $company     = $cenario['company'];
        $servicoPerf = $cenario['servicoPerf'];

        $template = NpsTemplate::factory()->create();
        $template->serviceScopes()->attach($servicoPerf);

        for ($i = 1; $i <= 3; $i++) {
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
            'ordem'       => 4,
        ]);

        $survey = NpsSurvey::factory()->create([
            'template_id' => $template->id,
            'company_id'  => $company->id,
        ]);
        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        for ($i = 1; $i <= 3; $i++) {
            NpsResponseAnswer::factory()->create([
                'response_id'                => $response->id,
                'question_dimensao_snapshot' => NpsTemplateQuestion::DIMENSAO_EMPRESA,
                'option_peso_snapshot'       => 5,
            ]);
        }

        app(NpsSnapshotService::class)->registrar($response);

        $score = NpsResponseScore::where('nps_response_id', $response->id)
            ->where('dimensao', NpsTemplateQuestion::DIMENSAO_EMPRESA)
            ->first();

        $this->assertNotNull($score);

        // Invariante: score_sum / question_count == average_score.
        $this->assertEqualsWithDelta(
            $score->score_sum / $score->question_count,
            $score->average_score,
            0.001
        );

        // question_count NÃO pode contar a pergunta texto_livre — só as 3 com peso.
        $this->assertSame(3, $score->question_count);
    }
}
