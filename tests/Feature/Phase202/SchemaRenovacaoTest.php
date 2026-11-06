<?php

namespace Tests\Feature\Phase202;

use App\Models\Company;
use App\Models\ContratoRenovacao;
use App\Models\ContratoServico;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 202 (REN-02) — esquema de ciclos/eventos e origem do término.
 */
class SchemaRenovacaoTest extends TestCase
{
    use RefreshDatabase;

    private function ciclo(Company $company, int $servicoId, int $numero, array $extra = []): ContratoRenovacao
    {
        return ContratoRenovacao::create(array_merge([
            'company_id'        => $company->id,
            'servico_id'        => $servicoId,
            'numero_ciclo'      => $numero,
            'chave_ciclo'       => "c{$company->id}-s{$servicoId}-n{$numero}-".uniqid(),
            'chave_correlacao'  => "corr-{$company->id}-{$servicoId}-{$numero}-".uniqid(),
            'termino_capturado' => '2027-01-10',
        ], $extra));
    }

    public function test_unicidade_empresa_servico_numero_do_ciclo(): void
    {
        $cs = ContratoServico::factory()->create();

        $this->ciclo($cs->company, $cs->servico_id, 1);

        $this->expectException(QueryException::class);
        $this->ciclo($cs->company, $cs->servico_id, 1);
    }

    public function test_negocio_renovacao_nao_entra_em_dois_ciclos_mas_null_repete(): void
    {
        $cs = ContratoServico::factory()->create();

        $this->ciclo($cs->company, $cs->servico_id, 1);
        $this->ciclo($cs->company, $cs->servico_id, 2);
        $this->assertSame(2, ContratoRenovacao::count());

        $this->ciclo($cs->company, $cs->servico_id, 3, ['negocio_renovacao_id' => '999']);

        $this->expectException(QueryException::class);
        $this->ciclo($cs->company, $cs->servico_id, 4, ['negocio_renovacao_id' => '999']);
    }

    public function test_edicao_humana_vira_manual_e_apagar_zera_a_origem(): void
    {
        $cs = ContratoServico::factory()->create();

        $cs->update(['data_vencimento' => '2027-05-01']);
        $this->assertSame('manual', $cs->fresh()->data_vencimento_origem);

        $cs->update(['data_vencimento' => null]);
        $this->assertNull($cs->fresh()->data_vencimento_origem);

        $cs->gravarTermino('2027-06-01', 'calculada');
        $fresh = $cs->fresh();
        $this->assertSame('calculada', $fresh->data_vencimento_origem);
        $this->assertSame('2027-06-01', $fresh->data_vencimento->toDateString());
    }

    public function test_origem_repetida_permanece(): void
    {
        foreach (['renovacao', 'calculada', 'hubspot'] as $origem) {
            $cs = ContratoServico::factory()->create();
            $cs->gravarTermino('2027-01-01', $origem);
            $cs->gravarTermino('2028-01-01', $origem);

            $fresh = $cs->fresh();
            $this->assertSame($origem, $fresh->data_vencimento_origem);
            $this->assertSame('2028-01-01', $fresh->data_vencimento->toDateString());
        }
    }

    public function test_update_humano_depois_de_gravar_termino_vira_manual(): void
    {
        $cs = ContratoServico::factory()->create();
        $cs->gravarTermino('2027-01-01', 'renovacao');

        $cs->update(['data_vencimento' => '2027-09-09']);

        $this->assertSame('manual', $cs->fresh()->data_vencimento_origem);
    }

    public function test_registrar_evento_grava_dados_e_simulacao(): void
    {
        $cs = ContratoServico::factory()->create();
        $ciclo = $this->ciclo($cs->company, $cs->servico_id, 1);

        $ev = $ciclo->registrarEvento('detectado', 'comando', 'criar', ['a' => 1], true);

        $fresh = $ev->fresh();
        $this->assertSame(['a' => 1], $fresh->dados);
        $this->assertTrue($fresh->simulacao);
        $this->assertSame('comando', $fresh->origem);
        $this->assertSame(1, $ciclo->eventos()->count());
    }
}
