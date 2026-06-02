<?php

namespace App\Services;

use App\Models\AdmanMetric;
use App\Models\AdmanSyncLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Gera diagnósticos proativos do sync Adman lendo APENAS o banco local.
 *
 * Sem IA e sem chamada externa — heurística PHP pura sobre os dados
 * que já existem nas tabelas adman_sync_logs, adman_metrics, jobs e failed_jobs.
 *
 * Quatro grupos de diagnóstico:
 *  1. Empresas com conta Adman sem sync recente (último synced_at > limiar ou nunca).
 *  2. Syncs recentes com error_message preenchido.
 *  3. Contadores de fila: jobs pendentes e jobs falhos.
 *  4. Anomalias de métrica: queda forte de revenue e TACoS fora da média da empresa.
 */
class AdmanDiagnosticoService
{
    /** Sem sync recente além desta quantidade de horas vira alerta (severidade média). */
    public const SEM_SYNC_HORAS = 48;

    /** Severidade alta quando sem sync há mais tempo que isto, ou nunca sincronizou. */
    public const SEM_SYNC_HORAS_ALTA = 120;

    /** Janela de horas para considerar syncs com erro como "recentes". */
    public const ERRO_RECENTE_HORAS = 48;

    /**
     * Queda forte de revenue: revenue_growth <= -40% aciona o alerta.
     * Usar float negativo para comparação direta com o accessor.
     */
    public const REVENUE_GROWTH_QUEDA = -40.0;

    /**
     * Piso de revenue_prev_period (em reais) para evitar ruído de empresas
     * com faturamento ínfimo (ex.: R$ 0,01 vs R$ 0,00 = queda de 100%).
     */
    public const REVENUE_PREV_PISO = 100.0;

    /** Janela em dias para calcular a média de TACoS por empresa. */
    public const TACOS_DIAS_MEDIA = 14;

    /** TACoS anômalo se o último valor for maior que (média da janela × este fator). */
    public const TACOS_MARGEM = 1.30;

    /** Limite de itens por lista para evitar payload gigante. */
    public const TOP_N = 15;

    // ─── Interface pública ───────────────────────────────────────────────────

