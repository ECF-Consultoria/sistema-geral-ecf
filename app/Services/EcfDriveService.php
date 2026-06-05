<?php

// Phase 20 — wrapper HTTP do sistema externo ECF Drive (substitui sync SFTP do ML).
// Phase 22 — expandido com 18 métodos novos cobrindo /clientes, /sellers, /carteira, /signals, /relatorios.
// Documentação da API: https://files.ecfconsultoria.com.br/api/v1

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Wrapper HTTP para o sistema externo ECF Drive.
 *
 * O ECF Drive é desenvolvido pelo próprio usuário e abstrai o SFTP do Mercado
 * Livre, expondo os dados como API REST autenticada por API key.
 *
 * Phase 20 — substituiu o pipeline frágil XLSX-via-SFTP para /grants (listGrants, cliente, ping, grantsExpirandoEm).
 * Phase 22 — expandido para cobrir /clientes, /sellers, /carteira, /signals, /relatorios
 *            com helpers privados get() + cacheKey() e cache estratégico por endpoint.
 */
class EcfDriveService
{
    /**
     * Métricas aceitas pelo endpoint /sellers/ranking (fonte: API-GUIDE.md §5).
     * Atualizar se o guide expandir a lista no futuro.
     */
    private const MET_VALIDAS = [
        'tgmv_lc',
        'f_tgmv_lc',
        'tsi',
        'f_tsi',
        'tgmv_lc_pads',
        'inv_pads',
        'tgmv_lc_fbm',
        'tgmv_lc_flex',
        'tgmv_lc_me2',
        'visitas',
        'total_livelistings',
        'score_final_full',
        'score_final_pads',
    ];

    private string $base;
    private string $key;

    public function __construct(?string $base = null, ?string $key = null)
    {
        $this->base = rtrim($base ?? (string) config('services.ecf.base'), '/');
        $this->key  = $key ?? (string) config('services.ecf.key', '');
    }

    // ─── Clientes ───

    /**
     * Lista grants paginados da API ECF Drive.
     *
     * Filtros aceitos:
     *  - expirando_em_dias: int
     *  - expirado: bool|string
     *  - from: 'YYYY-MM-DD'
     *  - to: 'YYYY-MM-DD'
     *  - search: string
     *  - page: int (default 1)
     *  - limit: int (default 100, max API-dependente)
     *
     * Retorna array decodificado: ['data' => Grant[], 'total' => int, ...]
     *
     * @throws \RuntimeException quando HTTP != 2xx após retry.
     */
    public function listGrants(array $filtros = []): array
    {
        // Refatorado na Phase 22: delega para o helper get() — comportamento idêntico ao Phase 20.
        return $this->get('/clientes/grants', $filtros);
    }

    /**
     * Detalhe de 1 cliente/grant por custId.
     *
     * Retorna array com: custId, razaoSocial, cnpj, email, telefone, segmento,
     * grantInicio, grantFim, diasParaExpirar, expirado.
     *
     * @throws \RuntimeException em 404 ou qualquer erro HTTP.
     */
    public function cliente(string $custId): array
    {
        // Refatorado na Phase 22: delega para o helper get() — comportamento idêntico ao Phase 20.
        return $this->get("/clientes/{$custId}");
    }

    /**
     * Helper cacheado de 5 minutos — retorna grants que expiram em N dias.
     *
     * Cache key: "ecf_drive:expirando:{dias}" — formato preservado da Phase 20 (D-03).
     * A UI de /grants NÃO usa este método diretamente (usa tabela local).
     * Método disponível para futuros consumers: dashboard, alertas, etc.
     *
     * @return array Mesmo shape de listGrants().
     */
    public function grantsExpirandoEm(int $dias): array
    {
        // PRESERVA a chave existente da Phase 20 — pode haver caches ativos em prod (D-03 do PLAN).
        return Cache::remember("ecf_drive:expirando:{$dias}", 300, function () use ($dias) {
            return $this->listGrants(['expirando_em_dias' => $dias]);
        });
    }

