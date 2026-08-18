<?php

namespace Tests\Feature\Phase132;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\Servico;
use App\Services\Clicksign\CongelamentoEmissaoService;
use App\Services\Contratos\GatilhoContratoAdministrativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * O interruptor de emissão (D-07) precisa valer para o SISTEMA, não só para a
 * tela do Administrativo.
 *
 * Medido em produção em 2026-08-18: com o interruptor LIGADO, criar empresa e
 * vincular serviço fez nascer contrato e envelope no mesmo segundo, porque o
 * caminho dos observers da Fase 128 não passava pela checagem — que existia só
 * em `ContratoAdminController::gerarContrato()`.
 */
class GatilhoRespeitaInterruptorTest extends TestCase
{
    use RefreshDatabase;

    public function test_com_o_interruptor_ligado_o_gatilho_automatico_recusa_e_nada_nasce(): void
    {
        Bus::fake();
        app(CongelamentoEmissaoService::class)->ligar();

        $company = Company::factory()->create();
        $antes   = ContratoAssinatura::count();

        $resultado = app(GatilhoContratoAdministrativoService::class)->dispararSeElegivel($company);

        $this->assertSame('emissao_congelada', $resultado['status']);
        $this->assertSame($antes, ContratoAssinatura::count(), 'nenhum contrato pode nascer com a emissão congelada');
        Bus::assertNothingDispatched();
    }

    public function test_a_recusa_acontece_antes_de_qualquer_avaliacao(): void
    {
        app(CongelamentoEmissaoService::class)->ligar();

        // Empresa sem nada preenchido: se a avaliação rodasse, o status seria
        // "não elegível" por pendência — e não "emissao_congelada".
        $resultado = app(GatilhoContratoAdministrativoService::class)
            ->dispararSeElegivel(Company::factory()->create());

        $this->assertSame(
            'emissao_congelada',
            $resultado['status'],
            'o interruptor precisa cortar ANTES da avaliação, sem I/O nenhum'
        );
    }

    public function test_desligado_o_gatilho_volta_a_avaliar_normalmente(): void
    {
        app(CongelamentoEmissaoService::class)->desligar();

        $resultado = app(GatilhoContratoAdministrativoService::class)
            ->dispararSeElegivel(Company::factory()->create());

        $this->assertNotSame(
            'emissao_congelada',
            $resultado['status'],
            'com o interruptor desligado nada muda no comportamento de hoje'
        );
    }
}
