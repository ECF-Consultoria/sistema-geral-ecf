<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContratoServico>
 *
 * Phase 135 — Factory minimalista para os testes do motor de Onboarding.
 * Cobre so os campos obrigatorios/utilizados nos testes desta fase; colunas
 * hubspot_* (proveniencia) ficam de fora do default.
 *
 * Sem ServicoFactory dedicada ainda: o servico e criado inline no default,
 * no setor Performance com cobranca mensal (molde neutro para os testes do
 * Observer, que precisam de um servico_id valido, nao necessariamente "Gestao").
 */
class ContratoServicoFactory extends Factory
{
    protected $model = ContratoServico::class;

    public function definition(): array
    {
        return [
            'company_id'       => Company::factory(),
            'servico_id'       => fn () => Servico::create([
                'nome'          => fake()->unique()->words(3, true),
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_PERFORMANCE,
            ])->id,
            'valor_contratado' => fake()->randomFloat(2, 100, 5000),
            'data_contratacao' => now()->toDateString(),
            'data_vencimento'  => null,
            'ativo'            => true,
        ];
    }

    /**
     * State: fixa o servico do contrato. Os testes do Observer (Plano 04)
     * precisam criar contrato do servico "Gestao" especificamente.
     */
    public function paraServico(Servico|int $servico): static
    {
        return $this->state(fn (array $attributes) => [
            'servico_id' => $servico instanceof Servico ? $servico->id : $servico,
        ]);
    }
}
