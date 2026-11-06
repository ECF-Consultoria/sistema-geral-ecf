<?php

namespace Tests\Feature\Phase202;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Contratos\TerminoContratoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 202 (REN-01, D-03) — término calculado, origem rastreável, backfill.
 */
class TerminoContratoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servico(string $nome, bool $exige = true, ?int $donoId = null): Servico
    {
        return Servico::create([
            'nome'                          => $nome,
            'valor_padrao'                  => 0,
            'tipo_cobranca'                 => Servico::TIPO_MENSAL,
            'ativo'                         => true,
            'setor'                         => Servico::SETOR_PERFORMANCE,
            'exige_contrato'                => $exige,
            'contrato_junto_com_servico_id' => $donoId,
        ]);
    }

    private function contrato(Company $company, Servico $servico, array $attrs = []): ContratoServico
    {
        return ContratoServico::factory()->create(array_merge([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'data_contratacao' => '2026-03-10',
        ], $attrs));
    }

    private function empresa(array $attrs = []): Company
    {
        return Company::factory()->create(array_merge(['active' => true], $attrs));
    }

    private function preencher(Company $c, bool $aplicar = true): array
    {
        return app(TerminoContratoService::class)->preencherEmpresa($c, $aplicar);
    }

    public function test_fase_unica_p6m_calcula_seis_meses(): void
    {
        $c  = $this->empresa();
        $cs = $this->contrato($c, $this->servico('Gestao'), ['hubspot_billing_period' => 'P6M']);

        $this->preencher($c);

        $fresh = $cs->fresh();
        $this->assertSame('2026-09-10', $fresh->data_vencimento->toDateString());
        $this->assertSame('calculada', $fresh->data_vencimento_origem);
    }

    public function test_sem_periodo_usa_doze_meses(): void
    {
        $c  = $this->empresa();
        $cs = $this->contrato($c, $this->servico('Gestao'));

        $this->preencher($c);

        $this->assertSame('2027-03-10', $cs->fresh()->data_vencimento->toDateString());
    }

    public function test_data_expressa_no_hubspot_vence_o_calculo(): void
    {
        $c  = $this->empresa();
        $cs = $this->contrato($c, $this->servico('Gestao'), [
            'hubspot_billing_period' => 'P6M',
            'hubspot_snapshot'       => ['line_item' => ['hs_recurring_billing_end_date' => '2027-01-31']],
        ]);

        $this->preencher($c);

        $fresh = $cs->fresh();
        $this->assertSame('2027-01-31', $fresh->data_vencimento->toDateString());
        $this->assertSame('hubspot', $fresh->data_vencimento_origem);
    }

    public function test_escalonado_soma_as_fases_do_mesmo_servico(): void
    {
        $c = $this->empresa();
        $s = $this->servico('Gestao');
        $a = $this->contrato($c, $s, ['hubspot_billing_period' => 'P3M', 'data_contratacao' => '2026-02-10']);
        $b = $this->contrato($c, $s, ['hubspot_billing_period' => 'P9M', 'data_contratacao' => '2026-02-10']);

        $this->preencher($c);

        $this->assertSame('2027-02-10', $a->fresh()->data_vencimento->toDateString());
        $this->assertSame('2027-02-10', $b->fresh()->data_vencimento->toDateString());
    }

    public function test_grupo_dono_e_membro_usa_o_maior_entre_servicos(): void
    {
        $c      = $this->empresa();
        $dono   = $this->servico('Gestao');
        $membro = $this->servico('Shopee', true, $dono->id);
        $a = $this->contrato($c, $dono, ['hubspot_billing_period' => 'P12M']);
        $b = $this->contrato($c, $membro, ['hubspot_billing_period' => 'P6M']);

        $this->preencher($c);

        $this->assertSame('2027-03-10', $a->fresh()->data_vencimento->toDateString());
        $this->assertSame('2027-03-10', $b->fresh()->data_vencimento->toDateString());
    }

    public function test_fase_do_grupo_sem_periodo_cai_em_doze(): void
    {
        $c      = $this->empresa();
        $dono   = $this->servico('Gestao');
        $membro = $this->servico('Shopee', true, $dono->id);
        $this->contrato($c, $dono, ['hubspot_billing_period' => 'P6M']);
        $b = $this->contrato($c, $membro);

        $this->preencher($c);

        $this->assertSame('2027-03-10', $b->fresh()->data_vencimento->toDateString());
    }

    public function test_assinatura_do_dono_vence_data_contratacao_como_inicio(): void
    {
        $c  = $this->empresa();
        $s  = $this->servico('Gestao');
        $cs = $this->contrato($c, $s, ['hubspot_billing_period' => 'P6M']);
        ContratoAssinatura::factory()->assinado()->create([
            'company_id'  => $c->id,
            'servico_id'  => $s->id,
            'assinado_em' => '2026-04-05 15:00:00',
        ]);

        $linhas = $this->preencher($c);

        $this->assertSame('2026-10-05', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame('assinatura', $linhas[0]['inicio_fonte']);
        $this->assertSame('2026-04-05', $linhas[0]['inicio']);
    }

    public function test_data_preenchida_nao_muda_e_legado_vira_manual(): void
    {
        $c = $this->empresa();
        $s = $this->servico('Gestao');
        $manual = $this->contrato($c, $s, ['data_vencimento' => '2028-01-01']);
        // Legado: data sem origem (coluna nova ainda nula).
        \DB::table('contratos_servico')->where('id', $manual->id)->update(['data_vencimento_origem' => null]);
        $calc = $this->contrato($c, $s, ['data_vencimento' => '2028-02-02']);
        $calc->gravarTermino('2028-02-02', 'calculada');

        $this->preencher($c);

        $this->assertSame('2028-01-01', $manual->fresh()->data_vencimento->toDateString());
        $this->assertSame('manual', $manual->fresh()->data_vencimento_origem);
        $this->assertSame('calculada', $calc->fresh()->data_vencimento_origem);
        $this->assertSame('2028-02-02', $calc->fresh()->data_vencimento->toDateString());
    }

    public function test_fora_do_universo_inativo_empresa_inativa_e_dono_sem_contrato(): void
    {
        $c        = $this->empresa();
        $inativo  = $this->contrato($c, $this->servico('A'), ['ativo' => false]);
        $isento   = $this->contrato($c, $this->servico('Polos', false));
        $c2       = $this->empresa(['active' => false]);
        $deEmpInat = $this->contrato($c2, $this->servico('B'));

        $this->assertSame([], $this->preencher($c2));
        $this->preencher($c);

        $this->assertNull($inativo->fresh()->data_vencimento);
        $this->assertNull($isento->fresh()->data_vencimento);
        $this->assertNull($deEmpInat->fresh()->data_vencimento);
    }

    public function test_dry_run_nao_grava_e_devolve_relatorio(): void
    {
        $c  = $this->empresa();
        $cs = $this->contrato($c, $this->servico('Gestao'), ['hubspot_billing_period' => 'P6M']);

        $linhas = $this->preencher($c, false);

        $this->assertNull($cs->fresh()->data_vencimento);
        $this->assertNull($cs->fresh()->data_vencimento_origem);
        $this->assertCount(1, $linhas);
        $this->assertSame('gravar_calculada', $linhas[0]['acao']);
        $this->assertSame('2026-09-10', $linhas[0]['termino_depois']);
    }

    public function test_sinaliza_data_contratacao_futura(): void
    {
        $c = $this->empresa();
        $this->contrato($c, $this->servico('Gestao'), ['data_contratacao' => '2026-12-10']);

        $linhas = $this->preencher($c, false);

        $this->assertTrue($linhas[0]['data_contratacao_futura']);
    }

    // ── Comando de backfill ──────────────────────────────────────────────

    public function test_comando_sem_aplicar_nao_altera_nada_e_diz_simulacao(): void
    {
        $c  = $this->empresa();
        $cs = $this->contrato($c, $this->servico('Gestao'));

        $this->artisan('contratos:calcular-termino')
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertSuccessful();

        $this->assertNull($cs->fresh()->data_vencimento);
    }

    public function test_comando_aplicar_grava_e_e_idempotente(): void
    {
        $c  = $this->empresa();
        $cs = $this->contrato($c, $this->servico('Gestao'), ['hubspot_billing_period' => 'P6M']);

        $this->artisan('contratos:calcular-termino', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame('2026-09-10', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame('calculada', $cs->fresh()->data_vencimento_origem);

        $relatorio = app(TerminoContratoService::class)->preencherTodos(false);
        $gravacoes = collect($relatorio)->filter(fn ($l) => str_starts_with($l['acao'], 'gravar_'))->count();
        $this->assertSame(0, $gravacoes);
    }

    public function test_comando_empresa_restringe(): void
    {
        $c1  = $this->empresa();
        $c2  = $this->empresa();
        $s   = $this->servico('Gestao');
        $a = $this->contrato($c1, $s);
        $b = $this->contrato($c2, $s);

        $this->artisan('contratos:calcular-termino', ['--aplicar' => true, '--empresa' => $c1->id])->assertSuccessful();

        $this->assertNotNull($a->fresh()->data_vencimento);
        $this->assertNull($b->fresh()->data_vencimento);
    }
}
