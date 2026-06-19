import { formatCurrency, cn } from '@/lib/utils';
import CityGauge from './CityGauge';
import { shapeForPolo } from './cityShapes';

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
 * DonutCard — card de % da meta por polo (Phase 38 · quick 260619-dce).
 *
 * O CARD É O POLO: sem caixa retangular — a silhueta da cidade (CityGauge) é o
 * próprio card, enchendo de baixo até o % da meta. Nome em cima, % no centro da
 * cidade, status + KPIs compactos embaixo. Fallback para anel flat quando o polo
 * não tem contorno mapeado.
 *
 * Recebe o objeto polo agregado (de UM mês) pelo PolosController:
 *   { polo, pct, faturamento, meta, ativos, status }
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

    const temShape = !!shapeForPolo(nome);

    // ── Anel de progresso flat (fallback p/ polos sem contorno) ───────────────
    const atingido = Math.max(Math.min(Number(pct) || 0, 100), 0);
    const size     = 168;
    const stroke   = 13;
    const c        = size / 2;
    const r        = (size - stroke) / 2 - 6;
    const circ     = 2 * Math.PI * r;
    const dash     = (atingido / 100) * circ;
    const glowId   = `donut-glow-${String(nome).replace(/[^a-zA-Z0-9]/g, '') || 'x'}`;

    return (
        <div className="flex flex-col items-center gap-2 px-2 py-3">
            {/* Nome do polo + ponto de identidade */}
            <div className="flex items-center gap-2 min-w-0">
                {corPolo && <span className="h-2.5 w-2.5 rounded-full shrink-0" style={{ background: corPolo }} />}
                <span className="text-white font-semibold text-sm tracking-wide uppercase truncate">{nome}</span>
            </div>

            {/* O polo em si: silhueta da cidade (ou anel, se não houver contorno) */}
            <div className="flex items-center justify-center" style={{ height: 190 }}>
                {temShape ? (
                    <CityGauge nome={nome} pct={pct} cor={cor} height={190} />
                ) : (
                    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden="true">
                        <defs>
                            <filter id={glowId} x="-40%" y="-40%" width="180%" height="180%">
                                <feDropShadow dx="0" dy="0" stdDeviation="4" floodColor={cor} floodOpacity="0.55" />
                            </filter>
                        </defs>
                        <circle cx={c} cy={c} r={r} fill="none" stroke={COR_RESTANTE} strokeWidth={stroke} />
                        {dash > 0 && (
                            <circle
                                cx={c} cy={c} r={r} fill="none"
                                stroke={cor} strokeWidth={stroke} strokeLinecap="round"
                                strokeDasharray={`${dash} ${circ - dash}`}
                                transform={`rotate(-90 ${c} ${c})`}
                                filter={`url(#${glowId})`}
                            />
                        )}
                        <text x={c} y={c - 2} textAnchor="middle" dominantBaseline="middle"
                              fontSize="32" fontWeight="800" fill={cor} fontFamily="inherit">
                            {(Number(pct) || 0).toFixed(0)}%
                        </text>
                        <text x={c} y={c + 22} textAnchor="middle"
                              fontSize="9" letterSpacing="1" fill="rgba(255,255,255,0.35)" fontFamily="inherit">
                            DA META
                        </text>
                    </svg>
                )}
            </div>

            {/* Status */}
            <div className="flex items-center gap-1.5">
                <span className="h-2 w-2 rounded-full" style={{ background: cor }} />
                <span className="text-[11px] uppercase tracking-wider" style={{ color: cor }}>{label}</span>
            </div>

            {/* KPIs compactos (sem caixa) */}
            <div className="flex items-center gap-2 text-[11px] text-white/45">
                <span className="text-white/85 font-semibold tabular-nums">{formatCurrency(faturamento)}</span>
                <span className="text-white/20">·</span>
                <span className="tabular-nums">meta {formatCurrency(meta)}</span>
                <span className="text-white/20">·</span>
                <span className="tabular-nums">{ativos} {ativos === 1 ? 'empresa' : 'empresas'}</span>
            </div>
        </div>
    );
}
