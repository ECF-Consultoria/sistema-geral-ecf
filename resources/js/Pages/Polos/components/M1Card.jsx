import { Sprout } from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';

const COR_FAT = '#22c55e';   // faturando (verde — "No alvo")
const COR_NAO = '#ef4444';   // não faturou (vermelho)

/**
 * M1Card — coorte de empresas M1 (onboarding). M1 NÃO conta na meta de faturamento,
 * então tem gráfico próprio: donut binário "faturando" vs "não", com a contagem no
 * centro, legenda e quebra por polo. Dados de PolosController::montarM1().
 *
 * Props:
 *   m1      : { total, faturando, nao, faturamento, polos:[{polo,total,faturando,faturamento}] }
 *   fechado : mês fechado (CSV)? — apenas rótulo de origem
 */
export default function M1Card({ m1 = {}, fechado = false }) {
    const total = m1.total ?? 0;
    const fat   = m1.faturando ?? 0;
    const nao   = m1.nao ?? Math.max(total - fat, 0);
    const pctFat = total > 0 ? Math.round((fat / total) * 100) : 0;
    const deg    = total > 0 ? (fat / total) * 360 : 0;
    const polos  = m1.polos ?? [];

    return (
        <div>
            <div className="flex items-center justify-between mb-4">
                <p className="text-white/40 text-xs uppercase tracking-wider flex items-center gap-2">
                    <Sprout size={13} className="text-emerald-400" /> Empresas M1 (onboarding)
                </p>
                <span className="text-white/25 text-[10px]">não conta na meta de faturamento</span>
            </div>

            {total === 0 ? (
                <div className="flex items-center justify-center text-white/35 text-sm" style={{ height: 220 }}>
                    Nenhuma empresa M1 no mês selecionado
                </div>
            ) : (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-center">
                    {/* Donut binário (conic CSS) com contagem central */}
                    <div className="flex justify-center">
                        <div
                            className="relative h-44 w-44 rounded-full"
                            style={{
                                background: `conic-gradient(${COR_FAT} 0deg ${deg}deg, ${COR_NAO} ${deg}deg 360deg)`,
                            }}
                        >
                            <div className="absolute inset-[15%] rounded-full bg-ecf-card flex flex-col items-center justify-center">
                                <span className="font-display text-3xl font-extrabold tabular-nums text-emerald-400">
                                    {fat}<span className="text-white/30 text-xl">/{total}</span>
                                </span>
                                <span className="text-[10px] uppercase tracking-wider text-white/40">faturando</span>
                                <span className="mt-0.5 text-[11px] tabular-nums text-white/45">{pctFat}%</span>
                            </div>
                        </div>
                    </div>

                    {/* Legenda + quebra por polo */}
                    <div>
                        <div className="space-y-1.5">
                            <div className="flex items-center gap-3 rounded-lg px-3 py-2">
                                <span className="h-3 w-3 rounded-full shrink-0" style={{ background: COR_FAT }} />
                                <span className="text-white/80 text-sm flex-1">Faturando (No alvo)</span>
                                <span className="text-white font-semibold text-sm tabular-nums">{fat}</span>
                            </div>
                            <div className="flex items-center gap-3 rounded-lg px-3 py-2">
                                <span className="h-3 w-3 rounded-full shrink-0" style={{ background: COR_NAO }} />
                                <span className="text-white/80 text-sm flex-1">Não faturou</span>
                                <span className="text-white font-semibold text-sm tabular-nums">{nao}</span>
                            </div>
                            <div className="flex items-center justify-between border-t border-white/[0.06] px-3 pt-2.5 mt-1">
                                <span className="text-white/40 text-[11px]">Faturamento M1 no mês</span>
                                <span className="text-ecf-yellow font-semibold text-xs tabular-nums">{formatCurrency(m1.faturamento)}</span>
                            </div>
                        </div>

                        {/* Por polo: faturando / total (barra de cobertura) */}
                        {polos.length > 0 && (
                            <>
                                <p className="text-white/30 text-[11px] uppercase tracking-wider mt-4 mb-2">Faturando por polo</p>
                                <div className={cn('space-y-1', polos.length > 8 && 'max-h-[200px] overflow-y-auto pr-1')}>
                                    {polos.map((p) => {
                                        const pct = p.total > 0 ? (p.faturando / p.total) * 100 : 0;
                                        return (
                                            <div key={p.polo} className="flex items-center gap-3">
                                                <span className="w-28 shrink-0 truncate text-xs text-white/70">{p.polo}</span>
                                                <div className="relative h-2 flex-1 overflow-hidden rounded-full bg-white/[0.06]">
                                                    <div
                                                        className="h-full rounded-full"
                                                        style={{ width: `${pct}%`, background: `linear-gradient(90deg, ${COR_FAT}cc, ${COR_FAT})` }}
                                                    />
                                                </div>
                                                <span className="w-12 shrink-0 text-right text-[11px] tabular-nums text-white/50">{p.faturando}/{p.total}</span>
                                            </div>
                                        );
                                    })}
                                </div>
                            </>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
