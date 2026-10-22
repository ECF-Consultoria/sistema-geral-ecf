<?php

namespace App\Services\Mlb\Publicacao;

use App\Services\MlColetaService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
 *
 * Só entra no cache resposta 2xx NÃO vazia. Falha (não-2xx, rede) ou vazio
 * nunca vai para a chave longa — antes um `[]` de falha passageira ficava 7 dias
 * (categoria/atributos) ou 1 h (preditor) — e um `[]` antigo encontrado no
 * cache conta como ausente (mesma regra do `CategoriaSugestaoService::categoriasEmLote`).
 */
class MlCatalogoMetaService
{
    private const API_BASE = 'https://api.mercadolibre.com';
    private const SITE_ID  = 'MLB';

    // Metadados de categoria mudam pouco → cache longo (7 dias).
    private const TTL_META = 604800;
    // Preditor depende do texto digitado → cache curto (1h), só p/ aliviar rajadas.
    private const TTL_PREDITOR = 3600;
    /**
     * Resposta vazia ou falha do preditor fica só 60 s, numa chave À PARTE.
     *
     * Em 22/10/2026 o `domain_discovery` passou a devolver 200 `[]` para qualquer
     * termo: o `[]` ia para o cache de 1 h e, com o ML de volta, a busca seguia
     * "Não deu para buscar agora" por mais uma hora. Não cachear nada faria o
     * preparo da IA (vários rascunhos do mesmo produto) e a busca repetida do
     * Portal martelarem o ML a cada tentativa enquanto ele está fora; 60 s é o
     * teto do atraso depois que ele volta.
     */
    private const TTL_PREDITOR_VAZIO = 60;

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
        $chaveVazio = 'ml_meta_preditor_vazio_' . md5($q . "|{$limit}");

        $guardado = Cache::get($chave);
        if (is_array($guardado) && $guardado !== []) {
            return $guardado;
        }
        if (Cache::has($chaveVazio)) {
            return [];
        }

        $lido = $this->lerLista(
            self::API_BASE . '/sites/' . self::SITE_ID . '/domain_discovery/search',
            ['q' => $q, 'limit' => $limit],
        );

        if ($lido === null || $lido === []) {
            Cache::put($chaveVazio, true, self::TTL_PREDITOR_VAZIO);

            return [];
        }

        Cache::put($chave, $lido, self::TTL_PREDITOR);

        return $lido;
    }

    /**
     * Detalhe de uma categoria — inclui `settings` (título máx, nº de fotos,
     * pagamento/estoque, catalog_domain) e `children_categories`.
     * GET /categories/{id}
     */
    public function categoria(string $categoryId): array
    {
        return $this->lembrarSoSucesso(
            "ml_meta_categoria_{$categoryId}",
            self::TTL_META,
            self::API_BASE . "/categories/{$categoryId}",
        );
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
        return $this->lembrarSoSucesso(
            "ml_meta_atributos_{$categoryId}",
            self::TTL_META,
            self::API_BASE . "/categories/{$categoryId}/attributes",
        );
    }

    /**
     * Tipos de anúncio do site (gold_pro=Premium, gold_special=Clássico, free...).
     * GET /sites/MLB/listing_types
     *
     * @return array<int, array>
     */
    public function tiposDeAnuncio(): array
    {
        return $this->lembrarSoSucesso(
            'ml_meta_listing_types_' . self::SITE_ID,
            self::TTL_META,
            self::API_BASE . '/sites/' . self::SITE_ID . '/listing_types',
        );
    }

    // ─── Cache só de sucesso ───────────────────────────────────────────────

    /**
     * Lê do cache; sem nada útil lá, vai ao ML e só guarda resposta 2xx não vazia.
     * Falha ou vazio voltam `[]` e a próxima chamada tenta o ML de novo.
     */
    private function lembrarSoSucesso(string $chave, int $ttl, string $url): array
    {
        $guardado = Cache::get($chave);
        if (is_array($guardado) && $guardado !== []) {
            return $guardado;
        }

        $lido = $this->lerLista($url);
        if ($lido === null || $lido === []) {
            return [];
        }

        Cache::put($chave, $lido, $ttl);

        return $lido;
    }

    /**
     * GET no ML. Nulo = falhou (não-2xx, corpo que não é JSON, rede) — distinto
     * do `[]` de um 2xx vazio, para quem precisar saber.
     */
    private function lerLista(string $url, array $query = []): ?array
    {
        try {
            $resp = $this->http()->get($url, $query);
        } catch (\Throwable $e) {
            Log::warning('[MlCatalogoMeta] leitura falhou: ' . $url . ' — ' . $e->getMessage());

            return null;
        }

        if (! $resp->successful()) {
            return null;
        }

        $json = $resp->json();

        return is_array($json) ? $json : null;
    }
}
