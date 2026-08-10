<?php

namespace Database\Factories;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContratoAssinaturaSignatario>
 *
 * Fase 125 — factory de signatários de contrato.
 */
class ContratoAssinaturaSignatarioFactory extends Factory
{
    protected $model = ContratoAssinaturaSignatario::class;

    public function definition(): array
    {
        return [
            'contrato_assinatura_id' => ContratoAssinatura::factory(),
            'user_id'                => null,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'nome'                   => fake()->name(),
            'email'                  => fake()->safeEmail(),
            'cpf'                    => fake()->numerify('###.###.###-##'),
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'assinado_em'            => null,
            'ip_address'             => null,
            'auths'                  => null,
            'evidencia_signer'       => null,
            'clicksign_signer_key'   => null,
        ];
    }

    /**
     * State: signatário que assinou, com a evidência do Gate #9 na FORMA
     * real confirmada contra o sandbox (CLICKSIGN-SANDBOX-EMPIRICO.md §3):
     * todas as chaves do bloco `data.signer`, sem poda.
     *
     * ⚠️ Os VALORES são sintéticos de propósito. A primeira versão desta
     * factory trazia o IP público e a chave de signatário reais colhidos na
     * rodada do Gate #9 — PII de verdade, que ficaria permanente no
     * histórico do git. Trocados no code review da Fase 125 por um IP de
     * documentação (RFC 5737, `203.0.113.0/24`) e um UUID obviamente falso.
     * Ao atualizar esta fixture com dados novos da Clicksign, anonimize
     * antes de colar.
     */
    public function assinou(): static
    {
        return $this->state(fn (array $attributes) => [
            'situacao'    => ContratoAssinaturaSignatario::SITUACAO_ASSINOU,
            'assinado_em' => now()->subMinutes(5),
            'ip_address'  => '203.0.113.10',
            'auths'       => ['email'],
            'evidencia_signer' => [
                'sign_as'                    => 'contractor',
                'key'                        => '00000000-0000-4000-8000-000000000001',
                'email'                      => $attributes['email'] ?? fake()->safeEmail(),
                'name'                       => $attributes['nome'] ?? fake()->name(),
                'auths'                      => ['email'],
                'address'                    => '203.0.113.10',
                'latitude'                   => null,
                'longitude'                  => null,
                'selfie_enabled'             => false,
                'handwritten_enabled'        => false,
                'official_document_enabled'  => false,
                'liveness_enabled'           => false,
                'facial_biometrics_enabled'  => false,
                'federal_data_validation'    => null,
                'documentation'              => null,
                'has_documentation'          => false,
                'phone_number'               => null,
                'phone_number_hash'          => null,
                'communicate_by'             => 'email',
                'url'                        => 'https://sandbox.clicksign.com/notarial/widget/signatures/00000000-0000-4000-8000-000000000001/redirect',
            ],
        ]);
    }

    /**
     * State: signatário que recusou. Sem carimbo de assinatura — a
     * recusa não passa pelo evento `sign`.
     */
    public function recusou(): static
    {
        return $this->state(fn (array $attributes) => [
            'situacao'    => ContratoAssinaturaSignatario::SITUACAO_RECUSOU,
            'assinado_em' => null,
        ]);
    }

    /**
     * State: signatário é usuário interno da ECF. Copia nome/e-mail do
     * `User` MESMO tendo o vínculo — não é redundância, é a D-07: a
     * evidência jurídica não pode depender de FK viva.
     */
    public function daEcf(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'nome'    => $user->name,
            'email'   => $user->email,
        ]);
    }
}
