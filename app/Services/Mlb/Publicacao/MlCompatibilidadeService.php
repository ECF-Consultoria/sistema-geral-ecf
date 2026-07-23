<?php

namespace App\Services\Mlb\Publicacao;

use App\Models\Company;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Compatibilidades de autopeças (veículo × anúncio).
 *
 * No Mercado Livre a compatibilidade NÃO vai no POST /items — é um recurso
 * separado, aplicado DEPOIS de criar o item (POST /items/{id}/compatibilities).
 * Categorias de autopeças que exigem compatibilidade e ficam sem ela recebem a
 * tag `incomplete_compatibilities` e são pausadas — por isso aplicamos a compat
 * logo após publicar (best-effort, sem abortar a publicação).
 *
 * Descoberta de veículos: cascata de `top_values` no domínio MLB-CARS_AND_VANS
 * (marca → modelo → ano). Usa APP TOKEN (dados públicos do catálogo, cacheáveis);
 * a aplicação usa o token da empresa (escreve no item do cliente).
 *
 * TUDO degrada graciosamente: detecção/cascata retornam vazio em erro (o picker
 * simplesmente não aparece / fica vazio) e a aplicação nunca lança para o fluxo
 * de publicação. Assim, categorias que não são autopeças ficam 100% intactas.
 *
 * Fonte (pesquisa 2026-07-23, docs oficiais ML):
 *   POST /items/{id}/compatibilities  (products_families com BRAND/MODEL/VEHICLE_YEAR)
 *   POST /catalog_domains/MLB-CARS_AND_VANS/attributes/{ATTR}/top_values
 *   creation_source obrigatório; domínio de veículos BR = MLB-CARS_AND_VANS.
 */
class MlCompatibilidadeService
{
    private const API_BASE = 'https://api.mercadolibre.com';

    /** Domínio de veículos do Brasil (marca/modelo/ano). */
    public const DOMINIO_VEICULOS = 'MLB-CARS_AND_VANS';

    /** Raiz "Acessórios para Veículos" — âncora da heurística de "é autopeça". */
    private const RAIZ_VEICULOS = 'MLB5672';

    private const TTL_TOP_VALUES = 604800; // 7 dias (catálogo de veículos muda pouco)

    public function __construct(
        private MlColetaService $coleta,
        private MlCatalogoMetaService $meta,
        private MercadoLivreService $ml,
    ) {}

    /**
     * A categoria aceita compatibilidade de veículos? Heurística barata que reusa
     * o cache de categoria (path_from_root): categorias sob "Acessórios para
     * Veículos" (MLB5672) ou cujo caminho cite peças/veículos. Best-effort — se
     * não reconhecer, retorna aceita=false (o picker não aparece).
     *
     * @return array{aceita: bool, domain_id: string}
     */
    public function aceitaCompatibilidades(string $categoryId): array
    {
        try {
            $cat  = $this->meta->categoria($categoryId);
            $path = (array) data_get($cat, 'path_from_root', []);

            $aceita = collect($path)->contains(function ($p) {
                $id   = (string) data_get($p, 'id', '');
                $nome = mb_strtolower((string) data_get($p, 'name', ''));

                return $id === self::RAIZ_VEICULOS
                    || str_contains($nome, 'acessórios para veículos')
                    || str_contains($nome, 'peças');
            });

            return ['aceita' => $aceita, 'domain_id' => self::DOMINIO_VEICULOS];
        } catch (\Throwable $e) {
            Log::warning("[MLB Compat] Falha ao detectar compat da categoria {$categoryId}: {$e->getMessage()}");

            return ['aceita' => false, 'domain_id' => self::DOMINIO_VEICULOS];
        }
    }

    /** Marcas de veículo (top_values BRAND). @return array<int, array{id: string, name: string}> */
    public function marcas(): array
    {
        return $this->topValues('BRAND', []);
    }

    /** Modelos de uma marca (top_values MODEL). @return array<int, array{id: string, name: string}> */
    public function modelos(string $brandId): array
    {
        if ($brandId === '') {
            return [];
        }

        return $this->topValues('MODEL', [['id' => 'BRAND', 'value_id' => $brandId]]);
    }

