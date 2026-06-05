import { AlertOctagon, AlertTriangle, Lightbulb } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Phase 23 — Header de KPI cards para /alertas-estrategicos.
 *
 * Exibe 3 contadores de alertas não-ackeados por severidade:
 *   Críticos (vermelho) · Atenção (amarelo) · Oportunidades (azul)
 *
 * Props:
 *   stats — objeto { critical: number, warning: number, info: number }
 *
 * Cores Tailwind hardcoded — necessário para JIT não purgar classes dinâmicas.
 */
export default function StatsHeader({ stats }) {
    // Configuração estática de cada card com classes hardcoded
    const cards = [
        {
            key:       'critical',
            label:     'Críticos',
            icon:      AlertOctagon,
            value:     stats?.critical ?? 0,
            bg:        'bg-red-500/10',
            border:    'border-red-500/30',
            text:      'text-red-300',
            iconColor: 'text-red-400',
        },
        {
            key:       'warning',
            label:     'Atenção',
            icon:      AlertTriangle,
            value:     stats?.warning ?? 0,
            bg:        'bg-yellow-500/10',
            border:    'border-yellow-500/30',
            text:      'text-yellow-300',
            iconColor: 'text-yellow-400',
        },
        {
            key:       'info',
            label:     'Oportunidades',
            icon:      Lightbulb,
            value:     stats?.info ?? 0,
            bg:        'bg-blue-500/10',
            border:    'border-blue-500/30',
            text:      'text-blue-300',
            iconColor: 'text-blue-400',
        },
    ];

    return (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            {cards.map(card => {
                const Icon = card.icon;
                return (
                    <div
                        key={card.key}
                        className={cn(
                            'flex items-center gap-4 p-4 rounded-lg border',
                            card.bg,
                            card.border,
                        )}
                    >
                        {/* Ícone */}
                        <div className="p-2.5 rounded-lg bg-ecf-bg shrink-0">
                            <Icon className={card.iconColor} size={20} />
                        </div>

                        {/* Texto + número */}
                        <div>
                            <div className="text-[11px] uppercase tracking-wider text-white/40">
                                {card.label}
                            </div>
                            <div className={cn('text-2xl font-bold tabular-nums', card.text)}>
                                {card.value.toLocaleString('pt-BR')}
                            </div>
                            <div className="text-[10px] text-white/30">não-vistos</div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
