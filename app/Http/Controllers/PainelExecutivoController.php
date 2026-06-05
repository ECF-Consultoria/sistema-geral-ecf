<?php

// Phase 24 — Painel Executivo Carteira ECF.
// Consome /carteira/* da API ECF Drive via EcfDriveService (Phase 22 wrapper).
// Dashboard estratégico complementar ao /dashboard operacional (D-01 do CONTEXT).

namespace App\Http\Controllers;

use App\Services\EcfDriveService;
use Inertia\Inertia;

/**
 * Controller do Painel Executivo da carteira ECF inteira.
 *
 * Carrega 5 chamadas sequenciais ao ECF Drive em try/catch global (D-02, D-03).
 * Cache do wrapper absorve latência (5min resumo, 24h histórico, 1h breakdowns).
 * Acesso restrito a admin via middleware role:admin na rota.
 */
class PainelExecutivoController extends Controller
{
    public function __construct(private EcfDriveService $ecf) {}

    /**
     * Retorna a view do Painel Executivo com dados consolidados da carteira ECF.
     *
     * Estratégia defensiva (D-03): try/catch Throwable global. Se ECF Drive
     * estiver offline, a página abre normalmente com props vazias + mensagem
     * de erro pt-BR — NUNCA quebra o pageload do admin.
     *
     * Chamadas sequenciais (D-02 — 5 total):
     *   1. carteiraResumo()          → cache 5min  (KPIs do mês + MoM)
     *   2. carteiraHistorico('mensal') → cache 24h  (série 12 meses)
     *   3. carteiraBreakdown('programa')   → cache 1h
     *   4. carteiraBreakdown('frete')      → cache 1h
     *   5. carteiraBreakdown('cluster')    → cache 1h
     *   6. carteiraBreakdown('localidade') → cache 1h
     *
     * Cold cache: ~5-10s na primeira carga (R-01). Subsequentes: <500ms.
     */
    public function index()
    {
        try {
            // ─── 5 chamadas sequenciais ao ECF Drive ────────────────────────
            $resumo = $this->ecf->carteiraResumo();

            $historicoResp = $this->ecf->carteiraHistorico('mensal');
            $historico     = $historicoResp['data'] ?? [];

            $brPrograma   = $this->ecf->carteiraBreakdown('programa');
            $brFrete      = $this->ecf->carteiraBreakdown('frete');
            $brCluster    = $this->ecf->carteiraBreakdown('cluster');
            $brLocalidade = $this->ecf->carteiraBreakdown('localidade');

            $breakdowns = [
                'programa'   => $brPrograma,
                'frete'      => $brFrete,
                'cluster'    => $brCluster,
                'localidade' => $brLocalidade,
            ];

            return Inertia::render('PainelExecutivo/Index', [
                'resumo'     => $resumo,
                'historico'  => $historico,
                'breakdowns' => $breakdowns,
                'erro'       => null,
            ]);
        } catch (\Throwable $e) {
            // Defensiva (D-03): ECF Drive offline NÃO quebra a aba
            report($e);
            return Inertia::render('PainelExecutivo/Index', [
                'resumo'     => null,
                'historico'  => [],
                'breakdowns' => [
                    'programa'   => ['distribuicao' => []],
                    'frete'      => ['distribuicao' => []],
                    'cluster'    => ['distribuicao' => []],
                    'localidade' => ['distribuicao' => []],
                ],
                'erro' => 'Não foi possível buscar dados do painel agora. Tente em alguns segundos.',
            ]);
        }
    }
}
