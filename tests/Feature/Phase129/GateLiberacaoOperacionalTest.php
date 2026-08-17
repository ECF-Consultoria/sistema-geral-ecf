<?php

namespace Tests\Feature\Phase129;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Services\Contratos\GateLiberacaoOperacionalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 129 Plano 04 (Task 2) — a regra da CLICK-05, ramo a ramo. O caso
 * central é o `closed` com contratante `pendente`: o achado do Pitfall A
 * (RESEARCH) é que o evento `deadline` pode fechar o envelope com
 * assinatura PARCIAL, e o gate precisa recusar liberar nesse cenário.
 */
class GateLiberacaoOperacionalTest extends TestCase
{
    use RefreshDatabase;

    private function service(): GateLiberacaoOperacionalService
    {
        return new GateLiberacaoOperacionalService();
    }

    private function envelope(string $status): array
    {
        return [
            'id'         => 'envelope-fake',
            'type'       => 'envelopes',
            'attributes' => ['status' => $status],
        ];
    }

    #[Test]
    public function envelope_running_com_todos_assinados_nao_libera(): void
    {
        $contrato = ContratoAssinatura::factory()->comSnapshot()->create();
        ContratoAssinaturaSignatario::factory()->assinou()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
        ]);

        $resultado = $this->service()->avaliar($contrato, $this->envelope('running'));

        $this->assertFalse($resultado['liberar']);
        $this->assertSame('envelope ainda nao esta fechado', $resultado['motivo']);
        $this->assertSame('running', $resultado['status_envelope']);
    }

    #[Test]
    public function envelope_closed_com_contratante_pendente_nao_libera_fechamento_parcial(): void
    {
        $contrato = ContratoAssinatura::factory()->comSnapshot()->create();
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        $resultado = $this->service()->avaliar($contrato, $this->envelope('closed'));

        $this->assertFalse($resultado['liberar']);
        $this->assertSame('fechamento parcial: contratante ainda nao assinou', $resultado['motivo']);
        $this->assertSame(1, $resultado['contratantes_pendentes']);
    }

    #[Test]
    public function envelope_closed_com_contratante_assinado_e_contratada_pendente_libera(): void
    {
        $contrato = ContratoAssinatura::factory()->comSnapshot()->create();
        ContratoAssinaturaSignatario::factory()->assinou()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
        ]);
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATADA,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        $resultado = $this->service()->avaliar($contrato, $this->envelope('closed'));

        $this->assertTrue($resultado['liberar'], 'O lado da ECF nao pode prender o cliente');
        $this->assertSame('envelope fechado e contratante assinou', $resultado['motivo']);
    }

    #[Test]
    public function envelope_closed_com_signatario_recusado_nao_libera(): void
    {
        $contrato = ContratoAssinatura::factory()->comSnapshot()->create();
        ContratoAssinaturaSignatario::factory()->recusou()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
        ]);

        $resultado = $this->service()->avaliar($contrato, $this->envelope('closed'));

        $this->assertFalse($resultado['liberar']);
        $this->assertTrue($resultado['houve_recusa']);
        $this->assertSame('algum signatario recusou assinar', $resultado['motivo']);
    }

    #[Test]
    public function contrato_sem_nenhum_contratante_nao_libera_fail_closed(): void
    {
        $contrato = ContratoAssinatura::factory()->comSnapshot()->create();
        ContratoAssinaturaSignatario::factory()->assinou()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATADA,
        ]);

        $resultado = $this->service()->avaliar($contrato, $this->envelope('closed'));

        $this->assertFalse($resultado['liberar']);
        $this->assertSame('contrato sem signatario contratante registrado', $resultado['motivo']);
    }

    #[Test]
    public function envelope_closed_com_todos_assinados_libera(): void
    {
        $contrato = ContratoAssinatura::factory()->comSnapshot()->create();
        ContratoAssinaturaSignatario::factory()->assinou()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
        ]);
        ContratoAssinaturaSignatario::factory()->assinou()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATADA,
        ]);

        $resultado = $this->service()->avaliar($contrato, $this->envelope('closed'));

        $this->assertTrue($resultado['liberar']);
        $this->assertSame('envelope fechado e contratante assinou', $resultado['motivo']);
        $this->assertSame(0, $resultado['contratantes_pendentes']);
        $this->assertFalse($resultado['houve_recusa']);
    }
}