    /**
     * Gera o array completo de diagnósticos.
     *
     * @return array{
     *   sem_sync: array<int, array{company_id: int, empresa: string, severidade: 'alta'|'media'|'baixa', descricao: string, acao: string}>,
     *   erros:    array<int, array{company_id: int, empresa: string, severidade: 'alta'|'media'|'baixa', descricao: string, acao: string}>,
     *   fila:     array{pendentes: int, falhos: int},
     *   anomalias: array<int, array{company_id: int, empresa: string, severidade: 'alta'|'media'|'baixa', descricao: string, acao: string}>,
     *   total:    int,
     * }
     */
    public function gerar(): array
    {
        $semSync   = $this->diagnosticarSemSync();
        $erros     = $this->diagnosticarErros();
        $fila      = $this->diagnosticarFila();
        $anomalias = $this->diagnosticarAnomalias();

        $total = count($semSync)
            + count($erros)
            + count($anomalias)
            + ($fila['falhos'] > 0 ? 1 : 0);

        return compact('semSync', 'erros', 'fila', 'anomalias', 'total');
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
     * Grupo 2 — Syncs com erro na janela recente.
     *
     * Carrega com eager load de company para evitar N+1.
     */
    private function diagnosticarErros(): array
    {
        $logs = AdmanSyncLog::whereNotNull('error_message')
            ->where('synced_at', '>=', now()->subHours(self::ERRO_RECENTE_HORAS))
            ->with('company')
            ->latest('synced_at')
            ->limit(self::TOP_N)
            ->get();

        $alertas = [];

        foreach ($logs as $log) {
            if ($log->company === null) {
                // Empresa removida — pular
                continue;
            }

            // Truncar mensagem de erro para evitar payload grande (~120 chars)
            $mensagem = mb_strlen($log->error_message) > 120
                ? mb_substr($log->error_message, 0, 120) . '…'
                : $log->error_message;

            $alertas[] = [
                'company_id' => $log->company_id,
                'empresa'    => $log->company->name,
                'severidade' => 'alta',
                'descricao'  => $mensagem,
                'acao'       => 'Re-disparar sync',
            ];
        }

        return $alertas;
    }

    /**
     * Grupo 3 — Contadores da fila de jobs.
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

    /**
     * Grupo 4 — Anomalias de métrica Adman.
     *
     * Detecta duas classes de anomalia:
     *  a) Queda forte de revenue (revenue_growth <= REVENUE_GROWTH_QUEDA).
     *  b) TACoS anômalo (último > média 14 dias × TACOS_MARGEM).
     *
     * Sem N+1: usa uma query para obter a métrica mais recente por empresa
     * e outra para calcular a média de TACoS por empresa.
     */
    private function diagnosticarAnomalias(): array
    {
        // Empresas com conta Adman
        $idsComAdman = Company::whereNotNull('adman_account_id')
            ->pluck('name', 'id');

        if ($idsComAdman->isEmpty()) {
            return [];
        }

        $ids = $idsComAdman->keys()->toArray();

        // Métrica mais recente por empresa (uma query com subquery)
        $ultimasMetricas = AdmanMetric::whereIn('company_id', $ids)
            ->whereIn('id', function ($sub) use ($ids) {
                $sub->selectRaw('MAX(id)')
                    ->from('adman_metrics')
                    ->whereIn('company_id', $ids)
                    ->groupBy('company_id');
            })
            ->get()
            ->keyBy('company_id');

        // Média de TACoS por empresa nos últimos TACOS_DIAS_MEDIA dias
        $mediasTacos = AdmanMetric::selectRaw('company_id, AVG(tacos) as media')
            ->whereIn('company_id', $ids)
            ->where('reference_date', '>=', now()->subDays(self::TACOS_DIAS_MEDIA)->toDateString())
            ->whereNotNull('tacos')
            ->groupBy('company_id')
            ->pluck('media', 'company_id');

        $alertas = [];

        foreach ($ultimasMetricas as $companyId => $metrica) {
            $nomeEmpresa = $idsComAdman[$companyId] ?? "Empresa #{$companyId}";

            // a) Queda forte de revenue
            $revenueGrowth  = $metrica->revenue_growth;
            $revenuePrev    = (float) $metrica->revenue_prev_period;

            if ($revenueGrowth <= self::REVENUE_GROWTH_QUEDA && $revenuePrev >= self::REVENUE_PREV_PISO) {
                $pct = number_format(abs($revenueGrowth), 1, ',', '.');

                $alertas[] = [
                    'company_id' => $companyId,
                    'empresa'    => $nomeEmpresa,
                    'severidade' => 'alta',
                    'descricao'  => "Revenue caiu {$pct}% vs período anterior (mês de referência: {$metrica->reference_date->format('M/Y')}).",
                    'acao'       => 'Re-disparar sync',
                ];
            }

            // b) TACoS anômalo
            $media = isset($mediasTacos[$companyId]) ? (float) $mediasTacos[$companyId] : 0.0;
            $tacos = (float) $metrica->tacos;

            if ($media > 0 && $tacos > ($media * self::TACOS_MARGEM)) {
                $tacosFormatado  = number_format($tacos, 2, ',', '.');
                $mediaFormatada  = number_format($media, 2, ',', '.');

                $alertas[] = [
                    'company_id' => $companyId,
                    'empresa'    => $nomeEmpresa,
                    'severidade' => 'media',
                    'descricao'  => "TACoS {$tacosFormatado} acima da média {$mediaFormatada} dos últimos " . self::TACOS_DIAS_MEDIA . ' dias.',
                    'acao'       => 'Re-disparar sync',
                ];
            }
        }

        return array_slice($alertas, 0, self::TOP_N);
    }
}
