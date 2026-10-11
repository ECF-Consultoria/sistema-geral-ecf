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

    // ═══ Navegar pela árvore (11/10/2026) ════════════════════════════════════
    //
    // A busca de cima é o PREDITOR do Mercado Livre: devolve até 8 palpites para um texto e, com
    // texto curto, erra feio ("cadeira" devolve Abraçadeiras e Chocadeiras). O usuário procurou a
    // categoria de um anúncio que mostrava "… > Cadeiras, Sofás e Banquetas > Sofás > Cadeira" e não
    // achou — "Sofás" é o último nível da árvore; o "Cadeira" dali não é categoria. Navegar nível a
    // nível, como na tela do próprio Mercado Livre, mostra o que existe e onde a árvore acaba.

    /** As 32 raízes do site mudam quase nunca; o mesmo TTL das categorias. */
    private const CACHE_RAIZES = 'ml_meta_raizes_MLB';

    /** Acima disto não se lê filha por filha para saber quem é folha (a maior raiz tem 40): a tela descobre ao entrar. */
    private const MAX_FILHAS_CONFERIDAS = 60;

    /**
     * Um nível da árvore: a categoria (`atual`, nula na raiz), o caminho até ela e as filhas.
     * Cada filha diz se é FOLHA (só folha recebe anúncio) — lidas em paralelo e guardadas no mesmo
     * cache de 7 dias; `folha` nula = não deu para saber, e a tela descobre ao entrar nela.
     *
     * Nulo = o Mercado Livre não respondeu (nada é guardado: a próxima tentativa refaz a leitura).
     *
     * @return array{
     *   atual: ?array{id: string, nome: string, folha: bool, anunciavel: bool},
     *   caminho: list<array{id: string, nome: string}>,
     *   filhas: list<array{id: string, nome: string, folha: ?bool, anunciavel: ?bool}>
     * }|null
     */
    public function nivel(?string $pai = null): ?array
    {
        $pai = trim((string) $pai);

        if ($pai === '') {
            $raizes = $this->raizes();

            return $raizes === [] ? null : [
                'atual' => null,
                'caminho' => [],
                // Raiz nunca é folha (todas têm de 4 a 40 filhas) e nenhuma recebe anúncio.
                'filhas' => array_map(fn (array $r) => [...$r, 'folha' => false, 'anunciavel' => false], $raizes),
            ];
        }

        $cat = $this->categoriasEmLote([$pai])[$pai] ?? [];
        if ($cat === []) {
            return null;
        }

        $cruas = [];
        foreach ((array) ($cat['children_categories'] ?? []) as $f) {
            $id = trim((string) ($f['id'] ?? ''));
            $nome = trim((string) ($f['name'] ?? ''));
            if ($id !== '' && $nome !== '') {
                $cruas[$id] = ['id' => $id, 'nome' => $nome];
            }
        }

        $metas = $cruas !== [] && count($cruas) <= self::MAX_FILHAS_CONFERIDAS ? $this->categoriasEmLote(array_keys($cruas)) : [];

        $caminho = [];
        foreach ((array) ($cat['path_from_root'] ?? []) as $n) {
            if (($n['id'] ?? '') !== '' && ($n['name'] ?? '') !== '') {
                $caminho[] = ['id' => (string) $n['id'], 'nome' => (string) $n['name']];
            }
        }

        return [
            'atual' => ['id' => (string) ($cat['id'] ?? $pai), 'nome' => (string) ($cat['name'] ?? $pai),
                'folha' => $cruas === [], 'anunciavel' => $cruas === [] && self::recebeAnuncio($cat)],
            'caminho' => $caminho,
            'filhas' => array_values(array_map(function (array $f) use ($metas) {
                $meta = $metas[$f['id']] ?? [];
                $folha = $meta === [] ? null : empty($meta['children_categories']);

                return [...$f, 'folha' => $folha, 'anunciavel' => $folha === null ? null : ($folha && self::recebeAnuncio($meta))];
            }, $cruas)),
        ];
    }

    /** `settings.listing_allowed` da categoria; sem o campo, vale o que a folha diz. */
    private static function recebeAnuncio(array $categoria): bool
    {
        return (data_get($categoria, 'settings.listing_allowed') ?? true) !== false;
    }

    /** @return list<array{id: string, nome: string}>  as categorias do 1º nível; vazio = o ML não respondeu (não é guardado) */
    private function raizes(): array
    {
        $cache = Cache::get(self::CACHE_RAIZES);
        if (is_array($cache) && $cache !== []) {
            return $cache;
        }

        try {
            $resposta = Http::withToken($this->coleta->getAppToken())->timeout(15)->get(self::API_BASE.'/sites/MLB/categories');
        } catch (\Throwable $e) {
            Log::warning('[Publicador] leitura das categorias raiz falhou: '.$e->getMessage());

            return [];
        }

        $raizes = [];
        foreach ($resposta->successful() ? (array) $resposta->json() : [] as $r) {
            $id = trim((string) ($r['id'] ?? ''));
            $nome = trim((string) ($r['name'] ?? ''));
            if ($id !== '' && $nome !== '') {
                $raizes[] = ['id' => $id, 'nome' => $nome];
            }
        }
        if ($raizes !== []) {
            Cache::put(self::CACHE_RAIZES, $raizes, self::TTL_CATEGORIA);
        }

        return $raizes;
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
            // O `Cache::remember` de lá guarda o `[]` de uma queda passageira por 7 dias: solta a chave,
            // senão a categoria fica "fora do ar" na navegação até o cache vencer.
            if ($saida[$faltam[0]] === []) {
                Cache::forget(self::CACHE_CATEGORIA.$faltam[0]);
            }

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
