import { CLUSTER_LABELS, PROGRAMA_LABELS, traduzirItem } from '@/lib/ecfDriveLabels';

/**
 * MatrizHeatmap — tabela heatmap programa (linhas) × cluster (colunas).
 *
 * Cada célula é colorida proporcionalmente ao % do GMV total (gradient amarelo ECF).
 * Labels traduzidos via lib/ecfDriveLabels.js (Phase 25 — NÃO duplicar dicionários).
 *
 * Props:
 *   matriz — { linhas: string[], colunas: string[], celulas: [...], top5: [...], gmvTotal: number }
 *
 * Sem dependência de shadcn Table (tabela HTML semântica basta — R-08 PLAN).
 * Tooltip via title HTML nativo (pattern KpiCard Phase 24 — D-I PLAN).
 */

// ─── Helpers locais de formatação ────────────────────────────────────────────

const fmtMoney = (v) =>
    new Intl.NumberFormat('pt-BR', {
        style:                 'currency',
        currency:              'BRL',
        maximumFractionDigits: 0,
    }).format(v ?? 0);

// Formato compacto para dentro das células (R$ 12,2M / R$ 763k)
const fmtMoneyShort = (v) => {
    if (v === null || v === undefined) return '—';
    const formatted = new Intl.NumberFormat('pt-BR', {
        style:                 'currency',
        currency:              'BRL',
        notation:              'compact',
        maximumFractionDigits: 1,
    }).format(v);
    // Ajusta sufixo pt-BR: " mi" → "M", " mil" → "k"
    return formatted.replace(' mi', 'M').replace(' mil', 'k');
};

// ─── Componente principal ─────────────────────────────────────────────────────

export default function MatrizHeatmap({ matriz }) {
    // Empty state quando segmentação não veio
    if (!matriz?.linhas?.length) {
        return (
            <div className="h-40 flex items-center justify-center text-white/30 text-sm">
                Sem dados de segmentação disponíveis.
            </div>
        );
    }

    const { linhas, colunas, celulas, top5 } = matriz;

    // Monta lookup rápido de célula por programa+cluster
    const celulaMap = {};
    for (const c of celulas) {
        celulaMap[`${c.programa}|${c.cluster}`] = c;
    }

    return (
        <div className="space-y-6">

            {/* ─── Tabela heatmap ───────────────────────────────────────── */}
            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            {/* Canto superior esquerdo vazio */}
                            <th className="p-3 border border-white/[0.04] text-left text-white/40 text-xs font-semibold uppercase tracking-wider min-w-[140px]">
                                Programa ╲ Cluster
                            </th>
                            {/* Colunas de cluster — traduzidas */}
                            {colunas.map((col) => (
                                <th
                                    key={col}
                                    className="p-3 border border-white/[0.04] text-center text-white/50 text-xs font-semibold uppercase tracking-wider whitespace-nowrap"
                                    title={CLUSTER_LABELS[col] || col}
                                >
                                    {/* Mostra abreviação para não estourar a tabela */}
                                    {col}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {linhas.map((linha) => (
                            <tr key={linha}>
                                {/* Header de linha — nome do programa traduzido */}
                                <th className="p-3 border border-white/[0.04] text-left text-white/60 text-xs font-semibold whitespace-nowrap">
                                    {traduzirItem('programa', linha)}
                                </th>
                                {/* Células: uma por cluster */}
                                {colunas.map((coluna) => {
                                    const c = celulaMap[`${linha}|${coluna}`];
                                    if (!c) {
                                        // Combinação inexistente — célula vazia
                                        return (
                                            <td
                                                key={coluna}
                                                className="border border-white/[0.04] p-3 text-center text-white/20"
                                            >
                                                —
                                            </td>
                                        );
                                    }

                                    // Gradient amarelo proporcional ao % do GMV
                                    // Divisor 40: célula com ≥40% do GMV fica opaque (#ffe600 saturado)
                                    // arbitrary value via inline style para flexibilidade (R-09 PLAN)
                                    const alpha = Math.min(0.85, c.pct / 40);

                                    return (
                                        <td
                                            key={coluna}
                                            className="border border-white/[0.04] p-3 text-center cursor-help transition-colors hover:border-ecf-yellow/30"
                                            style={{ backgroundColor: `rgba(255, 230, 0, ${alpha})` }}
                                            title={
                                                `${traduzirItem('programa', c.programa)} × ${traduzirItem('cluster', c.cluster)}\n` +
                                                `Sellers: ${c.sellers}\n` +
                                                `GMV: ${fmtMoney(c.gmv)}\n` +
                                                `% do total: ${c.pct.toFixed(2)}%`
                                            }
                                        >
                                            {/* Valor compacto */}
                                            <div className="text-xs text-white/90 font-semibold">
                                                {fmtMoneyShort(c.gmv)}
                                            </div>
                                            {/* Percentual */}
                                            <div className="text-[10px] text-white/60">
                                                {c.pct.toFixed(1)}%
                                            </div>
                                            {/* Contagem de sellers */}
                                            <div className="text-[10px] text-white/40">
                                                {c.sellers} sellers
                                            </div>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* ─── Top 5 concentrações ─────────────────────────────────── */}
            {top5?.length > 0 && (
                <div>
                    <h3 className="text-white/60 text-xs font-semibold uppercase tracking-wider mb-3">
                        Top 5 concentrações
                    </h3>
                    <div className="space-y-2">
                        {top5.map((c, i) => (
                            <div
                                key={i}
                                className="flex items-center justify-between rounded-md bg-white/[0.03] border border-white/[0.06] px-3 py-2"
                            >
                                <div className="flex items-center gap-3">
                                    <span className="text-ecf-yellow font-bold text-sm">#{i + 1}</span>
                                    <span className="text-white/80 text-sm">
                                        {traduzirItem('programa', c.programa)} × {traduzirItem('cluster', c.cluster)}
                                    </span>
                                </div>
                                <div className="flex items-center gap-4 text-xs">
                                    <span className="text-white/50">{c.sellers} sellers</span>
                                    <span className="text-white/70 font-semibold">{fmtMoney(c.gmv)}</span>
                                    <span className="text-ecf-yellow font-bold">{c.pct.toFixed(2)}%</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

        </div>
    );
}
