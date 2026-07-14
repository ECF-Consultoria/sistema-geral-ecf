<?php

namespace App\Services\Mlb\Publicacao;

use App\Models\Company;
use App\Services\MercadoLivreService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gerencia grades de tamanho do Mercado Livre para o wizard de anúncios (WIZ-06).
 *
 * Responsabilidades:
 *  - Detectar se a categoria exige grade de tamanho (atributo SIZE_GRID_ID /
 *    value_type grid_id ou grid_row_id).
 *  - Listar as grades disponíveis do vendedor via POST /catalog/charts/search,
 *    cacheando 1h por company + domínio (grades mudam raramente — T-77-09).
 *  - Criar uma grade customizada via POST /catalog/charts.
 *
 * Usa token de empresa (não app token) pois o endpoint /catalog/charts/search
 * exige seller_id — obter via MlToken::ml_user_id (confirmado nos spikes Fase A/B,
 * MercadoLivreService usa $token->ml_user_id como Seller ID em /users/{id}).
 */
class MlGradeService
{
    private const API_BASE  = 'https://api.mercadolibre.com';
    private const SITE_ID   = 'MLB';

    // Grades mudam raramente → cache de 1 hora por company+domínio (T-77-09)
    private const TTL_GRADE = 3600;

    public function __construct(private MercadoLivreService $ml) {}

    // ─── Detecção ────────────────────────────────────────────────────────────

    /**
     * Verifica se a categoria exige grade de tamanho.
     *
     * Retorna true quando qualquer atributo tem:
     *  - value_type igual a 'grid_id' ou 'grid_row_id' (STACK.md linha 161), OU
     *  - id igual a 'SIZE_GRID_ID' (convenção da API ML para grade de tamanho).
     *
     * @param  array $atributos  Lista de atributos retornada pelo endpoint /categories/{id}/attributes
     * @return bool              True = categoria de moda com grade de tamanho
     */
    public function categoriaExigeGrade(array $atributos): bool
    {
        foreach ($atributos as $attr) {
            // Detecção pelo value_type (grid_id ou grid_row_id)
            if (in_array($attr['value_type'] ?? '', ['grid_id', 'grid_row_id'], true)) {
                return true;
            }

            // Detecção explícita pelo id do atributo (SIZE_GRID_ID)
            if (($attr['id'] ?? '') === 'SIZE_GRID_ID') {
                return true;
            }
        }

        return false;
    }

    // ─── Listagem (cacheada) ─────────────────────────────────────────────────

    /**
     * Lista as grades de tamanho disponíveis para o vendedor no domínio informado.
     *
     * Usa POST /catalog/charts/search com body {domain_id, site_id, seller_id, offset, limit}.
     * Cacheado por 1 hora por company + domínio (grades mudam raramente — T-77-09).
     *
     * Degrada graciosamente: em qualquer falha (HTTP ou exceção) retorna {results: []}
     * sem propagar o erro — o front exibe o seletor vazio em vez de travar o wizard.
     *
     * @param  Company $company   Empresa com conta ML conectada (token obrigatório)
     * @param  string  $domainId  Domínio da categoria (ex.: "SNEAKERS", "SHIRTS")
     * @return array{results: array, paging?: array}
     */
    public function listarGrades(Company $company, string $domainId): array
    {
        $chave = "ml_grade_{$company->id}_{$domainId}";

        return Cache::remember($chave, self::TTL_GRADE, function () use ($company, $domainId) {
            try {
                $http     = $this->http($company);
                $sellerId = $this->sellerId($company);

                $resp = $http->post(self::API_BASE . '/catalog/charts/search', [
                    'domain_id' => $domainId,
                    'site_id'   => self::SITE_ID,
                    'seller_id' => $sellerId,
                    'offset'    => 0,
                    'limit'     => 50,
                ]);

                // Degradação graciosa: retorna vazio em falha HTTP (T-77-09)
                return $resp->successful() ? $resp->json() : ['results' => []];
            } catch (\Throwable $e) {
                // Falha de rede, token inválido etc. → log + vazio (não travar o wizard)
                Log::error("[MLB Grade] Falha ao listar grades empresa {$company->id} domínio {$domainId}: {$e->getMessage()}");

                return ['results' => []];
            }
        });
    }

    // ─── Criação ─────────────────────────────────────────────────────────────

    /**
     * Cria uma grade customizada na conta do vendedor via POST /catalog/charts.
     *
     * @param  Company $company  Empresa com conta ML conectada
     * @param  array   $dados    Body da grade: names, domain_id, main_attribute, attributes, rows
     * @return string|null       ID da grade criada ("chart_xxx") ou null em falha
     */
    public function criarGrade(Company $company, array $dados): ?string
    {
        try {
            $http     = $this->http($company);
            $sellerId = $this->sellerId($company);

            // Complementa o body com campos obrigatórios do contexto da empresa
            $body = array_merge($dados, [
                'site_id'   => self::SITE_ID,
                'seller_id' => $sellerId,
            ]);

            $resp = $http->post(self::API_BASE . '/catalog/charts', $body);

            return $resp->json('id');
        } catch (\Throwable $e) {
            Log::error("[MLB Grade] Falha ao criar grade empresa {$company->id}: {$e->getMessage()}");

            return null;
        }
    }

    // ─── Helpers privados ────────────────────────────────────────────────────

    /**
     * Cliente HTTP autenticado com o token de empresa.
     *
     * O seller_id (id ML do vendedor) = MlToken::ml_user_id (CONFIRMADO — não há campo
     * "seller_id" separado; MercadoLivreService usa $token->ml_user_id como Seller ID
     * em /users/{id}, linhas 468/493).
     *
     * @throws \RuntimeException  Quando a empresa não possui token ML válido
     */
    private function http(Company $company): PendingRequest
    {
        $token = $this->ml->ensureValidToken($company);

        if ($token === null) {
            throw new \RuntimeException("[MLB Grade] Empresa {$company->id} sem token ML válido.");
        }

        return Http::withToken($token->access_token);
    }

    /**
     * Retorna o seller_id (ml_user_id) do token da empresa.
     *
     * @throws \RuntimeException  Quando a empresa não possui token ML válido
     */
    private function sellerId(Company $company): string
    {
        $token = $this->ml->ensureValidToken($company);

        if ($token === null) {
            throw new \RuntimeException("[MLB Grade] Empresa {$company->id} sem token ML para obter seller_id.");
        }

        return (string) $token->ml_user_id;
    }
}
