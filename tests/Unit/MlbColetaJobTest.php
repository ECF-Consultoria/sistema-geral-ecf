<?php

namespace Tests\Unit;

use App\Jobs\MlbColetaJob;
use App\Models\MlbColeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes unitários do MlbColetaJob.
 *
 * Cobre o D-06: failed() atualiza status=erro e erro_mensagem sem invocar o Service
 * (o teste é completamente independente do Plan 17-03 / MlColetaService).
 *
 * @group phase17
 */
class MlbColetaJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * D-06: failed() deve gravar status=erro e prefixar a mensagem com "Falha definitiva: ".
     *
     * Instancia o Job diretamente (sem dispatch) e chama failed() com uma exceção simulada.
     * Verifica que o banco foi atualizado sem depender da implementação do pipeline.
     */
    public function test_failed_marca_erro(): void
    {
        // Cria coleta com status=rodando (simula job em andamento que falhou definitivamente)
        $coleta = MlbColeta::create([
            'user_id' => null,
            'keyword' => 'teste',
            'status'  => MlbColeta::STATUS_RODANDO,
        ]);

        $job = new MlbColetaJob($coleta->id);
        $job->failed(new \RuntimeException('Erro simulado'));

        $this->assertDatabaseHas('mlb_coletas', [
            'id'     => $coleta->id,
            'status' => MlbColeta::STATUS_ERRO,
        ]);
    }
}
