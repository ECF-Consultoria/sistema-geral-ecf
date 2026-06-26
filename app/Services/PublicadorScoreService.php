<?php

namespace App\Services;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use Carbon\Carbon;

/**
 * Fase 38 — Score 0-100 + 5 eixos do publicador (Painel do Publicador).
 *
 * Análogo direto do PortfolioScoreService (Carteira dos analistas de ADS), porém
 * alimentado pelos dados do publicador. Os métodos scoreFinal() e classificar()
 * são copiados VERBATIM do PortfolioScoreService — mesma redistribuição de pesos
 * para eixos sem dado e mesmos limiares de classificação (85/70/55).
 *
 * Os 5 eixos e seus pesos:
 *   35%  Meta:          atingimento da meta de anúncios (feito/meta), cap 100.
 *   25%  Produtividade: volume de anúncios vs meta (0%→0, 100%→80, ≥130%→100).
 *   20%  Pontualidade:  % de SKUs sem atraso nas empresas onde responsavel_id=publicador.
 *   10%  Conversão:     % de anúncios com venda no mês (vendido/feito).
 *   10%  Qualidade:     % sem problema (60%) + % feedbacks resolvidos (40%).
 *
 * Quando um eixo não tem dado (ex.: sem publicações → conversão null; sem empresas
 * responsáveis → pontualidade null), o peso dele é redistribuído entre os demais
 * via scoreFinal() — não penaliza quem não tem dados (vs. quem tem dados ruins).
 *
 * IMPORTANTE: feito/vendas/meta vêm de fora (do controller, via calcularKpis()/
 * metaParaMes()) — o Service NÃO recalcula essas queries (evita duplicação).
 * Apenas Pontualidade e Qualidade fazem queries próprias.
 */
class PublicadorScoreService
{
    // Pesos dos eixos (ajustáveis). Somam 100 quando todos têm dado.
    private const PESO_META          = 35;
    private const PESO_PRODUTIVIDADE  = 25;
    private const PESO_PONTUALIDADE   = 20;
    private const PESO_CONVERSAO      = 10;
    private const PESO_QUALIDADE      = 10;

