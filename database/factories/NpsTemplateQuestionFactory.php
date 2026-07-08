<?php

namespace Database\Factories;

use App\Models\NpsTemplate;
use App\Models\NpsTemplateQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NpsTemplateQuestion>
 *
 * Phase 68 v15.0 — Factory para perguntas de template NPS.
 * Suporta chain com `->has()->count()` para criar template + N perguntas em
 * 1 chamada nos testes do Plan 68-05.
 */
class NpsTemplateQuestionFactory extends Factory
{
    protected $model = NpsTemplateQuestion::class;

    public function definition(): array
    {
        return [
            'template_id' => NpsTemplate::factory(),
            'texto'       => $this->faker->sentence(6) . '?',
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'obrigatoria' => true,
            'ordem'       => 1,
        ];
    }

    /**
     * State: pergunta na dimensão estrategista (dashboard NPS-E-05 agrega por
     * dimensão via `question_dimensao_snapshot`).
     */
    public function estrategista(): static
    {
        return $this->state(fn (array $attributes) => [
            'dimensao' => NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
        ]);
    }

    /**
     * State: pergunta na dimensão analista.
     */
    public function analista(): static
    {
        return $this->state(fn (array $attributes) => [
            'dimensao' => NpsTemplateQuestion::DIMENSAO_ANALISTA,
        ]);
    }

    /**
     * State: pergunta na dimensão empresa (default do factory já — este state
     * é redundante mas mantido pela simetria com estrategista/analista/geral).
     */
    public function empresa(): static
    {
        return $this->state(fn (array $attributes) => [
            'dimensao' => NpsTemplateQuestion::DIMENSAO_EMPRESA,
        ]);
    }

    /**
     * State: pergunta transversal (ex: comentário final, não agrega em dimensão).
     */
    public function geral(): static
    {
        return $this->state(fn (array $attributes) => [
            'dimensao' => NpsTemplateQuestion::DIMENSAO_GERAL,
        ]);
    }
}
