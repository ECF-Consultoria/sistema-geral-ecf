<?php

namespace Tests\Feature\Phase131;

use App\Models\ContratoAssinatura;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 131 Plano 05 (CLICK-10/D-13) — ContratoAdminController::registrarCancelamento().
 *
 * O teste corrigido pela `131-VALIDATION.md`: prova o REGISTRO (autor +
 * motivo + data) e a AUSÊNCIA de chamada ao `ClicksignClient` — nunca o
 * cancelamento em si, que a API v3 não permite (medido 2026-08-14).
 *
 * Nasceu na Task 1 com o caminho feliz e é COMPLETADO na Task 3 com os
 * guards (sem chamada à API, motivo curto, registro duplicado, contrato
 * terminal) no MESMO arquivo.
 */
class ContratoAdminCancelarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Caminho feliz: grava as 3 colunas, conferido por RECONSULTA AO BANCO
     * (`fresh()`) — nunca pela mensagem de sucesso. `status` continua
     * INALTERADO (quem fecha o estado é o webhook `cancel`, Fase 129).
     * Nenhuma requisição HTTP sai desta action.
     */
    public function test_registrar_cancelamento_grava_autor_motivo_e_data_sem_chamar_a_api(): void
    {
        $admin    = $this->admin();
        $contrato = ContratoAssinatura::factory()->emAndamento()->create();

        Http::fake();

        $response = $this->actingAs($admin)->post(
            route('admin.contratos.cancelamento', $contrato),
            ['motivo' => 'Cliente pediu para cancelar por e-mail errado no cadastro.'],
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Reconsulta ao banco — nunca confia na mensagem de sucesso.
        $contratoFresco = $contrato->fresh();
        $this->assertSame('Cliente pediu para cancelar por e-mail errado no cadastro.', $contratoFresco->cancelamento_motivo);
        $this->assertSame($admin->id, $contratoFresco->cancelamento_solicitado_por_user_id);
        $this->assertNotNull($contratoFresco->cancelamento_solicitado_em);

        // status INALTERADO — quem muda é o webhook.
        $this->assertSame(ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, $contratoFresco->status);

        Http::assertNothingSent();
    }
}
