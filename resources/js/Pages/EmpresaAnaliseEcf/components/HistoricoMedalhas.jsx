import { Award, TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * HistoricoMedalhas — timeline horizontal de promoções/rebaixamentos de medalha (Phase 25).
 *
 * Exibe cards em sequência cronológica mostrando o nível de medalha de cada mês
 * com indicador visual comparando com o mês anterior (subiu/caiu/manteve).
 *
 * Props:
 *   medalhas — array de { timMonthId, nivel } (default [])
 */

// ─── Badge de medalha com classes Tailwind HARDCODED ─────────────────────────
// NÃO interpolar bg-${nivel}-500/15 — JIT do Tailwind purgaria classes dinâmicas.
// `ordem` é usado para comparar níveis: PLATINUM(4) > GOLD(3) > SILVER(2) > BRONZE(1)
const MEDALHA_BADGE = {
    PLATINUM: { label: 'Platinum',  ordem: 4, bg: 'bg-cyan-500/15',   text: 'text-cyan-300',   border: 'border-cyan-500/30' },
    GOLD:     { label: 'Gold',      ordem: 3, bg: 'bg-yellow-500/15', text: 'text-yellow-300', border: 'border-yellow-500/30' },
    SILVER:   { label: 'Silver',    ordem: 2, bg: 'bg-slate-500/15',  text: 'text-slate-300',  border: 'border-slate-500/30' },
    BRONZE:   { label: 'Bronze',    ordem: 1, bg: 'bg-amber-700/15',  text: 'text-amber-400',  border: 'border-amber-700/30' },
};

// ─── Helpers locais ──────────────────────────────────────────────────────────

const MESES_PT = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

const fmtMesAno = (timMonthId) => {
    if (!timMonthId || timMonthId.length !== 6) return timMonthId ?? '';
    const ano = timMonthId.slice(0, 4);
    const mes = parseInt(timMonthId.slice(4, 6), 10);
    if (isNaN(mes) || mes < 1 || mes > 12) return timMonthId;
    return `${MESES_PT[mes - 1]}/${ano.slice(2)}`;
};

/**
 * Compara dois níveis de medalha pelo campo `ordem`.
 * Retorna 'subiu' | 'caiu' | 'manteve' | null (quando prev é null).
 */
const compararNiveis = (prev, atual) => {
    if (!prev) return null;
    const ordemPrev  = MEDALHA_BADGE[prev]?.ordem  ?? 0;
    const ordemAtual = MEDALHA_BADGE[atual]?.ordem ?? 0;
    if (ordemAtual > ordemPrev)  return 'subiu';
    if (ordemAtual < ordemPrev)  return 'caiu';
    return 'manteve';
};

// ─── Componente principal ─────────────────────────────────────────────────────

export default function HistoricoMedalhas({ medalhas = [] }) {
    // Empty state quando não há histórico de medalhas
    if (medalhas.length === 0) {
        return (
            <div className="h-32 flex items-center justify-center text-white/30 text-sm">
                Sem histórico de medalhas registrado.
            </div>
        );
    }

    return (
        // Scroll horizontal quando há muitas medalhas
        <div className="overflow-x-auto">
            <div className="flex gap-3 min-w-min pb-2">
                {medalhas.map((item, idx) => {
                    const nivel   = item.nivel;
                    const config  = MEDALHA_BADGE[nivel] ?? null;
                    const mudanca = compararNiveis(medalhas[idx - 1]?.nivel, nivel);

                    return (
                        <div
                            key={item.timMonthId ?? idx}
                            className="w-28 shrink-0 rounded-lg border bg-ecf-card border-white/[0.06] p-3 flex flex-col items-center gap-1.5 text-center"
                        >
                            {/* Mês/ano formatado */}
                            <span className="text-[10px] text-white/40 font-mono">
                                {fmtMesAno(item.timMonthId)}
                            </span>

                            {/* Badge da medalha */}
                            {config ? (
                                <span className={cn(
                                    'inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold border',
                                    config.bg,
                                    config.text,
                                    config.border,
                                )}>
                                    <Award size={10} />
                                    {config.label}
                                </span>
                            ) : (
                                <span className="text-[10px] text-white/30">{nivel ?? '—'}</span>
                            )}

                            {/* Indicador de mudança vs. mês anterior */}
                            {mudanca === 'subiu' && (
                                <div className="flex items-center gap-1 text-[10px] text-emerald-400">
                                    <TrendingUp size={11} />
                                    <span>Subiu</span>
                                </div>
                            )}
                            {mudanca === 'caiu' && (
                                <div className="flex items-center gap-1 text-[10px] text-red-400">
                                    <TrendingDown size={11} />
                                    <span>Caiu</span>
                                </div>
                            )}
                            {mudanca === 'manteve' && (
                                <div className="flex items-center gap-1 text-[10px] text-white/30">
                                    <Minus size={11} />
                                    <span>Manteve</span>
                                </div>
                            )}
                            {/* mudanca === null = primeiro item da timeline, sem indicador */}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
