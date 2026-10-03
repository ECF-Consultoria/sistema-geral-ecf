<?php

namespace App\Services\Publicador;

use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use App\Services\MlColetaService;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Busca de categoria do Mercado Livre para o editor do Publicador.
 * Extraído do Anunciar do Portal para sobreviver à saída dele (D18).
 *
 * Usa o app token (dado público), nunca a conta do cliente.
 */
class CategoriaBuscaService
{
    private const API_BASE = 'https://api.mercadolibre.com';

    /**
     * O cache dos metadados de categoria é do `MlCatalogoMetaService`
     * (`Cache::remember` por id, 7 dias). A chave já é contrato de fato e é por ela
     * que a leitura em paralelo daqui aquece o MESMO cache que `categoria()` consome.
     */
    private const CACHE_CATEGORIA = 'ml_meta_categoria_';
    private const TTL_CATEGORIA = 604800;

    public function __construct(
        private MlCatalogoMetaService $meta,
        private MlColetaService $coleta,
    ) {}

    /**
     * Categorias para um texto (o preditor do ML, `domain_discovery`), cada
     * uma com o CAMINHO inteiro da árvore (`path_from_root`). Uma categoria
     * cujo caminho falhou aparece com o nome do preditor e `caminho` vazio —
     * a lista não cai por causa de uma.
     *
     * @return array<int, array{id: string, nome: string, dominio: ?string, caminho: array<int, string>}>
     */
    public function categorias(string $q): array
    {
        $candidatos = [];

        foreach ($this->meta->preverCategoria($q) as $c) {
            $id = (string) ($c['category_id'] ?? '');
            if ($id === '' || isset($candidatos[$id])) {
                continue;
            }
            $candidatos[$id] = ['id' => $id, 'nome' => (string) ($c['category_name'] ?? $id), 'dominio' => $c['domain_name'] ?? null];
        }

        $metas = $this->categoriasEmLote(array_keys($candidatos));

        return array_values(array_map(fn (array $c) => [
            ...$c,
            'caminho' => array_values(array_filter(array_column((array) data_get($metas[$c['id']] ?? [], 'path_from_root', []), 'name'))),
        ], $candidatos));
    }

    /**
     * `GET /categories/{id}` de várias categorias de uma vez. O que já está
     * no cache do `MlCatalogoMetaService` sai de lá; o que falta é lido em
     * PARALELO (`Http::pool`, app token) e gravado no mesmo cache.
     * Falha de UMA categoria não derruba as outras: ela entra vazia e NÃO é cacheada.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array>  id → corpo de GET /categories/{id} ([] quando falhou)
     */
    private function categoriasEmLote(array $ids): array
    {
        $saida = [];
        $faltam = [];

        foreach ($ids as $id) {
            $cache = Cache::get(self::CACHE_CATEGORIA.$id);
            if (is_array($cache) && $cache) {
                $saida[$id] = $cache;
            } else {
                $faltam[] = $id;
            }
        }

        // Uma só: o próprio service do admin, com o cache dele.
        if (count($faltam) === 1) {
            $saida[$faltam[0]] = $this->meta->categoria($faltam[0]);

            return $saida;
        }

        if ($faltam) {
            try {
                $token = $this->coleta->getAppToken();
                $respostas = Http::pool(fn (Pool $pool) => array_map(
                    fn ($id) => $pool->as($id)->withToken($token)->timeout(15)->get(self::API_BASE."/categories/{$id}"),
                    $faltam,
                ));
            } catch (\Throwable $e) {
                Log::warning('[Publicador] leitura em lote de categorias falhou: '.$e->getMessage());
                $respostas = [];
            }

            foreach ($faltam as $id) {
                $r = $respostas[$id] ?? null;
                if ($r instanceof Response && $r->successful() && is_array($r->json())) {
                    $saida[$id] = (array) $r->json();
                    Cache::put(self::CACHE_CATEGORIA.$id, $saida[$id], self::TTL_CATEGORIA);
                } else {
                    $saida[$id] = [];
                }
            }
        }

        return $saida;
    }
}
