<?php

namespace App\Services;

use App\Models\AdmanSyncLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Gera diagnósticos proativos do sync Adman lendo APENAS o banco local.
 *
 * Sem IA e sem chamada externa — heurística PHP pura sobre os dados
 * que já existem nas tabelas adman_sync_logs, adman_metrics, jobs e failed_jobs.
 *
 * Dois grupos de diagnóstico:
 *  1. Empresas com conta Adman sem sync recente (último synced_at > limiar ou nunca).
 *  2. Contadores de fila: jobs pendentes e jobs falhos.
 */
class AdmanDiagnosticoService
{
    /** Sem sync recente além desta quantidade de horas vira alerta (severidade média). */
    public const SEM_SYNC_HORAS = 48;

    /** Severidade alta quando sem sync há mais tempo que isto, ou nunca sincronizou. */
    public const SEM_SYNC_HORAS_ALTA = 120;

    /** Limite de itens por lista para evitar payload gigante. */
    public const TOP_N = 15;

    // ─── Interface pública ───────────────────────────────────────────────────

    /**
     * Gera o array completo de diagnósticos.
     *
     * @return array{
     *   sem_sync: array<int, array{company_id: int, empresa: string, severidade: 'alta'|'media'|'baixa', descricao: string, acao: string}>,
     *   fila:     array{pendentes: int, falhos: int},
     *   total:    int,
     * }
     */
    public function gerar(): array
    {
        $semSync = $this->diagnosticarSemSync();
        $fila    = $this->diagnosticarFila();

        $total = count($semSync) + ($fila['falhos'] > 0 ? 1 : 0);

        // Chave 'sem_sync' (snake_case) para casar com o contrato do PHPDoc e o consumo no JSX.
        return [
            'sem_sync' => $semSync,
            'fila'     => $fila,
            'total'    => $total,
        ];
    }

    // ─── Grupos de diagnóstico ───────────────────────────────────────────────

    /**
     * Grupo 1 — Empresas com conta Adman sem sync recente.
     *
     * Obtém o último synced_at por empresa em UMA query agregada.
     * Sem N+1: nomes carregados via pluck em lote antes do loop.
     */
    private function diagnosticarSemSync(): array
    {
        // Empresas que têm conta Adman configurada
        $empresas = Company::whereNotNull('adman_account_id')
            ->pluck('name', 'id');

        if ($empresas->isEmpty()) {
            return [];
        }

        // Último synced_at por empresa — uma única query agregada
        $ultimoSync = AdmanSyncLog::selectRaw('company_id, MAX(synced_at) as ultimo')
            ->whereIn('company_id', $empresas->keys())
            ->groupBy('company_id')
            ->pluck('ultimo', 'company_id');

        $agora    = now();
        $alertas  = [];

        foreach ($empresas as $companyId => $nome) {
            $ultimo = $ultimoSync[$companyId] ?? null;

            if ($ultimo === null) {
                // Nunca sincronizou — severidade alta
                $alertas[] = [
                    'company_id' => $companyId,
                    'empresa'    => $nome,
                    'severidade' => 'alta',
                    'descricao'  => 'Nunca sincronizou com o Adman.',
                    'acao'       => 'Re-disparar sync',
                ];
                continue;
            }

            $horasDesde = $agora->diffInHours($ultimo);

            if ($horasDesde >= self::SEM_SYNC_HORAS) {
                $severidade = $horasDesde >= self::SEM_SYNC_HORAS_ALTA ? 'alta' : 'media';
                $alertas[]  = [
                    'company_id' => $companyId,
                    'empresa'    => $nome,
                    'severidade' => $severidade,
                    'descricao'  => "Sem sync há {$horasDesde}h (último em {$agora->parse($ultimo)->format('d/m H:i')}).",
                    'acao'       => 'Re-disparar sync',
                ];
            }
        }

        // Ordenar por severidade (alta primeiro) e limitar
        usort($alertas, fn ($a, $b) => ($a['severidade'] === 'alta' ? 0 : 1) <=> ($b['severidade'] === 'alta' ? 0 : 1));

        return array_slice($alertas, 0, self::TOP_N);
    }

    /**
     * Grupo 2 — Contadores da fila de jobs.
     *
     * Usa consultas diretas nas tabelas 'jobs' e 'failed_jobs' (driver database).
     */
    private function diagnosticarFila(): array
    {
        return [
            'pendentes' => DB::table('jobs')->count(),
            'falhos'    => DB::table('failed_jobs')->count(),
        ];
    }
}
