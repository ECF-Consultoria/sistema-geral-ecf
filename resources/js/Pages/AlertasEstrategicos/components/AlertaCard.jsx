import { useState } from 'react';
import { router } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { cn } from '@/lib/utils';

/**
 * Phase 23 — Card individual de alerta estratégico.
 *
 * Props:
 *   signal        — objeto Signal da API (id, custId, eventType, severity, payload, detectedAt, ackedAt)
 *   company       — objeto { id, name } do lookup batch, ou null se externo
 *   typeLabels    — mapa eventType → label pt-BR (espelho do PHP TYPE_LABELS)
 *   severityLabels — mapa severity → label pt-BR
 *   severityColors — mapa severity → 'red' | 'yellow' | 'blue'
 */
export default function AlertaCard({ signal, company, typeLabels, severityLabels, severityColors }) {
    const [acking, setAcking] = useState(false);

    const severity      = signal.severity ?? 'info';
    const severityColor = severityColors?.[severity] ?? 'blue';
    const tipoLabel     = typeLabels?.[signal.eventType] ?? signal.eventType;
    const isAcked       = signal.ackedAt != null;

    // Classes Tailwind HARDCODED por cor — necessário para JIT não purgar (evita bg-${color}-500)
    const colorClasses = {
        red: {
            bg:     'bg-red-500/10',
            border: 'border-red-500/25',
            text:   'text-red-300',
            dot:    'bg-red-400',
            badge:  'bg-red-500/15 text-red-300 border-red-500/25',
        },
        yellow: {
            bg:     'bg-yellow-500/10',
            border: 'border-yellow-500/25',
            text:   'text-yellow-300',
            dot:    'bg-yellow-400',
            badge:  'bg-yellow-500/15 text-yellow-300 border-yellow-500/25',
        },
        blue: {
            bg:     'bg-blue-500/10',
            border: 'border-blue-500/25',
            text:   'text-blue-300',
            dot:    'bg-blue-400',
            badge:  'bg-blue-500/15 text-blue-300 border-blue-500/25',
        },
    };

    const colors = colorClasses[severityColor] ?? colorClasses.blue;

    // Dispara POST /alertas-estrategicos/{id}/ack via Inertia
    const handleAck = () => {
        if (acking || isAcked) return;
        setAcking(true);
        router.post(
            route('alertas.ack', signal.id),
            {},
            {
                preserveScroll: true,
                preserveState:  true,
                onFinish:       () => setAcking(false),
            }
        );
    };

    // Tempo relativo pt-BR — ex: "há 2 horas"
    let detectedAgo = '';
    try {
        detectedAgo = formatDistanceToNow(new Date(signal.detectedAt), {
            locale:    ptBR,
            addSuffix: true,
        });
    } catch {
        detectedAgo = signal.detectedAt ?? '';
    }

    // Payload formatado em pt-BR conforme o tipo do alerta
    const payloadTexto = formatPayload(signal.eventType, signal.payload);

    return (
        <div
            className={cn(
                'flex items-start gap-4 p-4 rounded-lg border transition-opacity',
                'bg-ecf-card',
                colors.border,
                isAcked && 'opacity-50',
            )}
        >
            {/* Coluna esquerda: indicador de severidade */}
            <div className="flex flex-col items-center gap-1.5 pt-0.5 shrink-0">
                <div className={cn('w-2.5 h-2.5 rounded-full shrink-0', colors.dot)} />
                <span className={cn(
                    'text-[9px] font-semibold uppercase tracking-widest writing-mode-vertical',
                    colors.text,
                )}>
                    {severityLabels?.[severity] ?? severity}
                </span>
            </div>

            {/* Coluna central: conteúdo principal */}
            <div className="flex-1 min-w-0 space-y-1">
                {/* Tipo do alerta + empresa */}
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span className="text-sm font-semibold text-white">{tipoLabel}</span>
                    <span className="text-white/20 text-xs">·</span>
                    {company ? (
                        <span className="text-sm font-medium text-ecf-yellow truncate">
                            {company.name}
                        </span>
                    ) : (
                        <span className="text-sm text-white/40 italic">
                            Cliente externo: {signal.custId}
                        </span>
                    )}
                </div>

                {/* Payload formatado pt-BR */}
                {payloadTexto && (
                    <p className="text-sm text-white/70 leading-relaxed">{payloadTexto}</p>
                )}

                {/* Metadata: data + indicador de visto */}
                <div className="flex items-center gap-3 text-[11px] text-white/30">
                    <span>Detectado {detectedAgo}</span>
                    {isAcked && signal.ackedAt && (
                        <>
                            <span>·</span>
                            <span className="text-white/40">
                                Visto {formatDistanceToNow(new Date(signal.ackedAt), { locale: ptBR, addSuffix: true })}
                            </span>
                        </>
                    )}
                </div>
            </div>

            {/* Coluna direita: botão de ack */}
            <div className="shrink-0">
                {isAcked ? (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs text-white/30 border border-white/[0.08] rounded-md">
                        <CheckCircle2 size={13} /> Já visto
                    </span>
                ) : (
                    <button
                        type="button"
                        onClick={handleAck}
                        disabled={acking}
                        className={cn(
                            'inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium',
                            'border rounded-md transition-all',
                            acking
                                ? 'text-white/30 border-white/[0.06] cursor-wait'
                                : 'text-white/60 border-white/[0.10] hover:text-white hover:border-white/25',
                        )}
                    >
                        <CheckCircle2 size={13} />
                        {acking ? 'Marcando...' : 'Marcar como visto'}
                    </button>
                )}
            </div>
        </div>
    );
}

