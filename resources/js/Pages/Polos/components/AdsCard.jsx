import { useEffect, useMemo, useState } from 'react';
import { Megaphone, Info, ChevronDown, ChevronRight } from 'lucide-react';
import { cn, formatCurrency, formatCurrencyCompact } from '@/lib/utils';

const COR_ADS  = '#38bdf8';   // sky — identidade de ADS (mesma do SparkSemanal)
const COR_OVER = '#f43f5e';   // rose — estourou o teto disponível
const FASES    = ['M2', 'M3', 'M4'];

// Linha de barra "gasto / disponível" (ADS). Estoura → rosa.
function BarraAds({ label, gasto, disponivel, sub, mounted = true }) {
    const pct  = disponivel > 0 ? Math.min((gasto / disponivel) * 100, 100) : 0;
    const over = disponivel > 0 && gasto > disponivel;
    const cor  = over ? COR_OVER : COR_ADS;
    return (
        <div className="flex items-center gap-3">
            <span className="w-16 shrink-0 truncate text-sm text-white/80">{label}</span>
            <div className="relative h-2.5 flex-1 overflow-hidden rounded-full bg-white/[0.06]">
                <div
                    className="h-full rounded-full transition-[width] duration-700 ease-out"
                    style={{ width: `${mounted ? pct : 0}%`, background: `linear-gradient(90deg, ${cor}cc, ${cor})` }}
                />
            </div>
            <span className="w-44 shrink-0 text-right text-xs tabular-nums text-white/65">
                {formatCurrency(gasto)} <span className="text-white/30">/ {formatCurrency(disponivel)}</span>
            </span>
            {sub != null && <span className="w-12 shrink-0 text-right text-[11px] tabular-nums text-white/40">{sub}</span>}
        </div>
    );
}

// Valor compacto (R$ 279K) p/ caber nos cards estreitos; `title` mostra o valor exato no hover.
function MiniKpi({ label, valor, title, cor = 'text-white/85' }) {
    return (
        <div className="min-w-0 rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5">
            <p className="text-white/35 text-[10px] uppercase tracking-wider truncate">{label}</p>
            <p title={title} className={cn('mt-0.5 font-display font-extrabold text-base tabular-nums whitespace-nowrap', cor)}>{valor}</p>
        </div>
    );
}

/**
 * AdsCard — Saldo de ADS do mês. "Disponível" = teto por empresa (R$3.000) × nº de
 * empresas ativas; "Gasto" = investment (Adman) somado. Quebra por estágio (M2/M3/M4)
 * e por polo (clicável → drawer). ADS só é apurado no mês corrente (mês fechado = sem fonte).
 *
 * Props:
 *   polos   : polos visíveis ({ polo, ativos, empresas:[{ fase, ads }] })
 *   teto    : teto de ADS por empresa (adsLimites.teto, default 3000)
 *   fechado : mês fechado (CSV)? ADS indisponível → aviso
 *   onPolo  : (polo) => void — abre o drawer
 */
