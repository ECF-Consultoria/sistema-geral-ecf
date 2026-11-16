<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolve a categoria-RAIZ (path_from_root[0]) e, quando preciso, o caminho inteiro de uma
 * categoria do Mercado Livre a partir do categoryId, usando a API PÚBLICA de categorias do
 * ML — SEM token de vendedor. Cacheia por categoryId (as categorias do ML são praticamente
 * estáticas).
 *
 * Existe para o /polos fatiar o faturamento por categoria (as categorias da meta, ver
 * Polos\CategoriasDaMetaPolos). A Adman entrega o categoryId por item em /performance, mas
 * NÃO o nome/raiz — este serviço faz essa tradução.
 */
class MlCategoriaService
{
    /** Raiz "Casa, Móveis e Decoração" no Mercado Livre Brasil (path_from_root[0].id). */
    public const RAIZ_CASA_MOVEIS_DECORACAO = 'MLB1574';

    /** Categorias do ML quase nunca mudam de raiz — cache longo evita rechamar a API. */
    private const CACHE_TTL_DIAS = 30;

    /** @var array<string, array<int,string>> raízes das subcategorias-alvo, por conjunto de alvos */
    private array $raizesDeSubcategorias = [];

    /**
     * ID da categoria-raiz do categoryId informado, ou null se não resolver.
     * A 1ª resolução bate na API pública do ML; as demais leem do cache.
     */
    public function raizId(string $categoryId): ?string
    {
        $categoryId = trim($categoryId);
        if ($categoryId === '') {
            return null;
        }

        return Cache::remember(
            "ml:categoria:raiz:{$categoryId}",
            now()->addDays(self::CACHE_TTL_DIAS),
            fn () => $this->buscarCaminho($categoryId)[0] ?? null
        );
    }

    /**
     * Ids do caminho da raiz até a própria categoria (o último é o próprio categoryId), ou
     * null se não resolver. Cache próprio: o de raizId() só guarda a raiz.
     *
     * @return array<int,string>|null
     */
    public function caminhoIds(string $categoryId): ?array
    {
        $categoryId = trim($categoryId);
        if ($categoryId === '') {
            return null;
        }

        return Cache::remember(
            "ml:categoria:caminho:{$categoryId}",
            now()->addDays(self::CACHE_TTL_DIAS),
            fn () => $this->buscarCaminho($categoryId)
        );
    }

    /**
     * True se a categoria está dentro de algum dos alvos (raiz ou subcategoria: vale a
     * subárvore inteira do alvo).
     *
     * Decide pela raiz sempre que dá, porque ela já está no cache de meses de warm. O
     * caminho inteiro só é buscado quando o item mora na mesma raiz de um alvo que é
     * subcategoria (ex.: um brinquedo, com o alvo Brinquedos > Mesas e Cadeiras). Não
     * resolver a categoria (ML fora do ar) conta como "fora", igual sempre foi.
     *
     * @param  array<int,string>  $alvos
     */
    public function pertenceAAlguma(string $categoryId, array $alvos): bool
    {
        $raiz = $this->raizId($categoryId);
        if ($raiz === null) {
            return false;
        }

        if (in_array($raiz, $alvos, true)) {
            return true;
        }

        if (! in_array($raiz, $this->raizesDeSubcategorias($alvos), true)) {
            return false;
        }

        return array_intersect($this->caminhoIds($categoryId) ?? [], $alvos) !== [];
    }

    /**
     * Raízes dos alvos que NÃO são raiz (subcategorias). Memo por instância: o warm pergunta
     * isto para cada item de cada empresa. Só memoriza quando todos os alvos resolveram —
     * uma falha passageira do ML não pode tirar a subcategoria do warm inteiro.
     *
     * @param  array<int,string>  $alvos
     * @return array<int,string>
     */
    private function raizesDeSubcategorias(array $alvos): array
    {
        $chave = implode(',', $alvos);
        if (isset($this->raizesDeSubcategorias[$chave])) {
            return $this->raizesDeSubcategorias[$chave];
        }

        $raizes = [];
        $todasResolvidas = true;
        foreach ($alvos as $alvo) {
            $raiz = $this->raizId($alvo);
            if ($raiz === null) {
                $todasResolvidas = false;
            } elseif ($raiz !== $alvo) {
                $raizes[$raiz] = $raiz;
            }
        }
        $raizes = array_values($raizes);

        if ($todasResolvidas) {
            $this->raizesDeSubcategorias[$chave] = $raizes;
        }

        return $raizes;
    }

    /**
     * Ids de `path_from_root` na API pública do ML, ou null em qualquer falha.
     *
     * @return array<int,string>|null
     */
    private function buscarCaminho(string $categoryId): ?array
    {
        try {
            $resp = Http::timeout(20)->get("https://api.mercadolibre.com/categories/{$categoryId}");
            if ($resp->ok()) {
                $ids = array_values(array_filter(array_map(
                    fn ($no) => is_array($no) ? (string) ($no['id'] ?? '') : '',
                    (array) ($resp->json('path_from_root') ?? [])
                )));

                return $ids !== [] ? $ids : null;
            }
            Log::info("[MlCategoria] {$categoryId}: HTTP {$resp->status()} — sem raiz.");

            return null;
        } catch (\Throwable $e) {
            Log::warning("[MlCategoria] Falha ao resolver {$categoryId}: " . $e->getMessage());

            return null;
        }
    }
}
