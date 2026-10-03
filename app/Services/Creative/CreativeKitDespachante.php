<?php

namespace App\Services\Creative;

use App\Jobs\GerarCriativoIaJob;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use Illuminate\Support\Facades\Log;

/**
 * Despacha os jobs de geração dos slots pendentes/em erro de um kit, em
 * ONDAS controladas (Fase 161, Plano 02, Decisão 5) — GEN-03.
 *
 * Paralelismo controlado é despacho em ONDAS, não rate limiter de job: a
 * alternativa idiomática (`Illuminate\Queue\Middleware\RateLimited`) devolve
 * o job para a fila quando estoura o limite, e cada devolução consome uma
 * tentativa de `$tries` — misturar controle de cota com contagem de
 * retentativa deixaria um kit em dia movimentado morrendo por "tentativas
 * esgotadas" sem nenhuma falha real.
 *
 * Em vez disso, o slot de posição `i` (0-based, entre os CANDIDATOS desta
 * chamada, não o `slot_indice` absoluto) sai com
 * `delay(now()->addSeconds(intdiv($i, $paralelo) * $intervalo))` — molde
 * literal do despacho escalonado já em produção
 * (`MlbAnuncioController::publicarLote`). Com os defaults do 161-01
 * (`paralelo=3`, `intervalo_s=15`) e os ~12,5s por imagem medidos na 160-05,
 * saem 3 ondas e no máximo 3 chamadas simultâneas ao provedor —
 * independentemente de quantos processos a fila `creative` tem.
 *
 * ⚠️ Fato operacional que este despachante não pode medir: o paralelismo
 * REAL é `min($paralelo, numprocs do ecf-worker-creative)`. Se o worker
 * tiver 1 processo, as 7 imagens saem em série (~90s) e ocupam o worker
 * interativo nesse tempo — o desenho já está correto para os dois casos; só
 * o número de ondas observadas na prática muda.
 */
class CreativeKitDespachante
{
    /**
     * Ignora slot em `rodando`, `pronto` ou `aprovado` (um segundo clique
     * não repaga imagem que já existe) e recusa a onda inteira quando o teto
     * de imagens do kit já foi atingido — nunca chama o provedor por conta
     * disso (é dinheiro, Decisão 6).
     *
     * @return array{enfileirados: int, ignorados: int, motivo: ?string}
     */
    public function despachar(MlAnuncioCriativoKit $kit): array
    {
        $totalSlots = $kit->totalSlots();

        if ($kit->tetoDeImagensAtingido()) {
            return [
                'enfileirados' => 0,
                'ignorados'    => $totalSlots,
                'motivo'       => $kit->motivoDoTeto(),
            ];
        }

        $candidatos = $kit->slots()
            ->whereIn('status', [MlAnuncioCriativo::STATUS_PENDENTE, MlAnuncioCriativo::STATUS_ERRO])
            ->orderBy('slot_indice')
            ->get();

        $ignorados = $totalSlots - $candidatos->count();

        if ($candidatos->isEmpty()) {
            return ['enfileirados' => 0, 'ignorados' => $ignorados, 'motivo' => null];
        }

        $paralelo  = max(1, (int) config('services.creative.kit.paralelo', 3));
        $intervalo = max(0, (int) config('services.creative.kit.intervalo_s', 15));

        foreach ($candidatos->values() as $posicao => $slot) {
            $onda = intdiv($posicao, $paralelo);

            GerarCriativoIaJob::dispatch($slot->id)->delay(now()->addSeconds($onda * $intervalo));
        }

        $kit->update([
            'status'     => MlAnuncioCriativoKit::STATUS_GERANDO,
            'started_at' => $kit->started_at ?? now(),
        ]);

        $ondas = intdiv($candidatos->count() - 1, $paralelo) + 1;

        // GEN-05: nenhum dado sensível — só o que ajuda a medir a operação.
        Log::info("[Creative] Kit {$kit->id} despachado", [
            'enfileirados' => $candidatos->count(),
            'ondas'        => $ondas,
        ]);

        return ['enfileirados' => $candidatos->count(), 'ignorados' => $ignorados, 'motivo' => null];
    }
}