    /**
     * Lista paginada de sellers cadastrados na ECF Drive.
     *
     * Filtros aceitos: ativo (bool), segmento (string), grant_termina_em_dias (int),
     * page (int), limit (int).
     *
     * Cache: 5min (TTL=300s) — endpoint usado em listas executivas com refresh frequente.
     *
     * @see API-GUIDE.md §4 (GET /clientes)
     * @return array Estrutura ['data' => Cliente[], 'total' => int]
     */
    public function listClientes(array $filtros = []): array
    {
        return Cache::remember(
            $this->cacheKey('/clientes', $filtros),
            300,
            fn () => $this->get('/clientes', $filtros),
        );
    }

    /**
     * Histórico de snapshots de 1 cliente ao longo do tempo (auditoria).
     *
     * SEM cache — payload pode ser pesado e acesso é raro (proxy on-demand).
     *
     * @see API-GUIDE.md §4 (GET /clientes/{custId}/historico)
     */
    public function clienteHistorico(string $custId): array
    {
        return $this->get("/clientes/{$custId}/historico");
    }

    /**
     * Sellers com `acao_recomendada_ccp` não-nula vinda do ML (inbox de intervenção comercial).
     *
     * Cache: 5min (TTL=300s).
     *
     * @see API-GUIDE.md §4 (GET /clientes/acoes-pendentes)
     */
    public function acoesPendentes(): array
    {
        return Cache::remember(
            $this->cacheKey('/clientes/acoes-pendentes'),
            300,
            fn () => $this->get('/clientes/acoes-pendentes'),
        );
    }

    // ─── Sellers ───

    /**
     * Snapshot consolidado de 1 seller (dados + medalha atual + métrica mensal atual).
     *
     * SEM cache — proxy on-demand, usado quando o caller já decidiu mostrar este seller.
     *
     * @see API-GUIDE.md §5 (GET /sellers/{custId})
     */
    public function seller(string $custId): array
    {
        return $this->get("/sellers/{$custId}");
    }

    /**
     * Série temporal mensal das métricas do seller.
     *
     * Filtros aceitos: from (YYYY-MM ou YYYYMM), to, programa (CPP|POLOS|CDP),
     * fields (default=essenciais, '*'=todos os campos, 'raw'=inclui raw_data JSON
     * com 100+ campos originais do ML), page, limit.
     *
     * Cache: 1h (TTL=3600s) — métricas mensais atualizam D-1 após sync ECF.
     *
     * @see API-GUIDE.md §5 (GET /sellers/{custId}/metricas/mensal)
     */
    public function sellerMetricasMensal(string $custId, array $filtros = []): array
    {
        $path = "/sellers/{$custId}/metricas/mensal";
        return Cache::remember(
            $this->cacheKey($path, $filtros),
            3600,
            fn () => $this->get($path, $filtros),
        );
    }

    /**
     * Série temporal diária das métricas do seller (granularidade fina).
     *
     * Filtros aceitos: from, to, programa, fields, page, limit.
     * SEM cache — usado raramente (drill-down de curva intra-mês).
     *
     * @see API-GUIDE.md §5 (GET /sellers/{custId}/metricas/diario)
     */
    public function sellerMetricasDiario(string $custId, array $filtros = []): array
    {
        return $this->get("/sellers/{$custId}/metricas/diario", $filtros);
    }

    /**
     * Histórico de NIVEL_SOLUCION do seller mês a mês (promoções/rebaixamentos).
     *
     * Cache: 6h (TTL=21600s) — medalhas mudam mensalmente, dado estável.
     *
     * @see API-GUIDE.md §5 (GET /sellers/{custId}/medalhas)
     */
    public function sellerMedalhas(string $custId): array
    {
        $path = "/sellers/{$custId}/medalhas";
        return Cache::remember(
            $this->cacheKey($path),
            21600,
            fn () => $this->get($path),
        );
    }

