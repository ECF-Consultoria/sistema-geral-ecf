<?php

namespace Tests\Feature\Phase130;

use App\Models\ContratoAssinatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 130 Plano 01 (Task 2) — prova a fundação do carimbo de último alerta
 * por contrato (D-04): a coluna existe, é mass-assignable e volta do banco
 * como data — o cooldown do plano 130-05 tem onde gravar.
 */
class FundacaoContratoAssinaturaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function coluna_ultimo_alerta_em_existe_na_tabela(): void
    {
        $this->assertTrue(Schema::hasColumn('contrato_assinaturas', 'ultimo_alerta_em'));
    }

    #[Test]
    public function contrato_criado_pela_factory_nasce_com_ultimo_alerta_em_nulo(): void
    {
        $contrato = ContratoAssinatura::factory()->create();

        $this->assertNull($contrato->ultimo_alerta_em);
    }

    #[Test]
    public function atualizar_ultimo_alerta_em_persiste_e_volta_como_data(): void
    {
        $contrato = ContratoAssinatura::factory()->create();

        $contrato->update(['ultimo_alerta_em' => now()]);

        $fresco = $contrato->fresh();

        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $fresco->ultimo_alerta_em);
    }
}
