<?php

namespace Tests\Unit\Phase137;

use App\Models\Company;
use App\Services\FluxoEntrada\EtapaTransicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 137 (plano 03) — prova da régua pura `podeTransicionar()` (ETAPA-06)
 * nesta task; `transicionar()`/`carimbarBackfill()` (ETAPA-03) são
 * estendidos na Task 3 do mesmo plano.
 */
class EtapaTransicaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private EtapaTransicaoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EtapaTransicaoService();
    }

    private function empresaNaEtapa(?string $etapa): Company
    {
        return Company::factory()->create(['etapa' => $etapa]);
    }

    public function test_destino_inexistente_e_recusado_com_requisito_nomeado(): void
    {
        $empresa = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO);

        $resultado = $this->service->podeTransicionar($empresa, 'etapa_que_nao_existe');

        $this->assertFalse($resultado['permitido']);
        $this->assertStringContainsString('etapa_que_nao_existe', $resultado['requisito_faltante']);
    }

    public function test_destino_igual_ao_atual_e_recusado(): void
    {
        $empresa = $this->empresaNaEtapa(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO);

        $resultado = $this->service->podeTransicionar($empresa, Company::ETAPA_ADMINISTRATIVO_ANDAMENTO);

        $this->assertFalse($resultado['permitido']);
        $this->assertNotNull($resultado['requisito_faltante']);
    }

    public function test_salto_1_para_9_e_recusado_com_lista_de_destinos_aceitos(): void
    {
        $empresa = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO);

        $resultado = $this->service->podeTransicionar($empresa, Company::ETAPA_EM_OPERACAO);

        $this->assertFalse($resultado['permitido']);
        $this->assertStringContainsString(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $resultado['requisito_faltante']);
        $this->assertStringContainsString(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $resultado['requisito_faltante']);
    }

    public function test_salto_2_para_4_e_permitido(): void
    {
        $empresa = $this->empresaNaEtapa(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO);

        $resultado = $this->service->podeTransicionar($empresa, Company::ETAPA_ADMINISTRATIVO_CONCLUIDO);

        $this->assertTrue($resultado['permitido']);
        $this->assertFalse($resultado['retrocesso']);
    }

    public function test_null_para_etapa_1_e_permitido(): void
    {
        $empresa = $this->empresaNaEtapa(null);

        $resultado = $this->service->podeTransicionar($empresa, Company::ETAPA_AGUARDANDO_ADMINISTRATIVO);

        $this->assertTrue($resultado['permitido']);
        $this->assertFalse($resultado['retrocesso']);
    }

    public function test_null_para_etapa_5_e_recusado(): void
    {
        $empresa = $this->empresaNaEtapa(null);

        $resultado = $this->service->podeTransicionar($empresa, Company::ETAPA_AGUARDANDO_DISTRIBUICAO);

        $this->assertFalse($resultado['permitido']);
    }

    public function test_retrocesso_5_para_2_e_permitido_e_marcado_como_retrocesso(): void
    {
        $empresa = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_DISTRIBUICAO);

        $resultado = $this->service->podeTransicionar($empresa, Company::ETAPA_ADMINISTRATIVO_ANDAMENTO);

        $this->assertTrue($resultado['permitido']);
        $this->assertTrue($resultado['retrocesso']);
    }
}