    /**
     * Computa os 5 eixos + score + classificação para um publicador.
     *
     * @param  int     $userId  ID do publicador autenticado.
     * @param  string  $mesRef  Mês de referência no formato 'Y-m'.
     * @param  int     $feito   Anúncios feitos no mês (de calcularKpis()).
     * @param  int     $vendas  Anúncios com venda no mês (de calcularKpis()).
     * @param  int     $meta    Meta de anúncios do mês (de metaParaMes()).
     *
     * @return array{
     *   score: float,
     *   classificacao: string,
     *   pontos_categoria: array<string, array{valor: float|null, peso: int}>,
     *   metricas: array<string, array<string, float|int|null>>,
     * }
     */
    public function compute(int $userId, string $mesRef, int $feito, int $vendas, int $meta): array
    {
        $ref      = Carbon::createFromFormat('Y-m', $mesRef)->startOfMonth();
        $primeiro = $ref->copy()->startOfMonth()->toDateString();
        $ultimo   = $ref->copy()->endOfMonth()->toDateString();

        // ── Eixo 1: Meta — atingimento (feito/meta), cap 100 ──
        $metaPct    = $meta > 0 ? round(($feito / $meta) * 100, 1) : null;
        $pontosMeta = $meta > 0 ? min(100.0, (float) $metaPct) : null;

        // ── Eixo 2: Produtividade — volume vs meta (dois segmentos lineares) ──
        // 0%→0pts, 100%→80pts (adequado), ≥130%→100pts (excepcional).
        $pctMeta = $meta > 0 ? ($feito / $meta) * 100 : 0;
        if ($pctMeta >= 130) {
            $pontosProdutividade = 100.0;
        } elseif ($pctMeta >= 100) {
            $pontosProdutividade = round(80.0 + (($pctMeta - 100) / 30) * 20, 1);
        } else {
            $pontosProdutividade = round(($pctMeta / 100) * 80, 1);
        }
        // null quando não há meta (sem cadastro e sem cargo com meta).
        $pontosProdutividade = $meta > 0 ? $pontosProdutividade : null;

        // ── Eixo 3: Pontualidade — % de SKUs sem atraso (empresas responsavel_id=publicador) ──
        // Lógica espelhada de MlbController::dashboard() linhas 461-481.
        $empresas = MlbEmpresa::where('responsavel_id', $userId)
            ->get([
                'id',
                'skus_estagio1', 'skus_estagio2', 'skus_estagio3',
                'prazo_estagio1', 'prazo_estagio2', 'prazo_estagio3',
            ]);

        $totalSkus    = 0;
        $atrasadosCnt = 0;
        $hoje         = now()->startOfDay();

        foreach ($empresas as $e) {
            for ($stage = 1; $stage <= 3; $stage++) {
                $skus      = $e->{"skus_estagio{$stage}"} ?? [];
                $prazoRaw  = $e->{"prazo_estagio{$stage}"};
                $prazoVenc = $prazoRaw && $hoje->gt(Carbon::parse($prazoRaw)->startOfDay());
                foreach ($skus as $sku) {
                    if (trim($sku['sku'] ?? '') === '') {
                        continue;
                    }
                    $totalSkus++;
                    if ($sku['atrasado'] ?? false) {
                        // Concluído mas atrasado.
                        $atrasadosCnt++;
                    } elseif (!($sku['ok'] ?? false) && $prazoVenc) {
                        // Pendente com prazo vencido.
                        $atrasadosCnt++;
                    }
                }
            }
        }

        $pontosPontualidade = $totalSkus > 0
            ? round((($totalSkus - $atrasadosCnt) / $totalSkus) * 100, 1)
            : null;

        // ── Eixo 4: Conversão — % de anúncios com venda no mês ──
        $pontosConversao = $feito > 0
            ? round(($vendas / $feito) * 100, 1)
            : null;

        // ── Eixo 5: Qualidade — % sem problema (60%) + % feedbacks resolvidos (40%) ──
        $pubsMes = Publicacao::where('user_id', $userId)
            ->whereBetween('data', [$primeiro, $ultimo])
            ->where('tipo', '!=', 'variacao');

        $totalPubs      = (clone $pubsMes)->count();
        $comProblema    = (clone $pubsMes)->where('problema', true)->count();
        $pctSemProblema = $totalPubs > 0
            ? (($totalPubs - $comProblema) / $totalPubs) * 100
            : null;

        // Feedbacks: publicações com comentário do líder (acumulado, sem filtro de mês).
        $totalFeedbacks = Publicacao::where('user_id', $userId)
            ->whereNotNull('comentario')
            ->where('comentario', '!=', '')
            ->count();
        $feedbacksResolvidos = Publicacao::where('user_id', $userId)
            ->whereNotNull('comentario')
            ->where('comentario', '!=', '')
            ->where('comentario_resolvido', true)
            ->count();
        $pctFeedbacksResolvidos = $totalFeedbacks > 0
            ? ($feedbacksResolvidos / $totalFeedbacks) * 100
            : null; // sem feedbacks = sem dado (não penaliza)

        if ($pctSemProblema === null && $pctFeedbacksResolvidos === null) {
            $pontosQualidade = null;
        } elseif ($pctFeedbacksResolvidos === null) {
            $pontosQualidade = round($pctSemProblema, 1); // só componente A
        } elseif ($pctSemProblema === null) {
            $pontosQualidade = round($pctFeedbacksResolvidos, 1); // só componente B
        } else {
            $pontosQualidade = round($pctSemProblema * 0.60 + $pctFeedbacksResolvidos * 0.40, 1);
        }

        // ── Montagem do score (com redistribuição de pesos para eixos sem dado) ──
        $pontos = [
            'meta'          => ['valor' => $pontosMeta,          'peso' => self::PESO_META],
            'produtividade' => ['valor' => $pontosProdutividade, 'peso' => self::PESO_PRODUTIVIDADE],
            'pontualidade'  => ['valor' => $pontosPontualidade,  'peso' => self::PESO_PONTUALIDADE],
            'conversao'     => ['valor' => $pontosConversao,     'peso' => self::PESO_CONVERSAO],
            'qualidade'     => ['valor' => $pontosQualidade,     'peso' => self::PESO_QUALIDADE],
        ];
        $score   = $this->scoreFinal($pontos);
        $classif = $this->classificar($score);

        return [
            'score'            => $score,
            'classificacao'    => $classif,
            'pontos_categoria' => $pontos,
            'metricas' => [
                'meta' => [
                    'feito' => $feito,
                    'alvo'  => $meta,
                    'pct'   => $metaPct,
                ],
                'produtividade' => [
                    'feito' => $feito,
                    'meta'  => $meta,
                    'pct'   => $meta > 0 ? round($pctMeta, 1) : null,
                ],
                'pontualidade' => [
                    'total_skus'   => $totalSkus,
                    'atrasados'    => $atrasadosCnt,
                    'pct_no_prazo' => $pontosPontualidade,
                ],
                'conversao' => [
                    'vendidos' => $vendas,
                    'feito'    => $feito,
                    'pct'      => $pontosConversao,
                ],
                'qualidade' => [
                    'pct_sem_problema'         => $pctSemProblema !== null ? round($pctSemProblema, 1) : null,
                    'pct_feedbacks_resolvidos' => $pctFeedbacksResolvidos !== null ? round($pctFeedbacksResolvidos, 1) : null,
                ],
            ],
        ];
    }

    /**
     * Score final 0-100 com redistribuição de pesos para eixos sem dado.
     * Copiado VERBATIM do PortfolioScoreService.
     */
    private function scoreFinal(array $pontos): float
    {
        $totalPeso = 0;
        $somaPonderada = 0.0;
        foreach ($pontos as $p) {
            if ($p['valor'] === null) continue;
            $totalPeso     += $p['peso'];
            $somaPonderada += $p['peso'] * (float) $p['valor'];
        }
        if ($totalPeso === 0) return 0.0;
        return round($somaPonderada / $totalPeso, 1);
    }

    /**
     * Classificação por faixa. Copiado VERBATIM do PortfolioScoreService.
     */
    private function classificar(float $score): string
    {
        if ($score >= 85) return 'excelente';
        if ($score >= 70) return 'bom';
        if ($score >= 55) return 'atencao';
        return 'critico';
    }
}
