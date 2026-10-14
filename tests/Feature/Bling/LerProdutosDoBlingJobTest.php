<?php

namespace Tests\Feature\Bling;

use App\Jobs\LerProdutosDoBlingJob;
use App\Models\BlingConexao;
use App\Models\Company;
use App\Services\Bling\BlingClient;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Fase 184-08 (BLING-24) — `LerProdutosDoBlingJob`: a leitura do catálogo do
 * Bling em fatias de ~45 s, continuando por `release()` (NUNCA por um novo
 * despacho de dentro do próprio `handle()` — `publicador-ml.md` §6), na fila
 * `publicador-ia` (`publicador-ml.md` §22), com `retryUntil()` finito e sem
 * insistir depois de um erro do Bling.
 */
class LerProdutosDoBlingJobTest extends TestCase
{
    use RefreshDatabase;

    private function empresaComConexaoAtiva(): Company
    {
        $empresa = Company::create([
            'name' => 'Loja Bling '.uniqid(), 'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);

        BlingConexao::create([
            'company_id'    => $empresa->id,
            'access_token'  => 'token-de-teste',
            'refresh_token' => 'refresh-de-teste',
            'status'        => BlingConexao::STATUS_ATIVA,
            'expires_at'    => now()->addHours(6),
        ]);

        return $empresa;
    }

    private function produtoFake(int $id, string $codigo): array
    {
        return ['id' => $id, 'nome' => "Produto {$id}", 'codigo' => $codigo, 'situacao' => 'Ativo', 'tipo' => 'P', 'formato' => 'S'];
    }

    /** Mock da fila subjacente (`InteractsWithQueue::$job`), para capturar a chamada a `release()`. */
    private function comFilaEspiada(LerProdutosDoBlingJob $job): QueueJobContract
    {
        $mock = Mockery::mock(QueueJobContract::class);
        $mock->shouldIgnoreMissing();
        $job->job = $mock;

        return $mock;
    }

    // ═══ Teste 1 — uma execução conclui com 1 página cheia e a próxima vazia ═══

    public function test_uma_execucao_conclui_quando_a_proxima_pagina_vem_vazia(): void
    {
        $empresa = $this->empresaComConexaoAtiva();
        Http::fake([
            '*/produtos?*pagina=1*' => Http::response(['data' => [
                ['id' => 501, 'codigo' => 'P1'],
                ['id' => 502, 'codigo' => 'P2'],
            ]]),
            '*/produtos?*pagina=2*' => Http::response(['data' => []]),
            '*/produtos/501' => Http::response(['data' => $this->produtoFake(501, 'P1')]),
            '*/produtos/502' => Http::response(['data' => $this->produtoFake(502, 'P2')]),
        ]);

        $job = new LerProdutosDoBlingJob($empresa->id, 'rodada-1');
        $job->limitePaginaOverride = 2;
        $job->handle(app(BlingClient::class));

        $estado = Cache::get(LerProdutosDoBlingJob::chaveEstado($empresa->id, 'rodada-1'));

        $this->assertTrue($estado['concluida']);
        $this->assertCount(2, $estado['produtos']);
        $this->assertSame(2, $estado['lidos']);
    }

    // ═══ Teste 2 — a janela esgota, o Job libera a fatia e NUNCA despacha de novo ═══

    public function test_janela_esgotada_libera_a_fatia_sem_despachar_de_novo(): void
    {
        $empresa = $this->empresaComConexaoAtiva();
        Http::fake([
            '*/produtos?*pagina=1*' => Http::response(['data' => [
                ['id' => 601, 'codigo' => 'Q1'],
                ['id' => 602, 'codigo' => 'Q2'],
            ]]),
            '*/produtos/601' => Http::response(['data' => $this->produtoFake(601, 'Q1')]),
            '*/produtos/602' => Http::response(['data' => $this->produtoFake(602, 'Q2')]),
        ]);
        Queue::fake();

        $job = new LerProdutosDoBlingJob($empresa->id, 'rodada-2');
        $job->limitePaginaOverride = 2; // página cheia = NÃO é a última
        $job->janelaSegundosOverride = 0; // esgota depois da 1ª página
        $mock = $this->comFilaEspiada($job);
        $mock->shouldReceive('release')->once()->with(5);

        $job->handle(app(BlingClient::class));

        $estado = Cache::get(LerProdutosDoBlingJob::chaveEstado($empresa->id, 'rodada-2'));
        $this->assertFalse($estado['concluida']);
        $this->assertCount(2, $estado['produtos']);

        // Asserção negativa: nada foi despachado via Queue — a continuação é só pelo release() do mock acima.
        Queue::assertNothingPushed();
    }

    // ═══ Teste 3 — rodada concluída não deixa NADA pendente na fila ═══

    public function test_rodada_concluida_nao_deixa_nada_pendente_na_fila(): void
    {
        $empresa = $this->empresaComConexaoAtiva();
        Http::fake([
            '*/produtos?*' => Http::response(['data' => []]),
        ]);
        Queue::fake();

        $job = new LerProdutosDoBlingJob($empresa->id, 'rodada-3');
        $job->handle(app(BlingClient::class));

        Queue::assertNothingPushed();

        $estado = Cache::get(LerProdutosDoBlingJob::chaveEstado($empresa->id, 'rodada-3'));
        $this->assertTrue($estado['concluida']);
    }

    // ═══ Teste 4 — duas rodadas da MESMA empresa não se misturam ═══

    public function test_duas_rodadas_da_mesma_empresa_nao_se_misturam(): void
    {
        $empresa = $this->empresaComConexaoAtiva();
        Http::fake([
            '*/produtos?*pagina=1*' => Http::response(['data' => [['id' => 701, 'codigo' => 'R1']]]),
            '*/produtos/701' => Http::response(['data' => $this->produtoFake(701, 'R1')]),
        ]);

        $jobA = new LerProdutosDoBlingJob($empresa->id, 'rodada-a');
        $jobA->limitePaginaOverride = 1;
        $jobA->janelaSegundosOverride = 0;
        $this->comFilaEspiada($jobA);
        $jobA->handle(app(BlingClient::class));

        $estadoA = Cache::get(LerProdutosDoBlingJob::chaveEstado($empresa->id, 'rodada-a'));
        $estadoB = Cache::get(LerProdutosDoBlingJob::chaveEstado($empresa->id, 'rodada-b'));

        $this->assertNotNull($estadoA);
        $this->assertNull($estadoB, 'uma rodada que nunca rodou não pode ter estado');
    }

    // ═══ Teste 5 — a fila declarada é a própria (nem a de quem espera, nem a congestionada) ═══

    public function test_o_job_declara_a_fila_publicador_ia(): void
    {
        $empresa = $this->empresaComConexaoAtiva();
        $job = new LerProdutosDoBlingJob($empresa->id, 'rodada-fila');

        $this->assertSame('publicador-ia', $job->queue);
    }

    // ═══ Teste 6 — 429 na 2ª chamada: registra o erro, libera com espera MAIOR, sem nova chamada ═══

    public function test_429_registra_erro_libera_com_espera_maior_e_nao_insiste(): void
    {
        $empresa = $this->empresaComConexaoAtiva();
        Http::fake([
            // Página 1 com exatamente 1 item (= limite de 1): não é a última página.
            '*/produtos?*pagina=1*' => Http::response(['data' => [['id' => 801, 'codigo' => 'S1']]]),
            '*/produtos/801' => Http::response(['data' => $this->produtoFake(801, 'S1')]),
            // Página 2: o Bling recusa com 429.
            '*/produtos?*pagina=2*' => Http::response(['error' => ['description' => 'TOO_MANY_REQUESTS', 'limit' => 3, 'period' => '1s']], 429),
        ]);

        $job = new LerProdutosDoBlingJob($empresa->id, 'rodada-6');
        $job->limitePaginaOverride = 1;
        $mock = $this->comFilaEspiada($job);
        $mock->shouldReceive('release')->once()->with(60);

        $job->handle(app(BlingClient::class));

        $estado = Cache::get(LerProdutosDoBlingJob::chaveEstado($empresa->id, 'rodada-6'));
        $this->assertNotNull($estado['erro']);
        $this->assertStringContainsString('TOO_MANY_REQUESTS', $estado['erro']);
        $this->assertFalse($estado['concluida']);

        // A contagem exata prova a ausência de retentativa: página 1 + ficha do único item +
        // página 2 (429). Nenhuma 4ª chamada repetindo a página 2 depois do erro.
        Http::assertSentCount(3);
    }

    // ═══ Teste 7 — a ficha de cada item vem de produto(), nunca da listagem resumida ═══

    public function test_busca_a_ficha_de_cada_item_por_produto_nunca_so_da_listagem(): void
    {
        $empresa = $this->empresaComConexaoAtiva();
        Http::fake([
            '*/produtos?*pagina=1*' => Http::response(['data' => [
                ['id' => 901, 'codigo' => 'T1'], // listagem resumida, sem os campos completos
                ['id' => 902, 'codigo' => 'T2'],
                ['id' => 903, 'codigo' => 'T3'],
            ]]),
            '*/produtos?*pagina=2*' => Http::response(['data' => []]),
            '*/produtos/901' => Http::response(['data' => $this->produtoFake(901, 'T1')]),
            '*/produtos/902' => Http::response(['data' => $this->produtoFake(902, 'T2')]),
            '*/produtos/903' => Http::response(['data' => $this->produtoFake(903, 'T3')]),
        ]);

        $job = new LerProdutosDoBlingJob($empresa->id, 'rodada-7');
        $job->limitePaginaOverride = 3;
        $job->handle(app(BlingClient::class));

        Http::assertSentCount(5); // página 1 + 3 fichas + página 2 (vazia, encerra)
    }

    // ═══ Teste 8 — TTL da rodada e retryUntil() finito ═══

    public function test_rodada_expira_pelo_ttl_e_retryuntil_tem_limite_finito(): void
    {
        Carbon::setTestNow('2026-10-13 10:00:00');

        $chave = LerProdutosDoBlingJob::chaveEstado(999, 'rodada-ttl');
        Cache::put($chave, ['pagina' => 1, 'produtos' => [], 'avisos' => [], 'erro' => null, 'concluida' => false, 'lidos' => 0], LerProdutosDoBlingJob::RODADA_TTL);
        $this->assertNotNull(Cache::get($chave));

        Carbon::setTestNow(Carbon::now()->addSeconds(LerProdutosDoBlingJob::RODADA_TTL + 1));
        $this->assertNull(Cache::get($chave), 'a rodada tem que expirar sozinha pelo TTL');

        $job = new LerProdutosDoBlingJob(999, 'rodada-ttl');
        $retryUntil = $job->retryUntil();

        $this->assertGreaterThanOrEqual(Carbon::now()->addMinutes(44)->getTimestamp(), $retryUntil->getTimestamp());
        $this->assertLessThanOrEqual(Carbon::now()->addMinutes(46)->getTimestamp(), $retryUntil->getTimestamp());

        Carbon::setTestNow();
    }

    // ═══ Teste bônus — sem conexão ativa: registra o erro e encerra SEM release() ═══

    public function test_sem_conexao_ativa_registra_erro_e_encerra_sem_liberar(): void
    {
        $empresa = Company::create([
            'name' => 'Sem Bling '.uniqid(), 'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);

        $job = new LerProdutosDoBlingJob($empresa->id, 'rodada-sem-conexao');
        $mock = $this->comFilaEspiada($job);
        $mock->shouldNotReceive('release');

        $job->handle(app(BlingClient::class));

        $estado = Cache::get(LerProdutosDoBlingJob::chaveEstado($empresa->id, 'rodada-sem-conexao'));
        $this->assertNotNull($estado['erro']);
    }
}
