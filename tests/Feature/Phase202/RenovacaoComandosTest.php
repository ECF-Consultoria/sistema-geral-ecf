<?php

namespace Tests\Feature\Phase202;

use App\Jobs\SincronizarRenovacaoJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoRenovacao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Renovacao\RenovacaoConfig;
use App\Services\Renovacao\RenovacaoOrquestrador;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 202 (REN-04/REN-07) — comandos, job por ciclo e agendamento.
 */
class RenovacaoComandosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-01 03:00:00');
        config(['services.hubspot.access_token' => 'token-fake']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contratoNaJanela(): Company
    {
        $c = Company::factory()->create(['active' => true, 'hubspot_company_id' => 'HC1']);
        $s = Servico::create([
            'nome' => 'Gestao', 'valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo' => true, 'setor' => Servico::SETOR_PERFORMANCE, 'exige_contrato' => true,
        ]);
        ContratoServico::factory()->create([
            'company_id' => $c->id, 'servico_id' => $s->id,
            'data_contratacao' => '2026-01-10', 'data_vencimento' => '2027-01-10', 'hubspot_billing_period' => 'P12M',
        ]);

        return $c;
    }

    public function test_chave_ausente_nao_detecta_nem_despacha_nada(): void
    {
        Queue::fake();
        $this->contratoNaJanela();

        $this->artisan('renovacao:verificar')
            ->expectsOutputToContain('DESLIGADA')
            ->assertExitCode(0);

        $this->assertSame(0, ContratoRenovacao::count());
        Queue::assertNothingPushed();
    }

    public function test_simular_com_chave_desligada_cria_ciclos_e_despacha_jobs_em_simulacao(): void
    {
        Queue::fake();
        $this->contratoNaJanela();

        $this->artisan('renovacao:verificar --simular')
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertExitCode(0);

        $this->assertSame(1, ContratoRenovacao::count());
        Queue::assertPushedOn('high', SincronizarRenovacaoJob::class, fn ($j) => $j->simulacao === true && $j->origem === 'agendador');
        Queue::assertPushed(SincronizarRenovacaoJob::class, 1);
    }

    public function test_chave_ligada_com_simulacao_padrao_despacha_em_simulacao(): void
    {
        Queue::fake();
        $this->contratoNaJanela();
        $this->artisan('renovacao:chave ligar')->assertExitCode(0);

        $this->artisan('renovacao:verificar')->assertExitCode(0);

        Queue::assertPushed(SincronizarRenovacaoJob::class, fn ($j) => $j->simulacao === true);
    }

    public function test_modo_real_sem_a_property_de_correlacao_falha_com_exit_1(): void
    {
        Queue::fake();
        $this->contratoNaJanela();
        Configuracao::set(RenovacaoConfig::CHAVE_ATIVA, '1');
        Configuracao::set(RenovacaoConfig::CHAVE_SIMULACAO, '0');
        Http::fake(['*' => Http::response([], 404)]);

        $this->artisan('renovacao:verificar')
            ->expectsOutputToContain('renovacao:hubspot-preparar')
            ->assertExitCode(1);

        $this->assertSame(0, ContratoRenovacao::count());
        Queue::assertNothingPushed();
    }

    public function test_comando_chave_liga_desliga_e_mostra_status(): void
    {
        $this->assertFalse(RenovacaoConfig::ativa());
        $this->assertTrue(RenovacaoConfig::simulacao());

        $this->artisan('renovacao:chave ligar --antecedencia=3')->assertExitCode(0);
        $this->assertSame('1', Configuracao::get(RenovacaoConfig::CHAVE_ATIVA));
        $this->assertSame(3, RenovacaoConfig::antecedenciaMeses());

        $this->artisan('renovacao:chave status')
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertExitCode(0);

        $this->artisan('renovacao:chave simulacao-desligar')->assertExitCode(0);
        $this->assertFalse(RenovacaoConfig::simulacao());
        $this->artisan('renovacao:chave simulacao-ligar')->assertExitCode(0);
        $this->assertTrue(RenovacaoConfig::simulacao());

        $this->artisan('renovacao:chave desligar')->assertExitCode(0);
        $this->assertFalse(RenovacaoConfig::ativa());
    }

    public function test_comando_chave_rejeita_acao_e_antecedencia_invalidas(): void
    {
        $this->artisan('renovacao:chave explodir')->assertExitCode(1);
        $this->artisan('renovacao:chave status --antecedencia=13')->assertExitCode(1);
        $this->assertFalse(RenovacaoConfig::ativa());
    }

    public function test_job_na_fila_high_com_after_commit(): void
    {
        $job = new SincronizarRenovacaoJob(1, 'agendador');

        $this->assertSame('high', $job->queue);
        $this->assertTrue($job->afterCommit);
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    public function test_job_com_lock_ocupado_nao_chama_o_orquestrador(): void
    {
        $orq = \Mockery::mock(RenovacaoOrquestrador::class);
        $orq->shouldNotReceive('sincronizar');

        $lock = Cache::lock('renovacao:ciclo:77', 300);
        $this->assertTrue($lock->get());

        (new SincronizarRenovacaoJob(77, 'agendador'))->handle($orq);

        $lock->release();
    }

    public function test_job_com_lock_livre_chama_o_orquestrador_e_libera_o_lock(): void
    {
        $c = $this->contratoNaJanela();
        $ciclo = ContratoRenovacao::create([
            'company_id' => $c->id, 'servico_id' => Servico::first()->id, 'numero_ciclo' => 1,
            'chave_ciclo' => 'ren:x', 'chave_correlacao' => 'ecf-test:ren:x', 'termino_capturado' => '2027-01-10',
        ]);

        $orq = \Mockery::mock(RenovacaoOrquestrador::class);
        $orq->shouldReceive('sincronizar')->once()
            ->andReturn(new \App\Services\Renovacao\RenovacaoDecisao('nada_a_fazer', null, null, 'ok'));

        (new SincronizarRenovacaoJob($ciclo->id, 'agendador', null, true))->handle($orq);

        $lock = Cache::lock("renovacao:ciclo:{$ciclo->id}", 5);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_failed_nunca_rebaixa_criacao_em_duvida_para_erro(): void
    {
        $c = $this->contratoNaJanela();
        $ciclo = ContratoRenovacao::create([
            'company_id' => $c->id, 'servico_id' => Servico::first()->id, 'numero_ciclo' => 1,
            'chave_ciclo' => 'ren:y', 'chave_correlacao' => 'ecf-test:ren:y', 'termino_capturado' => '2027-01-10',
            'status_integracao' => ContratoRenovacao::STATUS_INTEGRACAO_CRIANDO,
            'criacao_iniciada_em' => now(),
        ]);

        (new SincronizarRenovacaoJob($ciclo->id, 'agendador'))->failed(new \RuntimeException('boom'));

        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_RESULTADO_INCERTO, $ciclo->fresh()->status_integracao);

        $ciclo->update(['status_integracao' => ContratoRenovacao::STATUS_INTEGRACAO_PENDENTE, 'criacao_iniciada_em' => null]);
        (new SincronizarRenovacaoJob($ciclo->id, 'agendador'))->failed(new \RuntimeException('boom'));
        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_ERRO, $ciclo->fresh()->status_integracao);
    }

    public function test_agendamento_diario_esta_registrado(): void
    {
        Artisan::call('schedule:list');
        $saida = Artisan::output();

        $this->assertStringContainsString('renovacao:verificar', $saida);
        $this->assertMatchesRegularExpression('/35\s+7\s+\*\s+\*\s+\*\s+php artisan renovacao:verificar/', $saida);
    }
}
