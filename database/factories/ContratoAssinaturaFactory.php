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

    /**
     * State: preenche `servicos_snapshot` com a forma que a Fase 127 vai
     * congelar (D-04/D-10) — 2 a 3 serviços, cada um com nome legível,
     * `valor_contratado` decimal COM CENTAVOS (não redondo, de propósito:
     * valor redondo demais mascara bug de arredondamento no PDF) e as datas
     * de vigência no formato `Y-m-d`, mesma forma de
     * `ContratoServico::$casts`.
     *
     * Fase 126 (D-04). "Empresa real" na régua do Success Criteria 3 (ver
     * 126-VALIDATION.md §"A régua do Success Criteria 3") significa FORMA e
     * VOLUME de dado real — não conexão ao banco de produção, que nem está
     * no ar durante os testes. Os valores aqui são inventados, nunca
     * copiados de contrato de cliente real (D-15 / achado WR-07 da Fase
     * 125).
     *
     * @param  array<int, array{servico: string, valor_contratado: float, data_contratacao: string, data_vencimento: string}>|null  $servicos
     */
    public function comSnapshot(?array $servicos = null): static
    {
        $snapshot = $servicos ?? [
            [
                'servico'          => 'Gestão de Tráfego — Mercado Livre',
                'valor_contratado' => 1847.32,
                'data_contratacao' => '2026-01-15',
                'data_vencimento'  => '2027-01-15',
            ],
            [
                'servico'          => 'Consultoria Shopee',
                'valor_contratado' => 923.9,
                'data_contratacao' => '2026-02-01',
                'data_vencimento'  => '2027-02-01',
            ],
            [
                'servico'          => 'Análise de Sugadores',
                'valor_contratado' => 412.55,
                'data_contratacao' => '2026-03-10',
                'data_vencimento'  => '2027-03-10',
            ],
        ];

        return $this->state(fn (array $attributes) => [
            'servicos_snapshot' => $snapshot,
        ]);
    }

    /**
     * State: a empresa do contrato tem `name` extremo — 80+ caracteres,
     * com acentuação e caractere especial (`Ç`, `Ã`, travessão). Insumo do
     * teste de PDF-03 (quebra de página / acentuação) no plano 126-05.
     * Fica aqui para que cada teste não invente o seu próprio nome extremo.
     *
     * Fase 126 (D-04 / PDF-03). Nome e CNPJ são inventados, nunca copiados
     * de empresa real (D-15).
     */
    public function comEmpresaDeNomeExtremo(): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => Company::factory()->state([
                'name' => 'Sociedade Comercial, Industrial e Distribuidora de Produtos Eletrônicos, Informática e Acessórios Ção-Ão Ltda',
                'cnpj' => '12.345.678/0001-99',
            ]),
        ]);
    }
}