    /**
     * Alertas detectados para 1 seller específico (filtragem server-side).
     *
     * Cache: 5min (TTL=300s).
     *
     * @see API-GUIDE.md §5 (GET /sellers/{custId}/signals)
     */
    public function sellerSignals(string $custId): array
    {
        $path = "/sellers/{$custId}/signals";
        return Cache::remember(
            $this->cacheKey($path),
            300,
            fn () => $this->get($path),
        );
    }

    /**
     * Top N sellers por métrica.
     *
     * Métricas válidas (fonte: API-GUIDE.md §5 — manter alinhado com MET_VALIDAS):
     *   tgmv_lc, f_tgmv_lc, tsi, f_tsi, tgmv_lc_pads, inv_pads, tgmv_lc_fbm,
     *   tgmv_lc_flex, tgmv_lc_me2, visitas, total_livelistings,
     *   score_final_full, score_final_pads.
     *
     * Cache: 1h (TTL=3600s).
     *
     * @throws \InvalidArgumentException quando $metrica não está em MET_VALIDAS.
     * @see API-GUIDE.md §5 (GET /sellers/ranking)
     */
    public function ranking(string $metrica, int $top = 20, ?string $programa = null, bool $asc = false): array
    {
        if (! in_array($metrica, self::MET_VALIDAS, true)) {
            throw new \InvalidArgumentException(
                "Métrica '{$metrica}' não suportada pelo endpoint /sellers/ranking. "
                . "Válidas: " . implode(', ', self::MET_VALIDAS)
            );
        }

        $params = array_filter([
            'metrica'  => $metrica,
            'top'      => $top,
            'programa' => $programa,
            'asc'      => $asc ? 'true' : null,  // só envia se true (D-05 do PLAN)
        ], fn ($v) => $v !== null);

        $path = '/sellers/ranking';
        return Cache::remember(
            $this->cacheKey($path, $params),
            3600,
            fn () => $this->get($path, $params),
        );
    }

    /**
     * Compara lado-a-lado até 20 sellers no mesmo período.
     *
     * SEM cache — query ad-hoc disparada pelo usuário em fluxo interativo.
     *
     * @throws \InvalidArgumentException quando lista vazia ou tem > 20 cust_ids.
     * @see API-GUIDE.md §5 (GET /sellers/comparar)
     */
    public function compararSellers(array $custIds, ?string $timMonthId = null): array
    {
        $count = count($custIds);
        if ($count < 1 || $count > 20) {
            throw new \InvalidArgumentException(
                "compararSellers aceita de 1 a 20 cust_ids; recebido {$count}."
            );
        }

        $params = array_filter([
            'cust_ids'     => implode(',', $custIds),
            'tim_month_id' => $timMonthId,
        ], fn ($v) => $v !== null && $v !== '');

        return $this->get('/sellers/comparar', $params);
    }

    // ─── Carteira ───

    /**
     * Mês mais recente + comparação MoM (delta absoluto e %) para GMV, vendas,
     * sellers ativos, ADS, visitas — dashboard executivo number-one.
     *
     * Cache: 5min (TTL=300s) — refresh on view.
     *
     * @see API-GUIDE.md §6 (GET /carteira/resumo)
     */
    public function carteiraResumo(): array
    {
        return Cache::remember(
            $this->cacheKey('/carteira/resumo'),
            300,
            fn () => $this->get('/carteira/resumo'),
        );
    }

    /**
     * Série temporal agregada da carteira (default: 12 meses retroativos).
     *
     * Parâmetros: $periodicidade ('mensal'|'diario'), filtros adicionais (from, to).
     * Cache: 24h (TTL=86400s) — pull diário, dado estável até virada de dia.
     *
     * @see API-GUIDE.md §6 (GET /carteira/historico)
     */
    public function carteiraHistorico(string $periodicidade = 'mensal', array $filtros = []): array
    {
        $params = array_merge(['periodicidade' => $periodicidade], $filtros);
        $path = '/carteira/historico';
        return Cache::remember(
            $this->cacheKey($path, $params),
            86400,
            fn () => $this->get($path, $params),
        );
    }

