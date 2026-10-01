<?php

namespace App\Jobs\Publicador;

use App\Models\PubRascunho;
use App\Services\Publicador\ConferenciaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * O botão "Conferir com o Mercado Livre" (L3, `08` §4). Em Job porque são N
 * chamadas ao ML (um validate por item do plano) e a requisição do portal não
 * pode esperar. A tela acompanha pelo `pub_validacoes` mais recente.
 *
 * Um por rascunho de cada vez (`ShouldBeUnique`): dois cliques não disparam
 * duas conferências. Sem nova tentativa automática — o serviço já repete o que
 * vale repetir (429, 5xx) e grava ERRO com mensagem; repetir o Job inteiro só
 * dobraria as chamadas.
 */
class ConferirRascunhoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public bool $failOnTimeout = true;

    public function __construct(public int $rascunhoId)
    {
        // Fila `high` (clique de pessoa; a `default` represa o sync da Adman).
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->rascunhoId;
    }

    public function handle(ConferenciaService $conferencia): void
    {
        $r = PubRascunho::find($this->rascunhoId);
        if (! $r) {
            Log::warning("[Publicador] rascunho {$this->rascunhoId} sumiu antes da conferência.");

            return;
        }

        $v = $conferencia->conferir($r);
        $itens = count((array) ($v->respostas_ml['itens'] ?? []));
        Log::info("[Publicador] conferência do rascunho {$r->id} (revisão {$v->revisao}): {$v->resultado}, {$itens} validate(s), ".count((array) $v->issues).' problema(s).');
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] conferência do rascunho {$this->rascunhoId} quebrou: ".$e->getMessage());

        // A tela não pode ficar em "conferindo…" para sempre.
        $r = PubRascunho::find($this->rascunhoId);
        $r?->validacoes()->create([
            'revisao' => $r->revisao,
            'camada' => 'L3',
            'resultado' => ConferenciaService::ERRO,
            'issues' => [['regra' => 'V-REM-01', 'severidade' => 'BLOCKER', 'mensagem' => 'A conferência não terminou. Tente de novo; se repetir, avise o time.', 'camada' => 'L3', 'alvo' => ['etapa' => 'E11'], 'ml_causa' => null]],
        ]);
    }
}
