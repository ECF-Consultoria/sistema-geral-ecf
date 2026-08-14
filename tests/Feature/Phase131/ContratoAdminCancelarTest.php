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

    // ─── Task 3 — guards ─────────────────────────────────────────────────

    /**
     * Prova isolada, dedicada só à ausência de chamada — mesmo com
     * `Http::fake()` sem NENHUMA rota registrada (qualquer request real
     * quebraria o teste), registrar cancelamento não dispara nada.
     */
    public function test_registrar_cancelamento_nunca_dispara_nenhuma_requisicao_http(): void
    {
        $contrato = ContratoAssinatura::factory()->emAndamento()->create();

        Http::fake();

        $this->actingAs($this->admin())->post(
            route('admin.contratos.cancelamento', $contrato),
            ['motivo' => 'Motivo válido, com mais de dez caracteres.'],
        );

        Http::assertNothingSent();
    }

    /**
     * Motivo com menos de 10 caracteres falha a validação e NADA é gravado
     * — conferido por reconsulta ao banco, não pela mensagem de erro.
     */
    public function test_registrar_cancelamento_com_motivo_curto_falha_validacao_e_nao_grava_nada(): void
    {
        $contrato = ContratoAssinatura::factory()->emAndamento()->create();

        Http::fake();

        $response = $this->actingAs($this->admin())->post(
            route('admin.contratos.cancelamento', $contrato),
            ['motivo' => 'curto'],
        );

        $response->assertSessionHasErrors('motivo');

        $contratoFresco = $contrato->fresh();
        $this->assertNull($contratoFresco->cancelamento_motivo);
        $this->assertNull($contratoFresco->cancelamento_solicitado_por_user_id);
        $this->assertNull($contratoFresco->cancelamento_solicitado_em);

        Http::assertNothingSent();
    }

    /**
     * Segundo registro no mesmo contrato devolve 422 — o histórico do
     * primeiro pedido não pode ser sobrescrito (T-131-05-06).
     */
    public function test_registrar_cancelamento_pela_segunda_vez_devolve_422(): void
    {
        $admin    = $this->admin();
        $contrato = ContratoAssinatura::factory()->emAndamento()->create([
            'cancelamento_motivo'                 => 'Motivo do primeiro pedido de cancelamento.',
            'cancelamento_solicitado_por_user_id'  => $admin->id,
            'cancelamento_solicitado_em'           => now()->subDay(),
        ]);

        Http::fake();

        $response = $this->actingAs($admin)->post(
            route('admin.contratos.cancelamento', $contrato),
            ['motivo' => 'Um segundo motivo, diferente do primeiro pedido.'],
        );

        $response->assertStatus(422);

        // Reconsulta — o motivo original não foi sobrescrito.
        $this->assertSame('Motivo do primeiro pedido de cancelamento.', $contrato->fresh()->cancelamento_motivo);

        Http::assertNothingSent();
    }

    /**
     * Contrato em estado terminal (`assinado`) não aceita registro de
     * cancelamento — só faz sentido para contrato vivo.
     */
    public function test_registrar_cancelamento_em_contrato_assinado_devolve_422_e_nao_grava_nada(): void
    {
        $contrato = ContratoAssinatura::factory()->assinado()->create();

        Http::fake();

        $response = $this->actingAs($this->admin())->post(
            route('admin.contratos.cancelamento', $contrato),
            ['motivo' => 'Tentando cancelar um contrato já assinado.'],
        );

        $response->assertStatus(422);

        $contratoFresco = $contrato->fresh();
        $this->assertNull($contratoFresco->cancelamento_motivo);
        $this->assertNull($contratoFresco->cancelamento_solicitado_em);

        Http::assertNothingSent();
    }
}
