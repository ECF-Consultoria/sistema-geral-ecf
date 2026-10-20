<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Pausar, suspender e retomar o agente de UMA loja (AG-06, 20/10/2026) — o que a tela chama.
 *
 * Grava o controle (`ConfiguracaoDoAgenteService`, com versão e snapshot) e é só isso que pausa: workers e
 * agendador releem a política do banco antes de cada etapa (`PoliticaDoAgente::recusaParaRascunho` no Job da
 * IA, `recusaParaSeguir` no encaminhador), inclusive para Job que já estava na fila. Chamada à IA que já saiu
 * termina; o valor que volta espera a retomada e nunca escreve por cima de gente.
 *
 * Pausado e suspenso param o PREPARO do mesmo jeito. Nenhum dos dois mexe em anúncio ativo, oferta que já está na
 * Aprovação ou publicação aprovada — parada de publicação é controle da fila de publicação, não deste.
 *
 * Retomar continua as execuções vivas no mesmo registro, sem criar outra.
 */
class ControleDoAgente
{
    public function __construct(
        private ConfiguracaoDoAgenteService $config,
        private EntradaDoPreparo $entrada,
    ) {}

    public function pausar(array $conta, User $quem, ?string $motivo = null): PubAgenteConfiguracao
    {
        return $this->config->pausar($conta, $quem, $motivo);
    }

    public function suspender(array $conta, User $quem, ?string $motivo = null): PubAgenteConfiguracao
    {
        return $this->config->suspender($conta, $quem, $motivo);
    }

    /** @return array{configuracao: PubAgenteConfiguracao, continuadas: int} */
    public function retomar(array $conta, User $quem): array
    {
        $cfg = $this->config->retomar($conta, $quem);
        $continuadas = 0;
        PubAgenteExecucao::query()
            ->where('conta_chave', $conta['chave'])
            ->whereIn('estado', PubAgenteExecucao::VIVAS)
            ->orderBy('id')->get()
            ->each(function (PubAgenteExecucao $e) use (&$continuadas) {
                try {
                    $this->entrada->continuar($e);
                    $continuadas++;
                } catch (\Throwable $ex) {
                    Log::error("[Publicador Agente] Retomar: a execução {$e->id} ({$e->conta_chave}) não continuou: ".$ex->getMessage());
                }
            });

        return ['configuracao' => $cfg, 'continuadas' => $continuadas];
    }
}
