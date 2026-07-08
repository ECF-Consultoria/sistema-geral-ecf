<?php

namespace Database\Factories;

use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NpsTemplateOption>
 *
 * Phase 68 v15.0 — Factory para opções de pergunta NPS.
 * Peso default = 3 (meio da escala 1..5). Testes que precisam de peso especifico
 * setam via ->state ou ->create(['peso' => N]).
 */
class NpsTemplateOptionFactory extends Factory
{
    protected $model = NpsTemplateOption::class;

    public function definition(): array
    {
        return [
            'question_id' => NpsTemplateQuestion::factory(),
            'label'       => '3',
            'peso'        => 3,
            'ordem'       => 1,
        ];
    }
}
