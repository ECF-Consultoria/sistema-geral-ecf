<?php

namespace App\Services;

use App\Models\Publicacao;
use App\Models\PublicacaoAbsenteismo;
use Carbon\Carbon;

/**
 * Plano de Metas do Time de Publicação.
 *
 * Régua ÚNICA e canônica da nota do publicador — consumida tanto pelo "Meu
 * Painel" (MlbController) quanto pelo dashboard "Desempenho" (PerformanceController),
 * eliminando a divergência histórica entre as duas telas.
 *
 * Nota final 0-5 = soma ponderada das notas 1-5 de 5 indicadores:
 *   30%  Nº de publicações  (cota 320/mês)
 *   30%  Conversão          (meta 30%)
 *   15%  Produtividade      (16 anúncios/dia útil)
 *   15%  Pontualidade       (≥80% dentro do prazo combinado)
 *   10%  Absenteísmo        (sem faltas não justificadas)
 *
 * Faixas de bonificação (após as travas de elegibilidade):
 *   < 3,50  → sem bônus  | 3,50-3,99 → base
 *   4,00-4,49 → intermediário | 4,50-5,00 → máximo
 *
 * Indicadores SEM fonte de dado no mês (pontualidade sem prazos definidos,
 * absenteísmo sem registro) ficam com nota null e têm o peso REDISTRIBUÍDO
 * entre os demais — não penaliza quem ainda não tem o dado lançado. As travas
 * só "mordem" quando há dado comprovando a violação (inocente até registrar).
 */
class PlanoMetasPublicacaoService
{
    // ─── Metas do plano (constantes ajustáveis) ──────────────────────────────
    public const COTA_MENSAL       = 320;   // anúncios/mês
    public const CONVERSAO_META    = 30.0;  // %
    public const PRODUTIVIDADE_REF = 16;    // anúncios/dia útil
    public const PONTUALIDADE_META = 80.0;  // % dentro do prazo

    // ─── Pesos (somam 100 quando todos os indicadores têm dado) ──────────────
    public const PESO_PUBLICACOES   = 30;
    public const PESO_CONVERSAO     = 30;
    public const PESO_PRODUTIVIDADE = 15;
    public const PESO_PONTUALIDADE  = 15;
    public const PESO_ABSENTEISMO   = 10;

