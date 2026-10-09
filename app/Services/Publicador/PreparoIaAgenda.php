<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * O gatilho do preparo pela IA (09/10/2026): cada save do produto no Portal (linhas/variações,
 * ficha técnica, descrição, imagens) chama `aoSalvar`, que marca no cache o instante do último
 * save do produto e agenda `PrepararProdutoNoPublicadorJob` para daqui a
 * `publicador.preparo_ia.atraso_min`. O Job que acorda e acha uma marca mais nova sai sem fazer
 * nada (debounce): numa sequência de saves, só o último age.
 *
 * Nada aqui pode quebrar o save do cliente: qualquer erro vira log. E o cliente não vê nada
 * disso (sigilo do Portal): a resposta do save não muda.
 */
class PreparoIaAgenda
{
    public static function chaveDaMarca(int $estruturaProdutoId): string
    {
        return "publicador:preparo:marca:{$estruturaProdutoId}";
    }

    public static function marcaAtual(int $estruturaProdutoId): ?string
    {
        $m = Cache::get(self::chaveDaMarca($estruturaProdutoId));

        return is_string($m) ? $m : null;
    }

    /** @param  iterable<int|string|null>  $produtoIds  ids de `estrutura_produtos` da empresa */
    public function aoSalvar(int $companyId, iterable $produtoIds): void
    {
        try {
            if (! config('publicador.preparo_ia.ativo', true)) {
                return;
            }
            // Fila `sync` (testes, máquina sem worker): o Job rodaria DENTRO do save do cliente e
            // ignoraria a espera — sincronizar e chamar a IA no meio do request. Não agenda.
            if (Queue::connection() instanceof SyncQueue) {
                return;
            }

            $atraso = max(0, (int) config('publicador.preparo_ia.atraso_min', 10));
            $ids = [];
            foreach ($produtoIds as $id) {
                if ((int) $id > 0) {
                    $ids[(int) $id] = true;
                }
            }
            foreach (array_keys($ids) as $id) {
                $marca = (string) Str::uuid();
                // Vale por um dia: cobre a espera e os adiamentos (editor em uso) do mesmo save.
                Cache::put(self::chaveDaMarca($id), $marca, now()->addDay());
                PrepararProdutoNoPublicadorJob::dispatch($companyId, $id, $marca)->delay(now()->addMinutes($atraso));
            }
        } catch (\Throwable $e) {
            Log::warning("[Publicador] Preparo pela IA: não foi possível agendar após o save no Portal (empresa {$companyId}): ".$e->getMessage());
        }
    }
}
