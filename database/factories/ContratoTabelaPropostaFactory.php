<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ContratoTabelaProposta;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContratoTabelaProposta>
 *
 * Fase 140 Plano 04 — Factory de `ContratoTabelaProposta`. Default é uma proposta de TABELA
 * (o formato mais comum na varredura real, 49 de 85) com uma faixa de exemplo; `valorFixo()`
 * troca para o outro formato medido (29 de 85).
 */
class ContratoTabelaPropostaFactory extends Factory
{
    protected $model = ContratoTabelaProposta::class;

    public function definition(): array
    {
        return [
            'clicksign_envelope_id' => 'env-' . $this->faker->unique()->uuid(),
            'nome_envelope'         => 'Contrato Gestão de ADS _ ECF - ' . $this->faker->company(),
            'envelope_situacao'     => 'closed',
            'envelope_data'         => $this->faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'company_id'            => null,
            'confianca'             => ContratoTabelaProposta::CONFIANCA_INCERTO,
            'pontuacao'             => null,
            'ambiguo'               => false,
            'candidatos'            => [],
            'tipo_cobranca'         => ContratoTabelaProposta::TIPO_TABELA,
            'valor_fixo'            => null,
            'faixas'                => [
                ['ordem' => 1, 'limite_superior' => 500000.00, 'valor' => 3000.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 5000.00, 'valor_e_piso' => true],
            ],
            'cnpj_lido'             => null,
            'razao_social_lida'     => null,
            'motivo'                => null,
            'situacao'              => ContratoTabelaProposta::SITUACAO_PENDENTE,
            'confirmado_por'        => null,
            'confirmado_em'         => null,
        ];
    }

    /**
     * State: proposta de valor fixo, sem faixa (formato medido em 29 de 85 contratos).
     */
    public function valorFixo(): static
    {
        return $this->state(fn (array $attributes) => [
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_VALOR_FIXO,
            'valor_fixo'    => 3000.00,
            'faixas'        => null,
        ]);
    }

    /**
     * State: palpite de empresa com CNPJ batendo — confiança máxima.
     */
    public function comEmpresaCasada(): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => Company::factory(),
            'confianca'  => ContratoTabelaProposta::CONFIANCA_CERTO,
            'pontuacao'  => 100.00,
        ]);
    }

    /**
     * State: já conferida por uma pessoa e confirmada.
     */
    public function confirmada(): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id'     => Company::factory(),
            'situacao'       => ContratoTabelaProposta::SITUACAO_CONFIRMADA,
            'confirmado_por' => User::factory(),
            'confirmado_em'  => now(),
        ]);
    }
}