    /**
     * Distribuição de sellers por NIVEL_SOLUCION (pizza/barras).
     *
     * Cache: 1h (TTL=3600s).
     *
     * @see API-GUIDE.md §6 (GET /carteira/distribuicao/medalhas)
     */
    public function carteiraDistribuicaoMedalhas(?string $timMonthId = null): array
    {
        $params = $timMonthId ? ['tim_month_id' => $timMonthId] : [];
        $path = '/carteira/distribuicao/medalhas';
        return Cache::remember(
            $this->cacheKey($path, $params),
            3600,
            fn () => $this->get($path, $params),
        );
    }

    /**
     * Decomposição da carteira por uma dimensão.
     *
     * Dimensões suportadas pelo guide: programa, frete, cluster, localidade
     * (validação fina delegada à API remota — recebemos 400 se for inválida).
     *
     * Cache: 1h (TTL=3600s).
     *
     * @see API-GUIDE.md §6 (GET /carteira/breakdown)
     */
    public function carteiraBreakdown(string $dimensao, ?string $timMonthId = null): array
    {
        $params = array_filter([
            'dimensao'     => $dimensao,
            'tim_month_id' => $timMonthId,
        ], fn ($v) => $v !== null && $v !== '');

        $path = '/carteira/breakdown';
        return Cache::remember(
            $this->cacheKey($path, $params),
            3600,
            fn () => $this->get($path, $params),
        );
    }

    /**
     * Matriz cruzada de 2 ou mais dimensões (segmentação analítica).
     *
     * $dimensoes deve ser string CSV com nomes de dimensão — ex: 'programa,cluster',
     * 'programa,nivel_solucion', 'cluster,h_l'.
     *
     * Cache: 1h (TTL=3600s).
     *
     * @throws \InvalidArgumentException quando $dimensoes é vazio (após trim).
     * @see API-GUIDE.md §6 (GET /carteira/segmentacao)
     */
    public function carteiraSegmentacao(string $dimensoes): array
    {
        if (trim($dimensoes) === '') {
            throw new \InvalidArgumentException(
                "carteiraSegmentacao exige 'dimensoes' como string CSV não-vazia (ex: 'programa,cluster')."
            );
        }

        $params = ['dimensoes' => $dimensoes];
        $path = '/carteira/segmentacao';
        return Cache::remember(
            $this->cacheKey($path, $params),
            3600,
            fn () => $this->get($path, $params),
        );
    }

    // ─── Signals ───

    /**
     * Lista signals (alertas automáticos) com filtros.
     *
     * Filtros aceitos: event_type, severity (info|warning|critical), cust_id,
     * acked (true|false), from, to, page, limit.
     *
     * Cache: 1min (TTL=60s) — TTL muito curto até webhook real-time da Phase 26
     * substituir o polling.
     *
     * @see API-GUIDE.md §7 (GET /signals)
     */
    public function listSignals(array $filtros = []): array
    {
        $path = '/signals';
        return Cache::remember(
            $this->cacheKey($path, $filtros),
            60,
            fn () => $this->get($path, $filtros),
        );
    }

    /**
     * Marca um signal como visto (POST /signals/{id}/ack).
     *
     * Invalida a chave de cache da inbox padrão ('acked=false, limit=50, page=1' — D-04 do PLAN).
     * Outras combinações de filtros expiram naturalmente em 1min.
     *
     * @return array Resposta JSON da API.
     * @throws \RuntimeException em HTTP != 2xx.
     * @see API-GUIDE.md §7 (POST /signals/{id}/ack)
     */
    public function ackSignal(int $id): array
    {
        $response = $this->http()->post("/signals/{$id}/ack");

        if (! $response->successful()) {
            throw new \RuntimeException(
                "ECF Drive ackSignal({$id}) falhou: HTTP {$response->status()} - {$response->body()}"
            );
        }

        // Invalidação determinística da chave-padrão da inbox (D-04 do PLAN)
        $inboxKey = $this->cacheKey('/signals', ['acked' => false, 'limit' => 50, 'page' => 1]);
        Cache::forget($inboxKey);

        return $response->json() ?? [];
    }

