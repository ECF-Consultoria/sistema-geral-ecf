<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\SincronizarProdutoDoPortalJob;
use App\Support\Trilha\TrilhaDeTrabalho;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
 *    `publicador.preparo_ia.atraso_s` (20 s desde 20/10/2026) — a IA. O Job que acorda e acha uma marca mais nova sai sem
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

    /**
     * A fila do preparo pela IA (`publicador.preparo_ia.fila`, padrão `publicador-ia`): a cadeia e quem a abre.
     * O Sincronizar ao salvar continua na `high` — é ele que leva o produto ao Publicador "na hora".
     */
    public static function fila(): string
    {
        $fila = trim((string) config('publicador.preparo_ia.fila', 'publicador-ia'));

        return $fila !== '' ? $fila : 'publicador-ia';
    }

    /** Salvou no Portal e o preparo ainda não começou (`IaTrabalhando::AGENDADA`, 20/10/2026). */
    public static function chaveDoAgendado(int $estruturaProdutoId): string
    {
        return "publicador:preparo-ia:agendado:{$estruturaProdutoId}";
    }

    public static function marcaAtual(int $estruturaProdutoId): ?string
    {
        $m = Cache::get(self::chaveDaMarca($estruturaProdutoId));

        return is_string($m) ? $m : null;
    }

    /**
     * Trilha de trabalho (revisão de 02/11/2026): o que o último Job agendado do produto vai preparar (`marca` +
     * `itens`, null = todos). O Job novo SUPERA o anterior (debounce) e por isso leva a união dos dois — senão o save
     * do cliente que a soltura da onda alcança em menos de 20 s perderia o preparo que pediu.
     */
    public static function chaveDosPendentes(int $estruturaProdutoId): string
    {
        return "publicador:preparo:pendentes:{$estruturaProdutoId}";
    }

    /** A marca cujo preparo já começou: o que ela leva não se soma mais aos pedidos novos. */
    public static function chaveDoComecado(int $estruturaProdutoId): string
    {
        return "publicador:preparo:comecado:{$estruturaProdutoId}";
    }

    /** O preparo de `$marca` passou da espera e vai rodar ({@see PreparoIaDoRascunhoService::preparar()}). */
    public static function comecou(int $estruturaProdutoId, string $marca): void
    {
        Cache::put(self::chaveDoComecado($estruturaProdutoId), $marca, now()->addDay());
    }

    /**
     * @param  iterable<int|string|null>  $produtoIds  ids de `estrutura_produtos` da empresa
     * @param  array<int, list<string>>|null  $soItensPorProduto  Trilha de produtos (02/11/2026): a soltura da onda diz,
     *        por produto, quais itens da trilha pediu — a IA só roda para eles (o Sincronizar segue para todos). Null (o
     *        save de sempre) = nenhuma restrição.
     */
    public function aoSalvar(int $companyId, iterable $produtoIds, ?array $soItensPorProduto = null): void
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

            $atraso = max(0, (int) config('publicador.preparo_ia.atraso_s', 20));
            $atrasoSincronizar = max(0, (int) config('publicador.preparo_ia.sincronizar_atraso_s', 15));
            $trilha = TrilhaDeTrabalho::ligada($companyId);
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
                $soItens = $soItensPorProduto !== null ? array_values($soItensPorProduto[$id] ?? []) : null;
                if ($trilha) {
                    $soItens = self::marcarComPendentes($id, $marca, $soItens);
                } else {
                    // Vale por um dia: cobre a espera e os adiamentos (editor em uso) do mesmo save.
                    Cache::put(self::chaveDaMarca($id), $marca, now()->addDay());
                }
                PrepararProdutoNoPublicadorJob::dispatch($companyId, $id, $marca, 0, $soItens)->delay(now()->addSeconds($atraso));
                // 3. "A IA vem aí" para a vitrine e o editor aberto (`IaTrabalhando::AGENDADA`): a espera + 1 minuto,
                //    o bastante para a 1ª etapa já estar `rodando` quando a marca vence.
                Cache::put(self::chaveDoAgendado($id), now()->timestamp, now()->addSeconds($atraso + 60));
            }
        } catch (\Throwable $e) {
            Log::warning("[Publicador] Preparo pela IA: não foi possível agendar após o save no Portal (empresa {$companyId}): ".$e->getMessage());
        }
    }

    /**
     * Trilha de trabalho: a soma com o que estava pendente e a marca nova, numa trava por produto — o save do cliente e
     * a soltura da onda no mesmo instante não podem gravar um por cima do outro (o "todos" do save se perderia). Sem a
     * trava em 5 s, segue sem ela: o pior caso é o de antes da soma.
     *
     * @param  list<string>|null  $pedido
     * @return list<string>|null
     */
    private static function marcarComPendentes(int $estruturaProdutoId, string $marca, ?array $pedido): ?array
    {
        $marcar = function () use ($estruturaProdutoId, $marca, $pedido): ?array {
            $juntos = self::juntarPendentes($estruturaProdutoId, $marca, $pedido);
            Cache::put(self::chaveDaMarca($estruturaProdutoId), $marca, now()->addDay());

            return $juntos;
        };

        try {
            return Cache::lock("publicador:preparo:agenda:{$estruturaProdutoId}", 10)->block(5, $marcar);
        } catch (LockTimeoutException) {
            return $marcar();
        }
    }

    /**
     * O pedido novo somado ao do Job anterior que ainda não começou ({@see chaveDosPendentes()}): "todos" (null) vence.
     * O Job que já começou não entra na soma — os itens dele já estão sendo preparados.
     *
     * @param  list<string>|null  $pedido
     * @return list<string>|null
     */
    private static function juntarPendentes(int $estruturaProdutoId, string $marca, ?array $pedido): ?array
    {
        $pendente = Cache::get(self::chaveDosPendentes($estruturaProdutoId));
        $anterior = is_array($pendente) && array_key_exists('itens', $pendente)
            && ($pendente['marca'] ?? null) !== Cache::get(self::chaveDoComecado($estruturaProdutoId))
            ? $pendente : null;

        $juntos = match (true) {
            $pedido === null => null,
            $anterior === null => $pedido,
            $anterior['itens'] === null => null,
            default => array_values(array_unique([...(array) $anterior['itens'], ...$pedido])),
        };
        Cache::put(self::chaveDosPendentes($estruturaProdutoId), ['marca' => $marca, 'itens' => $juntos], now()->addDay());

        return $juntos;
    }
}
