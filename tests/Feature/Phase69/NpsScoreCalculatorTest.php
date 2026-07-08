<?php

namespace Tests\Feature\Phase69;

use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsTemplateQuestion;
use App\Services\Nps\NpsScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 69 Plan 02 — RED da suite Feature do NpsScoreCalculator.
 *
 * Cobre REQ NPS-B-02: dado NpsResponse + dimensao, retornar AVG dos
 * option_peso_snapshot filtrando por question_dimensao_snapshot.
 *
 * Regras testadas (research §1 + §5):
 *  - Fonte de verdade e o snapshot per-row em nps_response_answers, nunca
 *    as FKs vivas nem as colunas legacy score_* de nps_responses.
 *  - AVG uniforme entre tipos escala e opcoes (nao ha branch por tipo).
 *  - Semantica de vazio: sem answers na dimensao -> null (nao 0.0, nao erro).
 *  - Defesa: dimensao fora do whitelist NpsTemplateQuestion::DIMENSOES -> null.
 *
 * @see .planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-02-PLAN.md
 * @see .planning/research/v15-nps-templates-schema.md §1 e §5
 * @see app/Models/NpsTemplateQuestion.php const DIMENSOES
 */
class NpsScoreCalculatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper: cria uma answer da $dimensao pedida com o $peso e $label
     * dados, ligada ao $response. Bypassa FKs vivas (template_question_id
     * e template_option_id) — o calculator le apenas o snapshot.
     */
    private function anexarAnswer(
        NpsResponse $response,
        string $dimensao,
        int $peso,
        string $label = null,
    ): NpsResponseAnswer {
        return NpsResponseAnswer::factory()->create([
            'response_id'                => $response->id,
            'question_dimensao_snapshot' => $dimensao,
            'option_peso_snapshot'       => $peso,
            'option_label_snapshot'      => $label ?? (string) $peso,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Happy path — AVG basico
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_multiplas_answers_dimensao_estrategista_retorna_avg(): void
    {
        // Cenario canonico REQ NPS-B-02: N answers da mesma dimensao,
        // media aritmetica dos pesos snapshot.
        $response = NpsResponse::factory()->create();

        $this->anexarAnswer($response, 'estrategista', 3);
        $this->anexarAnswer($response, 'estrategista', 4);
        $this->anexarAnswer($response, 'estrategista', 5);

        $calc = app(NpsScoreCalculator::class);

        // (3 + 4 + 5) / 3 = 4.0
        $this->assertSame(4.0, $calc->compute($response, 'estrategista'));
    }

    #[Test]
    public function test_uma_answer_retorna_o_peso(): void
    {
        // Edge case: 1 answer -> AVG = peso da answer.
        $response = NpsResponse::factory()->create();

        $this->anexarAnswer($response, 'empresa', 5);

        $calc = app(NpsScoreCalculator::class);

        $this->assertSame(5.0, $calc->compute($response, 'empresa'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Semantica de vazio — null e nao 0.0
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_zero_answers_retorna_null(): void
    {
        // Response existe mas nao tem answers da dimensao pedida — retorna
        // null (semantica: "nao ha perguntas desta dimensao neste template",
        // NAO "nota zero"). Consumidores diferenciam null vs 0.0 no display.
        $response = NpsResponse::factory()->create();

        // 2 answers de OUTRA dimensao para provar que so o filtro conta.
        $this->anexarAnswer($response, 'estrategista', 4);
        $this->anexarAnswer($response, 'estrategista', 5);

        $calc = app(NpsScoreCalculator::class);

        $this->assertNull($calc->compute($response, 'analista'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Defesa contra input invalido
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dimensao_invalida_retorna_null(): void
    {
        // Threat T-69-02-01: chamador pode passar string arbitraria vinda
        // de request user. Calculator faz whitelist strict contra
        // NpsTemplateQuestion::DIMENSOES e retorna null — NAO lanca exception.
        $response = NpsResponse::factory()->create();
        $this->anexarAnswer($response, 'estrategista', 4);

        $calc = app(NpsScoreCalculator::class);

        // Dimensao fora do enum canonico -> null defensivo.
        $this->assertNull($calc->compute($response, 'xpto'));
        $this->assertNull($calc->compute($response, ''));
        $this->assertNull($calc->compute($response, 'ESTRATEGISTA')); // case-sensitive
    }

    // ═══════════════════════════════════════════════════════════════════
    // Filtro por dimensao — nao mistura eixos
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_mistura_dimensoes_filtra_corretamente(): void
    {
        // Response tem answers de 2 dimensoes ao mesmo tempo. Cada
        // dimensao tem seu proprio AVG isolado; dimensao ausente = null.
        $response = NpsResponse::factory()->create();

        // Estrategista: pesos [4, 5] -> AVG 4.5
        $this->anexarAnswer($response, 'estrategista', 4);
        $this->anexarAnswer($response, 'estrategista', 5);

        // Analista: pesos [1, 2] -> AVG 1.5
        $this->anexarAnswer($response, 'analista', 1);
        $this->anexarAnswer($response, 'analista', 2);

        $calc = app(NpsScoreCalculator::class);

        $this->assertSame(4.5, $calc->compute($response, 'estrategista'));
        $this->assertSame(1.5, $calc->compute($response, 'analista'));
        // Sem answers da dimensao 'empresa' -> null (nao 0.0).
        $this->assertNull($calc->compute($response, 'empresa'));
        // Sem answers da dimensao 'geral' -> null.
        $this->assertNull($calc->compute($response, 'geral'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Uniformidade escala vs opcoes — research §5
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_avg_uniforme_escala_e_opcoes(): void
    {
        // Research §5: calculator NAO faz branch por tipo de pergunta. AVG
        // trata answers de perguntas tipo=escala (labels 1..5) e tipo=opcoes
        // (labels Sim/Nao) uniformemente — le apenas option_peso_snapshot.
        //
        // Cenario: 4 answers da dimensao 'geral', com labels/origens mistos:
        //   - Origem escala: label='5', peso=5
        //   - Origem escala: label='3', peso=3
        //   - Origem opcoes: label='Sim', peso=5
        //   - Origem opcoes: label='Nao', peso=1
        // AVG = (5 + 3 + 5 + 1) / 4 = 3.5
        $response = NpsResponse::factory()->create();

        $this->anexarAnswer($response, 'geral', 5, '5');
        $this->anexarAnswer($response, 'geral', 3, '3');
        $this->anexarAnswer($response, 'geral', 5, 'Sim');
        $this->anexarAnswer($response, 'geral', 1, 'Nao');

        $calc = app(NpsScoreCalculator::class);

        $this->assertSame(3.5, $calc->compute($response, 'geral'));
    }
}
