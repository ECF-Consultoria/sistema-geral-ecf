<?php

namespace Tests\Feature\Phase131;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 131 Plano 05 (CLICK-07) — ContratoAdminController::reenviar().
 *
 * Nasceu na Task 1 com o núcleo decisivo (corpo JSON:API enviado + o 429
 * como resposta ESPERADA) e é COMPLETADO na Task 3 com os guards (IDOR,
 * estado errado, pessoa já respondeu) no MESMO arquivo — regra do "teste
 * nasce na mesma task do código que ele prova" (armadilha do `--filter`
 * sem match que sai 0 e varre a suíte).
 */
class ContratoAdminReenviarTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://sandbox.clicksign.com/api/v3';

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Contrato em `aguardando_assinaturas` com envelope + signatário pendente
     * — o único estado em que o UI-SPEC oferece "Reenviar aviso".
     */
    private function contratoComSignatarioPendente(): array
    {
        $contrato = ContratoAssinatura::factory()->emAndamento()->create([
            'clicksign_envelope_id' => '00000000-0000-4000-8000-000000000001',
        ]);

        $signatario = ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => '00000000-0000-4000-8000-000000000003',
        ]);

        return [$contrato, $signatario];
    }

    /**
     * O padrão que impede teste inócuo (quick 260814-d9s): assertar só o
     * status seria insuficiente — foi exatamente o buraco que deixou o POST
     * sem corpo chegar em produção. Aqui o CORPO é conferido: `data.type`
     * precisa ser `notifications` e a chave `attributes` precisa existir.
     */
    public function test_reenviar_envia_corpo_jsonapi_com_data_type_notifications(): void
    {
        [$contrato, $signatario] = $this->contratoComSignatarioPendente();

        Http::fake([
            self::BASE_URL . '/envelopes/*/signers/*/notifications' => Http::response([
                'data' => [
                    'id'         => '00000000-0000-4000-8000-000000000009',
                    'type'       => 'notifications',
                    'attributes' => ['summary' => [['signer_id' => $signatario->clicksign_signer_key, 'notified' => true]]],
                ],
            ], 201),
        ]);

        $response = $this->actingAs($this->admin())->post(
            route('admin.contratos.reenviar', [$contrato, $signatario]),
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Http::assertSent(function ($request) {
            $data = $request['data'] ?? null;

            return $data !== null
                && ($data['type'] ?? null) === 'notifications'
                && array_key_exists('attributes', $data);
        });
    }

    /**
     * O 429 deste endpoint é resposta ESPERADA (rate limit anti-spam próprio
     * da Clicksign, texto puro) — nunca erro do sistema, nunca 500. O
     * controller não retenta sozinho: uma tentativa por clique.
     */
    public function test_reenviar_com_429_em_texto_puro_devolve_aviso_nunca_error_e_nunca_500(): void
    {
        [$contrato, $signatario] = $this->contratoComSignatarioPendente();

        Http::fake([
            self::BASE_URL . '/envelopes/*/signers/*/notifications' => Http::response('Too many requests', 429),
        ]);

        $response = $this->actingAs($this->admin())->post(
            route('admin.contratos.reenviar', [$contrato, $signatario]),
        );

        $response->assertRedirect();
        $response->assertSessionHas('aviso');
        $response->assertSessionMissing('error');

        Http::assertSentCount(1);
    }
}
