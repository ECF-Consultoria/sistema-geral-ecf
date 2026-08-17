<?php

namespace Tests\Feature\Phase129;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Services\Clicksign\ContratoSignatariosSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite Feature — Fase 129, plano 129-03, Task 1 (ContratoSignatariosSyncService).
 *
 * Fixtures montadas à mão a partir da forma MEDIDA no gate A1
 * (129-GATE.md) e no Gate #9 (CLICKSIGN-SANDBOX-EMPIRICO.md §3) — IP da
 * faixa RFC 5737 (203.0.113.0/24) e UUID sintético
 * 00000000-0000-4000-8000-000000000001, mesma disciplina de
 * ContratoAssinaturaSignatarioFactory::assinou().
 *
 * Cobre:
 *  T1. evento `sign` casa por clicksign_signer_key e grava situação, data, IP, auths, evidência
 *  T2. evento `sign` casa por e-mail quando a chave está vazia, e preenche a chave
 *  T3. aplicar a MESMA lista duas vezes produz o mesmo estado (idempotência)
 *  T4. ordem invertida por `created`: refusal depois de sign deixa recusou; sign depois de refusal deixa assinou
 *  T5. sign de signatário desconhecido não cria linha nova e aparece em nao_reconhecidos
 */
class ContratoSignatariosSyncTest extends TestCase
{
    use RefreshDatabase;

    private const IP_TESTE = '203.0.113.10';

    private function eventoSign(string $signerKey, string $email, string $nome, string $created): array
    {
        return [
            'id'         => 'evt-' . $signerKey,
            'type'       => 'events',
            'attributes' => [
                'name'    => 'sign',
                'created' => $created,
                'data'    => [
                    'signer' => [
                        'key'     => $signerKey,
                        'email'   => $email,
                        'name'    => $nome,
                        'sign_as' => 'contractor',
                        'auths'   => ['email'],
                        'address' => self::IP_TESTE,
                    ],
                ],
            ],
        ];
    }

    private function eventoRefusal(string $signerKey, string $email, string $nome, string $created): array
    {
        return [
            'id'         => 'evt-refusal-' . $signerKey,
            'type'       => 'events',
            'attributes' => [
                'name'    => 'refusal',
                'created' => $created,
                'data'    => [
                    'signer'  => [
                        'key'   => $signerKey,
                        'email' => $email,
                        'name'  => $nome,
                    ],
                    'refusal' => [
                        'reasons' => ['dados_incorretos'],
                        'comment' => 'CNPJ divergente do contrato.',
                    ],
                ],
            ],
        ];
    }

    public function test_evento_sign_casa_por_clicksign_signer_key_e_grava_estado(): void
    {
        $contrato   = ContratoAssinatura::factory()->create();
        $signatario = ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'email'                  => 'fulano@example.com',
            'clicksign_signer_key'   => '00000000-0000-4000-8000-000000000001',
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        $eventos = [
            $this->eventoSign('00000000-0000-4000-8000-000000000001', 'fulano@example.com', 'Fulano de Tal', '2026-08-13T10:27:02.000-03:00'),
        ];

        $resultado = (new ContratoSignatariosSyncService())->aplicar($contrato, $eventos);

        $this->assertSame(1, $resultado['assinaram']);
        $this->assertSame(0, $resultado['recusaram']);
        $this->assertSame([], $resultado['nao_reconhecidos']);

        $signatario->refresh();
        $this->assertSame(ContratoAssinaturaSignatario::SITUACAO_ASSINOU, $signatario->situacao);
        $this->assertNotNull($signatario->assinado_em);
        $this->assertSame(self::IP_TESTE, $signatario->ip_address);
        $this->assertSame(['email'], $signatario->auths);
        $this->assertSame('00000000-0000-4000-8000-000000000001', $signatario->evidencia_signer['key']);
    }

