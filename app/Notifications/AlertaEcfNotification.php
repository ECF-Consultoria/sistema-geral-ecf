<?php

namespace App\Notifications;

/**
 * Notificação disparada quando o ECF Drive emite um `signal.detected` com
 * severity=critical para empresa da NOSSA carteira (Phase 29).
 *
 * Subclasse concreta de `BaseNotification` — mesma convenção das Phases 11/12
 * (`MetaAtribuidaNotification`, `ManualNotification`, `EmpresaCadastradaNotification`).
 *
 * Monta título via `TYPE_LABELS` (espelho de `AlertasController::TYPE_LABELS`
 * da Phase 23 — duplicação consciente, documentada). Monta mensagem via
 * helper privado estático `formatPayload` que replica a lógica do JSX
 * `formatPayload` de `AlertaCard.jsx` (Phase 23).
 *
 * NÃO sobrescreve `via()` nem `toArray()` — canal único `database` e payload
 * canônico de 6 chaves vêm 100% do `BaseNotification`.
 */
class AlertaEcfNotification extends BaseNotification
{
    /**
     * Labels pt-BR por event_type — espelho de `AlertasController::TYPE_LABELS`
     * (Phase 23). Prefixo "crítica" nos 2 primeiros: notificação SOMENTE
     * aparece para severity=critical, então ressaltar a gravidade é coerente.
     *
     * Duplicação consciente: evita acoplar Job a um Controller. Se mudar em
     * AlertasController, ajustar aqui também (e vice-versa).
     */
    private const TYPE_LABELS = [
        'seller.gmv_queda_mom'     => 'Queda crítica de faturamento',
        'seller.queda_visitas'     => 'Queda crítica de visitas',
        'seller.medalha_rebaixada' => 'Medalha rebaixada',
        'seller.score_critico'     => 'Score crítico',
        'seller.oportunidade_pads' => 'Oportunidade de ADS detectada',
    ];

    /**
     * Constrói a notificação de alerta estratégico crítico.
     *
     * @param  int|string|null  $signalId       ID do signal na API ECF Drive (cast para string no meta)
     * @param  string|null      $eventType      Tipo do evento (ex: 'seller.gmv_queda_mom')
     * @param  string           $custId         Identificador da conta Adman / ML Store
     * @param  string           $empresaNome    Nome da empresa da carteira (Company::name)
     * @param  array            $payloadResumido Dados numéricos do payload do signal (ex: delta_pct, gmv_atual)
     * @param  string           $link           Link de destino ao clicar na notificação
     */
    public function __construct(
        int|string|null $signalId,
        ?string $eventType,
        string $custId,
        string $empresaNome,
        array $payloadResumido = [],
        string $link = '/alertas-estrategicos',
    ) {
        $label  = self::TYPE_LABELS[$eventType] ?? ($eventType ?: 'Alerta crítico');
        $titulo = "{$label} em {$empresaNome}";

        parent::__construct(
            titulo:      $titulo,
            mensagem:    self::formatPayload($eventType, $payloadResumido),
            categoria:   Categoria::ALERTA_ECF,
            autorUserId: null,
            url:         $link,
            meta:        [
                'signal_id'  => (string) $signalId,
                'event_type' => $eventType,
                'cust_id'    => $custId,
                'link'       => $link,
                'severity'   => 'critical',  // Fixo — esta subclasse só existe para critical
            ],
        );
    }

    // ═══ HELPERS PRIVADOS ═══

    /**
     * Formata o payload do signal em mensagem legível pt-BR.
     *
     * Replica a função `formatPayload` de
     * `resources/js/Pages/AlertasEstrategicos/components/AlertaCard.jsx` (Phase 23)
     * — duplicação consciente (PHP não executa JS; lógica simples, estável).
     */
    private static function formatPayload(?string $eventType, array $payload): string
    {
        switch ($eventType) {
            case 'seller.gmv_queda_mom':
                $delta     = self::fmtPct($payload['delta_pct'] ?? null);
                $atual     = self::fmtBRL($payload['gmv_atual'] ?? null);
                $anterior  = self::fmtBRL($payload['gmv_anterior'] ?? null);
                $mes       = $payload['mes_atual'] ?? null;
                $base      = "GMV caiu {$delta} ({$anterior} → {$atual})";
                return $mes ? "{$base} em {$mes}" : $base;

            case 'seller.queda_visitas':
                $delta    = self::fmtPct($payload['delta_pct'] ?? null);
                $atual    = self::fmtInt($payload['visitas_atual'] ?? null);
                $anterior = self::fmtInt($payload['visitas_anterior'] ?? null);
                return "Visitas caíram {$delta} ({$anterior} → {$atual})";

            case 'seller.medalha_rebaixada':
                $de  = $payload['medalha_anterior'] ?? '—';
                $para = $payload['medalha_atual'] ?? '—';
                return "Medalha rebaixada de {$de} para {$para}";

            case 'seller.score_critico':
                $full       = $payload['score_full'] ?? null;
                $qualidade  = $payload['score_qualidade'] ?? null;
                if ($full !== null && $qualidade !== null) {
                    return 'Score Full ' . (int) $full . ' · Score Qualidade ' . (int) $qualidade;
                }
                if ($full !== null) {
                    return 'Score Full ' . (int) $full;
                }
                return 'Score crítico detectado';

            case 'seller.oportunidade_pads':
                $gmv = self::fmtBRL($payload['gmv_mensal'] ?? null);
                return "GMV mensal {$gmv} sem ADS — oportunidade de PADS";

            default:
                // Fallback: 3 primeiras entradas do payload concatenadas
                $partes = [];
                $i = 0;
                foreach ($payload as $k => $v) {
                    if ($i >= 3) break;
                    $partes[] = "{$k}: {$v}";
                    $i++;
                }
                return $partes ? implode(' · ', $partes) : 'Alerta crítico detectado';
        }
    }

    /**
     * Formata valor monetário pt-BR: R$ 11.135 (sem casas decimais, milhar com ponto).
     */
    private static function fmtBRL(float|int|string|null $n): string
    {
        $num = is_numeric($n) ? (float) $n : null;
        if ($num === null) return '—';
        return 'R$ ' . number_format($num, 0, ',', '.');
    }

    /**
     * Formata percentual pt-BR: 76,5% (sempre positivo — usa abs()).
     */
    private static function fmtPct(float|int|string|null $n): string
    {
        $num = is_numeric($n) ? abs((float) $n) : null;
        if ($num === null) return '—';
        return number_format($num, 1, ',', '.') . '%';
    }

    /**
     * Formata inteiro pt-BR: 12.345 (milhar com ponto).
     */
    private static function fmtInt(float|int|string|null $n): string
    {
        $num = is_numeric($n) ? (int) round((float) $n) : null;
        if ($num === null) return '—';
        return number_format($num, 0, ',', '.');
    }
}
