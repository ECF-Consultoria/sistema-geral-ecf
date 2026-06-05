<?php

// Phase 20 — wrapper HTTP do sistema externo ECF Drive (substitui sync SFTP do ML).
// Documentação da API: https://files.ecfconsultoria.com.br/api/v1

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Wrapper HTTP para o sistema externo ECF Drive.
 *
 * O ECF Drive é desenvolvido pelo próprio usuário e abstrai o SFTP do Mercado
 * Livre, expondo os dados de grants como uma API REST autenticada por API key.
 *
 * Substitui o pipeline frágil XLSX-via-SFTP do SyncGrantsFromSftp.php.
 * O comando antigo permanece intacto como rollback safety por +1 fase (Phase 21+).
 */
class EcfDriveService
{
    private string $base;
    private string $key;

    public function __construct(?string $base = null, ?string $key = null)
    {
        $this->base = rtrim($base ?? (string) config('services.ecf.base'), '/');
        $this->key  = $key ?? (string) config('services.ecf.key', '');
    }

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
        $response = $this->http()->get('/clientes/grants', $filtros);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "ECF Drive listGrants falhou: HTTP {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
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
        $response = $this->http()->get("/clientes/{$custId}");

        if (! $response->successful()) {
            throw new \RuntimeException(
                "ECF Drive cliente({$custId}) falhou: HTTP {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * Health check da API ECF Drive (GET /auth/me).
     *
     * Retorna true quando a API responde 2xx com a API key válida.
     * Retorna false em qualquer outro caso sem lançar exceção.
     */
    public function ping(): bool
    {
        try {
            return $this->http()->get('/auth/me')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Helper cacheado de 5 minutos — retorna grants que expiram em N dias.
     *
     * Cache key: "ecf_drive:expirando:{dias}".
     * A UI de /grants NÃO usa este método diretamente (usa tabela local).
     * Método disponível para futuros consumers: dashboard, alertas, etc.
     *
     * @return array Mesmo shape de listGrants().
     */
    public function grantsExpirandoEm(int $dias): array
    {
        return Cache::remember("ecf_drive:expirando:{$dias}", 300, function () use ($dias) {
            return $this->listGrants(['expirando_em_dias' => $dias]);
        });
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
        ->retry(2, 500)
        ->timeout(15)
        ->baseUrl($this->base);
    }
}