    /**
     * Computa a nota, a faixa de bônus e o detalhamento por indicador.
     *
     * @param  int     $userId            ID do publicador.
     * @param  string  $mesRef            Mês de referência 'Y-m'.
     * @param  int     $feito             Anúncios publicados no mês (do controller).
     * @param  int     $vendas            Anúncios com venda no mês (do controller).
     * @param  int     $diasUteisDecorridos  Dias úteis decorridos (produtividade).
     *
     * @return array{
     *   nota: float, faixa: string, faixa_por_nota: string, elegivel_bonus: bool,
     *   travas: array<int,string>, score100: float, indicadores: array<string,array>
     * }
     */
    public function compute(int $userId, string $mesRef, int $feito, int $vendas, int $diasUteisDecorridos): array
    {
        $ref      = Carbon::createFromFormat('Y-m', $mesRef)->startOfMonth();
        $primeiro = $ref->copy()->startOfMonth()->toDateString();
        $ultimo   = $ref->copy()->endOfMonth()->toDateString();

        // ── 1. Nº de publicações (nota sempre presente: uma contagem sempre existe) ──
        $notaPub = $this->notaPublicacoes($feito);

        // ── 2. Conversão (% de anúncios com venda). Sem publicações → sem dado. ──
        // Usa o valor BRUTO na régua e nas travas (borda 39,99% → 3; portão do
        // máximo ≥40%); arredonda só para exibição (senão 39,99% viraria 40,0).
        $convPctRaw = $feito > 0 ? ($vendas / $feito) * 100 : null;
        $convPct    = $convPctRaw !== null ? round($convPctRaw, 1) : null;
        $notaConv   = $this->notaConversao($convPctRaw);

        // ── 3. Produtividade (média diária vs 16/dia útil). Mês futuro → sem dado. ──
        $mediaDia  = $diasUteisDecorridos > 0 ? round($feito / $diasUteisDecorridos, 1) : null;
        $notaProd  = $this->notaProdutividade($mediaDia);

        // ── 4. Pontualidade (% de anúncios COM prazo publicados dentro dele) ──
        $pont     = $this->apurarPontualidade($userId, $primeiro, $ultimo);
        $notaPont = $this->notaPontualidade($pont['pct']);

        // ── 5. Absenteísmo (registro manual do líder). Sem registro → sem dado. ──
        $abs      = $this->apurarAbsenteismo($userId, $mesRef);
        $notaAbs  = $abs['nota']; // já é 1-5 ou null

        // ── Nota final (0-5) com redistribuição de pesos p/ indicadores sem dado ──
        $componentes = [
            'publicacoes'   => ['nota' => $notaPub,  'peso' => self::PESO_PUBLICACOES],
            'conversao'     => ['nota' => $notaConv, 'peso' => self::PESO_CONVERSAO],
            'produtividade' => ['nota' => $notaProd, 'peso' => self::PESO_PRODUTIVIDADE],
            'pontualidade'  => ['nota' => $notaPont, 'peso' => self::PESO_PONTUALIDADE],
            'absenteismo'   => ['nota' => $notaAbs,  'peso' => self::PESO_ABSENTEISMO],
        ];
        $nota = $this->notaFinal($componentes);

        // ── Faixa de bônus + travas de elegibilidade (usa conversão BRUTA nos portões) ──
        [$faixa, $faixaPorNota, $travas] = $this->definirFaixa($nota, $feito, $convPctRaw, $notaAbs, $abs['faltas']);

        return [
            'nota'           => $nota,
            'score100'       => round($nota * 20, 1), // p/ reuso dos gauges 0-100
            'faixa'          => $faixa,
            'faixa_por_nota' => $faixaPorNota,
            'elegivel_bonus' => $faixa !== 'sem_bonus',
            'travas'         => $travas,
            'indicadores'    => [
                'publicacoes' => [
                    'nota'  => $notaPub,
                    'peso'  => self::PESO_PUBLICACOES,
                    'valor' => $feito,
                    'meta'  => self::COTA_MENSAL,
                    'unidade' => 'anúncios',
                ],
                'conversao' => [
                    'nota'  => $notaConv,
                    'peso'  => self::PESO_CONVERSAO,
                    'valor' => $convPct,
                    'meta'  => self::CONVERSAO_META,
                    'vendas'=> $vendas,
                    'feito' => $feito,
                    'unidade' => '%',
                ],
                'produtividade' => [
                    'nota'  => $notaProd,
                    'peso'  => self::PESO_PRODUTIVIDADE,
                    'valor' => $mediaDia,
                    'meta'  => self::PRODUTIVIDADE_REF,
                    'dias_uteis' => $diasUteisDecorridos,
                    'unidade' => '/dia útil',
                ],
                'pontualidade' => [
                    'nota'  => $notaPont,
                    'peso'  => self::PESO_PONTUALIDADE,
                    'valor' => $pont['pct'],
                    'meta'  => self::PONTUALIDADE_META,
                    'no_prazo' => $pont['no_prazo'],
                    'total'    => $pont['total'],
                    'unidade' => '%',
                ],
                'absenteismo' => [
                    'nota'       => $notaAbs,
                    'peso'       => self::PESO_ABSENTEISMO,
                    'faltas'     => $abs['faltas'],
                    'atrasos'    => $abs['atrasos'],
                    'registrado' => $abs['registrado'],
                    'observacao' => $abs['observacao'],
                ],
            ],
        ];
    }

    // ═══ Réguas de nota por indicador (1-5, ou null quando sem dado) ═══════════

    /** 3.1 — <280→1, 280-319→2, 320→3, 321-369→4, ≥370→5. */
    private function notaPublicacoes(int $feito): int
    {
        if ($feito >= 370) return 5;
        if ($feito >= 321) return 4;
        if ($feito >= 320) return 3;
        if ($feito >= 280) return 2;
        return 1;
    }

    /** 3.2 — <30%→1, 30-39,99%→3, ≥40%→5. */
    private function notaConversao(?float $pct): ?int
    {
        if ($pct === null) return null;
        if ($pct >= 40) return 5;
        if ($pct >= 30) return 3;
        return 1;
    }

    /** 3.3 — ≤12→1, 13-15→2, 16→3, 17-19→4, ≥20→5 (anúncios/dia útil). */
    private function notaProdutividade(?float $media): ?int
    {
        if ($media === null) return null;
        if ($media >= 20) return 5;
        if ($media >= 17) return 4;
        if ($media >= 16) return 3;
        if ($media >= 13) return 2;
        return 1;
    }

    /** 3.4 — <70%→1, 70-79→2, 80-89→3, 90-96→4, 97-100→5. */
    private function notaPontualidade(?float $pct): ?int
    {
        if ($pct === null) return null;
        if ($pct >= 97) return 5;
        if ($pct >= 90) return 4;
        if ($pct >= 80) return 3;
        if ($pct >= 70) return 2;
        return 1;
    }

    // ═══ Apuração de dados próprios (queries) ═════════════════════════════════

    /**
     * Pontualidade: entre os anúncios do publicador no mês que têm prazo definido,
     * quantos foram publicados dentro dele (data <= prazo).
     *
     * @return array{pct: float|null, no_prazo: int, total: int}
     */
    private function apurarPontualidade(int $userId, string $primeiro, string $ultimo): array
    {
        $comPrazo = Publicacao::where('user_id', $userId)
            ->whereBetween('data', [$primeiro, $ultimo])
            ->where('tipo', '!=', 'variacao')->considerado()
            ->whereNotNull('prazo')
            ->get(['data', 'prazo']);

        $total = $comPrazo->count();
        if ($total === 0) {
            return ['pct' => null, 'no_prazo' => 0, 'total' => 0];
        }

        $noPrazo = $comPrazo->filter(fn ($p) => $p->data->lte($p->prazo))->count();

        return [
            'pct'      => round(($noPrazo / $total) * 100, 1),
            'no_prazo' => $noPrazo,
            'total'    => $total,
        ];
    }

