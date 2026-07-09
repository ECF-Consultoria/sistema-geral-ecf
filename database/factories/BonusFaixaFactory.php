<?php

namespace Database\Factories;

use App\Models\BonusFaixa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BonusFaixa>
 *
 * Phase 74 — Factory do Model `BonusFaixa`. Alimenta os testes Feature do
 * `DesempenhoScoreService` (Plan 74-09) e do `DesempenhoConfigController`
 * (Plan 74-10). Estados nomeados replicam as 4 faixas canônicas do D-16 para
 * uso em fixtures.
 *
 * Defaults sanos permitem inserção limpa em SQLite in-memory
 * (RefreshDatabase + PRAGMA foreign_keys ON) sem colisão de unique(slug):
 * `faker->unique()->slug(1)` gera slug distinto por invocação.
 */
class BonusFaixaFactory extends Factory
{
    protected $model = BonusFaixa::class;

    public function definition(): array
    {
        return [
            'slug'      => $this->faker->unique()->slug(1),
            'nome'      => $this->faker->words(2, true),
            'descricao' => $this->faker->sentence(),
            'nota_min'  => 0.00,
            'nota_max'  => 5.00,
            'ordem'     => $this->faker->numberBetween(1, 10),
            'ativo'     => true,
        ];
    }

    /**
     * State: replica a faixa canônica `sem_bonus` (D-16).
     */
    public function semBonus(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug'      => 'sem_bonus',
            'nome'      => 'Sem bônus',
            'descricao' => 'Nota final abaixo do piso da régua. Sem bônus mensal.',
            'nota_min'  => 0.00,
            'nota_max'  => 3.99,
            'ordem'     => 1,
            'ativo'     => true,
        ]);
    }

    /**
     * State: replica a faixa canônica `basico` (D-16).
     */
    public function basico(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug'      => 'basico',
            'nome'      => 'Básico',
            'descricao' => 'Bônus básico da régua.',
            'nota_min'  => 4.00,
            'nota_max'  => 4.49,
            'ordem'     => 2,
            'ativo'     => true,
        ]);
    }

    /**
     * State: replica a faixa canônica `intermediario` (D-16).
     */
    public function intermediario(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug'      => 'intermediario',
            'nome'      => 'Intermediário',
            'descricao' => 'Bônus intermediário. 2 meses consecutivos nesta faixa promovem para máximo (regra DESEMP-08).',
            'nota_min'  => 4.50,
            'nota_max'  => 4.99,
            'ordem'     => 3,
            'ativo'     => true,
        ]);
    }

    /**
     * State: replica a faixa canônica `maximo` (D-16).
     */
    public function maximo(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug'      => 'maximo',
            'nome'      => 'Máximo',
            'descricao' => 'Nota final = 5.00 OU 2 meses consecutivos intermediário. Bônus máximo.',
            'nota_min'  => 5.00,
            'nota_max'  => 5.00,
            'ordem'     => 4,
            'ativo'     => true,
        ]);
    }

    /**
     * State: desativa a faixa (fica na tabela mas fora da classificação).
     */
    public function inativa(): static
    {
        return $this->state(fn (array $attributes) => [
            'ativo' => false,
        ]);
    }
}
