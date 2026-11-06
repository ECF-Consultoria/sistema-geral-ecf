<?php

namespace Tests\Feature\Phase202;

use App\Models\Company;
use App\Models\ContratoRenovacao;
use App\Models\ContratoRenovacaoEvento;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Renovacao\RenovacaoDetector;
use App\Services\Renovacao\RenovacaoExtensaoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 202 (D-05) — extensão do contrato no ganho da renovação.
 */
class RenovacaoExtensaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servico(): Servico
    {
        return Servico::create([
            'nome' => 'Gestao', 'valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo' => true, 'setor' => Servico::SETOR_PERFORMANCE, 'exige_contrato' => true,
        ]);
    }

    private function contrato(Company $c, Servico $s, array $attrs = []): ContratoServico
    {
        return ContratoServico::factory()->create(array_merge([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'data_contratacao' => '2026-01-10',
            'data_vencimento'  => '2027-01-10',
            'hubspot_billing_period' => 'P12M',
        ], $attrs));
    }

    private function cenario(array $attrs = []): array
    {
        $c  = Company::factory()->create(['active' => true]);
        $s  = $this->servico();
        $cs = $this->contrato($c, $s, $attrs);
        $ciclo = app(RenovacaoDetector::class)->garantirCiclo($c, $s->id);

        return [$c, $s, $cs, $ciclo];
    }

    private function servicoExt(): RenovacaoExtensaoService
    {
        return app(RenovacaoExtensaoService::class);
    }

    public function test_ganho_estende_contrato_e_abre_proximo_ciclo(): void
    {
        [, , $cs, $ciclo] = $this->cenario();
        $ciclo->update(['negocio_renovacao_id' => 'D-REN-1']);

        $this->assertTrue($this->servicoExt()->registrarGanho($ciclo, 'webhook'));

        $cs->refresh();
        $this->assertSame('2028-01-10', $cs->data_vencimento->toDateString());
        $this->assertSame('renovacao', $cs->data_vencimento_origem);

        $ciclo->refresh();
        $this->assertSame('renovado', $ciclo->status_comercial);
        $this->assertSame('2028-01-10', $ciclo->termino_novo->toDateString());
        $this->assertNotNull($ciclo->renovado_em);

        $proximo = ContratoRenovacao::where('numero_ciclo', 2)->first();
        $this->assertSame($ciclo->id, $proximo->ciclo_anterior_id);
        $this->assertSame('2028-01-10', $proximo->termino_capturado->toDateString());
        $this->assertSame('D-REN-1', $proximo->negocio_original_id);
        $this->assertSame(1, ContratoRenovacaoEvento::where('tipo', 'contrato_estendido')->count());
    }

    public function test_ganho_repetido_nao_estende_de_novo(): void
    {
        [, , $cs, $ciclo] = $this->cenario();

        $this->assertTrue($this->servicoExt()->registrarGanho($ciclo, 'webhook'));
        $this->assertFalse($this->servicoExt()->registrarGanho($ciclo, 'webhook'));

        $this->assertSame(2, ContratoRenovacao::count());
        $this->assertSame('2028-01-10', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame(1, ContratoRenovacaoEvento::where('tipo', 'ganho_repetido_ignorado')->count());
    }

    public function test_escalonado_soma_doze_meses(): void
    {
        $c  = Company::factory()->create(['active' => true]);
        $s  = $this->servico();
        $a  = $this->contrato($c, $s, ['hubspot_billing_period' => 'P3M']);
        $b  = $this->contrato($c, $s, ['hubspot_billing_period' => 'P9M']);
        $ciclo = app(RenovacaoDetector::class)->garantirCiclo($c, $s->id);

        $this->assertTrue($this->servicoExt()->registrarGanho($ciclo, 'manual'));

        $this->assertSame('2028-01-10', $a->fresh()->data_vencimento->toDateString());
        $this->assertSame('2028-01-10', $b->fresh()->data_vencimento->toDateString());
    }

    public function test_contrato_desativado_vai_para_revisao_manual(): void
    {
        [, , $cs, $ciclo] = $this->cenario();
        $cs->update(['ativo' => false]);

        $this->assertFalse($this->servicoExt()->registrarGanho($ciclo, 'webhook'));

        $ciclo->refresh();
        $this->assertSame('revisao_manual', $ciclo->status_integracao);
        $this->assertStringContainsString('desativado', $ciclo->ultimo_erro);
        $this->assertSame('identificado', $ciclo->status_comercial);
        $this->assertSame(1, ContratoRenovacao::count());
        $this->assertSame(1, ContratoRenovacaoEvento::where('tipo', 'ganho_sem_extensao')->count());
    }

    public function test_ciclo_dispensado_ou_nao_renovado_nao_estende(): void
    {
        foreach (['dispensado', 'nao_renovado'] as $status) {
            [, , $cs, $ciclo] = $this->cenario();
            $ciclo->update(['status_comercial' => $status]);

            $this->assertFalse($this->servicoExt()->registrarGanho($ciclo, 'webhook'));

            $this->assertSame('revisao_manual', $ciclo->fresh()->status_integracao, $status);
            $this->assertSame('2027-01-10', $cs->fresh()->data_vencimento->toDateString(), $status);
        }
    }

    public function test_segunda_renovacao_mantem_origem_renovacao(): void
    {
        [, , $cs, $ciclo] = $this->cenario();
        $this->servicoExt()->registrarGanho($ciclo, 'webhook');

        $ciclo2 = ContratoRenovacao::where('numero_ciclo', 2)->first();
        $this->assertTrue($this->servicoExt()->registrarGanho($ciclo2, 'webhook'));

        $cs->refresh();
        $this->assertSame('2029-01-10', $cs->data_vencimento->toDateString());
        $this->assertSame('renovacao', $cs->data_vencimento_origem);
        $this->assertSame(3, ContratoRenovacao::count());
        // histórico do ciclo anterior preservado
        $this->assertSame(1, ContratoRenovacaoEvento::where('contrato_renovacao_id', $ciclo->id)->where('tipo', 'contrato_estendido')->count());
    }
}
