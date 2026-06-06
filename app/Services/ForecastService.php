<?php

namespace App\Services;

/**
 * Cálculos puros de forecast estratégico (Phase 27 — Concentração de Receita).
 *
 * Sem dependências externas — testável em isolation.
 * Não usa Cache, Http, Log, Carbon nem qualquer injeção de dependência.
 *
 * Funções disponíveis:
 *   - regressaoLinear: y = a*x + b sobre série indexada [0,1,..,N-1]
 *   - projetar: aplica coeficientes para gerar N pontos a partir de startX
 *   - coeficienteVariacao: desvio_padrao/media — adimensional. Null se N<2 ou media=0.
 */
class ForecastService
{
    /**
     * Calcula regressão linear simples sobre uma série de valores.
     *
     * Eixo x implícito = [0, 1, 2, ..., N-1].
     * Retorna {a: float, b: float, r2: float} onde y = a*x + b.
     *
     * Aceita valores string via cast defensivo — sellerMetricasMensal vem
     * com tgmvLc como STRING (pitfall recorrente Phase 25/27).
     *
     * Caso degenerado (N < 2 ou denominador zero): retorna slope=0,
     * intercept=media e r2=0 — seguro para uso no frontend.
     *
     * @param  array $y  Série de valores numéricos (ou strings parseáveis)
     * @return array     ['a' => float, 'b' => float, 'r2' => float]
     */
    public function regressaoLinear(array $y): array
    {
        $n = count($y);

        // Caso degenerado: série vazia
        if ($n === 0) {
            return ['a' => 0.0, 'b' => 0.0, 'r2' => 0.0];
        }

        // Caso degenerado: 1 único ponto — slope indefinido
        if ($n === 1) {
            return ['a' => 0.0, 'b' => (float) $y[0], 'r2' => 0.0];
        }

        // Cast defensivo (D-M do PLAN) — tgmvLc vem STRING do parceiro ECF Drive
        $y = array_map(fn ($v) => (float) $v, $y);

        // Eixo x implícito: [0, 1, 2, ..., N-1]
        // mean_x é determinístico: (N-1)/2
        $mean_x = ($n - 1) / 2.0;
        $mean_y = array_sum($y) / $n;

        // Slope (a) e intercept (b) via mínimos quadrados
        $num = 0.0;
        $den = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $num += ($i - $mean_x) * ($y[$i] - $mean_y);
            $den += ($i - $mean_x) ** 2;
        }

        // Denominador zero → série constante ou todos os x iguais (impossível com x implícito)
        $a = $den != 0 ? $num / $den : 0.0; // slope
        $b = $mean_y - $a * $mean_x;        // intercept

        // R² (coeficiente de determinação) — adimensional [0,1]
        // Útil para card "qualidade do ajuste" na ForecastChart Phase 27
        $ss_tot = 0.0;
        $ss_res = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $y_pred  = $a * $i + $b;
            $ss_res += ($y[$i] - $y_pred) ** 2;
            $ss_tot += ($y[$i] - $mean_y)  ** 2;
        }

        // ss_tot = 0 → série constante → ajuste perfeito degenerado (não há variação a explicar)
        $r2 = $ss_tot > 0 ? 1.0 - ($ss_res / $ss_tot) : 0.0;

        return ['a' => $a, 'b' => $b, 'r2' => $r2];
    }

    /**
     * Projeta N pontos a partir de startX usando coeficientes da regressão.
     *
     * Fórmula: y = a*x + b para x = startX, startX+1, ..., startX+meses-1
     *
     * $startX é tipicamente count($serieHistorica), ou seja, o próximo índice
     * após o último ponto da série original.
     *
     * @param  array $coef    ['a' => float, 'b' => float] — saída de regressaoLinear()
     * @param  int   $meses   Quantos pontos projetar
     * @param  int   $startX  Índice X do primeiro ponto projetado
     * @return array          [['x' => int, 'y' => float], ...]
     */
    public function projetar(array $coef, int $meses, int $startX): array
    {
        $a   = (float) ($coef['a'] ?? 0);
        $b   = (float) ($coef['b'] ?? 0);
        $out = [];

        for ($i = 0; $i < $meses; $i++) {
            $x     = $startX + $i;
            $out[] = ['x' => $x, 'y' => $a * $x + $b];
        }

        return $out;
    }

    /**
     * Calcula o coeficiente de variação da série (desvio_padrão / média).
     *
     * Retorna valor adimensional. Para exibir como %, multiplicar por 100.
     * Aceita strings via cast defensivo (tgmvLc STRING — pitfall CONTEXT Phase 27).
     *
     * Retorna null quando:
     *   - N < 2 (série insuficiente para calcular desvio)
     *   - média = 0 (divisão por zero — CV indefinido)
     *
     * @param  array      $valores  Série de valores numéricos (ou strings parseáveis)
     * @return float|null           CV adimensional ou null quando indefinido
     */
    public function coeficienteVariacao(array $valores): ?float
    {
        $n = count($valores);

        // N < 2 → desvio padrão não calculável (precisa de pelo menos 2 pontos)
        if ($n < 2) {
            return null;
        }

        // Cast defensivo — aceita strings parseáveis sem TypeError
        $valores = array_map(fn ($v) => (float) $v, $valores);

        $media = array_sum($valores) / $n;

        // média = 0 → divisão por zero → CV indefinido
        if ($media == 0) {
            return null;
        }

        // Variância populacional (divide por N, não N-1)
        // Consistente com o uso em comparação relativa entre sellers
        $variancia = array_sum(array_map(fn ($v) => ($v - $media) ** 2, $valores)) / $n;
        $desvio    = sqrt($variancia);

        // CV adimensional — multiplicar por 100 apenas no frontend (VacasLeiteirasTabela)
        return $desvio / $media;
    }
}
