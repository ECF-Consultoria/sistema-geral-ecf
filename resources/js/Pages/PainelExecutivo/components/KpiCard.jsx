import { TrendingUp, TrendingDown, Minus } from 'lucide-react';

/**
 * KpiCard — card reutilizável para indicadores do Painel Executivo.
 * Phase 24 — helpers de formatação locais (D-04 do PLAN: sem tocar lib/utils.js).
 *
 * Props:
 *   label     — string  — título do indicador (obrigatório)
 *   valor     — number|null — valor atual do indicador
 *   deltaPct  — number|null — variação MoM em % (positivo = subiu, negativo = caiu)
 *   isCount   — bool (default false) — quando true, formata como contagem (não BRL)
 *   sublabel  — string|null — texto extra abaixo do valor (opcional, não usado na Phase 24)
 */

// ─── Helpers locais de formatação ─────────────────────────────────────────────

/**
 * Formata valor BRL de forma abreviada para KPI cards.
 * Ex: 42859191 → "R$ 42,8M" | 763000 → "R$ 763k" | null → "—"
 */
const fmtMoneyShort = (v) => {
    if (v === null || v === undefined) return '—';
    const formatted = new Intl.NumberFormat('pt-BR', {
        style:            'currency',
        currency:         'BRL',
        notation:         'compact',
        compactDisplay:   'short',
        maximumFractionDigits: 1,
    }).format(v);
    // Intl compact pt-BR gera " mi" e " mil" — ajustar para "M" e "k" (mockup CONTEXT)
    return formatted.replace(' mi', 'M').replace(' mil', 'k');
};

/**
 * Formata contagem abreviada para KPI cards (sem símbolo de moeda).
 * Ex: 1238 → "1,24k" | 357531 → "358k" | 12691798 → "12,7M" | null → "—"
 */
const fmtCountShort = (v) => {
    if (v === null || v === undefined) return '—';
    const formatted = new Intl.NumberFormat('pt-BR', {
        notation:         'compact',
        compactDisplay:   'short',
        maximumFractionDigits: 2,
    }).format(v);
    return formatted.replace(' mi', 'M').replace(' mil', 'k');
};

/**
 * Formata percentual com sinal explícito e vírgula pt-BR.
 * Ex: -11.74 → "-11,74%" | 15.70 → "+15,70%" | 0 → "0,00%" | null → "—"
 */
const fmtPercentSigned = (v) => {
    if (v === null || v === undefined) return '—';
    if (v === 0) return '0,00%';
    return new Intl.NumberFormat('pt-BR', {
        signDisplay:           'exceptZero',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(v) + '%';
};

// ─── Componente ───────────────────────────────────────────────────────────────

export default function KpiCard({ label, valor, deltaPct, isCount = false, sublabel = null }) {
    // Formata o valor conforme o tipo (BRL abreviado ou contagem abreviada)
    const valorFormatado = isCount ? fmtCountShort(valor) : fmtMoneyShort(valor);

    // Determina cor e ícone com base no sinal do delta
    const deltaPositivo = typeof deltaPct === 'number' && deltaPct > 0;
    const deltaNegativo = typeof deltaPct === 'number' && deltaPct < 0;
    const deltaZero     = typeof deltaPct === 'number' && deltaPct === 0;
    const temDelta      = typeof deltaPct === 'number';

    return (
        <div className="rounded-lg bg-ecf-card border border-white/[0.06] p-4 flex flex-col gap-2">

            {/* Label do indicador */}
            <div className="text-[11px] uppercase tracking-wider text-white/40">
                {label}
            </div>

            {/* Valor principal */}
            <div className="text-2xl font-bold text-white">
                {valorFormatado}
            </div>

            {/* Footer MoM — só renderiza quando deltaPct não é null/undefined */}
            {temDelta && (
                <div className="flex items-center gap-1.5 text-xs">
                    {deltaPositivo && <TrendingUp  size={14} className="text-emerald-400" />}
                    {deltaNegativo && <TrendingDown size={14} className="text-red-400" />}
                    {deltaZero     && <Minus        size={14} className="text-white/40"  />}
                    <span className={
                        deltaPositivo ? 'text-emerald-300' :
                        deltaNegativo ? 'text-red-300'     :
                        'text-white/50'
                    }>
                        {fmtPercentSigned(deltaPct)} MoM
                    </span>
                </div>
            )}

            {/* Sublabel opcional (reservado para uso futuro) */}
            {sublabel && (
                <div className="text-[10px] text-white/30 mt-0.5">{sublabel}</div>
            )}
        </div>
    );
}
