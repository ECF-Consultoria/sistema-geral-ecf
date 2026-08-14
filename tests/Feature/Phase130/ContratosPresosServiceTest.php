<?php

namespace Tests\Feature\Phase130;

use App\Models\Configuracao;
use App\Models\ContratoAssinatura;
use App\Services\Contratos\ContratosPresosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 130 Plano 02 (D-03, D-05) — ContratosPresosService.
 *
 * Toda asserção de consolidação reconsulta o banco/serviço fresco (nunca
 * stdout) — disciplina já registrada como aprendizado do projeto.
 */
class ContratosPresosServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ContratosPresosService
    {
        return new ContratosPresosService();
    }

    public function test_aguardando_assinaturas_com_enviado_dez_dias_atras_aparece_em_listar(): void
    {
        $contrato = ContratoAssinatura::factory()->emAndamento()->create([
            'enviado_em' => now()->subDays(10),
        ]);

        $ids = $this->service()->listar()->pluck('id')->all();

        $this->assertContains($contrato->id, $ids);
    }

    public function test_aguardando_assinaturas_com_enviado_um_dia_atras_nao_aparece(): void
    {
        $contrato = ContratoAssinatura::factory()->emAndamento()->create([
            'enviado_em' => now()->subDay(),
        ]);

        $ids = $this->service()->listar()->pluck('id')->all();

        $this->assertNotContains($contrato->id, $ids);
    }

    public function test_contrato_com_liberado_em_preenchido_nunca_aparece_mesmo_velho(): void
    {
        $contrato = ContratoAssinatura::factory()->assinado()->create([
            'assinado_em' => now()->subDays(30),
            'liberado_em' => now()->subDay(),
        ]);

        $ids = $this->service()->listar()->pluck('id')->all();

        $this->assertNotContains($contrato->id, $ids);
    }

    public function test_gatilho_o_que_vier_primeiro_fracao_vence_para_prazo_curto(): void
    {
        Configuracao::set(ContratosPresosService::CHAVE_DIAS_FIXO, 20);
        Configuracao::set(ContratosPresosService::CHAVE_FRACAO_PRAZO, 0.5);

        // prazo_dias = 10 → fração = 5 dias, fixo = 20 dias → limiar = 5 (fração vence).
        $contratoNoLimiar = ContratoAssinatura::factory()->emAndamento()->create([
            'prazo_dias' => 10,
            'enviado_em' => now()->subDays(5),
        ]);
        $contratoAntesDoLimiar = ContratoAssinatura::factory()->emAndamento()->create([
            'prazo_dias' => 10,
            'enviado_em' => now()->subDays(4),
        ]);

        $service = $this->service();

        $this->assertSame(5, $service->limiarDias($contratoNoLimiar->fresh()));
        $this->assertTrue($service->estaPreso($contratoNoLimiar->fresh()));
        $this->assertFalse($service->estaPreso($contratoAntesDoLimiar->fresh()));
    }

    public function test_gatilho_o_que_vier_primeiro_fixo_vence_para_prazo_longo(): void
    {
        Configuracao::set(ContratosPresosService::CHAVE_DIAS_FIXO, 20);
        Configuracao::set(ContratosPresosService::CHAVE_FRACAO_PRAZO, 0.5);

        // prazo_dias = 100 → fração = 50 dias, fixo = 20 dias → limiar = 20 (fixo vence).
        $contratoNoLimiar = ContratoAssinatura::factory()->emAndamento()->create([
            'prazo_dias' => 100,
            'enviado_em' => now()->subDays(20),
        ]);
        $contratoAntesDoLimiar = ContratoAssinatura::factory()->emAndamento()->create([
            'prazo_dias' => 100,
            'enviado_em' => now()->subDays(19),
        ]);

        $service = $this->service();

        $this->assertSame(20, $service->limiarDias($contratoNoLimiar->fresh()));
        $this->assertTrue($service->estaPreso($contratoNoLimiar->fresh()));
        $this->assertFalse($service->estaPreso($contratoAntesDoLimiar->fresh()));
    }

    public function test_causa_devolve_a_constante_certa_por_estado(): void
    {
        $service = $this->service();

        $rascunho = ContratoAssinatura::factory()->create(['status' => ContratoAssinatura::STATUS_RASCUNHO]);
        $recusado = ContratoAssinatura::factory()->create(['status' => ContratoAssinatura::STATUS_RECUSADO]);
        $expirado = ContratoAssinatura::factory()->create(['status' => ContratoAssinatura::STATUS_EXPIRADO]);
        $erro     = ContratoAssinatura::factory()->create(['status' => ContratoAssinatura::STATUS_ERRO]);
        $assinado = ContratoAssinatura::factory()->assinado()->create();

        $this->assertSame(ContratosPresosService::CAUSA_RASCUNHO_PARADO, $service->causa($rascunho));
        $this->assertSame(ContratosPresosService::CAUSA_RECUSADO, $service->causa($recusado));
        $this->assertSame(ContratosPresosService::CAUSA_EXPIRADO, $service->causa($expirado));
        $this->assertSame(ContratosPresosService::CAUSA_ERRO, $service->causa($erro));
        $this->assertSame(ContratosPresosService::CAUSA_ASSINADO_SEM_LIBERACAO, $service->causa($assinado));
    }

    public function test_assinado_sem_liberacao_e_assinado_em_velho_aparece_com_causa_certa(): void
    {
        $contrato = ContratoAssinatura::factory()->assinado()->create([
            'assinado_em' => now()->subDays(10),
        ]);

        $service = $this->service();
        $ids     = $service->listar()->pluck('id')->all();

        $this->assertContains($contrato->id, $ids);
        $this->assertSame(ContratosPresosService::CAUSA_ASSINADO_SEM_LIBERACAO, $service->causa($contrato));
    }

    public function test_limiar_dias_nunca_devolve_valor_menor_que_um(): void
    {
        Configuracao::set(ContratosPresosService::CHAVE_DIAS_FIXO, 0);
        Configuracao::set(ContratosPresosService::CHAVE_FRACAO_PRAZO, 0);

        $contrato = ContratoAssinatura::factory()->emAndamento()->create([
            'prazo_dias' => 1,
        ]);

        $this->assertSame(1, $this->service()->limiarDias($contrato));
    }

    /**
     * Regressão do bug do relógio (260814-cro): dataBase() de um estado
     * "default" (recusado/expirado/cancelado/erro) usava `updated_at`, e o
     * próprio alerta (que grava `ultimo_alerta_em` via `update()`) bumpava
     * esse `updated_at` — zerando o contador de dias parado no instante em
     * que o contrato era alertado. Qualquer escrita no contrato (não só o
     * alerta) tem o mesmo efeito.
     */
    public function test_bump_de_updated_at_nao_zera_o_relogio_de_contrato_recusado(): void
    {
        $contrato = ContratoAssinatura::factory()->create([
            'status'     => ContratoAssinatura::STATUS_RECUSADO,
            'enviado_em' => now()->subDays(10),
        ]);

        $service = $this->service();

        $this->assertTrue($service->estaPreso($contrato->fresh()));

        // Qualquer escrita no contrato — aqui simulando a gravação do
        // cooldown do alerta — bumpa `updated_at` via Eloquent.
        $contrato->update(['ultimo_alerta_em' => now()]);

        $fresco = ContratoAssinatura::find($contrato->id);

        $this->assertTrue($service->estaPreso($fresco), 'O contrato tem que continuar preso depois da escrita.');
        $this->assertGreaterThanOrEqual(10, $service->diasParado($fresco));
    }

    /**
     * Estado sem `enviado_em` (ex.: erro antes do envio) conta desde
     * `created_at` — nunca desde `updated_at`.
     */
    public function test_estado_sem_enviado_em_conta_desde_created_at(): void
    {
        $contrato = ContratoAssinatura::factory()->create([
            'status'     => ContratoAssinatura::STATUS_ERRO,
            'enviado_em' => null,
        ]);
        $contrato->forceFill(['created_at' => now()->subDays(10)])->save();

        $service = $this->service();
        $fresco  = ContratoAssinatura::find($contrato->id);

        $this->assertGreaterThanOrEqual(10, $service->diasParado($fresco));
        $this->assertTrue($service->estaPreso($fresco));
    }
}
