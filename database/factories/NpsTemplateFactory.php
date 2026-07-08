<?php

namespace Database\Factories;

use App\Models\NpsTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NpsTemplate>
 *
 * Phase 68 v15.0 — Factory para o Model NpsTemplate.
 * Suporta testes do Plan 68-05 + Phases 69-73 (NpsTemplateService, dashboards,
 * CRUD admin). Defaults sanos garantem inserção limpa em SQLite in-memory.
 */
class NpsTemplateFactory extends Factory
{
    protected $model = NpsTemplate::class;

    public function definition(): array
    {
        return [
            'nome'                    => 'Template ' . $this->faker->unique()->words(2, true),
            'descricao'               => $this->faker->optional(0.5)->sentence(),
            'active'                  => true,
            // Default = false: o unique parcial em is_default impede que 2 factories
            // criem 2 rows com is_default=true simultaneamente. Testes que precisam
            // do default explicito usam ->padrao().
            'is_default'              => false,
            'priority'                => 0,
            'envio_automatico_mensal' => false,
        ];
    }

    /**
     * State: marca este template como "NPS Padrão" (is_default=true).
     * O seed retro (Plan 68-03) usa esse state para criar o único row default.
     */
    public function padrao(): static
    {
        return $this->state(fn (array $attributes) => [
            'nome'       => 'NPS Padrão',
            'is_default' => true,
        ]);
    }

    /**
     * State: habilita/desabilita o template. Útil em testes de precedência do
     * `NpsTemplateService::resolveForCompany` (Phase 69).
     */
    public function active(bool $active = true): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => $active,
        ]);
    }
}
