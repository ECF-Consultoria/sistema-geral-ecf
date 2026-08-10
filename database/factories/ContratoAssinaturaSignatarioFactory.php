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
     * State: signatário que assinou, com a evidência do Gate #9 na forma
     * REAL confirmada contra o sandbox (CLICKSIGN-SANDBOX-EMPIRICO.md
     * §3). É a fixture canônica do Gate #9 para as Fases 126 e 129 — todas
     * as chaves do bloco `data.signer` real, sem poda.
     */
    public function assinou(): static
    {
        return $this->state(fn (array $attributes) => [
            'situacao'    => ContratoAssinaturaSignatario::SITUACAO_ASSINOU,
            'assinado_em' => now()->subMinutes(5),
            'ip_address'  => '187.56.206.108',
            'auths'       => ['email'],
            'evidencia_signer' => [
                'sign_as'                    => 'contractor',
                'key'                        => '3ec39713-9f0e-4667-bd17-923ff0e58c66',
                'email'                      => $attributes['email'] ?? fake()->safeEmail(),
                'name'                       => $attributes['nome'] ?? fake()->name(),
                'auths'                      => ['email'],
                'address'                    => '187.56.206.108',
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
                'url'                        => 'https://sandbox.clicksign.com/notarial/widget/signatures/3ec39713-9f0e-4667-bd17-923ff0e58c66/redirect',
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
