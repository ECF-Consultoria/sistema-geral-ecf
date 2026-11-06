<?php

namespace Tests\Feature\Phase202;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoRenovacao;
use App\Models\ContratoRenovacaoEvento;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Renovacao\RenovacaoConfig;
use App\Services\Renovacao\RenovacaoDetector;
use App\Services\Renovacao\RenovacaoJanela;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 202 (REN-02) — janela de calendário e detecção idempotente de ciclos.
 */
class RenovacaoJanelaDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-01-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servico(string $nome = 'Gestao', bool $exige = true): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 0,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => $exige,
        ]);
    }

    private function empresa(array $attrs = []): Company
    {
        return Company::factory()->create(array_merge(['active' => true], $attrs));
    }

    private function contrato(Company $c, Servico $s, ?string $termino, array $attrs = []): ContratoServico
    {
        return ContratoServico::factory()->create(array_merge([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'data_contratacao' => '2026-01-10',
            'data_vencimento'  => $termino,
        ], $attrs));
    }

    private function detector(): RenovacaoDetector
    {
        return app(RenovacaoDetector::class);
    }

    public function test_janela_abre_por_meses_de_calendario_nas_fronteiras(): void
    {
        $casos = [
            '2027-04-30' => '2027-02-28',
            '2027-03-31' => '2027-01-31',
            '2027-12-31' => '2027-10-31',
            '2028-04-30' => '2028-02-29',
        ];

        foreach ($casos as $termino => $abre) {
            $this->assertSame($abre, RenovacaoJanela::janelaAbre(Carbon::parse($termino))->toDateString(), $termino);
        }
    }

    public function test_situacao_e_vence_em_30_dias(): void
    {
        $hoje = Carbon::parse('2027-01-15');

        $this->assertSame('sem_termino', RenovacaoJanela::situacao(null, $hoje));
        $this->assertSame('vencido', RenovacaoJanela::situacao(Carbon::parse('2027-01-14'), $hoje));
        $this->assertSame('na_janela', RenovacaoJanela::situacao(Carbon::parse('2027-01-15'), $hoje));
        $this->assertSame('na_janela', RenovacaoJanela::situacao(Carbon::parse('2027-03-15'), $hoje));
        $this->assertSame('fora_da_janela', RenovacaoJanela::situacao(Carbon::parse('2027-03-16'), $hoje));

        $this->assertTrue(RenovacaoJanela::venceEmAte30Dias(Carbon::parse('2027-02-14'), $hoje));
        $this->assertFalse(RenovacaoJanela::venceEmAte30Dias(Carbon::parse('2027-02-15'), $hoje));
        $this->assertFalse(RenovacaoJanela::venceEmAte30Dias(Carbon::parse('2027-01-14'), $hoje));
        $this->assertSame(-1, RenovacaoJanela::diasRestantes(Carbon::parse('2027-01-14'), $hoje));
    }

    public function test_antecedencia_configuravel_e_invalida_cai_em_dois(): void
    {
        $termino = Carbon::parse('2027-06-30');

        Configuracao::set(RenovacaoConfig::CHAVE_ANTECEDENCIA, '3');
        $this->assertSame('2027-03-30', RenovacaoJanela::janelaAbre($termino)->toDateString());

        foreach (['abc', '0'] as $invalido) {
            Configuracao::set(RenovacaoConfig::CHAVE_ANTECEDENCIA, $invalido);
            $this->assertSame(2, RenovacaoConfig::antecedenciaMeses(), $invalido);
        }
    }

    public function test_config_nasce_desligada_e_em_simulacao(): void
    {
        $this->assertFalse(RenovacaoConfig::ativa());
        $this->assertTrue(RenovacaoConfig::simulacao());

        Configuracao::set(RenovacaoConfig::CHAVE_ATIVA, '1');
        Configuracao::set(RenovacaoConfig::CHAVE_SIMULACAO, '0');

        $this->assertTrue(RenovacaoConfig::ativa());
        $this->assertFalse(RenovacaoConfig::simulacao());
    }

    public function test_detecta_grupo_na_janela_e_cria_ciclo_um(): void
    {
        $c = $this->empresa(['hubspot_company_id' => 'HC1', 'hubspot_deal_id' => 'D-ORIG']);
        $s = $this->servico();
        $this->contrato($c, $s, '2027-02-20');

        $ciclos = $this->detector()->detectar();

        $this->assertCount(1, $ciclos);
        $ciclo = ContratoRenovacao::first();
        $this->assertSame(1, $ciclo->numero_ciclo);
        $this->assertSame("ren:{$c->id}:{$s->id}:1", $ciclo->chave_ciclo);
        $this->assertSame(config('services.hubspot.renovacao.prefixo_correlacao') . ":ren:{$c->id}:{$s->id}:1", $ciclo->chave_correlacao);
        $this->assertSame('2027-02-20', $ciclo->termino_capturado->toDateString());
        $this->assertSame('D-ORIG', $ciclo->negocio_original_id);
        $this->assertSame('HC1', $ciclo->hubspot_company_id);
        $this->assertSame(1, ContratoRenovacaoEvento::where('tipo', 'detectado')->count());
    }

    public function test_detectar_duas_vezes_mantem_um_ciclo_e_um_evento(): void
    {
        $c = $this->empresa();
        $this->contrato($c, $this->servico(), '2027-02-20');

        $this->detector()->detectar();
        $this->detector()->detectar();

        $this->assertSame(1, ContratoRenovacao::count());
        $this->assertSame(1, ContratoRenovacaoEvento::where('tipo', 'detectado')->count());
    }

    public function test_termino_corrigido_atualiza_o_mesmo_ciclo(): void
    {
        $c  = $this->empresa();
        $cs = $this->contrato($c, $this->servico(), '2027-01-10');
        // vencido: só por pedido manual
        $ciclo = $this->detector()->garantirCiclo($c, $cs->servico_id, true);

        $cs->update(['data_vencimento' => '2027-01-20']);
        $this->detector()->detectar();

        $this->assertSame(1, ContratoRenovacao::count());
        $ciclo->refresh();
        $this->assertSame('2027-01-20', $ciclo->termino_capturado->toDateString());

        $evento = ContratoRenovacaoEvento::where('tipo', 'termino_corrigido')->first();
        $this->assertNotNull($evento);
        $this->assertSame('2027-01-10', $evento->dados['termino_antes']);
        $this->assertSame('2027-01-20', $evento->dados['termino_depois']);
    }

    public function test_vencido_so_por_pedido_manual(): void
    {
        $c  = $this->empresa();
        $cs = $this->contrato($c, $this->servico(), '2027-01-10');

        $this->detector()->detectar();
        $this->assertSame(0, ContratoRenovacao::count());

        try {
            $this->detector()->garantirCiclo($c, $cs->servico_id);
            $this->fail('Deveria recusar contrato vencido sem permissão.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('venceu', $e->getMessage());
        }

        $this->detector()->garantirCiclo($c, $cs->servico_id, permitirVencido: true);
        $this->assertSame(1, ContratoRenovacao::count());
    }

    public function test_fora_da_janela_inativos_e_sem_termino_nao_criam(): void
    {
        $s = $this->servico();

        $fora = $this->empresa();
        $this->contrato($fora, $s, '2027-09-01');

        $inativa = $this->empresa(['active' => false]);
        $this->contrato($inativa, $s, '2027-02-20');

        $contratoInativo = $this->empresa();
        $this->contrato($contratoInativo, $s, '2027-02-20', ['ativo' => false]);

        $this->detector()->detectar();
        $this->assertSame(0, ContratoRenovacao::count());

        $semTermino = $this->empresa();
        $cs = $this->contrato($semTermino, $s, null);
        $this->expectException(\DomainException::class);
        $this->detector()->garantirCiclo($semTermino, $cs->servico_id);
    }

    public function test_empresas_e_grupos_independentes(): void
    {
        $s1 = $this->servico('Gestao');
        $s2 = $this->servico('Mentoria');
        $a  = $this->empresa();
        $b  = $this->empresa();
        $this->contrato($a, $s1, '2027-02-20');
        $this->contrato($a, $s2, '2027-03-01');
        $this->contrato($b, $s1, '2027-02-25');

        $this->assertCount(3, $this->detector()->detectar());
        $this->assertSame(3, ContratoRenovacao::count());
    }

    public function test_garantir_ciclo_duas_vezes_resulta_em_um_so(): void
    {
        $c  = $this->empresa();
        $cs = $this->contrato($c, $this->servico(), '2027-02-20');

        $a = $this->detector()->garantirCiclo($c, $cs->servico_id);
        $b = $this->detector()->garantirCiclo($c, $cs->servico_id);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, ContratoRenovacao::count());
    }

    public function test_ultimo_ciclo_renovado_usa_o_seguinte_sem_recriar_o_renovado(): void
    {
        $c  = $this->empresa();
        $cs = $this->contrato($c, $this->servico(), '2027-02-20');
        $this->detector()->detectar();

        ContratoRenovacao::first()->update(['status_comercial' => 'renovado']);
        $cs->gravarTermino('2027-03-01', 'renovacao');

        $ciclos = $this->detector()->detectar();

        $this->assertSame(2, ContratoRenovacao::count());
        $novo = ContratoRenovacao::where('numero_ciclo', 2)->first();
        $this->assertSame(ContratoRenovacao::first()->id, $novo->ciclo_anterior_id);
        $this->assertCount(1, $ciclos);
        $this->assertSame($novo->id, $ciclos->first()->id);
    }

    public function test_revisao_manual_fica_fora_da_lista_de_conciliacao(): void
    {
        $c = $this->empresa();
        $this->contrato($c, $this->servico(), '2027-02-20');
        $this->detector()->detectar();

        ContratoRenovacao::first()->update(['status_integracao' => 'revisao_manual']);

        $this->assertCount(0, $this->detector()->detectar());
        $this->assertSame(1, ContratoRenovacao::count());
    }
}