    /**
     * Absenteísmo: lê o registro manual do mês e deriva a nota 1-5.
     * 3.5 — 3+ faltas→1, 2→2, 1→3, sem faltas c/ atrasos→4, sem faltas nem atrasos→5.
     * Sem registro no mês → nota null (peso redistribuído).
     *
     * @return array{nota: int|null, faltas: int|null, atrasos: bool|null, registrado: bool, observacao: string|null}
     */
    private function apurarAbsenteismo(int $userId, string $mesRef): array
    {
        $reg = PublicacaoAbsenteismo::where('user_id', $userId)->where('mes', $mesRef)->first();

        if (!$reg) {
            return ['nota' => null, 'faltas' => null, 'atrasos' => null, 'registrado' => false, 'observacao' => null];
        }

        $faltas  = (int) $reg->faltas_nao_justificadas;
        $atrasos = (bool) $reg->atrasos_pontuais;

        if ($faltas >= 3) {
            $nota = 1;
        } elseif ($faltas === 2) {
            $nota = 2;
        } elseif ($faltas === 1) {
            $nota = 3;
        } elseif ($atrasos) {
            $nota = 4; // sem faltas, mas com pequenos atrasos pontuais
        } else {
            $nota = 5; // sem faltas e sem atrasos
        }

        return [
            'nota'       => $nota,
            'faltas'     => $faltas,
            'atrasos'    => $atrasos,
            'registrado' => true,
            'observacao' => $reg->observacao,
        ];
    }

    // ═══ Nota final + faixa de bônus ══════════════════════════════════════════

    /**
     * Média ponderada das notas 1-5 (escala 0-5), redistribuindo o peso dos
     * indicadores sem dado (nota null). Todos null → 0.0.
     */
    private function notaFinal(array $componentes): float
    {
        $totalPeso = 0;
        $soma      = 0.0;
        foreach ($componentes as $c) {
            if ($c['nota'] === null) continue;
            $totalPeso += $c['peso'];
            $soma      += $c['peso'] * $c['nota'];
        }
        if ($totalPeso === 0) return 0.0;
        return round($soma / $totalPeso, 2);
    }

    /**
     * Faixa por nota + travas de elegibilidade (Seção 6 do plano). As travas só
     * REBAIXAM a faixa e só quando há dado comprovando a violação.
     *
     * @return array{0: string, 1: string, 2: array<int,string>}  [faixa, faixaPorNota, travas]
     */
    private function definirFaixa(float $nota, int $feito, ?float $convPct, ?int $notaAbs, ?int $faltas): array
    {
        // Faixa "crua" pela nota.
        if ($nota >= 4.50) {
            $faixa = 'maximo';
        } elseif ($nota >= 4.00) {
            $faixa = 'intermediario';
        } elseif ($nota >= 3.50) {
            $faixa = 'base';
        } else {
            $faixa = 'sem_bonus';
        }
        $faixaPorNota = $faixa;
        $travas       = [];

        $ehTopo = fn (string $f) => in_array($f, ['intermediario', 'maximo'], true);

        // Trava do bônus MÁXIMO: exige ≥370 pub, ≥40% conversão e absenteísmo 4-5.
        // Absenteísmo sem registro (null) não bloqueia (só um registro < 4 bloqueia).
        if ($faixa === 'maximo') {
            $absOk = $notaAbs === null || $notaAbs >= 4;
            if ($feito < 370 || $convPct === null || $convPct < 40 || !$absOk) {
                $faixa = 'intermediario';
                $travas[] = 'Bônus máximo exige ≥370 publicações, ≥40% de conversão e absenteísmo nota 4-5.';
            }
        }

        // Trava de CONVERSÃO: intermediário/máximo exigem ≥30% de conversão.
        if ($ehTopo($faixa) && $convPct !== null && $convPct < self::CONVERSAO_META) {
            $faixa = 'base';
            $travas[] = 'Bônus intermediário/máximo exige pelo menos 30% de conversão.';
        }

        // Trava de ABSENTEÍSMO: 3+ ocorrências não justificadas limitam ao bônus base.
        if ($ehTopo($faixa) && $faltas !== null && $faltas >= 3) {
            $faixa = 'base';
            $travas[] = '3+ faltas não justificadas limitam ao bônus base.';
        }

        // Trava de VOLUME: mínimo de 320 publicações para qualquer bônus.
        if ($feito < self::COTA_MENSAL && $faixa !== 'sem_bonus') {
            $faixa = 'sem_bonus';
            $travas[] = 'Mínimo de 320 publicações para qualquer bônus.';
        }

        return [$faixa, $faixaPorNota, $travas];
    }
}
