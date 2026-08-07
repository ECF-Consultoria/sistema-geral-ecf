import { Calendar, Trophy } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    PERIODO_EM_CURSO_TITULO, PERIODO_EM_CURSO_DETALHE,
    PERIODO_FECHADO_TITULO, PERIODO_FECHADO_DETALHE,
    PERIODO_BONUS_TITULO, PERIODO_BONUS_DETALHE,
} from '@/lib/desempenhoLabels';

/**
 * Banner de contexto de período — UMA linha, explicação longa no tooltip.
 *
 * Antes o mesmo parágrafo aparecia escrito à mão em Performance/Show,
 * Performance/Index e Portfolio/AdminCarteira, ocupando um bloco de 4 linhas
 * no topo das três telas. O texto de contexto não é a informação que a pessoa
 * veio buscar; empurrava o dado real para baixo da dobra.
 *
 * Componente de apresentação puro — sem router, sem usePage, sem fetch —,
 * porque é consumido por três páginas com contratos de props diferentes.
 *
 * Props:
 *   modo      — 'em_curso' | 'mes_fechado' | 'bonus_atual'
 *   resumo    — string curta do lado direito (ex: "1–14/08 vs 1–14/07")
 *   mesLabel  — mês por extenso, quando o modo for 'mes_fechado'
 */
export default function PeriodoBanner({ modo, resumo = null, mesLabel = null, className = '' }) {
    const cfg = {
        em_curso: {
            Icone: Calendar,
            titulo: PERIODO_EM_CURSO_TITULO,
            detalhe: PERIODO_EM_CURSO_DETALHE,
            cls: 'border-amber-500/25 bg-amber-500/[0.05] text-amber-200',
            iconeCls: 'text-amber-300',
        },
        bonus_atual: {
            Icone: Trophy,
            titulo: PERIODO_BONUS_TITULO,
            detalhe: PERIODO_BONUS_DETALHE,
            cls: 'border-ecf-yellow/25 bg-ecf-yellow/[0.05] text-ecf-yellow/90',
            iconeCls: 'text-ecf-yellow',
        },
        mes_fechado: {
            Icone: Calendar,
            titulo: mesLabel ? `${PERIODO_FECHADO_TITULO} — ${mesLabel}` : PERIODO_FECHADO_TITULO,
            detalhe: PERIODO_FECHADO_DETALHE,
            cls: 'border-white/[0.1] bg-white/[0.03] text-white/80',
            iconeCls: 'text-white/50',
        },
    }[modo] ?? null;

    if (!cfg) return null;

    const { Icone } = cfg;

    return (
        <div
            title={cfg.detalhe}
            className={cn(
                'rounded-xl border px-4 py-2.5 flex items-center gap-2.5 flex-wrap text-sm cursor-help',
                cfg.cls,
                className,
            )}
        >
            <Icone size={14} className={cn('shrink-0', cfg.iconeCls)} />
            <span className="font-semibold capitalize">{cfg.titulo}</span>
            {resumo && (
                <>
                    <span className="opacity-30">·</span>
                    <span className="opacity-70 text-[13px]">{resumo}</span>
                </>
            )}
        </div>
    );
}
