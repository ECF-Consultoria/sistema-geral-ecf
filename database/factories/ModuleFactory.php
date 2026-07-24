<?php

namespace Database\Factories;

use App\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Module>
 *
 * Phase 97 (Module Registry) — factory do model `Module`. Alimenta os testes
 * do `ModuleRegistry` e as fixtures da Wave 4 (comandos `modules:sync` /
 * `modules:check`).
 *
 * Defaults sanos para SQLite in-memory (RefreshDatabase): `key` usa
 * `faker->unique()->slug(2)` para não colidir com o `unique(key)` da tabela.
 */
class ModuleFactory extends Factory
{
    protected $model = Module::class;

    public function definition(): array
    {
        return [
            'key'      => $this->faker->unique()->slug(2),
            'name'     => $this->faker->words(2, true),
            'grupo'    => null,
            'stage'    => 'producao',
            'ordem'    => $this->faker->numberBetween(0, 100),
            'bloqueado' => false,
            'metadata' => null,
        ];
    }

    /** State: define um estágio arbitrário (dentro dos canônicos de `App\Support\Modules::STAGES`). */
    public function stage(string $stage): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => $stage,
        ]);
    }

    /** State: módulo em desenvolvimento (visível só pro time Dev). */
    public function emDesenvolvimento(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'em_desenvolvimento',
        ]);
    }

    /** State: módulo arquivado (abandonado, sem item de menu). */
    public function arquivado(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'arquivado',
        ]);
    }
}
