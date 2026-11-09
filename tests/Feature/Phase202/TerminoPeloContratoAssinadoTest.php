<?php

namespace Tests\Feature\Phase202;

use App\Models\Company;
use App\Models\ContratoClicksignAssinado;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Contratos\TerminoContratoService;
use App\Services\Contratos\VigenciaContrato;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 202-08 — o término vem do CONTRATO ASSINADO na Clicksign (assinatura +
 * prazo escrito). O período de cobrança do HubSpot só vale sem contrato lido.
 * Casos espelham produção em 09/11/2026: Ab4 (P4M × contrato 12m), Quater
 * (P6M × contrato 6m), Acmax (3× P12M em paralelo somados → 2029).
 */
class TerminoPeloContratoAssinadoTest extends TestCase
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

    private function servico(string $nome = 'Gestao'): Servico
    {
        return Servico::create([
            'nome' => $nome, 'valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo' => true, 'setor' => Servico::SETOR_PERFORMANCE, 'exige_contrato' => true,
        ]);
    }

    private function contrato(Company $c, Servico $s, array $attrs = []): ContratoServico
    {
        return ContratoServico::factory()->create(array_merge([
            'company_id' => $c->id, 'servico_id' => $s->id, 'data_contratacao' => '2026-08-03',
        ], $attrs));
    }

    private function assinado(Company $c, array $attrs = []): ContratoClicksignAssinado
    {
        static $n = 0;
        $n++;

        return ContratoClicksignAssinado::create(array_merge([
            'envelope_id'    => "env-{$n}",
            'envelope_nome'  => "Contrato_Projeto_Brigadas_ECF - EMPRESA {$n} - 12 Meses",
            'tipo'           => 'gestao',
            'company_id'     => $c->id,
            'confianca'      => 'certo',
            'assinado_em'    => '2026-07-30 09:16:01',
            'vigencia_meses' => 12,
            'vigencia_fonte' => 'texto',
            'situacao'       => 'casado',
        ], $attrs));
    }

    private function preencher(Company $c, bool $aplicar = true): array
    {
        return app(TerminoContratoService::class)->preencherEmpresa($c, $aplicar);
    }

    public function test_ab4_hubspot_p4m_mas_contrato_de_12_meses_assinado_em_30_07(): void
    {
        $c  = Company::factory()->create(['active' => true]);
        $cs = $this->contrato($c, $this->servico(), ['hubspot_billing_period' => 'P4M']);
        $this->assinado($c);

        $linhas = $this->preencher($c);

        $this->assertSame('2027-07-30', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame('contrato', $cs->fresh()->data_vencimento_origem);
        $this->assertSame('gravar_contrato', $linhas[0]['acao']);
        $this->assertSame(12, $linhas[0]['meses']);
        $this->assertSame('2026-07-30', $linhas[0]['inicio']);
    }

    public function test_quater_p6m_e_contrato_de_6_meses(): void
    {
        $c  = Company::factory()->create(['active' => true]);
        $cs = $this->contrato($c, $this->servico(), ['hubspot_billing_period' => 'P6M']);
        $this->assinado($c, ['assinado_em' => '2026-06-15 14:00:00', 'vigencia_meses' => 6]);

        $this->preencher($c);

        $this->assertSame('2026-12-15', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame('contrato', $cs->fresh()->data_vencimento_origem);
    }

    public function test_contrato_de_2025_vencido_avanca_pela_renovacao_automatica(): void
    {
        // Kaiton em produção: assinado 11/09/2025, 12 meses → venceu 11/09/2026,
        // mas a cláusula renova automaticamente → 11/09/2027 (decisão 09/11/2026).
        $c  = Company::factory()->create(['active' => true]);
        $cs = $this->contrato($c, $this->servico(), ['data_contratacao' => '2026-05-25']);
        $this->assinado($c, ['assinado_em' => '2025-09-11 10:00:00']);

        $this->preencher($c);
        $this->assertSame('2027-09-11', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame('contrato', $cs->fresh()->data_vencimento_origem);

        // Prazo curto avança várias vezes até hoje ou depois; idempotente.
        $c2  = Company::factory()->create(['active' => true]);
        $cs2 = $this->contrato($c2, $this->servico(), ['data_contratacao' => '2026-05-25']);
        $this->assinado($c2, ['assinado_em' => '2025-11-03 10:00:00', 'vigencia_meses' => 4]);

        $this->preencher($c2);
        $this->preencher($c2);
        // 03/11/2025 → 03/03 → 03/07 → 03/11/2026 (ainda antes de 09/11) → 03/03/2027.
        $this->assertSame('2027-03-03', $cs2->fresh()->data_vencimento->toDateString());

        // Término exatamente hoje não avança.
        $c3  = Company::factory()->create(['active' => true]);
        $cs3 = $this->contrato($c3, $this->servico(), ['data_contratacao' => '2026-05-25']);
        $this->assinado($c3, ['assinado_em' => '2025-11-09 10:00:00']);
        $this->preencher($c3);
        $this->assertSame('2026-11-09', $cs3->fresh()->data_vencimento->toDateString());
    }

    public function test_nao_avanca_com_ciclo_de_renovacao_aberto(): void
    {
        $c  = Company::factory()->create(['active' => true]);
        $s  = $this->servico();
        $cs = $this->contrato($c, $s, ['data_contratacao' => '2026-05-25']);
        $this->assinado($c, ['assinado_em' => '2025-10-20 10:00:00']);

        \App\Models\ContratoRenovacao::create([
            'company_id'        => $c->id,
            'servico_id'        => $s->id,
            'numero_ciclo'      => 1,
            'chave_ciclo'       => "teste-{$c->id}-1",
            'chave_correlacao'  => "corr-{$c->id}-1",
            'termino_capturado' => '2026-10-20',
            'vigencia_meses'    => 12,
            'status_comercial'  => \App\Models\ContratoRenovacao::STATUS_COMERCIAL_EM_NEGOCIACAO,
            'status_integracao' => \App\Models\ContratoRenovacao::STATUS_INTEGRACAO_SINCRONIZADO,
            'detectado_em'      => now(),
        ]);

        $this->preencher($c);

        // O Comercial está tratando este término: fica vencido até fechar ou dispensar.
        $this->assertSame('2026-10-20', $cs->fresh()->data_vencimento->toDateString());
    }

    public function test_acmax_tres_itens_p12m_em_paralelo_nao_somam(): void
    {
        $this->assertSame(12, VigenciaContrato::mesesDoGrupo([
            ['servico' => 1, 'parcelas' => 12], ['servico' => 1, 'parcelas' => 12], ['servico' => 1, 'parcelas' => 12],
        ]));
        // Escalonado segue somando.
        $this->assertSame(12, VigenciaContrato::mesesDoGrupo([['servico' => 1, 'parcelas' => 1], ['servico' => 1, 'parcelas' => 11]]));
        $this->assertSame(12, VigenciaContrato::mesesDoGrupo([['servico' => 1, 'parcelas' => 3], ['servico' => 1, 'parcelas' => 9]]));

        $c = Company::factory()->create(['active' => true]);
        $s = $this->servico();
        $a = $this->contrato($c, $s, ['hubspot_billing_period' => 'P12M', 'data_contratacao' => '2026-05-25']);
        $this->contrato($c, $s, ['hubspot_billing_period' => 'P12M', 'data_contratacao' => '2026-05-25']);
        $this->contrato($c, $s, ['hubspot_billing_period' => 'P12M', 'data_contratacao' => '2026-05-25']);

        $this->preencher($c);

        $this->assertSame('2027-05-25', $a->fresh()->data_vencimento->toDateString());
    }

    public function test_manual_e_renovacao_nunca_mudam_mesmo_com_contrato(): void
    {
        $c = Company::factory()->create(['active' => true]);
        $s = $this->servico();
        $manual = $this->contrato($c, $s);
        $manual->gravarTermino('2030-01-01', 'manual');
        $ren = $this->contrato($c, $s);
        $ren->gravarTermino('2028-07-30', 'renovacao');
        $this->assinado($c);

        $this->preencher($c);

        $this->assertSame('2030-01-01', $manual->fresh()->data_vencimento->toDateString());
        $this->assertSame('manual', $manual->fresh()->data_vencimento_origem);
        $this->assertSame('2028-07-30', $ren->fresh()->data_vencimento->toDateString());
        $this->assertSame('renovacao', $ren->fresh()->data_vencimento_origem);
    }

    public function test_calculada_e_recalculada_quando_aparece_o_contrato_e_e_idempotente(): void
    {
        $c  = Company::factory()->create(['active' => true]);
        $cs = $this->contrato($c, $this->servico(), ['hubspot_billing_period' => 'P4M']);

        $this->preencher($c);
        $this->assertSame('2026-12-03', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame('calculada', $cs->fresh()->data_vencimento_origem);

        $this->assinado($c);

        // Dry-run mostra e não grava.
        $simulado = $this->preencher($c, false);
        $this->assertSame('recalcular', $simulado[0]['acao']);
        $this->assertSame('2026-12-03', $cs->fresh()->data_vencimento->toDateString());

        $linhas = $this->preencher($c);
        $this->assertSame('recalcular', $linhas[0]['acao']);
        $this->assertSame('2027-07-30', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame('contrato', $cs->fresh()->data_vencimento_origem);

        $denovo = $this->preencher($c);
        $this->assertSame('manter', $denovo[0]['acao']);
    }

    public function test_varios_contratos_vale_a_assinatura_mais_recente(): void
    {
        $c  = Company::factory()->create(['active' => true]);
        $cs = $this->contrato($c, $this->servico());
        $this->assinado($c, ['assinado_em' => '2025-08-01 10:00:00', 'vigencia_meses' => 12]);
        $this->assinado($c, ['assinado_em' => '2026-08-10 10:00:00', 'vigencia_meses' => 6]);

        $this->preencher($c);

        $this->assertSame('2027-02-10', $cs->fresh()->data_vencimento->toDateString());
    }

    public function test_tipo_mentoria_so_vale_para_o_grupo_de_mentoria(): void
    {
        $c        = Company::factory()->create(['active' => true]);
        $gestao   = $this->contrato($c, $this->servico('Gestao de ADS'), ['hubspot_billing_period' => 'P4M']);
        $mentoria = $this->contrato($c, $this->servico('Mentoria Mercado Livre'), ['hubspot_billing_period' => 'P3M']);
        $this->assinado($c, ['tipo' => 'mentoria', 'vigencia_meses' => 6]);

        $this->preencher($c);

        $this->assertSame('2027-01-30', $mentoria->fresh()->data_vencimento->toDateString());
        $this->assertSame('contrato', $mentoria->fresh()->data_vencimento_origem);
        $this->assertSame('2026-12-03', $gestao->fresh()->data_vencimento->toDateString());
        $this->assertSame('calculada', $gestao->fresh()->data_vencimento_origem);
    }

    public function test_duvidoso_e_outra_empresa_nao_contam(): void
    {
        $c     = Company::factory()->create(['active' => true]);
        $outra = Company::factory()->create(['active' => true]);
        $cs    = $this->contrato($c, $this->servico(), ['hubspot_billing_period' => 'P4M']);
        $this->assinado($c, ['situacao' => 'duvidoso', 'company_id' => null]);
        $this->assinado($outra);

        $this->preencher($c);

        $this->assertSame('calculada', $cs->fresh()->data_vencimento_origem);
        $this->assertSame('2026-12-03', $cs->fresh()->data_vencimento->toDateString());
    }

    public function test_sem_prazo_lido_usa_a_assinatura_como_inicio_e_o_periodo_do_hubspot(): void
    {
        $c  = Company::factory()->create(['active' => true]);
        $cs = $this->contrato($c, $this->servico(), ['hubspot_billing_period' => 'P6M']);
        $this->assinado($c, ['situacao' => 'sem_prazo', 'vigencia_meses' => null, 'vigencia_fonte' => null]);

        $linhas = $this->preencher($c);

        $this->assertSame('2027-01-30', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame('calculada', $cs->fresh()->data_vencimento_origem);
        $this->assertSame('assinatura_clicksign', $linhas[0]['inicio_fonte']);
    }

    public function test_comando_mostra_recalcular_e_linhas_que_viram_contrato(): void
    {
        $c  = Company::factory()->create(['active' => true, 'name' => 'Ab4 Store']);
        $cs = $this->contrato($c, $this->servico(), ['hubspot_billing_period' => 'P4M']);
        $cs->gravarTermino('2026-12-03', 'calculada');
        $this->assinado($c);

        $this->artisan('contratos:calcular-termino')
            ->expectsOutputToContain('recalcular: 1')
            ->expectsOutputToContain('Término passando a seguir o contrato assinado')
            ->assertSuccessful();

        $this->assertSame('2026-12-03', $cs->fresh()->data_vencimento->toDateString());
    }
}
