<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\SincronizarProdutoDoPortalJob;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * O gatilho do save no Portal (09/10/2026): cada save do produto (linhas/variações, ficha técnica,
 * descrição, imagens) chama `aoSalvar`, que faz duas coisas:
 *
 * 1. leva o produto ao Publicador LOGO (10/10/2026): `SincronizarProdutoDoPortalJob` daqui a
 *    `publicador.preparo_ia.sincronizar_atraso_s` segundos, sem IA. Saves seguidos dentro dessa
 *    espera viram uma sincronização só (`chaveDoSincronizar`; o Job a apaga ao começar);
 * 2. marca no cache o instante do último save e agenda `PrepararProdutoNoPublicadorJob` para daqui a
 *    `publicador.preparo_ia.atraso_min` — a IA. O Job que acorda e acha uma marca mais nova sai sem
 *    fazer nada (debounce): numa sequência de saves, só o último chama a IA.
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

    /** Existe enquanto há um Sincronizar deste produto agendado e ainda não começado. */
    public static function chaveDoSincronizar(int $estruturaProdutoId): string
    {
        return "publicador:preparo:sincronizar:{$estruturaProdutoId}";
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
            $atrasoSincronizar = max(0, (int) config('publicador.preparo_ia.sincronizar_atraso_s', 15));
            $ids = [];
            foreach ($produtoIds as $id) {
                if ((int) $id > 0) {
                    $ids[(int) $id] = true;
                }
            }
            foreach (array_keys($ids) as $id) {
                // 1. O produto no Publicador já: um Sincronizar só dele, sem IA. A marca vence sozinha se o Job se
                //    perder (o próximo save agenda outro; o preparo abaixo sincroniza de novo de qualquer jeito).
                if (Cache::add(self::chaveDoSincronizar($id), 1, now()->addMinutes(5))) {
                    SincronizarProdutoDoPortalJob::dispatch($companyId, $id)->delay(now()->addSeconds($atrasoSincronizar));
                }

                // 2. A IA, depois que o cliente parar de mexer.
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
