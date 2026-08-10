<?php

namespace Tests\Feature\Phase125;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova do vínculo signatário↔contrato, do round-trip da evidência do
 * Gate #9 e da preservação dos dados copiados quando o usuário da ECF é
 * apagado (D-07, T-125-11).
 */
class ContratoAssinaturaSignatarioModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function factory_cria_signatario_valido_sem_argumento(): void
    {
        $signatario = ContratoAssinaturaSignatario::factory()->create();

        $this->assertNotNull($signatario->papel);
        $this->assertNotNull($signatario->nome);
        $this->assertNotNull($signatario->email);
        $this->assertNotNull($signatario->cpf);
        $this->assertSame(ContratoAssinaturaSignatario::SITUACAO_PENDENTE, $signatario->situacao);
    }

    #[Test]
    public function signatario_pertence_ao_contrato_correto(): void
    {
        $contratoA = ContratoAssinatura::factory()->create();
        $contratoB = ContratoAssinatura::factory()->create();

        $signatario = ContratoAssinaturaSignatario::factory()
            ->for($contratoA, 'contrato')
            ->create();

        $this->assertTrue($signatario->contrato->is($contratoA));
        $this->assertCount(1, $contratoA->fresh()->signatarios);
        $this->assertCount(0, $contratoB->fresh()->signatarios);
    }

    #[Test]
    public function round_trip_evidencia_signer(): void
    {
        $signatario = ContratoAssinaturaSignatario::factory()->assinou()->create();

        $payloadEsperado = [
            'sign_as'                    => 'contractor',
            'key'                        => '3ec39713-9f0e-4667-bd17-923ff0e58c66',
            'email'                      => $signatario->email,
            'name'                       => $signatario->nome,
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
        ];

        $fresh = $signatario->fresh();

        $this->assertSame($payloadEsperado, $fresh->evidencia_signer);
        $this->assertSame(['email'], $fresh->auths);
        $this->assertIsArray($fresh->auths);
        $this->assertNotNull($fresh->ip_address);
        $this->assertNotNull($fresh->assinado_em);
    }

    #[Test]
    public function apagar_o_usuario_preserva_a_evidencia_de_quem_assinou(): void
    {
        $user = User::factory()->create();

        $signatario = ContratoAssinaturaSignatario::factory()
            ->daEcf($user)
            ->assinou()
            ->create();

        $nome            = $signatario->nome;
        $email           = $signatario->email;
        $cpf             = $signatario->cpf;
        $evidenciaSigner = $signatario->evidencia_signer;

        // forceDelete(), não delete(): User usa SoftDeletes (deleted_at) —
        // um delete() comum não dispara o DELETE físico que a FK
        // `nullOnDelete` observa. A remoção definitiva do usuário é
        // exatamente o `forceDelete()` já usado em UserController.
        $user->forceDelete();

        $fresh = $signatario->fresh();

        $this->assertNotNull($fresh);
        $this->assertNull($fresh->user_id);
        $this->assertSame($nome, $fresh->nome);
        $this->assertSame($email, $fresh->email);
        $this->assertSame($cpf, $fresh->cpf);
        $this->assertSame($evidenciaSigner, $fresh->evidencia_signer);
    }

    #[Test]
    public function situacao_usa_vocabulario_proprio_e_nao_o_do_contrato(): void
    {
        $this->assertCount(3, ContratoAssinaturaSignatario::SITUACAO_TODAS);

        foreach (ContratoAssinaturaSignatario::SITUACAO_TODAS as $situacao) {
            $this->assertNotContains($situacao, ContratoAssinatura::STATUS_TODOS);
        }

        $exclusivosDoContrato = [
            ContratoAssinatura::STATUS_RASCUNHO,
            ContratoAssinatura::STATUS_CANCELADO,
            ContratoAssinatura::STATUS_EXPIRADO,
            ContratoAssinatura::STATUS_ERRO,
            ContratoAssinatura::STATUS_ASSINADO,
            ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ];

        foreach ($exclusivosDoContrato as $status) {
            $this->assertNotContains($status, ContratoAssinaturaSignatario::SITUACAO_TODAS);
        }
    }

    #[Test]
    public function papel_usa_vocabulario_interno(): void
    {
        $this->assertSame(
            ['contratante', 'contratada', 'testemunha'],
            ContratoAssinaturaSignatario::PAPEL_TODOS
        );

        $termosClicksign = ['sign', 'party', 'contractor'];

        foreach ($termosClicksign as $termo) {
            $this->assertNotContains($termo, ContratoAssinaturaSignatario::PAPEL_TODOS);
        }
    }
}
