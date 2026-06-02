<?php

namespace App\Services;

use App\Models\MlbColeta;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MlColetaService — pipeline HTTP da API oficial do Mercado Livre.
 *
 * Orquestra a coleta de inteligência de anúncios usando APENAS o app token
 * (client_credentials, D-01), sem token de empresa. Encadeia:
 *   domain_discovery → products/search (top 10) → highlights → trends → top 5 a fundo.
 *
 * Princípios:
 *   - App token cacheado em `ml_app_token_coleta` (TTL = expires_in - 300) — nunca logado (T-17-08).
 *   - Degradação graciosa: questions/reviews de terceiros indisponíveis (401/403) NÃO abortam (D-02).
 *   - Rate limit: 429 dispara backoff (Retry-After, cap 30s) e a falha de 1 item não derruba o lote (D-03).
 *   - Mineração 100% heurística via MlKeywordMinerService (D-04, D-05) — sem IA nesta fase.
 *   - Privacidade: persiste apenas o TEXTO da pergunta, nunca from_id do comprador (T-17-09).
 *
 * Análogo: app/Services/MercadoLivreService.php (cache de token + get() 401/429 + loop best-effort).
 *
 * @see PATTERNS.md §MlColetaService
 * @see RESEARCH.md §Estrutura JSON do campo resultado
 */
class MlColetaService
{
    private const TOKEN_URL = 'https://api.mercadolibre.com/oauth/token';
    private const API_BASE  = 'https://api.mercadolibre.com';
    private const SITE_ID   = 'MLB';

    // ─── App token (client_credentials) ───────────────────────────────────────

