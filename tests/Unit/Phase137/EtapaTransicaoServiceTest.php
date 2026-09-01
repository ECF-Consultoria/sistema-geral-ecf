<?php

namespace Tests\Unit\Phase137;

use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\User;
use App\Services\FluxoEntrada\EtapaTransicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 137 (plano 03) — prova da régua pura `podeTransicionar()` (ETAPA-06,
 * Task 2) e do único ponto de escrita `transicionar()`/`carimbarBackfill()`
 * (ETAPA-03, Task 3).
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

    // ─── Task 3: transicionar() — o único ponto de escrita ───

    public function test_transicao_valida_grava_etapa_e_cria_uma_linha_de_historico_com_o_ator(): void
    {
        $empresa = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO);
        $user = User::factory()->create();

        $resultado = $this->service->transicionar($empresa, Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $user);

        $this->assertSame('transicionado', $resultado['status']);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $empresa->fresh()->etapa);

        $this->assertSame(1, CompanyEtapaTransicao::where('company_id', $empresa->id)->count());

        $linha = CompanyEtapaTransicao::where('company_id', $empresa->id)->first();
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $linha->etapa_anterior);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $linha->etapa_nova);
        $this->assertSame($user->id, $linha->user_id);
        $this->assertFalse($linha->retrocesso);
    }

    public function test_transicao_recusada_nao_grava_etapa_nem_cria_historico(): void
    {
        $empresa = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO);
        $user = User::factory()->create();

        $resultado = $this->service->transicionar($empresa, Company::ETAPA_EM_OPERACAO, $user);

        $this->assertSame('recusado', $resultado['status']);
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $empresa->fresh()->etapa);
        $this->assertSame(0, CompanyEtapaTransicao::where('company_id', $empresa->id)->count());
    }

    public function test_retrocesso_sem_motivo_e_recusado(): void
    {
        $empresa = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_DISTRIBUICAO);
        $user = User::factory()->create();

        $resultado = $this->service->transicionar($empresa, Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $user, null);

        $this->assertSame('recusado', $resultado['status']);
        $this->assertSame(Company::ETAPA_AGUARDANDO_DISTRIBUICAO, $empresa->fresh()->etapa);
        $this->assertSame(0, CompanyEtapaTransicao::where('company_id', $empresa->id)->count());
    }

    public function test_retrocesso_com_motivo_grava_com_retrocesso_true_e_motivo_persistido(): void
    {
        $empresa = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_DISTRIBUICAO);
        $user = User::factory()->create();

        $resultado = $this->service->transicionar($empresa, Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $user, 'erro de clique');

        $this->assertSame('transicionado', $resultado['status']);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $empresa->fresh()->etapa);

        $linha = CompanyEtapaTransicao::where('company_id', $empresa->id)->first();
        $this->assertTrue($linha->retrocesso);
        $this->assertSame('erro de clique', $linha->motivo);
    }

    public function test_carimbar_backfill_grava_etapa_9_sem_criar_historico(): void
    {
        $empresa = $this->empresaNaEtapa(null);

        $afetadas = $this->service->carimbarBackfill([$empresa->id]);

        $this->assertSame(1, $afetadas);
        $this->assertSame(Company::ETAPA_EM_OPERACAO, $empresa->fresh()->etapa);
        $this->assertSame(0, CompanyEtapaTransicao::where('company_id', $empresa->id)->count());
    }
}
