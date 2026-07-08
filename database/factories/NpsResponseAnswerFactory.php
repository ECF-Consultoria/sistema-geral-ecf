<?php

namespace Database\Factories;

use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NpsResponseAnswer>
 *
 * Phase 68 v15.0 — Factory para snapshot per-row de resposta NPS.
 *
 * Defaults com valores hardcoded nas colunas snapshot (texto/dimensao/label/peso)
 * — teste que precisar de FK viva usa `->for($question, 'templateQuestion')`
 * e `->for($option, 'templateOption')`. Ambas FKs default NULL, replicando
 * o cenário de hard-delete futuro do template (research §1).
 */
class NpsResponseAnswerFactory extends Factory
{
    protected $model = NpsResponseAnswer::class;

    public function definition(): array
    {
        return [
            'response_id'                => NpsResponse::factory(),
            // FKs vivas default NULL — snapshot per-row deve funcionar sem elas
            // (research §1: snapshot é a verdade histórica; FK é opcional para audit).
            'template_question_id'       => null,
            'template_option_id'         => null,
            'question_texto_snapshot'    => 'Como você avalia o serviço?',
            'question_dimensao_snapshot' => 'empresa',
            'option_label_snapshot'      => '4',
            'option_peso_snapshot'       => 4,
            'comentario'                 => null,
        ];
    }
}
