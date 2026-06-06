import { CheckCircle2, AlertTriangle } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { cn } from '@/lib/utils';
import { formatPayload } from '@/Pages/AlertasEstrategicos/components/AlertaCard';

/**
 * AlertasDoSeller — lista read-only dos alertas/signals de um seller (Phase 25).
 *
 * Reutiliza `formatPayload` do AlertaCard (Phase 23) para exibir o payload
 * em texto amigável pt-BR sem duplicar a lógica de formatação.
 *
 * IMPORTANTE: esta seção é SOMENTE LEITURA. Não há botão "Marcar como visto".
 * Para tomar ações sobre alertas, o usuário deve acessar /alertas-estrategicos.
 *
 * Props:
 *   signals — array de { id, eventType, severity, payload, detectedAt, ackedAt }
 *             (default [], já limitado a 20 pelo controller PHP)
 */

// ─── Mapeamentos de labels — cópia do AlertaCard Phase 23 ─────────────────────
// NÃO importar estas constantes do AlertaCard (não são exportadas) — cópia local.

const TYPE_LABELS = {
    'seller.gmv_queda_mom':    'Queda de Faturamento',
    'seller.queda_visitas':    'Queda de Visitas',
    'seller.medalha_rebaixada':'Rebaixamento de Medalha',
    'seller.score_critico':    'Score Crítico',
    'seller.oportunidade_pads':'Oportunidade PADS',
};

const SEVERITY_LABELS = {
    critical: 'Crítico',
    warning:  'Aviso',
    info:     'Info',
};

// Classes Tailwind HARDCODED por cor — necessário para JIT não purgar (evita bg-${color}-500)
const COLOR_CLASSES = {
    critical: {
        bg:     'bg-red-500/10',
        border: 'border-red-500/25',
        text:   'text-red-300',
        dot:    'bg-red-400',
    },
    warning: {
        bg:     'bg-yellow-500/10',
        border: 'border-yellow-500/25',
        text:   'text-yellow-300',
        dot:    'bg-yellow-400',
    },
    info: {
        bg:     'bg-blue-500/10',
        border: 'border-blue-500/25',
        text:   'text-blue-300',
        dot:    'bg-blue-400',
    },
};

// ─── Componente principal ─────────────────────────────────────────────────────

export default function AlertasDoSeller({ signals = [] }) {
    // Empty state quando não há alertas
    if (signals.length === 0) {
        return (
            <div className="h-32 flex items-center justify-center text-white/30 text-sm">
                Sem alertas ativos para este seller.
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {/* Aviso explícito sobre read-only e link para ação */}
            <p className="text-xs text-white/40">
                Mostrando {signals.length} alerta{signals.length !== 1 ? 's' : ''} mais
                recente{signals.length !== 1 ? 's' : ''} deste seller. Para tomar ação (marcar
                como visto), acesse{' '}
                <a
                    href="/alertas-estrategicos"
                    className="text-ecf-yellow hover:underline"
                >
                    /alertas-estrategicos
                </a>
                .
            </p>

            {/* Lista de cards */}
            <div className="space-y-2">
                {signals.map((signal, idx) => {
                    const severity     = signal.severity ?? 'info';
                    const colors       = COLOR_CLASSES[severity] ?? COLOR_CLASSES.info;
                    const tipoLabel    = TYPE_LABELS[signal.eventType] ?? signal.eventType;
                    const isAcked      = signal.ackedAt != null;
                    const payloadTexto = formatPayload(signal.eventType, signal.payload);

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

                    return (
                        <div
                            key={signal.id ?? idx}
                            className={cn(
                                'flex items-start gap-4 p-4 rounded-lg border',
                                'bg-ecf-card',
                                colors.border,
                                isAcked && 'opacity-50',
                            )}
                        >
                            {/* Coluna esquerda: dot de severidade */}
                            <div className="flex flex-col items-center gap-1.5 pt-0.5 shrink-0">
                                <div className={cn('w-2.5 h-2.5 rounded-full shrink-0', colors.dot)} />
                                <span className={cn(
                                    'text-[9px] font-semibold uppercase tracking-widest',
                                    colors.text,
                                )}>
                                    {SEVERITY_LABELS[severity] ?? severity}
                                </span>
                            </div>

                            {/* Coluna central: tipo + payload + tempo */}
                            <div className="flex-1 min-w-0 space-y-1">
                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <span className="text-sm font-semibold text-white">{tipoLabel}</span>
                                </div>

                                {payloadTexto && (
                                    <p className="text-sm text-white/70 leading-relaxed">{payloadTexto}</p>
                                )}

                                <div className="flex items-center gap-3 text-[11px] text-white/30">
                                    <span>Detectado {detectedAgo}</span>
                                    {isAcked && signal.ackedAt && (
                                        <>
                                            <span>·</span>
                                            <span className="text-white/40 flex items-center gap-1">
                                                <CheckCircle2 size={10} />
                                                Já visto
                                            </span>
                                        </>
                                    )}
                                </div>
                            </div>

                            {/* Coluna direita: indicador de "já visto" — SEM botão ack (read-only) */}
                            {isAcked && (
                                <div className="shrink-0">
                                    <span className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs text-white/30 border border-white/[0.08] rounded-md">
                                        <CheckCircle2 size={13} /> Já visto
                                    </span>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
