<?php

namespace App\Support\Onboarding;

/**
 * ReguaMercadoLider — traduz o `seller_reputation` que a API do Mercado Livre
 * devolve em "onde a conta está e o que falta para a próxima medalha".
 *
 * Por que existe: `GET /users/{id}` entrega as MÉTRICAS do vendedor
 * (reclamações, cancelamentos, despachos com atraso, vendas do período) mas
 * NUNCA os limiares do programa. Comparar uma coisa com a outra é conta nossa.
 *
 * ─── A disciplina que separa esta classe de um chute ────────────────────────
 *
 * Ela afirma DUAS coisas e se recusa a afirmar uma terceira:
 *
 *  1. **Reputação** — `level_id` precisa ser verde escuro (`5_green`). A API
 *     devolve o valor exato; comparar é seguro.
 *  2. **Métricas de qualidade** — reclamações < 1%, cancelamentos < 0,5%,
 *     despachos com atraso < 6%. Todas as fontes consultadas concordam nesses
 *     três limites, e a API devolve exatamente essas três taxas.
 *  3. **Volume (vendas e faturamento) — NÃO afirmamos.** As fontes públicas
 *     divergem entre si (230 vendas/R$ 37.000 em 60 dias × "60+ vendas"), o
 *     Mercado Livre muda os patamares sem aviso, e nenhuma delas é a página
 *     oficial — que bloqueia leitura automatizada. Dizer "faltam 47 vendas"
 *     com esse material seria inventar um número que o cliente levaria a
 *     sério. Então o volume é REPORTADO (quantas vendas, em que janela) sem
 *     veredicto, e quem fecha a conta é a pessoa na reunião.
 *
 * Se um dia alguém confirmar os patamares com o Mercado Livre, o lugar de
 * gravar isso é aqui — e aí `volume` ganha veredicto como os outros.
 */
class ReguaMercadoLider
{
    /** Verde escuro — o único `level_id` que o programa aceita. */
    public const LEVEL_VERDE_ESCURO = '5_green';

    /**
     * Progressão das medalhas. `null` = ainda não é MercadoLíder.
     * Valores conforme `seller_reputation.power_seller_status`.
     */
    public const PROGRESSAO = [null, 'silver', 'gold', 'platinum'];

    public const NOMES = [
        'silver'   => 'MercadoLíder',
        'gold'     => 'MercadoLíder Gold',
        'platinum' => 'MercadoLíder Platinum',
    ];

    /**
     * Teto de cada métrica de qualidade, em fração (0,01 = 1%).
     * Chave = caminho dentro de `seller_reputation.metrics`.
     */
    public const LIMITES = [
        'claims'                => ['teto' => 0.01,  'rotulo' => 'Reclamações'],
        'cancellations'         => ['teto' => 0.005, 'rotulo' => 'Cancelamentos por sua conta'],
        'delayed_handling_time' => ['teto' => 0.06,  'rotulo' => 'Despachos com atraso'],
    ];

    /**
     * Devolve o diagnóstico da conta.
     *
     * Todo campo que a API não entregou vale `null` — nunca `false`, nunca
     * `0`. Um `false` aqui diria "a conta reprovou nesta métrica", que é
     * afirmação diferente de "não sabemos". Mesma disciplina de
     * `MetricasContaResolver::$naoObtidos`.
     *
     * @param  array<string, mixed>|null  $reputacao  O bloco `seller_reputation` cru.
     * @return array{
     *   medalha_atual: ?string,
     *   medalha_atual_nome: ?string,
     *   proxima_medalha: ?string,
     *   proxima_medalha_nome: ?string,
     *   reputacao_verde: ?bool,
     *   metricas: array<int, array{chave:string,rotulo:string,taxa:?float,teto:float,dentro:?bool}>,
     *   volume: array{vendas:?int,periodo:?string},
     *   bloqueios: array<int, string>,
     * }
     */
    public static function diagnosticar(?array $reputacao): array
    {
        $atual = $reputacao['power_seller_status'] ?? null;
        $atual = in_array($atual, self::PROGRESSAO, true) ? $atual : null;

        $levelId = $reputacao['level_id'] ?? null;
        $reputacaoVerde = $levelId === null ? null : ($levelId === self::LEVEL_VERDE_ESCURO);

        $metricas = [];
        $bloqueios = [];

        foreach (self::LIMITES as $chave => $limite) {
            $taxa = $reputacao['metrics'][$chave]['rate'] ?? null;
            $taxa = is_numeric($taxa) ? (float) $taxa : null;

            // `rate` chega como fração (0,012 = 1,2%) — mesma unidade do teto.
            $dentro = $taxa === null ? null : $taxa <= $limite['teto'];

            $metricas[] = [
                'chave'  => $chave,
                'rotulo' => $limite['rotulo'],
                'taxa'   => $taxa,
                'teto'   => $limite['teto'],
                'dentro' => $dentro,
            ];

            if ($dentro === false) {
                $bloqueios[] = sprintf(
                    '%s em %s — o limite é %s',
                    $limite['rotulo'],
                    self::percentual($taxa),
                    self::percentual($limite['teto'])
                );
            }
        }

        if ($reputacaoVerde === false) {
            $bloqueios[] = 'Reputação não está em verde escuro';
        }

        return [
            'medalha_atual'        => $atual,
            'medalha_atual_nome'   => $atual ? (self::NOMES[$atual] ?? null) : null,
            'proxima_medalha'      => self::proxima($atual),
            'proxima_medalha_nome' => self::NOMES[self::proxima($atual)] ?? null,
            'reputacao_verde'      => $reputacaoVerde,
            'metricas'             => $metricas,
            // Sem veredicto de propósito — ver o docblock da classe.
            'volume'               => [
                'vendas'  => isset($reputacao['metrics']['sales']['completed'])
                    ? (int) $reputacao['metrics']['sales']['completed']
                    : null,
                'periodo' => $reputacao['metrics']['sales']['period'] ?? null,
            ],
            'bloqueios'            => $bloqueios,
        ];
    }

    /** Próximo degrau da progressão; `null` quando já está no topo. */
    public static function proxima(?string $atual): ?string
    {
        $indice = array_search($atual, self::PROGRESSAO, true);

        if ($indice === false) {
            return null;
        }

        return self::PROGRESSAO[$indice + 1] ?? null;
    }

    private static function percentual(float $fracao): string
    {
        return rtrim(rtrim(number_format($fracao * 100, 2, ',', '.'), '0'), ',').'%';
    }
}
