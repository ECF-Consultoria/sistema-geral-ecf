<?php

namespace Tests\Feature\Phase135;

use App\Models\ContratoServico;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 135 — Wave 0 (135-01).
 *
 * Primeira suite da fase: prova que o diretorio tests/Feature/Phase135
 * funciona e que a ContratoServicoFactory nova (com HasFactory) persiste
 * um contrato de servico valido — pre-requisito dos testes do Observer
 * (Plano 04), que vao precisar criar contrato do servico "Gestao".
 */
class OnboardingWave0Test extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function factory_persiste_contrato_ativo_sem_vencimento(): void
    {
        $contrato = ContratoServico::factory()->create();

        $this->assertDatabaseHas('contratos_servico', [
            'id'   => $contrato->id,
            'ativo' => true,
        ]);
        $this->assertTrue($contrato->fresh()->ativo);
        $this->assertNull($contrato->fresh()->data_vencimento);
    }

    /** @test */
    public function state_para_servico_fixa_o_servico_pedido(): void
    {
        $servico = Servico::create([
            'nome'          => 'Gestao',
            'valor_padrao'  => 3000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        $contrato = ContratoServico::factory()->paraServico($servico)->create();

        $this->assertSame($servico->id, $contrato->servico_id);
    }
}
