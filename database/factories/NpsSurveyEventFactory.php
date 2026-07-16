<?php

namespace Database\Factories;

use App\Models\NpsSurvey;
use App\Models\NpsSurveyEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NpsSurveyEvent>
 *
 * Phase 94 (AB-94-3) — Factory para eventos da trilha técnica do survey NPS.
 */
class NpsSurveyEventFactory extends Factory
{
    protected $model = NpsSurveyEvent::class;

    public function definition(): array
    {
        return [
            'survey_id'  => NpsSurvey::factory(),
            'event_type' => NpsSurveyEvent::TYPE_OPENED,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'user_id'    => null,
            'metadata'   => null,
        ];
    }
}
