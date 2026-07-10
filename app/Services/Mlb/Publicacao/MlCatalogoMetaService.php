<?php

namespace App\Services\Mlb\Publicacao;

use App\Services\MlColetaService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Metadados do catálogo do Mercado Livre que alimentam o wizard de anúncios
 * (categorias, ficha técnica, atributos, tipos de anúncio).
 *
 * Usa o APP TOKEN (client_credentials, via MlColetaService::getAppToken()) — não
 * precisa de token de empresa, pois esses dados são públicos. Assim aquecemos o
 * CACHE uma única vez e servimos todas as contas de cliente, mantendo o wizard
 * rápido mesmo com dezenas/centenas de contas.
 *
 * Todas as chamadas degradam graciosamente: em erro HTTP retornam vazio (o
 * chamador trata a ausência) — nunca lançam exceção que derrube a tela.
 */
class MlCatalogoMetaService
{
    private const API_BASE = 'https://api.mercadolibre.com';
    private const SITE_ID  = 'MLB';

    // Metadados de categoria mudam pouco → cache longo (7 dias).
    private const TTL_META = 604800;
    // Preditor depende do texto digitado → cache curto (1h), só p/ aliviar rajadas.
    private const TTL_PREDITOR = 3600;

    public function __construct(private MlColetaService $coleta) {}

    /** Cliente HTTP autenticado com o app token (metadados públicos). */
    private function http(): PendingRequest
    {
        return Http::withToken($this->coleta->getAppToken());
    }

    /**
     * Prediz categorias a partir de um texto livre (título do anúncio).
     * GET /sites/MLB/domain_discovery/search
     *
     * @return array<int, array{category_id?: string, category_name?: string, domain_id?: string}>
     */
    public function preverCategoria(string $q, int $limit = 8): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $chave = 'ml_meta_preditor_' . md5($q . "|{$limit}");

        return Cache::remember($chave, self::TTL_PREDITOR, function () use ($q, $limit) {
            $resp = $this->http()->get(
                self::API_BASE . '/sites/' . self::SITE_ID . '/domain_discovery/search',
                ['q' => $q, 'limit' => $limit],
            );

            return $resp->successful() ? (array) $resp->json() : [];
        });
    }

    /**
     * Detalhe de uma categoria — inclui `settings` (título máx, nº de fotos,
     * pagamento/estoque, catalog_domain) e `children_categories`.
     * GET /categories/{id}
     */
    public function categoria(string $categoryId): array
    {
        return Cache::remember("ml_meta_categoria_{$categoryId}", self::TTL_META, function () use ($categoryId) {
            $resp = $this->http()->get(self::API_BASE . "/categories/{$categoryId}");

            return $resp->successful() ? (array) $resp->json() : [];
        });
    }

    /**
     * Atributos de uma categoria — a fonte do formulário dinâmico. Cada item
     * traz `tags` (required, catalog_required, allow_variations), `value_type`
     * (string/number/number_unit/boolean/list) e, quando `list`, os `values`.
     * GET /categories/{id}/attributes
     *
     * @return array<int, array>
     */
    public function atributos(string $categoryId): array
    {
        return Cache::remember("ml_meta_atributos_{$categoryId}", self::TTL_META, function () use ($categoryId) {
            $resp = $this->http()->get(self::API_BASE . "/categories/{$categoryId}/attributes");

            return $resp->successful() ? (array) $resp->json() : [];
        });
    }

    /**
     * Tipos de anúncio do site (gold_pro=Premium, gold_special=Clássico, free...).
     * GET /sites/MLB/listing_types
     *
     * @return array<int, array>
     */
    public function tiposDeAnuncio(): array
    {
        return Cache::remember('ml_meta_listing_types_' . self::SITE_ID, self::TTL_META, function () {
            $resp = $this->http()->get(self::API_BASE . '/sites/' . self::SITE_ID . '/listing_types');

            return $resp->successful() ? (array) $resp->json() : [];
        });
    }
}
