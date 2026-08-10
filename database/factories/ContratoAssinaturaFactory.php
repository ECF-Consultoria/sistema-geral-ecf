<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContratoAssinatura>
 *
 * Fase 125 — factory de contratos de assinatura.
 */
class ContratoAssinaturaFactory extends Factory
{
    protected $model = ContratoAssinatura::class;

    public function definition(): array
    {
        return [
            'company_id'         => Company::factory(),
            'status'             => ContratoAssinatura::STATUS_RASCUNHO,
            // Quem cuida de company_id_em_andamento é o hook `saving` do
            // model — não setar aqui.
            'servicos_snapshot'  => null,
        ];
    }

    /**
     * State: contrato em andamento (aguardando_assinaturas). NÃO copiar
     * `$attributes['company_id']` para a coluna auxiliar aqui: dentro de um
     * state esse valor pode ser uma instância de `Factory` (não resolvida
     * ainda), não um inteiro — o hook `saving` do model resolve isso depois
     * de a empresa já existir.
     */
    public function emAndamento(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ]);
    }

    /**
     * State: contrato assinado, ainda não liberado — o caso da D-05 que o
     * alerta da REDE-02 precisa enxergar (`assinado` com `liberado_em`
     * nulo).
     */
    public function assinado(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'      => ContratoAssinatura::STATUS_ASSINADO,
            'enviado_em'  => now()->subDays(3),
            'assinado_em' => now()->subDay(),
            'liberado_em' => null,
        ]);
    }
}
