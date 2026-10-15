<?php

namespace App\Jobs\Publicador;

use App\Models\AcervoImagem;
use App\Services\Publicador\AcervoExclusaoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Apaga do storage (R2 ou local) o objeto de uma imagem marcada `excluindo` no acervo
 * (v28.0 Fase 199, EXC-02). Idempotente — rodar duas vezes, ou depois de a imagem voltar ao
 * acervo, não faz nada. Falha do storage repete com espera crescente; esgotou, vira `falhou`.
 *
 * Fila `default`: não é interativo (a tela já tirou a imagem da lista quando ela virou `excluindo`).
 */
class ExcluirAtivoDoAcervoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $ativoId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(AcervoExclusaoService $exclusao): void
    {
        try {
            $exclusao->executar($this->ativoId);
        } catch (\Throwable $e) {
            AcervoImagem::query()->whereKey($this->ativoId)->update(['exclusao_erro' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000)]);
            Log::warning("[Acervo] Ativo {$this->ativoId}: falhou ao apagar do storage (tentativa {$this->attempts()}): ".$e->getMessage());

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        AcervoImagem::query()->whereKey($this->ativoId)->where('status', AcervoImagem::EXCLUINDO)
            ->update(['status' => AcervoImagem::FALHOU]);
        Log::error("[Acervo] Ativo {$this->ativoId}: exclusão desistiu depois de {$this->tries} tentativas: ".$e->getMessage());
    }
}
