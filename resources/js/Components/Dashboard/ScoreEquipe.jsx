import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * ScoreEquipe — card "Score da equipe" (Fase 97 Plan 04, DASH-97-6).
 *
 * Lista compacta por pessoa consumindo `performance_equipe` (Plan 97-02: já
 * restrito ao recorte de filtros aplicado). Nota oficial 0–5 (D1 do CONTEXT
 * — `nota_final` do DesempenhoScoreService, a mesma que vale bônus) com
 * barra + breakdown, ORDENADA pior→melhor (surfacing de quem precisa de
 * atenção primeiro). O backend envia ordenado do MELHOR pro pior (usado
 * pelo BarChart legado "Desempenho da equipe" que continua na página) —
 * este componente reordena a própria cópia sem afetar aquele widget.
 *
 * Decisão (herdada do 97-02-SUMMARY.md): `breakdown.tacos` vem SEMPRE null
 * (o DesempenhoScoreService não calcula esse componente). Quando null,
 * trocamos o rótulo "TACoS" por "Faturamento" (breakdown.faturamento, que é
 * o 3º componente real da média, junto de NPS e Margem).
 */
export default function ScoreEquipe({ membros = [] }) {
    const lista = Array.isArray(membros) ? membros : [];
    const ordenada = [...lista].sort((a, b) => (a.nota_final ?? -1) - (b.nota_final ?? -1));

    return (
        <div className="card-ecf rounded-2xl p-6 h-full flex flex-col">
            <div className="flex items-center justify-between mb-4">
                <p className="text-white font-display font-extrabold text-lg tracking-tight">Score da equipe</p>
                <Link
                    href={route('performance.index')}
                    className="text-[12px] font-semibold text-blue-400 hover:text-blue-300 transition-colors whitespace-nowrap"
                >
                    Área da equipe →
                </Link>
            </div>

            {ordenada.length === 0 ? (
                <div className="flex-1 flex items-center justify-center py-9 text-center">
                    <p className="text-white/30 text-sm">Sem profissionais no recorte atual.</p>
                </div>
            ) : (
                <div className="flex flex-col gap-4 flex-1 max-h-[440px] overflow-y-auto pr-1">
                    {ordenada.map((m) => {
                        // Cor da nota/barra computada AQUI, DENTRO do callback do
                        // .map() — pitfall Rollup (Fase 97): nunca herdar de
                        // variável de escopo externo.
                        const nota = m.nota_final ?? 0;
                        const corNota = nota >= 4 ? 'text-emerald-400' : nota >= 2.5 ? 'text-ecf-yellow' : 'text-red-400';
                        const corBarra = nota >= 4 ? '#22c55e' : nota >= 2.5 ? '#f59e0b' : '#ef4444';
                        const pct = Math.max(4, Math.min(100, (nota / 5) * 100));
                        const b = m.breakdown ?? {};
                        const quartoComponente = b.tacos != null
                            ? `TACoS ${Number(b.tacos).toFixed(1)}`
                            : `Faturamento ${b.faturamento != null ? Number(b.faturamento).toFixed(1) : '—'}`;

                        // 2026-08-07 — linha "fria": o gate do controller devolveu
                        // placeholder em vez de pagar o compute() síncrono (que
                        // media 124s na landing page inteira). "—" aqui se leria
                        // como "sem nota"; o estado tem que aparecer.
                        if (m.calculando === true) {
                            return (
                                <div key={m.id}>
                                    <div className="flex items-center justify-between gap-2 mb-1.5">
                                        <span className="text-white/85 text-[13px] font-bold truncate">{m.name}</span>
                                        <span className="text-[12px] font-semibold shrink-0 text-white/40 animate-pulse">
                                            calculando…
                                        </span>
                                    </div>
                                    <div className="h-1.5 rounded-full bg-white/[0.06] overflow-hidden">
                                        <div className="h-full w-1/4 rounded-full bg-white/15 animate-pulse" />
                                    </div>
                                </div>
                            );
                        }

                        return (
                            <div key={m.id}>
                                <div className="flex items-center justify-between gap-2 mb-1.5">
                                    <span className="text-white/85 text-[13px] font-bold truncate">{m.name}</span>
                                    <span className={cn('text-[13.5px] font-extrabold shrink-0 tabular-nums', corNota)}>
                                        {m.nota_final != null ? Number(m.nota_final).toFixed(2) : '—'} / 5,00
                                    </span>
                                </div>
                                <div className="h-1.5 rounded-full bg-white/[0.06] overflow-hidden">
                                    <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, background: corBarra }} />
                                </div>
                                <div className="flex gap-3 text-[10.5px] text-white/35 mt-1.5 flex-wrap">
                                    <span>{b.carteira ?? 0} {b.carteira === 1 ? 'empresa' : 'empresas'}</span>
                                    <span>NPS {b.nps != null ? Number(b.nps).toFixed(1) : '—'}</span>
                                    <span>Margem {b.margem != null ? Number(b.margem).toFixed(1) : '—'}</span>
                                    <span>{quartoComponente}</span>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
