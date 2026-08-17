<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\Servico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContratoAssinatura>
 *
 * Fase 125 — factory de contratos de assinatura.
 * Fase 127-01 (D-06) — `servico_id` passou a ser obrigatório (o hook
 * `saving` do model lança `\RuntimeException` sem ele).
 */
class ContratoAssinaturaFactory extends Factory
{
    protected $model = ContratoAssinatura::class;

    public function definition(): array
    {
        return [
            'company_id'         => Company::factory(),
            'servico_id'         => fn () => $this->servicoDeTeste(),
            'status'             => ContratoAssinatura::STATUS_RASCUNHO,
            // Quem cuida de company_id_em_andamento e
            // servico_id_em_andamento é o hook `saving` do model — não
            // setar aqui.
            'servicos_snapshot'  => null,
        ];
    }

    /**
     * Devolve o id de um serviço qualquer do catálogo já semeado por
     * migration (`Servico` NÃO tem `HasFactory`). Se por algum motivo o
     * catálogo estiver vazio, cria um registro mínimo copiando o `setor`
     * de um serviço existente — nunca inventar um valor novo: a coluna
     * `setor` é enum legado e o CHECK do SQLite derruba a suíte.
     */
    private function servicoDeTeste(): int
    {
        $id = Servico::query()->value('id');

        if ($id !== null) {
            return $id;
        }

        $setorExistente = Servico::query()->value('setor') ?? Servico::SETOR_OUTROS;

        return Servico::create([
            'nome'          => 'Serviço de teste (factory)',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => $setorExistente,
        ])->id;
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
     * State: preenche `servicos_snapshot` com a forma que a Fase 126
     * congela (D-04/D-10), cada item com nome legível, `valor_contratado`
     * decimal COM CENTAVOS (não redondo, de propósito: valor redondo
     * demais mascara bug de arredondamento no PDF) e as datas de vigência
     * no formato `Y-m-d`, mesma forma de `ContratoServico::$casts`.
     *
     * Fase 127-01 (D-06): o DEFAULT passou de 3 serviços para UM único
     * item — pós D-06, um `ContratoAssinatura` representa um serviço só,
     * então o snapshot "natural" também é de um item. Testes que precisam
     * de múltiplos itens (ex.: concatenação de nomes) devem passar
     * `$servicos` explicitamente, em vez de depender deste default.
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
