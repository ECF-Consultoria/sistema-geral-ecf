<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\NpsSurvey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NpsSurvey>
 *
 * Phase 68 v15.0 — Factory para surveys NPS.
 * Suporta o fluxo Phase 31 (manual + mensal) e o novo v15.0 (com template_id).
 */
class NpsSurveyFactory extends Factory
{
    protected $model = NpsSurvey::class;

    public function definition(): array
    {
        return [
            'company_id'      => Company::factory(),
            'token'           => (string) Str::uuid(),
            'status'          => 'pending',
            'expires_at'      => now()->addDays(7),
            'auto_generated'  => false,
            'month_reference' => null,
            // NULL default: rows legadas Phase 31-33 não têm template. Testes v15.0
            // que precisam de template travam com ->for($template) ou state explícito.
            'template_id'     => null,
            'generated_by'    => null,
        ];
    }

    /**
     * State: survey já respondido (`status=completed` + `completed_at` no
     * passado). Usado em testes de dashboard e de fluxo de resposta.
     */
    public function completed(int $daysAgo = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'status'       => 'completed',
            'completed_at' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * State: survey do ciclo mensal automatizado (Phase 31 D-12).
     * `month_reference` no formato `YYYY-MM-DD` (o cast do Model converte).
     */
    public function monthly(string $ymd): static
    {
        return $this->state(fn (array $attributes) => [
            'auto_generated'  => true,
            'month_reference' => $ymd,
            'expires_at'      => now()->addDays(30),
        ]);
    }
}