export default function AdsCard({ polos = [], teto = 3000, fechado = false, onPolo }) {
    const [mounted, setMounted] = useState(false);
    const [verEmpresas, setVerEmpresas] = useState(true);
    useEffect(() => {
        const id = requestAnimationFrame(() => setMounted(true));
        return () => cancelAnimationFrame(id);
    }, []);

    const dados = useMemo(() => {
        const fases   = { M2: { n: 0, gasto: 0 }, M3: { n: 0, gasto: 0 }, M4: { n: 0, gasto: 0 } };
        let gastoTot = 0, nTot = 0;
        const porPolo = [];
        const porEmpresa = [];

        polos.forEach((p) => {
            let gastoPolo = 0;
            const nPolo = p.ativos ?? (p.empresas ?? []).length;
            (p.empresas ?? []).forEach((e) => {
                const g = Number(e.ads) || 0;
                gastoPolo += g;
                gastoTot  += g;
                nTot++;
                if (fases[e.fase]) { fases[e.fase].n++; fases[e.fase].gasto += g; }
                porEmpresa.push({ cust_id: e.cust_id, nome: e.nome, polo: p.polo, fase: e.fase, gasto: g });
            });
            porPolo.push({ polo: p.polo, ref: p, gasto: gastoPolo, disponivel: teto * nPolo, n: nPolo });
        });

        porPolo.sort((a, b) => b.gasto - a.gasto);
        porEmpresa.sort((a, b) => b.gasto - a.gasto); // maiores gastadores primeiro
        const dispTot = teto * nTot;
        return { fases, gastoTot, nTot, dispTot, porPolo, porEmpresa };
    }, [polos, teto]);

    const saldo    = Math.max(dados.dispTot - dados.gastoTot, 0);
    const pctUso   = dados.dispTot > 0 ? (dados.gastoTot / dados.dispTot) * 100 : 0;

    return (
        <div>
            {/* Cabeçalho */}
            <div className="flex items-center justify-between mb-4">
                <p className="text-white/40 text-xs uppercase tracking-wider flex items-center gap-2">
                    <Megaphone size={13} className="text-sky-400" /> Saldo de ADS do mês
                </p>
                <span className="text-white/25 text-[10px]">teto {formatCurrency(teto)} / empresa</span>
            </div>

            {/* Aviso de mês fechado (sem fonte de gasto) */}
            {fechado && (
                <div className="mb-4 flex items-start gap-2 rounded-lg border border-white/[0.08] bg-white/[0.02] p-3">
                    <Info size={14} className="text-white/40 mt-0.5 shrink-0" />
                    <p className="text-white/45 text-[11px] leading-snug">
                        O gasto de ADS é apurado pela Adman apenas no mês corrente. Para meses fechados,
                        exibimos o disponível teórico (teto × ativos); o gasto aparece como R$0.
                    </p>
                </div>
            )}

            {/* Mini-KPIs do saldo (valor compacto p/ não transbordar; exato no hover) */}
            <div className="grid grid-cols-2 gap-2.5">
                <MiniKpi label="Disponível" valor={formatCurrencyCompact(dados.dispTot)} title={formatCurrency(dados.dispTot)} cor="text-sky-400" />
                <MiniKpi label="Gasto" valor={fechado ? '—' : formatCurrencyCompact(dados.gastoTot)} title={fechado ? undefined : formatCurrency(dados.gastoTot)} />
                <MiniKpi label="Saldo restante" valor={fechado ? '—' : formatCurrencyCompact(saldo)} title={fechado ? undefined : formatCurrency(saldo)} />
                <MiniKpi label="% utilizado" valor={fechado ? '—' : `${pctUso.toFixed(1)}%`} cor={pctUso > 100 ? 'text-rose-400' : 'text-white/85'} />
            </div>

            {/* Quebra por estágio M2/M3/M4 */}
            <p className="text-white/30 text-[11px] uppercase tracking-wider mt-5 mb-2">Por estágio</p>
            <div className="space-y-2">
                {FASES.map((f) => {
                    const { n, gasto } = dados.fases[f];
                    return (
                        <BarraAds
                            key={f}
                            label={f}
                            gasto={gasto}
                            disponivel={teto * n}
                            sub={`${n} ${n === 1 ? 'emp.' : 'emps.'}`}
                            mounted={mounted}
                        />
                    );
                })}
            </div>

            {/* Quebra por polo (clicável → drawer) */}
            <p className="text-white/30 text-[11px] uppercase tracking-wider mt-5 mb-2">Por polo</p>
            <div className={cn('space-y-1', dados.porPolo.length > 10 && 'max-h-[300px] overflow-y-auto pr-1')}>
                {dados.porPolo.map((p) => {
                    const pct  = p.disponivel > 0 ? Math.min((p.gasto / p.disponivel) * 100, 100) : 0;
                    const over = p.disponivel > 0 && p.gasto > p.disponivel;
                    const cor  = over ? COR_OVER : COR_ADS;
                    return (
                        <button
                            key={p.polo}
                            type="button"
                            onClick={() => onPolo?.(p.ref)}
                            title={`Ver empresas do polo ${p.polo}`}
                            className="flex w-full items-center gap-3 rounded-lg px-2 py-1.5 text-left transition hover:bg-white/[0.03] focus:outline-none focus:ring-2 focus:ring-ecf-yellow/40"
                        >
                            <span className="w-32 shrink-0 truncate text-sm text-white/80 sm:w-40">{p.polo}</span>
                            <div className="relative h-2 flex-1 overflow-hidden rounded-full bg-white/[0.06]">
                                <div
                                    className="h-full rounded-full transition-[width] duration-700 ease-out"
                                    style={{ width: `${mounted ? pct : 0}%`, background: `linear-gradient(90deg, ${cor}cc, ${cor})` }}
                                />
                            </div>
                            <span className="w-44 shrink-0 text-right text-xs tabular-nums text-white/65">
                                {formatCurrency(p.gasto)} <span className="text-white/30">/ {formatCurrency(p.disponivel)}</span>
                            </span>
                        </button>
                    );
                })}
            </div>

            {/* Gasto individual por empresa (recolhível; ADS é apurado só no mês corrente) */}
            <button
                type="button"
                onClick={() => setVerEmpresas((v) => !v)}
                className="mt-5 flex w-full items-center gap-1.5 text-[11px] uppercase tracking-wider text-white/40 transition hover:text-white/70"
            >
                {verEmpresas ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
                Por empresa — gasto individual ({dados.porEmpresa.length})
            </button>
            {verEmpresas && (
                <div className={cn('mt-2 space-y-1', dados.porEmpresa.length > 10 && 'max-h-[320px] overflow-y-auto pr-1')}>
                    {dados.porEmpresa.length === 0 && (
                        <p className="py-3 text-center text-[11px] text-white/30">Nenhuma empresa ativa no mês.</p>
                    )}
                    {dados.porEmpresa.map((e, i) => {
                        const pct  = teto > 0 ? Math.min((e.gasto / teto) * 100, 100) : 0;
                        const over = teto > 0 && e.gasto > teto;
                        const cor  = over ? COR_OVER : COR_ADS;
                        return (
                            <div key={`${e.cust_id || 'x'}-${i}`} className="flex items-center gap-3" title={`${e.nome} · ${e.polo} · ${e.fase}`}>
                                <span className="w-36 shrink-0 truncate text-xs text-white/75">{e.nome}</span>
                                <div className="relative h-2 flex-1 overflow-hidden rounded-full bg-white/[0.06]">
                                    <div
                                        className="h-full rounded-full transition-[width] duration-700 ease-out"
                                        style={{ width: `${mounted ? pct : 0}%`, background: `linear-gradient(90deg, ${cor}cc, ${cor})` }}
                                    />
                                </div>
                                <span className="w-28 shrink-0 text-right text-[11px] tabular-nums text-white/60">
                                    {fechado ? '—' : formatCurrencyCompact(e.gasto)} <span className="text-white/25">/ {formatCurrencyCompact(teto)}</span>
                                </span>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
