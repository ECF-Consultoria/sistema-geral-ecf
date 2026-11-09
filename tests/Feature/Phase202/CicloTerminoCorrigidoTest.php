<?php

namespace Tests\Feature\Phase202;

use App\Models\Company;
use App\Models\ContratoClicksignAssinado;
use App\Models\ContratoRenovacao;
use App\Models\ContratoRenovacaoEvento;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Contratos\TerminoContratoService;
use App\Services\Renovacao\RenovacaoDetector;
use App\Services\Renovacao\RenovacaoExtensaoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 202-08 (T3) — ciclos de renovação já criados quando o término muda
 * porque o contrato assinado foi lido. Corrigir data NÃO cria ciclo novo (REN-02).
 */
class CicloTerminoCorrigidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Ab4-like: P4M desde 03/08 → término calculado 03/12 (na janela). */
    private function cenario(): array
    {
        $c = Company::factory()->create(['active' => true]);
        $s = Servico::create([
            'nome' => 'Gestao', 'valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo' => true, 'setor' => Servico::SETOR_PERFORMANCE, 'exige_contrato' => true,
        ]);
        $cs = ContratoServico::factory()->create([
            'company_id' => $c->id, 'servico_id' => $s->id,
            'data_contratacao' => '2026-08-03', 'hubspot_billing_period' => 'P4M',
        ]);

        app(TerminoContratoService::class)->preencherEmpresa($c);
        $this->assertSame('2026-12-03', $cs->fresh()->data_vencimento->toDateString());

        app(RenovacaoDetector::class)->detectar();
        $ciclo = ContratoRenovacao::sole();
        $this->assertSame('2026-12-03', $ciclo->termino_capturado->toDateString());

        return [$c, $s, $cs, $ciclo];
    }

    private function lerContrato(Company $c, int $meses = 12, string $assinado = '2026-07-30 09:16:01'): void
    {
        ContratoClicksignAssinado::create([
            'envelope_id' => 'env-'.$c->id.'-'.$meses, 'envelope_nome' => 'Contrato — Brigada — X',
            'tipo' => 'gestao', 'company_id' => $c->id, 'confianca' => 'certo',
            'assinado_em' => $assinado, 'vigencia_meses' => $meses, 'vigencia_fonte' => 'texto',
            'situacao' => 'casado',
        ]);
        app(TerminoContratoService::class)->preencherEmpresa($c);
    }

    public function test_ciclo_sem_negocio_que_sai_da_janela_e_dispensado_com_evento(): void
    {
        [$c, , $cs, $ciclo] = $this->cenario();

        $this->lerContrato($c);
        $this->assertSame('2027-07-30', $cs->fresh()->data_vencimento->toDateString());

        app(RenovacaoDetector::class)->detectar();

        $ciclo->refresh();
        $this->assertSame(1, ContratoRenovacao::count(), 'corrigir data não cria ciclo novo');
        $this->assertSame('dispensado', $ciclo->status_comercial);
        $this->assertSame('2027-07-30', $ciclo->termino_capturado->toDateString());
        $this->assertStringContainsString('30/07/2027', $ciclo->dispensa_justificativa);
        $this->assertStringContainsString('contrato assinado', $ciclo->dispensa_justificativa);
        $this->assertNull($ciclo->dispensado_por);

        $evento = ContratoRenovacaoEvento::where('tipo', 'termino_corrigido')->sole();
        $this->assertSame('2026-12-03', $evento->dados['termino_antes']);
        $this->assertSame('2027-07-30', $evento->dados['termino_depois']);
        $this->assertSame('dispensado', $evento->dados['acao']);

        // Idempotente: rodar de novo não mexe.
        app(RenovacaoDetector::class)->detectar();
        $this->assertSame(1, ContratoRenovacao::count());
        $this->assertSame(1, ContratoRenovacaoEvento::where('tipo', 'termino_corrigido')->count());

        // Quando o término novo entra na janela, abre o ciclo seguinte pela chave nova.
        Carbon::setTestNow('2027-06-01');
        app(RenovacaoDetector::class)->detectar();

        $this->assertSame(2, ContratoRenovacao::count());
        $novo = ContratoRenovacao::where('numero_ciclo', 2)->sole();
        $this->assertSame('2027-07-30', $novo->termino_capturado->toDateString());
        $this->assertSame("ren:{$c->id}:{$cs->servico_id}:2", $novo->chave_ciclo);
        $this->assertSame(12, $novo->vigencia_meses, 'vigência do contrato, não o P4M do HubSpot');
    }

    public function test_ciclo_sem_negocio_que_continua_na_janela_so_atualiza_o_termino(): void
    {
        [$c, , $cs, $ciclo] = $this->cenario();

        // Contrato de 4 meses assinado em 15/08 → 15/12, ainda na janela.
        $this->lerContrato($c, 4, '2026-08-15 10:00:00');
        app(RenovacaoDetector::class)->detectar();

        $ciclo->refresh();
        $this->assertSame('identificado', $ciclo->status_comercial);
        $this->assertSame('2026-12-15', $ciclo->termino_capturado->toDateString());
        $this->assertSame(1, ContratoRenovacaoEvento::where('tipo', 'termino_corrigido')->count());
    }

    public function test_ciclo_com_negocio_criado_vai_para_revisao_manual(): void
    {
        [$c, , , $ciclo] = $this->cenario();
        $ciclo->update(['negocio_renovacao_id' => 'D-REN-9']);

        $this->lerContrato($c);
        app(RenovacaoDetector::class)->detectar();

        $ciclo->refresh();
        $this->assertSame('identificado', $ciclo->status_comercial, 'nunca dispensa ciclo com negócio');
        $this->assertSame('revisao_manual', $ciclo->status_integracao);
        $this->assertSame('D-REN-9', $ciclo->negocio_renovacao_id);
        $this->assertSame('2027-07-30', $ciclo->termino_capturado->toDateString());
        $this->assertStringContainsString('corrigido', $ciclo->ultimo_erro);

        $evento = ContratoRenovacaoEvento::where('tipo', 'termino_corrigido')->sole();
        $this->assertSame('termino_corrigido', $evento->dados['motivo']);
    }

    public function test_ciclo_com_tentativa_remota_vai_para_revisao_manual(): void
    {
        [$c, , , $ciclo] = $this->cenario();
        $ciclo->update(['criacao_iniciada_em' => now(), 'status_integracao' => 'resultado_incerto']);

        $this->lerContrato($c);
        app(RenovacaoDetector::class)->detectar();

        $ciclo->refresh();
        $this->assertNotSame('dispensado', $ciclo->status_comercial);
        $this->assertSame('revisao_manual', $ciclo->status_integracao);
    }

    public function test_dispensa_humana_nunca_reabre_sozinha(): void
    {
        [$c, , , $ciclo] = $this->cenario();
        $ciclo->update([
            'status_comercial' => 'dispensado', 'dispensado_por' => \App\Models\User::factory()->create()->id,
            'dispensado_em' => now(), 'dispensa_justificativa' => 'Cliente avisou que não renova.',
        ]);

        $this->lerContrato($c);
        Carbon::setTestNow('2027-06-01');
        app(RenovacaoDetector::class)->detectar();

        $this->assertSame(1, ContratoRenovacao::count());
    }

    public function test_extensao_da_renovacao_usa_o_prazo_do_contrato(): void
    {
        [$c, , $cs, $ciclo] = $this->cenario();
        $ciclo->update(['negocio_renovacao_id' => 'D-REN-1']);
        $this->lerContrato($c, 12, '2025-12-03 10:00:00'); // término 03/12/2026, igual ao capturado

        app(RenovacaoExtensaoService::class)->registrarGanho($ciclo->fresh(), 'webhook');

        $this->assertSame('2027-12-03', $cs->fresh()->data_vencimento->toDateString(), '12 meses do contrato, não P4M');
        $this->assertSame('renovacao', $cs->fresh()->data_vencimento_origem);
    }
}