    // ─── Relatórios ───

    /**
     * Relatório executivo mensal consolidado — resumo + histórico + breakdowns
     * + rankings + signals em um único request.
     *
     * Sem $timMonthId → API retorna o relatório do último mês emitido.
     * Com $timMonthId (formato 'YYYYMM') → relatório daquele mês específico.
     *
     * Cache: 24h (TTL=86400s) — relatório é estável após geração mensal
     * (dia 5 às 09:00 UTC conforme API-GUIDE.md §8).
     *
     * @see API-GUIDE.md §8 (GET /relatorios/mensal[/{timMonthId}])
     */
    public function relatorioMensal(?string $timMonthId = null): array
    {
        $path = $timMonthId
            ? "/relatorios/mensal/{$timMonthId}"
            : '/relatorios/mensal';

        return Cache::remember(
            $this->cacheKey($path),
            86400,
            fn () => $this->get($path),
        );
    }

    // ─── Auth/Health ───

    /**
     * Health check da API ECF Drive (GET /auth/me).
     *
     * Retorna true quando a API responde 2xx com a API key válida.
     * Retorna false em qualquer outro caso sem lançar exceção.
     *
     * NOTA: ping() NÃO usa o helper get() porque get() lança RuntimeException
     * em HTTP != 2xx. ping() precisa retornar false sem lançar (D-02 do PLAN).
     */
    public function ping(): bool
    {
        try {
            return $this->http()->get('/auth/me')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // ─── Helpers privados ───

    /**
     * Helper HTTP base — TODOS os métodos GET passam por aqui.
     * Centraliza headers, retry, timeout, checagem de status e mensagem de erro pt-BR.
     *
     * @param  string  $path    Caminho relativo (com / inicial) — ex: "/clientes/grants"
     * @param  array   $params  Query params (serializados como query string pelo Http facade)
     * @return array            Resposta JSON decodificada como array associativo
     * @throws \RuntimeException quando HTTP != 2xx após retry, com mensagem pt-BR.
     */
    private function get(string $path, array $params = []): array
    {
        $response = $this->http()->get($path, $params);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "ECF Drive {$path} falhou: HTTP {$response->status()} - {$response->body()}"
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Helper de geração de chave de cache determinística.
     *
     * Formato: "ecf.{path-sanitizado}.{md5(query-canonica)}"
     * - path-sanitizado: troca / por . e remove leading dot — ex: "/sellers/123/medalhas" → "sellers.123.medalhas"
     * - query-canonica: ksort + http_build_query (chaves null viram '') — determinístico para mesmo conjunto de params
     *
     * Quando $params está vazio, retorna apenas "ecf.{path-sanitizado}".
     *
     * NOTA: grantsExpirandoEm() NÃO usa este helper — preserva chave legada "ecf_drive:expirando:{dias}" (D-03 do PLAN).
     */
    private function cacheKey(string $path, array $params = []): string
    {
        $sanitizedPath = ltrim(str_replace('/', '.', $path), '.');

        if (empty($params)) {
            return "ecf.{$sanitizedPath}";
        }

        // Normaliza nulls para '' e ordena por chave (determinismo)
        $normalized = array_map(fn ($v) => $v ?? '', $params);
        ksort($normalized);
        $queryHash = md5(http_build_query($normalized));

        return "ecf.{$sanitizedPath}.{$queryHash}";
    }

    /**
     * Cliente HTTP base configurado com headers obrigatórios, retry e timeout.
     */
    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'X-Api-Key' => $this->key,
            'Accept'    => 'application/json',
        ])
        ->retry(2, 500, null, false)  // false = não lança automaticamente em erro; tratamos manualmente
        ->timeout(15)
        ->baseUrl($this->base);
    }
}
