import { formatCurrency, cn } from '@/lib/utils';

// Mapa de status → cor da fatia atingida + rótulo (Phase 38 — novo modelo D-11/D-13).
// Status vem do PolosController como string: 'Sim' | 'Em progresso' | 'Não' | 'Problema'.
const STATUS = {
    'Sim':          { cor: '#22c55e', label: 'No alvo' },      // fat >= limiar
    'Em progresso': { cor: '#ffe600', label: 'Em progresso' }, // 0 < fat < limiar
    'Não':          { cor: '#ef4444', label: 'Não' },          // fat = 0 (sem CSV)
    'Problema':     { cor: '#a855f7', label: 'Problema' },     // empresa.problema = true (precedência máxima)
};

// Cor do anel "restante" (faltante para a meta) — cinza sutil no tema dark
const COR_RESTANTE = '#2a2d36';

/**
 * DonutCard — card de % da meta por polo (Phase 38 · restyle quick 260619-dce).
 *
 * Recebe o objeto polo agregado (de UM mês) pelo PolosController:
 *   { polo, pct, faturamento, meta, ativos, status }
 *
 * Medidor de progresso: anel flat com glow (atingido = cor de status · restante =
 * cinza), % da meta no centro e os KPIs faturamento/meta/empresas ativas.
 * Restilizado para combinar com a pizza rose (flat + glow) — antes era pizza 3D.
 * `cor` opcional pinta o ponto de identidade do polo.
 */
export default function DonutCard({ polo: dados, cor: corPolo }) {
    const {
        polo: nome,
        pct         = 0,
        faturamento = 0,
        meta        = 0,
        ativos      = 0,
        status      = 'Não',
    } = dados;

    // Fallback para 'Não' caso status desconhecido (defesa contra prop malformada — T-38-11)
    const { cor, label } = STATUS[status] ?? STATUS['Não'];

    // Progresso clampado 0–100 para o anel (o número exibido mantém o pct real)
    const atingido = Math.max(Math.min(Number(pct) || 0, 100), 0);

    // ── Geometria do anel de progresso (flat) ─────────────────────────────────
    const size   = 150;
    const stroke = 13;
    const c      = size / 2;
    const r      = (size - stroke) / 2 - 6;          // raio do anel
    const circ   = 2 * Math.PI * r;
    const dash   = (atingido / 100) * circ;          // arco preenchido
    // ID estável por polo para isolar o filtro de glow entre os cards
    const glowId = `donut-glow-${String(nome).replace(/[^a-zA-Z0-9]/g, '') || 'x'}`;

    return (
        <div className={cn(
            'group relative overflow-hidden rounded-2xl p-5 flex flex-col gap-3',
            'border border-white/[0.08] bg-white/[0.02] transition-colors hover:bg-white/[0.04]',
        )}>
            {/* Faixa de status no topo do card */}
            <span className="absolute inset-x-0 top-0 h-[3px]" style={{ background: cor }} />

            {/* Cabeçalho: nome do polo + selo de status */}
            <div className="flex items-center justify-between gap-2">
                <span className="flex items-center gap-2 min-w-0">
                    {corPolo && <span className="h-2.5 w-2.5 rounded-full shrink-0" style={{ background: corPolo }} />}
                    <span className="text-white font-semibold text-sm tracking-wide uppercase truncate">{nome}</span>
                </span>
                <span className="flex items-center gap-1.5 shrink-0">
                    <span className="h-2 w-2 rounded-full" style={{ background: cor }} />
                    <span className="text-[10px] uppercase tracking-wider text-white/40">{label}</span>
                </span>
            </div>

            {/* Anel de progresso flat com glow (% da meta) */}
            <div className="flex items-center justify-center py-2" style={{ minHeight: 150 }}>
                <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden="true">
                    <defs>
                        <filter id={glowId} x="-40%" y="-40%" width="180%" height="180%">
                            <feDropShadow dx="0" dy="0" stdDeviation="4" floodColor={cor} floodOpacity="0.55" />
                        </filter>
                    </defs>

                    {/* Trilha (restante até a meta) */}
                    <circle cx={c} cy={c} r={r} fill="none" stroke={COR_RESTANTE} strokeWidth={stroke} />

                    {/* Arco preenchido (atingido) — começa no topo (-90°), ponta arredondada + glow */}
                    {dash > 0 && (
                        <circle
                            cx={c}
                            cy={c}
                            r={r}
                            fill="none"
                            stroke={cor}
                            strokeWidth={stroke}
                            strokeLinecap="round"
                            strokeDasharray={`${dash} ${circ - dash}`}
                            transform={`rotate(-90 ${c} ${c})`}
                            filter={`url(#${glowId})`}
                        />
                    )}

                    {/* % da meta no centro */}
                    <text x={c} y={c - 2} textAnchor="middle" dominantBaseline="middle"
                          fontSize="30" fontWeight="800" fill={cor} fontFamily="inherit">
                        {(Number(pct) || 0).toFixed(0)}%
                    </text>
                    <text x={c} y={c + 22} textAnchor="middle"
                          fontSize="9" letterSpacing="1" fill="rgba(255,255,255,0.35)" fontFamily="inherit">
                        DA META
                    </text>
                </svg>
            </div>

            {/* KPIs */}
            <div className="space-y-1.5 pt-1">
                <div className="flex items-center justify-between text-xs">
                    <span className="text-white/40">Faturamento</span>
                    <span className="text-white font-semibold">{formatCurrency(faturamento)}</span>
                </div>
                <div className="flex items-center justify-between text-xs">
                    <span className="text-white/40">Meta</span>
                    <span className="text-white/70 font-medium">{formatCurrency(meta)}</span>
                </div>
                <div className="flex items-center justify-between text-xs pt-1.5 border-t border-white/[0.06]">
                    <span className="text-white/40">Empresas ativas</span>
                    <span className="text-white/70 font-medium">{ativos}</span>
                </div>
            </div>
        </div>
    );
}