    public function test_evento_sign_casa_por_email_quando_chave_esta_vazia_e_preenche_a_chave(): void
    {
        $contrato   = ContratoAssinatura::factory()->create();
        $signatario = ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'email'                  => 'Fulano@Example.com',
            'clicksign_signer_key'   => null,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        $eventos = [
            $this->eventoSign('00000000-0000-4000-8000-000000000002', 'fulano@example.com', 'Fulano de Tal', '2026-08-13T10:27:02.000-03:00'),
        ];

        $resultado = (new ContratoSignatariosSyncService())->aplicar($contrato, $eventos);

        $this->assertSame(1, $resultado['assinaram']);

        $signatario->refresh();
        $this->assertSame(ContratoAssinaturaSignatario::SITUACAO_ASSINOU, $signatario->situacao);
        $this->assertSame('00000000-0000-4000-8000-000000000002', $signatario->clicksign_signer_key);
    }

    public function test_aplicar_mesma_lista_duas_vezes_produz_o_mesmo_estado(): void
    {
        $contrato = ContratoAssinatura::factory()->create();
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'email'                  => 'fulano@example.com',
            'clicksign_signer_key'   => '00000000-0000-4000-8000-000000000003',
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        $eventos = [
            $this->eventoSign('00000000-0000-4000-8000-000000000003', 'fulano@example.com', 'Fulano de Tal', '2026-08-13T10:27:02.000-03:00'),
        ];

        $service     = new ContratoSignatariosSyncService();
        $resultado1  = $service->aplicar($contrato, $eventos);
        $resultado2  = $service->aplicar($contrato, $eventos);

        $this->assertSame($resultado1, $resultado2);
        $this->assertSame(1, ContratoAssinaturaSignatario::where('contrato_assinatura_id', $contrato->id)->count());
    }

    public function test_ordem_invertida_por_created_refusal_depois_de_sign_deixa_recusou_e_vice_versa(): void
    {
        $contrato   = ContratoAssinatura::factory()->create();
        $signatario = ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'email'                  => 'fulano@example.com',
            'clicksign_signer_key'   => '00000000-0000-4000-8000-000000000004',
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        // Lista chega FORA de ordem (refusal na frente), mas o `created`
        // do sign é MAIOR — a ordem que vale é a do created (D-06/D-07).
        $eventosSignVence = [
            $this->eventoRefusal('00000000-0000-4000-8000-000000000004', 'fulano@example.com', 'Fulano de Tal', '2026-08-13T10:00:00.000-03:00'),
            $this->eventoSign('00000000-0000-4000-8000-000000000004', 'fulano@example.com', 'Fulano de Tal', '2026-08-13T10:05:00.000-03:00'),
        ];

        (new ContratoSignatariosSyncService())->aplicar($contrato, $eventosSignVence);

        $signatario->refresh();
        $this->assertSame(ContratoAssinaturaSignatario::SITUACAO_ASSINOU, $signatario->situacao);

        // Agora o refusal é o MAIS recente — precisa vencer.
        $eventosRefusalVence = [
            $this->eventoSign('00000000-0000-4000-8000-000000000004', 'fulano@example.com', 'Fulano de Tal', '2026-08-13T11:00:00.000-03:00'),
            $this->eventoRefusal('00000000-0000-4000-8000-000000000004', 'fulano@example.com', 'Fulano de Tal', '2026-08-13T11:05:00.000-03:00'),
        ];

        (new ContratoSignatariosSyncService())->aplicar($contrato, $eventosRefusalVence);

        $signatario->refresh();
        $this->assertSame(ContratoAssinaturaSignatario::SITUACAO_RECUSOU, $signatario->situacao);
    }

    public function test_sign_de_signatario_desconhecido_nao_cria_linha_nova_e_aparece_em_nao_reconhecidos(): void
    {
        $contrato = ContratoAssinatura::factory()->create();
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'email'                  => 'conhecido@example.com',
            'clicksign_signer_key'   => '00000000-0000-4000-8000-000000000005',
        ]);

        $eventos = [
            $this->eventoSign('00000000-0000-4000-8000-000000000099', 'desconhecido@example.com', 'Ninguém Cadastrado', '2026-08-13T10:27:02.000-03:00'),
        ];

        $resultado = (new ContratoSignatariosSyncService())->aplicar($contrato, $eventos);

        $this->assertSame(0, $resultado['assinaram']);
        $this->assertSame(['00000000-0000-4000-8000-000000000099'], $resultado['nao_reconhecidos']);
        $this->assertSame(1, ContratoAssinaturaSignatario::where('contrato_assinatura_id', $contrato->id)->count());
    }
}
