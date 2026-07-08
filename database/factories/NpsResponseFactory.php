<?php

namespace Database\Factories;

use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NpsResponse>
 *
 * Phase 68 v15.0 — Factory para respostas NPS.
 *
 * Defaults com scores NULL: a partir de v15.0 as respostas vivem no snapshot
 * per-row `nps_response_answers` e as colunas `score_*` legadas (Phase 31)
 * são NULLABLE (migration Plan 68-01 Task 3). Testes que precisam simular
 * rows legadas Phase 31 usam ->legacyScores($est, $ana, $emp).
 */
class NpsResponseFactory extends Factory
{
    protected $model = NpsResponse::class;

    public function definition(): array
    {
        return [
            // FK correta é `survey_id` (não `nps_survey_id`), confirmado em
            // app/Models/NpsResponse.php + database/migrations/*create_nps_*
            'survey_id'          => NpsSurvey::factory(),
            'score_estrategista' => null,
            'score_analista'     => null,
            'score_empresa'      => null,
            'respondent_name'    => $this->faker->name(),
            'comment'            => null,
        ];
    }

    /**
     * State: popula os 3 scores legados (Phase 31). Usado em testes de compat
     * com dashboards NPS-E-01…04 que ainda leem das colunas score_*.
     */
    public function legacyScores(int $est, ?int $ana, int $emp): static
    {
        return $this->state(fn (array $attributes) => [
            'score_estrategista' => $est,
            'score_analista'     => $ana,
            'score_empresa'      => $emp,
        ]);
    }
}