    /** Anos de um modelo (top_values VEHICLE_YEAR). @return array<int, array{id: string, name: string}> */
    public function anos(string $brandId, string $modelId): array
    {
        if ($brandId === '' || $modelId === '') {
            return [];
        }

        return $this->topValues('VEHICLE_YEAR', [
            ['id' => 'BRAND', 'value_id' => $brandId],
            ['id' => 'MODEL', 'value_id' => $modelId],
        ]);
    }

    /**
     * Cascata de valores do domínio de veículos.
     * POST /catalog_domains/MLB-CARS_AND_VANS/attributes/{ATTR}/top_values
     *
     * Cacheado por atributo + atributos conhecidos. Degrada para [] em erro.
     *
     * @param  array  $known  atributos já escolhidos (ex.: BRAND) para filtrar a cascata
     * @return array<int, array{id: string, name: string}>
     */
    private function topValues(string $attr, array $known): array
    {
        $chave = 'ml_compat_topvalues_' . md5($attr . '|' . json_encode($known));

        return Cache::remember($chave, self::TTL_TOP_VALUES, function () use ($attr, $known) {
            try {
                $resp = Http::withToken($this->coleta->getAppToken())->post(
                    self::API_BASE . '/catalog_domains/' . self::DOMINIO_VEICULOS . "/attributes/{$attr}/top_values",
                    $known ? ['known_attributes' => $known] : [],
                );

                if (! $resp->successful()) {
                    return [];
                }

                // Normaliza para {id, name}: o retorno traz {id, name, metric} por valor.
                return collect((array) $resp->json())
                    ->map(fn ($v) => [
                        'id'   => (string) data_get($v, 'id', data_get($v, 'value_id', '')),
                        'name' => (string) data_get($v, 'name', data_get($v, 'value_name', '')),
                    ])
                    ->filter(fn ($v) => $v['id'] !== '' && $v['name'] !== '')
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                Log::warning("[MLB Compat] top_values {$attr} falhou: {$e->getMessage()}");

                return [];
            }
        });
    }

    /**
     * Aplica as compatibilidades a um item JÁ publicado.
     * POST /items/{itemId}/compatibilities  (products_families).
     *
     * Cada veículo vira uma "família" com BRAND/MODEL/VEHICLE_YEAR (só os que têm id).
     * `creation_source: DEFAULT` (obrigatório). NÃO lança — devolve o total aplicado
     * (0 em falha); o chamador (publicar) trata como best-effort.
     *
     * @param  array  $veiculos  [{brand_id, model_id, year_id?}]
     * @return int  quantidade de famílias enviadas (0 se nada ou falha)
     */
    public function aplicar(Company $company, string $itemId, array $veiculos): int
    {
        $familias = collect($veiculos)
            ->map(function ($v) {
                $attrs = array_values(array_filter([
                    ! empty($v['brand_id']) ? ['id' => 'BRAND',        'value_id' => (string) $v['brand_id']] : null,
                    ! empty($v['model_id']) ? ['id' => 'MODEL',        'value_id' => (string) $v['model_id']] : null,
                    ! empty($v['year_id'])  ? ['id' => 'VEHICLE_YEAR', 'value_id' => (string) $v['year_id']]  : null,
                ]));

                if (empty($attrs)) {
                    return null;
                }

                return [
                    'domain_id'       => self::DOMINIO_VEICULOS,
                    'creation_source' => 'DEFAULT',
                    'attributes'      => $attrs,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (empty($familias)) {
            return 0;
        }

        try {
            $this->ml->post($company, "/items/{$itemId}/compatibilities", [
                'products_families' => $familias,
            ]);

            Log::info("[MLB Compat] {$itemId}: " . count($familias) . ' compatibilidade(s) aplicada(s) (empresa ' . $company->id . ')');

            return count($familias);
        } catch (\Throwable $e) {
            // Best-effort: o item já existe; a falta de compat vira tag incomplete_compatibilities
            // no ML (o publicador ajusta lá). NÃO derruba a publicação.
            Log::warning("[MLB Compat] Falha ao aplicar compat no item {$itemId} (empresa {$company->id}): {$e->getMessage()}");

            return 0;
        }
    }
}