// ─── Formatador de payload pt-BR ───────────────────────────────────────────

/**
 * Formata o payload do signal em texto amigável pt-BR conforme o event_type.
 * Função pura — chamada diretamente no JSX, sem efeitos colaterais.
 *
 * @param  {string} eventType  - event_type da API (ex: 'seller.gmv_queda_mom')
 * @param  {object} payload    - payload do signal (varia por tipo)
 * @returns {string}           - texto formatado pt-BR ou '' se payload inválido
 */
export function formatPayload(eventType, payload) {
    if (!payload || typeof payload !== 'object') return '';

    // Helpers locais com tratamento de NaN/Infinity
    const fmtBRL = (n) => {
        const num = parseFloat(n);
        if (!isFinite(num)) return '—';
        return num.toLocaleString('pt-BR', {
            style: 'currency', currency: 'BRL', maximumFractionDigits: 0,
        });
    };

    const fmtPct = (n) => {
        const num = parseFloat(n);
        if (!isFinite(num)) return '—';
        return `${Math.abs(num).toFixed(1)}%`;
    };

    const fmtInt = (n) => {
        const num = Math.round(parseFloat(n));
        if (!isFinite(num)) return '—';
        return num.toLocaleString('pt-BR');
    };

    switch (eventType) {
        case 'seller.gmv_queda_mom': {
            const { delta_pct, gmv_atual, gmv_anterior, mes_atual } = payload;
            const partes = [
                `GMV caiu ${fmtPct(delta_pct)} (${fmtBRL(gmv_anterior)} → ${fmtBRL(gmv_atual)})`,
            ];
            if (mes_atual) partes.push(`em ${mes_atual}`);
            return partes.join(' ');
        }

        case 'seller.queda_visitas': {
            const { delta_pct, visitas_atual, visitas_anterior } = payload;
            return `Visitas caíram ${fmtPct(delta_pct)} (${fmtInt(visitas_anterior)} → ${fmtInt(visitas_atual)})`;
        }

        case 'seller.medalha_rebaixada': {
            const { medalha_anterior, medalha_atual } = payload;
            return `Medalha rebaixada de ${medalha_anterior ?? '—'} para ${medalha_atual ?? '—'}`;
        }

        case 'seller.score_critico': {
            const { score_final_full, score_qualidade_final } = payload;
            const partes = [];
            if (score_final_full != null)       partes.push(`Score Full ${fmtInt(score_final_full)}`);
            if (score_qualidade_final != null)  partes.push(`Score Qualidade ${fmtInt(score_qualidade_final)}`);
            return partes.length ? partes.join(' · ') : 'Score crítico detectado';
        }

        case 'seller.oportunidade_pads': {
            const { gmv_mensal } = payload;
            return `GMV mensal ${fmtBRL(gmv_mensal)} sem ADS — oportunidade de PADS`;
        }

        default: {
            // Fallback genérico: 3 primeiras entradas do payload
            return Object.entries(payload)
                .slice(0, 3)
                .map(([k, v]) => `${k}: ${v}`)
                .join(' · ');
        }
    }
}