    /**
     * Obtém o app token via client_credentials, cacheado com TTL dinâmico (expires_in - 300).
     *
     * Público para ser exercitado por MlColetaServiceTest::test_app_token_cacheado (D-01).
     * O token vive apenas no cache server-side; NUNCA é logado (T-17-08).
     */
    public function getAppToken(): string
    {
        $cached = Cache::get('ml_app_token_coleta');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'client_credentials',
            'client_id'     => config('services.mercadolivre.client_id'),
            'client_secret' => config('services.mercadolivre.client_secret'),
        ]);

        if (! $response->successful()) {
            // Não inclui o body de credenciais; só o status para diagnóstico
            throw new \RuntimeException('[MLB Coleta] Falha ao obter app token: HTTP ' . $response->status());
        }

        $data      = $response->json();
        $token     = (string) ($data['access_token'] ?? '');
        $expiresIn = (int) ($data['expires_in'] ?? 21600);

        if ($token === '') {
            throw new \RuntimeException('[MLB Coleta] Resposta de token sem access_token');
        }

        // TTL = expires_in - 300 (margem de 5 min); mínimo de 60s por segurança (Pitfall 1)
        Cache::put('ml_app_token_coleta', $token, now()->addSeconds(max(60, $expiresIn - 300)));

        return $token;
    }

    // ─── GET autenticado com tratamento de 429/401/403 ────────────────────────

    /**
     * GET autenticado na API ML. Em 429 faz backoff (Retry-After, cap 30s) e lança;
     * em 401/403 lança (caller decide best-effort); demais erros também lançam.
     */
    private function mlGet(string $endpoint, array $query = []): array
    {
        $response = Http::withToken($this->getAppToken())
            ->timeout(15)
            ->get(self::API_BASE . $endpoint, $query);

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 2);
            sleep(max(1, min($retryAfter, 30)));
            throw new \RuntimeException("[MLB Coleta] Rate limit (429) em {$endpoint}");
        }

        if ($response->status() === 401 || $response->status() === 403) {
            // Best-effort para endpoints opcionais (questions/reviews) — caller trata
            throw new \RuntimeException("[MLB Coleta] Acesso negado ({$response->status()}) em {$endpoint}");
        }

        if (! $response->successful()) {
            throw new \RuntimeException("[MLB Coleta] Erro {$response->status()} em {$endpoint}");
        }

        return $response->json() ?? [];
    }

    // ─── Pipeline principal (contrato consumido pelo MlbColetaJob — Plan 17-02) ─

    /**
     * Executa o pipeline completo de coleta e retorna o array `resultado`.
     *
     * Estrutura de retorno documentada em RESEARCH.md §Estrutura JSON:
     *   meta, ranking_keywords, tendencias, top_duvidas, recomendacao, produtos_analisados.
     */
    public function executarPipeline(MlbColeta $coleta, MlKeywordMinerService $miner): array
    {
        $inicio  = microtime(true);
        $keyword = (string) $coleta->keyword;
        Log::info("[MLB Coleta] Iniciando pipeline coleta {$coleta->id} keyword='{$keyword}'");

        $questionsDisponivel = true;

        // 1. domain_discovery → categoria/domínio a partir da keyword
        $categoriaId   = $coleta->categoria_id ?: null;
        $categoriaNome = null;
        $domainId      = null;
        try {
            $dd       = $this->mlGet('/sites/' . self::SITE_ID . '/domain_discovery/search', ['q' => $keyword, 'limit' => 1]);
            $primeiro = $dd[0] ?? $dd;
            $domainId      = $primeiro['domain_id'] ?? null;
            $categoriaId   = $categoriaId ?: ($primeiro['category_id'] ?? null);
            $categoriaNome = $primeiro['category_name'] ?? ($primeiro['domain_name'] ?? null);
        } catch (\Throwable $e) {
            Log::warning("[MLB Coleta] domain_discovery indisponível: {$e->getMessage()}");
        }

        // 2. products/search → top 10 produtos (ids + títulos); sem paginação (RESEARCH Q3)
        $titulos  = [];
        $produtos = [];
        try {
            $ps = $this->mlGet('/products/search', [
                'status'  => 'active',
                'site_id' => self::SITE_ID,
                'q'       => $keyword,
                'limit'   => 10,
                'offset'  => 0,
            ]);
            foreach (($ps['results'] ?? []) as $p) {
                $titulo = $p['name'] ?? ($p['title'] ?? null);
                if ($titulo) {
                    $titulos[] = $titulo;
                }
                $produtos[] = [
                    'item_id'         => $p['id'] ?? null,
                    'titulo'          => $titulo,
                    'sold_quantity'   => null,           // preenchido no loop a fundo (Pitfall 2)
                    'posicao_ranking' => count($produtos) + 1,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("[MLB Coleta] products/search falhou: {$e->getMessage()}");
        }

        // 3. highlights da categoria → best-sellers (best-effort)
        if ($categoriaId) {
            try {
                $this->mlGet('/highlights/' . self::SITE_ID . '/category/' . $categoriaId);
            } catch (\Throwable $e) {
                Log::warning("[MLB Coleta] highlights indisponível: {$e->getMessage()}");
            }
        }

        // 4. trends MLB[/categoria] → keywords trending (normalizadas p/ casar com o ranking)
        $trends = [];
        try {
            $endpoint = $categoriaId
                ? '/trends/' . self::SITE_ID . '/' . $categoriaId
                : '/trends/' . self::SITE_ID;
            foreach ($this->mlGet($endpoint) as $t) {
                $kw = is_array($t) ? ($t['keyword'] ?? null) : (string) $t;
                if ($kw) {
                    $trends[] = $miner->normalizarToken($kw);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[MLB Coleta] trends indisponível: {$e->getMessage()}");
        }

        // 5. loop top 5 a fundo — best-effort por item (falha de 1 não derruba o lote, D-03)
        $topCinco  = array_slice($produtos, 0, 5);
        $perguntas = [];
        foreach ($topCinco as $idx => $prod) {
            $itemId = $prod['item_id'] ?? null;
            if (! $itemId) {
                continue;
            }
            try {
                $detalhe = $this->mlGet("/items/{$itemId}");
                // Pitfall 2: sold_quantity pode estar oculto/ausente — tratar como null sem TypeError
                $produtos[$idx]['sold_quantity'] = $detalhe['sold_quantity'] ?? null;

                // descrição (best-effort, coletada mas não usada no ranking)
                try {
                    $this->mlGet("/items/{$itemId}/description");
                } catch (\Throwable $e) {
                    Log::warning("[MLB Coleta] descrição indisponível item {$itemId}: {$e->getMessage()}");
                }

                // perguntas (best-effort; 401/403 → questions_disponivel=false, D-02)
                [$qs, $ok] = $this->fetchPerguntas($itemId);
                if (! $ok) {
                    $questionsDisponivel = false;
                }
                $perguntas = array_merge($perguntas, $qs);
            } catch (\Throwable $e) {
                // Inclui o caso 429 (já fez backoff em mlGet) — loga e segue
                Log::warning("[MLB Coleta] Falha ao buscar detalhe item {$itemId}: {$e->getMessage()}");
            }
            usleep(200000); // 200 ms entre itens (D-03 backoff preventivo)
        }

        // 6. mineração estatística (heurística — D-04, D-05)
        $ranking      = $miner->rankingKeywords($titulos, $trends);
        $topDuvidas   = $miner->agruparPerguntas($perguntas);
        $recomendacao = $miner->recomendacaoHeuristica($ranking, $topDuvidas, $keyword);

        // 7. montagem do resultado (estrutura RESEARCH.md §Estrutura JSON)
        $segundos = (int) round(microtime(true) - $inicio);
        Log::info("[MLB Coleta] Concluído coleta {$coleta->id} keyword='{$keyword}' em {$segundos}s");

        return [
            'meta' => [
                'keyword'                   => $keyword,
                'categoria_id'              => $categoriaId,
                'categoria_nome'            => $categoriaNome,
                'domain_id'                 => $domainId,
                'total_produtos_analisados' => count($produtos),
                'total_a_fundo'             => count($topCinco),
                'questions_disponivel'      => $questionsDisponivel,
                'processado_em_segundos'    => $segundos,
            ],
            'ranking_keywords'    => $ranking,
            'tendencias'          => array_values(array_unique($trends)),
            'top_duvidas'         => $topDuvidas,
            'recomendacao'        => $recomendacao,
            'produtos_analisados' => $produtos,
        ];
    }

    /**
     * Busca perguntas de um item (best-effort).
     *
     * Retorna [perguntas[], disponivel] — `disponivel=false` quando o app token não tem
     * acesso (401/403), sinalizando questions_disponivel=false sem abortar a coleta (D-02).
     * Privacidade (T-17-09): persiste apenas o texto da pergunta, nunca o from_id.
     */
    private function fetchPerguntas(string $itemId): array
    {
        try {
            $resp      = $this->mlGet('/questions/search', ['item' => $itemId]);
            $perguntas = [];
            foreach (($resp['questions'] ?? []) as $q) {
                $perguntas[] = ['text' => $q['text'] ?? ''];
            }
            return [$perguntas, true];
        } catch (\Throwable $e) {
            Log::warning("[MLB Coleta] questions indisponível item {$itemId}: {$e->getMessage()}");
            return [[], false];
        }
    }
}
