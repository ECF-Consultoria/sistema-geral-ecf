<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolve a categoria-RAIZ (path_from_root[0]) de um anúncio do Mercado Livre a
 * partir do categoryId, usando a API PÚBLICA de categorias do ML — SEM token de
 * vendedor. Cacheia por categoryId (as categorias do ML são praticamente estáticas).
 *
 * Existe para o /polos fatiar o faturamento por categoria (ex.: contar SÓ
 * "Casa, Móveis e Decoração"). A Adman entrega o categoryId por item em
 * /performance, mas NÃO o nome/raiz — este serviço faz essa tradução.
 */
class MlCategoriaService
{
    /** Raiz "Casa, Móveis e Decoração" no Mercado Livre Brasil (path_from_root[0].id). */
    public const RAIZ_CASA_MOVEIS_DECORACAO = 'MLB1574';

    /** Categorias do ML quase nunca mudam de raiz — cache longo evita rechamar a API. */
    private const CACHE_TTL_DIAS = 30;

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
            function () use ($categoryId) {
                try {
                    $resp = Http::timeout(20)->get("https://api.mercadolibre.com/categories/{$categoryId}");
                    if ($resp->ok()) {
                        $path = $resp->json('path_from_root') ?? [];
                        return $path[0]['id'] ?? null;
                    }
                    Log::info("[MlCategoria] {$categoryId}: HTTP {$resp->status()} — sem raiz.");
                    return null;
                } catch (\Throwable $e) {
                    Log::warning("[MlCategoria] Falha ao resolver {$categoryId}: " . $e->getMessage());
                    return null;
                }
            }
        );
    }

    /** True se o anúncio pertence à raiz "Casa, Móveis e Decoração". */
    public function ehCasaMoveisDecoracao(string $categoryId): bool
    {
        return $this->raizId($categoryId) === self::RAIZ_CASA_MOVEIS_DECORACAO;
    }
}
